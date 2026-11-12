<?php

namespace App\Http\Controllers\Admin;

use App\Events\VehicleLocationUpdated;
use App\Http\Controllers\Controller;
use App\Models\MaintenanceReport;
use App\Models\ResponseTeam;
use App\Models\Vehicle;
use App\Services\VehicleMaintenanceService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class VehicleController extends Controller
{
    public function index(Request $request, VehicleMaintenanceService $maintenanceService)
    {
        $filters = $request->only('search', 'status', 'vehicle_type', 'report_status');

        $vehicles = Vehicle::query()
            ->with(['team:id,team_name,active_vehicle_id', 'openMaintenanceReports'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(function ($sub) use ($s): void {
                $sub->where('plate_number', 'like', "%{$s}%")
                    ->orWhere('name', 'like', "%{$s}%");
            }))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['vehicle_type'] ?? null, fn ($q, $t) => $q->where('vehicle_type', $t))
            ->orderBy('plate_number')
            ->paginate(8, ['*'], 'page')
            ->withQueryString()
            ->through(fn (Vehicle $vehicle): array => $vehicle->toTeamPayload($vehicle->team?->active_vehicle_id) + [
                'response_team_id' => $vehicle->response_team_id,
                'team_name' => $vehicle->team?->team_name,
                'raw_maintenance_warning' => $vehicle->maintenance_warning,
            ]);

        $maintenanceReports = MaintenanceReport::query()
            ->with(['vehicle', 'team', 'reporter', 'reviewer'])
            ->when($filters['report_status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(8, ['*'], 'reports_page')
            ->withQueryString()
            ->through(fn (MaintenanceReport $report): array => $maintenanceService->formatReport($report));

        return Inertia::render('Admin/Vehicles/Index', [
            'filters' => $filters,
            'vehicles' => $vehicles,
            'maintenanceReports' => $maintenanceReports,
            'teams' => ResponseTeam::query()->orderBy('team_name')->get(['id', 'team_name', 'status', 'active_vehicle_id']),
            'stats' => [
                'total_vehicles' => Vehicle::count(),
                'available_vehicles' => Vehicle::where('status', 'available')->count(),
                'in_maintenance_vehicles' => Vehicle::where('status', 'maintenance')->count(),
                'pending_maintenance_reports' => MaintenanceReport::whereIn('status', ['pending', 'acknowledged'])->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($request, $data): void {
            if ($request->hasFile('photo')) {
                $data['photo_path'] = $request->file('photo')->store('vehicles', 'public');
            }
            unset($data['photo']);

            $vehicle = Vehicle::query()->create($data);

            if ($vehicle->response_team_id && $vehicle->status === 'available') {
                $team = ResponseTeam::query()->lockForUpdate()->find($vehicle->response_team_id);
                if ($team && ! $team->active_vehicle_id) {
                    $team->update(['active_vehicle_id' => $vehicle->id]);
                }
            }

            Audit::record('CREATE_VEHICLE', "Created vehicle {$vehicle->plate_number}");
        });

        return back()->with('success', 'Vehicle added.');
    }

    public function update(Request $request, Vehicle $vehicle)
    {
        $data = $this->validated($request, $vehicle);

        DB::transaction(function () use ($request, $vehicle, $data): void {
            $locked = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->id);
            if ($locked->assigned_report_id !== null && in_array($data['status'], ['offline', 'maintenance'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Complete the active dispatch before placing this vehicle offline or under maintenance.',
                ]);
            }

            if ($request->hasFile('photo')) {
                if ($locked->photo_path && ! str_starts_with($locked->photo_path, 'http') && ! str_starts_with($locked->photo_path, '/')) {
                    Storage::disk('public')->delete($locked->photo_path);
                }
                $data['photo_path'] = $request->file('photo')->store('vehicles', 'public');
            }
            unset($data['photo']);

            $previousTeamId = $locked->response_team_id;
            $locked->update($data);

            if ($previousTeamId && $previousTeamId !== $locked->response_team_id) {
                $oldTeam = ResponseTeam::query()->lockForUpdate()->find($previousTeamId);
                if ($oldTeam && $oldTeam->active_vehicle_id === $locked->id) {
                    $fallback = Vehicle::query()
                        ->where('response_team_id', $oldTeam->id)
                        ->where('status', 'available')
                        ->orderBy('id')
                        ->first();
                    $oldTeam->update(['active_vehicle_id' => $fallback?->id]);
                }
            }

            if ($locked->response_team_id) {
                $team = ResponseTeam::query()->lockForUpdate()->find($locked->response_team_id);
                if ($team) {
                    if ($locked->status === 'maintenance' && $team->active_vehicle_id === $locked->id) {
                        $fallback = Vehicle::query()
                            ->where('response_team_id', $team->id)
                            ->where('id', '!=', $locked->id)
                            ->where('status', 'available')
                            ->orderBy('id')
                            ->first();
                        $team->update(['active_vehicle_id' => $fallback?->id]);
                    } elseif (! $team->active_vehicle_id && $locked->status === 'available') {
                        $team->update(['active_vehicle_id' => $locked->id]);
                    }
                }
            }

            event(new VehicleLocationUpdated($locked->refresh()));
            Audit::record('UPDATE_VEHICLE', "Updated vehicle {$locked->plate_number} ({$locked->status})");
        });

        return back()->with('success', 'Vehicle updated.');
    }

    public function updateStatus(Request $request, Vehicle $vehicle)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['available', 'offline', 'maintenance'])],
            'maintenance_warning' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($vehicle, $data): void {
            $locked = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->id);
            if ($locked->assigned_report_id !== null && in_array($data['status'], ['offline', 'maintenance'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Complete the active dispatch before changing this vehicle status.',
                ]);
            }

            $warning = array_key_exists('maintenance_warning', $data)
                ? ($data['maintenance_warning'] !== null && trim($data['maintenance_warning']) !== '' ? trim($data['maintenance_warning']) : null)
                : ($data['status'] === 'available' ? null : $locked->maintenance_warning);

            $locked->update([
                'status' => $data['status'],
                'maintenance_warning' => $warning,
            ]);

            if ($locked->response_team_id) {
                $team = ResponseTeam::query()->lockForUpdate()->find($locked->response_team_id);
                if ($team) {
                    if ($locked->status === 'maintenance' && $team->active_vehicle_id === $locked->id) {
                        $fallback = Vehicle::query()
                            ->where('response_team_id', $team->id)
                            ->where('id', '!=', $locked->id)
                            ->where('status', 'available')
                            ->orderBy('id')
                            ->first();
                        $team->update(['active_vehicle_id' => $fallback?->id]);
                    } elseif (! $team->active_vehicle_id && $locked->status === 'available') {
                        $team->update(['active_vehicle_id' => $locked->id]);
                    }
                }
            }

            event(new VehicleLocationUpdated($locked->refresh()));
            Audit::record('UPDATE_VEHICLE_STATUS', "Changed vehicle {$locked->plate_number} status to {$locked->status}");
        });

        return back()->with('success', 'Vehicle status updated.');
    }

    public function destroy(Vehicle $vehicle)
    {
        DB::transaction(function () use ($vehicle): void {
            $locked = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->id);
            if ($locked->assigned_report_id !== null) {
                throw ValidationException::withMessages([
                    'vehicle' => 'Cannot delete a vehicle assigned to an active incident.',
                ]);
            }
            $plate = $locked->plate_number;
            $locked->delete();
            Audit::record('DELETE_VEHICLE', "Deleted vehicle {$plate}");
        });

        return back()->with('success', 'Vehicle removed.');
    }

    public function reviewMaintenanceReport(Request $request, MaintenanceReport $maintenanceReport, VehicleMaintenanceService $service)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['acknowledged', 'approved', 'in_maintenance', 'resolved', 'rejected'])],
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $service->reviewReport($request->user(), $maintenanceReport, $data);

        return back()->with('success', 'Maintenance report updated.');
    }

    private function validated(Request $request, ?Vehicle $vehicle = null): array
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'plate_number' => ['required', 'string', 'max:40', Rule::unique('vehicles', 'plate_number')->ignore($vehicle)],
            'vehicle_type' => ['required', Rule::in(Vehicle::TYPES)],
            'response_team_id' => ['nullable', 'integer', 'exists:response_teams,id'],
            'status' => ['required', Rule::in(['available', 'offline', 'maintenance'])],
            'maintenance_warning' => ['nullable', 'string', 'max:500'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $data['plate_number'] = strtoupper(trim($data['plate_number']));
        $data['name'] = isset($data['name']) && trim($data['name']) !== '' ? trim($data['name']) : null;
        $data['maintenance_warning'] = isset($data['maintenance_warning']) && trim($data['maintenance_warning']) !== ''
            ? trim($data['maintenance_warning'])
            : null;

        return $data;
    }
}
