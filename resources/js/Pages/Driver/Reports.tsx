import { Head, useForm } from '@inertiajs/react';
import { FileText, Pencil } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/PageHeader';
import { EmptyState } from '@/Components/EmptyState';
import { StatusBadge } from '@/Components/StatusBadge';
import { ServerPagination } from '@/Components/ServerPagination';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import type { Paginated } from '@/types';

const CATEGORIES = ['Vehicular Accident', 'Medical', 'Pedia', 'Ob/Gyne', 'Others'];
const blank = {
  driver_section: { driverName: '', governmentCardPlateNo: '', authorizedPassenger: '', placeVisitedInspected: '', purpose: '', passengerName: '' },
  emergency_section: { timeReported: '', timeOfArrival: '', patientName: '', age: '', sex: 'Male', address: '', typeOfIncident: 'Medical', allergies: '', medications: '', assessmentComment: '', treatmentManagement: '', vitalSigns: { bloodPressure: '', pulseRate: '', respiration: '' } },
};

export default function Reports({ reports }: { reports: Paginated<any> }) {
  const [rep, setRep] = useState<any | null>(null);
  const form = useForm(blank);
  const open = (r: any) => { setRep(r); form.setData(r.form ? { driver_section: { ...blank.driver_section, ...r.form.driver_section }, emergency_section: { ...blank.emergency_section, ...r.form.emergency_section, vitalSigns: { ...blank.emergency_section.vitalSigns, ...r.form.emergency_section?.vitalSigns } } } : blank); };
  const d = (k: keyof typeof blank.driver_section) => ({ 'aria-label': k, placeholder: k.replace(/([A-Z])/g, ' $1').toLowerCase(), value: form.data.driver_section[k], onChange: (e: React.ChangeEvent<HTMLInputElement>) => form.setData('driver_section', { ...form.data.driver_section, [k]: e.target.value }) });
  const e = (k: Exclude<keyof typeof blank.emergency_section, 'vitalSigns' | 'sex' | 'typeOfIncident'>) => ({ 'aria-label': k, placeholder: k.replace(/([A-Z])/g, ' $1').toLowerCase(), value: form.data.emergency_section[k], onChange: (ev: React.ChangeEvent<HTMLInputElement>) => form.setData('emergency_section', { ...form.data.emergency_section, [k]: ev.target.value }) });
  const vit = (k: keyof typeof blank.emergency_section.vitalSigns) => ({ 'aria-label': k, placeholder: k.replace(/([A-Z])/g, ' $1').toLowerCase(), value: form.data.emergency_section.vitalSigns[k], onChange: (ev: React.ChangeEvent<HTMLInputElement>) => form.setData('emergency_section', { ...form.data.emergency_section, vitalSigns: { ...form.data.emergency_section.vitalSigns, [k]: ev.target.value } }) });

  return (
    <AppLayout>
      <Head title="Patient Care Reports" />
      <PageHeader icon={FileText} title="Patient Care Reports" description="Complete the trip and patient forms for your calls" />
      {reports.data.length === 0 ? <Card><EmptyState icon={FileText} title="Nothing to report yet" /></Card> : (
        <div className="space-y-3">{reports.data.map((r) => (
          <Card key={r.id}><CardContent className="flex flex-wrap items-center justify-between gap-3 p-4">
            <div><p className="flex flex-wrap items-center gap-2"><span className="font-mono text-sm text-muted-foreground">{r.reference}</span><span className="font-medium">{r.incident_type.name}</span><StatusBadge value={r.status} />
              {r.form ? <Badge className="border-0 bg-emerald-100 text-emerald-800">Form saved</Badge> : <Badge className="border-0 bg-amber-100 text-amber-800">Form pending</Badge>}</p>
              <p className="mt-1 text-sm text-muted-foreground">{r.location}</p></div>
            <Button variant="outline" onClick={() => open(r)}><Pencil className="mr-1 size-4" />{r.form ? 'Edit form' : 'Fill form'}</Button></CardContent></Card>))}</div>)}
      <ServerPagination page={reports} noun="report" />

      <Dialog open={!!rep} onOpenChange={(o) => !o && setRep(null)}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl"><DialogHeader><DialogTitle>Patient care report · {rep?.reference}</DialogTitle><DialogDescription>Saved forms are visible to admins.</DialogDescription></DialogHeader>
          <div className="space-y-5">
            <section className="space-y-2"><h4 className="text-sm font-semibold">Trip details</h4><div className="grid gap-2 sm:grid-cols-2">
              <Input {...d('driverName')} /><Input {...d('governmentCardPlateNo')} /><Input {...d('authorizedPassenger')} /><Input {...d('placeVisitedInspected')} /><Input {...d('purpose')} /><Input {...d('passengerName')} /></div></section>
            <section className="space-y-2"><h4 className="text-sm font-semibold">Patient</h4><div className="grid gap-2 sm:grid-cols-2">
              <Input {...e('patientName')} /><Input {...e('age')} /><Input {...e('address')} /><Input {...e('timeReported')} type="time" /><Input {...e('timeOfArrival')} type="time" />
              <Select value={form.data.emergency_section.sex} onValueChange={(v) => form.setData('emergency_section', { ...form.data.emergency_section, sex: v })}><SelectTrigger aria-label="Sex"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="Male">Male</SelectItem><SelectItem value="Female">Female</SelectItem></SelectContent></Select>
              <Select value={form.data.emergency_section.typeOfIncident} onValueChange={(v) => form.setData('emergency_section', { ...form.data.emergency_section, typeOfIncident: v })}><SelectTrigger aria-label="Incident category"><SelectValue /></SelectTrigger><SelectContent>{CATEGORIES.map((c) => <SelectItem key={c} value={c}>{c}</SelectItem>)}</SelectContent></Select>
              <Input {...e('allergies')} /><Input {...e('medications')} /></div>
              <div className="grid gap-2 sm:grid-cols-3"><Input {...vit('bloodPressure')} /><Input {...vit('pulseRate')} /><Input {...vit('respiration')} /></div>
              <div className="space-y-1.5"><Label htmlFor="ac">Assessment</Label><Textarea id="ac" rows={2} value={form.data.emergency_section.assessmentComment} onChange={(ev) => form.setData('emergency_section', { ...form.data.emergency_section, assessmentComment: ev.target.value })} /></div>
              <div className="space-y-1.5"><Label htmlFor="tm">Treatment / management</Label><Textarea id="tm" rows={2} value={form.data.emergency_section.treatmentManagement} onChange={(ev) => form.setData('emergency_section', { ...form.data.emergency_section, treatmentManagement: ev.target.value })} /></div></section>
          </div>
          <DialogFooter><Button variant="outline" onClick={() => setRep(null)}>Close</Button><Button disabled={form.processing} onClick={() => form.put(route('driver.reports.form', rep.id), { preserveScroll: true, onSuccess: () => setRep(null) })}>Save report</Button></DialogFooter>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
