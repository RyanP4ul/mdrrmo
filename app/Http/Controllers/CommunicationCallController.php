<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\CommunicationCall;
use App\Models\EmergencyReport;
use App\Models\ResidentUser;
use App\Models\User;
use App\Services\AgoraTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunicationCallController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAbility($request, false);
        $calls = CommunicationCall::with('report')->forParticipant($request->user())
            ->whereNull('ended_at')->where('expires_at', '>', now())
            ->latest()->paginate(20);

        $calls->through(fn (CommunicationCall $call) => $this->payload($call));

        return response()->json($calls)->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, AgoraTokenService $tokens): JsonResponse
    {
        $this->authorizeAbility($request, true);
        abort_unless($request->user()->hasRole('dispatcher'), 403);
        $data = $request->validate(['report_id' => ['required', 'integer', 'exists:emergency_reports,id']]);
        $tokens->assertConfigured();

        $call = DB::transaction(function () use ($data, $request): CommunicationCall {
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

        return response()->json(['data' => $this->payload($call)], 201)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, CommunicationCall $call): JsonResponse
    {
        $this->authorizeAbility($request, false);
        $this->authorizeParticipant($request, $call);

        return response()->json(['data' => $this->payload($call)])->header('Cache-Control', 'no-store');
    }

    public function token(Request $request, CommunicationCall $call, AgoraTokenService $tokens): JsonResponse
    {
        $this->authorizeAbility($request, true);
        $this->authorizeParticipant($request, $call);

        return DB::transaction(function () use ($request, $call, $tokens): JsonResponse {
            $call = CommunicationCall::query()->lockForUpdate()->findOrFail($call->id);
            abort_if($call->ended_at || $call->expires_at->isPast(), 409, 'This call has ended or expired.');
            $report = $call->report;
            abort_unless($report && in_array($report->status, ['pending', 'acknowledged', 'dispatched'], true)
                && $report->reporter instanceof ResidentUser
                && Account::keyFor($report->reporter) === $call->resident_key
                && $report->driver && Account::keyFor($report->driver) === $call->responder_key,
                409, 'The report participants or status changed. End this call and create a new one.');
            foreach ([$call->resident_key, $call->dispatcher_key, $call->responder_key] as $key) {
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
        abort_unless(Account::keyFor($request->user()) === $call->dispatcher_key, 403);
        CommunicationCall::whereKey($call->id)->whereNull('ended_at')->update(['ended_at' => now()]);

        return response()->json(['data' => $this->payload($call->refresh())]);
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

        return [
            'id' => $call->id, 'report_id' => $call->emergency_report_id, 'report_reference' => $call->report?->reference, 'channel' => $call->channel(),
            'status' => $call->ended_at ? 'ended' : ($call->expires_at->isPast() ? 'expired' : 'active'),
            'joinable' => $joinable,
            'participants' => [
                ['account_key' => $call->resident_key, 'role' => 'resident', 'uid' => 1],
                ['account_key' => $call->dispatcher_key, 'role' => 'dispatcher', 'uid' => 2],
                ['account_key' => $call->responder_key, 'role' => 'driver/responder', 'uid' => 3],
            ],
            'created_at' => $call->created_at->toISOString(), 'expires_at' => $call->expires_at->toISOString(),
            'ended_at' => $call->ended_at?->toISOString(),
        ];
    }
}
