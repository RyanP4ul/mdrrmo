<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Schema::table('drivers', function (Blueprint $table) {
            $table->timestampTz('last_seen_at')->nullable()->index();
        });
        Schema::table('emergency_reports', function (Blueprint $table) {
            $table->timestampTz('withdrawn_at')->nullable();
            $table->string('withdrawal_reason', 1000)->nullable();
        });
        Schema::create('dispatch_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emergency_report_id')->unique()->constrained('emergency_reports')->cascadeOnDelete();
            $table->string('destination')->nullable();
            $table->string('vehicle_number')->nullable();
            $table->string('driver_name')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('on_scene_at')->nullable();
            $table->timestampTz('picked_up_at')->nullable();
            $table->timestampTz('arrived_at')->nullable();
            $table->decimal('distance_km', 9, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('driver_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->foreignId('report_id')->constrained('emergency_reports')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('title');
            $table->text('message');
            $table->timestampTz('read_at')->nullable();
            $table->timestamps();
            $table->unique(['driver_id', 'report_id', 'kind']);
        });
        DB::table('emergency_reports')->whereNotNull('assigned_driver_id')
            ->whereIn('status', ['acknowledged', 'dispatched'])->orderBy('id')->chunkById(200, function ($reports): void {
                foreach ($reports as $report) {
                    DB::table('driver_notifications')->insert([
                        'driver_id' => $report->assigned_driver_id, 'report_id' => $report->id, 'kind' => 'assigned',
                        'title' => 'Assignment received', 'message' => 'Assignment: '.$report->reference,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });
        Schema::create('account_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->string('account_key')->unique();
            $table->string('status', 30)->default('pending');
            $table->text('reason')->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_password_reset_tokens');
        Schema::dropIfExists('account_deletion_requests');
        Schema::dropIfExists('driver_notifications');
        Schema::dropIfExists('dispatch_trips');
        Schema::table('emergency_reports', fn (Blueprint $table) => $table->dropColumn(['withdrawn_at', 'withdrawal_reason']));
        Schema::table('drivers', function (Blueprint $table): void {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
