<?php

namespace App\Events;

use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class EmergencyReportUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public EmergencyReport $report, public ?int $previousDriverId = null) {}

    public function broadcastOn(): array
    {
        $ids = DriverResponderUser::where('status', 'active')->whereIn('id', array_filter([$this->report->assigned_driver_id, $this->previousDriverId]))->pluck('id');

        return [new PrivateChannel('dispatch'), ...$ids->map(fn ($id) => new PrivateChannel('drivers.'.$id))->all()];
    }

    public function broadcastAs(): string
    {
        return 'report.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->report->getKey(),
            'status' => $this->report->status,
        ];
    }
}
