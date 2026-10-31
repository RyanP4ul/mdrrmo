<?php

namespace App\Http\Controllers\Dispatcher;

use App\Http\Controllers\Controller;
use App\Models\{ResponseTeam, Shift};
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ResponderController extends Controller
{
    public function index(Request $request)
    {
        $f = $request->only('search', 'status');

        return Inertia::render('Dispatcher/Responders', [
            'filters' => $f,
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
            $team->update(['team_name' => $data['team_name'], 'status' => $data['status']]);
            $team->members()->whereNotIn('id', collect($data['members'])->pluck('id')->filter())->delete();
            foreach ($data['members'] as $m) {
                $team->members()->updateOrCreate(['id' => $m['id'] ?? null], ['name' => $m['name'], 'role' => $m['role'] ?? null, 'status' => $m['status'] ?? 'active']);
            }
        });
        Audit::record('UPDATE_TEAM', "Updated team {$team->team_name}");

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
            'member_ids' => ['required', 'array', 'min:1'], 'member_ids.*' => ['exists:team_members,id'],
        ]);
        $shift = $team->shifts()->create(collect($d)->except('member_ids')->all());
        $shift->members()->sync($d['member_ids']);

        return back()->with('success', 'Shift scheduled.');
    }

    public function updateShift(Request $request, Shift $shift)
    {
        $shift->update($request->validate(['status' => ['required', 'in:on-shift,off-shift,on-break']]));
        return back()->with('success', 'Shift updated.');
    }

    private function validated(Request $request, ?ResponseTeam $team = null): array
    {
        return $request->validate([
            'team_name' => ['required', 'string', 'max:80', Rule::unique('response_teams', 'team_name')->ignore($team)],
            'status' => ['required', 'in:active,inactive,on-leave,off-duty'],
            'members' => ['required', 'array', 'min:1'],
            'members.*.id' => ['nullable', 'integer'],
            'members.*.name' => ['required', 'string', 'max:120'],
            'members.*.role' => ['nullable', 'string', 'max:60'],
            'members.*.status' => ['nullable', 'in:active,inactive,on-leave,off-duty'],
        ]);
    }
}
