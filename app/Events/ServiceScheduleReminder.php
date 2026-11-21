<?php

namespace App\Events;

use App\Models\ServiceSchedule;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ServiceScheduleReminder implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<int, int>  $driverIds
     */
    public function __construct(
        public ServiceSchedule $schedule,
        public array $driverIds,
        public int $minutesUntil,
    ) {}

    public function broadcastOn(): array
    {
        return collect($this->driverIds)
            ->unique()
            ->values()
            ->map(fn (int $id): PrivateChannel => new PrivateChannel('drivers.'.$id))
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'schedule.reminder';
    }

    public function broadcastWith(): array
    {
        $this->schedule->loadMissing('resident:id,first_name,last_name');

        return [
            'id' => $this->schedule->getKey(),
            'service_type' => $this->schedule->service_details ?: $this->schedule->service_type,
            'preferred_date' => $this->schedule->preferred_date?->toDateString(),
            'preferred_time' => $this->schedule->preferred_time,
            'address' => $this->schedule->address,
            'resident' => $this->schedule->resident?->full_name,
            'minutes_until' => $this->minutesUntil,
            'title' => 'Upcoming scheduled service',
            'message' => sprintf(
                '%s for %s at %s (%s).',
                $this->schedule->service_details ?: $this->schedule->service_type,
                $this->schedule->resident?->full_name ?? 'Resident',
                $this->schedule->preferred_time,
                $this->schedule->address,
            ),
        ];
    }
}
