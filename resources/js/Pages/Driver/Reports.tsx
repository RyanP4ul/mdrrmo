import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { FileText, Pencil, Shield, Truck, Wrench } from 'lucide-react';
import { useState, type ChangeEvent } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { StatusBadge } from '@/components/StatusBadge';
import { ServerPagination } from '@/components/ServerPagination';
import { Field } from '@/components/Field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import type { PageProps, Paginated } from '@/types';
import type { EmergencyReport, TeamRoleMembership } from '@/types/website';

const CATEGORIES = ['Vehicular Accident', 'Medical', 'Pedia', 'Ob/Gyne', 'Others'];
const blankDriver = {
  tripDate: '',
  driverName: '',
  governmentCardPlateNo: '',
  authorizedPassenger: '',
  placeVisitedInspected: '',
  purpose: '',
  balanceInTank: '',
  issuedByOffice: '',
  purchasedDuringTrip: '',
  totalAvailableFuel: '',
  fuelUsedDuringTrip: '',
  remainingFuel: '',
  gearOilUsed: '',
};
const blankEmergency = {
  timeReported: '',
  timeOfArrival: '',
  patientName: '',
  age: '',
  sex: 'Male',
  address: '',
  typeOfIncident: 'Medical',
  allergies: '',
  medications: '',
  assessmentComment: '',
  treatmentManagement: '',
  vitalSigns: { bloodPressure: '', pulseRate: '', respiration: '' },
};

const LABEL: Record<string, string> = {
  tripDate: 'Trip Date',
  driverName: 'Assigned Driver',
  governmentCardPlateNo: 'Government Vehicle',
  authorizedPassenger: 'Authorized Passenger',
  placeVisitedInspected: 'Destination / Place to Visit',
  purpose: 'Purpose of Trip',
  balanceInTank: 'Balance in Tank (L)',
  issuedByOffice: 'Issued by Office (L)',
  purchasedDuringTrip: 'Purchased During Trip (L)',
  totalAvailableFuel: 'Total Available Fuel (L)',
  fuelUsedDuringTrip: 'Fuel Used During Trip (L)',
  remainingFuel: 'Remaining Fuel (L)',
  gearOilUsed: 'Gear Oil Used (L)',
  patientName: 'Patient name',
  age: 'Age',
  address: 'Address',
  timeReported: 'Time reported',
  timeOfArrival: 'Time of arrival',
  allergies: 'Allergies',
  medications: 'Medications',
  bloodPressure: 'Blood pressure',
  pulseRate: 'Pulse rate',
  respiration: 'Respiration',
};

type ReportWithTrip = EmergencyReport & {
  trip?: { destination?: string | null; vehicle_number?: string | null } | null;
};

function computeFuelTotals(next: typeof blankDriver) {
  const num = (v: unknown) => {
    const parsed = parseFloat(String(v ?? ''));
    return Number.isFinite(parsed) ? parsed : 0;
  };
  const hasAnySupply =
    String(next.balanceInTank ?? '').trim() !== '' ||
    String(next.issuedByOffice ?? '').trim() !== '' ||
    String(next.purchasedDuringTrip ?? '').trim() !== '';
  const total = num(next.balanceInTank) + num(next.issuedByOffice) + num(next.purchasedDuringTrip);
  const totalStr = hasAnySupply ? String(Math.round(total * 100) / 100) : '';
  const hasUsed = hasAnySupply || String(next.fuelUsedDuringTrip ?? '').trim() !== '';
  const rem = (hasAnySupply ? total : num(next.totalAvailableFuel)) - num(next.fuelUsedDuringTrip);
  const remStr = hasUsed ? String(Math.round(rem * 100) / 100) : '';
  return {
    ...next,
    totalAvailableFuel: totalStr,
    remainingFuel: remStr,
  };
}

