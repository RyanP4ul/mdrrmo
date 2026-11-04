# Driver / Responder Module

Pages are protected by `auth` and `role:driver/responder`.

```mermaid
flowchart TD
    Driver[/driver] --> Assignments[/driver/assignments]
    Driver --> Reports[/driver/reports]
    Driver --> Tracking[/driver/tracking]
    Driver --> Active[Active assignments]
    Active -->|Advance status| Status[PATCH driver.assignments.status]
    Active -->|Fill patient report| Reports
    Reports --> Form[Patient care report dialog]
    Form -->|Save| Save[PUT driver.reports.form]
    Tracking -->|Every 15 seconds| Reload[Reload location prop]
```

| Page | Source | Interaction |
|---|---|---|
| Dashboard | `Pages/Driver/Dashboard.tsx` | Progresses assignment through response statuses and links to patient reports. |
| Assignments | `Pages/Driver/Assignments.tsx` | Read-only paginated assignment table. |
| Patient Reports | `Pages/Driver/Reports.tsx` | Opens a dialog, edits driver/emergency/vital-sign fields, saves via PUT. |
| Tracking | `Pages/Driver/Tracking.tsx` | Displays location and automatically reloads `location` every 15 seconds. |

Server validation for assignment status is in `Driver\DriverController::updateStatus`; patient report validation is delegated to `SavePatientCareReportRequest`.
