import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CalendarDays, Clock, MapPin, Truck, Users } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { StatusBadge } from '@/components/StatusBadge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/utils';
import type { ReportForm } from '@/types/website';

interface ScheduleDetail {
  id: number;
  reference: string;
  service_type: string;
  service_details?: string | null;
  preferred_date: string | null;
  preferred_time: string;
  address: string;
  destination_address?: string | null;
  notes?: string | null;
  status: string;
  decline_reason?: string | null;
  resident?: string | null;
  contact?: string | null;
  team_name?: string | null;
  team_members: { id: number; name: string; role: string }[];
  acknowledged_at?: string | null;
  started_at?: string | null;
  arrived_at?: string | null;
  completed_at?: string | null;
  created_at?: string | null;
}

interface DriverTicketDetail {
  id: number;
  driver_name?: string | null;
  updated_at?: string | null;
  driver_section: ReportForm['driver_section'];
}

const Row = ({ k, v, always = false }: { k: string; v?: string | number | null; always?: boolean }) => {
  const display = v !== null && v !== undefined && String(v).trim() !== '' ? String(v) : null;
  if (!display && !always) return null;
  return (
    <div className="flex justify-between gap-4 py-2 text-sm">
      <dt className="text-muted-foreground">{k}</dt>
      <dd className="text-right font-medium">{display ?? '—'}</dd>
    </div>
  );
};

function formatLiter(value: number | string | null | undefined): string | null {
  if (value == null || value === '') return null;
  return `${value} L`;
}

