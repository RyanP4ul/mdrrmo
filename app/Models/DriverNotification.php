<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverNotification extends Model
{
    protected $guarded = ['id'];

    public static function receipt(int $driver, EmergencyReport $report, string $kind, string $title): void
    {
        static::firstOrCreate(['driver_id' => $driver, 'report_id' => $report->id, 'kind' => $kind], [
            'title' => $title, 'message' => "{$title}: {$report->reference} at {$report->location}.",
        ]);
    }
}
