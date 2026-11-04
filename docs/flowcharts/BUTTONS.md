# Button and Interactive Control Inventory

This inventory covers application-level buttons and links. Primitive controls in `resources/js/components/ui` are documented through their consuming page rather than repeated for every Radix wrapper.

## Global and authentication controls

| Control | Location | Handler / action | Result and states |
|---|---|---|---|
| Sign In | `Pages/Login.tsx` `handleSubmit` | `form.post(route("api.auth.login"))` | Disabled while `form.processing`; success enters role dashboard; validation/auth errors remain on login. |
| Forgot Password? | `Pages/Login.tsx` | No handler | **Incomplete placeholder**; no route or API call. |
| Password visibility eye | `Pages/Login.tsx` | No handler | **Incomplete placeholder**; input remains `type="password"`. |
| Register | `Pages/Login.tsx` | No handler | **Incomplete placeholder**; no web registration route. |
| Role navigation links | `Layouts/AppLayout.tsx` `NavList` | Inertia `<Link href={href}>` | Navigates to the role-specific pages; active item uses `aria-current`. |
| Sign out | `Layouts/AppLayout.tsx` footer | `<Link href="/logout" method="post" as="button">` | POSTs `logout`; returns to login. |
| Open menu | `Layouts/AppLayout.tsx` | `setOpen(true)` | Opens mobile `Sheet`; selecting a link closes it. |

## Admin controls

| Control | Source / handler | Request |
|---|---|---|
| Add type, edit type, delete type, cancel, create/save | `Pages/Admin/IncidentTypes/Index.tsx`: `openAdd`, `openEdit`, `submit`, `ConfirmDialog` | POST/PUT `admin.incident-types.store/update`; DELETE `admin.incident-types.destroy` |
| Add announcement, Edit, Delete, cancel, Publish/Save | `Pages/Admin/Announcements/Index.tsx`: `openAdd`, `openEdit`, `submit` | POST/POST-with-`_method=put` `admin.announcements.store/update`; DELETE `admin.announcements.destroy` |
| Add/edit user, delete user, accept/decline resident | `Pages/Admin/Users/Index.tsx`: `openForm`, `save`, `review`, delete callback | POST/PUT `admin.users.store/update`; DELETE `admin.users.destroy`; PATCH `admin.residents.review` |
| Change report priority/status | `Pages/Admin/Reports/Show.tsx`: `upd` | PATCH `admin.reports.update` |
| Back to reports | `Pages/Admin/Reports/Show.tsx` | Inertia link to `admin.reports.index` |
| View response team | `Pages/Admin/ResponseTeams.tsx` | Opens team dialog from row / eye control |

## Dispatcher controls

| Control | Source / handler | Request or result |
|---|---|---|
| Select incoming report | `Pages/Dispatcher/Dashboard.tsx` `setSelId` | Loads selected report into dispatch form; no request. |
| Cancel selected report form | `Dispatcher/Dashboard.tsx` `onDone` | Clears selection. |
| Mark invalid | `Dispatcher/Dashboard.tsx` | PATCH `dispatcher.reports.invalid` |
| Create & dispatch / Dispatch team | `Dispatcher/Dashboard.tsx` `form.post` | POST `dispatcher.dispatch.store`; disabled while processing or no auto team. |
| Back to operations | `Dispatcher/Reports/Show.tsx` | Link to `dispatcher.dashboard` |
| Acknowledge report | `Dispatcher/Reports/Show.tsx` | PATCH `dispatcher.reports.acknowledge` |
| Dispatch / Re-assign team | `Dispatcher/Reports/Show.tsx` | POST `dispatcher.reports.assign` with `team_id`. |
| Choose route recommendation | `Dispatcher/Reports/Show.tsx` `setRoute` | Local selection only. |
| Previous/next month | `Dispatcher/Schedule.tsx` `go` | GET `dispatcher.schedule.index` with `month` and optional status. |
| Calendar day | `Dispatcher/Schedule.tsx` `setDay` | Filters visible queue locally. |
| Confirm schedule | `Dispatcher/Schedule.tsx` `act` | PATCH `dispatcher.schedule.acknowledge`. |
| Decline schedule | `Dispatcher/Schedule.tsx` | Opens reason dialog, then PATCH `dispatcher.schedule.decline`; disabled until reason is nonblank. |
| New/edit/delete team, schedule shift, add/remove member | `Dispatcher/Responders.tsx` | POST/PUT/DELETE responders and POST/PATCH shifts routes. |
| Shift status select | `Dispatcher/Responders.tsx` | PATCH `dispatcher.shifts.update`. |
| Select driver on map | `Dispatcher/Map.tsx` | Local selection; map refresh uses `router.reload` for `drivers`, `reports`, `snapshotAt`. |

## Driver/responder controls

| Control | Source / handler | Request |
|---|---|---|
| Assignment progress button | `Pages/Driver/Dashboard.tsx` `advance` | PATCH `driver.assignments.status` with `response_status`; preserves scroll. |
| Fill patient report / Edit form | `Pages/Driver/Dashboard.tsx`, `Pages/Driver/Reports.tsx` `open` | Navigates to reports or opens the patient report dialog. |
| Close patient report | `Pages/Driver/Reports.tsx` | Clears dialog selection. |
| Save report | `Pages/Driver/Reports.tsx` | PUT `driver.reports.form`; disabled while processing or no report. |
| Tracking refresh | `Pages/Driver/Tracking.tsx` | Automatic `router.reload({ only: ['location'] })` every 15 seconds; no manual button. |

## Resident controls

| Control | Source / handler | Request |
|---|---|---|
| Report an emergency | `Pages/Resident/Dashboard.tsx` | Link to `resident.report`. |
| Book a service / Notifications / View all | `Pages/Resident/Dashboard.tsx` | Links to resident schedule, notifications, and announcements. |
| Send report | `Pages/Resident/Report.tsx` | POST `resident.report.store`; disabled while processing. |
| Use my location | `components/maps/LocationPicker.tsx` `locate` | Browser geolocation; updates latitude/longitude locally. |
| Submit request | `Pages/Resident/Schedule.tsx` | POST `resident.schedule.store`; resets form on success. |
| Cancel request | `Pages/Resident/Schedule.tsx` | PATCH `resident.schedule.cancel`. |
| Mark all as read | `Pages/Resident/Notifications.tsx` | PATCH `resident.notifications.read` with no notification id. |
| Notification row | `Pages/Resident/Notifications.tsx` | PATCH `resident.notifications.read/{id}` only when unread. |

## Incomplete controls found in source

`components/app-header.tsx` has a mobile menu button without an `onClick`, notification dropdown items without navigation/read handlers, profile/settings items without handlers, and a logout item with a commented-out handler. `components/app-sidebar.tsx` uses placeholder page strings, hard-codes `isActive = false`, and has a logout button without an action. These components are not wired into `AppLayout.tsx`.
