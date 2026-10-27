# Mulawin FleetOps: Setup and System Guide

Mulawin FleetOps is a PHP and MySQL web application for managing trucking
operations at RP Mulawin Trucking Services. It is a work in progress; the
features described here reflect the current repository and may change.

## Requirements

- PHP 8.2 or later, with PDO and the PDO MySQL driver enabled
- MySQL or MariaDB, with permission to create tables and indexes
- Apache (XAMPP is suitable for local development) or another PHP-capable web
  server
- A modern browser

The application has no Composer dependency installation step. Bootstrap,
Bootstrap Icons, and Chart.js are already included under `assets/vendor/`.

## Local setup with XAMPP

1. Install and start Apache and MySQL from the XAMPP Control Panel.
2. Put the project folder under `C:\xampp\htdocs\mulawin_fleetops`. If you use
   a different folder or URL path, update `APP_BASE` in `.env` to match it.
3. In phpMyAdmin, create an empty database named `mulawin_fleetops` using
   `utf8mb4` (or choose another name and use that name in `.env`).
4. **Schema (current code).** The application code on the `system-hardening`
   branch runs only on the new schema
   [`../db/reference/mulawin_fleetops_new.sql`](../db/reference/mulawin_fleetops_new.sql)
   plus the drafts in `../db/target_migrations/`, applied in file-name order
   to an empty database. Follow
   [`../docs/testing/database_setup_checklist.md`](../docs/testing/database_setup_checklist.md);
   each database change needs owner approval. The development database
   `mulawin_fleetops_test` already has the reference schema and the drafts
   up to `20261021_route_locations_client_routes.sql` (checked read-only on
   2026-10-21), even though the draft headers still say "not executed".

   > **Legacy installation only — do not use with the current code.** The
   > file [`../db/mulawin_fleetops_db.sql`](../db/mulawin_fleetops_db.sql) is
   > the old schema: it has no `client_bookings`, `route_locations` or
   > `trip_pay_records`, so the current pages fail on it. The steps below
   > apply only to an old installation of the legacy code.

   Legacy only: select the empty database in phpMyAdmin and import
   [`../db/mulawin_fleetops_db.sql`](../db/mulawin_fleetops_db.sql). This file
   contains the legacy schema and bundled database additions. It does not
   create the database or any login accounts.

   **Fresh database only:** the SQL contains non-idempotent table creation.
   Do not import it over an existing installation. Back up an existing
   database before any schema change; do not treat this fresh-install file as
   an upgrade script.

   For an existing installation, back up the database and apply the targeted
   [`../db/auth_session_version_migration.sql`](../db/auth_session_version_migration.sql)
   to the selected application database before deploying the updated PHP code.
5. Copy `.env.example` to `.env` in the project root and set the database
   credentials and application path. For the default XAMPP setup, the relevant
   values are:

   ```dotenv
   DB_HOST=localhost
   DB_NAME=mulawin_fleetops
   DB_USER=root
   DB_PASS=
   DB_CHARSET=utf8mb4
   APP_BASE=/mulawin_fleetops
   SESSION_NAME=mulawin_session
   SESSION_TIMEOUT=1800
   CSRF_TOKEN_NAME=csrf_token
   APP_TIMEZONE=Asia/Manila
   COMPANY_EMAIL_DOMAIN=rpm.com
   ```

   Change `DB_USER` and `DB_PASS` to match your local database. Keep `.env`
   private; it is excluded from Git. The environment loader supports whole-line
   comments, but does not strip inline comments from values.
6. For a disposable local database only, create the initial administrator from
   the project root:

   ```powershell
   php auth/seed_users.php
   ```

   The script asks twice for the initial Admin password without showing what
   you type (at least 8 characters; both entries must match). Run it from an
   interactive PowerShell or terminal window: it refuses to run when the
   password cannot be read through a hidden prompt, for example when input is
   piped or redirected, or in a Git Bash window that has no hidden prompt.
   On Linux or macOS, if the terminal stops showing what you type after the
   script was interrupted, run `stty sane` to restore it.
   The password is never stored in the source. The script creates the
   initial Admin account only if it does not exist yet and never changes an
   existing account's password. Sign in at
   `http://localhost/mulawin_fleetops/login.php`. To change a password later, an Admin
   opens User Management and uses Reset Password on the account; this also
   signs the user out of existing sessions. There is no self-service
   password change: users who forget a password must ask an Admin. Remove
   `auth/seed_users.php` before exposing the application to a network. Never
   use this seeded account in production. If PHP is not on your command path,
   run the command using the full path to XAMPP's `php.exe`.
7. Open the login page in a browser. Sign in with an account that exists in the
   `users` table.

## Configuration

The app reads `.env` from the project root; see `.env.example` for all
supported settings.

