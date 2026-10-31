<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        $f = $request->only('search', 'status');

        return Inertia::render('Admin/ResponseTeams', [
            'filters' => $f,
            'teams' => ResponseTeam::with('members:id,response_team_id,name,role,status')
                ->when($f['search'] ?? null, fn ($q, $s) => $q->where('team_name', 'like', "%{$s}%"))
                ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->orderBy('team_name')
                ->paginate(5)->withQueryString(),
        ]);
    }
}
