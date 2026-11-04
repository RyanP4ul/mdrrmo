export interface CallParticipant { account_key: string; role: string; uid: number }
export interface CommunicationCall {
  id: string; report_id: number; report_reference?: string; status: 'active' | 'ended' | 'expired';
  participants: CallParticipant[]; expires_at: string; joinable: boolean;
}
export interface CallCredentials { app_id: string; channel: string; uid: number; token: string; expires_at: string }
export interface AudioTrack { setEnabled(enabled: boolean): Promise<void>; stop(): void; close(): void }
interface RemoteUser { uid: number | string; audioTrack?: { play(): void }; hasAudio: boolean }
export interface VoiceClient {
  remoteUsers: RemoteUser[];
  on(event: string, callback: (...args: any[]) => void): void;
  removeAllListeners(): void;
  join(appId: string, channel: string, token: string, uid: number): Promise<unknown>;
  leave(): Promise<void>;
  publish(track: AudioTrack): Promise<void>;
  subscribe(user: RemoteUser, type: 'audio'): Promise<unknown>;
  renewToken(token: string): Promise<void>;
}
export interface VoiceSdk {
  createClient(options: { mode: 'rtc'; codec: 'vp8' }): VoiceClient;
  createMicrophoneAudioTrack(): Promise<AudioTrack>;
  resumeAudioContext(): void;
}

let sdkRequest: Promise<VoiceSdk> | undefined;
export function loadVoiceSdk(): Promise<VoiceSdk> {
  if (!window.isSecureContext) return Promise.reject(new Error('Voice calls require HTTPS, or localhost on this computer.'));
  if (!navigator.mediaDevices?.getUserMedia) return Promise.reject(new Error('This browser does not support microphone calling.'));
  const existing = (window as Window & { AgoraRTC?: VoiceSdk }).AgoraRTC;
  if (existing) return Promise.resolve(existing);
  if (sdkRequest) return sdkRequest;
  sdkRequest = new Promise<VoiceSdk>((resolve, reject) => {
    const script = document.createElement('script');
    script.src = 'https://download.agora.io/sdk/release/AgoraRTC_N-4.24.8.js';
    script.async = true;
    const timer = window.setTimeout(() => fail(), 20000);
    const fail = () => {
      clearTimeout(timer); script.remove(); sdkRequest = undefined;
      reject(new Error('Could not load voice calling. Check your connection and try again.'));
    };
    script.onerror = fail;
    script.onload = () => {
      clearTimeout(timer);
      const sdk = (window as Window & { AgoraRTC?: VoiceSdk }).AgoraRTC;
      if (sdk) resolve(sdk); else fail();
    };
    document.head.append(script);
  });
  return sdkRequest;
}

export async function callRequest<T>(path = '', method = 'GET', body?: unknown): Promise<T> {
  const xsrf = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='))?.slice(11);
  const response = await fetch(`/communication/calls${path}`, {
    method, credentials: 'same-origin', headers: {
      Accept: 'application/json', 'Content-Type': 'application/json',
      ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
    }, ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    signal: AbortSignal.timeout(15000),
  });
  const data = await response.json().catch(() => null);
  if (!response.ok) throw new Error(data?.message || 'Could not contact the call service. Please try again.');
  if (!data || !Object.hasOwn(data, 'data')) throw new Error('Invalid call service response. Please sign in again or retry.');
  return data as T;
}

export interface VoiceState {
  phase: 'idle' | 'joining' | 'connected' | 'reconnecting';
  call: CommunicationCall | null; uid: number | null; peers: number[]; muted: boolean; error: string | null;
}
const emptyState = (): VoiceState => ({ phase: 'idle', call: null, uid: null, peers: [], muted: false, error: null });

/** Owns the microphone and RTC connection, including cancellation during joining. */
export class VoiceSession {
  state: VoiceState = emptyState();
  private client?: VoiceClient;
  private track?: AudioTrack;
  private generation = 0;
  private renewing = false;
  private toggling = false;
  constructor(
    private changed: (state: VoiceState) => void,
    private credentials: (id: string) => Promise<CallCredentials>,
    private sdk: () => Promise<VoiceSdk> = loadVoiceSdk,
  ) {}

