<?php

namespace App\Services;

use App\Events\DriverLocationUpdated;
use App\Events\EmergencyReportUpdated;
use App\Events\VehicleLocationUpdated;
use App\Models\DispatchTrip;
use App\Models\DriverLocation;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\Hospital;
use App\Models\ResidentNotification;
use App\Models\ResidentUser;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DriverResponseProgress
{
    public function advance(DriverResponderUser $driver, EmergencyReport $report, string $next, array $attributes = []): EmergencyReport
    {
        return DB::transaction(function () use ($driver, $report, $next, $attributes): EmergencyReport {
            $locked = EmergencyReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($driver)->authorize('advanceAsDriver', $locked);
            $trip = DispatchTrip::where('emergency_report_id', $locked->id)->first();
            // Existing assigned vehicles only maintain compatibility with historical records.
            $legacy = Vehicle::where('assigned_report_id', $locked->id)->lockForUpdate()->first();
            $current = $trip?->arrived_at
                ? 'arrived'
                : ($trip?->returning_at
                    ? 'returning'
                    : ($trip?->picked_up_at
                        ? 'transporting'
                        : ($trip?->on_scene_at
                            ? 'on-scene'
                            : ($trip?->started_at
                                ? 'en-route'
                                : ($legacy?->status ?? 'available')))));
            $valid = match ($next) {
                'en-route' => in_array($current, ['available', 'offline'], true),
                'on-scene' => $current === 'en-route',
                'transporting' => $current === 'on-scene',
                'returning' => in_array($current, ['on-scene', 'transporting'], true),
                'arrived', 'available' => in_array($current, ['on-scene', 'transporting', 'returning'], true),
                default => false,
            };
            if (! $valid) {
                throw ValidationException::withMessages(['response_status' => 'The response cannot move to that stage yet.']);
            }

            $hospitalId = isset($attributes['hospital_id']) ? (int) $attributes['hospital_id'] : $trip?->hospital_id;
            $destination = isset($attributes['destination']) && trim((string) $attributes['destination']) !== ''
                ? trim((string) $attributes['destination'])
                : null;

            if ($next === 'arrived') {
                if ($hospitalId !== null) {
                    $unavailableIds = array_map('intval', $trip?->unavailable_hospital_ids ?? []);
                    if (in_array($hospitalId, $unavailableIds, true)) {
                        throw ValidationException::withMessages(['hospital_id' => 'Selected hospital is marked as not available for this dispatch.']);
                    }
                    $hospital = Hospital::find($hospitalId);
                    if (! $hospital || ($hospital->available_beds !== null && $hospital->available_beds <= 0)) {
                        throw ValidationException::withMessages(['hospital_id' => 'Selected hospital is not available.']);
                    }
                    $destination ??= $hospital->name;
                }
            }

            if (in_array($next, ['arrived', 'available'], true)) {
                $tripUpdates = ['driver_name' => $driver->full_name];
                if ($next === 'arrived' && $trip?->arrived_at === null) {
                    $tripUpdates['arrived_at'] = now();
                }
                if ($hospitalId !== null) {
                    $tripUpdates['hospital_id'] = $hospitalId;
                }
                if ($destination !== null) {
                    $tripUpdates['destination'] = $destination;
                }
                if ($next === 'arrived' || $hospitalId !== null || $destination !== null || $trip !== null) {
                    DispatchTrip::updateOrCreate(['emergency_report_id' => $locked->id], $tripUpdates);
                }
                app(EmergencyReportWorkflow::class)->transition($locked, 'resolved', $driver);
            } else {
                $field = match ($next) {
                    'en-route' => 'started_at',
                    'on-scene' => 'on_scene_at',
                    'transporting' => 'picked_up_at',
                    'returning' => 'returning_at',
                };
                $tripUpdates = [$field => now(), 'driver_name' => $driver->full_name];
                if ($next === 'en-route' && $trip?->driver_acknowledged_at === null) {
                    $tripUpdates['driver_acknowledged_at'] = now();
                }
                if ($hospitalId !== null) {
                    $tripUpdates['hospital_id'] = $hospitalId;
                }
                if ($destination !== null) {
                    $tripUpdates['destination'] = $destination;
                }
                DispatchTrip::updateOrCreate(['emergency_report_id' => $locked->id], $tripUpdates);
                if ($next === 'en-route' && $locked->reporter instanceof ResidentUser) {
                    ResidentNotification::firstOrCreate(
                        [
                            'user_id' => $locked->reported_by,
                            'type' => 'report_update',
                            'title' => 'Response vehicle on the way',
                            'related_type' => EmergencyReport::class,
                            'related_id' => $locked->getKey(),
                        ],
                        [
                            'message' => "The response vehicle for report {$locked->reference} is now on its way.",
                        ],
                    );
                }
                EmergencyReportUpdated::dispatch($locked);
            }
            if ($legacy) {
                $vehicleStatus = match ($next) {
                    'transporting' => 'returning',
                    'arrived' => 'available',
                    default => $next,
                };
                $legacy->update(['status' => $vehicleStatus, 'assigned_report_id' => $vehicleStatus === 'available' ? null : $locked->id]);
                VehicleLocationUpdated::dispatch($legacy);
            }
            $location = DriverLocation::find($driver->id);
            if ($location) {
                DriverLocationUpdated::dispatch($location);
            }

            return $locked->refresh();
        });
    }
}
