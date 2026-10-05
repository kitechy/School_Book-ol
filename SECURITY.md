# School Book-ol security notes

## Architecture

This is a server-rendered PHP application using PDO/MySQL and PHP server-side sessions. It does not currently expose a JSON API, use JWTs, or have a separate frontend bundle; CORS and refresh-token rotation therefore do not apply. PHP templates are the XSS boundary: user-controlled values are HTML-escaped before rendering.

## Implemented controls

### Frontend

- `app_escape()` in `config/bootstrap.php` applies HTML entity encoding; page templates use it for user names, room data, booking purpose, and other database content. A booking purpose such as `<script>alert(1)</script>` is displayed as text.
- Forms that change application state include a random session CSRF token. `app_verify_csrf()` rejects missing or incorrect tokens with HTTP 403.
- `config/bootstrap.php` sends `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, a restrictive `Permissions-Policy`, and a CSP with `frame-ancestors 'none'`.
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
- **Headers:** Inspect response headers for CSP, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, and `Permissions-Policy`. HSTS is emitted only for HTTPS requests.
- **Password storage:** Inspect only the hash algorithm/length in MySQL; no UI query returns `password_hash`.
- **Secrets:** Search tracked files and browser responses for actual database/admin credentials. The example environment file contains placeholders only.
- **Throttling:** Submit more than the configured login or registration limit from one IP inside its time window and expect HTTP 429.

## Deployment limits and remaining work

- Local XAMPP development uses HTTP. Production must terminate TLS at Apache or a trusted reverse proxy and set `APP_ENV=production`. Configure `APP_CANONICAL_HOST` to the deployed hostname. If TLS terminates at a reverse proxy, set `TRUSTED_PROXY_IPS` to the proxy's exact source IP address(es), and configure that proxy to remove and set the forwarded-protocol headers. The application trusts forwarded HTTPS indicators only from those configured addresses. Direct HTTPS requests are detected from PHP's `HTTPS` server variable. The application redirects HTTP requests to HTTPS and emits HSTS only when HTTPS is detected; HSTS alone does not configure TLS.
- Production PHP must have `display_errors=Off` and server-side error logging enabled; the application exception handler returns a generic error response for unhandled exceptions.
- Data at rest is not field-encrypted. Configure encrypted storage/backups and database access controls at the hosting layer if required by school policy.
- Application throttling is per IP and is not DDoS protection. Use hosting-provider WAF/network protections for distributed attacks.
- There is no API, so no CORS policy or API route validation layer has been added. The server-rendered form handlers perform validation.
- MFA, account recovery, email verification, automated tests, and an external security-header audit are not configured. Do not represent them as implemented or tested.

## Optional hardening status

| Control | Status | Current state |
| --- | --- | --- |
| MFA / TOTP | Planned | Not implemented. Admin MFA needs a maintained RFC 6238 library, enrollment and recovery flows, secret protection, and migration/session changes; password-only admin login remains in place. |
| WAF | Deployment-dependent | No production edge/reverse-proxy configuration is present in this repository. Use a managed WAF or ModSecurity with OWASP CRS at the deployed edge; it supplements, not replaces, the PHP controls. |
| Dependency vulnerability scanning | Not applicable | No Composer manifest/lockfile, other package-manager dependency manifest, or CI workflow exists, so there are no declared third-party application dependencies for a dependency scanner to audit. |
| External security-header audit | Pending deployment | No public deployed HTTPS URL or before/after scanner results were provided. No scan or screenshot is claimed. |

### MFA implementation path

When MFA is added, use a maintained RFC 6238 TOTP library and require the challenge after password verification but before creating the authenticated administrator session. Encrypt pending and active TOTP secrets using a vetted authenticated-encryption facility with a key supplied from a production secret manager; never return a stored secret after enrollment. Add rate-limited verification, audit events that contain no OTP or secret, and documented admin recovery/re-enrollment steps. Do not enable MFA for students by default. Test enrollment, valid and invalid codes, brute-force throttling, session state before/after challenge, and account recovery before requiring it for admins.

### WAF deployment path

Place a managed WAF or ModSecurity with the OWASP Core Rule Set in front of the production HTTPS origin. Initially run the rules in detection-only mode, review false positives against legitimate login, registration, booking, and admin form traffic, and add narrowly scoped exclusions only when justified. Then enable blocking and rate controls, keep WAF logs protected, and restrict direct public access to the origin so traffic cannot bypass the WAF. Re-test normal workflows and malicious-request handling after every ruleset or application change. XAMPP does not currently include a configured WAF, and no WAF behavior has been tested locally.

### Dependency scanning path

The PHP application currently has no Composer or other package-manager manifest/lockfile. Do not add a placeholder audit job that cannot run. If Composer dependencies are introduced, commit both `composer.json` and `composer.lock`, configure GitHub Dependabot for the `composer` ecosystem and GitHub Actions, and add a CI job using Composer 2.4+ to install from the lockfile and run `composer audit` on pull requests and pushes to the protected default branch. Make the audit fail CI for reported advisories; investigate and document any exception rather than suppressing it. Until then, there are no declared third-party application packages for a dependency scanner to assess.

### External security-header audit procedure

The external audit is **pending deployment**. The before-scan was not captured before the current header changes, and this local XAMPP project does not provide a publicly reachable HTTPS endpoint. Do not invent baseline scores or screenshots. Once a public test deployment is available:

1. Choose a non-sensitive HTTPS URL on the deployment and use the same scanner for both runs (for example, Mozilla Observatory or SecurityHeaders.com).
2. If a baseline is still useful, record that it is a post-change baseline; a genuine pre-change result can only be reported if it was captured before the changes.
3. Save the scanner name, tested URL, UTC date/time, result/score, each finding, and a sanitized screenshot under `docs/security-audit/`. Do not include account data, credentials, session cookies, or private URLs.
4. Verify HTTP redirects to the same canonical HTTPS host; check `Strict-Transport-Security` is present only on HTTPS and includes `max-age=31536000; includeSubDomains`; inspect CSP, `Permissions-Policy`, `X-Content-Type-Options`, `X-Frame-Options`, and `Referrer-Policy`.
5. Inspect a login response's `Set-Cookie` header to confirm `Secure`, `HttpOnly`, and `SameSite=Lax`; do not save or publish the cookie value.
6. Apply justified fixes, deploy them, and re-run the same scanner against the same URL. Record remaining findings and save a sanitized after screenshot. Keep the results marked pending until this is actually done.

The application currently configures CSP (with inline styles allowed for existing templates), `Permissions-Policy`, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, conditional HSTS, and a production HTTP-to-HTTPS redirect. An external scan is still needed to confirm the deployed proxy, TLS, redirect, and cookie behavior.
