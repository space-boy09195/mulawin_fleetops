# Setup & Deployment Instructions

## 1. Requirements

- PHP 8.0+
- MySQL 5.7+ or MariaDB equivalent
- A local web server for development: XAMPP, Laragon, or `php -S` with a
  MySQL install
- Checklist and vehicle inspection submissions require five minutes after
  selecting the dispatch, truck, or trip. To change the minimum, edit
  `MAINTENANCE_FORM_MIN_DURATION_SECONDS` in `config/app.php`; the server checks
  elapsed time independently of the browser countdown.
- Preventive maintenance schedules recur by calendar days. The interval is
  specified per truck and the next due date advances from the recorded service
  date; no odometer reading is required.

## 2. Local setup

1. Clone the repository into your server's web root
   (e.g. `htdocs/mulawin_fleetops` for XAMPP).
2. Copy `.env.example` to `.env`:
   ```
   cp .env.example .env
   ```
3. Edit `.env` with your local MySQL credentials and set:
   ```
   APP_BASE=/mulawin_fleetops
   ```
   (or whatever subfolder your local server serves the project from).
4. Create the database and import the schema into that selected database:
   ```
   mysql -u root -p -e "CREATE DATABASE mulawin_fleetops"
   mysql -u root -p mulawin_fleetops < "db/Mulawin_DB-Phase 1"
   ```
5. Back up the database, then apply the migrations in the order below. Do not
   assume migrations are safe to re-run: several contain `ALTER TABLE` changes
   that fail on a second run. Application code assumes all listed migrations
   have been applied — skipping one will cause runtime errors in dependent
   features. Verify changes on a staging copy before production:
   ```
   mysql -u root -p mulawin_fleetops < db/announcement.md
   for f in db/announcement_duration_audience_migration.sql \
            db/automation_settings_migration.sql \
            db/clients_migration.sql \
            db/dispatch_client_migration.sql \
            db/document_expiry_migration.sql \
            db/route_approval_and_notifications_migration.sql \
            db/trip_costs_migration.sql \
            db/trip_report_document_visibility_migration.sql \
            db/truck_image_migration.sql \
            db/vehicle_inspection_migration.sql \
            db/system_hardening_migration.sql \
            db/password_reset_requests_migration.sql \
            db/phase1_foundations_migration.sql \
            db/phase2_master_data_migration.sql \
            db/phase3_trip_operations_migration.sql \
            db/phase3_roles_migration.sql \
            db/phase3_dispatch_handoff_migration.sql \
            db/phase3_dispatch_batches_migration.sql \
            db/phase4_trip_inspections_migration.sql \
            db/phase4_maintenance_form_timers_migration.sql \
            db/phase4_repair_work_orders_migration.sql \
            db/phase4_preventive_maintenance_schedules_migration.sql \
            db/phase4_truck_status_history_migration.sql \
            db/phase5_rbac_roles_migration.sql \
            db/phase5_purchasing_migration.sql \
            db/phase6_finance_migration.sql \
            db/phase7_hr_attendance_migration.sql \
            db/phase7_payroll_components_migration.sql \
            db/phase7_payroll_deduction_items_migration.sql \
            db/phase8_admin_officer_migration.sql \
            db/phase9_truck_warranty_migration.sql \
            db/phase10_trip_workflow_migration.sql; do
     mysql -u root -p mulawin_fleetops < "$f"
   done
   ```
   or run them individually if your shell doesn't support globbing:
   ```
   mysql -u root -p mulawin_fleetops < db/announcement_duration_audience_migration.sql
   mysql -u root -p mulawin_fleetops < db/automation_settings_migration.sql
   mysql -u root -p mulawin_fleetops < db/clients_migration.sql
   mysql -u root -p mulawin_fleetops < db/dispatch_client_migration.sql
   mysql -u root -p mulawin_fleetops < db/document_expiry_migration.sql
   mysql -u root -p mulawin_fleetops < db/route_approval_and_notifications_migration.sql
   mysql -u root -p mulawin_fleetops < db/trip_costs_migration.sql
   mysql -u root -p mulawin_fleetops < db/trip_report_document_visibility_migration.sql
   mysql -u root -p mulawin_fleetops < db/truck_image_migration.sql
   mysql -u root -p mulawin_fleetops < db/vehicle_inspection_migration.sql
   mysql -u root -p mulawin_fleetops < db/system_hardening_migration.sql
   mysql -u root -p mulawin_fleetops < db/password_reset_requests_migration.sql
   mysql -u root -p mulawin_fleetops < db/phase1_foundations_migration.sql
   mysql -u root -p mulawin_fleetops < db/phase2_master_data_migration.sql
   mysql -u root -p mulawin_fleetops < db/phase3_trip_operations_migration.sql
   mysql -u root -p mulawin_fleetops < db/phase3_roles_migration.sql
   mysql -u root -p mulawin_fleetops < db/phase3_dispatch_handoff_migration.sql
   mysql -u root -p mulawin_fleetops < db/phase3_dispatch_batches_migration.sql
   mysql -u root -p mulawin_fleetops < db/phase4_trip_inspections_migration.sql
   mysql -u root -p mulawin_fleetops < db/phase4_maintenance_form_timers_migration.sql
   mysql -u root -p mulawin_fleetops < db/phase4_repair_work_orders_migration.sql
   mysql -u root -p mulawin_fleetops < db/phase4_preventive_maintenance_schedules_migration.sql
   mysql -u root -p mulawin_fleetops < db/phase4_truck_status_history_migration.sql
   mysql -u root -p mulawin_fleetops < db/phase5_rbac_roles_migration.sql
   mysql -u root -p mulawin_fleetops < db/phase5_purchasing_migration.sql
   mysql -u root -p mulawin_fleetops < db/phase6_finance_migration.sql
   mysql -u root -p mulawin_fleetops < db/phase7_hr_attendance_migration.sql
   mysql -u root -p mulawin_fleetops < db/phase7_payroll_components_migration.sql
   mysql -u root -p mulawin_fleetops < db/phase7_payroll_deduction_items_migration.sql
   mysql -u root -p mulawin_fleetops < db/phase8_admin_officer_migration.sql
   mysql -u root -p mulawin_fleetops < db/phase9_truck_warranty_migration.sql
   mysql -u root -p mulawin_fleetops < db/phase10_trip_workflow_migration.sql
   ```
   Whenever a new migration is added, apply it to every environment (local,
   Hostinger, and any preview database) before relying on the feature it backs.
   The Phase 5 RBAC migration adds the detailed department and dispatcher
   roles without changing existing user-role assignments. Review existing
   accounts and reassign them after confirming their department, vehicle
   category, and Day/Night shift. Dispatcher truck choices are limited to
   canonical Car Carrier, Container, and Wing Van categories; unclassified
   legacy trucks must be updated by an Admin before those dispatchers can use
   them.
   Apply `phase10_trip_workflow_migration.sql` after Phase 9 and the Finance
   migration. New dispatch requests then use the ordered 13-step trip workflow:
   Dispatchers confirm the approved assignment, record document and allowance
   readiness (or an explicit reason that either is not required), Maintenance
   completes the pre-departure checklist and inspection, and an Operations Head
   records dispatch clearance before departure. Dispatchers record progress and
   delivery/return details, Maintenance records the return inspection, and the
   Dispatcher submits the completed trip through the existing Completed status
   action. Existing trips are not backfilled with invented workflow history;
   they retain their existing status and inspection gates.
