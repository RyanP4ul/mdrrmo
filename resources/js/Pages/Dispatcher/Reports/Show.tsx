import { Head, Link, router } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { ArrowLeft, Check, Hospital, PhoneCall, Radio, Smartphone } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { StartVoiceCall } from '@/components/VoiceCalls';
import { StatusBadge } from '@/components/StatusBadge';
import { LocationMap } from '@/components/maps/LocationMap';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn, formatDateTime } from '@/lib/utils';
import type { DispatchTeam, EmergencyReport, HospitalRecommendation } from '@/types/website';

const Row = ({ k, v }: { k: string; v?: string | null }) => v ? <div className="flex justify-between gap-4 py-2 text-sm"><dt className="text-muted-foreground">{k}</dt><dd className="text-right font-medium">{v}</dd></div> : null;

export default function Show({ report: r, team, teams, hospitals }: { report: EmergencyReport; team: DispatchTeam | null; teams: { id: number; team_name: string }[]; hospitals: HospitalRecommendation[] }) {
  const [teamId, setTeamId] = useState<string>(String(team?.id ?? ''));
  const [route_, setRoute] = useState<number | null>(hospitals[0]?.id ?? null);
  const open = !['resolved', 'invalid'].includes(r.status);
  const f = r.form;
  return (
    <AppLayout>
      <Head title={r.reference} />
      <Link href={route('dispatcher.dashboard')} className="inline-flex min-h-9 w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" aria-hidden="true" />Back to operations</Link>

      <header className="flex flex-wrap items-start justify-between gap-4 border-b pb-4">
        <div className="min-w-0 space-y-1.5">
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge value={r.priority} /><StatusBadge value={r.status} />
            <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">{r.source === 'mobile-app' ? <Smartphone className="size-3.5" aria-hidden="true" /> : <PhoneCall className="size-3.5" aria-hidden="true" />}{r.source === 'mobile-app' ? 'Mobile app' : 'Emergency call'}</span>
          </div>
          <h1 className="text-xl font-semibold tracking-tight">{r.incident_type.name} <span className="ml-1 font-mono text-sm font-normal text-muted-foreground">{r.reference}</span></h1>
          <p className="text-sm text-muted-foreground">{r.location} · <span className="tabular-nums">{formatDateTime(r.reported_at)}</span></p>
        </div>
        {open && (
          <div className="flex flex-wrap gap-2">
            <StartVoiceCall reportId={r.id} />
            {r.status === 'pending' && <Button variant="outline" onClick={() => router.patch(route('dispatcher.reports.acknowledge', r.id), {}, { preserveScroll: true })}><Check aria-hidden="true" />Acknowledge</Button>}
            <Button onClick={() => router.post(route('dispatcher.reports.assign', r.id), { team_id: teamId }, { preserveScroll: true })}><Radio aria-hidden="true" />{team?.assigned ? 'Re-assign team' : 'Dispatch team'}</Button>
          </div>
        )}
      </header>

      <div className="grid items-start gap-5 lg:grid-cols-2">
        <Card>
          <CardHeader><CardTitle>Response team</CardTitle></CardHeader>
          <CardContent className="space-y-3">
            {open && (
              <Select value={teamId} onValueChange={setTeamId}>
                <SelectTrigger aria-label="Team" className="w-full"><SelectValue placeholder="Choose team" /></SelectTrigger>
                <SelectContent>{teams.map((t) => <SelectItem key={t.id} value={String(t.id)}>{t.team_name}</SelectItem>)}</SelectContent>
              </Select>
            )}
            {team ? (
              <>
                <p className="text-sm font-medium">{team.name} <span className="font-normal text-muted-foreground">{team.assigned ? '(assigned)' : '(suggested)'}</span></p>
                <ul className="divide-y rounded-md border">{team.onShift.map((m) => <li key={m.id} className="flex justify-between px-3 py-2 text-sm"><span>{m.name}</span><span className="text-muted-foreground">{m.role}</span></li>)}</ul>
              </>
            ) : <p className="text-sm text-muted-foreground">No active team on shift.</p>}
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>Incident location</CardTitle></CardHeader>
          <CardContent><LocationMap lat={r.lat} lng={r.lng} label={r.reference} hospitals={hospitals} routeTo={route_} /></CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>Description</CardTitle></CardHeader>
          <CardContent className="space-y-3 text-sm">
            <p>{r.description || 'No description.'}</p>
            {r.victim_info && (
              <div className="rounded-md border p-3"><p className="eyebrow mb-1.5">Victim</p>
                {Object.entries(r.victim_info).filter(([, v]) => v).map(([k, v]) => <p key={k} className="capitalize text-muted-foreground">{k}: <span className="text-foreground">{String(v)}</span></p>)}
              </div>
            )}
            {r.witness_info?.statement && <div className="rounded-md border p-3"><p className="eyebrow mb-1.5">Witness statement</p><p className="text-muted-foreground">{r.witness_info.statement}</p></div>}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2"><Hospital className="size-4 text-muted-foreground" aria-hidden="true" />Recommended hospitals</CardTitle>
            <CardDescription>Specialty matches first, then nearest. Distances are straight-line estimates. Select a hospital to show its direction on the map.</CardDescription>
          </CardHeader>
          <CardContent className="px-0">
            {hospitals.length === 0 && <p className="px-4 text-sm text-muted-foreground">No hospitals on file.</p>}
            <ul className="divide-y border-y first:border-t-0 last:border-b-0">
              {hospitals.map((h) => (
                <li key={h.id}>
                  <button type="button" onClick={() => setRoute(h.id)} aria-pressed={route_ === h.id} className={cn('block min-h-11 w-full px-4 py-3 text-left text-sm transition-colors hover:bg-muted/50', route_ === h.id && 'bg-primary/5')}>
                    <span className="flex items-center justify-between gap-2"><span className="font-medium">{h.name}</span>{h.match && <span className="inline-flex h-5 items-center rounded-sm bg-ok-soft px-1.5 text-xs font-medium text-ok">Specialty match</span>}</span>
                    <span className="mt-0.5 block text-xs text-muted-foreground tabular-nums">{h.distance} km · ~{h.eta} min estimated · {h.availableBeds == null ? 'Bed availability unconfirmed' : `${h.availableBeds} beds`} · {h.contactNumber ?? 'Contact not listed'}</span>
                  </button>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>

        {f && Object.values(f.emergency_section ?? {}).some((v) => v !== null && v !== '' && (typeof v !== 'object' || Object.values(v ?? {}).some(Boolean))) && (
          <Card className="lg:col-span-2">
            <CardHeader><CardTitle>Emergency / Patient Care Report</CardTitle></CardHeader>
            <CardContent className="grid gap-x-10 gap-y-4 sm:grid-cols-2">
              <dl className="divide-y">
                <Row k="Patient" v={f.emergency_section?.patientName} />
                <Row k="Age / sex" v={[f.emergency_section?.age, f.emergency_section?.sex].filter(Boolean).join(' · ') || null} />
                <Row k="Address" v={f.emergency_section?.address} />
                <Row k="Incident" v={f.emergency_section?.typeOfIncident} />
                <Row k="Time reported" v={f.emergency_section?.timeReported} />
                <Row k="Time of arrival" v={f.emergency_section?.timeOfArrival} />
              </dl>
              <dl className="divide-y">
                <Row k="Blood pressure" v={f.emergency_section?.vitalSigns?.bloodPressure} />
                <Row k="Pulse rate" v={f.emergency_section?.vitalSigns?.pulseRate} />
                <Row k="Respiration" v={f.emergency_section?.vitalSigns?.respiration} />
                <Row k="Allergies" v={f.emergency_section?.allergies} />
                <Row k="Medications" v={f.emergency_section?.medications} />
                <Row k="Assessment" v={f.emergency_section?.assessmentComment} />
                <Row k="Treatment" v={f.emergency_section?.treatmentManagement} />
              </dl>
            </CardContent>
          </Card>
        )}

        {f && Object.values(f.driver_section ?? {}).some((v) => v !== null && v !== '') && (
          <Card className="lg:col-span-2">
            <CardHeader><CardTitle>Driver Form</CardTitle></CardHeader>
            <CardContent className="grid gap-x-10 gap-y-6 sm:grid-cols-2">
              <div>
                <p className="eyebrow mb-2">Authorization</p>
                <dl className="divide-y">
                  <Row k="Trip Date" v={f.driver_section?.tripDate} />
                  <Row k="Assigned Driver" v={f.driver_section?.driverName} />
                  <Row k="Government Vehicle" v={f.driver_section?.governmentCardPlateNo} />
                  <Row k="Authorized Passenger" v={f.driver_section?.authorizedPassenger || f.driver_section?.passengerName} />
                  <Row k="Destination / Place to Visit" v={f.driver_section?.placeVisitedInspected} />
                  <Row k="Purpose of Trip" v={f.driver_section?.purpose} />
                </dl>
              </div>
              <div>
                <p className="eyebrow mb-2">Fuel</p>
                <dl className="divide-y">
                  <Row k="Balance in Tank (L)" v={f.driver_section?.balanceInTank != null && f.driver_section?.balanceInTank !== '' ? `${f.driver_section.balanceInTank} L` : null} />
                  <Row k="Issued by Office (L)" v={f.driver_section?.issuedByOffice != null && f.driver_section?.issuedByOffice !== '' ? `${f.driver_section.issuedByOffice} L` : null} />
                  <Row k="Purchased During Trip (L)" v={f.driver_section?.purchasedDuringTrip != null && f.driver_section?.purchasedDuringTrip !== '' ? `${f.driver_section.purchasedDuringTrip} L` : null} />
                  <Row k="Total Available Fuel (L)" v={f.driver_section?.totalAvailableFuel != null && f.driver_section?.totalAvailableFuel !== '' ? `${f.driver_section.totalAvailableFuel} L` : null} />
                  <Row k="Fuel Used During Trip (L)" v={f.driver_section?.fuelUsedDuringTrip != null && f.driver_section?.fuelUsedDuringTrip !== '' ? `${f.driver_section.fuelUsedDuringTrip} L` : null} />
                  <Row k="Remaining Fuel (L)" v={f.driver_section?.remainingFuel != null && f.driver_section?.remainingFuel !== '' ? `${f.driver_section.remainingFuel} L` : null} />
                  <Row k="Gear Oil Used (L)" v={f.driver_section?.gearOilUsed != null && f.driver_section?.gearOilUsed !== '' ? `${f.driver_section.gearOilUsed} L` : null} />
                </dl>
              </div>
            </CardContent>
          </Card>
        )}
      </div>
    </AppLayout>
  );
}
