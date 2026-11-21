<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('response_teams')->where('status', 'active')->whereNull('deleted_at')
            ->select('id')->chunkById(100, function ($teams): void {
                foreach ($teams as $row) {
                    DB::transaction(function () use ($row): void {
                        $team = DB::table('response_teams')->where('status', 'active')->whereNull('deleted_at')->lockForUpdate()->find($row->id);
                        if ($team === null) {
                            return;
                        }
                        $members = DB::table('team_members')->where('response_team_id', $team->id)
                            ->where('status', 'active')->orderBy('id')->lockForUpdate()->get();
                        if ($members->count() !== 2 || $members->pluck('user_id')->filter()->unique()->count() !== 2
                            || DB::table('drivers')->whereIn('id', $members->pluck('user_id'))->where('status', 'active')->whereNull('deleted_at')->count() !== 2) {
                            return;
                        }
                        if ($team->member_roles_enabled && $members->pluck('role')->sort()->values()->all() === ['Driver', 'Responder']) {
                            return;
                        }
                        if (DB::table('emergency_reports')->where('response_team_id', $team->id)->whereNull('deleted_at')->whereIn('status', ['acknowledged', 'dispatched'])->exists()
                            || DB::table('service_schedules')->where('response_team_id', $team->id)->whereIn('status', ['en-route', 'on-scene'])->exists()) {
                            return;
                        }

                        $driver = $members->first(fn ($member): bool => strtolower((string) $member->role) === 'driver') ?? $members->first();
                        $partner = $members->firstWhere('id', '!=', $driver->id);
                        DB::table('team_members')->where('response_team_id', $team->id)->whereRaw("LOWER(role) = 'driver'")->update(['role' => 'Responder']);
                        DB::table('team_members')->where('id', $driver->id)->update(['role' => 'Driver']);
                        DB::table('team_members')->where('id', $partner->id)->update(['role' => 'Responder']);
                        DB::table('response_teams')->where('id', $team->id)->update(['member_roles_enabled' => true]);
                    }, 3);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep operational role assignments made since this repair.
    }
};
