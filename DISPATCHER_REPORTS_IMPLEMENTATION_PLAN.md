# Dispatcher Weekly & Monthly Emergency Operations PDF Reports — Implementation Plan

## 1. Existing System Analysis

### Architecture & Technology Stack
- **Backend**: PHP `^8.5.8`, Laravel `^13.17`, Inertia.js (`inertiajs/inertia-laravel ^3.4`), Fortify, Sanctum, Reverb, Ziggy (`tightenco/ziggy`).
- **Frontend**: React `19.3`, `@inertiajs/react ^3.7.1`, TypeScript, Tailwind CSS `^4.3.3`, Radix UI primitives, Lucide React icons, Vite 8.
- **Databases & Timezone**:
  - Runtime database: PostgreSQL (`DB_CONNECTION=pgsql`), with PHPUnit tests running against SQLite `:memory:` (`phpunit.xml`).
  - `config('app.timezone')` is `UTC`, while Philippine Standard Time (`Asia/Manila`) is configured in `config('mobile.timezone')`. All timestamps in the database are stored normalized in UTC, so reporting date boundaries must be computed in `Asia/Manila` and converted to UTC (`->utc()`) for queries, then formatted back in `Asia/Manila` for report output.
- **Authentication & Authorization**:
  - Role-separated account tables (`admins`, `dispatchers`, `drivers`, `residents`) fronted by `App\Http\Middleware\RoleMiddleware` (`role:dispatcher`, requiring both matching role and active status) and `App\Policies\EmergencyReportPolicy` (`viewAny` authorizes `admin` and `dispatcher`).
- **PDF Generation Capability**:
  - **None installed** in either `composer.json` or `package.json`. No Blade PDF templates, report export controllers, or scheduled report generation jobs exist (`routes/console.php` only schedules `mobile:auto-dispatch`).
  - Verified via PHP extension check (`php -m`) that PHP 8.5.8 already includes `dom`, `gd`, `mbstring`, `xml`, `xmlreader`, `xmlwriter`, `zip`, `zlib`, `bcmath`, `pdo_pgsql`, and `pdo_sqlite`. Dry-run resolution of `barryvdh/laravel-dompdf` (`v3.1.2` + `dompdf/dompdf v3.1.6`) succeeds cleanly with zero conflicts and works identically on Windows local dev and Railway Linux (`start.sh`) without requiring headless Chrome/Chromium.

### Existing Dispatcher Reports Page & Controller
- **Route**: `GET /dispatcher/reports` (`dispatcher.reports.index`), protected by `['auth', 'role:dispatcher']` in `routes/web.php`.
- **Controller**: `App\Http\Controllers\Dispatcher\ReportController::index(Request $request)` currently authorizes `viewAny` on `EmergencyReport::class` and queries `EmergencyReport` filtered strictly to `whereIn('status', ['resolved', 'invalid'])` with `incidentType`, `form`, and `driver` eager-loaded, paginated at 6 items per page.
  - Note: `ReportController::getReports()` serves the mobile/driver API (`routes/api.php` -> `api.driver-responder.reports`) and must remain untouched.
- **UI (`resources/js/Pages/Dispatcher/Reports/Index.tsx`)**:
  - Uses `AppLayout`, `PageHeader`, `FilterBar`, `FilterSelect`, `EmptyState`, `StatusBadge`, `ServerPagination`, and `Button`.
  - Currently splits the 6-item current page between two client-side tabs: **Emergency Reports** (resolved/invalid history list linking to `dispatcher.reports.show`) and **Driver Forms** (records on the current page having populated `form.driver_section` keys).
  - There is currently no reporting period selector, statistical aggregation, operational timeline/vehicle/patient report compiler, or PDF preview/download flow.

---

## 2. Available Database Tables and Relevant Fields

The existing schema provides all operational entities needed without creating duplicate tables:

