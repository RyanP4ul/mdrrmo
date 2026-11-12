<?php

namespace App\Events;

use App\Models\TeamMember;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TeamRoleUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $teamId) {}

    public function broadcastOn(): array
    {
        return TeamMember::where('response_team_id', $this->teamId)->whereNotNull('user_id')->pluck('user_id')->unique()
            ->map(fn ($id) => new PrivateChannel('drivers.'.$id))->values()->all();
    }

    public function broadcastAs(): string
    {
        return 'team.role.updated';
    }

    public function broadcastWith(): array
    {
        return ['team_id' => $this->teamId];
    }
}
