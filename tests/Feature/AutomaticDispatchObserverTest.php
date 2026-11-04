<?php

namespace Tests\Feature;

use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Models\Shift;
use App\Models\TeamMember;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutomaticDispatchObserverTest extends TestCase
{
    use DatabaseMigrations;

    public function test_new_reports_are_dispatched_only_after_the_surrounding_submission_commits(): void
    {
        $driver = DriverResponderUser::factory()->create();
        $resident = ResidentUser::factory()->accepted()->create();
        $type = IncidentType::create(['name' => 'Medical', 'description' => 'Medical emergency', 'priority' => 'high']);
        $team = ResponseTeam::create(['team_name' => 'Alpha', 'status' => 'active', 'specializations' => ['Medical']]);
        $member = TeamMember::create(['response_team_id' => $team->id, 'user_id' => $driver->id, 'name' => $driver->full_name, 'role' => 'Driver', 'status' => 'active']);
        $shift = Shift::create(['response_team_id' => $team->id, 'start_time' => now()->subHour(), 'end_time' => now()->addHour(), 'status' => 'on-shift']);
        $shift->members()->attach($member);
        $vehicle = Vehicle::create(['response_team_id' => $team->id, 'vehicle_type' => 'ambulance', 'plate_number' => 'MED-1', 'status' => 'available']);
        DB::table('automatic_dispatch_settings')->update(['enabled' => true]);
        $report = DB::transaction(function () use ($resident, $type, $vehicle): EmergencyReport {
            $report = $resident->reports()->create(['reference' => 'RPT-AUTO', 'incident_type_id' => $type->id, 'reported_by_type' => $resident->getMorphClass(), 'reporter_name' => $resident->full_name, 'location' => 'Town hall', 'description' => 'Medical emergency', 'lat' => 14.8, 'lng' => 120.9, 'priority' => 'high', 'status' => 'pending', 'reported_at' => now()]);
            $this->assertSame('pending', $report->fresh()->status);
            $this->assertNull($vehicle->fresh()->assigned_report_id);

            return $report;
        });
        $this->assertSame('dispatched', $report->fresh()->status);
        $this->assertSame($driver->id, $report->fresh()->assigned_driver_id);
        $this->assertNull($vehicle->fresh()->assigned_report_id);
        $this->assertDatabaseHas('resident_notifications', ['user_id' => $resident->id, 'title' => 'Help is on the way']);
        $this->assertDatabaseHas('driver_notifications', ['driver_id' => $driver->id, 'report_id' => $report->id, 'kind' => 'assigned']);
        $this->artisan('mobile:auto-dispatch')->assertSuccessful();
        $this->assertDatabaseCount('driver_notifications', 1);
    }
}
