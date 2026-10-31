<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ResidentNotification extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['read_at' => 'datetime'];

    public function related(): MorphTo { return $this->morphTo(); }
}
