<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->string('status', 30)->default('available')->change();
            $table->string('name', 120)->nullable()->after('response_team_id');
            $table->string('photo_path')->nullable()->after('plate_number');
            $table->string('maintenance_warning', 500)->nullable()->after('status');
        });

        Schema::table('response_teams', function (Blueprint $table): void {
            $table->foreignId('active_vehicle_id')->nullable()->after('member_roles_enabled')
                ->constrained('vehicles')->nullOnDelete();
        });

        // Backfill active_vehicle_id for teams that already have vehicles
        foreach (DB::table('response_teams')->pluck('id') as $teamId) {
            $vehicleId = DB::table('vehicles')
                ->where('response_team_id', $teamId)
                ->orderByRaw("CASE WHEN status = 'available' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->value('id');
            if ($vehicleId) {
                DB::table('response_teams')->where('id', $teamId)->update(['active_vehicle_id' => $vehicleId]);
            }
        }

        Schema::create('maintenance_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('response_team_id')->nullable()->constrained('response_teams')->nullOnDelete();
            $table->foreignId('reported_by')->constrained('drivers')->cascadeOnDelete();
            $table->string('issue_type', 80);
            $table->string('severity', 20)->default('medium');
            $table->unsignedInteger('mileage')->nullable();
            $table->text('description');
            $table->boolean('recommend_out_of_service')->default(false);
            $table->string('status', 30)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index(['vehicle_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_reports');

        Schema::table('response_teams', function (Blueprint $table): void {
            $table->dropForeign(['active_vehicle_id']);
            $table->dropColumn('active_vehicle_id');
        });

        DB::table('vehicles')->where('status', 'maintenance')->update(['status' => 'offline']);

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropColumn(['name', 'photo_path', 'maintenance_warning']);
            $table->enum('status', ['en-route', 'on-scene', 'available', 'returning', 'offline'])->default('available')->change();
        });
    }
};
