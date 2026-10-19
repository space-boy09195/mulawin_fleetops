# Isolated test database: setup and migration checklist

**Status: PREPARED — NOT EXECUTED.** Nothing in this file has been run. Every
step that changes a database needs the owner's separate, explicit approval,
given after the owner has verified the backup and the target name.

Target: `mulawin_fleetops_test` on the local XAMPP MariaDB 10.4.
Never touch the legacy database `mulawin_fleetops` (no connect, import,
drop, truncate or migration).

Commands are written for Git Bash on Windows with XAMPP's client
(`/c/xampp/mysql/bin/mysql.exe`). `-p` prompts for the password; never put a
password on the command line or in a file in the repository.

---

## 0. Preconditions (owner)

- [ ] A fresh backup of the legacy database exists **and was test-restored**
      somewhere other than the live database (owner verifies; not done here).
- [ ] The owner confirms the server is the local XAMPP instance.
- [ ] The owner confirms the name `mulawin_fleetops_test` does not exist yet,
      or that it may be dropped (read-only check):
      ```sh
      /c/xampp/mysql/bin/mysql.exe -u root -p -e "SHOW DATABASES LIKE 'mulawin_fleetops_test'"
      ```
- [ ] Scheduled automation, reminders, email and external notifications stay
      disabled (`scripts/run_automations.php` and `scripts/generate_reminders.php`
      are not scheduled; automation settings stay off).
- [ ] Static checks pass on the code that will be tested
      (see "Pre-run static checks" at the end).

## 1. Create the empty test database — APPROVAL REQUIRED

```sh
/c/xampp/mysql/bin/mysql.exe -u root -p -e "CREATE DATABASE mulawin_fleetops_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

## 2. Prepare a test-only copy of the reference schema (no DB change — filter dry-run verified on a scratch copy: removes only the 4 CREATE DATABASE / USE lines, keeps 70 tables)

`db/reference/mulawin_fleetops_new.sql` contains its own
`CREATE DATABASE IF NOT EXISTS mulawin_fleetops_new` (lines 25–27) and
`USE mulawin_fleetops_new;` (line 29). **Do not import it as is**: it would
create and fill a different database. Make a filtered copy outside the
repository that drops those statements, then check it:

```sh
OUT=/c/Users/$USERNAME/mulawin_test_import
mkdir -p "$OUT"
sed -e '/^CREATE DATABASE IF NOT EXISTS mulawin_fleetops_new/,/;$/d' \
    -e '/^USE mulawin_fleetops_new;/d' \
    db/reference/mulawin_fleetops_new.sql > "$OUT/reference_for_test.sql"
