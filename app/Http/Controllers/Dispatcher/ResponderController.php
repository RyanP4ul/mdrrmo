<?php

namespace App\Http\Controllers\Dispatcher;

use App\Http\Controllers\Controller;
use App\Models\DriverResponderUser;
use App\Models\ResponseTeam;
use App\Models\Shift;
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
        $f = $request->only('search', 'status');

        return Inertia::render('Dispatcher/Responders', [
            'filters' => $f,
            'drivers' => DriverResponderUser::query()->orderBy('first_name')->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn (DriverResponderUser $driver): array => ['id' => $driver->getKey(), 'name' => $driver->full_name]),
            'teams' => ResponseTeam::with(['members', 'shifts' => fn ($q) => $q->latest('start_time')->limit(5), 'shifts.members:id,name,role'])
                ->when($f['search'] ?? null, fn ($q, $s) => $q->where('team_name', 'like', "%$s%"))
                ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->orderBy('team_name')->paginate(5)->withQueryString(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        DB::transaction(fn () => ResponseTeam::create(['team_name' => $data['team_name'], 'status' => $data['status']])->members()->createMany($data['members']));
        Audit::record('CREATE_TEAM', "Created team {$data['team_name']}");

        return back()->with('success', 'Team created.');
    }

    public function update(Request $request, ResponseTeam $team)
    {
        $data = $this->validated($request, $team);
        DB::transaction(function () use ($team, $data) {
            $lockedTeam = ResponseTeam::query()->lockForUpdate()->findOrFail($team->getKey());
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
                $lockedTeam->members()->updateOrCreate(['id' => $m['id'] ?? null], ['user_id' => $m['user_id'], 'name' => $m['name'], 'role' => $m['role'], 'status' => $m['status'] ?? 'active']);
            }
        });
        Audit::record('UPDATE_TEAM', "Updated team {$data['team_name']}");

        return back()->with('success', 'Team updated.');
    }

    public function destroy(ResponseTeam $team)
    {
        $team->delete();
        Audit::record('DELETE_TEAM', "Deleted team {$team->team_name}");

        return back()->with('success', 'Team deleted.');
    }

    public function storeShift(Request $request, ResponseTeam $team)
    {
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
        abort_unless($shift->response_team_id === $team->getKey(), 404);
        $shift->update($request->validate(['status' => ['required', 'in:on-shift,off-shift,on-break']]));

        return back()->with('success', 'Shift updated.');
    }

    private function validated(Request $request, ?ResponseTeam $team = null): array
    {
        $data = $request->validate([
            'team_name' => ['required', 'string', 'max:80', Rule::unique('response_teams', 'team_name')->ignore($team)],
            'status' => ['required', 'in:active,inactive,on-leave,off-duty'],
            'members' => ['required', 'array', 'min:1'],
            'members.*.id' => $team
                ? ['nullable', 'integer', 'distinct', Rule::exists('team_members', 'id')->where('response_team_id', $team->getKey())]
                : ['prohibited'],
            'members.*.user_id' => ['required', 'integer', 'distinct', Rule::exists('drivers', 'id')],
            'members.*.status' => ['nullable', 'in:active,inactive,on-leave,off-duty'],
        ]);

        $drivers = DriverResponderUser::query()->whereIn('id', array_column($data['members'], 'user_id'))->get()->keyBy('id');
        foreach ($data['members'] as &$member) {
            $member['name'] = $drivers[$member['user_id']]->full_name;
            $member['role'] = 'Driver/Responder';
        }

        return $data;
    }
}
