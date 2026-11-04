# Authentication Module

## Sources

- `resources/js/Pages/Login.tsx`: `Page`, `handleSubmit`
- `app/Http/Controllers/WebAuthController.php`: `authenticate`, `logout`
- `app/Actions/Auth/LoginAction.php`
- `routes/web.php`: `login`, `api.auth.login`, `logout`, `dashboard`

```mermaid
flowchart TD
    Open[/] --> Form[Email and password form]
    Form -->|Submit| LoginAPI[POST /api/auth/login]
    LoginAPI -->|Throttle or validation error| Error[Errors on login]
    LoginAPI -->|Authenticated session| RoleRedirect[/dashboard]
    RoleRedirect --> Role{Role}
    Role -->|admin| Admin[/admin]
    Role -->|dispatcher| Dispatch[/dispatcher]
    Role -->|driver/responder| Driver[/driver]
    Role -->|resident| Resident[/resident]
    Authenticated[Authenticated layout] -->|Sign out| Logout[POST /logout]
    Logout --> Open
```

## Implemented and incomplete controls

| Control | Behavior |
|---|---|
| Sign In | Uses Inertia `useForm`; disabled during processing; posts to `api.auth.login`. |
| Forgot Password? | Visible but no handler or web route. |
| Password eye | Visible but no handler to change input type. |
| Register | Visible but no handler or web registration route. |

Web role middleware is defined in `routes/web.php`. Mobile authentication is separate Sanctum API authentication and is documented in [mobile-api.md](./mobile-api.md).
