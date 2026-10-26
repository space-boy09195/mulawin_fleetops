# MariaDB integration test checklist

**Status: NOT TESTED.** No item below has been run. Run only against the
separate test database `mulawin_fleetops_e2e`, through a separate copy of the
application with its own `.env` and a restricted database account, after
`docs/testing/database_setup_checklist.md` steps 1–7 were approved and
completed (none of this is set up yet). Never run these items against the
working database `mulawin_fleetops_test`. Record each result as PASS / FAIL
with the date, the account used and the evidence (row counts, audit row
ids, screenshots). Stubs, SQLite or static checks never count as a PASS here.

Two browser sessions (or two terminals with `curl` and two session cookies)
are needed for the concurrency items.

## A. Schema and seed

- [ ] Reference import + all 21 drafts applied without error, in order.
- [ ] `roles.system_key` present for every seeded role; no legacy role auto-mapped.
- [ ] Operations Head holds `reports.view`; Finance does not hold `approvals.review`.
- [ ] `approval_workflows` has the 6 workflows with a step 1 role each.
- [ ] `inspection_templates` has 'Pre-trip checklist' (min 300 s) and the standard templates.

## B. Identity, sessions, audit, RBAC

- [ ] Login / logout per role; failed logins recorded in `login_attempts`; lockout after the configured limit.
- [ ] Session version bump (role change) forces re-login.
- [ ] Each role lands on its dashboard (admin → dashboard_head, operations_head → dashboard_operations_head, dispatchers → dashboard_dispatcher, maintenance → dashboard_maintenance, finance / billing_collection → dashboard_accounting, others → dashboard).
- [ ] Direct URL to a page / ajax endpoint without its permission returns 403 (HTML or JSON) and writes no data.
- [ ] Every create/update/delete writes one `audit_logs` row with the actor.
- [ ] Admin override: action without reason → 422 and `ADMIN_OVERRIDE_REFUSED` audit; with reason → success and override audit rows.

## C. Dispatch and trips (B3)

- [ ] Submit dispatch → trip 'Pending Approval' + `dispatch_requests` Pending + approval request; trip number unique and sequential (`trip_number_counters`).
- [ ] Submit the same request twice with the same idempotency key → second reply 409, one trip only.
- [ ] Two reviewers approve the same request at the same time → exactly one succeeds, the other gets 409; one approval history row.
- [ ] Approve while the truck/driver is already assigned to an overlapping trip → refused (conflict), nothing changed.
- [ ] Approval sets trip Approved; assignment reserves truck → 'Assigned' + `truck_assignments`; crew availability 'On Trip'.
- [ ] Reject → trip Cancelled, dispatch request Rejected, truck/crew released, rejection reason stored.
- [ ] Status transitions follow `update_trip_status.php` rules (Dispatched→In Progress→Delivered→Completed; Cancelled needs a reason); invalid jump → 409.
- [ ] Dashboard quick update (dispatcher dashboard) works with the idempotency key; cancellation prompts for a reason.
- [ ] Driver completion report: one pending report per trip (T-T2 unique); second submit refused.
- [ ] Second driver: assignable, shown on trip pages, payable in Trip Pay.
- [ ] Incident log + resolution (`resolved_at`, `resolved_by`); open count drops on dashboards.
- [ ] Workflow events recorded for each step; truck status history visible.
- [ ] Truck status never becomes a value outside the ENUM ('Deployed' no longer written).

## D. Fleet, maintenance, parts, purchasing, documents (B4)

- [ ] Truck create/edit with CR / insurance / warranty documents and photos (files + documents + document_links).
- [ ] Maintenance record, work order, inspection, checklist (timer: submit before 300 s refused; after → saved; token reuse refused).
- [ ] Stock out to a maintenance job updates `inventory`, `maintenance_parts`, job `parts_cost` / `total_cost`; stock out above quantity refused; two concurrent stock-outs cannot drive quantity negative.
- [ ] Purchase request → approval decision (Requests page, generic section) → PO Issued → partial and full receipt → stock in.
- [ ] Upload validation: wrong type / oversize / double extension refused; download requires `documents.download` and the document's visibility.

