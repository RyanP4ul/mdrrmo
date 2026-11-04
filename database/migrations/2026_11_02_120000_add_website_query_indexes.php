<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_members', function (Blueprint $table): void {
            $table->index(['response_team_id', 'user_id'], 'team_members_team_user_index');
        });

        Schema::table('shifts', function (Blueprint $table): void {
            $table->index(['response_team_id', 'status', 'start_time'], 'shifts_team_status_start_index');
        });

        Schema::table('emergency_reports', function (Blueprint $table): void {
            $table->index(['assigned_driver_id', 'status'], 'emergency_reports_driver_status_index');
            $table->index(['response_team_id', 'status'], 'emergency_reports_team_status_index');
            $table->index(['reported_by_type', 'reported_by', 'reported_at'], 'emergency_reports_reporter_date_index');
            $table->index(['status', 'reported_at'], 'emergency_reports_status_date_index');
        });

        Schema::table('service_schedules', function (Blueprint $table): void {
            $table->index(['resident_id', 'preferred_date'], 'service_schedules_resident_date_index');
            $table->index(['preferred_date', 'status'], 'service_schedules_date_status_index');
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->index(['response_team_id', 'status'], 'vehicles_team_status_index');
        });

        Schema::table('resident_notifications', function (Blueprint $table): void {
            $table->index(['user_id', 'read_at', 'created_at'], 'resident_notifications_user_read_date_index');
        });

        Schema::table('hospitals', function (Blueprint $table): void {
            $table->index(['lat', 'lng'], 'hospitals_location_index');
        });
    }

    public function down(): void
    {
        Schema::table('hospitals', function (Blueprint $table): void {
            $table->dropIndex('hospitals_location_index');
        });

        Schema::table('resident_notifications', function (Blueprint $table): void {
            $table->dropIndex('resident_notifications_user_read_date_index');
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropIndex('vehicles_team_status_index');
        });

        Schema::table('service_schedules', function (Blueprint $table): void {
            $table->dropIndex('service_schedules_date_status_index');
            $table->dropIndex('service_schedules_resident_date_index');
        });

        Schema::table('emergency_reports', function (Blueprint $table): void {
            $table->dropIndex('emergency_reports_reporter_date_index');
            $table->dropIndex('emergency_reports_status_date_index');
            $table->dropIndex('emergency_reports_team_status_index');
            $table->dropIndex('emergency_reports_driver_status_index');
        });

        Schema::table('shifts', function (Blueprint $table): void {
            $table->dropIndex('shifts_team_status_start_index');
        });

        Schema::table('team_members', function (Blueprint $table): void {
            $table->dropIndex('team_members_team_user_index');
        });
    }
};
