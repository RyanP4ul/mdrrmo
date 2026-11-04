import assert from 'node:assert/strict';
import test from 'node:test';
import { VoiceSession } from '../../resources/js/lib/voice-calls.ts';

const call = { id: 'call-1', report_id: 1, status: 'active', joinable: true, participants: [] };
const credentials = { app_id: 'public-id', channel: 'server-channel', uid: 2, token: 'secret-token' };
function deferred() { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; }
function fixture(getCredentials = async () => credentials) {
  const events = {};
  const track = { closed: false, stopped: false, enabled: true,
    async setEnabled(enabled) { this.enabled = enabled; }, stop() { this.stopped = true; }, close() { this.closed = true; } };
  const client = { remoteUsers: [], joined: null, published: false, left: 0, renewed: null,
    on(name, fn) { events[name] = fn; }, removeAllListeners() {},
    async join(...args) { this.joined = args; }, async publish() { this.published = true; },
    async leave() { this.left++; }, async subscribe() {}, async renewToken(token) { this.renewed = token; } };
  const sdk = { createClient: () => client, createMicrophoneAudioTrack: async () => track, async resumeAudioContext() {} };
  const session = new VoiceSession(() => {}, getCredentials, async () => sdk);
  return { session, track, client, sdk, events };
}

test('joins the server-issued identity, publishes audio, shows peers and mutes', async () => {
  const { session, track, client, events } = fixture();
  await session.join(call);
  assert.deepEqual(client.joined, ['public-id', 'server-channel', 'secret-token', 2]);
  assert.equal(client.published, true);
  assert.equal(session.state.phase, 'connected');
  client.remoteUsers = [{ uid: 1 }, { uid: 3 }];
  events['user-joined']();
  assert.deepEqual(session.state.peers, [1, 3]);
  await session.mute();
  assert.equal(track.enabled, false);
  assert.equal(session.state.muted, true);
  await session.leave();
  assert.equal(track.closed, true);
  assert.equal(track.stopped, true);
  assert.equal(client.left, 1);
  assert.equal(session.state.phase, 'idle');
});

test('leaving while microphone permission is pending closes the late track without publishing', async () => {
  const { session, sdk, track, client } = fixture();
  const pending = deferred();
  sdk.createMicrophoneAudioTrack = () => pending.promise;
  const joining = session.join(call);
  await new Promise(resolve => setImmediate(resolve));
  await session.leave();
  pending.resolve(track);
  await joining;
  assert.equal(track.closed, true);
  assert.equal(client.published, false);
  assert.equal(session.state.phase, 'idle');
});

test('leaving during SDK join cannot publish audio after cancellation', async () => {
  const { session, client, track } = fixture();
  const pending = deferred();
  client.join = () => pending.promise;
  const joining = session.join(call);
  await new Promise(resolve => setImmediate(resolve));
  await session.leave();
  pending.resolve(2);
  await joining;
  assert.equal(track.closed, true);
  assert.equal(client.published, false);
  assert.equal(session.state.phase, 'idle');
});

test('failed microphone capture releases the partial RTC connection', async () => {
  const { session, sdk, client } = fixture();
  sdk.createMicrophoneAudioTrack = async () => { throw new Error('Microphone permission denied'); };
  await session.join(call);
  assert.equal(client.left, 1);
  assert.equal(session.state.phase, 'idle');
  assert.match(session.state.error, /Microphone permission/);
});

test('token renewal uses the same call and releases microphone when renewal is forbidden', async () => {
  let forbidden = false;
  const ids = [];
  const { session, client, track, events } = fixture(async id => {
    ids.push(id);
    if (forbidden) throw new Error('Forbidden');
    return credentials;
  });
  await session.join(call);
  await events['token-privilege-will-expire']();
  assert.equal(client.renewed, 'secret-token');
  assert.deepEqual(ids, ['call-1', 'call-1']);
  forbidden = true;
  await events['token-privilege-did-expire']();
  assert.equal(track.closed, true);
  assert.equal(session.state.phase, 'idle');
  assert.match(session.state.error, /expired or changed/);
});

test('remote audio is subscribed and played and reconnection status is visible', async () => {
  const { session, events } = fixture();
  await session.join(call);
  let played = false;
  await events['user-published']({ uid: 1, audioTrack: { play() { played = true; } } }, 'audio');
  assert.equal(played, true);
  events['connection-state-change']('RECONNECTING');
  assert.equal(session.state.phase, 'reconnecting');
  events['connection-state-change']('CONNECTED');
  assert.equal(session.state.phase, 'connected');
  await session.leave();
});
