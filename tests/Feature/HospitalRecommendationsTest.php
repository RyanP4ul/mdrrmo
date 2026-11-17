<?php

namespace Tests\Feature;

use App\Models\DispatcherUser;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\Hospital;
use App\Models\IncidentType;
use App\Models\ResidentUser;
use App\Services\DispatchService;
use Database\Seeders\HospitalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HospitalRecommendationsTest extends TestCase
{
    use RefreshDatabase;

    private function report(DriverResponderUser $driver): EmergencyReport
    {
        $type = IncidentType::firstOrCreate(
            ['name' => 'Medical Emergency'],
            ['description' => 'Medical incident', 'priority' => 'high'],
        );

        return EmergencyReport::create([
            'reference' => EmergencyReport::generateReference(), 'incident_type_id' => $type->id,
            'reporter_name' => 'Guest reporter', 'location' => 'Balagtas municipal hall',
            'description' => 'Medical incident', 'lat' => 14.8153, 'lng' => 120.9094,
            'assigned_driver_id' => $driver->id, 'status' => 'dispatched', 'reported_at' => now(),
        ]);
    }

    public function test_hospital_seed_is_repeatable_and_preserves_locally_maintained_details(): void
    {
        $this->seed(HospitalSeeder::class);
        $this->assertDatabaseCount('hospitals', 3);
        $hospital = Hospital::where('name', 'United Balagtas Doctors Hospital')->firstOrFail();
        $this->assertNull($hospital->available_beds);
        $this->assertNotEmpty($hospital->source_url);
        $hospital->update(['contact_number' => 'Updated local contact', 'available_beds' => 4]);
        $this->seed(HospitalSeeder::class);
        $this->assertDatabaseCount('hospitals', 3);
        $this->assertSame('Updated local contact', $hospital->fresh()->contact_number);
        $this->assertSame(4, $hospital->fresh()->available_beds);
    }

    public function test_mobile_and_dispatcher_use_the_same_hospital_recommendations(): void
    {
        $this->seed(HospitalSeeder::class);
        $driver = DriverResponderUser::factory()->create();
        $report = $this->report($driver);
        $expected = json_decode(app(DispatchService::class)->recommendHospitals($report)->toJson(), true);
        Sanctum::actingAs($driver, ['driver-read']);
        $this->getJson('/api/driver-responder/assignments/'.$report->id.'/hospitals')->assertOk()
            ->assertJsonPath('report_id', $report->id)->assertJsonCount(3, 'data')
            ->assertJsonPath('data', $expected)->assertJsonPath('data.0.name', 'Bulacan Medical Center')
            ->assertJsonPath('data.0.match', true)->assertJsonPath('data.0.availableBeds', null);
        $this->actingAs(DispatcherUser::factory()->create(), 'web')
            ->get('/dispatcher/reports/'.$report->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dispatcher/Reports/Show')
                ->where('hospitals', $expected));
    }

    public function test_hospital_endpoint_requires_the_assigned_driver_and_read_ability(): void
    {
        $driver = DriverResponderUser::factory()->create();
        $report = $this->report($driver);
        $path = '/api/driver-responder/assignments/'.$report->id.'/hospitals';
        $this->getJson($path)->assertUnauthorized();
        Sanctum::actingAs(ResidentUser::factory()->accepted()->create(), ['*']);
        $this->getJson($path)->assertForbidden();
        Sanctum::actingAs(DriverResponderUser::factory()->create(), ['driver-read']);
        $this->getJson($path)->assertNotFound();
        Sanctum::actingAs($driver, ['session']);
        $this->getJson($path)->assertForbidden();
    }

    public function test_driver_receives_an_empty_list_if_no_hospitals_are_configured(): void
    {
        $driver = DriverResponderUser::factory()->create();
        $report = $this->report($driver);
        Sanctum::actingAs($driver, ['driver-read']);
        $this->getJson('/api/driver-responder/assignments/'.$report->id.'/hospitals')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_driver_response_workflow_transporting_hospital_skip_and_arrived(): void
    {
        $this->seed(HospitalSeeder::class);
        $driver = DriverResponderUser::factory()->create();
        $report = $this->report($driver);
        $otherReport = $this->report($driver);
        Sanctum::actingAs($driver, ['driver-read', 'driver-write']);

        $this->patchJson('/api/driver-responder/assignments/'.$report->id.'/status', [
            'response_status' => 'en-route',
        ])->assertOk()->assertJsonPath('assignment.response_status', 'en-route');
        $this->assertNotNull(\App\Models\DispatchTrip::where('emergency_report_id', $report->id)->value('driver_acknowledged_at'));

        $this->patchJson('/api/driver-responder/assignments/'.$report->id.'/status', [
            'response_status' => 'on-scene',
        ])->assertOk()->assertJsonPath('assignment.response_status', 'on-scene');

        $hospitals = $this->getJson('/api/driver-responder/assignments/'.$report->id.'/hospitals')
            ->assertOk()
            ->json('data');
        $firstHospital = $hospitals[0];
        $secondHospital = $hospitals[1];

        $this->patchJson('/api/driver-responder/assignments/'.$report->id.'/status', [
            'response_status' => 'transporting',
            'hospital_id' => $firstHospital['id'],
            'destination' => $firstHospital['name'],
        ])->assertOk()
            ->assertJsonPath('assignment.response_status', 'transporting')
            ->assertJsonPath('assignment.hospital_id', $firstHospital['id']);

        $this->postJson('/api/driver-responder/assignments/'.$report->id.'/hospitals/'.$firstHospital['id'].'/unavailable')
            ->assertOk()
            ->assertJsonPath('assignment.hospital_id', $secondHospital['id'])
            ->assertJsonMissing(['id' => $firstHospital['id']]);

        // Skipping only applies to this dispatch; other reports still see all hospitals.
        $otherHospitals = $this->getJson('/api/driver-responder/assignments/'.$otherReport->id.'/hospitals')
            ->assertOk()
            ->json('data');
        $this->assertSame($firstHospital['id'], $otherHospitals[0]['id']);

        // Arrived with the skipped hospital is rejected.
        $this->patchJson('/api/driver-responder/assignments/'.$report->id.'/status', [
            'response_status' => 'arrived',
            'hospital_id' => $firstHospital['id'],
        ])->assertUnprocessable();

        // Arrived with the available next hospital succeeds and resolves the dispatch.
        $this->patchJson('/api/driver-responder/assignments/'.$report->id.'/status', [
            'response_status' => 'arrived',
            'hospital_id' => $secondHospital['id'],
            'destination' => $secondHospital['name'],
        ])->assertOk()->assertJsonPath('assignment', null);
        $this->assertSame('resolved', $report->fresh()->status);
        $this->assertNotNull(\App\Models\DispatchTrip::where('emergency_report_id', $report->id)->value('arrived_at'));
    }
}
