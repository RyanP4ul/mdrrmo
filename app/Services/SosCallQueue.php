<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CommunicationCall;
use App\Models\DispatcherUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Durable queue, serialized across workers so one caller/dispatcher is reserved once. */
class SosCallQueue
{
    public function run(callable $operation): mixed
    {
        return DB::transaction(function () use ($operation): mixed {
            DB::table('communication_queue_lock')->where('id', 1)->lockForUpdate()->first();

            return $operation();
        }, 3);
    }

    public function heartbeat(User $user, ?bool $accepting = null): void
    {
        if (! $user->hasRole('dispatcher')) {
            return;
        }
        $key = Account::keyFor($user);
        $this->run(function () use ($key, $accepting): void {
            $presence = DB::table('communication_dispatchers')->where('account_key', $key)->first();
            DB::table('communication_dispatchers')->updateOrInsert(['account_key' => $key], [
                'last_seen_at' => now(), 'accepting' => $accepting ?? ($presence?->accepting ?? true),
            ]);
        });
    }

    public function availability(string $key): string
    {
        $presence = DB::table('communication_dispatchers')->where('account_key', $key)
            ->where('last_seen_at', '>', now()->subSeconds(20))->where('accepting', true)->first();
        if (! $presence || Account::findByKey($key)?->status !== 'active') {
            return 'Offline';
        }

        return CommunicationCall::where('dispatcher_key', $key)->whereNull('ended_at')
            ->where('expires_at', '>', now())->exists() ? 'Busy' : 'Available';
    }

    public function dispatchers(): array
    {
        return DispatcherUser::where('status', 'active')->orderBy('id')->get()->map(fn ($user) => [
            'account_key' => Account::keyFor($user), 'name' => $user->full_name,
            'status' => $this->availability(Account::keyFor($user)),
        ])->all();
    }

    public function sync(): void
    {
        $this->run(function (): void {
            $calls = CommunicationCall::where('kind', 'sos')->whereNull('ended_at')
                ->orderBy('priority')->orderBy('created_at')->orderBy('id')->get();
            foreach ($calls as $call) {
                $callerGone = $call->caller_seen_at && $call->caller_seen_at->lt(now()->subSeconds(45));
                $dispatcherGone = $call->accepted_at && (($call->dispatcher_seen_at && $call->dispatcher_seen_at->lt(now()->subSeconds(45))) || ! DB::table('communication_dispatchers')
                    ->where('account_key', $call->dispatcher_key)->where('last_seen_at', '>', now()->subSeconds(45))->exists());
                if ($call->expires_at->isPast() || $callerGone || $dispatcherGone) {
                    $call->update(['ended_at' => now(), 'queue_state' => 'ended',
                        'end_reason' => $callerGone || $dispatcherGone ? 'disconnected' : 'timeout']);

                    continue;
                }
                if ($call->accepted_at) {
                    continue;
                }
                if ($call->offered_dispatcher_key && ($this->availability($call->offered_dispatcher_key) !== 'Available'
                    || $call->offered_at?->lt(now()->subSeconds((int) config('agora.sos_ring_seconds', 120))))) {
                    $call->update(['last_offered_dispatcher_key' => $this->availability($call->offered_dispatcher_key) === 'Available' ? $call->offered_dispatcher_key : null,
                        'offered_dispatcher_key' => null, 'offered_at' => null, 'queue_state' => 'waiting']);
                }
            }
            $available = collect($this->dispatchers())->where('status', 'Available')->pluck('account_key');
            $reserved = CommunicationCall::where('kind', 'sos')->whereNull('ended_at')->where('expires_at', '>', now())
                ->whereNotNull('offered_dispatcher_key')->pluck('offered_dispatcher_key');
            $available = $available->diff($reserved)->values();
            foreach (CommunicationCall::where('kind', 'sos')->whereNull('ended_at')->whereNull('accepted_at')
                ->whereNull('offered_dispatcher_key')->where('expires_at', '>', now())
                ->orderBy('priority')->orderBy('created_at')->orderBy('id')->get() as $call) {
                $key = $available->first(fn ($key) => $key !== $call->last_offered_dispatcher_key);
                if ($key === null) {
                    continue;
                }
                $call->update(['offered_dispatcher_key' => $key, 'offered_at' => now(), 'queue_state' => 'ringing']);
                $available = $available->reject(fn ($value) => $value === $key)->values();
            }
        });
    }

    public function accept(User $user, CommunicationCall $call): void
    {
        $key = Account::keyFor($user);
        abort_if($call->offered_dispatcher_key && $call->offered_dispatcher_key !== $key, 409, 'This call is routed to another dispatcher.');
        abort_if(CommunicationCall::where('dispatcher_key', $key)->whereNull('ended_at')
            ->where('expires_at', '>', now())->whereKeyNot($call->id)->exists(), 409, 'End your current call before answering another.');
        abort_unless($this->availability($key) !== 'Offline', 409, 'Set your availability to Available before answering.');
        // An unassigned call may be accepted manually for emergency triage.
        $call->update(['dispatcher_key' => $key, 'accepted_at' => now(), 'dispatcher_seen_at' => now(), 'queue_state' => 'active',
            'offered_dispatcher_key' => null, 'expires_at' => now()->addMinutes(max(1, min(1440, (int) config('agora.call_minutes'))))]);
    }

    public function position(CommunicationCall $call): ?int
    {
        if ($call->kind !== 'sos' || $call->ended_at || $call->accepted_at || $call->offered_dispatcher_key) {
            return null;
        }
        $ids = CommunicationCall::where('kind', 'sos')->whereNull('ended_at')->whereNull('accepted_at')
            ->whereNull('offered_dispatcher_key')->where('expires_at', '>', now())
            ->orderBy('priority')->orderBy('created_at')->orderBy('id')->pluck('id');
        $position = $ids->search($call->id);

        return $position === false ? null : $position + 1;
    }
}
