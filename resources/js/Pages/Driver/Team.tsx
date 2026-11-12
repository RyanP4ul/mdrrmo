import { Head, router, useForm } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { AlertTriangle, CheckCircle2, Phone, RefreshCw, Shield, Truck, Users, Wrench } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { StatusBadge } from '@/components/StatusBadge';
import { Field } from '@/components/Field';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatDateTime } from '@/lib/utils';
import type { MaintenanceReportItem, TeamRoleMembership } from '@/types/website';

interface RosterMember {
  id: number;
  team_id: number;
  team_name: string;
  name: string;
  contact: string | null;
  role: string;
  status: string;
  online: boolean;
}

export default function Team({
  teamRoles,
  members,
  maintenanceReports,
  issueTypes,
}: {
  teamRoles: TeamRoleMembership[];
  members: RosterMember[];
  maintenanceReports: MaintenanceReportItem[];
  issueTypes: string[];
}) {
  const driverTeams = teamRoles.filter((t) => t.role === 'Driver');
  const isDriverAnywhere = driverTeams.length > 0;
  const defaultVehicleId =
    driverTeams[0]?.active_vehicle?.id ?? driverTeams[0]?.vehicles[0]?.id ?? '';

  const maintenanceForm = useForm({
    vehicle_id: defaultVehicleId ? String(defaultVehicleId) : '',
    issue_type: issueTypes[0] ?? 'Engine / Mechanical',
    severity: 'medium' as 'low' | 'medium' | 'high' | 'critical',
    mileage: '',
    description: '',
    recommend_out_of_service: false,
  });
  const errors = maintenanceForm.errors as Record<string, string>;

  const toggleRole = (teamId: number, makeDriver: boolean) => {
    router.patch(
      route('driver.teams.role', teamId),
      { role: makeDriver ? 'Driver' : 'Responder' },
      { preserveScroll: true },
    );
  };

  const switchAmbulance = (teamId: number, vehicleId: string) => {
    router.patch(
      route('driver.teams.vehicle', teamId),
      { vehicle_id: Number(vehicleId) },
      { preserveScroll: true },
    );
  };

  const submitMaintenance = (e: React.FormEvent) => {
    e.preventDefault();
    maintenanceForm.post(route('driver.maintenance-reports.store'), {
      preserveScroll: true,
      onSuccess: () => {
        maintenanceForm.reset('mileage', 'description', 'recommend_out_of_service');
      },
    });
  };

  const allDriverVehicles = driverTeams.flatMap((t) =>
    t.vehicles.map((v) => ({ ...v, team_name: t.team_name })),
  );

  return (
    <AppLayout>
      <Head title="My Team" />
      <PageHeader
        icon={Users}
        title="My Team"
        description="View your team ambulance photo & plate number, maintenance warnings, switch ambulances, and manage your team role."
      />

      {teamRoles.length === 0 ? (
        <div className="rounded-lg border bg-card">
          <EmptyState
            icon={Shield}
            title="No active team membership"
            hint="Ask the dispatcher to link your account to an active response team."
          />
        </div>
      ) : (
        <div className="grid gap-5 lg:grid-cols-2">
          {teamRoles.map((team) => {
            const isDriver = team.role === 'Driver';
            const activeVehicle = team.active_vehicle;
            return (
              <Card key={team.team_id} className="overflow-hidden">
                <CardHeader className="border-b bg-muted/20 pb-4">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                      <p className="eyebrow">Response Team</p>
                      <CardTitle className="mt-0.5 text-lg">{team.team_name}</CardTitle>
                      <CardDescription className="mt-1">
                        {team.locked
                          ? `Your role: ${team.role}. Complete active dispatches or service trips before changing roles.`
                          : isDriver
                            ? 'You are the team Driver. You control dispatch status, ambulance selection, and maintenance reporting.'
                            : !team.can_be_driver
                              ? `${team.driver_name ?? 'Another member'} is currently the Driver.`
                              : 'You are a Responder. Toggle on to become the team Driver.'}
                      </CardDescription>
                    </div>
                    <div className="flex items-center gap-2 rounded-md border bg-background px-3 py-1.5">
                      <span className="text-xs font-medium">Driver role</span>
                      <Switch
                        aria-label={`${team.team_name}: Driver`}
                        checked={isDriver}
                        disabled={team.locked || (!isDriver && !team.can_be_driver)}
                        onCheckedChange={(checked) => toggleRole(team.team_id, checked)}
                      />
                    </div>
                  </div>
                </CardHeader>

                <CardContent className="space-y-4 pt-4">
                  {/* Ambulance Photo, Plate Number & Maintenance Warning */}
                  <div className="rounded-lg border bg-muted/15 p-4">
                    <div className="flex items-center justify-between gap-2 pb-3">
                      <span className="eyebrow flex items-center gap-1.5">
                        <Truck className="size-3.5 text-primary" aria-hidden="true" />
                        Assigned Ambulance
                      </span>
                      {activeVehicle && <StatusBadge value={activeVehicle.status} />}
                    </div>

                    {!activeVehicle ? (
                      <p className="text-sm text-muted-foreground">
                        No ambulance is currently assigned to this team.
                      </p>
                    ) : (
                      <div className="space-y-3">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                          <div className="relative flex h-28 w-full shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted sm:w-44">
                            {activeVehicle.photo_url ? (
                              <img
                                src={activeVehicle.photo_url}
                                alt={`Ambulance ${activeVehicle.plate_number}`}
                                className="size-full object-cover"
                              />
                            ) : (
                              <div className="flex flex-col items-center gap-1 text-muted-foreground">
                                <Truck className="size-8 text-primary/70" aria-hidden="true" />
                                <span className="text-[11px] font-medium uppercase tracking-wider">
                                  {activeVehicle.vehicle_type.replace('-', ' ')}
                                </span>
                              </div>
                            )}
                          </div>

                          <div className="min-w-0 flex-1 space-y-1.5">
                            <p className="text-base font-semibold">{activeVehicle.name}</p>
                            <div className="flex flex-wrap items-center gap-2">
                              <span className="inline-flex items-center rounded border bg-background px-2.5 py-0.5 font-mono text-xs font-bold tracking-wider">
                                PLATE: {activeVehicle.plate_number}
                              </span>
                              <span className="text-xs capitalize text-muted-foreground">
                                {activeVehicle.vehicle_type.replace('-', ' ')}
                              </span>
                            </div>
                            {activeVehicle.open_maintenance_count > 0 && (
                              <p className="text-xs text-muted-foreground">
                                {activeVehicle.open_maintenance_count} open maintenance report(s)
                              </p>
                            )}
                          </div>
                        </div>

                        {activeVehicle.has_maintenance_warning && (
                          <div
                            role="alert"
                            className="flex items-start gap-2.5 rounded-md border border-warning/40 bg-warning-soft p-3 text-xs text-warning"
                          >
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                            <div className="space-y-0.5">
                              <p className="font-semibold">Maintenance Warning</p>
                              <p>{activeVehicle.maintenance_warning}</p>
                            </div>
                          </div>
                        )}

                        {/* Ambulance Switcher */}
                        {team.vehicles.length > 1 && (
                          <div className="border-t pt-3">
                            <Field
                              label="Switch active ambulance"
                              htmlFor={`switch-vehicle-${team.team_id}`}
                              hint={
                                !isDriver
                                  ? 'Only the team Driver can switch the active ambulance.'
                                  : team.locked
                                    ? 'Complete active dispatches before switching ambulances.'
                                    : 'Vehicles under maintenance cannot be selected.'
                              }
                            >
                              <Select
                                value={String(activeVehicle.id)}
                                disabled={!isDriver || team.locked}
                                onValueChange={(val) => switchAmbulance(team.team_id, val)}
                              >
                                <SelectTrigger id={`switch-vehicle-${team.team_id}`} className="w-full">
                                  <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                  {team.vehicles.map((v) => (
                                    <SelectItem
                                      key={v.id}
                                      value={String(v.id)}
                                      disabled={!v.can_select && v.id !== activeVehicle.id}
                                    >
                                      {v.plate_number} — {v.name} ({v.status.replace('-', ' ')})
                                      {v.has_maintenance_warning ? ' ⚠' : ''}
                                    </SelectItem>
                                  ))}
                                </SelectContent>
                              </Select>
                            </Field>
                          </div>
                        )}
                      </div>
                    )}
                  </div>
                </CardContent>
              </Card>
            );
          })}
        </div>
      )}

      {/* Driver-Only Maintenance Report Form */}
      <section id="maintenance-form" className="grid gap-5 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <Wrench className="size-4 text-primary" aria-hidden="true" />
              Driver-Only Maintenance Report Form
            </CardTitle>
            <CardDescription>
              Report mechanical, medical equipment, or scheduled maintenance issues for your team ambulance to Admin.
            </CardDescription>
          </CardHeader>
          <CardContent>
            {!isDriverAnywhere ? (
              <div className="rounded-md border bg-muted/30 p-4 text-sm text-muted-foreground">
                You are currently assigned as a <strong>Responder</strong>. Only the team <strong>Driver</strong> can submit vehicle maintenance reports.
              </div>
            ) : allDriverVehicles.length === 0 ? (
              <div className="rounded-md border bg-muted/30 p-4 text-sm text-muted-foreground">
                No vehicles are currently linked to your team. Ask an Administrator to assign an ambulance in Vehicle Management.
              </div>
            ) : (
              <form onSubmit={submitMaintenance} className="space-y-3.5">
                <Field label="Ambulance / Vehicle *" htmlFor="m-vehicle" error={errors.vehicle_id}>
                  <Select
                    value={maintenanceForm.data.vehicle_id}
                    onValueChange={(v) => maintenanceForm.setData('vehicle_id', v)}
                  >
                    <SelectTrigger id="m-vehicle" className="w-full">
                      <SelectValue placeholder="Choose ambulance" />
                    </SelectTrigger>
                    <SelectContent>
                      {allDriverVehicles.map((v) => (
                        <SelectItem key={v.id} value={String(v.id)}>
                          {v.plate_number} · {v.name} ({v.team_name})
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </Field>

                <div className="grid gap-3 sm:grid-cols-2">
                  <Field label="Issue category *" htmlFor="m-issue" error={errors.issue_type}>
                    <Select
                      value={maintenanceForm.data.issue_type}
                      onValueChange={(v) => maintenanceForm.setData('issue_type', v)}
                    >
                      <SelectTrigger id="m-issue" className="w-full">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {issueTypes.map((type) => (
                          <SelectItem key={type} value={type}>
                            {type}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </Field>

                  <Field label="Severity *" htmlFor="m-severity" error={errors.severity}>
                    <Select
                      value={maintenanceForm.data.severity}
                      onValueChange={(v) =>
                        maintenanceForm.setData('severity', v as 'low' | 'medium' | 'high' | 'critical')
                      }
                    >
                      <SelectTrigger id="m-severity" className="w-full">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {(['low', 'medium', 'high', 'critical'] as const).map((s) => (
                          <SelectItem key={s} value={s} className="capitalize">
                            {s}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </Field>
                </div>

                <Field label="Current odometer / mileage (km)" htmlFor="m-mileage" error={errors.mileage}>
                  <Input
                    id="m-mileage"
                    type="number"
                    min={0}
                    placeholder="e.g. 48250"
                    value={maintenanceForm.data.mileage}
                    onChange={(e) => maintenanceForm.setData('mileage', e.target.value)}
                  />
                </Field>

                <Field label="Issue description *" htmlFor="m-desc" error={errors.description}>
                  <Textarea
                    id="m-desc"
                    rows={3}
                    placeholder="Describe the mechanical or equipment issue observed..."
                    value={maintenanceForm.data.description}
                    onChange={(e) => maintenanceForm.setData('description', e.target.value)}
                  />
                </Field>

                <label className="flex items-center gap-2.5 rounded-md border bg-muted/20 p-3 text-sm">
                  <Checkbox
                    checked={maintenanceForm.data.recommend_out_of_service}
                    onCheckedChange={(c) => maintenanceForm.setData('recommend_out_of_service', !!c)}
                  />
                  <span>Recommend placing this ambulance under maintenance (out of service)</span>
                </label>

                <Button type="submit" disabled={maintenanceForm.processing || !maintenanceForm.data.vehicle_id}>
                  <Wrench className="mr-1.5 size-4" aria-hidden="true" />
                  {maintenanceForm.processing ? 'Submitting…' : 'Submit maintenance report'}
                </Button>
              </form>
            )}
          </CardContent>
        </Card>

        {/* Maintenance Reports Status History */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <RefreshCw className="size-4 text-primary" aria-hidden="true" />
              Team Maintenance Reports
            </CardTitle>
            <CardDescription>Track Admin acknowledgment, approval, and resolution of reported issues.</CardDescription>
          </CardHeader>
          <CardContent>
            {maintenanceReports.length === 0 ? (
              <p className="py-6 text-center text-sm text-muted-foreground">
                No maintenance reports submitted yet.
              </p>
            ) : (
              <ul className="divide-y rounded-md border">
                {maintenanceReports.map((r) => (
                  <li key={r.id} className="space-y-1.5 p-3.5 text-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <div className="flex items-center gap-2">
                        <span className="font-mono text-xs font-semibold">{r.plate_number}</span>
                        <span className="font-medium">{r.issue_type}</span>
                      </div>
                      <div className="flex items-center gap-1.5">
                        <StatusBadge value={r.severity} />
                        <StatusBadge value={r.status} />
                      </div>
                    </div>
                    <p className="text-xs text-muted-foreground">{r.description}</p>
                    {r.admin_notes && (
                      <p className="rounded bg-muted/50 px-2.5 py-1.5 text-xs">
                        <strong>Admin review:</strong> {r.admin_notes}
                      </p>
                    )}
                    <p className="text-[11px] text-muted-foreground">
                      {r.reference} · Reported by {r.reporter_name ?? 'Driver'} ·{' '}
                      {r.created_at ? formatDateTime(r.created_at) : ''}
                    </p>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </section>

      {/* Team Directory */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Team Directory</CardTitle>
          <CardDescription>
            {members.filter((m) => m.online).length} of {members.length} team member(s) recently active
          </CardDescription>
        </CardHeader>
        <CardContent>
          {members.length === 0 ? (
            <p className="text-sm text-muted-foreground">No team members found.</p>
          ) : (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {members.map((member) => (
                <div key={member.id} className="flex items-center justify-between rounded-lg border p-3.5">
                  <div className="min-w-0 space-y-0.5">
                    <div className="flex items-center gap-2">
                      <span
                        className={`size-2 rounded-full ${member.online ? 'bg-ok' : 'bg-muted-foreground/40'}`}
                        aria-hidden="true"
                      />
                      <p className="truncate text-sm font-medium">{member.name}</p>
                    </div>
                    <p className="text-xs text-muted-foreground">
                      {member.team_name} · <strong>{member.role}</strong>
                    </p>
                    {member.contact && (
                      <p className="flex items-center gap-1 text-xs text-muted-foreground">
                        <Phone className="size-3" aria-hidden="true" />
                        {member.contact}
                      </p>
                    )}
                  </div>
                  <StatusBadge value={member.status} />
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </AppLayout>
  );
}
