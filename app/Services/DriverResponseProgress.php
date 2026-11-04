<?php

namespace App\Services;

use App\Events\DriverLocationUpdated;
use App\Events\EmergencyReportUpdated;
use App\Events\VehicleLocationUpdated;
use App\Models\DispatchTrip;
use App\Models\DriverLocation;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DriverResponseProgress
{
    public function advance(DriverResponderUser $driver, EmergencyReport $report, string $next): EmergencyReport
    {
        return DB::transaction(function () use ($driver, $report, $next): EmergencyReport {
            $locked = EmergencyReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($driver)->authorize('advanceAsDriver', $locked);
            $trip = DispatchTrip::where('emergency_report_id', $locked->id)->first();
            // Existing assigned vehicles only maintain compatibility with historical records.
            $legacy = Vehicle::where('assigned_report_id', $locked->id)->lockForUpdate()->first();
            $current = $trip?->returning_at ? 'returning' : ($trip?->on_scene_at ? 'on-scene' : ($trip?->started_at ? 'en-route' : ($legacy?->status ?? 'available')));
            $valid = match ($next) {
                'en-route' => in_array($current, ['available', 'offline'], true),
                'on-scene' => $current === 'en-route',
                'returning' => $current === 'on-scene',
                'available' => in_array($current, ['on-scene', 'returning'], true),
                default => false,
            };
            if (! $valid) {
                throw ValidationException::withMessages(['response_status' => 'The response cannot move to that stage yet.']);
            }
            if ($next === 'available') {
                app(EmergencyReportWorkflow::class)->transition($locked, 'resolved', $driver);
            } else {
                $field = match ($next) {
                    'en-route' => 'started_at', 'on-scene' => 'on_scene_at', 'returning' => 'returning_at'
                };
                DispatchTrip::updateOrCreate(['emergency_report_id' => $locked->id], [$field => now(), 'driver_name' => $driver->full_name]);
                EmergencyReportUpdated::dispatch($locked);
            }
            if ($legacy) {
                $legacy->update(['status' => $next, 'assigned_report_id' => $next === 'available' ? null : $locked->id]);
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