## E. Billing, collections, Trip Pay, payroll, finance (B5)

- [ ] One billing per trip (second create refused); invoice number unique; status Issued; billing item line written.
- [ ] Payments: partial → Partially Paid; full → Paid; overpayment refused; two concurrent payments cannot exceed the balance.
- [ ] Trip Pay: concurrent saves for the same trip/employee/pay type produce **one** row (T-P1); delete → Cancelled; Driver Allowance stays an expense, separate from Trip Pay.
- [ ] Payroll period + record + deductions + disbursement; duplicate employee/period refused; payslip shows paid date and recorder.
- [ ] Fund request → approval (Admin) → disbursement capped at the approved amount → Processing / Disbursed.
- [ ] Payable → voucher (capped at open balance) → approval → disbursement → voucher Paid, payable Partially Paid / Paid.
- [ ] Requester cannot approve their own fund request / voucher (403; Admin needs an override reason).
- [ ] Billing and Collection user cannot open Finance pages or see cost figures on their dashboard; Finance sees them.

**Billing page period boundaries** (`pages/billing.php`; window = period start to the start of tomorrow, exclusive; static check: `tests/static/billing_period_test.php`). Use a Finance or Admin account (the cost and payroll tabs need `finance.view` / `payroll.view`). Before testing, record the app time zone (`APP_TIMEZONE`, default Asia/Manila) and the database server time zone, and use test rows only. For each item, compare the displayed figure with a hand-calculated total from the test rows.
- [ ] Period starts: Today = today; Last 7 Days = today − 6 days; Last 1 Month / 3 / 6 / 12 Months = today minus that many months (PHP `modify`). For each period, rows dated **the day before the start** are excluded; rows **on the start date** and **today** are included; rows dated **tomorrow** are excluded.
- [ ] Billings (`billing_date`, status not Draft / Cancelled): Billings tab rows and the Total Billed card follow the boundaries. The **Collected** and balance cards sum **all** collections of the billings in the window, whatever their payment date.
- [ ] Collections (`payment_date`): the Collections tab rows and count follow the boundaries.
- [ ] Payroll (paid date = latest `payroll_disbursements.payment_date` of the record, status Disbursed): Payroll rows and the Payroll total follow the boundaries. A record with no disbursement has no paid date: it appears under All Time only.
- [ ] Trip Pay (date = trip `completion_time`, else `scheduled_departure`, as a date; status not Cancelled): a completed trip is dated by completion even if scheduled on another day. An uncompleted trip is dated by its scheduled departure. Trip Pay total follows the boundaries.
- [ ] Trip expenses (`expense_date`, reference type trip, operating types): Fuel / Toll / Driver Allowance / Other cards follow the boundaries.
- [ ] Maintenance cost (date = `actual_completion_date`, else `start_date`, else `scheduled_date`, else the date of `created_at`; status not Cancelled): each fallback level checked once. A **future scheduled job** is excluded from every bounded period. `created_at` is set by the database server clock: confirm its date matches the app's "today".
- [ ] Parts purchased (`inventory_transactions.transaction_date`, DATETIME; Stock In with a unit cost): a row at **today 23:59:59** is included; a row at **tomorrow 00:00:00** is excluded.
- [ ] All Time: no window. Rows dated tomorrow or later (including the future scheduled job) **are** included.
- [ ] Last 1 Month at month-end: on 31 March the start is **3 March** (PHP `-1 months` overflow; observed with PHP for 2026-03-31; on 30 March it is 2 March). Record the actual start shown. This item can only run on such a date; it documents current behaviour and is not a decision that it is correct.

## F. Analytics and dashboards (B7)

