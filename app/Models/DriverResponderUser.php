<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class DriverResponderUser extends User
{
    protected $table = 'drivers';

    public function activeAssignments(): HasMany
    {
        return $this->hasMany(EmergencyReport::class, 'assigned_driver_id')->whereIn('status', ['acknowledged', 'dispatched'])->orderBy('id');
    }

    public function activeMemberships(): HasMany
    {
        return $this->hasMany(TeamMember::class, 'user_id')->where('status', 'active')->whereHas('team', fn ($query) => $query->where('status', 'active'))->orderBy('id');
    }

    public static function roleName(): string
    {
        return 'driver/responder';
    }
}
