<?php

namespace App\Services;

use App\Models\EmergencyReport;
use App\Models\MaintenanceReport;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EmergencyOperationsReportService
{
    public const string NOT_RECORDED = '—';

    public const string NOT_APPLICABLE = 'N/A';

    private static ?string $cachedLogoDataUri = null;

    private static bool $logoResolved = false;

    /**
     * Resolve the Philippine Standard Time reporting window and corresponding UTC query bounds.
     *
     * @param  array{period_type?: string, week_date?: string|null, month?: int|string|null, year?: int|string|null}  $input
     * @return array<string, mixed>
     */
    public function resolvePeriod(array $input, ?CarbonImmutable $now = null): array
    {
        $timezone = (string) config('reporting.timezone', 'Asia/Manila');
        $nowLocal = ($now ?? CarbonImmutable::now($timezone))->setTimezone($timezone);
        $periodType = ($input['period_type'] ?? 'weekly') === 'monthly' ? 'monthly' : 'weekly';

        if ($periodType === 'weekly') {
            $weekAnchorInput = $input['week_date'] ?? $nowLocal->toDateString();
            $anchor = CarbonImmutable::parse((string) $weekAnchorInput, $timezone);
            $startLocal = $anchor->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
            $endLocal = $anchor->endOfWeek(CarbonInterface::SUNDAY)->endOfDay();
            $totalCalendarDays = 7;
            $periodLabel = sprintf(
                '%s to %s',
                $startLocal->format('M d, Y (D)'),
                $endLocal->format('M d, Y (D)')
            );
            $filenameSlug = sprintf(
                'weekly-%s_to_%s',
                $startLocal->toDateString(),
                $endLocal->toDateString()
            );
        } else {
            $year = (int) ($input['year'] ?? $nowLocal->year);
            $month = (int) ($input['month'] ?? $nowLocal->month);
            $startLocal = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $timezone)->startOfDay();
            $endLocal = $startLocal->endOfMonth()->endOfDay();
            $totalCalendarDays = $startLocal->daysInMonth;
            $periodLabel = sprintf(
                '%s (%s – %s)',
                $startLocal->format('F Y'),
                $startLocal->format('M d, Y'),
                $endLocal->format('M d, Y')
            );
            $filenameSlug = sprintf('monthly-%04d-%02d', $year, $month);
        }

        $isFuturePeriod = $startLocal->greaterThan($nowLocal);
        $isPartialPeriod = ! $isFuturePeriod && $endLocal->greaterThan($nowLocal);

        if ($isFuturePeriod) {
            $elapsedDays = 0;
        } elseif ($isPartialPeriod) {
            $elapsedDays = max(1, (int) $startLocal->startOfDay()->diffInDays($nowLocal->startOfDay()) + 1);
        } else {
            $elapsedDays = $totalCalendarDays;
        }

        return [
            'period_type' => $periodType,
            'period_type_label' => $periodType === 'weekly' ? 'Weekly' : 'Monthly',
            'timezone' => $timezone,
            'timezone_abbr' => 'PHT (UTC+08:00)',
            'start_local' => $startLocal,
            'end_local' => $endLocal,
            'start_utc' => $startLocal->utc(),
            'end_utc' => $endLocal->utc(),
            'start_date' => $startLocal->toDateString(),
            'end_date' => $endLocal->toDateString(),
            'start_display' => $startLocal->format('M d, Y h:i A'),
            'end_display' => $endLocal->format('M d, Y h:i A'),
            'period_label' => $periodLabel,
            'is_partial_period' => $isPartialPeriod,
            'is_future_period' => $isFuturePeriod,
            'total_calendar_days' => $totalCalendarDays,
            'elapsed_days' => $elapsedDays,
            'generated_at_display' => $nowLocal->format('M d, Y h:i A').' PHT',
            'status_label' => $isPartialPeriod
                ? 'In Progress — Data as of '.$nowLocal->format('M d, Y h:i A').' PHT'
                : ($isFuturePeriod
                    ? 'Upcoming Period (Starts '.$startLocal->format('M d, Y').')'
                    : 'Final — Complete Reporting Period'),
            'filename_slug' => $filenameSlug,
        ];
    }

    /**
     * Build the full structured dataset covering Sections 1 through 6 from verified DB records only.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function buildReport(
        array $input,
        object $generatedBy,
        bool $includePatientDetails = true,
        ?CarbonImmutable $now = null
    ): array {
        $period = $this->resolvePeriod($input, $now);
        $timezone = $period['timezone'];
        $startUtc = $period['start_utc'];
        $endUtc = $period['end_utc'];

        $reports = EmergencyReport::query()
            ->with([
                'incidentType:id,name,description,priority,color',
                'team:id,team_name,active_vehicle_id,member_roles_enabled',
                'team.members.user',
                'driver',
                'trip',
                'form',
            ])
            ->whereBetween('reported_at', [$startUtc, $endUtc])
            ->orderBy('reported_at')
            ->orderBy('id')
            ->get();

        $reportIds = $reports->pluck('id')->all();

        $trackingSessionsByReport = collect();
        if ($reportIds !== []) {
            $trackingSessionsByReport = DB::table('vehicle_tracking_sessions')
                ->whereIn('emergency_report_id', $reportIds)
                ->orderBy('started_at')
                ->get()
                ->groupBy('emergency_report_id');
        }

        $fleetVehicles = Vehicle::query()
            ->with([
                'team:id,team_name',
                'openMaintenanceReports' => function ($query): void {
                    $query->orderByDesc('created_at');
                },
            ])
            ->orderBy('plate_number')
            ->orderBy('id')
            ->get();

        $vehiclesById = $fleetVehicles->keyBy('id');
        $vehiclesByPlate = $fleetVehicles->keyBy(
            fn (Vehicle $v): string => strtoupper(trim((string) $v->plate_number))
        );
        $vehiclesByAssignedReport = $fleetVehicles
            ->whereNotNull('assigned_report_id')
            ->keyBy('assigned_report_id');
        $vehiclesByTeamId = $fleetVehicles
            ->whereNotNull('response_team_id')
            ->groupBy('response_team_id');

        $periodMaintenanceReports = MaintenanceReport::query()
            ->whereBetween('created_at', [$startUtc, $endUtc])
            ->orderBy('created_at')
            ->get();

        $maintenanceByVehicleId = $periodMaintenanceReports->groupBy('vehicle_id');

        // Counters & accumulators (strictly incident-distinct).
        $totalIncidents = $reports->count();
        $completedCount = 0;
        $pendingCount = 0;
        $acknowledgedCount = 0;
        $dispatchedStatusCount = 0;
        $withdrawnCount = 0;
        $invalidatedCount = 0;
        $dispatchOperationsCount = 0;
        $hospitalTransportRecordedCount = 0;

        /** @var array<string, array<string, mixed>> $categoryStats */
        $categoryStats = [];
        /** @var array<string, array<string, mixed>> $personnelDeployments */
        $personnelDeployments = [];
        /** @var array<string, array<string, mixed>> $vehicleDeploymentStats */
        $vehicleDeploymentStats = [];

        $dispatchDelaysMinutes = [];
        $travelTimesMinutes = [];
        $operationDurationsMinutes = [];

        $distinctPeriodVehicleKeys = [];
        $incidentRows = [];
        $timelineRows = [];
        $patientRows = [];
        $utilizationRows = [];

        $totalLoggedDistanceKm = 0.0;
        $distanceRecordCount = 0;
        $totalFuelPurchasedLiters = 0.0;
        $fuelPurchasedRecordCount = 0;
        $totalFuelConsumedLiters = 0.0;
        $fuelConsumedRecordCount = 0;
        $incompatibleFuelNotesCount = 0;

        foreach ($reports as $report) {
            $incidentRef = $this->cleanText($report->reference) ?? sprintf('ER-%04d', $report->id);
            $categoryName = $report->incidentType?->name ?? 'Uncategorized';
            $rawPriority = $this->cleanText($report->priority) ?? $this->cleanText($report->incidentType?->priority);
            $priority = $rawPriority !== null
                ? ucfirst($rawPriority)
                : self::NOT_RECORDED;

            // Status classification.
            $status = (string) $report->status;
            if ($status === 'resolved') {
                $completedCount++;
            } elseif (in_array($status, ['pending', 'acknowledged', 'dispatched'], true)) {
                if ($status === 'pending') {
                    $pendingCount++;
                } elseif ($status === 'acknowledged') {
                    $acknowledgedCount++;
                } else {
                    $dispatchedStatusCount++;
                }
            } elseif ($status === 'invalid') {
                if ($report->withdrawn_at !== null) {
                    $withdrawnCount++;
                } else {
                    $invalidatedCount++;
                }
            }

            if (! isset($categoryStats[$categoryName])) {
                $categoryStats[$categoryName] = [
                    'category' => $categoryName,
                    'priority' => $priority,
                    'total' => 0,
                    'completed' => 0,
                    'pending_ongoing' => 0,
                    'cancelled_withdrawn' => 0,
                    'invalidated' => 0,
                ];
            }
            $categoryStats[$categoryName]['total']++;
            if ($status === 'resolved') {
                $categoryStats[$categoryName]['completed']++;
            } elseif (in_array($status, ['pending', 'acknowledged', 'dispatched'], true)) {
                $categoryStats[$categoryName]['pending_ongoing']++;
            } elseif ($status === 'invalid') {
                if ($report->withdrawn_at !== null) {
                    $categoryStats[$categoryName]['cancelled_withdrawn']++;
                } else {
                    $categoryStats[$categoryName]['invalidated']++;
                }
            }

            $form = $report->form;
            $driverSection = is_array($form?->driver_section) ? $form->driver_section : [];
            $emergencySection = is_array($form?->emergency_section) ? $form->emergency_section : [];
            $trip = $report->trip;

            // Dispatch initiation criterion: explicitly dispatched status, trip recorded, or resolved with team/driver/trip.
            $isDispatchedOperation = $status === 'dispatched'
                || $trip !== null
                || ($status === 'resolved' && ($report->response_team_id !== null || $report->assigned_driver_id !== null));
            if ($isDispatchedOperation) {
                $dispatchOperationsCount++;
            }

            // Personnel resolution (without double-counting per incident).
            $personnel = $this->resolveIncidentPersonnel($report, $driverSection);
            foreach ($personnel['unique_roster'] as $personKey => $personInfo) {
                if (! isset($personnelDeployments[$personKey])) {
                    $personnelDeployments[$personKey] = [
                        'name' => $personInfo['name'],
                        'roles' => [],
                        'incident_count' => 0,
                    ];
                }
                $personnelDeployments[$personKey]['incident_count']++;
                foreach ($personInfo['roles'] as $roleLabel) {
                    if (! in_array($roleLabel, $personnelDeployments[$personKey]['roles'], true)) {
                        $personnelDeployments[$personKey]['roles'][] = $roleLabel;
                    }
                }
            }

            // Vehicle resolution via tracking sessions, live assigned report, trip/form plate, or assigned team vehicle.
            $vehicleInfo = $this->resolveIncidentVehicle(
                $report,
                $trackingSessionsByReport->get($report->id, collect()),
                $vehiclesById,
                $vehiclesByPlate,
                $vehiclesByAssignedReport,
                $vehiclesByTeamId,
                $driverSection,
                $isDispatchedOperation
            );

            // Reported timestamp & compact location.
            $reportedAtDisplay = $this->formatTimestamp($report->reported_at, $timezone);
            $reportedCompactDisplay = $this->formatTimestamp($report->reported_at, $timezone, true);
            $locationDisplay = $this->formatLocation($report);
            $remarks = $this->buildIncidentRemarks($report, $emergencySection, $driverSection);

            $patientFields = $this->extractVictimAndEmergencyFields($report, $emergencySection);
            $sectionTwoName = $patientFields['incident_patient_name'] !== null
                ? ($includePatientDetails
                    ? $patientFields['incident_patient_name']
                    : $this->formatPatientIdentifier($patientFields['incident_patient_name'], $incidentRef, false))
                : self::NOT_RECORDED;
            $sectionTwoAddress = $includePatientDetails
                ? ($patientFields['clinical_address'] ?? $patientFields['incident_location'] ?? self::NOT_RECORDED)
                : ($patientFields['incident_location'] ?? self::NOT_RECORDED);
            $sectionTwoContact = $this->formatContactNumber($patientFields['contact'], $includePatientDetails);
            $typeOfEmergency = $this->cleanText($report->incidentType?->name)
                ?? $this->cleanText($emergencySection['typeOfIncident'] ?? null)
                ?? $categoryName;

            $incidentRows[] = [
                'id' => $report->id,
                'incident_ref' => $incidentRef,
                'patient_name' => $sectionTwoName,
                'patient_age' => $patientFields['age'] ?? self::NOT_RECORDED,
                'type_of_emergency' => $typeOfEmergency,
                'address' => $sectionTwoAddress,
                'contact' => $sectionTwoContact,
                'date_time' => $reportedAtDisplay,
                'category' => $categoryName,
                'priority' => $priority,
                'location' => $locationDisplay,
                'reported_at' => $reportedAtDisplay,
                'reported_at_compact' => $reportedCompactDisplay,
                'team_name' => $report->team?->team_name ?? self::NOT_RECORDED,
                'driver_name' => $personnel['driver_display'],
                'responders' => $personnel['responders_display'],
                'assigned_personnel' => $this->formatAssignedPersonnelSummary(
                    $report->team?->team_name,
                    $personnel['driver_display'],
                    $personnel['responders_display']
                ),
                'status' => $this->formatStatusLabel($status, $report->withdrawn_at !== null),
                'outcome' => $this->formatOutcomeLabel($report),
                'remarks' => $remarks,
            ];

            // Section 3: Timeline rows for dispatched / assigned / resolved operational reports.
            $includeInTimeline = $isDispatchedOperation || $report->assigned_driver_id !== null || $report->response_team_id !== null;
            if ($includeInTimeline) {
                $dispatchStartAt = $trip?->started_at;
                $onSceneAt = $trip?->on_scene_at;
                $completedAt = $report->resolved_at;
                $returningAt = $trip?->returning_at;

                $delayResult = $this->diffMinutesBetween($report->reported_at, $dispatchStartAt);
                $travelResult = $this->diffMinutesBetween($dispatchStartAt, $onSceneAt);
                $durationResult = $this->diffMinutesBetween($dispatchStartAt, $completedAt);

                if ($delayResult['valid']) {
                    $dispatchDelaysMinutes[] = $delayResult['minutes'];
                }
                if ($travelResult['valid']) {
                    $travelTimesMinutes[] = $travelResult['minutes'];
                }
                if ($durationResult['valid']) {
                    $operationDurationsMinutes[] = $durationResult['minutes'];
                }

                $timelineRows[] = [
                    'incident_ref' => $incidentRef,
                    'vehicle_label' => $vehicleInfo['vehicle_label'],
                    'plate_number' => $vehicleInfo['plate_number'],
                    'driver_and_responders' => $personnel['combined_display'],
                    'received_at' => $reportedCompactDisplay,
                    'dispatched_at' => $this->formatTimestamp($dispatchStartAt, $timezone, true),
                    'arrived_at' => $this->formatTimestamp($onSceneAt, $timezone, true),
                    'completed_at' => $this->formatTimestamp($completedAt, $timezone, true),
                    'returning_at' => $this->formatTimestamp($returningAt, $timezone, true),
                    'returned_to_base_at' => self::NOT_RECORDED,
                    'dispatch_delay' => $delayResult['display'],
                    'response_travel_time' => $travelResult['display'],
                    'operation_duration' => $durationResult['display'],
                    'final_status' => $this->formatOutcomeLabel($report),
                ];
            }

            // Section 5: Patient and Treatment Records.
            $extractedPatients = $this->extractPatientRecords($report, $incidentRef, $emergencySection, $driverSection, $timezone, $includePatientDetails);
            foreach ($extractedPatients as $patientRow) {
                $patientRows[] = $patientRow;
            }
            if ($trip?->picked_up_at !== null || $trip?->arrived_at !== null || $this->cleanText($trip?->destination) !== null) {
                $hospitalTransportRecordedCount++;
            }

            // Section 6: Vehicle utilization & logistics.
            $hasFuelData = $this->hasAnyDriverLogisticsData($driverSection);
            if ($vehicleInfo['has_recorded_vehicle'] || $trip !== null || $hasFuelData) {
                if ($vehicleInfo['tracking_key'] !== null) {
                    $distinctPeriodVehicleKeys[$vehicleInfo['tracking_key']] = true;
                }

                $fuelBegin = $this->parseFuelLiters($driverSection['balanceInTank'] ?? null);
                $fuelEnd = $this->parseFuelLiters($driverSection['remainingFuel'] ?? null);
                $fuelPurchased = $this->parseFuelLiters($driverSection['purchasedDuringTrip'] ?? null);
                $fuelUsed = $this->parseFuelLiters($driverSection['fuelUsedDuringTrip'] ?? null);

                if ($fuelPurchased['valid']) {
                    $totalFuelPurchasedLiters += $fuelPurchased['value'];
                    $fuelPurchasedRecordCount++;
                } elseif ($fuelPurchased['incompatible']) {
                    $incompatibleFuelNotesCount++;
                }

                if ($fuelUsed['valid']) {
                    $totalFuelConsumedLiters += $fuelUsed['value'];
                    $fuelConsumedRecordCount++;
                } elseif ($fuelUsed['incompatible']) {
                    $incompatibleFuelNotesCount++;
                }

                $tripDistanceKm = $trip?->distance_km !== null ? (float) $trip->distance_km : null;
                if ($tripDistanceKm !== null && $tripDistanceKm >= 0) {
                    $totalLoggedDistanceKm += $tripDistanceKm;
                    $distanceRecordCount++;
                }

                $vehicleMaintenanceNotes = [];
                if ($vehicleInfo['vehicle_id'] !== null && $maintenanceByVehicleId->has($vehicleInfo['vehicle_id'])) {
                    foreach ($maintenanceByVehicleId->get($vehicleInfo['vehicle_id']) as $mr) {
                        $vehicleMaintenanceNotes[] = sprintf(
                            '%s (%s, %s)',
                            $mr->issue_type,
                            $mr->severity,
                            $mr->status
                        );
                    }
                }
                $gearOil = $this->cleanText($driverSection['gearOilUsed'] ?? null);
                $tripPurpose = $this->cleanText($driverSection['purpose'] ?? null);
                $logisticsRemarksParts = array_filter([
                    $tripPurpose !== null ? 'Purpose: '.$tripPurpose : null,
                    $gearOil !== null ? 'Gear Oil: '.$gearOil : null,
                    $vehicleMaintenanceNotes !== [] ? 'Period Maintenance: '.implode('; ', $vehicleMaintenanceNotes) : null,
                    $fuelUsed['incompatible'] ? 'Fuel Used unit incompatible ('.$fuelUsed['display'].')' : null,
                    $fuelPurchased['incompatible'] ? 'Fuel Purchased unit incompatible ('.$fuelPurchased['display'].')' : null,
                ]);

                $utilizationRows[] = [
                    'vehicle_id' => $vehicleInfo['vehicle_id'] !== null ? (string) $vehicleInfo['vehicle_id'] : self::NOT_RECORDED,
                    'vehicle_label' => $vehicleInfo['vehicle_label'],
                    'vehicle_type' => $vehicleInfo['vehicle_type'],
                    'plate_number' => $vehicleInfo['plate_number'],
                    'assigned_driver' => $personnel['driver_display'],
                    'incident_ref' => $incidentRef,
                    'trip_date' => $this->cleanText($driverSection['tripDate'] ?? null) ?? $reportedAtDisplay,
                    'fuel_beginning' => $fuelBegin['display'],
                    'fuel_end' => $fuelEnd['display'],
                    'fuel_purchased' => $fuelPurchased['display'],
                    'fuel_consumed' => $fuelUsed['display'],
                    'starting_odometer' => self::NOT_RECORDED,
                    'ending_odometer' => self::NOT_RECORDED,
                    'odometer_distance' => self::NOT_RECORDED,
                    'logged_distance' => $tripDistanceKm !== null ? number_format($tripDistanceKm, 2).' km' : self::NOT_RECORDED,
                    'remarks' => $logisticsRemarksParts !== [] ? implode(' | ', $logisticsRemarksParts) : self::NOT_RECORDED,
                ];

                if ($vehicleInfo['tracking_key'] !== null) {
                    $vKey = $vehicleInfo['tracking_key'];
                    if (! isset($vehicleDeploymentStats[$vKey])) {
                        $vehicleDeploymentStats[$vKey] = [
                            'vehicle_id' => $vehicleInfo['vehicle_id'] !== null ? (string) $vehicleInfo['vehicle_id'] : self::NOT_RECORDED,
                            'vehicle_label' => $vehicleInfo['vehicle_label'],
                            'vehicle_type' => $vehicleInfo['vehicle_type'],
                            'plate_number' => $vehicleInfo['plate_number'],
                            'drivers' => [],
                            'deployments' => 0,
                            'distance_km' => 0.0,
                            'distance_count' => 0,
                            'fuel_purchased_l' => 0.0,
                            'fuel_purchased_count' => 0,
                            'fuel_consumed_l' => 0.0,
                            'fuel_consumed_count' => 0,
                            'incident_refs' => [],
                        ];
                    }
                    $vehicleDeploymentStats[$vKey]['deployments']++;
                    $vehicleDeploymentStats[$vKey]['incident_refs'][] = $incidentRef;
                    if (
                        $personnel['driver_display'] !== self::NOT_RECORDED
                        && ! in_array($personnel['driver_display'], $vehicleDeploymentStats[$vKey]['drivers'], true)
                    ) {
                        $vehicleDeploymentStats[$vKey]['drivers'][] = $personnel['driver_display'];
                    }
                    if ($tripDistanceKm !== null && $tripDistanceKm >= 0) {
                        $vehicleDeploymentStats[$vKey]['distance_km'] += $tripDistanceKm;
                        $vehicleDeploymentStats[$vKey]['distance_count']++;
                    }
                    if ($fuelPurchased['valid']) {
                        $vehicleDeploymentStats[$vKey]['fuel_purchased_l'] += $fuelPurchased['value'];
                        $vehicleDeploymentStats[$vKey]['fuel_purchased_count']++;
                    }
                    if ($fuelUsed['valid']) {
                        $vehicleDeploymentStats[$vKey]['fuel_consumed_l'] += $fuelUsed['value'];
                        $vehicleDeploymentStats[$vKey]['fuel_consumed_count']++;
                    }
                }
            }
        }

        // Sort aggregates deterministically.
        uasort($categoryStats, fn (array $a, array $b): int => $b['total'] <=> $a['total'] ?: strcmp($a['category'], $b['category']));
        uasort($personnelDeployments, fn (array $a, array $b): int => $b['incident_count'] <=> $a['incident_count'] ?: strcmp($a['name'], $b['name']));
        uasort($vehicleDeploymentStats, fn (array $a, array $b): int => $b['deployments'] <=> $a['deployments'] ?: strcmp($a['plate_number'], $b['plate_number']));

        $pendingOngoingTotal = $pendingCount + $acknowledgedCount + $dispatchedStatusCount;
        $cancelledOrUnsuccessfulTotal = $withdrawnCount + $invalidatedCount;

        $avgDelay = $this->summarizeAverages($dispatchDelaysMinutes);
        $avgTravel = $this->summarizeAverages($travelTimesMinutes);
        $avgOperation = $this->summarizeAverages($operationDurationsMinutes);

        // Current vehicle inventory & maintenance status (distinct from historical reporting period).
        $currentFleetRows = [];
        $vehiclesUnavailableCount = 0;
        $vehiclesMaintenanceRequiredCount = 0;
        foreach ($fleetVehicles as $vehicle) {
            $openIssues = $vehicle->openMaintenanceReports;
            $requiresMaintenance = $vehicle->status === 'maintenance'
                || (bool) $vehicle->maintenance_warning
                || $openIssues->isNotEmpty();

            if ($vehicle->status !== 'available') {
                $vehiclesUnavailableCount++;
            }
            if ($requiresMaintenance) {
                $vehiclesMaintenanceRequiredCount++;
            }

            $latestIssue = $openIssues->first();
            $currentFleetRows[] = [
                'id' => $vehicle->id,
                'name' => $vehicle->name ?? ucwords(str_replace('-', ' ', (string) $vehicle->vehicle_type)),
                'type' => ucwords(str_replace('-', ' ', (string) $vehicle->vehicle_type)),
                'plate_number' => $vehicle->plate_number,
                'team_name' => $vehicle->team?->team_name ?? 'Unassigned',
                'current_status' => ucwords(str_replace('-', ' ', (string) $vehicle->status)),
                'is_available_now' => $vehicle->status === 'available',
                'maintenance_warning' => (bool) $vehicle->maintenance_warning,
                'requires_maintenance' => $requiresMaintenance,
                'open_maintenance_count' => $openIssues->count(),
                'latest_issue' => $latestIssue !== null
                    ? sprintf('%s (%s / %s)', $latestIssue->issue_type, ucfirst((string) $latestIssue->severity), ucwords(str_replace('_', ' ', (string) $latestIssue->status)))
                    : 'None',
            ];
        }

        $periodMaintenanceRows = $periodMaintenanceReports->map(function (MaintenanceReport $mr) use ($vehiclesById, $timezone): array {
            $veh = $vehiclesById->get($mr->vehicle_id);

            return [
                'id' => $mr->id,
                'plate_number' => $veh !== null ? ($this->cleanText($veh->plate_number) ?? self::NOT_RECORDED) : self::NOT_RECORDED,
                'vehicle_label' => $veh !== null
                    ? trim(($veh->name ? $veh->name.' — ' : '').$veh->plate_number)
                    : 'Vehicle #'.$mr->vehicle_id,
                'issue_type' => $mr->issue_type,
                'severity' => ucfirst((string) $mr->severity),
                'mileage' => $mr->mileage !== null ? number_format((int) $mr->mileage).' km' : self::NOT_RECORDED,
                'status' => ucwords(str_replace('_', ' ', (string) $mr->status)),
                'recommend_out_of_service' => (bool) $mr->recommend_out_of_service,
                'reported_at' => $this->formatTimestamp($mr->created_at, $timezone),
                'description' => $this->cleanText($mr->description) ?? self::NOT_RECORDED,
            ];
        })->values()->all();

        $topVehicleDeployments = $vehicleDeploymentStats === []
            ? []
            : array_values(array_filter(
                $vehicleDeploymentStats,
                fn (array $v): bool => $v['deployments'] === reset($vehicleDeploymentStats)['deployments']
            ));

        $completionRateDisplay = $totalIncidents > 0
            ? number_format(($completedCount / $totalIncidents) * 100, 1).'%'
            : self::NOT_APPLICABLE;

        $incidentsPerDayDisplay = $period['elapsed_days'] > 0
            ? number_format($totalIncidents / $period['elapsed_days'], 2)
            : self::NOT_APPLICABLE;

        $reportInfo = [
            'organization_name' => (string) config('reporting.organization_name', 'Municipal Disaster Risk Reduction and Management Office (MDRRMO)'),
            'unit_name' => (string) config('reporting.unit_name', 'Emergency Operations & Dispatch Division'),
            'logo_data_uri' => $this->resolveOrganizationLogoDataUri(),
            'report_title' => 'EMERGENCY OPERATIONS REPORT',
            'report_type' => $period['period_type_label'],
            'period_type' => $period['period_type'],
            'reporting_period' => $period['period_label'],
            'start_date' => $period['start_date'],
            'end_date' => $period['end_date'],
            'start_display' => $period['start_display'],
            'end_display' => $period['end_display'],
            'is_partial_period' => $period['is_partial_period'],
            'is_future_period' => $period['is_future_period'],
            'report_status_label' => $period['status_label'],
            'generated_at' => $period['generated_at_display'],
            'generated_by_name' => $this->formatPersonName($generatedBy),
            'generated_by_email' => (string) ($generatedBy->email ?? 'N/A'),
            'total_incidents' => $totalIncidents,
            'completed_operations' => $completedCount,
            'pending_ongoing_operations' => $pendingOngoingTotal,
            'cancelled_or_unsuccessful_operations' => $cancelledOrUnsuccessfulTotal,
            'withdrawn_operations' => $withdrawnCount,
            'invalidated_operations' => $invalidatedCount,
            'include_patient_details' => $includePatientDetails,
            'privacy_mode_label' => $includePatientDetails
                ? 'Authorized Clinical View'
                : 'Privacy Masked View',
        ];

        $executiveSummary = [
            'total_incidents_received' => $totalIncidents,
            'total_dispatches_initiated' => $dispatchOperationsCount,
            'total_completed_responses' => $completedCount,
            'total_pending_ongoing' => $pendingOngoingTotal,
            'pending_breakdown' => [
                'pending' => $pendingCount,
                'acknowledged' => $acknowledgedCount,
                'dispatched' => $dispatchedStatusCount,
            ],
            'total_cancelled_failed' => $cancelledOrUnsuccessfulTotal,
            'withdrawn_count' => $withdrawnCount,
            'invalidated_count' => $invalidatedCount,
            'category_breakdown' => array_values($categoryStats),
            'average_dispatch_delay' => $avgDelay,
            'average_response_time' => $avgTravel,
            'average_operation_duration' => $avgOperation,
            'total_vehicles_deployed' => count($distinctPeriodVehicleKeys),
            'total_personnel_involved' => count($personnelDeployments),
            'total_patients_assisted' => count($patientRows),
        ];

        $vehicleLogisticsSummary = [
            'total_deployments' => count($utilizationRows),
            'distinct_vehicles_deployed' => count($distinctPeriodVehicleKeys),
            'most_frequently_deployed' => $topVehicleDeployments,
            'total_logged_distance_km' => $distanceRecordCount > 0 ? number_format($totalLoggedDistanceKm, 2).' km' : self::NOT_RECORDED,
            'distance_record_count' => $distanceRecordCount,
            'total_odometer_distance' => self::NOT_RECORDED,
            'total_fuel_purchased_l' => $fuelPurchasedRecordCount > 0 ? number_format($totalFuelPurchasedLiters, 2).' L' : self::NOT_RECORDED,
            'fuel_purchased_record_count' => $fuelPurchasedRecordCount,
            'total_fuel_consumed_l' => $fuelConsumedRecordCount > 0 ? number_format($totalFuelConsumedLiters, 2).' L' : self::NOT_RECORDED,
            'fuel_consumed_record_count' => $fuelConsumedRecordCount,
            'vehicles_currently_unavailable' => $vehiclesUnavailableCount,
            'vehicles_requiring_maintenance' => $vehiclesMaintenanceRequiredCount,
            'total_fleet_size' => $fleetVehicles->count(),
            'incompatible_fuel_notes_count' => $incompatibleFuelNotesCount,
        ];

        $performance = [
            'incidents_per_day' => $incidentsPerDayDisplay,
            'incidents_per_day_numerator' => $totalIncidents,
            'incidents_per_day_denominator' => $period['elapsed_days'],
            'incidents_per_day_note' => $period['is_partial_period']
                ? sprintf('%d incident(s) / %d elapsed day(s) in current partial period', $totalIncidents, $period['elapsed_days'])
                : sprintf('%d incident(s) / %d calendar day(s)', $totalIncidents, $period['elapsed_days']),
            'incidents_by_category' => array_values($categoryStats),
            'average_dispatch_delay' => $avgDelay,
            'average_response_travel_time' => $avgTravel,
            'average_operation_duration' => $avgOperation,
            'completion_rate' => $completionRateDisplay,
            'completion_rate_numerator' => $completedCount,
            'completion_rate_denominator' => $totalIncidents,
            'vehicle_deployments' => array_values($vehicleDeploymentStats),
            'personnel_deployments' => array_values($personnelDeployments),
            'incidents_with_transport_recorded' => $hospitalTransportRecordedCount,
            'unsuccessful_or_cancelled_responses' => $cancelledOrUnsuccessfulTotal,
            'withdrawn_responses' => $withdrawnCount,
            'invalidated_responses' => $invalidatedCount,
        ];

        return [
            'period' => $period,
            'report_info' => $reportInfo,
            'executive_summary' => $executiveSummary,
            'incidents' => $incidentRows,
            'category_summary' => array_values($categoryStats),
            'timelines' => $timelineRows,
            'patients' => $patientRows,
            'vehicle_utilization' => $utilizationRows,
            'vehicle_deployments' => array_values($vehicleDeploymentStats),
            'vehicle_logistics_summary' => $vehicleLogisticsSummary,
            'period_maintenance_reports' => $periodMaintenanceRows,
            'current_fleet_status' => $currentFleetRows,
            'performance' => $performance,
        ];
    }

    /**
     * Build compact summary payload for pre-generation UI feedback and validation.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function buildSummary(
        array $input,
        object $generatedBy,
        bool $includePatientDetails = true,
        ?CarbonImmutable $now = null
    ): array {
        $full = $this->buildReport($input, $generatedBy, $includePatientDetails, $now);

        return [
            'period' => [
                'period_type' => $full['period']['period_type'],
                'period_type_label' => $full['period']['period_type_label'],
                'timezone' => $full['period']['timezone'],
                'timezone_abbr' => $full['period']['timezone_abbr'],
                'start_date' => $full['period']['start_date'],
                'end_date' => $full['period']['end_date'],
                'start_display' => $full['period']['start_display'],
                'end_display' => $full['period']['end_display'],
                'period_label' => $full['period']['period_label'],
                'is_partial_period' => $full['period']['is_partial_period'],
                'is_future_period' => $full['period']['is_future_period'],
                'total_calendar_days' => $full['period']['total_calendar_days'],
                'elapsed_days' => $full['period']['elapsed_days'],
                'filename_slug' => $full['period']['filename_slug'],
            ],
            'stats' => [
                'total_incidents' => $full['report_info']['total_incidents'],
                'completed_operations' => $full['report_info']['completed_operations'],
                'pending_ongoing_operations' => $full['report_info']['pending_ongoing_operations'],
                'cancelled_or_unsuccessful_operations' => $full['report_info']['cancelled_or_unsuccessful_operations'],
                'total_dispatches_initiated' => $full['executive_summary']['total_dispatches_initiated'],
                'total_vehicles_deployed' => $full['executive_summary']['total_vehicles_deployed'],
                'total_personnel_involved' => $full['executive_summary']['total_personnel_involved'],
                'total_patients_assisted' => $full['executive_summary']['total_patients_assisted'],
                'completion_rate' => $full['performance']['completion_rate'],
                'is_empty' => $full['report_info']['total_incidents'] === 0,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $driverSection
     * @return array{
     *   driver_display: string,
     *   responders_display: string,
     *   combined_display: string,
     *   unique_roster: array<string, array{name: string, roles: array<int, string>}>
     * }
     */
    private function resolveIncidentPersonnel(EmergencyReport $report, array $driverSection): array
    {
        $uniqueRoster = [];

        $driverName = null;
        $driverUserId = null;
        if ($report->driver !== null) {
            $driverName = $this->formatPersonName($report->driver);
            $driverUserId = $report->driver->id;
        } elseif ($this->cleanText($report->trip?->driver_name) !== null) {
            $driverName = $this->cleanText($report->trip->driver_name);
        } elseif ($this->cleanText($driverSection['driverName'] ?? null) !== null) {
            $driverName = $this->cleanText($driverSection['driverName']);
        }

        if ($driverName !== null && $driverName !== self::NOT_RECORDED) {
            $key = $driverUserId !== null ? 'user:'.$driverUserId : 'name:'.mb_strtolower($driverName);
            $uniqueRoster[$key] = [
                'name' => $driverName,
                'roles' => ['Driver'],
            ];
        }

        $responderNames = [];
        if ($report->team !== null && $report->team->relationLoaded('members')) {
            foreach ($report->team->members as $member) {
                $memberUser = $member->user;
                $memberName = $memberUser !== null
                    ? $this->formatPersonName($memberUser)
                    : $this->cleanText($member->name);
                if ($memberName === null || $memberName === self::NOT_RECORDED) {
                    continue;
                }

                $roleRaw = strtolower((string) ($member->role ?? 'responder'));
                $roleLabel = $roleRaw === 'driver' ? 'Driver' : 'Responder';
                $key = $member->user_id !== null ? 'user:'.$member->user_id : 'name:'.mb_strtolower($memberName);

                if (! isset($uniqueRoster[$key])) {
                    $uniqueRoster[$key] = [
                        'name' => $memberName,
                        'roles' => [$roleLabel],
                    ];
                } elseif (! in_array($roleLabel, $uniqueRoster[$key]['roles'], true)) {
                    $uniqueRoster[$key]['roles'][] = $roleLabel;
                }

                if ($driverUserId !== null && (int) $member->user_id === (int) $driverUserId) {
                    continue;
                }
                if ($roleRaw === 'driver' && $driverName === null) {
                    $driverName = $memberName;

                    continue;
                }
                if (! in_array($memberName, $responderNames, true)) {
                    $responderNames[] = $memberName;
                }
            }
        }

        $authorizedPassenger = $this->cleanText($driverSection['authorizedPassenger'] ?? null);
        if ($authorizedPassenger !== null) {
            foreach (preg_split('/\s*[,;]\s*/', $authorizedPassenger) ?: [] as $extraName) {
                $extraName = trim((string) $extraName);
                if ($extraName === '') {
                    continue;
                }
                $key = 'name:'.mb_strtolower($extraName);
                if (! isset($uniqueRoster[$key])) {
                    $uniqueRoster[$key] = [
                        'name' => $extraName,
                        'roles' => ['Crew / Authorized Passenger'],
                    ];
                }
                if (! in_array($extraName, $responderNames, true) && $extraName !== $driverName) {
                    $responderNames[] = $extraName;
                }
            }
        }

        $driverDisplay = $driverName ?? self::NOT_RECORDED;
        $respondersDisplay = $responderNames !== [] ? implode(', ', $responderNames) : self::NOT_RECORDED;
        $combinedParts = [];
        if ($driverDisplay !== self::NOT_RECORDED) {
            $combinedParts[] = 'Driver: '.$driverDisplay;
        }
        if ($respondersDisplay !== self::NOT_RECORDED) {
            $combinedParts[] = 'Responders: '.$respondersDisplay;
        }

        return [
            'driver_display' => $driverDisplay,
            'responders_display' => $respondersDisplay,
            'combined_display' => $combinedParts !== [] ? implode(' | ', $combinedParts) : self::NOT_RECORDED,
            'unique_roster' => $uniqueRoster,
        ];
    }

    /**
     * Resolve vehicle from recorded tracking sessions, active assignment, trip/form plate,
     * or the dispatched team's active/assigned vehicle when no separate plate entry was logged.
     *
     * @param  Collection<int, object>  $trackingSessions
     * @param  Collection<int, Vehicle>  $vehiclesById
     * @param  Collection<string, Vehicle>  $vehiclesByPlate
     * @param  Collection<int, Vehicle>  $vehiclesByAssignedReport
     * @param  Collection<int, Collection<int, Vehicle>>  $vehiclesByTeamId
     * @param  array<string, mixed>  $driverSection
     * @return array{
     *   has_recorded_vehicle: bool,
     *   vehicle_id: int|null,
     *   vehicle_label: string,
     *   vehicle_type: string,
     *   plate_number: string,
     *   tracking_key: string|null
     * }
     */
    private function resolveIncidentVehicle(
        EmergencyReport $report,
        Collection $trackingSessions,
        Collection $vehiclesById,
        Collection $vehiclesByPlate,
        Collection $vehiclesByAssignedReport,
        Collection $vehiclesByTeamId,
        array $driverSection,
        bool $isDispatchedOperation
    ): array {
        $resolvedVehicles = collect();

        foreach ($trackingSessions as $session) {
            $veh = $vehiclesById->get((int) $session->vehicle_id);
            if ($veh !== null && ! $resolvedVehicles->contains('id', $veh->id)) {
                $resolvedVehicles->push($veh);
            }
        }

        if ($resolvedVehicles->isEmpty() && $vehiclesByAssignedReport->has($report->id)) {
            $resolvedVehicles->push($vehiclesByAssignedReport->get($report->id));
        }

        $recordedPlate = $this->cleanText($report->trip?->vehicle_number)
            ?? $this->cleanText($driverSection['governmentCardPlateNo'] ?? null);

        if ($resolvedVehicles->isEmpty() && $recordedPlate !== null) {
            $matched = $vehiclesByPlate->get(strtoupper($recordedPlate));
            if ($matched !== null) {
                $resolvedVehicles->push($matched);
            }
        }

        if ($resolvedVehicles->isEmpty() && $recordedPlate === null && $isDispatchedOperation && $report->team !== null) {
            $teamActiveVehicleId = $report->team->active_vehicle_id;
            if ($teamActiveVehicleId !== null && $vehiclesById->has((int) $teamActiveVehicleId)) {
                $resolvedVehicles->push($vehiclesById->get((int) $teamActiveVehicleId));
            } else {
                $teamVehicles = $vehiclesByTeamId->get($report->team->id, collect());
                $fallbackVehicle = $teamVehicles->firstWhere('status', 'available') ?? $teamVehicles->first();
                if ($fallbackVehicle !== null) {
                    $resolvedVehicles->push($fallbackVehicle);
                }
            }
        }

        if ($resolvedVehicles->isNotEmpty()) {
            /** @var Vehicle $primary */
            $primary = $resolvedVehicles->first();
            $labels = $resolvedVehicles->map(
                fn (Vehicle $v): string => trim(($v->name ?? ucwords(str_replace('-', ' ', (string) $v->vehicle_type))).' (#'.$v->id.')')
            )->all();
            $types = $resolvedVehicles->map(
                fn (Vehicle $v): string => ucwords(str_replace('-', ' ', (string) $v->vehicle_type))
            )->unique()->all();
            $plates = $resolvedVehicles->pluck('plate_number')->filter()->unique()->all();

            return [
                'has_recorded_vehicle' => true,
                'vehicle_id' => $primary->id,
                'vehicle_label' => implode(', ', $labels),
                'vehicle_type' => implode(', ', $types),
                'plate_number' => $plates !== [] ? implode(', ', $plates) : self::NOT_RECORDED,
                'tracking_key' => 'vehicle:'.$primary->id,
            ];
        }

        if ($recordedPlate !== null) {
            return [
                'has_recorded_vehicle' => true,
                'vehicle_id' => null,
                'vehicle_label' => 'Recorded Plate Only ('.$recordedPlate.')',
                'vehicle_type' => self::NOT_RECORDED,
                'plate_number' => $recordedPlate,
                'tracking_key' => 'plate:'.strtoupper($recordedPlate),
            ];
        }

        return [
            'has_recorded_vehicle' => false,
            'vehicle_id' => null,
            'vehicle_label' => self::NOT_RECORDED,
            'vehicle_type' => self::NOT_RECORDED,
            'plate_number' => self::NOT_RECORDED,
            'tracking_key' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $emergencySection
     * @param  array<string, mixed>  $driverSection
     * @return array<int, array<string, string>>
     */
    private function extractPatientRecords(
        EmergencyReport $report,
        string $incidentRef,
        array $emergencySection,
        array $driverSection,
        string $timezone,
        bool $includePatientDetails
    ): array {
        $vitals = is_array($emergencySection['vitalSigns'] ?? null) ? $emergencySection['vitalSigns'] : [];
        $bp = $this->cleanText($vitals['bloodPressure'] ?? null);
        $pulse = $this->cleanText($vitals['pulseRate'] ?? null);
        $resp = $this->cleanText($vitals['respiration'] ?? null);

        $vitalParts = [];
        if ($bp !== null) {
            $vitalParts[] = 'BP: '.$bp;
        }
        if ($pulse !== null) {
            $vitalParts[] = 'PR: '.$pulse;
        }
        if ($resp !== null) {
            $vitalParts[] = 'RR: '.$resp;
        }
        $vitalsDisplay = $vitalParts !== [] ? implode(', ', $vitalParts) : self::NOT_RECORDED;

        $fields = $this->extractVictimAndEmergencyFields($report, $emergencySection);
        $patientNameRaw = $fields['clinical_or_victim_name'];
        $ageRaw = $fields['age'];
        $sexRaw = $fields['sex'];
        $addressRaw = $fields['clinical_address'];
        $conditionRaw = $this->cleanText($emergencySection['typeOfIncident'] ?? null)
            ?? $this->cleanText($emergencySection['assessmentComment'] ?? null);
        $treatmentRaw = $this->cleanText($emergencySection['treatmentManagement'] ?? null);
        $allergiesRaw = $this->cleanText($emergencySection['allergies'] ?? null);
        $medsRaw = $this->cleanText($emergencySection['medications'] ?? null);
        $assessmentRaw = $this->cleanText($emergencySection['assessmentComment'] ?? null);

        $hasFormPatientData = $patientNameRaw !== null
            || $ageRaw !== null
            || $sexRaw !== null
            || $conditionRaw !== null
            || $treatmentRaw !== null
            || $vitalParts !== []
            || $allergiesRaw !== null
            || $medsRaw !== null;

        $victimNote = $fields['victim_symptoms'];

        if (! $hasFormPatientData && ! $fields['has_victim_payload']) {
            return [];
        }

        $trip = $report->trip;
        $destination = $this->cleanText($trip?->destination)
            ?? $this->cleanText($driverSection['placeVisitedInspected'] ?? null)
            ?? self::NOT_RECORDED;

        $transportStatus = self::NOT_RECORDED;
        if ($trip?->arrived_at !== null) {
            $transportStatus = 'Arrived at Destination ('.$this->formatTimestamp($trip->arrived_at, $timezone).')';
        } elseif ($trip?->picked_up_at !== null) {
            $transportStatus = 'Picked Up ('.$this->formatTimestamp($trip->picked_up_at, $timezone).') — Arrival Not Recorded';
        }

        $displayName = $this->formatPatientIdentifier($patientNameRaw, $incidentRef, $includePatientDetails);

        $remarkParts = [];
        if ($allergiesRaw !== null) {
            $remarkParts[] = 'Allergies: '.$allergiesRaw;
        }
        if ($medsRaw !== null) {
            $remarkParts[] = 'Medications: '.$medsRaw;
        }
        if ($includePatientDetails && $addressRaw !== null) {
            $remarkParts[] = 'Address: '.$addressRaw;
        }
        if ($victimNote !== null && $hasFormPatientData && $includePatientDetails) {
            $remarkParts[] = 'Initial Victim Note: '.$victimNote;
        }

        if (! $hasFormPatientData && $victimNote !== null) {
            return [[
                'incident_ref' => $incidentRef,
                'patient_name' => $includePatientDetails ? 'Reported Victim (No Clinical Sheet)' : 'Patient ['.$incidentRef.'] (Masked)',
                'age' => self::NOT_RECORDED,
                'gender' => self::NOT_RECORDED,
                'initial_condition' => $includePatientDetails ? $victimNote : 'Confidential (Privacy Mode)',
                'vital_signs' => self::NOT_RECORDED,
                'first_aid_and_treatment' => self::NOT_RECORDED,
                'transport_status' => $transportStatus,
                'hospital_destination' => $destination,
                'hospital_endorsement' => self::NOT_RECORDED,
                'remarks' => 'Initial report note only.',
            ]];
        }

        return [[
            'incident_ref' => $incidentRef,
            'patient_name' => $displayName,
            'age' => $ageRaw ?? self::NOT_RECORDED,
            'gender' => $sexRaw ?? self::NOT_RECORDED,
            'initial_condition' => $assessmentRaw ?? $conditionRaw ?? $victimNote ?? self::NOT_RECORDED,
            'vital_signs' => $vitalsDisplay,
            'first_aid_and_treatment' => $treatmentRaw ?? self::NOT_RECORDED,
            'transport_status' => $transportStatus,
            'hospital_destination' => $destination,
            'hospital_endorsement' => self::NOT_RECORDED,
            'remarks' => $remarkParts !== [] ? implode(' | ', $remarkParts) : self::NOT_RECORDED,
        ]];
    }

    /**
     * Resolve raw patient/victim, address, and contact fields from clinical, victim, or reporter records.
     *
     * @param  array<string, mixed>  $emergencySection
     * @return array<string, mixed>
     */
    private function extractVictimAndEmergencyFields(EmergencyReport $report, array $emergencySection): array
    {
        $victimInfo = is_array($report->victim_info) ? $report->victim_info : [];
        $victimNoteString = is_string($report->victim_info) ? $this->cleanText($report->victim_info) : null;

        $clinicalName = $this->cleanText($emergencySection['patientName'] ?? null);
        $victimName = $this->cleanText($victimInfo['name'] ?? null);
        $reporterVictimName = $report->reporter_type === 'victim' ? $this->cleanText($report->reporter_name) : null;

        $clinicalOrVictimName = $clinicalName ?? $victimName;
        $incidentPatientName = $clinicalOrVictimName ?? $reporterVictimName;

        $victimAge = $this->cleanText($victimInfo['age'] ?? null);
        $victimSex = $this->cleanText($victimInfo['sex'] ?? null);
        $victimContact = $this->cleanText($victimInfo['contact_number'] ?? ($victimInfo['contact'] ?? null));
        $victimSymptoms = $this->cleanText($victimInfo['symptoms'] ?? null) ?? $victimNoteString;

        return [
            'clinical_or_victim_name' => $clinicalOrVictimName,
            'incident_patient_name' => $incidentPatientName,
            'age' => $this->cleanText($emergencySection['age'] ?? null) ?? $victimAge,
            'sex' => $this->cleanText($emergencySection['sex'] ?? null) ?? $victimSex,
            'clinical_address' => $this->cleanText($emergencySection['address'] ?? null),
            'incident_location' => $this->cleanText($report->location),
            'contact' => $victimContact ?? $this->cleanText($report->reporter_contact),
            'victim_symptoms' => $victimSymptoms,
            'has_victim_payload' => $victimName !== null
                || $victimAge !== null
                || $victimSex !== null
                || $victimSymptoms !== null
                || $victimContact !== null,
        ];
    }

    private function formatContactNumber(?string $rawContact, bool $includeFullDetails): string
    {
        if ($rawContact === null) {
            return self::NOT_RECORDED;
        }

        if ($includeFullDetails) {
            return $rawContact;
        }

        $len = mb_strlen($rawContact);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4).mb_substr($rawContact, -4);
    }

    private function formatPatientIdentifier(?string $rawName, string $incidentRef, bool $includeFullDetails): string
    {
        if ($rawName === null) {
            return $includeFullDetails ? 'Unnamed Patient ('.$incidentRef.')' : 'Anonymous ('.$incidentRef.')';
        }

        if ($includeFullDetails) {
            return $rawName;
        }

        $tokens = preg_split('/\s+/', trim($rawName)) ?: [];
        $maskedTokens = array_map(function (string $token): string {
            $len = mb_strlen($token);
            if ($len <= 1) {
                return '*';
            }

            return mb_substr($token, 0, 1).str_repeat('*', min(4, $len - 1));
        }, $tokens);

        return implode(' ', $maskedTokens);
    }

    /**
     * @param  array<string, mixed>  $driverSection
     */
    private function hasAnyDriverLogisticsData(array $driverSection): bool
    {
        foreach ([
            'governmentCardPlateNo',
            'driverName',
            'balanceInTank',
            'issuedByOffice',
            'purchasedDuringTrip',
            'totalAvailableFuel',
            'fuelUsedDuringTrip',
            'remainingFuel',
            'gearOilUsed',
            'placeVisitedInspected',
            'purpose',
        ] as $key) {
            if ($this->cleanText($driverSection[$key] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{valid: bool, incompatible: bool, value: float|null, display: string}
     */
    private function parseFuelLiters(mixed $raw): array
    {
        $text = $this->cleanText($raw);
        if ($text === null) {
            return [
                'valid' => false,
                'incompatible' => false,
                'value' => null,
                'display' => self::NOT_RECORDED,
            ];
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(?:l|liters?|litres?)?$/i', $text, $matches) === 1) {
            $liters = (float) $matches[1];

            return [
                'valid' => true,
                'incompatible' => false,
                'value' => $liters,
                'display' => number_format($liters, 2).' L',
            ];
        }

        return [
            'valid' => false,
            'incompatible' => true,
            'value' => null,
            'display' => $text.' (Unstandardized Unit)',
        ];
    }

    /**
     * @return array{valid: bool, minutes: float|null, display: string}
     */
    private function diffMinutesBetween(mixed $start, mixed $end): array
    {
        if (! $start instanceof CarbonInterface || ! $end instanceof CarbonInterface) {
            return ['valid' => false, 'minutes' => null, 'display' => self::NOT_RECORDED];
        }

        if ($end->lessThan($start)) {
            return ['valid' => false, 'minutes' => null, 'display' => self::NOT_RECORDED.' (Invalid Sequence)'];
        }

        $seconds = (int) $start->diffInSeconds($end);
        $minutes = $seconds / 60;

        return [
            'valid' => true,
            'minutes' => $minutes,
            'display' => $this->formatDurationFromSeconds($seconds),
        ];
    }

    /**
     * @param  array<int, float>  $minutesList
     * @return array{valid_count: int, average_minutes: float|null, display: string}
     */
    private function summarizeAverages(array $minutesList): array
    {
        $count = count($minutesList);
        if ($count === 0) {
            return [
                'valid_count' => 0,
                'average_minutes' => null,
                'display' => self::NOT_RECORDED,
            ];
        }

        $avgMinutes = array_sum($minutesList) / $count;
        $avgSeconds = (int) round($avgMinutes * 60);

        return [
            'valid_count' => $count,
            'average_minutes' => round($avgMinutes, 2),
            'display' => sprintf('%s (%d %s)', $this->formatDurationFromSeconds($avgSeconds), $count, $count === 1 ? 'record' : 'records'),
        ];
    }

    private function formatDurationFromSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remSeconds = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %02dm', $hours, $minutes);
        }

        if ($remSeconds > 0) {
            return sprintf('%dm %02ds', $minutes, $remSeconds);
        }

        return sprintf('%dm', $minutes);
    }

    private function formatTimestamp(mixed $timestamp, string $timezone, bool $compact = false): string
    {
        if (! $timestamp instanceof CarbonInterface) {
            return self::NOT_RECORDED;
        }

        return CarbonImmutable::instance($timestamp)
            ->setTimezone($timezone)
            ->format($compact ? 'M d, h:i A' : 'M d, Y h:i A');
    }

    private function formatLocation(EmergencyReport $report): string
    {
        $loc = $this->cleanText($report->location);
        if ($loc !== null) {
            return $loc;
        }

        if ($report->lat !== null && $report->lng !== null) {
            return sprintf('%.5f, %.5f (GPS)', (float) $report->lat, (float) $report->lng);
        }

        return self::NOT_RECORDED;
    }

    private function formatAssignedPersonnelSummary(?string $teamName, string $driverDisplay, string $respondersDisplay): string
    {
        $parts = [];
        $teamClean = $this->cleanText($teamName);
        if ($teamClean !== null && $teamClean !== self::NOT_RECORDED) {
            $parts[] = $teamClean;
        }
        if ($driverDisplay !== self::NOT_RECORDED) {
            $parts[] = 'Driver: '.$driverDisplay;
        }
        if ($respondersDisplay !== self::NOT_RECORDED) {
            $parts[] = 'Crew: '.$respondersDisplay;
        }

        return $parts !== [] ? implode('; ', $parts) : self::NOT_RECORDED;
    }

    private function resolveOrganizationLogoDataUri(): ?string
    {
        if (self::$logoResolved) {
            return self::$cachedLogoDataUri;
        }

        self::$logoResolved = true;
        $webpPath = public_path('mdrrmo-logo.webp');
        if (! is_file($webpPath)) {
            return null;
        }

        if (function_exists('imagecreatefromwebp') && function_exists('imagepng')) {
            $image = @imagecreatefromwebp($webpPath);
            if ($image !== false) {
                imagealphablending($image, false);
                imagesavealpha($image, true);
                ob_start();
                imagepng($image);
                $pngBytes = ob_get_clean();
                imagedestroy($image);

                if (is_string($pngBytes) && $pngBytes !== '') {
                    self::$cachedLogoDataUri = 'data:image/png;base64,'.base64_encode($pngBytes);

                    return self::$cachedLogoDataUri;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $emergencySection
     * @param  array<string, mixed>  $driverSection
     */
    private function buildIncidentRemarks(EmergencyReport $report, array $emergencySection, array $driverSection): string
    {
        $parts = [];
        $desc = $this->cleanText($report->description);
        if ($desc !== null) {
            $parts[] = $desc;
        }
        $withdrawReason = $this->cleanText($report->withdrawal_reason);
        if ($withdrawReason !== null) {
            $parts[] = 'Withdrawal Reason: '.$withdrawReason;
        }
        $assessment = $this->cleanText($emergencySection['assessmentComment'] ?? null);
        if ($assessment !== null) {
            $parts[] = 'Responder Assessment: '.$assessment;
        }
        $purpose = $this->cleanText($driverSection['purpose'] ?? null);
        if ($purpose !== null) {
            $parts[] = 'Trip Purpose: '.$purpose;
        }

        return $parts !== [] ? implode(' | ', $parts) : self::NOT_RECORDED;
    }

    private function formatStatusLabel(string $status, bool $isWithdrawn): string
    {
        if ($status === 'invalid' && $isWithdrawn) {
            return 'Withdrawn / Cancelled';
        }

        return match ($status) {
            'pending' => 'Pending',
            'acknowledged' => 'Acknowledged',
            'dispatched' => 'Dispatched (Ongoing)',
            'resolved' => 'Resolved',
            'invalid' => 'Invalid',
            default => ucfirst($status),
        };
    }

    private function formatOutcomeLabel(EmergencyReport $report): string
    {
        if ($report->status === 'resolved') {
            return 'Completed';
        }
        if ($report->status === 'dispatched') {
            return 'Ongoing (Unit Dispatched)';
        }
        if ($report->status === 'acknowledged') {
            return 'Pending Dispatch (Acknowledged)';
        }
        if ($report->status === 'pending') {
            return 'Pending Acknowledgment';
        }
        if ($report->status === 'invalid') {
            return $report->withdrawn_at !== null
                ? 'Cancelled / Withdrawn by Reporter'
                : 'Invalidated / Unsuccessful';
        }

        return ucfirst((string) $report->status);
    }

    private function formatPersonName(?object $person): string
    {
        if ($person === null) {
            return self::NOT_RECORDED;
        }

        $first = trim((string) ($person->first_name ?? ''));
        $last = trim((string) ($person->last_name ?? ''));
        $combined = trim($first.' '.$last);
        if ($combined !== '') {
            return $combined;
        }

        $fallback = trim((string) ($person->name ?? $person->email ?? ''));

        return $fallback !== '' ? $fallback : self::NOT_RECORDED;
    }

    private function cleanText(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