| Table / Model | Relevant Fields for Operations Reporting | Relationships & Notes |
|---|---|---|
| `emergency_reports` (`EmergencyReport`) | `id`, `incident_type_id`, `response_team_id`, `reported_by_type`, `reported_by`, `location`, `latitude`, `longitude`, `description`, `victim_info`, `status` (`pending`, `acknowledged`, `dispatched`, `resolved`, `invalid`), `withdrawn_at`, `withdrawal_reason`, `assigned_driver_id`, `reported_at`, `acknowledged_by_type`, `acknowledged_by`, `acknowledged_at`, `resolved_at`, `created_at` | Composite index on `(status, reported_at)`. Links to `IncidentType`, `ResponseTeam`, `DriverResponderUser`, `DispatchTrip` (`hasOne`), `ReportForm` (`hasOne`). |
| `incident_types` (`IncidentType`) | `id`, `name`, `description`, `priority`, `color` | Category and severity/priority source for Section 2, Section 3, and Section 7. |
| `dispatch_trips` (`DispatchTrip`) | `id`, `emergency_report_id` (unique FK), `destination`, `vehicle_number`, `driver_name`, `started_at`, `on_scene_at`, `picked_up_at`, `arrived_at`, `returning_at`, `distance_km` | Stores operational milestones and driver-logged distance (`distance_km`). Does **not** contain a separate `returned_to_base_at` column. |
| `report_forms` (`ReportForm`) | `id`, `emergency_report_id` (unique FK), `driver_section` (JSON), `emergency_section` (JSON) | **Driver section keys**: `tripDate`, `driverName`, `governmentCardPlateNo`, `authorizedPassenger`, `placeVisitedInspected`, `purpose`, `passengerName`, `balanceInTank`, `issuedByOffice`, `purchasedDuringTrip`, `totalAvailableFuel`, `fuelUsedDuringTrip`, `remainingFuel`, `gearOilUsed`. **Emergency/Patient section keys**: `timeReported`, `timeOfArrival`, `patientName`, `age`, `sex`, `address`, `typeOfIncident`, `allergies`, `medications`, `assessmentComment`, `treatmentManagement`, `vitalSigns` (`bloodPressure`, `pulseRate`, `respiration`). |
| `response_teams` (`ResponseTeam`), `team_members` (`TeamMember`), `shifts` (`Shift`), `shift_team_member` | Team `id`, `name`, `active_vehicle_id`, `member_roles_enabled`; member `user_id`, `response_team_id`, `role` (driver vs responder via `TeamRoles`); `shifts` and `shift_team_member` active assignments | Used to resolve assigned response team, drivers, and responder personnel alongside submitted `report_forms.driver_section` text (`driverName`, `authorizedPassenger`). |
| `vehicles` (`Vehicle`) | `id`, `name`, `type` (`ambulance`, `fire-truck`, `rescue-van`, `police-car`, `utility-truck`), `plate_number`, `response_team_id`, `assigned_report_id`, `status` (`en-route`, `on-scene`, `available`, `returning`, `offline`, `maintenance`), `maintenance_warning` | Holds **current** vehicle availability and maintenance warning flags. Note: `assigned_report_id` is cleared (`null`) once an incident resolves or becomes invalid (`EmergencyReportWorkflow`). |
| `vehicle_tracking_sessions` | `id`, `vehicle_id`, `driver_id`, `emergency_report_id`, `started_at`, `stopped_at` | Preserves historical association between a vehicle and an `emergency_report_id` after `vehicles.assigned_report_id` is cleared. Currently no Eloquent relation is defined on `EmergencyReport` for this table. |
| `maintenance_reports` (`MaintenanceReport`) | `id`, `vehicle_id`, `response_team_id`, `reported_by`, `issue_type`, `severity`, `mileage`, `description`, `recommend_out_of_service`, `status` (`OPEN_STATUSES`: `pending`, `acknowledged`, `approved`, `in_maintenance`), `reviewed_at`, `resolved_at`, `admin_notes`, `created_at` | Used for vehicle maintenance observations during the period and **current** open maintenance status (`openMaintenanceReports`). |
| `dispatchers`, `drivers`, `admins`, `residents`, `hospitals` | `first_name`, `last_name`, `email`, `status` on account models; `hospitals` directory (`name`, `address`, `available_beds`) | Used to identify authorized dispatcher generating the report and driver/responder names. |

