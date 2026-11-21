import { Head, router } from '@inertiajs/react';
import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { Users, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { mergeDriverMove, readDriverMove, type DriverMove } from '@/lib/live-drivers';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { StatCard, StatGroup } from '@/components/StatCard';
import { StatusBadge } from '@/components/StatusBadge';
import { Button } from '@/components/ui/button';
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

interface TeamGroup {
  key: string;
  title: string;
  assigned: boolean;
  members: MapDriver[];
}

function groupDriversByTeam(drivers: MapDriver[]): TeamGroup[] {
  const groups = new Map<string, TeamGroup>();
  for (const driver of drivers) {
    const teamLabel = driver.teamName?.trim() || '';
    const key = driver.teamId != null ? `team-${driver.teamId}` : teamLabel ? `name-${teamLabel.toLowerCase()}` : 'unassigned';
    const existing = groups.get(key);
    if (existing) {
      existing.members.push(driver);
    } else {
      groups.set(key, {
        key,
        title: teamLabel || 'Unassigned',
        assigned: Boolean(teamLabel),
        members: [driver],
      });
    }
  }
  const roleRank = (role?: string | null) => {
    const lower = (role ?? '').toLowerCase();
    if (lower === 'driver') return 0;
    if (lower === 'responder') return 1;
    return 2;
  };
  return Array.from(groups.values())
    .map((group) => ({
      ...group,
      members: [...group.members].sort((a, b) => roleRank(a.memberRole) - roleRank(b.memberRole) || a.name.localeCompare(b.name)),
    }))
    .sort((a, b) => {
      if (a.assigned !== b.assigned) return a.assigned ? -1 : 1;
      return a.title.localeCompare(b.title);
    });
}

function DriverList({ drivers, selectedId, onSelect, className }: {
  drivers: MapDriver[];
  selectedId: number | null;
  onSelect: (id: number) => void;
  className?: string;
}) {
  if (drivers.length === 0) {
    return (
      <div className={cn('px-4 py-8 text-center text-sm text-muted-foreground', className)}>
        Waiting for driver/responder coordinates. Enable location and open the mobile app.
      </div>
    );
  }
  const teams = groupDriversByTeam(drivers);
  return (
    <div className={cn('divide-y overflow-y-auto', className)}>
      {teams.map((team) => (
        <div key={team.key} className="bg-card">
          <div className="flex items-center justify-between gap-2 border-b bg-muted/40 px-4 py-2">
            <h3 className="truncate text-xs font-semibold uppercase tracking-wide text-foreground">
              {team.title}
            </h3>
            <span className="shrink-0 text-[11px] font-medium text-muted-foreground tabular-nums">
              {team.members.length} {team.members.length === 1 ? 'member' : 'members'}
            </span>
          </div>
          <ul className="divide-y">
            {team.members.map((v) => {
              const roleLabel = v.memberRole?.trim() || 'Driver / Responder';
              const isDriverRole = roleLabel.toLowerCase() === 'driver';
              return (
                <li key={v.id}>
                  <button type="button" onClick={() => onSelect(v.id)} aria-pressed={selectedId === v.id}
                    className={cn('block min-h-11 w-full px-4 py-2.5 text-left text-sm transition-colors hover:bg-muted/50', selectedId === v.id && 'bg-primary/5')}>
                    <span className="flex items-center justify-between gap-2">
                      <span className="flex min-w-0 items-center gap-1.5">
                        <span className="truncate font-medium">{v.name}</span>
                        <span
                          className={cn(
                            'shrink-0 rounded-sm border px-1.5 py-0.5 text-[10px] font-semibold',
                            isDriverRole
                              ? 'border-primary/25 bg-primary/10 text-primary'
                              : 'border-border bg-muted text-muted-foreground',
                          )}
                        >
                          {roleLabel}
                        </span>
                      </span>
                      <StatusBadge value={v.status} />
                    </span>
                    <span className="mt-1 block text-xs text-muted-foreground tabular-nums">{v.speed} km/h · {ago(v.updatedAt)}</span>
                    {v.report && <span className="mt-1 block truncate text-xs">→ {v.report.reference}: {v.report.location}</span>}
                  </button>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </div>
  );
}

export default function MapPage({ drivers: snapshot, reports, snapshotAt, googleMapsApiKey }: {
  drivers: MapDriver[];
  reports: MapReport[];
  snapshotAt?: string;
  googleMapsApiKey?: string | null;
}) {
  const [drivers, setDrivers] = useState(snapshot);
  const [live, setLive] = useState(false);
  const [sel, setSel] = useState<number | null>(null);
  const [isFullMap, setIsFullMap] = useState(false);
  const [popupOpen, setPopupOpen] = useState(true);
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
  useEffect(() => {
    if (!isFullMap) return;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setIsFullMap(false);
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [isFullMap]);
  const visibleDrivers = drivers.map((driver) => ({ ...driver, status: Date.parse(driver.expiresAt) <= Date.now() ? 'offline' : driver.status }));
  const count = (s: string) => visibleDrivers.filter((driver) => driver.status === s).length;
  const toggleFullMap = () => {
    setIsFullMap((prev) => {
      const next = !prev;
      if (next) setPopupOpen(true);
      return next;
    });
  };
  const fullscreenOverlay = (
    <div className="pointer-events-none absolute bottom-4 left-4 z-20 flex flex-col items-start gap-2 sm:bottom-5 sm:left-5">
      {popupOpen ? (
        <section
          aria-label="Drivers and responders popup"
          className="pointer-events-auto flex max-h-[65dvh] w-[min(22rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-lg border bg-card/95 shadow-lg backdrop-blur-sm"
        >
          <div className="flex items-center justify-between border-b px-4 py-2.5">
            <h2 className="text-sm font-semibold">
              Drivers / responders{' '}
              <span className="ml-1 font-normal text-muted-foreground tabular-nums">{drivers.length}</span>
            </h2>
            <Button
              type="button"
              variant="ghost"
              size="icon-sm"
              onClick={() => setPopupOpen(false)}
              aria-label="Close responders popup"
            >
              <X className="size-4" aria-hidden="true" />
            </Button>
          </div>
          <DriverList drivers={visibleDrivers} selectedId={sel} onSelect={setSel} className="max-h-[calc(65dvh-3rem)]" />
        </section>
      ) : (
        <Button
          type="button"
          size="sm"
          variant="secondary"
          onClick={() => setPopupOpen(true)}
          className="pointer-events-auto border bg-card/95 text-foreground shadow-md backdrop-blur-sm hover:bg-card"
        >
          <Users className="size-4" aria-hidden="true" />
          Drivers / responders ({drivers.length})
        </Button>
      )}
    </div>
  );
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
        <DriverMap
          drivers={visibleDrivers}
          reports={reports}
          selectedId={sel}
          onSelect={setSel}
          apiKey={googleMapsApiKey}
          isFullscreen={isFullMap}
          onToggleFullscreen={toggleFullMap}
          fullscreenOverlay={fullscreenOverlay}
        />
        <section aria-labelledby="responders-heading" className="overflow-hidden rounded-lg border bg-card">
          <h2 id="responders-heading" className="border-b px-4 py-3 text-sm font-semibold">Drivers / responders <span className="ml-1 font-normal text-muted-foreground tabular-nums">{drivers.length}</span></h2>
          <DriverList drivers={visibleDrivers} selectedId={sel} onSelect={setSel} className="max-h-[32rem]" />
        </section>
      </div>
    </AppLayout>
  );
}