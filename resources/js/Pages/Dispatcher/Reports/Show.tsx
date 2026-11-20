import { Head, Link, router } from '@inertiajs/react';
import { route } from 'ziggy-js';
import {
  ArrowLeft,
  Check,
  Clock,
  ExternalLink,
  Hospital,
  MapPin,
  Navigation,
  Phone,
  PhoneCall,
  Radio,
  Smartphone,
  UserCheck,
  Users,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { DispatchReportRealtime, type ReportUpdatedEvent } from '@/components/DispatchReportRealtime';
import { StatusBadge } from '@/components/StatusBadge';
import { GoogleLocationMap } from '@/components/maps/GoogleLocationMap';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn, formatDateTime } from '@/lib/utils';
import type { DispatchTeam, EmergencyReport, HospitalRecommendation } from '@/types/website';

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

export default function Show({
  report: r,
  team,
  teams,
  hospitals,
  googleMapsApiKey,
}: {
  report: EmergencyReport;
  team: DispatchTeam | null;
  teams: { id: number; team_name: string }[];
  hospitals: HospitalRecommendation[];
  googleMapsApiKey?: string | null;
}) {
  const [teamId, setTeamId] = useState<string>(String(team?.id ?? ''));
  const [route_, setRoute] = useState<number | null>(hospitals[0]?.id ?? null);
  const reloadTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    setTeamId(String(team?.id ?? ''));
  }, [team?.id]);

  useEffect(
    () => () => {
      if (reloadTimer.current) clearTimeout(reloadTimer.current);
    },
    [],
  );

  const handleReportUpdate = useCallback(
    (event: ReportUpdatedEvent) => {
      if (event.id !== r.id || reloadTimer.current) return;
      reloadTimer.current = setTimeout(() => {
        reloadTimer.current = null;
        router.reload({ only: ['report', 'team', 'hospitals'] });
      }, 250);
    },
    [r.id],
  );

  const open = !['resolved', 'invalid'].includes(r.status);
  const f = r.form;
  const selectedHospital = hospitals.find((h) => h.id === route_) ?? null;
  const driverFullName = r.driver ? `${r.driver.first_name} ${r.driver.last_name}`.trim() : null;
  const hasCoordinates = Number.isFinite(r.lat) && Number.isFinite(r.lng);

  const hasEmergencySection = Boolean(
    f &&
      Object.values(f.emergency_section ?? {}).some(
        (v) => v !== null && v !== '' && (typeof v !== 'object' || Object.values(v ?? {}).some(Boolean)),
      ),
  );
  const e = f?.emergency_section ?? {};
  const victim = (r.victim_info ?? {}) as Record<string, unknown>;
  const pickVal = (...candidates: unknown[]): string | null => {
    for (const c of candidates) {
      if (c !== null && c !== undefined && String(c).trim() !== '') {
        return String(c).trim();
      }
    }
    return null;
  };
  const hasDriverSection = Boolean(
    f && Object.values(f.driver_section ?? {}).some((v) => v !== null && v !== ''),
  );
  const hasVictimInfo = Boolean(
    r.victim_info && Object.values(r.victim_info).some((v) => v !== null && String(v).trim() !== ''),
  );

  const dispatchSelectedTeam = () =>
    router.post(route('dispatcher.reports.assign', r.id), { team_id: teamId }, { preserveScroll: true });

  return (
    <AppLayout>
      <Head title={r.reference} />
      <DispatchReportRealtime onEvent={handleReportUpdate} />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <Link
          href={route('dispatcher.dashboard')}
          className="inline-flex min-h-9 w-fit items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
        >
          <ArrowLeft className="size-4" aria-hidden="true" />
          Back to operations
        </Link>
        <div className="flex flex-wrap items-center gap-2 text-xs">
          <Link
            href={route('dispatcher.map')}
            className="inline-flex h-8 items-center gap-1.5 rounded-md border bg-card px-2.5 font-medium text-muted-foreground transition-colors hover:text-foreground"
          >
            <Navigation className="size-3.5" aria-hidden="true" />
            Live response map
          </Link>
          <Link
            href={route('dispatcher.reports.index')}
            className="inline-flex h-8 items-center rounded-md border bg-card px-2.5 font-medium text-muted-foreground transition-colors hover:text-foreground"
          >
            Reports archive
          </Link>
        </div>
      </div>

      <section aria-label="Incident summary" className="rounded-lg border bg-card p-5">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0 space-y-2">
            <div className="flex flex-wrap items-center gap-2">
              <StatusBadge value={r.priority} />
              <StatusBadge value={r.status} />
              <span className="inline-flex h-5 items-center gap-1 rounded-sm border bg-muted/50 px-2 font-mono text-xs font-medium text-foreground">
                {r.reference}
              </span>
              <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                {r.source === 'mobile-app' ? (
                  <Smartphone className="size-3.5" aria-hidden="true" />
                ) : (
                  <PhoneCall className="size-3.5" aria-hidden="true" />
                )}
                {r.source === 'mobile-app' ? 'Mobile app' : 'Emergency call'}
              </span>
            </div>
            <h1 className="text-xl font-semibold tracking-tight">{r.incident_type.name}</h1>
            <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
              <span className="inline-flex items-center gap-1.5 text-foreground">
                <MapPin className="size-3.5 shrink-0 text-critical" aria-hidden="true" />
                {r.location}
              </span>
              <span aria-hidden="true">·</span>
              <span className="inline-flex items-center gap-1 tabular-nums">
                <Clock className="size-3.5 shrink-0" aria-hidden="true" />
                {formatDateTime(r.reported_at)}
              </span>
            </p>
          </div>

          {open && r.status === 'pending' && (
            <div className="flex flex-wrap items-center gap-2">
              <Button
                variant="outline"
                onClick={() => router.patch(route('dispatcher.reports.acknowledge', r.id), {}, { preserveScroll: true })}
              >
                <Check aria-hidden="true" />
                Acknowledge
              </Button>
            </div>
          )}
        </div>

        <dl className="mt-4 grid gap-3 border-t pt-4 sm:grid-cols-2 lg:grid-cols-4">
          <div className="rounded-md border bg-muted/30 px-3 py-2">
            <dt className="eyebrow">Reporter</dt>
            <dd className="mt-0.5 truncate text-sm font-medium">{r.reporter_name || '—'}</dd>
            {r.reporter_contact && <dd className="text-xs text-muted-foreground tabular-nums">{r.reporter_contact}</dd>}
          </div>
          <div className="rounded-md border bg-muted/30 px-3 py-2">
            <dt className="eyebrow">Response unit</dt>
            <dd className="mt-0.5 truncate text-sm font-medium">
              {r.team?.team_name ?? (team ? `${team.name} (suggested)` : 'Unassigned')}
            </dd>
            {driverFullName && <dd className="text-xs text-muted-foreground">Driver: {driverFullName}</dd>}
          </div>
          <div className="rounded-md border bg-muted/30 px-3 py-2">
            <dt className="eyebrow">Acknowledged / Resolved</dt>
            <dd className="mt-0.5 text-sm font-medium tabular-nums">
              {r.acknowledged_at ? formatDateTime(r.acknowledged_at) : 'Pending review'}
            </dd>
            {r.resolved_at && (
              <dd className="text-xs text-ok tabular-nums">Resolved {formatDateTime(r.resolved_at)}</dd>
            )}
          </div>
          <div className="rounded-md border bg-muted/30 px-3 py-2">
            <dt className="eyebrow">Coordinates</dt>
            <dd className="mt-0.5 font-mono text-xs font-medium tabular-nums">
              {hasCoordinates ? `${r.lat.toFixed(5)}, ${r.lng.toFixed(5)}` : 'Not recorded'}
            </dd>
            {hasCoordinates && (
              <dd className="mt-0.5">
                <a
                  href={`https://www.google.com/maps/search/?api=1&query=${r.lat},${r.lng}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                >
                  Open in Google Maps
                  <ExternalLink className="size-3" aria-hidden="true" />
                </a>
              </dd>
            )}
          </div>
        </dl>
      </section>

      <div className="grid items-start gap-5 lg:grid-cols-12">
        <div className="space-y-5 lg:col-span-7">
          <Card>
            <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
              <div>
                <CardTitle>Incident location &amp; hospital routing</CardTitle>
                <CardDescription>
                  Google Maps view showing the incident pin, nearby hospitals, and direction line.
                </CardDescription>
              </div>
              {selectedHospital && (
                <span className="inline-flex items-center gap-1.5 rounded-sm bg-info-soft px-2 py-1 text-xs font-medium text-info tabular-nums">
                  <Hospital className="size-3.5" aria-hidden="true" />
                  {selectedHospital.name} · {selectedHospital.distance} km
                </span>
              )}
            </CardHeader>
            <CardContent className="space-y-3">
              <GoogleLocationMap
                lat={r.lat}
                lng={r.lng}
                label={r.reference}
                locationText={r.location}
                hospitals={hospitals}
                routeTo={route_}
                onSelectHospital={setRoute}
                apiKey={googleMapsApiKey}
                className="h-[380px]"
              />
              <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                <div className="flex flex-wrap items-center gap-4">
                  <span className="inline-flex items-center gap-1.5">
                    <span className="size-2.5 rounded-full bg-critical" aria-hidden="true" />
                    Incident pin
                  </span>
                  <span className="inline-flex items-center gap-1.5">
                    <span className="size-2.5 rounded-full bg-info" aria-hidden="true" />
                    Hospital
                  </span>
                  {selectedHospital && (
                    <span className="inline-flex items-center gap-1.5">
                      <span className="h-0.5 w-4 bg-primary" aria-hidden="true" />
                      Selected direction
                    </span>
                  )}
                </div>
                {hasCoordinates && selectedHospital && (
                  <a
                    href={`https://www.google.com/maps/dir/?api=1&origin=${r.lat},${r.lng}&destination=${selectedHospital.lat},${selectedHospital.lng}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1 font-medium text-primary hover:underline"
                  >
                    Turn-by-turn in Google Maps
                    <ExternalLink className="size-3" aria-hidden="true" />
                  </a>
                )}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Field report &amp; parties involved</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4 text-sm">
              <div className="rounded-md border bg-muted/35 p-3.5">
                <p className="eyebrow mb-1">Description</p>
                <p className="leading-relaxed text-foreground">{r.description || 'No description provided.'}</p>
              </div>

              {r.photo_url && (
                <div className="space-y-1.5">
                  <p className="eyebrow">Incident photo</p>
                  <a
                    href={r.photo_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="block overflow-hidden rounded-md border bg-muted/30 transition-opacity hover:opacity-95"
                  >
                    <img
                      src={r.photo_url}
                      alt={`Incident photo for ${r.reference}`}
                      className="max-h-80 w-full object-contain"
                    />
                  </a>
                </div>
              )}

              {(hasVictimInfo || r.witness_info?.statement) && (
                <div className="grid gap-3 sm:grid-cols-2">
                  {hasVictimInfo && r.victim_info && (
                    <div className="rounded-md border p-3.5">
                      <p className="eyebrow mb-2">Victim</p>
                      <dl className="space-y-1">
                        {Object.entries(r.victim_info)
                          .filter(([, v]) => v != null && String(v).trim() !== '')
                          .map(([k, v]) => (
                            <div key={k} className="flex justify-between gap-2 text-xs">
                              <dt className="capitalize text-muted-foreground">{k}</dt>
                              <dd className="font-medium text-foreground">{String(v)}</dd>
                            </div>
                          ))}
                      </dl>
                    </div>
                  )}
                  {r.witness_info?.statement && (
                    <div className="rounded-md border p-3.5">
                      <p className="eyebrow mb-2">Witness statement</p>
                      <p className="text-xs leading-relaxed text-muted-foreground">{r.witness_info.statement}</p>
                    </div>
                  )}
                </div>
              )}
            </CardContent>
          </Card>
        </div>

        <div className="space-y-5 lg:col-span-5">
          <Card>
            <CardHeader className="flex flex-row items-start justify-between gap-3">
              <div>
                <CardTitle className="flex items-center gap-2">
                  <Users className="size-4 text-muted-foreground" aria-hidden="true" />
                  Response team
                </CardTitle>
                <CardDescription>
                  {open ? 'Choose or confirm the active team to deploy.' : 'Team assigned to this incident.'}
                </CardDescription>
              </div>
              {team && (
                <span
                  className={cn(
                    'inline-flex h-5 items-center rounded-sm px-1.5 text-xs font-medium',
                    team.assigned ? 'bg-ok-soft text-ok' : 'bg-warning-soft text-warning',
                  )}
                >
                  {team.assigned ? 'Assigned' : 'Suggested'}
                </span>
              )}
            </CardHeader>
            <CardContent className="space-y-3">
              {open && (
                <div className="flex flex-col gap-2 sm:flex-row">
                  <Select value={teamId} onValueChange={setTeamId}>
                    <SelectTrigger aria-label="Team" className="w-full">
                      <SelectValue placeholder="Choose team" />
                    </SelectTrigger>
                    <SelectContent>
                      {teams.map((t) => (
                        <SelectItem key={t.id} value={String(t.id)}>
                          {t.team_name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <Button type="button" onClick={dispatchSelectedTeam} className="shrink-0">
                    <Radio aria-hidden="true" />
                    {team?.assigned ? 'Re-assign' : 'Dispatch'}
                  </Button>
                </div>
              )}
              {team ? (
                <>
                  <div className="flex items-center justify-between text-sm">
                    <span className="font-medium">{team.name}</span>
                    <span className="text-xs text-muted-foreground tabular-nums">
                      {team.onShift.length} on shift
                    </span>
                  </div>
                  <ul className="divide-y rounded-md border">
                    {team.onShift.map((m) => (
                      <li key={m.id} className="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                        <span className="inline-flex items-center gap-2 font-medium">
                          <UserCheck className="size-3.5 text-muted-foreground" aria-hidden="true" />
                          {m.name}
                        </span>
                        <span className="rounded-sm bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                          {m.role ?? 'Member'}
                        </span>
                      </li>
                    ))}
                  </ul>
                </>
              ) : (
                <p className="rounded-md border border-dashed px-3 py-4 text-center text-sm text-muted-foreground">
                  No active team on shift.
                </p>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Hospital className="size-4 text-muted-foreground" aria-hidden="true" />
                Recommended hospitals
              </CardTitle>
              <CardDescription>
                Specialty matches first, then nearest. Distances are straight-line estimates. Select a hospital to
                preview its direction on Google Maps.
              </CardDescription>
            </CardHeader>
            <CardContent className="px-0 pb-0">
              {hospitals.length === 0 && (
                <p className="px-6 pb-6 text-sm text-muted-foreground">No hospitals on file.</p>
              )}
              <ul className="divide-y border-t">
                {hospitals.map((h) => {
                  const active = route_ === h.id;
                  return (
                    <li key={h.id}>
                      <button
                        type="button"
                        onClick={() => setRoute(h.id)}
                        aria-pressed={active}
                        className={cn(
                          'block min-h-11 w-full px-6 py-3.5 text-left text-sm transition-colors hover:bg-muted/50',
                          active && 'bg-primary/5',
                        )}
                      >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                          <span className="font-medium text-foreground">{h.name}</span>
                          <div className="flex flex-wrap items-center gap-1.5">
                            {h.match && (
                              <span className="inline-flex h-5 items-center rounded-sm bg-ok-soft px-1.5 text-xs font-medium text-ok">
                                Specialty match
                              </span>
                            )}
                            {active && (
                              <span className="inline-flex h-5 items-center rounded-sm bg-primary/10 px-1.5 text-xs font-medium text-primary">
                                On map
                              </span>
                            )}
                          </div>
                        </div>
                        {h.address && (
                          <p className="mt-0.5 truncate text-xs text-muted-foreground">{h.address}</p>
                        )}
                        <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground tabular-nums">
                          <span className="font-medium text-foreground">
                            {h.distance} km · ~{h.eta} min
                          </span>
                          <span>
                            {h.availableBeds == null ? 'Bed availability unconfirmed' : `${h.availableBeds} beds`}
                          </span>
                          <span className="inline-flex items-center gap-1">
                            <Phone className="size-3" aria-hidden="true" />
                            {h.contactNumber ?? 'Contact not listed'}
                          </span>
                        </div>
                      </button>
                    </li>
                  );
                })}
              </ul>
            </CardContent>
          </Card>
        </div>

        {(hasEmergencySection || hasVictimInfo) && (
          <Card className="lg:col-span-12">
            <CardHeader>
              <CardTitle>Emergency Report</CardTitle>
              <CardDescription>Clinical assessment and vitals submitted from the field.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <div className="grid gap-3 sm:grid-cols-3">
                <div className="rounded-md border bg-muted/30 px-3.5 py-2.5">
                  <p className="eyebrow">Blood Pressure</p>
                  <p className="mt-0.5 text-base font-semibold tabular-nums">
                    {pickVal(e.vitalSigns?.bloodPressure, victim.blood_pressure) || '—'}
                  </p>
                </div>
                <div className="rounded-md border bg-muted/30 px-3.5 py-2.5">
                  <p className="eyebrow">Pulse Rate (BPM)</p>
                  <p className="mt-0.5 text-base font-semibold tabular-nums">
                    {pickVal(e.vitalSigns?.pulseRate, victim.pulse_rate) || '—'}
                  </p>
                </div>
                <div className="rounded-md border bg-muted/30 px-3.5 py-2.5">
                  <p className="eyebrow">Respiration (BPM)</p>
                  <p className="mt-0.5 text-base font-semibold tabular-nums">
                    {pickVal(e.vitalSigns?.respiration, victim.respiration) || '—'}
                  </p>
                </div>
              </div>
              <div className="grid gap-x-10 gap-y-4 sm:grid-cols-2">
                <dl className="divide-y">
                  <Row k="Time Reported" v={pickVal(e.timeReported, victim.time_reported, r.reported_at ? formatDateTime(r.reported_at) : null)} always />
                  <Row k="Time of arrival on scene" v={pickVal(e.timeOfArrival, victim.time_of_arrival)} always />
                  <Row k="Date" v={pickVal(e.date, victim.date, r.reported_at ? r.reported_at.slice(0, 10) : null)} always />
                  <Row k="Location" v={pickVal(e.location, r.location)} always />
                  <Row k="Name of Patient" v={pickVal(e.patientName, victim.name)} always />
                  <Row k="Age" v={pickVal(e.age, victim.age)} always />
                  <Row k="Sex" v={pickVal(e.sex, victim.sex)} always />
                  <Row k="Address" v={pickVal(e.address, victim.address)} always />
                  <Row k="Type of incident" v={pickVal(e.typeOfIncident, victim.type_of_incident, r.incident_type?.name)} always />
                </dl>
                <dl className="divide-y">
                  <Row k="Allergies" v={pickVal(e.allergies, victim.allergies)} always />
                  <Row k="Medication" v={pickVal(e.medications, victim.medications)} always />
                  <Row k="Assessment / Comment" v={pickVal(e.assessmentComment, victim.assessment_comment, victim.symptoms)} always />
                  <Row k="Treatment / Management" v={pickVal(e.treatmentManagement, victim.treatment_management)} always />
                </dl>
              </div>
            </CardContent>
          </Card>
        )}

        {hasDriverSection && f && (
          <Card className="lg:col-span-12">
            <CardHeader>
              <CardTitle>Driver Form</CardTitle>
              <CardDescription>Trip authorization and fuel consumption record.</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-x-10 gap-y-6 sm:grid-cols-2">
              <div>
                <p className="eyebrow mb-2">Authorization</p>
                <dl className="divide-y">
                  <Row k="Trip Date" v={f.driver_section?.tripDate} />
                  <Row k="Assigned Driver" v={f.driver_section?.driverName} />
                  <Row k="Government Vehicle" v={f.driver_section?.governmentCardPlateNo} />
                  <Row
                    k="Authorized Passenger"
                    v={f.driver_section?.authorizedPassenger || f.driver_section?.passengerName}
                  />
                  <Row k="Destination / Place to Visit" v={f.driver_section?.placeVisitedInspected} />
                  <Row k="Purpose of Trip" v={f.driver_section?.purpose} />
                </dl>
              </div>
              <div>
                <p className="eyebrow mb-2">Fuel</p>
                <dl className="divide-y">
                  <Row k="Balance in Tank (L)" v={formatLiter(f.driver_section?.balanceInTank)} />
                  <Row k="Issued by Office (L)" v={formatLiter(f.driver_section?.issuedByOffice)} />
                  <Row k="Purchased During Trip (L)" v={formatLiter(f.driver_section?.purchasedDuringTrip)} />
                  <Row k="Total Available Fuel (L)" v={formatLiter(f.driver_section?.totalAvailableFuel)} />
                  <Row k="Fuel Used During Trip (L)" v={formatLiter(f.driver_section?.fuelUsedDuringTrip)} />
                  <Row k="Remaining Fuel (L)" v={formatLiter(f.driver_section?.remainingFuel)} />
                  <Row k="Gear Oil Used (L)" v={formatLiter(f.driver_section?.gearOilUsed)} />
                </dl>
              </div>
            </CardContent>
          </Card>
        )}
      </div>
    </AppLayout>
  );
}
