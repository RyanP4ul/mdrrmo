<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminNotification extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['kind', 'subject_id', 'title'];

    public function incidentReport(): BelongsTo
    {
        return $this->belongsTo(EmergencyReport::class, 'subject_id');
    }

    public function payload(): array
    {
        return [
            'id' => $this->getKey(),
            'title' => $this->title,
            'created_at' => $this->created_at->toISOString(),
            'url' => $this->kind === 'incident' && $this->incidentReport
                ? route('admin.reports.show', $this->subject_id, false)
                : null,
        ];
    }
}
