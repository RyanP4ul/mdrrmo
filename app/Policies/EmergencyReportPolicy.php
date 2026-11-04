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
            || ($user->hasRole('driver/responder') && $report->assigned_driver_id === $user->getKey())
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
            && $report->assigned_driver_id === $user->getKey()
            && in_array($report->status, ['dispatched', 'resolved'], true);
    }

    public function advanceAsDriver(User $user, EmergencyReport $report): bool
    {
        return $user->hasRole('driver/responder')
            && $report->assigned_driver_id === $user->getKey()
            && $report->status === 'dispatched';
    }
}
