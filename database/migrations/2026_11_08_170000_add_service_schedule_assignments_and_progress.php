<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SQLite must disable foreign keys outside a transaction when rebuilding a referenced table.
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('service_schedules', function (Blueprint $table): void {
            $table->string('status', 30)->default('pending')->change();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->index(['response_team_id', 'preferred_date']);
        });
        Schema::create('service_schedule_assignments', function (Blueprint $table): void {
            $table->foreignId('service_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->primary(['service_schedule_id', 'driver_id']);
        });
        DB::table('driver_notifications')->whereNotNull('schedule_id')->where('kind', 'schedule_assigned')
            ->orderBy('id')->chunkById(200, function ($notifications): void {
                foreach ($notifications as $notification) {
                    DB::table('service_schedule_assignments')->insertOrIgnore([
                        'service_schedule_id' => $notification->schedule_id, 'driver_id' => $notification->driver_id,
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('service_schedules')->whereIn('status', ['en-route', 'on-scene'])->update(['status' => 'confirmed']);
        Schema::dropIfExists('service_schedule_assignments');
        Schema::table('service_schedules', function (Blueprint $table): void {
            $table->dropIndex(['response_team_id', 'preferred_date']);
            $table->dropColumn(['started_at', 'arrived_at', 'completed_at']);
            $table->enum('status', ['pending', 'acknowledged', 'confirmed', 'declined', 'completed', 'cancelled'])->default('pending')->change();
        });
    }
};
