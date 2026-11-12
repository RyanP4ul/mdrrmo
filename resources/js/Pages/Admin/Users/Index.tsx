import { Head, router, useForm } from "@inertiajs/react";
import {
    Check,
    Eye,
    Pencil,
    Plus,
    Trash2,
    UserCheck,
    Users,
    X,
} from "lucide-react";
import { useState } from "react";
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
    barangay: string | null;
    city: string | null;
    province: string | null;
    id_type: string | null;
    id_document_path: string | null;
    registration_status: string | null;
    full_name: string;
}
interface Props {
    employees: Paginated<U>;
    residents: Paginated<U>;
    filters: Record<string, string>;
}

const blank = {
    first_name: "",
    last_name: "",
    middle_initial: "",
    email: "",
    contact_number: "",
    phone_number: "",
    date_of_birth: "",
    sex: "",
    role: "dispatcher",
    status: "active",
    barangay: "",
    city: "Meycauayan",
    province: "Bulacan",
};
export default function Index({ employees, residents, filters }: Props) {
    const { values, set } = useServerFilters(
        {
            emp_search: filters.emp_search ?? "",
            role: filters.role ?? "",
            status: filters.status ?? "",
            res_search: filters.res_search ?? "",
            reg: filters.reg ?? "",
        },
        ["employees", "residents"],
    );
    const [editing, setEditing] = useState<U | null>(null);
    const [open, setOpen] = useState(false);
    const [del, setDel] = useState<U | null>(null);
    const [view, setView] = useState<U | null>(null);
    const [decline, setDecline] = useState<U | null>(null);
    const form = useForm<typeof blank>(blank);
    const err = form.errors as Record<string, string>;
    const bind = (k: keyof typeof blank) => ({
        id: k,
        value: form.data[k] ?? "",
        onChange: (e: React.ChangeEvent<HTMLInputElement>) =>
            form.setData(k, e.target.value),
    });

    const openForm = (u?: U) => {
        setEditing(u ?? null);
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
            <Tabs defaultValue="employees" className="space-y-4">
                <TabsList>
                    <TabsTrigger value="employees">
                        Employees ({employees.total})
                    </TabsTrigger>
                    <TabsTrigger value="residents">
                        Residents ({residents.total})
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="employees" className="space-y-4">
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
                        <FilterSelect
                            label="Status"
                            value={values.status}
                            onChange={pick("status")}
                            options={["active", "inactive", "suspended"].map(
                                (r) => ({ value: r, label: r }),
                            )}
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
                                            <TableHead>Status</TableHead>
                                            <TableHead className="w-28 text-center">
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
                                                <TableCell>
                                                    <StatusBadge
                                                        value={u.status}
                                                    />
                                                </TableCell>
                                                <TableCell className="text-center">
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
                                                        aria-label={`Delete ${u.full_name}`}
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
                    <ServerPagination page={employees} noun="employee" />
                </TabsContent>

                <TabsContent value="residents" className="space-y-4">
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
                                            <TableHead className="w-40 text-center">
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
                                                    {u.registration_status ===
                                                        "pending" && (
                                                        <>
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                aria-label="Accept"
                                                                className="text-ok"
                                                                onClick={() =>
                                                                    review(
                                                                        u,
                                                                        "accepted",
                                                                    )
                                                                }
                                                            >
                                                                <Check className="size-4" />
                                                            </Button>
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                aria-label="Decline"
                                                                className="text-destructive"
                                                                onClick={() =>
                                                                    setDecline(u)
                                                                }
                                                            >
                                                                <X className="size-4" />
                                                            </Button>
                                                        </>
                                                    )}
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label="Delete"
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
                                ? "Update account details."
                                : "A temporary password is generated and shown once after saving."}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field
                            htmlFor="first_name"
                            label="First name" required
                            error={err.first_name}
                        >
                            <Input
                                type="text"
                                autoComplete="given-name"
                                {...bind("first_name")}
                            />
                        </Field>
                        <Field
                            htmlFor="last_name"
                            label="Last name" required
                            error={err.last_name}
                        >
                            <Input
                                type="text"
                                autoComplete="family-name"
                                {...bind("last_name")}
                            />
                        </Field>
                        <Field htmlFor="email" label="Email" required error={err.email}>
                            <Input
                                type="email"
                                autoComplete="off"
                                {...bind("email")}
                            />
                        </Field>
                        <Field
                            htmlFor="contact_number"
                            label="Contact" required
                            error={err.contact_number}
                        >
                            <Input
                                type="text"
                                inputMode="tel"
                                {...bind("contact_number")}
                            />
                        </Field>
                        {form.data.role === "dispatcher" && <Field htmlFor="phone_number" label="Dispatcher SOS phone number" error={err.phone_number}>
                            <Input type="tel" maxLength={28} {...bind("phone_number")} />
                            <p className="text-xs text-muted-foreground">Residents and guests use this public number for regular phone calls. Include the country or area code.</p>
                        </Field>}
                        <Field htmlFor="role" label="Role">
                            <Select
                                value={form.data.role}
                                disabled={!!editing}
                                onValueChange={(v) => form.setData("role", v)}
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
                                    Role is fixed because accounts are stored in
                                    separate tables.
                                </p>
                            )}
                        </Field>
                        <Field htmlFor="status" label="Status">
                            <Select
                                value={form.data.status}
                                onValueChange={(v) => form.setData("status", v)}
                            >
                                <SelectTrigger id="status">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {["active", "inactive", "suspended"].map(
                                        (r) => (
                                            <SelectItem
                                                key={r}
                                                value={r}
                                                className="capitalize"
                                            >
                                                {r}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                        </Field>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button onClick={save} disabled={form.processing}>
                            {editing ? "Save changes" : "Create account"}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={!!view} onOpenChange={(o) => !o && setView(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{view?.full_name}</DialogTitle>
                        <DialogDescription>{view?.email}</DialogDescription>
                    </DialogHeader>
                    <dl className="space-y-1.5 text-sm">
                        {[
                            ["Contact", view?.contact_number],
                            ...(view?.role === "dispatcher" ? [["SOS phone", view?.phone_number]] : []),
                            ["Birth date", view?.date_of_birth?.slice(0, 10)],
                            ["Sex", view?.sex],
                            [
                                "Address",
                                [view?.barangay, view?.city, view?.province]
                                    .filter(Boolean)
                                    .join(", "),
                            ],
                            ["ID type", view?.id_type],
                        ].map(([k, v]) =>
                            v ? (
                                <div key={k} className="flex justify-between">
                                    <dt className="text-muted-foreground">
                                        {k}
                                    </dt>
                                    <dd className="font-medium">{v}</dd>
                                </div>
                            ) : null,
                        )}
                    </dl>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={!!decline}
                onOpenChange={(o) => !o && setDecline(null)}
                title="Decline registration"
                description={`${decline?.full_name}'s registration will be declined and they will not be able to report or book services.`}
                confirmLabel="Decline"
                onConfirm={() => {
                    review(decline!, "declined");
                    setDecline(null);
                }}
            />

            <ConfirmDialog
                open={!!del}
                onOpenChange={(o) => !o && setDel(null)}
                title="Delete user"
                description={`${del?.full_name} will be permanently removed.`}
                onConfirm={() => {
                    router.delete(
                        route("admin.users.destroy", del!.account_key),
                        { preserveScroll: true },
                    );
                    setDel(null);
                }}
            />
        </AppLayout>
    );
}
