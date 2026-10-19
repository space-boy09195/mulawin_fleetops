# Owner access decision worksheet — OD-1 to OD-4

Mulawin FleetOps — Fleet Management System with Accounting and Inventory
Management for RP Mulawin Trucking Services. Prepared 2026-10-15 for the
project owner. **Internal document; not for the client.**

This worksheet records the original inspection and decision state. A later
user follow-up explicitly authorized targeted implementation of dispatcher
reports and Trip Allowance requests, a limited Operations Head maintenance
report, and read-only Audit access to Billing, Finance, Payroll, and audit
history. The resulting implementation status is recorded below. Unselected
decisions remain unresolved. No client approval exists; client questions
CQ-1–CQ-19 are unanswered and the clarification request has not been sent.

Sources:

- [`analytics_data_gaps_and_client_questions.md`](analytics_data_gaps_and_client_questions.md)
  — "Owner proposals — revision 2" and the earlier OD analysis.
- [`implementation_progress.md`](implementation_progress.md).
- One code re-check, made for this worksheet to settle a contradiction in
  OD-2 (see the note there).

The reviewed RBAC seed
`db/target_migrations/20261011_p21_rbac_seed_review_only.sql` is not proof of
the live grants. For the follow-up implementation, the configured
development/test database was inspected read-only for relevant role keys,
grants, schema columns, indexes, and the Trip Allowance approval chain. The
additive RBAC migration
`db/target_migrations/20261015_tA2_scoped_reports_and_allowance_permissions.sql`
was applied transactionally to the configured development/test database after
explicit approval. Post-migration, that database has 71 permissions and 175
role grants; Operations Head retains `maintenance.view` and has the new
limited-report grant. The Budgets table remains present with 0 rows. The
migration added only permission and role-grant rows; no schema DDL or
business-record data was changed. This does not establish production database
state.

How to use: tick or fill in the remaining **Owner answer** lines, then return
the worksheet. Implementations already authorized below do not depend on
answers to unrelated open questions.

---

## OD-1 — Dispatcher visibility

### Existing behaviour (verified in code)

- **Scope already exists** (`includes/dispatcher_scope.php`):
  - **Truck types** come from the role: `car_carrier_dispatcher` → Car
    Carrier; `container_wing_van_dispatcher` → Container and Wing Van.
  - **Shift** comes from the employee's single active `dispatcher_assignments`
    row (linked through `users.employee_id`) that covers today, and the
    scheduled date when one is given.
  - It **fails closed**: no linked employee, no applicable assignment, more
    than one, or a type mismatch → the dispatcher sees nothing.
- **Where scope applies:** dispatcher dashboard (truck type and shift),
  dispatcher figures on the general dashboard (the dispatcher's own submitted
  trips and truck type, **not** shift), the Dispatch page's available-truck
  list only (its
  request list is not filtered; see 1-c), dispatch submission, and trip
  status / workflow actions.
- **Where scope does not apply:** Operations Performance and Analytics. Neither
  admits dispatchers today: they need `operations.reports.view` /
  `reports.view`, which dispatchers do not hold.
- **Workload records:**
  - `dispatch_requests.submitted_by`: the user who submitted the request.
    "My requests" figures use this.
  - `trips.dispatcher_id`: the employee, set from the submitting user at
    submission.
- **No financial data** on Operations Performance or in the dispatcher view of
  Analytics.

### Documented limitations

1. **No screen creates or edits `dispatcher_assignments`.** Rows would have to
   be maintained in the database directly. Whether correct rows exist is
   unverified.
2. The scope uses **today's** assignment, so a past period is filtered by the
   current shift, not the shift in force when the trips ran.
3. `trips.shift` may be empty in the schema. The app always fills it, but
   records created another way (e.g. imported) without a shift are left out
   of the dispatcher dashboard (shift filter). One that has a truck may still
   appear on the submitting dispatcher's general dashboard (no shift filter).
   Dispatcher actions on it are refused.
4. Truck types are per role, not per person.

### Proposed behaviour (owner proposal, revision 2)

Dispatchers see operational counts and summaries **limited to their assigned
truck types, shifts and operational workload**, with **no financial data**.

### Decision section — remaining assignment and historical-scope decisions

Prepared 2026-10-15 from the code findings already recorded above and in
`analytics_data_gaps_and_client_questions.md` (OD-1, revision 2). No new
broad code scan was made. The follow-up authorization resolves the dispatcher
report and submit-only allowance scopes; assignment maintenance and the
historical assignment rule below remain unresolved.

#### 1-a. Assignment maintenance — PARTLY DECIDED (owner decisions 2026-10-15, documentation only)

A dispatcher's shift comes only from `dispatcher_assignments`. If a row is
missing, duplicated or wrong, that dispatcher sees nothing (fail closed) or
the wrong shift. No maintenance screen was identified, so someone must own
these rows and their corrections.

> **Owner decisions recorded 2026-10-15 — DOCUMENTATION ONLY; NOT APPROVED
> FOR IMPLEMENTATION.** They record intent only. No screen, permission, role
> seed, schema, migration or database record is created or changed, and no
> implementation is authorized.

| Question | Owner answer |
|---|---|
| Who is responsible for creating and ending assignments? ☐ Admin ☐ Operations Head ☐ HR ☐ Other: ____ | **Owner decision (2026-10-15):** Operations Head **or** Admin. Documentation only |
| How are they maintained? ☐ A new assignment screen (new work, needs separate authorization) ☐ Directly in the database by IT / Admin ☐ Other: ____ | **Owner decision (2026-10-15):** a dedicated, permission-protected system screen. Documentation only. The screen does not exist and is not authorized to be built; its permission key is not yet defined |
| How are corrections handled (wrong shift, overlapping or missing rows)? ☐ End the wrong row and add a corrected one, keeping history ☐ Edit the row in place ☐ Other: ____ | OWNER DECISION REQUIRED — NOT IMPLEMENTED |
| Must assignment changes be logged in the audit trail? ☐ Yes ☐ No | **Owner decision (2026-10-15):** yes, required; record who changed an assignment and when. Documentation only. Not implemented |

