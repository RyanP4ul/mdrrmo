<?php

namespace App\Http\Controllers\Dispatcher;

use App\Http\Controllers\Controller;
use App\Models\DriverResponderUser;
use App\Models\ResponseTeam;
use App\Models\Shift;
use App\Services\ArchiveService;
use App\Services\TeamRoles;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ResponderController extends Controller
{
    public function index(Request $request)
    {
        $f = $request->only('search', 'status', 'shift');
        $dispatcherShift = $request->user()?->shift_team;
        $activeShift = in_array($f['shift'] ?? null, ResponseTeam::SHIFT_TEAMS, true)
            ? $f['shift']
            : ($dispatcherShift ?? 'day');
        $canEditShift = $dispatcherShift === null || $dispatcherShift === $activeShift;

        $teamsQuery = ResponseTeam::with(['members', 'vehicles', 'shifts' => fn ($q) => $q->latest('start_time')->limit(5), 'shifts.members:id,name,role'])
            ->where(function ($q) use ($activeShift): void {
                $q->where('shift_team', $activeShift)
                    ->when($activeShift === 'day', fn ($sub) => $sub->orWhereNull('shift_team'));
            })
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where('team_name', 'like', "%$s%"))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('team_name');

        $teams = $teamsQuery->paginate(5)->withQueryString()->through(function (ResponseTeam $team): array {
            $hasDriver = $team->members->contains(
                fn ($member): bool => ($member->status ?? 'active') === 'active'
                    && str_contains(strtolower((string) $member->role), 'driver')
            );
            $vehicle = $hasDriver ? $team->resolveActiveVehicle() : null;
            $vehicleName = $vehicle
                ? ($vehicle->name ?: ucfirst(str_replace('-', ' ', $vehicle->vehicle_type)).' '.$vehicle->plate_number)
                : null;

            return [
                ...$team->toArray(),
                'vehicle_name' => $vehicleName,
            ];
        });

        return Inertia::render('Dispatcher/Responders', [
            'filters' => $f,
            'shiftView' => [
                'active' => $activeShift,
                'dispatcherShift' => $dispatcherShift,
                'canEdit' => $canEditShift,
            ],
            'drivers' => DriverResponderUser::query()->orderBy('first_name')->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name', 'shift_team'])
                ->map(fn (DriverResponderUser $driver): array => [
                    'id' => $driver->getKey(),
                    'name' => $driver->full_name,
                    'shift_team' => $driver->shift_team,
                ]),
            'unassignedShiftDrivers' => DriverResponderUser::query()
                ->where('status', 'active')
                ->whereNull('shift_team')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn (DriverResponderUser $driver): array => [
                    'id' => $driver->getKey(),
                    'name' => $driver->full_name,
                ]),
            'shiftRoster' => $this->buildShiftRoster($activeShift),
            'allMembers' => $this->buildAllMembers(),
            'teams' => $teams,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $dispatcherShift = $request->user()?->shift_team;
        $shiftTeam = $dispatcherShift ?? ($data['shift_team'] ?? 'day');
        $this->authorizeShiftWrite($request, $shiftTeam);

        DB::transaction(function () use ($data, $shiftTeam): void {
            $team = ResponseTeam::create([
                'team_name' => $data['team_name'],
                'status' => $data['status'],
                'shift_team' => $shiftTeam,
            ]);
            $team->members()->createMany($data['members']);
            if (array_key_exists('driver_user_id', $data)) {
                app(TeamRoles::class)->reassign($team, $data['driver_user_id']);
            }
        });
        Audit::record('CREATE_TEAM', "Created team {$data['team_name']}");

        return back()->with('success', 'Team created.');
    }

    public function update(Request $request, ResponseTeam $team)
    {
        $this->authorizeShiftWrite($request, $team->shift_team ?? 'day');
        $data = $this->validated($request, $team);
        DB::transaction(function () use ($team, $data) {
            $lockedTeam = ResponseTeam::query()->lockForUpdate()->findOrFail($team->getKey());
            if (array_key_exists('driver_user_id', $data)) {
                $previousDriver = $lockedTeam->members()->whereRaw("LOWER(role) = 'driver'")->first();
                if ($previousDriver?->user_id !== $data['driver_user_id']) {
                    app(TeamRoles::class)->ensureIdle($lockedTeam);
                    $lockedTeam->members()->whereRaw("LOWER(role) = 'driver'")->update(['role' => 'Responder']);
                }
            } elseif ($lockedTeam->member_roles_enabled) {
                $currentDriver = $lockedTeam->members()->where('role', 'Driver')->first();
                $retained = collect($data['members'])->contains(fn ($member) => ($member['id'] ?? null) === $currentDriver?->id && $member['user_id'] === $currentDriver?->user_id && ($member['status'] ?? 'active') === 'active');
                if ($currentDriver && ! $retained) {
                    app(TeamRoles::class)->ensureIdle($lockedTeam);
                }
            }
            $memberIds = collect($data['members'])->pluck('id')->filter()->unique()->values();
            $validMemberIds = $lockedTeam->members()
                ->whereIn('id', $memberIds)
                ->lockForUpdate()
                ->pluck('id');

            if ($validMemberIds->count() !== $memberIds->count()) {
                throw ValidationException::withMessages([
                    'members' => 'All existing members must belong to the selected response team.',
                ]);
            }

            $lockedTeam->update(['team_name' => $data['team_name'], 'status' => $data['status']]);
            $lockedTeam->members()->whereNotIn('id', $memberIds)->delete();

            foreach ($data['members'] as $m) {
                if (! array_key_exists('driver_user_id', $data) && $lockedTeam->member_roles_enabled) {
                    $current = $lockedTeam->members()->whereKey($m['id'] ?? 0)->first();
                    $m['role'] = $current?->user_id === $m['user_id'] ? $current->role : 'Responder';
                }
                $lockedTeam->members()->updateOrCreate(['id' => $m['id'] ?? null], ['user_id' => $m['user_id'], 'name' => $m['name'], 'role' => $m['role'], 'status' => $m['status'] ?? 'active']);
            }
            if (array_key_exists('driver_user_id', $data)) {
                app(TeamRoles::class)->reassign($lockedTeam, $data['driver_user_id']);
            }
        });
        Audit::record('UPDATE_TEAM', "Updated team {$data['team_name']}");

        return back()->with('success', 'Team updated.');
    }

    public function destroy(Request $request, ResponseTeam $team, ArchiveService $archives)
    {
        $this->authorizeShiftWrite($request, $team->shift_team ?? 'day');
        $archives->archive($team, $request->user());

        return back()->with('success', 'Team moved to Archives.');
    }

    public function storeShift(Request $request, ResponseTeam $team)
    {
        $this->authorizeShiftWrite($request, $team->shift_team ?? 'day');
        $d = $request->validate([
            'start_time' => ['required', 'date'], 'end_time' => ['required', 'date', 'after:start_time'],
            'status' => ['required', 'in:on-shift,off-shift,on-break'],
            'member_ids' => ['required', 'array', 'min:1'], 'member_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('team_members', 'id')->where('response_team_id', $team->getKey()),
            ],
        ]);

        DB::transaction(function () use ($team, $d): void {
            $lockedTeam = ResponseTeam::query()->lockForUpdate()->findOrFail($team->getKey());
            $memberIds = array_values(array_unique($d['member_ids']));
            $validMemberIds = $lockedTeam->members()
                ->whereIn('id', $memberIds)
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            if (count($validMemberIds) !== count($memberIds)) {
                throw ValidationException::withMessages([
                    'member_ids' => 'All shift members must belong to the selected response team.',
                ]);
            }

            $overlapsExistingShift = $lockedTeam->shifts()
                ->where('start_time', '<', $d['end_time'])
                ->where('end_time', '>', $d['start_time'])
                ->exists();

            if ($overlapsExistingShift) {
                throw ValidationException::withMessages([
                    'start_time' => 'This shift overlaps another shift for the selected team.',
                ]);
            }

            $shift = $lockedTeam->shifts()->create(collect($d)->except('member_ids')->all());
            $shift->members()->sync($d['member_ids']);
        });

        return back()->with('success', 'Shift scheduled.');
    }

    public function updateShift(Request $request, ResponseTeam $team, Shift $shift)
    {
        $this->authorizeShiftWrite($request, $team->shift_team ?? 'day');
        abort_unless($shift->response_team_id === $team->getKey(), 404);
        $shift->update($request->validate(['status' => ['required', 'in:on-shift,off-shift,on-break']]));

        return back()->with('success', 'Shift updated.');
    }

    public function updateShiftRoster(Request $request, DriverResponderUser $driver)
    {
        $data = $request->validate([
            'shift_team' => ['nullable', Rule::in(ResponseTeam::SHIFT_TEAMS)],
        ]);

        $dispatcherShift = $request->user()?->shift_team;
        if ($dispatcherShift !== null) {
            if ($data['shift_team'] !== null && $data['shift_team'] !== $dispatcherShift) {
                abort(403, 'You can only assign personnel to your own shift.');
            }
            if ($data['shift_team'] === null && $driver->shift_team !== null && $driver->shift_team !== $dispatcherShift) {
                abort(403, 'You cannot remove personnel from the other shift.');
            }
        }

        $driver->update(['shift_team' => $data['shift_team']]);
        $label = $data['shift_team'] ? ucfirst($data['shift_team']).' Team' : 'Unassigned';
        Audit::record('UPDATE_SHIFT_ROSTER', "Assigned {$driver->full_name} to {$label}");

        return back()->with('success', 'Shift roster updated.');
    }

    private function authorizeShiftWrite(Request $request, ?string $targetShift): void
    {
        $dispatcherShift = $request->user()?->shift_team;
        if ($dispatcherShift !== null && $targetShift !== null && $targetShift !== $dispatcherShift) {
            abort(403, 'You can only manage assignments for your own shift.');
        }
    }

    /**
     * Build deduplicated shift roster counts (Paired > Solo > Unassigned).
     *
     * @return array<string, mixed>
     */
    private function buildShiftRoster(string $shiftTeam): array
    {
        $drivers = DriverResponderUser::query()
            ->where('status', 'active')
            ->where('shift_team', $shiftTeam)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'status', 'shift_team']);

        $activeTeams = ResponseTeam::query()
            ->where('status', 'active')
            ->where(function ($q) use ($shiftTeam): void {
                $q->where('shift_team', $shiftTeam)
                    ->when($shiftTeam === 'day', fn ($sub) => $sub->orWhereNull('shift_team'));
            })
            ->with(['members' => fn ($q) => $q->where('status', 'active')->whereNotNull('user_id')])
            ->get(['id', 'team_name', 'shift_team']);

        $membershipsByUser = [];
        foreach ($activeTeams as $team) {
            $activeCount = $team->members->count();
            foreach ($team->members as $member) {
                $membershipsByUser[$member->user_id][] = [
                    'team_name' => $team->team_name,
                    'team_size' => $activeCount,
                    'role' => $member->role,
                    'member_status' => $member->status,
                ];
            }
        }

        $roster = $drivers->map(function (DriverResponderUser $driver) use ($membershipsByUser): array {
            $entries = collect($membershipsByUser[$driver->getKey()] ?? []);
            $hasPaired = $entries->contains(fn (array $e) => $e['team_size'] >= 2);
            $hasSolo = $entries->contains(fn (array $e) => $e['team_size'] === 1);

            $assignmentType = $hasPaired ? 'paired' : ($hasSolo ? 'solo' : 'unassigned');
            $role = $entries->contains(fn (array $e) => strcasecmp((string) $e['role'], 'driver') === 0)
                ? 'Driver'
                : ($entries->isNotEmpty() ? 'Responder' : null);
            $memberStatus = $entries->pluck('member_status')->filter()->first();

            return [
                'id' => $driver->getKey(),
                'name' => $driver->full_name,
                'status' => $memberStatus ?? $driver->status ?? 'active',
                'assignment_type' => $assignmentType,
                'role' => $role,
                'team_names' => $entries->pluck('team_name')->unique()->values()->all(),
            ];
        });

        return [
            'shift' => $shiftTeam,
            'total' => $roster->count(),
            'pairedCount' => $roster->where('assignment_type', 'paired')->count(),
            'soloCount' => $roster->where('assignment_type', 'solo')->count(),
            'unassignedCount' => $roster->where('assignment_type', 'unassigned')->count(),
            'driversCount' => $roster->where('role', 'Driver')->count(),
            'respondersCount' => $roster->where('role', 'Responder')->count(),
            'members' => $roster->values()->all(),
        ];
    }

    /**
     * Build the full directory of all driver/responder personnel with team & status details.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildAllMembers(): array
    {
        $drivers = DriverResponderUser::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'status', 'shift_team']);

        $allTeams = ResponseTeam::query()
            ->with(['members:id,response_team_id,user_id,name,role,status'])
            ->get(['id', 'team_name', 'status', 'shift_team']);

        $membershipsByUser = [];
        foreach ($allTeams as $team) {
            $activeCount = $team->members->where('status', 'active')->count();
            foreach ($team->members as $member) {
                if (! $member->user_id) {
                    continue;
                }
                $membershipsByUser[$member->user_id][] = [
                    'team_name' => $team->team_name,
                    'team_size' => $activeCount,
                    'role' => $member->role,
                    'member_status' => $member->status,
                ];
            }
        }

        return $drivers->map(function (DriverResponderUser $driver) use ($membershipsByUser): array {
            $entries = collect($membershipsByUser[$driver->getKey()] ?? []);
            $hasPaired = $entries->contains(fn (array $e) => $e['team_size'] >= 2);
            $hasSolo = $entries->contains(fn (array $e) => $e['team_size'] === 1);
            $assignmentType = $hasPaired ? 'paired' : ($hasSolo ? 'solo' : 'unassigned');
            $role = $entries->contains(fn (array $e) => strcasecmp((string) $e['role'], 'driver') === 0)
                ? 'Driver'
                : ($entries->isNotEmpty() ? 'Responder' : null);
            $memberStatus = $entries->pluck('member_status')->filter()->first();

            return [
                'id' => $driver->getKey(),
                'name' => $driver->full_name,
                'status' => $driver->status !== 'active' ? $driver->status : ($memberStatus ?? $driver->status),
                'shift_team' => $driver->shift_team,
                'assignment_type' => $assignmentType,
                'role' => $role,
                'team_names' => $entries->pluck('team_name')->unique()->values()->all(),
            ];
        })->values()->all();
    }

    private function validated(Request $request, ?ResponseTeam $team = null): array
    {
        $data = $request->validate([
            'team_name' => ['required', 'string', 'max:80', Rule::unique('response_teams', 'team_name')->ignore($team)],
            'status' => ['required', 'in:active,inactive,on-leave,off-duty'],
            'shift_team' => ['sometimes', 'nullable', Rule::in(ResponseTeam::SHIFT_TEAMS)],
            'members' => ['required', 'array', 'min:1'],
            'members.*.id' => $team
                ? ['nullable', 'integer', 'distinct', Rule::exists('team_members', 'id')->where('response_team_id', $team->getKey())]
                : ['prohibited'],
            'members.*.user_id' => ['required', 'integer', 'distinct', Rule::exists('drivers', 'id')],
            'members.*.status' => ['nullable', 'in:active,inactive,on-leave,off-duty'],
            'driver_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('drivers', 'id')->where('status', 'active')],
        ]);

        if (isset($data['driver_user_id'])) {
            $data['driver_user_id'] = (int) $data['driver_user_id'];
        }
        foreach ($data['members'] as &$validatedMember) {
            $validatedMember['user_id'] = (int) $validatedMember['user_id'];
            if (isset($validatedMember['id'])) {
                $validatedMember['id'] = (int) $validatedMember['id'];
            }
        }
        unset($validatedMember);
        if (isset($data['driver_user_id']) && ! collect($data['members'])->contains(fn ($member) => $member['user_id'] === $data['driver_user_id'] && ($member['status'] ?? 'active') === 'active')) {
            throw ValidationException::withMessages(['driver_user_id' => 'Choose an active member of this team as Driver.']);
        }

        $drivers = DriverResponderUser::query()->whereIn('id', array_column($data['members'], 'user_id'))->get()->keyBy('id');
        foreach ($data['members'] as &$member) {
            $member['name'] = $drivers[$member['user_id']]->full_name;
            $existing = $team?->members()->whereKey($member['id'] ?? 0)->first();
            $member['role'] = array_key_exists('driver_user_id', $data)
                ? ($member['user_id'] === $data['driver_user_id'] ? 'Driver' : 'Responder')
                : ($team?->member_roles_enabled && $existing?->user_id === $member['user_id'] ? $existing->role : ($team?->member_roles_enabled ? 'Responder' : 'Driver/Responder'));
        }

        return $data;
    }
}
