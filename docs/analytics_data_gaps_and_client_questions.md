# Analytics data gaps and client questions

Revalidated on 2026-10-15 against the filled-in questionnaire (Q), the Car
Carrier (CC), Container (CT) and Drivers Payroll (PR) workbooks; see
[`analytics_metric_traceability.md`](analytics_metric_traceability.md) and
[`testing/analytics_validation_report.md`](testing/analytics_validation_report.md).
The 2026-10-14 questions Q1–Q11 are answered or replaced below.

**Status (2026-10-15):** the client-facing version of CQ-1–CQ-19, with
examples and the request for missing records, is
[`client_analytics_clarification_request.md`](client_analytics_clarification_request.md).
**It has not been sent, and no answers or records have been received.**
Blocking questions: CQ-1, CQ-4, CQ-5, CQ-7, CQ-12, CQ-14. The owner decisions
are detailed at the end of this file. The owner's proposals for them were
recorded on 2026-10-15 (current: revision 2) as **OWNER PROPOSALS — NOT YET
IMPLEMENTED**: none is
approved for implementation, and no permission has changed. **OD-2** is
**OWNER APPROVED IN PRINCIPLE — DOCUMENTATION ONLY; NOT APPROVED FOR
IMPLEMENTATION** (2026-10-15). OD-1 is open; its topic 1-a is **partly
decided for documentation purposes only** (responsible role, dedicated
screen, audit trail), with its remaining questions open. OD-3 and OD-4 remain
open.

## Sources still unavailable

The following were not found anywhere searched (`C:\Users\acer`,
`C:\xampp\htdocs`), so **their contents could not be verified**:

- Client maintenance records / motorpool logbook (needed to check maintenance cost per truck)
- Billing and collection records, external accounting records (revenue, outstanding, profit)
- Expense reports, fuel SOAs (Shell / Petron), RFID statements
- Parts inventory list, trip tickets, driver trip summaries
- Section I of the questionnaire (positions and access). The filled-in copy
  stops at Section H; only the blank older form has Section I.

## What the sources already answered (previous questions)

| Old # | Answer found | Source |
|---|---|---|
| Q1 trip date | Container summaries count by **ACTUAL DEPARTURE DATE**; car carrier uses an unlabelled DATE; weekly trips = "waybills submitted per week" | CT pivots, CC, Q14 — still open, see CQ-1 |
| Q2 second driver | Car-carrier trips almost always have **two drivers** (93–100 % of rows) | CC — attribution rule open, CQ-4 |
| Q3 "completed" | "Delivered the total units required by the client" | Q5 |
| Q4 delayed / grace | Delayed = "due to pending documents or operational issues"; scheduled vs actual **not compared**; grace period "walang tiyak" *(radio)* | Q5, Q6 — CQ-7 |
| Q6 waybill | Rule "if not submitted by the cut-off date"; cut-off value not given | Q26 #1 — CQ-12 |
| Q7 expiry thresholds | **365 days** for OR/CR, insurance, permit / franchise, gate / entry passes | Q21 — CQ-14 |
| Q9 finance | Revenue = billed amount; profit = billed − all expenses (external accounting); outstanding after 15 / 30 days from waybill submission | Q15–Q17 |
| Q10 utilization | No formal computation; uses trips, deployed, idle and downtime **days** | Q9 — CQ-11 |
| Q11 thresholds | No alert thresholds given anywhere | — (still D) |

## Questions for the client

