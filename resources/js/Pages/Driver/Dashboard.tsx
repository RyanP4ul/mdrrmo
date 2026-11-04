import { Head, Link, router } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { Ambulance, CheckCircle, ClipboardList, MapPin } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { StatCard } from '@/components/StatCard';
import { StatusBadge } from '@/components/StatusBadge';
import { EmptyState } from '@/components/EmptyState';
import { LocationMap } from '@/components/maps/LocationMap';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { EmergencyReport } from '@/types/website';

export const NEXT: Record<string, { to: string; label: string } | undefined> = {
  dispatched: { to: 'en-route', label: 'Start en route' }, 'en-route': { to: 'on-scene', label: 'Arrived on scene' },
  'on-scene': { to: 'returning', label: 'Returning' }, returning: { to: 'available', label: 'Complete & available' },
};

export default function Dashboard({ active, doneToday }: { active: (EmergencyReport & { trip?: { started_at?: string; on_scene_at?: string; returning_at?: string } | null })[]; doneToday: number }) {
  const advance = (id: number, to: string) => router.patch(route('driver.assignments.status', id), { response_status: to }, { preserveScroll: true });
  return (
    <AppLayout>
      <Head title="Driver Dashboard" />
      <PageHeader icon={Ambulance} title="Driver / Responder Dashboard" description="Your assignments and response progress" />
      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard label="Active assignments" value={active.length} icon={ClipboardList} tone="bg-amber-100 text-amber-600" />
        <StatCard label="Completed today" value={doneToday} icon={CheckCircle} tone="bg-emerald-100 text-emerald-600" />

      </div>
      {active.length === 0 ? <Card><EmptyState icon={ClipboardList} title="No active assignments" hint="You'll see a card here as soon as dispatch assigns you." /></Card> : active.map((r) => { const step = r.status === 'dispatched' ? NEXT[r.trip?.returning_at ? 'returning' : r.trip?.on_scene_at ? 'on-scene' : r.trip?.started_at ? 'en-route' : 'dispatched'] : undefined; return (
        <Card key={r.id} className="border-primary/30"><CardHeader className="flex-row flex-wrap items-start justify-between gap-2 space-y-0"><div>
          <CardTitle className="text-lg">{r.incident_type.name} · {r.reference}</CardTitle><p className="mt-1 flex items-center gap-1.5 text-sm text-muted-foreground"><MapPin className="size-3.5" />{r.location}</p></div>
          <div className="flex gap-2"><StatusBadge value={r.priority} /><StatusBadge value={r.status} /></div></CardHeader>
          <CardContent className="space-y-3"><LocationMap lat={r.lat} lng={r.lng} label={r.reference} className="h-56" />
            <p className="text-sm text-muted-foreground">{r.description}</p>
            <div className="flex flex-wrap gap-2">{step && <Button onClick={() => advance(r.id, step.to)}>{step.label}</Button>}
              <Button asChild variant="outline"><Link href={route('driver.reports')}>Fill patient report</Link></Button></div></CardContent></Card>); })}
    </AppLayout>
  );
}
