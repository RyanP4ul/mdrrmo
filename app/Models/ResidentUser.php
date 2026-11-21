<?php

namespace App\Models;

use Carbon\CarbonInterface;
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

    public function victimInformation(CarbonInterface $reportedAt): array
    {
        $reportDate = $reportedAt->copy()->startOfDay();
        $birthDate = $this->date_of_birth?->copy()->shiftTimezone($reportDate->getTimezone());

        return array_filter([
            'name' => $this->full_name,
            'age' => $birthDate !== null && $birthDate->lte($reportDate)
                ? (int) $birthDate->diffInYears($reportDate) : null,
            'sex' => $this->sex,
        ], fn ($value): bool => $value !== null && $value !== '');
    }
}
