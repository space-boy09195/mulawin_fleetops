# Requirements Traceability

The Phase 0 audit baseline was completed on 2026-09-30; this matrix records Phase 1 and Phase 2 implementation status. Detailed findings and design notes are in [GAP_ANALYSIS.md](./GAP_ANALYSIS.md). “Partial” means related code exists but one or more material requirements are missing; it is not acceptance of the full requirement.

| Prompt requirement | Status | Existing implementation / evidence | Planned phase |
|---|---|---|---|
| 4.1 Fleet / Truck Management | Partial | Phase 2 adds internal unit/type, MV file, registration-expiry, and insurance particulars; Phase 9 adds warranty expiry tracking in Fleet Status. Phase 4 records truck status snapshots and attributed, reasoned status changes across dispatch, trip, maintenance, and manual updates, with a per-truck history view. Type-specific requirements remain | 4, 9 |
| 4.2 Driver and Helper Management | Partial | Phase 2 adds employee/contractor classification, contractor company, resignation details, and a configurable crew-section label; multi-slot crew and assignment availability remain | 3, 6 |
| 4.3 Client, Destination, and Rate Master Data | Partial | Phase 2 adds client type/parent relationships, client-specific locations, and effective-dated rate cards; dispatch integration and broader inactivity monitoring remain | 3 |
| 4.4 Trip Management and Dispatch | Partial | Phase 3 adds client/location/rate/reference capture, second-driver support, serialized same-day resource reservations, expected-arrival and actual-departure tracking, ordered progress transitions, Day/Night Shift, and bulk Operations Head instruction submission. Phase 10 adds the ordered 13-step workflow with assignment confirmation, document/allowance readiness, clearance, append-only progress, delivery/return details, arrival inspection, and completed-record submission. Custom client fields, schedule/assignment changes, batch execution, printable pre-advice, and interval reminders remain | 3, 4, 10 |
| 4.5 Operations Head and Dispatcher Monitoring | Partial | Operations Head creates and sends individual shift-specific instructions or a 2–25 item batch with per-trip details; Dispatchers see batch references, encode each item from their queue, and return requests for Operations Head approval. Filtered performance page summarizes dispatcher/truck trips, completion, lateness, and completed trip details. Target windows, clearance dashboards, and broader comparisons remain | 3, 8 |
| 4.6 Vehicle Inspection and Checklists | Partial | `maintenance_checklists`, `vehicle_inspections`, and `vehicle_inspection_findings`; Phase 4 links departure and return inspections to trips, requires all 24 standard parts Good before departure and a return inspection before completion, highlights newly non-Good return findings, and enforces a configurable minimum form duration on the server. Dynamic/admin-configurable templates, photos, and VIN-specific damage sheets remain | 4 |
| 4.7 Maintenance Management | Partial | `maintenance_records`, inspections, incidents, parts, repair work orders, and per-truck calendar-based preventive schedules with configurable intervals, due/overdue visibility, pausing/archiving, and service completion linked to maintenance history. Work-order downtime and filtered downtime/cost reports are available; truck warranty expiry is tracked. Part warranty tracking remains | 4, 8, 9 |
| 4.8 Purchasing and Inventory | Partial | `parts_inventory`, `parts_movements`, supplier records, purchase orders with line items and approvals, receiving with stock-in/cost updates; separate Admin Officer office-supplies stock and movement ledger added in Phase 8. Full stock valuation/reconciliation remains | 5, 8 |
| 4.9 Finance, AP, and Cash | Partial | Phase 6 adds fund requests, shared approval linkage, AP records, payment vouchers, disbursements, and finance summaries; full reconciliation and double-entry ledger remain deferred | 6 |
| 4.10 Billing, Collection, and AR | Partial | `billings`, `collections`, billing pages, collection summaries, receivables aging, and overdue-by-client view; normalized billing-party data and broader AR exports remain | 6 |
| 4.11 Payroll and Timekeeping | Partial | Phase 7 adds account-linked attendance clock-in/out, overtime controls/reporting, payroll base/allowance and itemized manual deductions with server-validated totals, validated net pay, and printable payslips; statutory formulas, biometric integration, automated trip-pay calculation, and employee payroll self-service remain | 6–7 |
| 4.12 Documents, Admin Records, and Messaging | Partial | `documents`, upload/download, expiry/visibility migrations; Phase 8 adds a permission-gated recruitment pipeline and office-supply records; general administrative document store, polymorphic versioning, and messaging remain | 7–8 |
| 4.13 Dashboards and Reports | Partial | Role dashboards, analytics, trip report, billing and fleet summaries, filtered Operations Head performance, and Maintenance downtime/cost reports | 3–8 |
| Section 8.1 live GPS/maps/API | Deferred | Explicitly out of scope; retain GPS report/document upload only | N/A |
| Section 8.2 full double-entry GL/financial statements | Deferred | Basic billing/expenses and summaries only; future ledger extension point | N/A |
| Section 8.3 statutory payroll/biometric integration | Deferred | Manual attendance/configurable pay or deductions only | N/A |
| Section 8.4 historical Excel migration | Deferred | No one-click workbook migration; CSV templates and optional validated trip import later | 8 |
| Section 8.5 real-time chat infrastructure | Deferred | Optional polling-based messaging may be considered last | 7, if time |
| Section 8.6 visual workflow designer | Deferred | Configurable request-type approver roles only | N/A |
| Section 8.7 automated client email/SMS/EDI | Deferred | Printable pre-advice; no automated client dispatch | N/A |
| Section 8.8 map-based distance calculation | Deferred | Route master/manual distance | N/A |
| Section 8.9 native mobile/offline app | Deferred | Responsive web only | N/A |
| Section 8.10 OCR/e-signature/barcode | Deferred | No implementation | N/A |
| Section 8.11 in-app automatic backups | Deferred | Document/script external database + uploads backup/restore | 8 |
| Section 8.12 enforced update interval/escalation | Deferred | Manual trip updates; optional simple reminder only | 3 |