| # | Affects | Evidence and current app behaviour | Question |
|---|---|---|---|
| CQ-1 | Trip counts (all) | CT counts by ACTUAL DEPARTURE DATE; CC DATE column unlabelled; Q14: trips per week = waybills **submitted** per week. App counts by **scheduled** departure | Which date decides the day / week of a trip: actual departure, delivery, or waybill submission? Is it the same for car carrier and container? |
| CQ-2 | Total trips | Q says total trips come from "Scheduled trips". App "Trips scheduled" includes Pending Approval and Cancelled | Do cancelled and not-yet-approved trips count in total trips? |
| CQ-3 | Trips by client | CC / CT group by COMPANY NAME (e.g. RVL Movers, Fujitrans, CJ Smart) then CLIENT (e.g. MMPC, Coca-Cola). App has one client level | Which level is "client" in reports? Do you need both? (A schema change would need approval) |
| CQ-4 | Trips by driver | Car carrier: Driver 1 and Driver 2 on almost every trip. App's trip reports credit Driver 1 only; Trip Pay already lists the second driver as a Driver | Should a trip count once for **each** driver? Should both drivers receive trip pay? Are helpers counted for container trips? |
| CQ-5 | Completed trips | Completed = all required units delivered. App has no delivered-units field and treats Delivered / Returned / Completed as completed | Should the system record delivered units per trip (car carrier) and count a trip completed only when units are complete? |
| CQ-6 | Cancelled trips | Cancelled "by client or due to operational issues" | Report the two causes separately? |
| CQ-7 | **Delayed trips** | Client: delayed = reported delay with a reason (pending documents, operational issues); client-caused delays to be recorded separately. App: "Delayed" = past scheduled arrival (time rule) on 5 dashboards | Confirm that "delayed" should mean **a trip with a recorded delay and reason**, with client-caused delays shown separately. Should the time-based "past ETA" alert be kept under a different name? |
| CQ-8 | On-time / late | No on-time formula, no consistent time records, scheduled vs actual not compared | Keep the system's on-time rate (once times are recorded), or remove it from management views? |
| CQ-9 | Schedule changes | Wanted: keep original and updated schedules | Confirm both should appear on trip reports. (Needs a field or reported history) |
| CQ-10 | Motorpool / truck status | CT `TRUCK STATUS` codes `OK`, `DT`, `NT`, `-`; `DT` counted as down-truck days | What do `NT` and `-` mean? Is "nasa motorpool" the same as `DT`, or a separate state? |
| CQ-11 | Utilization | Client looks at trips, deployed days, idle days and down days; app shows % of trucks with a trip | Should utilization be replaced by days deployed / idle / down per truck per month (needs a daily truck status record)? |
| CQ-12 | Missing waybills, overdue billing | Rule "not submitted by the cut-off date"; billing due 15 / 30 days after waybill submission; payment terms per client. App: no cut-off, no submission date, due date typed by hand | What is the waybill cut-off (days after delivery)? Should the system record the waybill **submission date** and each client's payment terms (15 / 30 days)? |
| CQ-13 | Record completeness | "All records must be complete" by department / document type / date | Which records and fields count as "complete" for this report? |
| CQ-14 | Expiry alerts | Client: 365 days for every document type. App: amber ≤ 30 days on screen; reminders 60 / 30 / 15 / 7 days | Does "365 days" mean an alert one year before expiry (every document would always be "due"), or that renewal is yearly? What warning period should show on screen? |
| CQ-15 | Renewal status | CC registration list has FOR RENEWAL / UPDATED. All 39 "UPDATED" units still show their old (past) expiry date | When a registration is renewed, will the new expiry date be recorded? Do you need a renewal-status field in addition to the date? Are trailer chassis tracked as separate units? |
| CQ-16 | Expenses / profit | Expenses monitored include RFID; profit uses "all expenses" from external accounting | Is RFID a separate expense type? Should the system's margin be shown at all, given accounting keeps the official figure? Per client? |
| CQ-17 | Trip Pay | Q23: fixed rate per destination × trips. PR: regular wage = daily rate × days; trips and allowances typed, no trip-pay column | Where does per-destination trip pay appear in the payroll sheet (inside "Total Allowances"?), and is it paid on top of the daily rate? |
| CQ-18 | Client's own container count | TARA weekly totals omit MONDE, CAVITE and PARAÑAQUE trips (not in the `COUNTIF` list) | Are those trips meant to be excluded from the weekly total, or is the formula missing them? |
| CQ-19 | Second-driver data model (D3) | Code is written for one fixed second-driver column (T-T1, not executed). Client records show at most two drivers (car carrier) or one driver and one helper (container), which is an inference, not confirmed | Can a trip ever have more than one second driver or more than one helper? Can a third or relief driver be assigned? Can a car carrier have a helper in addition to two drivers? Can crew change partway through a trip? |

## Owner decisions (permissions — not changed)

Summary table moved to "Owner access decisions OD-1 to OD-4" at the end of
this file, with current behaviour, options and minimum permissions.

## Schema dependencies (review-only, nothing executed)

- **Delivered units per trip** (CQ-5); **waybill submission date** and
  **client payment terms** (CQ-12); **company / parent client** (CQ-3);
  **daily truck status** history (CQ-10, CQ-11); **document renewal status**
  (CQ-15). None of these is drafted. Each depends on a client answer first.
- **Second-driver data model (D3):** T-T1's fixed `trips.second_driver_id`
  or a crew-slot model. Undecided until CQ-19 is answered. No crew-slot draft
  exists.
- Existing gated drafts still needed by analytics: T-T1 (second driver),
  T-I1 (incident resolution), T-F1 (Toll / Driver Allowance), T-M1 / T-M2
  (maintenance due), T-B1 (budgets).

## Other data gaps

- No metric has been validated against data in a database (test database not
  approved).
- PHP computes expiry days in `APP_TIMEZONE` (default Asia/Manila); SQL screens
  use the database server's `CURDATE()`. Confirm both use the same zone.
- Expiry reminders fire only on the exact threshold day, and automation is off
  by default.

## Owner access decisions OD-1 to OD-4

Internal, for the project owner; not part of the client request. **Nothing
here has been implemented.** Permission keys and role grants are from the
reviewed RBAC seed `db/target_migrations/20261011_p21_rbac_seed_review_only.sql`
(not executed). Admin holds every permission. Every write path in the
handlers needs a `.manage` key (`billing.manage`, `finance.manage`,
`payroll.manage`, …), so the view permissions below are read-only on the
server.

### OD-1 — Trip totals visible to dispatchers

> **OWNER PROPOSAL — NOT YET IMPLEMENTED.** The current proposal is in "Owner proposals — revision 2" at the end of this file. The options here are the earlier analysis.

- **Evidence:** Q26 #1 (Total trips per week / client / truck / driver) — "Sino
  lang ang dapat makakita? Operations / Management / **Dispatcher**". Q1: trip
  reports reviewed by Operations Head / Management.
