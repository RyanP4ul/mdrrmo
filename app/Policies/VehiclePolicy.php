<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;

class VehiclePolicy
{
    public function update(User $user, Vehicle $vehicle): bool
    {
        return $user->hasRole('driver/responder') && $user->status === 'active'
            && $vehicle->team?->status === 'active'
            && $vehicle->team->members()->where('user_id', $user->getKey())->where('status', 'active')
                ->whereIn('role', ['Driver', 'driver', 'Driver/Responder', 'driver/responder'])->exists()
            && ($vehicle->assigned_report_id === null || $vehicle->report?->assigned_driver_id === $user->getKey());
    }
}
