<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vehicle extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['lat' => 'float', 'lng' => 'float', 'last_updated_at' => 'datetime'];

    public function team(): BelongsTo   { return $this->belongsTo(ResponseTeam::class, 'response_team_id'); }
    public function report(): BelongsTo { return $this->belongsTo(EmergencyReport::class, 'assigned_report_id'); }
}
