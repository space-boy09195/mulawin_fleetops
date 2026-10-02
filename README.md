# Mulawin FleetOps

A web-based ERP system built for RP Mulawin Trucking Services — a final capstone
project for BSIT Business Analytics at Batangas State University (JPLPC Malvar
Campus).

## Overview

Mulawin FleetOps digitizes fleet trucking operations across four
role-based modules:

| Role              | Focus                                                              |
|-------------------|---------------------------------------------------------------------|
| Head Management   | Cross-department oversight, analytics, announcements               |
| Dispatcher        | Trip dispatch, routing, trip monitoring                             |
| Maintenance       | Vehicle maintenance scheduling, parts inventory, incident logging  |
| Accounting        | Billing, payroll, trip costs, profit tracking                       |

Access to each module is enforced with role-based access control (RBAC) —
see `includes/session.php` and `config/app.php`.

## Tech stack

- **Backend:** PHP (no framework), PDO for MySQL access
- **Database:** MySQL
- **Frontend:** Bootstrap 5, Bootstrap Icons, vanilla JS / AJAX
- **Auth & security:** Session-based auth, CSRF tokens, hardened session
  cookies, soft-delete (recycle bin) pattern, audit logging

## Project structure

```
ajax/           AJAX endpoint handlers (one per feature area)
assets/         CSS, JS, images
auth/           Login handling, user seeding
config/         App constants, DB connection
db/             Fresh-install database schema and bundled additions
includes/       Shared helpers: session, CSRF, layout, audit, alerts
instructions/   Setup and deployment instructions
pages/          Page controllers/views, one per feature
login.php       Login entry point
```

## Getting started

See [`instructions/instructions.md`](instructions/instructions.md) for detailed
requirements, local setup, configuration, system workflows, scheduled jobs,
and troubleshooting.

Quick local setup with XAMPP:

1. Install PHP 8.2+ with PDO MySQL enabled, MySQL or MariaDB, and Apache. Start
   Apache and MySQL, then place the project under `C:\xampp\htdocs\mulawin_fleetops`.
2. Create an empty `mulawin_fleetops` database in phpMyAdmin and import
   [`db/mulawin_fleetops_db.sql`](db/mulawin_fleetops_db.sql).
   **This SQL file is for a fresh, empty database only. Do not import it over
   an existing installation.**
3. Copy `.env.example` to `.env` in the project root. Set the database
   credentials and confirm `APP_BASE=/mulawin_fleetops` matches the URL path.
   Keep `.env` private; it is excluded from Git.
4. For disposable local development only, run `php auth/seed_users.php` from
   the project root to create the initial admin account. The temporary
   credentials are `admin` / `Admin@1234`; change the password after signing in
   and remove the seed script before exposing the app to a network. Never use
   this seeded account in production.
5. Visit `http://localhost/mulawin_fleetops/login.php` and sign in with an
   account in the database.

The project is a work in progress. Use a dedicated database account and HTTPS
for production; see the setup guide for storage and deployment considerations.

## Security notes

- Passwords are never stored or logged in plaintext-adjacent form; DB errors
  are logged server-side only, never shown to the browser.
- Session cookies are `httponly`, `SameSite=Strict`, and automatically marked
  `secure` once served over HTTPS (no manual flag to flip on deploy).
- CSRF tokens are required on state-changing forms via `includes/csrf.php`.
- Idle session timeout (default 30 min) and periodic session ID regeneration
  guard against fixation/hijacking.
- Failed logins are rate-limited using the `login_attempts` table.
- Dispatch requests stay pending until Head Management approves them; only
  approval creates the trip and deploys the truck.
- Uploaded documents are served through the authenticated download endpoint,
  not as directly accessible public files.
- Password changes requested from the login page are held for Head Management
  approval before they are applied.
- Trip Problem Reports reuse the existing incident records and Trip Monitoring
  includes filters for trips with and without reported problems.