import { useEcho } from '@laravel/echo-react';
import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import {
  MapPin,
  Mic,
  MicOff,
  Minus,
  Phone,
  PhoneIncoming,
  PhoneOff,
  Volume2,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { AuthUser } from '@/types';
import {
  callRequest,
  incomingSosCalls,
  VoiceSession,
  type CommunicationCall,
  type CallCredentials,
  type VoiceState,
} from '@/lib/voice-calls';

interface DispatcherAvailability {
  account_key: string;
  name: string;
  status: 'Available' | 'Busy' | 'Offline';
}

const Context = createContext<{ start(reportId: number): Promise<void>; busy: boolean } | null>(null);
const message = (error: unknown) =>
  error instanceof Error ? error.message : 'Could not connect. Please try again.';

const PRIORITY_LABELS = ['Critical', 'High', 'Emergency'] as const;

export function StartVoiceCall({ reportId }: { reportId: number }) {
  const calls = useContext(Context);
  return (
    <Button variant="outline" disabled={!calls || calls.busy} onClick={() => void calls?.start(reportId)}>
      <Phone className="mr-2 size-4" />
      Start three-way call
    </Button>
  );
}

function CallRealtime({ refresh }: { refresh: () => void }) {
  useEcho('dispatch', '.call.updated', refresh, [refresh]);
  return null;
}

const callLabel = (call: CommunicationCall) =>
  call.kind === 'sos'
    ? call.resident_name || 'Resident / Guest'
    : call.report_reference || `Report #${call.report_id}`;

export function VoiceCalls({ user, children }: { user: AuthUser; children: ReactNode }) {
  const [refreshVersion, setRefreshVersion] = useState(0);
  const refresh = useCallback(() => setRefreshVersion((version) => version + 1), []);
  const [dispatchers, setDispatchers] = useState<DispatcherAvailability[]>([]);
  const [calls, setCalls] = useState<CommunicationCall[]>([]);
  const [state, setState] = useState<VoiceState>({
    phase: 'idle',
    call: null,
    uid: null,
    peers: [],
    muted: false,
    error: null,
  });
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const panelStorageKey = `mdrrmo.voiceCalls.collapsed.${user.role}.${user.id}`;
  const [collapsed, setCollapsed] = useState(() => {
    try {
      return sessionStorage.getItem(panelStorageKey) === 'true';
    } catch {
      return false;
    }
  });
  const setPanelCollapsed = useCallback(
    (value: boolean) => {
      setCollapsed(value);
      try {
        sessionStorage.setItem(panelStorageKey, String(value));
      } catch {
        /* Keep controls usable when storage is blocked. */
      }
    },
    [panelStorageKey],
  );
  const previousIncoming = useRef(new Set<string>());
  const [dismissed, setDismissed] = useState<string[]>([]);
  const session = useRef<VoiceSession | null>(null);
  if (!session.current) {
    session.current = new VoiceSession(
      setState,
      async (id) => (await callRequest<{ data: CallCredentials }>(`/${id}/token`, 'POST')).data,
    );
  }
  const voice = session.current;
  const enabled = user.role !== 'admin' && user.role !== 'resident';
  const mounted = useRef(true);

  useEffect(() => {
    mounted.current = true;
    let stopped = false;
    let timer: ReturnType<typeof setTimeout>;
    const poll = async () => {
      try {
        if (!enabled) return;
        const result = await callRequest<{
          data: CommunicationCall[];
          dispatchers?: DispatcherAvailability[];
          last_page?: number;
        }>();
        for (let page = 2; page <= (result.last_page ?? 1) && !stopped; page++) {
          const next = await callRequest<{ data: CommunicationCall[] }>(`?page=${page}`);
          result.data.push(...next.data);
        }
        if (!stopped) {
          setCalls(result.data);
          setDispatchers(result.dispatchers ?? []);
        }
        const active = voice.state.call;
        if (active && !stopped) {
          const result = await callRequest<{ data: CommunicationCall }>(`/${active.id}`);
          if (
            !stopped &&
            voice.state.call?.id === active.id &&
            (result.data.status !== 'active' || !result.data.joinable)
          ) {
            await voice.leave('This call ended or its report assignment changed.');
          }
        }
      } catch (err) {
        if (!stopped) {
          setError(message(err));
          // Release the mic when the server can no longer confirm access.
          if (voice.state.call) await voice.leave('Call access could not be confirmed. Please reconnect.');
        }
      } finally {
        if (!stopped && enabled) timer = setTimeout(poll, 5000);
      }
    };
    void poll();
    const pagehide = () => void voice.leave();
    window.addEventListener('pagehide', pagehide);
    return () => {
      mounted.current = false;
      stopped = true;
      clearTimeout(timer);
      window.removeEventListener('pagehide', pagehide);
    };
  }, [user.id, user.role, enabled, voice, refreshVersion]);

  const previousCall = useRef<CommunicationCall | null>(null);
  useEffect(() => {
    const previous = previousCall.current;
    previousCall.current = state.call;
    if (user.role === 'dispatcher' && previous?.kind === 'sos' && !state.call) {
      void callRequest(`/${previous.id}/end`, 'POST')
        .then(() => refresh())
        .catch((err) => {
          if (mounted.current) setError(message(err));
        });
    }
  }, [state.call, user.role, refresh]);
  useEffect(() => () => { void voice.leave(); }, [voice]);

  async function start(reportId: number) {
    if (busy || voice.state.call) return;
    setBusy(true);
    setError(null);
    try {
      const result = await callRequest<{ data: CommunicationCall }>('', 'POST', { report_id: reportId });
      if (!mounted.current) return;
      setCalls((previous) => [result.data, ...previous]);
      await voice.join(result.data);
    } catch (err) {
      if (mounted.current) setError(message(err));
    } finally {
      if (mounted.current) setBusy(false);
    }
  }

  async function end(call: CommunicationCall) {
    setBusy(true);
    setError(null);
    try {
      await callRequest(`/${call.id}/end`, 'POST');
      if (voice.state.call?.id === call.id) await voice.leave();
      if (mounted.current) setCalls((previous) => previous.filter((item) => item.id !== call.id));
    } catch (err) {
      if (mounted.current) setError(message(err));
    } finally {
      if (mounted.current) setBusy(false);
    }
  }

  async function manage(call: CommunicationCall, action: 'reject' | 'priority', priority?: number) {
    setBusy(true);
    setError(null);
    try {
      await callRequest(`/${call.id}/manage`, 'POST', { action, priority });
      refresh();
    } catch (err) {
      setError(message(err));
    } finally {
      setBusy(false);
    }
  }

  async function availability(status: 'Available' | 'Offline') {
    setBusy(true);
    setError(null);
    try {
      const result = await callRequest<{ data: DispatcherAvailability[] }>('/availability', 'POST', { status });
      setDispatchers(result.data);
      refresh();
    } catch (err) {
      setError(message(err));
    } finally {
      setBusy(false);
    }
  }

  async function answer(call: CommunicationCall) {
    if (busy || voice.state.phase !== 'idle') return;
    setBusy(true);
    setError(null);
    setPanelCollapsed(false);
    try {
      await voice.join(call);
      refresh();
    } finally {
      if (mounted.current) setBusy(false);
    }
  }

  const active = state.call;
  const dispatcherKey = `dispatcher-${user.id}`;
  const currentDispatcher = dispatchers.find((d) => d.account_key === dispatcherKey);
  const available =
    user.role === 'dispatcher' &&
    !active &&
    state.phase === 'idle' &&
    currentDispatcher?.status === 'Available';
  const incoming = incomingSosCalls(calls, dispatcherKey, available);
  const incomingKey = incoming.map((call) => call.id).join(',');

  useEffect(() => {
    const ids = new Set(incomingKey ? incomingKey.split(',') : []);
    if ([...ids].some((id) => !previousIncoming.current.has(id))) setPanelCollapsed(false);
    previousIncoming.current = ids;
    if (!ids.size) return;
    const original = document.title;
    const alertTitle = `Incoming SOS call - ${original}`;
    document.title = alertTitle;
    return () => {
      if (document.title === alertTitle) document.title = original;
    };
  }, [incomingKey, setPanelCollapsed]);

  const invitations = calls.filter((call) => call.id !== active?.id && !dismissed.includes(call.id));
  const hasSosActivity = active?.kind === 'sos' || invitations.some((call) => call.kind === 'sos');
  const showPanel = enabled && (user.role === 'dispatcher' || active || invitations.length > 0 || error || state.error);
  const primaryIncoming = incoming[0];
  const primaryPriority = primaryIncoming ? PRIORITY_LABELS[primaryIncoming.priority ?? 2] ?? 'Emergency' : null;
  const isAvailableStatus = currentDispatcher?.status === 'Available';

  return (
    <Context.Provider value={{ start, busy: busy || state.phase !== 'idle' }}>
      {user.role === 'dispatcher' && import.meta.env.VITE_REVERB_APP_KEY && <CallRealtime refresh={refresh} />}
      {children}

      {incoming.length > 0 && primaryIncoming && (
        <section
          role="alert"
          aria-label="Incoming SOS call"
          className="fixed top-4 left-1/2 z-[60] w-[calc(100%-2rem)] max-w-md -translate-x-1/2 overflow-hidden rounded-xl border-2 border-[#b42318] bg-popover shadow-2xl ring-4 ring-[#b42318]/25"
        >
          <div className="flex items-center justify-between gap-2 bg-[#b42318] px-4 py-2 text-white">
            <span className="inline-flex items-center gap-2 text-xs font-bold tracking-wider uppercase">
              <span className="relative flex size-2.5">
                <span className="absolute inline-flex size-full rounded-full bg-white opacity-75 motion-safe:animate-ping" />
                <span className="relative inline-flex size-2.5 rounded-full bg-white" />
              </span>
              Incoming SOS call
            </span>
            <div className="flex items-center gap-1.5">
              {primaryPriority && (
                <span className="rounded-sm bg-white/20 px-1.5 py-0.5 text-[11px] font-semibold uppercase">
                  {primaryPriority}
                </span>
              )}
              {incoming.length > 1 && (
                <span className="rounded-sm bg-black/25 px-1.5 py-0.5 text-[11px] font-semibold tabular-nums">
                  +{incoming.length - 1} waiting
                </span>
              )}
            </div>
          </div>

          <div className="p-4">
            <div className="flex items-start gap-3">
              <span className="relative flex size-11 shrink-0 items-center justify-center rounded-full border border-critical/30 bg-critical-soft text-critical">
                <PhoneIncoming className="size-5" aria-hidden="true" />
              </span>
              <div className="min-w-0 flex-1 space-y-1">
                <p className="truncate text-base font-semibold text-foreground">
                  {primaryIncoming.resident_name || 'Resident / Guest'}
                </p>
                {primaryIncoming.resident_contact && (
                  <p className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground tabular-nums">
                    <Phone className="size-3 shrink-0" aria-hidden="true" />
                    {primaryIncoming.resident_contact}
                  </p>
                )}
                {primaryIncoming.resident_address && (
                  <p className="flex items-start gap-1.5 text-xs text-muted-foreground">
                    <MapPin className="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                    <span className="line-clamp-2">{primaryIncoming.resident_address}</span>
                  </p>
                )}
              </div>
            </div>

            <div className="mt-4 grid grid-cols-2 gap-2">
              <Button
                disabled={busy}
                onClick={() => void answer(primaryIncoming)}
                className="bg-[#17703f] text-white hover:bg-[#135c33]"
              >
                <Phone className="size-4" aria-hidden="true" />
                Answer
              </Button>
              <Button
                variant="outline"
                disabled={busy}
                onClick={() => void manage(primaryIncoming, 'reject')}
                className="border-critical/40 text-critical hover:bg-critical-soft hover:text-critical"
              >
                <PhoneOff className="size-4" aria-hidden="true" />
                Reject
              </Button>
            </div>
          </div>
        </section>
      )}

      {showPanel && collapsed && (
        <Button
          aria-label="Show voice calls"
          aria-expanded={false}
          aria-controls="voice-calls-panel"
          onClick={() => setPanelCollapsed(false)}
          className={cn(
            'fixed right-4 bottom-4 z-50 h-11 gap-2 rounded-full px-4 shadow-xl max-lg:bottom-[4.5rem]',
            hasSosActivity || incoming.length > 0
              ? 'bg-[#b42318] text-white ring-4 ring-[#b42318]/25 hover:bg-[#991b1b]'
              : active
                ? 'bg-[#17703f] text-white hover:bg-[#135c33]'
                : 'border bg-card text-foreground hover:bg-accent',
            incoming.length > 0 && 'motion-safe:animate-pulse',
          )}
        >
          {hasSosActivity || incoming.length > 0 ? (
            <PhoneIncoming className="size-4" aria-hidden="true" />
          ) : (
            <Phone className="size-4" aria-hidden="true" />
          )}
          <span className="font-semibold">
            {active ? (active.kind === 'sos' ? 'In SOS call' : 'In call') : hasSosActivity ? 'SOS call' : 'Voice calls'}
          </span>
          {user.role === 'dispatcher' && !active && !hasSosActivity && (
            <span
              className={cn('size-2 rounded-full', isAvailableStatus ? 'bg-ok' : 'bg-muted-foreground/50')}
              aria-hidden="true"
            />
          )}
          {invitations.length > 0 && (
            <span
              className={cn(
                'rounded-full px-1.5 py-0.5 text-[11px] font-bold tabular-nums',
                hasSosActivity ? 'bg-white text-[#b42318]' : 'bg-primary text-primary-foreground',
              )}
            >
              {invitations.length}
            </span>
          )}
          {(error || state.error) && (
            <span aria-label="Call error" className="rounded-full bg-amber-400 px-1.5 text-xs font-bold text-black">
              !
            </span>
          )}
        </Button>
      )}

      {showPanel && (
        <aside
          id="voice-calls-panel"
          hidden={collapsed}
          aria-label="Voice calls"
          aria-live="polite"
          className={cn(
            'fixed right-4 bottom-4 z-50 flex max-h-[72dvh] w-[calc(100%-2rem)] max-w-sm flex-col overflow-hidden rounded-xl border bg-popover shadow-2xl max-lg:bottom-[4.5rem]',
            hasSosActivity ? 'border-[#b42318]/60 ring-2 ring-[#b42318]/15' : 'border-border',
          )}
        >
          <div
            className={cn(
              'flex items-center justify-between gap-2 px-4 py-2.5',
              hasSosActivity ? 'bg-[#b42318] text-white' : 'border-b bg-card text-foreground',
            )}
          >
            <h2 className="flex items-center gap-2 text-sm font-semibold">
              {hasSosActivity ? (
                <PhoneIncoming className="size-4" aria-hidden="true" />
              ) : (
                <Phone className="size-4 text-muted-foreground" aria-hidden="true" />
              )}
              <span>{hasSosActivity ? 'SOS emergency call' : 'Voice calls'}</span>
              {invitations.length > 0 && (
                <span
                  className={cn(
                    'rounded-full px-1.5 py-0.5 text-[11px] font-bold tabular-nums',
                    hasSosActivity ? 'bg-white/20 text-white' : 'bg-muted text-foreground',
                  )}
                >
                  {invitations.length}
                </span>
              )}
            </h2>
            <Button
              variant="ghost"
              size="icon-sm"
              aria-label="Hide voice calls"
              title="Hide voice calls"
              aria-expanded={true}
              aria-controls="voice-calls-panel"
              onClick={() => setPanelCollapsed(true)}
              className={hasSosActivity ? 'text-white hover:bg-white/15 hover:text-white' : undefined}
            >
              <Minus className="size-4" />
            </Button>
          </div>

          <div className="space-y-3 overflow-y-auto p-3.5">
            {user.role === 'dispatcher' && (
              <div className="flex items-center justify-between gap-2 rounded-lg border bg-muted/35 px-3 py-2">
                <span className="inline-flex items-center gap-2 text-xs font-medium text-foreground">
                  <span
                    className={cn('size-2 rounded-full', isAvailableStatus ? 'bg-ok' : 'bg-muted-foreground/60')}
                    aria-hidden="true"
                  />
                  {isAvailableStatus ? 'Available for SOS' : 'Offline'}
                </span>
                <div className="inline-flex rounded-md border bg-card p-0.5">
                  <button
                    type="button"
                    disabled={busy || !!active}
                    onClick={() => void availability('Available')}
                    className={cn(
                      'rounded px-2.5 py-1 text-xs font-medium transition-colors disabled:opacity-50',
                      isAvailableStatus ? 'bg-[#17703f] text-white' : 'text-muted-foreground hover:text-foreground',
                    )}
                  >
                    Available
                  </button>
                  <button
                    type="button"
                    disabled={busy || !!active}
                    onClick={() => void availability('Offline')}
                    className={cn(
                      'rounded px-2.5 py-1 text-xs font-medium transition-colors disabled:opacity-50',
                      !isAvailableStatus ? 'bg-secondary text-foreground' : 'text-muted-foreground hover:text-foreground',
                    )}
                  >
                    Offline
                  </button>
                </div>
              </div>
            )}

            {(error || state.error) && (
              <div
                role="alert"
                className="flex items-start justify-between gap-2 rounded-md border border-critical/30 bg-critical-soft px-3 py-2 text-xs text-critical"
              >
                <span>{error || state.error}</span>
                <button
                  type="button"
                  className="shrink-0 font-semibold underline"
                  onClick={() => {
                    setError(null);
                    if (state.phase === 'idle') void voice.leave();
                  }}
                >
                  Dismiss
                </button>
              </div>
            )}

            {active && (
              <div className="space-y-3 rounded-lg border border-ok/35 bg-ok-soft/40 p-3">
                <div className="flex items-center justify-between gap-2">
                  <span className="truncate text-sm font-semibold text-foreground">{callLabel(active)}</span>
                  <span className="inline-flex items-center gap-1 rounded-sm bg-ok-soft px-1.5 py-0.5 text-[11px] font-semibold text-ok">
                    {state.phase === 'joining'
                      ? 'Connecting'
                      : state.phase === 'reconnecting'
                        ? 'Reconnecting'
                        : active.kind === 'sos' && !state.peers.includes(state.uid === 1 ? 2 : 1)
                          ? 'Waiting'
                          : 'Connected'}
                  </span>
                </div>

                <ul className="divide-y rounded-md border bg-card/80 text-xs">
                  {active.participants.map((person) => (
                    <li key={person.uid} className="flex items-center justify-between px-2.5 py-1.5 capitalize">
                      <span className="font-medium text-foreground">
                        {person.role === 'driver/responder' ? 'Responder' : person.role}
                      </span>
                      <span className="text-muted-foreground">
                        {person.uid === state.uid
                          ? 'You'
                          : state.peers.includes(person.uid)
                            ? 'In call'
                            : 'Waiting'}
                      </span>
                    </li>
                  ))}
                </ul>

                <div className="flex flex-wrap gap-1.5">
                  <Button
                    size="sm"
                    variant="outline"
                    disabled={state.phase === 'joining'}
                    onClick={() => void voice.mute()}
                  >
                    {state.muted ? <MicOff className="size-3.5" /> : <Mic className="size-3.5" />}
                    {state.muted ? 'Unmute' : 'Mute'}
                  </Button>
                  <Button size="sm" variant="outline" onClick={() => void voice.resumeAudio()}>
                    <Volume2 className="size-3.5" />
                    Audio
                  </Button>
                  <Button size="sm" variant="outline" onClick={() => void voice.leave()}>
                    <PhoneOff className="size-3.5" />
                    Leave
                  </Button>
                  {user.role === 'dispatcher' && (
                    <Button size="sm" variant="destructive" disabled={busy} onClick={() => void end(active)}>
                      End call
                    </Button>
                  )}
                </div>
              </div>
            )}

            {invitations.map((call) => {
              const isSos = call.kind === 'sos';
              const priorityLabel = PRIORITY_LABELS[call.priority ?? 2] ?? 'Emergency';
              const queueLabel =
                call.queue_state === 'waiting'
                  ? `Queued${call.queue_position ? ` #${call.queue_position}` : ''}`
                  : call.accepted_at
                    ? 'In progress'
                    : 'Ringing';
              const assignedElsewhere =
                user.role === 'dispatcher' &&
                !!call.offered_dispatcher_key &&
                call.offered_dispatcher_key !== dispatcherKey;
              const routedName = call.offered_dispatcher_key
                ? dispatchers.find((item) => item.account_key === call.offered_dispatcher_key)?.name
                : null;

              return (
                <div
                  key={call.id}
                  className={cn(
                    'space-y-2.5 rounded-lg border p-3',
                    isSos ? 'border-critical/40 bg-critical-soft/50' : 'bg-card',
                  )}
                >
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="truncate text-sm font-semibold text-foreground">{callLabel(call)}</p>
                      {isSos && (
                        <div className="mt-1 flex flex-wrap items-center gap-1.5 text-[11px]" role="status">
                          <span className="rounded-sm bg-critical/15 px-1.5 py-0.5 font-semibold text-critical">
                            {queueLabel}
                          </span>
                          <span className="rounded-sm border bg-card px-1.5 py-0.5 font-medium text-foreground">
                            {priorityLabel}
                          </span>
                          {routedName && <span className="text-muted-foreground">→ {routedName}</span>}
                        </div>
                      )}
                    </div>
                  </div>

                  {isSos && (call.resident_contact || call.resident_address) && (
                    <div className="space-y-1 text-xs text-muted-foreground">
                      {call.resident_contact && (
                        <p className="flex items-center gap-1.5 text-foreground tabular-nums">
                          <Phone className="size-3 shrink-0 text-muted-foreground" aria-hidden="true" />
                          {call.resident_contact}
                        </p>
                      )}
                      {call.resident_address && (
                        <p className="flex items-start gap-1.5">
                          <MapPin className="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                          <span className="line-clamp-2">{call.resident_address}</span>
                        </p>
                      )}
                    </div>
                  )}

                  {isSos && user.role === 'dispatcher' && !call.accepted_at && (
                    <label className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                      <span>Priority</span>
                      <select
                        aria-label={`Priority for ${callLabel(call)}`}
                        disabled={busy}
                        value={call.priority ?? 2}
                        onChange={(event) => void manage(call, 'priority', Number(event.target.value))}
                        className="h-7 rounded-md border bg-background px-2 text-xs font-medium text-foreground"
                      >
                        <option value={0}>Critical</option>
                        <option value={1}>High</option>
                        <option value={2}>Emergency</option>
                      </select>
                    </label>
                  )}

                  <div className="flex flex-wrap gap-2">
                    <Button
                      size="sm"
                      disabled={busy || !!active || !call.joinable || assignedElsewhere}
                      onClick={() => void answer(call)}
                      className={isSos ? 'bg-[#17703f] text-white hover:bg-[#135c33]' : undefined}
                    >
                      <Phone className="size-3.5" aria-hidden="true" />
                      {isSos ? (user.role === 'dispatcher' ? 'Answer' : 'Join SOS') : 'Join call'}
                    </Button>

                    {user.role === 'dispatcher' && isSos && !call.accepted_at && (
                      <Button
                        size="sm"
                        variant="outline"
                        disabled={busy || assignedElsewhere}
                        onClick={() => void manage(call, 'reject')}
                        className="border-critical/40 text-critical hover:bg-critical-soft hover:text-critical"
                      >
                        Reject
                      </Button>
                    )}

                    {user.role === 'dispatcher' && (!isSos || call.accepted_at) ? (
                      <Button size="sm" variant="outline" disabled={busy} onClick={() => void end(call)}>
                        End
                      </Button>
                    ) : user.role !== 'dispatcher' ? (
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setDismissed((previous) => [...previous, call.id])}
                      >
                        Dismiss
                      </Button>
                    ) : null}
                  </div>
                </div>
              );
            })}
          </div>
        </aside>
      )}
    </Context.Provider>
  );
}
