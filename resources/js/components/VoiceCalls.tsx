import { useEcho } from '@laravel/echo-react';
import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { Mic, MicOff, Minus, Phone, PhoneIncoming, PhoneOff } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { AuthUser } from '@/types';
import { callRequest, incomingSosCalls, VoiceSession, type CommunicationCall, type CallCredentials, type VoiceState } from '@/lib/voice-calls';

interface DispatcherAvailability { account_key: string; name: string; status: 'Available' | 'Busy' | 'Offline' }

const Context = createContext<{ start(reportId: number): Promise<void>; busy: boolean } | null>(null);
const message = (error: unknown) => error instanceof Error ? error.message : 'Could not connect. Please try again.';

export function StartVoiceCall({ reportId }: { reportId: number }) {
  const calls = useContext(Context);
  return <Button variant="outline" disabled={!calls || calls.busy} onClick={() => void calls?.start(reportId)}>
    <Phone className="mr-2 size-4" />Start three-way call
  </Button>;
}

function CallRealtime({ refresh }: { refresh: () => void }) {
  useEcho('dispatch', '.call.updated', refresh, [refresh]);
  return null;
}
const callLabel = (call: CommunicationCall) => call.kind === 'sos' ? `SOS - ${call.resident_name || 'Resident'}` : call.report_reference || `Report #${call.report_id}`;

