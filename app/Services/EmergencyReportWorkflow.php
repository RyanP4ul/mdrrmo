<?php

namespace App\Services;

use App\Events\EmergencyReportUpdated;
use App\Models\DriverNotification;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\ResidentNotification;
use App\Models\ResidentUser;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmergencyReportWorkflow
{
    private const TRANSITIONS = [
        'pending' => ['acknowledged', 'dispatched', 'invalid'],
        'acknowledged' => ['dispatched', 'invalid'],
        'dispatched' => ['resolved', 'invalid'],
        'resolved' => [],
        'invalid' => [],
    ];

    private function displayStatus(EmergencyReport $report, string $status): string
    {
        return $report->withdrawn_at ? 'withdrawn' : $status;
    }

    public function transition(
        EmergencyReport $report,
        string $status,
        ?User $actor = null,
        ?string $priority = null,
    ): EmergencyReport {
        return DB::transaction(function () use ($report, $status, $actor, $priority): EmergencyReport {
            $lockedReport = EmergencyReport::query()->lockForUpdate()->findOrFail($report->getKey());

            if ($lockedReport->status !== $status
                && ! in_array($status, self::TRANSITIONS[$lockedReport->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => "A report cannot move from {$lockedReport->status} to {$status}.",
                ]);
            }

            $statusChanged = $lockedReport->status !== $status;
            $updates = ['status' => $status];

            if ($priority !== null) {
                $updates['priority'] = $priority;
            }

            if ($status === 'acknowledged' && $actor !== null) {
                $updates['acknowledged_by'] = $actor->getKey();
                $updates['acknowledged_by_type'] = $actor->getMorphClass();
                $updates['acknowledged_at'] = now();
            }

            if ($statusChanged) {
                $updates['resolved_at'] = $status === 'resolved' ? now() : null;
            }

            $lockedReport->update($updates);

            if ($statusChanged && in_array($status, ['resolved', 'invalid'], true)) {
                DB::table('vehicle_tracking_sessions')->where('emergency_report_id', $lockedReport->id)->whereNull('stopped_at')->update(['stopped_at' => now()]);
                Vehicle::query()
                    ->where('assigned_report_id', $lockedReport->getKey())
                    ->lockForUpdate()
                    ->get()
                    ->each(fn (Vehicle $vehicle) => $vehicle->update([
                        'status' => 'available',
                        'assigned_report_id' => null,
                    ]));
            }

            if ($statusChanged && $lockedReport->assigned_driver_id) {
                DriverNotification::receipt($lockedReport->assigned_driver_id, $lockedReport, $status, 'Report '.$status);
            }
            if ($statusChanged && $lockedReport->reporter instanceof DriverResponderUser) {
                DriverNotification::receipt($lockedReport->reported_by, $lockedReport, $status, 'Report '.$status);
            }
            if ($statusChanged && $lockedReport->reporter instanceof ResidentUser) {
                ResidentNotification::create([
                    'user_id' => $lockedReport->reported_by,
                    'type' => 'report_update',
                    'title' => 'Emergency report updated',
                    'message' => "Your report {$lockedReport->reference} is now {$this->displayStatus($lockedReport, $status)}.",
                    'related_type' => EmergencyReport::class,
                    'related_id' => $lockedReport->getKey(),
                ]);
            }

            if ($statusChanged || $priority !== null) {
                Audit::record('UPDATE_REPORT', "Report {$lockedReport->reference} set to {$status}");
                EmergencyReportUpdated::dispatch($lockedReport);
            }

            return $lockedReport->refresh();
        });
    }
}
