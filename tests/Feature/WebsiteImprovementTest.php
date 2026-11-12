<?php

namespace Tests\Feature;

use App\Events\EmergencyReportUpdated;
use App\Events\VehicleLocationUpdated;
use App\Models\DispatcherUser;
use App\Models\DriverNotification;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\Hospital;
use App\Models\IncidentType;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Models\TeamMember;
use App\Models\Vehicle;
use App\Services\DispatchService;
use App\Services\EmergencyReportWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WebsiteImprovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_login_is_throttled_after_five_attempts_per_minute(): void
    {
        RateLimiter::clear('127.0.0.1|throttle@example.test');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('api.auth.login'), [
                'email' => 'throttle@example.test',
                'password' => 'incorrect',
            ])->assertRedirect();
        }

        $this->post(route('api.auth.login'), [
            'email' => 'throttle@example.test',
            'password' => 'incorrect',
        ])->assertTooManyRequests();
    }

    public function test_only_active_dispatchers_can_authenticate_to_the_dispatch_broadcast_channel(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => 'test-app',
            'broadcasting.connections.pusher.options' => ['cluster' => 'mt1'],
        ]);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');

        $dispatcher = DispatcherUser::factory()->create();

        $this->actingAs($dispatcher)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-dispatch',
            ])
            ->assertOk();

        $resident = ResidentUser::factory()->accepted()->create();

        $this->actingAs($resident)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-dispatch',
            ])
            ->assertForbidden();
    }

    public function test_report_references_are_unique_and_do_not_depend_on_the_next_database_id(): void
    {
        $resident = ResidentUser::factory()->accepted()->create();
        $incidentType = IncidentType::create([
            'name' => 'Medical Emergency',
            'description' => 'Medical emergency',
            'priority' => 'high',
        ]);
        $data = [
            'incident_type_id' => $incidentType->getKey(),
            'reporter_type' => 'victim',
            'location' => 'Municipal Hall',
            'lat' => 14.6,
            'lng' => 121.0,
            'description' => 'Needs assistance.',
        ];

        $this->actingAs($resident)->post(route('resident.report.store'), $data)->assertRedirect(route('resident.history'));
        $this->actingAs($resident)->post(route('resident.report.store'), $data)->assertRedirect(route('resident.history'));

        $references = EmergencyReport::query()->orderBy('id')->pluck('reference');

        $this->assertCount(2, $references);
        $this->assertCount(2, $references->unique());
        foreach ($references as $reference) {
            $this->assertMatchesRegularExpression('/^RPT-\d{4}-[A-Z0-9]{8}$/', $reference);
        }
    }

    public function test_pending_residents_cannot_create_website_service_schedules(): void
    {
        $resident = ResidentUser::factory()->create();

        $this->actingAs($resident)
            ->post(route('resident.schedule.store'), [])
            ->assertForbidden();

        $this->assertDatabaseCount('service_schedules', 0);
    }

    public function test_approved_residents_can_create_website_service_schedules(): void
    {
        $resident = ResidentUser::factory()->accepted()->create();

        $this->actingAs($resident)
            ->post(route('resident.schedule.store'), [
                'service_type' => 'Ambulance Standby',
                'preferred_date' => now()->addDay()->toDateString(),
                'preferred_time' => '09:00',
                'address' => 'Municipal Hall',
                'notes' => '',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('service_schedules', [
            'resident_id' => $resident->getKey(),
            'service_type' => 'Ambulance Standby',
            'preferred_time' => '09:00',
        ]);
    }

    public function test_resident_cannot_cancel_another_residents_service_schedule(): void
    {
        $owner = ResidentUser::factory()->accepted()->create();
        $otherResident = ResidentUser::factory()->accepted()->create();
        $schedule = $owner->schedules()->create([
            'service_type' => 'Ambulance Standby',
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time' => '09:00',
            'address' => 'Municipal Hall',
            'status' => 'pending',
        ]);

        $this->actingAs($otherResident)
            ->patch(route('resident.schedule.cancel', $schedule))
            ->assertForbidden();

        $this->assertDatabaseHas('service_schedules', [
            'id' => $schedule->getKey(),
            'status' => 'pending',
        ]);
    }

    public function test_dispatcher_schedule_filter_rejects_an_invalid_month(): void
    {
        $dispatcher = DispatcherUser::factory()->create();

        $this->actingAs($dispatcher)
            ->get(route('dispatcher.schedule.index', ['month' => '2026-13']))
            ->assertRedirect()
            ->assertSessionHasErrors('month');
    }

    public function test_dispatcher_confirms_a_schedule_with_a_selected_team_and_notifies_both_sides(): void
    {
        $dispatcher = DispatcherUser::factory()->create();
        $resident = ResidentUser::factory()->accepted()->create();
        $team = ResponseTeam::create(['team_name' => 'Team Alpha', 'status' => 'active']);
        $drivers = DriverResponderUser::factory()->count(2)->create();
        foreach ($drivers as $driver) {
            TeamMember::create([
                'response_team_id' => $team->getKey(),
                'user_id' => $driver->getKey(),
                'name' => $driver->full_name,
                'status' => 'active',
            ]);
        }
        $schedule = $resident->schedules()->create([
            'service_type' => 'Ambulance Standby',
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time' => '09:00',
            'address' => 'Municipal Hall',
            'status' => 'pending',
        ]);

        $this->actingAs($dispatcher)
            ->patch(route('dispatcher.schedule.acknowledge', $schedule), ['team_id' => $team->getKey()])
            ->assertRedirect();

        $this->assertDatabaseHas('service_schedules', [
            'id' => $schedule->getKey(),
            'status' => 'confirmed',
            'response_team_id' => $team->getKey(),
        ]);
        $this->assertDatabaseCount('driver_notifications', 2);
        foreach ($drivers as $driver) {
            $this->assertDatabaseHas('driver_notifications', [
                'driver_id' => $driver->getKey(),
                'schedule_id' => $schedule->getKey(),
                'kind' => 'schedule_assigned',
            ]);
        }
        $this->assertDatabaseHas('resident_notifications', [
            'user_id' => $resident->getKey(),
            'type' => 'schedule_confirmed',
            'related_id' => $schedule->getKey(),
            'message' => 'Your Ambulance Standby on '.$schedule->preferred_date->toFormattedDateString().' is confirmed. Team Alpha has been assigned.',
        ]);
    }

    public function test_dispatcher_cannot_confirm_a_schedule_for_a_team_without_an_active_responder(): void
    {
        $dispatcher = DispatcherUser::factory()->create();
        $resident = ResidentUser::factory()->accepted()->create();
        $team = ResponseTeam::create(['team_name' => 'Empty Team', 'status' => 'active']);
        $schedule = $resident->schedules()->create([
            'service_type' => 'Ambulance Standby',
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time' => '09:00',
            'address' => 'Municipal Hall',
            'status' => 'pending',
        ]);

        $this->actingAs($dispatcher)
            ->patch(route('dispatcher.schedule.acknowledge', $schedule), ['team_id' => $team->getKey()])
            ->assertSessionHasErrors('team_id');

        $this->assertDatabaseHas('service_schedules', [
            'id' => $schedule->getKey(),
            'status' => 'pending',
            'response_team_id' => null,
        ]);
        $this->assertSame(0, DriverNotification::count());
        $this->assertDatabaseCount('resident_notifications', 0);
    }

    public function test_shift_creation_rejects_members_from_another_response_team(): void
    {
        $dispatcher = DispatcherUser::factory()->create();
        $team = ResponseTeam::create(['team_name' => 'Team Alpha', 'status' => 'active']);
        $otherTeam = ResponseTeam::create(['team_name' => 'Team Beta', 'status' => 'active']);
        $member = TeamMember::create(['response_team_id' => $otherTeam->getKey(), 'name' => 'Responder']);

        $this->actingAs($dispatcher)
            ->post(route('dispatcher.shifts.store', $team->getKey()), [
                'start_time' => now()->addHour()->toDateTimeString(),
                'end_time' => now()->addHours(2)->toDateTimeString(),
                'status' => 'on-shift',
                'member_ids' => [$member->getKey()],
            ])
            ->assertSessionHasErrors('member_ids.0');

        $this->assertDatabaseCount('shifts', 0);
    }

    public function test_shift_update_cannot_target_a_shift_through_the_wrong_team(): void
    {
        $dispatcher = DispatcherUser::factory()->create();
        $team = ResponseTeam::create(['team_name' => 'Team Alpha', 'status' => 'active']);
        $otherTeam = ResponseTeam::create(['team_name' => 'Team Beta', 'status' => 'active']);
        $shift = $otherTeam->shifts()->create([
            'start_time' => now()->addHour(),
            'end_time' => now()->addHours(2),
            'status' => 'off-shift',
        ]);

        $this->actingAs($dispatcher)
            ->patch(route('dispatcher.shifts.update', [$team->getKey(), $shift->getKey()]), ['status' => 'on-shift'])
            ->assertNotFound();

        $this->assertDatabaseHas('shifts', ['id' => $shift->getKey(), 'status' => 'off-shift']);
    }

    public function test_team_update_rejects_members_from_another_team(): void
    {
        $dispatcher = DispatcherUser::factory()->create();
        $team = ResponseTeam::create(['team_name' => 'Team Alpha', 'status' => 'active']);
        $otherTeam = ResponseTeam::create(['team_name' => 'Team Beta', 'status' => 'active']);
        $member = TeamMember::create(['response_team_id' => $otherTeam->getKey(), 'name' => 'Responder']);

        $this->actingAs($dispatcher)
            ->put(route('dispatcher.responders.update', $team->getKey()), [
                'team_name' => 'Renamed Team Alpha',
                'status' => 'active',
                'members' => [[
                    'id' => $member->getKey(),
                    'name' => 'Responder',
                    'role' => 'Medic',
                    'status' => 'active',
                ]],
            ])
            ->assertSessionHasErrors('members.0.id');

        $this->assertDatabaseHas('response_teams', [
            'id' => $team->getKey(),
            'team_name' => 'Team Alpha',
        ]);
    }

    public function test_shift_creation_rejects_overlapping_team_shifts(): void
    {
        $dispatcher = DispatcherUser::factory()->create();
        $team = ResponseTeam::create(['team_name' => 'Team Alpha', 'status' => 'active']);
        $member = TeamMember::create(['response_team_id' => $team->getKey(), 'name' => 'Responder']);
        $start = now()->addDay()->startOfHour();
        $team->shifts()->create([
            'start_time' => $start,
            'end_time' => $start->copy()->addHours(4),
            'status' => 'on-shift',
        ])->members()->attach($member);

        $this->actingAs($dispatcher)
            ->post(route('dispatcher.shifts.store', $team->getKey()), [
                'start_time' => $start->copy()->addHours(2)->toDateTimeString(),
                'end_time' => $start->copy()->addHours(6)->toDateTimeString(),
                'status' => 'on-shift',
                'member_ids' => [$member->getKey()],
            ])
            ->assertSessionHasErrors('start_time');

        $this->assertDatabaseCount('shifts', 1);
    }

    public function test_report_workflow_releases_vehicle_and_notifies_resident_when_resolved(): void
    {
        Event::fake([EmergencyReportUpdated::class]);
        $resident = ResidentUser::factory()->accepted()->create();
        $team = ResponseTeam::create(['team_name' => 'Team Alpha', 'status' => 'active']);
        $report = $this->createReport([
            'status' => 'dispatched',
            'reported_by' => $resident->getKey(),
            'reported_by_type' => $resident->getMorphClass(),
            'response_team_id' => $team->getKey(),
        ]);
        $vehicle = Vehicle::create([
            'response_team_id' => $team->getKey(),
            'vehicle_type' => 'ambulance',
            'plate_number' => 'TEST-001',
            'status' => 'on-scene',
            'assigned_report_id' => $report->getKey(),
        ]);

        $resolved = app(EmergencyReportWorkflow::class)->transition($report, 'resolved');

        $this->assertSame('resolved', $resolved->status);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->getKey(),
            'status' => 'available',
            'assigned_report_id' => null,
        ]);
        $this->assertDatabaseHas('resident_notifications', [
            'user_id' => $resident->getKey(),
            'related_id' => $report->getKey(),
            'type' => 'report_update',
        ]);
        Event::assertDispatched(EmergencyReportUpdated::class);
    }

    public function test_report_workflow_rejects_transitions_out_of_terminal_states(): void
    {
        $report = $this->createReport(['status' => 'resolved']);

        try {
            app(EmergencyReportWorkflow::class)->transition($report, 'dispatched');
            $this->fail('A resolved report must not be dispatched again.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertDatabaseHas('emergency_reports', [
            'id' => $report->getKey(),
            'status' => 'resolved',
        ]);
    }

    public function test_dispatch_service_assigns_a_report_to_the_selected_team_and_driver(): void
    {
        Event::fake([EmergencyReportUpdated::class]);
        $driver = DriverResponderUser::factory()->create();
        $resident = ResidentUser::factory()->accepted()->create();
        $team = ResponseTeam::create(['team_name' => 'Team Alpha', 'status' => 'active']);
        TeamMember::create([
            'response_team_id' => $team->getKey(),
            'user_id' => $driver->getKey(),
            'name' => 'Driver',
            'role' => 'Driver',
        ]);
        Vehicle::create([
            'response_team_id' => $team->getKey(),
            'vehicle_type' => 'ambulance',
            'plate_number' => 'TEST-003',
            'status' => 'available',
        ]);
        $report = $this->createReport([
            'reported_by' => $resident->getKey(),
            'reported_by_type' => $resident->getMorphClass(),
        ]);

        $dispatched = app(DispatchService::class)->dispatch($report, $team);

        $this->assertSame('dispatched', $dispatched->status);
        $this->assertSame($team->getKey(), $dispatched->response_team_id);
        $this->assertSame($driver->getKey(), $dispatched->assigned_driver_id);
        $this->assertDatabaseHas('vehicles', [
            'plate_number' => 'TEST-003',
            'assigned_report_id' => null,
        ]);
        $this->assertNotNull($dispatched->acknowledged_at);
        $this->assertDatabaseHas('resident_notifications', [
            'user_id' => $resident->getKey(),
            'related_id' => $report->getKey(),
            'title' => 'Help is on the way',
        ]);
        Event::assertDispatched(EmergencyReportUpdated::class);
    }

    public function test_auto_assignment_ignores_shifts_that_have_not_started(): void
    {
        $futureTeam = ResponseTeam::create(['team_name' => 'Future Team', 'status' => 'active']);
        $onShiftTeam = ResponseTeam::create(['team_name' => 'On Shift Team', 'status' => 'active']);
        foreach ([$futureTeam, $onShiftTeam] as $team) {
            $driver = DriverResponderUser::factory()->create();
            $team->members()->create(['user_id' => $driver->id, 'name' => $driver->full_name, 'status' => 'active']);
        }
        $futureTeam->shifts()->create([
            'start_time' => now()->addHour(),
            'end_time' => now()->addHours(3),
            'status' => 'on-shift',
        ]);
        $onShiftTeam->shifts()->create([
            'start_time' => now()->subHour(),
            'end_time' => now()->addHours(2),
            'status' => 'on-shift',
        ]);

        $selectedTeam = DB::transaction(fn () => app(DispatchService::class)->autoAssignTeam(lockForUpdate: true));

        $this->assertSame($onShiftTeam->getKey(), $selectedTeam?->getKey());
    }

    public function test_hospital_recommendations_prioritize_specialists_and_fill_nearest_results(): void
    {
        $report = $this->createReport();
        Hospital::create([
            'name' => 'Nearest General Hospital',
            'address' => 'Nearby',
            'lat' => 14.61,
            'lng' => 121.0,
            'specialization' => [],
        ]);
        Hospital::create([
            'name' => 'Emergency Specialist',
            'address' => 'Farther away',
            'lat' => 14.8,
            'lng' => 121.0,
            'specialization' => ['Emergency'],
        ]);
        Hospital::create([
            'name' => 'Second General Hospital',
            'address' => 'Nearby 2',
            'lat' => 14.62,
            'lng' => 121.0,
            'specialization' => [],
        ]);
        Hospital::create([
            'name' => 'Third General Hospital',
            'address' => 'Nearby 3',
            'lat' => 14.63,
            'lng' => 121.0,
            'specialization' => [],
        ]);

        $recommendations = app(DispatchService::class)->recommendHospitals($report);

        $this->assertCount(3, $recommendations);
        $this->assertSame('Emergency Specialist', $recommendations[0]['name']);
        $this->assertTrue($recommendations[0]['match']);
        $this->assertSame('Nearest General Hospital', $recommendations[1]['name']);
        $this->assertLessThan($recommendations[2]['distance'], $recommendations[1]['distance']);
    }

    public function test_driver_must_follow_response_progress_to_resolve_assignment(): void
    {
        Event::fake([EmergencyReportUpdated::class, VehicleLocationUpdated::class]);
        $driver = DriverResponderUser::factory()->create();
        $team = ResponseTeam::create(['team_name' => 'Team Alpha', 'status' => 'active']);
        TeamMember::create([
            'response_team_id' => $team->getKey(),
            'user_id' => $driver->getKey(),
            'name' => 'Driver',
            'role' => 'Driver',
        ]);
        $report = $this->createReport([
            'status' => 'dispatched',
            'response_team_id' => $team->getKey(),
            'assigned_driver_id' => $driver->getKey(),
        ]);
        $vehicle = Vehicle::create([
            'response_team_id' => $team->getKey(),
            'vehicle_type' => 'ambulance',
            'plate_number' => 'TEST-002',
            'status' => 'available',
            'assigned_report_id' => $report->getKey(),
        ]);

        $this->actingAs($driver)
            ->patch(route('driver.assignments.status', $report), ['vehicle_status' => 'on-scene'])
            ->assertSessionHasErrors('response_status');

        foreach (['en-route', 'on-scene', 'returning', 'available'] as $status) {
            $this->patch(route('driver.assignments.status', $report), ['vehicle_status' => $status])
                ->assertRedirect();
        }

        $this->assertDatabaseHas('emergency_reports', [
            'id' => $report->getKey(),
            'status' => 'resolved',
        ]);
        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->getKey(),
            'status' => 'available',
            'assigned_report_id' => null,
        ]);
        Event::assertDispatched(EmergencyReportUpdated::class);
        Event::assertDispatchedTimes(VehicleLocationUpdated::class, 4);
    }

    public function test_driver_location_endpoint_validates_coordinates_and_is_rate_limited(): void
    {
        $driver = DriverResponderUser::factory()->create();

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->actingAs($driver)
                ->post(route('driver.ping'), ['lat' => 91, 'lng' => 181])
                ->assertSessionHasErrors(['lat', 'lng']);
        }

        $this->actingAs($driver)
            ->post(route('driver.ping'), ['lat' => 91, 'lng' => 181])
            ->assertTooManyRequests();
    }

    public function test_driver_cannot_update_an_unassigned_report(): void
    {
        $assignedDriver = DriverResponderUser::factory()->create();
        $otherDriver = DriverResponderUser::factory()->create();
        $report = $this->createReport([
            'status' => 'dispatched',
            'assigned_driver_id' => $assignedDriver->getKey(),
        ]);

        $this->actingAs($otherDriver)
            ->patch(route('driver.assignments.status', $report), ['vehicle_status' => 'en-route'])
            ->assertForbidden();
    }

    public function test_driver_patient_form_rejects_non_string_fields(): void
    {
        $driver = DriverResponderUser::factory()->create();
        $report = $this->createReport([
            'status' => 'dispatched',
            'assigned_driver_id' => $driver->getKey(),
        ]);

        $this->actingAs($driver)
            ->put(route('driver.reports.form', $report), [
                'driver_section' => ['driverName' => ['unexpected' => 'object']],
                'emergency_section' => [
                    'sex' => 'Male',
                    'typeOfIncident' => 'Medical',
                    'vitalSigns' => [],
                ],
            ])
            ->assertSessionHasErrors('driver_section.driverName');

        $this->assertDatabaseCount('report_forms', 0);
    }

    private function createReport(array $attributes = []): EmergencyReport
    {
        $incidentType = IncidentType::create([
            'name' => 'Medical Emergency',
            'description' => 'Medical emergency',
            'priority' => 'high',
        ]);

        return EmergencyReport::create(array_merge([
            'reference' => 'RPT-2026-'.fake()->unique()->numerify('########'),
            'incident_type_id' => $incidentType->getKey(),
            'location' => 'Municipal Hall',
            'lat' => 14.6,
            'lng' => 121.0,
            'description' => 'Needs assistance.',
            'status' => 'pending',
            'priority' => 'high',
            'source' => 'mobile-app',
            'reporter_name' => 'Test Resident',
            'reported_at' => now(),
        ], $attributes));
    }
}
