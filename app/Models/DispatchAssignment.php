<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispatchAssignment extends Model
{
    public const TYPES = ['primary', 'backup'];

    public const STATUSES = ['dispatched', 'completed', 'cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(EmergencyReport::class, 'emergency_report_id')->withTrashed();
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(ResponseTeam::class, 'response_team_id')->withTrashed();
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id')->withTrashed();
    }
}
