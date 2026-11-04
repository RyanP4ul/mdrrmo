<?php

namespace App\Services;

use App\Events\EmergencyReportUpdated;
use App\Models\AuditLog;
use App\Models\DriverNotification;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\Hospital;
use App\Models\ResidentNotification;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Models\TeamMember;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DispatchService
{
    /** Least-busy active team that is currently on shift; falls back to any active team. */
    public function autoAssignTeam(bool $lockForUpdate = false): ?ResponseTeam
    {
        $busy = fn ($q) => $q->whereIn('status', ['acknowledged', 'dispatched']);

        $activeTeamIds = null;
        if ($lockForUpdate) {
            $activeTeamIds = ResponseTeam::query()
                ->where('status', 'active')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            if ($activeTeamIds->isEmpty()) {
                return null;
            }
        }

        $now = now();
        $onShift = ResponseTeam::where('status', 'active')
            ->whereHas('members', fn ($q) => $q->where('status', 'active')->whereHas('user', fn ($user) => $user->where('status', 'active')))
            ->when($activeTeamIds !== null, fn ($q) => $q->whereIn('id', $activeTeamIds))
            ->whereHas('shifts', fn ($q) => $q->where('status', 'on-shift')
                ->where('start_time', '<=', $now)
                ->where('end_time', '>', $now))
            ->withCount(['reports as busy_count' => $busy])
            ->orderBy('busy_count')
            ->orderBy('id');

        $team = $onShift->first();

        if ($team) {
            return $team;
        }

        return ResponseTeam::where('status', 'active')
            ->whereHas('members', fn ($q) => $q->where('status', 'active')->whereHas('user', fn ($user) => $user->where('status', 'active')))
            ->when($activeTeamIds !== null, fn ($q) => $q->whereIn('id', $activeTeamIds))
            ->withCount(['reports as busy_count' => $busy])
            ->orderBy('busy_count')
            ->orderBy('id')
            ->first();
    }

    /** Members of the team's current on-shift roster. */
    public function onShiftMembers(ResponseTeam $team)
    {
        $now = now();
        $shift = $team->shifts()
            ->where('status', 'on-shift')
            ->where('start_time', '<=', $now)
            ->where('end_time', '>', $now)
            ->with('members.user:id,status')
            ->first();

        return $shift ? $shift->members : collect();
    }

    public function dispatch(EmergencyReport $report, ?ResponseTeam $team = null, ?TeamMember $selectedDriver = null, bool $automatic = false): EmergencyReport
    {
        return DB::transaction(function () use ($report, $team, $selectedDriver, $automatic): EmergencyReport {
            $lockedReport = EmergencyReport::query()->lockForUpdate()->findOrFail($report->getKey());

            if (! in_array($lockedReport->status, ['pending', 'acknowledged', 'dispatched'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only active reports can be dispatched.',
                ]);
            }

            $team = $team
                ? ResponseTeam::query()->where('status', 'active')->lockForUpdate()->find($team->getKey())
                : $this->autoAssignTeam(lockForUpdate: true);
            if (! $team) {
                throw ValidationException::withMessages([
                    'team' => 'No team has an active linked driver/responder. Edit a response team, select its members from the dropdowns, and ensure their accounts are active.',
                ]);
            }

            $driver = $this->onShiftMembers($team)->first(
                fn ($member) => $member->user_id
                    && $member->status === 'active'
                    && $member->user?->status === 'active',
            ) ?? $team->members()
                ->where('status', 'active')
                ->whereNotNull('user_id')
                ->whereHas('user', fn ($query) => $query->where('status', 'active'))
                ->first();

            if (! $driver) {
                throw ValidationException::withMessages([
                    'team' => 'This team has no active linked driver/responder. Edit its roster and select an active driver/responder account.',
                ]);
            }
            if (! $selectedDriver) {
                DriverResponderUser::whereKey($driver->user_id)->where('status', 'active')->lockForUpdate()->firstOrFail();
            }
            if ($selectedDriver) {
                $driver = $team->members()->whereKey($selectedDriver->id)->where('status', 'active')->firstOrFail();
                $account = DriverResponderUser::whereKey($driver->user_id)->where('status', 'active')->lockForUpdate()->firstOrFail();
                abort_if(EmergencyReport::where('assigned_driver_id', $account->id)->whereIn('status', ['acknowledged', 'dispatched'])->where('id', '!=', $lockedReport->id)->exists(), 422, 'This driver has an active assignment.');
            }
            $previousDriverId = $lockedReport->assigned_driver_id;
            $lockedReport->update([
                'response_team_id' => $team->getKey(),
                'assigned_driver_id' => $driver?->user_id,
                'status' => 'dispatched',
                'acknowledged_by' => $lockedReport->acknowledged_by ?? ($automatic ? null : auth()->user()?->getKey()),
                'acknowledged_by_type' => $lockedReport->acknowledged_by_type ?? ($automatic ? null : auth()->user()?->getMorphClass()),
                'acknowledged_at' => $lockedReport->acknowledged_at ?? now(),
            ]);

            if ($lockedReport->assigned_driver_id) {
                DriverNotification::receipt($lockedReport->assigned_driver_id, $lockedReport, 'assigned', 'Assignment received');
            }
            if ($lockedReport->reporter instanceof ResidentUser) {
                ResidentNotification::create([
                    'user_id' => $lockedReport->reported_by,
                    'type' => 'report_update',
                    'title' => 'Help is on the way',
                    'message' => "{$team->team_name} has been dispatched to {$lockedReport->location}.",
                    'related_type' => EmergencyReport::class,
                    'related_id' => $lockedReport->getKey(),
                ]);
            }

            if ($automatic) {
                AuditLog::create(['user_id' => null, 'user_type' => null, 'user_name' => 'System', 'action' => 'AUTO_ASSIGN_TEAM', 'details' => 'Automatically dispatched '.$lockedReport->reference.' to '.$team->team_name]);
            } else {
                Audit::record('ASSIGN_TEAM', "Report {$lockedReport->reference} dispatched to {$team->team_name}");
            }
            EmergencyReportUpdated::dispatch($lockedReport, $previousDriverId);

            return $lockedReport->refresh();
        });
    }

    /** Nearest hospitals (haversine), ETA assumes 40 km/h average. Prefers matching specialization. */
    public function recommendHospitals(EmergencyReport $report, int $limit = 3)
    {
        $want = ['Medical Emergency' => 'Emergency', 'Trauma' => 'Trauma', 'Vehicular' => 'Trauma', 'Fire' => 'Burn'][$report->incidentType->name] ?? null;
        $columns = ['id', 'name', 'address', 'lat', 'lng', 'available_beds', 'contact_number', 'specialization'];
        $toRecommendation = function (Hospital $hospital) use ($report, $want): array {
            $km = $this->km($report->lat, $report->lng, $hospital->lat, $hospital->lng);

            return [
                'id' => $hospital->id,
                'name' => $hospital->name,
                'address' => $hospital->address,
                'lat' => $hospital->lat,
                'lng' => $hospital->lng,
                'distance' => round($km, 1),
                'rawDistance' => $km,
                'availableBeds' => $hospital->available_beds,
                'contactNumber' => $hospital->contact_number,
                'specialization' => $hospital->specialization ?? [],
                'eta' => max(1, (int) round($km / 40 * 60)),
                'match' => $want === null ? null : in_array($want, $hospital->specialization ?? [], true),
            ];
        };

        $specialized = $want === null
            ? collect()
            : Hospital::query()->whereJsonContains('specialization', $want)->get($columns)->map($toRecommendation);

        $rankedSpecialized = $specialized->sortBy('rawDistance')->take($limit)->values();
        $remaining = max(0, $limit - $rankedSpecialized->count());

        if ($remaining === 0) {
            return $rankedSpecialized->map(function (array $hospital): array {
                unset($hospital['rawDistance']);

                return $hospital;
            });
        }

        $excludedIds = $specialized->pluck('id')->all();
        $radiusKm = 25.0;
        $rankedOtherHospitals = collect();

        do {
            $query = Hospital::query();
            if ($excludedIds !== []) {
                $query->whereNotIn('id', $excludedIds);
            }

            $latitudeRange = $radiusKm / 110.574;
            if ($latitudeRange < 90) {
                $query->whereBetween('lat', [
                    max(-90, $report->lat - $latitudeRange),
                    min(90, $report->lat + $latitudeRange),
                ]);
            }

            $maxLatitude = min(90, abs($report->lat) + $latitudeRange);
            $longitudeScale = max(cos(deg2rad($maxLatitude)), 0.01);
            $longitudeRange = 2 * $radiusKm / (111.320 * $longitudeScale);
            $minLongitude = $report->lng - $longitudeRange;
            $maxLongitude = $report->lng + $longitudeRange;
            if ($longitudeRange < 180 && $minLongitude >= -180 && $maxLongitude <= 180) {
                $query->whereBetween('lng', [$minLongitude, $maxLongitude]);
            }

            $rankedOtherHospitals = $query->get($columns)
                ->map($toRecommendation)
                ->sortBy('rawDistance')
                ->take($remaining)
                ->values();

            $nearestDistance = $rankedOtherHospitals->last()['rawDistance'] ?? INF;
            if ($rankedOtherHospitals->count() >= $remaining && $nearestDistance <= $radiusKm / 2) {
                break;
            }

            $radiusKm *= 2;
        } while ($radiusKm < 20000);

        return $rankedSpecialized
            ->concat($rankedOtherHospitals)
            ->sortBy([['match', 'desc'], ['rawDistance', 'asc']])
            ->take($limit)
            ->values()
            ->map(function (array $hospital): array {
                unset($hospital['rawDistance']);

                return $hospital;
            });
    }

    private function km(float $a, float $b, float $c, float $d): float
    {
        $r = 6371;
        $dLat = deg2rad($c - $a);
        $dLng = deg2rad($d - $b);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a)) * cos(deg2rad($c)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(sqrt(min(1, max(0, $h))));
    }
}
