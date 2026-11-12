<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceReport extends Model
{
    public const ISSUE_TYPES = [
        'Engine / Mechanical',
        'Brakes / Tires',
        'Electrical / Siren / Lights',
        'Medical Equipment',
        'Preventive / Scheduled',
        'Other',
    ];

    public const OPEN_STATUSES = [
        'pending',
        'acknowledged',
        'approved',
        'in_maintenance',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'mileage' => 'integer',
        'recommend_out_of_service' => 'boolean',
        'reviewed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(ResponseTeam::class, 'response_team_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(DriverResponderUser::class, 'reported_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by');
    }
}
