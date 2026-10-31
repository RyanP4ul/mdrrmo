import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, FileText } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge } from '@/Components/StatusBadge';
import { LocationMap } from '@/Components/maps/LocationMap';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { formatDateTime } from '@/lib/utils';

const Row = ({ k, v }: { k: string; v?: string | null }) => v ? <div className="flex justify-between gap-4 text-sm"><dt className="text-muted-foreground">{k}</dt><dd className="text-right font-medium">{v}</dd></div> : null;

export default function Show({ report: r }: { report: any }) {
  const upd = (patch: Record<string, string>) => router.patch(route('admin.reports.update', r.id), { status: r.status, priority: r.priority, ...patch }, { preserveScroll: true });
  const f = r.form;
  return (
    <AppLayout>
      <Head title={r.reference} />
      <Button asChild variant="ghost" className="w-fit gap-2"><Link href={route('admin.reports.index')}><ArrowLeft className="size-4" />Back to reports</Link></Button>
      <PageHeader icon={FileText} title={`Report ${r.reference}`} description={`${r.incident_type.name} · ${formatDateTime(r.reported_at)}`} actions={
        <div className="flex gap-2">
          <Select value={r.priority} onValueChange={(v) => upd({ priority: v })}><SelectTrigger className="w-32" aria-label="Priority"><SelectValue /></SelectTrigger>
            <SelectContent>{['low', 'medium', 'high', 'critical'].map((p) => <SelectItem key={p} value={p} className="capitalize">{p}</SelectItem>)}</SelectContent></Select>
          <Select value={r.status} onValueChange={(v) => upd({ status: v })}><SelectTrigger className="w-36" aria-label="Status"><SelectValue /></SelectTrigger>
            <SelectContent>{['pending', 'acknowledged', 'dispatched', 'resolved', 'invalid'].map((p) => <SelectItem key={p} value={p} className="capitalize">{p}</SelectItem>)}</SelectContent></Select>
        </div>} />
      <div className="grid gap-4 lg:grid-cols-2">
        <Card><CardHeader><CardTitle className="text-base">Incident</CardTitle></CardHeader><CardContent className="space-y-3">
          <div className="flex gap-2"><StatusBadge value={r.status} /><StatusBadge value={r.priority} /></div>
          <dl className="space-y-1.5"><Row k="Location" v={r.location} /><Row k="Source" v={r.source === 'mobile-app' ? 'Mobile app' : 'Emergency call'} /><Row k="Reporter" v={`${r.reporter_name}${r.reporter_contact ? ' · ' + r.reporter_contact : ''}`} />
            <Row k="Team" v={r.team?.team_name} /><Row k="Driver" v={r.driver ? `${r.driver.first_name} ${r.driver.last_name}` : null} /></dl>
          <p className="rounded-lg bg-muted/50 p-3 text-sm">{r.description || 'No description provided.'}</p>
        </CardContent></Card>
        <Card><CardHeader><CardTitle className="text-base">Location</CardTitle></CardHeader><CardContent><LocationMap lat={r.lat} lng={r.lng} label={r.reference} /></CardContent></Card>
        {f && <Card className="lg:col-span-2"><CardHeader><CardTitle className="text-base">Patient care report</CardTitle></CardHeader><CardContent className="grid gap-6 sm:grid-cols-2">
          <dl className="space-y-1.5"><Row k="Driver" v={f.driver_section.driverName} /><Row k="Plate no." v={f.driver_section.governmentCardPlateNo} /><Row k="Purpose" v={f.driver_section.purpose} /><Row k="Place visited" v={f.driver_section.placeVisitedInspected} /></dl>
          <dl className="space-y-1.5"><Row k="Patient" v={f.emergency_section.patientName} /><Row k="Age / sex" v={`${f.emergency_section.age ?? ''} ${f.emergency_section.sex ?? ''}`} /><Row k="Incident" v={f.emergency_section.typeOfIncident} /><Row k="Assessment" v={f.emergency_section.assessmentComment} /><Row k="Treatment" v={f.emergency_section.treatmentManagement} /></dl>
        </CardContent></Card>}
      </div>
    </AppLayout>
  );
}