## Cross-cutting requirements

| Requirement | Status | Current evidence / planned work |
|---|---|---|
| Data-driven granular RBAC (2.1) | Partial | Phase 1 adds permission tables and legacy-role compatibility; Phase 3 names role ID 1 Admin, adds Operations Head, and gates dispatch planning/encoding, approval, route, trip, and report surfaces by permission. Admin retains all permissions; exactly one active Operations Head account is enforced. Full page/handler conversion and accounting role split remain deferred. |
| Reusable approvals (2.2) | Partial | Phase 1 adds configurable role chains and shared approval history, wired to dispatch; other request types remain for later phases. |
| Notifications/reminders (2.3) | Partial | Phase 1 adds shared notification/reminder delivery, configurable expiry thresholds, and deduplication; broader event coverage remains. |
| Shared downloadable reporting (2.4) | Partial | Phase 1 adds validated date-range and CSV helpers; requested operational report pages/exports remain for later phases. |
| Polymorphic attachments/bulk upload (2.5) | Partial | Phase 1 adds trip-linked attachment/version metadata and shared upload/storage validation; broader entity coverage and bulk upload remain. |
| UX, pagination, PHP currency/timezone (2.6) | Partial | Phase 1 adds shared opt-in state persistence and user-scoped preferences; pagination, accessibility, and timezone validation remain. |
| State persistence, reliability, security, deployment (2.7) | Partial | Phase 1 adds migration/setup guidance, safe login return paths, and scoped idempotency; broader safeguards remain in the non-functional audit and Phase 8 plan in `GAP_ANALYSIS.md`. |

## Phase 2 validation

The Phase 2 master-data migration and the documented migration sequence were
applied to a fresh disposable MariaDB database. Browser checks in the isolated
local demo confirmed truck create/edit with the new identifiers and expiry
fields, client-location creation, effective-dated rate creation and overlap
rejection, and contractor resignation deactivation. The Phase 1/2 migrations
were not applied to the configured FleetOps database. Department-specific
permissions and new account roles remain intentionally deferred.

## Phase 3 validation

The Phase 3 migration has been applied to a fresh disposable MariaDB database
after the full documented schema/migration sequence. Browser/API checks in the
isolated local demo confirmed dispatch creation with client locations, booking
and waybill references, unit count, and an effective-rate snapshot; approval
created a trip and deployed the truck; departure was rejected without a passed
clearance; the ordered In Transit → Unloading → Completed lifecycle then
recorded departure/arrival and released the truck. Same-day truck reservation
and invalid status-transition attempts were rejected. The filtered CSV export
returned the expected trip data, and invalid date filters were rejected.
Phase 3 remains partial: full trip-step configuration, batches, schedule
changes, and operations comparison reports remain.

The Phase 3 role migration was applied after the trip-operations migration to
both a fresh disposable MariaDB database and the isolated local demo. Checks
confirmed role creation and grants, Admin retaining every seeded permission,
Operations Head receiving the dispatch approval step, account creation blocking
a second active Operations Head, and an Operations Head approving a Dispatcher-
submitted request into a trip. The Operations Head dashboard loaded, access to
Admin User Management was denied, and the configured FleetOps database was not
modified. Admin approval override is implemented for all-access testing.

## Phase 5 RBAC validation

Phase 5 adds separate permission bundles for Management / Head, Admin Officer,
Operations Head, Car Carrier Day/Night Dispatchers, Container and Wing Van
Day/Night Dispatchers, Purchasing Officer, Finance, Billing and Collection,
and Payroll. Admin retains all permissions. Existing account assignments are
preserved for Admin review and reassignment.

