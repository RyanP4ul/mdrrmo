<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverLocation extends Model
{
    protected $primaryKey = 'driver_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'accuracy' => 'float', 'speed' => 'integer', 'heading' => 'integer', 'observed_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverResponderUser::class, 'driver_id');
    }

    public function mapData(?string $sentAt = null): array
    {
        $this->loadMissing('driver.activeAssignments.trip', 'driver.activeAssignments.team', 'driver.activeMemberships.team');
        $report = $this->driver->activeAssignments->first();
        $trip = $report?->trip;
        $status = $this->expires_at->isPast() || $this->driver->status !== 'active'
            ? 'offline' : ($report ? ($trip?->on_scene_at ? 'on-scene' : ($trip?->started_at ? 'en-route' : 'dispatched')) : 'available');
        $activeTeam = $report?->team ?? $this->driver->activeMemberships->first()?->team;
        $membership = $activeTeam
            ? ($this->driver->activeMemberships->firstWhere('response_team_id', $activeTeam->id) ?? $this->driver->activeMemberships->first())
            : $this->driver->activeMemberships->first();

        return [
            'id' => $this->driver_id, 'name' => $this->driver->full_name,
            'teamId' => $activeTeam?->id,
            'teamName' => $activeTeam?->team_name,
            'memberRole' => $membership?->role,
            'lat' => $this->lat, 'lng' => $this->lng, 'accuracy' => $this->accuracy,
            'speed' => $this->speed, 'heading' => $this->heading, 'status' => $status,
            'assignedReportId' => $report?->id, 'report' => $report?->only('id', 'reference', 'location'),
            'updatedAt' => $this->observed_at->toISOString(), 'expiresAt' => $this->expires_at->toISOString(),
            'sentAt' => $sentAt ?? now()->toISOString(),
        ];
    }
}