  private update(patch: Partial<VoiceState>) { this.state = { ...this.state, ...patch }; this.changed(this.state); }

  async join(call: CommunicationCall): Promise<void> {
    if (this.state.phase !== 'idle') return;
    const generation = ++this.generation;
    const current = () => generation === this.generation;
    this.update({ ...emptyState(), phase: 'joining', call });
    let client: VoiceClient | undefined;
    let track: AudioTrack | undefined;
    try {
      const [sdk, credentials] = await Promise.all([this.sdk(), this.credentials(call.id)]);
      if (!current()) return;
      client = sdk.createClient({ mode: 'rtc', codec: 'vp8' });
      this.client = client;
      const rtc = client;
      const peers = () => { if (current()) this.update({ peers: rtc.remoteUsers.map(user => Number(user.uid)) }); };
      rtc.on('user-joined', peers);
      rtc.on('user-left', peers);
      rtc.on('user-published', async (user: RemoteUser, type: string) => {
        if (type !== 'audio' || !current()) return;
        try { await rtc.subscribe(user, 'audio'); if (current()) user.audioTrack?.play(); }
        catch { if (current()) this.update({ error: 'Could not play a participant. Use Enable audio or rejoin.' }); }
      });
      const renew = async () => {
        if (!current() || this.renewing) return;
        this.renewing = true;
        try { const result = await this.credentials(call.id); if (current()) await rtc.renewToken(result.token); }
        catch { if (current()) await this.leave('Call access expired or changed. Rejoin if the call is still available.'); }
        finally { this.renewing = false; }
      };
      rtc.on('token-privilege-will-expire', renew);
      rtc.on('token-privilege-did-expire', renew);
      rtc.on('connection-state-change', (state: string, _previous: string, reason?: string) => {
        if (!current()) return;
        if (state === 'DISCONNECTED' && reason) void this.leave('The call disconnected. You can try joining again.');
        else if (state === 'RECONNECTING') this.update({ phase: 'reconnecting' });
        else if (state === 'CONNECTED' && this.state.phase !== 'joining') this.update({ phase: 'connected' });
      });
      track = await sdk.createMicrophoneAudioTrack();
      if (!current()) { track.close(); return; }
      this.track = track;
      await rtc.join(credentials.app_id, credentials.channel, credentials.token, credentials.uid);
      if (!current()) { await rtc.leave(); return; }
      await rtc.publish(track);
      if (!current()) { await rtc.leave(); return; }
      this.update({ phase: 'connected', uid: credentials.uid, peers: rtc.remoteUsers.map(user => Number(user.uid)) });
    } catch (error) {
      if (current()) await this.leave(error instanceof Error ? error.message : 'Could not join. Allow microphone access and try again.');
      else { track?.close(); await client?.leave().catch(() => {}); }
    }
  }

  async mute(): Promise<void> {
    if (!this.track || this.toggling) return;
    this.toggling = true;
    const generation = this.generation;
    try { const muted = !this.state.muted; await this.track.setEnabled(!muted); if (generation === this.generation) this.update({ muted }); }
    catch { this.update({ error: 'Could not change the microphone. Please try again.' }); }
    finally { this.toggling = false; }
  }

  async resumeAudio(): Promise<void> {
    try { await (await this.sdk()).resumeAudioContext(); this.client?.remoteUsers.forEach(user => user.audioTrack?.play()); }
    catch { this.update({ error: 'Audio is blocked. Check your browser sound permissions.' }); }
  }

  async leave(error: string | null = null): Promise<void> {
    ++this.generation;
    const client = this.client;
    const track = this.track;
    this.client = undefined; this.track = undefined;
    track?.stop(); track?.close(); client?.removeAllListeners();
    this.update({ ...emptyState(), error });
    await client?.leave().catch(() => {});
  }
}