##### 1-a (corrections): options and implications — OWNER DECISION REQUIRED — NOT IMPLEMENTED

Basis and limits. This is drawn from:

- the reference schema (`db/reference/mulawin_fleetops_new.sql`; no migration
  draft changes this table);
- the recorded reading of `includes/dispatcher_scope.php`.

**Not verified:** any live or test database, existing assignment rows, and
runtime behaviour. No correction workflow exists today: there is no
assignment screen, and the decided screen is not built.

What the documented design implies:

- `dispatcher_assignments` holds employee, dispatcher type, shift, start date,
  optional end date, an active flag, and created / updated timestamps. It has
  **no changed-by, reason or "replaces row" column**, and **no constraint
  preventing overlapping rows**.
- A dispatcher's scope needs **exactly one** active row covering today (and
  the trip's scheduled date for submissions). Zero or two such rows deny
  access (fail closed).
- The audit trail decided above (who changed what, when) is **not
  implemented**. The existing `audit_logs` table can hold a user, timestamp
  and old / new values, but no assignment change writes to it today.

| | **A. End the incorrect row and add a replacement** | **B. Edit the existing row in place** |
|---|---|---|
| What remains in the table | Both rows: the old one ended (end date and / or inactive), the new one active | One row with the corrected values; the earlier values are gone from the table |
| Who / when / what changed | Partly visible from the rows themselves (created / updated times); **who** still needs the audit trail | Only the update time remains in the table; **who and the previous values** depend entirely on the audit trail |
| Historical reporting (1-b) | Supports an "assignment on the trip date" rule, because past rows survive | Makes that rule unreliable from the table alone; history could only be rebuilt from audit entries |
| Risk of locking a dispatcher out | If old and new rows both cover **today**, the scope sees two rows and denies access. For a submission, two rows covering both today and the trip's scheduled date also deny it. An overlap only on past dates has no effect; one only on a future date takes effect when that date becomes today. The end and start dates must not overlap | Lower: there is still one row. A wrong edit takes effect immediately |
| A row that was **never** valid (data-entry error) | The ended row stays and may look as if it was in force for its dates. The schema has no flag to mark it void | Removes the wrong values cleanly; nothing misleading remains in the table |
| A **real** change (dispatcher moves shift) | Preserves who was on which shift and when | Loses when the previous assignment applied |
| Fit with the decided audit trail | Complements it (history in table and log) | Depends on it completely |

A mixed rule (for example: replace for real changes, edit in place for
same-day data-entry errors) is also possible. It would need a definition of
which case is which.

Questions for the owner (answer lines):

| Question | Owner answer |
|---|---|
| Correction method: ☐ A. End and replace ☐ B. Edit in place ☐ Mixed rule: ____ | OWNER DECISION REQUIRED — NOT IMPLEMENTED |
| Should a correction record a reason? ☐ Yes ☐ No (a reason column does not exist; would need a schema change approved separately) | OWNER DECISION REQUIRED — NOT IMPLEMENTED |
| Must the screen prevent overlapping active rows for the same dispatcher? ☐ Yes ☐ No | OWNER DECISION REQUIRED — NOT IMPLEMENTED |

#### 1-b. Historical reporting — OWNER DECISION REQUIRED — NOT IMPLEMENTED

Today the scope uses the assignment valid **today** (and on the scheduled date
for submissions). Two possible rules for past periods:

- **Assignment in force on each trip's date.** A dispatcher's past reports
  stay stable after a shift change, and show what they actually handled.
  This needs assignment history to be kept (ended rows, not overwritten), and
  report queries would have to match trips to dated assignments (a code
  change).
- **Current assignment decides visibility (current behaviour).** This is
  simpler, but after a shift change a dispatcher's view of past periods
  switches to the new shift's trips. Their own earlier trips may disappear
  from their reports, and trips they never handled may appear.

| Question | Owner answer |
|---|---|
| Which rule applies to historical reports? ☐ Assignment in force on the trip date ☐ Current assignment ☐ Other: ____ | OWNER DECISION REQUIRED — NOT IMPLEMENTED |
| If the trip-date rule: how long must ended assignments be kept? ____ | OWNER DECISION REQUIRED — NOT IMPLEMENTED |

##### 1-b detail: consequences and dependencies — OWNER DECISION REQUIRED — NOT IMPLEMENTED

**Verified basis (code and schema reading only):**
- `includes/dispatcher_scope.php` selects the dispatcher's assignment with
  `is_active = 1` and `start_date` / `end_date` covering **today**, and also
  the trip's scheduled date when a submission is checked.
- Page filters (`dispatcherScope()`) use **today's** assignment only. They
  limit trips by `trips.shift` = that assignment's shift and by the truck's
  type (`trucks.truck_type`) in the role's truck types.
- Each trip records its own `shift` at submission and is dated by
  `scheduled_departure` in the reports.
- `dispatcher_assignments` has start / end dates and an active flag, but no
  field telling an assignment that **ended normally** from one that was
  **wrong from the start**.

**Not verified:** any live or test database, existing assignment rows,
runtime results.