export default function Reports({
  reports,
  isDriver = true,
  teamRoles = [],
}: {
  reports: Paginated<ReportWithTrip>;
  isDriver?: boolean;
  teamRoles?: TeamRoleMembership[];
}) {
  const { auth } = usePage<PageProps>().props;
  const [tab, setTab] = useState<'driver' | 'emergency'>(isDriver ? 'driver' : 'emergency');
  const [driverRep, setDriverRep] = useState<ReportWithTrip | null>(null);
  const [emergencyRep, setEmergencyRep] = useState<ReportWithTrip | null>(null);

  const driverForm = useForm<{ driver_section: typeof blankDriver }>({ driver_section: blankDriver });
  const emergencyForm = useForm<{ emergency_section: typeof blankEmergency }>({ emergency_section: blankEmergency });

  const activeVehicle = teamRoles[0]?.active_vehicle;
  const activePlate = activeVehicle
    ? `${activeVehicle.plate_number}${activeVehicle.name ? ` · ${activeVehicle.name}` : ''}`
    : '';

  const openDriverForm = (r: ReportWithTrip) => {
    driverForm.clearErrors();
    setDriverRep(r);
    const saved = r.form?.driver_section ?? {};
    const defaultDate = (r.reported_at ?? new Date().toISOString()).slice(0, 10);
    const defaultDriver =
      (r.driver ? `${r.driver.first_name} ${r.driver.last_name}`.trim() : '') ||
      teamRoles[0]?.driver_name ||
      auth.user.full_name;
    const victimName = String((r.victim_info as Record<string, unknown> | null)?.name ?? r.reporter_name ?? '').trim();
    const defaultDestination = r.trip?.destination || r.location || '';
    const defaultPurpose = `Emergency Response - ${r.incident_type?.name ?? 'Emergency'}`;

    driverForm.setData({
      driver_section: computeFuelTotals({
        tripDate: String(saved.tripDate || defaultDate),
        driverName: String(saved.driverName || defaultDriver),
        governmentCardPlateNo: String(saved.governmentCardPlateNo || activePlate),
        authorizedPassenger: String(saved.authorizedPassenger || saved.passengerName || victimName),
        placeVisitedInspected: String(saved.placeVisitedInspected || defaultDestination),
        purpose: String(saved.purpose || defaultPurpose),
        balanceInTank: saved.balanceInTank != null ? String(saved.balanceInTank) : '',
        issuedByOffice: saved.issuedByOffice != null ? String(saved.issuedByOffice) : '',
        purchasedDuringTrip: saved.purchasedDuringTrip != null ? String(saved.purchasedDuringTrip) : '',
        totalAvailableFuel: saved.totalAvailableFuel != null ? String(saved.totalAvailableFuel) : '',
        fuelUsedDuringTrip: saved.fuelUsedDuringTrip != null ? String(saved.fuelUsedDuringTrip) : '',
        remainingFuel: saved.remainingFuel != null ? String(saved.remainingFuel) : '',
        gearOilUsed: saved.gearOilUsed != null ? String(saved.gearOilUsed) : '',
      }),
    });
  };

  const openEmergencyForm = (r: ReportWithTrip) => {
    emergencyForm.clearErrors();
    setEmergencyRep(r);
    const saved = r.form?.emergency_section;
    const victimName = String((r.victim_info as Record<string, unknown> | null)?.name ?? '').trim();
    emergencyForm.setData({
      emergency_section: saved
        ? {
            ...blankEmergency,
            ...saved,
            vitalSigns: { ...blankEmergency.vitalSigns, ...saved.vitalSigns },
          }
        : {
            ...blankEmergency,
            patientName: victimName,
            address: r.location ?? '',
          },
    });
  };

  const d = (k: keyof typeof blankDriver) => ({
    id: `df-${k}`,
    value: driverForm.data.driver_section[k],
    disabled: !isDriver,
    error: (driverForm.errors as Record<string, string | undefined>)[`driver_section.${k}`],
    onChange: (ev: ChangeEvent<HTMLInputElement>) => {
      const updated = { ...driverForm.data.driver_section, [k]: ev.target.value };
      driverForm.setData('driver_section', computeFuelTotals(updated));
    },
  });

  const e = (k: Exclude<keyof typeof blankEmergency, 'vitalSigns' | 'sex' | 'typeOfIncident'>) => ({
    id: `ef-${k}`,
    value: emergencyForm.data.emergency_section[k],
    error: (emergencyForm.errors as Record<string, string | undefined>)[`emergency_section.${k}`],
    onChange: (ev: ChangeEvent<HTMLInputElement>) =>
      emergencyForm.setData('emergency_section', { ...emergencyForm.data.emergency_section, [k]: ev.target.value }),
  });

  const vit = (k: keyof typeof blankEmergency.vitalSigns) => ({
    id: `ef-${k}`,
    value: emergencyForm.data.emergency_section.vitalSigns[k],
    error: (emergencyForm.errors as Record<string, string | undefined>)[`emergency_section.vitalSigns.${k}`],
    onChange: (ev: ChangeEvent<HTMLInputElement>) =>
      emergencyForm.setData('emergency_section', {
        ...emergencyForm.data.emergency_section,
        vitalSigns: { ...emergencyForm.data.emergency_section.vitalSigns, [k]: ev.target.value },
      }),
  });

  const f = (
    k: string,
    { error, ...props }: { id: string; disabled?: boolean; value?: string; error?: string; onChange?: (e: ChangeEvent<HTMLInputElement>) => void },
    extra?: Record<string, unknown>,
  ) => (
    <Field label={LABEL[k]} htmlFor={props.id} error={error}>
      <Input {...props} {...extra} />
    </Field>
  );

  const errorBanner = (errors: Record<string, string | undefined>) => {
    const messages = Object.values(errors).filter((msg): msg is string => Boolean(msg));
    if (messages.length === 0) return null;
    return (
      <div role="alert" className="rounded-lg border border-critical/40 bg-critical/10 px-3 py-2 text-xs text-critical">
        <p className="font-semibold">Unable to save form:</p>
        <ul className="mt-1 list-disc space-y-0.5 pl-4">
          {messages.map((msg, idx) => (
            <li key={idx}>{msg}</li>
          ))}
        </ul>
      </div>
    );
  };

  return (
    <AppLayout>
      <Head title="Driver Forms & Emergency Reports" />
      <PageHeader
        title={tab === 'driver' ? 'Driver Forms' : 'Emergency / Patient Care Reports'}
        description={
          tab === 'driver'
            ? 'Complete the Driver Authorization and Fuel Form for your dispatches.'
            : 'Complete the clinical Emergency / Patient Care assessment separately from the Driver Form.'
        }
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <div className="inline-flex rounded-lg border bg-muted p-1">
              <Button
                type="button"
                size="sm"
                variant={tab === 'driver' ? 'default' : 'ghost'}
                onClick={() => setTab('driver')}
              >
                <Truck className="mr-1.5 size-3.5" aria-hidden="true" />
                Driver Forms
              </Button>
              <Button
                type="button"
                size="sm"
                variant={tab === 'emergency' ? 'default' : 'ghost'}
                onClick={() => setTab('emergency')}
              >
                <FileText className="mr-1.5 size-3.5" aria-hidden="true" />
                Emergency Reports
              </Button>
            </div>
            <span className="inline-flex items-center gap-1.5 rounded-md border bg-card px-2.5 py-1 text-xs font-medium">
              <Shield className="size-3.5 text-primary" aria-hidden="true" />
              Role: <strong className="font-semibold">{isDriver ? 'Driver' : 'Responder'}</strong>
            </span>
            {isDriver && (
              <Button asChild size="sm" variant="outline">
                <Link href={`${route('driver.team')}#maintenance-form`}>
                  <Wrench className="mr-1.5 size-3.5" aria-hidden="true" />
                  Maintenance Report Form
                </Link>
              </Button>
            )}
          </div>
        }
      />
      {reports.data.length === 0 ? (
        <div className="rounded-lg border bg-card">
          <EmptyState icon={FileText} title="Nothing to report yet" hint="Calls assigned to your team will appear here." />
        </div>
      ) : (
        <ul className="divide-y rounded-lg border bg-card">
          {reports.data.map((r) => {
            const hasDriverForm =
              r.form?.driver_section &&
              Object.values(r.form.driver_section).some((v) => v !== null && v !== '');
            const hasEmergencyForm =
              r.form?.emergency_section &&
              Object.values(r.form.emergency_section).some(
                (v) => v !== null && v !== '' && (typeof v !== 'object' || Object.values(v ?? {}).some(Boolean)),
              );
            return (
              <li key={r.id} className="flex flex-wrap items-center justify-between gap-3 p-4">
                <div className="min-w-0">
                  <p className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="font-mono text-xs text-muted-foreground">{r.reference}</span>
                    <span className="font-medium">{r.incident_type.name}</span>
                    <StatusBadge value={r.status} />
                    {tab === 'driver' ? (
                      <span
                        className={cn(
                          'inline-flex h-5 items-center rounded-sm px-1.5 text-xs font-medium',
                          hasDriverForm ? 'bg-ok-soft text-ok' : 'bg-warning-soft text-warning',
                        )}
                      >
                        {hasDriverForm ? 'Driver Form saved' : 'Driver Form pending'}
                      </span>
                    ) : (
                      <span
                        className={cn(
                          'inline-flex h-5 items-center rounded-sm px-1.5 text-xs font-medium',
                          hasEmergencyForm ? 'bg-ok-soft text-ok' : 'bg-warning-soft text-warning',
                        )}
                      >
                        {hasEmergencyForm ? 'Emergency Report saved' : 'Emergency Report pending'}
                      </span>
                    )}
                  </p>
                  <p className="mt-1 text-sm text-muted-foreground">{r.location}</p>
                </div>
                <div className="flex flex-wrap gap-2">
                  {tab === 'driver' ? (
                    <Button variant={hasDriverForm ? 'outline' : 'default'} onClick={() => openDriverForm(r)}>
                      <Pencil aria-hidden="true" />
                      {hasDriverForm ? 'Edit Driver Form' : 'Fill Driver Form'}
                    </Button>
                  ) : (
                    <Button variant={hasEmergencyForm ? 'outline' : 'default'} onClick={() => openEmergencyForm(r)}>
                      <Pencil aria-hidden="true" />
                      {hasEmergencyForm ? 'Edit Emergency Report' : 'Fill Emergency Report'}
                    </Button>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}
      <ServerPagination page={reports} noun="report" />

      {/* Dedicated Driver Form Dialog */}
      <Dialog open={!!driverRep} onOpenChange={(o) => !o && setDriverRep(null)}>
        <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Driver Form · {driverRep?.reference}</DialogTitle>
            <DialogDescription>
              Authorization and Fuel log for this dispatch. Some inputs are auto-filled from your active assignment and profile.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-5">
            {errorBanner(driverForm.errors)}
            <section className="space-y-3">
              <div className="flex items-center justify-between">
                <h3 className="eyebrow">Authorization</h3>
                {!isDriver && <span className="text-xs text-muted-foreground">Driver-only section</span>}
              </div>
              <div className="grid gap-3 sm:grid-cols-2">
                {f('tripDate', d('tripDate'), { type: 'date' })}
                {f('driverName', d('driverName'))}
                {f('governmentCardPlateNo', d('governmentCardPlateNo'))}
                {f('authorizedPassenger', d('authorizedPassenger'))}
                {f('placeVisitedInspected', d('placeVisitedInspected'))}
                {f('purpose', d('purpose'))}
              </div>
            </section>
            <section className="space-y-3 border-t pt-4">
              <h3 className="eyebrow">Fuel</h3>
              <div className="grid gap-3 sm:grid-cols-2">
                {f('balanceInTank', d('balanceInTank'), { type: 'number', step: '0.01', min: '0' })}
                {f('issuedByOffice', d('issuedByOffice'), { type: 'number', step: '0.01', min: '0' })}
                {f('purchasedDuringTrip', d('purchasedDuringTrip'), { type: 'number', step: '0.01', min: '0' })}
                {f('totalAvailableFuel', d('totalAvailableFuel'), { type: 'number', step: '0.01', readOnly: true })}
                {f('fuelUsedDuringTrip', d('fuelUsedDuringTrip'), { type: 'number', step: '0.01', min: '0' })}
                {f('remainingFuel', d('remainingFuel'), { type: 'number', step: '0.01', readOnly: true })}
                {f('gearOilUsed', d('gearOilUsed'), { type: 'number', step: '0.01', min: '0' })}
              </div>
            </section>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDriverRep(null)}>
              Close
            </Button>
            <Button
              disabled={driverForm.processing || !driverRep || !isDriver}
              onClick={() =>
                driverRep &&
                driverForm.put(route('driver.reports.form', driverRep.id), {
                  preserveScroll: true,
                  onSuccess: () => setDriverRep(null),
                })
              }
            >
              {driverForm.processing ? 'Saving…' : 'Save Driver Form'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Dedicated Emergency / Patient Care Report Dialog */}
      <Dialog open={!!emergencyRep} onOpenChange={(o) => !o && setEmergencyRep(null)}>
        <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Emergency / Patient Care Report · {emergencyRep?.reference}</DialogTitle>
            <DialogDescription>
              Clinical patient care and emergency assessment section (kept separate from the Driver Form).
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-5">
            {errorBanner(emergencyForm.errors)}
            <section className="space-y-3">
              <h3 className="eyebrow">Patient & Responder Assessment</h3>
              <div className="grid gap-3 sm:grid-cols-2">
                {f('patientName', e('patientName'))}
                {f('age', e('age'), { inputMode: 'numeric' })}
                {f('address', e('address'))}
                {f('timeReported', e('timeReported'), { type: 'time' })}
                {f('timeOfArrival', e('timeOfArrival'), { type: 'time' })}
                <Field label="Sex" htmlFor="ef-sex">
                  <Select
                    value={emergencyForm.data.emergency_section.sex}
                    onValueChange={(v) =>
                      emergencyForm.setData('emergency_section', { ...emergencyForm.data.emergency_section, sex: v })
                    }
                  >
                    <SelectTrigger id="ef-sex" className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="Male">Male</SelectItem>
                      <SelectItem value="Female">Female</SelectItem>
                    </SelectContent>
                  </Select>
                </Field>
                <Field label="Incident category" htmlFor="ef-cat">
                  <Select
                    value={emergencyForm.data.emergency_section.typeOfIncident}
                    onValueChange={(v) =>
                      emergencyForm.setData('emergency_section', {
                        ...emergencyForm.data.emergency_section,
                        typeOfIncident: v,
                      })
                    }
                  >
                    <SelectTrigger id="ef-cat" className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {CATEGORIES.map((c) => (
                        <SelectItem key={c} value={c}>
                          {c}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </Field>
                {f('allergies', e('allergies'))}
                {f('medications', e('medications'))}
              </div>
            </section>
            <section className="space-y-3 border-t pt-4">
              <h3 className="eyebrow">Vital signs</h3>
              <div className="grid gap-3 sm:grid-cols-3">
                {f('bloodPressure', vit('bloodPressure'))}
                {f('pulseRate', vit('pulseRate'))}
                {f('respiration', vit('respiration'))}
              </div>
            </section>
            <section className="space-y-3 border-t pt-4">
              <Field label="Assessment" htmlFor="ac">
                <Textarea
                  id="ac"
                  rows={2}
                  value={emergencyForm.data.emergency_section.assessmentComment}
                  onChange={(ev) =>
                    emergencyForm.setData('emergency_section', {
                      ...emergencyForm.data.emergency_section,
                      assessmentComment: ev.target.value,
                    })
                  }
                />
              </Field>
              <Field label="Treatment / management" htmlFor="tm">
                <Textarea
                  id="tm"
                  rows={2}
                  value={emergencyForm.data.emergency_section.treatmentManagement}
                  onChange={(ev) =>
                    emergencyForm.setData('emergency_section', {
                      ...emergencyForm.data.emergency_section,
                      treatmentManagement: ev.target.value,
                    })
                  }
                />
              </Field>
            </section>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setEmergencyRep(null)}>
              Close
            </Button>
            <Button
              disabled={emergencyForm.processing || !emergencyRep}
              onClick={() =>
                emergencyRep &&
                emergencyForm.put(route('driver.reports.form', emergencyRep.id), {
                  preserveScroll: true,
                  onSuccess: () => setEmergencyRep(null),
                })
              }
            >
              {emergencyForm.processing ? 'Saving…' : 'Save Emergency Report'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
