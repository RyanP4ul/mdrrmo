<?php

namespace App\Policies;

use App\Models\ResponseTeam;
use App\Models\User;
use App\Models\Vehicle;

class VehiclePolicy
{
    public function update(User $user, Vehicle $vehicle): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->hasRole('driver/responder') && $user->status === 'active'
            && $vehicle->team?->status === 'active'
            && $vehicle->status !== 'maintenance'
            && $vehicle->team->members()->where('user_id', $user->getKey())->where('status', 'active')
                ->whereIn('role', $vehicle->team->member_roles_enabled ? ['Driver', 'driver'] : ['Driver', 'driver', 'Driver/Responder', 'driver/responder'])->exists()
            && ($vehicle->assigned_report_id === null || $vehicle->report?->assigned_driver_id === $user->getKey());
    }

    public function reportMaintenance(User $user, Vehicle $vehicle): bool
    {
        return $user->hasRole('driver/responder') && $user->status === 'active'
            && $vehicle->team?->status === 'active'
            && $vehicle->team->members()->where('user_id', $user->getKey())->where('status', 'active')
                ->whereIn('role', $vehicle->team->member_roles_enabled ? ['Driver', 'driver'] : ['Driver', 'driver', 'Driver/Responder', 'driver/responder'])->exists();
    }

    public function switchTeamVehicle(User $user, Vehicle $vehicle, ResponseTeam $team): bool
    {
        return $user->hasRole('driver/responder') && $user->status === 'active'
            && $team->status === 'active'
            && $vehicle->response_team_id === $team->id
            && $team->members()->where('user_id', $user->getKey())->where('status', 'active')
                ->whereIn('role', $team->member_roles_enabled ? ['Driver', 'driver'] : ['Driver', 'driver', 'Driver/Responder', 'driver/responder'])->exists();
    }
}
