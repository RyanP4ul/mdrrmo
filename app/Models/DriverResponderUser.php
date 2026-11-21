<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class DriverResponderUser extends User
{
    public const SHIFT_TEAMS = ['day', 'night'];

    protected $table = 'drivers';

    public function scopeForShiftTeam($query, string $shiftTeam)
    {
        return $query->where('shift_team', $shiftTeam);
    }

    public function activeAssignments(): HasMany
    {
        return $this->hasMany(EmergencyReport::class, 'assigned_driver_id')->whereIn('status', ['acknowledged', 'dispatched'])->orderBy('id');
    }

    public function activeMemberships(): HasMany
    {
        return $this->hasMany(TeamMember::class, 'user_id')->where('status', 'active')->whereHas('team', fn ($query) => $query->where('status', 'active'))->orderBy('id');
    }

    public function hasActiveEmergencyDispatch(): bool
    {
        return EmergencyReport::query()->assignedToResponder($this->getKey())
            ->whereIn('status', ['acknowledged', 'dispatched'])->exists();
    }

    public static function roleName(): string
    {
        return 'driver/responder';
    }
}
