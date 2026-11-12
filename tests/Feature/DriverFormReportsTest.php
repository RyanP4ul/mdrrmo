<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\DispatcherUser;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResidentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DriverFormReportsTest extends TestCase
{
    use RefreshDatabase;

    public static function staffRoles(): array
    {
        return [
            'admin' => ['admin', AdminUser::class, 8],
            'dispatcher' => ['dispatcher', DispatcherUser::class, 6],
        ];
    }

    private function report(array $attributes = []): EmergencyReport
    {
        $type = IncidentType::firstOrCreate(['name' => 'Medical'], [
            'description' => 'Medical emergency', 'priority' => 'high', 'color' => '#123456',
        ]);

        return EmergencyReport::create($attributes + [
            'reference' => 'RPT-'.Str::upper(Str::random(10)),
            'incident_type_id' => $type->id, 'reporter_type' => 'witness',
            'reporter_name' => 'Citizen Caller', 'location' => 'Town hall',
            'description' => 'Emergency description', 'lat' => 14.8, 'lng' => 120.9,
            'status' => 'resolved', 'priority' => 'high', 'reported_at' => now(),
        ]);
    }

    #[DataProvider('staffRoles')]
    public function test_mobile_submission_is_visible_even_when_active_and_older_than_the_first_emergency_page(string $role, string $model, int $perPage): void
    {
        $driver = DriverResponderUser::factory()->create();
        $report = $this->report([
            'assigned_driver_id' => $driver->id, 'status' => 'dispatched',
            'reported_at' => now()->subDays(10),
        ]);
        for ($i = 0; $i < $perPage + 1; $i++) {
            $this->report();
        }

        Sanctum::actingAs($driver, ['*']);
        $url = '/api/driver-responder/reports/'.$report->id.'/patient';
        $revision = $this->getJson($url)->assertOk()->json('revision');
        $this->putJson($url, [
            'revision' => $revision,
            'driver_section' => ['driverName' => $driver->full_name, 'tripDate' => '2026-11-12', 'fuelUsedDuringTrip' => 0],
        ])->assertOk()->assertJsonPath('form.driver_section.driverName', $driver->full_name);

        $this->actingAs($model::factory()->create(), 'web');
        $this->get(route($role.'.reports.index', ['tab' => 'driver']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.tab', 'driver')
            ->where('reports.total', 1)
            ->has('reports.data', 1)
            ->where('reports.data.0.id', $report->id)
            ->where('reports.data.0.status', 'dispatched')
            ->where('reports.data.0.form.driver_section.driverName', $driver->full_name));
        $this->get(route($role.'.reports.show', $report))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.form.driver_section.driverName', $driver->full_name));
        $this->get(route($role.'.reports.driver-form', $report))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Reports/DriverForm')
            ->where('report.id', $report->id)
            ->where('driverSection.driverName', $driver->full_name)
            ->where('driverSection.fuelUsedDuringTrip', 0)
            ->where('backUrl', route($role.'.reports.index', ['tab' => 'driver']))
            ->where('reportUrl', route($role.'.reports.show', $report))
            ->missing('report.form')
            ->missing('emergencySection'));
    }

    #[DataProvider('staffRoles')]
    public function test_driver_forms_are_paginated_and_filtered_on_the_server(string $role, string $model, int $perPage): void
    {
        for ($i = 0; $i < $perPage + 2; $i++) {
            $this->report(['location' => 'District hospital', 'reported_at' => now()->subMinutes($i)])
                ->form()->create(['emergency_section' => [], 'driver_section' => ['driverName' => 'Driver '.$i]]);
        }
        $this->report(['priority' => 'low'])->form()->create(['emergency_section' => [], 'driver_section' => ['driverName' => 'Other Driver']]);
        $this->report(['location' => 'District hospital']);
        $this->actingAs($model::factory()->create(), 'web');
        $query = ['tab' => 'driver', 'search' => 'District hospital', 'priority' => 'high'];
        $first = $this->get(route($role.'.reports.index', $query))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('reports.total', $perPage + 2)
            ->has('reports.data', $perPage)
            ->where('reports.last_page', 2));
        $next = $first->viewData('page')['props']['reports']['next_page_url'] ?? null;
        // The next-page link must retain the selected tab and search filters.
        $this->assertNotNull($next);
        parse_str(parse_url($next, PHP_URL_QUERY), $nextQuery);
        $this->assertSame('driver', $nextQuery['tab']);
        $this->assertSame('District hospital', $nextQuery['search']);
        $this->get($next)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('reports.total', $perPage + 2)
            ->where('reports.current_page', 2)
            ->has('reports.data', 2));
    }

    #[DataProvider('staffRoles')]
    public function test_patient_only_and_blank_forms_are_excluded_but_zero_fuel_is_valid(string $role, string $model, int $perPage): void
    {
        foreach ([[], ['driverName' => null], ['driverName' => '']] as $section) {
            $this->report()->form()->create([
                'driver_section' => $section, 'emergency_section' => ['patientName' => 'Patient'],
            ]);
        }
        $zeroFuel = $this->report();
        $zeroFuel->form()->create(['emergency_section' => [], 'driver_section' => ['fuelUsedDuringTrip' => 0]]);
        $this->actingAs($model::factory()->create(), 'web');
        $this->get(route($role.'.reports.index', ['tab' => 'driver']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('reports.total', 1)
            ->where('reports.data.0.id', $zeroFuel->id));
    }

    public function test_dispatcher_emergency_history_still_excludes_active_incidents(): void
    {
        $this->report(['status' => 'dispatched'])->form()->create(['emergency_section' => [], 'driver_section' => ['driverName' => 'Active Driver']]);
        $resolved = $this->report();
        $this->actingAs(DispatcherUser::factory()->create());
        $this->get(route('dispatcher.reports.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('reports.total', 1)
                ->where('reports.data.0.id', $resolved->id));
    }

    #[DataProvider('staffRoles')]
    public function test_driver_form_details_require_a_saved_form_and_staff_access(string $role, string $model, int $perPage): void
    {
        $report = $this->report();
        $url = route($role.'.reports.driver-form', $report);
        $this->actingAs($model::factory()->create(), 'web');
        $this->get($url)->assertNotFound();
        $report->form()->create(['driver_section' => [], 'emergency_section' => ['patientName' => 'Patient']]);
        $this->get($url)->assertNotFound();
        $report->form()->update(['driver_section' => ['driverName' => 'Driver']]);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Reports/DriverForm')->missing('report.form')->missing('emergencySection'));
        $this->actingAs(ResidentUser::factory()->accepted()->create(), 'web');
        $this->get($url)->assertForbidden();
        $this->actingAs(DriverResponderUser::factory()->create(), 'web');
        $this->get($url)->assertForbidden();
    }
}
