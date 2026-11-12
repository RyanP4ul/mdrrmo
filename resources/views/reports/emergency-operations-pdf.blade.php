@php
    $period = $report['period'];
    $info = $report['report_info'];
    $summary = $report['executive_summary'];
    $incidents = $report['incidents'];
    $timelines = $report['timelines'];
    $patients = $report['patients'];
    $vehicleUtilization = $report['vehicle_utilization'];
    $vehicleDeployments = $report['vehicle_deployments'];
    $logistics = $report['vehicle_logistics_summary'];
    $fleetStatus = $report['current_fleet_status'];
    $maintenanceReports = $report['period_maintenance_reports'];
    $performance = $report['performance'];

    $hasReturnToBase = collect($timelines)->contains(
        fn ($row) => ($row['returned_to_base_at'] ?? '—') !== '—'
    );
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $info['report_title'] }} - {{ $period['period_label'] }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 16mm 13mm 17mm 13mm;
        }

        body {
            font-family: 'Times-Roman', 'Times New Roman', Times, serif;
            font-size: 9pt;
            line-height: 1.35;
            color: #111111;
            background-color: #ffffff;
            margin: 0;
            padding: 0;
        }

        .running-header {
            position: fixed;
            top: -10.5mm;
            left: 0;
            right: 0;
            height: 6.5mm;
            border-bottom: 0.5pt solid #b5b5b5;
            font-size: 7.5pt;
            color: #444444;
        }

        .running-header-right {
            position: absolute;
            top: 0;
            right: 0;
            text-align: right;
        }

        .running-footer {
            position: fixed;
            bottom: -11mm;
            left: 0;
            right: 0;
            height: 7mm;
            border-top: 0.5pt solid #b5b5b5;
            padding-top: 3.5px;
            font-size: 7.5pt;
            color: #444444;
        }

        .letterhead {
            text-align: center;
            border-bottom: 1.5pt solid #111111;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }

        .letterhead-logo-wrap {
            text-align: center;
            margin-bottom: 5px;
        }

        .letterhead-logo {
            width: 56px;
            height: 56px;
        }

        .letterhead-org {
            font-size: 11.5pt;
            font-weight: bold;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            margin: 0;
            color: #000000;
        }

        .letterhead-division {
            font-size: 9pt;
            margin: 2px 0 6px 0;
            color: #222222;
        }

        .letterhead-title {
            font-size: 14pt;
            font-weight: bold;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            margin: 0 0 4px 0;
            color: #000000;
        }

        .letterhead-type {
            font-size: 10pt;
            font-weight: bold;
            margin: 0 0 3px 0;
            color: #111111;
        }

        .letterhead-meta {
            font-size: 8.5pt;
            color: #222222;
            margin: 1px 0;
        }

        .status-line {
            margin-top: 4px;
            font-size: 8.5pt;
            font-weight: bold;
            color: #111111;
        }

        h2.section-title {
            font-size: 10.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #000000;
            border-bottom: 1pt solid #111111;
            padding: 2px 0;
            margin: 12px 0 6px 0;
            page-break-after: avoid;
        }

        h3.subsection-title {
            font-size: 9pt;
            font-weight: bold;
            color: #111111;
            margin: 8px 0 4px 0;
            page-break-after: avoid;
        }

        table.formal {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            border-top: 0.6pt solid #444444;
            border-left: 0.6pt solid #444444;
            margin-bottom: 7px;
            font-size: 8pt;
            page-break-inside: auto;
        }

        table.formal.fixed {
            table-layout: fixed;
        }

        table.formal.compact {
            font-size: 7.4pt;
            line-height: 1.25;
        }

        table.formal thead {
            display: table-header-group;
        }

        table.formal tr {
            page-break-inside: avoid;
        }

        .keep-together {
            page-break-inside: avoid;
        }

        table.formal th,
        table.formal td {
            border-right: 0.6pt solid #444444;
            border-bottom: 0.6pt solid #444444;
            padding: 4px 5px;
            vertical-align: top;
            text-align: left;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        table.formal.compact th,
        table.formal.compact td {
            padding: 3px 3.5px;
        }

        table.formal th {
            background-color: #f2f2f2;
            font-weight: bold;
            color: #000000;
        }

        table.formal tr.total-row td {
            background-color: #f2f2f2;
            font-weight: bold;
            color: #000000;
            border-top: 1pt solid #222222;
        }

        table.formal td.num,
        table.formal th.num {
            text-align: right;
        }

        table.formal td.center,
        table.formal th.center {
            text-align: center;
        }

        table.formal td.label-cell {
            background-color: #fafafa;
            font-weight: bold;
            width: 19%;
            color: #111111;
        }

        .kpi-value {
            font-size: 11pt;
            font-weight: bold;
            color: #000000;
        }

        .subtext {
            font-size: 7.2pt;
            color: #444444;
        }

        .note-line {
            font-size: 7.8pt;
            color: #333333;
            margin: 0 0 5px 0;
        }

        .empty-state {
            border: 0.6pt solid #888888;
            padding: 6px 8px;
            font-size: 8.5pt;
            font-style: italic;
            color: #222222;
            margin-bottom: 7px;
        }

        .signoff-wrap {
            page-break-inside: avoid;
            margin-top: 6px;
        }

        table.signoff-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: separate;
            border-spacing: 0;
            border-top: 0.6pt solid #444444;
            border-left: 0.6pt solid #444444;
            font-size: 8.5pt;
        }

        table.signoff-table td {
            width: 50%;
            border-right: 0.6pt solid #444444;
            border-bottom: 0.6pt solid #444444;
            padding: 8px 10px;
            vertical-align: top;
        }

        .signoff-role {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 8pt;
            margin-bottom: 24px;
            color: #111111;
        }

        .signoff-line {
            border-top: 0.7pt solid #111111;
            padding-top: 3px;
            font-weight: bold;
            font-size: 8.5pt;
        }

        .signoff-meta {
            font-size: 7.8pt;
            color: #333333;
            margin-top: 2px;
        }

        .small-legend {
            font-size: 7.4pt;
            color: #555555;
            margin-top: 4px;
        }
    </style>
</head>
<body>
    <div class="running-header">
        <span>MDRRMO &mdash; Emergency Operations Report</span>
        <span class="running-header-right">{{ $period['period_type_label'] }} Report &nbsp;|&nbsp; {{ $period['period_label'] }}</span>
    </div>

    <div class="running-footer">
        <span>MDRRMO &mdash; Official Emergency Operations Report &nbsp;|&nbsp; Generated {{ $info['generated_at'] }}</span>
    </div>

    {{-- Official First-Page Letterhead --}}
    <div class="letterhead">
        @if(!empty($info['logo_data_uri']))
            <div class="letterhead-logo-wrap">
                <img src="{{ $info['logo_data_uri'] }}" alt="MDRRMO Official Logo" class="letterhead-logo">
            </div>
        @endif
        <p class="letterhead-org">{{ mb_strtoupper($info['organization_name']) }}</p>
        <p class="letterhead-division">{{ $info['unit_name'] }}</p>
        <h1 class="letterhead-title">{{ $info['report_title'] }}</h1>
        <p class="letterhead-type">{{ $info['report_type'] }} Report</p>
        <p class="letterhead-meta">Reporting Period: {{ $info['reporting_period'] }} ({{ $period['timezone'] }})</p>
        <p class="letterhead-meta">Generated On: {{ $info['generated_at'] }}</p>
        @if($info['is_partial_period'] || $info['is_future_period'])
            <p class="status-line">Report Status: {{ $info['report_status_label'] }}</p>
        @endif
    </div>

    {{-- SECTION 1: REPORT OVERVIEW --}}
    <h2 class="section-title">Section 1: Report Overview</h2>

    @if(empty($summary['category_breakdown']))
        <div class="empty-state">No emergency incidents were recorded during this reporting period.</div>
    @else
        <h3 class="subsection-title">Incidents by Category</h3>
        <table class="formal fixed">
            <thead>
                <tr>
                    <th style="width: 58%;">Incident Category</th>
                    <th style="width: 24%;">Priority</th>
                    <th class="num" style="width: 18%;">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($summary['category_breakdown'] as $row)
                    <tr>
                        <td>{{ $row['category'] }}</td>
                        <td>{{ $row['priority'] }}</td>
                        <td class="num"><strong>{{ $row['total'] }}</strong></td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="2">Total Emergencies</td>
                    <td class="num">{{ $info['total_incidents'] }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    {{-- SECTION 2: PATIENT AND EMERGENCY RECORDS --}}
    <h2 class="section-title">Section 2: Patient and Emergency Records</h2>

    @if(empty($incidents))
        <div class="empty-state">No emergency incidents or patient records were recorded during this reporting period.</div>
    @else
        @if(!$info['include_patient_details'])
            <p class="note-line">Privacy Mode enabled: identifying patient names, residential addresses, and contact numbers are masked for this copy.</p>
        @endif
        <table class="formal fixed compact">
            <thead>
                <tr>
                    <th style="width: 20%;">Name</th>
                    <th class="center" style="width: 6%;">Age</th>
                    <th style="width: 16%;">Type Of Emergency</th>
                    <th style="width: 25%;">Address</th>
                    <th style="width: 15%;">Contact</th>
                    <th style="width: 18%;">Date &amp; Time</th>
                </tr>
            </thead>
            <tbody>
                @foreach($incidents as $incident)
                    <tr>
                        <td><strong>{{ $incident['patient_name'] }}</strong></td>
                        <td class="center">{{ $incident['patient_age'] }}</td>
                        <td>{{ $incident['type_of_emergency'] }}</td>
                        <td>{{ $incident['address'] }}</td>
                        <td>{{ $incident['contact'] }}</td>
                        <td>{{ $incident['date_time'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- SECTION 3: DISPATCH AND RESPONSE RECORDS --}}
    <h2 class="section-title">Section 3: Dispatch and Response Records</h2>

    @if(empty($timelines))
        <div class="empty-state">No dispatch and response records are available for this reporting period.</div>
    @else
        <table class="formal fixed compact">
            <thead>
                <tr>
                    <th style="width: {{ $hasReturnToBase ? '9%' : '10%' }};">Incident Ref</th>
                    <th style="width: {{ $hasReturnToBase ? '18%' : '20%' }};">Driver &amp; Responders</th>
                    <th style="width: {{ $hasReturnToBase ? '9%' : '10%' }};">Plate</th>
                    <th style="width: 9%;">Received</th>
                    <th style="width: 9%;">Dispatched</th>
                    <th style="width: 9%;">Arrived</th>
                    <th style="width: 9%;">Completed</th>
                    @if($hasReturnToBase)
                        <th style="width: 9%;">Return to Base</th>
                    @endif
                    <th style="width: {{ $hasReturnToBase ? '10%' : '13%' }};">Response Duration</th>
                    <th style="width: {{ $hasReturnToBase ? '9%' : '11%' }};">Final Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($timelines as $t)
                    <tr>
                        <td><strong>{{ $t['incident_ref'] }}</strong></td>
                        <td>{{ $t['driver_and_responders'] }}</td>
                        <td><strong>{{ $t['plate_number'] }}</strong></td>
                        <td>{{ $t['received_at'] }}</td>
                        <td>{{ $t['dispatched_at'] }}</td>
                        <td>{{ $t['arrived_at'] }}</td>
                        <td>{{ $t['completed_at'] }}</td>
                        @if($hasReturnToBase)
                            <td>{{ $t['returned_to_base_at'] }}</td>
                        @endif
                        <td>
                            {{ $t['response_travel_time'] }}
                            @if(($t['operation_duration'] ?? '—') !== '—')
                                <br><span class="subtext">Total Op: {{ $t['operation_duration'] }}</span>
                            @endif
                        </td>
                        <td>{{ $t['final_status'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- SECTION 4: VEHICLE UTILIZATION AND LOGISTICS --}}
    <h2 class="section-title">Section 4: Vehicle Utilization and Logistics</h2>

    <h3 class="subsection-title">4.1 Reporting Period Deployment, Fuel, and Odometer Logs</h3>

    @if(empty($vehicleDeployments) && empty($vehicleUtilization))
        <div class="empty-state">No emergency vehicle deployments or trip logs were recorded during this reporting period.</div>
    @else
        @if(!empty($vehicleDeployments))
            <table class="formal fixed compact">
                <thead>
                    <tr>
                        <th style="width: 14%;">Plate</th>
                        <th style="width: 25%;">Assigned Driver(s)</th>
                        <th class="num" style="width: 11%;">Deployments</th>
                        <th class="num" style="width: 15%;">Distance Traveled</th>
                        <th class="num" style="width: 18%;">Fuel Purchased / Consumed</th>
                        <th style="width: 17%;">Incident Refs</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($vehicleDeployments as $vd)
                        @php
                            $fuelSummaryParts = [];
                            if (($vd['fuel_purchased_count'] ?? 0) > 0) {
                                $fuelSummaryParts[] = 'Purch: '.number_format((float) $vd['fuel_purchased_l'], 2).' L';
                            }
                            if (($vd['fuel_consumed_count'] ?? 0) > 0) {
                                $fuelSummaryParts[] = 'Used: '.number_format((float) $vd['fuel_consumed_l'], 2).' L';
                            }
                            $fuelSummary = empty($fuelSummaryParts) ? '—' : implode(' / ', $fuelSummaryParts);
                        @endphp
                        <tr>
                            <td><strong>{{ $vd['plate_number'] }}</strong></td>
                            <td>{{ !empty($vd['drivers']) ? implode(', ', $vd['drivers']) : '—' }}</td>
                            <td class="num"><strong>{{ $vd['deployments'] }}</strong></td>
                            <td class="num">{{ ($vd['distance_count'] ?? 0) > 0 ? number_format((float) $vd['distance_km'], 2).' km' : '—' }}</td>
                            <td class="num">{{ $fuelSummary }}</td>
                            <td>{{ !empty($vd['incident_refs']) ? implode(', ', $vd['incident_refs']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if(!empty($vehicleUtilization))
            <table class="formal fixed compact">
                <thead>
                    <tr>
                        <th style="width: 11%;">Plate</th>
                        <th style="width: 23%;">Driver &amp; Incident Ref</th>
                        <th style="width: 12%;">Trip Date</th>
                        <th style="width: 13%;">Fuel Begin / End</th>
                        <th style="width: 13%;">Fuel Purch / Used</th>
                        <th style="width: 16%;">Odometer Start / End</th>
                        <th class="num" style="width: 12%;">Distance Traveled</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($vehicleUtilization as $vu)
                        @php
                            $fuelLevels = ($vu['fuel_beginning'] !== '—' || $vu['fuel_end'] !== '—')
                                ? $vu['fuel_beginning'].' / '.$vu['fuel_end']
                                : '—';
                            $fuelUsed = ($vu['fuel_purchased'] !== '—' || $vu['fuel_consumed'] !== '—')
                                ? $vu['fuel_purchased'].' / '.$vu['fuel_consumed']
                                : '—';
                            $odometerRange = ($vu['starting_odometer'] !== '—' || $vu['ending_odometer'] !== '—')
                                ? $vu['starting_odometer'].' - '.$vu['ending_odometer']
                                : '—';
                            $distanceValue = $vu['logged_distance'] !== '—'
                                ? $vu['logged_distance']
                                : ($vu['odometer_distance'] ?? '—');
                        @endphp
                        <tr>
                            <td><strong>{{ $vu['plate_number'] }}</strong></td>
                            <td>
                                {{ $vu['assigned_driver'] }}
                                @if(($vu['incident_ref'] ?? '—') !== '—')
                                    <br><span class="subtext">Ref: {{ $vu['incident_ref'] }}</span>
                                @endif
                            </td>
                            <td>{{ $vu['trip_date'] }}</td>
                            <td>{{ $fuelLevels }}</td>
                            <td>{{ $fuelUsed }}</td>
                            <td>{{ $odometerRange }}</td>
                            <td class="num">{{ $distanceValue }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif

    <h3 class="subsection-title">4.2 Current Fleet Availability and Maintenance Status (As of {{ $info['generated_at'] }})</h3>

    @if(!empty($maintenanceReports))
        <table class="formal fixed compact">
            <thead>
                <tr>
                    <th style="width: 15%;">Plate</th>
                    <th style="width: 17%;">Issue Type</th>
                    <th style="width: 10%;">Severity</th>
                    <th style="width: 13%;">Reported</th>
                    <th style="width: 13%;">Status</th>
                    <th style="width: 32%;">Maintenance Notes</th>
                </tr>
            </thead>
            <tbody>
                @foreach($maintenanceReports as $mr)
                    <tr>
                        <td><strong>{{ $mr['plate_number'] }}</strong></td>
                        <td>{{ $mr['issue_type'] }}</td>
                        <td>{{ $mr['severity'] }}</td>
                        <td>{{ $mr['reported_at'] }}</td>
                        <td>{{ $mr['status'] }}@if($mr['recommend_out_of_service']) (Out of Service)@endif</td>
                        <td>{{ $mr['description'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if(!empty($fleetStatus))
        <table class="formal fixed compact">
            <thead>
                <tr>
                    <th style="width: 14%;">Plate</th>
                    <th style="width: 13%;">Type</th>
                    <th style="width: 17%;">Assigned Team</th>
                    <th style="width: 14%;">Current Status</th>
                    <th style="width: 13%;">Availability</th>
                    <th style="width: 29%;">Maintenance Notice</th>
                </tr>
            </thead>
            <tbody>
                @foreach($fleetStatus as $fs)
                    @php
                        $rawWarning = $fs['maintenance_warning'] ?? null;
                        $hasFlag = !empty($fs['requires_maintenance']) || !empty($rawWarning) || ($fs['open_maintenance_count'] ?? 0) > 0;
                        $maintenanceLabel = is_string($rawWarning) && !in_array(trim($rawWarning), ['', '0', '1'], true)
                            ? $rawWarning
                            : ($hasFlag ? 'Maintenance Review Required' : 'Normal (No active warning)');
                    @endphp
                    <tr>
                        <td><strong>{{ $fs['plate_number'] }}</strong></td>
                        <td>{{ $fs['type'] }}</td>
                        <td>{{ $fs['team_name'] }}</td>
                        <td>{{ $fs['current_status'] }}</td>
                        <td>{{ $fs['is_available_now'] ? 'Available' : 'Unavailable' }}</td>
                        <td>
                            {{ $maintenanceLabel }}
                            @if(($fs['open_maintenance_count'] ?? 0) > 0 && ($fs['latest_issue'] ?? '—') !== '—')
                                <br><span class="subtext">Open ({{ $fs['open_maintenance_count'] }}): {{ $fs['latest_issue'] }}</span>
                            @elseif(($fs['open_maintenance_count'] ?? 0) > 0)
                                <br><span class="subtext">Open Reports: {{ $fs['open_maintenance_count'] }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="empty-state">No fleet units registered.</div>
    @endif

    {{-- SECTION 5: OPERATIONAL PERFORMANCE --}}
    <div class="keep-together">
        <h2 class="section-title">Section 5: Operational Performance</h2>

        <h3 class="subsection-title">5.1 Performance Indicators</h3>
        <table class="formal fixed">
            <thead>
                <tr>
                    <th style="width: 55%;">Performance Indicator</th>
                    <th style="width: 45%;">Measured Value</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Incidents per Day</td>
                    <td><strong>{{ $performance['incidents_per_day'] }}</strong> <span class="subtext">({{ $performance['incidents_per_day_note'] }})</span></td>
                </tr>
                <tr>
                    <td>Completion Rate</td>
                    <td><strong>{{ $performance['completion_rate'] }}</strong></td>
                </tr>
                <tr>
                    <td>Average Dispatch Delay</td>
                    <td>{{ $performance['average_dispatch_delay']['display'] }}</td>
                </tr>
                <tr>
                    <td>Average Response Time</td>
                    <td>{{ $performance['average_response_travel_time']['display'] }}</td>
                </tr>
                <tr>
                    <td>Average Operation Duration</td>
                    <td>{{ $performance['average_operation_duration']['display'] }}</td>
                </tr>
                <tr>
                    <td>Incidents with Patient Transport Recorded</td>
                    <td>{{ $performance['incidents_with_transport_recorded'] }}</td>
                </tr>
                <tr>
                    <td>Cancelled or Unsuccessful Operations</td>
                    <td>{{ $performance['unsuccessful_or_cancelled_responses'] }}</td>
                </tr>
                @if(($performance['withdrawn_responses'] ?? 0) > 0 || ($performance['invalidated_responses'] ?? 0) > 0)
                    <tr>
                        <td>Withdrawn / Invalidated Reports</td>
                        <td>{{ $performance['withdrawn_responses'] }} withdrawn, {{ $performance['invalidated_responses'] }} invalidated</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    <h3 class="subsection-title">5.2 Personnel Deployment Frequency</h3>
    @if(empty($performance['personnel_deployments']))
        <div class="empty-state">No personnel deployment assignments were recorded during this reporting period.</div>
    @else
        <table class="formal fixed compact">
            <thead>
                <tr>
                    <th style="width: 44%;">Personnel Name</th>
                    <th style="width: 36%;">Operational Role(s)</th>
                    <th class="num" style="width: 20%;">Incidents Assigned</th>
                </tr>
            </thead>
            <tbody>
                @foreach($performance['personnel_deployments'] as $person)
                    <tr>
                        <td><strong>{{ $person['name'] }}</strong></td>
                        <td>{{ !empty($person['roles']) ? implode(', ', $person['roles']) : '—' }}</td>
                        <td class="num"><strong>{{ $person['incident_count'] }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h3 class="subsection-title">5.3 Vehicle Deployment Frequency</h3>
    @if(empty($performance['vehicle_deployments']))
        <div class="empty-state">No vehicle deployments were recorded during this reporting period.</div>
    @else
        <table class="formal fixed compact">
            <thead>
                <tr>
                    <th style="width: 22%;">Plate</th>
                    <th style="width: 43%;">Assigned Driver(s)</th>
                    <th class="num" style="width: 15%;">Deployments</th>
                    <th class="num" style="width: 20%;">Logged Distance</th>
                </tr>
            </thead>
            <tbody>
                @foreach($performance['vehicle_deployments'] as $vd)
                    <tr>
                        <td><strong>{{ $vd['plate_number'] }}</strong></td>
                        <td>{{ !empty($vd['drivers']) ? implode(', ', $vd['drivers']) : '—' }}</td>
                        <td class="num"><strong>{{ $vd['deployments'] }}</strong></td>
                        <td class="num">{{ ($vd['distance_count'] ?? 0) > 0 ? number_format((float) $vd['distance_km'], 2).' km' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- SECTION 6: REPORT SIGN-OFF --}}
    <h2 class="section-title">Section 6: Report Sign-Off</h2>

    <div class="signoff-wrap">
        <table class="signoff-table">
            <tbody>
                <tr>
                    <td>
                        <div class="signoff-role">Prepared By:</div>
                        <div class="signoff-line">{{ $info['generated_by_name'] }}</div>
                        <div class="signoff-meta">Authorized MDRRMO Administrator</div>
                        <div class="signoff-meta">Date Signed: ___________________________</div>
                    </td>
                    <td>
                        <div class="signoff-role">Reviewed By:</div>
                        <div class="signoff-line">&nbsp;</div>
                        <div class="signoff-meta">MDRRMO Head / Operations Officer</div>
                        <div class="signoff-meta">Date Signed: ___________________________</div>
                    </td>
                </tr>
            </tbody>
        </table>
        <p class="small-legend">Unrecorded or unavailable data fields are marked with &ldquo;&mdash;&rdquo;.</p>
    </div>
</body>
</html>
