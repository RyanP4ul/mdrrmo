# Resident Module

Resident pages are protected by `auth` and `role:resident`.

```mermaid
flowchart TD
    Home[/resident] --> Report[/resident/report]
    Report -->|Send report| ReportAPI[POST resident.report.store]
    ReportAPI --> History[/resident/history]
    Home --> Schedule[/resident/schedule]
    Schedule -->|Submit request| ScheduleAPI[POST resident.schedule.store]
    Schedule -->|Cancel pending/confirmed| Cancel[PATCH resident.schedule.cancel]
    Home --> Notifications[/resident/notifications]
    Notifications -->|Mark one/all read| Read[PATCH notifications.read]
    Home --> Announcements[/resident/announcements]
```

## Pages

- `Pages/Resident/Dashboard.tsx`: emergency shortcut, service shortcut, notification count, announcement preview.
- `Pages/Resident/Report.tsx`: incident type, victim/witness, address, map location, description; posts to `resident.report.store`.
- `Pages/Resident/History.tsx`: paginated reports, read-only.
- `Pages/Resident/Schedule.tsx`: service/date/time/address/notes form and request list; cancel is available for pending or confirmed requests.
- `Pages/Resident/Notifications.tsx`: paginated notifications; unread rows and “Mark all as read”.
- `Pages/Shared/Announcements.tsx`: public/read-only announcement feed.

`LocationPicker` uses browser geolocation for “Use my location” and otherwise emits map coordinate changes locally. Server validation is in `Resident\ResidentController::storeReport` and `storeSchedule`.