| | **Assignment in force on the trip date** | **Current assignment (today's behaviour)** |
|---|---|---|
| What a dispatcher sees for a past period | Trips matching the shift (and truck types) of the assignment valid on each trip's date | Trips matching **today's** shift, for any past date |
| After a shift change | Earlier trips stay in the dispatcher's history | Earlier trips on the old shift drop out; past trips on the new shift (handled by others) appear |
| Dispatcher with no current assignment (ended or missing) | Could still see past periods they were assigned to, if the rule allows it | Sees nothing, past or present (fail closed) |
| Two or zero assignments on a past date (overlap or gap) | Those dates would need a rule (hide, or flag for correction); fail-closed handling would hide history | Not affected for past dates; only today's rows matter |
| Data needed | Accurate start / end dates on **retained** rows | Only the current row |
| Work needed | Code change: report queries must join trips to the assignment dated for each trip (not authorized) | None (existing behaviour) |
| Effect on comparisons | Stable over time; past totals do not change when assignments change | Past totals for a dispatcher can change whenever their assignment changes |

**Dependency on 1-a (correction handling).** The trip-date rule is only as
trustworthy as the assignment history:
- **End and replace (A):** history survives. A row that was wrong from the
  start stays in the table and, without a void flag, would be treated as in
  force for its dates, so past reports would follow the wrong shift.
- **Edit in place (B):** the earlier values disappear from the table. The
  trip-date rule would then report past periods as if the corrected values had
  always applied, and real shift changes edited this way would be lost. History
  could only be rebuilt from the audit trail, which is decided in principle
  but not built.
- **Mixed rule:** works only if real changes always use A and only genuine
  entry errors use B, which needs a clear definition.

Under the **current-assignment** rule, 1-a affects only today's view, not
history.

**Dependency on client question CQ-1 (unanswered).** "Trip date" is not
settled. Reports currently use the **scheduled departure**, while the client's
records point to actual departure or waybill submission. The trip-date rule
would have to use the same date basis as the reports.

#### 1-c. Page access — OWNER DECISION REQUIRED — NOT IMPLEMENTED

**Current behaviour.** The pages and handlers listed were re-inspected on
2026-10-15. Dispatcher grants come from the reviewed role seed (not
executed): both dispatcher roles hold `trips.view`, `trips.create`,
`trips.assign`, `trips.update`, `fleet.view`, `dispatch.instructions.encode`,
among others. **Repository inspection does not verify live or test database
behaviour**, nor which roles real accounts actually hold.

"Assignment filter" means the dispatcher scope from
`includes/dispatcher_scope.php` (role truck types + shift of today's
assignment). **Holding a permission does not by itself filter data;** each
page applies the scope or does not.

| Page / action | Permission check | Can a dispatcher open it? (seed) | Assignment filter on displayed data | Financial data | Unverified |
|---|---|---|---|---|---|
| Dispatcher dashboard (`pages/dashboard_dispatcher.php`) | `trips.view` | Yes | **Yes:** truck type and shift on trip counts and lists | None found | Runtime results |
| General dashboard, dispatcher figures (`pages/dashboard.php`) | login | Yes | **Partial:** own submitted trips and truck type; **not shift**. Whether shift filtering should be added is an open owner decision | None found | Runtime results; whether every dispatcher figure on the page uses this filter |
| Dispatch page (`pages/dispatch.php`) | `trips.view` | Yes | **Partial:** only the *available trucks* list is limited to the role's truck types (not shift). **The dispatch request list (up to 500) is not filtered:** all dispatchers, shifts and truck types | None found (keyword search) | Runtime results |
| Dispatch submission (`ajax/submit_dispatch.php`) | dispatch permissions | Yes | **Enforced:** shift and truck type must match the assignment | — | Runtime results |
| Trip Monitor (`pages/trip_monitor.php`) | `tripPageAccess(['trips.view'])` (Helpers limited to their own trips) | Yes | **No:** dispatchers see all operational trips. Shift buttons are on-screen filters, not access control | None found (keyword search) | Runtime results |
| Trip Workflow (`pages/trip_workflow.php`) | `tripPageAccess(['trips.view'])` | Yes | **No** for viewing any trip | None found (keyword search) | Runtime results |
| Trip status and workflow actions (`ajax/update_trip_status.php`, `ajax/trip_workflow_handler.php`) | `trips.update` / per-action permission | Yes | **Checked:** `dispatcherScopeViolation()` refuses actions outside the assignment | — | Runtime results |
| Fleet Status (`pages/fleet_status.php`) | `fleet.view` | Yes | **No** `dispatcherScope()` call: all trucks | None found (keyword search) | Runtime results |
| Dispatch Planning (`pages/dispatch_planning.php`) | `dispatch.instructions.manage` (Operations Head in the seed) | **No** | — | — | — |
| Operations Performance (`pages/operations_performance.php`) | `operations.reports.view` | **No** | No scope code | None | — |
| Analytics (`pages/analytics.php`) | `reports.view` | **No** | No scope code (a dispatcher view exists in the code, without financial figures) | Not in the dispatcher view | — |
| Trip operations export (`ajax/trip_operations_export.php`) | `trips.export` | **No** | No | — | — |

In short: today dispatchers **can view** trips outside their assignment on
the Dispatch request list, Trip Monitor and Trip Workflow, but **can act**
only inside it (submission, status and workflow actions are checked). The
assignment filter on displayed data exists only on the dashboards and the
Dispatch page truck list.

**Proposed future access rule (owner proposal, revision 2 — not decided in
detail, not approved for implementation):** dispatchers see operational counts
and summaries limited to their assigned truck types, shifts and workload, with
no financial data. **No page list has been decided.** Granting a page's
permission would not by itself apply the assignment filter. Each page would
need its own scope code (a code change, not authorized).

| Question | Owner answer |
|---|---|
| Which pages should dispatchers have for trip totals? ☐ Dispatcher dashboard only (current) ☐ Operations Performance, scoped (code change) ☐ Analytics dispatcher view, scoped (code change) ☐ Other: ____ | OWNER DECISION REQUIRED — NOT IMPLEMENTED |
| Should the pages dispatchers already open (Dispatch request list, Trip Monitor, Trip Workflow, Fleet Status) be limited to their assignment, or is full operational visibility intended there? ☐ Limit (code change) ☐ Keep full visibility ☐ Decide per page: ____ | OWNER DECISION REQUIRED — NOT IMPLEMENTED |
| Should dispatchers see other dispatchers' workloads (the dispatcher table on Operations Performance)? ☐ No ☐ Yes | OWNER DECISION REQUIRED — NOT IMPLEMENTED |

#### 1-d. Assigned workload — OWNER DECISION REQUIRED — NOT IMPLEMENTED

Three readings are possible from the existing data. None is chosen, and no
rule exists beyond what the code records.

| Option | Uses | Effect |
|---|---|---|
| Trips the dispatcher submitted | `dispatch_requests.submitted_by` | Counts own submissions only, whichever shift / truck type they belong to |
| Trips where the dispatcher is the trip's dispatcher | `trips.dispatcher_id` | Today this is the submitter; it would differ only if reassignment were ever allowed |
| All trips in the assigned truck types and shift | role truck types + assignment shift | Team view: includes trips other dispatchers on the same shift submitted |

| Question | Owner answer |
|---|---|
| Which definition is the dispatcher's workload? ☐ Submitted ☐ Trip dispatcher ☐ Truck types + shift ☐ Combination: ____ ☐ Other: ____ | OWNER DECISION REQUIRED — NOT IMPLEMENTED |
| Can a trip's dispatcher ever change after submission? ☐ No ☐ Yes, by: ____ (today no code changes it) | OWNER DECISION REQUIRED — NOT IMPLEMENTED |

##### 1-d detail: requests, trips, statuses and grouping — OWNER DECISION REQUIRED — NOT IMPLEMENTED

**Verified in the repository (schema and code reading, 2026-10-15).
Repository inspection does not establish live or test database behaviour.**

**Requests and trips are different records, linked one-to-one:**
- `dispatch_requests` is the approval record of a trip. It has `trip_id`
  (unique: one request per trip), `submitted_by` (user), `submitted_at`,
  `reviewed_by`, `reviewed_at`, `status`.
- The trip row is created **at submission**, together with its request
  (`ajax/submit_dispatch.php` is the only `INSERT INTO trips`). Trips created
  any other way (for example imported) would have no request.
- **No request is "assigned to" a dispatcher.** The schema links a request to
  the dispatcher who **submitted** it. The trip stores `dispatcher_id`
  (employee), set from the submitter. No code changes `dispatcher_id`
  afterwards.
- The code already treats the submitter as the trip's **owner for actions**.
  `ajax/update_trip_status.php` and `ajax/trip_workflow_handler.php` refuse
  status and workflow actions by anyone other than the submitting dispatcher
  (Admin override excepted; departure clearance excepted in the workflow
  handler), in addition to the assignment scope check.

**Status values that actually exist:**

| Record | Status values (schema ENUM) | Notes from the code |
|---|---|---|
| `dispatch_requests.status` | Pending, Approved, Rejected, Cancelled | Review sets only **Approved** or **Rejected**, and only from Pending. **No code sets a request to Cancelled.** Cancelling a trip later leaves its request **Approved** |
| `trips.status` | Pending Approval, Approved, Assigned, Ready for Dispatch, Dispatched, In Progress, Delivered, Returned, Completed, Cancelled, For Review | **A rejected request sets its trip to Cancelled.** There is **no "Rejected" trip status** and **no "Delayed" status**. "For Review" is never written by the code |
| "Delayed" | — (not a status) | Computed: an active trip past its `scheduled_arrival` (`tripOverdueSql`). The client defines delayed differently (CQ-7, unanswered) |
| "Completed" | trip status only | The app's "delivered" group is Delivered / Returned / Completed. What counts as completed is open (CQ-5) |

**Requests, trips, or both. Possible double counting:**
- Because each trip has exactly one request, counting **both** as separate
  work items counts every submitted trip **twice**.
- **"Cancelled trips" mixes two events:** requests rejected by the Operations
  Head (trip set to Cancelled at review) and approved trips cancelled later.
  Counting rejected requests **and** cancelled trips counts each rejection
  twice. Separating them needs the join to `dispatch_requests.status`.
- A request stays **Approved** after its trip is cancelled, so "approved
  requests" is not the same as "trips that went ahead".
- "Pending" exists twice: request **Pending** and trip **Pending Approval**
  describe the same stage. They should be counted once.
- "Delayed" overlaps with active statuses (a delayed trip is also Dispatched
  or In Progress). It is a flag on top of a status, not an extra bucket.

Alternatives (none chosen):

| Option | Counts | Statuses available | Caution |
|---|---|---|---|
| W1. Requests only | Requests the dispatcher submitted | Pending / Approved / Rejected | Shows approval workload; says nothing about delivery progress; Cancelled never occurs |
| W2. Trips only | Trips the dispatcher submitted (owner) | Trip statuses; "rejected" = Cancelled at review, distinguishable only through the request | Separate rejected from later-cancelled if both are shown |
| W3. Both, as two views | Request outcomes **and** trip progress, shown separately, never summed | Both sets | No double count if not added together |
| W4. Team view | All trips in the assigned truck types and shift (any submitter) | Trip statuses | Not "own" workload; depends on 1-b for past periods |

**Grouping options supported by existing fields:**
- by **dispatcher**: `submitted_by` / `dispatcher_id`;
- by **shift**: `trips.shift`;
- by **truck type**: the truck's type (`trucks.truck_type`) or `trips.trip_type`;
- by **date**: `submitted_at` (request), `scheduled_departure` (trip; date
  basis open in CQ-1), `reviewed_at`;
- by **assignment**: only by matching dates to `dispatcher_assignments`, which
  depends on 1-b and on history being kept (1-a).

| Question | Owner answer |
|---|---|
| Should workload count ☐ W1 requests ☐ W2 trips ☐ W3 both as separate views ☐ W4 team view ☐ Other: ____ ? | OWNER DECISION REQUIRED — NOT IMPLEMENTED |
| Which statuses should be shown, and should "rejected at review" be reported separately from "cancelled after approval"? | OWNER DECISION REQUIRED — NOT IMPLEMENTED |
| Should "delayed" appear in workload? If so, under which definition (the app's past-scheduled-arrival flag or the client's reported-delay definition, CQ-7)? | OWNER DECISION REQUIRED — NOT IMPLEMENTED |
| Group by: ☐ dispatcher ☐ shift ☐ truck type ☐ date (which date: ____) ☐ assignment | OWNER DECISION REQUIRED — NOT IMPLEMENTED |

#### 1-e. Data integrity — OWNER DECISION REQUIRED — NOT IMPLEMENTED

- **Assignment rows have not been verified against a live database.** There
  was no database access, and the test database is not approved.
- **No assignment-maintenance screen was identified in the reviewed code.**
  `dispatcher_assignments` is only read.
- `trips.shift` may be empty in the schema. The app fills it on submission,
  but a trip created another way without a shift is left out of the
  dispatcher dashboard (shift filter). If it has a truck, it may still appear
  on the submitting dispatcher's general dashboard. Dispatcher actions on it
  are refused.
- Truck types follow the role, not the person.

| Question | Owner answer |
|---|---|
| Before any OD-1 change, should assignment rows be checked on a test database, if approved (one active row per dispatcher, correct type and shift)? No existing rows are known in the reviewed schema files; see `docs/testing/od1_assignment_test_readiness.md` (synthetic-first direction). ☐ Yes ☐ No | OWNER DECISION REQUIRED — NOT IMPLEMENTED |
| How should trips with no recorded shift be treated? ☐ Hidden from dispatchers ☐ Shown to all dispatchers of the truck type ☐ Must be corrected first | OWNER DECISION REQUIRED — NOT IMPLEMENTED |
| Are per-person truck-type assignments needed, or is per-role enough? ☐ Per role ☐ Per person | OWNER DECISION REQUIRED — NOT IMPLEMENTED |

##### 1-e detail: integrity questions — OWNER DECISION REQUIRED — NOT IMPLEMENTED

Sources: the reference schema `db/reference/mulawin_fleetops_new.sql`, plus
the code in `includes/dispatcher_scope.php`, `includes/dashboard_metrics.php`,
`pages/dashboard.php`, `ajax/submit_dispatch.php`,
`ajax/trip_workflow_handler.php`, `ajax/update_trip_status.php` and
`ajax/trucks_handler.php`, read on 2026-10-15. **Nothing below was observed
at runtime. No live or test database was inspected.**

**(i) Overlapping assignment rows**
- *Verified (schema / code):*
  - `dispatcher_assignments` has no unique key or other constraint against
    overlapping rows for the same employee.
  - The scope query reads up to two active rows covering today (and the
    scheduled date, when checked). Anything other than exactly one, including
    two rows of **different** types, gives `denied` and writes a line to the
    PHP error log.
  - A row whose `dispatcher_role_type` does not match the user's role is also
    denied.
  - Inactive rows (`is_active = 0`) are ignored.
- *Unverified (runtime):* whether any overlapping, missing or mismatched rows
  exist; whether denied dispatchers appear in the error log.
- *Owner decision:* prevent overlaps in the planned assignment screen
  (already asked under 1-a), and what to do with overlaps found in existing
  data.

**(ii) Trips with no recorded shift (and no truck)**
- *Verified (schema / code):*
  - `trips.shift` and `trips.truck_id` are both nullable.
  - Dispatch submission requires a shift and a truck, and it is the only code
    that creates trips.
  - The **dispatcher dashboard** filters on `t.shift = <assigned shift>` and
    on the truck's type through `LEFT JOIN trucks`, so a trip with no shift or
    no truck is **left out of the dispatcher dashboard**.
  - The **general dashboard** filters a dispatcher's figures by own submitted
    trips and truck type, **not shift**. A no-shift trip **with a truck** may
    therefore still appear there for its submitter. A trip **with no truck**
    is excluded from truck-type-scoped views.
  - Status and workflow actions compare the trip's shift with the assignment,
    so such a trip would be **refused for any dispatcher** (Admin and
    Operations Head have no dispatcher scope).
- *Unverified (runtime):* whether any such trips exist, for example from data
  migrated or loaded outside the app.
- *Owner decision:* see the question above (hidden / shown / correct first).

**(iii) Truck types per role or per person**
- *Verified (code):*
  - Truck types come from the **role**: Car Carrier dispatcher → Car Carrier;
    Container & Wing Van dispatcher → Container **and** Wing Van together.
  - The assignment row's `dispatcher_role_type` (CAR_CARRIER /
    CONTAINER_WING_VAN) is only checked against the role. It cannot narrow a
    person to, say, Wing Van only.
  - Scoped figures use the truck's **current** type (`trucks.truck_type`),
    not the trip's stored `trip_type`. Replacing a trip's truck must keep the
    same type.
  - A truck's own type **can be edited** (`ajax/trucks_handler.php`; refused
    while the truck is Assigned or On Trip). Editing it would move that
    truck's past trips between dispatchers' scoped views.
