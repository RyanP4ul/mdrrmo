<?php

namespace App\Services;

use App\Events\TeamRoleUpdated;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\ResponseTeam;
use App\Models\ServiceSchedule;
use App\Models\Vehicle;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TeamRoles
{
    public function busy(ResponseTeam $team): bool
    {
        return EmergencyReport::where('response_team_id', $team->id)->whereIn('status', ['acknowledged', 'dispatched'])->exists()
            || ServiceSchedule::where('response_team_id', $team->id)->whereIn('status', ['en-route', 'on-scene'])->exists();
    }

    public function ensureIdle(ResponseTeam $team): void
    {
        if ($this->busy($team)) {
            $message = 'Complete active dispatches or service trips before changing the team driver.';
            if (request()->expectsJson() || request()->is('api/*')) {
                abort(409, $message);
            }
            throw ValidationException::withMessages(['driver_user_id' => $message]);
        }
    }

    public function memberships(DriverResponderUser $user): array
    {
        return ResponseTeam::where('status', 'active')
            ->whereHas('members', fn ($query) => $query->where('user_id', $user->id)->where('status', 'active'))
            ->with(['members.user', 'vehicles.openMaintenanceReports', 'activeVehicle'])
            ->orderBy('id')->get()->map(function (ResponseTeam $team) use ($user): array {
                $self = $team->members->first(fn ($member) => $member->user_id === $user->id && $member->status === 'active');
                $driver = $team->members->first(fn ($member) => strtolower((string) $member->role) === 'driver');
                $activeVehicle = $team->resolveActiveVehicle();
                $activeVehicleId = $activeVehicle?->id;
                $vehicles = $team->vehicles
                    ->map(fn (Vehicle $vehicle): array => $vehicle->toTeamPayload($activeVehicleId))
                    ->values()
                    ->all();

                return [
                    'team_id' => $team->id,
                    'team_name' => $team->team_name,
                    'member_id' => $self->id,
                    'role' => strtolower((string) $self->role) === 'driver' ? 'Driver' : 'Responder',
                    'driver_member_id' => $driver?->id,
                    'driver_name' => $driver?->user?->full_name ?? $driver?->name,
                    'can_be_driver' => $driver === null || $driver->id === $self->id,
                    'locked' => $this->busy($team),
                    'active_vehicle_id' => $activeVehicleId,
                    'active_vehicle' => $activeVehicle?->toTeamPayload($activeVehicleId),
                    'vehicles' => $vehicles,
                ];
            })->all();
    }

    public function changeOwnRole(ResponseTeam $team, DriverResponderUser $user, string $role): void
    {
        DB::transaction(function () use ($team, $user, $role): void {
            $team = ResponseTeam::whereKey($team->id)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $member = $team->members()->where('user_id', $user->id)->where('status', 'active')->orderBy('id')->lockForUpdate()->firstOrFail();
            if ($member->role === $role && $team->member_roles_enabled) {
                return;
            }
            $this->ensureIdle($team);
            $other = $team->members()->whereKeyNot($member->id)->whereRaw("LOWER(role) = 'driver'")->first();
            abort_if($role === 'Driver' && $other !== null, 409, 'This team already has a Driver. Ask the current Driver to switch to Responder or ask dispatch to reassign the role.');
            $team->update(['member_roles_enabled' => true]);
            $team->members()->whereIn('role', ['Driver/Responder', 'driver/responder'])->update(['role' => 'Responder']);
            $member->update(['role' => $role]);
            Audit::record('CHANGE_TEAM_ROLE', "Team {$team->id} member {$member->id} changed to {$role}");
            TeamRoleUpdated::dispatch($team->id);
        }, 3);
    }

    public function switchVehicle(ResponseTeam $team, DriverResponderUser $user, int $vehicleId): Vehicle
    {
        return DB::transaction(function () use ($team, $user, $vehicleId): Vehicle {
            $lockedTeam = ResponseTeam::whereKey($team->id)->where('status', 'active')->lockForUpdate()->firstOrFail();
            if ($this->busy($lockedTeam)) {
                $message = 'Complete active dispatches or service trips before switching ambulances.';
                if (request()->expectsJson() || request()->is('api/*')) {
                    abort(409, $message);
                }
                throw ValidationException::withMessages(['vehicle_id' => $message]);
            }

            $vehicle = Vehicle::whereKey($vehicleId)->where('response_team_id', $lockedTeam->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('switchTeamVehicle', [$vehicle, $lockedTeam]);

            if ($vehicle->status === 'maintenance' || $vehicle->assigned_report_id !== null) {
                $message = 'This ambulance is currently under maintenance and cannot be selected.';
                if (request()->expectsJson() || request()->is('api/*')) {
                    abort(422, $message);
                }
                throw ValidationException::withMessages(['vehicle_id' => $message]);
            }

            $lockedTeam->update(['active_vehicle_id' => $vehicle->id]);
            Audit::record('SWITCH_TEAM_VEHICLE', "Team {$lockedTeam->team_name} switched active vehicle to {$vehicle->plate_number}");

            return $vehicle->refresh();
        }, 3);
    }

    public function reassign(ResponseTeam $team, ?int $driverUserId): void
    {
        DB::transaction(function () use ($team, $driverUserId): void {
            $team = ResponseTeam::whereKey($team->id)->lockForUpdate()->firstOrFail();
            $current = $team->members()->whereRaw("LOWER(role) = 'driver'")->first();
            if ($current?->user_id !== $driverUserId) {
                $this->ensureIdle($team);
            }
            $target = $driverUserId === null ? null : $team->members()->where('user_id', $driverUserId)->where('status', 'active')
                ->whereHas('user', fn ($query) => $query->where('status', 'active'))->firstOrFail();
            $team->members()->whereRaw("LOWER(role) = 'driver'")->update(['role' => 'Responder']);
            if ($target) {
                $team->members()->whereKey($target->id)->update(['role' => 'Driver']);
            }
            $team->members()->whereIn('role', ['Driver/Responder', 'driver/responder'])->update(['role' => 'Responder']);
            $team->update(['member_roles_enabled' => true]);
            TeamRoleUpdated::dispatch($team->id);
        }, 3);
    }
}
