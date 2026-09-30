# Changelog

## Unreleased

### Phase 13
- Normalized the billing party: `billings` now has a `client_id` FK to `clients` (alongside the existing free-text `client_name`, kept for one-off bill-to overrides), backfilled from existing rows by name match.
- Added optional `invoice_number` (unique when set) and `invoice_date` columns so Accounting can record a client's own invoice reference separately from the internal `billing_number`.
- Added an "Unbilled Trips" dashboard tab on the Billing page listing completed trips with no billing record yet, with a one-click "Create Billing" action that pre-selects the trip.
- Added a filtered, downloadable AR CSV export (`ajax/billing_export.php`) that respects the same period filter as the Billing page.
- Validated against a disposable schema: client_id backfill from `client_name` match, invoice_number uniqueness (including that multiple `NULL` invoice numbers don't conflict), and the Unbilled Trips query correctly excluding already-billed trips. PHP lint, `node --check` on the JS, and `git diff --check` all passed. No changes were applied to the configured FleetOps database.
- Client-rate override auditing at billing time (i.e., a dispatcher/accounting override of the effective-dated `client_rates` lookup, with a reason) was intentionally not built — it's policy-dependent (who can override, and is an approval required?) and is flagged for confirmation rather than invented.

### Phase 12
- Added a pre-confirmation trip reassignment action (`reassign_resources`): lets the submitting dispatcher (or Admin) change the assigned truck, driver, second driver, helper, and schedule on a trip that is still `Loading` and has not yet had its assignment confirmed. Reuses the existing `lockDispatchResources()`/`assertDispatchResourcesAvailable()` checks from dispatch creation, so a truck/crew conflict or an inactive crew member is rejected the same way as at initial dispatch. Releases the previous truck back to `Available` and marks the new one `Deployed` when the truck changes; requires a reason for audit purposes.
- Added a printable/exportable trip pre-advice notice (`pages/trip_pre_advice.php`) summarizing route, client, truck/crew, schedule, and cargo/reference details, linked from the trip workflow page.
- Validated the reassignment logic (truck swap, releasing/redeploying trucks, same-date truck/crew conflict rejection, inactive-crew rejection) against a minimal disposable schema exercising the real `includes/dispatch_assignment.php` helpers. PHP lint and `git diff --check` passed; no migration or schema change was needed, and no changes were applied to the configured FleetOps database.
- Batch assignment/execution and custom client-specific trip fields remain open pending confirmation of the desired fields/policy.

### Phase 11
- Added `parts_inventory.warranty_expiry` and a part-warranty expiry reminder alongside the existing document/employee-license reminders, notifying employees/fleet managers via the existing reminder threshold configuration.
- Fixed a read-then-write race condition in `record_movement`: the part row is now locked with `SELECT ... FOR UPDATE` inside the transaction before computing the new stock quantity.
- Added an "Adjustment" physical-count reconciliation flow: the submitted quantity is the counted total (can be 0), and the system computes and logs the signed delta; a counted total equal to the current recorded stock is rejected as a no-op.
- Added a warranty-expiry date field to Add Part, a "Warranty Expiring" tab and column on the Parts page, and an estimated stock-value summary card (based on last recorded unit cost, not a costed valuation method).
- Fixed a legacy reminder-dedup fingerprint collision risk: `part_warranty` reminders now use a distinct fingerprint prefix so they cannot collide with `employee_license` reminders sharing the same numeric ID and due date; `document`/`employee_license` fingerprint formats are unchanged for backward compatibility.
- Added `db/phase11_inventory_controls_migration.sql`; validated against a disposable MariaDB schema (add part with warranty date, Stock In, Stock Out insufficient-stock rejection, Adjustment no-op rejection, Adjustment delta computation including a 0 count). PHP lint, JavaScript syntax checks, and `git diff --check` passed; no migration was applied to the configured FleetOps database.

### Phase 10
- Added the ordered 13-step trip workflow for new dispatches, recording the initial request, availability check, trip details, and approval submission; post-approval Dispatcher assignment confirmation; trip-document and allowance readiness; passed pre-departure checks; Operations Head dispatch clearance; truck dispatch; progress updates; delivery/return details; arrival inspection; and final completed-trip submission.
- Trip allowance preparation now requires either an explicit “Not Required” reason or a linked Trip Allowance fund request in Approved/Disbursed status. Document preparation reuses trip-linked uploads without imposing document categories; “Not Required” is explicitly recorded.
- Added optional delivered-unit, receipt, shared-waybill, and co-load references plus required delivery and return notes. Waybill/co-load references are indexed but not globally unique.
- Preserved the existing approval-time truck/crew reservation and status transition safety. New workflow gates enforce the Dispatcher confirmation, checklist/inspection, Operations Head clearance, progress, details, and arrival sequence; legacy trips retain existing protections and are not assigned fabricated workflow history.
- Allowed a new maintenance checklist attempt after a failed checklist, while keeping a passed checklist final, so corrective work can be rechecked.
- Added a per-trip workflow/timeline page and wired trip-monitor links, document upload selection, and maintenance checklist/inspection entry points.

### Phase 0
- Added a repository audit and gap analysis for the supplied FleetOps requirements prompt.
- Added a section-level requirements traceability matrix with current status and planned phases.
- No application code or database schema was changed.

### Phase 1
- Added a database-backed permission and approval foundation with an administration page, preserving legacy role-based routes.
- Added shared notifications, expiry reminders, configurable reminder thresholds, attachment/version metadata, safer upload/storage helpers, reporting/export foundations, and idempotency support for dispatch and document mutations.
- Added user-scoped UI preferences, URL-backed filter/tab state, secure same-origin login return paths, configurable company email domain, and deployment guidance for the additive migration and CLI reminders.
- Added `db/phase1_foundations_migration.sql`; it passed a smoke test against a disposable MariaDB database. No migration was applied to the configured FleetOps database.
- PHP and JavaScript syntax checks and `git diff --check` passed. Feature-level integration against the application database remains pending deployment of the migration.

### Phase 2
- Added truck unit/type, MV file, registration-expiry, and insurance fields; added employee/contractor and resignation details with automatic deactivation; and made the crew-section label configurable.
- Added client type and parent-client fields, client-specific locations, and effective-dated rate cards with server-side ownership and overlap validation.
- Added `db/phase2_master_data_migration.sql` and documented the full migration sequence. The clean-bootstrap check also corrected target-database selection and made the announcement, trip-cost, and inspection migrations compatible with schema fields already present in the base schema.
- Validated the migration sequence against a fresh disposable MariaDB database and verified truck creation/editing, location creation, rate creation/overlap rejection, and contractor resignation in the isolated local demo. PHP lint, JavaScript syntax checks, and `git diff --check` passed; no migration was applied to the configured FleetOps database.
- Department-specific role/account splitting remains deferred as requested; role ID 1 retains all permissions for current admin/testing access.

### Phase 3 (in progress)
- Added an additive trip-operations migration for client and billing-client links, pickup/delivery locations, effective-rate snapshots, second drivers, booking/waybill references, unit counts, expected arrival, and actual departure.
- Dispatch creation and approval now reserve truck and crew resources under row locks and reject same-day conflicts; dispatch requests retain their master-data and reference details.
- Trip progress now follows Loading → In Transit → Unloading → Completed (or cancellation), requires a passed pre-departure clearance before leaving, captures location/update history, and releases the truck on completion or cancellation.
- Added a permission-gated trip operations CSV export with safe date, client, and status filters.
- Admin/test users with `trips.create` and `trips.update` can use the dispatcher/trip workflow without changing the deferred role split.
- Added a Phase 3 role migration for Admin and Operations Head while retaining Dispatcher as the request-entry role; Admin keeps all permissions and may test approvals, and only one active Operations Head account is allowed.
- Routed Operations Head and Dispatcher dispatch, route-review, trip-monitoring, incident, and report access through granular permissions; documented the Ops Head review flow and migration order.
- Added the required idempotency header to the Requests-page dispatch approval call after the isolated role-flow test exposed a rejected review request.
- Validated the documented migration sequence in a fresh disposable database and tested Dispatcher request submission, Operations Head approval/trip creation, duplicate active Operations Head rejection, and Operations Head denial from Admin User Management in the isolated demo. No migration was applied to the configured FleetOps database.
- Added Operations Head Dispatch Planning and a notified Dispatcher encoding queue, with explicit Day/Night shifts retained on dispatches and trips and available in trip filtering/export. The documented migration sequence passed on a fresh disposable MariaDB database; the isolated demo confirmed Day instruction creation, Dispatcher encoding, Operations Head approval, and shift propagation to the trip. A Night instruction was also confirmed open for encoding. Admin permissions were verified and the handler's permission gate preserves Admin testing access; the configured FleetOps database was not modified.
- Added batch planning for Operations Head: submit 2–25 independently detailed trip instructions together, validate and persist the entire batch atomically, notify Dispatchers once, and show the shared batch reference in planning and queue views. Clean disposable migrations and isolated-demo Day/Night batch submission passed; invalid batch items were rejected without partial writes. No configured FleetOps database was modified.
- Added trip-linked departure and return vehicle inspections. Departure requires all 24 standard inspection parts to be recorded Good and retains the existing passed pre-trip checklist gate; trip completion requires a return inspection. The Maintenance page highlights non-Good return findings against the departure baseline. The full migration sequence and disposable-demo rejection/pass/completion paths were validated. No configured FleetOps database was modified.
- Added a server-enforced minimum completion timer for pre-trip checklists and General, Departure, and Return vehicle inspections. The five-minute duration is controlled by `MAINTENANCE_FORM_MIN_DURATION_SECONDS` in `config/app.php`; one-use, user/target-bound timer tokens prevent client-side bypass and replay. Apply `phase4_maintenance_form_timers_migration.sql` after the trip-inspection migration.
- Added repair work orders with Open/In Progress/Completed/Cancelled tracking, priority, expected completion, closure notes, and elapsed downtime. Creating a work order reserves an available or already-maintained truck; deployed trucks and trucks with active trips are rejected. Completion restores availability only when no active trip or other repair order blocks it, while cancellation deliberately leaves the truck Under Maintenance for a safety review. Apply `phase4_repair_work_orders_migration.sql` after the timer migration.
- Added per-truck, calendar-based preventive maintenance schedules with configurable day intervals, due-soon/overdue visibility, pause/resume/archive controls, and service completion that creates a maintenance history record and advances the next due date from the service date. Service completion is blocked for unavailable trucks, active trips, active repair orders, or repeat completion on the same day. Apply `phase4_preventive_maintenance_schedules_migration.sql` after the repair work-order migration.
- Added truck availability history with explicit rollout snapshots and reasoned, attributed status transitions for truck registration, dispatch approval, trip completion/cancellation, maintenance records, repair work orders, Fleet Status updates, and truck edits. Fleet Status provides a per-truck history view; manual changes require a reason. The status-history view and actor/reason display were verified in the isolated demo, and the corrected full migration/attribution checks passed in a disposable schema. Apply `phase4_truck_status_history_migration.sql` after the Phase 4 maintenance migrations.
- Added Phase 5 RBAC roles: Management / Head, Admin Officer, Operations Head,
  vehicle-category and Day/Night dispatcher roles, Purchasing Officer, Finance,
  Billing and Collection, and Payroll. Admin remains the full-access
  superuser; existing user-role assignments are preserved for Admin review.
  Dispatcher queue, truck selection, and server-side trip submission enforce
  the assigned shift and vehicle category.
- Added Phase 5 purchasing and inventory workflow: suppliers, multi-line
  purchase orders, shared approval linkage, approved-order receiving, and
  automatic Stock In movements with quantity and unit-cost updates. New
  workflow tables avoid conflict with legacy `purchase_orders`.
- Added Phase 6 fund requests, AP records, payment vouchers, approval linkage,
  controlled disbursements, and Finance summaries. Added billing receivables
  aging and overdue balances grouped by client.
- Added Phase 7 Admin Officer attendance, account-linked clock-in/out,
  overtime controls, date-range attendance summaries, and spreadsheet-safe
  CSV export. Self-service users can only see their own attendance.
- Converted system filter dropdowns to compact searchable inputs backed by
  native datalist options while preserving existing select behavior and
  leaving workflow selectors unchanged.
- Added payroll base pay, allowances, manually itemized deductions, calculated
  net pay, and printable payslips. SSS, PhilHealth, Pag-IBIG, withholding tax,
  cash advances, loan repayments, and custom names can be entered; statutory
  rates are not calculated. Historical deductions remain clearly marked as
  unitemized. Payroll deduction details survive archival/restoration and are
  removed on permanent purge.
- Added the Phase 8 Admin Officer recruitment pipeline and separate office
  supplies inventory with reorder thresholds, auditable stock-in/issue/
  adjustment movements, and server-side nonnegative-stock enforcement.
  Admin retains access; Admin Officer access is granted by the additive
  migration without changing existing user assignments.
- Added date-filtered Operations Performance reports for dispatcher/truck
  trip counts, completions, lateness, and completed trip details; added
  Maintenance downtime and service-cost summaries. Both report pages are
  permission-gated for their intended department and management roles.
- Added a truck warranty-expiry field to truck registration/editing and the
  Fleet Status view, with expired warranties highlighted. Apply the separate
  Phase 9 additive migration after Phase 8.
- Fixed Finance handler routing so payable/voucher actions execute outside the
  disbursement branch. Voucher creation is now bounded by the remaining AP
  balance; partial fund/voucher disbursements retain their open status until
  the approved amount is fully disbursed, and payable state reflects partial
  versus complete payment.
- Granted the configured Management / Head approver the existing
  `approvals.review` permission required to review Finance requests; no
  broader approval rights are added to other roles.
- Linked active employee accounts without HR-wide permission can reach their
  own Attendance clock through the sidebar, matching the existing
  server-enforced self-service access boundary.
- Added Phase 8/9 migrations to deployment instructions. In the isolated demo,
  migration/schema and role-grant checks passed; API tests covered AP/voucher
  and fund partial/full disbursement limits, recruitment creation/status and
  invalid-email rejection, and office-stock movements including negative-stock
  rejection. Operations, Maintenance, recruitment, and supplies pages rendered
  successfully. Test records and temporary test files were removed.
- Full PHP syntax checks for `ajax/`, `pages/`, and `includes/`, JavaScript
  syntax checks, and `git diff --check` passed. No writes were made to the
  configured `mulawin_fleetops` database.
  migration passed in a disposable MariaDB schema and the isolated demo was
  verified without modifying the configured FleetOps database.
