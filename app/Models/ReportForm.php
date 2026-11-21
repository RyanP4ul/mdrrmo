<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportForm extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['driver_section' => 'array', 'emergency_section' => 'array'];

    public static function mergeEmergencySection(array $saved, array $changes): array
    {
        foreach (['vitalSigns', 'endorsedBy', 'endorsedTo'] as $field) {
            if (is_array($changes[$field] ?? null)) {
                $previous = is_array($saved[$field] ?? null) ? $saved[$field] : [];
                $changes[$field] = array_merge($previous, $changes[$field]);
            }
        }

        return array_merge($saved, $changes);
    }

    public function report(): BelongsTo { return $this->belongsTo(EmergencyReport::class, 'emergency_report_id'); }
}
