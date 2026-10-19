# Mulawin FleetOps — Option B implementation progress (B0–B8)

Running report for the master implementation plan. No secrets, credentials or
`.env` values are recorded here.

- Repository: `C:\xampp\htdocs\mulawin_fleetops`
- Branch: `system-hardening`, HEAD `be5452194c0baeae4db1c5cec03bfed0995481b6` (verified)
- Target schema: `db/reference/mulawin_fleetops_new.sql` + drafts in `db/target_migrations/`
- Pre-edit backups of every file changed in this run:
  `C:\Users\acer\mulawin_patches\batch_b0_b8_master_20261014_105630\`
  (`files/`, `pre_edit.sha256`, `new_files.txt`, `git_status_before.txt`)
- **No database connection was made and no SQL was executed in any phase.**
  No migration was applied, no account was created, no automation was enabled.
  Nothing was committed or pushed.

Test-environment labels used below:

| Label | Meaning |
|---|---|
| STATIC | PHP/JS syntax checks, `scripts/schema_check.php` (pattern-based SQL vs schema model), grep scans |
| UNIT | PHP CLI tests of pure functions, no database |
| MOCK | PHP CLI tests against a recording/fake PDO or SQLite — **not** MariaDB evidence |
| MARIADB | Real MariaDB 10.4 against `mulawin_fleetops_test` — **none run yet (needs approval)** |
| BROWSER | Real browser on XAMPP/Apache — **none run yet** |

---

## Phase 0 — Preflight and B0 draft validation

### Preflight (PASS)
- Branch `system-hardening`, HEAD `be54521…` as expected.
- Working tree had 88 changed/untracked paths of prior user work (Phase 1–5
  hardening, B0 drafts, `scripts/schema_check.php`, `docs/B0_schema_check.md`).
  A full status snapshot is in the backup folder (`git_status_before.txt`).
  Nothing was reset, cleaned, switched or discarded.

### Commands
```
git rev-parse --abbrev-ref HEAD; git rev-parse HEAD; git status --porcelain
C:\xampp\php\php.exe scripts/schema_check.php --self-test          -> passed 24, failed 0
C:\xampp\php\php.exe scripts/schema_check.php --lint-drafts --no-scan --drafts=all
                                                                    -> 0 errors, 0 warnings, 12 info
