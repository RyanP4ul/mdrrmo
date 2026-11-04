import { Head, router, useForm } from "@inertiajs/react";
import { route } from "ziggy-js";
import { CalendarDays } from "lucide-react";
import AppLayout from "@/Layouts/AppLayout";
import { PageHeader } from "@/components/PageHeader";
import { EmptyState } from "@/components/EmptyState";
import { StatusBadge } from "@/components/StatusBadge";
import { ServerPagination } from "@/components/ServerPagination";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import type { Paginated } from "@/types";
import type { ServiceSchedule } from "@/types/website";

export default function Schedule({
    services,
    schedules,
}: {
    services: string[];
    schedules: Paginated<ServiceSchedule>;
}) {
    const form = useForm({
        service_type: "",
        preferred_date: "",
        preferred_time: "09:00",
        address: "",
        notes: "",
    });
    const e = form.errors as Record<string, string>;
    const err = (k: string) =>
        e[k] && <p className="text-xs text-destructive">{e[k]}</p>;
    return (
        <AppLayout>
            <Head title="Book a Service" />
            <PageHeader
                icon={CalendarDays}
                title="Book a service"
                description="Request ambulance standby, trainings and other MDRRMO services"
            />
            <div className="grid gap-4 lg:grid-cols-5">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle className="text-base">New request</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="space-y-1.5">
                            <Label>Service *</Label>
                            <Select
                                value={form.data.service_type}
                                onValueChange={(v) =>
                                    form.setData("service_type", v)
                                }
                            >
                                <SelectTrigger aria-label="Service">
                                    <SelectValue placeholder="Choose a service" />
                                </SelectTrigger>
                                <SelectContent>
                                    {services.map((s) => (
                                        <SelectItem key={s} value={s}>
                                            {s}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {err("service_type")}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="d">Date *</Label>
                                <Input
                                    id="d"
                                    type="date"
                                    min={new Date().toISOString().slice(0, 10)}
                                    value={form.data.preferred_date}
                                    onChange={(ev) =>
                                        form.setData(
                                            "preferred_date",
                                            ev.target.value,
                                        )
                                    }
                                />
                                {err("preferred_date")}
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="t">Time *</Label>
                                <Input
                                    id="t"
                                    type="time"
                                    value={form.data.preferred_time}
                                    onChange={(ev) =>
                                        form.setData(
                                            "preferred_time",
                                            ev.target.value,
                                        )
                                    }
                                />
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="a">Address *</Label>
                            <Input
                                id="a"
                                value={form.data.address}
                                onChange={(ev) =>
                                    form.setData("address", ev.target.value)
                                }
                            />
                            {err("address")}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="n">Notes</Label>
                            <Textarea
                                id="n"
                                rows={3}
                                value={form.data.notes}
                                onChange={(ev) =>
                                    form.setData("notes", ev.target.value)
                                }
                            />
                        </div>
                        <Button
                            className="w-full"
                            disabled={form.processing}
                            onClick={() =>
                                form.post(route("resident.schedule.store"), {
                                    preserveScroll: true,
                                    onSuccess: () => form.reset(),
                                })
                            }
                        >
                            Submit request
                        </Button>
                    </CardContent>
                </Card>

                <div className="space-y-3 lg:col-span-3">
                    {schedules.data.length === 0 ? (
                        <Card>
                            <EmptyState
                                icon={CalendarDays}
                                title="No requests yet"
                            />
                        </Card>
                    ) : (
                        schedules.data.map((s) => (
                            <Card key={s.id}>
                                <CardContent className="space-y-1.5 p-4 text-sm">
                                    <div className="flex items-start justify-between gap-2">
                                        <p className="font-medium">
                                            {s.service_type}
                                        </p>
                                        <StatusBadge value={s.status} />
                                    </div>
                                    <p className="text-muted-foreground">
                                        {s.preferred_date?.slice(0, 10)} ·{" "}
                                        {s.preferred_time} · {s.address}
                                    </p>
                                    {s.decline_reason && (
                                        <p className="text-destructive">
                                            Declined: {s.decline_reason}
                                        </p>
                                    )}
                                    {["pending", "confirmed"].includes(
                                        s.status,
                                    ) && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.patch(
                                                    route(
                                                        "resident.schedule.cancel",
                                                        s.id,
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Cancel request
                                        </Button>
                                    )}
                                </CardContent>
                            </Card>
                        ))
                    )}
                    <ServerPagination page={schedules} noun="request" />
                </div>
            </div>
        </AppLayout>
    );
}
