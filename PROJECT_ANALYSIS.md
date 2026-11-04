# MDRRMO Project Analysis

**Analysis date:** 2026-11-02  
**Scope:** Laravel website, Flutter mobile application, shared backend/database  
**Implementation status:** Website-only implementation phase completed. Mobile-specific, deployment-dependent, and long-term shared-system items remain as marked below.

## Status Legend

- **NOT IMPLEMENTED** — No implementation or control was found.
- **PARTIALLY** — Some implementation exists, but it is incomplete or inconsistent.
- **COMPLETED** — The control is implemented sufficiently based on the inspected source.

---

# 1. Project Overview

## System components

| Component | Location | Technologies
|---|---|---|---|
| Website | `mdrrmo\` | Laravel 13, PHP, Inertia.js, React, TypeScript, Vite, Tailwind, MapLibre, Recharts
| Mobile application | `mdrrmo_flutter\` | Flutter, Dart, Dio, `http`, Sanctum bearer tokens, secure storage
| Backend API | `mdrrmo\app\Http\Controllers\*`, `routes\api.php` | Laravel JSON endpoints and Sanctum
| Database | `mdrrmo\database\migrations\` | PostgreSQL in current `.env`; SQLite artifacts also exist
| Real-time communication | `app\Events\VehicleLocationUpdated.php`, Reverb | Laravel Reverb/Echo, with map polling fallback

## Shared backend responsibilities

- Role-specific accounts are stored in `admins`, `dispatchers`, `drivers`, and `residents`.
- `RoleUserProvider` resolves accounts across those tables.
- `DispatchService` selects response teams and drivers.
- `EmergencyReport` is the central emergency workflow entity.
- Vehicles, teams, shifts, notifications, schedules, hospitals, announcements, and audit logs are modeled with Eloquent.

## Main workflows

1. Web login redirects users to role-specific dashboards.
2. Residents submit emergency reports or service schedules.
3. Dispatchers acknowledge reports and dispatch response teams.
4. Drivers update vehicle and assignment status and submit patient reports.
5. Mobile residents and drivers use Sanctum-protected API endpoints.
6. Dispatchers monitor vehicle and report changes through configured broadcasting, with periodic polling as a fallback.

---

# 2. Website Analysis

## Website architecture

| Area | Main files | Responsibility | Status |
|---|---|---|---|
| Web routes | `routes\web.php` | Role-based browser routes | COMPLETED |
| Authentication | `app\Http\Controllers\WebAuthController.php`, `app\Actions\Auth\LoginAction.php` | Session login/logout | PARTIALLY |
| Admin portal | `app\Http\Controllers\Admin\`, `resources\js\Pages\Admin\` | Users, reports, teams, announcements, audit logs | PARTIALLY |
| Dispatcher portal | `app\Http\Controllers\Dispatcher\`, `resources\js\Pages\Dispatcher\` | Dispatching, map, teams, shifts, schedules | PARTIALLY |
| Driver portal | `app\Http\Controllers\Driver\`, `resources\js\Pages\Driver\` | Assignments, tracking, patient forms | PARTIALLY |
| Resident portal | `app\Http\Controllers\Resident\`, `resources\js\Pages\Resident\` | Reports, schedules, notifications | PARTIALLY |
| Frontend shell | `resources\js\Layouts\AppLayout.tsx` | Navigation, role menus, flash messages | PARTIALLY |

## Website architecture findings

| Severity | File / lines | Finding | Why it matters | Recommended solution | Priority | Status |
|---|---|---|---|---|---|---|
| High | Many `resources\js\` page and component imports | Imports previously used `@/Components` while the directory is lowercase `components`. | Windows may tolerate this, but Linux deployment can fail. | Standardize directory and import casing. | Critical | COMPLETED |
| Medium | `resources\js\app.tsx`; former `resources\js\app.jsx`, `Layouts\panel-layout.tsx` | Two Inertia entrypoints existed; the older entrypoint referenced an undefined provider. | Creates uncertainty about the active frontend architecture. | Keep one authoritative entrypoint and remove dead code. | Medium | COMPLETED |
| Medium | Former `resources\js\Pages\_admin\`; active `resources\js\Pages\Admin\` | Duplicate admin page structures existed, and no server-rendered page referenced the legacy paths. | Developers may modify inactive pages. | Remove or clearly isolate legacy pages. | Medium | COMPLETED |
| High | `app\Http\Controllers\*` | Controllers still combine some validation, authorization, persistence, and presentation concerns, although key report workflows now use Form Requests and services. | Business rules remain difficult to test and reuse consistently. | Continue extracting focused Form Requests, Actions/Services, and Resources. | High | PARTIALLY |
| Medium | Former `app\Http\Middleware\EnsureRole.php`; `app\Http\Middleware\RoleMiddleware.php` | Two role middleware implementations had different behavior; the unused duplicate has been removed. | Authorization behavior could diverge between routes. | Keep one role middleware implementation. | Medium | COMPLETED |
| High | `ResidentController.php`, `Dispatcher\DashboardController.php`; `EmergencyReport.php` | Report references previously used `max(id) + 1`. | Concurrent submissions could generate duplicate references. | Generate random references under the existing unique database constraint. | Immediate | COMPLETED |
| High | `app\Http\Controllers\Dispatcher\ResponderController.php` | Shift member IDs were validated globally rather than for the selected team. | A shift could contain members from another team. | Validate member IDs against the selected team. | High | COMPLETED |
| High | `app\Http\Controllers\Dispatcher\ResponderController.php`; `routes\web.php`; `resources\js\Pages\Dispatcher\Responders.tsx` | Shift update previously accepted a shift ID without validating its team. | A stale or manipulated request could update another team's shift. | Use nested, scoped route binding and verify the parent team. | High | COMPLETED |
| Medium | `app\Http\Controllers\Dispatcher\ScheduleController.php` | `Carbon::parse()` previously received an unvalidated month parameter. | Malformed input could cause a server error. | Validate status and `YYYY-MM` before parsing. | High | COMPLETED |
| High | `app\Http\Controllers\AnnouncementFeedController.php`; `Admin\AnnouncementController.php` | Announcement author was assumed to exist. | Missing historical authors could crash pages. | Display a fallback when an author is unavailable. | High | COMPLETED |
| Medium | `app\Http\Controllers\Admin\EmergencyReportController.php`; `app\Services\EmergencyReportWorkflow.php` | Admin status changes now use a workflow service with transactional side effects. | The workflow maintains vehicle, notification, timestamp, audit, and event consistency. | Keep all website report status changes routed through the service. | High | COMPLETED |
| Medium | `app\Http\Controllers\Dispatcher\ReportController.php`; `app\Services\EmergencyReportWorkflow.php` | Dispatcher report actions now use explicit allowed transitions. | Invalid terminal-state changes are rejected. | Keep transitions centralized and tested. | Medium | COMPLETED |
| Medium | `app\Services\DispatchService.php` | Dispatch, notification, audit, vehicle assignment, and report update now run transactionally with team row locking. | Prevents partial dispatch writes and serializes website team selection. | Add database-backed concurrent dispatch tests before treating cross-database race behavior as fully proven. | Medium | PARTIALLY |
| Medium | `app\Http\Controllers\Driver\DriverController.php` | Vehicle and report status changes now use transactions, row locks, workflow validation, and report-specific vehicle association. | Prevents partial updates and accidental use of another assignment's vehicle. | Keep the transition path centralized and covered by regression tests. | Medium | COMPLETED |
| Medium | `resources\js\Pages\Dispatcher\Map.tsx`; `resources\js\app.tsx` | Map updates use authenticated Reverb/Echo when configured and 15-second polling otherwise. | Real-time transport is optional and the fallback still incurs periodic requests. | Configure Reverb in deployment and retain polling as failover. | High | PARTIALLY |
| High | `app\Http\Controllers\Dispatcher\MapController.php` | Map refresh still returns all located vehicles and active reports, but now selects only required columns and has supporting indexes. | Query/response cost still grows with the full fleet and active incident count. | Add bounded viewport or server-side spatial filtering where product requirements permit. | High | PARTIALLY |
| Medium | `resources\js\app.tsx`; `maplibre-gl` | Inertia pages now load lazily, but the MapLibre production chunk is still about 1.1 MB before gzip. | Map pages remain relatively heavy on slower networks and devices. | Keep map code route-lazy and evaluate MapLibre feature imports or a lighter map client. | Medium | PARTIALLY |
| Medium | `resources\js\Pages\*`; `resources\js\types\website.ts` | Website page props now use shared domain types instead of `any`. | Improves compile-time API protection. | Keep page contracts aligned with controller payloads. | High | COMPLETED |
| Low | Former `app\Http\Requests\UserFilterRequest.php`; `app\Services\UserService.php` | These were confirmed unused by project source and have been removed. | Removes dead code. | Continue checking for dead code before adding new abstractions. | Low | COMPLETED |

## Website security controls

| Control | Evidence | Status |
|---|---|---|
| Web state-changing routes use CSRF protection | Laravel web middleware and Inertia forms | COMPLETED |
| Web logout invalidates the session and regenerates CSRF token | `WebAuthController.php:37-45` | COMPLETED |
| Role-specific route groups exist | `routes\web.php:25-82` | COMPLETED |
| Resident reports and notifications are owner-scoped | `ResidentController.php` | COMPLETED |
| Only approved residents can book services on the website | `ResidentController.php` | COMPLETED |
| Policies are used for resource authorization | `app\Policies\EmergencyReportPolicy.php`, `ServiceSchedulePolicy.php` and controller/Form Request authorization | COMPLETED |
| Browser login has explicit route-level throttling | `routes\web.php`, `throttle:5,1` | COMPLETED |

## Website bugs and reliability

| Severity | File / lines | Problem | Why it matters | Recommended solution | Priority | Status |
|---|---|---|---|---|---|---|
| High | `app\Http\Controllers\Driver\DriverController.php`; `app\Services\EmergencyReportWorkflow.php` | Web driver status transitions now enforce vehicle progression and report assignment rules. | Prevents invalid completion and cross-assignment vehicle changes. | Keep transition and authorization regression tests. | High | COMPLETED |
| Medium | `app\Http\Controllers\Dispatcher\MapController.php`; `resources\js\Pages\Dispatcher\Map.tsx` | Both vehicle and report data refresh from realtime events and the polling fallback. | Map incident state no longer depends on vehicle-only refreshes. | Monitor Reverb delivery and retain the fallback. | Medium | COMPLETED |
| Medium | `app\Http\Controllers\Driver\DriverController.php`; `routes\web.php` | Website GPS requests now validate coordinate ranges and are rate-limited to 30 requests per minute. | Limits invalid coordinates and excessive website writes. | Add monitoring and tune the limit from observed traffic. | Medium | COMPLETED |
| Low | `tests\Feature\WebsiteImprovementTest.php` | Regression tests now cover authorization, malformed inputs, workflow transitions, shift overlap, GPS throttling, and dispatch side effects; frontend and true concurrent-database coverage remain limited. | Some regressions could still escape automated tests. | Add browser-level and database-specific concurrency tests. | High | PARTIALLY |

---

# 3. Mobile Analysis

## Mobile architecture

| Area | Main files | Responsibility | Status |
|---|---|---|---|
| Mobile shell | `mdrrmo_flutter\lib\main.dart`, `screens\` | Login, registration, role selection, resident/driver flows | PARTIALLY |
| Authentication | `lib\services\auth_service.dart` | Registration, login, current-user lookup, logout | NOT IMPLEMENTED |
| API client | `lib\data\api_client.dart` | Dio configuration and bearer-token interceptor | NOT IMPLEMENTED |
| Driver API | `lib\data\api\driver_service.api.dart` | Driver assignments, status, profile, reports | PARTIALLY |
| Resident API | `lib\data\api\resident_service.api.dart` | Resident bootstrap, reports, schedules, notifications | PARTIALLY |
| Driver state | `lib\state\driver_responder_state.dart` | Driver workflow and availability state | PARTIALLY |
| Resident state | `lib\state\resident_state.dart` | Resident workflow and local UI state | PARTIALLY |
| Local storage | `flutter_secure_storage`, `shared_preferences` | Token and preferences storage | PARTIALLY |

## Mobile critical findings

| Severity | File / lines | Finding | Why it matters | Recommended solution | Priority | Status |
|---|---|---|---|---|---|---|
| Critical | `lib\data\api_client.dart:18-25` | Authorization header is set to the literal `'******'`, not the retrieved token. | Authenticated Dio requests cannot authenticate. | Send `Bearer <token>` using the retrieved token. | Immediate | NOT IMPLEMENTED |
| Critical | `lib\services\auth_service.dart:108-113, 133-138` | `getMe()` and `logout()` also send `'******'`. | Session validation and logout are broken. | Send the bearer token consistently. | Immediate | NOT IMPLEMENTED |
| High | `mdrrmo_flutter\.env:1`; `auth_service.dart:8-10`; `api_client.dart:6-9` | Mobile API uses plain HTTP, including authentication. | Credentials and tokens can be intercepted. | Use HTTPS exclusively and remove HTTP production fallbacks. | Immediate | NOT IMPLEMENTED |
| Medium | `mdrrmo_flutter\pubspec.yaml:31-44` | `.env` is packaged as a Flutter asset. | Development endpoints can be embedded in release builds. | Use build-time environment configuration. | Medium | NOT IMPLEMENTED |
| Medium | `lib\services\auth_service.dart:85-91` | Response decoding occurs before robust status/content handling. | Empty or HTML errors become confusing parse failures. | Normalize response and error parsing. | Medium | NOT IMPLEMENTED |
| Medium | `lib\state\driver_responder_state.dart` | Driver state performs network actions but has limited explicit retry/reconciliation behavior. | Temporary failures can leave UI state out of sync. | Add retry policy and server-state refresh after failures. | Medium | PARTIALLY |
| Medium | `lib\state\resident_state.dart` | Local lists are updated optimistically after API calls without a general refresh strategy. | Failed or duplicated operations can make local state stale. | Reconcile mutations with server responses and refresh. | Medium | PARTIALLY |

## Mobile security findings

| Severity | File / lines | Vulnerability | Impact | Recommended solution | Priority | Status |
|---|---|---|---|---|---|---|
| High | `mdrrmo_flutter\.env:1` | Plain HTTP transport. | Network attackers can steal passwords/tokens. | Enforce HTTPS and certificate validation. | Immediate | NOT IMPLEMENTED |
| Medium | `MobileAuthController.php:37-45, 69-80` | Pending residents are created as `active` and can obtain mobile tokens. | Unreviewed users can access the mobile application. | Make pending accounts inactive or require accepted registration status during login. | Immediate | NOT IMPLEMENTED |
| Medium | `MobileResidentApiController.php:99-112` | Mobile schedule creation does not require accepted registration status. | Pending residents can submit service requests. | Require `registration_status === accepted`. | High | NOT IMPLEMENTED |
| High | `routes\api.php:11-12`; `MobileAuthController.php:53-80` | Mobile login and registration have no explicit rate limiting. | Password guessing and registration abuse are easier. | Add IP/account/device-based throttling. | High | NOT IMPLEMENTED |
| Medium | `routes\api.php:23-26` | Authenticated users can create unlimited Sanctum tokens without abilities or visible limits. | Token sprawl increases compromise impact. | Add abilities, expiry, naming controls, and token management. | Medium | NOT IMPLEMENTED |

## Mobile reliability findings

| Severity | File / lines | Problem | Why it matters | Recommended solution | Priority | Status |
|---|---|---|---|---|---|---|
| High | `app\Http\Controllers\MobileDriverApiController.php:83-115` | Vehicle lookup selects the first vehicle for a response team. | The wrong vehicle may be updated when teams have multiple vehicles. | Model explicit driver-to-vehicle assignment. | High | NOT IMPLEMENTED |
| Medium | `app\Http\Controllers\MobileDriverApiController.php:34-35` | Active-report query executes twice during bootstrap. | Avoidable database work on a frequently used endpoint. | Reuse the loaded result. | Low | NOT IMPLEMENTED |
| Medium | `app\Http\Controllers\MobileDriverApiController.php:99-115` | Vehicle/report updates do not use row locks. | Concurrent requests can create invalid states. | Lock rows during transitions. | Medium | NOT IMPLEMENTED |
| Medium | `tests\Feature\MobileApiTest.php` | Mobile tests cover key flows but not token headers, pending-user restrictions, rate limits, retries, or concurrent updates. | Important mobile regressions may go undetected. | Add focused API and client integration tests. | High | PARTIALLY |

---

# 4. Shared Backend and Database Analysis

## Database findings

| Severity | File / lines | Problem | Why it matters | Recommended solution | Priority | Status |
|---|---|---|---|---|---|---|
| High | `database\migrations\2026_10_26_110321_create_mddrmo_db.php:10-436` | One migration creates almost the entire domain and converts account tables. | Rollbacks and production migrations are high-risk. | Split schema and data conversion into smaller forward migrations. | High | NOT IMPLEMENTED |
| High | `database\migrations\2026_11_02_120000_add_website_query_indexes.php` | Targeted composite indexes now cover the website's driver, team, reporter, resident, schedule, vehicle, and status/date query patterns. | These indexes reduce common filtering costs but do not replace query-size limits for map/dashboard data. | Review query plans against production-like data and add indexes only when supported by measured workload. | High | COMPLETED |
| Medium | Same migration: `137-153` | `vehicles.assigned_report_id` is not unique. | Multiple vehicles could claim one report. | Add uniqueness if the domain permits one active vehicle per report. | Medium | NOT IMPLEMENTED |
| Medium | `app\Http\Controllers\Dispatcher\ResponderController.php` | Website shift creation now rejects overlapping shifts while holding a response-team row lock. | Prevents overlapping shifts through the website even though the database has no portable exclusion constraint. | Keep overlap checks in any future shift-writing interface and add database-specific concurrency coverage. | Medium | COMPLETED |
| Medium | Same migration: `40-53` | Duplicate user/team relationships are allowed. | A driver can appear more than once in a team. | Add a unique `(response_team_id, user_id)` constraint. | Medium | NOT IMPLEMENTED |
| Medium | Same migration: `84-129` | Polymorphic account references cannot use normal foreign keys. | Deleted accounts can leave dangling references. | Use explicit foreign keys where possible or integrity cleanup. | Medium | NOT IMPLEMENTED |
| High | Same migration: `181-436` | Rollback reconstructs tables and rewrites polymorphic identifiers. | Rollback is destructive and hard to verify safely. | Prefer forward-compatible migrations and verified backups. | High | NOT IMPLEMENTED |
| Medium | `app\Services\DispatchService.php`; `database\migrations\2026_11_02_120000_add_website_query_indexes.php` | Hospital recommendations now select only required columns, prioritize specialist matches, and progressively query indexed coordinate bounds for nearest non-matches. | Distance calculations still happen in PHP for the bounded candidate set, with a global fallback for remote results. | Monitor query plans and hospital volumes; adopt native spatial types only if measurements justify the added database-specific complexity. | Medium | COMPLETED |
| Medium | `app\Services\DispatchService.php` | Website auto-assignment now locks active response teams in stable order before selecting a least-busy team; database-specific concurrent behavior has not been stress-tested. | Reduces overlapping selections but the guarantee depends on the configured database isolation semantics. | Add PostgreSQL concurrency tests and consider an explicit reservation mechanism if needed. | High | PARTIALLY |

## Shared backend controls

| Control | Status |
|---|---|
| Eloquent relationships model core entities | COMPLETED |
| SQL uses parameterized Eloquent/query-builder operations | COMPLETED |
| Passwords use Laravel hashing | COMPLETED |
| Sanctum token revocation on mobile logout is intended | PARTIALLY |
| Centralized domain workflow services are used consistently | PARTIALLY |
| Resource policies are defined for website reports and schedules | COMPLETED |
| Comprehensive audit coverage exists for all state changes | PARTIALLY |

---

# 5. Overall Scores

| Area | Website | Mobile | Shared backend/database | Status |
|---|---:|---:|---:|---|
| Feature coverage | 7/10 | 6/10 | 7/10 | PARTIALLY |
| Architecture | 5/10 | 5/10 | 5/10 | PARTIALLY |
| Security | 5/10 | 3/10 | 5/10 | PARTIALLY |
| Reliability | 5/10 | 4/10 | 5/10 | PARTIALLY |
| Performance | 5/10 | 5/10 | 5/10 | PARTIALLY |
| Database design | N/A | N/A | 5/10 | PARTIALLY |
| Testing | 4/10 | 4/10 | 5/10 | PARTIALLY |
| Maintainability | 5/10 | 5/10 | 5/10 | PARTIALLY |

## Overall project score: 5.5 / 10

The project has a solid functional foundation and clear role-based workflows, but it is not production-ready without addressing mobile authentication, transport security, approval enforcement, race conditions, inconsistent authorization, migration risk, and deployment portability.

---

# 6. Recommended Roadmap

## Phase 1: Critical fixes

| Task | Area | Priority | Status |
|---|---|---|---|
| Fix Flutter Authorization headers | Mobile | Critical | NOT IMPLEMENTED |
| Enforce HTTPS for mobile API traffic | Mobile | Critical | NOT IMPLEMENTED |
| Prevent pending residents from using protected resident workflows | Website/Mobile | Critical | PARTIALLY |
| Remove default production credentials | Shared | Critical | NOT IMPLEMENTED |
| Standardize website import casing | Website | Critical | COMPLETED |
| Replace race-prone report-reference generation | Website | Critical | COMPLETED |
| Require approval for website service scheduling | Website | Critical | COMPLETED |

## Phase 2: Important improvements

| Task | Area | Priority | Status |
|---|---|---|---|
| Add mobile login and registration rate limiting | Mobile/Backend | High | NOT IMPLEMENTED |
| Add Policies for website report and schedule authorization | Website | High | COMPLETED |
| Validate shift members against their response team | Website/Backend | High | COMPLETED |
| Scope shift updates to the selected response team | Website/Backend | High | COMPLETED |
| Enforce report and vehicle state transitions | Website/Mobile/Backend | High | PARTIALLY |
| Add transactions and row locks to website dispatch operations | Website/Backend | High | COMPLETED |
| Add focused website regression tests | Website | High | COMPLETED |

## Phase 3: Architecture and maintainability

| Task | Area | Priority | Status |
|---|---|---|---|
| Consolidate role middleware | Website | Medium | COMPLETED |
| Extract Form Requests and report workflow services | Website | Medium | PARTIALLY |
| Add API Resources or typed DTOs | Backend/Mobile | Medium | NOT IMPLEMENTED |
| Replace frontend `any` types | Website | Medium | COMPLETED |
| Remove duplicate frontend entrypoints and legacy pages | Website | Medium | COMPLETED |
| Improve migration organization | Shared database | Medium | NOT IMPLEMENTED |

## Phase 4: Performance optimization

| Task | Area | Priority | Status |
|---|---|---|---|
| Replace map polling with Reverb/Echo and retain a fallback | Website/Backend | Medium | PARTIALLY |
| Add targeted website query indexes | Website/Database | Medium | COMPLETED |
| Optimize website dashboard and map payload queries | Website | Medium | PARTIALLY |
| Replace `Hospital::all()` distance calculations with indexed bounding-box candidate searches | Website/Backend | Medium | COMPLETED |
| Add server-side GPS throttling | Website/Mobile/Backend | Medium | PARTIALLY |

## Phase 5: Long-term improvements

| Task | Area | Priority | Status |
|---|---|---|---|
| Introduce stronger dispatch domain modeling | Shared backend | Low | NOT IMPLEMENTED |
| Add production observability and monitoring | Shared | Low | NOT IMPLEMENTED |
| Establish staging and production configuration pipelines | Shared | Low | NOT IMPLEMENTED |
| Add comprehensive frontend and mobile integration tests | Website/Mobile | Low | NOT IMPLEMENTED |

---

## Final Assessment

Website-only work has been verified with the Laravel feature suite, strict TypeScript checking, PHP formatting checks, and a production frontend build. Mobile-specific findings and the shared/mobile issues identified above remain unchanged and outside this phase. The score table reflects the original analysis baseline, not a post-implementation re-score.