- *Unverified (runtime):* whether any truck has been retyped; whether real
  dispatchers work across both Container and Wing Van.
- *Owner decision:* per role vs per person (question above). Also whether
  scoped figures should follow the trip's stored type (code change, not
  authorized).

**(iv) Checking assignment rows in an isolated test database**

- **No existing assignment rows are known.** The reviewed schema files (legacy
  schema, reference schema and the 21 drafts) contain no assignment rows. The
  agreed first direction is **synthetic test data** for the assignment
  logic; see `docs/testing/od1_assignment_test_readiness.md` and
  `docs/testing/od1_assignment_synthetic_test_matrix.md`. Real assignment data
  is a separate, future task. The actual legacy database is unverified.
- *Verified (repository):*
  - The test database (`mulawin_fleetops_test`) is **not approved and not
    created**. `docs/testing/database_setup_checklist.md` is PREPARED — NOT
    EXECUTED.
  - `docs/testing/mariadb_integration_checklist.md` has a dashboard-scope
    check but **no check of the assignment rows themselves**.
- *Unverified:* all row contents.
- *Owner decision:* whether to run these checks, read-only and on a test
  database, if approved, only (never the legacy database), before any OD-1
  implementation.
  Candidate checks, described here, not written as SQL and not run:
  - one active, non-overlapping row per dispatcher covering the check date
    (shows only that the loaded rows meet the scope rule; it does not
    establish that real assignment data is valid);
  - row type matches the user's role;
  - every dispatcher user is linked to an employee;
  - count of trips with no shift or no truck;
  - trucks whose type differs from their trips' `trip_type`.