export function VoiceCalls({ user, children }: { user: AuthUser; children: ReactNode }) {
  const [refreshVersion, setRefreshVersion] = useState(0);
  const refresh = useCallback(() => setRefreshVersion(version => version + 1), []);
  const [dispatchers, setDispatchers] = useState<DispatcherAvailability[]>([]);
  const [calls, setCalls] = useState<CommunicationCall[]>([]);
  const [state, setState] = useState<VoiceState>({ phase: 'idle', call: null, uid: null, peers: [], muted: false, error: null });
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const panelStorageKey = `mdrrmo.voiceCalls.collapsed.${user.role}.${user.id}`;
  const [collapsed, setCollapsed] = useState(() => {
    try { return sessionStorage.getItem(panelStorageKey) === 'true'; } catch { return false; }
  });
  const setPanelCollapsed = useCallback((value: boolean) => {
    setCollapsed(value);
    try { sessionStorage.setItem(panelStorageKey, String(value)); } catch { /* Keep controls usable when storage is blocked. */ }
  }, [panelStorageKey]);
  const previousIncoming = useRef(new Set<string>());
  const [dismissed, setDismissed] = useState<string[]>([]);
  const session = useRef<VoiceSession | null>(null);
  if (!session.current) session.current = new VoiceSession(setState, async id =>
    (await callRequest<{ data: CallCredentials }>(`/${id}/token`, 'POST')).data);
  const voice = session.current;
  const enabled = user.role !== 'admin';
  const mounted = useRef(true);

  useEffect(() => {
    mounted.current = true;
    let stopped = false;
    let timer: ReturnType<typeof setTimeout>;
    const poll = async () => {
      try {
        if (!enabled) return;
        const result = await callRequest<{ data: CommunicationCall[]; dispatchers?: DispatcherAvailability[]; last_page?: number }>();
        for (let page = 2; page <= (result.last_page ?? 1) && !stopped; page++) {
          const next = await callRequest<{ data: CommunicationCall[] }>(`?page=${page}`);
          result.data.push(...next.data);
        }
        if (!stopped) { setCalls(result.data); setDispatchers(result.dispatchers ?? []); }
        const active = voice.state.call;
        if (active && !stopped) {
          const result = await callRequest<{ data: CommunicationCall }>(`/${active.id}`);
          if (!stopped && voice.state.call?.id === active.id && (result.data.status !== 'active' || !result.data.joinable)) {
            await voice.leave('This call ended or its report assignment changed.');
          }
        }
      } catch (error) {
        if (!stopped) {
          setError(message(error));
          // Release the mic when the server can no longer confirm access.
          if (voice.state.call) await voice.leave('Call access could not be confirmed. Please reconnect.');
        }
      } finally { if (!stopped && enabled) timer = setTimeout(poll, 5000); }
    };
    void poll();
    const pagehide = () => void voice.leave();
    window.addEventListener('pagehide', pagehide);
    return () => { mounted.current = false; stopped = true; clearTimeout(timer); window.removeEventListener('pagehide', pagehide); };
  }, [user.id, user.role, enabled, voice, refreshVersion]);

  const previousCall = useRef<CommunicationCall | null>(null);
  useEffect(() => {
    const previous = previousCall.current;
    previousCall.current = state.call;
    if (user.role === 'dispatcher' && previous?.kind === 'sos' && !state.call) {
      void callRequest(`/${previous.id}/end`, 'POST').then(() => refresh()).catch(error => {
        if (mounted.current) setError(message(error));
      });
    }
  }, [state.call, user.role, refresh]);
  useEffect(() => () => { void voice.leave(); }, [voice]);

  async function start(reportId: number) {
    if (busy || voice.state.call) return;
    setBusy(true); setError(null);
    try {
      const result = await callRequest<{ data: CommunicationCall }>('', 'POST', { report_id: reportId });
      if (!mounted.current) return;
      setCalls(previous => [result.data, ...previous]);
      await voice.join(result.data);
    } catch (error) { if (mounted.current) setError(message(error)); }
    finally { if (mounted.current) setBusy(false); }
  }

  async function end(call: CommunicationCall) {
    setBusy(true); setError(null);
    try {
      await callRequest(`/${call.id}/end`, 'POST');
      if (voice.state.call?.id === call.id) await voice.leave();
      if (mounted.current) setCalls(previous => previous.filter(item => item.id !== call.id));
    } catch (error) { if (mounted.current) setError(message(error)); }
    finally { if (mounted.current) setBusy(false); }
  }

  async function manage(call: CommunicationCall, action: 'reject' | 'priority', priority?: number) {
    setBusy(true); setError(null);
    try { await callRequest(`/${call.id}/manage`, 'POST', { action, priority }); refresh(); }
    catch (error) { setError(message(error)); }
    finally { setBusy(false); }
  }
  async function availability(status: 'Available' | 'Offline') {
    setBusy(true); setError(null);
    try { const result = await callRequest<{ data: DispatcherAvailability[] }>('/availability', 'POST', { status }); setDispatchers(result.data); refresh(); }
    catch (error) { setError(message(error)); }
    finally { setBusy(false); }
  }
  async function answer(call: CommunicationCall) {
    if (busy || voice.state.phase !== 'idle') return;
    setBusy(true); setError(null); setPanelCollapsed(false);
    try { await voice.join(call); refresh(); }
    finally { if (mounted.current) setBusy(false); }
  }
  const active = state.call;
  const dispatcherKey = `dispatcher-${user.id}`;
  const available = user.role === 'dispatcher' && !active && state.phase === 'idle'
    && dispatchers.some(dispatcher => dispatcher.account_key === dispatcherKey && dispatcher.status === 'Available');
  const incoming = incomingSosCalls(calls, dispatcherKey, available);
  const incomingKey = incoming.map(call => call.id).join(',');
  useEffect(() => {
    const ids = new Set(incomingKey ? incomingKey.split(',') : []);
    if ([...ids].some(id => !previousIncoming.current.has(id))) setPanelCollapsed(false);
    previousIncoming.current = ids;
    if (!ids.size) return;
    const original = document.title;
    const alertTitle = `Incoming SOS call - ${original}`;
    document.title = alertTitle;
    return () => { if (document.title === alertTitle) document.title = original; };
  }, [incomingKey, setPanelCollapsed]);
  const invitations = calls.filter(call => call.id !== active?.id && !dismissed.includes(call.id));
  return <Context.Provider value={{ start, busy: busy || state.phase !== 'idle' }}>
    {user.role === 'dispatcher' && import.meta.env.VITE_REVERB_APP_KEY && <CallRealtime refresh={refresh} />}
    {children}
    {incoming.length > 0 && <section role="alert" aria-label="Incoming SOS call" className="fixed top-4 left-1/2 z-[60] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 rounded-xl border-2 border-destructive bg-popover p-4 shadow-2xl ring-4 ring-destructive/20">
      <div className="flex items-center gap-3">
        <span className="flex size-12 shrink-0 items-center justify-center rounded-full bg-destructive text-destructive-foreground"><PhoneIncoming className="size-6 motion-safe:animate-bounce" /></span>
        <div className="min-w-0 flex-1"><p className="font-bold text-destructive">Incoming SOS call</p><p className="break-words text-lg font-semibold">{incoming[0].resident_name || 'Resident / guest'}</p><p className="text-sm text-muted-foreground">{incoming.length > 1 ? `${incoming.length} callers are ringing. Answer the highest-priority call.` : 'A caller needs assistance. You are available to answer.'}</p></div>
      </div>
      <div className="mt-4 flex flex-wrap gap-2"><Button disabled={busy} onClick={() => void answer(incoming[0])}><Phone className="mr-1 size-4" />Answer call</Button><Button variant="outline" disabled={busy} onClick={() => void manage(incoming[0], 'reject')}>Reject call</Button><Button variant="ghost" onClick={() => setPanelCollapsed(false)}>View calls</Button></div>
    </section>}
    {enabled && collapsed && (user.role === 'dispatcher' || active || invitations.length > 0 || error || state.error) && <Button aria-label="Show voice calls" aria-expanded={false} aria-controls="voice-calls-panel" className={`fixed right-4 bottom-4 z-50 gap-2 rounded-full px-4 shadow-xl max-lg:bottom-[4.5rem] ${incoming.length ? 'motion-safe:animate-pulse' : ''}`} onClick={() => setPanelCollapsed(false)}>
      {incoming.length ? <PhoneIncoming className="size-4" /> : <Phone className="size-4" />}{active ? 'In call' : 'Voice calls'}{invitations.length > 0 && <span className="rounded-full bg-primary-foreground px-1.5 text-xs text-primary">{invitations.length}</span>}{(error || state.error) && <span aria-label="Call error">!</span>}
    </Button>}
    {enabled && (user.role === 'dispatcher' || active || invitations.length > 0 || error || state.error) &&
      <aside id="voice-calls-panel" hidden={collapsed} aria-label="Voice calls" aria-live="polite" className="fixed right-4 bottom-4 z-50 max-h-[70dvh] w-[calc(100%-2rem)] max-w-sm overflow-auto rounded-lg border bg-popover p-4 shadow-xl max-lg:bottom-[4.5rem]">
        <div className="mb-2 flex items-center justify-between gap-2"><h2 className="flex items-center gap-2 font-semibold"><Phone className="size-4" />{active?.kind === "sos" || invitations.some(call => call.kind === "sos") ? "SOS emergency call" : "Voice calls"}</h2><Button variant="ghost" size="icon-sm" aria-label="Hide voice calls" title="Hide voice calls" aria-expanded={true} aria-controls="voice-calls-panel" onClick={() => setPanelCollapsed(true)}><Minus className="size-4" /></Button></div>
        {user.role === 'dispatcher' && <div className="mb-3 space-y-2 border-b pb-3">
          <p className="text-sm font-medium">Dispatcher availability</p>
          {dispatchers.map(dispatcher => <p className="text-xs" key={dispatcher.account_key}>{dispatcher.name}: {dispatcher.status}</p>)}
          <div className="flex gap-2"><Button size="sm" variant="outline" disabled={busy || !!active} onClick={() => void availability('Available')}>Available</Button><Button size="sm" variant="outline" disabled={busy || !!active} onClick={() => void availability('Offline')}>Offline</Button></div>
          <p className="text-xs text-muted-foreground">{invitations.filter(call => call.kind === 'sos').length} incoming or waiting SOS calls. Emergency priority takes precedence over arrival time.</p>
        </div>}
        {(error || state.error) && <p role="alert" className="mb-3 text-sm text-destructive">{error || state.error}<button className="ml-2 underline" onClick={() => { setError(null); if (state.phase === 'idle') void voice.leave(); }}>Dismiss</button></p>}
        {active && <div className="space-y-3">
          <p className="text-sm">{callLabel(active)} · {state.phase === 'joining' ? 'Connecting…' : state.phase === 'reconnecting' ? 'Reconnecting…' : active.kind === 'sos' && !state.peers.includes(state.uid === 1 ? 2 : 1) ? 'Waiting for the other participant' : 'Connected'}</p>
          <ul className="space-y-1 text-sm">{active.participants.map(person => <li key={person.uid} className="flex justify-between capitalize"><span>{person.role === 'driver/responder' ? 'Responder' : person.role}</span><span className="text-muted-foreground">{person.uid === state.uid ? 'You' : state.peers.includes(person.uid) ? 'In call' : 'Waiting'}</span></li>)}</ul>
          <div className="flex flex-wrap gap-2">
            <Button size="sm" variant="outline" disabled={state.phase === 'joining'} onClick={() => void voice.mute()}>{state.muted ? <MicOff className="mr-1 size-4" /> : <Mic className="mr-1 size-4" />}{state.muted ? 'Unmute' : 'Mute'}</Button>
            <Button size="sm" variant="outline" onClick={() => void voice.resumeAudio()}>Enable audio</Button>
            <Button size="sm" variant="outline" onClick={() => void voice.leave()}><PhoneOff className="mr-1 size-4" />Leave</Button>
            {user.role === 'dispatcher' && <Button size="sm" variant="destructive" disabled={busy} onClick={() => void end(active)}>End for everyone</Button>}
          </div>
          <p className="text-xs text-muted-foreground">Keep this page open during the call.</p>
        </div>}
        {invitations.map(call => <div key={call.id} className={`mt-3 space-y-2 rounded-md border p-3 ${call.kind === "sos" ? "border-critical/40 bg-critical-soft" : ""}`}>
          <p className="text-sm font-medium">{callLabel(call)}</p>
          <p className="text-xs text-muted-foreground">{call.kind === "sos" ? "Incoming SOS - answer to speak directly" : "Resident, dispatcher, and responder"}</p>
          {call.kind === 'sos' && <p className="text-xs" role="status">{call.queue_state === 'waiting' ? `Queued${call.queue_position ? ` #${call.queue_position}` : ''}` : call.accepted_at ? 'In progress' : 'Ringing'} ? {['Critical', 'High', 'Emergency'][call.priority ?? 2]}{call.offered_dispatcher_key ? ` ? Routed to ${dispatchers.find(item => item.account_key === call.offered_dispatcher_key)?.name ?? 'dispatcher'}` : ''}</p>}
          {call.kind === 'sos' && user.role === 'dispatcher' && !call.accepted_at && <label className="block text-xs">Triage priority <select aria-label={`Priority for ${callLabel(call)}`} disabled={busy} value={call.priority ?? 2} onChange={event => void manage(call, 'priority', Number(event.target.value))}><option value={0}>Critical</option><option value={1}>High</option><option value={2}>Emergency</option></select></label>}
          {call.kind === "sos" && <p className="break-words text-sm">{call.resident_contact}<br />{call.resident_address}</p>}
          <div className="flex flex-wrap gap-2">
            <Button size="sm" disabled={busy || !!active || !call.joinable || (user.role === 'dispatcher' && !!call.offered_dispatcher_key && call.offered_dispatcher_key !== `dispatcher-${user.id}`)} onClick={() => void answer(call)}>{call.kind === "sos" ? user.role === "dispatcher" ? "Answer SOS call" : "Join SOS call" : "Join voice call"}</Button>
            {user.role === 'dispatcher' && call.kind === 'sos' && !call.accepted_at && <Button size="sm" variant="destructive" disabled={busy || (!!call.offered_dispatcher_key && call.offered_dispatcher_key !== `dispatcher-${user.id}`)} onClick={() => void manage(call, 'reject')}>Reject call</Button>}
            {user.role === 'dispatcher' && (call.kind !== 'sos' || call.accepted_at) ? <Button size="sm" variant="outline" disabled={busy} onClick={() => void end(call)}>End call</Button> : user.role !== 'dispatcher' ? <Button size="sm" variant="ghost" onClick={() => setDismissed(previous => [...previous, call.id])}>Dismiss</Button> : null}
          </div>
        </div>)}
      </aside>}
  </Context.Provider>;
}
