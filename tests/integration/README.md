# Integration tests (HTTP + MariaDB)

Status as of 2026-10-22: **harness only. Nothing in this folder has been run
against a database or a web server.** No disposable database or test account
exists yet. Provisioning, D1 in the disposable database, the setup stage and
HTTP execution each need separate approval.

| File | Purpose |
|---|---|
| `run.php` | CLI runner: offline preflight (default), opt-in read-only database checks, and the HTTP scenarios (disabled) |
| `seed_synthetic.sql` | Fictional fixture rows, guarded so they insert only into an empty disposable database (not run) |

## Modes

| Command | What it does | Database | HTTP |
|---|---|---|---|
| `php tests/integration/run.php` | Checks PHP and its extensions, then lists the scenarios and why none of them is ready | none | none |
| `php tests/integration/run.php --config=PATH` | Also validates the config, the working copy and the copy's `.env` | none | none |
| `php tests/integration/run.php --config=PATH --integration` | Opt-in. Also connects as the **application-test account** and runs read-only checks (identity, grants, marker, data, schema, fixtures) | read-only `SELECT` / `SHOW` | none while `E2E_HTTP_SCENARIOS_APPROVED = false` |

Exit codes: `0` passed; `1` a check refused (nothing further runs); `2` usage
error; `3` database checks passed and the run stopped at the HTTP stage gate.

`E2E_HTTP_SCENARIOS_APPROVED` in `run.php` is `false`. Turning it on is a
reviewed code change, made only after HTTP execution is approved.

## Why a name or a marker is not enough

A database name says nothing about what the database holds: the working
database is called `mulawin_fleetops_test`. The runner refuses unless **all**
of these hold:

1. **Name**: `db_name` matches `^mulawin_fleetops_e2e(_[a-z0-9]+)?$`, is not
   `mulawin_fleetops_test`, and differs from `DB_NAME` in the project `.env`.
2. **Dedicated account**: the copy's `.env` uses `app_db_user`, never root or
   the setup account. Connected with exactly the copy's credentials,
   `CURRENT_USER()` must be that account at a loopback host, and
   `SHOW GRANTS` must show only `USAGE` plus exactly `SELECT, INSERT,
   UPDATE, DELETE` on the one disposable database. Global, table, role or
   proxy grants, `GRANT OPTION`, schema or trigger privileges, other
   databases and wildcard names all refuse. If the copy pointed at another
   database, the account could not reach it, so the application fails closed.
3. **Identity**: `SELECT DATABASE()` equals `db_name`.
4. **Marker**: `app_settings.e2e_marker` equals the 64-hex value generated
   for this setup and stored in the config. It proves the database is the
   one this setup created, not just one with the right name.
5. **Synthetic data only**: every user is `e2e_…@example.invalid`, and every
   client, trip and supplier starts with `E2E`. A copy of real data refuses.
6. **Working copy**: `working_copy` is a separate directory, not the project,
   not a parent or child of it, and outside the Apache document root. Its key
   files are byte-identical to the working tree, and its `.env` sets
   every `DB_*` key explicitly (`config/database.php` falls back to `root` and
   `mulawin_fleetops` when a key is missing).
7. **Loopback server**: the copy is served by PHP's built-in server on
   `127.0.0.1` only. The runner refuses a port that is already in use, and
   checks a per-run nonce from the server before sending any request.
8. **Schema**: the required tables and columns exist before any HTTP request.

### Why the project `.env` cannot be overridden

`includes/env.php` `loadEnv()` always sets `$_ENV` from the `.env` beside the
code, on every request (`config/app.php`, `config/database.php`). PHP's
`variables_order` is `GPCS`, so process variables never reach `$_ENV`.
The only safe way to point the application elsewhere is a separate copy with
its own `.env`. The server is started with a minimal environment, so `env()`'s
`$_SERVER` fallback cannot supply a `DB_*` value either.

## Accounts

| Account | Privileges | Used by | Never |
|---|---|---|---|
| Application-test (`app_db_user`) | `SELECT, INSERT, UPDATE, DELETE` on the disposable database only | the copied application (its `.env`) and the runner's read-only checks | schema, trigger or account privileges; other databases |
| Setup (`setup_db_user`) | schema and data privileges on the disposable database only | the approved setup, restore and cleanup steps, typed by a person in the `mysql` client | in the copy's `.env`, the config file, the runner or the application |