| Question | Owner answer |
|---|---|
| What should happen to overlapping or mismatched assignment rows found in existing data? ☐ Correct before go-live ☐ Leave (dispatcher stays denied until corrected) ☐ Other: ____ | OWNER DECISION REQUIRED — NOT IMPLEMENTED |
| Should scoped figures follow the truck's current type or the trip's stored type? ☐ Current truck type (today) ☐ Trip's stored type (code change) | OWNER DECISION REQUIRED — NOT IMPLEMENTED |
| If test-database inspection is approved, which candidate checks above should be included? ____ | OWNER DECISION REQUIRED — NOT IMPLEMENTED |

**Approval status (OD-1):** 1-a partly decided (responsible role, maintenance
method, audit trail): owner decisions for documentation only, **not approved
for implementation**. The 1-a corrections question and all of 1-b to 1-e:
OWNER DECISION REQUIRED — NOT IMPLEMENTED.

---

## OD-2 — Operations Head maintenance costs

### Existing behaviour (verified in code)

The Operations Head holds `maintenance.view` but not `maintenance.reports.view`.

| | Maintenance page (`pages/maintenance.php`, `maintenance.view`) — **Operations Head has it** | Per-truck report (`pages/maintenance_reports.php`, `maintenance.reports.view`) — **Maintenance and Admin only** |
|---|---|---|
| Individual record cost | **Yes:** Cost column per maintenance record (`total_cost`), with truck, type, description, truck status, date performed, next due, performed by, inspection results, linked incident | No (totals only) |
| Repair work orders | **Yes:** Work Orders tab lists every work order: truck, title, priority, status, opened, expected completion, **downtime** | Yes, filtered to a date range, adding **closed time** and **opened by** |
| Downtime | **Per work order** (Work Orders tab) | **Totals** for the range (hours), plus per order |
| Cost per truck | No (must be added up by hand) | **Yes:** service count and sum of `total_cost` per truck for a from / to date range |
| Work-order counts | No | Total, open and completed counts for the range |

