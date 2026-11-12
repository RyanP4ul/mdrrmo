<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResponseTeam extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['specializations' => 'array', 'member_roles_enabled' => 'boolean'];

    public function members(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(EmergencyReport::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function activeVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'active_vehicle_id');
    }

    public function maintenanceReports(): HasMany
    {
        return $this->hasMany(MaintenanceReport::class);
    }

    public function resolveActiveVehicle(): ?Vehicle
    {
        $vehicles = $this->relationLoaded('vehicles') ? $this->vehicles : $this->vehicles()->get();
        if ($this->active_vehicle_id) {
            $found = $vehicles->firstWhere('id', $this->active_vehicle_id);
            if ($found) {
                return $found;
            }
        }

        return $vehicles->firstWhere('status', 'available') ?? $vehicles->first();
    }
}
