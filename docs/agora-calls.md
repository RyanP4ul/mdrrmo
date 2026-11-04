# Agora three-way calling API

This Laravel API coordinates one resident, one dispatcher, and one assigned driver/responder in a shared Agora RTC channel. It issues AccessToken2 credentials for audio/video publishing. Media travels through Agora; Laravel stores call membership and lifecycle. The web app and Android/iOS Flutter app now include voice calling screens, microphone controls, participant status, and automatic token renewal.

## Make a three-way voice call

1. Sign in as a dispatcher. Open an active resident report and assign a response team with a responder.
2. On the website, open **Reports**, select the report, and click **Start three-way call**. On Flutter, open **Reports and dispatch**, select the report, choose **Three-way voice call**, then **Start call for this report**.
3. Allow microphone access. A call panel shows the resident, dispatcher, and responder, including who has joined.
4. The resident and assigned responder sign in with their own accounts. On the website, an invitation appears in the call panel. On Flutter, tap the phone icon in the app header (a badge shows available invitations), then **Join call**.
5. All three can speak and listen. Use **Mute/Unmute** to control the microphone. Flutter also offers speaker/earpiece selection; the website has **Enable audio** to retry browser playback if needed.
6. **Leave** disconnects only that participant. The creating dispatcher can use **End for everyone** to close the shared call. Leaving does not end the invitation, so participants can rejoin until the dispatcher ends the call or it expires.

Keep the web page or Flutter call screen open while speaking. Navigating away releases the microphone. Moving the Flutter app to the background leaves the call; reopen the calls screen to rejoin. Invitations refresh every five seconds while the app is open. Closed-app ringing, background calls, push notifications, recording, and video UI are not included.

The browser needs HTTPS (or localhost on the same computer), microphone permission, and network access to Agora. Opening the website through an unsecured LAN IP such as `http://192.168.x.x` will not allow browser microphone calling; use HTTPS. The web client loads Agora's versioned `4.24.8` SDK from `https://download.agora.io` when joining, so that host must be allowed by any network or content security policy. SDK loading failures show a retryable error.

Rebuild/restart the Flutter app after these changes; hot reload cannot apply new native plugins or microphone permissions. Android declares recording, audio settings, and network permissions. iOS declares its microphone usage purpose; this project uses Swift Package Manager, and permission_handler discovers the microphone permission from `Info.plist`. For Xcode-launched builds where permission_handler cannot locate the plist, use its documented `PERMISSION_HANDLER_INFO_PLIST` setting pointing to `ios/Runner/Info.plist`. iOS requires a macOS/Xcode build environment. The Flutter calling screen supports Android/iOS; use the MDRRMO website for browser calling.

Build the web assets with `npm run build`, and run Flutter with `flutter run` (or build/install a new APK). No additional database migration is needed beyond the communication-calls migration already installed.

## Configure

1. Create an Agora RTC project in the [Agora Console](https://console.agora.io/) and enable App Certificate authentication. Use token authentication for the project.
2. Set these values in the **Laravel** `.env` (never put the certificate in Flutter, browser code, source control, or API requests):

   ```dotenv
   AGORA_APP_ID=your_32_character_app_id
   AGORA_APP_CERTIFICATE=your_32_character_app_certificate
   AGORA_TOKEN_SECONDS=600
   AGORA_CALL_MINUTES=120
   ```

3. From the `mdrrmo` Laravel directory, run:

   ```sh
   php artisan migrate
   php artisan config:clear
   ```

Token lifetime defaults to 10 minutes and is bounded to 30–3600 seconds, further shortened by the call's remaining lifetime. Call lifetime defaults to two hours and is bounded to 1–1440 minutes. Missing or malformed credentials return HTTP 503 when creating a call or issuing a token.

## Authentication and permissions

Use the existing mobile login endpoint (`POST /api/auth/mobile/login`) and its bearer token. Send `Authorization: Bearer <session-token>` and `Accept: application/json`. All endpoints require an active account and the `session` ability, plus the role's `*-read` ability for reads or `*-write` ability for mutations (`resident`, `driver`, or `staff`).

The website uses matching routes under `/communication/calls` with Laravel browser session authentication, active-role checks, and CSRF protection. It does not create or expose mobile bearer tokens. Mutations send the existing `XSRF-TOKEN` cookie value as `X-XSRF-TOKEN`.

Only a dispatcher can create calls. The server derives the resident from the report's polymorphic reporter and the responder from `assigned_driver_id`. The report must be active (`pending`, `acknowledged`, or `dispatched`), with an active resident reporter and an active assigned responder. Reports submitted by staff cannot create a three-way resident call. There can be one unexpired, unended call per report.

Membership is fixed when the call is created. Only those three accounts may inspect it or request credentials. Administrators and other dispatchers cannot join. Membership uses role-qualified account keys, avoiding collisions between separate account tables.

## Endpoints

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/api/communication/calls` | Create a call (dispatcher only), body `{"report_id": 123}`; returns 201 |
| GET | `/api/communication/calls` | Paginated active calls for the current participant; supports `?page=2` |
| GET | `/api/communication/calls/{id}` | Inspect call status and its three participants |
| POST | `/api/communication/calls/{id}/token` | Obtain or renew credentials for the authenticated participant; no body required |
| POST | `/api/communication/calls/{id}/end` | End the call (its creating dispatcher only); safe to repeat |

The call ID is a UUID. Creation and detail responses wrap the call in `data`, containing `id`, `report_id`, `report_reference`, `channel`, `status`, `joinable`, `participants`, `created_at`, `expires_at`, and `ended_at`. `joinable` becomes false after report closure or a resident/responder assignment change, so clients can disconnect on their next status poll. Participant entries contain `account_key`, `role`, and `uid`. Listing uses Laravel pagination with the calls in `data` and pagination metadata alongside it. Ended and expired calls are omitted from the list but remain available to their participants through the detail endpoint.

Example creation:

```sh
curl -X POST http://localhost:8000/api/communication/calls \
  -H 'Authorization: Bearer <dispatcher-session-token>' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"report_id":123}'
