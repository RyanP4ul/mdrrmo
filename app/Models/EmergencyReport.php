<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class EmergencyReport extends Model
{
    protected $guarded = ['id'];

    public static function generateReference(): string
    {
        return 'RPT-'.now()->format('Y').'-'.Str::upper(Str::random(8));
    }

    protected function casts(): array
    {
        return [
            'victim_info' => 'array',
            'witness_info' => 'array',
            'lat' => 'float',
            'lng' => 'float',
            'withdrawn_at' => 'datetime',
            'reported_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function incidentType(): BelongsTo
    {
        return $this->belongsTo(IncidentType::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(ResponseTeam::class, 'response_team_id');
    }

    public function reporter(): MorphTo
    {
        return $this->morphTo('reporter', 'reported_by_type', 'reported_by');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverResponderUser::class, 'assigned_driver_id');
    }

    public function acknowledgedBy(): MorphTo
    {
        return $this->morphTo('acknowledgedBy', 'acknowledged_by_type', 'acknowledged_by');
    }

    public function trip(): HasOne
    {
        return $this->hasOne(DispatchTrip::class);
    }

    public function form(): HasOne
    {
        return $this->hasOne(ReportForm::class);
    }

    public function scopeActive($q)
    {
        return $q->whereIn('status', ['pending', 'acknowledged', 'dispatched']);
    }

    public function scopeWithDriverForm($query)
    {
        return $query->whereHas('form', fn ($form) => $form->where(function ($section) {
            foreach ([
                'tripDate', 'driverName', 'governmentCardPlateNo', 'authorizedPassenger',
                'passengerName', 'placeVisitedInspected', 'purpose', 'balanceInTank',
                'issuedByOffice', 'purchasedDuringTrip', 'totalAvailableFuel',
                'fuelUsedDuringTrip', 'remainingFuel', 'gearOilUsed',
            ] as $field) {
                $section->orWhere(fn ($value) => $value
                    ->whereNotNull('driver_section->'.$field)
                    ->where('driver_section->'.$field, '!=', ''));
            }
        }));
    }

    public function scopeFilter($q, array $f)
    {
        return $q
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('reference', 'like', "%{$s}%")
                ->orWhere('location', 'like', "%{$s}%")
                ->orWhereHas('incidentType', fn ($q) => $q->where('name', 'like', "%{$s}%"))))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('incident_type_id', $v));
    }
}
