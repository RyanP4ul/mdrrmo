<?php

namespace App\Http\Controllers;

use App\Events\CommunicationCallUpdated;
use App\Models\Account;
use App\Models\CommunicationCall;
use App\Models\DispatcherUser;
use App\Models\EmergencyReport;
use App\Models\ResidentUser;
use App\Models\User;
use App\Services\AgoraTokenService;
use App\Services\SosCallQueue;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommunicationCallController extends Controller
{
    public function __construct(private SosCallQueue $queue) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAbility($request, false);
        $this->queue->heartbeat($request->user());
        $this->queue->sync();
        $calls = CommunicationCall::with('report')->forParticipant($request->user())
            ->whereNull('ended_at')->where('expires_at', '>', now())
            ->orderByRaw("case when kind = 'sos' then 0 else 1 end")->orderBy('priority')->oldest()->paginate(20);

        $calls->through(fn (CommunicationCall $call) => $this->payload($call));

        return response()->json(array_merge($calls->toArray(), ['dispatchers' => $request->user()->hasRole('dispatcher') ? $this->queue->dispatchers() : []]))->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, AgoraTokenService $tokens): JsonResponse
    {
        $this->authorizeAbility($request, true);
        abort_unless($request->user()->hasRole('dispatcher'), 403);
        $data = $request->validate(['report_id' => ['required', 'integer', 'exists:emergency_reports,id']]);
        $tokens->assertConfigured();

        $call = $this->queue->run(function () use ($data, $request): CommunicationCall {
            abort_if(CommunicationCall::where('dispatcher_key', Account::keyFor($request->user()))->whereNull('ended_at')->where('expires_at', '>', now())->exists(), 409, 'End your current call before starting another.');

            $report = EmergencyReport::query()->lockForUpdate()->findOrFail($data['report_id']);
            $resident = $report->reporter;
            $driver = $report->driver;
            abort_unless(in_array($report->status, ['pending', 'acknowledged', 'dispatched'], true), 409, 'This report is no longer active.');
            abort_unless($resident instanceof ResidentUser && $resident->status === 'active'
                && $driver?->status === 'active', 409, 'An active resident and assigned responder are required.');
            abort_if(CommunicationCall::where('emergency_report_id', $report->id)
                ->whereNull('ended_at')->where('expires_at', '>', now())->exists(), 409, 'This report already has an active call.');

            return CommunicationCall::create([
                'emergency_report_id' => $report->id,
                'resident_key' => Account::keyFor($resident),
                'dispatcher_key' => Account::keyFor($request->user()),
                'responder_key' => Account::keyFor($driver),
                'expires_at' => now()->addMinutes(max(1, min(1440, (int) config('agora.call_minutes')))),
            ]);
        });

        $this->queue->sync();

        return response()->json(['data' => $this->payload($call->refresh())], 201)->header('Cache-Control', 'no-store');
    }

    public function sos(Request $request, AgoraTokenService $tokens): JsonResponse
    {
        $this->authorizeAbility($request, true);
        abort_unless($request->user()->hasRole('resident') && $request->user()->registration_status === 'accepted', 403, 'Your account is awaiting approval.');
        $tokens->assertConfigured();
        abort_unless(DispatcherUser::where('status', 'active')->exists(), 503, 'No dispatcher account is currently available. Please contact MDRRMO directly.');
        $call = DB::transaction(function () use ($request): CommunicationCall {
            ResidentUser::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $key = Account::keyFor($request->user());
            $existing = CommunicationCall::where('kind', 'sos')->where('resident_key', $key)
                ->whereNull('ended_at')->where('expires_at', '>', now())->first();
            if ($existing) {
                return $existing;
            }
            $call = CommunicationCall::create([
                'kind' => 'sos', 'resident_key' => $key, 'caller_seen_at' => now(),
                'expires_at' => now()->addSeconds(max(30, min(600, (int) config('agora.sos_queue_seconds', 600)))),
            ]);
            Audit::record('SOS_CALL', "Resident started SOS call {$call->id}");
            $this->broadcastCall($call);

            return $call;
        }, 3);

        $this->queue->sync();

        return response()->json(['data' => $this->payload($call->refresh())], 201)->header('Cache-Control', 'no-store');
    }

    public function guestSos(Request $request, AgoraTokenService $tokens): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'contact' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);
        $tokens->assertConfigured();
        abort_unless(DispatcherUser::where('status', 'active')->exists(), 503, 'No dispatcher account is currently available. Please contact MDRRMO directly.');
        $secret = $request->header('X-Guest-Call-Token') ?? Str::random(64);
        abort_unless(is_string($secret) && preg_match('/^[a-zA-Z0-9]{64}$/D', $secret), 422, 'Invalid guest call token.');
        $call = $this->queue->run(function () use ($data, $secret): CommunicationCall {
            $existing = CommunicationCall::where('guest_token_hash', hash('sha256', $secret))
                ->whereNull('ended_at')->where('expires_at', '>', now())->first();
            if ($existing) {
                return $existing;
            }
            $call = CommunicationCall::create([
                'kind' => 'sos', 'resident_key' => 'guest-'.Str::uuid(), 'caller_seen_at' => now(),
                'guest_token_hash' => hash('sha256', $secret),
                'guest_name' => $data['name'] ?? null,
                'guest_contact' => $data['contact'] ?? null,
                'guest_address' => $data['address'] ?? null,
                'expires_at' => now()->addSeconds(max(30, min(600, (int) config('agora.sos_queue_seconds', 600)))),
            ]);
            Audit::record('SOS_CALL', "Guest started SOS call {$call->id}");
            $this->broadcastCall($call);

            return $call;
        });

        $this->queue->sync();

        return response()->json(['data' => $this->payload($call->refresh()), 'guest_token' => $secret], 201)->header('Cache-Control', 'no-store');
    }

    private function authorizeGuest(Request $request, CommunicationCall $call): void
    {
        $secret = $request->header('X-Guest-Call-Token', '');
        abort_unless($call->isGuest() && strlen($secret) === 64
            && hash_equals($call->guest_token_hash, hash('sha256', $secret)), 403);
    }

    public function guestShow(Request $request, CommunicationCall $call): JsonResponse
    {
        $this->authorizeGuest($request, $call);
        $call->update(['caller_seen_at' => now()]);
        $this->queue->sync();

        return response()->json(['data' => $this->payload($call->refresh())])->header('Cache-Control', 'no-store');
    }

    public function guestToken(Request $request, CommunicationCall $call, AgoraTokenService $tokens): JsonResponse
    {
        $this->authorizeGuest($request, $call);

        return $this->queue->run(function () use ($call, $tokens): JsonResponse {
            $call = CommunicationCall::query()->lockForUpdate()->findOrFail($call->id);
            abort_unless($this->payload($call)['joinable'], 409, 'This call has ended or expired.');
            $seconds = min(max(30, min(3600, (int) config('agora.token_seconds'))), $call->expires_at->timestamp - now()->timestamp);

            return response()->json(['data' => [
                'app_id' => config('agora.app_id'), 'channel' => $call->channel(), 'uid' => 1,
                'token' => $tokens->build($call->channel(), 1, $seconds),
                'expires_at' => now()->addSeconds($seconds)->toISOString(),
            ]])->header('Cache-Control', 'no-store');
        });
    }

    public function guestEnd(Request $request, CommunicationCall $call): JsonResponse
    {
        $this->authorizeGuest($request, $call);
        CommunicationCall::whereKey($call->id)->whereNull('ended_at')->update(['ended_at' => now(), 'queue_state' => 'ended', 'end_reason' => 'cancelled']);
        $this->broadcastCall($call);
        $this->queue->sync();

        return response()->json(['data' => $this->payload($call->refresh())])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, CommunicationCall $call): JsonResponse
    {
        $this->authorizeAbility($request, false);
        $this->authorizeParticipant($request, $call);
        if (Account::keyFor($request->user()) === $call->resident_key) {
            $call->update(['caller_seen_at' => now()]);
        } elseif ($call->kind === 'sos' && Account::keyFor($request->user()) === $call->dispatcher_key) {
            $call->update(['dispatcher_seen_at' => now()]);
        }
        $this->queue->sync();

        return response()->json(['data' => $this->payload($call->refresh())])->header('Cache-Control', 'no-store');
    }

    public function token(Request $request, CommunicationCall $call, AgoraTokenService $tokens): JsonResponse
    {
        $this->authorizeAbility($request, true);
        $this->authorizeParticipant($request, $call);

        $this->queue->heartbeat($request->user());
        $this->queue->sync();

        return $this->queue->run(function () use ($request, $call, $tokens): JsonResponse {
            $call = CommunicationCall::query()->lockForUpdate()->findOrFail($call->id);
            abort_if($call->ended_at || $call->expires_at->isPast(), 409, 'This call has ended or expired.');
            $this->authorizeParticipant($request, $call);
            if ($call->kind === 'sos') {
                $this->claimSos($request, $call);
            } else {
                $report = $call->report;
                abort_unless($report && in_array($report->status, ['pending', 'acknowledged', 'dispatched'], true)
                    && $report->reporter instanceof ResidentUser
                    && Account::keyFor($report->reporter) === $call->resident_key
                    && $report->driver && Account::keyFor($report->driver) === $call->responder_key,
                    409, 'The report participants or status changed. End this call and create a new one.');
            }
            foreach (array_filter([$call->resident_key, $call->dispatcher_key, $call->responder_key]) as $key) {
                if ($call->isGuest() && $key === $call->resident_key) {
                    continue;
                }
                abort_unless(Account::findByKey($key)?->status === 'active', 409, 'A participant account is no longer active.');
            }

            $seconds = min(max(30, min(3600, (int) config('agora.token_seconds'))), $call->expires_at->timestamp - now()->timestamp);
            abort_if($seconds < 1, 409, 'This call has expired.');
            $uid = $call->uidFor($request->user());

            return response()->json(['data' => [
                'app_id' => config('agora.app_id'), 'channel' => $call->channel(), 'uid' => $uid,
                'token' => $tokens->build($call->channel(), $uid, $seconds),
                'expires_at' => now()->addSeconds($seconds)->toISOString(),
            ]])->header('Cache-Control', 'no-store');
        });
    }

    public function end(Request $request, CommunicationCall $call): JsonResponse
    {
        $this->authorizeAbility($request, true);
        $this->authorizeParticipant($request, $call);
        abort_unless(Account::keyFor($request->user()) === $call->dispatcher_key || ($call->kind === 'sos' && Account::keyFor($request->user()) === $call->resident_key), 403);
        CommunicationCall::whereKey($call->id)->whereNull('ended_at')->update(['ended_at' => now(), 'queue_state' => 'ended', 'end_reason' => 'cancelled']);
        $this->broadcastCall($call);
        $this->queue->sync();

        return response()->json(['data' => $this->payload($call->refresh())]);
    }

    public function availability(Request $request): JsonResponse
    {
        $this->authorizeAbility($request, true);
        abort_unless($request->user()->hasRole('dispatcher'), 403);
        $data = $request->validate(['status' => ['required', 'in:Available,Offline']]);
        $this->queue->heartbeat($request->user(), $data['status'] === 'Available');
        $this->queue->sync();

        return response()->json(['data' => $this->queue->dispatchers()])->header('Cache-Control', 'no-store');
    }

    public function manage(Request $request, CommunicationCall $call): JsonResponse
    {
        $this->authorizeAbility($request, true);
        abort_unless($request->user()->hasRole('dispatcher') && $call->kind === 'sos', 403);
        $data = $request->validate(['action' => ['required', 'in:reject,priority'], 'priority' => ['required_if:action,priority', 'integer', 'between:0,2']]);
        $this->queue->run(function () use ($request, $call, $data): void {
            $call->refresh();
            abort_if($call->ended_at || $call->accepted_at || $call->expires_at->isPast(), 409, 'This call is no longer waiting.');
            if ($data['action'] === 'reject') {
                abort_if($call->offered_dispatcher_key && $call->offered_dispatcher_key !== Account::keyFor($request->user()), 403);
                $call->update(['ended_at' => now(), 'queue_state' => 'ended', 'end_reason' => 'rejected']);
            } else {
                $call->update(['priority' => $data['priority']]);
            }
            Audit::record('MANAGE_SOS_CALL', "Dispatcher updated SOS call {$call->id}: {$data['action']}");
            $this->broadcastCall($call);
        });
        $this->queue->sync();

        return response()->json(['data' => $this->payload($call->refresh())])->header('Cache-Control', 'no-store');
    }

    private function claimSos(Request $request, CommunicationCall $call): void
    {
        $resident = Account::findByKey($call->resident_key);
        abort_unless($call->isGuest() || ($resident instanceof ResidentUser && $resident->status === 'active' && $resident->registration_status === 'accepted'), 409, 'The resident account is no longer available.');
        if ($call->dispatcher_key === null && $request->user()->hasRole('dispatcher')) {
            $this->queue->accept($request->user(), $call);
            Audit::record('ANSWER_SOS_CALL', "Dispatcher answered SOS call {$call->id}");
            $this->broadcastCall($call);
        }
    }

    private function broadcastCall(CommunicationCall $call): void
    {
        DB::afterCommit(function () use ($call): void {
            try {
                CommunicationCallUpdated::dispatch($call->id);
            } catch (\Throwable $error) {
                report($error);
            }
        });
    }

    private function authorizeParticipant(Request $request, CommunicationCall $call): void
    {
        abort_unless($call->uidFor($request->user()) !== null, 403);
    }

    private function authorizeAbility(Request $request, bool $write): void
    {
        /** @var User $user */
        $user = $request->user();
        $prefix = match ($user::roleName()) {
            'resident' => 'resident',
            'driver/responder' => 'driver',
            'dispatcher' => 'staff',
            default => null,
        };
        // Browser routes use Laravel session authentication and CSRF protection.
        // API routes still require their existing scoped Sanctum abilities.
        abort_unless($prefix && (! $request->is('api/*') || $user->tokenCan($prefix.($write ? '-write' : '-read'))), 403);
    }

    private function payload(CommunicationCall $call): array
    {
        $report = $call->report;
        $joinable = ! $call->ended_at && $call->expires_at->isFuture()
            && $report && in_array($report->status, ['pending', 'acknowledged', 'dispatched'], true)
            && $report->reporter instanceof ResidentUser
            && Account::keyFor($report->reporter) === $call->resident_key
            && $report->driver && Account::keyFor($report->driver) === $call->responder_key;

        $sos = $call->kind === 'sos';
        $resident = $sos ? Account::findByKey($call->resident_key) : null;
        if ($sos) {
            $joinable = ! $call->ended_at && $call->expires_at->isFuture() && ($call->isGuest() || ($resident?->status === 'active'
                && $resident?->registration_status === 'accepted'))
                && ($call->dispatcher_key === null || Account::findByKey($call->dispatcher_key)?->status === 'active');
        }
        $participants = [
            ['account_key' => $call->resident_key, 'role' => $call->isGuest() ? 'guest' : 'resident', 'uid' => 1],
            ['account_key' => $call->dispatcher_key, 'role' => 'dispatcher', 'uid' => 2],
        ];
        if (! $sos) {
            $participants[] = ['account_key' => $call->responder_key, 'role' => 'driver/responder', 'uid' => 3];
        }

        return [
            'queue_state' => $call->ended_at || $call->expires_at->isPast() ? 'ended' : ($call->accepted_at ? 'active' : $call->queue_state),
            'queue_position' => $this->queue->position($call), 'priority' => $call->priority,
            'offered_dispatcher_key' => $call->offered_dispatcher_key, 'end_reason' => $call->end_reason,
            'kind' => $call->kind ?? 'report', 'accepted_at' => $call->accepted_at?->toISOString(),
            'caller_type' => $call->isGuest() ? 'guest' : 'resident',
            'resident_name' => $call->isGuest() ? ($call->guest_name ?: 'Guest') : $resident?->full_name,
            'resident_contact' => $call->isGuest() ? $call->guest_contact : $resident?->contact_number,
            'resident_address' => $call->isGuest() ? $call->guest_address : ($resident ? collect([$resident->house_no, $resident->street, $resident->barangay, $resident->city, $resident->province])->filter()->implode(', ') : null),
            'id' => $call->id, 'report_id' => $call->emergency_report_id, 'report_reference' => $call->report?->reference, 'channel' => $call->channel(),
            'status' => $call->ended_at ? 'ended' : ($call->expires_at->isPast() ? 'expired' : 'active'),
            'joinable' => $joinable,
            'participants' => $participants,
            'created_at' => $call->created_at->toISOString(), 'expires_at' => $call->expires_at->toISOString(),
            'ended_at' => $call->ended_at?->toISOString(),
        ];
    }
}
