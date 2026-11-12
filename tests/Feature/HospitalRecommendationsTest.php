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
        $type = IncidentType::create(['name' => 'Medical Emergency', 'description' => 'Medical incident', 'priority' => 'high']);

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
}
