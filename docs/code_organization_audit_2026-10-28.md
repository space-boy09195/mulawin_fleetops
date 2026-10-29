# Mulawin FleetOps: code organization audit (Phase 1, read-only)

Date: 2026-10-28. Branch `system-hardening`, HEAD `4cfc1ba`.

This document is an audit and a plan. **No application file, database row,
schema object, Git ref or existing document was changed to produce it.** The
only file created is this report. Nothing in it has been implemented.

Evidence came from file reads, `git` read commands, unauthenticated `GET`
requests to the local XAMPP site, and `SELECT`/`SHOW` queries against the local
database (no credentials are reproduced here). "Verified" in this report means
observed in code or command output, not tested in a browser or with database
writes.

---

## 1. Verified repository status

| Item | Observed |
|---|---|
| Branch | `system-hardening` |
| HEAD | `4cfc1ba` "Fix 403 page fatal error; align README and guide with new structure" |
| Ahead / behind `origin/system-hardening` | 5 ahead, 0 behind (`git rev-list --left-right --count`: `0 5`). Remote is at `1113d03`. Nothing pushed |
| Staged | none |
| Modified / deleted / renamed (unstaged) | none |
| Stashes | none |
| Untracked | `pages/create_analytics.php`, `pages/test.txt` (unchanged, see below) |

Recent commits (newest first):

| Commit | Subject |
|---|---|
| `4cfc1ba` | Fix 403 page fatal error; align README and guide with new structure |
| `bd6d6c8` | Checkpoint: move shared and core helpers |
| `7502f1b` | Restructure phases 1-4: path check, analytics view, module helpers |
| `721ce3d` | Checkpoint: hardening, workflow, analytics and docs work before restructuring |
| `f13b060` | Checkpoint: system-hardening work before module reorganization |
| `1113d03` | Adjustements: dashboard interface and login page sizing. (= origin) |

Previously reported exceptions, rechecked:

| File | Status now | Evidence |
|---|---|---|
| `pages/create_analytics.php` | Still present, untracked, unchanged | 83 bytes, SHA-1 `e67e1283…`, dated 2026-10-27 04:49. Content is a UTF-8 BOM, `<?php`, then `<?php  = chr(39) . " C:\xampp\htdocs\mulawin_fleetops\pages\ . chr(39);`, a parse error. Never committed. No code references it |
| `pages/test.txt` | Still present, untracked, unchanged | 7 bytes ("hello"), SHA-1 `aa5916ae…`, same timestamp. No references |

The state matches the historical report. No difference was found.

Environment (read-only checks):