---

## 3. Existing API Endpoints & Services Considered for Reuse

- **Services Reviewed**:
  - `App\Services\DispatchService`: Handles active queue dispatch/hospital recommendations dynamically (`recommendHospitals` is not persisted per patient/incident). Not appropriate as a report compiler, though its status/dispatch rules explain timestamp semantics.
  - `App\Services\EmergencyReportWorkflow`: Defines transition behavior when resolving/invalidating reports (notably clearing `vehicles.assigned_report_id`).
  - `App\Services\DriverResponseProgress`: Updates `dispatch_trips` timestamps (`started_at`, `on_scene_at`, `picked_up_at`, `arrived_at`, `returning_at`) and `distance_km`.
  - `App\Services\VehicleMaintenanceService`: Defines vehicle status transitions and `MaintenanceReport` open statuses (`MaintenanceReport::OPEN_STATUSES`).
- **Auth & Routing Patterns**:
  - `Dispatcher\ReportController` + `EmergencyReportPolicy::viewAny()` already enforce dispatcher authentication and authorization; the new operations report actions should live on `Dispatcher\ReportController` (or delegate cleanly from it to a dedicated `EmergencyOperationsReportService`) under `routes/web.php` (`dispatcher.reports.operations.*`) so no duplicate auth middleware or API surface is created.

---

## 4. Missing Data, Schema Limitations & Transparent Handling Rules

In strict compliance with operational reporting integrity, **no dummy records, fabricated timestamps, or inferred values** will ever be generated. Each schema gap identified below will be handled explicitly:

1. **Incident Occurrence Timestamp (`occurred_at`)**:
   - *Gap*: `emergency_reports` only records `reported_at` (and `created_at`). `report_forms.emergency_section.timeReported` is optional time-of-day (`H:i`) without a date component.
   - *Handling*: Display `reported_at` in `Asia/Manila`; show `"Not Recorded (Recorded Time Only: HH:mm)"` or `"Not Recorded"` for `occurred_at` unless an explicit occurrence field exists. Do not assume `reported_at == occurred_at`.
2. **Exact Dispatch Timestamp (`dispatched_at`)**:
   - *Gap*: `DispatchService::dispatch()` sets `status = 'dispatched'` and sets `acknowledged_at = acknowledged_at ?? now()`, so there is no standalone `dispatched_at` column. Meanwhile `dispatch_trips.started_at` marks when the driver begins the trip (`en-route`).
   - *Handling*: Use `dispatch_trips.started_at` for unit deployment / trip start time when recorded, and clearly label `acknowledged_at` separately where relevant. Never compute dispatch delay or travel time when required endpoints (`reported_at`, `started_at`, `on_scene_at`, `resolved_at`) are null; print `"Not Recorded"` and exclude incomplete rows from averages while displaying valid sample counts `(n = X of Y)`.
3. **Operation Completion vs. Vehicle Return-to-Base**:
   - *Gap*: `dispatch_trips.returning_at` records when returning starts, and `emergency_reports.resolved_at` records incident closure; there is no separate `returned_to_base_at` completion column.
   - *Handling*: Display **Operation Completed (`resolved_at`)** and **Unit Returning (`dispatch_trips.returning_at`)** in separate columns, and explicitly note `"Not Recorded (Separate return-to-base arrival not stored)"` when `returning_at` or final return timestamp is unavailable—never conflating `resolved_at` and return-to-base.
4. **Cancelled vs. Unsuccessful / Invalid Operations**:
   - *Gap*: `emergency_reports.status` does not contain `'cancelled'` or `'failed'` enums; it uses `'invalid'` combined with nullable `withdrawn_at` and `withdrawal_reason`.
   - *Handling*: Classify `'resolved'` as **Completed**, `'pending' | 'acknowledged' | 'dispatched'` as **Pending / Ongoing**, and `'invalid'` split transparently into **Withdrawn / Cancelled** (`withdrawn_at != null`) and **Invalidated / Unsuccessful** (`withdrawn_at == null`).
