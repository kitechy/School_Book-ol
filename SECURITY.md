# School Book-ol security notes

## Architecture

This is a server-rendered PHP application using PDO/MySQL and PHP server-side sessions. It does not currently expose a JSON API, use JWTs, or have a separate frontend bundle; CORS and refresh-token rotation therefore do not apply. PHP templates are the XSS boundary: user-controlled values are HTML-escaped before rendering.

## Implemented controls

### Frontend

- `app_escape()` in `config/bootstrap.php` applies HTML entity encoding; page templates use it for user names, room data, booking purpose, and other database content. A booking purpose such as `<script>alert(1)</script>` is displayed as text.
- Forms that change application state include a random session CSRF token. `app_verify_csrf()` rejects missing or incorrect tokens with HTTP 403.
- `config/bootstrap.php` sends `X-Frame-Options: DENY` and a CSP with `frame-ancestors 'none'`.
- Apache `.htaccess` files disable directory indexes and deny HTTP access to `config/`, `database/`, and `bin/`; these rules require Apache 2.4 with `AllowOverride` enabled.
- The CSP permits only same-origin scripts, disallows plugins and inline scripts, and permits the existing Google Fonts stylesheet/font hosts. Existing page styles are inline, so `style-src 'unsafe-inline'` remains necessary until styles move to external stylesheets.
- Credentials and hashes are server-side only. No JavaScript bundle or frontend secret configuration exists.

### Session and authorization

- Authentication is maintained by PHP server-side sessions. Cookies are HttpOnly and SameSite=Lax, strict session IDs and cookie-only sessions are enabled, IDs rotate on login, and sessions expire after 30 minutes of inactivity.
- `app_require_user()` and `app_require_admin()` in `config/bootstrap.php` reload the account and role from MySQL on protected requests. Admin pages return HTTP 401 for unauthenticated requests and 403 for non-admin users.
- Public registration always assigns `Student`; the submitted form cannot grant an Admin role.
- `bin/create_admin.php` provisions or promotes an administrator from the command line only. It takes the password from `ADMIN_PASSWORD`; it is not an HTTP endpoint.
- Login and registration are throttled per client IP using the `rate_limits` table. Defaults are 10 login requests per 5 minutes and 5 registration requests per hour; `LOGIN_ATTEMPT_LIMIT`, `LOGIN_ATTEMPT_WINDOW`, `REGISTRATION_ATTEMPT_LIMIT`, and `REGISTRATION_ATTEMPT_WINDOW` may override them.

### Backend and database

- SQL values use PDO prepared statements. Passwords use PHP `password_hash()` / `password_verify()`.
- Booking inputs are validated server-side. Admin approval locks the booking and room rows in a transaction, checks the request is pending, checks the room is active, checks for overlapping approved bookings, updates the status, and writes its audit event in the same transaction.
- Room create/update/disable/activate actions are CSRF-protected, validated, transactional, and audited. Rooms are disabled rather than deleted, preserving booking history.
- Audit events include login success/failure, logout, registration, booking creation/review, room administration, and admin provisioning. Passwords and password hashes are not logged.
- `config/database.php` reads connection settings from environment variables. Development defaults to XAMPP's local `root` account; production refuses an empty or root database username.
- `.env` files are ignored by Git. `.env.example` contains placeholders only and is not automatically loaded by PHP.

## Migration and local setup

For an existing `school_bookol` database, run the migrations in order from PowerShell:

```powershell
& $env:ComSpec /c '"C:\xampp812\mysql\bin\mysql.exe" -h 127.0.0.1 -u root < "C:\xampp812\htdocs\school_bookol\database\migrations\001_admin_bookings.sql"'
& $env:ComSpec /c '"C:\xampp812\mysql\bin\mysql.exe" -h 127.0.0.1 -u root < "C:\xampp812\htdocs\school_bookol\database\migrations\002_booking_constraints.sql"'
```

For a new database, import `database/schema.sql` instead.

Use a dedicated non-root application database account outside development. Create it using a unique password held outside source control, then grant only the needed data permissions:

```sql
CREATE USER 'school_bookol_app'@'localhost' IDENTIFIED BY '<unique-password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON school_bookol.* TO 'school_bookol_app'@'localhost';
```

Configure `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, and `APP_ENV=production` in the PHP/Apache environment. Production refuses the root account or an empty database password. Do not commit real credentials. Database migrations should be run by a separately controlled setup account, not by the runtime application account.

To provision an admin, set `ADMIN_PASSWORD` temporarily in the local environment and run:

```powershell
$env:ADMIN_PASSWORD = '<unique-password-of-at-least-12-characters>'
& 'C:\xampp812\php\php.exe' 'C:\xampp812\htdocs\school_bookol\bin\create_admin.php' '<school-id>' '<email>' '<first-name>' '<last-name>'
Remove-Item Env:ADMIN_PASSWORD
```

Start local development with:

```powershell
& 'C:\xampp812\php\php.exe' -S 127.0.0.1:8127 -t 'C:\xampp812\htdocs\school_bookol'
```

## Security checks

- **XSS:** Submit a booking purpose containing `<script>alert(1)</script>` and inspect the booking details/list. It must appear escaped as text and must not execute.
- **CSRF:** Submit an approval, booking, room, or logout form without its token; the server should return 403.
- **RBAC:** Open `/admin/dashboard.php` without a session (401) and with a Student session (403); an Admin session should render the dashboard.
- **Booking conflicts:** Create two overlapping requests for the same room/date, approve the first, then approve the second. The second must return 409 and remain pending.
- **Headers:** Inspect response headers for CSP, `X-Frame-Options`, `X-Content-Type-Options`, and `Referrer-Policy`. HSTS is emitted only for HTTPS requests.
- **Password storage:** Inspect only the hash algorithm/length in MySQL; no UI query returns `password_hash`.
- **Secrets:** Search tracked files and browser responses for actual database/admin credentials. The example environment file contains placeholders only.
- **Throttling:** Submit more than the configured login or registration limit from one IP inside its time window and expect HTTP 429.

## Deployment limits and remaining work

- Local XAMPP development uses HTTP. Production must terminate TLS at Apache or a trusted reverse proxy, redirect HTTP to HTTPS, and set `APP_ENV=production`; HSTS is only sent when PHP detects HTTPS. HSTS alone does not configure TLS or the redirect.
- Production PHP must have `display_errors=Off` and server-side error logging enabled; the application exception handler returns a generic error response for unhandled exceptions.
- Data at rest is not field-encrypted. Configure encrypted storage/backups and database access controls at the hosting layer if required by school policy.
- Application throttling is per IP and is not DDoS protection. Use hosting-provider WAF/network protections for distributed attacks.
- There is no API, so no CORS policy or API route validation layer has been added. The server-rendered form handlers perform validation.
- MFA, account recovery, email verification, dependency scanning, automated security tests, and an external security-header audit are not configured. Do not represent them as implemented or tested.
