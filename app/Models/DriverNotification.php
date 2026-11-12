<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverNotification extends Model
{
    protected $guarded = ['id'];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ServiceSchedule::class);
    }

    public static function receipt(int $driver, EmergencyReport $report, string $kind, string $title): void
    {
        static::firstOrCreate(['driver_id' => $driver, 'report_id' => $report->id, 'kind' => $kind], [
            'title' => $title, 'message' => "{$title}: {$report->reference} at {$report->location}.",
        ]);
    }

    public static function scheduleReceipt(int $driver, ServiceSchedule $schedule, string $teamName): void
    {
        $schedule->responders()->syncWithoutDetaching([$driver]);
        static::firstOrCreate(['driver_id' => $driver, 'schedule_id' => $schedule->id, 'kind' => 'schedule_assigned'], [
            'title' => 'Service schedule assigned',
            'message' => "{$schedule->service_type} on {$schedule->preferred_date->toFormattedDateString()} at {$schedule->preferred_time}, {$schedule->address}. Assigned team: {$teamName}.",
        ]);
    }
}
