# MDRRMO UI Flowchart Documentation

This directory documents the implemented Laravel/Inertia React web UI and the related mobile API surface. Documentation is based on the source under `resources/js`, `routes`, and `app/Http/Controllers`; controls that exist visually but have no handler are marked **incomplete**.

## Documentation map

| Document | Scope |
|---|---|
| [NAVIGATION.md](./NAVIGATION.md) | Pages, route names, role gates, and navigation flows |
| [BUTTONS.md](./BUTTONS.md) | Buttons, links, handlers, requests, and states |
| [FORMS.md](./FORMS.md) | Forms, fields, validation, and submissions |
| [MODALS.md](./MODALS.md) | Dialogs, sheets, confirmations, and cancel flows |
| [Authentication](./modules/authentication.md) | Login and session entry/exit |
| [Admin](./modules/admin.md) | Administration dashboard and management screens |
| [Dispatcher](./modules/dispatcher.md) | Dispatch operations, reports, teams, map, and schedule |
| [Driver / responder](./modules/driver-responder.md) | Assignments, patient reports, and tracking |
| [Resident](./modules/resident.md) | Emergency reports, service requests, history, and notifications |
| [Shared](./modules/shared.md) | Announcements and reusable layout/navigation behavior |
| [Mobile API](./modules/mobile-api.md) | API endpoints used by mobile clients; no mobile UI is present in this repository |

## Application overview

The web application is an authenticated role-based MDRRMO operations system. `/` renders `resources/js/Pages/Login.tsx`; successful login posts to `api.auth.login`, then `/dashboard` redirects by `auth()->user()->role` to the appropriate role dashboard. Authenticated pages use `resources/js/Layouts/AppLayout.tsx`.

```mermaid
flowchart TD
    Start([Open /]) --> Login[Login page]
    Login -->|Submit credentials| AuthAPI[POST /api/auth/login]
    AuthAPI -->|Success| Dashboard[/dashboard]
    AuthAPI -->|Validation or auth error| LoginError[Login errors]
    Dashboard --> Role{User role}
    Role -->|admin| Admin[/admin]
    Role -->|dispatcher| Dispatcher[/dispatcher]
    Role -->|driver/responder| Driver[/driver]
    Role -->|resident| Resident[/resident]
    Admin --> AdminNav[Admin navigation]
    Dispatcher --> DispatchNav[Dispatcher navigation]
    Driver --> DriverNav[Driver navigation]
    Resident --> ResidentNav[Resident navigation]
    AdminNav --> Logout[Sign out]
    DispatchNav --> Logout
    DriverNav --> Logout
    ResidentNav --> Logout
    Logout -->|POST /logout| Login
```

## Cross-cutting behavior

- Web role middleware is applied in `routes/web.php`: `admin`, `dispatcher`, `driver/responder`, or `resident`.
- Inertia submissions use `useForm`, `router.get`, `router.patch`, `router.post`, `router.put`, or `router.delete`.
- Server success messages are shown by the `flash.success` toast in `AppLayout`; server errors appear as field errors where the page renders them.
- `AppLayout` contains the authoritative role-specific navigation. `components/app-header.tsx` and `components/app-sidebar.tsx` also exist, but several of their controls are placeholders and are not used by `AppLayout`.
- No mobile frontend pages were found under `resources/js`; the mobile functionality is represented by API routes in `routes/api.php`.

## Source conventions

References use repository-relative paths and include the relevant component or controller method. The route inventory is derived from `routes/web.php` and `routes/api.php`; UI behavior is derived from the page/component event handlers.
