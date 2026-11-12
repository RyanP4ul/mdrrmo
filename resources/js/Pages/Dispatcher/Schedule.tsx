import { Head, router, usePoll } from "@inertiajs/react";
import { route } from "ziggy-js";
import {
    CalendarDays,
    Check,
    ChevronLeft,
    ChevronRight,
    X,
} from "lucide-react";
import { useMemo, useState } from "react";
import AppLayout from "@/Layouts/AppLayout";
import { PageHeader } from "@/components/PageHeader";
import { EmptyState } from "@/components/EmptyState";
import { StatusBadge } from "@/components/StatusBadge";
import { FilterSelect } from "@/components/FilterBar";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Textarea } from "@/components/ui/textarea";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { cn } from "@/lib/utils";

interface S {
    id: number;
    date: string;
    service_type: string;
    preferred_time: string;
    address: string;
    notes: string | null;
    status: string;
    resident: string;
    contact: string | null;
    decline_reason: string | null;
    team_name: string | null;
    needs_assignment: boolean;
}

interface Team {
    id: number;
    team_name: string;
}

export default function Schedule({
    schedules,
    month,
    filters,
    teams,
    slotMinutes,
}: {
    schedules: S[];
    month: string;
    filters: { status?: string };
    teams: Team[];
    slotMinutes: number;
}) {
    usePoll(15000, { only: ['schedules', 'teams'] });
    const [actionError, setActionError] = useState<string | null>(null);
    const [acknowledging, setAcknowledging] = useState<number | null>(null);
    const [day, setDay] = useState<string | null>(null);
    const [declining, setDeclining] = useState<S | null>(null);
    const [confirming, setConfirming] = useState<S | null>(null);
    const [reason, setReason] = useState("");
    const [declineError, setDeclineError] = useState<string | null>(null);
    const [declineBusy, setDeclineBusy] = useState(false);
    const [teamId, setTeamId] = useState("");
    const [teamError, setTeamError] = useState<string | null>(null);
    const [confirmingBusy, setConfirmingBusy] = useState(false);
    const [y, m] = month.split("-").map(Number);
    const go = (delta: number, status = filters.status) => {
        setDay(null);
        const d = new Date(y, m - 1 + delta, 1);
        router.get(
            route("dispatcher.schedule.index"),
            {
                month: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`,
                ...(status ? { status } : {}),
            },
            { preserveScroll: true, preserveState: true },
        );
    };

    const byDay = useMemo(
        () =>
            schedules.reduce<Record<string, S[]>>(
                (a, s) => ((a[s.date] ??= []).push(s), a),
                {},
            ),
        [schedules],
    );
    const cells = useMemo(() => {
        const first = new Date(y, m - 1, 1).getDay(),
            days = new Date(y, m, 0).getDate();
        return [
            ...Array(first).fill(null),
            ...Array.from({ length: days }, (_, i) => i + 1),
        ];
    }, [y, m]);
    const key = (d: number) => `${month}-${String(d).padStart(2, "0")}`;
    const queue = day ? schedules.filter((s) => s.date === day) : schedules;
    const confirmSchedule = () => {
        if (!confirming || !teamId) {
            setTeamError("Choose a response team to continue.");
            return;
        }

        setConfirmingBusy(true);
        router.patch(
            route("dispatcher.schedule.acknowledge", confirming.id),
            { team_id: Number(teamId) },
            {
                preserveScroll: true,
                onSuccess: () => setConfirming(null),
                onError: (errors) =>
                    setTeamError(
                        errors.team_id ??
                            errors.team ?? errors.preferred_time ?? errors.decision ??
                            "The schedule could not be confirmed.",
                    ),
                onFinish: () => setConfirmingBusy(false),
            },
        );
    };

    return (
        <AppLayout>
            <Head title="Schedule" />
            <PageHeader
                icon={CalendarDays}
                title="Service schedule"
                description="Acknowledge requests, then assign a response team to confirm the service."
            />
            {actionError && <p role="alert" className="mb-4 text-sm text-destructive">{actionError}</p>}
            <div className="grid items-start gap-5 lg:grid-cols-5">
                <Card className="lg:col-span-3">
                    <CardHeader className="flex-row items-center justify-between space-y-0">
                        <CardTitle>
                            {new Date(y, m - 1).toLocaleDateString("en-PH", {
                                month: "long",
                                year: "numeric",
                            })}
                        </CardTitle>
                        <div className="flex gap-1">
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Previous month"
                                onClick={() => go(-1)}
                            >
                                <ChevronLeft className="size-4" />
                            </Button>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Next month"
                                onClick={() => go(1)}
                            >
                                <ChevronRight className="size-4" />
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-7 gap-1 text-center text-xs text-muted-foreground">
                            {[
                                "Sun",
                                "Mon",
                                "Tue",
                                "Wed",
                                "Thu",
                                "Fri",
                                "Sat",
                            ].map((d) => (
                                <div key={d} className="py-1 font-medium">
                                    {d}
                                </div>
                            ))}
                        </div>
                        <div className="grid grid-cols-7 gap-1">
                            {cells.map((d, i) =>
                                d === null ? (
                                    <div key={i} />
                                ) : (
                                    <button
                                        key={i}
                                        type="button"
                                        onClick={() =>
                                            setDay(
                                                day === key(d) ? null : key(d),
                                            )
                                        }
                                        aria-pressed={day === key(d)}
                                        aria-label={`${key(d)}: ${byDay[key(d)]?.length ?? 0} requests`}
                                        className={cn(
                                            "flex aspect-square flex-col items-center justify-center rounded-md border text-sm tabular-nums transition-colors hover:bg-muted max-sm:aspect-auto max-sm:min-h-11",
                                            day === key(d) &&
                                                "border-primary bg-primary/10 hover:bg-primary/10",
                                            key(d) ===
                                                new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Manila" }) &&
                                                "font-bold ring-1 ring-foreground/30",
                                        )}
                                    >
                                        {d}
                                        {byDay[key(d)] && (
                                            <span className="mt-0.5 rounded-sm bg-primary px-1.5 text-[10px] font-medium text-primary-foreground">
                                                {byDay[key(d)].length}
                                            </span>
                                        )}
                                    </button>
                                ),
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card className="lg:col-span-2">
                    <CardHeader className="space-y-3 pb-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <CardTitle>
                                {day
                                    ? new Date(
                                          `${day}T00:00:00`,
                                      ).toLocaleDateString("en-PH", {
                                          month: "long",
                                          day: "numeric",
                                          year: "numeric",
                                      })
                                    : "Requests this month"}
                            </CardTitle>
                            {day && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setDay(null)}
                                >
                                    Show all dates
                                </Button>
                            )}
                        </div>
                        <FilterSelect
                            label="Status"
                            className=""
                            value={filters.status ?? ""}
                            onChange={(v) => go(0, v === "all" ? "" : v)}
                            options={[
                                "pending",
                                "acknowledged",
                                "confirmed",
                                "en-route",
                                "on-scene",
                                "declined",
                                "cancelled",
                                "completed",
                            ].map((s) => ({ value: s, label: s }))}
                        />
                    </CardHeader>
                    <CardContent className="divide-y">
                        {queue.length === 0 && (
                            <EmptyState
                                icon={CalendarDays}
                                title="No service requests"
                                hint="Choose another date or adjust the status filter."
                            />
                        )}
                        {queue.map((s) => (
                            <div
                                key={s.id}
                                className="space-y-1.5 py-3 text-sm first:pt-0 last:pb-0"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <p className="font-medium">
                                        {s.service_type}
                                    </p>
                                    <StatusBadge value={s.status} />
                                </div>
                                <p className="text-muted-foreground">
                                    {s.resident}
                                    {s.contact && ` · ${s.contact}`}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {s.date} · {s.preferred_time} · {s.address}
                                </p>
                                {s.notes && (
                                    <p className="text-xs">{s.notes}</p>
                                )}
                                {s.team_name && <p className="text-xs font-medium">Team: {s.team_name}</p>}
                                {s.decline_reason && (
                                    <p className="text-xs text-destructive">
                                        Declined: {s.decline_reason}
                                    </p>
                                )}
                                {(["pending", "acknowledged"].includes(s.status) || (s.status === "confirmed" && s.needs_assignment)) && (
                                    <div className="flex flex-wrap gap-2 pt-1">
                                        {s.status === "pending" ? <Button size="sm" disabled={acknowledging === s.id} onClick={() => {
                                            setAcknowledging(s.id);
                                            setActionError(null);
                                            router.patch(route("dispatcher.schedule.acknowledge", s.id), {}, {
                                                preserveScroll: true,
                                                onError: (errors) => setActionError(Object.values(errors).join(" ")),
                                                onFinish: () => setAcknowledging(null),
                                            });
                                        }}>Acknowledge</Button> :
                                        <Button
                                            size="sm"
                                            onClick={() => {
                                                setTeamId("");
                                                setTeamError(null);
                                                setConfirming(s);
                                            }}
                                        >
                                            <Check className="mr-1 size-3" />
                                            Assign team
                                        </Button>}
                                        {s.status !== "confirmed" && <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => {
                                                setReason("");
                                                setDeclineError(null);
                                                setDeclining(s);
                                            }}
                                        >
                                            <X className="mr-1 size-3" />
                                            Decline
                                        </Button>}
                                    </div>
                                )}
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
            <Dialog
                open={!!declining}
                onOpenChange={(o) => !o && !declineBusy && setDeclining(null)}
            >
                <DialogContent className="max-h-[90dvh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Decline request</DialogTitle>
                        <DialogDescription>
                            The resident will be notified with your reason.
                        </DialogDescription>
                    </DialogHeader>
                    <Textarea
                        disabled={declineBusy}
                        maxLength={500}
                        aria-label="Reason"
                        rows={3}
                        placeholder="Reason for declining"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                    />
                    {declineError && <p role="alert" className="text-sm text-destructive">{declineError}</p>}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={declineBusy}
                            onClick={() => setDeclining(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={!reason.trim() || declineBusy}
                            onClick={() => {
                                setDeclineBusy(true);
                                router.patch(
                                    route(
                                        "dispatcher.schedule.decline",
                                        declining!.id,
                                    ),
                                    { reason },
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setDeclining(null),
                                        onError: (errors) => setDeclineError(Object.values(errors).join(" ")),
                                        onFinish: () => setDeclineBusy(false),
                                    },
                                );
                            }}
                        >
                            Decline
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <Dialog
                open={!!confirming}
                onOpenChange={(open) => {
                    if (!open && !confirmingBusy) setConfirming(null);
                }}
            >
                <DialogContent className="max-h-[90dvh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Assign response team</DialogTitle>
                        <DialogDescription>
                            Choose a response team. The selected team and the
                            resident will both be notified. Bookings reserve a {slotMinutes}-minute slot for the team and its responders.
                        </DialogDescription>
                    </DialogHeader>
                    {teams.length > 0 ? (
                        <div className="space-y-2">
                            <label
                                className="text-sm font-medium"
                                htmlFor="response-team"
                            >
                                Response team
                            </label>
                            <Select
                                value={teamId}
                                onValueChange={(value) => {
                                    setTeamId(value);
                                    setTeamError(null);
                                }}
                            >
                                <SelectTrigger
                                    id="response-team"
                                    aria-label="Response team"
                                    aria-invalid={!!teamError}
                                >
                                    <SelectValue placeholder="Choose a response team" />
                                </SelectTrigger>
                                <SelectContent>
                                    {teams.map((team) => (
                                        <SelectItem
                                            key={team.id}
                                            value={String(team.id)}
                                        >
                                            {team.team_name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    ) : (
                        <p className="text-sm text-destructive">
                            No active response team with an active driver /
                            responder is available.
                        </p>
                    )}
                    {teamError && (
                        <p role="alert" className="text-sm text-destructive">
                            {teamError}
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={confirmingBusy}
                            onClick={() => setConfirming(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            disabled={
                                !teamId ||
                                teams.length === 0 ||
                                confirmingBusy
                            }
                            onClick={confirmSchedule}
                        >
                            Confirm request
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
