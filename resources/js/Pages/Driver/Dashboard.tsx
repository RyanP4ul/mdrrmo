import { Head, Link, router } from '@inertiajs/react';
import { Ambulance, CheckCircle, ClipboardList, MapPin } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/PageHeader';
import { StatCard } from '@/Components/StatCard';
import { StatusBadge } from '@/Components/StatusBadge';
import { EmptyState } from '@/Components/EmptyState';
import { LocationMap } from '@/Components/maps/LocationMap';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';

export const NEXT: Record<string, { to: string; label: string } | undefined> = {
  dispatched: { to: 'en-route', label: 'Start en route' }, 'en-route': { to: 'on-scene', label: 'Arrived on scene' },
  'on-scene': { to: 'returning', label: 'Returning' }, returning: { to: 'available', label: 'Complete & available' },
};

export default function Dashboard({ active, doneToday, vehicle }: { active: any[]; doneToday: number; vehicle: any | null }) {
  const advance = (id: number, to: string) => router.patch(route('driver.assignments.status', id), { vehicle_status: to }, { preserveScroll: true });
  const step = NEXT[vehicle?.status === 'available' ? 'dispatched' : vehicle?.status ?? 'dispatched'];
  return (
    <AppLayout>
      <Head title="Driver Dashboard" />
      <PageHeader icon={Ambulance} title="Driver Dashboard" description={vehicle ? `${vehicle.plate_number} · ${vehicle.status.replace('-', ' ')}` : 'No vehicle linked to your team'} />
      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard label="Active assignments" value={active.length} icon={ClipboardList} tone="bg-amber-100 text-amber-600" />
        <StatCard label="Completed today" value={doneToday} icon={CheckCircle} tone="bg-emerald-100 text-emerald-600" />
        <StatCard label="Vehicle status" value={vehicle?.status?.replace('-', ' ') ?? '—'} icon={Ambulance} tone="bg-sky-100 text-sky-600" />
      </div>
      {active.length === 0 ? <Card><EmptyState icon={ClipboardList} title="No active assignments" hint="You'll see a card here as soon as dispatch assigns you." /></Card> : active.map((r) => (
        <Card key={r.id} className="border-primary/30"><CardHeader className="flex-row flex-wrap items-start justify-between gap-2 space-y-0"><div>
          <CardTitle className="text-lg">{r.incident_type.name} · {r.reference}</CardTitle><p className="mt-1 flex items-center gap-1.5 text-sm text-muted-foreground"><MapPin className="size-3.5" />{r.location}</p></div>
          <div className="flex gap-2"><StatusBadge value={r.priority} /><StatusBadge value={r.status} /></div></CardHeader>
          <CardContent className="space-y-3"><LocationMap lat={r.lat} lng={r.lng} label={r.reference} className="h-56" />
            <p className="text-sm text-muted-foreground">{r.description}</p>
            <div className="flex flex-wrap gap-2">{step && <Button onClick={() => advance(r.id, step.to)}>{step.label}</Button>}
              <Button asChild variant="outline"><Link href={route('driver.reports')}>Fill patient report</Link></Button></div></CardContent></Card>))}
    </AppLayout>
  );
}