6. Seed initial user accounts:
   - Open `auth/seed_users.php` in a browser once (or run via CLI), then
     remove/protect it — it should not stay publicly reachable in production.
   - Use User Management to create or assign one account per role actually
     staffed: Admin, Management / Head, Admin Officer, Operations Head,
     Car Carrier Dispatcher (Day/Night), Container & Wing Van Dispatcher
     (Day/Night), Maintenance, Purchasing Officer, Finance, Billing and
     Collection, and Payroll. Preserve existing Admin and role assignments
     until reviewed; do not create duplicate accounts or share logins.
   - Link every employee who should use account-based attendance to their
     individual employee record. A linked active employee can clock in/out
     from Attendance; only HR/Admin Officer permissions can view or manage
     attendance for other employees.
7. Visit `login.php` in your browser and sign in with a seeded account.

## 3. Deploying to Hostinger

Hostinger's shared/business hosting (via hPanel) supports PHP + MySQL
natively, so no code changes are needed beyond configuration.

1. **Create the database** — in hPanel, go to Databases → MySQL Databases,
   create a database and a database user, and note the host (usually
   `localhost`), database name, username, and password.
2. **Enable SSL** — in hPanel, go to SSL and enable the free SSL certificate
   for your domain. Do this before going live; the app's session cookies
   automatically become `secure` once traffic is served over HTTPS
   (see `includes/session.php`), so there's nothing else to flip manually.
3. **Set the PHP version** — in hPanel → Advanced → PHP Configuration,
   select PHP 8.x to match local development.