> **Correction (2026-10-15).** The revision-2 note in
> `analytics_data_gaps_and_client_questions.md` says granting the report "would
> also expose the work-order list and downtime". Re-checking the code for this
> worksheet shows the Maintenance page **already** shows work orders and
> per-order downtime to `maintenance.view` holders, including the Operations
> Head. What the report adds is **aggregation**: per-truck cost totals,
> work-order counts and total downtime for a date range, plus the closed time
> and opener's name. The gaps document was not edited in this task.

**Cost fields** (`maintenance_records`): `labor_cost`, `parts_cost`,
`other_cost`, `total_cost` (= labor + parts + other).

- The record form saves its single **Cost** entry into `other_cost`, so
  **labor is not recorded separately**.
- `parts_cost` grows only when parts are issued from inventory to the job.
- Both screens show only `total_cost`.

### Proposed behaviour (owner proposal, revision 2)

Per-truck maintenance-cost totals for the Operations Head, **approved in
principle only**. Superseded in detail by the owner decision below.

### Owner decision (2026-10-15) — OWNER APPROVED IN PRINCIPLE; FOLLOW-UP IMPLEMENTATION AUTHORIZED

The original decision approved the intended report content only. A later
follow-up explicitly authorized implementing this limited report using the
existing recorded-cost and date definitions below.

The original decision was **not**:

- a permission approval: it did not itself grant `maintenance.reports.view`
  or any other permission key;
- an implementation authorization: it did not itself authorize a page,
  query or navigation to be built or changed;
- a client approval.

At the time of the original worksheet, current access was unchanged.

1. The Operations Head **may receive a limited per-truck maintenance report**,
   in principle.
2. Intended report contents:
   - maintenance cost per truck, **based only on costs actually recorded**;
   - work-order counts;
   - total downtime;
   - work-order closed times.
3. **Excluded:** the name of the person who opened each work order.
4. **Parts purchase costs (stock-in) stay excluded** for now, to avoid
   counting parts twice. Parts issued to a job already reach that job's
   recorded cost.
5. **Labor is not shown as a separate cost** unless reliable source records
   support it.

The implementation uses a separate limited report rather than granting
`maintenance.reports.view`, so the new report does not show work-order opener
names.

Answers recorded against the earlier questions:

| # | Question | Owner answer (2026-10-15) |
|---|---|---|
| 2-a | What should the Operations Head see? | A **limited** per-truck report: recorded cost per truck, work-order counts, total downtime, work-order closed times; no opener names. Not the whole Maintenance Reports page as it stands |
| 2-b | Which cost fields? | Recorded cost only. **No separate labor figure** unless reliable source records support it |
| 2-c | "Purchasing" | **Parts purchase costs: excluded for now** (avoid double counting). **Purchasing Office access to the report: not decided** (see 2-h) |

### Remaining questions — not covered by the follow-up authorization

