<?php

namespace App\Services;

use App\Events\EmergencyReportUpdated;
use App\Events\VehicleLocationUpdated;
use App\Models\AuditLog;
use App\Models\DispatchAssignment;
use App\Models\DriverNotification;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\Hospital;
use App\Models\ResidentNotification;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Models\ServiceSchedule;
use App\Models\TeamMember;
use App\Models\Vehicle;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DispatchService
{
    /**
     * IDs of response teams currently dispatched to an active emergency report
     * or an active scheduled service.
     *
     * @return array<int, int>
     */
    public function busyTeamIds(?int $ignoreReportId = null): array
    {
        $reportTeamIds = EmergencyReport::query()
            ->whereIn('status', ['acknowledged', 'dispatched'])
            ->whereNotNull('response_team_id')
            ->when($ignoreReportId !== null, fn ($q) => $q->where('id', '!=', $ignoreReportId))
            ->pluck('response_team_id');

        $scheduleTeamIds = ServiceSchedule::query()
            ->whereIn('status', ['en-route', 'on-scene'])
            ->whereNotNull('response_team_id')
            ->pluck('response_team_id');

        return $reportTeamIds
            ->merge($scheduleTeamIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * IDs of vehicles currently in use on active dispatches (including primary
     * dispatches, active DispatchAssignments, and—for backup requests—the vehicle
     * used on the parent incident / requesting team).
     *
     * @return array<int, int>
     */
    public function inUseVehicleIds(?int $ignoreReportId = null, ?EmergencyReport $backupReport = null): array
    {
        $ids = collect();

        $ids = $ids->merge(
            Vehicle::query()
                ->whereNotNull('assigned_report_id')
                ->when($ignoreReportId !== null, fn ($q) => $q->where('assigned_report_id', '!=', $ignoreReportId))
                ->pluck('id'),
        );

        $ids = $ids->merge(
            DispatchAssignment::query()
                ->where('status', 'dispatched')
                ->whereNotNull('vehicle_id')
                ->when($ignoreReportId !== null, fn ($q) => $q->where('emergency_report_id', '!=', $ignoreReportId))
                ->pluck('vehicle_id'),
        );

        // Include the active/default vehicle of any team currently dispatched to another active report/service.
        $busyTeamIds = $this->busyTeamIds($ignoreReportId);
        if ($busyTeamIds !== []) {
            $busyTeams = ResponseTeam::query()
                ->whereIn('id', $busyTeamIds)
                ->with(['vehicles', 'activeVehicle'])
                ->get();

            foreach ($busyTeams as $busyTeam) {
                $activeVehicle = $busyTeam->resolveActiveVehicle();
                if ($activeVehicle) {
                    $ids->push($activeVehicle->getKey());
                }
            }
        }

        if ($backupReport !== null && $backupReport->isBackupRequest()) {
            $ids = $ids->merge($this->backupExcludedVehicleIds($backupReport));
        }

        return $ids
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Vehicle IDs specifically tied to the parent incident or requesting team
     * of a backup request (cannot be reused for that backup).
     *
     * @return array<int, int>
     */
    public function backupExcludedVehicleIds(EmergencyReport $backupReport): array
    {
        if (! $backupReport->isBackupRequest()) {
            return [];
        }

        $ids = collect();
        $parentReport = $backupReport->relationLoaded('parentReport')
            ? $backupReport->parentReport
            : $backupReport->parentReport()->with(['assignedVehicle', 'activeDispatchAssignment', 'team.vehicles', 'team.activeVehicle'])->first();

        if ($parentReport) {
            $ids = $ids->merge(
                Vehicle::query()
                    ->where('assigned_report_id', $parentReport->getKey())
                    ->pluck('id'),
            );
            $ids = $ids->merge(
                DispatchAssignment::query()
                    ->where('emergency_report_id', $parentReport->getKey())
                    ->where('status', 'dispatched')
                    ->whereNotNull('vehicle_id')
                    ->pluck('vehicle_id'),
            );
            $parentResolved = $parentReport->resolvedVehicle();
            if ($parentResolved) {
                $ids->push($parentResolved->getKey());
            }
        }

        $requestingTeamId = $backupReport->requested_by_team_id ?? $parentReport?->response_team_id;
        if ($requestingTeamId) {
            $requestingTeam = ResponseTeam::query()
                ->with(['vehicles', 'activeVehicle'])
                ->find($requestingTeamId);
            if ($requestingTeam) {
                if ($requestingTeam->active_vehicle_id) {
                    $ids->push($requestingTeam->active_vehicle_id);
                }
                $resolved = $requestingTeam->resolveActiveVehicle();
                if ($resolved) {
                    $ids->push($resolved->getKey());
                }
            }
        }

        return $ids
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** Available active team currently on shift; falls back to any available active team. Never returns an already-dispatched team. */
    public function autoAssignTeam(
        bool $lockForUpdate = false,
        ?int $ignoreReportId = null,
        ?int $excludeTeamId = null,
    ): ?ResponseTeam {
        $busyTeamIds = $this->busyTeamIds($ignoreReportId);
        if ($excludeTeamId !== null && ! in_array($excludeTeamId, $busyTeamIds, true)) {
            $busyTeamIds[] = $excludeTeamId;
        }

        $activeTeamIds = null;
        if ($lockForUpdate) {
            $activeTeamIds = ResponseTeam::query()
                ->where('status', 'active')
                ->when($busyTeamIds !== [], fn ($q) => $q->whereNotIn('id', $busyTeamIds))
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            if ($activeTeamIds->isEmpty()) {
                return null;
            }
        }

        $now = now();
        $hasUsableVehicle = fn ($q) => $q->whereHas('vehicles', fn ($v) => $v->where('status', '!=', 'maintenance'));

        $onShift = ResponseTeam::where('status', 'active')
            ->when($busyTeamIds !== [], fn ($q) => $q->whereNotIn('id', $busyTeamIds))
            ->where($hasUsableVehicle)
            ->whereHas('members', fn ($q) => $q->where('status', 'active')->whereHas('user', fn ($user) => $user->where('status', 'active'))
                ->where(fn ($role) => $role->where('response_teams.member_roles_enabled', false)->orWhere('role', 'Driver')))
            ->when($activeTeamIds !== null, fn ($q) => $q->whereIn('id', $activeTeamIds))
            ->whereHas('shifts', fn ($q) => $q->where('status', 'on-shift')
                ->where('start_time', '<=', $now)
                ->where('end_time', '>', $now))
            ->orderBy('id');

        $team = $onShift->first();

        if ($team) {
            return $team;
        }

        return ResponseTeam::where('status', 'active')
            ->when($busyTeamIds !== [], fn ($q) => $q->whereNotIn('id', $busyTeamIds))
            ->where($hasUsableVehicle)
            ->whereHas('members', fn ($q) => $q->where('status', 'active')->whereHas('user', fn ($user) => $user->where('status', 'active'))
                ->where(fn ($role) => $role->where('response_teams.member_roles_enabled', false)->orWhere('role', 'Driver')))
            ->when($activeTeamIds !== null, fn ($q) => $q->whereIn('id', $activeTeamIds))
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

    public function dispatch(
        EmergencyReport $report,
        ?ResponseTeam $team = null,
        ?TeamMember $selectedDriver = null,
        bool $automatic = false,
        ?Vehicle $selectedVehicle = null,
    ): EmergencyReport {
        return DB::transaction(function () use ($report, $team, $selectedDriver, $automatic, $selectedVehicle): EmergencyReport {
            $lockedReport = EmergencyReport::query()->lockForUpdate()->findOrFail($report->getKey());

            if (! in_array($lockedReport->status, ['pending', 'acknowledged', 'dispatched'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only active reports can be dispatched.',
                ]);
            }

            $isBackup = $lockedReport->isBackupRequest();
            $requestingTeamId = $isBackup
                ? ($lockedReport->requested_by_team_id ?? $lockedReport->parentReport?->response_team_id)
                : null;

            $team = $team
                ? ResponseTeam::query()->where('status', 'active')->lockForUpdate()->find($team->getKey())
                : $this->autoAssignTeam(
                    lockForUpdate: true,
                    ignoreReportId: $lockedReport->getKey(),
                    excludeTeamId: $requestingTeamId,
                );
            if (! $team) {
                $eligibleTeamQuery = ResponseTeam::query()
                    ->where('status', 'active')
                    ->whereHas('members', fn ($q) => $q->where('status', 'active')->whereHas('user', fn ($user) => $user->where('status', 'active'))
                        ->where(fn ($role) => $role->where('response_teams.member_roles_enabled', false)->orWhere('role', 'Driver')));

                $hasActiveTeamWithDriver = (clone $eligibleTeamQuery)->exists();
                $hasActiveTeamWithoutVehicle = (clone $eligibleTeamQuery)->whereDoesntHave('vehicles')->exists();
                $hasUsableVehicleTeam = (clone $eligibleTeamQuery)
                    ->whereHas('vehicles', fn ($v) => $v->where('status', '!=', 'maintenance'))
                    ->exists();

                if ($hasUsableVehicleTeam) {
                    throw ValidationException::withMessages([
                        'team' => $isBackup
                            ? 'No other available response team is free for backup right now. All eligible teams are currently dispatched.'
                            : 'All active response teams are currently dispatched. Wait for a team to complete its active response.',
                    ]);
                }

                if ($hasActiveTeamWithoutVehicle) {
                    throw ValidationException::withMessages([
                        'team' => 'This response team needs an assigned vehicle first before it can be dispatched.',
                    ]);
                }

                throw ValidationException::withMessages([
                    'team' => $hasActiveTeamWithDriver
                        ? 'All vehicles assigned to active response teams are currently under maintenance.'
                        : 'No team has an active linked driver/responder. Edit a response team, select its members from the dropdowns, and ensure their accounts are active.',
                ]);
            }

            // Task 1: Cannot dispatch the same team that requested backup.
            if ($isBackup && $requestingTeamId !== null && $team->getKey() === (int) $requestingTeamId) {
                throw ValidationException::withMessages([
                    'team' => "{$team->team_name} requested this backup and cannot be dispatched to its own backup request.",
                ]);
            }

            // Task 2: Cannot dispatch a team that is already dispatched to another active emergency report.
            $activeTeamReport = EmergencyReport::query()
                ->where('response_team_id', $team->getKey())
                ->whereIn('status', ['acknowledged', 'dispatched'])
                ->where('id', '!=', $lockedReport->getKey())
                ->first(['id', 'reference']);
            if ($activeTeamReport) {
                throw ValidationException::withMessages([
                    'team' => "{$team->team_name} is already dispatched to {$activeTeamReport->reference} and cannot be dispatched again until that response is completed.",
                ]);
            }

            $blockedVehicleIds = $this->inUseVehicleIds(
                ignoreReportId: $lockedReport->getKey(),
                backupReport: $isBackup ? $lockedReport : null,
            );
            $backupExcludedVehicleIds = $isBackup
                ? $this->backupExcludedVehicleIds($lockedReport)
                : [];

            $operationVehicle = null;
            if ($selectedVehicle !== null) {
                $operationVehicle = Vehicle::query()->lockForUpdate()->find($selectedVehicle->getKey());
                if (! $operationVehicle) {
                    throw ValidationException::withMessages([
                        'vehicle_id' => 'The selected vehicle could not be found.',
                    ]);
                }
                if ($operationVehicle->status === 'maintenance') {
                    throw ValidationException::withMessages([
                        'vehicle_id' => "{$operationVehicle->displayName()} is currently under maintenance and cannot be dispatched.",
                    ]);
                }
                if ($isBackup && in_array($operationVehicle->getKey(), $backupExcludedVehicleIds, true)) {
                    throw ValidationException::withMessages([
                        'vehicle_id' => "{$operationVehicle->displayName()} is already in use by the team that requested backup and cannot be assigned.",
                    ]);
                }
                $assignedElsewhere = ($operationVehicle->assigned_report_id !== null
                    && $operationVehicle->assigned_report_id !== $lockedReport->getKey())
                    || in_array($operationVehicle->getKey(), $blockedVehicleIds, true);
                if ($assignedElsewhere || ($operationVehicle->status !== 'available' && $operationVehicle->assigned_report_id !== $lockedReport->getKey())) {
                    throw ValidationException::withMessages([
                        'vehicle_id' => "{$operationVehicle->displayName()} is not currently available for dispatch.",
                    ]);
                }
            } elseif ($isBackup && $lockedReport->requested_vehicle_type !== null) {
                $teamDefault = $team->resolveActiveVehicle();
                if (
                    $teamDefault
                    && $teamDefault->vehicle_type === $lockedReport->requested_vehicle_type
                    && $teamDefault->status === 'available'
                    && ! in_array($teamDefault->getKey(), $blockedVehicleIds, true)
                    && ($teamDefault->assigned_report_id === null || $teamDefault->assigned_report_id === $lockedReport->getKey())
                ) {
                    $operationVehicle = Vehicle::query()->lockForUpdate()->find($teamDefault->getKey());
                } else {
                    $operationVehicle = Vehicle::query()
                        ->where('vehicle_type', $lockedReport->requested_vehicle_type)
                        ->where('status', 'available')
                        ->when($blockedVehicleIds !== [], fn ($q) => $q->whereNotIn('id', $blockedVehicleIds))
                        ->where(fn ($q) => $q->whereNull('assigned_report_id')->orWhere('assigned_report_id', $lockedReport->getKey()))
                        ->orderByRaw('CASE WHEN response_team_id = ? THEN 0 WHEN response_team_id IS NULL THEN 1 ELSE 2 END', [$team->getKey()])
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->first();
                }
            }

            if ($operationVehicle === null) {
                if (! $team->vehicles()->exists()) {
                    throw ValidationException::withMessages([
                        'team' => "{$team->team_name} needs an assigned vehicle first before it can be dispatched.",
                    ]);
                }

                if (! $team->vehicles()->where('status', '!=', 'maintenance')->exists()) {
                    throw ValidationException::withMessages([
                        'team' => 'All vehicles assigned to this team are currently under maintenance.',
                    ]);
                }

                if ($isBackup) {
                    $fallbackVehicle = $team->resolveActiveVehicle();
                    if (
                        $fallbackVehicle
                        && $fallbackVehicle->status === 'available'
                        && ! in_array($fallbackVehicle->getKey(), $blockedVehicleIds, true)
                        && ($fallbackVehicle->assigned_report_id === null || $fallbackVehicle->assigned_report_id === $lockedReport->getKey())
                    ) {
                        $operationVehicle = Vehicle::query()->lockForUpdate()->find($fallbackVehicle->getKey());
                    }
                }
            }

            $driver = $this->onShiftMembers($team)->first(
                fn ($member) => $member->user_id
                    && (! $team->member_roles_enabled || $member->role === 'Driver')
                    && $member->status === 'active'
                    && $member->user?->status === 'active',
            ) ?? $team->members()
                ->where('status', 'active')
                ->when($team->member_roles_enabled, fn ($query) => $query->where('role', 'Driver'))
                ->whereNotNull('user_id')
                ->whereHas('user', fn ($query) => $query->where('status', 'active'))
                ->first();

            if (! $driver) {
                throw ValidationException::withMessages([
                    'team' => 'This team has no available Driver. Select an active Driver in the team roster or mobile Team page.',
                ]);
            }
            if (! $selectedDriver) {
                $account = DriverResponderUser::whereKey($driver->user_id)->where('status', 'active')->lockForUpdate()->firstOrFail();
                if (EmergencyReport::where('assigned_driver_id', $account->id)->whereIn('status', ['acknowledged', 'dispatched'])->where('id', '!=', $lockedReport->id)->exists()) {
                    throw ValidationException::withMessages([
                        'team' => 'This team\'s active driver is already assigned to another active response.',
                    ]);
                }
            }
            if ($selectedDriver) {
                $driver = $team->members()->whereKey($selectedDriver->id)->where('status', 'active')->firstOrFail();
                abort_if($team->member_roles_enabled && $driver->role !== 'Driver', 422, 'Select the current team Driver.');
                $account = DriverResponderUser::whereKey($driver->user_id)->where('status', 'active')->lockForUpdate()->firstOrFail();
                abort_if(EmergencyReport::where('assigned_driver_id', $account->id)->whereIn('status', ['acknowledged', 'dispatched'])->where('id', '!=', $lockedReport->id)->exists(), 422, 'This driver has an active assignment.');
            }
            $previousDriverId = $lockedReport->assigned_driver_id;
            if (ServiceSchedule::whereIn('status', ['en-route', 'on-scene'])
                ->where(fn ($query) => $query->where('response_team_id', $team->id)
                    ->orWhereHas('responders', fn ($query) => $query->whereKey($driver->user_id)))->exists()) {
                throw ValidationException::withMessages(['team' => 'This team or driver is handling a scheduled service.']);
            }
            $lockedReport->update([
                'response_team_id' => $team->getKey(),
                'assigned_driver_id' => $driver?->user_id,
                'status' => 'dispatched',
                'acknowledged_by' => $lockedReport->acknowledged_by ?? ($automatic ? null : auth()->user()?->getKey()),
                'acknowledged_by_type' => $lockedReport->acknowledged_by_type ?? ($automatic ? null : auth()->user()?->getMorphClass()),
                'acknowledged_at' => $lockedReport->acknowledged_at ?? now(),
            ]);

            $shouldBindVehicle = $operationVehicle !== null && ($selectedVehicle !== null || $isBackup);
            if ($shouldBindVehicle) {
                Vehicle::query()
                    ->where('assigned_report_id', $lockedReport->getKey())
                    ->where('id', '!=', $operationVehicle->getKey())
                    ->lockForUpdate()
                    ->get()
                    ->each(function (Vehicle $previous): void {
                        $previous->update([
                            'status' => 'available',
                            'assigned_report_id' => null,
                        ]);
                        VehicleLocationUpdated::dispatch($previous);
                    });

                $operationVehicle->update([
                    'assigned_report_id' => $lockedReport->getKey(),
                ]);
                VehicleLocationUpdated::dispatch($operationVehicle);
            }

            DispatchAssignment::query()
                ->where('emergency_report_id', $lockedReport->getKey())
                ->where('status', 'dispatched')
                ->update([
                    'status' => 'cancelled',
                    'completed_at' => now(),
                ]);

            $recordedVehicle = $operationVehicle ?? $team->resolveActiveVehicle();
            DispatchAssignment::query()->create([
                'emergency_report_id' => $lockedReport->getKey(),
                'response_team_id' => $team->getKey(),
                'vehicle_id' => $recordedVehicle?->getKey(),
                'assignment_type' => $isBackup ? 'backup' : 'primary',
                'status' => 'dispatched',
                'dispatched_at' => now(),
            ]);

            if ($lockedReport->assigned_driver_id) {
                DriverNotification::receipt($lockedReport->assigned_driver_id, $lockedReport, 'assigned', $isBackup ? 'Backup assignment received' : 'Assignment received');
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

            $vehicleNote = $shouldBindVehicle ? " using {$operationVehicle->displayName()}" : '';
            if ($automatic) {
                AuditLog::create(['user_id' => null, 'user_type' => null, 'user_name' => 'System', 'action' => 'AUTO_ASSIGN_TEAM', 'details' => 'Automatically dispatched '.$lockedReport->reference.' to '.$team->team_name.$vehicleNote]);
            } else {
                Audit::record('ASSIGN_TEAM', "Report {$lockedReport->reference} dispatched to {$team->team_name}{$vehicleNote}");
            }
            EmergencyReportUpdated::dispatch($lockedReport, $previousDriverId);

            return $lockedReport->refresh();
        });
    }

    /** Nearest hospitals (haversine), ETA assumes 40 km/h average. Prefers matching specialization. */
    public function recommendHospitals(EmergencyReport $report, int $limit = 3, array $excludeHospitalIds = [])
    {
        $incidentTypeName = $report->incidentType?->name ?? '';
        $want = ['Medical Emergency' => 'Emergency', 'Trauma' => 'Trauma', 'Vehicular' => 'Trauma', 'Fire' => 'Burn'][$incidentTypeName] ?? null;
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
                'available' => $hospital->available_beds === null || $hospital->available_beds > 0,
                'contactNumber' => $hospital->contact_number,
                'specialization' => $hospital->specialization ?? [],
                'eta' => max(1, (int) round($km / 40 * 60)),
                'match' => $want === null ? null : in_array($want, $hospital->specialization ?? [], true),
            ];
        };

        $specialized = $want === null
            ? collect()
            : Hospital::query()
                ->when($excludeHospitalIds !== [], fn ($q) => $q->whereNotIn('id', $excludeHospitalIds))
                ->whereJsonContains('specialization', $want)
                ->get($columns)
                ->map($toRecommendation);

        $rankedSpecialized = $specialized->sortBy('rawDistance')->take($limit)->values();
        $remaining = max(0, $limit - $rankedSpecialized->count());

        if ($remaining === 0) {
            return $rankedSpecialized->map(function (array $hospital): array {
                unset($hospital['rawDistance']);

                return $hospital;
            });
        }

        $excludedIds = array_values(array_unique(array_merge($excludeHospitalIds, $specialized->pluck('id')->all())));
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
