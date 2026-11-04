# Admin Module

Admin pages are protected by `auth` and `role:admin` in `routes/web.php`.

## Feature map

```mermaid
flowchart TD
    Admin[/admin] --> Reports[/admin/reports]
    Reports --> Report[Report detail]
    Admin --> Users[/admin/users]
    Users --> UserDialog[Create/edit/detail/review]
    Admin --> Teams[/admin/response-teams]
    Teams --> TeamDialog[Read-only team roster]
    Admin --> Types[/admin/incident-types]
    Types --> TypeDialog[Create/edit]
    Types --> TypeDelete[Confirm delete]
    Admin --> Announcements[/admin/announcements]
    Announcements --> AnnouncementDialog[Create/edit]
    Announcements --> AnnouncementDelete[Confirm delete]
    Admin --> Audit[/admin/audit-logs]
```

## Page and request inventory

| Page | Component | Main interactions |
|---|---|---|
| Dashboard | `Pages/Admin/Dashboard.tsx` | Read-only statistics, incident chart, heatmap, recent activity. |
| Reports | `Pages/Admin/Reports/Index.tsx` | Filter status/type/priority and open report details. |
| Report detail | `Pages/Admin/Reports/Show.tsx` | Change priority/status via PATCH `admin.reports.update`; view location and patient report. |
| Users | `Pages/Admin/Users/Index.tsx` | Search/filter, create/edit/delete accounts, accept/decline resident registrations. |
| Response teams | `Pages/Admin/ResponseTeams.tsx` | Filter and inspect team roster in a dialog. |
| Incident types | `Pages/Admin/IncidentTypes/Index.tsx` | Search/filter, create/edit/delete types. |
| Announcements | `Pages/Admin/Announcements/Index.tsx` | Search/filter, create/edit/delete announcements with optional image. |
| Audit logs | `Pages/Admin/AuditLogs.tsx` | Filter and paginate audit history. |

All mutation success messages are returned by the corresponding controller and displayed by `AppLayout` toast handling.