| # | Question | Owner answer |
|---|---|---|
| 2-d | Confirm the exact report / page and fields **in the current code** before any implementation (existing page restricted, or a new limited view). Includes whether "closed times" means a per-order list (with which columns: truck, title, priority, status, opened?) or summary figures only | Resolved for the authorized scope: a separate limited report is implemented at `pages/operations_maintenance_report.php`; it presents recorded cost, work-order counts, downtime, and closure-time metrics without opener names. |
| 2-e | **Verify actual runtime access.** The reviewed role seed (not executed) is not proof that the Operations Head holds `maintenance.view` / lacks `maintenance.reports.view` on a live system; that needs the approved test database or a live check | Verified in the configured development/test database after migration: Operations Head has `maintenance.view` and `maintenance.reports.operations.view`. This does not establish production access. |
| 2-f | **How recorded maintenance cost is defined and displayed.** Today: `total_cost` = labor + parts + other, where the record form saves its single cost entry in `other_cost` and parts issued from inventory add to `parts_cost`; records are dated by completion, else start, else scheduled, else creation date; cancelled records are excluded. Confirm this definition, the date rule and the label shown | Resolved for the authorized report: it uses recorded cost and the existing completion / start / scheduled / creation date precedence; cancelled records are excluded. |
| 2-g | **Labor:** no code path in the application writes `labor_cost` (it is only read inside the total), so no separate labor value exists in the source data. **No labor figure may be invented or derived.** Should labor be recorded separately in future (a workflow / form change needing its own approval)? | Resolved for this report: no separate labor figure is shown. A future labor-recording workflow is outside the approved scope. |
| 2-h | Should the **Purchasing Office** role also receive this report (Q1 names it as a reviewer)? | |

**Approval status:** The later follow-up authorizes the limited report and its
recorded-cost / date definitions. Purchasing Officer access (2-h) remains
unapproved. The report code and its permission grant are applied in the
configured development/test environment; production was not changed.

---

## OD-3 — Audit role

### Intended role (owner proposal, revision 2)

A **designated internal auditor or otherwise authorized internal staff
member**, with **read-only** access to:

- approval decisions and workflow history;
- record changes and administrative actions;
- login and access-related events;
- individual payroll and Trip Pay amounts.

**No create, edit, delete, restore or approval authority.**

### Existing behaviour before follow-up (verified in code)

- Before the follow-up migration, the `audit` role held only `audit.view`.
- **Before the follow-up, the Audit role had no working audit-history access:**
  - `audit.view` shows one menu item, **Recycle Bin**, but that page and its
    handler allow **Admin only**, so an Audit user gets an access-denied page.
  - Recent audit activity appears only on the Admin dashboard, which Audit
    cannot open.
  - Like every user, Audit sees only its own last five actions on the general
    dashboard.
- It has no restore or purge rights.

The follow-up implementation adds a separate read-only Audit History page and
read-only Billing, Finance, and Payroll grants. These grants are applied in
the checked development/test database. No
deleted-record access, Audit trip access, broad document access, or
audit-page-view logging was added.

### Identified history sources

| Source | Where defined | What it holds |
|---|---|---|
| `audit_logs` | Reference schema | User, action, entity, old / new values, IP, user agent, time. Includes record changes, approval decisions, restores / purges, password resets, user and role / permission changes, Trip Pay and payment records, document downloads, Admin overrides, `LOGIN`, `LOGOUT`, `LOGIN_FAILED` |
| `approval_history` | Reference schema | Submitted / Approved / Rejected / Cancelled / Overridden, by whom, when, remarks |
| `workflow_events` | Reference schema | Trip workflow steps and truck status changes |
| `deleted_records` | **Only in migration draft T-S1** | Archived copies of soft-deleted rows (announcements, documents, payroll records and their deductions / payslips). **Can contain payroll amounts.** Its presence was not checked in the configured database; the new Audit History page does not query it. |

### Missing or limited coverage

- **Page views are not logged.** Nobody's viewing of payroll, billing or Trip
  Pay screens is recorded, including an auditor's own.
- **Exports are not logged:** billing, attendance and trip operations exports.
  Document downloads are logged.
- **Login rate-limit records** (`login_attempts`, itself a draft) are **purged
  after one day**, so they are not a login history. Login history is in
  `audit_logs` (`LOGIN`, `LOGOUT`, `LOGIN_FAILED`). A failed login for an
  unknown username is stored without the typed name, by design.
- `audit_logs` writes were checked per handler file, not per code path.
  **Completeness of the history is not established.**

### Remaining Audit decisions — not covered by the follow-up authorization

| # | Question | Options | Owner answer |
|---|---|---|---|
| 3-a | Who is the auditor, and who authorizes the assignment? | Name / position: ____ Authorized by: ____ Review period: ____ | |
| 3-b | Are the implemented history sources sufficient, or should deleted-record history also be included? | Current page: `audit_logs`, `approval_history`, and `workflow_events`; deleted-record archive is excluded | |
| 3-c | Should deleted payroll details be included? | ☐ Yes ☐ No ☐ Only summary (who deleted what, when) | |
| 3-d | Where should the Audit menu lead? | Implemented in code: the new read-only Audit History page; Recycle Bin remains Admin-only | Resolved for the authorized scope |
| 3-e | How is access to individual payroll and Trip Pay amounts authorized and audited? | Read-only page access and grants are implemented and applied in the configured development/test database; audit of page views is not implemented. | |
| 3-f | Does the owner approve **planning** a new read-only audit view? | ☐ Yes, plan it ☐ No ☐ Later | Resolved: implementation was authorized and the page is in code |
| 3-g | Is the current coverage acceptable (no view or export logging)? | ☐ Acceptable ☐ Add export logging ☐ Add view logging for payroll / Trip Pay | |

The follow-up authorizes read-only access to Audit History and Payroll-related
information; it does not resolve the auditor's identity, deleted-record
coverage, or whether view/export logging is acceptable. The permission grants
are active in the configured development/test database; production was not
changed.

---

## OD-4 — Finance payroll

### Existing behaviour (verified in code)

- **Billing page, Payroll & Trip Pay tab**
  (`pages/billing.php`, changed 2026-10-15): shown to `payroll.view` **or**
  `finance.view`, so Finance sees it.
  - For the selected period it lists each payroll record's employee, position,
    pay period, **net amount**, paid date, notes and recorder, and each Trip
    Pay row's crew role, trip number, amount and paid date, with period
    subtotals and a print report.
  - **Missing compared with the Payroll page:** base pay, allowances,
    **deduction breakdown**, and **payslips**.
- **Recording** a salary payment needs `payroll.manage` (button, form and
  `ajax/payroll_handler.php`). Deleting is Admin-only on this tab.