| Setting | Purpose |
| --- | --- |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET` | PDO database connection |
| `APP_BASE` | URL path where the app is hosted, such as `/mulawin_fleetops`; use an empty value if hosted at the domain root |
| `SESSION_NAME` | Browser session cookie name |
| `SESSION_TIMEOUT` | Idle timeout in seconds; default is 1800 (30 minutes) |
| `CSRF_TOKEN_NAME` | Name used for the CSRF token |
| `APP_TIMEZONE` | PHP timezone; default is `Asia/Manila` |
| `COMPANY_EMAIL_DOMAIN` | Company email domain used by relevant account workflows |
| `DOCUMENT_STORAGE_PATH` | Optional absolute document-storage directory; by default it uses the project's `uploads` directory |

For production, use a dedicated database account with only the required
privileges, set a strong database password, serve the site over HTTPS, and set
`APP_BASE` to the deployed URL path. Keep `.env` and uploaded documents out of
public access. If you configure private document storage, ensure the PHP
process can read and write that directory and that it is outside the public
web root.

## How the system works

### Request and data flow

1. A user opens a PHP page under `pages/`. Pages render the application shell
   and the relevant screen.
2. Browser-side JavaScript and CSS live under `assets/`. Interactive screens
   send requests to feature-specific PHP handlers under `ajax/`.
3. Handlers and pages use shared code under `includes/` for sessions,
   permissions, CSRF validation, database helpers, auditing, approvals,
   notifications, uploads, and soft deletion.
4. `config/app.php` loads application settings and role identifiers.
   `config/database.php` creates a shared PDO connection using the `.env`
   database settings.
5. MySQL stores the operational records. The single SQL file under `db/` is
   the current fresh-install schema and includes the initial roles,
   permissions, and related workflow settings.

There is no framework router: the PHP page and handler files are the app's
entry points. For example, `pages/dispatch.php` presents a dispatch screen,
while related operations are handled by PHP endpoints in `ajax/`.

### Login, roles, and permissions

Login is handled by `auth/login_handler.php`. It verifies the submitted
password against the stored password hash, checks whether the account is
active, records login activity, and redirects to a dashboard. User sessions
are started and enforced through `includes/session.php`; unauthenticated
requests are redirected to login, and pages or handlers check the user's
permissions before allowing restricted actions.

The database starts with four roles: Head Management, Dispatcher,
Maintenance, and Accounting. The `permissions` and `role_permissions` tables
control access to modules and actions; administrators can manage permissions
in the application. A user account is stored in `users`. Employees such as
drivers and helpers are stored separately in `employees`, since not every
employee needs a login.

### Main functional areas

- **Operations:** dashboards, fleet status, dispatch and trip planning, trip
  monitoring, trip problem reports, and approvals/requests.
- **Maintenance:** maintenance records and reports, vehicle inspections,
  parts inventory, and purchasing.
- **People and administration:** attendance, recruitment, office supplies,
  user and permission management, announcements, automation, and the recycle
  bin.
- **Accounting:** billing, clients and client data, trip costs, finance, and
  payroll.
- **Documents:** document metadata is stored in the database, while files are
  stored on disk and downloaded through authenticated application endpoints.

What a user can see and do depends on their assigned role permissions.

### Important workflows and shared behavior

- A dispatcher submits a dispatch request. It remains pending until an
  authorized approver accepts or rejects it; approval drives trip creation
  and truck deployment.
- Several modules use shared approval-request and approval-history records.
  Check the request status in the application rather than assuming that a
  submitted request has already taken effect.
- Maintenance and fleet actions update vehicle and maintenance records; trip
  and incident data are reused in operations monitoring and reporting.
- Audit records capture important account and business actions. Soft-deleted
  records may be restored from the recycle bin instead of being permanently
  erased.
- State-changing requests use CSRF validation. Sessions use HTTP-only cookies,
  an idle timeout, and periodic session-ID regeneration. Failed login attempts
  are recorded and rate-limited.

### Scheduled command-line jobs

Two scripts are intended to be run by PHP from the command line, not by
visiting them in a browser:

```powershell
php scripts/run_automations.php
php scripts/generate_reminders.php
```

The automation command executes enabled FleetOps automations. The reminder
command sends the configured expiry reminders: vehicle documents (OR/CR,
insurance), uploaded documents, driver licenses and part warranties.

How reminders are matched: the setting `reminder_thresholds_days`
(app_settings; default `60,30,15,7`) lists the days before expiry. Each run
sends, for every item, the smallest threshold that still covers its days left,
once per recipient (`reminder_deliveries`). A skipped day therefore does not
lose a reminder: the next run sends the tier that applies. Add `0` to the
setting to also notify on the expiry date itself. Every run of
`generate_reminders.php` is recorded in `automation_runs` (job
`expiry_reminders`), and the command can be repeated safely.

**Scheduling status (checked 2026-10-21):** no Windows Task Scheduler task
for these scripts exists on the development machine, and every automation job
is off in `automation_settings`. Reminders are therefore only sent when the
command is run by hand. To schedule the daily reminder run on this XAMPP
machine, an administrator can create the task (review it first; it is not
created automatically):

```powershell
schtasks /Create /TN "Mulawin FleetOps Expiry Reminders" /SC DAILY /ST 06:00 `
  /TR "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\mulawin_fleetops\scripts\generate_reminders.php\""
```

Check it with `schtasks /Query /TN "Mulawin FleetOps Expiry Reminders"` and
look for a `success` row in `automation_runs` the next day. On a Linux host
use an equivalent cron entry. `run_automations.php` runs only the jobs
enabled in `automation_settings`; schedule it the same way if those jobs are
turned on. Review the command output and PHP/server logs when validating
scheduled runs.

## Troubleshooting

- **Database connection error:** make sure MySQL is running, the database
  exists, and `DB_*` values in `.env` are correct.
- **Login page or assets have broken paths:** ensure `APP_BASE` matches the URL
  path used in the browser.
- **Access denied after login:** check that the user's account is active and
  that its role has the required permission.
- **Uploads or downloads fail:** check PHP directory permissions and
  `DOCUMENT_STORAGE_PATH`; do not make private documents publicly accessible
  to work around a permissions problem.
- **Blank page or HTTP 500:** inspect the PHP and web-server error logs. The
  app logs database errors server-side rather than displaying connection
  details in the browser.
