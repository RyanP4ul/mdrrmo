import { Head, Link, router } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { Archive, ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { StatusBadge } from '@/components/StatusBadge';
import { LocationMap } from '@/components/maps/LocationMap';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatDateTime } from '@/lib/utils';
import type { EmergencyReport } from '@/types/website';

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

export default function Show({ report: r }: { report: EmergencyReport }) {
  const [confirmArchive, setConfirmArchive] = useState(false);
  const upd = (patch: Record<string, string>) => router.patch(route('admin.reports.update', r.id), { status: r.status, priority: r.priority, ...patch }, { preserveScroll: true });
  const f = r.form;
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
  const canArchive = r.status === 'resolved' || r.status === 'invalid';
  return (
    <AppLayout>
      <Head title={r.reference} />
      <Link href={route('admin.reports.index')} className="inline-flex min-h-9 w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" aria-hidden="true" />Back to reports</Link>

      <header className="flex flex-wrap items-start justify-between gap-4 border-b pb-4">
        <div className="min-w-0 space-y-1.5">
          <div className="flex gap-2"><StatusBadge value={r.priority} /><StatusBadge value={r.status} /></div>
          <h1 className="text-xl font-semibold tracking-tight">{r.incident_type.name} <span className="ml-1 font-mono text-sm font-normal text-muted-foreground">{r.reference}</span></h1>
          <p className="text-sm text-muted-foreground tabular-nums">{formatDateTime(r.reported_at)}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Select value={r.priority} onValueChange={(v) => upd({ priority: v })}>
            <SelectTrigger className="w-32" aria-label="Priority"><SelectValue /></SelectTrigger>
            <SelectContent>{['low', 'medium', 'high', 'critical'].map((p) => <SelectItem key={p} value={p} className="capitalize">{p}</SelectItem>)}</SelectContent>
          </Select>
          <Select value={r.status} onValueChange={(v) => upd({ status: v })}>
            <SelectTrigger className="w-36" aria-label="Status"><SelectValue /></SelectTrigger>
            <SelectContent>{['pending', 'acknowledged', 'dispatched', 'resolved', 'invalid'].map((p) => <SelectItem key={p} value={p} className="capitalize">{p}</SelectItem>)}</SelectContent>
          </Select>
          {canArchive && (
            <Button type="button" variant="outline" onClick={() => setConfirmArchive(true)}>
              <Archive className="mr-1.5 size-4" />
              Archive
            </Button>
          )}
        </div>
      </header>

      <div className="grid items-start gap-5 lg:grid-cols-2">
        <Card>
          <CardHeader><CardTitle>Incident</CardTitle></CardHeader>
          <CardContent className="space-y-3">
            <dl className="divide-y">
              <Row k="Location" v={r.location} /><Row k="Source" v={r.source === 'mobile-app' ? 'Mobile app' : 'Emergency call'} />
              <Row k="Reporter" v={`${r.reporter_name}${r.reporter_contact ? ' · ' + r.reporter_contact : ''}`} />
              <Row k="Team" v={r.team?.team_name} /><Row k="Driver" v={r.driver ? `${r.driver.first_name} ${r.driver.last_name}` : null} />
            </dl>
            <p className="rounded-md bg-muted p-3 text-sm">{r.description || 'No description provided.'}</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle>Location</CardTitle></CardHeader>
          <CardContent><LocationMap lat={r.lat} lng={r.lng} label={r.reference} /></CardContent>
        </Card>
        <Card className="lg:col-span-2">
          <CardHeader><CardTitle>Emergency Report</CardTitle></CardHeader>
          <CardContent className="grid gap-x-10 gap-y-6 sm:grid-cols-2">
            <div>
              <p className="eyebrow mb-2">Incident &amp; Patient Details</p>
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
            </div>
            <div className="space-y-6">
              <div>
                <p className="eyebrow mb-2">Clinical Assessment &amp; Care</p>
                <dl className="divide-y">
                  <Row k="Allergies" v={pickVal(e.allergies, victim.allergies)} always />
                  <Row k="Medication" v={pickVal(e.medications, victim.medications)} always />
                  <Row k="Assessment / Comment" v={pickVal(e.assessmentComment, victim.assessment_comment, victim.symptoms)} always />
                  <Row k="Treatment / Management" v={pickVal(e.treatmentManagement, victim.treatment_management)} always />
                </dl>
              </div>
              <div>
                <p className="eyebrow mb-2">Vital Sign</p>
                <dl className="divide-y">
                  <Row k="Blood Pressure" v={pickVal(e.vitalSigns?.bloodPressure, victim.blood_pressure)} always />
                  <Row k="Pulse Rate (BPM)" v={pickVal(e.vitalSigns?.pulseRate, victim.pulse_rate)} always />
                  <Row k="Respiration (BPM)" v={pickVal(e.vitalSigns?.respiration, victim.respiration)} always />
                </dl>
              </div>
            </div>
          </CardContent>
        </Card>
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
      <ConfirmDialog
        open={confirmArchive}
        onOpenChange={setConfirmArchive}
        title="Move report to Archives"
        description={`Report ${r.reference} will be moved to Archives. You can restore it or schedule 30-day permanent deletion from Archives.`}
        confirmLabel="Archive"
        onConfirm={() => {
          router.patch(route('admin.reports.archive', r.id));
          setConfirmArchive(false);
        }}
      />
    </AppLayout>
  );
}