The runner has no input for the setup credential: only the account **name**
appears in the config, so the runner can refuse a copy that uses it. The
runner itself writes nothing to a database.

Grant the application account with escaped underscores, so the name is not a
wildcard:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON `mulawin\_fleetops\_e2e`.* TO 'fleetops_e2e_app'@'localhost';
```

## Config file (outside the repository)

```json
{
  "working_copy": "C:/path/outside/htdocs/fleetops_e2e_copy",
  "db_host": "127.0.0.1",
  "db_name": "mulawin_fleetops_e2e",
  "app_db_user": "fleetops_e2e_app",
  "setup_db_user": "fleetops_e2e_setup",
  "marker": "<64 hex characters generated in the setup stage>",
  "port": 8765,
  "d1_applied_confirmed": false
}
```

The config holds no password. The application-test password is only in the
copy's `.env`; the synthetic users' password is passed in the
`E2E_USER_PASSWORD` environment variable when the HTTP stage runs. Nothing
prints passwords, DSNs, grant lines, cookies or session IDs. Database errors
are reported by SQLSTATE only.

The copy's `.env` must set: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`,
`APP_BASE=` (empty), a `SESSION_NAME` different from the project's, and a
`DOCUMENT_STORAGE_PATH` that exists outside the project.

## Setup (needs separate approval; not done)

Done once per run by a person, in this order. Restoring means repeating
steps 2-7 on a freshly created database.

1. Copy the working tree to a directory outside `htdocs`, then write the
   copy's `.env` as described above.
2. A database administrator creates `mulawin_fleetops_e2e`, the setup account
   (all privileges on that database only) and the application-test account
   (the grant above). Passwords are typed at the prompt (`-p`), never on the
   command line.
3. Schema: `mysqldump --no-data --skip-add-drop-table --routines --triggers`
   of the working database (read-only), imported into the disposable
   database with the setup account. Check the dump for `DEFINER` clauses and
   `USE` statements first.
4. Configuration only: a data-only dump of `roles`, `permissions`,
   `role_permissions`, `approval_workflows` and `approval_workflow_steps`,
   imported with the setup account. No users, clients, trips or financial
   records are copied.
5. D1, only if separately approved: apply the corrected D1 block in the
   disposable database, then set `d1_applied_confirmed` to `true`.
6. Marker: generate 64 hex characters (`bin2hex(random_bytes(32))`), store
   them in the config, and insert them as `app_settings.e2e_marker` with the
   setup account.
7. Seed: choose the synthetic users' password, hash it with
   `password_hash(..., PASSWORD_BCRYPT)`, and run `seed_synthetic.sql` with
   the setup account, the hash in `@e2e_password_hash` (`--init-command`) and
   without `--force`. Its guard refuses any other database, a missing marker,
   a missing hash or non-empty fixture tables.

Then run the preflight, then `--integration`.

## How the HTTP stage works (implemented, disabled, never executed)

- **Server**: `php -S 127.0.0.1:<port> -t <copy> <router>`, with a minimal
  environment. A temporary router answers the identity probe and returns 404
  for dot-files (`.env`) and non-public folders (`config`, `includes`,
  `tests`, `db`, …). The built-in server does not apply `.htaccess`. The
  server is stopped and the router deleted when the run ends, including on
  failure.
- **Sign-in**: the real flow. `GET /login.php`, read the hidden CSRF field,
  `POST /auth/login_handler.php` with the username and password, and expect
  a `302` whose `Location` is not `login.php`. Then `GET` that dashboard and
  read `window.CSRF_TOKEN` (`includes/layout.php`). One sign-in per user per
  run stays far below the login throttle (`auth/login_handler.php`).
- **Cookies**: one in-memory cookie store per user (curl share handle). Never
  written to disk, never printed.
- **Requests**: every handler call is its own HTTP request with
  `Accept: application/json`, `X-Requested-With: XMLHttpRequest`, the CSRF
  field (`CSRF_TOKEN_NAME`, default `csrf_token`) and a fresh
  `Idempotency-Key` (`includes/idempotency.php`: 16-80 of `[A-Za-z0-9._-]`).
  Redirects are never followed; status, `Location` and the JSON body
  (`success`, `message`, IDs) are checked.