# Must print nothing:
grep -nE 'CREATE DATABASE|^USE |DROP DATABASE' "$OUT/reference_for_test.sql"
# Must still contain all 70 reference tables (verified on a copy, 2026-10-14):
grep -c '^CREATE TABLE' "$OUT/reference_for_test.sql"
```

## 3. Import the schema into the test database — APPROVAL REQUIRED

The database name is given explicitly on the command line, so the import
can only land in `mulawin_fleetops_test`:

```sh
/c/xampp/mysql/bin/mysql.exe -u root -p mulawin_fleetops_test < "$OUT/reference_for_test.sql"
/c/xampp/mysql/bin/mysql.exe -u root -p mulawin_fleetops_test -e "SELECT DATABASE(); SHOW TABLES"
```

MariaDB 10.4 compatibility to watch during import (the reference header
says MySQL 8.x): 34 `CHECK` constraints (supported since 10.2), utf8mb4_unicode_ci
collation (supported). Stop at the first error and report it — do not edit
the schema to "make it pass" without review.

## 4. Apply the draft migrations in this order — APPROVAL REQUIRED, one by one

Order = `SC_DRAFT_ORDER` in `scripts/schema_check.php` (same order the static
model uses). For each file: read its header, run its READ-ONLY PRE-CHECKS
first, then apply it with the database named explicitly, then run its
post-checks.

Each draft's pre-check `SELECT DATABASE()` expects `mulawin_fleetops_new`;
on the test copy the expected value is **`mulawin_fleetops_test`** — that is
the only expected value that changes.

| # | Draft | Notes |
|---|---|---|
| 1 | `20261010_batch1a_security_support_tables.sql` | support tables |
| 2 | `20261010_batch1b_login_attempts.sql` | |
| 3 | `20261010_p21_roles_system_key.sql` | role system keys; no role is auto-mapped |
| 4 | `20261010_s36_employee_fields.sql` | |
| 5 | `20261014_tE1_employee_compat_columns.sql` | T-E1: `full_name` etc.; run its backfill check (pre-check must show no NULL names after backfill) |
| 6 | `20261011_p21_rbac_seed_review_only.sql` | roles + permissions; **creates no user**; includes Operations Head `reports.view` (approved 2026-10-14) |
| 7 | `20261014_tS1_support_tables.sql` | |
| 8 | `20261014_tK1_truck_fuel_efficiency.sql` | |
| 9 | `20261014_tA1_approval_workflow_steps.sql` | T-A1: pending uniqueness (`pending_key` generated column + unique key) — pre-check must find no duplicate pending requests; seeds workflows (step 1 roles) |
| 10 | `20261014_tT1_trip_second_driver.sql` | |
| 11 | `20261014_tT2_trip_completion_reports.sql` | generated `active_trip_id` STORED + unique key |
| 12 | `20261014_tC1_client_booking_dispatch_fields.sql` | |
| 13 | `20261014_tI1_trip_incident_resolution.sql` | |
| 14 | `20261014_tD1_announcement_audience.sql` | |
| 15 | `20261014_tP1_trip_pay_records_guard.sql` | T-P1: unique (trip, employee, pay type) — pre-check must find no duplicate wage rows |
| 16 | `20261014_tF1_expense_categories.sql` | |
| 17 | `20261014_tB1_budgets.sql` | |
| 18 | `20261014_tM1_maintenance_next_due.sql` | |
| 19 | `20261014_tM2_maintenance_planning.sql` | |
| 20 | `20261014_tN1_inspection_templates_seed.sql` | review the 24 checklist items with Maintenance first |
| 21 | `20261010_p21_attendance_records.sql` | generated `open_shift_employee_id` VIRTUAL + unique key (MariaDB supports unique keys on virtual columns; verify) |

Example (repeat per file):

```sh
/c/xampp/mysql/bin/mysql.exe -u root -p mulawin_fleetops_test < db/target_migrations/20261010_batch1a_security_support_tables.sql
```

Several drafts contain DDL (`ALTER TABLE`, `CREATE TABLE`), which MariaDB
commits implicitly; a failed draft can leave a partial change. If one fails,
stop, record the error, and recreate the test database from step 1 rather
than patching by hand.

## 5. Accounts for testing — APPROVAL REQUIRED, owner decides

No Admin or other user is created by any step above. `auth/seed_users.php`
has **not** been run. The owner decides whether and how test accounts are
created (one per role: admin, operations_head, car_carrier_dispatcher,
container_wing_van_dispatcher, maintenance, purchasing_officer, finance,
billing_collection, payroll, audit, driver, helper) and who holds them.
Use obviously fake names and non-real emails; never copy real people.

## 6. Point the application at the test database (owner)

The owner edits `.env` personally (it is never read or changed by the
assistant): set the database name to `mulawin_fleetops_test`, keep the
legacy database untouched, keep mail/notification delivery disabled.
Restore the previous value after testing.

## 7. Verification (read-only)

```sql
SELECT DATABASE();                                   -- mulawin_fleetops_test
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'mulawin_fleetops_test';  -- 86 = 70 reference tables + 13 draft tables + 3 views (static model: 83 tables)
SELECT system_key FROM roles ORDER BY system_key;    -- the seeded role keys
SELECT workflow_key FROM approval_workflows;         -- dispatch, route_request, purchase_request, fund_request, payment_voucher, payroll
SELECT p.permission_key FROM role_permissions rp JOIN roles r ON r.role_id = rp.role_id
  JOIN permissions p ON p.permission_id = rp.permission_id
 WHERE r.system_key = 'operations_head' AND p.permission_key = 'reports.view';   -- 1 row
```

Then run `tests/sql/analytics_known_dataset.sql` (approval required; it ends
in ROLLBACK) and the MariaDB integration checklist.

## 8. Rollback / clean-up — APPROVAL REQUIRED

Only the test database is ever dropped, and only after the owner confirms
the name on screen:

```sh
/c/xampp/mysql/bin/mysql.exe -u root -p -e "DROP DATABASE mulawin_fleetops_test"
```

## Pre-run static checks (safe, no database)

```sh
php scripts/schema_check.php --self-test
php scripts/schema_check.php --drafts=all --lint-drafts --no-scan
php scripts/schema_check.php --drafts=all --paths=ajax,pages,includes,auth,login.php,analytics,scripts
php tests/unit/run.php
php tests/static/run.php
```
