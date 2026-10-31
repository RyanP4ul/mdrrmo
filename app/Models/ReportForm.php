<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportForm extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['driver_section' => 'array', 'emergency_section' => 'array'];

    public function report(): BelongsTo { return $this->belongsTo(EmergencyReport::class, 'emergency_report_id'); }
}
