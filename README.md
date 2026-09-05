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
db/             Schema and migration files
includes/       Shared helpers: session, CSRF, layout, audit, alerts
instructions/   Setup and deployment instructions
pages/          Page controllers/views, one per feature
login.php       Login entry point
```

## Getting started

See [`instructions/instructions.md`](instructions/instructions.md) for full
local setup and Hostinger deployment steps. Quick version:

1. Clone the repo and copy `.env.example` to `.env`, filling in your DB
   credentials.
2. Import the schema in `db/Mulawin_DB-Phase 1` into MySQL, then run any
   `*_migration.sql` files in `db/`.
3. Serve the project root with PHP 8+ (e.g. XAMPP/Laragon locally, or
   Hostinger for production).
4. Visit `login.php`. Seed accounts can be created via `auth/seed_users.php`.

## Security notes

- Passwords are never stored or logged in plaintext-adjacent form; DB errors
  are logged server-side only, never shown to the browser.
- Session cookies are `httponly`, `SameSite=Strict`, and automatically marked
  `secure` once served over HTTPS (no manual flag to flip on deploy).
- CSRF tokens are required on state-changing forms via `includes/csrf.php`.
- Idle session timeout (default 30 min) and periodic session ID regeneration
  guard against fixation/hijacking.