export default function ScheduleShow({
  schedule: s,
  driverTicket,
  backUrl,
  scheduleUrl,
}: {
  schedule: ScheduleDetail;
  driverTicket: DriverTicketDetail | null;
  backUrl: string;
  scheduleUrl?: string | null;
}) {
  const d = driverTicket?.driver_section ?? null;

  return (
    <AppLayout>
      <Head title={`Scheduled Service ${s.reference}`} />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <Link
          href={backUrl}
          className="inline-flex min-h-9 w-fit items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
        >
          <ArrowLeft className="size-4" aria-hidden="true" />
          Back to Scheduled Service Reports
        </Link>
        {scheduleUrl && (
          <Button asChild size="sm" variant="outline">
            <Link href={scheduleUrl}>
              <CalendarDays className="mr-1.5 size-4" aria-hidden="true" />
              Open Schedule Calendar
            </Link>
          </Button>
        )}
      </div>

      <section aria-label="Scheduled service summary" className="rounded-lg border bg-card p-5">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0 space-y-2">
            <div className="flex flex-wrap items-center gap-2">
              <StatusBadge value={s.status} />
              <span className="inline-flex h-5 items-center rounded-sm border bg-muted/50 px-2 font-mono text-xs font-medium text-foreground">
                {s.reference}
              </span>
              {driverTicket && (
                <span className="inline-flex h-5 items-center gap-1 rounded-sm bg-ok-soft px-2 text-xs font-medium text-ok">
                  <Truck className="size-3" aria-hidden="true" />
                  Driver Ticket submitted
                </span>
              )}
            </div>
            <h1 className="text-xl font-semibold tracking-tight">{s.service_details || s.service_type}</h1>
            <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
              <span className="inline-flex items-center gap-1.5 text-foreground">
                <MapPin className="size-3.5 shrink-0 text-primary" aria-hidden="true" />
                {s.address}
              </span>
              <span aria-hidden="true">·</span>
              <span className="inline-flex items-center gap-1 tabular-nums">
                <Clock className="size-3.5 shrink-0" aria-hidden="true" />
                {s.preferred_date} · {s.preferred_time}
              </span>
            </p>
          </div>
        </div>

        <dl className="mt-4 grid gap-3 border-t pt-4 sm:grid-cols-2 lg:grid-cols-4">
          <div className="rounded-md border bg-muted/30 px-3 py-2">
            <dt className="eyebrow">Requester</dt>
            <dd className="mt-0.5 truncate text-sm font-medium">{s.resident || '—'}</dd>
            {s.contact && <dd className="text-xs text-muted-foreground tabular-nums">{s.contact}</dd>}
          </div>
          <div className="rounded-md border bg-muted/30 px-3 py-2">
            <dt className="eyebrow">Assigned Team</dt>
            <dd className="mt-0.5 truncate text-sm font-medium">{s.team_name || 'Unassigned'}</dd>
          </div>
          <div className="rounded-md border bg-muted/30 px-3 py-2">
            <dt className="eyebrow">Destination</dt>
            <dd className="mt-0.5 truncate text-sm font-medium">{s.destination_address || '—'}</dd>
          </div>
          <div className="rounded-md border bg-muted/30 px-3 py-2">
            <dt className="eyebrow">Completed</dt>
            <dd className="mt-0.5 text-sm font-medium tabular-nums">
              {s.completed_at ? formatDateTime(s.completed_at) : 'In progress / scheduled'}
            </dd>
          </div>
        </dl>
      </section>

      <div className="grid items-start gap-5 lg:grid-cols-12">
        <Card className="lg:col-span-7">
          <CardHeader>
            <CardTitle>Scheduled Service Details</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <dl className="divide-y">
              <Row k="Service Type" v={s.service_type} always />
              {s.service_details && <Row k="Requested Service" v={s.service_details} />}
              <Row k="Scheduled Date" v={s.preferred_date} always />
              <Row k="Scheduled Time" v={s.preferred_time} always />
              <Row k="Pickup Address" v={s.address} always />
              <Row k="Destination Address" v={s.destination_address} always />
              <Row k="Trip Started" v={s.started_at ? formatDateTime(s.started_at) : null} />
              <Row k="Arrived On Scene" v={s.arrived_at ? formatDateTime(s.arrived_at) : null} />
              <Row k="Completed" v={s.completed_at ? formatDateTime(s.completed_at) : null} />
              {s.decline_reason && <Row k="Decline Reason" v={s.decline_reason} />}
            </dl>
            <div className="rounded-md border bg-muted/35 p-3.5 text-sm">
              <p className="eyebrow mb-1">Notes</p>
              <p className="leading-relaxed text-foreground">{s.notes || 'No additional notes provided.'}</p>
            </div>
          </CardContent>
        </Card>

        <Card className="lg:col-span-5">
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Users className="size-4 text-muted-foreground" aria-hidden="true" />
              Assigned Response Team
            </CardTitle>
            <CardDescription>{s.team_name ?? 'No team assigned yet.'}</CardDescription>
          </CardHeader>
          <CardContent>
            {s.team_members.length === 0 ? (
              <p className="rounded-md border border-dashed px-3 py-4 text-center text-sm text-muted-foreground">
                No active team members recorded.
              </p>
            ) : (
              <ul className="divide-y rounded-md border">
                {s.team_members.map((member) => (
                  <li key={member.id} className="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                    <span className="font-medium">{member.name}</span>
                    <span className="rounded-sm bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                      {member.role}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card className="lg:col-span-12 border-t-2 border-t-primary/30">
          <CardHeader className="border-b bg-muted/20">
            <CardTitle className="flex items-center gap-2">
              <Truck className="size-4 text-primary" aria-hidden="true" />
              Driver Ticket
            </CardTitle>
            <CardDescription>
              {driverTicket
                ? `Trip authorization and fuel consumption record submitted for ${s.reference}${driverTicket.updated_at ? ` · Updated ${formatDateTime(driverTicket.updated_at)}` : ''}.`
                : `No Driver Ticket has been submitted yet for ${s.reference}.`}
            </CardDescription>
          </CardHeader>
          <CardContent className="pt-5">
            {d ? (
              <div className="grid gap-x-10 gap-y-6 sm:grid-cols-2">
                <div>
                  <p className="eyebrow mb-2">Trip Authorization</p>
                  <dl className="divide-y">
                    <Row k="Trip Date" v={d.tripDate} always />
                    <Row k="Assigned Driver" v={d.driverName || driverTicket?.driver_name} always />
                    <Row k="Government Vehicle" v={d.governmentCardPlateNo} always />
                    <Row k="Authorized Passenger" v={d.authorizedPassenger || d.passengerName} always />
                    <Row k="Destination / Place to Visit" v={d.placeVisitedInspected} always />
                    <Row k="Purpose of Trip" v={d.purpose} always />
                  </dl>
                </div>
                <div>
                  <p className="eyebrow mb-2">Fuel &amp; Lubricants</p>
                  <dl className="divide-y tabular-nums">
                    <Row k="Balance in Tank (L)" v={formatLiter(d.balanceInTank)} always />
                    <Row k="Issued by Office (L)" v={formatLiter(d.issuedByOffice)} always />
                    <Row k="Purchased During Trip (L)" v={formatLiter(d.purchasedDuringTrip)} always />
                    <Row k="Total Available Fuel (L)" v={formatLiter(d.totalAvailableFuel)} always />
                    <Row k="Fuel Used During Trip (L)" v={formatLiter(d.fuelUsedDuringTrip)} always />
                    <Row k="Remaining Fuel (L)" v={formatLiter(d.remainingFuel)} always />
                    <Row k="Gear Oil Used (L)" v={formatLiter(d.gearOilUsed)} always />
                  </dl>
                </div>
              </div>
            ) : (
              <p className="rounded-md border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
                The assigned driver has not submitted a Driver Ticket for this scheduled service yet.
              </p>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