```

Each participant requests their own credentials:

```sh
curl -X POST http://localhost:8000/api/communication/calls/<call-id>/token \
  -H 'Authorization: Bearer <participant-session-token>' \
  -H 'Accept: application/json'
```

Token response:

```json
{
  "data": {
    "app_id": "your_public_agora_app_id",
    "channel": "mdrrmo-<call-uuid>",
    "uid": 1,
    "token": "007...",
    "expires_at": "2026-11-04T12:10:00.000000Z"
  }
}
```

The resident receives UID `1`, the creating dispatcher `2`, and the assigned responder `3`. These UIDs are unique within the call. The server selects the channel, UID, expiry, and publishing permissions; client-supplied overrides are ignored. Credentials are returned with `Cache-Control: no-store` and are never stored in the calls table. Treat tokens as secrets and do not share them across participants.

## Client integration contract

1. Poll the calls list while waiting for a call; this API does not send incoming-call push notifications or ringing events.
2. Request `/token` using the participant's own bearer token.
3. Initialize the Agora RTC SDK with `app_id`. Join the returned `channel` using exactly the numeric `uid` and `token`. All three participants publish and subscribe in the same channel. Configure voice or video in the client SDK.
4. Request `/token` again on the SDK's token-expiry callback and pass the result to the SDK's token renewal method. Credential renewal requires the report to remain active, its resident and responder to match the original membership, and all three accounts to remain active.
5. Poll call detail during the call. Leave the Agora channel and release local media when the status is `ended` or `expired`, or when renewal fails. A participant hanging up locally should leave the SDK channel; only the creating dispatcher ends the shared call.

Ending a call blocks further credentials; it does **not** immediately disconnect existing Agora connections or revoke already issued tokens. Previously issued credentials can remain usable until their privilege expiry. Reassignment, report closure, account deactivation, and logout also cannot revoke credentials already held by a client. Immediate enforced disconnection would require additional Agora channel-management integration. Clients must honor call status and renewal failures.

If a report is reassigned, end the old call and create a new one to obtain a fresh channel with the new responder. Repeated creation while the previous call is active returns 409.

Typical failures: 401 for missing/inactive sessions, 403 for role/ability/membership violations, 404 for an unknown call, 409 for lifecycle or participant conflicts, 422 for invalid input, 429 for throttling, and 503 for missing Agora configuration. Creation/end are limited to 10 requests/minute and credential requests to 30/minute per authenticated user.

## Validation and references

Run `php artisan test --compact tests/Feature/CommunicationCallTest.php`. Tests cover the three participants, unrelated accounts, scoped session abilities, inactive accounts, assignment changes, duplicate calls, expiry, ending, credential configuration, token signature, binary format, and privilege lifetime.

Run `node --experimental-transform-types --test tests/Frontend/voice-calls.test.mjs` from Laravel for web RTC lifecycle tests. In the Flutter directory, run `flutter test --no-pub test/voice_calls_test.dart` and `flutter analyze --no-pub`. Lifecycle tests substitute an RTC transport and do not use real credentials or microphones. A final media acceptance test needs three signed-in clients on a real Agora project: verify every participant can hear the other two, mute and unmute, leave and rejoin, and disconnect when the dispatcher ends the call.

The dependency-free RTC encoder follows Agora's [AccessToken2 implementation](https://github.com/AgoraIO/Tools/blob/master/DynamicKey/AgoraDynamicKey/php/src/AccessToken2.php) and [RTC token builder](https://github.com/AgoraIO/Tools/blob/master/DynamicKey/AgoraDynamicKey/php/src/RtcTokenBuilder2.php). See the official [token-server guide](https://docs.agora.io/en/realtime-media/rtc/build/authenticate-users/deploy-token-server) for the authentication flow. Live media verification requires configured Agora credentials and three SDK clients.
