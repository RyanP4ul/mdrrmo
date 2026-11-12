<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\DispatcherUser;
use App\Models\DispatchTrip;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\MaintenanceReport;
use App\Models\ReportForm;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Models\Vehicle;
use App\Services\EmergencyOperationsReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminOperationsPdfReportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
        gc_collect_cycles();
    }

    private function createIncidentType(string $name = 'Medical Emergency', string $priority = 'high'): IncidentType
    {
        return IncidentType::firstOrCreate(
            ['name' => $name],
            ['description' => $name.' response', 'priority' => $priority, 'color' => '#dc2626']
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createEmergencyReport(IncidentType $type, array $attributes = []): EmergencyReport
    {
        return EmergencyReport::create(array_merge([
            'reference' => 'RPT-'.strtoupper(Str::random(8)),
            'incident_type_id' => $type->id,
            'reporter_type' => 'witness',
            'reporter_name' => 'Citizen Caller',
            'location' => 'Brgy. Poblacion, Balagtas',
            'description' => 'Emergency call received.',
            'lat' => 14.81530,
            'lng' => 120.90940,
            'status' => 'pending',
            'priority' => $type->priority,
            'reported_at' => CarbonImmutable::now('UTC'),
        ], $attributes));
    }

    public function test_authorization_and_privacy_access_controls(): void
    {
        Storage::fake('public');

        $this->assertFalse(Route::has('dispatcher.reports.operations.summary'));
        $this->assertFalse(Route::has('dispatcher.reports.operations.pdf'));

        $summaryUrl = route('admin.reports.operations.summary', [
            'period_type' => 'weekly',
            'week_date' => '2026-11-12',
        ]);
        $pdfPreviewUrl = route('admin.reports.operations.pdf', [
            'period_type' => 'weekly',
            'week_date' => '2026-11-12',
            'mode' => 'preview',
        ]);
        $pdfDownloadUrl = route('admin.reports.operations.pdf', [
            'period_type' => 'weekly',
            'week_date' => '2026-11-12',
            'mode' => 'download',
        ]);

        // Guest rejected.
        $this->getJson($summaryUrl)->assertUnauthorized();
        $this->get($pdfPreviewUrl)->assertRedirect();

        // Non-admin roles (including dispatchers) rejected.
        foreach ([
            DispatcherUser::factory()->create(),
            DriverResponderUser::factory()->create(),
            ResidentUser::factory()->accepted()->create(),
        ] as $nonAdmin) {
            $this->actingAs($nonAdmin)->getJson($summaryUrl)->assertForbidden();
            $this->actingAs($nonAdmin)->get($pdfPreviewUrl)->assertForbidden();
        }

        // Inactive admin rejected.
        $inactiveAdmin = AdminUser::factory()->create(['status' => 'inactive']);
        $this->actingAs($inactiveAdmin)->getJson($summaryUrl)->assertForbidden();

        // Authorized active admin succeeds.
        $admin = AdminUser::factory()->create(['status' => 'active']);

        $this->actingAs($admin)
            ->getJson($summaryUrl)
            ->assertOk()
            ->assertJsonPath('period.period_type', 'weekly')
            ->assertJsonPath('stats.total_incidents', 0);

        $previewResponse = $this->actingAs($admin)->get($pdfPreviewUrl);
        $previewResponse->assertOk();
        $this->assertSame('application/pdf', $previewResponse->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', (string) $previewResponse->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $previewResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $previewResponse->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-', (string) $previewResponse->getContent());

        $downloadResponse = $this->actingAs($admin)->get($pdfDownloadUrl);
        $downloadResponse->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $downloadResponse->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', (string) $downloadResponse->getContent());

        // Never persists sensitive exports to public disk.
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_weekly_report_manila_monday_sunday_utc_boundaries_and_partial_flag(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 11, 12, 10, 0, 0, 'Asia/Manila'));

        $admin = AdminUser::factory()->create();
        $fire = $this->createIncidentType('Fire', 'critical');

        // Target week: Nov 2, 2026 (Mon 00:00 PHT = Nov 1 16:00 UTC) through Nov 8, 2026 (Sun 23:59:59 PHT = Nov 8 15:59:59 UTC).
        // Just before Monday 00:00 PHT (Sun Nov 1 23:59:59 PHT -> EXCLUDED).
        $this->createEmergencyReport($fire, [
            'reference' => 'RPT-OUT-BEFORE',
            'reported_at' => CarbonImmutable::create(2026, 11, 1, 15, 59, 59, 'UTC'),
        ]);
        // Exact Monday 00:00:00 PHT -> INCLUDED.
        $this->createEmergencyReport($fire, [
            'reference' => 'RPT-IN-MON',
            'status' => 'resolved',
            'reported_at' => CarbonImmutable::create(2026, 11, 1, 16, 0, 0, 'UTC'),
            'resolved_at' => CarbonImmutable::create(2026, 11, 1, 16, 45, 0, 'UTC'),
        ]);
        // Exact Sunday 23:59:59 PHT -> INCLUDED.
        $this->createEmergencyReport($fire, [
            'reference' => 'RPT-IN-SUN',
            'status' => 'pending',
            'reported_at' => CarbonImmutable::create(2026, 11, 8, 15, 59, 59, 'UTC'),
        ]);
        // Just after Sunday 23:59:59 PHT (Mon Nov 9 00:00:00 PHT -> EXCLUDED from Nov 2-8 week).
        $currentWeekIncident = $this->createEmergencyReport($fire, [
            'reference' => 'RPT-NEXT-MON',
            'status' => 'dispatched',
            'reported_at' => CarbonImmutable::create(2026, 11, 8, 16, 0, 0, 'UTC'),
        ]);

        // Historical full week selected via Wednesday 2026-11-04.
        $this->actingAs($admin)
            ->getJson(route('admin.reports.operations.summary', [
                'period_type' => 'weekly',
                'week_date' => '2026-11-04',
            ]))
            ->assertOk()
            ->assertJsonPath('period.start_date', '2026-11-02')
            ->assertJsonPath('period.end_date', '2026-11-08')
            ->assertJsonPath('period.is_partial_period', false)
            ->assertJsonPath('stats.total_incidents', 2)
            ->assertJsonPath('stats.completed_operations', 1)
            ->assertJsonPath('stats.pending_ongoing_operations', 1);

        // Current incomplete week (Mon Nov 9 – Sun Nov 15) should include RPT-NEXT-MON and flag partial period.
        $service = app(EmergencyOperationsReportService::class);
        $partialData = $service->buildReport(
            ['period_type' => 'weekly', 'week_date' => '2026-11-12'],
            $admin,
            false,
            CarbonImmutable::now('Asia/Manila')
        );

        $this->assertTrue($partialData['period']['is_partial_period']);
        $this->assertSame('2026-11-09', $partialData['period']['start_date']);
        $this->assertSame('2026-11-15', $partialData['period']['end_date']);
        $this->assertSame(4, $partialData['period']['elapsed_days']);
        $this->assertSame(1, $partialData['report_info']['total_incidents']);
        $this->assertSame($currentWeekIncident->reference, $partialData['incidents'][0]['incident_ref']);

        $html = view('reports.emergency-operations-pdf', ['report' => $partialData])->render();
        $this->assertStringContainsString('Report Status: In Progress — Data as of', $html);
        $this->assertStringNotContainsString('PARTIAL REPORTING PERIOD NOTICE', $html);
        $this->assertStringNotContainsString('Schema Integrity Note', $html);
        $this->assertStringNotContainsString('emergency_reports.', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('MUNICIPAL DISASTER RISK REDUCTION AND MANAGEMENT OFFICE (MDRRMO)', $html);
        $this->assertStringContainsString('RPT-NEXT-MON', $html);
        $this->assertStringNotContainsString('RPT-IN-MON', $html);
    }

    public function test_monthly_report_filters_exact_manila_calendar_month(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 11, 12, 10, 0, 0, 'Asia/Manila'));

        $admin = AdminUser::factory()->create();
        $medical = $this->createIncidentType('Medical', 'high');

        // 2026-10-31 16:30:00 UTC === 2026-11-01 00:30:00 PHT (belongs to November 2026 in PHT).
        $this->createEmergencyReport($medical, [
            'reference' => 'RPT-MANILA-NOV-1',
            'status' => 'resolved',
            'reported_at' => CarbonImmutable::create(2026, 10, 31, 16, 30, 0, 'UTC'),
            'resolved_at' => CarbonImmutable::create(2026, 10, 31, 17, 15, 0, 'UTC'),
        ]);
        // 2026-10-31 15:30:00 UTC === 2026-10-31 23:30:00 PHT (belongs to October 2026).
        $this->createEmergencyReport($medical, [
            'reference' => 'RPT-MANILA-OCT-31',
            'status' => 'resolved',
            'reported_at' => CarbonImmutable::create(2026, 10, 31, 15, 30, 0, 'UTC'),
            'resolved_at' => CarbonImmutable::create(2026, 10, 31, 16, 10, 0, 'UTC'),
        ]);

        // Historical October 2026.
        $this->actingAs($admin)
            ->getJson(route('admin.reports.operations.summary', [
                'period_type' => 'monthly',
                'month' => 10,
                'year' => 2026,
            ]))
            ->assertOk()
            ->assertJsonPath('period.start_date', '2026-10-01')
            ->assertJsonPath('period.end_date', '2026-10-31')
            ->assertJsonPath('period.is_partial_period', false)
            ->assertJsonPath('stats.total_incidents', 1);

        // Partial current November 2026.
        $this->actingAs($admin)
            ->getJson(route('admin.reports.operations.summary', [
                'period_type' => 'monthly',
                'month' => 11,
                'year' => 2026,
            ]))
            ->assertOk()
            ->assertJsonPath('period.start_date', '2026-11-01')
            ->assertJsonPath('period.end_date', '2026-11-30')
            ->assertJsonPath('period.is_partial_period', true)
            ->assertJsonPath('stats.total_incidents', 1);
    }

    public function test_empty_period_handles_zero_records_and_separates_current_fleet_snapshot(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 11, 12, 10, 0, 0, 'Asia/Manila'));

        $admin = AdminUser::factory()->create();
        $driver = DriverResponderUser::factory()->create();
        $team = ResponseTeam::create(['team_name' => 'Alpha Rescue', 'status' => 'active']);
        $vehicle = Vehicle::create([
            'response_team_id' => $team->id,
            'name' => 'Rescue Ambulance 1',
            'vehicle_type' => 'ambulance',
            'plate_number' => 'MDR-901',
            'status' => 'maintenance',
            'maintenance_warning' => true,
        ]);
        MaintenanceReport::create([
            'reference' => 'MNT-'.strtoupper(Str::random(6)),
            'vehicle_id' => $vehicle->id,
            'response_team_id' => $team->id,
            'reported_by' => $driver->id,
            'issue_type' => 'Brake System',
            'severity' => 'high',
            'mileage' => 42500,
            'description' => 'Brake pads worn below threshold.',
            'recommend_out_of_service' => true,
            'status' => 'in_maintenance',
        ]);

        $service = app(EmergencyOperationsReportService::class);
        $report = $service->buildReport(
            ['period_type' => 'monthly', 'month' => 1, 'year' => 2025],
            $admin,
            false,
            CarbonImmutable::now('Asia/Manila')
        );

        $this->assertSame(0, $report['report_info']['total_incidents']);
        $this->assertSame('N/A', $report['performance']['completion_rate']);
        $this->assertSame(0, $report['executive_summary']['average_dispatch_delay']['valid_count']);
        $this->assertSame(0, $report['vehicle_logistics_summary']['total_deployments']);
        $this->assertSame(1, $report['vehicle_logistics_summary']['vehicles_currently_unavailable']);
        $this->assertSame(1, $report['vehicle_logistics_summary']['vehicles_requiring_maintenance']);

        $html = view('reports.emergency-operations-pdf', ['report' => $report])->render();
        $this->assertStringContainsString('No emergency incidents were recorded during this reporting period.', $html);
        $this->assertStringContainsString('No emergency incidents or patient records were recorded during this reporting period.', $html);
        $this->assertStringNotContainsString('Not Recorded', $html);
        foreach ([
            'Section 1: Report Overview',
            'Section 2: Patient and Emergency Records',
            'Section 3: Dispatch and Response Records',
            'Section 4: Vehicle Utilization and Logistics',
            'Section 5: Operational Performance',
            'Section 6: Report Sign-Off',
        ] as $sectionHeading) {
            $this->assertStringContainsString($sectionHeading, $html);
        }
        $this->assertStringNotContainsString('Emergency Incident Summary', $html);
        $this->assertStringNotContainsString('Patient and Treatment Records', $html);
        $this->assertStringNotContainsString('Generated By', $html);
        $this->assertStringNotContainsString('Patient Privacy', $html);
        $this->assertStringNotContainsString('Key Operational Observations', $html);
        $this->assertStringNotContainsString('Vehicle &amp; Plate', $html);
        $this->assertStringNotContainsString('Rescue Ambulance 1', $html);
        $this->assertStringContainsString('Authorized MDRRMO Administrator', $html);
        $this->assertStringNotContainsString('Authorized Dispatcher, MDRRMO', $html);
        $this->assertStringContainsString('MDR-901', $html);
        $this->assertStringContainsString('Brake System', $html);

        $pdfResponse = $this->actingAs($admin)
            ->get(route('admin.reports.operations.pdf', [
                'period_type' => 'monthly',
                'month' => 1,
                'year' => 2025,
                'mode' => 'preview',
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('/Subtype /Image', (string) $pdfResponse->getContent());
    }

    public function test_complete_operations_accuracy_anti_double_counting_timings_fuel_and_privacy(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 11, 12, 10, 0, 0, 'Asia/Manila'));

        $admin = AdminUser::factory()->create(['first_name' => 'Elena', 'last_name' => 'Santos']);
        $driver1 = DriverResponderUser::factory()->create(['first_name' => 'Marco', 'last_name' => 'Reyes']);
        $resp1 = DriverResponderUser::factory()->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);
        $resp2 = DriverResponderUser::factory()->create(['first_name' => 'Leo', 'last_name' => 'Diaz']);
        $resp3 = DriverResponderUser::factory()->create(['first_name' => 'Nora', 'last_name' => 'Vega']);

        $team = ResponseTeam::create([
            'team_name' => 'Bravo Team',
            'status' => 'active',
            'member_roles_enabled' => true,
        ]);
        $team->members()->create(['user_id' => $driver1->id, 'name' => $driver1->full_name, 'role' => 'Driver', 'status' => 'active']);
        $team->members()->create(['user_id' => $resp1->id, 'name' => $resp1->full_name, 'role' => 'Responder', 'status' => 'active']);
        $team->members()->create(['user_id' => $resp2->id, 'name' => $resp2->full_name, 'role' => 'Responder', 'status' => 'active']);
        $team->members()->create(['user_id' => $resp3->id, 'name' => $resp3->full_name, 'role' => 'Responder', 'status' => 'active']);

        $vehicle1 = Vehicle::create([
            'response_team_id' => $team->id,
            'name' => 'Ambulance Alpha',
            'vehicle_type' => 'ambulance',
            'plate_number' => 'AMB-101',
            'status' => 'available',
        ]);
        $vehicle2 = Vehicle::create([
            'name' => 'Fire Pumper 2',
            'vehicle_type' => 'fire-truck',
            'plate_number' => 'FIR-202',
            'status' => 'en-route',
        ]);

        $fireType = $this->createIncidentType('Fire', 'critical');
        $floodType = $this->createIncidentType('Flood', 'high');
        $vehicularType = $this->createIncidentType('Vehicular Accident', 'high');

        // Incident 1 (Resolved, complete timestamps, patient form, fuel form, 2 tracking sessions on same vehicle).
        $t1Report = CarbonImmutable::create(2026, 10, 6, 9, 0, 0, 'Asia/Manila')->utc();
        $er1 = $this->createEmergencyReport($fireType, [
            'reference' => 'RPT-OCT-001',
            'response_team_id' => $team->id,
            'assigned_driver_id' => $driver1->id,
            'reporter_contact' => '09171234567',
            'location' => 'MacArthur Highway, Balagtas',
            'status' => 'resolved',
            'reported_at' => $t1Report,
            'acknowledged_at' => $t1Report->addMinutes(2),
            'resolved_at' => $t1Report->addMinutes(50),
        ]);
        DispatchTrip::create([
            'emergency_report_id' => $er1->id,
            'destination' => 'Bulacan Medical Center',
            'driver_name' => $driver1->full_name,
            'started_at' => $t1Report->addMinutes(6),   // delay = 6m
            'on_scene_at' => $t1Report->addMinutes(18), // travel = 12m
            'picked_up_at' => $t1Report->addMinutes(26),
            'arrived_at' => $t1Report->addMinutes(36),
            'returning_at' => $t1Report->addMinutes(44),
            'distance_km' => 18.50,
        ]);
        ReportForm::create([
            'emergency_report_id' => $er1->id,
            'driver_section' => [
                'tripDate' => '2026-10-06',
                'driverName' => 'Marco Reyes',
                'governmentCardPlateNo' => 'AMB-101',
                'balanceInTank' => '60',
                'purchasedDuringTrip' => '25 L',
                'fuelUsedDuringTrip' => '14.5 L',
                'remainingFuel' => '70.5',
                'gearOilUsed' => '0.5 L',
                'purpose' => 'Fire emergency response & patient transport',
            ],
            'emergency_section' => [
                'timeReported' => '08:58',
                'patientName' => 'Juan Dela Cruz',
                'age' => '34',
                'sex' => 'Male',
                'address' => 'Brgy. San Juan Private Street 99',
                'typeOfIncident' => 'Smoke inhalation & minor burn',
                'allergies' => 'None',
                'medications' => 'Normal Saline',
                'assessmentComment' => 'Conscious, stable breathing',
                'treatmentManagement' => 'Oxygen supplementation and sterile dressing',
                'vitalSigns' => [
                    'bloodPressure' => '120/80',
                    'pulseRate' => '88',
                    'respiration' => '20',
                ],
            ],
        ]);
        // Two tracking sessions on same vehicle & incident must NOT double-count deployments or incident counts.
        DB::table('vehicle_tracking_sessions')->insert([
            [
                'id' => (string) Str::uuid(),
                'vehicle_id' => $vehicle1->id,
                'driver_id' => $driver1->id,
                'emergency_report_id' => $er1->id,
                'consent_version' => 'driver-2026-11-04',
                'started_at' => $t1Report->addMinutes(6),
                'stopped_at' => $t1Report->addMinutes(30),
                'expires_at' => $t1Report->addHours(4),
            ],
            [
                'id' => (string) Str::uuid(),
                'vehicle_id' => $vehicle1->id,
                'driver_id' => $driver1->id,
                'emergency_report_id' => $er1->id,
                'consent_version' => 'driver-2026-11-04',
                'started_at' => $t1Report->addMinutes(31),
                'stopped_at' => $t1Report->addMinutes(50),
                'expires_at' => $t1Report->addHours(4),
            ],
        ]);

        // Incident 2 (Dispatched ongoing, incomplete timestamps: started_at set, on_scene_at/resolved_at null).
        $t2Report = CarbonImmutable::create(2026, 10, 7, 14, 0, 0, 'Asia/Manila')->utc();
        $er2 = $this->createEmergencyReport($floodType, [
            'reference' => 'RPT-OCT-002',
            'status' => 'dispatched',
            'reported_at' => $t2Report,
            'acknowledged_at' => $t2Report->addMinutes(4),
        ]);
        $vehicle2->update(['assigned_report_id' => $er2->id]);
        DispatchTrip::create([
            'emergency_report_id' => $er2->id,
            'vehicle_number' => 'FIR-202',
            'started_at' => $t2Report->addMinutes(10), // delay = 10m; travel/op duration Not Recorded
            'on_scene_at' => null,
            'distance_km' => 6.25,
        ]);

        // Incident 3 (Cancelled / Withdrawn).
        $t3Report = CarbonImmutable::create(2026, 10, 8, 11, 0, 0, 'Asia/Manila')->utc();
        $this->createEmergencyReport($vehicularType, [
            'reference' => 'RPT-OCT-003',
            'status' => 'invalid',
            'reported_at' => $t3Report,
            'withdrawn_at' => $t3Report->addMinutes(3),
            'withdrawal_reason' => 'Caller confirmed false alarm',
        ]);

        // Incident 4 (Invalidated).
        $t4Report = CarbonImmutable::create(2026, 10, 9, 16, 0, 0, 'Asia/Manila')->utc();
        $this->createEmergencyReport($fireType, [
            'reference' => 'RPT-OCT-004',
            'status' => 'invalid',
            'reported_at' => $t4Report,
        ]);

        $service = app(EmergencyOperationsReportService::class);
        $maskedReport = $service->buildReport(
            ['period_type' => 'weekly', 'week_date' => '2026-10-08'],
            $admin,
            false,
            CarbonImmutable::now('Asia/Manila')
        );

        // No double counting: exactly 4 unique incidents despite 4 team members + 2 tracking sessions.
        $this->assertSame(4, $maskedReport['report_info']['total_incidents']);
        $this->assertSame(1, $maskedReport['report_info']['completed_operations']);
        $this->assertSame(1, $maskedReport['report_info']['pending_ongoing_operations']);
        $this->assertSame(2, $maskedReport['report_info']['cancelled_or_unsuccessful_operations']);
        $this->assertSame(1, $maskedReport['report_info']['withdrawn_operations']);
        $this->assertSame(1, $maskedReport['report_info']['invalidated_operations']);

        // Averages exclude incomplete records and track exact denominators.
        $this->assertSame(2, $maskedReport['executive_summary']['average_dispatch_delay']['valid_count']);
        $this->assertSame(8.0, $maskedReport['executive_summary']['average_dispatch_delay']['average_minutes']);
        $this->assertSame(1, $maskedReport['executive_summary']['average_response_time']['valid_count']);
        $this->assertSame(12.0, $maskedReport['executive_summary']['average_response_time']['average_minutes']);
        $this->assertSame(1, $maskedReport['executive_summary']['average_operation_duration']['valid_count']);
        $this->assertSame(44.0, $maskedReport['executive_summary']['average_operation_duration']['average_minutes']);
        $this->assertSame('25.0%', $maskedReport['performance']['completion_rate']);

        // Timelines preserve separate return-to-base missing timestamp & '—' for incomplete trip.
        $this->assertCount(2, $maskedReport['timelines']);
        $this->assertSame('—', $maskedReport['timelines'][0]['returned_to_base_at']);
        $this->assertSame('—', $maskedReport['timelines'][1]['arrived_at']);
        $this->assertSame('—', $maskedReport['timelines'][1]['response_travel_time']);
        $this->assertSame('—', $maskedReport['timelines'][1]['operation_duration']);

        // Fuel & Odometer checks: fuel purchased (25 L) never mixed into consumed (14.5 L).
        $this->assertSame('25.00 L', $maskedReport['vehicle_logistics_summary']['total_fuel_purchased_l']);
        $this->assertSame('14.50 L', $maskedReport['vehicle_logistics_summary']['total_fuel_consumed_l']);
        $this->assertSame('24.75 km', $maskedReport['vehicle_logistics_summary']['total_logged_distance_km']);
        $this->assertSame('—', $maskedReport['vehicle_logistics_summary']['total_odometer_distance']);
        $this->assertSame(2, $maskedReport['vehicle_logistics_summary']['distinct_vehicles_deployed']);
        $this->assertSame(4, $maskedReport['executive_summary']['total_personnel_involved']);

        // Explicit privacy masked HTML hides patient full name, clinical street address, and full contact digits.
        $maskedHtml = view('reports.emergency-operations-pdf', ['report' => $maskedReport])->render();
        $this->assertStringNotContainsString('Juan Dela Cruz', $maskedHtml);
        $this->assertStringNotContainsString('Brgy. San Juan Private Street 99', $maskedHtml);
        $this->assertStringNotContainsString('09171234567', $maskedHtml);
        $this->assertStringContainsString('*******4567', $maskedHtml);
        $this->assertStringNotContainsString('Not Recorded', $maskedHtml);
        $this->assertStringNotContainsString('Schema Integrity Note', $maskedHtml);
        $this->assertStringNotContainsString('emergency_reports.', $maskedHtml);
        $this->assertStringNotContainsString('trip.started_at', $maskedHtml);
        $this->assertStringNotContainsString('Key Operational Observations', $maskedHtml);
        $this->assertStringNotContainsString('Vehicle &amp; Plate', $maskedHtml);
        $this->assertStringNotContainsString('Ambulance Alpha', $maskedHtml);
        $this->assertStringNotContainsString('Fire Pumper 2', $maskedHtml);
        $this->assertStringContainsString('AMB-101', $maskedHtml);
        $this->assertStringContainsString('FIR-202', $maskedHtml);
        $this->assertStringContainsString('Privacy Mode enabled', $maskedHtml);
        $this->assertStringContainsString('Total Emergencies', $maskedHtml);
        $this->assertStringNotContainsString('Generated By', $maskedHtml);
        $this->assertStringNotContainsString('Patient Privacy', $maskedHtml);
        $this->assertStringNotContainsString('Dispatches Initiated', $maskedHtml);
        $this->assertStringNotContainsString('Completed Operations', $maskedHtml);
        $this->assertStringNotContainsString('Pending / Ongoing', $maskedHtml);
        $this->assertStringNotContainsString('Cancelled / Failed', $maskedHtml);

        // Admin default report (omitting include_patient_details) shows full patient name, address, and contact.
        $detailedReport = $service->buildReport(
            ['period_type' => 'weekly', 'week_date' => '2026-10-08'],
            $admin,
            now: CarbonImmutable::now('Asia/Manila')
        );
        $detailedHtml = view('reports.emergency-operations-pdf', ['report' => $detailedReport])->render();
        $this->assertStringContainsString('Juan Dela Cruz', $detailedHtml);
        $this->assertStringContainsString('Brgy. San Juan Private Street 99', $detailedHtml);
        $this->assertStringContainsString('09171234567', $detailedHtml);
        $this->assertStringContainsString('Date &amp; Time', $detailedHtml);
        $this->assertStringNotContainsString('Privacy Mode enabled', $detailedHtml);
    }

    public function test_incompatible_fuel_units_are_excluded_from_sums_and_generation_is_read_only(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 11, 12, 10, 0, 0, 'Asia/Manila'));

        $admin = AdminUser::factory()->create();
        $fire = $this->createIncidentType('Fire', 'high');
        $er = $this->createEmergencyReport($fire, [
            'reference' => 'RPT-FUEL-TEST',
            'status' => 'resolved',
            'reported_at' => CarbonImmutable::create(2026, 11, 10, 2, 0, 0, 'UTC'),
            'resolved_at' => CarbonImmutable::create(2026, 11, 10, 3, 0, 0, 'UTC'),
        ]);
        ReportForm::create([
            'emergency_report_id' => $er->id,
            'driver_section' => [
                'governmentCardPlateNo' => 'UTL-777',
                'purchasedDuringTrip' => '15 L',
                'fuelUsedDuringTrip' => '3 gallons',
            ],
            'emergency_section' => [],
        ]);

        $countsBefore = [
            'reports' => DB::table('emergency_reports')->count(),
            'trips' => DB::table('dispatch_trips')->count(),
            'forms' => DB::table('report_forms')->count(),
            'vehicles' => DB::table('vehicles')->count(),
            'maintenance' => DB::table('maintenance_reports')->count(),
            'audits' => DB::table('audit_logs')->count(),
        ];

        $service = app(EmergencyOperationsReportService::class);
        $report = $service->buildReport(
            ['period_type' => 'weekly', 'week_date' => '2026-11-10'],
            $admin,
            false,
            CarbonImmutable::now('Asia/Manila')
        );

        $this->assertSame('15.00 L', $report['vehicle_logistics_summary']['total_fuel_purchased_l']);
        $this->assertSame(1, $report['vehicle_logistics_summary']['fuel_purchased_record_count']);
        $this->assertSame('—', $report['vehicle_logistics_summary']['total_fuel_consumed_l']);
        $this->assertSame(0, $report['vehicle_logistics_summary']['fuel_consumed_record_count']);
        $this->assertSame(1, $report['vehicle_logistics_summary']['incompatible_fuel_notes_count']);

        $this->actingAs($admin)
            ->get(route('admin.reports.operations.pdf', [
                'period_type' => 'weekly',
                'week_date' => '2026-11-10',
                'mode' => 'download',
            ]))
            ->assertOk();

        $countsAfter = [
            'reports' => DB::table('emergency_reports')->count(),
            'trips' => DB::table('dispatch_trips')->count(),
            'forms' => DB::table('report_forms')->count(),
            'vehicles' => DB::table('vehicles')->count(),
            'maintenance' => DB::table('maintenance_reports')->count(),
            'audits' => DB::table('audit_logs')->count(),
        ];
        $this->assertSame($countsBefore, $countsAfter);
    }

    public function test_large_multipage_report_renders_multiple_pages_and_admin_reports_index_receives_defaults(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 11, 12, 10, 0, 0, 'Asia/Manila'));

        $admin = AdminUser::factory()->create();
        $dispatcher = DispatcherUser::factory()->create();
        $medical = $this->createIncidentType('Medical Emergency', 'high');
        $base = CarbonImmutable::create(2026, 11, 3, 1, 0, 0, 'UTC');

        for ($i = 1; $i <= 20; $i++) {
            $reportedAt = $base->addHours($i);
            $er = $this->createEmergencyReport($medical, [
                'reference' => sprintf('RPT-BULK-%03d', $i),
                'status' => 'resolved',
                'reported_at' => $reportedAt,
                'acknowledged_at' => $reportedAt->addMinutes(2),
                'resolved_at' => $reportedAt->addMinutes(35),
            ]);
            DispatchTrip::create([
                'emergency_report_id' => $er->id,
                'vehicle_number' => 'AMB-500',
                'started_at' => $reportedAt->addMinutes(4),
                'on_scene_at' => $reportedAt->addMinutes(14),
                'returning_at' => $reportedAt->addMinutes(30),
                'distance_km' => 8.0,
            ]);
        }

        // Admin index Inertia page receives operationsDefaults; Dispatcher index does not.
        $this->actingAs($admin)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports/Index')
                ->has('operationsDefaults')
                ->where('operationsDefaults.timezone', 'Asia/Manila')
                ->where('operationsDefaults.today_date', '2026-11-12')
            );

        $this->actingAs($dispatcher)
            ->get(route('dispatcher.reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dispatcher/Reports/Index')
                ->missing('operationsDefaults')
            );

        // Multi-page DomPDF rendering verification (20 incidents spanning 3 detailed tables).
        $report = app(EmergencyOperationsReportService::class)->buildReport(
            ['period_type' => 'monthly', 'month' => 11, 'year' => 2026],
            $admin,
            false,
            CarbonImmutable::now('Asia/Manila')
        );
        $dompdf = Pdf::loadView('reports.emergency-operations-pdf', ['report' => $report])
            ->setPaper('a4', 'portrait')
            ->getDomPDF();
        $dompdf->render();

        $this->assertGreaterThan(1, $dompdf->getCanvas()->get_page_count());
        unset($dompdf, $report);
        gc_collect_cycles();

        $response = $this->actingAs($admin)->get(route('admin.reports.operations.pdf', [
            'period_type' => 'monthly',
            'month' => 11,
            'year' => 2026,
            'mode' => 'preview',
        ]));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    public function test_dispatched_and_resolved_team_operations_resolve_plate_from_team_active_vehicle_without_vehicle_names_or_observations(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 11, 12, 10, 0, 0, 'Asia/Manila'));

        $admin = AdminUser::factory()->create();
        $driver = DriverResponderUser::factory()->create(['first_name' => 'Ramon', 'last_name' => 'Delgado']);
        $fire = $this->createIncidentType('Fire', 'critical');

        $team = ResponseTeam::create([
            'team_name' => 'Team Alpha',
            'status' => 'active',
        ]);
        $vehicle = Vehicle::create([
            'response_team_id' => $team->id,
            'name' => 'Ambulance 01',
            'vehicle_type' => 'ambulance',
            'plate_number' => 'AMB-10',
            'status' => 'available',
            'assigned_report_id' => null,
        ]);
        $team->update(['active_vehicle_id' => $vehicle->id]);

        $reportedAt = CarbonImmutable::create(2026, 11, 9, 8, 0, 0, 'Asia/Manila')->utc();
        $er = $this->createEmergencyReport($fire, [
            'reference' => 'RPT-TEAM-PLATE-1',
            'response_team_id' => $team->id,
            'assigned_driver_id' => $driver->id,
            'status' => 'resolved',
            'reported_at' => $reportedAt,
            'resolved_at' => $reportedAt->addMinutes(20),
        ]);
        DispatchTrip::create([
            'emergency_report_id' => $er->id,
            'driver_name' => $driver->full_name,
            'vehicle_number' => null,
            'started_at' => $reportedAt->addMinutes(5),
            'on_scene_at' => $reportedAt->addMinutes(12),
        ]);

        $report = app(EmergencyOperationsReportService::class)->buildReport(
            ['period_type' => 'weekly', 'week_date' => '2026-11-12'],
            $admin,
            false,
            CarbonImmutable::now('Asia/Manila')
        );

        $this->assertSame(1, $report['executive_summary']['total_vehicles_deployed']);
        $this->assertSame('AMB-10', $report['timelines'][0]['plate_number']);
        $this->assertSame('AMB-10', $report['vehicle_utilization'][0]['plate_number']);
        $this->assertSame('AMB-10', $report['vehicle_deployments'][0]['plate_number']);
        $this->assertArrayNotHasKey('observations', $report);

        $html = view('reports.emergency-operations-pdf', ['report' => $report])->render();
        $this->assertStringContainsString('AMB-10', $html);
        $this->assertStringNotContainsString('Ambulance 01', $html);
        $this->assertStringNotContainsString('Vehicle &amp; Plate', $html);
        $this->assertStringNotContainsString('Key Operational Observations', $html);
        $this->assertStringContainsString('Section 6: Report Sign-Off', $html);
    }

    public function test_section_two_patient_and_emergency_table_mapping_fallbacks_and_category_grand_total(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 11, 12, 10, 0, 0, 'Asia/Manila'));

        $admin = AdminUser::factory()->create();
        $fire = $this->createIncidentType('Fire', 'critical');
        $medical = $this->createIncidentType('Medical Emergency', 'critical');

        // 1. Mobile / victim_info payload without ReportForm.
        $this->createEmergencyReport($medical, [
            'reference' => 'RPT-VICTIM-JSON',
            'reporter_type' => 'witness',
            'reporter_name' => 'Responder Driver',
            'reporter_contact' => '09990000001',
            'location' => 'Brgy. Wawa, Balagtas',
            'victim_info' => [
                'name' => 'Maria Clara',
                'age' => '29',
                'contact_number' => '09181112222',
                'symptoms' => 'Dizziness and laceration',
            ],
            'reported_at' => CarbonImmutable::create(2026, 11, 10, 9, 15, 0, 'Asia/Manila')->utc(),
        ]);

        // 2. Self-reported victim without ReportForm or victim_info falls back to reporter_name & reporter_contact.
        $this->createEmergencyReport($fire, [
            'reference' => 'RPT-SELF-VICTIM',
            'reporter_type' => 'victim',
            'reporter_name' => 'Pedro Penduko',
            'reporter_contact' => '09172223333',
            'location' => 'Brgy. Borol 1st, Balagtas',
            'victim_info' => null,
            'reported_at' => CarbonImmutable::create(2026, 11, 10, 11, 30, 0, 'Asia/Manila')->utc(),
        ]);

        // 3. Witness report without patient sheet/victim_info leaves patient Name & Age as '—' while keeping emergency details.
        $this->createEmergencyReport($fire, [
            'reference' => 'RPT-WITNESS-ONLY',
            'reporter_type' => 'witness',
            'reporter_name' => 'Witness Bystander',
            'reporter_contact' => '09173334444',
            'location' => 'Brgy. Santol, Balagtas',
            'victim_info' => null,
            'reported_at' => CarbonImmutable::create(2026, 11, 10, 14, 0, 0, 'Asia/Manila')->utc(),
        ]);

        $report = app(EmergencyOperationsReportService::class)->buildReport(
            ['period_type' => 'weekly', 'week_date' => '2026-11-12'],
            $admin,
            true,
            CarbonImmutable::now('Asia/Manila')
        );

        $this->assertSame(3, $report['report_info']['total_incidents']);
        $this->assertSame(1, $report['executive_summary']['total_patients_assisted']);

        $byRef = collect($report['incidents'])->keyBy('incident_ref');

        $this->assertSame('Maria Clara', $byRef['RPT-VICTIM-JSON']['patient_name']);
        $this->assertSame('29', $byRef['RPT-VICTIM-JSON']['patient_age']);
        $this->assertSame('Medical Emergency', $byRef['RPT-VICTIM-JSON']['type_of_emergency']);
        $this->assertSame('Brgy. Wawa, Balagtas', $byRef['RPT-VICTIM-JSON']['address']);
        $this->assertSame('09181112222', $byRef['RPT-VICTIM-JSON']['contact']);

        $this->assertSame('Pedro Penduko', $byRef['RPT-SELF-VICTIM']['patient_name']);
        $this->assertSame('—', $byRef['RPT-SELF-VICTIM']['patient_age']);
        $this->assertSame('09172223333', $byRef['RPT-SELF-VICTIM']['contact']);

        $this->assertSame('—', $byRef['RPT-WITNESS-ONLY']['patient_name']);
        $this->assertSame('—', $byRef['RPT-WITNESS-ONLY']['patient_age']);
        $this->assertSame('Fire', $byRef['RPT-WITNESS-ONLY']['type_of_emergency']);
        $this->assertSame('Brgy. Santol, Balagtas', $byRef['RPT-WITNESS-ONLY']['address']);
        $this->assertSame('09173334444', $byRef['RPT-WITNESS-ONLY']['contact']);
        $this->assertNotSame('Witness Bystander', $byRef['RPT-WITNESS-ONLY']['patient_name']);

        $html = view('reports.emergency-operations-pdf', ['report' => $report])->render();
        $this->assertStringContainsString('Total Emergencies', $html);
        $this->assertStringContainsString('<td class="num">3</td>', $html);
        $this->assertStringContainsString('Maria Clara', $html);
        $this->assertStringContainsString('Pedro Penduko', $html);
        $this->assertStringNotContainsString('Witness Bystander', $html);

        // Request omitting include_patient_details renders PDF cleanly with admin default unmasked view.
        $this->actingAs($admin)
            ->get(route('admin.reports.operations.pdf', [
                'period_type' => 'weekly',
                'week_date' => '2026-11-12',
                'mode' => 'preview',
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }
}
