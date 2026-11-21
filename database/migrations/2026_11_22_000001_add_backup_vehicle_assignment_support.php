<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emergency_reports', function (Blueprint $table): void {
            $table->foreignId('parent_report_id')
                ->nullable()
                ->after('id')
                ->constrained('emergency_reports')
                ->nullOnDelete();
            $table->foreignId('requested_by_team_id')
                ->nullable()
                ->after('response_team_id')
                ->constrained('response_teams')
                ->nullOnDelete();
            $table->string('requested_vehicle_type', 40)
                ->nullable()
                ->after('requested_by_team_id');
        });

        Schema::create('dispatch_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emergency_report_id')
                ->constrained('emergency_reports')
                ->cascadeOnDelete();
            $table->foreignId('response_team_id')
                ->nullable()
                ->constrained('response_teams')
                ->nullOnDelete();
            $table->foreignId('vehicle_id')
                ->nullable()
                ->constrained('vehicles')
                ->nullOnDelete();
            $table->string('assignment_type', 20)->default('primary');
            $table->string('status', 20)->default('dispatched');
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->index(['emergency_report_id', 'status']);
            $table->index(['vehicle_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_assignments');

        Schema::table('emergency_reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_report_id');
            $table->dropConstrainedForeignId('requested_by_team_id');
            $table->dropColumn('requested_vehicle_type');
        });
    }
};
