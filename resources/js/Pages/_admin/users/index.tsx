import {
    keepPreviousData,
    useMutation,
    useQuery,
    useQueryClient,
} from "@tanstack/react-query";
import {
    flexRender,
    stockFeatures,
    useTable,
    type ColumnDef,
    type PaginationState,
    type StockFeatures,
} from "@tanstack/react-table";
import {
    Badge,
    Button,
    Card,
    Dialog,
    Flex,
    Heading,
    Select,
    Spinner,
    Table,
    Text,
    TextField,
} from "@radix-ui/themes";
import {
    useCallback,
    useEffect,
    useMemo,
    useState,
    type FormEvent,
} from "react";
import {
    type CreateUserInput,
    type UpdateUserInput,
    type UserRole,
    type UserRow,
    type UserStatus,
} from "../../../types/user";
import { createUser, deleteUser, fetchUsers, updateUser } from "./use.user.api";
import { ApiError } from "@/lib/api";

const roleOptions: Array<{ label: string; value: UserRole }> = [
    { label: "Admin", value: "admin" },
    { label: "Staff", value: "staff" },
    { label: "Customer", value: "customer" },
    { label: "Driver", value: "driver" },
];

type RoleFilter = "all" | UserRole;
type StatusFilter = "all" | UserStatus;
type UserFormValues = {
    first_name: string;
    last_name: string;
    email: string;
    phone_number: string;
    role: UserRole;
    status: UserStatus;
    password: string;
};
type FieldErrors = Record<string, string[]>;

const emptyForm: UserFormValues = {
    first_name: "",
    last_name: "",
    email: "",
    phone_number: "",
    role: "staff",
    status: "active",
    password: "",
};

const roleColors: Record<UserRole, "crimson" | "blue" | "green" | "orange"> = {
    admin: "crimson",
    staff: "blue",
    customer: "green",
    driver: "orange",
};

function formatDate(value: string | null): string {
    if (!value) return "—";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat("en-PH", {
        day: "2-digit",
        month: "short",
        year: "numeric",
    }).format(date);
}

