import { Head, router } from '@inertiajs/react';
import { Map as MapIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/PageHeader';
import { StatCard } from '@/Components/StatCard';
import { StatusBadge } from '@/Components/StatusBadge';
import { FleetMap, type FleetVehicle, type MapReport } from '@/Components/maps/FleetMap';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { cn } from '@/lib/utils';
import { Ambulance, Navigation, ShieldCheck, WifiOff } from 'lucide-react';

const ago = (iso: string | null) => { if (!iso) return 'no signal'; const s = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000)); return s < 60 ? `${s}s ago` : `${Math.round(s / 60)}m ago`; };

export default function MapPage({ vehicles, reports }: { vehicles: FleetVehicle[]; reports: MapReport[] }) {
  const [sel, setSel] = useState<number | null>(null);
  const [, tick] = useState(0);

  useEffect(() => {
    // Real GPS pings are saved by drivers (POST /driver/ping); here we just re-fetch the `vehicles` prop.
    // With Laravel Reverb + Echo you can replace this with: Echo.private('dispatch').listen('.vehicle.moved', () => router.reload({ only: ['vehicles'] }))
    const poll = setInterval(() => router.reload({ only: ['vehicles'] }), 3000);
    const clock = setInterval(() => tick((n) => n + 1), 1000);
    return () => { clearInterval(poll); clearInterval(clock); };
  }, []);

  const count = (s: string) => vehicles.filter((v) => v.status === s).length;
  return (
    <AppLayout>
      <Head title="Live Map" />
      <PageHeader icon={MapIcon} title="Live Map" description="Vehicle positions refresh every 3 seconds" />
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="En route" value={count('en-route')} icon={Navigation} tone="bg-amber-100 text-amber-600" />
        <StatCard label="On scene" value={count('on-scene')} icon={Ambulance} tone="bg-red-100 text-red-600" />
        <StatCard label="Available" value={count('available')} icon={ShieldCheck} tone="bg-emerald-100 text-emerald-600" />
        <StatCard label="Offline" value={count('offline')} icon={WifiOff} tone="bg-slate-100 text-slate-600" />
      </div>
      <div className="grid gap-4 lg:grid-cols-4">
        <div className="lg:col-span-3"><FleetMap vehicles={vehicles} reports={reports} selectedId={sel} onSelect={setSel} /></div>
        <Card><CardHeader className="pb-2"><CardTitle className="text-base">Vehicles ({vehicles.length})</CardTitle></CardHeader>
          <CardContent className="p-0"><ScrollArea className="h-[500px]"><ul className="space-y-2 p-4 pt-0">
            {vehicles.map((v) => (
              <li key={v.id}><button type="button" onClick={() => setSel(v.id)} aria-pressed={sel === v.id}
                className={cn('w-full rounded-lg border p-3 text-left text-sm transition hover:border-primary/50', sel === v.id && 'border-primary bg-primary/5')}>
                <div className="flex items-center justify-between"><span className="font-medium">{v.teamName ?? v.plate}</span><StatusBadge value={v.status} /></div>
                <p className="mt-1 text-xs text-muted-foreground">{v.plate} · {v.speed} km/h · {ago(v.updatedAt)}</p>
                {v.report && <p className="mt-1 truncate text-xs">→ {v.report.reference}: {v.report.location}</p>}
              </button></li>
            ))}
          </ul></ScrollArea></CardContent></Card>
      </div>
    </AppLayout>
  );
}