- **Current behaviour:** dispatchers (`car_carrier_dispatcher`,
  `container_wing_van_dispatcher`) hold `trips.view`. Their dashboard shows
  active / pending / delayed counts and trips by status, **limited to their
  truck types and shift** (`dispatcherScope()`). They do not hold
  `operations.reports.view` (Operations Performance) or `reports.view`
  (Analytics).
- **Options:**
  - **A.** Grant `operations.reports.view` to both dispatcher roles. Gives the
    full Operations Performance page: **all** truck types and shifts, plus
    the dispatcher table (colleagues' workloads). No code change.
  - **B.** Keep the dashboard counts only. No change; per-client / truck /
    driver totals stay unavailable to dispatchers.
  - **C.** A dispatcher-scoped Operations Performance (own truck types and
    shift). Needs a code change, so a later phase.
- **Business impact:** A exposes other fleets' and other dispatchers'
  workloads. B does not meet Q26 #1. C meets it without the wider view, but
  is new work.
- **Minimum permission:** A: `operations.reports.view`. C: same key plus
  scoping code.
- **Recommendation:** the client requirement supports giving dispatchers
  trip totals. **The scope (all fleets vs own fleet) is not documented**, so
  choose between A and C. Questionnaire Section I (requested) may settle it.

### OD-2 — Maintenance cost reports visible to the Operations Head

> **OWNER PROPOSAL — NOT YET IMPLEMENTED.** The current proposal is in "Owner proposals — revision 2" at the end of this file. The options here are the earlier analysis.

- **Evidence:** Q1: maintenance reports reviewed by "Operations Head /
  Purchasing Office / Management". Q26 #3 (maintenance expenses per truck):
  "Motorpool / Admin / Management".
- **Current behaviour:** `maintenance.reports.view` (Maintenance Reports:
  cost per truck, downtime) is held by Maintenance and Admin only. Operations
  Head already holds `maintenance.view`, and `pages/maintenance.php` already
  shows **the cost of every maintenance record** to that role. Only the
  per-truck totals page is missing. Purchasing Officer holds neither.
- **Options:**
  - **A.** Grant `maintenance.reports.view` to `operations_head`.
  - **B.** Grant it to `operations_head` and `purchasing_officer`.
  - **C.** No change.
- **Business impact:** A exposes no new kind of data to Operations Head (the
  record costs are already visible), only per-truck totals and downtime. B
  also exposes maintenance costs to Purchasing.
- **Minimum permission:** `maintenance.reports.view`.
- **Recommendation:** **A**. It follows from Q1 and the existing model:
  the same data is already visible to Operations Head record by record.
  Purchasing (B) is named in Q1 but not in Q26 #3, so decide that part
  separately.

### OD-3 — Report access for the Audit role

> **OWNER PROPOSAL — NOT YET IMPLEMENTED.** The current proposal is in "Owner proposals — revision 2" at the end of this file. The options here are the earlier analysis.

- **Evidence:** Q1: billing / collection reports reviewed by "Billing and
  Collection Officer / **Audit Personnel** / Management"; expense reports and
  payroll reports by "Finance Personnel / **Audit Personnel** / Management".
- **Current behaviour:** `audit` holds only `audit.view` (audit history,
  Recycle Bin). It cannot open Billing, Trip Costs, the accounting dashboard
  costs or Payroll.
- **Options:**
  - **A.** Grant read-only `billing.view`, `finance.view`, `payroll.view` to
    `audit`. Audit then sees billings and collections, costs and margin,
    individual payroll and Trip Pay amounts. No write access.
  - **B.** Grant only `billing.view` and `finance.view` (no individual pay).
  - **C.** No change.
- **Business impact:** A matches Q1 but exposes individual pay to the Audit
  role. B withholds pay records, against Q1.
- **Minimum permission:** `billing.view` (billing / collection),
  `finance.view` (expenses, costs, margin), `payroll.view` (payroll).
- **Recommendation:** Q1 documents Audit as a reviewer of all three, which
  supports **A**. Exposing individual pay is a sensitive grant, so the owner
  should confirm who holds the Audit role first. Section I (requested) may
  settle it.

### OD-4 — Finance access to the Payroll page

> **OWNER PROPOSAL — NOT YET IMPLEMENTED.** The current proposal is in "Owner proposals — revision 2" at the end of this file. The options here are the earlier analysis.

- **Evidence:** Q1: payroll reports reviewed by "**Finance Personnel** /
  Audit Personnel / Management".
- **Current behaviour:** `finance` lacks `payroll.view`, so it cannot open
  `pages/payroll.php`. Since the 2026-10-15 correction, Finance **can** see the
  Payroll & Trip Pay tab on the Billing page (`payroll.view` **or**
  `finance.view`). Recording salary payments still needs `payroll.manage`,
  also enforced by `ajax/payroll_handler.php`.
- **Options:**
  - **A.** Grant `payroll.view` to `finance` (read-only Payroll page; the
    Payroll page's management actions stay behind `payroll.manage`).
  - **B.** No change: Finance reviews payroll through the Billing-page tab
    only.
- **Business impact:** A gives Finance the same records with the Payroll
  page's periods and payslip views. B is consistent with Q1 but splits the
  payroll review across pages.
- **Minimum permission:** `payroll.view`.
- **Recommendation:** **A**. It follows from Q1 and the existing model
  (view key read-only; manage key unchanged).

### Review of the 2026-10-15 `pages/billing.php` correction (no change made)

| Viewer | Expenses & Margin tab | Payroll & Trip Pay tab | Log Salary Payment |
|---|---|---|---|
| Admin | yes | yes | yes |
| Finance (`finance.view`) | yes | yes (Q1) | no (`payroll.manage` needed) |
| Billing & Collection (`billing.view` only) | no | no | no |
| Payroll role | cannot open the page (no `billing.view`) | — | — |

The queries behind each tab run only when the tab is permitted. Delete
buttons stay Admin-only. No defect was found, so the file was left
unchanged.

## Owner proposals — revision 1 (2026-10-15) — SUPERSEDED by revision 2 below

*Kept for history. Revision 2 replaces it: the owner narrowed OD-1 to
assigned scope, approved OD-2 in principle, defined the Audit role for OD-3,
and kept Finance to the Billing-page tab for OD-4.*

The project owner proposed the direction below for each decision. **These are
proposals for review, not approvals.** They are not client-approved, and no
permission, role seed, page or handler was changed to implement them. Current
access is exactly as described under each OD above.

### OD-1 — Dispatcher trip totals

- **Proposed behaviour:** dispatchers may see **operational trip counts and
  summaries** (trips per day / week / month, by status, client, truck,
  driver, waybill status) **without any financial data**.
- **Rationale:** Q26 #1 names Dispatcher among the people who should see
  total trips; dispatchers have no documented need for financial figures.
- **Affected pages / permissions (as already identified):** Operations
  Performance (`operations.reports.view`) has no revenue, cost or pay data:
  only trip counts, dispatcher workloads, truck, client and driver tables,
  and waybill status. The Analytics page (`reports.view`) hides financial
  figures from the dispatcher view (`$showFinancials` is false for
  dispatchers). The dispatcher dashboard (`trips.view`) already shows
  scoped counts.
- **Unresolved:**
  - Scope: all fleets and shifts (granting `operations.reports.view` gives the
    unscoped page, including other dispatchers' workloads) or own truck types
    and shift only (needs a code change).
  - Whether the Analytics page is also intended (`reports.view`).
  - Questionnaire Section I (requested from the client) may settle both.
- **Approval status:** PROPOSED — NOT YET IMPLEMENTED. Awaiting owner
  approval of the scope.

### OD-2 — Operations Head maintenance costs

- **Proposed behaviour:** maintenance-cost visibility for the Operations Head
  **requires an explicit owner decision**. Until then, current access stays
  unchanged.
- **Current access (unchanged):** Operations Head holds `maintenance.view`
  and already sees each maintenance record's cost on `pages/maintenance.php`.
  It does not hold `maintenance.reports.view` (Maintenance Reports: cost per
  truck and downtime), which Maintenance and Admin hold.
- **Rationale:** Q1 lists the Operations Head among the reviewers of
  maintenance reports; Q26 #3 lists Motorpool / Admin / Management for
  maintenance expenses per truck. The sources differ, so the owner decides.
- **Affected pages / permissions:** `pages/maintenance_reports.php`
  (`maintenance.reports.view`).
- **Unresolved:**
  - Whether the Operations Head should see per-truck totals.
  - Whether the already-visible per-record costs are intended for that role.
  - Whether Purchasing (named in Q1) is included.
- **Approval status:** PROPOSED — NOT YET IMPLEMENTED. Explicit owner
  decision required; no change to current access.

### OD-3 — Audit role reports

- **Proposed behaviour:** the Audit role gets **read-only** access to
  approved reports and relevant audit records. It gets **no** authority to
  create, edit, delete or approve transactions.
- **Rationale:** Q1 names Audit Personnel as a reviewer of billing /
  collection, expense and payroll reports. An audit function needs to see
  records, not change them.
- **Affected pages / permissions (as already identified):** candidate view
  keys `billing.view` (Billing page), `finance.view` (costs, margin, Trip
  Costs), `payroll.view` (Payroll page, individual pay). Server-side writes
  need `.manage` keys, and approvals need `approvals.review`; the proposal
  grants neither.
- **Current audit-record access (verified in code, unchanged):** `audit`
  holds only `audit.view`. That key shows audit history only on the Admin
  dashboard (`company.dashboard.view`, not held by Audit). Its nav item, the
  Recycle Bin, is **Admin-only** in both the page and the handler, so an Audit
  user gets a 403 there; it has no restore or purge rights. Every user sees
  only their own recent activity on `pages/dashboard.php`. **No read-only
  audit-log page exists for the Audit role today.**
- **Unresolved (owner confirmation flagged):**
  1. Which reports are "approved" for Audit, in particular whether individual
     payroll and Trip Pay amounts are included.
  2. Which audit records are "relevant": a read-only audit-log view would be
     new work.
  3. Who holds the Audit role.
  4. The Audit nav item pointing to an Admin-only page.
- **Approval status:** PROPOSED — NOT YET IMPLEMENTED. **Flagged for owner
  confirmation.**

### OD-4 — Finance payroll access

- **Proposed behaviour:** Finance may **view** payroll and Trip Pay where its
  duties require it. **Recording salary payments stays controlled separately
  by `payroll.manage`.**
- **Rationale:** Q1 names Finance Personnel as a reviewer of payroll reports.
- **Current access (unchanged):**
  - Since the 2026-10-15 `pages/billing.php` correction, Finance
    (`finance.view`) sees the Payroll & Trip Pay tab on the Billing page.
  - "Log Salary Payment" there needs `payroll.manage`, as does the `create`
    action in `ajax/payroll_handler.php`.
  - Finance cannot open `pages/payroll.php`, which needs `payroll.view`, a
    key Finance does not hold.
  - `pages/trip_pay.php` needs `payroll.manage`.
- **Affected pages / permissions:** `pages/payroll.php` (`payroll.view`);
  `pages/billing.php` payroll tab (already `payroll.view` or `finance.view`);
  `payroll.manage` unchanged.
- **Unresolved:**
  - Whether the Billing-page tab is enough or Finance also needs the Payroll
    page.
  - Whether the 2026-10-15 Billing-page behaviour is confirmed as matching
    the proposal (it is consistent with it, but has not been approved as
    such).
- **Approval status:** PROPOSED — NOT YET IMPLEMENTED. Current access
  unchanged pending owner approval.

## Owner proposals — revision 2 (2026-10-15) — OWNER PROPOSALS — NOT YET IMPLEMENTED

These record the project owner's proposals as given. **They are not approved
for implementation and have not been approved by the client.** No code,
permission, role seed, navigation, schema or configuration was changed.
Current access is unchanged.

Each decision separates four kinds of statement:

- **Verified behaviour:** what the current code does, checked by reading it.
- **Owner proposal:** the owner's stated direction.
- **Unresolved owner decisions:** what the owner still has to settle.
- **Related client questions:** unanswered questions from
  `client_analytics_clarification_request.md`.

Nothing here was tested against a database.

### OD-1 — Dispatcher operational visibility

**Owner proposal.** Limit dispatcher operational counts and summaries to their
**assigned truck types, shifts and operational workload**. **No
financial-data access.**

**Rationale.** Q26 #1 names Dispatcher among those who should see total
trips. Dispatchers have no documented need for financial figures.

**Verified behaviour (`includes/dispatcher_scope.php`, read 2026-10-15):**
- A scope mechanism already exists. `dispatcherScopeResult()` returns `none`
  (Admin, Operations Head, roles without dispatch permissions), `scoped`, or
  `denied`.
- **Truck types** come from the role key: `car_carrier_dispatcher` → Car
  Carrier; `container_wing_van_dispatcher` → Container, Wing Van.
- **Shift** comes from the single active `dispatcher_assignments` row of the
  employee linked through `users.employee_id` that covers today (and the
  scheduled date, when given).
- No linked employee, zero or more than one applicable assignment, or a type
  mismatch → `denied`, and a denied scope matches no rows (fail closed).
- Where the scope is applied (corrected 2026-10-15 to match
  `owner_access_decision_worksheet.md`, OD-1 1-c; code reading only, not
  runtime-verified):
  - **Dispatcher dashboard** (`pages/dashboard_dispatcher.php`): dispatcher
    figures filtered by truck type **and** shift.
  - **General dashboard** (`pages/dashboard.php`): dispatcher figures
    filtered by the dispatcher's own submitted trips and truck type, **not**
    shift. Whether every dispatcher figure on the page uses this filter is
    not verified.
  - **Dispatch page** (`pages/dispatch.php`): only the available-truck list
    is filtered (by truck type). The dispatch request list is **not**
    restricted to the dispatcher's assignment.
  - **Dispatch submission** (`ajax/submit_dispatch.php`): shift and truck type
    must match the assignment.
  - **Status-update and workflow actions** (`ajax/update_trip_status.php`,
    `ajax/trip_workflow_handler.php`): their own ownership check (only the
    submitting dispatcher; departure clearance excepted in the workflow
    handler) and assignment check.
- `pages/operations_performance.php` and `pages/analytics.php` have **no**
  dispatcher scoping. Neither currently admits dispatchers.
- **Workload data:** `trips.dispatcher_id` (employee) is set at submission
  from the submitting user. `dispatch_requests.submitted_by` records the
  submitter. "My requests" figures use the submitter.
- **No financial data** appears on Operations Performance or in the dispatcher
  view of Analytics (`$showFinancials` is false for dispatchers).

**Assignment-data reliability (gap documented, nothing invented or
implemented):**
1. **No screen in the application creates or edits `dispatcher_assignments`.**
   The table is only read. Assignments would have to be maintained directly in
   the database. Whether the target database holds correct rows is
   **unverified** (no database access).
2. The scope uses the assignment valid **today**. Reports for past periods
   would be filtered by today's shift, not by the shift in force when the
   trips ran. A dispatcher who changed shift would see a different history.
3. `trips.shift` is nullable in the schema. The application always sets it on
   submission, but trips created any other way (for example imported
   records) without a shift would be left out of the dispatcher dashboard
   (shift filter). If it has a truck, it may still appear on the submitting
   dispatcher's general dashboard, which does not filter by shift. Dispatcher
   actions on it are refused.
4. Truck type is taken from the role, so all dispatchers of one role share the
   same truck types. There is no per-person truck-type assignment.

**Unresolved owner decisions:**
- Who maintains dispatcher assignments, and where (no screen exists).
- Whether historical reports should use the assignment in force at the trip
  date or today's.
- Which views dispatchers get (scoped Operations Performance, scoped Analytics,
  or dashboard only).
- Whether "operational workload" means trips the dispatcher submitted
  (`dispatch_requests.submitted_by`) or trips where they are the trip's
  `dispatcher_id`.

**Related client questions:** none directly. Questionnaire Section I (positions
and access) is requested in Part C of the clarification request.

**Status:** OWNER PROPOSAL — NOT YET IMPLEMENTED.

### OD-2 — Operations Head maintenance-cost analytics

**Owner proposal.** Approve, **in principle**, visibility of **per-truck
maintenance-cost totals** for the Operations Head. This is an owner proposal
only; current access remains unchanged.

**Rationale.** Q1 lists the Operations Head among the reviewers of maintenance
reports.

**Verified behaviour:**
- **Operations Head already sees individual maintenance-record costs.** It
  holds `maintenance.view`, and `pages/maintenance.php` lists every record with
  a Cost column (`maintenance_records.total_cost`). This is an existing audit
  finding, re-checked in code on 2026-10-15.
- Per-truck totals are on `pages/maintenance_reports.php`
  (`maintenance.reports.view`, held by Maintenance and Admin). That page shows:
  - **cost per truck** (service count and `SUM(total_cost)` for non-cancelled
    records in a from / to range);
  - **repair work orders** (issue, priority, status, opened / closed,
    downtime, opened by);
  - downtime totals.
- Operations Head's Analytics view shows no maintenance cost.

**Unresolved owner decisions:**
- The exact report or page to use. Per the code inspection recorded on
  2026-10-15 (see `owner_access_decision_worksheet.md`, OD-2), the
  Maintenance page's Work Orders tab already shows work orders and per-order
  downtime to `maintenance.view` holders, which includes the Operations Head
  in the reviewed seed. Granting `maintenance.reports.view` would add
  date-range totals: cost per truck, work-order counts and total downtime,
  plus each order's closed time and the name of the person who opened it.
  This was checked by reading the code only; the role's actual visibility at
  runtime was not verified with a database or integration test.
- The cost fields to expose: total only, or labor / parts / other. The
  record form stores its single cost in `other_cost`, so labor is not
  separated.
- Whether Purchasing (named in Q1) is included.

**Related client questions:** none blocking; the maintenance-records sample
(Part C) is needed to validate the figure itself.

**Status:** **OWNER APPROVED IN PRINCIPLE — DOCUMENTATION ONLY; NOT APPROVED
FOR IMPLEMENTATION** (owner decision recorded 2026-10-15).

The approved intent is a **limited** per-truck report for the Operations
Head, containing:

- recorded maintenance cost per truck (costs actually recorded only);
- work-order counts;
- total downtime;
- work-order closed times.

It **excludes** opener names, keeps **parts purchase costs excluded** (to
avoid double counting), and shows **no separate labor figure** without
reliable source records.

This is **not** a permission approval or an implementation authorization;
current access is unchanged.

Still open (see `owner_access_decision_worksheet.md`, OD-2, 2-d–2-h):
- the exact report / page and fields in the current code;
- verification of actual runtime access (the role seed is not proof);
- how recorded maintenance cost is defined and displayed;
- labor (none recorded separately; none to be invented);
- Purchasing Office access.

The cost-field, labor and Purchasing bullets above remain unresolved except as
answered here.

### OD-3 — Internal Audit role

**Owner proposal.** The Audit role is for a **designated internal auditor or
otherwise authorized internal staff member**. It may have **read-only** access
to:
- approval decisions and workflow history;
- record changes and administrative actions;
- login and access-related events;
- individual payroll and Trip Pay amounts.

**No create, edit, delete, restore or approval authority** is proposed.

**Rationale.** Q1 names Audit Personnel as a reviewer of billing / collection,
expense and payroll reports. An internal audit function needs to see history,
not change it.

**Verified behaviour (read 2026-10-15):**
- **Audit menu / navigation:**
  - The `audit` role holds only `audit.view`.
  - In `includes/layout.php`, `audit.view` controls one nav item, **"Recycle
    Bin"** (`/pages/recycle_bin.php`).
  - That page and `ajax/recycle_bin_handler.php` allow **Admin only**
    (`isAdmin()`), so an Audit user sees the menu item but gets an
    access-denied (403) page. It has no restore or purge rights.
  - Audit history ("recent activity") appears only on the Admin dashboard
    (`pages/dashboard_head.php`, gated by `company.dashboard.view`, which Audit
    does not hold).
  - On `pages/dashboard.php` every user sees only their own last five
    actions.
  - **There is no read-only audit-history page for the Audit role.**
- **Existing audit-event sources:**

  | Source | Holds | Notes |
  |---|---|---|
  | `audit_logs` | user, action, entity, old / new values (JSON), IP, user agent, time | Every `ajax/` handler file except the three exports contains an audit write (checked per file, not per code path). Actions include CREATE / UPDATE / DELETE variants, `APPROVAL_DECISION`, `RESTORE`, `PURGE`, `RESET_PASSWORD`, `EDIT_USER`, `ADD_USER`, `UPDATE_ROLE_PERMISSIONS`, `UPDATE_APPROVAL_CHAINS`, `LOG_TRIP_PAY`, `DELETE_TRIP_PAY`, `RECORD_PAYMENT`, `DOWNLOAD` (documents), `LOGIN`, `LOGOUT`, `LOGIN_FAILED`, and Admin overrides (`auditAdminOverride`). Audit writes fail closed (the business transaction rolls back) |
  | `approval_history` | Submitted / Approved / Rejected / Cancelled / Overridden, by whom, when, remarks | Per approval request |
  | `workflow_events` | trip workflow steps, truck status changes | — |
  | `login_attempts` (draft 1B) | rate-limit records | **Purged after one day**; not an audit history |
  | `deleted_records` (draft T-S1, not executed) | archived JSON of soft-deleted rows (announcements, documents, payroll records and their children) | **Can contain payroll amounts** |

- **Not audited:**
  - **viewing** pages (no record of who viewed payroll, billing or Trip Pay
    screens);
  - the three exports (`ajax/billing_export.php`,
    `ajax/attendance_export.php`, `ajax/trip_operations_export.php`);
  - failed logins for **unknown** usernames: the row is written without the
    typed name, by design.
- Completeness against a real database is **unverified**.

**Unresolved owner decisions:**
1. The designated person and the authorization process have not been
   specified.
2. Confirm which audit-event sources (above) are in scope, and whether they
   are complete enough (no view or export logging).
3. Whether the read-only view must include **deleted-record details**
   (`deleted_records`, which can hold payroll data).
4. The Audit menu item points to an Admin-only page. Fixing or replacing it
   is a code change, not authorized.
5. How access to individual payroll and Trip Pay amounts is authorized, and
   whether the auditor's own viewing must itself be logged (it is not today).
6. A read-only audit view does not exist. Building one is new work, not
   authorized.

**Related client questions:** none. Section I (Part C) may name the Audit
holder.

**Status:** OWNER PROPOSAL — NOT YET IMPLEMENTED. Flagged for owner
confirmation of items 1–6.

### OD-4 — Finance payroll visibility

**Owner proposal.** Keep Finance's payroll visibility **limited to the existing
Billing-page tab** for now. **Do not grant the full Payroll page.**
Salary-payment recording stays separately controlled by `payroll.manage`.

**Rationale.** Q1 names Finance Personnel as a reviewer of payroll reports.
The Billing-page tab already provides a view without widening page access.

**Verified behaviour:**
- `pages/billing.php`, since the 2026-10-15 correction:
  - The Payroll & Trip Pay tab shows for `payroll.view` **or** `finance.view`.
  - Its "Log Salary Payment" button, form and employee list need
    `payroll.manage`.
  - Admin-only delete buttons are unchanged.
  - `ajax/payroll_handler.php` requires `payroll.manage` for create and
    delete.
- Finance holds `finance.view`, not `payroll.view`, so it **cannot** open
  `pages/payroll.php`. `pages/trip_pay.php` needs `payroll.manage`.
- **The tab shows less than the Payroll page:**
  - The tab lists, for the selected period, each payroll record's employee,
    position, pay period, **net amount**, paid date, notes and recorder, and
    each Trip Pay row's crew role, trip number, amount and paid date. It has a
    print report.
  - The Payroll page also shows **base pay, allowances, deductions and
    payslips** (`pages/payroll_slip.php`).

**Unresolved owner decisions:**
- Confirm the tab supplies everything Finance needs. It has no deduction
  breakdown and no payslips.
- Confirm the 2026-10-15 Billing-page behaviour as the accepted state. It
  matches this proposal but has not been formally approved.

**Related client questions:** CQ-17 (where trip pay appears in payroll) may
affect what Finance needs to see.

**Status:** OWNER PROPOSAL — NOT YET IMPLEMENTED. Current access unchanged.

### Unanswered client questions (unchanged)

CQ-1–CQ-19 remain **unanswered** (as of revision 2; owner answers were received on 2026-10-16, see the last section); the clarification request has not been
sent. None of the proposals above answers or changes a client question.

## Owner answers received — 2026-10-16 — RECORDED; NOT IMPLEMENTED

The owner supplied answers to CQ-1–CQ-19 and OD-1–OD-4 ("Proposed Client
Clarification and Requirements Decisions"), plus three follow-up answers in
the same session. They are recorded here as given. **No code, permission or
schema was changed because of them.** Each item needing a change becomes a
separate, approved implementation task.

### Part A — priority questions

| Item | Owner answer (summary) | Still open |
|---|---|---|
| CQ-1 | Operational trip counts use the **actual departure date** for Car Carrier and Container. "Waybills submitted per week" is reported separately (submission date), never mixed with departure date. | Meaning of the Car Carrier workbook's unlabeled `DATE` column; whether the official weekly summary uses departure, submission, or both (client). |
| CQ-4 | Trips-per-driver credits **one trip to each participating driver**. Driver and helper participation are reported separately; a helper is not counted as a driver. Trip activity is separate from Trip Pay. **Trip Pay is entered manually, for record purposes only** (rates differ per person), at the after-trip report stage. | — |
| CQ-5 | Record **required and delivered units** per trip, for **Car Carrier and Container** trips. Fully completed only when all required units are delivered; otherwise partial/incomplete until delivered or closed under an approved exception. Returned, damaged or refused units are recorded and marked by delivery condition. | Schema/model for units and delivery condition (proposal needed). |
| CQ-7 | **Delayed** = staff record a delay **and its reason**. Client-caused delays are separate from RP Mulawin document/operations delays. The current time-based dashboard condition is renamed **Past ETA** (alert only, not in the client delay count). | — |
| CQ-12 | Add a **waybill submission date**. Store payment terms per client; **all clients currently share the same terms, 15–30 days**; due date = submission date + terms. Due-date corrections only through an authorised, recorded process. Missing-waybill cut-off is configurable (not hard-coded). | Whether terms are a single value (15 or 30) or a range used per invoice. |
| CQ-14 | The workbook's "365 days" is a renewal cycle, not a warning lead time. Keep reminders at **60 / 30 / 15 / 7 days** with a **30-day visual warning**; track the renewal cycle separately. | — |

### Part B — other questions

| Item | Owner answer (summary) |
|---|---|
| CQ-2 | Separate labelled figures: Approved/scheduled, Completed, Pending approval, Cancelled. Pending and cancelled trips are excluded from the headline operational trip count; an "all requests" count must be labelled as such. |
| CQ-3 | Support two client levels (company/forwarder/broker; end client/brand/consignee) where recorded; reports state which level is shown. Do not claim both levels until the relationship is stored. |
| CQ-6 | Cancellation reason required (except authorised exceptions). Report: cancelled by client; cancelled due to RP Mulawin operational issues; other/unspecified. |
| CQ-8 | On-time delivery rate stays a future KPI: hidden from management summaries or labelled "Not yet validated" / "Insufficient time data" until scheduled and actual delivery times are reliably captured and the client approves the calculation. |
| CQ-9 | Keep the original schedule; record each revision with timestamp and reason. Reports can show original and current schedule. |
| CQ-10 | **Skipped for now (owner, 2026-10-16).** Use only the listed truck states: Available/operational, Deployed, Idle (available, not deployed), Down for maintenance/repair, In motorpool (only if the client defines it as distinct). The meaning of `NT`, `–` and motorpool vs `DT` stays open. |
| CQ-11 | Utilisation as monthly truck-days: deployed, idle/available, down, motorpool (if distinct). Every report states period and denominator. Needs daily truck-status records. |
| CQ-13 | Per-module record-completeness checklist (trip, delivered trip, Car Carrier units, truck, maintenance, billing, payment) as a starting point. It flags missing information; it does not block saving. Not a client-approved mandatory-field list. |
| CQ-15 | On renewal, record the new expiry and keep the prior date in history. Separate renewal status (For Renewal / In Process / Renewed-Updated). Trailer chassis tracked separately if each has its own registration/expiry. |
| CQ-16 | RFID is a separate expense category. FleetOps may show an **indicative recorded margin** (billed revenue − expenses entered in FleetOps), never labelled official net profit. Margin by client only where revenue and expenses link reliably to the client. |
| CQ-17 | Trip Pay stays separately identifiable from daily wages and allowances. Its payroll treatment (inside Total Allowances or on top of wages) stays **unconfirmed**. |
| CQ-18 | Include Monde, Cavite and Parañaque trips in TARA's weekly total unless management confirms exclusion; compare the revised total with the trip rows and ask the client to confirm. |
| CQ-19 | **One main driver and one helper per trip (owner, 2026-10-16).** This settles the conflicting wording in the proposal ("multiple crew members"). If crew changes during a trip, keep who was assigned and when. Impact on D3 / T-T1 (`second_driver_id`): see below. |

### Part C — client records still needed

Unchanged: maintenance/motorpool sample; one month of billings and payments;
expense categories and the official profit method; a Shell/Petron fuel
statement; questionnaire Section I. Related analytics stay unvalidated.

### Part D — role access (documentation only; permissions unchanged)

| Item | Owner answer |
|---|---|
| OD-1 | Dispatchers get operational trip counts scoped to their truck types, shift and workload; **no financial data**. Assignments are maintained by the **Operations Head**. Historical scoping: **daily, weekly, monthly**. Report pages: **only those related to their role**. |
| OD-2 | Limited per-truck maintenance report for the Operations Head: recorded cost, work-order counts, downtime, closure times; no opener names; no double-counting of parts purchases. (Consistent with the earlier in-principle approval.) |
| OD-3 | Holder: **audit personnel**. Read-only; no transaction changes or approvals. Scope: **all reports and payroll data, if possible**. A dedicated audit-history page is **not needed**. |
| OD-4 | Finance payroll visibility stays limited to the **Billing-page tab**; payment recording stays separately protected (`payroll.manage`). Matches the current code. |

### Consequences to review (not acted on)

- **D3 / T-T1:** with one driver + one helper, `trips.second_driver_id`
  (applied in the test database only) is not needed for the stated rule.
  Whether to keep the column unused or drop the draft is a separate decision.
- **OD-1:** assignments still have no maintenance screen; in the test
  database they were inserted directly (see the progress log).
- **OD-3:** "all payroll data" for Audit widens access beyond the current
  RBAC seed; it needs an explicit permission change approval.
- CQ-1–CQ-19 client-side confirmations (Car Carrier `DATE` column, weekly
  summary basis, payment-term value, CQ-10 codes) remain open with the client.
