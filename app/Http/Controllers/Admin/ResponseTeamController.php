<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DriverResponderUser;
use App\Models\ResponseTeam;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ResponseTeamController extends Controller
{
    public function getTeams(Request $request)
    {
        return ResponseTeam::query()
            ->whereHas('members', fn ($query) => $query->where('user_id', $request->user()->getKey()))
            ->with(['members' => fn ($query) => $query->select('id', 'response_team_id', 'user_id', 'name', 'role', 'status')])
            ->get()
            ->flatMap(fn (ResponseTeam $team) => $team->members->map(fn ($member): array => [
                'name' => $member->name,
                'role' => $member->role ?? 'Team member',
                'online' => $team->status === 'active' && $member->status === 'active',
            ]))
            ->values();
    }
    
    public function index(Request $request)
    {
        $f = $request->only('search', 'status', 'shift');
        $activeShift = in_array($f['shift'] ?? null, ResponseTeam::SHIFT_TEAMS, true)
            ? $f['shift']
            : 'day';

        $teams = ResponseTeam::with('members:id,response_team_id,user_id,name,role,status')
            ->where(function ($q) use ($activeShift): void {
                $q->where('shift_team', $activeShift)
                    ->when($activeShift === 'day', fn ($sub) => $sub->orWhereNull('shift_team'));
            })
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where('team_name', 'like', "%{$s}%"))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('team_name')
            ->paginate(8)
            ->withQueryString();

        return Inertia::render('Admin/ResponseTeams', [
            'filters' => [
                'search' => $f['search'] ?? '',
                'status' => $f['status'] ?? '',
                'shift' => $activeShift,
            ],
            'shift' => $activeShift,
            'teams' => $teams,
            'shiftMembers' => $this->shiftMembersFor($activeShift),
        ]);
    }

    /**
     * Build a deduplicated list of personnel assigned to the selected shift and their status.
     *
     * @return array<int, array<string, mixed>>
     */
    private function shiftMembersFor(string $shiftTeam): array
    {
        $drivers = DriverResponderUser::query()
            ->where('shift_team', $shiftTeam)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'status', 'shift_team']);

        $shiftTeams = ResponseTeam::query()
            ->where(function ($q) use ($shiftTeam): void {
                $q->where('shift_team', $shiftTeam)
                    ->when($shiftTeam === 'day', fn ($sub) => $sub->orWhereNull('shift_team'));
            })
            ->with(['members:id,response_team_id,user_id,name,role,status'])
            ->get(['id', 'team_name', 'status', 'shift_team']);

        $membershipsByUser = [];
        foreach ($shiftTeams as $team) {
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
                'assignment_type' => $assignmentType,
                'role' => $role,
                'team_names' => $entries->pluck('team_name')->unique()->values()->all(),
            ];
        })->values()->all();
    }
}
