# Mobile API Surface

No mobile application pages were found in `resources/js`. The following API routes in `routes/api.php` are available to mobile clients and are included so the UI documentation does not imply they are web-page interactions.

## Authentication and account

| Endpoint group | Access / purpose |
|---|---|
| `/api/auth/mobile/login`, `/register`, `/forgot-password`, `/reset-password`, `/policy` | Public mobile authentication and policy |
| `/api/auth/mobile/account`, `/avatar`, `/password`, `/deletion-request`, `/me`, `/logout` | Sanctum mobile session; account/profile lifecycle |

## Driver/responder

`/api/driver-responder` requires Sanctum, a mobile session, `role:driver/responder`, and endpoint abilities. It exposes bootstrap, assignments, history, status/availability/profile updates, incident types, reports, teams, notifications, patient/trip records, realtime configuration, fleet data, and broadcasting authentication.

Tracking is available under `/api/driver-responder/tracking` and `/api/driver-responder/location`; location reads are available at `/api/driver-responder/locations`.

## Resident

`/api/resident` requires Sanctum, a mobile session, and `role:resident`. It exposes bootstrap, reports, schedules, notifications, report creation/update/withdrawal, schedule creation/update/cancel, and mark-read operations.

## Operations and administration

`/api/operations` requires `role:admin,dispatcher` plus staff abilities. It exposes dashboards, reports, schedules, teams, drivers, incident types, locations, and staff mutations. Admin-only `admin-manage` routes cover accounts, audit logs, automatic dispatch, announcements, and incident types.

```mermaid
flowchart TD
    Mobile[Mobile client] --> Auth[Mobile auth]
    Auth -->|Sanctum session| Role{Role}
    Role -->|resident| ResidentAPI[/api/resident]
    Role -->|driver/responder| DriverAPI[/api/driver-responder]
    Role -->|admin/dispatcher| OpsAPI[/api/operations]
    DriverAPI --> Tracking[/tracking and location]
    OpsAPI --> AdminOnly{Admin ability}
    AdminOnly -->|yes| AdminAPI[Accounts, audit, catalogs]
    AdminOnly -->|no| StaffRead[Staff read/write operations]
```

Controller validation and ability middleware are defined directly in `routes/api.php` and the `Mobile*Controller` classes. These endpoints are not called by the current web React pages.
