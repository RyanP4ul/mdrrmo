<?php

namespace App\Events;

use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\TeamMember;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class EmergencyReportUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public EmergencyReport $report,
        public ?int $previousDriverId = null,
        public bool $created = false,
    ) {}

    public function broadcastOn(): array
    {
        $teamMemberIds = $this->report->response_team_id
            ? TeamMember::where('response_team_id', $this->report->response_team_id)
                ->where('status', 'active')
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->all()
            : [];

        $ids = DriverResponderUser::where('status', 'active')
            ->whereIn('id', array_unique(array_filter([$this->report->assigned_driver_id, $this->previousDriverId, ...$teamMemberIds])))
            ->pluck('id');

        return [new PrivateChannel('dispatch'), ...$ids->map(fn ($id) => new PrivateChannel('drivers.'.$id))->all()];
    }

    public function broadcastAs(): string
    {
        return 'report.updated';
    }

    public function broadcastWith(): array
    {
        $this->report->loadMissing('incidentType:id,name');

        return [
            'id' => $this->report->getKey(),
            'reference' => $this->report->reference,
            'status' => $this->report->status,
            'priority' => $this->report->priority,
            'location' => $this->report->location,
            'reporter_name' => $this->report->reporter_name,
            'incident_type' => $this->report->incidentType?->name,
            'created' => $this->created,
        ];
    }
}