5. **Historical Vehicle Linkage After Incident Closure**:
   - *Gap*: `vehicles.assigned_report_id` is nulled on resolution, and `DriverResponseProgress` does not always populate `dispatch_trips.vehicle_number`.
   - *Handling*: Resolve incident vehicle historically in priority order using verifiable recorded sources only: (1) `vehicle_tracking_sessions` (`emergency_report_id -> vehicle_id`), (2) `dispatch_trips.vehicle_number` (matched cleanly to `vehicles.plate_number` if available), and (3) `report_forms.driver_section['governmentCardPlateNo']`. If none of those were recorded for the incident, display `"Not Recorded"` rather than guessing `team->activeVehicle`.
6. **Vehicle Odometer Readings (`starting_odometer`, `ending_odometer`) & Fuel Consumption**:
   - *Gap*: Neither `vehicles`, `dispatch_trips`, nor `report_forms` stores `starting_odometer` or `ending_odometer` (`maintenance_reports.mileage` only records maintenance-event mileage). `dispatch_trips.distance_km` records trip distance directly. Fuel fields live in `report_forms.driver_section` in liters (`L`): `balanceInTank`, `issuedByOffice`, `purchasedDuringTrip`, `totalAvailableFuel`, `fuelUsedDuringTrip`, `remainingFuel`.
   - *Handling*: Display `"Not Recorded"` for Starting/Ending Odometer and clearly label **Recorded Trip Distance (`distance_km`)** separately (never fabricating odometer math from missing endpoints; use `Ending - Starting` only if future odometer data exists). Report `purchasedDuringTrip`, `balanceInTank` (beginning), `remainingFuel` (end), and `fuelUsedDuringTrip` (consumption) as distinct metrics—never treating fuel purchased as fuel consumed.
7. **Multiple Patients per Incident & Hospital Destination / Acceptance**:
   - *Gap*: `report_forms.emergency_report_id` has a unique constraint (at most one `ReportForm` per incident), and there are no dedicated `patient_transport_status` or `hospital_endorsement_status` columns. `dispatch_trips.destination` / `driver_section.placeVisitedInspected` may hold destination text.
   - *Handling*: Read patient details from `report_forms.emergency_section` (plus structured/textual fallback notes only when `victim_info` exists, marked clearly), avoid double-counting incidents when aggregating patient counts, show `dispatch_trips.destination` (or `"Not Recorded"`) for Hospital Destination, and mark Transport Status / Hospital Endorsement Status as `"Not Recorded"` when unrecorded. Protect patient privacy by sanitizing output by default (masked patient names / omitted street addresses unless authorized dispatcher opts to include full clinical records under role authorization) and streaming PDFs securely (`Cache-Control: no-store, private`, never saved to `public` disk).
8. **Organization Metadata & PDF Library**:
   - *Gap*: `config('app.name')` defaults to `"Laravel"`, and no PDF package exists.
   - *Handling*: Add a lightweight `config/reporting.php` (or derive organization name cleanly as `"Municipal Disaster Risk Reduction and Management Office (MDRRMO)"` configurable via env without modifying unrelated app identity) and propose installing `barryvdh/laravel-dompdf` (`^3.1`) after dependency approval.

---

## 5. Proposed Backend Implementation

### Dependency Addition (Pending Approval per `CLAUDE.md`)
- Install `barryvdh/laravel-dompdf` (`^3.1`) via Composer for server-side A4 PDF generation using Blade + CSS (`Times-Roman`, repeating `<thead>` headers, script/page-number footer, zero browser/external runtime dependencies).

### New / Updated Backend Components
1. **`app/Http/Requests/GenerateEmergencyOperationsReportRequest.php`**
   - Authorizes via `$this->user()?->hasRole('dispatcher')` (matching route middleware + `EmergencyReportPolicy::viewAny`).
   - Validates:
     - `period_type`: `required|in:weekly,monthly`
     - `week_date` (or `week`): `required_if:period_type,weekly|date` — resolved cleanly to the Monday `00:00:00` through Sunday `23:59:59` week containing that date in `Asia/Manila` (defaulting to current `Asia/Manila` week if omitted in UI).
     - `month`: `required_if:period_type,monthly|integer|between:1,12`
     - `year`: `required_if:period_type,monthly|integer|between:2020,2100`
     - `mode`: `nullable|in:preview,download`
     - `include_patient_details`: `nullable|boolean` (controls full vs anonymized/masked patient identifiers for data privacy compliance; unauthorized roles are always rejected at route + Gate + Request level).
