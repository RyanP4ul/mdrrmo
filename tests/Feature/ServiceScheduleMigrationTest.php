<?php

namespace Tests\Feature;

use App\Models\DriverNotification;
use App\Models\DriverResponderUser;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceScheduleMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_existing_receipts_and_schedules_survive_migration_and_become_durable_assignments(): void
    {
        $driver = DriverResponderUser::factory()->create();
        $team = ResponseTeam::create(['team_name' => 'Response team', 'status' => 'active']);
        $schedule = ResidentUser::factory()->accepted()->create()->schedules()->create([
            'service_type' => 'Medical Mission', 'preferred_date' => '2030-01-02', 'preferred_time' => '10:00',
            'address' => 'Town hall', 'status' => 'confirmed', 'response_team_id' => $team->id,
        ]);
        DriverNotification::scheduleReceipt($driver->id, $schedule, $team->team_name);
        $migration = require database_path('migrations/2026_11_08_170000_add_service_schedule_assignments_and_progress.php');
        $migration->down();
        $migration->up();

        $this->assertDatabaseCount('driver_notifications', 1);
        $this->assertDatabaseHas('service_schedule_assignments', ['service_schedule_id' => $schedule->id, 'driver_id' => $driver->id]);
        Sanctum::actingAs($driver, ['*']);
        $this->getJson('/api/driver-responder/schedules/'.$schedule->id)->assertOk()->assertJsonPath('status', 'confirmed');
    }
}
