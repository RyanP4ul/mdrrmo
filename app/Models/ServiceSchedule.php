<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ServiceSchedule extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['preferred_date' => 'date', 'acknowledged_at' => 'datetime'];

    public function resident(): BelongsTo { return $this->belongsTo(ResidentUser::class, 'resident_id'); }
    public function acknowledgedBy(): MorphTo { return $this->morphTo('acknowledgedBy', 'acknowledged_by_type', 'acknowledged_by'); }
}
