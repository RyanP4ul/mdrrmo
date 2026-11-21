import { Head, Link, router, useForm } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { toast } from 'sonner';
import { AlertTriangle, Ambulance, ArrowRightLeft, Lock, MapPin, Phone, PhoneCall, Radio, ShieldAlert, Smartphone, Truck, Users } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { DispatchReportRealtime } from '@/components/DispatchReportRealtime';
import { PageHeader } from '@/components/PageHeader';
import { StatCard, StatGroup } from '@/components/StatCard';
import { StatusBadge } from '@/components/StatusBadge';
import { EmptyState } from '@/components/EmptyState';
import { Field } from '@/components/Field';
import { FilterBar } from '@/components/FilterBar';
import { LocationMap } from '@/components/maps/LocationMap';
import { DEFAULT_CENTER } from '@/components/maps/constants';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn, formatDateTime } from '@/lib/utils';
import type { DispatchVehicleOption } from '@/types/website';

interface VictimInfo { name?: string | null; age?: string | number | null; sex?: string | null }
interface WitnessInfo { statement?: string | null }
interface DefaultVehicleSummary {
  id: number;
  name: string;
  plate_number: string;
  vehicle_type: string;
  vehicle_type_label: string | null;
  status: string;
  is_available: boolean;
}
interface TeamOption {
  id: number;
  name: string;
  shift_team?: 'day' | 'night' | null;
  is_available?: boolean;
  active_report_reference?: string | null;
  vehicle_name?: string | null;
  default_vehicle?: DefaultVehicleSummary | null;
  members: { id: number; name: string; role: string | null }[];
}
interface Report {
  id: number;
  reference: string;
  status: string;
  priority: string;
  location: string;
  lat: number;
  lng: number;
  description: string;
  photo_url?: string | null;
  source: string;
  reporter_name: string | null;
  reporter_contact: string | null;
  incident_type_id: number;
  response_team_id?: number | null;
  reporter_type: 'victim' | 'witness' | null;
  victim_info?: VictimInfo | null;
  witness_info?: WitnessInfo | null;
  reported_at: string;
  incident_type: { name: string; color: string };
  team: { team_name: string } | null;
  is_backup?: boolean;
  requested_vehicle_type?: string | null;
  requested_vehicle_label?: string | null;
  excluded_vehicle_ids?: number[];
  parent_report?: { id: number; reference: string; status: string } | null;
  requested_by_team?: { id: number; team_name: string } | null;
  assigned_vehicle?: {
    id: number;
    name: string;
    plate_number: string;
    vehicle_type: string;
    vehicle_type_label: string | null;
    status: string;
  } | null;
}
interface Props {
  incoming: Report[];
  counts: Record<string, number>;
  types: { id: number; name: string; priority: string }[];
  autoTeam: TeamOption | null;
  teams?: TeamOption[];
  vehicles?: DispatchVehicleOption[];
}

function VehicleTypeIcon({ type, className = 'size-5' }: { type?: string | null; className?: string }) {
  if (type === 'ambulance') return <Ambulance className={className} aria-hidden="true" />;
  if (type === 'rescue-truck') return <ShieldAlert className={className} aria-hidden="true" />;
  return <Truck className={className} aria-hidden="true" />;
}

function getTeamDisabledReason(team: TeamOption, selected: Report | null): string | null {
  if (selected?.is_backup && selected.requested_by_team?.id === team.id) {
    return 'Requested backup';
  }
  if (team.is_available === false && team.id !== selected?.response_team_id) {
    return team.active_report_reference
      ? `Dispatched · ${team.active_report_reference}`
      : 'Already dispatched';
  }
  return null;
}

function isVehicleEligibleForBackup(
  vehicle: DispatchVehicleOption,
  selectedReport: Report | null,
): boolean {
  if (selectedReport?.excluded_vehicle_ids?.includes(vehicle.id)) {
    return false;
  }
  return Boolean(
    vehicle.is_available ||
      (selectedReport != null && vehicle.assigned_report_id === selectedReport.id),
  );
}

function resolveRecommendedBackupVehicleId(
  selectedReport: Report | null,
  selectedTeam: TeamOption | null,
  vehicles: DispatchVehicleOption[],
): string {
  if (!selectedReport?.is_backup) return '';

  const excludedIds = selectedReport.excluded_vehicle_ids ?? [];
  if (selectedReport.assigned_vehicle?.id && !excludedIds.includes(selectedReport.assigned_vehicle.id)) {
    return String(selectedReport.assigned_vehicle.id);
  }

  const eligibleVehicles = vehicles.filter((v) => isVehicleEligibleForBackup(v, selectedReport));
  const requestedType = selectedReport.requested_vehicle_type;
  const teamDefault = selectedTeam?.default_vehicle ?? null;
  const teamDefaultEligible =
    teamDefault != null &&
    teamDefault.is_available &&
    !excludedIds.includes(teamDefault.id);

  if (requestedType) {
    if (teamDefaultEligible && teamDefault.vehicle_type === requestedType) {
      return String(teamDefault.id);
    }

    const matchingVehicle = eligibleVehicles.find((v) => v.vehicle_type === requestedType);
    if (matchingVehicle) {
      return String(matchingVehicle.id);
    }
  }

  if (teamDefaultEligible) {
    return String(teamDefault.id);
  }

  const anyAvailable = eligibleVehicles[0];
  return anyAvailable ? String(anyAvailable.id) : '';
}