Dispatcher queue filtering, available-truck filtering, and server-side
submission validation enforce the assigned shift and canonical truck
category. The Fleet Status truck forms now use Car Carrier, Container, and
Wing Van categories. Module gates use permission keys rather than the former
broad legacy role checks, and Admin accounts cannot be assigned, edited, or
deactivated by non-Admin users. Payroll has a standalone permission-gated
page.

The complete migration sequence and RBAC grant assertions passed in a fresh
disposable MariaDB schema. The isolated demo migration was applied, the
Admin landing page, Fleet Status category controls, and Payroll page were
loaded successfully, and the temporary test account was removed. The
configured FleetOps database was not modified. Admin approval override is implemented for all-access testing.

The dispatch handoff and shift migration adds Operations Head planning and
Dispatcher encoding queues, preserving the assigned Day/Night Shift through
dispatch approval and trip creation. The complete documented migration
sequence, including the new handoff migration, passed in a fresh disposable
MariaDB database. In the isolated local demo, the Operations Head sent a Day
Shift instruction, the Dispatcher encoded it, and approval created a Day Shift
trip; a Night Shift instruction was also sent and appeared as open. Admin's
dispatch-create and handoff permissions were verified, and the dispatch handler
uses the permission gate so Admin can test the workflow. Temporary demo
credentials were restored after testing. The configured FleetOps database was
not modified.

The dispatch-batch migration adds an all-or-nothing Operations Head submission
for 2–25 independently specified trip instructions, with each row showing a
shared batch reference to Dispatchers. The full documented migration sequence,
including the batch migration, passed on a fresh disposable MariaDB database.
In the isolated demo, a Day and Night item were submitted together and both
appeared under the same batch in Dispatch Planning and the Dispatcher queue.
An invalid second item was rejected without creating a partial batch. The
configured FleetOps database was not modified.

The Phase 4 trip-inspection migration passed with the full documented schema
sequence in a fresh disposable MariaDB database. In the isolated demo, a
departure inspection with a damaged part was rejected; a full 24-part clear
inspection was accepted, while departure remained blocked until the separate
existing pre-trip checklist passed. After departure and unloading, completion
was rejected until a return inspection was recorded. The return inspection
captured a damaged rear door, the Maintenance page highlighted it as a new
finding against the departure baseline, and trip completion then succeeded.
Temporary Admin credentials were restored; the configured FleetOps database
was not modified.

The maintenance timer and repair-work-order migrations both passed in a fresh
disposable database after the documented Phase 4 inspection migration. A
disposable schema smoke test verified timer-session persistence/consumption and
the repair-order truck reservation, completion release, and maintenance-page
join. In the isolated UI demo, the timer was temporarily set to five seconds:
the checklist countdown disabled early submission, the server rejected an
immediate direct submission with HTTP 409, and a checklist was accepted after
the countdown. A repair order was created, moved through In Progress and
Completed, and returned its truck to Available. The demo-only timer value was
restored to 300 seconds; the temporary account and records were removed. The
calendar-based PM schedule was also exercised in the isolated UI: an overdue
schedule was created, service completion logged a Preventive maintenance
record and advanced its due date by the 30-day interval; pause, resume, and
archive controls were verified. Temporary PM test data was removed and the
demo truck returned to its original Available status. The configured FleetOps
database was not modified.

## Phase 6 and Phase 7 accounting and HR validation

Phase 6 adds fund requests and Finance disbursements through shared approval
infrastructure, AP records, payment vouchers, billing receivables aging, and
overdue-by-client summaries. Phase 7 adds employee attendance clocking with
overtime limits/reasons, attendance reporting and CSV export, and payroll
component breakdowns with calculated net pay and printable payslips.

The attendance and Finance migrations were applied to the isolated demo. Payroll
components were exercised with a temporary employee and user: a 20,000.00 base,
1,500.00 allowance, and 500.00 deduction produced a 21,000.00 net payslip. The
temporary payroll, employee, user, and audit row were removed afterward. The
configured FleetOps database was not modified.

## Phase 5 purchasing and inventory

Phase 5 adds supplier master records, purchase orders with line items and
totals, approval-workflow linkage, purchase-order statuses, and receiving that
posts Stock In movements while updating inventory quantities and unit costs.
Purchasing access is permission-gated and separate from the existing Parts
inventory ledger.

The truck availability-history migration was validated in a fresh disposable
schema, including rollout snapshots, transition ordering, actor attribution,
and reasons. In the isolated demo, the Fleet Status manual status-change path
and history view were exercised: the view displayed the rollout snapshot and
two transitions with their actor and reason. The test truck was restored to
its original Available status and temporary test data was removed. Existing
truck statuses are seeded as rollout snapshots explicitly noting that prior
change details are unavailable; subsequent transitions capture the
previous/new status, user, timestamp, and reason. The configured FleetOps
database was not modified.
