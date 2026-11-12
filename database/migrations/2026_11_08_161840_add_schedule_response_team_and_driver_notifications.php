<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_schedules', function (Blueprint $table): void {
            $table->foreignId('response_team_id')->nullable()->after('resident_id')
                ->constrained('response_teams')->nullOnDelete();
        });

        Schema::table('driver_notifications', function (Blueprint $table): void {
            $table->dropForeign(['report_id']);
            $table->unsignedBigInteger('report_id')->nullable()->change();
            $table->foreignId('schedule_id')->nullable()->after('report_id')
                ->constrained('service_schedules')->cascadeOnDelete();
            $table->foreign('report_id')->references('id')->on('emergency_reports')->cascadeOnDelete();
            $table->unique(['driver_id', 'schedule_id', 'kind']);
        });
    }

    public function down(): void
    {
        DB::table('driver_notifications')->whereNotNull('schedule_id')->delete();

        Schema::table('driver_notifications', function (Blueprint $table): void {
            $table->dropUnique(['driver_id', 'schedule_id', 'kind']);
            $table->dropForeign(['schedule_id']);
            $table->dropColumn('schedule_id');
            $table->dropForeign(['report_id']);
            $table->unsignedBigInteger('report_id')->nullable(false)->change();
            $table->foreign('report_id')->references('id')->on('emergency_reports')->cascadeOnDelete();
        });

        Schema::table('service_schedules', function (Blueprint $table): void {
            $table->dropForeign(['response_team_id']);
            $table->dropColumn('response_team_id');
        });
    }
};
