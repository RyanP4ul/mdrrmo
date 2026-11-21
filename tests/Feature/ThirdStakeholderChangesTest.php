<?php

namespace Tests\Feature;

use App\Events\ServiceScheduleReminder;
use App\Models\AdminUser;
use App\Models\DispatcherUser;
use App\Models\DriverLocation;
use App\Models\DriverNotification;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\MaintenanceReport;
use App\Models\ReportForm;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Models\ServiceSchedule;
use App\Models\ServiceScheduleDriverTicket;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ThirdStakeholderChangesTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_map_exposes_team_id_and_member_role_for_grouped_sidebar(): void
    {
        $dispatcher = DispatcherUser::factory()->create();
        $driver = DriverResponderUser::factory()->create(['first_name' => 'Juan', 'last_name' => 'Driver', 'status' => 'active']);
        $responder = DriverResponderUser::factory()->create(['first_name' => 'Maria', 'last_name' => 'Responder', 'status' => 'active']);

        $team = ResponseTeam::create(['team_name' => 'Team A', 'status' => 'active']);
        $team->members()->create(['user_id' => $driver->id, 'name' => $driver->full_name, 'role' => 'Driver', 'status' => 'active']);
        $team->members()->create(['user_id' => $responder->id, 'name' => $responder->full_name, 'role' => 'Responder', 'status' => 'active']);

        foreach ([$driver, $responder] as $user) {
            DriverLocation::create([
                'driver_id' => $user->id,
                'session_id' => 'sess-'.$user->id,
                'lat' => 14.82,
                'lng' => 120.90,
                'accuracy' => 10,
                'speed' => 12,
                'heading' => 0,
                'observed_at' => now(),
                'expires_at' => now()->addMinutes(5),
            ]);
        }

        $this->actingAs($dispatcher)
            ->get(route('dispatcher.map'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dispatcher/Map')
                ->has('drivers', 2)
                ->where('drivers.0.teamId', $team->id)
                ->where('drivers.0.teamName', 'Team A')
                ->where('drivers.0.memberRole', 'Driver')
                ->where('drivers.1.teamId', $team->id)
                ->where('drivers.1.teamName', 'Team A')
                ->where('drivers.1.memberRole', 'Responder'));
    }

    public function test_dispatcher_report_show_includes_submitted_emergency_form_with_endorsements(): void
    {
        $dispatcher = DispatcherUser::factory()->create();
        $type = IncidentType::create(['name' => 'Medical', 'description' => 'Medical', 'priority' => 'high']);
        $report = EmergencyReport::create([
            'reference' => 'RPT-2026-EFORM01',
            'incident_type_id' => $type->id,
            'location' => 'Barangay San Juan',
            'lat' => 14.82,
            'lng' => 120.90,
            'description' => 'Patient fainted',
            'status' => 'resolved',
            'priority' => 'high',
            'source' => 'mobile-app',
            'reporter_name' => 'Pedro Cruz',
            'reported_at' => now(),
        ]);

        ReportForm::create([
            'emergency_report_id' => $report->id,
            'driver_section' => [],
            'emergency_section' => [
                'patientName' => 'Pedro Cruz',
                'age' => '45',
                'sex' => 'Male',
                'typeOfIncident' => 'Medical',
                'endorsedBy' => ['name' => 'Responder Ana', 'time' => '10:30', 'date' => '2026-11-21'],
                'endorsedTo' => ['name' => 'Nurse Joy', 'time' => '10:35', 'date' => '2026-11-21'],
            ],
        ]);

        $this->actingAs($dispatcher)
            ->get(route('dispatcher.reports.show', $report))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dispatcher/Reports/Show')
                ->where('report.form.emergency_section.patientName', 'Pedro Cruz')
                ->where('report.form.emergency_section.endorsedBy.name', 'Responder Ana')
                ->where('report.form.emergency_section.endorsedTo.name', 'Nurse Joy'));
    }

    public function test_schedule_reminder_command_sends_once_within_lead_window(): void
    {
        Event::fake([ServiceScheduleReminder::class]);
        config(['mobile.service_reminder_minutes' => 30]);

        $tz = config('mobile.timezone', 'Asia/Manila');
        $resident = ResidentUser::factory()->create(['first_name' => 'Ana', 'last_name' => 'Reyes']);
        $driver = DriverResponderUser::factory()->create(['status' => 'active']);
        $team = ResponseTeam::create(['team_name' => 'Team B', 'status' => 'active']);

        $upcoming = Carbon::now($tz)->addMinutes(20);
        $dueSchedule = ServiceSchedule::create([
            'resident_id' => $resident->id,
            'response_team_id' => $team->id,
            'service_type' => 'Medical',
            'preferred_date' => $upcoming->toDateString(),
            'preferred_time' => $upcoming->format('H:i'),
            'address' => 'Brgy Wawa',
            'status' => 'confirmed',
        ]);
        $dueSchedule->responders()->attach($driver->id);

        $far = Carbon::now($tz)->addHours(3);
        $laterSchedule = ServiceSchedule::create([
            'resident_id' => $resident->id,
            'response_team_id' => $team->id,
            'service_type' => 'Discharge',
            'preferred_date' => $far->toDateString(),
            'preferred_time' => $far->format('H:i'),
            'address' => 'Brgy Burol',
            'status' => 'confirmed',
        ]);
        $laterSchedule->responders()->attach($driver->id);

        $this->artisan('mobile:send-schedule-reminders')->assertSuccessful();

        $this->assertDatabaseHas('driver_notifications', [
            'driver_id' => $driver->id,
            'schedule_id' => $dueSchedule->id,
            'kind' => 'schedule_reminder',
        ]);
        $this->assertDatabaseMissing('driver_notifications', [
            'driver_id' => $driver->id,
            'schedule_id' => $laterSchedule->id,
            'kind' => 'schedule_reminder',
        ]);
        Event::assertDispatchedTimes(ServiceScheduleReminder::class, 1);

        // Running again should remain idempotent (no duplicate reminder).
        $this->artisan('mobile:send-schedule-reminders')->assertSuccessful();
        $this->assertSame(1, DriverNotification::where('kind', 'schedule_reminder')->count());
        Event::assertDispatchedTimes(ServiceScheduleReminder::class, 1);
    }

    public function test_simplified_maintenance_report_supports_new_categories_and_omits_severity_and_mileage(): void
    {
        $driver = DriverResponderUser::factory()->create(['status' => 'active']);
        $team = ResponseTeam::create(['team_name' => 'Team A', 'status' => 'active']);
        $team->members()->create(['user_id' => $driver->id, 'name' => $driver->full_name, 'role' => 'Driver', 'status' => 'active']);
        $vehicle = Vehicle::create([
            'response_team_id' => $team->id,
            'name' => 'Ambulance 01',
            'plate_number' => 'AMB-01',
            'vehicle_type' => 'ambulance',
            'status' => 'available',
        ]);

        Sanctum::actingAs($driver, ['driver-read', 'driver-write']);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/driver-responder/maintenance-reports', [
                'vehicle_id' => $vehicle->id,
                'issue_type' => 'Change Oil',
                'description' => 'Due for regular oil change.',
            ])->assertCreated()
            ->assertJsonPath('report.issue_type', 'Change Oil')
            ->assertJsonPath('report.issue_label', 'Change Oil')
            ->assertJsonPath('report.mileage', null);

        // "Others" requires issue_details.
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/driver-responder/maintenance-reports', [
                'vehicle_id' => $vehicle->id,
                'issue_type' => 'Others',
                'description' => 'Custom issue.',
            ])->assertUnprocessable()->assertJsonValidationErrors('issue_details');

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/driver-responder/maintenance-reports', [
                'vehicle_id' => $vehicle->id,
                'issue_type' => 'Others',
                'issue_details' => 'Broken side mirror',
                'description' => 'Right side mirror cracked.',
            ])->assertCreated()
            ->assertJsonPath('report.issue_type', 'Others')
            ->assertJsonPath('report.issue_details', 'Broken side mirror')
            ->assertJsonPath('report.issue_label', 'Others: Broken side mirror');

        $this->assertSame(['Brakes / Tires', 'Change Oil', 'Others'], MaintenanceReport::ISSUE_TYPES);
    }

    public function test_driver_ticket_auto_fills_requester_and_links_exclusively_to_its_scheduled_service_report(): void
    {
        $admin = AdminUser::factory()->create();
        $dispatcher = DispatcherUser::factory()->create();
        $residentOne = ResidentUser::factory()->create(['first_name' => 'Liza', 'last_name' => 'Mendoza']);
        $residentTwo = ResidentUser::factory()->create(['first_name' => 'Carlo', 'last_name' => 'Garcia']);
        $driver = DriverResponderUser::factory()->create(['first_name' => 'Juan', 'last_name' => 'Driver', 'status' => 'active']);
        $responder = DriverResponderUser::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Medic', 'status' => 'active']);

        $team = ResponseTeam::create(['team_name' => 'Team A', 'status' => 'active', 'member_roles_enabled' => true]);
        $team->members()->create(['user_id' => $driver->id, 'name' => $driver->full_name, 'role' => 'Driver', 'status' => 'active']);
        $team->members()->create(['user_id' => $responder->id, 'name' => $responder->full_name, 'role' => 'Responder', 'status' => 'active']);
        $vehicle = Vehicle::create([
            'response_team_id' => $team->id,
            'name' => 'Ambulance 01',
            'plate_number' => 'RESCUE-01',
            'vehicle_type' => 'ambulance',
            'status' => 'available',
        ]);
        $team->update(['active_vehicle_id' => $vehicle->id]);

        $scheduleOne = ServiceSchedule::create([
            'resident_id' => $residentOne->id,
            'response_team_id' => $team->id,
            'service_type' => 'Medical',
            'preferred_date' => '2026-11-22',
            'preferred_time' => '09:00',
            'address' => 'Brgy San Juan',
            'destination_address' => 'Bulacan Medical Center',
            'status' => 'completed',
        ]);
        $scheduleOne->responders()->attach([$driver->id, $responder->id]);

        $scheduleTwo = ServiceSchedule::create([
            'resident_id' => $residentTwo->id,
            'response_team_id' => $team->id,
            'service_type' => 'Discharge',
            'preferred_date' => '2026-11-23',
            'preferred_time' => '14:00',
            'address' => 'Hospital Gate',
            'status' => 'confirmed',
        ]);
        $scheduleTwo->responders()->attach([$driver->id, $responder->id]);

        // Responder cannot save Driver Ticket (Driver-only).
        Sanctum::actingAs($responder, ['driver-read', 'driver-write']);
        $this->putJson("/api/driver-responder/schedules/{$scheduleOne->id}/driver-ticket", [
            'revision' => 'new',
            'driver_section' => ['driverName' => $responder->full_name],
        ])->assertForbidden();

        // Driver gets defaults with Authorized Passenger auto-filled as schedule requester.
        Sanctum::actingAs($driver, ['driver-read', 'driver-write']);
        $getResponse = $this->getJson("/api/driver-responder/schedules/{$scheduleOne->id}/driver-ticket")
            ->assertOk()
            ->assertJsonPath('revision', 'new')
            ->assertJsonPath('defaults.authorizedPassenger', 'Liza Mendoza')
            ->assertJsonPath('defaults.governmentCardPlateNo', 'RESCUE-01')
            ->assertJsonPath('defaults.placeVisitedInspected', 'Bulacan Medical Center')
            ->assertJsonPath('defaults.purpose', 'Medical');

        $this->putJson("/api/driver-responder/schedules/{$scheduleOne->id}/driver-ticket", [
            'revision' => $getResponse->json('revision'),
            'driver_section' => [
                'tripDate' => '2026-11-22',
                'driverName' => $driver->full_name,
                'governmentCardPlateNo' => 'RESCUE-01',
                'authorizedPassenger' => 'Liza Mendoza',
                'placeVisitedInspected' => 'Bulacan Medical Center',
                'purpose' => 'Medical',
                'balanceInTank' => 30,
                'fuelUsedDuringTrip' => 8,
                'remainingFuel' => 22,
            ],
        ])->assertOk()
            ->assertJsonPath('ticket.driver_section.authorizedPassenger', 'Liza Mendoza');

        $this->assertSame(1, ServiceScheduleDriverTicket::count());

        // Dispatcher & Admin see the Driver Ticket inside Schedule One's report, NOT Schedule Two's report.
        foreach ([[$dispatcher, 'dispatcher'], [$admin, 'admin']] as [$actor, $role]) {
            $this->actingAs($actor)
                ->get(route($role.'.schedules.show', $scheduleOne))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Reports/ScheduleShow')
                    ->where('schedule.id', $scheduleOne->id)
                    ->where('schedule.resident', 'Liza Mendoza')
                    ->where('driverTicket.driver_section.authorizedPassenger', 'Liza Mendoza')
                    ->where('driverTicket.driver_section.fuelUsedDuringTrip', 8));

            $this->actingAs($actor)
                ->get(route($role.'.schedules.show', $scheduleTwo))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Reports/ScheduleShow')
                    ->where('schedule.id', $scheduleTwo->id)
                    ->where('driverTicket', null));

            $this->actingAs($actor)
                ->get(route($role.'.reports.index', ['tab' => 'scheduled']))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('filters.tab', 'scheduled')
                    ->has('scheduledReports.data', 2));
        }
    }
}
