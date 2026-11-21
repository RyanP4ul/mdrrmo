<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceScheduleDriverTicket extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'driver_section' => 'array',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ServiceSchedule::class, 'service_schedule_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverResponderUser::class, 'driver_id')->withTrashed();
    }
}
