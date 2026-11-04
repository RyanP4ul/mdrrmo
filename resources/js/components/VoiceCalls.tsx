import { createContext, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { Mic, MicOff, Phone, PhoneOff } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { AuthUser } from '@/types';
import { callRequest, VoiceSession, type CommunicationCall, type CallCredentials, type VoiceState } from '@/lib/voice-calls';

const Context = createContext<{ start(reportId: number): Promise<void>; busy: boolean } | null>(null);
const message = (error: unknown) => error instanceof Error ? error.message : 'Could not connect. Please try again.';

export function StartVoiceCall({ reportId }: { reportId: number }) {
  const calls = useContext(Context);
  return <Button variant="outline" disabled={!calls || calls.busy} onClick={() => void calls?.start(reportId)}>
    <Phone className="mr-2 size-4" />Start three-way call
  </Button>;
}

export function VoiceCalls({ user, children }: { user: AuthUser; children: ReactNode }) {
  const [calls, setCalls] = useState<CommunicationCall[]>([]);
  const [state, setState] = useState<VoiceState>({ phase: 'idle', call: null, uid: null, peers: [], muted: false, error: null });
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
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
        const result = await callRequest<{ data: CommunicationCall[] }>();
        if (!stopped) setCalls(result.data);
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
    return () => { mounted.current = false; stopped = true; clearTimeout(timer); window.removeEventListener('pagehide', pagehide); void voice.leave(); };
  }, [user.id, user.role, enabled, voice]);

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

  const active = state.call;
  const invitations = calls.filter(call => call.id !== active?.id && !dismissed.includes(call.id));
  return <Context.Provider value={{ start, busy: busy || state.phase !== 'idle' }}>
    {children}
    {enabled && (active || invitations.length > 0 || error || state.error) &&
      <aside aria-label="Voice calls" className="fixed bottom-4 right-4 z-50 max-h-[70vh] w-[calc(100%-2rem)] max-w-sm overflow-auto rounded-xl border bg-background p-4 shadow-xl">
        <h2 className="mb-2 flex items-center gap-2 font-semibold"><Phone className="size-4" />Three-way voice call</h2>
        {(error || state.error) && <p role="alert" className="mb-3 text-sm text-destructive">{error || state.error}<button className="ml-2 underline" onClick={() => { setError(null); if (state.phase === 'idle') void voice.leave(); }}>Dismiss</button></p>}
        {active && <div className="space-y-3">
          <p className="text-sm">{active.report_reference || `Report #${active.report_id}`} · {state.phase === 'joining' ? 'Connecting…' : state.phase === 'reconnecting' ? 'Reconnecting…' : 'Connected'}</p>
          <ul className="space-y-1 text-sm">{active.participants.map(person => <li key={person.uid} className="flex justify-between capitalize"><span>{person.role === 'driver/responder' ? 'Responder' : person.role}</span><span className="text-muted-foreground">{person.uid === state.uid ? 'You' : state.peers.includes(person.uid) ? 'In call' : 'Waiting'}</span></li>)}</ul>
          <div className="flex flex-wrap gap-2">
            <Button size="sm" variant="outline" disabled={state.phase === 'joining'} onClick={() => void voice.mute()}>{state.muted ? <MicOff className="mr-1 size-4" /> : <Mic className="mr-1 size-4" />}{state.muted ? 'Unmute' : 'Mute'}</Button>
            <Button size="sm" variant="outline" onClick={() => void voice.resumeAudio()}>Enable audio</Button>
            <Button size="sm" variant="outline" onClick={() => void voice.leave()}><PhoneOff className="mr-1 size-4" />Leave</Button>
            {user.role === 'dispatcher' && <Button size="sm" variant="destructive" disabled={busy} onClick={() => void end(active)}>End for everyone</Button>}
          </div>
          <p className="text-xs text-muted-foreground">Keep this page open during the call.</p>
        </div>}
        {invitations.map(call => <div key={call.id} className="mt-3 space-y-2 border-t pt-3">
          <p className="text-sm font-medium">{call.report_reference || `Report #${call.report_id}`}</p>
          <p className="text-xs text-muted-foreground">Resident, dispatcher, and responder</p>
          <div className="flex flex-wrap gap-2">
            <Button size="sm" disabled={busy || !!active || !call.joinable} onClick={() => { setError(null); void voice.join(call); }}>Join voice call</Button>
            {user.role === 'dispatcher' ? <Button size="sm" variant="outline" disabled={busy} onClick={() => void end(call)}>End call</Button> : <Button size="sm" variant="ghost" onClick={() => setDismissed(previous => [...previous, call.id])}>Dismiss</Button>}
          </div>
        </div>)}
      </aside>}
  </Context.Provider>;
}
