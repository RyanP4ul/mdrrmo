import { Head, router } from '@inertiajs/react';
import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { mergeDriverMove, readDriverMove, type DriverMove } from '@/lib/live-drivers';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { StatCard, StatGroup } from '@/components/StatCard';
import { StatusBadge } from '@/components/StatusBadge';
import { DriverMap, type MapDriver, type MapReport } from '@/components/maps/DriverMap';
import { cn } from '@/lib/utils';

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
      <PageHeader title="Live response map"
        description={live ? 'Locations are updating live. Select a responder to see their assignment.' : 'Locations refresh every 15 seconds. Select a responder to see their assignment.'}
        actions={<span className={cn('inline-flex h-6 items-center gap-1.5 rounded-sm px-2 text-xs font-medium', live ? 'bg-ok-soft text-ok' : 'bg-muted text-muted-foreground')}><span aria-hidden="true" className="size-1.5 rounded-full bg-current" />{live ? 'Live' : 'Polling every 15s'}</span>} />
      <StatGroup>
        <StatCard label="Dispatched / en route" value={count('dispatched') + count('en-route')} status="brand" />
        <StatCard label="On scene" value={count('on-scene')} status="info" />
        <StatCard label="Available" value={count('available')} status="ok" />
        <StatCard label="Offline" value={count('offline')} />
      </StatGroup>
      <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <DriverMap drivers={visibleDrivers} reports={reports} selectedId={sel} onSelect={setSel} />
        <section aria-labelledby="responders-heading" className="overflow-hidden rounded-lg border bg-card">
          <h2 id="responders-heading" className="border-b px-4 py-3 text-sm font-semibold">Drivers / responders <span className="ml-1 font-normal text-muted-foreground tabular-nums">{drivers.length}</span></h2>
          <ul className="max-h-[32rem] divide-y overflow-y-auto">
            {visibleDrivers.map((v) => (
              <li key={v.id}>
                <button type="button" onClick={() => setSel(v.id)} aria-pressed={sel === v.id}
                  className={cn('block min-h-11 w-full px-4 py-3 text-left text-sm transition-colors hover:bg-muted/50', sel === v.id && 'bg-primary/5')}>
                  <span className="flex items-center justify-between gap-2"><span className="font-medium">{v.name}</span><StatusBadge value={v.status} /></span>
                  <span className="mt-1 block text-xs text-muted-foreground tabular-nums">{v.teamName ?? 'Driver / Responder'} · {v.speed} km/h · {ago(v.updatedAt)}</span>
                  {v.report && <span className="mt-1 block truncate text-xs">→ {v.report.reference}: {v.report.location}</span>}
                </button>
              </li>
            ))}
            {drivers.length === 0 && <li className="px-4 py-8 text-center text-sm text-muted-foreground">Waiting for driver/responder coordinates. Enable location and open the mobile app.</li>}
          </ul>
        </section>
      </div>
    </AppLayout>
  );
}