- **Database checks**: after each response, on the runner's own autocommit
  connection. Each request opens its own application connection and has
  committed or rolled back before the response arrives, so rollbacks show up
  as unchanged rows. New rows are found by comparing IDs taken before the
  request.
- **Cleanup**: the runner never drops or deletes anything (see below).

## Scenarios

Expected statuses and messages come from the current handler code.

| # | Scenario | Status | D1 | Request | Expected |
|---|---|---|---|---|---|
| 1 | Dispatcher fund request goes to the configured Finance step | implemented | yes | dispatcher `POST /ajax/finance_handler.php` `create_fund_request`, Trip Allowance on `E2E-TRIP-0001` | `200`, "Fund request submitted for approval."; Pending request; steps equal the template; notifications only to active Finance users; one `CREATE` audit; no routing audit |
| 2 | Finance fund request is routed to Admin for that request only | implemented | yes | finance `create_fund_request`, Cash Advance | `200`, "Fund request submitted for Admin approval, because you belong to the approving role."; step on Admin; template unchanged; notifications only to active Admins (not `e2e_admin_inactive`); one `APPROVAL_ROUTED_TO_ADMIN` audit (Finance to Admin, fund request ID, notified count) |
| 3 | Finance cannot decide its own routed request | implemented | yes | finance `POST /ajax/approval_decision_handler.php` on the request from 2 | `403`, "You cannot decide your own request."; request, steps and fund request unchanged; no decision audit |
| 4 | Admin self-review needs a reason and is audited | implemented | no | admin creates an Operating Fund request, then decides it without and with `override_reason` | `422`, "An Admin override reason (1-500 characters) is required to decide your own request." plus `ADMIN_OVERRIDE_REFUSED`, then success with `ADMIN_OVERRIDE` (`self_review` true, reason kept) and `APPROVAL_DECISION` |
| 5 | Admin without `approvals.review`: safe failure | **planned** | yes | finance `create_fund_request` after the Admin grant is removed | per code: `500`, "Could not create fund request."; no new fund request, approval, step, notification or audit rows. Needs a setup-account grant change and a restore, which are not approved |
| 6 | Notification insert failure rolls back the request | **disabled** | yes | finance `create_fund_request` with notification inserts failing | per code: `500`, "Could not create fund request."; nothing saved. Needs an approved fault-injection method; no trigger is created or granted |
| 7 | Disbursement before approval is rejected | implemented | no | admin creates a pending request; finance `create_disbursement` against it | `409`, "Only approved fund requests can be disbursed."; no disbursement row; amount disbursed unchanged; request still Pending Approval; no disbursement audit |
| 8 | Purchase request keeps its configured approver | implemented | no | purchasing `POST /ajax/purchasing_handler.php` `create_order` | `200`, "Purchase order submitted for approval."; steps equal the `purchase_request` template; notifications to that role; no routing audit |

"Implemented" means the code exists in `run.php`; it has never been
executed. The runner marks scenarios 1, 2, 3 (and 5, 6) **not ready** unless
the database shows a single Finance step holding `approvals.review` for
`fund_request` **and** the config has `d1_applied_confirmed: true`. If the
config claims D1 but the database disagrees, the run refuses. Scenario 1
also needs a fresh restore (no active allowance on the synthetic trip).

## Cleanup (needs separate approval; not done)

The runner stops its server and deletes its temporary router and log. All
database cleanup is manual, by a database administrator:

1. In one session, confirm `SELECT DATABASE()` and the `e2e_marker` value
   against the config.
2. `DROP DATABASE` that one database, by its exact name. Never by pattern.
3. Drop the two test accounts by their exact names.
4. Delete the working copy, its document-storage folder and the config file.

## Known limitations

- The working copy is a snapshot. The runner refuses a copy whose key files
  differ from the working tree, but other files can still drift: re-copy
  before each run.
- The copy's `.env` is checked, then hashed again just before the server
  starts. A change made while requests are running is not detected.
- PHP's built-in server is not Apache: `.htaccess` rules do not apply (the
  router covers the folders listed above), and requests are handled one at a
  time, so these scenarios do not test concurrency.
- Sessions use PHP's default session folder, shared with XAMPP; the separate
  `SESSION_NAME` keeps the cookies apart.
- The grant check relies on `SHOW GRANTS FOR CURRENT_USER()` output; an
  unrecognised line refuses rather than passes.
