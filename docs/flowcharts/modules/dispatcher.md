# Dispatcher Module

Dispatcher pages are protected by `auth` and `role:dispatcher`.

```mermaid
flowchart TD
    Ops[/dispatcher] --> Incoming[Select incoming report]
    Incoming --> DispatchForm[Review dispatch form]
    DispatchForm -->|Create & dispatch| Create[POST dispatcher.dispatch.store]
    DispatchForm -->|Dispatch selected| Create
    DispatchForm -->|Mark invalid| Invalid[PATCH dispatcher.reports.invalid]
    Ops --> Reports[/dispatcher/reports]
    Reports --> Show[/dispatcher/reports/{report}]
    Show -->|Acknowledge| Ack[PATCH reports.acknowledge]
    Show -->|Assign team| Assign[POST reports.assign]
    Ops --> Map[/dispatcher/map]
    Map --> Live[Reload driver/report snapshot]
    Ops --> Teams[/dispatcher/responders]
    Teams --> TeamForm[Create/edit team and shifts]
    Ops --> Schedule[/dispatcher/schedule]
    Schedule -->|Confirm| Confirm[PATCH schedule.acknowledge]
    Schedule -->|Decline| Reason[Reason dialog]
    Reason --> Decline[PATCH schedule.decline]
    Ops --> Announcements[/dispatcher/announcements]
```

## Components and source methods

- `Pages/Dispatcher/Dashboard.tsx`: `DispatchForm`, `setSelId`; combines phone-call entry with automatic team dispatch.
- `Pages/Dispatcher/Reports/Index.tsx`: server filters and report links.
- `Pages/Dispatcher/Reports/Show.tsx`: acknowledge, assign/reassign, route recommendation selection.
- `Pages/Dispatcher/Responders.tsx`: `openForm`, `setMember`, `save`; team CRUD and shift scheduling/status.
- `Pages/Dispatcher/Map.tsx`: `DispatchMapRealtime`, driver selection, periodic snapshot reload.
- `Pages/Dispatcher/Schedule.tsx`: month navigation, day filtering, confirm and decline dialog.
- `Pages/Shared/Announcements.tsx`: read-only announcement feed.

Web endpoints are defined by `Dispatcher\DashboardController`, `ReportController`, `ResponderController`, `MapController`, and `ScheduleController`.
