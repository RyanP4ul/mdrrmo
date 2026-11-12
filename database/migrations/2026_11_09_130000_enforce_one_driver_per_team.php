<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('response_teams', function (Blueprint $table): void {
            $table->boolean('member_roles_enabled')->default(false);
        });
        $duplicates = DB::table('team_members')->whereRaw("LOWER(role) = 'driver'")
            ->select('response_team_id')->groupBy('response_team_id')->havingRaw('COUNT(*) > 1')->pluck('response_team_id');
        foreach ($duplicates as $teamId) {
            $members = DB::table('team_members')->where('response_team_id', $teamId)->whereRaw("LOWER(role) = 'driver'")->orderBy('id')->get();
            $assigned = DB::table('emergency_reports')->where('response_team_id', $teamId)
                ->whereIn('status', ['acknowledged', 'dispatched'])->orderByDesc('id')->value('assigned_driver_id');
            $keep = $members->first(fn ($member) => $assigned !== null && $member->user_id === $assigned) ?? $members->first();
            DB::table('team_members')->whereIn('id', $members->pluck('id')->reject(fn ($id) => $id === $keep->id))->update(['role' => 'Responder']);
        }
        DB::table('team_members')->whereRaw("LOWER(role) = 'driver'")->update(['role' => 'Driver']);
        foreach (DB::table('response_teams')->pluck('id') as $teamId) {
            $eligible = DB::table('team_members')->where('response_team_id', $teamId)->where('status', 'active')
                ->whereIn('user_id', DB::table('drivers')->where('status', 'active')->select('id'))->orderBy('id')->get();
            $assigned = DB::table('emergency_reports')->where('response_team_id', $teamId)
                ->whereIn('status', ['acknowledged', 'dispatched'])->orderByDesc('id')->value('assigned_driver_id');
            $driver = $eligible->first(fn ($member) => $assigned !== null && $member->user_id === $assigned)
                ?? $eligible->first(fn ($member) => $member->role === 'Driver') ?? $eligible->first();
            DB::table('team_members')->where('response_team_id', $teamId)
                ->whereIn('role', ['Driver', 'Driver/Responder', 'driver/responder'])->update(['role' => 'Responder']);
            if ($driver) {
                DB::table('team_members')->where('id', $driver->id)->update(['role' => 'Driver']);
            }
            DB::table('response_teams')->where('id', $teamId)->update(['member_roles_enabled' => true]);
        }
        Schema::table('team_members', function (Blueprint $table): void {
            $column = $table->unsignedBigInteger('driver_team_id')->nullable();
            $expression = "CASE WHEN LOWER(role) = 'driver' THEN response_team_id ELSE NULL END";
            if (DB::getDriverName() === 'pgsql') {
                $column->storedAs($expression);
            } else {
                $column->virtualAs($expression);
            }
            $table->unique('driver_team_id', 'team_members_single_driver_unique');
        });
    }

    public function down(): void
    {
        Schema::table('team_members', function (Blueprint $table): void {
            $table->dropUnique('team_members_single_driver_unique');
            $table->dropColumn('driver_team_id');
        });
        Schema::table('response_teams', fn (Blueprint $table) => $table->dropColumn('member_roles_enabled'));
    }
};
