<?php

namespace Tests\Feature;

use App\Events\EmergencyReportUpdated;
use App\Models\DispatcherUser;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResponseTeam;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DispatcherOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_incoming_reports_use_priority_order_across_statuses_and_after_updates(): void
    {
        $dispatcher = DispatcherUser::factory()->create();
        $type = IncidentType::create(['name' => 'Fire', 'description' => 'Fire incident', 'priority' => 'critical']);
        $reportedAt = now();
        $create = fn (string $priority, string $status, $time) => EmergencyReport::create([
            'reference' => EmergencyReport::generateReference(), 'incident_type_id' => $type->id,
            'location' => 'Town hall', 'description' => 'Incident', 'lat' => 14.8, 'lng' => 120.9,
            'reporter_name' => 'Reporter', 'status' => $status, 'priority' => $priority, 'reported_at' => $time,
        ]);

        $low = $create('low', 'pending', $reportedAt);
        $medium = $create('medium', 'acknowledged', $reportedAt);
        $high = $create('high', 'pending', $reportedAt);
        $highTie = $create('high', 'pending', $reportedAt);
        $critical = $create('critical', 'dispatched', $reportedAt->copy()->subHour());
        $create('critical', 'resolved', $reportedAt);

        $firstLoad = $this->actingAs($dispatcher)->get(route('dispatcher.dashboard'));
        $firstLoad->assertInertia(fn (Assert $page) => $page->where('incoming', fn ($reports) =>
            $reports->pluck('id')->all() === [$critical->id, $highTie->id, $high->id, $medium->id, $low->id]));

        $low->update(['priority' => 'critical']);

        $this->get(route('dispatcher.dashboard'), [
            'X-Inertia' => 'true', 'X-Inertia-Version' => $firstLoad->viewData('page')['version'],
            'X-Inertia-Partial-Component' => 'Dispatcher/Dashboard', 'X-Inertia-Partial-Data' => 'incoming',
        ])->assertOk()->assertJsonPath('props.incoming.0.id', $low->id);
    }

    public function test_dispatching_an_incoming_report_keeps_its_details_read_only(): void
    {
        Event::fake([EmergencyReportUpdated::class]);
        $dispatcher = DispatcherUser::factory()->create();
        $driver = DriverResponderUser::factory()->create();
        $team = ResponseTeam::create(['team_name' => 'Alpha Team', 'status' => 'active']);
        Vehicle::create(['response_team_id' => $team->id, 'plate_number' => 'AMB-ALPHA1', 'vehicle_type' => 'ambulance', 'status' => 'available']);
        $team->members()->create([
            'user_id' => $driver->id,
            'name' => $driver->full_name,
            'role' => 'Driver',
            'status' => 'active',
        ]);

        $originalType = IncidentType::create([
            'name' => 'Medical Emergency',
            'description' => 'Medical emergency',
            'priority' => 'high',
        ]);
        $otherType = IncidentType::create([
            'name' => 'Fire',
            'description' => 'Fire incident',
            'priority' => 'critical',
        ]);

        $report = EmergencyReport::create([
            'reference' => 'RPT-2026-READONLY1',
            'incident_type_id' => $originalType->id,
            'location' => 'Barangay San Juan',
            'lat' => 14.8153,
            'lng' => 120.9094,
            'description' => 'Resident needs ambulance assistance.',
            'status' => 'pending',
            'priority' => 'high',
            'source' => 'mobile-app',
            'reporter_type' => null,
            'reporter_name' => 'Maria Santos',
            'reporter_contact' => null,
            'victim_info' => ['name' => 'Pedro Santos', 'age' => '62', 'sex' => 'Male'],
            'witness_info' => ['statement' => 'Fell near the plaza.'],
            'reported_at' => now(),
        ]);

        $this->actingAs($dispatcher)
            ->post(route('dispatcher.dispatch.store'), [
                'report_id' => $report->id,
                'incident_type_id' => $otherType->id,
                'reporter_name' => 'Tampered Name',
                'reporter_contact' => '09999999999',
                'location' => 'Tampered Location',
                'lat' => 15.0,
                'lng' => 122.0,
                'description' => 'Tampered description',
                'reporter_type' => 'witness',
                'victim_info' => ['name' => '', 'age' => '', 'sex' => ''],
                'witness_info' => ['statement' => 'Tampered statement'],
            ])
            ->assertRedirect(route('dispatcher.reports.show', $report));

        $fresh = $report->fresh();
        $this->assertSame('dispatched', $fresh->status);
        $this->assertSame($team->id, $fresh->response_team_id);
        $this->assertSame($originalType->id, $fresh->incident_type_id);
        $this->assertSame('high', $fresh->priority);
        $this->assertSame('Maria Santos', $fresh->reporter_name);
        $this->assertNull($fresh->reporter_contact);
        $this->assertNull($fresh->reporter_type);
        $this->assertSame('Barangay San Juan', $fresh->location);
        $this->assertEqualsWithDelta(14.8153, $fresh->lat, 0.0001);
        $this->assertEqualsWithDelta(120.9094, $fresh->lng, 0.0001);
        $this->assertSame('Resident needs ambulance assistance.', $fresh->description);
        $this->assertSame(['name' => 'Pedro Santos', 'age' => '62', 'sex' => 'Male'], $fresh->victim_info);
        $this->assertSame(['statement' => 'Fell near the plaza.'], $fresh->witness_info);
    }

    public function test_dispatching_a_non_active_report_via_operations_is_forbidden(): void
    {
        $dispatcher = DispatcherUser::factory()->create();
        $type = IncidentType::create([
            'name' => 'Medical Emergency',
            'description' => 'Medical emergency',
            'priority' => 'high',
        ]);
        $report = EmergencyReport::create([
            'reference' => 'RPT-2026-RESOLVED1',
            'incident_type_id' => $type->id,
            'location' => 'Municipal Hall',
            'lat' => 14.8153,
            'lng' => 120.9094,
            'description' => 'Already resolved.',
            'status' => 'resolved',
            'priority' => 'high',
            'source' => 'mobile-app',
            'reporter_type' => 'victim',
            'reporter_name' => 'Juan Dela Cruz',
            'reported_at' => now(),
        ]);

        $this->actingAs($dispatcher)
            ->post(route('dispatcher.dispatch.store'), ['report_id' => $report->id])
            ->assertForbidden();
    }

    public function test_dispatcher_can_still_create_and_dispatch_a_new_emergency_call(): void
    {
        Event::fake([EmergencyReportUpdated::class]);
        $dispatcher = DispatcherUser::factory()->create();
        $driver = DriverResponderUser::factory()->create();
        $team = ResponseTeam::create(['team_name' => 'Bravo Team', 'status' => 'active']);
        Vehicle::create(['response_team_id' => $team->id, 'plate_number' => 'AMB-BRAVO1', 'vehicle_type' => 'ambulance', 'status' => 'available']);
        $team->members()->create([
            'user_id' => $driver->id,
            'name' => $driver->full_name,
            'role' => 'Driver',
            'status' => 'active',
        ]);
        $type = IncidentType::create([
            'name' => 'Trauma',
            'description' => 'Trauma emergency',
            'priority' => 'critical',
        ]);

        $this->actingAs($dispatcher)
            ->post(route('dispatcher.dispatch.store'), [
                'report_id' => '',
                'incident_type_id' => $type->id,
                'reporter_name' => 'Caller One',
                'reporter_contact' => '09171234567',
                'location' => 'Public Market',
                'lat' => 14.82,
                'lng' => 120.91,
                'description' => 'Road collision.',
                'reporter_type' => 'witness',
                'witness_info' => ['statement' => 'Two motorcycles collided.'],
            ])
            ->assertRedirect();

        $created = EmergencyReport::firstOrFail();
        $this->assertSame('dispatched', $created->status);
        $this->assertSame('emergency-call', $created->source);
        $this->assertSame('critical', $created->priority);
        $this->assertSame('Caller One', $created->reporter_name);
        $this->assertSame(['statement' => 'Two motorcycles collided.'], $created->witness_info);
    }

    public function test_dispatcher_operations_page_includes_victim_and_witness_details_for_incoming_reports(): void
    {
        $dispatcher = DispatcherUser::factory()->create();
        $type = IncidentType::create([
            'name' => 'Medical Emergency',
            'description' => 'Medical emergency',
            'priority' => 'high',
        ]);
        EmergencyReport::create([
            'reference' => 'RPT-2026-DETAILS1',
            'incident_type_id' => $type->id,
            'location' => 'Barangay Wawa',
            'lat' => 14.8153,
            'lng' => 120.9094,
            'description' => 'Needs assistance.',
            'status' => 'pending',
            'priority' => 'high',
            'source' => 'mobile-app',
            'reporter_type' => 'victim',
            'reporter_name' => 'Ana Reyes',
            'victim_info' => ['name' => 'Ana Reyes', 'age' => '34', 'sex' => 'Female'],
            'reported_at' => now(),
        ]);

        $this->actingAs($dispatcher)
            ->get(route('dispatcher.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dispatcher/Dashboard')
                ->has('incoming', 1)
                ->where('incoming.0.victim_info.name', 'Ana Reyes')
                ->where('incoming.0.victim_info.age', '34')
                ->where('incoming.0.victim_info.sex', 'Female'));
    }

    public function test_dispatching_a_team_without_an_assigned_vehicle_fails_with_clear_message(): void
    {
        $dispatcher = DispatcherUser::factory()->create();
        $driver = DriverResponderUser::factory()->create();
        $team = ResponseTeam::create(['team_name' => 'No-Vehicle Team', 'status' => 'active']);
        $team->members()->create([
            'user_id' => $driver->id,
            'name' => $driver->full_name,
            'role' => 'Driver',
            'status' => 'active',
        ]);
        $type = IncidentType::create([
            'name' => 'Medical Emergency',
            'description' => 'Medical emergency',
            'priority' => 'high',
        ]);
        $report = EmergencyReport::create([
            'reference' => 'RPT-2026-NOVEH1',
            'incident_type_id' => $type->id,
            'location' => 'Barangay San Juan',
            'lat' => 14.8153,
            'lng' => 120.9094,
            'description' => 'Needs ambulance.',
            'status' => 'pending',
            'priority' => 'high',
            'source' => 'mobile-app',
            'reporter_type' => 'victim',
            'reporter_name' => 'Maria Santos',
            'reported_at' => now(),
        ]);

        // Manual team selection without vehicle throws team validation message.
        $this->actingAs($dispatcher)
            ->post(route('dispatcher.dispatch.store'), [
                'report_id' => $report->id,
                'team_id' => $team->id,
            ])
            ->assertSessionHasErrors([
                'team' => 'No-Vehicle Team needs an assigned vehicle first before it can be dispatched.',
            ]);
        $this->assertSame('pending', $report->fresh()->status);

        // Auto-assign when active team has no vehicle also throws clear message.
        $this->actingAs($dispatcher)
            ->post(route('dispatcher.dispatch.store'), [
                'report_id' => $report->id,
            ])
            ->assertSessionHasErrors([
                'team' => 'This response team needs an assigned vehicle first before it can be dispatched.',
            ]);
        $this->assertSame('pending', $report->fresh()->status);
    }
}
