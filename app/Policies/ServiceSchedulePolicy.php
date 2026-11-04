<?php

namespace App\Policies;

use App\Models\ServiceSchedule;
use App\Models\User;

class ServiceSchedulePolicy
{
    public function cancel(User $user, ServiceSchedule $schedule): bool
    {
        return $user->hasRole('resident')
            && $schedule->resident_id === $user->getKey()
            && in_array($schedule->status, ['pending', 'confirmed'], true);
    }
}
