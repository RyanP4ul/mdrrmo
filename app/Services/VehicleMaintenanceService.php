<?php

namespace App\Services;

use App\Events\VehicleLocationUpdated;
use App\Models\DriverNotification;
use App\Models\DriverResponderUser;
use App\Models\MaintenanceReport;
use App\Models\ResponseTeam;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class VehicleMaintenanceService
{
    public function submitReport(DriverResponderUser $driver, array $data): MaintenanceReport
    {
        return DB::transaction(function () use ($driver, $data): MaintenanceReport {
            $vehicle = Vehicle::query()->with('team')->lockForUpdate()->findOrFail($data['vehicle_id']);
            Gate::forUser($driver)->authorize('reportMaintenance', $vehicle);

            $report = MaintenanceReport::query()->create([
                'reference' => 'MNT-'.now()->format('Y').'-'.Str::upper(Str::random(8)),
                'vehicle_id' => $vehicle->id,
                'response_team_id' => $vehicle->response_team_id,
                'reported_by' => $driver->id,
                'issue_type' => $data['issue_type'],
                'severity' => $data['severity'],
                'mileage' => $data['mileage'] ?? null,
                'description' => $data['description'],
                'recommend_out_of_service' => (bool) ($data['recommend_out_of_service'] ?? false),
                'status' => 'pending',
            ]);

            if (! $vehicle->maintenance_warning && ($report->recommend_out_of_service || in_array($report->severity, ['high', 'critical'], true))) {
                $vehicle->update([
                    'maintenance_warning' => "Pending {$report->severity} maintenance report ({$report->issue_type}): {$report->description}",
                ]);
            }

            Audit::record('CREATE_MAINTENANCE_REPORT', "Driver {$driver->full_name} reported maintenance {$report->reference} for {$vehicle->plate_number}");

            return $report->load(['vehicle', 'team', 'reporter', 'reviewer']);
        });
    }

    public function reviewReport(User $admin, MaintenanceReport $report, array $data): MaintenanceReport
    {
        return DB::transaction(function () use ($admin, $report, $data): MaintenanceReport {
            $lockedReport = MaintenanceReport::query()->lockForUpdate()->findOrFail($report->id);
            $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($lockedReport->vehicle_id);
            $status = $data['status'];
            $notes = isset($data['admin_notes']) && trim((string) $data['admin_notes']) !== ''
                ? trim((string) $data['admin_notes'])
                : null;

            $lockedReport->update([
                'status' => $status,
                'reviewed_by' => $admin->getKey(),
                'reviewed_at' => now(),
                'resolved_at' => $status === 'resolved' ? now() : ($status === 'rejected' ? null : $lockedReport->resolved_at),
                'admin_notes' => $notes ?? $lockedReport->admin_notes,
            ]);

            if (in_array($status, ['approved', 'in_maintenance'], true)) {
                $warning = $notes ?: "Approved for maintenance ({$lockedReport->issue_type}): {$lockedReport->description}";
                $vehicleUpdates = ['maintenance_warning' => $warning];
                if ($vehicle->assigned_report_id === null) {
                    $vehicleUpdates['status'] = 'maintenance';
                }
                $vehicle->update($vehicleUpdates);

                if ($vehicle->response_team_id) {
                    $team = ResponseTeam::query()->lockForUpdate()->find($vehicle->response_team_id);
                    if ($team && $team->active_vehicle_id === $vehicle->id) {
                        $replacement = Vehicle::query()
                            ->where('response_team_id', $team->id)
                            ->where('id', '!=', $vehicle->id)
                            ->where('status', 'available')
                            ->orderBy('id')
                            ->first();
                        $team->update(['active_vehicle_id' => $replacement?->id]);
                    }
                }
                event(new VehicleLocationUpdated($vehicle->refresh()));
            } elseif ($status === 'acknowledged') {
                $warning = $notes ?: "Maintenance report acknowledged ({$lockedReport->issue_type}): {$lockedReport->description}";
                $vehicle->update(['maintenance_warning' => $warning]);
            } elseif ($status === 'resolved') {
                $otherOpen = MaintenanceReport::query()
                    ->where('vehicle_id', $vehicle->id)
                    ->where('id', '!=', $lockedReport->id)
                    ->whereIn('status', MaintenanceReport::OPEN_STATUSES)
                    ->exists();
                if (! $otherOpen) {
                    $vehicle->update([
                        'status' => $vehicle->status === 'maintenance' ? 'available' : $vehicle->status,
                        'maintenance_warning' => null,
                    ]);
                    if ($vehicle->response_team_id) {
                        $team = ResponseTeam::query()->lockForUpdate()->find($vehicle->response_team_id);
                        if ($team && ! $team->active_vehicle_id) {
                            $team->update(['active_vehicle_id' => $vehicle->id]);
                        }
                    }
                    event(new VehicleLocationUpdated($vehicle->refresh()));
                }
            } elseif ($status === 'rejected') {
                $otherOpen = MaintenanceReport::query()
                    ->where('vehicle_id', $vehicle->id)
                    ->where('id', '!=', $lockedReport->id)
                    ->whereIn('status', MaintenanceReport::OPEN_STATUSES)
                    ->exists();
                if (! $otherOpen && $vehicle->status !== 'maintenance') {
                    $vehicle->update(['maintenance_warning' => null]);
                }
            }

            if ($lockedReport->reported_by) {
                $statusLabel = str_replace('_', ' ', $status);
                DriverNotification::query()->create([
                    'driver_id' => $lockedReport->reported_by,
                    'report_id' => null,
                    'schedule_id' => null,
                    'kind' => "maintenance_{$lockedReport->id}_{$status}_".Str::random(4),
                    'title' => 'Maintenance report '.$statusLabel,
                    'message' => "Maintenance report {$lockedReport->reference} for {$vehicle->plate_number} was marked {$statusLabel}."
                        .($notes ? " Admin note: {$notes}" : ''),
                ]);
            }

            Audit::record('REVIEW_MAINTENANCE_REPORT', "Reviewed maintenance report {$lockedReport->reference} ({$status}) for {$vehicle->plate_number}");

            return $lockedReport->load(['vehicle', 'team', 'reporter', 'reviewer']);
        });
    }

    public function formatReport(MaintenanceReport $report): array
    {
        $report->loadMissing(['vehicle', 'team', 'reporter', 'reviewer']);

        return [
            'id' => $report->id,
            'reference' => $report->reference,
            'vehicle_id' => $report->vehicle_id,
            'plate_number' => $report->vehicle?->plate_number,
            'vehicle_number' => $report->vehicle?->plate_number,
            'vehicle_type' => $report->vehicle?->vehicle_type,
            'vehicle_name' => $report->vehicle?->name ?: ($report->vehicle ? ucfirst(str_replace('-', ' ', $report->vehicle->vehicle_type)).' '.$report->vehicle->plate_number : null),
            'vehicle_photo_url' => $report->vehicle?->photo_url,
            'vehicle_status' => $report->vehicle?->status,
            'response_team_id' => $report->response_team_id,
            'team_name' => $report->team?->team_name,
            'reported_by' => $report->reported_by,
            'reporter_name' => $report->reporter?->full_name,
            'issue_type' => $report->issue_type,
            'severity' => $report->severity,
            'mileage' => $report->mileage,
            'description' => $report->description,
            'recommend_out_of_service' => $report->recommend_out_of_service,
            'status' => $report->status,
            'reviewed_by' => $report->reviewed_by,
            'reviewer_name' => $report->reviewer?->full_name,
            'reviewed_at' => $report->reviewed_at?->toIso8601String(),
            'resolved_at' => $report->resolved_at?->toIso8601String(),
            'admin_notes' => $report->admin_notes,
            'created_at' => $report->created_at?->toIso8601String(),
        ];
    }
}
