import { Head, router } from '@inertiajs/react';
import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { Map as MapIcon } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { mergeDriverMove, readDriverMove, type DriverMove } from '@/lib/live-drivers';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { StatCard } from '@/components/StatCard';
import { StatusBadge } from '@/components/StatusBadge';
import { DriverMap, type MapDriver, type MapReport } from '@/components/maps/DriverMap';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ScrollArea } from '@/components/ui/scroll-area';
import { cn } from '@/lib/utils';
import { Ambulance, Navigation, ShieldCheck, WifiOff } from 'lucide-react';

const ago = (iso: string | null) => { if (!iso) return 'no signal'; const s = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000)); return s < 60 ? `${s}s ago` : `${Math.round(s / 60)}m ago`; };

function DispatchMapRealtime({ onDriver, onStatus, refresh }: {
  onDriver: (event: unknown) => void; onStatus: (live: boolean) => void; refresh: () => void;
}) {
  const status = useConnectionStatus();
  const { channel } = useEcho<DriverMove>('dispatch', '.driver.moved', onDriver, [onDriver]);
  useEcho('dispatch', '.report.updated', refresh, [refresh]);
  useEffect(() => {
    channel()?.subscribed(() => { onStatus(true); refresh(); });
    channel()?.error(() => onStatus(false));
  }, [channel, onStatus, refresh]);
  useEffect(() => { if (status !== 'connected') onStatus(false); }, [status, onStatus]);
  return null;
}

export default function MapPage({ drivers: snapshot, reports, snapshotAt }: { drivers: MapDriver[]; reports: MapReport[]; snapshotAt?: string }) {
  const [drivers, setDrivers] = useState(snapshot);
  const [live, setLive] = useState(false);
  const [sel, setSel] = useState<number | null>(null);
  const [, tick] = useState(0);
  const moves = useRef(new Map<number, DriverMove>());
  const knownDrivers = useRef(new Set(snapshot.map((driver) => driver.id)));
  const refreshTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const refresh = useCallback(() => {
    if (refreshTimer.current) return;
    refreshTimer.current = setTimeout(() => {
      refreshTimer.current = null;
      router.reload({ only: ['drivers', 'reports', 'snapshotAt'] });
    }, 250);
  }, []);
  const onDriver = useCallback((input: unknown) => {
    const move = readDriverMove(input);
    if (!move) return;
    const previous = moves.current.get(move.id);
    if (previous && Date.parse(previous.sentAt) > Date.parse(move.sentAt)) return;
    moves.current.set(move.id, move);
    if (!knownDrivers.current.has(move.id)) { knownDrivers.current.add(move.id); refresh(); }
    setDrivers((current) => mergeDriverMove(current, move));
  }, [refresh]);
  useEffect(() => {
    let next = snapshot;
    for (const [id, move] of moves.current) {
      if (snapshotAt && Date.parse(move.sentAt) <= Date.parse(snapshotAt)) moves.current.delete(id);
      else next = mergeDriverMove(next, move);
    }
    knownDrivers.current = new Set(next.map((driver) => driver.id));
    setDrivers(next);
  }, [snapshot, snapshotAt]);
  useEffect(() => {
    const clock = setInterval(() => tick((n) => n + 1), 1000);
    return () => { clearInterval(clock); if (refreshTimer.current) clearTimeout(refreshTimer.current); };
  }, []);
  useEffect(() => {
    if (live) return;
    const timer = setInterval(refresh, 15000);
    return () => clearInterval(timer);
  }, [live, refresh]);
  const visibleDrivers = drivers.map((driver) => ({ ...driver, status: Date.parse(driver.expiresAt) <= Date.now() ? 'offline' : driver.status }));
  const count = (s: string) => visibleDrivers.filter((driver) => driver.status === s).length;
  return (
    <AppLayout>
      <Head title="Live Map" />
      {import.meta.env.VITE_REVERB_APP_KEY && <DispatchMapRealtime onDriver={onDriver} onStatus={setLive} refresh={refresh} />}
      <PageHeader icon={MapIcon} title="Live response map" description={live ? 'Locations are updating live. Select a responder to see their assignment.' : 'Locations refresh every 15 seconds. Select a responder to see their assignment.'} />
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Dispatched / En route" value={count('dispatched') + count('en-route')} icon={Navigation} tone="bg-amber-100 text-amber-600" />
        <StatCard label="On scene" value={count('on-scene')} icon={Ambulance} tone="bg-red-100 text-red-600" />
        <StatCard label="Available" value={count('available')} icon={ShieldCheck} tone="bg-emerald-100 text-emerald-600" />
        <StatCard label="Offline" value={count('offline')} icon={WifiOff} tone="bg-slate-100 text-slate-600" />
      </div>
      <div className="grid gap-4 lg:grid-cols-4">
        <div className="lg:col-span-3"><DriverMap drivers={visibleDrivers} reports={reports} selectedId={sel} onSelect={setSel} /></div>
        <Card><CardHeader className="pb-2"><CardTitle className="text-base">Drivers / Responders ({drivers.length})</CardTitle></CardHeader>
          <CardContent className="p-0"><ScrollArea className="h-[500px]"><ul className="space-y-2 p-4 pt-0">
            {visibleDrivers.map((v) => (
              <li key={v.id}><button type="button" onClick={() => setSel(v.id)} aria-pressed={sel === v.id}
                className={cn('w-full rounded-lg border p-3 text-left text-sm transition hover:border-primary/50', sel === v.id && 'border-primary bg-primary/5')}>
                <div className="flex items-center justify-between"><span className="font-medium">{v.name}</span><StatusBadge value={v.status} /></div>
                <p className="mt-1 text-xs text-muted-foreground">{v.teamName ?? 'Driver / Responder'} · {v.speed} km/h · {ago(v.updatedAt)}</p>
                {v.report && <p className="mt-1 truncate text-xs">→ {v.report.reference}: {v.report.location}</p>}
              </button></li>
            ))}
            {drivers.length === 0 && <li className="text-sm text-muted-foreground">Waiting for driver/responder coordinates. Enable location and open the mobile app.</li>}
          </ul></ScrollArea></CardContent></Card>
      </div>
    </AppLayout>
  );
}
