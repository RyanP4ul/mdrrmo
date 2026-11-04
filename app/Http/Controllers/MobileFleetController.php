<?php

namespace App\Http\Controllers;

use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\ResponseTeam;
use App\Models\Shift;
use App\Models\TeamMember;
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
            abort_if($member->exists && ($data['status'] !== 'active' || ($data['user_id'] ?? null) !== $member->user_id) && EmergencyReport::where('response_team_id', $team->id)->where('assigned_driver_id', $member->user_id)->whereIn('status', ['acknowledged', 'dispatched'])->exists(), 422, 'This member has an active assignment.');
            abort_if(! empty($data['user_id']) && $team->members()->where('user_id', $data['user_id'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists(), 422, 'This driver is already in the team.');
            $member->fill($data)->save();
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
}