2. **`app/Services/EmergencyOperationsReportService.php`**
   - Single read-only source of truth returning a structured report payload for both JSON summary preview and PDF Blade rendering (zero DB writes/mutations during report generation).
   - **Period Resolution (`Asia/Manila` -> `UTC`)**:
     - Weekly: `$anchor = CarbonImmutable::parse($weekDate, 'Asia/Manila')`; `$startLocal = $anchor->startOfWeek(CarbonInterface::MONDAY)->startOfDay()`, `$endLocal = $anchor->endOfWeek(CarbonInterface::SUNDAY)->endOfDay()`.
     - Monthly: `$startLocal = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'Asia/Manila')->startOfDay()`, `$endLocal = $startLocal->endOfMonth()->endOfDay()`.
     - Computes `is_partial_period = $endLocal->greaterThan(CarbonImmutable::now('Asia/Manila'))` (and `effective_end_local` for days elapsed calculation in `incidents per day` vs full period label).
   - **Query & Relationship Loading (No N+1)**:
     - Queries `EmergencyReport` where `reported_at` is between `[$startLocal->utc(), $endLocal->utc()]` with eager-loaded `incidentType`, `team.members.user`, `driver`, `trip`, `form`.
     - Batch-loads `vehicle_tracking_sessions` (`with('vehicle')` / joined `vehicles`) for the report IDs in one query, `vehicles` (all current fleet vehicles for Section 6 current availability/maintenance inventory), and `maintenance_reports` (created/open in period or open currently) with `vehicle`.
   - **Exact Metric & Formula Safeguards**:
     - Incident counts are strictly **incident-distinct** (`EmergencyReport.id`), preventing double-counting when multiple team members, tracking sessions, or maintenance events exist.
     - Time deltas (`dispatch_delay_minutes`, `response_travel_minutes`, `operation_duration_minutes`) only calculate when both timestamps exist and the delta is non-negative (`>= 0`); invalid/incomplete rows show `"Not Recorded"` and report `(n = valid / total)`.
     - Completion rate: `completed_count / max(1, total_incidents)` (`N/A` if `total_incidents === 0`).
     - Fuel consumption (`fuelUsedDuringTrip`) and fuel purchased (`purchasedDuringTrip`) are totaled separately only from numeric values in compatible liter units, tracking valid form count `n`.
   - **Deterministic Observations (Section 8)**:
     - Built strictly from verified counts, top categories, valid timing averages, recorded fuel/distance totals, open vehicle maintenance flags, and explicit data-completeness notices (e.g., how many incidents lacked `started_at` or `ReportForm`). Never invents causes or unsupported recommendations.
3. **`app/Http/Controllers/Dispatcher/ReportController.php` & `routes/web.php`**
   - Keep `index()` and existing report workflow endpoints intact.
   - Add two endpoints under `Route::middleware(['auth', 'role:dispatcher'])->prefix('dispatcher')->name('dispatcher.')`:
     - `GET dispatcher/reports/operations/summary` (`dispatcher.reports.operations.summary`): returns validated period metadata (`start_date`, `end_date`, `is_partial_period`, `period_label`) and executive KPIs + record count + availability checks (read-only JSON for instant period confirmation & empty-period feedback before/alongside PDF rendering).
     - `GET dispatcher/reports/operations/pdf` (`dispatcher.reports.operations.pdf`): generates A4 PDF via DomPDF (`setPaper('a4', 'portrait')`, `isPhpEnabled => true` only for page numbering script or native CSS `@page` counters with `Times-Roman` font), returning binary PDF with:
       - `Content-Type: application/pdf`
       - `Content-Disposition: inline; filename="..."` when `mode=preview`, or `attachment; filename="..."` when `mode=download`
       - `Cache-Control: no-store, no-cache, must-revalidate, private` and `X-Content-Type-Options: nosniff` (prevents unauthorized caching of patient/incident data).
   - Provide `defaultReportMeta` props in `Dispatcher\ReportController::index()` so the page initializes with the current `Asia/Manila` week (Mon–Sun) and current month/year immediately.

