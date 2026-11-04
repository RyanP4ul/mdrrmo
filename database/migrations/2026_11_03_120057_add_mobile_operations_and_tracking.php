<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automatic_dispatch_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
        DB::table('automatic_dispatch_settings')->insert(['id' => 1, 'enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('automatic_dispatch_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emergency_report_id')->unique()->constrained('emergency_reports')->cascadeOnDelete();
            $table->string('state', 30);
            $table->string('reason');
            $table->timestampTz('attempted_at');
        });
        Schema::create('vehicle_tracking_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->foreignId('emergency_report_id')->constrained('emergency_reports')->cascadeOnDelete();
            $table->boolean('background')->default(false);
            $table->string('consent_version', 30);
            $table->timestampTz('started_at');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('stopped_at')->nullable();
            $table->timestampTz('last_ping_at')->nullable();
            $table->timestampTz('last_observed_at')->nullable();
            $table->unsignedBigInteger('last_sequence')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_tracking_sessions');
        Schema::dropIfExists('automatic_dispatch_attempts');
        Schema::dropIfExists('automatic_dispatch_settings');
    }
};