4. **Upload the code** — via Hostinger's File Manager, Git integration (if
   available on your plan), or FTP/SFTP. Upload everything except `.env`
   (create it directly on the server instead — never upload real credentials).
5. **Create `.env` on the server** with production values:
   ```
   DB_HOST=localhost
   DB_NAME=<your_hostinger_db_name>
   DB_USER=<your_hostinger_db_user>
   DB_PASS=<your_hostinger_db_password>
   DB_CHARSET=utf8mb4
   APP_BASE=/
   SESSION_NAME=mulawin_session
   SESSION_TIMEOUT=1800
   CSRF_TOKEN_NAME=csrf_token
   APP_TIMEZONE=Asia/Manila
   COMPANY_EMAIL_DOMAIN=rpm.com
   ```
   Set `APP_BASE=/` if the app is served from your domain root, or
   `/subfolder` if it's in a subfolder.
6. **Import the database** — use phpMyAdmin (available in hPanel) to import
   `db/Mulawin_DB-Phase 1` and the migration files, same as the local steps.
   Select the target database before importing; the schema and migrations do
   not choose or create a hard-coded database.
7. **Verify** — load the site over `https://` and confirm login works. Check
   that the session cookie is marked `Secure` in your browser's dev tools
   (Application → Cookies) to confirm HTTPS auto-detection is working.
8. **Lock down `auth/seed_users.php`** — remove it or restrict access before
   the site is public; it's a setup convenience, not something end users
   should be able to hit.
9. **Configure document storage** — optionally set `DOCUMENT_STORAGE_PATH` in
   `.env` to an absolute writable path outside `public_html`. If unset, files
   remain in the existing `uploads/` directory, protected by its server rules
   and served through `ajax/document_download.php`. Back up this directory
   together with the database.
10. **Schedule expiry reminders** — configure a daily Hostinger cron job to run
    `scripts/generate_reminders.php` with the server's PHP CLI, for example:
    ```sh
    mysql -u root -p mulawin_fleetops < db/announcement.md
    /usr/bin/php /home/ACCOUNT/domains/DOMAIN/public_html/scripts/generate_reminders.php
    ```
    Use the PHP executable and absolute path shown by the hosting account.
    The script is CLI-only, reads `reminder_thresholds_days`, and suppresses
    duplicate notifications for the same record, expiry date, and threshold.
    The existing automation runner uses the same delivery ledger and threshold
    setting, so enabling both scheduling methods will not duplicate these notices.

## 4. Notes

- Never commit a real `.env` file — it's gitignored on purpose.
- The company email domain defaults to `rpm.com`; Admin can change it
  from Role Permissions after applying `phase1_foundations_migration.sql`.
- Apply `phase3_roles_migration.sql` after the trip-operations migration. It
  renames role ID 1 to Admin while preserving Admin's all-access permissions,
  creates the Operations Head role, and assigns Dispatchers request-entry
  permissions. Create the single active Operations Head account from Admin's
  User Management page; a second active account is rejected, while inactive
  historical accounts may remain.
- Apply `phase3_dispatch_handoff_migration.sql` after the roles migration.
  Operations Head users create and send shift dispatch instructions from
  Dispatch Planning. Dispatchers encode open instructions from their queue;
  the assigned shift, client, route, and departure time stay tied to the
  instruction. Encoded requests return to the Operations Head for approval,
  and Day/Night Shift is retained on both dispatch and trip records.
- Apply `phase3_dispatch_batches_migration.sql` after the dispatch handoff
  migration. From Dispatch Planning, an Operations Head can add 2–25 trip
  instructions to a batch and submit them together. Each item may have its own
  client, route, departure, shift, unit count, and notes. The entire batch is
  validated and committed as one operation; if validation or saving fails, no
  items from that submission are added. Dispatchers see the shared batch
  reference in their queue and encode each trip through the normal review flow.
- Apply `phase4_trip_inspections_migration.sql` after the Phase 3 migrations.
  General vehicle inspections remain available. Maintenance can also record
  one 24-part departure inspection while a trip is Loading and one return
  inspection while it is Unloading. A departure report must mark every standard
  part Good before the trip can move In Transit; the existing passed pre-trip
  checklist is still required. A return inspection is required before a trip
  can be completed, and new non-Good findings are highlighted against the
  departure baseline.
- If DB errors show up in the browser instead of a generic message, check
  `error_log` location in your PHP config rather than displaying errors —
  `config/database.php` already logs failures server-side and returns a
  generic message to the client.