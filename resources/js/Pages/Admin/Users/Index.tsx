import { Head, router, useForm } from "@inertiajs/react";
import {
    Check,
    Eye,
    EyeOff,
    Pencil,
    Plus,
    Trash2,
    UserCheck,
    UserX,
    Users,
    X,
} from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";
import { route } from "ziggy-js";
import AppLayout from "@/Layouts/AppLayout";
import { PageHeader } from "@/components/PageHeader";
import { FilterBar, FilterSelect } from "@/components/FilterBar";
import { EmptyState } from "@/components/EmptyState";
import { StatusBadge } from "@/components/StatusBadge";
import { ServerPagination } from "@/components/ServerPagination";
import { ConfirmDialog } from "@/components/ConfirmDialog";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Field } from "@/components/Field";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useServerFilters } from "@/hooks/useServerFilters";
import { formatDateTime } from "@/lib/utils";
import type { Paginated } from "@/types";

interface U {
    id: number;
    account_key: string;
    first_name: string;
    last_name: string;
    middle_initial: string | null;
    email: string;
    contact_number: string | null;
    phone_number?: string | null;
    date_of_birth: string | null;
    sex: string | null;
    role: string;
    status: string;
    shift_team?: "day" | "night" | null;
    house_no?: string | null;
    street?: string | null;
    barangay: string | null;
    city: string | null;
    province: string | null;
    id_type: string | null;
    id_document_path: string | null;
    id_document_url?: string | null;
    registration_status: string | null;
    created_at?: string | null;
    email_verified_at?: string | null;
    reports_count?: number;
    schedules_count?: number;
    full_name: string;
}
interface Props {
    employees: Paginated<U>;
    residents: Paginated<U>;
    filters: Record<string, string>;
    counts?: {
        employees_active: number;
        employees_inactive: number;
        residents_active: number;
        residents_inactive: number;
    };
}

const computeAge = (dob?: string | null): number | null => {
    if (!dob) return null;
    const birth = new Date(dob);
    if (Number.isNaN(birth.getTime())) return null;
    const today = new Date();
    let age = today.getFullYear() - birth.getFullYear();
    const m = today.getMonth() - birth.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) age--;
    return age >= 0 ? age : null;
};

