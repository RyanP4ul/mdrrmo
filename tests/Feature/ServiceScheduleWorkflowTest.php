<?php

namespace Tests\Feature;

use App\Models\DispatcherUser;
use App\Models\DriverNotification;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Models\ServiceSchedule;
use App\Models\TeamMember;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceScheduleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function team(?DriverResponderUser $driver = null): array
    {
        $driver ??= DriverResponderUser::factory()->create();
        $team = ResponseTeam::create(['team_name' => 'Response team '.ResponseTeam::count(), 'status' => 'active']);
        TeamMember::create(['response_team_id' => $team->id, 'user_id' => $driver->id, 'name' => $driver->full_name, 'status' => 'active']);

        return [$team, $driver];
    }

    private function schedule(array $data = []): ServiceSchedule
    {
        return ResidentUser::factory()->accepted()->create()->schedules()->create($data + [
            'service_type' => 'Ambulance Standby', 'preferred_date' => '2030-01-02', 'preferred_time' => '10:00',
            'address' => 'Municipal Hall, Balagtas, Bulacan', 'status' => 'pending',
        ]);
    }

    public function test_acknowledgement_precedes_assignment_and_retries_do_not_duplicate_notifications(): void
    {
        $this->travelTo(Carbon::parse('2030-01-02 08:00', 'Asia/Manila'));
        [$team, $driver] = $this->team();
        $schedule = $this->schedule();
        $dispatcher = DispatcherUser::factory()->create();
        $this->actingAs($dispatcher)->patch(route('dispatcher.schedule.acknowledge', $schedule))->assertRedirect();
        $this->assertDatabaseHas('service_schedules', ['id' => $schedule->id, 'status' => 'acknowledged', 'response_team_id' => null]);
        $this->assertDatabaseCount('driver_notifications', 0);
        $this->actingAs($dispatcher)->patch(route('dispatcher.schedule.acknowledge', $schedule))->assertRedirect();
        $this->assertDatabaseCount('resident_notifications', 1);

        Sanctum::actingAs($dispatcher, ['*']);
        $url = '/api/operations/schedules/'.$schedule->id;
        $this->patchJson($url, ['decision' => 'confirmed'])->assertUnprocessable()->assertJsonValidationErrors('team_id');
        $this->patchJson($url, ['decision' => 'confirmed', 'team_id' => $team->id])->assertOk()->assertJsonPath('status', 'confirmed');
        $this->patchJson($url, ['decision' => 'confirmed', 'team_id' => $team->id])->assertOk();
        $this->assertDatabaseHas('service_schedule_assignments', ['service_schedule_id' => $schedule->id, 'driver_id' => $driver->id]);
        $this->assertDatabaseCount('driver_notifications', 1);
        $this->assertDatabaseCount('resident_notifications', 2);
        $this->assertSame($dispatcher->id, $schedule->fresh()->acknowledged_by);
    }

    public function test_team_and_shared_responder_bookings_conflict_across_midnight_but_allow_the_slot_boundary(): void
    {
        $this->travelTo(Carbon::parse('2030-01-01 08:00', 'Asia/Manila'));
        [$team, $driver] = $this->team();
        [$otherTeam] = $this->team($driver);
        $first = $this->schedule(['preferred_date' => '2030-01-02', 'preferred_time' => '23:30']);
        $conflict = $this->schedule(['preferred_date' => '2030-01-03', 'preferred_time' => '00:00']);
        $sameDay = $this->schedule(['preferred_date' => '2030-01-02', 'preferred_time' => '23:45']);
        $boundary = $this->schedule(['preferred_date' => '2030-01-03', 'preferred_time' => '00:30']);
        Sanctum::actingAs(DispatcherUser::factory()->create(), ['*']);
        $this->patchJson('/api/operations/schedules/'.$first->id, ['decision' => 'confirmed', 'team_id' => $team->id])->assertOk();

        $this->patchJson('/api/operations/schedules/'.$sameDay->id, ['decision' => 'confirmed', 'team_id' => $team->id])->assertUnprocessable()->assertJsonValidationErrors('team_id');
        $url = '/api/operations/schedules/'.$conflict->id;
        $this->patchJson($url, ['decision' => 'confirmed', 'team_id' => $team->id])->assertUnprocessable()->assertJsonValidationErrors('team_id');
        $this->patchJson($url, ['decision' => 'confirmed', 'team_id' => $otherTeam->id])->assertUnprocessable()->assertJsonValidationErrors('team_id');
        $this->assertDatabaseHas('service_schedules', ['id' => $conflict->id, 'status' => 'pending', 'response_team_id' => null]);
        $this->assertDatabaseCount('service_schedule_assignments', 1);
        $this->assertDatabaseCount('driver_notifications', 1);
        $this->patchJson('/api/operations/schedules/'.$boundary->id, ['decision' => 'confirmed', 'team_id' => $otherTeam->id])->assertOk();
    }

    public function test_cancel_notifies_assigned_team_once_and_stale_actions_cannot_reopen_it(): void
    {
        $this->travelTo(Carbon::parse('2030-01-02 08:00', 'Asia/Manila'));
        [$team, $driver] = $this->team();
        $schedule = $this->schedule();
        $dispatcher = DispatcherUser::factory()->create();
        Sanctum::actingAs($dispatcher, ['*']);
        $this->patchJson('/api/operations/schedules/'.$schedule->id, ['decision' => 'confirmed', 'team_id' => $team->id])->assertOk();
        Sanctum::actingAs($schedule->resident, ['*']);
        $url = '/api/resident/schedules/'.$schedule->id.'/cancel';

        $this->patchJson($url)->assertOk()->assertJsonPath('schedule.status', 'cancelled');
        $this->patchJson($url)->assertOk();
        $this->assertDatabaseHas('driver_notifications', ['driver_id' => $driver->id, 'schedule_id' => $schedule->id, 'kind' => 'schedule_cancelled']);
        $this->assertDatabaseCount('driver_notifications', 2);
        $this->assertDatabaseCount('resident_notifications', 2);
        $this->actingAs($dispatcher)->patch(route('dispatcher.schedule.decline', $schedule), ['reason' => 'Stale request'])->assertSessionHasErrors('decision');
        Sanctum::actingAs($driver, ['*']);
        $this->patchJson('/api/driver-responder/schedules/'.$schedule->id.'/complete')->assertUnprocessable();
        $this->assertDatabaseHas('service_schedules', ['id' => $schedule->id, 'status' => 'cancelled']);
    }

    public function test_driver_trip_progress_survives_notification_deletion_and_is_visible_to_the_resident(): void
    {
        $this->travelTo(Carbon::parse('2030-01-02 08:00', 'Asia/Manila'));
        [$team, $driver] = $this->team();
        $schedule = $this->schedule();
        Sanctum::actingAs(DispatcherUser::factory()->create(), ['*']);
        $this->patchJson('/api/operations/schedules/'.$schedule->id, ['decision' => 'confirmed', 'team_id' => $team->id])->assertOk();
        DriverNotification::query()->delete();
        Sanctum::actingAs($driver, ['*']);
        $url = '/api/driver-responder/schedules/'.$schedule->id;
        $this->getJson($url)->assertOk()->assertJsonPath('address', 'Municipal Hall, Balagtas, Bulacan');
        $this->patchJson($url.'/status', ['status' => 'on-scene'])->assertUnprocessable();
        $this->patchJson($url.'/status', ['status' => 'en-route'])->assertOk()->assertJsonPath('status', 'en-route');
        $this->patchJson($url.'/status', ['status' => 'en-route'])->assertOk();
        $this->assertDatabaseCount('resident_notifications', 2);
        Sanctum::actingAs($schedule->resident, ['*']);
        $this->getJson('/api/resident/schedules/'.$schedule->id)->assertOk()->assertJsonPath('status', 'en-route')->assertJsonPath('team_name', $team->team_name);
        $this->patchJson('/api/resident/schedules/'.$schedule->id.'/cancel')->assertForbidden();
        Sanctum::actingAs($driver, ['*']);
        $this->patchJson($url.'/status', ['status' => 'on-scene'])->assertOk();
        $this->patchJson($url.'/status', ['status' => 'completed'])->assertOk();
        $this->patchJson($url.'/complete')->assertOk();
        $this->getJson('/api/driver-responder/schedules?active=1')->assertOk()->assertJsonCount(0, 'data');
        $record = $schedule->fresh();
        $this->assertNotNull($record->started_at);
        $this->assertNotNull($record->arrived_at);
        $this->assertNotNull($record->completed_at);
        $this->assertDatabaseCount('resident_notifications', 4);
        Sanctum::actingAs(DriverResponderUser::factory()->create(), ['*']);
        $this->getJson($url)->assertNotFound();
        $this->patchJson($url.'/status', ['status' => 'completed'])->assertNotFound();
    }

    public function test_future_services_cannot_be_started_or_completed_and_stale_requests_cannot_be_assigned(): void
    {
        $this->travelTo(Carbon::parse('2030-01-01 08:00', 'Asia/Manila'));
        [$team, $driver] = $this->team();
        $schedule = $this->schedule();
        $dispatcher = DispatcherUser::factory()->create();
        Sanctum::actingAs($dispatcher, ['*']);
        $this->patchJson('/api/operations/schedules/'.$schedule->id, ['decision' => 'confirmed', 'team_id' => $team->id])->assertOk();
        Sanctum::actingAs($driver, ['*']);
        $this->getJson('/api/driver-responder/schedules?active=1')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson('/api/driver-responder/schedules/'.$schedule->id.'/status', ['status' => 'en-route'])->assertUnprocessable();
        $this->patchJson('/api/driver-responder/schedules/'.$schedule->id.'/complete')->assertUnprocessable();
        $this->assertDatabaseCount('resident_notifications', 1);
        $past = $this->schedule(['preferred_date' => '2029-12-31']);
        Sanctum::actingAs($dispatcher, ['*']);
        $this->patchJson('/api/operations/schedules/'.$past->id, ['decision' => 'confirmed', 'team_id' => $team->id])->assertUnprocessable()->assertJsonValidationErrors('preferred_time');
    }

    public function test_dispatch_and_service_progress_prevent_a_responder_from_handling_two_trips(): void
    {
        $this->travelTo(Carbon::parse('2030-01-02 08:00', 'Asia/Manila'));
        [$team, $driver] = $this->team();
        $schedule = $this->schedule(['status' => 'en-route', 'response_team_id' => $team->id]);
        DriverNotification::scheduleReceipt($driver->id, $schedule, $team->team_name);
        $type = IncidentType::create(['name' => 'Medical', 'description' => 'Medical emergency', 'priority' => 'high']);
        $report = EmergencyReport::create([
            'reference' => 'RPT-SCHEDULE-CONFLICT', 'incident_type_id' => $type->id,
            'location' => 'Town plaza', 'lat' => 14.82, 'lng' => 120.90, 'description' => 'Help needed', 'status' => 'pending', 'priority' => 'high',
            'reported_by' => $schedule->resident_id, 'reported_by_type' => $schedule->resident->getMorphClass(), 'reporter_name' => $schedule->resident->full_name,
        ]);
        Sanctum::actingAs(DispatcherUser::factory()->create(), ['*']);
        $this->postJson('/api/operations/reports/'.$report->id.'/assign', ['team_id' => $team->id])->assertUnprocessable()->assertJsonValidationErrors('team');
        $this->assertDatabaseHas('emergency_reports', ['id' => $report->id, 'status' => 'pending', 'assigned_driver_id' => null]);
        $other = $this->schedule(['status' => 'confirmed', 'response_team_id' => $team->id]);
        DriverNotification::scheduleReceipt($driver->id, $other, $team->team_name);
        Sanctum::actingAs($driver, ['*']);
        $this->patchJson('/api/driver-responder/schedules/'.$other->id.'/status', ['status' => 'en-route'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $schedule->update(['status' => 'completed']);
        $report->update(['assigned_driver_id' => $driver->id, 'response_team_id' => $team->id, 'status' => 'dispatched']);
        $this->patchJson('/api/driver-responder/schedules/'.$other->id.'/status', ['status' => 'en-route'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('service_schedules', ['id' => $other->id, 'status' => 'confirmed']);
    }

    public function test_resident_web_and_mobile_reject_past_and_overlapping_requests_and_keep_initial_status_pending(): void
    {
        $this->travelTo(Carbon::parse('2030-01-02 08:00', 'Asia/Manila'));
        $resident = ResidentUser::factory()->accepted()->create();
        $data = ['service_type' => 'Medical Mission', 'preferred_date' => '2030-01-02', 'preferred_time' => '07:59', 'address' => 'Town hall'];
        $this->actingAs($resident)->post(route('resident.schedule.store'), $data)->assertSessionHasErrors('preferred_time');
        $data['preferred_time'] = '10:00';
        $this->post(route('resident.schedule.store'), $data + ['status' => 'confirmed'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('service_schedules', ['resident_id' => $resident->id, 'status' => 'pending']);
        Sanctum::actingAs($resident, ['*']);
        $data['preferred_time'] = '10:30';
        $this->postJson('/api/resident/schedules', $data, ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('preferred_time');
        $this->assertDatabaseCount('service_schedules', 1);
        $this->assertDatabaseCount('resident_notifications', 0);
    }
}
