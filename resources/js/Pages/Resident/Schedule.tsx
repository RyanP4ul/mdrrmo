import { Head, router, useForm, usePoll } from "@inertiajs/react";
import { useState, type FormEvent } from "react";
import { route } from "ziggy-js";
import { CalendarDays } from "lucide-react";
import AppLayout from "@/Layouts/AppLayout";
import { PageHeader } from "@/components/PageHeader";
import { EmptyState } from "@/components/EmptyState";
import { StatusBadge } from "@/components/StatusBadge";
import { ServerPagination } from "@/components/ServerPagination";
import { ConfirmDialog } from "@/components/ConfirmDialog";
import { Field } from "@/components/Field";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import type { Paginated } from "@/types";
import type { ServiceSchedule } from "@/types/website";

export default function Schedule({ services, schedules }: { services: string[]; schedules: Paginated<ServiceSchedule> }) {
    usePoll(15000, { only: ["schedules"] });
    const [cancelId, setCancelId] = useState<number | null>(null);
    const form = useForm({ service_type: "", preferred_date: "", preferred_time: "09:00", address: "", notes: "" });
    const e = form.errors as Record<string, string>;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route("resident.schedule.store"), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <AppLayout>
            <Head title="Book a Service" />
            <PageHeader title="Book a service" description="Request ambulance standby, trainings and other MDRRMO services." />
            <div className="grid items-start gap-5 lg:grid-cols-[22rem_minmax(0,1fr)]">
                <form onSubmit={submit} className="space-y-4 rounded-lg border bg-card p-5" aria-busy={form.processing}>
                    <h2 className="text-sm font-semibold">New request</h2>
                    <Field label="Service" htmlFor="service" required error={e.service_type}>
                        <Select value={form.data.service_type} onValueChange={(v) => form.setData("service_type", v)}>
                            <SelectTrigger id="service" className="w-full"><SelectValue placeholder="Choose a service" /></SelectTrigger>
                            <SelectContent>{services.map((s) => <SelectItem key={s} value={s}>{s}</SelectItem>)}</SelectContent>
                        </Select>
                    </Field>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Date" htmlFor="d" required error={e.preferred_date}>
                            <Input id="d" type="date" min={new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Manila" })} value={form.data.preferred_date} onChange={(ev) => form.setData("preferred_date", ev.target.value)} />
                        </Field>
                        <Field label="Time" htmlFor="t" required error={e.preferred_time}>
                            <Input id="t" type="time" value={form.data.preferred_time} onChange={(ev) => form.setData("preferred_time", ev.target.value)} />
                        </Field>
                    </div>
                    <Field label="Address" htmlFor="a" required error={e.address}>
                        <Input id="a" autoComplete="street-address" value={form.data.address} onChange={(ev) => form.setData("address", ev.target.value)} />
                    </Field>
                    <Field label="Notes" htmlFor="n" error={e.notes}>
                        <Textarea id="n" rows={3} value={form.data.notes} onChange={(ev) => form.setData("notes", ev.target.value)} />
                    </Field>
                    <Button type="submit" className="w-full" disabled={form.processing}>{form.processing ? "Submitting…" : "Submit request"}</Button>
                </form>

                <section aria-labelledby="requests-heading" className="space-y-3">
                    <h2 id="requests-heading" className="text-sm font-semibold">Your requests</h2>
                    {schedules.data.length === 0 ? (
                        <div className="rounded-lg border bg-card"><EmptyState icon={CalendarDays} title="No requests yet" hint="Requests you submit will appear here with their status." /></div>
                    ) : (
                        <ul className="divide-y rounded-lg border bg-card">
                            {schedules.data.map((s) => (
                                <li key={s.id} className="space-y-1 p-4 text-sm">
                                    <div className="flex items-start justify-between gap-2">
                                        <p className="font-medium">{s.service_type}</p>
                                        <StatusBadge value={s.status} />
                                    </div>
                                    <p className="text-muted-foreground tabular-nums">{s.preferred_date?.slice(0, 10)} · {s.preferred_time} · {s.address}</p>
                                    {s.decline_reason && <p className="text-critical">Declined: {s.decline_reason}</p>}
                                    {["pending", "acknowledged", "confirmed"].includes(s.status) && (
                                        <Button size="sm" variant="outline" className="mt-1" onClick={() => setCancelId(s.id)}>Cancel request</Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                    <ServerPagination page={schedules} noun="request" />
                </section>
            </div>

            <ConfirmDialog open={cancelId !== null} onOpenChange={(open) => !open && setCancelId(null)}
                title="Cancel this request?" description="The MDRRMO team will be told the request is cancelled. You can submit a new one later."
                confirmLabel="Cancel request"
                onConfirm={() => { if (cancelId !== null) router.patch(route("resident.schedule.cancel", cancelId), {}, { preserveScroll: true }); setCancelId(null); }} />
        </AppLayout>
    );
}