function pickInitialBackupTeamId(selected: Report | null, autoTeam: TeamOption | null, teams: TeamOption[]): string {
  if (!selected) return 'auto';
  if (selected.response_team_id) return String(selected.response_team_id);
  if (!selected.is_backup) return 'auto';

  const requestingTeamId = selected.requested_by_team?.id ?? null;
  const availableCandidate = teams.find(
    (t) => t.is_available !== false && t.id !== requestingTeamId,
  );
  if (availableCandidate) return String(availableCandidate.id);

  if (autoTeam && autoTeam.is_available !== false && autoTeam.id !== requestingTeamId) {
    return String(autoTeam.id);
  }

  return '';
}

const OTHER_INCIDENT_TYPE = 'others';

function DispatchForm({
  selected,
  types,
  autoTeam,
  teams = [],
  vehicles = [],
  onDone,
}: {
  selected: Report | null;
  types: Props['types'];
  autoTeam: Props['autoTeam'];
  teams?: TeamOption[];
  vehicles?: DispatchVehicleOption[];
  onDone: () => void;
}) {
  const readOnly = selected !== null;
  const isBackup = Boolean(selected?.is_backup);
  const initialTeamId = pickInitialBackupTeamId(selected, autoTeam, teams);
  const initialTeamOption =
    initialTeamId === 'auto'
      ? autoTeam
      : (teams.find((t) => String(t.id) === initialTeamId) ?? autoTeam);

  const [vehicleId, setVehicleId] = useState<string>(() =>
    resolveRecommendedBackupVehicleId(selected, initialTeamOption, vehicles),
  );
  const [changingVehicle, setChangingVehicle] = useState(false);

  const form = useForm({
    report_id: selected?.id ?? '',
    team_id: initialTeamId,
    incident_type_id: selected ? String(selected.incident_type_id) : '',
    incident_type_name: '',
    reporter_name: selected?.reporter_name ?? '',
    reporter_contact: selected?.reporter_contact ?? '',
    location: selected?.location ?? '',
    lat: selected?.lat ?? null,
    lng: selected?.lng ?? null,
    description: selected?.description ?? '',
    reporter_type: (selected ? (selected.reporter_type ?? '') : 'victim') as 'victim' | 'witness' | '',
    victim_info: {
      name: selected?.victim_info?.name ? String(selected.victim_info.name) : '',
      age: selected?.victim_info?.age != null && selected.victim_info.age !== '' ? String(selected.victim_info.age) : '',
      sex: selected?.victim_info?.sex ? String(selected.victim_info.sex) : '',
    },
    witness_info: { statement: selected?.witness_info?.statement ? String(selected.witness_info.statement) : '' },
  });
  const e = form.errors as Record<string, string>;
  const selectableTypes = useMemo(
    () => types.filter((t) => !['other', 'others'].includes(t.name.trim().toLowerCase())),
    [types],
  );
  const isOtherIncidentType = !readOnly && form.data.incident_type_id === OTHER_INCIDENT_TYPE;
  const priority =
    selected?.priority ??
    (isOtherIncidentType
      ? (types.find((t) => t.name.trim().toLowerCase() === form.data.incident_type_name.trim().toLowerCase())?.priority ?? 'high')
      : types.find((t) => String(t.id) === form.data.incident_type_id)?.priority);
  const readOnlyClass = readOnly ? 'bg-muted/50 text-foreground cursor-default focus-visible:border-input focus-visible:ring-0' : undefined;
  const displayValue = (value: string) => (readOnly ? value.trim() || '—' : value);
  const showVictimInfo = form.data.reporter_type === 'victim' || (readOnly && Boolean(form.data.victim_info.name || form.data.victim_info.age || form.data.victim_info.sex));
  const showWitnessInfo = form.data.reporter_type === 'witness' || (readOnly && Boolean(form.data.witness_info.statement));
  const callerRequired = !readOnly && form.data.reporter_type !== 'victim';

  const selectedTeamOption =
    form.data.team_id === 'auto'
      ? autoTeam
      : form.data.team_id
      ? (teams.find((t) => String(t.id) === form.data.team_id) ?? null)
      : null;

  // Automatically re-check the backup vehicle whenever the dispatcher selects a team.
  useEffect(() => {
    if (!isBackup) return;
    setVehicleId(resolveRecommendedBackupVehicleId(selected, selectedTeamOption, vehicles));
    setChangingVehicle(false);
  }, [isBackup, selected?.id, form.data.team_id]);

  const selectableVehicles = useMemo(
    () => vehicles.filter((v) => isVehicleEligibleForBackup(v, selected)),
    [vehicles, selected?.id, selected?.excluded_vehicle_ids],
  );

  const selectedBackupVehicle = useMemo(
    () => selectableVehicles.find((v) => String(v.id) === vehicleId) ?? null,
    [selectableVehicles, vehicleId],
  );

  const sortedTeams = useMemo(() => {
    return [...teams].sort((a, b) => {
      const aDisabled = getTeamDisabledReason(a, selected) !== null ? 1 : 0;
      const bDisabled = getTeamDisabledReason(b, selected) !== null ? 1 : 0;
      if (aDisabled !== bDisabled) return aDisabled - bDisabled;
      return a.name.localeCompare(b.name);
    });
  }, [teams, selected]);

  const selectedTeamDisabledReason = selectedTeamOption
    ? getTeamDisabledReason(selectedTeamOption, selected)
    : null;

  const isTemporaryVehicleChange = Boolean(
    isBackup &&
      selectedTeamOption?.default_vehicle &&
      selectedBackupVehicle &&
      selectedTeamOption.default_vehicle.id !== selectedBackupVehicle.id,
  );

  const requestedTypeUnavailable = Boolean(
    isBackup &&
      selected?.requested_vehicle_type &&
      !selectableVehicles.some((v) => v.vehicle_type === selected.requested_vehicle_type),
  );

  const canDispatchTeam = isBackup
    ? Boolean(
        selectedTeamOption &&
          !selectedTeamDisabledReason &&
          selectedBackupVehicle,
      )
    : Boolean(selectedTeamOption && !selectedTeamDisabledReason && selectedTeamOption.vehicle_name);

  const handleDispatch = () => {
    const chosenTeamId = form.data.team_id === 'auto' || !form.data.team_id ? null : Number(form.data.team_id);
    const chosenVehicleId = isBackup && vehicleId ? Number(vehicleId) : null;
    if (selected) {
      form.transform(() => ({
        report_id: selected.id,
        team_id: chosenTeamId,
        ...(chosenVehicleId ? { vehicle_id: chosenVehicleId } : {}),
      }));
    } else {
      form.transform((data) => ({
        ...data,
        team_id: chosenTeamId,
        incident_type_name: data.incident_type_id === OTHER_INCIDENT_TYPE ? data.incident_type_name : null,
      }));
    }
    form.post(route('dispatcher.dispatch.store'), {
      preserveScroll: true,
      onSuccess: () => { form.reset(); onDone(); },
      onError: (errors) => {
        const message = errors.vehicle_id ?? errors.team ?? errors.team_id ?? errors.status;
        if (message) toast.error(message);
      },
    });
  };

  if (isBackup && selected) {
    const requestingTeamName = selected.requested_by_team?.team_name ?? selected.reporter_name ?? 'Response team';
    const requiredLabel = selected.requested_vehicle_label ?? 'Any available vehicle';

    return (
      <section aria-labelledby="dispatch-heading" className="rounded-lg border bg-card">
        <header className="border-b px-5 py-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex flex-wrap items-center gap-2">
              <p className="eyebrow text-critical">Backup request</p>
              <StatusBadge value={selected.status} />
            </div>
            <span className="inline-flex h-5 items-center rounded-sm border bg-muted/50 px-2 font-mono text-xs font-medium text-foreground">
              {selected.reference}
            </span>
          </div>
          <h2 id="dispatch-heading" className="mt-1.5 text-base font-semibold">
            Requested by {requestingTeamName}
          </h2>
          <p className="mt-0.5 text-xs text-muted-foreground">
            Incident: {selected.incident_type.name} · On Scene
            {selected.parent_report ? ` (${selected.parent_report.reference})` : ''}
          </p>
        </header>

        <div className="space-y-5 p-5">
          {/* Required Vehicle Card */}
          <div className="space-y-2">
            <p className="eyebrow">Required vehicle</p>
            <div className="flex items-center gap-3.5 rounded-lg border border-primary/25 bg-primary/5 p-3.5">
              <div className="flex size-11 shrink-0 items-center justify-center rounded-md bg-primary/15 text-primary">
                <VehicleTypeIcon type={selected.requested_vehicle_type} className="size-5" />
              </div>
              <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-foreground">{requiredLabel}</p>
                <p className="text-xs text-muted-foreground">Requested for backup</p>
              </div>
              <Badge variant="secondary" className="shrink-0">Backup</Badge>
            </div>
          </div>

          {/* Location & Request Notes */}
          <div className="space-y-3 border-t pt-4">
            <div className="flex items-start gap-2 text-sm">
              <MapPin className="mt-0.5 size-4 shrink-0 text-critical" aria-hidden="true" />
              <div className="min-w-0">
                <p className="font-medium text-foreground">{selected.location}</p>
                <p className="text-xs text-muted-foreground tabular-nums">
                  Reported {formatDateTime(selected.reported_at)}
                  {selected.reporter_contact ? ` · ${selected.reporter_contact}` : ''}
                </p>
              </div>
            </div>
            <LocationMap
              lat={Number.isFinite(selected.lat) ? selected.lat : DEFAULT_CENTER[1]}
              lng={Number.isFinite(selected.lng) ? selected.lng : DEFAULT_CENTER[0]}
              label={selected.reference}
              className="h-44"
            />
            {selected.description && (
              <p className="rounded-md border bg-muted/30 px-3 py-2 text-xs leading-relaxed text-muted-foreground">
                {selected.description}
              </p>
            )}
          </div>

          {/* Select Available Team */}
          <fieldset className="space-y-3 border-t pt-4">
            <legend className="eyebrow mb-2 pr-3">Select available team</legend>
            <Field label="Response team" htmlFor="backup-dispatch-team">
              <Select value={form.data.team_id} onValueChange={(v) => form.setData('team_id', v)}>
                <SelectTrigger id="backup-dispatch-team" className="w-full" aria-label="Select available team">
                  <SelectValue placeholder="Choose available team" />
                </SelectTrigger>
                <SelectContent>
                  {sortedTeams.map((t) => {
                    const disabledReason = getTeamDisabledReason(t, selected);
                    return (
                      <SelectItem key={t.id} value={String(t.id)} disabled={disabledReason !== null}>
                        {t.name}
                        {disabledReason ? ` (${disabledReason})` : ' (Available)'}
                        {!disabledReason && t.vehicle_name ? ` · Default: ${t.vehicle_name}` : ''}
                      </SelectItem>
                    );
                  })}
                </SelectContent>
              </Select>
            </Field>

            {selectedTeamOption ? (
              <div className="rounded-md border bg-muted/20 p-3.5 text-sm">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="flex items-center gap-2 font-semibold text-foreground">
                    <Users className="size-4 text-muted-foreground" aria-hidden="true" />
                    {selectedTeamOption.name}
                  </p>
                  <span
                    className={cn(
                      'inline-flex h-5 items-center rounded-sm px-2 text-xs font-medium',
                      selectedTeamOption.is_available === false
                        ? 'bg-warning-soft text-warning'
                        : 'bg-ok-soft text-ok',
                    )}
                  >
                    {selectedTeamOption.is_available === false ? 'Busy' : 'Available'}
                  </span>
                </div>
                <p className="mt-1.5 text-xs text-muted-foreground">
                  {selectedTeamOption.members.length > 0
                    ? selectedTeamOption.members
                        .map((m) => `${m.name}${m.role ? ` (${m.role})` : ''}`)
                        .join(' · ')
                    : 'No active members listed'}
                </p>
                <div className="mt-2.5 flex items-center justify-between border-t pt-2 text-xs">
                  <span className="text-muted-foreground">Default vehicle</span>
                  <span className="font-medium text-foreground">
                    {selectedTeamOption.default_vehicle?.name ?? selectedTeamOption.vehicle_name ?? 'None assigned'}
                  </span>
                </div>
              </div>
            ) : (
              <div className="rounded-md border border-warning/30 bg-warning-soft p-3 text-xs text-warning">
                No other available response team is free for backup right now. All eligible teams are currently dispatched.{' '}
                <Link href="/dispatcher/responders" className="font-medium underline underline-offset-2">
                  Review response teams
                </Link>
              </div>
            )}
          </fieldset>

          {/* Vehicle to use for this backup */}
          <fieldset className="space-y-3 border-t pt-4">
            <div className="flex items-center justify-between gap-2">
              <legend className="eyebrow">Vehicle to use for this backup</legend>
              {selectableVehicles.length > 0 && (
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="h-7 px-2 text-xs"
                  onClick={() => setChangingVehicle((prev) => !prev)}
                >
                  <ArrowRightLeft className="size-3.5" aria-hidden="true" />
                  {changingVehicle ? 'Done' : 'Change vehicle'}
                </Button>
              )}
            </div>

            {selectedBackupVehicle ? (
              <div className="rounded-md border border-ok/30 bg-ok-soft p-3.5 text-sm">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="flex items-center gap-2.5">
                    <VehicleTypeIcon type={selectedBackupVehicle.vehicle_type} className="size-4 text-ok" />
                    <span className="font-semibold text-foreground">{selectedBackupVehicle.name}</span>
                  </div>
                  <span className="inline-flex h-5 items-center rounded-sm bg-background/80 px-2 text-xs font-medium text-ok">
                    {selectedBackupVehicle.assigned_report_id === selected.id ? 'Assigned' : 'Available'}
                  </span>
                </div>
                <p className="mt-1 text-xs text-muted-foreground">
                  {selectedBackupVehicle.vehicle_type_label ?? selectedBackupVehicle.vehicle_type} · Plate{' '}
                  <span className="font-mono">{selectedBackupVehicle.plate_number}</span>
                </p>
                {isTemporaryVehicleChange && selectedTeamOption?.default_vehicle && (
                  <p className="mt-2 rounded border border-ok/25 bg-background/70 px-2.5 py-1.5 text-xs text-foreground">
                    <strong>{selectedTeamOption.default_vehicle.name}</strong> will remain at the station. This change is temporary.
                  </p>
                )}
              </div>
            ) : (
              <div className="flex items-start gap-2 rounded-md border border-warning/30 bg-warning-soft p-3 text-xs text-warning">
                <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <span>
                  {selected.requested_vehicle_label
                    ? `No available ${selected.requested_vehicle_label} found right now.`
                    : 'No available vehicle found for this backup.'}
                </span>
              </div>
            )}

            {requestedTypeUnavailable && selectedBackupVehicle && (
              <p className="flex items-center gap-1.5 text-xs text-warning">
                <AlertTriangle className="size-3.5 shrink-0" aria-hidden="true" />
                No available {selected.requested_vehicle_label} right now; fallback vehicle selected.
              </p>
            )}

            {(changingVehicle || requestedTypeUnavailable || !selectedBackupVehicle) && selectableVehicles.length > 0 && (
              <Field label="Choose available vehicle" htmlFor="backup-vehicle-select">
                <Select value={vehicleId} onValueChange={setVehicleId}>
                  <SelectTrigger id="backup-vehicle-select" className="w-full" aria-label="Vehicle to use for this backup">
                    <SelectValue placeholder="Choose available vehicle" />
                  </SelectTrigger>
                  <SelectContent>
                    {selectableVehicles.map((v) => (
                      <SelectItem key={v.id} value={String(v.id)}>
                        {v.name} ({v.vehicle_type_label ?? v.vehicle_type}) · {v.plate_number}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
            )}

            {e.vehicle_id && <p role="alert" className="text-xs font-medium text-critical">{e.vehicle_id}</p>}
            {e.team && <p role="alert" className="text-xs font-medium text-critical">{e.team}</p>}
            {e.status && <p role="alert" className="text-xs font-medium text-critical">{e.status}</p>}
          </fieldset>
        </div>

        <footer className="flex flex-wrap justify-end gap-2 rounded-b-lg border-t bg-muted/40 px-5 py-3">
          <Button type="button" variant="outline" onClick={onDone}>Cancel</Button>
          {selected.status === 'pending' && (
            <Button
              type="button"
              variant="outline"
              onClick={() => router.patch(route('dispatcher.reports.invalid', selected.id), {}, { preserveScroll: true, onSuccess: onDone })}
            >
              Mark invalid
            </Button>
          )}
          <Button type="button" disabled={form.processing || !canDispatchTeam} onClick={handleDispatch}>
            <Radio aria-hidden="true" />
            {form.processing ? 'Dispatching backup…' : 'Dispatch Backup'}
          </Button>
        </footer>
      </section>
    );
  }

  return (
    <section aria-labelledby="dispatch-heading" className="rounded-lg border bg-card">
      <header className="border-b px-5 py-4">
        <div className="mb-1 flex flex-wrap items-center gap-2">
          <p className="eyebrow">{selected ? 'Incoming report' : 'New call'}</p>
          {selected && <Badge variant="secondary"><Lock aria-hidden="true" />Read-only</Badge>}
        </div>
        <h2 id="dispatch-heading" className="text-base font-semibold">{selected ? `Review ${selected.reference}` : 'Log an emergency call'}</h2>
        <p className="mt-0.5 text-xs text-muted-foreground">{selected ? 'Submitted report details are read-only. Review the caller, incident, and location before sending a team.' : 'Enter the caller details, locate the incident, then send the available team.'}</p>
      </header>

      <div className="space-y-6 p-5">
        <fieldset className="space-y-4">
          <legend className="eyebrow mb-3">Caller and incident</legend>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Caller / reporter" htmlFor="rn" required={callerRequired} hint={!readOnly && !callerRequired ? 'Optional for victim' : undefined} error={e.reporter_name}>
              <Input id="rn" autoComplete="name" readOnly={readOnly} className={readOnlyClass} aria-invalid={!!e.reporter_name} value={displayValue(form.data.reporter_name)} onChange={readOnly ? undefined : (ev) => form.setData('reporter_name', ev.target.value)} />
            </Field>
            <Field label="Contact number" htmlFor="rc" required={callerRequired} hint={!readOnly && !callerRequired ? 'Optional for victim' : undefined} error={e.reporter_contact}>
              <Input id="rc" type="tel" inputMode="tel" placeholder={readOnly ? undefined : '09XX XXX XXXX'} autoComplete="tel" readOnly={readOnly} className={readOnlyClass} aria-invalid={!!e.reporter_contact} value={displayValue(form.data.reporter_contact)} onChange={readOnly ? undefined : (ev) => form.setData('reporter_contact', ev.target.value)} />
            </Field>
          </div>
          <div className="grid gap-4 sm:grid-cols-[1fr_auto]">
            <Field label="Incident type" htmlFor="it" required={!readOnly} error={e.incident_type_id}>
              {readOnly ? (
                <Input id="it" readOnly className={readOnlyClass} value={selected.incident_type.name} />
              ) : (
                <Select
                  value={form.data.incident_type_id}
                  onValueChange={(v) => {
                    form.setData('incident_type_id', v);
                    if (v !== OTHER_INCIDENT_TYPE) {
                      form.setData('incident_type_name', '');
                    }
                  }}
                >
                  <SelectTrigger id="it" className="w-full"><SelectValue placeholder="Choose the type of emergency" /></SelectTrigger>
                  <SelectContent>
                    {selectableTypes.map((t) => <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>)}
                    <SelectItem value={OTHER_INCIDENT_TYPE}>Others</SelectItem>
                  </SelectContent>
                </Select>
              )}
            </Field>
            <Field label="Priority" hint="Set by incident type">
              <div className="flex h-9 items-center">{priority ? <StatusBadge value={priority} /> : <span className="text-sm text-muted-foreground">—</span>}</div>
            </Field>
          </div>
          {isOtherIncidentType && (
            <Field label="Specify incident type" htmlFor="incident-type-other" required error={e.incident_type_name}>
              <Input
                id="incident-type-other"
                placeholder="Enter the type of emergency (e.g., Fallen tree, Gas leak)"
                maxLength={80}
                aria-invalid={!!e.incident_type_name}
                value={form.data.incident_type_name}
                onChange={(ev) => form.setData('incident_type_name', ev.target.value)}
              />
            </Field>
          )}
          <Field label="Reporter is the">
            <ToggleGroup type="single" variant="outline" disabled={readOnly} aria-readonly={readOnly || undefined} value={form.data.reporter_type} onValueChange={(v) => !readOnly && v && form.setData('reporter_type', v as 'victim' | 'witness')}>
              <ToggleGroupItem value="victim" className={cn('px-4', readOnly && 'disabled:opacity-100 data-[state=off]:opacity-45 data-[state=on]:bg-muted data-[state=on]:font-semibold')}>Victim</ToggleGroupItem>
              <ToggleGroupItem value="witness" className={cn('px-4', readOnly && 'disabled:opacity-100 data-[state=off]:opacity-45 data-[state=on]:bg-muted data-[state=on]:font-semibold')}>Witness</ToggleGroupItem>
            </ToggleGroup>
          </Field>
          {showVictimInfo && (
            <div className="grid gap-4 sm:grid-cols-3">
              {(['name', 'age', 'sex'] as const).map((k) => (
                <Field
                  key={k}
                  label={`Victim ${k}`}
                  htmlFor={`victim-${k}`}
                  hint={!readOnly && k !== 'name' ? 'Optional' : undefined}
                  error={e[`victim_info.${k}`]}
                >
                  <Input
                    id={`victim-${k}`}
                    inputMode={k === 'age' ? 'numeric' : 'text'}
                    placeholder={!readOnly && k !== 'name' ? 'Optional' : undefined}
                    readOnly={readOnly}
                    className={readOnlyClass}
                    value={displayValue(form.data.victim_info[k])}
                    onChange={readOnly ? undefined : (ev) => form.setData('victim_info', { ...form.data.victim_info, [k]: ev.target.value })}
                  />
                </Field>
              ))}
            </div>
          )}
          {showWitnessInfo && (
            <Field label="Witness statement" htmlFor="ws"><Textarea id="ws" rows={2} readOnly={readOnly} className={readOnlyClass} value={displayValue(form.data.witness_info.statement)} onChange={readOnly ? undefined : (ev) => form.setData('witness_info', { statement: ev.target.value })} /></Field>
          )}
        </fieldset>

        <fieldset className="space-y-4 border-t pt-5">
          <legend className="eyebrow mb-3 pr-3">Location and details</legend>
          <Field label="Location" htmlFor="loc" required={!readOnly} error={e.location}>
            <Input id="loc" placeholder={readOnly ? undefined : 'Street, barangay, landmark'} readOnly={readOnly} className={readOnlyClass} value={displayValue(form.data.location)} onChange={readOnly ? undefined : (ev) => form.setData('location', ev.target.value)} />
          </Field>
          {readOnly && selected && (
            <Field label="Map pin" hint="Submitted incident location.">
              <LocationMap
                lat={Number.isFinite(selected.lat) ? selected.lat : DEFAULT_CENTER[1]}
                lng={Number.isFinite(selected.lng) ? selected.lng : DEFAULT_CENTER[0]}
                label={selected.reference}
                className="h-52"
              />
            </Field>
          )}
          <Field label="What happened?" htmlFor="incident-description" error={e.description}>
            <Textarea id="incident-description" rows={3} placeholder={readOnly ? undefined : 'Describe the emergency, people affected, and any useful landmarks.'} readOnly={readOnly} className={readOnlyClass} value={displayValue(form.data.description)} onChange={readOnly ? undefined : (ev) => form.setData('description', ev.target.value)} />
          </Field>
          {readOnly && selected.photo_url && (
            <Field label="Incident photo" hint="Submitted by the resident. Click to open full size.">
              <a href={selected.photo_url} target="_blank" rel="noopener noreferrer" className="block overflow-hidden rounded-md border bg-muted/30">
                <img src={selected.photo_url} alt={`Incident photo for ${selected.reference}`} className="max-h-64 w-full object-contain" />
              </a>
            </Field>
          )}
        </fieldset>

        <fieldset className="space-y-3 border-t pt-5">
          <legend className="eyebrow mb-3 pr-3">Response team</legend>
          <Field label="Select response team" htmlFor="dispatch-team" hint="Choose Auto-assign or manually select an active response team.">
            <Select value={form.data.team_id} onValueChange={(v) => form.setData('team_id', v)}>
              <SelectTrigger id="dispatch-team" className="w-full" aria-label="Select response team">
                <SelectValue placeholder="Choose response team" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="auto" disabled={!autoTeam}>
                  {autoTeam ? `Auto-assign (${autoTeam.name})` : 'Auto-assign (No available team)'}
                </SelectItem>
                {sortedTeams.map((t) => {
                  const disabledReason = getTeamDisabledReason(t, selected);
                  return (
                    <SelectItem key={t.id} value={String(t.id)} disabled={disabledReason !== null}>
                      {t.name}
                      {disabledReason
                        ? ` (${disabledReason})`
                        : `${t.vehicle_name ? ` · ${t.vehicle_name}` : ''}${t.shift_team ? ` (${t.shift_team === 'night' ? 'Night' : 'Day'})` : ''}`}
                    </SelectItem>
                  );
                })}
              </SelectContent>
            </Select>
          </Field>
          <div className={cn('rounded-md border p-3.5 text-sm', selectedTeamOption && !selectedTeamDisabledReason && selectedTeamOption.vehicle_name ? 'border-ok/30 bg-ok-soft' : 'border-warning/30 bg-warning-soft')}>
            <p className={cn('flex items-center gap-2 font-medium', selectedTeamOption && !selectedTeamDisabledReason && selectedTeamOption.vehicle_name ? 'text-ok' : 'text-warning')}>
              <Users className="size-4" aria-hidden="true" />
              {selectedTeamOption
                ? `${form.data.team_id === 'auto' ? 'Auto-assigned' : 'Selected team'}: ${selectedTeamOption.name}`
                : teams.some((t) => t.is_available === false)
                ? 'All active response teams are currently dispatched'
                : 'No team with an active driver/responder and assigned vehicle available'}
            </p>
            {!selectedTeamOption && (
              <p className="mt-1.5 text-xs text-foreground/80">
                {teams.some((t) => t.is_available === false)
                  ? 'Wait for a dispatched team to complete its active response before dispatching again.'
                  : 'A response team must have active members and an assigned vehicle before dispatching.'}{' '}
                <Link href="/dispatcher/responders" className="font-medium text-primary underline underline-offset-2">Review response teams</Link>
              </p>
            )}
            {selectedTeamDisabledReason && (
              <p role="alert" className="mt-1 text-xs font-medium text-critical">
                This team cannot be dispatched right now ({selectedTeamDisabledReason}).
              </p>
            )}
            {selectedTeamOption && !selectedTeamOption.vehicle_name && (
              <p role="alert" className="mt-1 text-xs font-medium text-critical">
                {selectedTeamOption.name} needs an assigned vehicle first before it can be dispatched.
              </p>
            )}
            {e.team && <p role="alert" className="mt-1 text-xs font-medium text-critical">{e.team}</p>}
            {e.status && <p role="alert" className="mt-1 text-xs font-medium text-critical">{e.status}</p>}
            {e.report_id && <p role="alert" className="mt-1 text-xs font-medium text-critical">{e.report_id}</p>}
            {selectedTeamOption && (
              <p className="mt-1 text-xs text-foreground/80">
                Members: {selectedTeamOption.members.map((m) => `${m.name}${m.role ? ` (${m.role})` : ''}`).join(', ') || '—'}
                {selectedTeamOption.vehicle_name ? ` · Vehicle: ${selectedTeamOption.vehicle_name}` : ' · Vehicle: None assigned'}
              </p>
            )}
          </div>
        </fieldset>
      </div>

      <footer className="flex flex-wrap justify-end gap-2 rounded-b-lg border-t bg-muted/40 px-5 py-3">
        {selected && <Button type="button" variant="outline" onClick={onDone}>Cancel</Button>}
        {selected && selected.status === 'pending' && <Button type="button" variant="outline" onClick={() => router.patch(route('dispatcher.reports.invalid', selected.id), {}, { preserveScroll: true, onSuccess: onDone })}>Mark invalid</Button>}
        <Button type="button" disabled={form.processing || !canDispatchTeam} onClick={handleDispatch}>
          <Radio aria-hidden="true" />{form.processing ? 'Sending dispatch…' : selected ? 'Dispatch team' : 'Create & dispatch'}
        </Button>
      </footer>
    </section>
  );
}

export default function Dashboard({ incoming, counts, types, autoTeam, teams = [], vehicles = [] }: Props) {
  const [selId, setSelId] = useState<number | null>(null);
  const [search, setSearch] = useState('');
  const [pendingOnly, setPendingOnly] = useState(false);
  const formPanel = useRef<HTMLDivElement>(null);
  const selected = incoming.find((r) => r.id === selId) ?? null;
  const visibleReports = incoming.filter((r) => (!pendingOnly || r.status === 'pending') && `${r.reference} ${r.location} ${r.incident_type.name} ${r.reporter_name ?? ''} ${r.requested_vehicle_label ?? ''}`.toLowerCase().includes(search.toLowerCase()));
  useEffect(() => {
    if (selId !== null && window.matchMedia('(max-width: 1279px)').matches) {
      formPanel.current?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'start' });
      formPanel.current?.focus({ preventScroll: true });
    }
  }, [selId]);
  return (
    <AppLayout>
      <Head title="Dispatch Operations" />
      <DispatchReportRealtime reloadProps={['incoming', 'counts', 'autoTeam', 'teams', 'vehicles']} />
      <PageHeader title="Dispatch operations" description="Review incoming incidents and coordinate your next response." />

      <StatGroup>
        <StatCard label="Pending" value={counts.pending ?? 0} status={counts.pending ? 'critical' : undefined} />
        <StatCard label="Acknowledged" value={counts.acknowledged ?? 0} status="info" />
        <StatCard label="Dispatched" value={counts.dispatched ?? 0} status="brand" />
        <StatCard label="Team on shift" value={autoTeam ? 1 : 0} status={autoTeam ? 'ok' : 'warning'} hint={autoTeam?.name ?? 'None available'} />
      </StatGroup>

      <div className="grid items-start gap-5 xl:grid-cols-[26rem_minmax(0,1fr)]">
        <section aria-labelledby="queue-heading" className="space-y-3">
          <div className="flex items-baseline justify-between gap-2">
            <h2 id="queue-heading" className="text-sm font-semibold">Incoming reports <span className="ml-1 font-normal text-muted-foreground tabular-nums">{incoming.length}</span></h2>
            <div className="flex gap-1" role="group" aria-label="Filter reports">
              <Button size="sm" variant={pendingOnly ? 'ghost' : 'secondary'} aria-pressed={!pendingOnly} onClick={() => setPendingOnly(false)}>All</Button>
              <Button size="sm" variant={pendingOnly ? 'secondary' : 'ghost'} aria-pressed={pendingOnly} onClick={() => setPendingOnly(true)}>Pending ({counts.pending ?? 0})</Button>
            </div>
          </div>
          <FilterBar search={search} onSearch={setSearch} placeholder="Search report or location…" />
          <div className="max-h-[28rem] overflow-y-auto rounded-lg border bg-card xl:max-h-[46rem]">
            {visibleReports.length === 0 ? (
              <EmptyState icon={Phone} title={incoming.length === 0 ? 'All clear' : 'No matching reports'} hint={incoming.length === 0 ? 'Incoming emergency reports will appear here.' : 'Try a different search or select all reports.'} />
            ) : (
              <ul className="divide-y">
                {visibleReports.map((r) => (
                  <li key={r.id}>
                    <button type="button" onClick={() => setSelId(selId === r.id ? null : r.id)} aria-pressed={selId === r.id} aria-controls="dispatch-form-panel"
                      className={cn('relative block min-h-11 w-full px-4 py-3 text-left transition-colors hover:bg-muted/50', selId === r.id && 'bg-primary/5 hover:bg-primary/5')}>
                      <span aria-hidden="true" className="absolute inset-y-0 left-0 w-1" style={{ background: selId === r.id ? 'var(--primary)' : r.incident_type.color }} />
                      <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span className="text-sm font-medium">{r.incident_type.name}</span>
                        {r.is_backup && (
                          <Badge variant="outline" className="border-primary/40 bg-primary/10 px-1.5 py-0 text-[11px] font-medium text-primary">
                            Backup{r.requested_vehicle_label ? ` · ${r.requested_vehicle_label}` : ''}
                          </Badge>
                        )}
                        <StatusBadge value={r.priority} /><StatusBadge value={r.status} />
                      </span>
                      <span className="mt-1 block truncate text-sm text-muted-foreground">{r.location}</span>
                      <span className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                        <span className="font-mono">{r.reference}</span>·
                        <span className="inline-flex items-center gap-1">{r.source === 'mobile-app' ? <Smartphone className="size-3" aria-hidden="true" /> : <PhoneCall className="size-3" aria-hidden="true" />}{r.source === 'mobile-app' ? 'App' : 'Call'}</span>·
                        <span className="tabular-nums">{formatDateTime(r.reported_at)}</span>{r.team && <>·<span>{r.team.team_name}</span></>}
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </section>

        <div id="dispatch-form-panel" ref={formPanel} tabIndex={-1} className="scroll-mt-16 outline-none">
          <DispatchForm
            key={selected?.id ?? 'new'}
            selected={selected}
            types={types}
            autoTeam={autoTeam}
            teams={teams}
            vehicles={vehicles}
            onDone={() => setSelId(null)}
          />
        </div>
      </div>
    </AppLayout>
  );
}