---

## 6. Proposed Frontend Changes (`resources/js/Pages/Dispatcher/Reports/Index.tsx`)

Integrate the Weekly & Monthly Emergency Operations PDF Generator directly into the existing **Dispatcher → Reports** page while preserving the existing **Emergency Reports** and **Driver Forms** historical tables:

1. **Page Header & Mode Organization**:
   - Keep `AppLayout` and `PageHeader`. Offer three clean tabs (or a dedicated **"Operations PDF Reports"** generator panel/tab alongside **"Emergency Reports"** and **"Driver Forms"**) using existing `Tabs` / `Button` / `Card` components so dispatchers stay on `/dispatcher/reports` without breaking existing workflows.
2. **Weekly & Monthly Reporting Controls**:
   - **Report Type Toggle**: `Weekly Report` (Monday–Sunday, `Asia/Manila`) | `Monthly Report` (Calendar Month & Year, `Asia/Manila`).
   - **Period Pickers**:
     - Weekly: Week anchor date / ISO week selector (supports any historical or current week) that snaps immediately to **Monday – Sunday (`Asia/Manila`)** and clearly displays the exact computed date range (`e.g., Nov 09, 2026 (Mon) – Nov 15, 2026 (Sun) PHT`) **before generation**.
     - Monthly: Month selector (`January`–`December`) + Year selector (historical & current years) showing exact bounds (`Nov 01, 2026 – Nov 30, 2026 PHT`).
   - **Partial Period Indicator**: Automatically badges current incomplete week/month (e.g. `Partial Reporting Period — In Progress as of ... PHT`).
   - **Privacy Control**: Clear option for `Include Full Patient Names & Details (Authorized View)` vs `Masked Patient Privacy Mode (Recommended Default)` with role-authorized notice.
3. **Pre-Generation Summary, Preview & Download Workflow**:
   - Fetches `dispatcher.reports.operations.summary` cleanly (via `fetch` with `Accept: application/json`, abort controller for stale requests) to preview key period counts and warn clearly when `0` incidents exist (still allowing generation of a valid empty formal report showing `"No Records Available"`).
   - **Actions**:
     - **Preview PDF**: Requests `dispatcher.reports.operations.pdf?mode=preview` via `fetch` returning a PDF `Blob`, creates an object URL, and displays an inline embedded `<iframe src={blobUrl}>` PDF previewer inside a clean card (with a full-tab fallback button and automatic `URL.revokeObjectURL` cleanup). Using `fetch` + `Blob` enables reliable loading indicators, exact HTTP error handling (`Alert`), and **strict duplicate-request prevention** (disables both Preview & Download buttons while `isGenerating` is true).
     - **Download PDF**: Triggers download via `dispatcher.reports.operations.pdf?mode=download` (blob download with deterministic filename e.g. `emergency-operations-report-weekly-2026-11-09_to_2026-11-15.pdf`), protected by the same in-flight lock.

---

## 7. Formal A4 PDF Design & 8-Section Structure

### Document Styling (`resources/views/reports/emergency-operations-pdf.blade.php`)
- **Paper & Layout**: A4 (`210mm x 297mm`), Portrait default (`15mm` top/bottom margins + header/footer clearance, `14mm` left/right margins), pure white background, black `#000000` primary text and crisp `#333333` muted borders (`1px solid #222`).
- **Typography**: `Times-Roman` / `'Times New Roman', Times, serif`, `10pt–11pt` body copy, `9pt–9.5pt` dense operational tables, uppercase numbered section headings (`12pt–13pt` bold with thin bottom rule).
- **Pagination & Headers/Footers**:
  - Consistent header band on pages (`EMERGENCY OPERATIONS REPORT | Weekly/Monthly | Reporting Period`).
  - Footer displaying generated timestamp (`Asia/Manila`), confidentiality/privacy classification, and `Page X of Y` (rendered cleanly via DomPDF `page_script` / PHP canvas pagination).
  - Table `thead { display: table-header-group; }` so headers repeat across page breaks, and `tr { page-break-inside: avoid; }` on table rows and section summary blocks.
