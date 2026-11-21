<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class EmergencyReport extends Model
{
    use Archivable;

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
        return $this->belongsTo(IncidentType::class)->withTrashed();
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(ResponseTeam::class, 'response_team_id')->withTrashed();
    }

    public function parentReport(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_report_id')->withTrashed();
    }

    public function backupReports(): HasMany
    {
        return $this->hasMany(self::class, 'parent_report_id');
    }

    public function requestedByTeam(): BelongsTo
    {
        return $this->belongsTo(ResponseTeam::class, 'requested_by_team_id')->withTrashed();
    }

    public function dispatchAssignments(): HasMany
    {
        return $this->hasMany(DispatchAssignment::class);
    }

    public function activeDispatchAssignment(): HasOne
    {
        return $this->hasOne(DispatchAssignment::class)
            ->where('status', 'dispatched')
            ->latestOfMany();
    }

    public function assignedVehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class, 'assigned_report_id');
    }

    public function isBackupRequest(): bool
    {
        return $this->parent_report_id !== null
            || $this->requested_vehicle_type !== null
            || str_starts_with((string) $this->description, '[BACKUP REQUEST');
    }

    public function resolvedVehicle(): ?Vehicle
    {
        $assigned = $this->relationLoaded('assignedVehicle')
            ? $this->assignedVehicle
            : $this->assignedVehicle()->first();
        if ($assigned) {
            return $assigned;
        }

        $activeAssignment = $this->relationLoaded('activeDispatchAssignment')
            ? $this->activeDispatchAssignment
            : $this->activeDispatchAssignment()->with('vehicle')->first();
        if ($activeAssignment?->vehicle) {
            return $activeAssignment->vehicle;
        }

        return $this->team?->resolveActiveVehicle();
    }

    public function reporter(): MorphTo
    {
        return $this->morphTo('reporter', 'reported_by_type', 'reported_by')->withTrashed();
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverResponderUser::class, 'assigned_driver_id')->withTrashed();
    }

    public function acknowledgedBy(): MorphTo
    {
        return $this->morphTo('acknowledgedBy', 'acknowledged_by_type', 'acknowledged_by')->withTrashed();
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

    public function scopeAssignedToResponder(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $assigned) use ($userId): void {
            $assigned->where('assigned_driver_id', $userId)
                ->orWhereHas('team', fn (Builder $team) => $team
                    ->where('status', 'active')
                    ->whereHas('members', fn (Builder $member) => $member->where('user_id', $userId)->where('status', 'active')));
        });
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
