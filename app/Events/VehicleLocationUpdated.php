<?php

namespace App\Events;

use App\Models\TeamMember;
use App\Models\Vehicle;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class VehicleLocationUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public string $sentAt;

    public function __construct(public Vehicle $vehicle)
    {
        $this->sentAt = now()->toISOString();
    }

    public function broadcastOn(): array
    {
        $drivers = TeamMember::where('response_team_id', $this->vehicle->response_team_id)->where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active'))->whereHas('team', fn ($q) => $q->where('status', 'active'))->whereNotNull('user_id')->distinct()->pluck('user_id');

        return [new PrivateChannel('dispatch'), ...$drivers->map(fn ($id) => new PrivateChannel('drivers.'.$id))->all()];
    }

    public function broadcastAs(): string
    {
        return 'vehicle.moved';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->vehicle->getKey(),
            'plate' => $this->vehicle->plate_number, 'type' => $this->vehicle->vehicle_type,
            'teamName' => $this->vehicle->team?->team_name,
            'speed' => $this->vehicle->speed, 'heading' => $this->vehicle->heading,
            'assignedReportId' => $this->vehicle->assigned_report_id, 'sentAt' => $this->sentAt,
            'lat' => $this->vehicle->lat,
            'lng' => $this->vehicle->lng,
            'status' => $this->vehicle->status,
            'updatedAt' => $this->vehicle->last_updated_at?->toIso8601String(),
        ];
    }
}