- **Empty & Missing Data Standard**:
  - Sections without period records render a structured formal placeholder box/row: `"No Records Available for This Reporting Period."`
  - Unrecorded timestamps or attributes print `"Not Recorded"` or `"N/A"`.

### Standardized 8-Section Contents
1. **Section 1: Report Information**
   - Organization Name (`Municipal Disaster Risk Reduction and Management Office (MDRRMO)`), Report Title (`EMERGENCY OPERATIONS REPORT`), Report Type (`Weekly` / `Monthly`) + Partial Period badge if applicable, Reporting Period (`Start – End`, `Asia/Manila`), Date & Time Generated (`Asia/Manila`), Generated By (`Authorized Dispatcher Full Name & Email`), Totals: Recorded Incidents, Completed Operations (`resolved`), Pending / Ongoing (`pending`, `acknowledged`, `dispatched`), Cancelled / Withdrawn (`invalid` with `withdrawn_at`), Invalidated / Unsuccessful (`invalid` without `withdrawn_at`).
2. **Section 2: Executive Summary**
   - Concise statistical grid & category breakdown table: total incidents received, total dispatch operations initiated (with trip/dispatch record), completed responses, withdrawn vs invalidated counts, incident count & share per `IncidentType`, average dispatch delay (`n` valid records), average response travel time (`n` valid records), distinct vehicles deployed, distinct response personnel/drivers involved, total patients recorded.
3. **Section 3: Emergency Incident Reports**
   - Category summary table (`IncidentType`, priority, count, completed, pending, invalid/withdrawn) followed by chronological incident register: Incident Reference (`ER-XXXX` / ID), Type & Priority/Severity, Location (coordinates fallback noted if address sparse), Occurrence Time (`Not Recorded` if absent) vs Reported Time (`reported_at`), Assigned Team, Assigned Driver & Responder Roster, Status, Final Outcome, Remarks / Withdrawal Reason.
4. **Section 4: Dispatch and Response Timelines**
   - Per-dispatched/recorded-trip incident: Incident Ref, Vehicle & Plate (resolved from tracking session / trip / form or `"Not Recorded"`), Driver & Responders, Time Received (`reported_at`), Dispatch / Trip Start (`started_at`, plus `acknowledged_at` noted cleanly), Arrival on Scene (`on_scene_at`), Operation Completed (`resolved_at`), Unit Returning (`returning_at`) & Return-to-Base Arrival (`Not Recorded` unless captured), Calculated Dispatch Delay, Calculated Response Travel Time, Calculated Operation Duration, Final Dispatch Status.
5. **Section 5: Patient and Treatment Records**
   - Per-patient record linked to Incident Ref: Patient Name (masked e.g. `J. D.` by default or full name when authorized dispatcher requests detailed clinical view), Age, Sex/Gender, Initial Condition / Assessment (`assessmentComment` / `victim_info` note), Initial Vital Signs (BP, Pulse, Respiration), First Aid / Scene Treatment (`treatmentManagement`, allergies/meds note), Transport Status (`Not Recorded` when unsupported), Hospital Destination (`dispatch_trips.destination` / `placeVisitedInspected` or `"Not Recorded"`), Hospital Endorsement/Acceptance (`Not Recorded` when unsupported), Remarks. Privacy notice printed directly in Section 5 header.
