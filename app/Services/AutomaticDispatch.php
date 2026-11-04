<?php

namespace App\Services;

use App\Models\EmergencyReport;
use App\Models\ResponseTeam;
use App\Models\TeamMember;
use Illuminate\Support\Facades\DB;

class AutomaticDispatch
{
    public function drain(int $limit = 25): int
    {
        if (! DB::table('automatic_dispatch_settings')->where('id', 1)->value('enabled')) {
            return 0;
        }
        $ids = EmergencyReport::leftJoin('automatic_dispatch_attempts', 'automatic_dispatch_attempts.emergency_report_id', '=', 'emergency_reports.id')
            ->where('emergency_reports.status', 'pending')->whereNull('emergency_reports.withdrawn_at')
            ->orderByRaw('case when automatic_dispatch_attempts.attempted_at is null then 0 else 1 end')
            ->orderBy('automatic_dispatch_attempts.attempted_at')
            ->orderByRaw("case priority when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->orderBy('emergency_reports.reported_at')->orderBy('emergency_reports.id')->limit(max(1, min(100, $limit)))->pluck('emergency_reports.id');
        $count = 0;
        foreach ($ids as $id) {
            if ($this->attempt($id)) {
                $count++;
            }
        }

        return $count;
    }

    public function attempt(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $settings = DB::table('automatic_dispatch_settings')->where('id', 1)->lockForUpdate()->first();
            if (! $settings?->enabled) {
                return false;
            }
            $report = EmergencyReport::whereKey($id)->lockForUpdate()->first();
            if (! $report || $report->status !== 'pending' || $report->withdrawn_at) {
                return false;
            }
            $report->load('incidentType');
            $teams = ResponseTeam::where('status', 'active')
                ->where(fn ($q) => $q->whereJsonContains('specializations', $report->incidentType->name)->orWhereJsonContains('specializations', '*'))
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($teams as $team) {
                $member = TeamMember::where('response_team_id', $team->id)->where('status', 'active')
                    ->whereHas('user', fn ($q) => $q->where('status', 'active'))
                    ->whereHas('shifts', fn ($q) => $q->where('status', 'on-shift')->where('response_team_id', $team->id)->where('start_time', '<=', now())->where('end_time', '>', now()))
                    ->orderBy('id')->get()->first(fn ($member) => ! EmergencyReport::where('assigned_driver_id', $member->user_id)->whereIn('status', ['acknowledged', 'dispatched'])->exists());
                if (! $member) {
                    continue;
                }
                app(DispatchService::class)->dispatch($report, $team, $member, true);
                $this->receipt($id, 'dispatched', 'Assigned to '.$team->team_name.' using an on-shift eligible driver/responder.');

                return true;
            }
            $this->receipt($id, 'waiting', 'No active capable team with an on-shift free driver/responder. Manual dispatch remains available.');

            return false;
        }, 3);
    }

    private function receipt(int $report, string $state, string $reason): void
    {
        DB::table('automatic_dispatch_attempts')->updateOrInsert(['emergency_report_id' => $report], ['state' => $state, 'reason' => $reason, 'attempted_at' => now()]);
    }
}
