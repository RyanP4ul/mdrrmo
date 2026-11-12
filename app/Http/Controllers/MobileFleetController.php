<?php

namespace App\Http\Controllers;

use App\Events\TeamRoleUpdated;
use App\Events\VehicleLocationUpdated;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\MaintenanceReport;
use App\Models\ResponseTeam;
use App\Models\Shift;
use App\Models\TeamMember;
use App\Models\Vehicle;
use App\Services\TeamRoles;
use App\Services\VehicleMaintenanceService;
use App\Support\Audit;
use App\Support\MobilePage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MobileFleetController extends Controller
{
    public function drivers(Request $request): JsonResponse
    {
        return response()->json(MobilePage::make(DriverResponderUser::where('status', 'active'), fn ($user) => $user->only(['id', 'first_name', 'last_name', 'contact_number']) + ['name' => $user->full_name], $request));
    }

    public function teams(Request $request): JsonResponse
    {
        return response()->json(MobilePage::make(ResponseTeam::with(['members.user:id,first_name,last_name,contact_number']), request: $request));
    }

    public function team(ResponseTeam $team): JsonResponse
    {
        return response()->json($team->load(['members.user:id,first_name,last_name,contact_number', 'shifts.members']));
    }

    public function saveTeam(Request $request, ?int $id = null): JsonResponse
    {
        $data = $request->validate(['team_name' => ['required', 'string', 'max:120', Rule::unique('response_teams', 'team_name')->ignore($id)], 'status' => ['required', 'in:active,inactive,on-leave,off-duty'], 'specializations' => ['required', 'array', 'max:100'], 'specializations.*' => ['required', 'string', 'max:80', 'distinct']]);

        return DB::transaction(function () use ($id, $data): JsonResponse {
            $team = $id ? ResponseTeam::whereKey($id)->lockForUpdate()->firstOrFail() : new ResponseTeam;
            if (! $id) {
                $team->member_roles_enabled = true;
            }
            abort_if($id && $data['status'] !== 'active' && $team->reports()->active()->exists(), 422, 'Complete or reassign active reports before disabling this team.');
            $team->fill($data)->save();
            Audit::record('SAVE_TEAM', 'Mobile team '.$team->team_name);

            return response()->json($team, $id ? 200 : 201);
        });
    }

    public function saveMember(Request $request, ResponseTeam $team, ?int $id = null): JsonResponse
    {
        $data = $request->validate(['user_id' => ['nullable', 'integer', 'exists:drivers,id'], 'name' => ['required', 'string', 'max:120'], 'role' => ['required', 'in:Driver,Driver/Responder,Responder,Medic,Team leader'], 'status' => ['required', 'in:active,inactive,on-leave,off-duty']]);

        return DB::transaction(function () use ($team, $id, $data): JsonResponse {
            $team = ResponseTeam::whereKey($team->id)->lockForUpdate()->firstOrFail();
            $member = $id ? $team->members()->whereKey($id)->lockForUpdate()->firstOrFail() : new TeamMember(['response_team_id' => $team->id]);
            if ($data['role'] === 'Driver') {
                abort_unless(! empty($data['user_id']) && $data['status'] === 'active' && DriverResponderUser::whereKey($data['user_id'])->where('status', 'active')->exists(), 422, 'The Driver must be an active linked team member.');
                $current = $team->members()->where('role', 'Driver')->first();
                if ($current?->id !== $member->id || $current?->user_id !== $data['user_id']) {
                    app(TeamRoles::class)->ensureIdle($team);
                    $team->members()->where('role', 'Driver')->update(['role' => 'Responder']);
                }
            } elseif ($member->role === 'Driver' && ($data['role'] !== 'Driver' || $data['status'] !== 'active' || ($data['user_id'] ?? null) !== $member->user_id)) {
                app(TeamRoles::class)->ensureIdle($team);
            }
            abort_if($member->exists && ($data['status'] !== 'active' || ($data['user_id'] ?? null) !== $member->user_id) && EmergencyReport::where('response_team_id', $team->id)->where('assigned_driver_id', $member->user_id)->whereIn('status', ['acknowledged', 'dispatched'])->exists(), 422, 'This member has an active assignment.');
            abort_if(! empty($data['user_id']) && $team->members()->where('user_id', $data['user_id'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists(), 422, 'This driver is already in the team.');
            $member->fill($data)->save();
            if (in_array($data['role'], ['Driver', 'Responder'], true)) {
                $team->update(['member_roles_enabled' => true]);
                TeamRoleUpdated::dispatch($team->id);
            }
            Audit::record('SAVE_TEAM_MEMBER', 'Team '.$team->id.' member '.$member->name);

            return response()->json($member, $id ? 200 : 201);
        });
    }

    public function saveShift(Request $request, ResponseTeam $team, ?int $id = null): JsonResponse
    {
        $data = $request->validate(['start_time' => ['required', 'date'], 'end_time' => ['required', 'date', 'after:start_time'], 'status' => ['required', 'in:on-shift,off-shift,on-break'], 'member_ids' => ['required', 'array', 'min:1'], 'member_ids.*' => ['required', 'integer', 'distinct', Rule::exists('team_members', 'id')->where('response_team_id', $team->id)]]);

        return DB::transaction(function () use ($team, $id, $data): JsonResponse {
            ResponseTeam::whereKey($team->id)->lockForUpdate()->firstOrFail();
            $shift = $id ? $team->shifts()->whereKey($id)->lockForUpdate()->firstOrFail() : new Shift(['response_team_id' => $team->id]);
            $ids = $data['member_ids'];
            unset($data['member_ids']);
            $shift->fill($data)->save();
            $shift->members()->sync($ids);
            Audit::record('SAVE_SHIFT', 'Mobile shift '.$shift->id.' for team '.$team->id);

            return response()->json($shift->load('members'), $id ? 200 : 201);
        });
    }

    private function authorizeAdminVehicleAccess(Request $request): void
    {
        abort_unless(
            $request->user() && $request->user()->hasRole('admin') && $request->user()->tokenCan('admin-manage'),
            404,
        );
    }

    public function vehicles(Request $request): JsonResponse
    {
        $this->authorizeAdminVehicleAccess($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Vehicle::STATUSES)],
            'vehicle_type' => ['nullable', Rule::in(Vehicle::TYPES)],
        ]);

        $query = Vehicle::query()
            ->with(['team:id,team_name,active_vehicle_id', 'openMaintenanceReports'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['vehicle_type'] ?? null, fn ($q, $t) => $q->where('vehicle_type', $t));

        return response()->json(MobilePage::make(
            $query,
            fn (Vehicle $vehicle): array => $vehicle->toTeamPayload($vehicle->team?->active_vehicle_id) + [
                'response_team_id' => $vehicle->response_team_id,
                'team_name' => $vehicle->team?->team_name ?? 'Unassigned',
            ],
            $request,
        ));
    }

    public function vehicle(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorizeAdminVehicleAccess($request);
        $vehicle->load(['team:id,team_name,active_vehicle_id', 'openMaintenanceReports']);

        return response()->json($vehicle->toTeamPayload($vehicle->team?->active_vehicle_id) + [
            'response_team_id' => $vehicle->response_team_id,
            'team_name' => $vehicle->team?->team_name ?? 'Unassigned',
        ]);
    }

    public function saveVehicle(Request $request, ?int $id = null): JsonResponse
    {
        $this->authorizeAdminVehicleAccess($request);
        if (! $request->filled('plate_number') && $request->filled('number')) {
            $request->merge(['plate_number' => $request->input('number')]);
        }
        if (! $request->filled('vehicle_type') && $request->filled('type')) {
            $request->merge(['vehicle_type' => strtolower(trim((string) $request->input('type')))]);
        } elseif ($request->filled('vehicle_type')) {
            $request->merge(['vehicle_type' => strtolower(trim((string) $request->input('vehicle_type')))]);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'plate_number' => ['required', 'string', 'max:40', Rule::unique('vehicles', 'plate_number')->ignore($id)],
            'vehicle_type' => ['required', Rule::in(Vehicle::TYPES)],
            'response_team_id' => ['nullable', 'integer', 'exists:response_teams,id'],
            'status' => ['required', Rule::in(Vehicle::STATUSES)],
            'maintenance_warning' => ['nullable', 'string', 'max:500'],
            'photo_url' => ['nullable', 'string', 'max:500'],
        ]);

        $data['plate_number'] = strtoupper(trim($data['plate_number']));
        $data['name'] = isset($data['name']) && trim($data['name']) !== '' ? trim($data['name']) : null;
        $data['maintenance_warning'] = isset($data['maintenance_warning']) && trim($data['maintenance_warning']) !== ''
            ? trim($data['maintenance_warning'])
            : null;
        if (array_key_exists('photo_url', $data)) {
            $data['photo_path'] = $data['photo_url'] !== '' ? $data['photo_url'] : null;
            unset($data['photo_url']);
        }

        return DB::transaction(function () use ($id, $data): JsonResponse {
            $vehicle = $id ? Vehicle::query()->whereKey($id)->lockForUpdate()->firstOrFail() : new Vehicle;
            abort_if($vehicle->exists && $vehicle->assigned_report_id !== null && in_array($data['status'], ['offline', 'maintenance'], true), 422, 'Complete the active dispatch before placing this vehicle offline or under maintenance.');

            $previousTeamId = $vehicle->exists ? $vehicle->response_team_id : null;
            $vehicle->fill($data)->save();

            if ($previousTeamId && $previousTeamId !== $vehicle->response_team_id) {
                $oldTeam = ResponseTeam::query()->lockForUpdate()->find($previousTeamId);
                if ($oldTeam && $oldTeam->active_vehicle_id === $vehicle->id) {
                    $fallback = Vehicle::query()->where('response_team_id', $oldTeam->id)->where('status', 'available')->orderBy('id')->first();
                    $oldTeam->update(['active_vehicle_id' => $fallback?->id]);
                }
            }

            if ($vehicle->response_team_id) {
                $team = ResponseTeam::query()->lockForUpdate()->find($vehicle->response_team_id);
                if ($team) {
                    if ($vehicle->status === 'maintenance' && $team->active_vehicle_id === $vehicle->id) {
                        $fallback = Vehicle::query()->where('response_team_id', $team->id)->where('id', '!=', $vehicle->id)->where('status', 'available')->orderBy('id')->first();
                        $team->update(['active_vehicle_id' => $fallback?->id]);
                    } elseif (! $team->active_vehicle_id && $vehicle->status === 'available') {
                        $team->update(['active_vehicle_id' => $vehicle->id]);
                    }
                }
            }

            event(new VehicleLocationUpdated($vehicle->refresh()));
            Audit::record('SAVE_VEHICLE', "Mobile vehicle {$vehicle->plate_number} ({$vehicle->status})");

            $vehicle->load(['team:id,team_name,active_vehicle_id', 'openMaintenanceReports']);

            return response()->json($vehicle->toTeamPayload($vehicle->team?->active_vehicle_id) + [
                'response_team_id' => $vehicle->response_team_id,
                'team_name' => $vehicle->team?->team_name ?? 'Unassigned',
            ], $id ? 200 : 201);
        });
    }

    public function maintenanceReports(Request $request, VehicleMaintenanceService $service): JsonResponse
    {
        $this->authorizeAdminVehicleAccess($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'acknowledged', 'approved', 'in_maintenance', 'resolved', 'rejected'])],
        ]);

        $query = MaintenanceReport::query()
            ->with(['vehicle', 'team', 'reporter', 'reviewer'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s));

        return response()->json(MobilePage::make(
            $query,
            fn (MaintenanceReport $report): array => $service->formatReport($report),
            $request,
        ));
    }

    public function maintenanceReport(Request $request, MaintenanceReport $maintenanceReport, VehicleMaintenanceService $service): JsonResponse
    {
        $this->authorizeAdminVehicleAccess($request);

        return response()->json($service->formatReport($maintenanceReport));
    }

    public function reviewMaintenanceReport(Request $request, MaintenanceReport $maintenanceReport, VehicleMaintenanceService $service): JsonResponse
    {
        $this->authorizeAdminVehicleAccess($request);
        $data = $request->validate([
            'status' => ['required', Rule::in(['acknowledged', 'approved', 'in_maintenance', 'resolved', 'rejected'])],
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $service->reviewReport($request->user(), $maintenanceReport, $data);

        return response()->json($service->formatReport($updated));
    }
}