6. **Section 6: Vehicle Utilization and Logistics**
   - Explicitly divided into:
     - **(A) Historical Utilization During Reporting Period**: Incident/Trip Ref, Vehicle ID/Name/Type/Plate, Assigned Driver, Fuel Beginning (`balanceInTank`), Fuel End (`remainingFuel`), Fuel Purchased (`purchasedDuringTrip`), Recorded Fuel Consumption (`fuelUsedDuringTrip`), Starting Odometer (`Not Recorded`), Ending Odometer (`Not Recorded`), Recorded Trip Distance (`distance_km`), Logistics Remarks (`gearOilUsed`, destination).
     - **(B) Period Deployment & Logistics Summary**: Deployments per vehicle, most frequently deployed vehicle(s), total recorded trip distance (`km`), total recorded fuel purchased (`L`), total recorded fuel consumed (`L`, `n` valid forms), and a clear **Schema Note** separating odometer distance math (`Not Recorded`) from logged `distance_km`.
     - **(C) Current Fleet Availability & Maintenance Status (As of Report Generation)**: Current status (`available`, `en-route`, `on-scene`, `returning`, `offline`, `maintenance`), `maintenance_warning` flag, open `maintenance_reports` count & latest issue summary—explicitly distinguished from historical period activity.
7. **Section 7: Operational Performance Analysis**
   - Defined formulas and denominators printed explicitly in a structured KPI table:
     - Incidents per day (`total / days_in_scope`, using elapsed days for partial periods)
     - Incidents by category
     - Average dispatch delay (`sum(started_at - reported_at) / n_valid`, `n = X`)
     - Average response travel time (`sum(on_scene_at - started_at) / n_valid`, `n = X`)
     - Average operation duration (`sum(resolved_at - reported_at) / n_valid`, `n = X`)
     - Completion rate (`resolved / total_incidents`)
     - Deployments per tracked/form-linked vehicle
     - Personnel deployment frequency (by driver & team responder appearances across distinct period incidents)
     - Incidents with destination/patient transport recorded vs unrecorded (`Not Recorded`)
     - Cancelled/withdrawn vs invalidated responses
8. **Section 8: Conclusions and Operational Observations**
   - Strictly deterministic, metric-bound factual paragraphs summarizing observed volume, leading categories, valid timing averages & timestamp completeness rates, vehicle/fuel/maintenance flags recorded during the period, and factual items flagged for administrative review (e.g., pending/open reports, vehicles flagged for maintenance, or incomplete dispatch/patient form documentation).

---

## 8. Comprehensive Test Plan (`tests/Feature/DispatcherOperationsPdfReportTest.php`)

Before marking the feature complete, automated PHPUnit feature tests + TypeScript/Vite build + Pint formatting will verify:
1. **Authorization & Privacy**: Guests, residents, drivers, and admins are denied (`403` or login redirect) from `dispatcher.reports.operations.summary` and `dispatcher.reports.operations.pdf`; authorized active dispatchers receive `200`, `application/pdf`, valid `%PDF-` header, and `Cache-Control: no-store`. Masked vs detailed patient privacy mode behaves as expected and leaves zero files in `storage/app/public`.
2. **Weekly Reporting (Mon–Sun `Asia/Manila` & UTC Boundaries)**: Boundary events at `2026-11-01 00:30:00 +08:00` (`2026-10-31 16:30:00 UTC`) and Sunday `23:59:59 +08:00` are included/excluded in the exact `Asia/Manila` week; current incomplete week renders `Partial Reporting Period`.
3. **Monthly Reporting**: Historical month vs partial current month filtering and period labels.
4. **Empty Period Handling**: Generates clean summary & valid PDF containing `"No Records Available"` and `N/A` rates without division-by-zero errors.
5. **Incomplete Timestamps & Denominators**: Incidents missing `started_at`, `on_scene_at`, `returning_at`, or `resolved_at` render `"Not Recorded"` and are excluded from timing averages with exact `(n = X)` denominators.
6. **Anti-Double-Counting**: Incidents with multiple team responders, tracking sessions, and maintenance reports count exactly once in incident/category/status totals.
7. **Vehicle, Fuel & Odometer Accuracy**: Historical period utilization via `vehicle_tracking_sessions` / `ReportForm` vs current fleet status/maintenance are separated; fuel purchased vs fuel consumption are never conflated; missing odometer fields render `"Not Recorded"`.
8. **Read-Only Guarantee & Multi-Page Generation**: Report generation mutates zero operational tables and completes cleanly with 60+ incidents spanning multiple A4 pages.
