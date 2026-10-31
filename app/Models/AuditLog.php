<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;
    protected $guarded = ['id'];
    protected $casts = ['created_at' => 'datetime'];

    public function user(): MorphTo { return $this->morphTo(); }
}