| Item | Observed |
|---|---|
| PHP | 8.2.12 (XAMPP) |
| MariaDB | 10.4.32 |
| Apache `mod_rewrite` | loaded (`httpd.conf` line 163) |
| Node.js | v24.17.0 |
| Docker | not installed (`docker: command not found`) |
| Databases visible | `mulawin_fleetops_test` (the app's `.env` target), `mulawin_fleetops_restorecheck`. **No `mulawin_fleetops_e2e`** |
| App database account | `root` with `ALL PRIVILEGES` (local XAMPP). Credentials not reproduced |
| Playwright project | `C:\Users\acer\mulawin-e2e` (`@playwright/test` 1.55.1, `channel: 'msedge'`) |
| Last Playwright run | `test-results/.last-run.json` dated 2026-10-28 05:50: **failed**. The page snapshot shows Microsoft Edge "localhost is blocked" (`ERR_BLOCKED_BY_CLIENT`). The app was never reached, so this run says nothing about the app; the browser environment blocks `localhost` |

Automated tests available (no database needed), last run at `4cfc1ba`, all passing:

| Command | Last result |
|---|---|
| `php -l` on all PHP except `pages/create_analytics.php` | 155 files, 0 failures |
| `php tests/static/path_check.php` | PASS (604 includes, 416 application paths, 8 relative links, 9 denied folders) |
| `php tests/unit/run.php` | 532 passed, 0 failed |
| `node tests/unit/send_action.js` | 25 passed, 0 failed |
| `node tests/unit/attendance_rows.js` | 10 passed, 0 failed |
| `php tests/static/billing_period_test.php` | 254 passed, 0 failed |
| `php tests/static/run.php` | PASS (captured SQL: 0 confirmed, 0 uncertain; `.captured` removed) |
| `php tests/integration/run.php` | Preflight only, exit 0 (HTTP scenarios disabled by `E2E_HTTP_SCENARIOS_APPROVED = false`) |
| `.github/workflows/ci.yml` | Runs only `php -l` and `node --check` on push. It does not run `tests/` |

---

## 2. Current directory and file inventory

274 files (272 tracked + 2 untracked), excluding `assets/vendor/**`,
`.git/`, the ignored `.env`, `backups/` contents and uploaded business files.

| Folder | Files | Role | Web access (local Apache, observed) |
|---|---|---|---|
| `/` | `login.php`, `.htaccess`, `.env.example`, `.gitignore`, `README.md` | Entry point, web rules, setup | `login.php` 200; `.env` 403 |
| `pages/` | 48 (+2 untracked) | Page entry points; URLs are file paths | 44 redirect to login, 3 return 404 (disabled) |
| `ajax/` | 38 | AJAX and export entry points | 401 JSON when not logged in |
| `auth/` | 3 | Login/logout handler, disabled reset stub, CLI admin seeder | Public (handler) |
| `includes/` | 6 | Request bootstrap: `session`, `csrf`, `layout`, `env`; unused `alerts`, `recommendations` | 403 |
| `app/core/` | 8 | Cross-cutting services | 403 |
| `app/shared/` | 5 | Shared helpers | 403 |
| `app/modules/` | 26 in 9 modules | Domain helpers | 403 |
| `resources/views/` | 1 | `analytics/page.php` | 403 |
| `config/` | 3 | `app.php`, `database.php`, `enums.php` | 403 |
| `analytics/` | 4 | Legacy alert engine (unused) | 403 |
| `assets/` | 65 tracked (21 CSS, 30 JS, 5 images/README, vendor) | Static files | Public |
| `db/` | 30 | Legacy schema + 5 legacy migrations, target reference schema, 21 review-only target migrations | 403 |
| `scripts/` | 3 | CLI cron entry points, schema checker | 403 |
| `tests/` | 15 | Unit, static, integration, SQL dataset | 403 |
| `docs/` | 18 (+ this file) | Guides, audits, test checklists | 403 |
| `uploads/` | 2 (`.htaccess` files) | Document storage root; `files.storage_path` stores paths under it | 403 |
| `backups/` | git-ignored, has its own `.htaccess` | Backup output | 403 |
| `.github/workflows/ci.yml` | 1 | Lint-only CI | n/a |

`pages/` + `ajax/` + `auth/` + `login.php` are the only PHP entry points.
Everything under `app/`, `includes/`, `resources/`, `config/` is included,
never requested directly.

---

## 3. File-by-file responsibility findings

The full per-file table for all 147 application PHP files is in
**Appendix A** (generated from the include graph, literal path references,
header comments and SQL statements). Non-PHP files are in **Appendices B–E**.
This section summarises what the table shows.

### 3.1 Status counts (147 application PHP files, excluding `tests/`)

Recommendations in Appendix A: Retain 117, Retain in place (bootstrap) 3,
Retain path with optional internal split 1, Retain and review later 5,
Split 11, Retain pending owner decision 3, Owner decision 6, Owner decision
to delete 1. Change risk: High 36, Medium 90, Low 21.

| Status | Count | Files |
|---|---|---|
| Active | 123 | Most entry points and helpers (includes `ajax/budgets_handler.php`, listed separately below) |
| Active, included on denial | 1 | `pages/403.php` |
| Active, partly legacy | 1 | `app/modules/trips/trip_completion.php` (driver completion is refused by `ajax/trip_completion_handler.php:15-16`) |
| Active, URL only (no link in the app) | 5 | `pages/dashboard_{head,operations_head,dispatcher,maintenance,accounting}.php`. `ROLE_KEY_DASHBOARDS = []` (`includes/session.php:307`) sends every role to `pages/dashboard.php` |
| Active redirect (kept for old links) | 2 | `pages/maintenance_reports.php`, `pages/operations_performance.php` |
| Active stub | 1 | `auth/forgot_password_request_handler.php` (disables the old self-service reset; redirects to `login.php?reset=disabled`) |
| Disabled (returns 404 at line 1-7) | 3 | `pages/automation.php`, `pages/permissions.php`, `pages/recruitment.php`. Their AJAX handlers stay active behind Admin-only permissions |
| (of the Active rows) endpoint with no caller | 1 | `ajax/budgets_handler.php` (`list`, `save`; `budgets.manage`). No page or script calls it |
| Unused legacy | 6 | `includes/alerts.php`, `includes/recommendations.php`, `analytics/{descriptive,comparative,diagnostic,prescriptive}_analytics.php` |
| CLI only | 3 | `auth/seed_users.php`, `scripts/run_automations.php`, `scripts/generate_reminders.php` |
| Test tooling | 1 | `scripts/schema_check.php` |
| Obsolete stray (untracked) | 1 | `pages/create_analytics.php` |

### 3.2 Evidence for the unused / uncertain items

"No reference" was not taken alone. Literal includes, URL strings in PHP and
JS, the sidebar definition (`includes/layout.php:23-66`), notification links
(`NOTIFICATION_ENTITY_LINKS`, `app/core/notifications.php:18`), the
dashboard map (`ROLE_KEY_DASHBOARDS`), `page_js` script loading
(`includes/layout.php:631`) and database columns that store paths
(`information_schema` scan: only `files.storage_path`, currently 0 rows) were
all checked.

| File | Evidence | Classification |
|---|---|---|
| `includes/alerts.php` | Only function `getAnalyticsAlerts()`; called nowhere. Loads all four `analytics/*.php` | Unused legacy |
| `analytics/*.php` | Loaded only by `includes/alerts.php`. Their functions (`getDescriptiveAlerts`, `getComparativeAlerts`, `getDiagnosticAlerts`, `getPrescription`) are called only inside this group | Unused legacy; may still matter as capstone evidence of the analytics layers |
| `includes/recommendations.php` | Its 4 `get*Recommendations()` functions are called nowhere in application code. **But** `tests/static/run.php:30,32-34` preloads it for 4 dashboard targets | Unused by the app; still loaded by a test |
| `ajax/budgets_handler.php` | No page, JS or script posts to it. Table `budgets` exists | Active endpoint without UI. Owner decision |
| `pages/dashboard_*.php` (5) | Not in the sidebar, not in `ROLE_KEY_DASHBOARDS`, not linked. Covered by `tests/static/run.php` SQL capture | Reachable by URL; uncertain whether still wanted |
| `assets/css/dashboard_head.css` | No literal reference. `pages/dashboard_head.php:81` calls `layoutHead('Management Dashboard')` without CSS | Unreferenced asset |
| `assets/js/dashboard_{head,operations_head,dispatcher,maintenance,accounting,trip_completion}.js` (6) | No literal reference and no dynamic `page_js` construction found. `dashboard_trip_completion.js` belongs to the disabled driver completion feature | Unreferenced assets |

### 3.3 Duplicated logic found

| Duplicate | Where | Note |
|---|---|---|
| JS `postAjax()` | 8 files: `billing`, `dispatch`, `fleet_status`, `incidents`, `maintenance`, `parts`, `recycle_bin`, `users` (`assets/js/`) | 6 copies are identical (normalised hash `a61e8b18`); `dispatch.js` and `fleet_status.js` differ. `layout.js` already exposes `window.sendAction` |
| JS `showAlert` / `hideAlert` / `setBusy` | 8-9 files (same set + `documents.js`, `dispatch_planning.js`) | Small UI helpers copied per page |
| JS `escapeHtml` | `incidents.js`, `maintenance.js` | |
| Inline JS `buildCrewPayRow()` | `pages/trip_costs.php:449`, `pages/trip_pay.php:180` | Not identical; needs reconciliation before any merge |
| Truck status writes | `ajax/maintenance_handler.php` (`setTruckMaintenanceStatus`), `ajax/trucks_handler.php`, `ajax/update_trip_status.php`, `ajax/update_truck_status.php`, `app/modules/trips/dispatch_assignment.php` | Five writers of `trucks.status`. `app/modules/fleet/truck_status_history.php` records history. Consolidation would touch transaction boundaries: **behaviour-sensitive, not a structural task** |
| Payroll listing | `pages/billing.php:146-227` (Payroll tab: `payroll_records` + `trip_pay_records`) and `pages/payroll.php` | Two pages read payroll. Moving the tab changes what users see: **product decision, not structural** |
| PHP function names in tests | `tests/static/{billing_period_test,capture_sql}.php`, `tests/unit/run.php` redefine `requireLogin`, `currentUserId`, `getDBConnection`, `layoutHead`, … | Intentional stubs; correct |

No duplicated PHP helper was found in application code after Phases 4-5.
`jsonOk`/`jsonFail`/`requirePostMethod`/`requiredInt` exist only in
`app/shared/validate.php`; `isValidDate` only in `includes/session.php`.

---

## 4. Files containing mixed or excessive responsibilities

Measured on the current files. "Data section" = PHP before the first
markup; SQL = statement keywords in the file.

| File | Lines | SQL | Structure observed | Unit/static test coupling |
|---|---|---|---|---|
| `pages/maintenance.php` | 1,651 | 17 | Guard + period filter (41) → records (62) → inspections (102) → checklists (273) → dropdowns (321) → view selection (454) → markup from 544 with tabs, filters and 6 modals; also renders `pages/maintenance_records.php` via `MAINTENANCE_RECORDS_PAGE` | `tests/unit/run.php` reads its source 7× (markup links at 887, 906, 1243-1304) |
| `pages/dispatch.php` | 1,088 | 11 | Data 1-180 (period filter, dropdowns, routes, requests); markup 180-1088 with 6 modals and the route-management tab | 2 unit references |
| `ajax/maintenance_handler.php` | 1,041 | 43 | 13 actions in one file; 5 local helpers (`activeTripForTruck`, `activeWorkOrderForTruck`, `lockTruckStatus`, `setTruckMaintenanceStatus`, `inspectionTripState`); writes 6 tables | 2 unit references |
| `assets/js/maintenance.js` | 958 | – | One IIFE: AJAX/alert helpers, minimum-time timers, filters, inspection renderer (SVG views), pre-departure sections | 2 unit references |
| `pages/users.php` | 898 | 6 | Data 15-147 (users, roles, employees, licence alerts, positions, stats); markup with 5 modals | none |
| `pages/billing.php` | 864 | 8 | Data 25-227 (billings, collections, unbilled trips, employees, payroll, crew pay); markup with 4 tabs (incl. Payroll) and 4 modals | `tests/static/billing_period_test.php` runs its data section |
| `pages/parts.php` | 811 | 5 | Data 20-168; markup with 3 modals | none |
| `pages/analytics.php` | 728 | 19 | Already split in Phase 3: data and calculations (1-725) + `resources/views/analytics/page.php` | static capture |
| `pages/trip_workflow.php` | 690 | 8 | Data 1-219 (trip, steps, documents, allowance); markup with 8 forms | 5 unit references |
| `ajax/users_handler.php` | 668 | 24 | 6 actions; 5 helpers (`assertSingleActiveUser`, `extractEmpFields`, `validateEmpFields`, `duplicateEmployeeExists`, `employmentStatusFor`) defined between action blocks (lines 20, 413-511) | none |
| `includes/layout.php` | 654 | 4 | Navigation config (23-66), visibility rules, notification and announcement queries (185-295), page shell (296-497), announcements panel (498-617), footer/script loader (618-654) | 1 unit, 1 integration |
| `pages/fleet_status.php` | 584 | 2 | Data 1-60; markup with 4 modals | none |
| `pages/dashboard.php` | 581 | 14 | Data and role branching 1-464; markup 464-581 | 2 unit, 1 static |
| `pages/trip_costs.php` | 532 | 5 | Data, markup and one inline `<script>` (duplicated `buildCrewPayRow`) | none |

Common pattern: every page already keeps **its data section above its
markup** (no SQL inside the HTML). That makes the analytics method
(markup moved verbatim to `resources/views/<page>/page.php`, data section
untouched) applicable to every page above, with low behaviour risk.

### 4.1 Separation plan per candidate

Only changes that make the file easier to read and change are proposed.
Splitting a file only to cut its line count was not considered.

| File | Keep in the entry point | Move to | Do **not** move now |
|---|---|---|---|
| `pages/maintenance.php` | Includes, guard, period filter, all queries, view selection | Markup → `resources/views/maintenance/page.php`; its 6 modals → `resources/views/maintenance/_modals.php` (one file, included by the view) | Queries into functions (later, optional) |
| `pages/dispatch.php` | Includes, guard, `refuseHelpers()`, queries | Markup → `resources/views/dispatch/page.php`; 6 modals → `resources/views/dispatch/_modals.php` | — |
| `pages/users.php` | Guard, queries | Markup → `resources/views/users/page.php` (+ `_modals.php`) | — |
| `pages/billing.php` | Guard, period filter, queries | Markup → `resources/views/billing/page.php` (+ `_modals.php`) | The Payroll tab (product decision, see §10) |
| `pages/parts.php` | Guard, queries | Markup → `resources/views/parts/page.php` | — |
| `pages/trip_workflow.php` | `tripPageAccess`, helper and scope checks, queries | Markup → `resources/views/trip_workflow/page.php` | — |
| `pages/fleet_status.php` | Guard, queries | Markup → `resources/views/fleet_status/page.php` | — |
| `pages/dashboard.php` | Guard, role branching, queries | Markup → `resources/views/dashboard/home.php` | — |
| `pages/trip_costs.php` | Guard, queries | Markup → `resources/views/trip_costs/page.php` | Inline `buildCrewPayRow` (differs from `trip_pay.php`'s copy) |
| `ajax/maintenance_handler.php` | Includes, guard, `requirePostMethod`, `enforceCsrf`, action dispatch, transactions, responses | The 5 local helpers, verbatim → `app/modules/maintenance/maintenance_work.php` | Per-action service functions (transaction boundaries live in the actions) |
| `ajax/users_handler.php` | Guard, CSRF, action blocks, transactions | The 5 helpers, verbatim → `app/modules/people/user_accounts.php` | Admin-protection logic inside actions |
| `assets/js/maintenance.js` | Everything for now | — | Splitting needs `layoutFoot()` to load more than one page script (`$GLOBALS['page_js']` holds one URL); that is a layout change across all pages. Defer |
| `includes/layout.php` | Path stays (41 includers) | Optional later: the notification/announcement queries (185-295) → `app/core/notifications.php` (already the notifications home); the navigation array (23-66) → `config/navigation.php` | Anything touching `layoutHead`/`layoutFoot` signatures |
| `ajax/submit_dispatch.php`, `ajax/update_trip_status.php`, `ajax/finance_handler.php`, `ajax/purchasing_handler.php` | All | — | Workflow-critical, transaction-heavy; covered by integration scenarios that have never run. Revisit after T7/T10 database-backed tests pass |

---

## 5. Dependency and path-risk findings

| Area | Finding | Consequence for future moves |
|---|---|---|
| Includes | 604 literal `__DIR__` includes; no dynamic includes outside `includes/layout.php`'s asset version lookup | Moves are mechanically checkable with `tests/static/path_check.php` |
| Bootstrap | `includes/session.php` is included by 96 files, `csrf.php` by 40, `layout.php` by 41, `env.php` by `config/app.php` and `config/database.php`. `env.php` finds `.env` at `__DIR__ . '/../.env'`; `layout.php` resolves asset files at `__DIR__ . '/../assets/...'` | Keep all four in `includes/`. Moving them touches login, permissions and CSRF for no functional gain |
| URLs | `pages/*.php`, `ajax/*.php`, `auth/login_handler.php`, `login.php` are URLs. Referenced from: sidebar (`includes/layout.php:25-66`), `NOTIFICATION_ENTITY_LINKS`, `home_tasks.php`, JS `fetch()` calls, form actions, redirects | Do not move or rename entry points. Splits keep the entry file and include from it |
| Uploads | `app/modules/documents/document_storage.php` resolves `dirname(__DIR__, 3)/uploads`; `files.storage_path` stores relative paths (`uploads/trucks/...` written by `app/modules/fleet/truck_records.php:146`) | `uploads/` must not move. Currently 0 `files` rows locally, so upload/download has never actually run here |
| Views | `resources/views/analytics/page.php` starts with `if (!defined('APP_BASE')) { http_response_code(404); exit; }` and is also denied by `.htaccess` | Reuse the same guard for every new view |
| Static SQL harness | `tests/static/capture_sql.php` executes a page's data section up to its first line that is exactly `?>`; `tests/static/run.php` lists helper files per target | View extraction keeps the data section, so the harness is unaffected. Moving queries into functions **does** change the harness lists |
| Unit tests | `tests/unit/run.php` has ~460 checks; ~234 read source text with `str_contains`/`strpos`/`preg_match`. It reads page sources by path, e.g. `$maintenancePage = file_get_contents($root . '/pages/maintenance.php')` (line 863) and `$workflowPage` (line 716) and asserts on markup | Moving markup out of a page requires changing which file those checks read (path only, assertions unchanged). This must be done in the same batch, and the assertion text must not be edited |
| Integration runner | `tests/integration/run.php` has `E2E_COPY_MUST_MATCH` (31 path literals) | Update in the same batch as any listed file moves |
| Billing period test | `tests/static/billing_period_test.php:83` runs `pages/billing.php`'s data section | Billing view extraction must leave lines up to the first `?>` byte-identical |
| Page scripts | One page script per page via `$GLOBALS['page_js']`; `layout.js`, `state_persistence.js`, `filter_inputs.js` on every page; Chart.js via `$GLOBALS['page_needs_chart']` | JS splitting needs a layout change; deferred |
| CSS sharing | `assets/css/billing.css` serves 5 pages; `dispatch.css` 2; `maintenance.css` 2; `trip_monitor.css` 3 | Renaming CSS files has wide impact; keep names |
| Web-root protection | Root `.htaccess` deny rule relies on `mod_rewrite`; `uploads/` and `backups/` have their own `Require all denied` | On Hostinger, verify `mod_rewrite` (or LiteSpeed equivalent) before relying on it |

---

## 6. Proposed target directory tree

Only folders that already exist or that hold files from the plan in §7.
Entry-point URLs, `uploads/` and `includes/` stay where they are. No
`public/` folder in this phase.

```
login.php                      (unchanged URL)
pages/                         (unchanged URLs; thin controllers: guard + data + include view)
ajax/                          (unchanged URLs; guard + CSRF + action dispatch + transactions)
auth/                          (unchanged)
includes/                      request bootstrap only: session.php, csrf.php, layout.php, env.php
config/                        app.php, database.php, enums.php  (+ optional navigation.php, batch L2)
app/
  core/                        audit, notifications, workflow_notifications, approval_workflow,
                               workflow_events, idempotency, soft_delete, app_settings   (unchanged)
  shared/                      validate, db_helpers, metrics, reporting, analytics_period (unchanged)
  modules/
    dashboard/                 dashboard_metrics, home_tasks                             (unchanged)
    documents/                 document_storage, document_upload, attachments, upload_validation
    finance/                   finance_records, client_rates
    fleet/                     truck_records, truck_status_history
    inventory/                 purchasing
    maintenance/               inspections, + maintenance_work.php        (batch H1)
    people/                    attendance, employee_profile, recruitment, + user_accounts.php (batch H2)
    system/                    backup, automation_runner, expiry_reminders
    trips/                     trip_number, trip_completion, route_options, dispatch_assignment,
                               trip_queries, crew_trip_access, dispatcher_scope, trip_workflow
resources/
  views/
    analytics/page.php         (exists)
    fleet_status/page.php      (V1)
    users/page.php, _modals.php            (V2)
    parts/page.php             (V3)
    trip_costs/page.php        (V4)
    billing/page.php, _modals.php          (V5)
    dashboard/home.php         (V6)
    dispatch/page.php, _modals.php         (V7)
    trip_workflow/page.php     (V8)
    maintenance/page.php, _modals.php      (V9)
assets/                        (unchanged names)
db/  scripts/  tests/  docs/  uploads/  backups/   (unchanged)
analytics/, includes/alerts.php, includes/recommendations.php   → owner decision (§10, D3)
```

Naming convention for new files:

- Lowercase `snake_case.php`, no spaces (Linux hosting is case-sensitive).
- Module helpers: `app/modules/<module>/<domain_noun>.php`, named for what
  they hold (`maintenance_work.php`, `user_accounts.php`), never for the
  page that calls them.
- Views: `resources/views/<page_name>/page.php`, partials prefixed `_`
  (`_modals.php`). Every view starts with the `APP_BASE` guard.
- Entry points keep their current names. New entry points go in `pages/` or
  `ajax/` only.
- Function names stay globally unique (no namespaces are used in this code
  base). Moving a function never renames it.

---

## 7. Exact move / split / merge / retain plan

### 7.1 Already correctly placed (retain)

`app/core/*` (8), `app/shared/*` (5), `app/modules/**` (26),
`resources/views/analytics/page.php`, `config/*` (3), `auth/*` (3),
`includes/{session,csrf,layout,env}.php`, all `ajax/*.php` and `pages/*.php`
as URL entry points, `scripts/*`, `tests/**`, `db/**`, `docs/**`,
`uploads/**` (structure), `assets/**` (names).

### 7.2 Split (code moved verbatim; entry file stays)

| # | Current responsibility | Destination | Reason | References to update | Compatibility risk | Tests | Batch |
|---|---|---|---|---|---|---|---|
| S1 | `pages/fleet_status.php` markup (from line 60) | `resources/views/fleet_status/page.php` | 524 lines of markup with 4 modals; no test coupling: a safe pilot | the page's `require` of the view | Low: same variables in scope; URL unchanged | path_check, unit, static, render-equivalence (Admin, Operations Head, Maintenance) | V1 |
| S2 | `pages/users.php` markup (from 148) | `resources/views/users/page.php`, `_modals.php` | 750 lines markup, 5 modals | page `require` | Low | as V1 + render-equivalence (Admin) | V2 |
| S3 | `pages/parts.php` markup (from 170) | `resources/views/parts/page.php` | 640 lines markup | page `require` | Low | as V1 (Purchasing, Maintenance) | V3 |
| S4 | `pages/trip_costs.php` markup incl. inline script | `resources/views/trip_costs/page.php` | Data and markup interleaved with inline JS | page `require` | Low | as V1 (Finance) | V4 |
| S5 | `pages/billing.php` markup (from 228) | `resources/views/billing/page.php`, `_modals.php` | 636 lines markup, 4 tabs | page `require`; data section must stay byte-identical up to its first `?>` for `billing_period_test.php` | Low-Medium | as V1 + `billing_period_test.php` + render-equivalence (Billing, Finance, Audit) | V5 |
| S6 | `pages/dashboard.php` markup (from 464) | `resources/views/dashboard/home.php` | Landing page for all roles | page `require`; 2 unit checks read the page source (update path only) | Medium (every role lands here) | as V1, all 12 roles | V6 |
| S7 | `pages/dispatch.php` markup (from 180) | `resources/views/dispatch/page.php`, `_modals.php` | 900 lines markup, 6 modals | page `require`; 2 unit checks (path only) | Medium | as V1 (Dispatcher ×2, Operations Head, Admin) | V7 |
| S8 | `pages/trip_workflow.php` markup (from 219) | `resources/views/trip_workflow/page.php` | 470 lines markup, 8 forms | page `require`; `$workflowPage` source read in `tests/unit/run.php:716` (path only) | Medium (Helper isolation stays in the controller) | as V1 + Helper own/other trip | V8 |
| S9 | `pages/maintenance.php` markup (from 544) | `resources/views/maintenance/page.php`, `_modals.php` | Largest file (1,651 lines), 6 modals; also serves `maintenance_records.php` | page `require`; `$maintenancePage` checks in `tests/unit/run.php:863-906` read markup (path only) | Medium | as V1 for both URLs and every `?view=` value | V9 |
| S10 | `ajax/maintenance_handler.php` local helpers (lines 37-86, 459-~470) | `app/modules/maintenance/maintenance_work.php` | Separates reusable truck/inspection-state checks from the 13 actions | handler `require_once`; 2 unit checks | Medium: functions lock rows (`FOR UPDATE`); must be called inside the same transactions as before (they are, since callers stay) | unit, static, path_check; DB-backed maintenance tests (T8) before relying on it | H1 |
| S11 | `ajax/users_handler.php` helpers (lines 20-60, 413-518) | `app/modules/people/user_accounts.php` | Helpers are defined between action blocks | handler `require_once` | Medium (user and admin protection) | unit, static; browser T5 (users) | H2 |

### 7.3 Merge (all deferred; need review first)

| Candidate | Why deferred |
|---|---|
| Identical JS `postAjax`/`showAlert`/`hideAlert`/`setBusy` copies → one shared file | Needs `layoutFoot()` to load an extra script on every page; two copies differ. Low value before the defense |
| `buildCrewPayRow` (`trip_costs.php`, `trip_pay.php`) | The two copies differ; reconciling them is a behaviour change |
| Truck-status writers (5 places) → one `app/modules/fleet/truck_status.php` | Changes transaction and locking code; business-critical |
| Payroll tab in `pages/billing.php` vs `pages/payroll.php` | Changes what Billing users see |

### 7.4 Retain in place deliberately

| File(s) | Reason |
|---|---|
| `includes/session.php`, `csrf.php`, `layout.php`, `env.php` | Bootstrap; ~180 includes across login, permissions and CSRF; relative lookups of `.env` and assets |
| `pages/maintenance_records.php`, `analytics_{fleet,expenses,maintenance}.php` | Thin wrappers (13-16 lines) that define a constant and include a page; already the right shape |
| `pages/maintenance_reports.php`, `operations_performance.php` | Redirects for old links |
| `auth/forgot_password_request_handler.php` | Neutralises old bookmarks to the disabled reset |
| `pages/automation.php`, `permissions.php`, `recruitment.php` | Disabled on purpose; enabling or deleting is an owner decision |
| `assets/css/*`, `assets/js/*` names | URLs referenced by pages; renaming has no functional benefit |
| `db/**` | Schema evidence and review-only migrations |

### 7.5 Uncertain (owner decision before any action)

`pages/create_analytics.php`, `pages/test.txt`, `includes/alerts.php`,
`includes/recommendations.php`, `analytics/*.php`, `ajax/budgets_handler.php`,
the 5 `pages/dashboard_*.php`, `assets/css/dashboard_head.css`, the 6
unreferenced `assets/js/dashboard_*.js`. See §10.

---

## 8. Ordered implementation batches

One batch = one commit. The method for V-batches is the one proven in Phase
3 (analytics): markup moved verbatim, data section untouched, rendered HTML
compared byte-for-byte before and after.

Common to every batch:

- **Before:** `git status` clean except the two protected untracked files;
  all suites pass (§1 commands).
- **After:** the same suites, `git diff --check`, and the batch-specific
  checks below.
- **Pass:** every command passes **and** the batch-specific equivalence
  check passes **and** `git diff` shows only the intended files.
- **Fail:** stop and report; do not continue to the next batch.
- **Recovery:** before commit, undo only the batch's own edits (delete the
  new view file and put the moved lines back from `git show HEAD:<page>`).
  Each step is shown for approval, and nothing else in the working tree is
  touched. After commit, `git revert <batch commit>` (non-destructive). No
  `reset`, `clean`, `stash` or forced checkout.
- **Render-equivalence harness:** the Phase 3 scratchpad method. Run the old
  page (from `HEAD`) and the new page with stubbed session/permission
  functions and a `SELECT`-only PDO wrapper against `mulawin_fleetops_test`.
  It refuses anything that is not `SELECT`. Then compare output bytes for
  each listed role and query variant. A negative control (a deliberate
  one-character change) must be detected.

| Batch | Files | Change | Prerequisites | Expected impact | Batch-specific check |
|---|---|---|---|---|---|
| **V1** | `pages/fleet_status.php`, new `resources/views/fleet_status/page.php` | Markup from line 60 moved verbatim; page ends with the view `require` | Approval of this plan | None visible | Render-equivalence: Admin, Operations Head, Maintenance, Dispatcher; view URL returns 403 |
| V2 | `pages/users.php`, `resources/views/users/{page,_modals}.php` | Same method | V1 passed | None | Admin; licence-alert banner on and off |
| V3 | `pages/parts.php`, `resources/views/parts/page.php` | Same | V2 | None | Purchasing, Maintenance; period filters |
| V4 | `pages/trip_costs.php`, `resources/views/trip_costs/page.php` | Same, inline script moves with the markup | V3 | None | Finance; Admin |
| V5 | `pages/billing.php`, `resources/views/billing/{page,_modals}.php` | Same; first `?>` line unchanged | V4 | None | `billing_period_test.php` 254/0; Billing, Finance, Audit, Admin × 3 periods |
| V6 | `pages/dashboard.php`, `resources/views/dashboard/home.php`; `tests/unit/run.php` path for its source checks | Same | V5 | None | All 12 roles; unit count unchanged (532) |
| V7 | `pages/dispatch.php`, `resources/views/dispatch/{page,_modals}.php`; unit path | Same | V6 | None | Both dispatchers, Operations Head, Admin |
| V8 | `pages/trip_workflow.php`, `resources/views/trip_workflow/page.php`; unit `$workflowPage` path | Same | V7 | None | Trips at 3 different steps; Helper own trip |
| V9 | `pages/maintenance.php`, `resources/views/maintenance/{page,_modals}.php`; unit `$maintenancePage` path | Same | V8 | None | Both URLs × every `view=` value × Maintenance/Admin/Operations Head |
| H1 | `ajax/maintenance_handler.php`, new `app/modules/maintenance/maintenance_work.php` | 5 functions moved verbatim; `require_once` added | V9 and **database-backed T8 available** | None | Function bodies byte-identical (diff); unit/static/path pass; T8 maintenance tests on a disposable or backed-up DB |
| H2 | `ajax/users_handler.php`, new `app/modules/people/user_accounts.php` | 5 functions moved verbatim | H1 | None | Bodies identical; browser T5 for Users |
| L1 (optional) | `.github/workflows/ci.yml` | Add `php tests/unit/run.php`, `php tests/static/run.php`, `php tests/static/path_check.php` | Owner approval | CI catches regressions | CI run green |
| L2 (optional) | `includes/layout.php` → `config/navigation.php` (nav array only) | Move `getNavItems()` data | All V-batches | None | All roles' sidebars identical in render-equivalence |

Not scheduled (need separate decisions): JS helper merge, truck-status
consolidation, payroll tab, query-function extraction from pages, the
`public/` web root.

---

## 9. Testing and verification plan

### 9.1 What the current suites actually prove

| Suite | Proves | Does not prove |
|---|---|---|
| `php -l`, `node --check` (CI) | Syntax | Anything at runtime |
| `tests/static/path_check.php` | Every literal include and app path resolves; `.htaccess` denies internal folders | Dynamic paths; that Apache honours the rules |
| `tests/unit/run.php` (532) | Pure helpers (metrics, finance definitions, timers, periods, routing, scope); ~234 source-text checks that specific code and markup exist | That pages render, permissions hold at runtime, or SQL succeeds |
| `tests/static/run.php` + `billing_period_test.php` | SQL built by analytics, dashboards and billing matches the target schema model; period bounds | Results against real data; writes |
| JS tests (2) | `sendAction` and attendance row logic | Page scripts |
| `tests/integration/run.php` | Preflight only | Nothing against a database yet |

**Depend on the current folder structure:** `path_check.php` (by design),
`tests/static/run.php` target lists, `tests/unit/run.php` (24 path literals),
`tests/integration/run.php` (31 path literals), `scripts/schema_check.php`.

**Not covered anywhere:** logged-in permission enforcement per role,
CSRF rejection, session timeout, the dispatch → approval → trip lifecycle,
maintenance and inspection writes, billing/collection writes, fund requests
and disbursement, payroll, upload/download, audit rows, the 403 page for a
logged-in user.

### 9.2 Lightweight strategy (no Docker, no e2e database)

1. **Per refactor batch (no database writes):** the automated suites plus the
   render-equivalence harness (SELECT-only, refuses writes). This is enough
   to show a structural change did not change output.
2. **Browser smoke (writes only login/logout audit rows):** the existing
   Playwright project. **Blocker found:** Edge currently blocks `localhost`
   (`ERR_BLOCKED_BY_CLIENT`, last run 2026-10-28 05:50). Likely causes are an
   extension or a browser policy. Fix options for the owner: run with a clean
   Edge profile, allow `localhost` in the blocking extension or policy, or use
   `127.0.0.1` as `baseURL`. Then run `admin-smoke.spec.js` and
   `analytics-phase3.spec.js`. Passwords are entered through environment
   variables set by the owner; they are never pasted into chat.
3. **Database-backed workflow tests:** these need a target the owner accepts.
   Either (a) a backup of `mulawin_fleetops_test` before testing and a
   restore after, or (b) the disposable `mulawin_fleetops_e2e` described in
   `tests/integration/README.md`. Neither has been done. No write test should
   run until one is chosen and confirmed.

### 9.3 Minimum checks per batch type

| Batch type | Automated | Equivalence | Browser (when available) | DB-backed |
|---|---|---|---|---|
| V (view extraction) | all suites | byte-identical HTML per role | open the page per listed role; console clean | not needed |
| H (helper extraction) | all suites | function bodies identical | page actions for the module | needed before commit (H1: T8; H2: user edits) |
| L (config/CI) | all suites | sidebar identical | sidebar per role | not needed |

---

## 10. Known risks, unresolved decisions, items requiring approval

| # | Decision for the owner | Evidence | Recommendation |
|---|---|---|---|
| D1 | Approve the plan and start with **V1** | §7-8 | Approve V1 only, then review its result |
| D2 | Delete `pages/create_analytics.php` and `pages/test.txt` | Untracked, no references, parse error | Delete (separate authorisation); the `.php` can expose a path in a parse error if `display_errors` is on |
| D3 | Legacy alert engine: `includes/alerts.php`, `includes/recommendations.php`, `analytics/*.php` | Unused by the app; `recommendations.php` still loaded by `tests/static/run.php` | Keep until after the defense if the write-up cites the analytics layers. If removed, drop the 4 test-list entries in the same commit |
| D4 | `ajax/budgets_handler.php` has no UI | No caller | Keep (harmless, Admin/Finance only) or build/retire later; not structural |
| D5 | Five URL-only role dashboards and seven unreferenced dashboard assets | `ROLE_KEY_DASHBOARDS = []` | Keep until you confirm they are no longer wanted |
| D6 | Disabled pages (automation, permissions, recruitment) keep live AJAX handlers | Admin-only permissions | Leave as is; document |
| D7 | Database-backed test target (backup-and-restore of `mulawin_fleetops_test` vs disposable e2e DB) | No e2e DB; app uses `root` | Backup-and-restore, given the deadline |
| D8 | Edge blocks `localhost` for Playwright | `.last-run.json`, error context | Fix the browser environment before any browser verification claim |
| D9 | Payroll recorded directly as `Disbursed`; Payroll approval template unused | `ajax/payroll_handler.php:136-147`; `approval_decision_handler.php:149-158` | Business decision, not structural |
| D10 | Fund requests route to Admin in the current database | `approval_workflow_steps` | Business decision |

Risks that stay regardless of the plan:

- No logged-in browser test has ever passed in this environment.
- No database-backed workflow test has been run; upload/download has never
  run locally (0 `files` rows).
- Hostinger compatibility is unverified: `mod_rewrite` or LiteSpeed rewrite
  support, case-sensitive paths, a dedicated database user, `display_errors`
  off, writable `uploads/` and `backups/`.
- `tests/unit/run.php` couples to source text. Any batch that moves markup
  must update the source path in the same commit, without editing assertion
  text.

---

## Appendix A: Application PHP files (147)

Generated from the include graph and literal references. "Included /
referenced by" counts code files only. Mentions in Markdown and SQL files
are excluded. Risk is the risk of **changing** the file.

| Path | Lines | Responsibility | Status | Included / referenced by | Includes | Tables written | Mixed concerns | Recommendation | Change risk |
|---|---|---|---|---|---|---|---|---|---|
| `ajax/announcement_handler.php` | 180 | action=add    — Head Management only | Active | 4 app (`assets/js/announcements.js`, `assets/js/layout.js`, …) | 9 | announcements | — | Retain | Medium |
| `ajax/approval_decision_handler.php` | 216 | Decide the current approval step of a purchase request, fund request, | Active | 2 app, 2 test (`assets/js/requests.js`, `pages/requests.php`, …) | 10 | fund_requests, payment_vouchers, payroll_periods | — | Retain | High |
| `ajax/attendance_export.php` | 68 | CSV export of attendance (GET, hr.attendance.report) | Active | 1 app (`pages/attendance.php`) | 3 | — | — | Retain | Medium |
| `ajax/attendance_handler.php` | 192 | Admin and Payroll (hr.attendance.manage) record attendance for employees. | Active | 2 app, 1 test (`app/modules/people/attendance.php`, `pages/attendance.php`, …) | 6 | attendance_records, status, attendance | — | Retain | Medium |
| `ajax/automation_handler.php` | 62 | Automation controls for independently managed, read-only jobs. | Active | 1 app (`pages/automation.php`) | 6 | automation_settings | — | Retain | Medium |
| `ajax/backup_handler.php` | 59 | action=create   — Admin only: full database dump + document copy | Active | 2 app (`assets/js/backup.js`, `pages/backup.php`) | 7 | — | — | Retain | High |
| `ajax/billing_export.php` | 72 | Exporting needs billing.export; viewing or managing billing is not enough. | Active | 1 app (`pages/billing.php`) | 4 | — | — | Retain | Medium |
| `ajax/billing_handler.php` | 248 | Billing and collections on the target schema: | Active | 1 app (`assets/js/billing.js`) | 8 | billings, billing_items, collections | — | Retain | High |
| `ajax/budgets_handler.php` | 103 | action=list — return current budget amounts for a set of | Active | 0 app | 6 | budgets | — | Retain | Medium |
| `ajax/client_master_data_handler.php` | 204 | Client locations (target client_locations: name, address, contact, | Active | 1 app (`assets/js/client_master_data.js`) | 8 | client_locations, client_rates | — | Retain | Medium |
| `ajax/clients_handler.php` | 116 | Client registry on the target clients table (client_name, | Active | 1 app (`assets/js/clients.js`) | 7 | clients | — | Retain | Medium |
| `ajax/dispatch_instructions_handler.php` | 265 | Operations Head dispatch instructions on the target schema. | Active | 1 app, 1 test (`assets/js/dispatch_planning.js`) | 9 | client_bookings | — | Retain | Medium |
| `ajax/document_download.php` | 54 | Authenticated document download (GET, documents.download); streams files from uploads/ | Active | 5 app (`pages/documents.php`, `pages/maintenance.php`, …) | 6 | — | — | Retain | High |
| `ajax/document_handler.php` | 167 | Document upload and delete (documents.upload / documents.manage) | Active | 4 app, 1 test (`assets/js/billing.js`, `assets/js/documents.js`, …) | 13 | — | — | Retain | High |
| `ajax/finance_handler.php` | 411 | Fund requests, accounts payable, payment vouchers and disbursements on | Active | 2 app, 3 test (`assets/js/trip_workflow.js`, `pages/finance.php`, …) | 11 | fund_requests, disbursements, payment_vouchers, accounts_payable | writes 4 tables | Retain; review later (§7) | High |
| `ajax/log_incident.php` | 155 | Trip problem reports on the target trip_incidents table. | Active | 1 app (`assets/js/incidents.js`) | 9 | trip_incidents | — | Retain | Medium |
| `ajax/maintenance_handler.php` | 1041 | Maintenance actions on the target schema: | Active | 1 app, 1 test (`assets/js/maintenance.js`) | 13 | trucks, preventive_maintenance_schedules, maintenance_records, maintenance_findings +2 | writes 6 tables; many actions | Split (§7) | High |
| `ajax/notifications_handler.php` | 35 | action=clear — marks the signed-in user's unread notifications as read | Active | 1 app (`assets/js/layout.js`) | 4 | notifications | — | Retain | Medium |
| `ajax/office_supplies_handler.php` | 101 | Office supplies on the target schema: office_supplies (quantity_on_hand, | Active | 1 app (`pages/office_supplies.php`) | 6 | office_supplies, office_supply_transactions | — | Retain | Medium |
| `ajax/parts_handler.php` | 366 | Parts inventory on the target schema: | Active | 2 app (`assets/js/parts.js`, `pages/parts.php`) | 8 | parts, inventory, inventory_transactions, maintenance_parts +1 | writes 5 tables | Retain | Medium |
| `ajax/payroll_handler.php` | 217 | action=list   — recent payroll records (for the Payroll tab table) | Active | 3 app, 1 test (`assets/js/billing.js`, `pages/billing.php`, …) | 8 | payroll_periods, payroll_records, payroll_deductions, payroll_disbursements | writes 4 tables | Retain | High |
| `ajax/permissions_handler.php` | 359 | Role, permission-grant and approval-step management (Admin; page disabled) | Active | 1 app (`assets/js/permissions.js`) | 6 | roles, approval_workflow_steps, role_permissions | — | Retain | High |
| `ajax/purchasing_handler.php` | 310 | Suppliers, purchase requests / orders and receiving on the target schema | Active | 1 app, 1 test (`pages/purchasing.php`) | 8 | suppliers, purchase_requests, purchase_orders, purchase_request_items +5 | writes 9 tables | Retain; review later (§7) | Medium |
| `ajax/recruitment_handler.php` | 100 | Recruitment records CRUD (hr.recruitment.*; page disabled) | Active | 1 app (`pages/recruitment.php`) | 6 | recruitment_records | — | Retain | Medium |
| `ajax/recycle_bin_handler.php` | 119 | action=restore — Head Management only | Active | 1 app (`assets/js/recycle_bin.js`) | 9 | files | — | Retain | High |
| `ajax/review_dispatch.php` | 315 | Records the current approval step of a dispatch request. | Active | 2 app (`assets/js/dispatch.js`, `assets/js/requests.js`) | 13 | dispatch_requests | — | Retain | High |
| `ajax/route_request_handler.php` | 176 | Route requests on the target schema. routes has no approval columns, so: | Active | 2 app (`assets/js/dispatch.js`, `assets/js/requests.js`) | 8 | routes | — | Retain | Medium |
| `ajax/routes_handler.php` | 182 | Route master data create/edit/toggle (routes.manage) | Active | 1 app (`assets/js/dispatch.js`) | 7 | routes | — | Retain | Medium |
| `ajax/submit_dispatch.php` | 340 | Dispatcher submits a trip for Operations Head approval. | Active | 1 app, 1 test (`assets/js/dispatch.js`) | 14 | trips, dispatch_requests, client_bookings | — | Retain; review later (§7) | High |
| `ajax/trip_completion_handler.php` | 190 | Trip completion is encoded by the assigned dispatcher (ajax/update_trip_status.php). | Active | 1 app (`assets/js/dashboard_trip_completion.js`) | 8 | trip_completion_reports | — | Retain | Medium |
| `ajax/trip_costs_handler.php` | 181 | Trip expense records create/edit (finance.manage / finance.expenses.edit) | Active | 1 app (`pages/trip_costs.php`) | 8 | expense_records | — | Retain | Medium |
| `ajax/trip_operations_export.php` | 120 | Exporting trip operations needs trips.export (not the generic reports.export). | Active | 1 app (`pages/trip_monitor.php`) | 6 | — | — | Retain | Medium |
| `ajax/trip_pay_handler.php` | 208 | Per-trip wages for the crew actually assigned to the trip (driver, second | Active | 3 app (`assets/js/billing.js`, `pages/trip_costs.php`, …) | 7 | trip_pay_records | — | Retain | Medium |
| `ajax/trip_workflow_handler.php` | 522 | Trip workflow steps 5-11 on the target schema (step map in | Active | 1 app, 1 test (`assets/js/trip_workflow.js`) | 12 | trips, truck_assignments, dispatch_clearances, trip_updates +4 | writes 8 tables | Retain | High |
| `ajax/trucks_handler.php` | 346 | Truck add/edit including truck photo upload (fleet.manage) | Active | 4 app (`app/modules/fleet/truck_records.php`, `assets/js/fleet_status.js`, …) | 11 | trucks | — | Retain | Medium |
| `ajax/update_trip_status.php` | 328 | AJAX endpoint — moves a trip to its next operational status and logs a | Active | 3 app, 1 test (`ajax/trip_completion_handler.php`, `assets/js/dashboard_dispatcher.js`, …) | 15 | trucks, truck_assignments, trip_completion_reports, client_bookings +1 | writes 5 tables | Retain; review later (§7) | High |
| `ajax/update_truck_status.php` | 115 | AJAX endpoint — updates a truck's status | Active | 2 app (`ajax/trucks_handler.php`, `assets/js/fleet_status.js`) | 8 | trucks | — | Retain | Medium |
| `ajax/users_handler.php` | 668 | User accounts and employee records: add/edit user, reset password, add/edit employee | Active | 1 app (`assets/js/users.js`) | 8 | users, employees | many actions | Split (§7) | High |
| `analytics/comparative_analytics.php` | 118 | COMPARATIVE LAYER — Step 2 of the analytics system. | Unused (legacy) | 1 app (`includes/alerts.php`) | 1 | — | — | Owner decision (§10) | Low |
| `analytics/descriptive_analytics.php` | 92 | DESCRIPTIVE LAYER — Step 1 of the analytics system. | Unused (legacy) | 1 app (`includes/alerts.php`) | 1 | — | — | Owner decision (§10) | Low |
| `analytics/diagnostic_analytics.php` | 120 | DIAGNOSTIC LAYER — Step 3 of the analytics system. | Unused (legacy) | 1 app (`includes/alerts.php`) | 2 | — | — | Owner decision (§10) | Low |
| `analytics/prescriptive_analytics.php` | 95 | PRESCRIPTIVE LAYER — Final step of the analytics system. | Unused (legacy) | 4 app (`analytics/comparative_analytics.php`, `analytics/descriptive_analytics.php`, …) | 0 | — | — | Owner decision (§10) | Low |
| `app/core/app_settings.php` | 20 | Read/write app_settings key-value rows | Active | 5 app (`ajax/permissions_handler.php`, `ajax/users_handler.php`, …) | 1 | app_settings, setting_value | — | Retain | Medium |
| `app/core/approval_workflow.php` | 493 | Target-schema approval engine: | Active | 6 app, 2 test (`ajax/approval_decision_handler.php`, `ajax/finance_handler.php`, …) | 1 | approval_requests, approval_steps, approval_history | — | Retain | High |
| `app/core/audit.php` | 399 | Write to the target audit_logs table — call after any critical action. | Active | 37 app, 1 test (`ajax/announcement_handler.php`, `ajax/approval_decision_handler.php`, …) | 1 | audit_logs | — | Retain | High |
| `app/core/idempotency.php` | 28 | Request idempotency keys (idempotency_requests) for double-submit protection | Active | 8 app, 1 test (`ajax/approval_decision_handler.php`, `ajax/dispatch_instructions_handler.php`, …) | 0 | idempotency_requests | — | Retain | High |
| `app/core/notifications.php` | 166 | Target notifications table: user_id, notification_type, title, message, | Active | 9 app, 2 test (`ajax/approval_decision_handler.php`, `ajax/dispatch_instructions_handler.php`, …) | 1 | notifications | — | Retain | Medium |
| `app/core/soft_delete.php` | 282 | Shared "soft delete" archive system (target table deleted_records, T-S1). | Active | 5 app, 1 test (`ajax/announcement_handler.php`, `ajax/document_handler.php`, …) | 0 | deleted_records | — | Retain | Medium |
| `app/core/workflow_events.php` | 42 | Target workflow_events table: one row per status change or workflow step | Active | 4 app (`ajax/log_incident.php`, `ajax/trip_completion_handler.php`, …) | 0 | workflow_events | — | Retain | Medium |
| `app/core/workflow_notifications.php` | 368 | Handoff notifications: when a trip, allowance or dispatch-instruction step | Active | 7 app, 1 test (`ajax/approval_decision_handler.php`, `ajax/dispatch_instructions_handler.php`, …) | 2 | — | — | Retain | Medium |
| `app/modules/dashboard/dashboard_metrics.php` | 299 | Shared dashboard figures on the target schema, so every role dashboard | Active | 6 app, 3 test (`pages/dashboard.php`, `pages/dashboard_accounting.php`, …) | 3 | — | — | Retain | Medium |
| `app/modules/dashboard/home_tasks.php` | 120 | "Work to Address" items on the Home page: actions that wait for the | Active | 1 app, 2 test (`pages/dashboard.php`, …) | 1 | — | — | Retain | Medium |
| `app/modules/documents/attachments.php` | 53 | Links documents to business records through the target document_links | Active | 2 app (`ajax/document_handler.php`, `app/modules/documents/document_upload.php`) | 0 | document_links, document_id | — | Retain | High |
| `app/modules/documents/document_storage.php` | 61 | Resolves the uploads/ storage directory and stored-file paths | Active | 6 app (`ajax/document_download.php`, `ajax/document_handler.php`, …) | 1 | — | — | Retain | High |
| `app/modules/documents/document_upload.php` | 171 | Stores an uploaded file in document storage and records it in the target | Active | 7 app (`ajax/document_download.php`, `ajax/document_handler.php`, …) | 4 | files, documents | — | Retain | High |
| `app/modules/documents/upload_validation.php` | 126 | Server-side upload checks shared by every upload entry point. Nothing here | Active | 3 app (`ajax/document_handler.php`, `ajax/trucks_handler.php`, …) | 0 | — | — | Retain | High |
| `app/modules/finance/client_rates.php` | 70 | Client rate cards on the target client_rates table: | Active | 3 app, 1 test (`ajax/billing_handler.php`, `ajax/client_master_data_handler.php`, …) | 0 | — | — | Retain | Medium |
| `app/modules/finance/finance_records.php` | 118 | Shared finance definitions on the target schema, used by the billing, | Active | 15 app, 4 test (`ajax/billing_export.php`, `ajax/payroll_handler.php`, …) | 1 | — | — | Retain | Medium |
| `app/modules/fleet/truck_records.php` | 168 | Truck profile data that the legacy trucks table held in columns and the | Active | 3 app, 1 test (`ajax/trucks_handler.php`, `pages/fleet_status.php`, …) | 0 | truck_documents, files, documents, document_links | — | Retain | Medium |
| `app/modules/fleet/truck_status_history.php` | 36 | Truck status history on the target schema: workflow_events rows with | Active | 6 app (`ajax/maintenance_handler.php`, `ajax/review_dispatch.php`, …) | 1 | — | — | Retain | Medium |
| `app/modules/inventory/purchasing.php` | 88 | Purchasing on the target schema: | Active | 4 app (`ajax/approval_decision_handler.php`, `ajax/finance_handler.php`, …) | 0 | purchase_requests, purchase_orders, suppliers | — | Retain | Medium |
| `app/modules/maintenance/inspections.php` | 268 | Vehicle inspections and the pre-trip checklist on the target schema. | Active | 2 app, 1 test (`ajax/maintenance_handler.php`, `pages/maintenance.php`, …) | 0 | inspection_items, vehicle_inspections, inspection_findings | — | Retain | Medium |
| `app/modules/people/attendance.php` | 98 | Attendance statuses and the checks shared by pages/attendance.php and | Active | 2 app, 1 test (`ajax/attendance_handler.php`, `pages/attendance.php`, …) | 0 | — | — | Retain | Medium |
| `app/modules/people/employee_profile.php` | 73 | Employee profile lookup for the logged-in user | Active | 4 app, 2 test (`ajax/users_handler.php`, `app/modules/trips/crew_trip_access.php`, …) | 0 | that, employees | — | Retain | Medium |
| `app/modules/people/recruitment.php` | 44 | Recruitment on the target recruitment_records table (applicant_name, | Active | 2 app, 1 test (`ajax/recruitment_handler.php`, `pages/recruitment.php`, …) | 0 | — | — | Retain | Medium |
| `app/modules/system/automation_runner.php` | 227 | Read-only automation runner. Intended for CLI/Task Scheduler, never a page request. | Active | 2 app (`scripts/generate_reminders.php`, `scripts/run_automations.php`) | 6 | automation_runs | — | Retain | Medium |
| `app/modules/system/backup.php` | 129 | Database dump and document-storage copy into backups/ | Active | 2 app (`ajax/backup_handler.php`, `pages/backup.php`) | 1 | — | — | Retain | Medium |
| `app/modules/system/expiry_reminders.php` | 240 | Document/licence expiry reminder tiers and notifications | Active | 2 app, 1 test (`app/modules/system/automation_runner.php`, `scripts/generate_reminders.php`, …) | 2 | — | — | Retain | Medium |
| `app/modules/trips/crew_trip_access.php` | 80 | Helper trip isolation. | Active | 7 app (`ajax/trip_operations_export.php`, `pages/dashboard_dispatcher.php`, …) | 5 | — | — | Retain | High |
| `app/modules/trips/dispatch_assignment.php` | 184 | Truck and crew locking + conflicting-assignment checks on the target | Active | 4 app (`ajax/review_dispatch.php`, `ajax/submit_dispatch.php`, …) | 0 | in, truck_assignments, trucks, employees | — | Retain | High |
| `app/modules/trips/dispatcher_scope.php` | 253 | Dispatcher scope: the truck types a dispatcher may encode. | Active | 15 app, 2 test (`ajax/finance_handler.php`, `ajax/log_incident.php`, …) | 2 | — | — | Retain | High |
| `app/modules/trips/route_options.php` | 103 | Route pickup/delivery options (route_locations) and the | Active | 3 app (`ajax/clients_handler.php`, `ajax/route_request_handler.php`, …) | 0 | route_locations, routes, client_routes | — | Retain | Medium |
| `app/modules/trips/trip_completion.php` | 16 | Legacy driver trip-completion report helpers (feature disabled for crew) | Active (partly legacy) | 2 app, 1 test (`ajax/trip_completion_handler.php`, `pages/dashboard.php`, …) | 0 | — | — | Retain | Medium |
| `app/modules/trips/trip_number.php` | 41 | Allocates the next TRP-YYYY-NNNN number from trip_number_counters. Must run | Active | 1 app (`ajax/submit_dispatch.php`) | 0 | trip_number_counters, next_number | — | Retain | Medium |
| `app/modules/trips/trip_queries.php` | 126 | One SELECT for "a trip with its dispatch request" on the target schema. | Active | 16 app, 2 test (`ajax/review_dispatch.php`, `ajax/submit_dispatch.php`, …) | 1 | on | — | Retain | Medium |
| `app/modules/trips/trip_workflow.php` | 509 | Trip workflow on the target schema. | Active | 9 app, 1 test (`ajax/maintenance_handler.php`, `ajax/review_dispatch.php`, …) | 2 | trips, status | — | Retain | High |
| `app/shared/analytics_period.php` | 111 | Pure helpers of the Analytics page (no database access except qRange / | Active | 1 app, 2 test (`pages/analytics.php`, …) | 0 | — | — | Retain | Medium |
| `app/shared/db_helpers.php` | 95 | Small, reusable query helpers for common patterns that were | Active | 23 app (`ajax/announcement_handler.php`, `ajax/billing_handler.php`, …) | 0 | — | — | Retain | Medium |
| `app/shared/metrics.php` | 107 | Shared metric definitions on the target schema. Dashboards, analytics, | Active | 7 app, 4 test (`app/modules/dashboard/dashboard_metrics.php`, `app/modules/finance/finance_records.php`, …) | 0 | — | — | Retain | Medium |
| `app/shared/reporting.php` | 55 | CSV export helpers shared by the export endpoints | Active | 4 app (`ajax/attendance_export.php`, `ajax/billing_export.php`, …) | 0 | — | — | Retain | Medium |
| `app/shared/validate.php` | 149 | Centralized input validation + JSON response helpers. | Active | 34 app, 1 test (`ajax/announcement_handler.php`, `ajax/approval_decision_handler.php`, …) | 0 | — | — | Retain | Medium |
| `auth/forgot_password_request_handler.php` | 17 | Self-service password change requests are disabled. | Active stub | 0 app | 1 | — | — | Retain | High |
| `auth/login_handler.php` | 369 | Handles the login form POST and the CSRF-protected logout POST action | Active | 3 app, 1 test (`includes/layout.php`, `login.php`, …) | 4 | login_attempts, failed | — | Retain | High |
| `auth/seed_users.php` | 286 | ONE-TIME script — run once to create test accounts | CLI only | 1 app (`config/app.php`) | 3 | users, employees | — | Retain | High |
| `config/app.php` | 74 | App-wide constants — session, security, RBAC role IDs | Active | 10 app, 2 test (`ajax/maintenance_handler.php`, `app/modules/documents/document_storage.php`, …) | 1 | — | — | Retain | High |
| `config/database.php` | 41 | Database connection using PDO (safer than mysqli) | Active | 88 app, 3 test (`ajax/announcement_handler.php`, `ajax/approval_decision_handler.php`, …) | 1 | — | — | Retain | High |
| `config/enums.php` | 93 | Single source of truth for fixed sets of allowed values. | Active | 29 app, 1 test (`ajax/announcement_handler.php`, `ajax/billing_handler.php`, …) | 0 | here | — | Retain | High |
| `includes/alerts.php` | 66 | ALERTS ORCHESTRATOR — The Boss/Traffic Controller for the Analytics Page. | Unused (legacy) | 3 app (`analytics/comparative_analytics.php`, `analytics/descriptive_analytics.php`, …) | 4 | — | — | Owner decision (§10) | Low |
| `includes/csrf.php` | 38 | CSRF token generation and validation | Active | 40 app, 1 test (`ajax/announcement_handler.php`, `ajax/approval_decision_handler.php`, …) | 0 | — | — | Retain in place (bootstrap) | High |
| `includes/env.php` | 56 | Load environment variables from .env file | Active | 2 app, 1 test (`config/app.php`, `config/database.php`, …) | 0 | — | — | Retain in place (bootstrap) | High |
| `includes/layout.php` | 654 | Shared shell: sidebar + topnav + announcements | Active | 39 app, 2 test (`pages/analytics.php`, `pages/announcements.php`, …) | 4 | — | SQL + HTML | Retain path; optional internal split (§7) | Medium |
| `includes/recommendations.php` | 185 | Rule-based recommended actions on the target schema. | Unused (legacy) | 0 app, 1 test (`tests/static/run.php`) | 2 | — | — | Owner decision (§10) | Low |
| `includes/session.php` | 333 | Bootstrap: must be the FIRST include on every PHP page | Active | 95 app, 1 test (`ajax/announcement_handler.php`, `ajax/approval_decision_handler.php`, …) | 3 | — | — | Retain in place (bootstrap) | High |
| `login.php` | 175 | login.php  —  Login Page | Active | 5 app, 3 test (`.htaccess`, `assets/css/login.css`, …) | 2 | — | — | Retain | High |
| `pages/403.php` | 35 | Access Denied page, included by permission guards (also reachable directly) | Active (included on denial) | 15 app (`includes/session.php` ×3, `app/modules/trips/crew_trip_access.php`, …) | 2 | — | — | Retain | Low |
| `pages/analytics.php` | 728 | Department analytics (Operations, Accounting, Maintenance). Each department | Active | 11 app, 1 test (`pages/analytics_expenses.php`, `pages/analytics_fleet.php`, …) | 7 | — | — | Retain; review later (§7) | Medium |
| `pages/analytics_expenses.php` | 16 | Billing & Collections Analytics: the Accounting section of | Active | 1 app (`includes/layout.php`) | 2 | — | — | Retain | Low |
| `pages/analytics_fleet.php` | 16 | Fleet Overview: the Operations section of pages/analytics.php (trips, | Active | 2 app (`includes/layout.php`, `pages/operations_performance.php`) | 2 | — | — | Retain | Low |
| `pages/analytics_maintenance.php` | 16 | Maintenance Analytics: the Maintenance section of pages/analytics.php | Active | 2 app (`includes/layout.php`, `pages/maintenance_reports.php`) | 2 | — | — | Retain | Low |
| `pages/announcements.php` | 316 | All announcements — all roles can view | Active | 1 app (`includes/layout.php`) | 3 | — | SQL + HTML | Retain | Medium |
| `pages/attendance.php` | 318 | Attendance list, time in/out and report (own record, or all with hr.attendance.view) | Active | 2 app, 2 test (`app/modules/people/attendance.php`, `includes/layout.php`, …) | 5 | — | SQL + HTML | Retain | Medium |
| `pages/audit_history.php` | 148 | Audit log viewer (audit.view) | Active | 2 app, 1 test (`includes/layout.php`, `pages/dashboard.php`, …) | 3 | — | SQL + HTML | Retain | Medium |
| `pages/automation.php` | 122 | Safe control center for read-only system automations. | Disabled (404) | 1 app (`ajax/automation_handler.php`) | 5 | — | SQL + HTML | Retain (owner decision) | Low |
| `pages/backup.php` | 69 | Back up all system data and uploaded documents — Admin only. | Active | 1 app (`includes/layout.php`) | 5 | — | — | Retain | Low |
| `pages/billing.php` | 864 | Billing and Collection staff see billings and collections only. The | Active | 11 app, 1 test (`ajax/billing_export.php`, `analytics/comparative_analytics.php`, …) | 5 | — | SQL + HTML | Split (§7) | Medium |
| `pages/client_master_data.php` | 161 | Client rates and locations maintenance (clients.manage) | Active | 1 app (`pages/clients.php`) | 5 | — | SQL + HTML | Retain | Medium |
| `pages/clients.php` | 99 | Target clients: status Active/Inactive, contact_number, billing_address. | Active | 2 app (`includes/layout.php`, `pages/client_master_data.php`) | 3 | — | SQL + HTML | Retain | Medium |
| `pages/create_analytics.php` | 3 | Untracked stray file; PHP parse error; no application role | Obsolete (stray, untracked) | 0 app | 0 | — | — | Owner decision: delete | Low |
| `pages/dashboard.php` | 581 | Home page: role-aware work items and KPI cards; landing page for every role | Active | 1 app, 3 test (`includes/session.php`, …) | 9 | — | SQL + HTML | Split (§7) | Medium |
| `pages/dashboard_accounting.php` | 88 | Accounting workspace dashboard (URL only, not the landing page) | Active (URL only) | 0 app, 1 test (`tests/static/run.php`) | 4 | — | SQL + HTML | Retain | Medium |
| `pages/dashboard_dispatcher.php` | 159 | Dispatcher dashboard (URL only) | Active (URL only) | 0 app, 1 test (`tests/static/run.php`) | 7 | — | — | Retain | Medium |
| `pages/dashboard_head.php` | 157 | Management dashboard (URL only) | Active (URL only) | 0 app, 1 test (`tests/static/run.php`) | 5 | — | — | Retain | Medium |
| `pages/dashboard_maintenance.php` | 136 | Maintenance workspace dashboard (URL only) | Active (URL only) | 0 app, 1 test (`tests/static/run.php`) | 5 | — | SQL + HTML | Retain | Medium |
| `pages/dashboard_operations_head.php` | 171 | Operations Head dashboard (URL only) | Active (URL only) | 0 app, 1 test (`tests/static/run.php`) | 5 | — | — | Retain | Medium |
| `pages/dispatch.php` | 1088 | Dispatch Requests + Route Management | Active | 11 app, 1 test (`app/core/notifications.php`, `app/modules/dashboard/home_tasks.php`, …) | 7 | — | SQL + HTML | Split (§7) | Medium |
| `pages/dispatch_inbox.php` | 122 | Instructions are open client_bookings rows (T-C1: route, scheduled | Active | 5 app, 1 test (`app/core/notifications.php`, `includes/layout.php`, …) | 4 | — | SQL + HTML | Retain | Medium |
| `pages/dispatch_planning.php` | 265 | Requested routes stay inactive until approved: active = approved. | Active | 4 app (`includes/layout.php`, `pages/dashboard.php`, …) | 4 | — | SQL + HTML | Retain | Medium |
| `pages/dispatcher_reports.php` | 359 | Admin and Operations Head see every dispatcher; dispatchers see only themselves. | Active | 2 app, 2 test (`includes/layout.php`, `pages/dashboard_operations_head.php`, …) | 5 | — | SQL + HTML | Retain | Medium |
| `pages/documents.php` | 361 | Document library: list, filter, upload, download links | Active | 3 app (`app/core/notifications.php`, `includes/layout.php`, …) | 5 | — | SQL + HTML | Retain | Medium |
| `pages/finance.php` | 200 | Target tables (see ajax/finance_handler.php). Figures follow | Active | 6 app, 1 test (`app/core/notifications.php`, `app/modules/dashboard/home_tasks.php`, …) | 5 | — | SQL + HTML | Retain | Medium |
| `pages/fleet_status.php` | 584 | Fleet Status — view, add, and edit trucks | Active | 9 app (`analytics/comparative_analytics.php`, `analytics/descriptive_analytics.php`, …) | 8 | status, Modal, Status | SQL + HTML | Split (§7) | Medium |
| `pages/incidents.php` | 298 | Trip problem reports (incidents) list and logging | Active | 7 app (`analytics/descriptive_analytics.php`, `app/core/notifications.php`, …) | 5 | — | SQL + HTML | Retain | Medium |
| `pages/maintenance.php` | 1651 | Maintenance work page: PM schedules, work orders, inspections, checklists, records (also renders the Maintenance History view) | Active | 11 app, 1 test (`pages/maintenance_records.php`, `analytics/diagnostic_analytics.php`, …) | 8 | — | SQL + HTML | Split (§7) | Medium |
| `pages/maintenance_records.php` | 13 | Maintenance Records & History: PM schedules, work orders, maintenance | Active | 3 app, 1 test (`includes/layout.php`, `pages/dashboard.php`, …) | 1 | — | — | Retain | Low |
| `pages/maintenance_reports.php` | 9 | Redirect to analytics_maintenance.php (kept for old links) | Active (redirect) | 0 app | 2 | — | — | Retain | Low |
| `pages/office_supplies.php` | 51 | Signed change and reference come from the transaction type and the | Active | 1 app, 1 test (`includes/layout.php`) | 3 | — | SQL + HTML | Retain | Medium |
| `pages/operations_maintenance_report.php` | 171 | Operations maintenance report (maintenance.reports.operations.view) | Active | 1 app, 1 test (`includes/layout.php`) | 4 | — | SQL + HTML | Retain | Medium |
| `pages/operations_performance.php` | 9 | Redirect to analytics_fleet.php (kept for old links) | Active (redirect) | 0 app | 2 | — | — | Retain | Low |
| `pages/parts.php` | 811 | Purchasing (parts.manage) records every movement; Maintenance | Active | 5 app (`includes/layout.php`, `includes/recommendations.php`, …) | 4 | — | SQL + HTML | Split (§7) | Medium |
| `pages/payroll.php` | 202 | Target payroll (see ajax/payroll_handler.php): period dates from | Active | 2 app, 1 test (`includes/layout.php`, `pages/dashboard.php`, …) | 4 | — | SQL + HTML | Retain | Medium |
| `pages/payroll_slip.php` | 86 | Printable payslip for one payroll record (payroll.view) | Active | 1 app (`pages/payroll.php`) | 3 | — | SQL + HTML | Retain | Medium |
| `pages/permissions.php` | 481 | Role permission editor (DISABLED: returns 404) | Disabled (404) | 0 app | 4 | Vehicle, Trip, Attendance | SQL + HTML | Retain (owner decision) | Low |
| `pages/purchasing.php` | 145 | Every supplier (active and inactive) for the supplier maintenance form. | Active | 3 app, 1 test (`app/core/notifications.php`, `includes/layout.php`, …) | 3 | — | SQL + HTML | Retain | Medium |
| `pages/recruitment.php` | 85 | Recruitment page (DISABLED: returns 404) | Disabled (404) | 0 app, 1 test (`tests/unit/run.php`) | 4 | — | SQL + HTML | Retain (owner decision) | Low |
| `pages/recycle_bin.php` | 181 | View and restore deleted records — Admin only. | Active | 1 app (`includes/layout.php`) | 5 | — | SQL + HTML | Retain | Medium |
| `pages/requests.php` | 278 | Central approval queue for Head Management | Active | 7 app (`app/core/notifications.php`, `includes/layout.php`, …) | 4 | — | SQL + HTML | Retain | Medium |
| `pages/trip_costs.php` | 532 | Target: trip expenses and separately recorded Driver/Helper Trip Pay. | Active | 1 app (`includes/layout.php`) | 4 | Expense | SQL + HTML | Split (§7) | Medium |
| `pages/trip_monitor.php` | 404 | Trip Monitoring — active trips, status updates, and past-ETA alerts | Active | 10 app (`analytics/comparative_analytics.php`, `analytics/descriptive_analytics.php`, …) | 7 | status, Modal, Trip | SQL + HTML | Retain | Medium |
| `pages/trip_pay.php` | 268 | Trip Pay entry for Payroll: per-trip Driver/Helper wages only. Shows just | Active | 2 app (`includes/layout.php`, `pages/trip_workflow.php`) | 4 | — | SQL + HTML | Retain | Medium |
| `pages/trip_pre_advice.php` | 101 | Printable/exportable pre-advice notice for an approved trip — a summary of | Active | 1 app (`pages/trip_workflow.php`) | 4 | — | SQL + HTML | Retain | Medium |
| `pages/trip_report.php` | 114 | trips.view or billing.view, or a Helper limited to trips assigned to them. | Active | 1 app (`pages/documents.php`) | 6 | — | SQL + HTML | Retain | Medium |
| `pages/trip_workflow.php` | 690 | trips.view, or a Helper limited to trips assigned to them. | Active | 7 app, 1 test (`app/core/notifications.php`, `app/modules/dashboard/home_tasks.php`, …) | 7 | — | SQL + HTML | Split (§7) | Medium |
| `pages/users.php` | 898 | User Management: users, employees, licence alerts, modals (users.manage) | Active | 2 app (`app/core/notifications.php`, `includes/layout.php`) | 4 | — | SQL + HTML | Split (§7) | Medium |
| `resources/views/analytics/page.php` | 152 | Analytics page markup, included by pages/analytics.php after its data | Active | 1 app (`pages/analytics.php`) | 0 | — | — | Retain | Medium |
| `scripts/generate_reminders.php` | 20 | Each run is recorded in automation_runs (job 'expiry_reminders', the same | CLI only | 0 app | 4 | — | — | Retain | Low |
| `scripts/run_automations.php` | 12 | CLI cron entry point for the automation runner (not scheduled) | CLI only | 0 app | 3 | — | — | Retain | Low |
| `scripts/schema_check.php` | 759 | scripts/schema_check.php - STATIC Option B schema checker (B0) | Test tooling | 0 app, 3 test (`tests/static/capture_sql.php`, `tests/static/helper_statements.php`, …) | 0 | col, t, vehicle, users +2 | — | Retain | Low |

## Appendix B: Front-end assets (excluding `assets/vendor/`)

Assets are loaded with `layoutHead(title, cssUrl)` and `$GLOBALS['page_js']` (one script per page), plus
`layout.js`, `state_persistence.js`, `filter_inputs.js` on every page. Vendor libraries: Bootstrap,
Bootstrap Icons, Chart.js (`assets/vendor/README.md`). Every asset below should keep its name (URL).

| Path | Lines | Loaded by | Status | Mixed concerns / note | Recommendation |
|---|---|---|---|---|---|
| `assets/css/analytics.css` | 76 | `pages/analytics.php` | Active | — | Retain |
| `assets/css/announcements.css` | 171 | `pages/announcements.php` | Active | — | Retain |
| `assets/css/automation.css` | 97 | `pages/automation.php` | Used only by a disabled page | — | Retain |
| `assets/css/billing.css` | 685 | `pages/billing.php`, `pages/client_master_data.php`, `pages/clients.php` +2 | Active | — | Retain |
| `assets/css/dashboard.css` | 733 | `pages/dashboard.php` | Active | — | Retain |
| `assets/css/dashboard_accounting.css` | 495 | `pages/dashboard_accounting.php` | Active | — | Retain |
| `assets/css/dashboard_dispatcher.css` | 672 | `pages/dashboard_dispatcher.php` | Active | — | Retain |
| `assets/css/dashboard_head.css` | 739 | — | Unreferenced | Styles for the Management dashboard; that page no longer passes it to layoutHead() | Owner decision (§10 D5) |
| `assets/css/dashboard_maintenance.css` | 598 | `pages/dashboard_maintenance.php` | Active | — | Retain |
| `assets/css/dispatch.css` | 181 | `pages/dispatch.php`, `pages/requests.php` | Active | — | Retain |
| `assets/css/documents.css` | 447 | `pages/documents.php` | Active | — | Retain |
| `assets/css/fleet_status.css` | 116 | `pages/fleet_status.php` | Active | — | Retain |
| `assets/css/incidents.css` | 417 | `pages/incidents.php` | Active | — | Retain |
| `assets/css/layout.css` | 2024 | `includes/layout.php` | Active | Shell styles for every page | Retain |
| `assets/css/login.css` | 355 | `login.php` | Active | — | Retain |
| `assets/css/maintenance.css` | 711 | `pages/dashboard_maintenance.php`, `pages/maintenance.php` | Active | — | Retain |
| `assets/css/parts.css` | 572 | `pages/parts.php` | Active | — | Retain |
| `assets/css/recycle_bin.css` | 240 | `pages/recycle_bin.php` | Active | — | Retain |
| `assets/css/trip_monitor.css` | 143 | `pages/trip_monitor.php`, `pages/trip_report.php`, `pages/trip_workflow.php` | Active | — | Retain |
| `assets/css/users.css` | 715 | `pages/users.php` | Active | — | Retain |
| `assets/js/analytics.js` | 117 | `pages/analytics.php` | Active | — | Retain |
| `assets/js/announcements.js` | 238 | `pages/announcements.php` | Active | — | Retain |
| `assets/js/automation.js` | 13 | `pages/automation.php` | Used only by a disabled page | — | Retain |
| `assets/js/backup.js` | 35 | `pages/backup.php` | Active | — | Retain |
| `assets/js/billing.js` | 534 | `pages/billing.php` | Active | Own copy of postAjax/showAlert/setBusy | Retain |
| `assets/js/client_master_data.js` | 160 | `pages/client_master_data.php` | Active | — | Retain |
| `assets/js/clients.js` | 109 | `pages/clients.php` | Active | — | Retain |
| `assets/js/dashboard_accounting.js` | 112 | — | Unreferenced | — | Owner decision (§10 D5) |
| `assets/js/dashboard_dispatcher.js` | 124 | — | Unreferenced | — | Owner decision (§10 D5) |
| `assets/js/dashboard_head.js` | 139 | — | Unreferenced | — | Owner decision (§10 D5) |
| `assets/js/dashboard_maintenance.js` | 85 | — | Unreferenced | — | Owner decision (§10 D5) |
| `assets/js/dashboard_operations_head.js` | 57 | — | Unreferenced | — | Owner decision (§10 D5) |
| `assets/js/dashboard_trip_completion.js` | 53 | — | Unreferenced | Driver/Helper completion form of the disabled feature | Owner decision (§10 D5) |
| `assets/js/dispatch.js` | 833 | `pages/dispatch.php` | Active | Own copy of postAjax/showAlert/setBusy | Retain |
| `assets/js/dispatch_planning.js` | 177 | `pages/dispatch_planning.php` | Active | — | Retain |
| `assets/js/documents.js` | 329 | `pages/documents.php` | Active | — | Retain |
| `assets/js/filter_inputs.js` | 347 | `includes/layout.php` | Active | Filter input behaviour; every page | Retain |
| `assets/js/fleet_status.js` | 440 | `pages/fleet_status.php` | Active | Own copy of postAjax/showAlert/setBusy | Retain |
| `assets/js/incidents.js` | 233 | `pages/incidents.php` | Active | Own copy of postAjax/showAlert/setBusy | Retain |
| `assets/js/layout.js` | 714 | `includes/layout.php`, `pages/attendance.php`, `pages/finance.php` +4 | Active | Shell behaviour for every page; exposes window.sendAction / window.softReload | Retain |
| `assets/js/login.js` | 65 | `login.php` | Active | — | Retain |
| `assets/js/maintenance.js` | 958 | `pages/maintenance.php` | Active | One IIFE: AJAX helpers, timers, filters, inspection SVG renderer, pre-departure sections. Own copy of postAjax/showAlert/setBusy | Retain; split deferred (needs multi-script layout) |
| `assets/js/parts.js` | 416 | `pages/parts.php` | Active | Own copy of postAjax/showAlert/setBusy | Retain |
| `assets/js/permissions.js` | 209 | `pages/permissions.php` | Used only by a disabled page | — | Retain |
| `assets/js/recycle_bin.js` | 145 | `pages/recycle_bin.php` | Active | Own copy of postAjax/showAlert/setBusy | Retain |
| `assets/js/requests.js` | 153 | `pages/requests.php` | Active | — | Retain |
| `assets/js/state_persistence.js` | 253 | `includes/layout.php` | Active | Remembers tab and filter state; every page | Retain |
| `assets/js/trip_monitor.js` | 211 | `pages/trip_monitor.php` | Active | — | Retain |
| `assets/js/trip_workflow.js` | 154 | `pages/trip_workflow.php` | Active | — | Retain |
| `assets/js/users.js` | 526 | `pages/users.php` | Active | Own copy of postAjax/showAlert/setBusy | Retain |

Images: `assets/images/README.md` (logo is optional), `assets/images/inspection/*.svg` (4 truck views) and
`README.txt`, used by the inspection screens in `pages/maintenance.php`, `pages/fleet_status.php`,
`assets/js/fleet_status.js` and `assets/js/maintenance.js`. Retain.

## Appendix C: Database files (`db/`)

| Path | Lines | Role | Status | Recommendation |
|---|---|---|---|---|
| `db/analytics_access_migration.sql` | 24 | Legacy incremental migration | Legacy | Retain with the legacy schema |
| `db/attendance_single_open_shift_migration.sql` | 13 | Legacy incremental migration | Legacy | Retain with the legacy schema |
| `db/auth_session_version_migration.sql` | 11 | Legacy incremental migration | Legacy | Retain with the legacy schema |
| `db/driver_trip_completion_migration.sql` | 31 | Legacy incremental migration | Legacy | Retain with the legacy schema |
| `db/mulawin_fleetops_db.sql` | 2627 | Legacy full schema; does not match current code (README) | Legacy reference | Retain (owner retires later) |
| `db/reference/mulawin_fleetops_new.sql` | 1429 | Target schema the code is written for | Reference | Retain |
| `db/system_user_employee_migration.sql` | 49 | Legacy incremental migration | Legacy | Retain with the legacy schema |
| `db/target_migrations/20261010_batch1a_security_support_tables.sql` | 104 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261010_batch1b_login_attempts.sql` | 72 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261010_p21_attendance_records.sql` | 128 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261010_p21_roles_system_key.sql` | 130 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261010_s36_employee_fields.sql` | 79 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261011_p21_rbac_seed_review_only.sql` | 408 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261014_tA1_approval_workflow_steps.sql` | 164 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261014_tB1_budgets.sql` | 54 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261014_tC1_client_booking_dispatch_fields.sql` | 87 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261014_tD1_announcement_audience.sql` | 57 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261014_tE1_employee_compat_columns.sql` | 101 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261014_tF1_expense_categories.sql` | 68 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261014_tI1_trip_incident_resolution.sql` | 58 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261014_tK1_truck_fuel_efficiency.sql` | 47 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261014_tM1_maintenance_next_due.sql` | 44 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261014_tM2_maintenance_planning.sql` | 137 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261014_tN1_inspection_templates_seed.sql` | 132 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261014_tP1_trip_pay_records_guard.sql` | 59 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261014_tS1_support_tables.sql` | 184 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261014_tT1_trip_second_driver.sql` | 57 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261014_tT2_trip_completion_reports.sql` | 73 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261015_tA2_scoped_reports_and_allowance_permissions.sql` | 64 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |
| `db/target_migrations/20261021_route_locations_client_routes.sql` | 37 | Review-only target migration draft | Not executed (per README / cleanup review) | Retain; never run without approval |

## Appendix D: Configuration, scripts, CI and tests

| Path | Lines | Role | Status | Recommendation |
|---|---|---|---|---|
| `.htaccess` | 12 | Root web rules: no indexes, `DirectoryIndex login.php`, deny `.env*`, `mod_rewrite` deny for internal folders | Active | Retain; extend the folder list if a new internal folder is added |
| `.env.example` | 17 | Template for `.env` (no secrets) | Active | Retain |
| `.gitignore` | 27 | Ignores `.env`, `/backups/`, generated files | Active | Retain |
| `.github/workflows/ci.yml` | 25 | CI: `php -l` + `node --check` only | Active | Optional batch L1: run the no-database suites too |
| `config/app.php` | 74 | Constants: `APP_BASE`, session, security, role IDs; loads `.env` | Active | Retain (bootstrap) |
| `config/database.php` | 41 | PDO connection from `.env` (`getDBConnection()`) | Active | Retain (bootstrap) |
| `config/enums.php` | 93 | Status and category constants | Active | Retain |
| `uploads/.htaccess` | 17 | Denies all direct access; disables script execution | Security control | Retain |
| `uploads/trucks/.htaccess` | 23 | Same for truck photos | Security control | Retain |
| `scripts/run_automations.php` | 12 | CLI cron entry: automation runner | Not scheduled | Retain |
| `scripts/generate_reminders.php` | 20 | CLI cron entry: expiry reminders | Not scheduled | Retain |
| `scripts/schema_check.php` | 759 | Schema-conformance checker used by the static suite | Test tooling | Retain |
| `tests/README.md` | 38 | Test commands and ISO 25010 readiness table | Active | Retain |
| `tests/unit/run.php` | 1428 | 532 checks; ~234 source-text checks; reads page sources by path | Active | Update source paths in the same batch as any markup move |
| `tests/unit/send_action.js` | 104 | Node test of `window.sendAction` | Active | Retain |
| `tests/unit/attendance_rows.js` | 87 | Node test of attendance row logic | Active | Retain |
| `tests/static/run.php` | 118 | Runs page data sections against a recording PDO; target lists name helper files | Active | Update lists when helpers move; drop `includes/recommendations.php` entries only with D3 |
| `tests/static/capture_sql.php` | 87 | Executes a page up to its first `?>` | Active | Retain; keep page data sections above the first `?>` |
| `tests/static/helper_statements.php` | 41 | Captures helper SQL | Active | Retain |
| `tests/static/billing_period_test.php` | 204 | Billing period filters (254 checks) | Active | Retain |
| `tests/static/path_check.php` | 131 | Include/path/.htaccess checker | Active | Retain; run after every batch |
| `tests/static/stubs/dispatcher_scope.php` | 10 | Stub for static runs | Active | Retain |
| `tests/static/stubs/general_dashboard.php` | 11 | Stub for static runs | Active | Retain |
| `tests/integration/run.php` | 1163 | Integration runner; preflight only; 31 path literals in `E2E_COPY_MUST_MATCH` | Active (preflight) | Update paths with moves; HTTP stage needs approval |
| `tests/integration/README.md` | 218 | Disposable-DB procedure | Active | Retain |
| `tests/integration/seed_synthetic.sql` | 139 | Synthetic fixtures for the disposable DB | Not run | Retain |
| `tests/sql/analytics_known_dataset.sql` | 157 | Known-dataset analytics check (ROLLBACK) | Not run | Retain |

## Appendix E: Documentation (`docs/`, README files)

Documentation is not changed by this audit. Several historical documents cite pre-restructuring
`includes/...` paths; they are records of their date and were left as they are.

| Path | Lines | Role |
|---|---|---|
| `README.md` | 106 | Mulawin FleetOps |
| `assets/vendor/README.md` | 36 | Vendored front-end libraries |
| `docs/B0_schema_check.md` | 107 | B0 — Static schema check (Option B) |
| `docs/analytics_audit_inventory.md` | 75 | Analytics audit inventory |
| `docs/analytics_data_gaps_and_client_questions.md` | 678 | Analytics data gaps and client questions |
| `docs/analytics_metric_traceability.md` | 176 | Analytics metric traceability matrix |
| `docs/client_analytics_clarification_request.md` | 409 | Mulawin FleetOps — Request for Clarification on Reports and Analytics |
| `docs/client_data_analytics_mapping.md` | 73 | Client data → analytics mapping |
| `docs/gabay_sistema_mulawin_fleetops.md` | 1130 | Gabay sa Sistema ng Mulawin FleetOps |
| `docs/implementation_progress.md` | 1231 | Mulawin FleetOps — Option B implementation progress (B0–B8) |
| `docs/instructions.md` | 253 | Mulawin FleetOps: Setup and System Guide |
| `docs/owner_access_decision_worksheet.md` | 792 | Owner access decision worksheet — OD-1 to OD-4 |
| `docs/repository_cleanup_review.md` | 96 | Repository cleanup review |
| `docs/system_audit_2026-10-19.md` | 253 | Mulawin FleetOps System Audit |
| `docs/testing/analytics_validation_report.md` | 132 | Analytics validation report |
| `docs/testing/browser_e2e_checklist.md` | 52 | Manual browser end-to-end checklist |
| `docs/testing/database_setup_checklist.md` | 207 | Disposable test database: setup and migration checklist |
| `docs/testing/mariadb_integration_checklist.md` | 114 | MariaDB integration test checklist |
| `docs/testing/od1_assignment_synthetic_test_matrix.md` | 197 | OD-1 synthetic assignment test matrix |
| `docs/testing/od1_assignment_test_readiness.md` | 171 | OD-1 dispatcher-assignment test readiness |
