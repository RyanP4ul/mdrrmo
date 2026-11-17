<?php

namespace App\Events;

use App\Models\ServiceSchedule;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ServiceScheduleUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public ServiceSchedule $schedule,
        public bool $created = false,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('dispatch')];
    }

    public function broadcastAs(): string
    {
        return 'schedule.updated';
    }

    public function broadcastWith(): array
    {
        $this->schedule->loadMissing('resident:id,first_name,last_name');

        return [
            'id' => $this->schedule->getKey(),
            'status' => $this->schedule->status,
            'service_type' => $this->schedule->service_type,
            'preferred_date' => $this->schedule->preferred_date?->toDateString(),
            'preferred_time' => $this->schedule->preferred_time,
            'address' => $this->schedule->address,
            'resident_name' => $this->schedule->resident?->full_name,
            'created' => $this->created,
        ];
    }
}