- [ ] `tests/sql/analytics_known_dataset.sql`: every check row PASS; after ROLLBACK no ZZTEST row remains.
- [ ] Analytics page per role (admin, operations_head, finance, maintenance): Operations Head sees no revenue / cost / margin; Admin sees all; figures match the known dataset when the window covers it.
- [ ] Empty database / empty window: no PHP error, rates show "—", charts show their empty states.
- [ ] Period and granularity filters: invalid values fall back to defaults; Operations Performance rejects ranges over one year and invalid dates (400).
- [ ] Budget vs actual: no budgets → empty message; budgets set → variance matches hand calculation.
- [ ] Dispatcher dashboard counts only the dispatcher's truck types / shift.

## G. Recycle bin, automation, notifications

- [ ] Soft delete archives the row and its children; restore validates columns; purge removes the archive only.
- [ ] Automation and reminders stay disabled; running them manually (if approved) sends nothing externally.
- [ ] Notifications link to the right entity and are only visible to their recipient.

## Objective-alignment corrections (2026-10-21) — NOT TESTED

Run each item on the isolated test database and record PASS / FAIL with
evidence. Expected results come from the code as changed; they are not
observed results.

- [ ] 1.5 / 1.9 / 4.2 Car Carrier dispatcher (Day assignment): Trip Monitor, Dispatch request list and Trip Problem Reports show no Container / Wing Van or Night-shift trips; opening `trip_workflow.php`, `trip_report.php` or `trip_pre_advice.php` with such a `trip_id` returns 403; Admin and Operations Head still see every trip.
- [ ] 1.10 Starting an inspection timer and submitting before 900 s is refused by the server (also with a crafted request); at or after 900 s it saves. Departure / return inspection photos (JPG/PNG/WebP, up to 8) create `files`, `documents` (scope `maintenance`) and `inspection_photos` rows linked to the new `inspection_id`; a failed save leaves no stored file.
- [ ] 1.2 Approving a dispatch sets the truck to Assigned / availability Unavailable; departure sets On Trip / Unavailable; completion or cancellation sets Available / Available.
- [ ] 1.3 / 2.7 With a truck document expiring in 28 days and thresholds `60,30,15,7`, `php scripts/generate_reminders.php` sends one 30-day reminder per recipient; a second run sends nothing; an `automation_runs` row with status `success` is written.
- [ ] 2.1 Work order: In Progress → Waiting for Parts → In Progress → Completed with labour and other cost and a finding; `total_cost` = labour + parts + other; a `maintenance_findings` row exists.
- [ ] 2.5 Maintenance user issues a part to a job (Stock Out with linked job): stock decreases, `maintenance_parts` row added, job `parts_cost` increases. The same user is refused (403) for Stock In, Adjustment, an unlinked movement and `add_part`. A linked Return above the issued quantity is refused; a valid Return lowers the job's parts cost.
- [ ] 2.4 Purchasing edits a part (reorder level, warranty) and deactivates it: history is kept and the part disappears from new movements and purchase orders. Editing and deactivating a supplier works; an inactive supplier is not offered on new purchase orders.
- [ ] 1.7 A Container instruction with a client booking reference stores it as `client_bookings.booking_reference`; a duplicate reference is refused; non-container instructions refuse it.
- [ ] 3.2 Creating a billing with a client other than the trip's client returns 422.
- [ ] 3.3 Billing and Collection can list and download a delivery receipt uploaded at trip completion (scope `operations`); a dispatcher cannot.
- [ ] 3.4 After the approved permission/workflow update: a Finance user sees a dispatcher's Trip Allowance request on Requests and approves it (audit row `APPROVAL_DECISION`); Finance does not see the dispatch or route request sections; a Payroll user gets 403 on `approval_decision_handler.php`.
- [ ] 3.5 A second Driver Allowance expense for the same trip is refused (409); editing the existing one works.
- [ ] 3.7 Trip Pay for a Cancelled trip is refused; resubmitting an unchanged amount changes nothing; a changed amount for an existing entry is refused (409) until an Admin cancels the entry; re-logging a cancelled entry is audited with its old values.
- [ ] 5.2 / 5.4 Analytics: an In Progress trip scheduled before the selected period appears in "Trips by current status"; maintenance cost charts include only Completed records; collections on a Cancelled billing are excluded.
