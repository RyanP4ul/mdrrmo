<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ResidentUser extends User
{
    protected $table = 'residents';

    public static function roleName(): string
    {
        return 'resident';
    }

    public function reports(): MorphMany
    {
        return $this->morphMany(EmergencyReport::class, 'reporter', 'reported_by_type', 'reported_by');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ServiceSchedule::class, 'resident_id');
    }
}
