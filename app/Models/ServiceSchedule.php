<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ServiceSchedule extends Model
{
    public const ACTIVE_STATUSES = ['confirmed', 'en-route', 'on-scene'];

    public const REQUEST_TYPES = ['Medical', 'Discharge', 'Others'];

    public const LEGACY_TYPES = ['Ambulance Standby', 'Medical Mission', 'Fire Safety Inspection', 'Disaster Drill', 'First Aid Training', 'Other'];

    protected $guarded = ['id'];

    protected $casts = ['preferred_date' => 'date', 'acknowledged_at' => 'datetime', 'started_at' => 'datetime', 'arrived_at' => 'datetime', 'completed_at' => 'datetime'];

    protected function casts(): array
    {
        return ['pickup_lat' => 'float', 'pickup_lng' => 'float', 'destination_lat' => 'float', 'destination_lng' => 'float'];
    }

    public function responders(): BelongsToMany
    {
        return $this->belongsToMany(DriverResponderUser::class, 'service_schedule_assignments', 'service_schedule_id', 'driver_id');
    }

    public function scheduledAt(): Carbon
    {
        return Carbon::parse($this->preferred_date->toDateString().' '.$this->preferred_time, config('mobile.timezone'));
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(ResidentUser::class, 'resident_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(ResponseTeam::class, 'response_team_id');
    }

    public function acknowledgedBy(): MorphTo
    {
        return $this->morphTo('acknowledgedBy', 'acknowledged_by_type', 'acknowledged_by');
    }

    public function driverTicket(): HasOne
    {
        return $this->hasOne(ServiceScheduleDriverTicket::class, 'service_schedule_id');
    }
}