const blank = {
    first_name: "",
    last_name: "",
    middle_initial: "",
    email: "",
    password: "",
    contact_number: "",
    phone_number: "",
    date_of_birth: "",
    sex: "",
    role: "dispatcher",
    status: "active",
    shift_team: "",
    barangay: "",
    city: "Meycauayan",
    province: "Bulacan",
};
export default function Index({ employees, residents, filters, counts }: Props) {
    const { values, set } = useServerFilters(
        {
            emp_search: filters.emp_search ?? "",
            role: filters.role ?? "",
            status: filters.status ?? "active",
            res_search: filters.res_search ?? "",
            reg: filters.reg ?? "",
            res_status: filters.res_status ?? "active",
        },
        ["employees", "residents", "counts", "filters"],
    );
    const [tab, setTab] = useState<string>(() => {
        if (filters.res_search || filters.reg || (filters.res_status && filters.res_status !== "active")) return "residents";
        if (typeof window !== "undefined") {
            const params = new URLSearchParams(window.location.search);
            if (params.has("res_page")) return "residents";
        }
        return "employees";
    });
    const [editing, setEditing] = useState<U | null>(null);
    const [open, setOpen] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const [del, setDel] = useState<U | null>(null);
    const [view, setView] = useState<U | null>(null);
    const [accept, setAccept] = useState<U | null>(null);
    const [decline, setDecline] = useState<U | null>(null);
    const form = useForm<typeof blank>(blank);
    const err = form.errors as Record<string, string>;
    const bind = (k: keyof typeof blank) => ({
        id: k,
        name: k,
        value: form.data[k] ?? "",
        onChange: (e: React.ChangeEvent<HTMLInputElement>) =>
            form.setData(k, e.target.value),
    });

    const openForm = (u?: U) => {
        setEditing(u ?? null);
        setShowPassword(false);
        form.clearErrors();
        u
            ? form.setData({
                  ...blank,
                  ...Object.fromEntries(
                      Object.entries(u)
                          .filter(([k]) => k in blank)
                          .map(([k, v]) => [k, v ?? ""]),
                  ),
              } as typeof blank)
            : form.setData(blank);
        setOpen(true);
    };
    const save = () => {
        const o = { preserveScroll: true, onSuccess: () => setOpen(false) };
        form.transform((data) => ({
            ...data,
            password: editing ? data.password || null : data.password,
            shift_team: data.shift_team || null,
        }));
        editing
            ? form.put(route("admin.users.update", editing.account_key), o)
            : form.post(route("admin.users.store"), o);
    };
    const review = (u: U, decision: "accepted" | "declined") =>
        router.patch(
            route("admin.residents.review", u.account_key),
            { decision },
            { preserveScroll: true },
        );
    const toggleStatus = (u: U) =>
        router.patch(
            route("admin.users.status", u.account_key),
            { status: u.status === "active" ? "inactive" : "active" },
            { preserveScroll: true, onError: (errors) => toast.error(errors.status || "Could not update account status.") },
        );
    const pick = (k: string) => (v: string) =>
        set(k as "role", v === "all" ? "" : v);

    return (
        <AppLayout>
            <Head title="User Management" />
            <PageHeader
                icon={Users}
                title="User Management"
                description="Employee accounts and resident registrations"
            />
            <Tabs value={tab} onValueChange={setTab} className="space-y-4">
                <TabsList>
                    <TabsTrigger value="employees">
                        Employees ({employees.total})
                    </TabsTrigger>
                    <TabsTrigger value="residents">
                        Residents ({residents.total})
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="employees" className="space-y-4">
                    <Tabs
                        value={values.status === "inactive" ? "inactive" : "active"}
                        onValueChange={(nextStatus) => set("status", nextStatus)}
                    >
                        <TabsList aria-label="Employee status tabs">
                            <TabsTrigger value="active">
                                <UserCheck className="mr-1.5 size-3.5" />
                                Active ({counts?.employees_active ?? (values.status !== "inactive" ? employees.total : 0)})
                            </TabsTrigger>
                            <TabsTrigger value="inactive">
                                <UserX className="mr-1.5 size-3.5" />
                                Inactive ({counts?.employees_inactive ?? (values.status === "inactive" ? employees.total : 0)})
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>

                    <FilterBar
                        search={values.emp_search}
                        onSearch={(v) => set("emp_search", v)}
                        placeholder="Search name or contact…"
                    >
                        <FilterSelect
                            label="Roles"
                            value={values.role}
                            onChange={pick("role")}
                            options={[
                                "admin",
                                "dispatcher",
                                "driver/responder",
                            ].map((r) => ({ value: r, label: r }))}
                        />
                        <Button onClick={() => openForm()}>
                            <Plus className="mr-1 size-4" />
                            Add employee
                        </Button>
                    </FilterBar>
                    <Card>
                        <CardContent className="overflow-x-auto p-0">
                            {employees.data.length === 0 ? (
                                <EmptyState
                                    icon={Users}
                                    title="No employees found"
                                />
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Name</TableHead>
                                            <TableHead className="hidden md:table-cell">Email</TableHead>
                                            <TableHead className="hidden sm:table-cell">Contact</TableHead>
                                            <TableHead>Role</TableHead>
                                            <TableHead className="hidden md:table-cell">Shift</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead className="w-44 text-center">
                                                Actions
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {employees.data.map((u) => (
                                            <TableRow key={u.id}>
                                                <TableCell className="font-medium">
                                                    {u.full_name}
                                                </TableCell>
                                                <TableCell className="hidden text-muted-foreground md:table-cell">
                                                    {u.email}
                                                </TableCell>
                                                <TableCell className="hidden sm:table-cell">
                                                    {u.contact_number}
                                                </TableCell>
                                                <TableCell className="capitalize">
                                                    {u.role}
                                                </TableCell>
                                                <TableCell className="hidden md:table-cell text-muted-foreground">
                                                    {u.shift_team === "day"
                                                        ? "Day"
                                                        : u.shift_team === "night"
                                                          ? "Night"
                                                          : u.role === "driver/responder"
                                                            ? "Unassigned"
                                                            : "—"}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        value={u.status}
                                                    />
                                                </TableCell>
                                                <TableCell className="text-center">
                                                    <div className="inline-flex items-center gap-1">
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className={
                                                                u.status === "active"
                                                                    ? "text-amber-600 hover:text-amber-700"
                                                                    : "text-emerald-600 hover:text-emerald-700"
                                                            }
                                                            aria-label={
                                                                u.status === "active"
                                                                    ? `Deactivate ${u.full_name}`
                                                                    : `Activate ${u.full_name}`
                                                            }
                                                            onClick={() =>
                                                                toggleStatus(u)
                                                            }
                                                        >
                                                            {u.status === "active" ? (
                                                                <>
                                                                    <UserX className="mr-1 size-4" />
                                                                    Deactivate
                                                                </>
                                                            ) : (
                                                                <>
                                                                    <UserCheck className="mr-1 size-4" />
                                                                    Activate
                                                                </>
                                                            )}
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label={`Edit ${u.full_name}`}
                                                            onClick={() =>
                                                                openForm(u)
                                                            }
                                                        >
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label={`Archive ${u.full_name}`}
                                                            disabled={u.status !== "inactive"}
                                                            className="text-destructive"
                                                            onClick={() =>
                                                                setDel(u)
                                                            }
                                                        >
                                                            <Trash2 className="size-4" />
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>
                    <ServerPagination page={employees} noun="employee" />
                </TabsContent>

                <TabsContent value="residents" className="space-y-4">
                    <Tabs
                        value={values.res_status === "inactive" ? "inactive" : "active"}
                        onValueChange={(nextStatus) => set("res_status", nextStatus)}
                    >
                        <TabsList aria-label="Resident status tabs">
                            <TabsTrigger value="active">
                                <UserCheck className="mr-1.5 size-3.5" />
                                Active ({counts?.residents_active ?? (values.res_status !== "inactive" ? residents.total : 0)})
                            </TabsTrigger>
                            <TabsTrigger value="inactive">
                                <UserX className="mr-1.5 size-3.5" />
                                Inactive ({counts?.residents_inactive ?? (values.res_status === "inactive" ? residents.total : 0)})
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>

                    <FilterBar
                        search={values.res_search}
                        onSearch={(v) => set("res_search", v)}
                        placeholder="Search name, contact or barangay…"
                    >
                        <FilterSelect
                            label="Registration"
                            value={values.reg}
                            onChange={pick("reg")}
                            options={["pending", "accepted", "declined"].map(
                                (r) => ({ value: r, label: r }),
                            )}
                        />
                    </FilterBar>
                    <Card>
                        <CardContent className="overflow-x-auto p-0">
                            {residents.data.length === 0 ? (
                                <EmptyState
                                    icon={UserCheck}
                                    title="No residents found"
                                />
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Name</TableHead>
                                            <TableHead className="hidden md:table-cell">Barangay</TableHead>
                                            <TableHead className="hidden sm:table-cell">Contact</TableHead>
                                            <TableHead>Registration</TableHead>
                                            <TableHead className="w-28 text-center">
                                                Actions
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {residents.data.map((u) => (
                                            <TableRow key={u.id}>
                                                <TableCell className="font-medium">
                                                    {u.last_name},{" "}
                                                    {u.first_name}
                                                </TableCell>
                                                <TableCell className="hidden md:table-cell">
                                                    {u.barangay}
                                                </TableCell>
                                                <TableCell className="hidden sm:table-cell">
                                                    {u.contact_number}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        value={
                                                            u.registration_status ??
                                                            "pending"
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="text-center">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label="View details"
                                                        onClick={() =>
                                                            setView(u)
                                                        }
                                                    >
                                                        <Eye className="size-4" />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Archive ${u.full_name}`}
                                                        disabled={u.status !== "inactive"}
                                                        className="text-destructive"
                                                        onClick={() =>
                                                            setDel(u)
                                                        }
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>
                    <ServerPagination page={residents} noun="resident" />
                </TabsContent>
            </Tabs>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? "Edit employee" : "Add employee"}
                        </DialogTitle>
                        <DialogDescription>
                            {editing
                                ? "Update account details or set a new password."
                                : "Enter account details and set a password for the employee."}
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            save();
                        }}
                        className="space-y-4"
                    >
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field
                                htmlFor="first_name"
                                label="First name"
                                required
                                error={err.first_name}
                            >
                                <Input
                                    type="text"
                                    autoComplete="given-name"
                                    required
                                    {...bind("first_name")}
                                />
                            </Field>
                            <Field
                                htmlFor="last_name"
                                label="Last name"
                                required
                                error={err.last_name}
                            >
                                <Input
                                    type="text"
                                    autoComplete="family-name"
                                    required
                                    {...bind("last_name")}
                                />
                            </Field>
                            <Field
                                htmlFor="email"
                                label="Email"
                                required
                                error={err.email}
                            >
                                <Input
                                    type="email"
                                    autoComplete="off"
                                    spellCheck={false}
                                    required
                                    {...bind("email")}
                                />
                            </Field>
                            <Field
                                htmlFor="contact_number"
                                label="Contact"
                                required
                                error={err.contact_number}
                            >
                                <Input
                                    type="tel"
                                    inputMode="tel"
                                    required
                                    {...bind("contact_number")}
                                />
                            </Field>
                            <Field
                                htmlFor="password"
                                label={editing ? "New password" : "Password"}
                                required={!editing}
                                hint={
                                    editing
                                        ? "Leave blank to keep the current password."
                                        : "At least 8 characters."
                                }
                                error={err.password}
                            >
                                <div className="relative">
                                    <Input
                                        type={
                                            showPassword ? "text" : "password"
                                        }
                                        autoComplete="new-password"
                                        minLength={8}
                                        maxLength={128}
                                        required={!editing}
                                        aria-invalid={!!err.password}
                                        className="pr-10"
                                        {...bind("password")}
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="absolute right-0.5 top-1/2 size-8 -translate-y-1/2 text-muted-foreground"
                                        onClick={() =>
                                            setShowPassword((prev) => !prev)
                                        }
                                        aria-label={
                                            showPassword
                                                ? "Hide password"
                                                : "Show password"
                                        }
                                        aria-pressed={showPassword}
                                        aria-controls="password"
                                    >
                                        {showPassword ? (
                                            <EyeOff className="size-4" />
                                        ) : (
                                            <Eye className="size-4" />
                                        )}
                                    </Button>
                                </div>
                            </Field>
                            <Field htmlFor="role" label="Role">
                                <Select
                                    value={form.data.role}
                                    disabled={!!editing}
                                    onValueChange={(v) =>
                                        form.setData("role", v)
                                    }
                                >
                                    <SelectTrigger id="role">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {[
                                            "admin",
                                            "dispatcher",
                                            "driver/responder",
                                        ].map((r) => (
                                            <SelectItem
                                                key={r}
                                                value={r}
                                                className="capitalize"
                                            >
                                                {r}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {editing && (
                                    <p className="text-xs text-muted-foreground">
                                        Role is fixed because accounts are
                                        stored in separate tables.
                                    </p>
                                )}
                            </Field>
                            {(form.data.role === "dispatcher" ||
                                form.data.role === "driver/responder") && (
                                <Field
                                    htmlFor="shift_team"
                                    label="Shift Team"
                                    error={err.shift_team}
                                >
                                    <Select
                                        value={form.data.shift_team || "none"}
                                        onValueChange={(v) =>
                                            form.setData(
                                                "shift_team",
                                                v === "none" ? "" : v,
                                            )
                                        }
                                    >
                                        <SelectTrigger id="shift_team">
                                            <SelectValue placeholder="Unassigned" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">
                                                Unassigned
                                            </SelectItem>
                                            <SelectItem value="day">
                                                Day Team
                                            </SelectItem>
                                            <SelectItem value="night">
                                                Night Team
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </Field>
                            )}
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {editing ? "Save changes" : "Create account"}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={!!view} onOpenChange={(o) => !o && setView(null)}>
                <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <div className="flex flex-wrap items-center gap-2">
                            <DialogTitle>
                                {[
                                    view?.first_name,
                                    view?.middle_initial ? `${view.middle_initial}.` : null,
                                    view?.last_name,
                                ]
                                    .filter(Boolean)
                                    .join(" ") || view?.full_name}
                            </DialogTitle>
                            {view?.registration_status && (
                                <StatusBadge value={view.registration_status} />
                            )}
                            {view?.status && <StatusBadge value={view.status} />}
                        </div>
                        <DialogDescription>
                            {view?.email}
                            {view?.email_verified_at ? " · Verified" : " · Unverified"}
                        </DialogDescription>
                    </DialogHeader>
                    {view && (
                        <div className="space-y-4 text-sm">
                            <dl className="space-y-1.5">
                                {(() => {
                                    const age = computeAge(view.date_of_birth);
                                    const birthText = view.date_of_birth
                                        ? `${view.date_of_birth.slice(0, 10)}${age !== null ? ` (${age} yrs)` : ""}`
                                        : null;
                                    const streetLine = [view.house_no, view.street]
                                        .filter(Boolean)
                                        .join(" ");
                                    const fullAddress =
                                        [streetLine, view.barangay, view.city, view.province]
                                            .filter(Boolean)
                                            .join(", ") || "Not provided";

                                    return [
                                        ["Contact", view.contact_number],
                                        ["Birth date", birthText],
                                        ["Sex", view.sex],
                                        ["Address", fullAddress],
                                        ["Barangay", view.barangay],
                                        ["ID type", view.id_type],
                                        [
                                            "Registered",
                                            view.created_at
                                                ? formatDateTime(view.created_at)
                                                : null,
                                        ],
                                    ];
                                })().map(([k, v]) =>
                                    v ? (
                                        <div key={k} className="flex justify-between gap-4">
                                            <dt className="shrink-0 text-muted-foreground">
                                                {k}
                                            </dt>
                                            <dd className="text-right font-medium">{v}</dd>
                                        </div>
                                    ) : null,
                                )}
                            </dl>

                            <div className="grid grid-cols-2 gap-2 border-t pt-3">
                                <div className="rounded-md border bg-muted/30 px-3 py-2">
                                    <p className="text-xs text-muted-foreground">
                                        Emergency Reports
                                    </p>
                                    <p className="text-base font-semibold">
                                        {view.reports_count ?? 0}
                                    </p>
                                </div>
                                <div className="rounded-md border bg-muted/30 px-3 py-2">
                                    <p className="text-xs text-muted-foreground">
                                        Service Bookings
                                    </p>
                                    <p className="text-base font-semibold">
                                        {view.schedules_count ?? 0}
                                    </p>
                                </div>
                            </div>
                        </div>
                    )}
                    {view?.id_document_url && (
                        <div className="space-y-1.5 border-t pt-3">
                            <p className="text-xs font-medium text-muted-foreground">
                                Uploaded ID
                            </p>
                            <a
                                href={view.id_document_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="block overflow-hidden rounded-md border bg-muted/30"
                            >
                                <img
                                    src={view.id_document_url}
                                    alt={`ID document for ${view.full_name}`}
                                    className="max-h-64 w-full object-contain"
                                />
                            </a>
                        </div>
                    )}
                    {view?.registration_status === "pending" && (
                        <DialogFooter className="border-t pt-3">
                            <Button
                                type="button"
                                variant="outline"
                                className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                onClick={() => setDecline(view)}
                            >
                                <X className="size-4" aria-hidden="true" />
                                Decline
                            </Button>
                            <Button
                                type="button"
                                className="bg-[#17703f] text-white hover:bg-[#135c33]"
                                onClick={() => setAccept(view)}
                            >
                                <Check className="size-4" aria-hidden="true" />
                                Accept
                            </Button>
                        </DialogFooter>
                    )}
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={!!accept}
                onOpenChange={(o) => !o && setAccept(null)}
                title="Accept registration"
                description={`${accept?.full_name}'s registration will be accepted and they will be able to submit reports and book services.`}
                confirmLabel="Accept"
                variant="default"
                onConfirm={() => {
                    review(accept!, "accepted");
                    setAccept(null);
                    setView(null);
                }}
            />

            <ConfirmDialog
                open={!!decline}
                onOpenChange={(o) => !o && setDecline(null)}
                title="Decline registration"
                description={`${decline?.full_name}'s registration will be declined and they will not be able to report or book services.`}
                confirmLabel="Decline"
                onConfirm={() => {
                    review(decline!, "declined");
                    setDecline(null);
                    setView(null);
                }}
            />

            <ConfirmDialog
                open={!!del}
                onOpenChange={(o) => !o && setDel(null)}
                title="Move user to Archives"
                description={`${del?.full_name} will be moved to Archives and signed out. You can restore them or start the 30-day permanent deletion countdown inside Archives.`}
                confirmLabel="Archive"
                onConfirm={() => {
                    router.delete(
                        route("admin.users.destroy", del!.account_key),
                        {
                            preserveScroll: true,
                            onSuccess: () => setDel(null),
                            onError: (errors) => toast.error(errors.user || "Could not archive this account."),
                        },
                    );
                }}
            />
        </AppLayout>
    );
}
