import { Head, Link, router } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { AlertTriangle, ClipboardList, FileText, MapPin, Shield, Truck, Wrench } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { StatCard, StatGroup } from '@/components/StatCard';
import { StatusBadge } from '@/components/StatusBadge';
import { EmptyState } from '@/components/EmptyState';
import { LocationMap } from '@/components/maps/LocationMap';
import { Button } from '@/components/ui/button';
import type { EmergencyReport, TeamRoleMembership } from '@/types/website';

export const NEXT: Record<string, { to: string; label: string } | undefined> = {
  dispatched: { to: 'en-route', label: 'Start en route' },
  'en-route': { to: 'on-scene', label: 'Arrived on scene' },
  'on-scene': { to: 'returning', label: 'Returning' },
  returning: { to: 'available', label: 'Complete & available' },
};

type AssignedReport = EmergencyReport & {
  trip?: { started_at?: string; on_scene_at?: string; returning_at?: string } | null;
  can_advance?: boolean;
  can_edit_trip?: boolean;
  can_fill_patient_form?: boolean;
};

export default function Dashboard({
  active,
  doneToday,
  teamRoles = [],
  isDriver = true,
}: {
  active: AssignedReport[];
  doneToday: number;
  teamRoles?: TeamRoleMembership[];
  isDriver?: boolean;
}) {
  const advance = (id: number, to: string) =>
    router.patch(route('driver.assignments.status', id), { response_status: to }, { preserveScroll: true });

  const primaryRole = teamRoles[0];
  const activeVehicle = primaryRole?.active_vehicle;

  return (
    <AppLayout>
      <Head title="Driver / Responder Dashboard" />
      <PageHeader
        title="Driver / Responder Dashboard"
        description="Your Assigned Emergency, team role actions, and response progress."
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {primaryRole && (
              <span className="inline-flex items-center gap-1.5 rounded-md border bg-card px-2.5 py-1 text-xs font-medium">
                <Shield className="size-3.5 text-primary" aria-hidden="true" />
                {primaryRole.team_name}: <strong className="font-semibold">{primaryRole.role}</strong>
              </span>
            )}
            <Button asChild size="sm" variant="outline">
              <Link href={route('driver.team')}>
                <Truck className="mr-1.5 size-3.5" aria-hidden="true" />
                My Team & Ambulance
              </Link>
            </Button>
          </div>
        }
      />

      {activeVehicle?.has_maintenance_warning && (
        <div
          role="alert"
          className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-warning/40 bg-warning-soft p-3.5 text-sm text-warning"
        >
          <div className="flex items-start gap-2.5">
            <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <div>
              <p className="font-semibold">
                Ambulance {activeVehicle.plate_number} Maintenance Warning
              </p>
              <p className="text-xs opacity-90">
                {activeVehicle.maintenance_warning ?? 'This vehicle has an active maintenance alert.'}
              </p>
            </div>
          </div>
          <Button asChild size="sm" variant="outline">
            <Link href={route('driver.team')}>Switch ambulance / View</Link>
          </Button>
        </div>
      )}

      <StatGroup className="lg:grid-cols-3">
        <StatCard label="Assigned emergencies" value={active.length} status={active.length ? 'warning' : undefined} />
        <StatCard label="Completed today" value={doneToday} status="ok" />
        <StatCard
          label="Current team role"
          value={isDriver ? 'Driver' : 'Responder'}
          hint={activeVehicle ? `Ambulance: ${activeVehicle.plate_number}` : 'Manage role & vehicle in My Team'}
        />
      </StatGroup>

      {/* Role-Specific Quick Actions */}
      <section className="rounded-lg border bg-card p-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p className="eyebrow">{isDriver ? 'Driver forms & actions' : 'Responder forms & actions'}</p>
            <p className="mt-0.5 text-sm text-muted-foreground">
              {isDriver
                ? 'As the team Driver, you can advance dispatch status, log trip records, switch ambulances, and submit maintenance reports.'
                : 'As a team Responder, you can view the Assigned Emergency and complete patient care & clinical assessment forms.'}
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button asChild variant="outline" size="sm">
              <Link href={route('driver.reports')}>
                <FileText className="mr-1.5 size-3.5" aria-hidden="true" />
                {isDriver ? 'Trip & Patient Care Reports' : 'Patient Care Reports'}
              </Link>
            </Button>
            {isDriver && (
              <Button asChild size="sm">
                <Link href={`${route('driver.team')}#maintenance-form`}>
                  <Wrench className="mr-1.5 size-3.5" aria-hidden="true" />
                  Report Ambulance Maintenance
                </Link>
              </Button>
            )}
          </div>
        </div>
      </section>

      <section className="space-y-3">
        <h2 className="text-sm font-semibold tracking-tight">Assigned Emergency</h2>
        {active.length === 0 ? (
          <div className="rounded-lg border bg-card">
            <EmptyState
              icon={ClipboardList}
              title="No active assigned emergency"
              hint="You'll see an Assigned Emergency card here as soon as dispatch assigns your team."
            />
          </div>
        ) : (
          active.map((r) => {
            const canAdvance = r.can_advance ?? isDriver;
            const currentStage = r.trip?.returning_at
              ? 'returning'
              : r.trip?.on_scene_at
                ? 'on-scene'
                : r.trip?.started_at
                  ? 'en-route'
                  : 'dispatched';
            const step = r.status === 'dispatched' && canAdvance ? NEXT[currentStage] : undefined;

            return (
              <article key={r.id} className="overflow-hidden rounded-lg border bg-card">
                <header className="flex flex-wrap items-start justify-between gap-2 p-4">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="rounded bg-primary/10 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wider text-primary">
                        Assigned Emergency
                      </span>
                      {r.team?.team_name && (
                        <span className="text-xs text-muted-foreground">{r.team.team_name}</span>
                      )}
                    </div>
                    <h3 className="mt-1 text-base font-semibold">
                      {r.incident_type.name}{' '}
                      <span className="ml-1 font-mono text-xs font-normal text-muted-foreground">{r.reference}</span>
                    </h3>
                    <p className="mt-1 flex items-center gap-1.5 text-sm text-muted-foreground">
                      <MapPin className="size-3.5 shrink-0" aria-hidden="true" />
                      {r.location}
                    </p>
                  </div>
                  <div className="flex gap-1.5">
                    <StatusBadge value={r.priority} />
                    <StatusBadge value={currentStage === 'dispatched' ? r.status : currentStage} />
                  </div>
                </header>
                <LocationMap lat={r.lat} lng={r.lng} label={r.reference} className="h-56 rounded-none" />
                <div className="space-y-3 p-4">
                  <p className="text-sm text-muted-foreground">{r.description}</p>
                  <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    {step && (
                      <Button size="lg" onClick={() => advance(r.id, step.to)}>
                        {step.label}
                      </Button>
                    )}
                    <Button asChild size="lg" variant={step ? 'outline' : 'default'}>
                      <Link href={route('driver.reports')}>
                        {isDriver ? 'Fill trip & patient report' : 'Fill patient care report'}
                      </Link>
                    </Button>
                    {!canAdvance && (
                      <span className="text-xs text-muted-foreground">
                        Vehicle status progression is controlled by the team Driver.
                      </span>
                    )}
                  </div>
                </div>
              </article>
            );
          })
        )}
      </section>
    </AppLayout>
  );
}
