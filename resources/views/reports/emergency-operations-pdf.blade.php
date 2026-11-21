@php
    $period = $report['period'];
    $info = $report['report_info'];
    $summary = $report['executive_summary'];
    $incidents = $report['incidents'];
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
    <h2 class="section-title">Report Overview</h2>

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
    <h2 class="section-title">Patient and Emergency Records</h2>

    @if(empty($incidents))
        <div class="empty-state">No emergency incidents or patient records were recorded during this reporting period.</div>
    @else
        @if(!$info['include_patient_details'])
            <p class="note-line">Privacy Mode enabled: identifying patient names, residential addresses, and contact numbers are masked for this copy.</p>
        @endif
        <table class="formal fixed compact">
            <thead>
                <tr>
                    <th style="width: 17%;">Name</th>
                    <th class="center" style="width: 5%;">Age</th>
                    <th style="width: 14%;">Type Of Emergency</th>
                    <th style="width: 21%;">Address</th>
                    <th style="width: 12%;">Contact</th>
                    <th style="width: 14%;">Date &amp; Time</th>
                    <th style="width: 17%;">Endorsement</th>
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
                        <td>
                            @if(($incident['endorsed_by'] ?? '—') !== '—' || ($incident['endorsed_to'] ?? '—') !== '—')
                                @if(($incident['endorsed_by'] ?? '—') !== '—')
                                    <div><span class="subtext">By:</span> {{ $incident['endorsed_by'] }}</div>
                                @endif
                                @if(($incident['endorsed_to'] ?? '—') !== '—')
                                    <div><span class="subtext">To:</span> {{ $incident['endorsed_to'] }}</div>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="small-legend">Unrecorded or unavailable data fields are marked with &ldquo;&mdash;&rdquo;.</p>
</body>
</html>
