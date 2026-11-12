<?php

namespace App\Policies;

use App\Models\EmergencyReport;
use App\Models\User;

class EmergencyReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin', 'dispatcher');
    }

    public function view(User $user, EmergencyReport $report): bool
    {
        return $user->hasRole('admin', 'dispatcher')
            || ($user->hasRole('driver/responder') && $this->isAssignedDriverOrTeamMember($user, $report))
            || ($user->hasRole('resident')
                && $report->reported_by === $user->getKey()
                && $report->reported_by_type === $user->getMorphClass());
    }

    public function update(User $user, EmergencyReport $report): bool
    {
        return $user->hasRole('admin');
    }

    public function dispatch(User $user, EmergencyReport $report): bool
    {
        return $user->hasRole('dispatcher')
            && in_array($report->status, ['pending', 'acknowledged', 'dispatched'], true);
    }

    public function acknowledge(User $user, EmergencyReport $report): bool
    {
        return $user->hasRole('dispatcher') && $report->status === 'pending';
    }

    public function invalidate(User $user, EmergencyReport $report): bool
    {
        return $user->hasRole('dispatcher')
            && in_array($report->status, ['pending', 'acknowledged', 'dispatched'], true);
    }

    public function updateAsDriver(User $user, EmergencyReport $report): bool
    {
        return $user->hasRole('driver/responder')
            && $this->isAssignedDriverOrTeamMember($user, $report)
            && in_array($report->status, ['dispatched', 'resolved'], true);
    }

    public function editTripAsDriver(User $user, EmergencyReport $report): bool
    {
        return $user->hasRole('driver/responder')
            && $report->assigned_driver_id === $user->getKey()
            && (! $report->team?->member_roles_enabled || ($report->team->status === 'active' && $report->team->members()
                ->where('user_id', $user->getKey())->where('status', 'active')->where('role', 'Driver')->exists()))
            && in_array($report->status, ['dispatched', 'resolved'], true);
    }

    public function advanceAsDriver(User $user, EmergencyReport $report): bool
    {
        return $user->hasRole('driver/responder')
            && $report->assigned_driver_id === $user->getKey()
            && (! $report->team?->member_roles_enabled || ($report->team->status === 'active' && $report->team->members()
                ->where('user_id', $user->getKey())->where('status', 'active')->where('role', 'Driver')->exists()))
            && $report->status === 'dispatched';
    }

    private function isAssignedDriverOrTeamMember(User $user, EmergencyReport $report): bool
    {
        if ($report->assigned_driver_id === $user->getKey()) {
            return true;
        }

        return $report->response_team_id !== null
            && $report->team?->status === 'active'
            && $report->team->members()
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->exists();
    }
}
