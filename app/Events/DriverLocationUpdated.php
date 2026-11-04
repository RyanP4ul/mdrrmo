<?php

namespace App\Events;

use App\Models\DriverLocation;
use App\Models\TeamMember;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class DriverLocationUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public string $sentAt;

    public function __construct(public DriverLocation $location)
    {
        $this->sentAt = now()->toISOString();
    }

    public function broadcastOn(): array
    {
        $teams = TeamMember::where('user_id', $this->location->driver_id)->where('status', 'active')
            ->whereHas('team', fn ($query) => $query->where('status', 'active'))->pluck('response_team_id');
        $recipients = TeamMember::whereIn('response_team_id', $teams)->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))->pluck('user_id')
            ->push($this->location->driver_id)->filter()->unique();

        return [new PrivateChannel('dispatch'), ...$recipients->map(fn ($id) => new PrivateChannel('drivers.'.$id))->all()];
    }

    public function broadcastAs(): string
    {
        return 'driver.moved';
    }

    public function broadcastWith(): array
    {
        return $this->location->mapData($this->sentAt);
    }
}