function titleCase(value: string): string {
    if (!value) return value;
    return value
        .replaceAll("_", " ")
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

function firstError(errors: FieldErrors, field: string): string | undefined {
    return errors[field]?.[0];
}

export default function UsersDataTable() {
    const queryClient = useQueryClient();
    const [search, setSearch] = useState("");
    const [debouncedSearch, setDebouncedSearch] = useState("");
    const [roleFilter, setRoleFilter] = useState<RoleFilter>("all");
    const [statusFilter, setStatusFilter] = useState<StatusFilter>("all");
    const [pagination, setPagination] = useState<PaginationState>({
        pageIndex: 0,
        pageSize: 10,
    });

    const [userDialogOpen, setUserDialogOpen] = useState(false);
    const [editingUser, setEditingUser] = useState<UserRow | null>(null);
    const [form, setForm] = useState<UserFormValues>(emptyForm);
    const [formErrors, setFormErrors] = useState<FieldErrors>({});
    const [formError, setFormError] = useState<string | null>(null);

    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [deleteTarget, setDeleteTarget] = useState<UserRow | null>(null);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    useEffect(() => {
        const timeoutId = window.setTimeout(
            () => setDebouncedSearch(search.trim()),
            300,
        );
        return () => window.clearTimeout(timeoutId);
    }, [search]);

    const usersQuery = useQuery({
        queryKey: [
            "users",
            {
                page: pagination.pageIndex + 1,
                perPage: pagination.pageSize,
                search: debouncedSearch,
                role: roleFilter === "all" ? undefined : roleFilter,
                status: statusFilter === "all" ? undefined : statusFilter,
            },
        ],
        queryFn: ({ signal }) =>
            fetchUsers(
                {
                    page: pagination.pageIndex + 1,
                    perPage: pagination.pageSize,
                    search: debouncedSearch || undefined,
                    role: roleFilter === "all" ? undefined : roleFilter,
                    status: statusFilter === "all" ? undefined : statusFilter,
                },
                signal,
            ),
        placeholderData: keepPreviousData,
    });

    const showFormError = useCallback((error: unknown) => {
        if (error instanceof ApiError) {
            setFormError(error.message);
            setFormErrors(error.errors ?? {});
            return;
        }
        setFormError(
            error instanceof Error
                ? error.message
                : "Something went wrong. Please try again.",
        );
        setFormErrors({});
    }, []);

    const closeUserDialog = useCallback(() => {
        setUserDialogOpen(false);
        setEditingUser(null);
        setForm(emptyForm);
        setFormErrors({});
        setFormError(null);
    }, []);

    const createMutation = useMutation({
        mutationFn: (payload: CreateUserInput) => createUser(payload),
        onSuccess: async () => {
            setPagination((current) => ({ ...current, pageIndex: 0 }));
            closeUserDialog();
            await queryClient.invalidateQueries({ queryKey: ["users"] });
        },
        onError: showFormError,
    });

    const updateMutation = useMutation({
        mutationFn: ({
            id,
            payload,
        }: {
            id: UserRow["id"];
            payload: UpdateUserInput;
        }) => updateUser(id, payload),
        onSuccess: async () => {
            closeUserDialog();
            await queryClient.invalidateQueries({ queryKey: ["users"] });
        },
        onError: showFormError,
    });

    const deleteMutation = useMutation({
        mutationFn: (id: UserRow["id"]) => deleteUser(id),
        onSuccess: async () => {
            setDeleteDialogOpen(false);
            setDeleteTarget(null);
            setDeleteError(null);
            await queryClient.invalidateQueries({ queryKey: ["users"] });
        },
        onError: (error) => {
            setDeleteError(
                error instanceof Error
                    ? error.message
                    : "Could not delete this user.",
            );
        },
    });

    // After deleting the last row on a page, Laravel may report a smaller last_page.
    useEffect(() => {
        const lastPage = usersQuery.data?.last_page;
        if (lastPage && pagination.pageIndex > lastPage - 1) {
            setPagination((current) => ({
                ...current,
                pageIndex: Math.max(0, lastPage - 1),
            }));
        }
    }, [usersQuery.data?.last_page, pagination.pageIndex]);

    function resetPage() {
        setPagination((current) => ({ ...current, pageIndex: 0 }));
    }

    function clearFilters() {
        setSearch("");
        setDebouncedSearch("");
        setRoleFilter("all");
        setStatusFilter("all");
        resetPage();
    }

    const openCreateDialog = useCallback(() => {
        setEditingUser(null);
        setForm(emptyForm);
        setFormErrors({});
        setFormError(null);
        setUserDialogOpen(true);
    }, []);

    const openEditDialog = useCallback((user: UserRow) => {
        console.log(`Edit user: ${user.id}`, user);
        setEditingUser(user);
        setForm({
            first_name: user.first_name,
            last_name: user.last_name,
            email: user.email,
            phone_number: user.phone_number,
            role: user.role,
            status: user.status,
            password: "",
        });
        setFormErrors({});
        setFormError(null);
        setUserDialogOpen(true);
    }, []);

    const openDeleteDialog = useCallback((user: UserRow) => {
        setDeleteTarget(user);
        setDeleteError(null);
        setDeleteDialogOpen(true);
    }, []);

    function updateField<K extends keyof UserFormValues>(
        field: K,
        value: UserFormValues[K],
    ) {
        setForm((current) => ({ ...current, [field]: value }));
        setFormErrors((current) => ({ ...current, [field]: [] }));
        setFormError(null);
    }

    function handleSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setFormErrors({});
        setFormError(null);

        if (editingUser) {
            const payload: UpdateUserInput = {
                first_name: form.first_name.trim(),
                last_name: form.last_name.trim(),
                email: form.email.trim(),
                phone_number: form.phone_number.trim(),
                role: form.role,
                status: form.status,
                ...(form.password.trim() ? { password: form.password } : {}),
            };
            updateMutation.mutate({ id: editingUser.id, payload });
            return;
        }

        createMutation.mutate({
            first_name: form.first_name.trim(),
            last_name: form.last_name.trim(),
            email: form.email.trim(),
            phone_number: form.phone_number.trim(),
            role: form.role,
            status: form.status,
            password: form.password,
        });
    }

    const columns = useMemo<ColumnDef<StockFeatures, UserRow>[]>(
        () => [
            {
                accessorKey: "name",
                header: "User",
                cell: ({ row }) => (
                    <Flex direction="column" gap="1" style={{ minWidth: 170 }}>
                        <Text weight="medium">
                            {row.original.first_name} {row.original.last_name}
                        </Text>
                        <Text size="2" color="gray">
                            {row.original.email}
                        </Text>
                    </Flex>
                ),
            },
            {
                accessorKey: "id",
                header: "User ID",
                cell: ({ getValue }) => (
                    <Text color="gray">
                        #{String(getValue<number | string>())}
                    </Text>
                ),
            },
            {
                accessorKey: "role",
                header: "Role",
                cell: ({ getValue }) => {
                    const role = getValue<UserRole>();
                    return (
                        <Badge
                            color={roleColors[role] ?? "gray"}
                            variant="soft"
                        >
                            {titleCase(role)}
                        </Badge>
                    );
                },
            },
            {
                accessorKey: "status",
                header: "Status",
                cell: ({ getValue }) => {
                    const status = getValue<UserStatus>();
                    return (
                        <Badge
                            color={status === "active" ? "green" : "gray"}
                            variant="soft"
                        >
                            {titleCase(status)}
                        </Badge>
                    );
                },
            },
            {
                accessorKey: "created_at",
                header: "Joined",
                cell: ({ getValue }) => (
                    <Text color="gray">
                        {formatDate(getValue<string | null>())}
                    </Text>
                ),
            },
            {
                id: "actions",
                header: "Actions",
                cell: ({ row }) => (
                    <Flex gap="2" align="center">
                        <Button
                            type="button"
                            size="1"
                            variant="soft"
                            onClick={() => openEditDialog(row.original)}
                        >
                            Edit
                        </Button>
                        <Button
                            type="button"
                            size="1"
                            variant="soft"
                            color="red"
                            onClick={() => openDeleteDialog(row.original)}
                        >
                            Delete
                        </Button>
                    </Flex>
                ),
            },
        ],
        [openDeleteDialog, openEditDialog],
    );

    // TanStack Table v9: useTable + stockFeatures replaces useReactTable/getCoreRowModel.
    const table = useTable({
        features: stockFeatures,
        data: usersQuery.data?.data ?? [],
        columns,
        manualPagination: true,
        pageCount: usersQuery.data?.last_page ?? 1,
        state: { pagination },
        onPaginationChange: setPagination,
    });

    const hasActiveFilters =
        search.trim().length > 0 ||
        roleFilter !== "all" ||
        statusFilter !== "all";
    const isSaving = createMutation.isPending || updateMutation.isPending;

    return (
        <>
            <Card size="3">
                <Flex direction="column" gap="4">
                    <Flex
                        align={{ initial: "start", sm: "center" }}
                        justify="between"
                        gap="3"
                        wrap="wrap"
                    >
                        <div>
                            <Heading size="5">Users</Heading>
                            <Text as="p" size="2" color="gray" mt="1">
                                Manage user accounts, search by name or email,
                                and filter by role or status.
                            </Text>
                        </div>
                        <Flex align="center" gap="3" wrap="wrap">
                            <Badge color="gray" variant="soft" size="2">
                                {usersQuery.data?.total ?? 0} total users
                            </Badge>
                            <Button
                                type="button"
                                onClick={openCreateDialog}
                                size="3"
                            >
                                Create user
                            </Button>
                        </Flex>
                    </Flex>

                    <Flex gap="3" align="center" wrap="wrap">
                        <div style={{ flex: "1 1 260px", minWidth: 0 }}>
                            <TextField.Root
                                aria-label="Search users"
                                placeholder="Search name, email, or numeric user ID..."
                                value={search}
                                onChange={(event) => {
                                    setSearch(event.target.value);
                                    resetPage();
                                }}
                                size="3"
                            />
                        </div>

                        <div style={{ width: 160, maxWidth: "100%" }}>
                            <Select.Root
                                value={roleFilter}
                                onValueChange={(value) => {
                                    setRoleFilter(value as RoleFilter);
                                    resetPage();
                                }}
                                size="3"
                            >
                                <Select.Trigger
                                    aria-label="Filter by role"
                                    style={{ width: "100%" }}
                                />
                                <Select.Content position="popper">
                                    <Select.Item value="all">
                                        All roles
                                    </Select.Item>
                                    {roleOptions.map((role) => (
                                        <Select.Item
                                            key={role.value}
                                            value={role.value}
                                        >
                                            {role.label}
                                        </Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                        </div>

                        <div style={{ width: 160, maxWidth: "100%" }}>
                            <Select.Root
                                value={statusFilter}
                                onValueChange={(value) => {
                                    setStatusFilter(value as StatusFilter);
                                    resetPage();
                                }}
                                size="3"
                            >
                                <Select.Trigger
                                    aria-label="Filter by account status"
                                    style={{ width: "100%" }}
                                />
                                <Select.Content position="popper">
                                    <Select.Item value="all">
                                        All statuses
                                    </Select.Item>
                                    <Select.Item value="active">
                                        Active
                                    </Select.Item>
                                    <Select.Item value="inactive">
                                        Inactive
                                    </Select.Item>
                                </Select.Content>
                            </Select.Root>
                        </div>

                        <Button
                            type="button"
                            variant="soft"
                            color="gray"
                            onClick={clearFilters}
                            disabled={!hasActiveFilters}
                            size="3"
                        >
                            Clear filters
                        </Button>
                    </Flex>

                    {usersQuery.isError && !usersQuery.data ? (
                        <Flex direction="column" align="start" gap="2" py="4">
                            <Text color="red" weight="medium">
                                Could not load users.
                            </Text>
                            <Text color="gray" size="2">
                                {usersQuery.error.message}
                            </Text>
                            <Button
                                type="button"
                                onClick={() => void usersQuery.refetch()}
                            >
                                Try again
                            </Button>
                        </Flex>
                    ) : (
                        <>
                            {usersQuery.isError && usersQuery.data ? (
                                <Text color="red" size="2">
                                    Refresh failed. Showing the last
                                    successfully loaded page.
                                </Text>
                            ) : null}

                            <div style={{ overflowX: "auto", width: "100%" }}>
                                <Table.Root
                                    variant="surface"
                                    size="2"
                                    style={{ minWidth: 820 }}
                                >
                                    <Table.Header>
                                        {table
                                            .getHeaderGroups()
                                            .map((headerGroup) => (
                                                <Table.Row key={headerGroup.id}>
                                                    {headerGroup.headers.map(
                                                        (header) => (
                                                            <Table.ColumnHeaderCell
                                                                key={header.id}
                                                            >
                                                                {header.isPlaceholder
                                                                    ? null
                                                                    : flexRender(
                                                                          header
                                                                              .column
                                                                              .columnDef
                                                                              .header,
                                                                          header.getContext(),
                                                                      )}
                                                            </Table.ColumnHeaderCell>
                                                        ),
                                                    )}
                                                </Table.Row>
                                            ))}
                                    </Table.Header>
                                    <Table.Body>
                                        {usersQuery.isPending ? (
                                            <Table.Row>
                                                <Table.Cell
                                                    colSpan={columns.length}
                                                >
                                                    <Flex
                                                        align="center"
                                                        justify="center"
                                                        gap="2"
                                                        py="6"
                                                    >
                                                        <Spinner />
                                                        <Text
                                                            color="gray"
                                                            size="2"
                                                        >
                                                            Loading users…
                                                        </Text>
                                                    </Flex>
                                                </Table.Cell>
                                            </Table.Row>
                                        ) : table.getRowModel().rows.length >
                                          0 ? (
                                            table
                                                .getRowModel()
                                                .rows.map((row) => (
                                                    <Table.Row key={row.id}>
                                                        {row
                                                            .getVisibleCells()
                                                            .map((cell) => (
                                                                <Table.Cell
                                                                    key={
                                                                        cell.id
                                                                    }
                                                                >
                                                                    {flexRender(
                                                                        cell
                                                                            .column
                                                                            .columnDef
                                                                            .cell,
                                                                        cell.getContext(),
                                                                    )}
                                                                </Table.Cell>
                                                            ))}
                                                    </Table.Row>
                                                ))
                                        ) : (
                                            <Table.Row>
                                                <Table.Cell
                                                    colSpan={columns.length}
                                                >
                                                    <Flex
                                                        direction="column"
                                                        align="center"
                                                        gap="2"
                                                        py="8"
                                                    >
                                                        <Text weight="medium">
                                                            No users found
                                                        </Text>
                                                        <Text
                                                            color="gray"
                                                            size="2"
                                                        >
                                                            Try another search
                                                            term or clear your
                                                            filters.
                                                        </Text>
                                                    </Flex>
                                                </Table.Cell>
                                            </Table.Row>
                                        )}
                                    </Table.Body>
                                </Table.Root>
                            </div>

                            <Flex
                                align="center"
                                justify="between"
                                gap="3"
                                wrap="wrap"
                            >
                                <Text color="gray" size="2">
                                    {usersQuery.data?.total
                                        ? `Showing ${usersQuery.data.from ?? 0}–${usersQuery.data.to ?? 0} of ${usersQuery.data.total} users`
                                        : "Showing 0 users"}
                                    {usersQuery.isFetching
                                        ? " · Updating…"
                                        : ""}
                                </Text>
                                <Flex align="center" gap="2" wrap="wrap">
                                    <Text color="gray" size="2">
                                        Rows per page
                                    </Text>
                                    <Select.Root
                                        value={String(pagination.pageSize)}
                                        onValueChange={(value) =>
                                            setPagination({
                                                pageIndex: 0,
                                                pageSize: Number(value),
                                            })
                                        }
                                        size="2"
                                    >
                                        <Select.Trigger aria-label="Rows per page" />
                                        <Select.Content position="popper">
                                            <Select.Item value="10">
                                                10
                                            </Select.Item>
                                            <Select.Item value="25">
                                                25
                                            </Select.Item>
                                            <Select.Item value="50">
                                                50
                                            </Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                    <Text color="gray" size="2">
                                        Page{" "}
                                        {usersQuery.data?.current_page ?? 1} of{" "}
                                        {usersQuery.data?.last_page ?? 1}
                                    </Text>
                                    <Button
                                        type="button"
                                        variant="soft"
                                        color="gray"
                                        onClick={() => table.previousPage()}
                                        disabled={
                                            !table.getCanPreviousPage() ||
                                            usersQuery.isFetching
                                        }
                                    >
                                        Previous
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="soft"
                                        color="gray"
                                        onClick={() => table.nextPage()}
                                        disabled={
                                            !table.getCanNextPage() ||
                                            usersQuery.isFetching
                                        }
                                    >
                                        Next
                                    </Button>
                                </Flex>
                            </Flex>
                        </>
                    )}
                </Flex>
            </Card>

            <Dialog.Root open={userDialogOpen} onOpenChange={setUserDialogOpen}>
                <Dialog.Content maxWidth="520px">
                    <Dialog.Title>
                        {editingUser ? "Edit user" : "Create user"}
                    </Dialog.Title>
                    <Dialog.Description size="2" mb="4">
                        {editingUser
                            ? "Update the user's account details. Leave password blank to keep the current password."
                            : "Create a new user account and assign its role and status."}
                    </Dialog.Description>

                    <form onSubmit={handleSubmit}>
                        <Flex direction="column" gap="3">
                            {formError ? (
                                <Text color="red" size="2" role="alert">
                                    {formError}
                                </Text>
                            ) : null}

                            <label>
                                <Text as="div" size="2" mb="1" weight="medium">
                                    First name
                                </Text>
                                <TextField.Root
                                    autoFocus
                                    value={form.first_name}
                                    onChange={(event) =>
                                        updateField(
                                            "first_name",
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Enter first name"
                                    autoComplete="name"
                                    required
                                    maxLength={255}
                                />
                                {firstError(formErrors, "name") ? (
                                    <Text as="div" color="red" size="1" mt="1">
                                        {firstError(formErrors, "name")}
                                    </Text>
                                ) : null}
                            </label>

                            <label>
                                <Text as="div" size="2" mb="1" weight="medium">
                                    Last name
                                </Text>
                                <TextField.Root
                                    value={form.last_name}
                                    onChange={(event) =>
                                        updateField(
                                            "last_name",
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Enter last name"
                                    autoComplete="name"
                                    required
                                    maxLength={255}
                                />
                                {firstError(formErrors, "name") ? (
                                    <Text as="div" color="red" size="1" mt="1">
                                        {firstError(formErrors, "name")}
                                    </Text>
                                ) : null}
                            </label>

                            <label>
                                <Text as="div" size="2" mb="1" weight="medium">
                                    Phone number
                                </Text>
                                <TextField.Root
                                    value={form.phone_number}
                                    onChange={(event) =>
                                        updateField(
                                            "phone_number",
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Enter phone number"
                                    autoComplete="tel"
                                    required
                                    maxLength={255}
                                />
                                {firstError(formErrors, "phone_number") ? (
                                    <Text as="div" color="red" size="1" mt="1">
                                        {firstError(formErrors, "phone_number")}
                                    </Text>
                                ) : null}
                            </label>

                            <label>
                                <Text as="div" size="2" mb="1" weight="medium">
                                    Email address
                                </Text>
                                <TextField.Root
                                    type="email"
                                    value={form.email}
                                    onChange={(event) =>
                                        updateField("email", event.target.value)
                                    }
                                    placeholder="name@example.com"
                                    autoComplete="email"
                                    required
                                    maxLength={255}
                                />
                                {firstError(formErrors, "email") ? (
                                    <Text as="div" color="red" size="1" mt="1">
                                        {firstError(formErrors, "email")}
                                    </Text>
                                ) : null}
                            </label>

                            <Flex gap="3" wrap="wrap">
                                <label style={{ flex: "1 1 180px" }}>
                                    <Text
                                        as="div"
                                        size="2"
                                        mb="1"
                                        weight="medium"
                                    >
                                        Role
                                    </Text>
                                    <Select.Root
                                        value={form.role}
                                        onValueChange={(value) =>
                                            updateField(
                                                "role",
                                                value as UserRole,
                                            )
                                        }
                                    >
                                        <Select.Trigger
                                            aria-label="User role"
                                            style={{ width: "100%" }}
                                        />
                                        <Select.Content position="popper">
                                            {roleOptions.map((role) => (
                                                <Select.Item
                                                    key={role.value}
                                                    value={role.value}
                                                >
                                                    {role.label}
                                                </Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                    {firstError(formErrors, "role") ? (
                                        <Text
                                            as="div"
                                            color="red"
                                            size="1"
                                            mt="1"
                                        >
                                            {firstError(formErrors, "role")}
                                        </Text>
                                    ) : null}
                                </label>

                                <label style={{ flex: "1 1 180px" }}>
                                    <Text
                                        as="div"
                                        size="2"
                                        mb="1"
                                        weight="medium"
                                    >
                                        Status
                                    </Text>
                                    <Select.Root
                                        value={form.status}
                                        onValueChange={(value) =>
                                            updateField(
                                                "status",
                                                value as UserStatus,
                                            )
                                        }
                                    >
                                        <Select.Trigger
                                            aria-label="User status"
                                            style={{ width: "100%" }}
                                        />
                                        <Select.Content position="popper">
                                            <Select.Item value="active">
                                                Active
                                            </Select.Item>
                                            <Select.Item value="inactive">
                                                Inactive
                                            </Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                    {firstError(formErrors, "status") ? (
                                        <Text
                                            as="div"
                                            color="red"
                                            size="1"
                                            mt="1"
                                        >
                                            {firstError(formErrors, "status")}
                                        </Text>
                                    ) : null}
                                </label>
                            </Flex>

                            <label>
                                <Text as="div" size="2" mb="1" weight="medium">
                                    Password {editingUser ? "(optional)" : ""}
                                </Text>
                                <TextField.Root
                                    type="password"
                                    value={form.password}
                                    onChange={(event) =>
                                        updateField(
                                            "password",
                                            event.target.value,
                                        )
                                    }
                                    placeholder={
                                        editingUser
                                            ? "Leave blank to keep current password"
                                            : "At least 8 characters"
                                    }
                                    autoComplete={
                                        editingUser
                                            ? "new-password"
                                            : "new-password"
                                    }
                                    required={!editingUser}
                                    minLength={8}
                                />
                                {firstError(formErrors, "password") ? (
                                    <Text as="div" color="red" size="1" mt="1">
                                        {firstError(formErrors, "password")}
                                    </Text>
                                ) : null}
                            </label>
                        </Flex>

                        <Flex justify="end" gap="2" mt="5">
                            <Dialog.Close>
                                <Button
                                    type="button"
                                    variant="soft"
                                    color="gray"
                                    disabled={isSaving}
                                    onClick={closeUserDialog}
                                >
                                    Cancel
                                </Button>
                            </Dialog.Close>
                            <Button type="submit" disabled={isSaving}>
                                {isSaving ? (
                                    <>
                                        <Spinner size="1" /> Saving…
                                    </>
                                ) : editingUser ? (
                                    "Save changes"
                                ) : (
                                    "Create user"
                                )}
                            </Button>
                        </Flex>
                    </form>
                </Dialog.Content>
            </Dialog.Root>

            <Dialog.Root
                open={deleteDialogOpen}
                onOpenChange={(open) => {
                    setDeleteDialogOpen(open);
                    if (!open) setDeleteError(null);
                }}
            >
                <Dialog.Content maxWidth="430px">
                    <Dialog.Title>Delete user?</Dialog.Title>
                    <Dialog.Description size="2" mb="3">
                        This will permanently delete{" "}
                        {deleteTarget
                            ? `${deleteTarget.first_name} ${deleteTarget.last_name} (${deleteTarget.email})`
                            : "this user"}
                        . This action cannot be undone.
                    </Dialog.Description>
                    {deleteError ? (
                        <Text color="red" size="2" mb="3" role="alert">
                            {deleteError}
                        </Text>
                    ) : null}
                    <Flex justify="end" gap="2">
                        <Dialog.Close>
                            <Button
                                type="button"
                                variant="soft"
                                color="gray"
                                disabled={deleteMutation.isPending}
                            >
                                Cancel
                            </Button>
                        </Dialog.Close>
                        <Button
                            type="button"
                            color="red"
                            disabled={!deleteTarget || deleteMutation.isPending}
                            onClick={() => {
                                if (deleteTarget)
                                    deleteMutation.mutate(deleteTarget.id);
                            }}
                        >
                            {deleteMutation.isPending ? (
                                <>
                                    <Spinner size="1" /> Deleting…
                                </>
                            ) : (
                                "Delete user"
                            )}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>
        </>
    );
}