C:\xampp\php\php.exe scripts/schema_check.php --drafts=all          -> baseline CONFIRMED 1094, UNCERTAIN 15, DYNAMIC 98 (108 files)
```

### Draft review (STATIC, no SQL executed)

| Draft | Result | Notes |
|---|---|---|
| 1A security support tables | OK | `app_settings`, `idempotency_requests` (unique user+action+key) |
| 1B login_attempts | OK | binary collation identifier, indexes match the handler's queries |
| 7M roles.system_key | OK | NULL for custom roles; UNIQUE allows many NULLs (MariaDB + MySQL) |
| S-36 employee fields | OK | `employee_code` UNIQUE NULL |
| **T-E1** employee compat columns | OK, contract now enforced | Backfill rule is deterministic and VARCHAR(270) cannot truncate (max 263). Before this run **no writer maintained the columns** (new rows would have `full_name` NULL). B1 adds `syncEmployeeCompatColumns()` (same SQL as the backfill) after every employee write. |
| RBAC seed | OK | 12 roles by `system_key`; privileged keys only for admin; **`reports.view` granted to `operations_head` (confirmed, section 3b)**; never remaps users; rerun-safe (NOT EXISTS). |
| T-S1 support tables | OK | `trip_number_counters` backfill substring offsets match `TRP-YYYY-NNNN` (year = chars 5–8, number from 10). Automation settings seeded `'0'` (all off). |
| **T-A1** approval steps + pending uniqueness | OK | `pending_key` = `workflow_id:entity_type:entity_id` only while `status='Pending'` → max length 10+1+60+1+20 = 92 ≤ VARCHAR(100). STORED generated column + UNIQUE index works on MariaDB 10.4 (STORED = PERSISTENT). Decided rows are NULL and never collide. Pre-check e) is a STOP condition. Requires 7M + seed first (order respected). |
| T-K1 fuel efficiency | OK (gated D14) | default 4.00 km/L is a placeholder, flagged in the draft |
| T-T1 second driver | OK (gated D3) | FK to employees, ON DELETE SET NULL |
| T-T2 completion reports | OK (gated D4/D5) | one Pending report per trip via generated `active_trip_id` + UNIQUE |
| T-I1 incident resolution | OK (gated D10) | `resolved_at`, `resolved_by` |
| T-D1 announcement audience | OK (gated D8) | priority / pin / audience ENUMs match `config/enums.php` |
| **T-P1** trip pay guard | OK | UNIQUE (trip_id, employee_id, pay_type) blocks concurrent duplicate wage rows; a second driver is a different employee so it does not collide. Cancelled rows still occupy their slot → code must re-activate (upsert), see B5. |
| T-F1 expense categories | OK (gated D6) | ENUM values appended at the end (no reorder); CHECK supported on 10.4 |
| T-B1 budgets | OK (gated D7) | UNIQUE (category, period_month) |
| T-M1 maintenance next due | OK (gated D11) | |
| P-21M attendance | OK | VIRTUAL generated column with UNIQUE index: supported on MariaDB 10.4 and MySQL 8. Note the target already has UNIQUE (employee_id, attendance_date), so one attendance row per employee per day. |

No draft needed a correction. All drafts remain marked **NOT EXECUTED**.

**Decision gates.** T-K1, T-T1, T-T2, T-I1, T-D1, T-F1, T-B1 and T-M1 are gated on
business decisions D3–D14 recorded outside the repository. The master plan
explicitly requires second-driver handling, completion reports, incident
resolution, announcements with audience, budgets, expense categories and
maintenance due dates, so the code below **assumes all gated drafts are
approved** and lists them as required migrations. If any gate is decided
against, the affected code must be revisited (listed per phase).

### `scripts/schema_check.php` limitations (do not treat as runtime proof)
- Pattern-based parser, not a SQL grammar: comma joins, nested derived tables
  with reused aliases and `INSERT … SET` can be missed.
- Only upper-case SQL verbs are recognised; SQL assembled from fragments
  (DYNAMIC) is checked only in its literal parts.
- Column **types, lengths, NOT NULL at UPDATE time, CHECK constraints, FK
  existence of values, triggers, collations and permissions are not checked.**
- ENUM checks cover only `col = 'x'` / `col IN (...)` literals; values bound
  through parameters are invisible to it.
- Views are accepted as tables but their columns are not modelled.
- It models the reference file + selected drafts, not a live server.
A clean scan therefore means "no literal mismatch found", nothing more.

---

## Phase 1 — B1: identity, login, sessions, audit, RBAC

Already target-compatible from earlier hardening (verified by reading, not
only by scan): `auth/login_handler.php` (name from employee parts, rate limit on
`login_attempts`, GET_LOCK serialisation, audit before session is kept),
`includes/session.php` (`roles.system_key`, `auth_version` re-validation every
request, Admin = `system_key 'admin'` only), `includes/audit.php` (target
`audit_logs` columns only, throws on failure).

### Changes
| File | Change |
|---|---|
| `includes/employee_profile.php` | + `syncEmployeeCompatColumns()` (T-E1 contract, same expressions as the migration backfill) and `employeeNameSql()` |
| `ajax/users_handler.php` | sync call after all 4 employee writes; add/edit employee now run in a transaction (sync + audit commit together); **EDIT_USER audit moved inside the transaction** (previously a role/status change could commit without its audit row) |
| `auth/seed_users.php` | Admin role resolved by `system_key = 'admin' AND is_active = 1` (was role name); preflight also requires `roles.system_key`, `employees.full_name`, `employees.is_active`; sync after employee insert. **Not run.** |
| `pages/permissions.php`, `ajax/permissions_handler.php` | approval chains on `approval_workflows` + `approval_workflow_steps` (T-A1); per-workflow replace inside one transaction; audit inside the transaction |

Least privilege unchanged: no role mapping, no Admin creation, Admin bypass
still only through `isAdmin()` with audited overrides.

---

## Phase 2 — B2: shared infrastructure

| File | Change |
|---|---|
| `includes/approval_workflow.php` | Rewritten for target: `approval_requests.approval_request_id`, `workflow_id`; steps by `step_number`; current step = lowest Pending step (no `current_step` column); history actions `Submitted/Approved/Rejected/Overridden/Cancelled` with `acted_by`; **rejection marks remaining steps `Skipped` (target has no `Cancelled` step status)**; step update guarded by `status='Pending'` + rowCount; duplicate Pending request → `ApprovalAlreadyPending` (code check + `uq_approval_pending` error 1062); entity type must match the workflow; new `cancelApprovalRequest()` |
| `includes/notifications.php` | Target columns `notification_type`, `entity_type`, `entity_id` (no `link`); links built only from a fixed allowlist `NOTIFICATION_ENTITY_LINKS`; `notifyUsersWithPermission()` includes active Admin users (Admin holds every permission) |
| `includes/layout.php` | notifications read `entity_type/entity_id`; announcements read `content/start_date/end_date`, and now also require `is_active = 1` and `start_date <= NOW()` |
| `includes/soft_delete.php` | Explicit table allowlist (`announcements`, `documents`, `payroll_records`) with ID column and FK-cascade children archived with the parent (`document_links`; `payroll_deductions`, `payslips`); restore validates every column against the live table; joins the caller's transaction; payload format 2 with legacy fallback |
| `includes/attachments.php` | `document_links` replaces `attachments`/`attachment_versions` (no versioning in target) |
| `includes/document_upload.php` | `files` → `documents` → `document_links`; SHA-256 checksum stored; central visibility helpers (rule unchanged) |
| `ajax/document_handler.php`, `ajax/document_download.php`, `pages/documents.php` | target joins; billing-linked documents still cannot be deleted; download name sanitised + RFC 5987 filename |
| `ajax/recycle_bin_handler.php` | purge in one transaction; `files` row removed only when no document still uses it (savepoint, FK RESTRICT respected); physical file removed only after commit |
| `includes/expiry_reminders.php` | documents via `files`; licenses via T-E1; part warranty = install date (`maintenance_parts.issued_at`) + `parts.warranty_period_days` (**derived rule, review**); **new:** truck document expiry (`truck_documents`) |
| `includes/automation_runner.php` | target tables/columns; pending approvals from `approval_requests`; unpaid billings only `Issued/Partially Paid/Overdue`; daily summary uses shared lateness rule. Jobs stay disabled by default. |
| `includes/metrics.php` (new) | single definition of delivered / late / on-time / overdue trips and safe percentages |
| `includes/workflow_events.php` (new), `includes/truck_status_history.php` | truck status history → `workflow_events` (entity `truck`, event `truck_status`); generic `recordWorkflowEvent()` with entity allowlist |
| `includes/db_helpers.php` | `findOrFail()` / `existsWhere()` only accept allowlisted table/column identifiers (LogicException otherwise) |
| `.htaccess` | `tests/` added to the denied directories |

### Results so far
| Check | Env | Result |
|---|---|---|
| `php -l` on all B1/B2 files | STATIC | PASS |
| schema_check `--drafts=all --only=<18 B1/B2 files>` | STATIC | PASS: CONFIRMED 0, UNCERTAIN 0 (DYNAMIC 30 reviewed: alias/identifier interpolation from allowlists only) |

Callers of the changed notification / approval APIs in later phases
(`review_dispatch`, `submit_dispatch`, `trip_completion_handler`,
`dispatch_instructions_handler`, `finance_handler`, `purchasing_handler`) are
ported in B3–B5.

---

## Phase 3 — B3: dispatch and trip operations

### Target model used (decision D2)
The trip row is created **at submission** (`trips.status = 'Pending Approval'`,
trip number allocated then); `dispatch_requests` is its one approval record
(`uq_dispatch_request_trip`); `approval_requests` (workflow `dispatch`)
drives the decision. Trip data (truck, crew, route, client, schedule, shift)
lives on `trips`.

| Legacy | Target |
|---|---|
| `dispatch_requests.*` trip data | `trips.*` |
| `trip_workflow_state` / `trip_workflow_events` | `workflow_events` (entity `trip`); current step = highest recorded step; packet details as JSON on the step-6 event |
| `trucks.status 'Deployed'` (**invalid in target ENUM — B0 confirmed**) | `'Assigned'` at approval (+ `truck_assignments 'Reserved'`, `trucks.current_assignment_id`), `'On Trip'` at departure, `'Available'` + assignment `Released/Cancelled` at completion/cancel |
| approval step `'Cancelled'` (**invalid in target — B0 confirmed**) | `'Skipped'` (B2) |
| trip statuses Loading / In Transit / Unloading | Approved → Assigned → Ready for Dispatch → Dispatched → In Progress → Delivered → Returned → Completed (+ Cancelled) |
| clearance on workflow state | `dispatch_clearances` (one per trip, unique) |
| `trip_updates.status/location_note/notes` | `trip_updates.update_type/location_text/description` |
| `trip_delivery_return_details` | `trip_deliveries` + `trip_returns`; receipt no., co-load ref and delivered units have no target column → kept as labelled lines in `delivery_notes` (parsed back for editing) |
| `incidents` | `trip_incidents` (types mapped: Vehicle Breakdown→Breakdown, Item Damage→Damage; severity added; resolution in `action_taken` + T-I1) |
| `dispatch_instructions` (+ batches) | `client_bookings` + **new draft T-C1** (route, scheduled departure, shift, created_by). Pending=Open, Confirmed=Encoded, Completed when the trip completes. Batches become individual bookings. **Truck type is now required** on an instruction (target NOT NULL) |
| `routes.approval_status/requested_by/request_notes` | inactive route + `route_request` approval; notes on the Submitted history row; toggle cannot activate a pending/rejected request |
| `trips.is_late` | computed (`includes/metrics.php`): no write on page load any more |
| `trip_completion_reports.dispatch_id` | not in T-T2; one Pending report per trip enforced by T-T2 unique key (1062 handled) |

Fields with **no target column, dropped** (documented, no data invented):
billing client / parent client, client location type (all locations usable as
pickup or delivery), per-dispatch client rate (looked up from `client_rates` at
billing), waybill at submission (captured at delivery as
`trip_deliveries.waybill_number`), cargo description/weight.
`'For Review'` trip status is not used: a Pending completion report carries
that state, as in the legacy app.

### Concurrency / idempotency
- Submit: idempotency key, truck + crew rows locked `FOR UPDATE` in fixed order,
  conflicting-assignment check on `trips` (same rule: one open trip per
  truck/crew member per scheduled date), booking claimed with a conditional
  `UPDATE … WHERE status='Pending'`, trip number from the locked counter.
- Review: dispatch request and trip locked; approval request locked in the
  engine; `uq_approval_pending` prevents duplicate Pending requests; every
  status change is a conditional update with rowCount check
  (`transitionTripStatus()`); truck reserve requires `status='Available'`.
- Workflow actions: trip row locked; step events written once per key.
- Admin overrides unchanged: reason required, `ADMIN_OVERRIDE` audited inside
  the business transaction, failure hook on error.

### Files
New: `includes/trip_queries.php`, `db/target_migrations/20261014_tC1_client_booking_dispatch_fields.sql` (NOT EXECUTED).
Rewritten/ported: `config/enums.php`, `includes/trip_workflow.php`,
`includes/dispatch_assignment.php`, `includes/crew_trip_access.php`,
`ajax/submit_dispatch.php`, `ajax/review_dispatch.php`,
`ajax/update_trip_status.php`, `ajax/trip_workflow_handler.php`,
`ajax/trip_completion_handler.php`, `ajax/log_incident.php`,
`ajax/dispatch_instructions_handler.php`, `ajax/route_request_handler.php`,
`ajax/routes_handler.php`, `ajax/trip_operations_export.php`,
`pages/requests.php`, `pages/dispatch.php`, `pages/dispatch_inbox.php`,
`pages/dispatch_planning.php`, `pages/trip_monitor.php`,
`pages/trip_workflow.php`, `pages/trip_report.php`,
`pages/trip_pre_advice.php`, `pages/incidents.php`,
`assets/js/dispatch.js`, `assets/js/trip_monitor.js`,
`assets/js/dispatch_planning.js`, `assets/js/incidents.js`,
`assets/css/incidents.css`; `scripts/schema_check.php` (T-C1 added to the
draft order).

### Results
| Check | Env | Result |
|---|---|---|
| `php -l` (21 PHP files), `node --check` (4 JS) | STATIC | PASS |
| schema_check `--drafts=all --only=<21 B3 files>` | STATIC | PASS: CONFIRMED 0, UNCERTAIN 0, DYNAMIC 20 reviewed by hand |
| schema_check on helper-expanded SQL (trip SELECT, monitor, pre-advice, export, metrics) | STATIC | PASS: CONFIRMED 0 (negative control: an injected bad column is reported CONFIRMED) |
| draft lint with T-C1 | STATIC | PASS: 0 errors, 0 warnings |
| Approval / concurrency behaviour under real locks | MARIADB | **NOT TESTED** (needs the approved test database) |

Open item for B4: departure readiness now requires **active inspection
templates** (`inspection_templates`/`inspection_items`, empty in the target).
Until templates are seeded, departure and completion stay blocked (fail
closed). A reviewed seed draft is prepared in B4.

---

## Phase 4 — B4: fleet, maintenance, purchasing, shared records

| Legacy | Target |
|---|---|
| truck image columns | `files` + `documents` ('Truck Photo', description = view) + `document_links` (files stay in the public, script-blocked `uploads/trucks/`) |
| `mv_file_number` / `registration_expiry` | `truck_documents` 'CR' |
| insurance provider / policy / expiry | `truck_documents` 'Insurance' (provider in notes) |
| `warranty_expiry` (truck) | `truck_documents` 'Other' with notes 'Warranty' |
| `year_model`, `body_type`, `capacity_tons` | `vehicle_year`, `body_details`, `capacity` |
| `truck_status_history` | `workflow_events` (B2) |
| status Deployed / Inactive | Assigned or On Trip / Unavailable; manual changes limited to Available, Under Maintenance, Unavailable; `availability_status` kept in step |
| `repair_work_orders` | `maintenance_records` (Repair, `record_source` 'work_order', T-M2 priority/closure/creator); Open = Reported |
| `preventive_maintenance_schedules` | **new draft T-M2** (same columns, FK to `maintenance_records`) |
| maintenance record `truck_status` | derived from record status (Completed → Operational, Scheduled → Scheduled Maintenance, other → Under Repair); cost → `other_cost` = `total_cost` |
| `maintenance_checklists` | Departure `vehicle_inspections` on the **'Pre-trip checklist'** template (**new seed draft T-N1**) |
| `vehicle_inspections` + `vehicle_inspection_findings` (trip) | target `vehicle_inspections` (Departure / Arrival) + `inspection_findings` on the **'Standard vehicle inspection'** templates; custom parts become inactive optional items; condition kept in `condition_description` |
| general (no-trip) inspection | `maintenance_records` (Inspection, `record_source` 'inspection') + `maintenance_findings` |
| `maintenance_form_timers` | one-time server-side timer in the PHP session (user + form + target bound, 2 h expiry, restored if the save fails); measured time stored on `vehicle_inspections` |
| arrival inspection completion | step 12 + trip Delivered → Returned |
| `parts_inventory` + `parts_movements` | `parts` + `inventory` + `inventory_transactions` (positive quantities; adjustment direction in `reference_type`); stock-out to a job also writes `maintenance_parts` and adds to its `parts_cost`/`total_cost` |
| part warranty expiry date | `parts.warranty_period_days`; "expiring" = installed units (`maintenance_parts`) within 30 days of warranty end |
| part supplier (free text) | `suppliers` (matched by name, created when new, audited) |
| add part: Admin only | `parts.catalog.manage` (Admin + Purchasing Officer per the reviewed seed) |
| `purchase_order_headers` / `_lines` | `purchase_requests` (+ items, approval workflow 'purchase_request') + `purchase_orders` (Draft → Issued on approval) + items; receiving → `purchase_receipts` + per-line stock-in transactions |
| `announcements.body/starts_at/ends_at` | `content/start_date/end_date` (+ `is_active`); title max 180 |
| `office_supply_items` / `_movements` | `office_supplies` / `office_supply_transactions` (Issue = Stock Out; adjustment direction and reference in remarks) |
| `recruitment_candidates` | `recruitment_records`; statuses are the target ENUM (Applicant, Screening, Interview, Accepted, Rejected, Withdrawn); email/source as labelled note lines; candidate no. = APP-<id> |

**Known gap (pre-existing):** no screen decides purchase / fund / voucher /
payroll approvals (the approval queue only handled dispatch and routes).
Addressed in B5 with a generic approval decision endpoint.

### Results
| Check | Env | Result |
|---|---|---|
| `php -l` on 21 B4 PHP files; `node --check assets/js/parts.js` | STATIC | PASS |
| schema_check on the 21 B4 files | STATIC | CONFIRMED 0; UNCERTAIN 3 = display labels produced by `IF()`/`CASE` (`'Return'`, `'Open'`, `'Issue'`), not comparisons; DYNAMIC reviewed |
| draft lint incl. T-C1, T-M2, T-N1 | STATIC | PASS: 0 errors, 0 warnings |
| expanded helper SQL (truck profile) | STATIC | PASS |
| Inspection timers, stock locking, receiving under concurrency | MARIADB | **NOT TESTED** |

## Phase 5 — B5: billing, collections, Trip Pay, allowance, expenses, payroll, finance

All changes are static code changes; no SQL was executed.

| Legacy | Target |
|---|---|
| `clients.notes`, `client_type`, `parent_client_id` | `billing_address` (notes dropped; type/parent have no target column); deactivation refused while the client has open trips |
| client rates | `client_rates` via `includes/client_rates.php` (Route / Distance / Fixed basis, effective-date overlap check, suggested billing amount) |
| `billings.amount`, Unpaid / Partial | `total_amount` + one `billing_items` line; one billing per trip; status Issued / Partially Paid / Paid / Overdue (legacy labels shown only as display text); collected = sum of `billing_payments` |
| payment GCash | `payment_method` 'Other' (wallet name kept in the reference) |
| `trip_expenses` | `expense_records` (`reference_type` 'trip', T-F1 categories); editing needs `finance.expenses.edit` |
| `trip_pay` (crew_role, paid_date) | `trip_pay_records` (`pay_type` Driver/Helper Trip Pay, status Paid; paid date as "Paid YYYY-MM-DD." note prefix); **T-P1** unique (trip, employee, pay_type) + upsert, so concurrent saves cannot duplicate wages; delete = status Cancelled; second driver is payable |
| Driver Allowance | stays an expense (`expense_records`, type Driver Allowance) — separate from Trip Pay; both may exist for the same trip and person |
| `payroll_records` (period dates, paid_date, notes) | `payroll_periods` (unique period, upsert) + `payroll_records` (Disbursed) + `payroll_deductions` + `payroll_disbursements` (reference "PR-REC-<id> notes" carries paid date/notes); duplicate employee+period refused (1062) |
| `finance_disbursements` | `disbursements` (reference_type/reference_id **required by the target**: every disbursement must link to exactly one approved fund request or approved payment voucher; amount capped at the remaining approved amount under row lock; fund request → Processing / Disbursed; voucher → Paid; payable → Partially Paid / Paid) |
| `accounts_payable.payable_number/supplier_name/invoice_number` | `AP-<ap_id>` display number, `suppliers` (matched/created by name, audited), `reference_number` |
| `payment_vouchers.payable_id`, workflow 'fund_request' | `reference_type` 'accounts_payable' / `reference_id`; **workflow 'payment_voucher'** (legacy code wrongly submitted vouchers under the fund_request workflow); voucher total per payable capped at the open balance |
| fund requests | `fund_requests` (Pending Approval, `submitted_at`, notes appended to purpose); Trip Allowance requests must name a non-cancelled trip |

**New:** `ajax/approval_decision_handler.php` + "Purchasing, Fund, Voucher &
Payroll Approvals" section on `pages/requests.php` (closes the B4 gap).
Requires `approvals.review`, CSRF, idempotency key; locks the approval
request; current step must belong to the actor's role (engine check); the
requester cannot decide their own request (Admin only with an audited
override reason); the entity changes only on the final decision and only from
'Pending Approval' (conditional UPDATE, else rollback with 409); requester
notified; `APPROVAL_DECISION` audit row. The 'payroll' branch is ready but
dormant: payroll is still recorded directly as Disbursed (no business rule
was given for payroll approval, so none was invented).

**Figures:** revenue = billings in Issued/Partially Paid/Paid/Overdue
(`billingRevenueSql`); outstanding = total − collected for open billings.
The Trip Costs "Net Profit" column is the existing page formula (revenue −
trip expenses − trip pay), labelled per trip; it is not company profit.
The target has no cargo weight, so the fuel estimate uses no load factor.

### Results
| Check | Env | Result |
|---|---|---|
| `php -l` on 23 B5 PHP files; `node --check` clients.js, client_master_data.js, billing.js, requests.js | STATIC | PASS |
| schema_check on B5 files | STATIC | CONFIRMED 0; UNCERTAIN = `IF()` display labels ('Driver'/'Helper'), not comparisons |
| expanded helper SQL (finance summary, payroll paid date/notes, trip revenue, payslip) | STATIC | PASS (CONFIRMED 0) |
| T-P1 duplicate-wage protection, disbursement caps under concurrency, approval decision races | MARIADB | **NOT TESTED** |
| Finance / billing / payroll screens | BROWSER | **NOT TESTED** |

## Phase 6 — B6: permissions and seed consistency (STATIC review)

| Check | Result |
|---|---|
| Operations Head `reports.view` | **CONFIRMED** in `20261011_p21_rbac_seed_review_only.sql` line 298 (approved 2026-10-14) |
| Every permission key used by code/nav (66 keys) exists in the reviewed seed or reference schema | PASS (`comm` of extracted keys; 0 missing) |
| Every `ajax/*.php` and `pages/*.php` has a server-side guard | PASS — direct `require*Permission` / `requireLogin` + in-page permission checks; trip pages use `tripPageAccess()` (permission or assigned Helper); `pages/403.php` is the error page |
| Nav item permission == page guard | PASS for all 25 permission-gated items (page guards accept the nav permission; some also accept a `.manage` key). Documents link is shown to all roles, page requires `documents.view` |
| Exports | `billing_export` billing.export, `trip_operations_export` trips.export, `attendance_export` hr.attendance.report, `document_download` documents.download |
| Hard-coded role IDs/names for authorization | none in handlers/pages; `navItemVisible()` role lists only for transitional legacy links; Admin decided by validated role key (`isAdmin()`) |
| `analytics/` folder (function libraries) reachable over HTTP | **FIXED**: added to the `.htaccess` deny rule (it produced no output, but should not be web-reachable) |
| Approval workflows purchase/fund/voucher/payroll | step 1 = admin role in T-A1 seed; Finance cannot approve its own fund requests or vouchers (`approvals.review` not granted; self-review refused in the handler) |
| Finance page vs handler permission | **FIXED**: page now also admits `finance.ap.view` like the handler |

Notes: `legacy.role.3`/`legacy.role.4` are still used as transitional grants
(maintenance incidents, finance document scope). They are defined in the
seed and granted only to the matching department role. Hiding a nav item is
never the only control: every target page re-checks on the server.

## Phase 7 — B7: analytics and role dashboards (descriptive / diagnostic only)

Nothing is forecast or predicted. The former parts "days until stock-out"
projection in `includes/recommendations.php` was removed (rule kept:
at/below reorder level, 30-day usage shown as context).
`analytics/prescriptive_analytics.php` is a static lookup of next steps,
not a model, and was kept.

### Shared definitions (one place each)
| Metric | Definition | Where |
|---|---|---|
| Delivered | trip status Delivered / Returned / Completed | `includes/metrics.php` |
| On-time rate | on-time ÷ **measurable** deliveries (scheduled and actual arrival present); "—" when none. The old driver table counted unmeasurable trips as on time — fixed | `metrics.php` |
| Delayed trip | active trip past its scheduled arrival | `tripOverdueSql()` |
| Fleet utilization | distinct trucks on a trip scheduled in the window (not Cancelled / Pending Approval) ÷ trucks in the fleet (today's count for both windows) | `dashboard_metrics.php`, analytics |
| Revenue billed | billings Issued / Partially Paid / Paid / Overdue by `billing_date` — **never labelled profit** | `finance_records.php` |
| Collection rate | collections ÷ revenue billed (analytics: received in the window; billing dashboard: received against the window's billings) | |
| Outstanding / overdue | total − collections on open billings; overdue = past `due_date` | `dashboardReceivables()` |
| Maintenance cost | `total_cost` of non-Cancelled records by completion / start / scheduled / created date | `maintenanceRecordDateSql()` |
| Operating expenses | expense types Fuel, Toll, Driver Allowance, Operating, Other (Maintenance / Parts / Payroll rows excluded to avoid double counting) | `operatingExpenseSql()` |
| Recorded margin (Admin only) | revenue − (maintenance + operating expenses + payroll + Trip Pay). Labelled "Recorded margin" with a note that it is **not an accounting profit** (no depreciation, tax, accruals or unrecorded costs) | analytics |
| Budget variance | only categories with a budget for the month (T-B1); revenue below / cost above budget is unfavourable; Payroll excludes Trip Pay | analytics, Admin and Finance dashboards |

### Role by role (server-side authorization on every page)
| Role (key) | Landing page (guard) | Shows |
|---|---|---|
| Admin (`admin`) | `dashboard_head.php` (`company.dashboard.view`) | KPIs; **this-month row**: revenue billed, collection rate, overdue receivables, on-time (30 d), utilization (30 d), maintenance cost, budget variance; delayed trips, open incidents, low stock, maintenance due, pending approvals, recommendations, audit. Analytics (`reports.view`): every section incl. margin and variance |
| Operations Head (`operations_head`) | `dashboard_operations_head.php` (`operations.dashboard.view`) | pending dispatches / routes, active and delayed trips, fleet status chart, trips by status this month, on-time and utilization (30 d), open incidents, open maintenance and due list. Analytics operations view — **no revenue, cost or margin** |
| Dispatchers (`car_carrier_dispatcher`, `container_wing_van_dispatcher`) | `dashboard_dispatcher.php` (`trips.view`) | scoped to their truck types and shift: active / pending / delayed, available vs assigned trucks, available licensed drivers, live and upcoming trips, my requests and weekly workload, trips by status. Quick status update fixed: valid next statuses only, idempotency key, cancellation reason, Admin override prompt (it previously sent legacy statuses without a key and was always rejected) |
| Maintenance (`maintenance`) | `dashboard_maintenance.php` (`maintenance.view` / `parts.view`) | trucks needing attention, under maintenance, open incidents, today's checklists, low stock, service due, type donut, monthly cost |
| Finance (`finance`), Billing and Collection (`billing_collection`) | `dashboard_accounting.php` (`billing.view`) | billed / collected / pending / overdue for the period, overdue and due-soon invoices, billings without documents, 6-month billed vs collected and rate; **operating expenses, maintenance cost and budget vs actual only with `finance.view`** (Finance yes, Billing and Collection no) |
| Payroll, Purchasing, Audit, Driver, Helper, others | `dashboard.php` (login) | only cards the user's permissions allow; crew see their own trips and current assignment |

`includes/session.php` `ROLE_KEY_DASHBOARDS` now routes each role key to its
dashboard (before, only Admin had one; the department dashboards were
unreachable).

### Files
New: `includes/dashboard_metrics.php`, `includes/analytics_period.php`,
`assets/js/dashboard_operations_head.js`. Ported: `pages/analytics.php`
(role views by role key instead of legacy role IDs), the six
`pages/dashboard*.php`, `pages/operations_performance.php` (range at most
366 days; delivered / late / on-time), `includes/recommendations.php`,
`analytics/descriptive_analytics.php`, `analytics/diagnostic_analytics.php`
(CRLF kept), `assets/js/analytics.js` (labels), `assets/js/dashboard_dispatcher.js`,
`includes/finance_records.php` (`operatingExpenseSql`, collections subquery
alias `cpay`), `includes/session.php`. Charts use the existing Chart.js
vendor copy and the existing page themes.

### Results
| Check | Env | Result |
|---|---|---|
| `php -l` / `node --check` on B7 files | STATIC | PASS |
| `tests/static/run.php`: SQL captured from analytics (5 role views), 6 dashboards, Operations Performance and 12 helper statements, checked against the schema model | MOCK (stub PDO, SQL capture only) + STATIC | PASS — CONFIRMED 0, UNCERTAIN 0; negative control reported |
| `tests/unit/run.php` (rates, divide-by-zero, buckets, badges, …) | UNIT | PASS 84 / 0 |
| Known-dataset aggregation `tests/sql/analytics_known_dataset.sql` | MARIADB | **NOT TESTED** (prepared; its 22 statements pass the static schema check) |
| Charts, filters, empty states in a browser | BROWSER | **NOT TESTED** |

## Phase 8 — B8: integration and consistency (STATIC)

| Check | Result |
|---|---|
| `php -l` on every PHP file in the working tree | PASS — 136 files (`includes/autoload.php` was already deleted before this run and is referenced nowhere) |
| `node --check` on `assets/js/*.js` | PASS — 30 files |
| Full schema scan (`ajax, pages, includes, auth, login.php, analytics, scripts`) | CONFIRMED **9** (baseline 1094) — all 9 are the checker's own self-test fixtures inside `scripts/schema_check.php`; UNCERTAIN 9 are `IF()` / `CASE` display labels ('Driver' / 'Helper', 'Return', 'Open', 'Issue'), not comparisons |
| `schema_check.php --self-test` | PASS 24 / 0 |
| Draft lint (21 drafts) | 0 errors, 0 warnings |
| Every endpoint / page referenced from PHP or JS exists | PASS |
| Every state-changing handler enforces CSRF and POST | PASS |
| `findOrFail` / `existsWhere` use allowlisted identifiers | PASS — 44 calls, none with a variable table |
| Duplicate constants / functions across includes | none (one clash, `TRUCK_STATUSES`, found and fixed) |
| `git diff --check` | PASS (LF→CRLF notices only; `core.autocrlf=true`) |
| Test-import filter of the reference schema (setup checklist step 2), dry run on a scratch copy | PASS — only the 4 CREATE DATABASE / USE lines removed, 70 tables kept |
| MariaDB 10.4 syntax review of schema and drafts | no MySQL-8-only syntax found (CHECK constraints and generated VIRTUAL / STORED columns with unique keys are supported); to be confirmed by the real import |

## Deliverables

- Code: phases B1–B7 above, uncommitted on `system-hardening`.
- Tests: `tests/unit/run.php`; `tests/static/run.php` with `capture_sql.php`,
  `helper_statements.php`, `stubs/`; `tests/sql/analytics_known_dataset.sql`;
  `tests/README.md`.
- Checklists: `docs/testing/database_setup_checklist.md` (**NOT EXECUTED**),
  `docs/testing/mariadb_integration_checklist.md` (**NOT TESTED**),
  `docs/testing/browser_e2e_checklist.md` (**NOT TESTED**).
- Backups: 111 pre-edit copies and 28 new files registered in the backup folder.

## Confirmed vs not tested

| Area | Confirmed here (environment) | Not tested / blocked |
|---|---|---|
| Syntax | PHP 136 files, JS 30 files (STATIC) | — |
| SQL names vs target schema | all application SQL incl. runtime-built dashboard / analytics SQL (STATIC + MOCK capture) | query results (MARIADB) |
| Calculations | rate, bucket, badge, note and rate-amount helpers (UNIT) | aggregates on data (MARIADB; fixture prepared) |
| Authorization | guards on every page / endpoint, nav = guard, seed keys (STATIC) | per-role behaviour (MARIADB, BROWSER) |
| Concurrency / idempotency (approvals, trip numbers, Trip Pay T-P1, payments, disbursements, stock) | code paths reviewed (STATIC) | **NOT TESTED** — needs MariaDB |
| Migrations / seed | draft lint (STATIC) | **NOT EXECUTED** — needs approval |
| Uploads | validation code reviewed (STATIC) | **NOT TESTED** |
| UI, charts, responsive, console | — | **NOT TESTED** (BROWSER) |

## Open items for the owner

1. Approve (or not) creating `mulawin_fleetops_test` and following
   `docs/testing/database_setup_checklist.md` step by step.
2. Decide how test accounts per role are created (no user or Admin was created).
3. Payroll approval: the 'payroll' workflow exists and the decision endpoint
   supports it, but payroll is still recorded directly as Disbursed. No rule
   for payroll approval was given, so none was invented.
4. Disbursements must now link to an approved fund request or voucher (the
   target requires a reference); free-standing disbursements are refused.
5. Review the 24 checklist items in T-N1 with Maintenance before seeding.

---

## Finalization pass — 2026-10-14 (review, analytics validation, cleanup review)

The B0–B8 record above is the completed implementation record and has not
been rewritten. This section lists what the finalization pass verified,
corrected and left open. Pre-edit copies of every file it changed are in
`C:\Users\acer\mulawin_patches\finalization_20261014\` (`files/`,
`pre_edit.sha256`, `git_status_before.txt`).

**Unchanged constraints:** no database connection, no SQL executed, no
migration applied, no account or privilege change, automation not enabled,
`.env` not read, nothing deleted, committed or pushed.

**Evidence limit:** the client questionnaire and Excel workbooks were not
available, and Sections 2–6 of the finalization brief (the client-source
summary) were missing from the brief received. Analytics were traced only to
the eight listed client reporting needs and the client formula
`Expiry date − current date`. See `docs/analytics_metric_traceability.md`.

### Status of the implementation record
- B0–B8 code and drafts are present as described above. Static claims were
  re-checked: syntax, schema scan, unit and static SQL tests (results below).
- Still **not done** (unchanged from the record): MariaDB test database and
  migrations (approval needed), MariaDB integration checks, browser / E2E
  checks, test accounts per role, payroll approval rule, T-N1 checklist review.

### Corrections made in this pass
| File | Change | Reason |
|---|---|---|
| `pages/analytics.php` | Budget variance actuals now cover the same calendar months as the budgets (first budget month → today, current month to date) instead of the rolling window. The header shows the window. Unused expense totals removed. Period label "This Month" → "Last 1 Month". On-page note that insight thresholds are developer values, not client targets | **Defect:** full-month budgets were compared with a partial-month window (e.g. "Last 3 Months" = 4 budget months vs about 3 months of actuals), so costs looked systematically favourable. The label described a rolling window as a calendar month |
| `pages/billing.php` | "Expenses & Profit" tab → "Expenses & Margin", shown and queried only with `finance.view` / `finance.manage`. "Payroll & Trip Pay" tab and its modals shown and queried only with `payroll.view`. "Net Profit" → "Recorded Margin" with a not-profit note. Trip-expense total limited to operating expense types (`operatingExpenseSql`). Dead "run db/payroll_migration.sql" warning removed (file does not exist; flag always false). Period labels "This Week" / "This Month" → "Last 7 Days" / "Last 1 Month" | **Access defect:** Billing & Collection staff (`billing.view` only) could see individual payroll and Trip Pay amounts and company cost totals. The Payroll page requires `payroll.view`, and the accounting dashboard limits costs to `finance.view`. Profit wording on revenue-billed figures was misleading |
| `pages/trip_costs.php` | Column "Net Profit" → "Trip Margin" with tooltip | Per-trip revenue − trip expenses − Trip Pay is not profit |
| `pages/operations_performance.php` | Added "Delivered, no waybill" count, waybill status per delivered trip (number / "File attached" / "Missing"), "Trips by client" and "Trips by driver" tables | Client needs 2 and 5 had no screen for client / driver grouping or missing waybills. Same date filter and definitions as the rest of the page |
| `includes/metrics.php` | `daysUntilExpiry()` = expiry date − today in whole days (positive = left, 0 = today, negative = overdue); `expiryDaysText()` | One implementation of the client's expiry formula |
| `pages/documents.php`, `pages/fleet_status.php` | Documents show expiry date and days left / expired. Fleet Status shows days for OR/CR and insurance (warranty already had a colour cue) via the shared helper | Need 7: document expiry dates were stored but not shown on the Documents page; truck papers showed a date only |
| `pages/announcements.php`, `dashboard_accounting.php`, `dashboard_dispatcher.php`, `dashboard_maintenance.php`, `dispatch.php`, `documents.php`, `maintenance.php`, `parts.php`, `trip_monitor.php` | Filter labels "This Week" / "This Month" → "Last 7 Days" / "Last 1 Month" (keys unchanged) | The filters are rolling windows (today − 6 days / today − 1 month), not calendar periods |
| `tests/unit/run.php` | 12 tests for the expiry helper | Covers the sign convention, today, year end, invalid input |
| `README.md` | "ERP system" → "Fleet Management System with Accounting and Inventory Management". Setup step 2 now points to the target schema and the test database checklist instead of importing the legacy schema | The legacy schema does not match the ported code. The project must not be described as ERP |
| `instructions/instructions.md` | Schema note at the database import step | Same reason; full rewrite of the guide still open |

New documents: `docs/client_data_analytics_mapping.md`,
`docs/analytics_metric_traceability.md`,
`docs/analytics_data_gaps_and_client_questions.md`,
`docs/repository_cleanup_review.md`.

### Analytics validation summary
- **A (client formula):** days until document expiry, implemented exactly as
  expiry date − current date.
- **B (client-requested, formula not specified):** trip counts by
  day / week / range, by client, truck and driver; completed, cancelled and
  delayed trips; late deliveries; status breakdown; truck availability;
  waybill per trip and missing waybills; maintenance cost per truck; licence
  and document expiry lists and reminders; trip export.
- **C (derived, rule needs validation):** on-time rate, fleet utilization,
  average trip duration, trips by dispatcher, maintenance totals and averages,
  revenue billed, collections and collection rate, receivables and aging,
  operating expenses, parts purchased, low stock, incidents.
- **D (needs clarification):** motorpool status, part warranty rule, average
  revenue per trip, recorded margin, trip margin, budget variance, all
  insight and recommendation thresholds.
- **Validation status:** every metric is static-verified only (schema names,
  pure helpers). **None has been validated against data.** The known-dataset
  SQL test still needs the approved test database.

### Repository cleanup
- Proposed for removal in this pass: **none**. No file could be confidently
  classified as unnecessary beyond the three below.
- Already deleted in the working tree **before** this pass (tracked, not yet
  committed): `assets/download`, `uploads/download (1)` (misnamed Apache
  caching files that never took effect) and `includes/autoload.php` (unused).
  Classified SAFE TO REMOVE.
- Actually removed by this pass after approval: **none** (no approval was
  requested because nothing was proposed).
- REVIEW REQUIRED: legacy `db/*.sql` (keep until the legacy database is
  retired); `auth/forgot_password_request_handler.php` (deliberate stub).

### Tests run in this pass
| Command | Env | Result |
|---|---|---|
| `C:\xampp\php\php.exe tests/unit/run.php` | UNIT | PASS 96 / 0 (84 before + 12 new) |
| `C:\xampp\php\php.exe tests/static/run.php` | STATIC + MOCK | PASS — 9 capture files, CONFIRMED 0, UNCERTAIN 0, negative control reported. Operations Performance now captures 6 statements |
| SQL capture of `pages/billing.php` (roles finance, billing_collection) + `schema_check.php --drafts=all` (ad hoc, not added to the suite) | STATIC + MOCK | CONFIRMED 0. UNCERTAIN 2 = the known `IF(pay_type = …, 'Helper', 'Driver')` display labels |
| `scripts/schema_check.php --drafts=all --confirmed-only` (default paths) | STATIC | 120 files, CONFIRMED 0 |
| `php -l` on every PHP file present | STATIC | 136 files, 0 errors (`includes/autoload.php` is listed by git but deleted) |
| `node --check assets/js/*.js` | STATIC | 30 files, 0 errors (no JS changed in this pass) |
| MariaDB, browser, E2E, concurrency | — | **NOT RUN** (no approved test database; no browser session) |

### Outstanding decisions
1. Owner: approve the test database and checklist (unchanged), then run
   `tests/sql/analytics_known_dataset.sql` and the integration checklist.
2. Owner: confirm the Billing page visibility change (Finance without
   `payroll.view` no longer sees the Payroll & Trip Pay tab).
3. Owner: decide whether `reports.view` / `maintenance.reports.view` should be
   granted beyond Admin, Operations Head and Maintenance.
4. Client: questions Q1–Q11 in `docs/analytics_data_gaps_and_client_questions.md`.
5. Owner: legacy schema retirement (decides the legacy `db/*.sql` files).
6. Unchanged from the record: gated drafts D3–D14, payroll approval rule,
   T-N1 checklist review, test accounts.

---

## Analytics verification pass — 2026-10-15 (Phase 1 continuation)

Scope: verify analytics against the actual client sources. No SQL migration,
database setup, deployment, UI polishing or repository cleanup was started.
Pre-edit copies are in
`C:\Users\acer\mulawin_patches\analytics_verification_20261015\` (`files/`,
`pre_edit.sha256`, `git_status_before.txt`). The 2026-10-14 backups are
untouched.

### State check
- Branch `system-hardening`, HEAD `be54521…`, 146 status entries: same as the
  2026-10-14 checkpoint. The finalization docs were unchanged since that pass
  (their last write was its own Need 6 correction).

### Client sources (correction to the 2026-10-14 record)
The 2026-10-14 pass recorded that the questionnaire and workbooks were not
available. **They were in `C:\Users\acer\Downloads` and were simply not
searched for.** Found and inspected this pass:
`Mulawin_FleetOps_Client_Questionnaire-2.pdf` (filled in),
`Mulawin_FleetOps_Client_Questionnaire.pdf` (blank older form),
`CAR CARRIER MONITORING 2026 (1).xlsx` (= `… 2026.xlsx`),
`CONTAINER MONITORING  2026 (1).xlsx` (= `… 2026.xlsx`),
`DRIVERS PAYROLL.xlsx` (structure only). Still unavailable: maintenance,
billing / collection, expense and accounting records, and questionnaire
Section I. All classifications were redone; see
`docs/analytics_metric_traceability.md`.

### Main findings
- **Confirmed by client evidence:** total trips as a count of trips (A;
  reproduced from CC rows); Monday–Sunday weeks (reproduced from CT weekly
  summary); trips per truck (A); cancelled count (A); maintenance expenses per
  truck = parts + labor + repair (A; formula matches, not compared to data);
  document expiry `Expiry date − current date` (A; helper matches); revenue =
  billed amount (A); profit structure = billed − all expenses (A, but the
  app's margin covers recorded costs only).
- **Contradicted by client evidence, left unchanged pending client answers:**
  "Delayed" (client: reported delay with reason; app: past scheduled arrival),
  on-time rate (client: no formula, no time records), fleet utilization
  (client: days deployed / idle / down), trips by driver (car-carrier trips
  have two drivers; app credits one), expiry alert lead (client 365 days; app
  30 / reminders 60–7), trip date basis (client: actual departure / waybill
  submission; app: scheduled departure).
- **Missing in the schema:** delivered units, waybill submission date, client
  payment terms, company / parent client, daily truck status, document renewal
  status. Proposals only, after client answers; nothing drafted.
- **Workbook reliability:** hidden consolidated sheets duplicate the monthly
  trip lists (2,017 duplicate keys); the "SEPT. 2026" sheet holds August rows;
  January waybills mostly blank; one stale pivot (June). Details in
  `docs/testing/analytics_validation_report.md`.

### Correction made
| File | Change | Reason |
|---|---|---|
| `pages/billing.php` | The Payroll & Trip Pay tab and its queries now need `payroll.view` **or `finance.view`** (2026-10-14: `payroll.view` only). "Log Salary Payment" button, form and employee list now need `payroll.manage` | Questionnaire Q1: payroll reports are reviewed by "Finance Personnel / Audit Personnel / Management". The 2026-10-14 change had removed Finance's view. Billing & Collection still cannot see payroll. Recording a payment already required `payroll.manage` in `ajax/payroll_handler.php`, so the button now matches the server rule |

No other application file changed. No permission seed or account changed.

### Tests in this pass
| Category | Result |
|---|---|
| Static | `php -l pages/billing.php` PASS; Billing SQL capture (finance, billing_collection, payroll) + schema check: CONFIRMED 0, UNCERTAIN 2 (known display labels) |
| Unit | Not re-run (no helper changed); last 96 / 0 |
| Mocked | Billing capture above (stub PDO) |
| **Actual client records** | Session-scratch replay (outside the repository) of CC / CT rows through `buildBuckets()`, the week rule and `daysUntilExpiry()`: **8 / 0 passed** |
| MariaDB integration | **Not run** (not approved) |
| Browser / E2E | **Not run** |

### Updated documents
`docs/client_data_analytics_mapping.md`,
`docs/analytics_metric_traceability.md`,
`docs/analytics_data_gaps_and_client_questions.md` (client questions CQ-1–CQ-18,
owner decisions OD-1–OD-4), new `docs/testing/analytics_validation_report.md`.

### Next actions to complete analytics validation
1. Client answers to CQ-1, CQ-4, CQ-5, CQ-7, CQ-12 and CQ-14 first: they decide
   the trip date basis, driver credit, units, delayed and waybill rules, and
   expiry alerts.
2. Obtain the missing sources: maintenance / motorpool records, billing and
   collection records, an expense report, and questionnaire Section I.
3. Owner decisions OD-1–OD-4 (report access).
4. After approval of the test database: import a slice of CC / CT records (one
   month each) and run the real report queries against the client totals
   reproduced here.

---

## Clarification and access-decision preparation — 2026-10-15

Checkpoint verified before starting: branch `system-hardening`, HEAD
`be54521`, 146 status entries, `pages/billing.php` modified, `docs/`
untracked. Doc timestamps all from the analytics verification pass; backups
in `C:\Users\acer\mulawin_patches\analytics_verification_20261015\` intact.
No difference from the reported checkpoint.

### Prepared (documents only)
- **New** `docs/client_analytics_clarification_request.md`: client-facing
  request. Part A has the six blocking questions (CQ-1, CQ-4, CQ-5, CQ-7,
  CQ-12, CQ-14) with evidence, current behaviour, why it matters, an example,
  and affected reports / fields. Part B has CQ-2, 3, 6, 8–11, 13, 15–18.
  Part C requests the missing records. No personal or salary data. **Not
  sent; no answers or records received.**
- `docs/analytics_data_gaps_and_client_questions.md`: status note; detailed
  **owner access decisions OD-1–OD-4** (current behaviour, options, impact,
  minimum permission, recommendation where documented); review of the
  `pages/billing.php` permission behaviour (no defect found, unchanged).

### Unchanged on purpose
No application code, formula, date rule, alert threshold, permission, seed,
schema or migration draft was changed. No database access, no SQL, no
commit or push. No tests were run, because no code changed (the expiry
example in the request was checked with `daysUntilExpiry()`: 46 days).

### Waiting for
1. Client answers to CQ-1–CQ-18 (Part A first) and the Part C records.
2. Owner decisions OD-1–OD-4.

---

## Owner access proposals recorded — 2026-10-15

Checkpoint verified: branch `system-hardening`, HEAD
`be5452194c0baeae4db1c5cec03bfed0995481b6`, 146 status entries; no
difference. Pre-edit copies in
`C:\Users\acer\mulawin_patches\owner_proposals_20261015\` (new folder;
earlier backups untouched).

The owner's proposals for OD-1–OD-4 were recorded in
`docs/analytics_data_gaps_and_client_questions.md` ("Owner proposals
(2026-10-15)") as **PROPOSED — NOT YET IMPLEMENTED**:

| # | Proposal | Status |
|---|---|---|
| OD-1 | Dispatchers see operational trip counts and summaries, no financial data | Proposed; scope (all fleets vs own) open |
| OD-2 | Operations Head maintenance-cost visibility needs an explicit owner decision | Proposed; current access unchanged |
| OD-3 | Audit: read-only approved reports and relevant audit records; no transaction or approval authority | Proposed; **flagged for owner confirmation** |
| OD-4 | Finance may view payroll and Trip Pay; recording stays under `payroll.manage` | Proposed; current access unchanged |

Found while documenting (verified in code, not changed): the Audit role has
no read-only audit-log page. Its `audit.view` nav item (Recycle Bin) is
Admin-only, and audit history appears only on the Admin dashboard.

No application code, permission, role seed, schema, formula, workflow or
configuration was changed. No database access. No client approval has been
received. The clarification request remains unsent.

---

## Owner access proposals — revision 2 recorded (2026-10-15)

**Before editing:**
- Branch `system-hardening`, HEAD
  `be5452194c0baeae4db1c5cec03bfed0995481b6`, 146 status entries;
  `pages/billing.php` modified and `docs/` untracked, as at the previous
  checkpoint.
- Pre-edit copies are in
  `C:\Users\acer\mulawin_patches\owner_decisions_rev2_20261015\`. Earlier
  backup folders were not touched.

**Recorded** in `docs/analytics_data_gaps_and_client_questions.md` ("Owner
proposals — revision 2") as **OWNER PROPOSALS — NOT YET IMPLEMENTED**.
Revision 1 is kept and marked superseded.

| # | Owner proposal | Key verified facts | Main unresolved items |
|---|---|---|---|
| OD-1 | Dispatchers: counts and summaries limited to assigned truck types, shifts and workload; no financial data | A scope mechanism exists (role key → truck types; one active `dispatcher_assignments` row → shift; fails closed). **No screen maintains `dispatcher_assignments`**; scope uses today's assignment; `trips.shift` nullable | Who maintains assignments; historical scope rule; which views; workload definition |
| OD-2 | Operations Head: per-truck maintenance-cost totals approved in principle | Individual record costs are already visible (`maintenance.view`, `pages/maintenance.php`). Maintenance Reports also shows work orders and downtime | Exact page and fields; labor not separated; Purchasing |
| OD-3 | Audit: designated internal auditor; read-only approvals / workflow history, record changes / admin actions, login events, payroll and Trip Pay amounts; no create / edit / delete / restore / approve | Audit menu item "Recycle Bin" leads to an Admin-only page (403). There is no audit-history page for Audit. Sources: `audit_logs`, `approval_history`, `workflow_events`; `login_attempts` purged daily; `deleted_records` (draft) can hold payroll data. Page views and the three exports are not logged | Designated person and authorization process; source scope and completeness; deleted-record details; menu issue; how sensitive pay access is authorized and audited |
| OD-4 | Finance: Billing-page payroll tab only; no Payroll page; recording stays `payroll.manage` | The tab shows net amounts, periods, paid dates and Trip Pay rows; no deductions breakdown and no payslips | Whether the tab suffices; formal acceptance of the 2026-10-15 Billing-page behaviour |

No application code, permission, role seed, navigation, schema, SQL,
formula, workflow or configuration was changed. No database access, no
tests run (documentation only). No client approval exists. CQ-1–CQ-18
remain unanswered, and the clarification request remains unsent and
unchanged.

---

## Owner access decision worksheet — 2026-10-15

**Checkpoint:** branch `system-hardening`, HEAD `be54521…`, status identical to
the previous snapshot (146 entries). Pre-edit copy of this file and a
checksum snapshot are in
`C:\Users\acer\mulawin_patches\owner_worksheet_20261015\`.

**New:** `docs/owner_access_decision_worksheet.md`, an internal worksheet for
OD-1–OD-4. For each decision it gives existing behaviour, the owner proposal
(revision 2), the unresolved questions (1-a–1-e, 2-a–2-c, 3-a–3-g,
4-a–4-b) with answer lines, and approval status. Every item is marked
**OWNER DECISION REQUIRED — NOT IMPLEMENTED**.

**Correction found (documented, not applied elsewhere).** A code re-check for
OD-2 showed that `pages/maintenance.php` already shows repair work orders with
per-order downtime to `maintenance.view` holders, including the Operations
Head. The revision-2 note in `docs/analytics_data_gaps_and_client_questions.md`
("would also expose the work-order list and downtime") overstates what the
per-truck report adds. It actually adds date-range aggregation (cost per
truck, work-order counts, total downtime), closed time and opener names. The
worksheet records the corrected comparison. The gaps document was **not**
edited in this task and still needs that sentence corrected.

No application code, permission, role seed, schema, formula, workflow or
configuration changed. No database access. The client clarification request
is unsent and unchanged; CQ-1–CQ-18 remain unanswered.

---

## OD-2 owner decision recorded — 2026-10-15

Checkpoint unchanged: branch `system-hardening`, HEAD `be54521…`, status
identical. Pre-edit copies are in
`C:\Users\acer\mulawin_patches\od2_decision_20261015\`.

**OD-2 — OWNER APPROVED IN PRINCIPLE — DOCUMENTATION ONLY; NOT APPROVED FOR
IMPLEMENTATION.** The Operations Head may receive a limited per-truck
maintenance report, in principle:

- **Contents:** recorded maintenance cost per truck (costs actually recorded
  only), work-order counts, total downtime, work-order closed times.
- **Excluded:** opener names.
- **Parts purchase costs:** stay excluded (avoid double counting).
- **Labor:** no separate figure unless reliable source records support it.

This is **not** a permission approval or an implementation authorization;
current access is unchanged. The existing Maintenance Reports page shows
opener names, so granting it as-is would not match the decision.

Still open (worksheet OD-2, 2-d–2-h):
- the exact page and fields in the current code;
- runtime access verification;
- the definition and display of recorded cost;
- labor (no code path writes `labor_cost`; no value may be invented);
- Purchasing Office access.

Recorded in `docs/owner_access_decision_worksheet.md` and
`docs/analytics_data_gaps_and_client_questions.md`. No code, permission,
seed, schema or database change. OD-1, OD-3 and OD-4 remain open;
CQ-1–CQ-18 remain unanswered.

---

## OD-1 decision section prepared — 2026-10-15

Checkpoint unchanged (branch `system-hardening`, HEAD `be54521…`, status
identical). Pre-edit copies are in
`C:\Users\acer\mulawin_patches\od1_prep_20261015\`.

`docs/owner_access_decision_worksheet.md`, OD-1: the question table was
replaced by a decision section with five topics. Each has an explanation,
owner answer lines and the marker **OWNER DECISION REQUIRED — NOT
IMPLEMENTED**:

- 1-a assignment maintenance and corrections;
- 1-b historical reporting (assignment on the trip date vs current
  assignment, with consequences);
- 1-c page access (current vs proposed, unverified items marked);
- 1-d assigned workload (three readings, none chosen);
- 1-e data integrity (assignment rows not verified against a live database;
  no assignment-maintenance screen identified; trips without a shift;
  per-role truck types).

A narrow guard re-check for 1-c corrected two earlier assumptions:

- the Dispatch page needs `trips.view`;
- Dispatch Planning needs `dispatch.instructions.manage`, which only the
  Operations Head holds in the seed, so dispatchers have no access.

Trip Monitor and Trip Workflow admit `trips.view`, with no `dispatcherScope()`
call found; whether they filter in some other way is unverified.

The OD-2 decision and its questions 2-d–2-h are unchanged. No code,
permission, seed, schema or database change; client questions unchanged.

---

## OD-1 owner decisions (1-a) recorded — 2026-10-15

Checkpoint unchanged (branch `system-hardening`, HEAD `be54521…`). Pre-edit
copies of the two edited files are in
`C:\Users\acer\mulawin_patches\od1_decisions_20261015_074804\`.

Recorded in `docs/owner_access_decision_worksheet.md`, OD-1 topic 1-a, as
**owner decisions for documentation only, not implementation approval**:

1. Responsible role for dispatcher assignments: **Operations Head or Admin**.
2. Maintenance method: **a dedicated, permission-protected system screen.**
   It does not exist, is not authorized to be built, and its permission key
   is not defined.
3. Assignment-change audit trail: **required**, recording who changed an
   assignment and when.

Still OWNER DECISION REQUIRED — NOT IMPLEMENTED:

- 1-a: how corrections are handled;
- 1-b: historical reporting rule;
- 1-c: page access;
- 1-d: workload definition;
- 1-e: data integrity checks, trips without a shift, per-role vs per-person
  truck types.

No application code, UI, permission, role seed, schema, migration or database
record was changed; no SQL was executed and no database was connected.

---

## OD-1 1-a correction options documented — 2026-10-15

`docs/owner_access_decision_worksheet.md`, OD-1 1-a: added "1-a
(corrections): options and implications". It compares **A. end the
incorrect row and add a replacement** with **B. edit in place**, covering:

- what remains in the table;
- who / when / what is recorded;
- effect on historical reporting (1-b);
- lock-out risk from overlapping rows;
- never-valid rows vs real shift changes;
- fit with the decided audit trail.

It also mentions a possible mixed rule, and adds three answer lines (method,
reason, overlap prevention), all **OWNER DECISION REQUIRED — NOT
IMPLEMENTED**. No option was selected.

Basis: the reference schema (no migration draft touches
`dispatcher_assignments`) and the recorded reading of
`includes/dispatcher_scope.php`. Not verified against any database. Pre-edit
copies are in
`C:\Users\acer\mulawin_patches\od1_corrections_20261015_075144\`. No code,
UI, permission, seed, schema, migration or database change; no SQL executed.

---

## OD-1 1-b historical reporting documented — 2026-10-15

`docs/owner_access_decision_worksheet.md`, OD-1 1-b: added "1-b detail:
consequences and dependencies". It compares **assignment in force on the
trip date** with **current assignment** across:

- what a dispatcher sees for past periods;
- what happens after a shift change;
- dispatchers with no current assignment;
- overlap or gap handling;
- data and work needed;
- stability of comparisons.

It also states two dependencies:

- **1-a:** correction handling decides whether assignment history is
  trustworthy. End-and-replace keeps void rows without a void flag;
  edit-in-place loses history except in the not-yet-built audit trail.
- **CQ-1:** the trip date basis is unanswered.

Both 1-b answer cells are now marked **OWNER DECISION REQUIRED — NOT
IMPLEMENTED**. No option was selected or approved.

Basis: the recorded reading of `includes/dispatcher_scope.php` and the
reference schema; live and test database behaviour is unverified. Pre-edit
copies are in `C:\Users\acer\mulawin_patches\od1_history_20261015_075708\`.
No code, UI, permission, seed, schema, migration or database change; no SQL
executed.

---

## OD-1 1-c page access documented — 2026-10-15

`docs/owner_access_decision_worksheet.md`, OD-1 1-c: the current-behaviour
table was rebuilt from a re-inspection of these pages and handlers:

- Dispatch, Dispatch Planning, Trip Monitor, Trip Workflow;
- Operations Performance, Analytics;
- the dashboards and Fleet Status;
- the dispatch submission and trip status / workflow handlers.

For each, it gives the permission check, whether a dispatcher can open it
(reviewed seed), whether the assignment filter applies to displayed data,
financial data, and what is unverified.

**Corrections to earlier worksheet text:**

- **Dispatch page:** only the available-truck list is limited (by truck type,
  not shift). Its dispatch request list is **not** filtered by assignment.
  Earlier the page was recorded as "scoped".
- **Trip Monitor and Trip Workflow:** they apply **no** assignment filter to
  displayed trips; previously recorded as unverified.
- **Fleet Status:** no scope call.
- **Trip status and workflow actions:** **are** checked against the assignment
  (`dispatcherScopeViolation()`).

The OD-1 "Existing behaviour" bullet was minimally corrected to match.

Current behaviour and the proposed rule are kept separate; no page list is
decided. The three answer lines are **OWNER DECISION REQUIRED — NOT
IMPLEMENTED**. One question was reworded: "review Fleet Status, Trip Monitor
and Trip Workflow for scope" became "limit or keep full visibility on the
pages dispatchers already open", since the review is now done.

1-a and 1-b, and everything else, are unchanged. Repository inspection does
not verify live or test database behaviour. Pre-edit copies are in
`C:\Users\acer\mulawin_patches\od1_pages_20261015_080150\`. No code, UI,
permission, seed, schema, migration or database change; no SQL executed.

---

## OD-1 1-d workload documented — 2026-10-15

`docs/owner_access_decision_worksheet.md`, OD-1 1-d: added "1-d detail:
requests, trips, statuses and grouping". It is based on the schema and code,
and covers:

- requests vs trips (one request per trip, created together at submission;
  requests are linked to the **submitter**, not assigned; `dispatcher_id` is
  never changed);
- the existing submitter-only rule for status and workflow actions;
- the verified status values (request: Pending / Approved / Rejected /
  Cancelled, where **Cancelled is never set by code**; trip: 11 values, with
  **no Rejected or Delayed status**, a rejected request **cancelling** its
  trip, and "For Review" unused);
- double-counting risks (request + trip; rejected vs cancelled; Pending vs
  Pending Approval; delayed overlaps active);
- alternatives W1–W4 and grouping fields.

All answer lines are marked **OWNER DECISION REQUIRED — NOT IMPLEMENTED**,
including the two existing 1-d questions (previously blank). No option
chosen.

No factual error found in 1-a, 1-b, 1-c or 1-e; they are unchanged. Not
recorded there: 1-c's action row mentions the assignment check, but not that
actions are also limited to the submitting dispatcher. That is now in 1-d.
Repository inspection does not establish live or test database behaviour.
Pre-edit copies are in
`C:\Users\acer\mulawin_patches\od1_workload_20261015_080752\`. No code, UI,
permission, seed, schema, migration or database change; no SQL executed.

---

## OD-1 1-e data integrity documented — 2026-10-15

`docs/owner_access_decision_worksheet.md`, OD-1 1-e: added "1-e detail:
integrity questions". Each topic separates verified schema / code facts,
unverified runtime behaviour, and owner decisions:

- (i) **overlapping assignment rows:** no constraint; anything other than
  one active row → denied and error-logged; type mismatch denied;
- (ii) **trips with no shift or no truck:** both nullable; such trips are
  left out of the dispatcher dashboard. A no-shift trip with a truck may
  still appear on the submitter's general dashboard (corrected 2026-10-15,
  see below). Actions on them are refused for dispatchers;
- (iii) **truck types per role:** Container and Wing Van are bundled; scoped
  figures use the truck's **current** type; a truck's type is editable
  (except while Assigned / On Trip), which would move its past trips between
  dispatchers;
- (iv) **test-database inspection:** the test database is not approved or
  created; the integration checklist has no assignment-row check; candidate
  read-only checks are described in words only, not written as SQL, not run.

The three existing 1-e answer cells (previously blank) and three new
questions are marked **OWNER DECISION REQUIRED — NOT IMPLEMENTED**. No
decision taken.

1-a to 1-d are unchanged. No runtime verification occurred and no tests were
run. Pre-edit copies are in
`C:\Users\acer\mulawin_patches\od1_integrity_20261015_081421\`. No code, UI,
permission, seed, schema, migration or database change; no SQL executed.

---

## OD-1 test-database readiness assessment documented — 2026-10-15

New `docs/testing/od1_assignment_test_readiness.md` (status: ASSESSMENT
ONLY — NOT EXECUTED). It records:

- **Readiness:** not ready for meaningful dispatcher-assignment checks. A
  test database built per `database_setup_checklist.md` would have no
  assignment rows, and no users or trips unless test data is loaded.
  Repository inspection found no `dispatcher_assignments` table in the legacy
  schema file and no assignment-row inserts in the reference schema or the
  21 drafts. The actual legacy database is unverified.
- **Agreed direction:** synthetic test data first, to validate assignment
  logic. Synthetic results do not establish real assignment correctness.
  Migrating or reconstructing real assignment data remains a separate future
  decision and task.
- Proposed synthetic scenarios S1–S6, planned only.
- The five candidate read-only checks, with what they could and cannot show.
- Decisions affecting interpretation (1-a corrections, 1-b, 1-e items,
  source of real data, CQ-1) vs those needed before implementation (1-c,
  1-d).
- Prerequisites from setup checklist §0 (none confirmed), target-safety
  rules, and the eight separately approvable steps.

No database or runtime verification has occurred. No database operation,
SQL writing or execution, schema import, migration, data loading, account
creation, application-code change or permission change was authorized or
performed. Owner decisions are unchanged. Pre-edit copy of this file is in
`C:\Users\acer\mulawin_patches\od1_readiness_20261015_082559\`.

---

## OD-1 synthetic assignment test matrix prepared — 2026-10-15

New `docs/testing/od1_assignment_synthetic_test_matrix.md` (status:
PROPOSED PLAN — NOT EXECUTED). It covers:

- scenarios S1–S6, each with purpose, minimum synthetic records, fields,
  actions, a **CODE-BASED EXPECTATION — NOT TESTED**, evidence,
  pass / fail / unexpected criteria, database / runtime needs, and risks;
- the five read-only checks C1–C5, kept separate, with synthetic vs later
  real-data suitability.

Items not settled by code reading are marked **UNCONFIRMED — NEEDS REVIEW**:
S4 login and schema allowance, the denial message display, and the S6
workflow replacement outcome.

Code reading for the matrix noted a detail not in earlier docs: for
dispatchers, `pages/dashboard.php` filters to **their own submitted trips and
their truck types, with no shift filter**. The worksheet text was not
changed.

No scenario has been run. No database, SQL, test data, test account or
runtime test was touched. Pre-edit copy of this log is in
`C:\Users\acer\mulawin_patches\od1_matrix_20261015_083429\`.

---

## OD-1 documentation consistency corrections — 2026-10-15

The owner authorized eight documentation-only corrections from the OD-1
consistency review. Applied:

1. **No-shift trips:** a trip without a shift is left out of the
   **dispatcher dashboard** (shift filter). A no-shift trip **with a truck**
   may still appear on the submitting dispatcher's **general dashboard**;
   trips with no truck are excluded from truck-type-scoped views. Dispatcher
   actions stay refused. Corrected in:
   - the worksheet: limitation #3, the 1-e intro and 1-e detail (ii);
   - the readiness doc: S5;
   - this log: the 1-e entry (ii);
   - the gaps doc: OD-1 revision 2, limitation 3.
2. **General dashboard:** worksheet existing-behaviour bullet and 1-c table
   row now say "own submitted trips and truck type, not shift". Whether to
   add shift filtering stays an open owner decision.
3. **Matrix S2:** the fail criterion now refers to a submission succeeding
   while two active rows cover both today and the scheduled date. The
   future-only variant B is unchanged.
4. **Worksheet 1-e(iv) and its question:** no existing assignment rows are
   known in the reviewed schema files; points to the readiness doc and the
   synthetic-first direction; "the approved test database" became "a test
   database, if approved".
5. **Worksheet 1-a corrections table:** "for any date" replaced with today
   vs scheduled-date wording (past-only overlaps no effect; future-only
   overlaps take effect when the date becomes today).
6. **C1:** the worksheet's list now says "covering the check date" and that
   it does not establish valid real assignment data. The readiness doc and
   matrix already used that wording.
7. **Readiness §4:** 1-c is noted as also relevant to interpreting S1's
   general-dashboard result. Existing behaviour and the unresolved desired
   rule are kept apart.
8. **Gaps doc status:** OD-1 is open; 1-a is partly decided for documentation
   purposes only, with its remaining questions open.

No owner decision was made or extended. All "NOT EXECUTED" and "CODE-BASED
EXPECTATION — NOT TESTED" labels are kept. No database, SQL, test, synthetic
data, account, permission, seed, schema, migration or code change.
- Pre-edit copies of the five edited files are in `C:\Users\acer\mulawin_patches\od1_consistency_fix_20261015_084417\`.

---

## D3 direction and client questions CQ-4 / CQ-19 — 2026-10-15 (documentation only)

**D3 (second driver):** the original D3 record could not be found in the
repository, its git history, `mulawin_patches` or the project documents. The
following direction is recorded as **PROPOSED / PENDING OWNER CONFIRMATION**,
not approved:

1. retain second-driver functionality in principle;
2. leave the data model (T-T1's fixed column or a crew-slot model) undecided
   until CQ-19 is answered;
3. T-T1 may be included conditionally in the isolated-test-database migration
   plan as a technical prerequisite only (no design approval, no execution, no
   database operation);
4. migration execution stays separately approved.

**Client questions (draft; request still unsent):**

- **CQ-4 revised:** pronoun corrected; adds whether both drivers receive trip
  pay; trip credit and pay separated from the storage design.
- **New CQ-19 (Part B):** maximum crew composition (more than one second
  driver or helper, a third or relief driver, a helper with two drivers, crew
  changes mid-trip).

**Files:**

- `docs/client_analytics_clarification_request.md`;
- `docs/analytics_data_gaps_and_client_questions.md` (CQ-4 row, CQ-19 row,
  counts CQ-1–CQ-19, schema-dependency note);
- `docs/owner_access_decision_worksheet.md` (header count, D3 section,
  summary row);
- `docs/testing/od1_assignment_test_readiness.md` (§6 conditional T-T1 note);
- this log.

`docs/testing/database_setup_checklist.md` is unchanged. No PHP, JavaScript,
SQL, schema or migration draft changed; T-T1 was not executed; no database
operation. Pre-edit copies are in
`C:\Users\acer\mulawin_patches\stage1_d3_cq19_docs_20261015_100448\`.

---

## Test database created after XAMPP crash — 2026-10-16 (owner request)

After a device crash, XAMPP's MariaDB 10.4.32 lost its table registry.
`mulawin_fleetops` showed 27 tables, some missing or empty;
`mulawin_fleetops_new` showed 0 tables, with its files still on disk. At the
owner's request (fresh test database; all 21 drafts for testing only;
`.env` DB_NAME changed by the assistant):

1. The whole `C:\xampp\mysql\data` folder (damaged files included) was copied
   to `C:\Users\acer\mulawin_patches\mysql_data_copy_after_crash_20261016_092548\`.
   The damaged databases were **not touched**.
2. `mulawin_fleetops_test` was created (utf8mb4_unicode_ci). The filtered
   reference schema was imported (70 tables + 3 views).
3. All 21 drafts were applied in `SC_DRAFT_ORDER`, every one without error.
   This is **for testing only**: it does not approve D3 or any other pending
   decision.
4. Read-only checks:
   - 86 tables and views;
   - 12 role keys and 6 approval workflows;
   - Operations Head holds `reports.view`; 68 permissions;
   - all automation settings 0; 0 users;
   - database time zone SYSTEM = Asia/Singapore (UTC+8).
5. `.env`: only `DB_NAME` changed, to `mulawin_fleetops_test`. Backup in
   `C:\Users\acer\mulawin_patches\env_backup_20261016_092655\`.
6. The app's own config connects to `mulawin_fleetops_test`; the login page
   returns HTTP 200.
7. **Clean rebuild (owner's choice).** MySQL then stopped, with no log entry,
   and the crash had left `ibdata1` inconsistent ("log sequence number … in
   the future"). The data folder was moved, not deleted, to
   `C:\xampp\mysql\data_crashed_20261016_093056`, and XAMPP's clean template
   (`C:\xampp\mysql\backup`) was installed. MySQL was started with XAMPP's
   `mysql_start.bat`; the new log shows 0 errors. `mulawin_fleetops_test` was
   re-created the same way (filtered schema + 21 drafts, all OK), with the
   same counts (86 objects, 12 roles, 6 workflows, 68 permissions, automation
   off, 3 inspection templates). The app connects; the login page returns
   HTTP 200. Other local databases (`lab_sql`, `login`, `mfpdemo…`, the
   damaged `mulawin_fleetops*`) are no longer loaded in MySQL; their files
   remain in both copies.

Not done: the Admin account (`auth/seed_users.php` needs the owner's
interactive terminal); test accounts per role; dispatcher assignment rows
(no screen exists); data; browser and integration tests.

---

## Test accounts, dispatcher assignments and demo data — 2026-10-16 (owner request)

Database: `mulawin_fleetops_test` only. The script refused to run on any
other database or on a non-empty one. Backup of the empty test database:
`C:\Users\acer\mulawin_patches\test_db_before_seed_20261016_113631\`.
The script (`seed_test_demo.php`) is in the session scratchpad, not the repo.

- **12 test users**, one per active role. The username is the role key
  (`admin`, `operations_head`, `car_carrier_dispatcher`,
  `container_wing_van_dispatcher`, `maintenance`, `purchasing_officer`,
  `finance`, `billing_collection`, `payroll`, `audit`, `driver`, `helper`).
  Shared test password intentionally omitted from this document. Each is
  linked to an employee "Test <Role>"; the driver has a licence. These are
  test-only accounts; reset their credentials before reuse or before moving
  them outside the isolated test database.
- **Dispatcher assignments:** both dispatchers, Day shift, from 2026-07-18,
  open-ended (no maintenance screen exists yet; OD-1).
- **Demo data (fake):**
  - 5 extra crew (3 drivers, 2 helpers); 3 clients, 4 routes, 5 client rates;
  - 6 trucks with 18 OR/CR/insurance documents (one expiring in 20 days, one
    expired);
  - 14 trips: 7 Completed, 1 Delivered, 1 In Progress, 1 Dispatched,
    1 Assigned, 2 Pending Approval, 1 Cancelled; each with a dispatch
    request; deliveries with waybills;
  - 5 billings (2 Paid, 1 Partially Paid, 2 Issued), 3 collections;
  - 21 trip expenses, 14 Trip Pay records;
  - 3 maintenance records (completed, in progress, scheduled), 1 supplier,
    4 parts with stock (one below reorder level).
- Read-only checks after seeding: all counts as above; every user linked to
  its employee; MySQL error log 0 errors.
- **Not done (owner excluded it):** logging in as each account and opening
  the pages. Browser and integration tests remain NOT TESTED.

Owner answers to CQ-1–CQ-19 and OD-1–OD-4 were recorded the same day in
`docs/analytics_data_gaps_and_client_questions.md` (last section), with no
code, permission or schema change.