- Finance cannot open `pages/payroll.php` (needs `payroll.view`) or
  `pages/trip_pay.php` (needs `payroll.manage`).
- In the checked database, Finance has `finance.view` but not `payroll.view`;
  its existing Billing tab remains the limited Payroll & Trip Pay view.
- Audit's new read-only grants are applied in the checked development/test
  database; production was not changed.

### Proposed behaviour (owner proposal, revision 2)

Finance's payroll visibility stays **limited to the Billing-page tab**; **no
full Payroll page**. Recording stays under `payroll.manage`.

### Unresolved questions — OWNER DECISION REQUIRED — NOT IMPLEMENTED

| # | Question | Options | Owner answer |
|---|---|---|---|
| 4-a | Is the Billing tab enough for Finance's duties? | ☐ Yes, net amounts and Trip Pay rows are enough ☐ No, Finance needs deductions and payslips (full Payroll page, `payroll.view`) ☐ Other: ____ | |
| 4-b | Does the owner formally accept the 2026-10-15 Billing-page change (Finance sees the tab; Billing & Collection does not; recording needs `payroll.manage`)? | ☐ Accept ☐ Change: ____ | |

Related client question: CQ-17 (where trip pay appears in the client's
payroll) may affect what Finance needs. No code or permission change is
made by this worksheet.

**Approval status:** OWNER DECISION REQUIRED — NOT IMPLEMENTED. The
2026-10-15 Billing-page change is in the working tree but **not formally
accepted**.

---

## D3 — Second-driver feature (data model) — PROPOSED / PENDING OWNER CONFIRMATION

*Not an access decision; recorded here because no other owner decision record
exists. The original D3 record could not be found in the repository, its git
history (701 commits), `mulawin_patches` or the project documents in
Downloads.*

**Proposed direction (not approved):**

1. Retain second-driver functionality in principle.
2. Leave the final data model (T-T1's fixed nullable `trips.second_driver_id`,
   or a configurable crew-slot model) undecided until the client confirms the
   maximum crew composition (CQ-19).
3. T-T1 (`db/target_migrations/20261014_tT1_trip_second_driver.sql`) may be
   included **conditionally** in the proposed isolated-test-database migration
   plan **as a technical prerequisite only**. This does not approve D3's final
   design, does not execute the migration, and authorizes no database
   operation.
4. Migration execution stays subject to separate explicit approval.

**Evidence (not a decision):**

- The Car Carrier workbook shows Driver 1 and Driver 2 on 93–100 % of rows.
- The requirements prompt (`Downloads\mulawin-fleetops-update-prompt-v2.md`,
  §4.2) describes Driver, Second Driver and Helper roles as crew slots.
- The current code (14 files, 32 references) is written for the fixed column.
  The shared trip query, submission, crew-conflict checks, status and workflow
  actions, trip pay and reports all need it.

**Open:**

- CQ-19: crew size, which decides the design.
- CQ-4: trip credit and pay, a separate question.
- The second-driver licence rule.

**Trade-off of item 3:** if a crew-slot model is chosen later, tests that
touched second-driver paths would need repeating.

**Status:** PROPOSED / PENDING OWNER CONFIRMATION. Not implemented, not
approved for execution.

---

## Follow-up implementation and verification status

The user-authorized targeted changes are implemented in code. They add:

- Dispatcher-only, submit-only Trip Allowance requests for a dispatcher's own
  confirmed trip, plus an operational report scoped to that dispatcher's
  current assignment and own submissions.
- A separate limited Operations Head maintenance report; it does not grant
  access to the broader Maintenance Reports page.
- A read-only Audit History page and read-only Billing, Finance, and Payroll
  page access. The Audit role is not granted trip-board or broad document
  access, and receives no finance-management actions.
- Crew dashboards that show Drivers and Helpers only the trips assigned to
  them.

The additive migration was **applied transactionally to the configured
development/test database after explicit approval**:
`db/target_migrations/20261015_tA2_scoped_reports_and_allowance_permissions.sql`.
It added three permission keys and the approved role grants without deleting
or altering existing grants. The new permissions and role grants are active
in that database; this migration was not applied to production.

The configured development/test database was queried read-only: it currently
has 71 permissions, 175 role grants, and 0 Budgets rows after migration.
Existing Budgets schema/data were not altered. These results do not describe
production.

Automated validation recorded for the implementation: PHP lint passed for
changed PHP files; JavaScript syntax check passed; 96 unit tests passed;
static SQL/schema checks passed (11 captures, zero confirmed or uncertain
findings, with the negative control caught); and 254 Billing period tests
passed. The migration's expected grants were verified after apply, forbidden
management grants were absent, and the Budgets row count remained 0.
Browser/E2E and endpoint integration tests were not run.

## Summary

| Decision | Owner proposal | Status |
|---|---|---|
| OD-1 | Dispatchers: scoped operational counts, no financial data | Dispatcher operational report and submit-only Trip Allowance flow implemented; grants applied in the configured development/test database, not production. Assignment maintenance and historical-assignment scope remain unresolved. |
| OD-2 | Operations Head: limited per-truck maintenance report (recorded cost per truck, work-order counts, total downtime, closed times; no opener names; parts purchases excluded; no separate labor without reliable records) | Limited report implemented; `maintenance.reports.operations.view` grant applied in the configured development/test database, not production. Purchasing Officer access remains unresolved. |
| OD-3 | Audit: designated internal auditor, read-only history and pay | Audit History and read-only page access implemented; grants applied in the configured development/test database, not production. Auditor identity, deleted-record coverage, and view/export logging remain unresolved. |
| OD-4 | Finance: Billing-page tab only | OWNER DECISION REQUIRED — NOT IMPLEMENTED (4-a–4-b open) |
| D3 | Second driver: retain in principle; model pending CQ-19; T-T1 test-only prerequisite (conditional) | PROPOSED / PENDING OWNER CONFIRMATION |
