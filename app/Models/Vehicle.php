<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Vehicle extends Model
{
    public const TYPES = ['ambulance', 'fire-truck', 'rescue-van', 'police-car', 'utility-truck'];

    public const STATUSES = ['en-route', 'on-scene', 'available', 'returning', 'offline', 'maintenance'];

    protected $guarded = ['id'];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'last_updated_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(ResponseTeam::class, 'response_team_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(EmergencyReport::class, 'assigned_report_id');
    }

    public function maintenanceReports(): HasMany
    {
        return $this->hasMany(MaintenanceReport::class);
    }

    public function openMaintenanceReports(): HasMany
    {
        return $this->hasMany(MaintenanceReport::class)
            ->whereIn('status', MaintenanceReport::OPEN_STATUSES);
    }

    public function getPhotoUrlAttribute(): ?string
    {
        if (! $this->photo_path) {
            return null;
        }
        if (str_starts_with($this->photo_path, 'http://') || str_starts_with($this->photo_path, 'https://') || str_starts_with($this->photo_path, '/')) {
            return $this->photo_path;
        }

        return Storage::disk('public')->url($this->photo_path);
    }

    public function effectiveMaintenanceWarning(): ?string
    {
        if ($this->maintenance_warning && trim($this->maintenance_warning) !== '') {
            return trim($this->maintenance_warning);
        }
        if ($this->status === 'maintenance') {
            return 'This vehicle is currently under maintenance and unavailable for dispatch.';
        }
        $open = $this->relationLoaded('openMaintenanceReports')
            ? $this->openMaintenanceReports->first()
            : $this->openMaintenanceReports()->latest()->first();

        if ($open) {
            return "Open {$open->severity} maintenance report ({$open->issue_type}): {$open->description}";
        }

        return null;
    }

    public function toTeamPayload(?int $activeVehicleId = null): array
    {
        $warning = $this->effectiveMaintenanceWarning();
        $openCount = $this->relationLoaded('openMaintenanceReports')
            ? $this->openMaintenanceReports->count()
            : $this->openMaintenanceReports()->count();

        return [
            'id' => $this->id,
            'name' => $this->name ?: ucfirst(str_replace('-', ' ', $this->vehicle_type)).' '.$this->plate_number,
            'type' => $this->vehicle_type,
            'vehicle_type' => $this->vehicle_type,
            'number' => $this->plate_number,
            'plate_number' => $this->plate_number,
            'vehicle_number' => $this->plate_number,
            'status' => $this->status,
            'vehicle_status' => $this->status,
            'photo_url' => $this->photo_url,
            'maintenance_warning' => $warning,
            'has_maintenance_warning' => $warning !== null || $this->status === 'maintenance' || $openCount > 0,
            'open_maintenance_count' => $openCount,
            'is_active' => $activeVehicleId !== null && $activeVehicleId === $this->id,
            'can_select' => $this->status !== 'maintenance' && $this->assigned_report_id === null,
        ];
    }
}
