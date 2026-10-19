# OD-1 synthetic assignment test matrix

**Status: PROPOSED PLAN — NOT EXECUTED.** No scenario has been run. No
database, SQL, test data, test account or runtime test was touched while
preparing this document. Every expectation below is **CODE-BASED EXPECTATION
— NOT TESTED**: it restates code read on 2026-10-15 and is not verified
behaviour.

Branch `system-hardening`, HEAD `be5452194c0baeae4db1c5cec03bfed0995481b6`.
Builds on [`od1_assignment_test_readiness.md`](od1_assignment_test_readiness.md);
decisions in [`../owner_access_decision_worksheet.md`](../owner_access_decision_worksheet.md) (OD-1).

## Purpose and limits

- This matrix tests whether the **current code** behaves as read. A PASS means
  "matches the code-based expectation", **not** that the behaviour is the
  right business rule. Several rules are still owner decisions (1-a, 1-b,
  1-e).
- Synthetic data cannot establish the correctness of real assignments or the
  legacy roster. Migrating or reconstructing real assignment data is a
  separate, future task.
- Running any scenario needs the separately approved steps listed in
  `od1_assignment_test_readiness.md` §6: test database, schema import, the
  drafts, synthetic data loading, test accounts, queries. None is approved.
- No executable SQL is written here.

## Code read for the expectations

| Code | Behaviour relied on |
|---|---|
| `includes/dispatcher_scope.php` | Role key `car_carrier_dispatcher` → Car Carrier; `container_wing_van_dispatcher` → Container + Wing Van. Scope needs `users.employee_id` set and **exactly one** `dispatcher_assignments` row with `is_active = 1` covering **today** (and the scheduled date when one is passed), whose `dispatcher_role_type` matches the role. Otherwise `denied`, with an `error_log` line: "no linked employee", "no / more than one applicable dispatcher assignment for <date>", or "assignment type does not match the dispatcher role". Admin and `operations_head` → `none` (unscoped). Denied page filters use shift `''` and truck type `''`, which match nothing |
| `pages/dashboard_dispatcher.php` | Trip figures filtered by `trucks.truck_type` (current truck type, via LEFT JOIN) **and** `trips.shift`; truck lists by truck type only |
| `pages/dashboard.php` | For a dispatcher: **own submitted trips** (`dispatch_requests.submitted_by`) **and** current truck type. **No shift filter** |
| `pages/dispatch.php` | Available-truck list limited to the role's truck types; the request list is not scope-filtered |
| `ajax/submit_dispatch.php` | `enforceDispatcherScope(shift, truck type, scheduled date)` → HTTP 403 with a message on violation |
| `ajax/update_trip_status.php` | Refuses anyone but the submitting dispatcher (403 "Only the dispatcher who submitted this trip can update its workflow."), then `dispatcherScopeViolation(trip shift, truck type)`. The truck type comes from `tripSelectSql()` = the truck's **current** `trucks.truck_type` |
| `ajax/trip_workflow_handler.php` | Same submitter rule (departure clearance excepted) and scope check. A replacement truck must equal the trip's stored `trip_type` |
| `ajax/trucks_handler.php` | Truck type editable, except while the truck is `Assigned` or `On Trip` |

No on-screen denial message was found in the page code for a denied
dispatcher. Whether one appears at runtime is **UNCONFIRMED — NEEDS REVIEW**.

## Common synthetic baseline (all scenarios)

Records any scenario needs, created only after approval, with fake
identities:

- **Roles** with `roles.system_key` (draft `20261010_p21_roles_system_key.sql`)
  and the reviewed RBAC seed: `car_carrier_dispatcher`,
  `container_wing_van_dispatcher`, plus `operations_head` or `admin` as a
  control.
- **Users** (test accounts) linked to those roles.
- **Employees** linked through `users.employee_id`.
- **Trucks** of each type (Car Carrier, Container, Wing Van).
- A **client** (trips require `client_id`).
- **Trips with dispatch requests** (`dispatch_requests.submitted_by` = the
  dispatcher user) where the scenario needs trips.

Assignment dates must be set **relative to the test date**, because the scope
uses "today".

Shared dependencies:
- a test database, if approved (not approved or created), with the reference
  schema and the needed drafts. Setup, synthetic-data loading and runtime
  testing are not authorized;
- test accounts;
- a way to observe the PHP error log of the local server.

---

## S1 — One valid active assignment

| Item | Content |
|---|---|
| Purpose | Confirm the baseline: a correctly assigned dispatcher is scoped to the role's truck types and the assigned shift |
| Minimum records | Car Carrier dispatcher user + linked employee; **one** active assignment (CAR_CARRIER, shift Day, start ≤ today, end empty or ≥ today); trucks of Car Carrier and Container types; trips on Day and Night shifts with both truck types, some submitted by this dispatcher, some by another |
| Preconditions / fields | `users.employee_id`, `dispatcher_assignments.{employee_id, dispatcher_role_type, shift, start_date, end_date, is_active}`, `trips.{shift, truck_id}`, `trucks.truck_type`, `dispatch_requests.submitted_by` |
| Actions | Open the dispatcher dashboard, the general dashboard and the Dispatch page as the dispatcher. Submit a Day / Car Carrier dispatch and a Night (or Container) dispatch |
| Expected | **CODE-BASED EXPECTATION — NOT TESTED.** Dispatcher dashboard counts only Day + Car Carrier trips. General dashboard counts only this dispatcher's own Car Carrier trips, **any shift** (no shift filter). Dispatch page truck list shows Car Carrier trucks only. Day / Car Carrier submission accepted; Night or Container submission refused with 403 |
| Evidence | Displayed counts vs a hand-count of the synthetic trips; truck list contents; HTTP status and message of each submission; no scope-denial line in the error log |
| Pass | All observations match the expectation |
| Fail | Any count includes other shifts / truck types on the dispatcher dashboard; an out-of-scope submission is accepted; an in-scope one is refused |
| Unexpected | General dashboard **does** filter by shift; error-log denial lines appear; counts differ for reasons not explained by the data |
| Needs DB / runtime | Yes / yes |
| Risks / decisions | Whether the general dashboard should also filter by shift is not decided (OD-1 1-c). The PASS criterion is the current code, not the desired rule |

## S2 — Overlapping active assignment rows

| Item | Content |
|---|---|
| Purpose | Confirm fail-closed handling when more than one assignment applies |
| Minimum records | One dispatcher + employee; **variant A:** two active rows both covering today (same or different shift); **variant B:** one row covering today and a second active row covering only a future date D; a few trips |
| Preconditions / fields | As S1; overlapping `start_date` / `end_date`, both `is_active = 1` |
| Actions | A: open the dispatcher dashboard and try a submission for today. B: open the dashboard today, then try a submission scheduled on date D |
| Expected | **CODE-BASED EXPECTATION — NOT TESTED.** A: scope `denied`. Dashboard trip figures empty (filters match nothing); submission refused (403, "does not have one active dispatcher assignment for this date…"); error log "more than one applicable dispatcher assignment for <today>". B: today's view uses only today's single row (scoped normally). For the submission on D, the query requires a row covering **both today and D**, so the future-only row does not match yet: the submission is **accepted** if the first row is open-ended (or covers D), refused otherwise. The overlap would only cause denial once today reaches D |
| Evidence | Empty figures; 403 status and message; error-log lines with the user ID and date |
| Pass | A denied as described; B scoped today, with the D submission outcome matching the first row's end date as described |
| Fail | Variant A: either row is silently used, or a submission succeeds while two active rows cover both today and the scheduled date. Variant B: the outcome differs from the expectation above |
| Unexpected | A visible error page or crash instead of empty figures; no error-log line |
| Needs DB / runtime | Yes / yes |
| Risks / decisions | How overlaps should be prevented or corrected is open (1-a corrections, 1-e). Inactive rows (`is_active = 0`) should be ignored; worth a third variant |

## S3 — Assignment type does not match the dispatcher's role

| Item | Content |
|---|---|
| Purpose | Confirm a row of the wrong dispatcher type denies scope |
| Minimum records | Car Carrier dispatcher user + employee; one active row with `dispatcher_role_type = CONTAINER_WING_VAN`; a few trips |
| Preconditions / fields | `roles.system_key = car_carrier_dispatcher`; row type mismatched |
| Actions | Open the dispatcher dashboard; attempt a submission |
| Expected | **CODE-BASED EXPECTATION — NOT TESTED.** Scope `denied`; empty figures; submission refused (403); error log "assignment type does not match the dispatcher role" |
| Evidence | As S2 |
| Pass | Denied, with that log line |
| Fail | The dispatcher gets Container / Wing Van scope, or Car Carrier scope |
| Unexpected | Any non-empty scoped figures |
| Needs DB / runtime | Yes / yes |
| Risks / decisions | Per-role vs per-person truck types is open (1-e). Container and Wing Van stay bundled under the current code |

## S4 — Dispatcher has no employee link

| Item | Content |
|---|---|
| Purpose | Confirm a dispatcher user without `users.employee_id` is denied |
| Minimum records | Dispatcher user with `employee_id` empty; optionally an assignment row for some employee (it cannot be reached) |
| Preconditions / fields | `users.employee_id` empty. Whether the schema and login allow such a user is **UNCONFIRMED — NEEDS REVIEW**: the employee-link drafts and the login path were not re-read for this matrix |
| Actions | Log in as that user; open the dispatcher dashboard; attempt a submission |
| Expected | **CODE-BASED EXPECTATION — NOT TESTED.** Scope `denied`; empty figures; submission refused; error log "no linked employee (users.employee_id)". Login may itself refuse such a user: **UNCONFIRMED — NEEDS REVIEW** |
| Evidence | Login outcome; empty figures; 403; error-log line |
| Pass | Denied as described (or refused at login, if that is confirmed as the intended earlier gate) |
| Fail | Any scoped data or a successful submission |
| Unexpected | An application error instead of denial |
| Needs DB / runtime | Yes / yes |
| Risks / decisions | Who links users to employees is not part of OD-1. Data loading must not use real names |

## S5 — Trip with no shift, or no truck

| Item | Content |
|---|---|
| Purpose | Confirm how trips missing `shift` or `truck_id` are treated |
| Minimum records | S1 baseline; trip T1 with `shift` empty but a truck; trip T2 with a shift but no truck; both submitted by the S1 dispatcher. These can only be **loaded directly** (the app's submission always sets both) |
| Preconditions / fields | `trips.shift` NULL, `trips.truck_id` NULL (both nullable in the schema) |
| Actions | View the dispatcher dashboard and general dashboard as the dispatcher; try a status update on T1 and T2 |
| Expected | **CODE-BASED EXPECTATION — NOT TESTED.** Dispatcher dashboard: T1 and T2 **absent** (shift filter; truck-type filter through LEFT JOIN). General dashboard: T1 **present** (own trip, truck type matches, no shift filter); T2 absent. Status updates refused for both: owner check passes, then the scope check fails on shift or truck type (403) |
| Evidence | Presence / absence per page; 403 messages |
| Pass | Matches the expectation per page |
| Fail | T1 or T2 shown on the dispatcher dashboard, or an update accepted |
| Unexpected | Errors from NULL values; T1 absent from the general dashboard |
| Needs DB / runtime | Yes / yes |
| Risks / decisions | Treatment of such trips is open (1-e). Whether real data contains them is unknown; only a later real-data check could tell |

## S6 — Truck's current type differs from the trip's stored type

| Item | Content |
|---|---|
| Purpose | Confirm that scoped figures and actions follow the truck's **current** type, not `trips.trip_type` |
| Minimum records | S1 baseline plus a Container dispatcher (with assignment, same shift); trip T3 submitted by the Car Carrier dispatcher with `trip_type = Car Carrier` on truck X; truck X then of type **Container**. Either load it so, or retype X while T3 is still Pending Approval (retyping is refused only while the truck is Assigned or On Trip) |
| Preconditions / fields | `trips.trip_type`, `trucks.truck_type`, truck status |
| Actions | View both dispatchers' dashboards; the Car Carrier dispatcher tries a status update on T3; try a workflow truck replacement on T3 with a Car Carrier truck and with a Container truck |
| Expected | **CODE-BASED EXPECTATION — NOT TESTED.** T3 appears on the **Container** dispatcher's dashboard (same shift) and not on the Car Carrier dispatcher's dashboard or general dashboard. Car Carrier dispatcher's status update refused by the scope check (truck type). Container dispatcher's update refused by the submitter rule. Workflow replacement accepts only a truck whose type equals `trip_type` (Car Carrier), subject to the owner and scope checks: whether that combination can ever pass is **UNCONFIRMED — NEEDS REVIEW** |
| Evidence | Dashboard presence; 403 messages for each attempt |
| Pass | Visibility follows the current truck type; actions refused as described |
| Fail | Visibility follows `trip_type` |
| Unexpected | The trip becomes actionable by nobody except Admin, if that is not accepted. Record as a finding, not as a pass or fail |
| Needs DB / runtime | Yes / yes |
| Risks / decisions | Whether filters should follow current or stored type is open (1-e). The scenario may reveal a trip stuck for dispatchers |

---

## Read-only checks (kept separate from the scenarios)

From `od1_assignment_test_readiness.md` §3. Described in words only; no SQL.

| Check | What it checks | Could establish | Cannot establish | Needs | Suitable for |
|---|---|---|---|---|---|
| C1 One active, non-overlapping row per dispatcher covering the check date | Count of active rows per dispatcher employee for a date | Whether the loaded rows satisfy the scope rule on that date | Real roster correctness; other dates; production state | `dispatcher_assignments`, users / roles / employees | **Synthetic:** confirms S1–S3 data was loaded as designed. **Later real-data assessment:** the meaningful use |
| C2 Row type matches the dispatcher's role | Row `dispatcher_role_type` vs the user's role key | Row / role consistency | The person's actual job | Roles with `system_key`, users, employees, assignments | Synthetic (S3 setup check) and later real data |
| C3 Every dispatcher user linked to an employee | Users in dispatcher roles with no / invalid `employee_id` | Missing links | That a link is the right person | Users, roles, employees | Synthetic (S4 setup check) and later real data |
| C4 Trips with no shift or no truck | Count of such trips | Their number in the loaded data | Anything about real trips unless they are migrated | Trips | Synthetic (S5 setup check); **mainly a later real-data assessment** |
| C5 Trucks whose type differs from their trips' stored type | Trips where `trip_type` ≠ the truck's current type | Mismatches in the loaded data | Which is correct; when the truck was retyped (no type history) | Trips, trucks | Synthetic (S6 setup check); mainly later real data |

On synthetic data, C1–C5 only confirm the test set-up; they are not tests of
the application. On an empty database they return nothing.

## Blockers and dependencies (no decision taken here)

- **Not blocking the plan itself:** the matrix was completed without making an
  implementation decision.
- **Blocking execution:** every approval in `od1_assignment_test_readiness.md`
  §6.
- **Blocking interpretation of "correct":** OD-1 1-a (corrections), 1-b
  (historical rule), 1-c (general-dashboard shift filtering, page access),
  1-e (no-shift trips, per-role types, current vs stored type, overlap
  handling).
- **UNCONFIRMED — NEEDS REVIEW:** S4 login behaviour and schema allowance for
  an unlinked user; whether a denial message is displayed; S6 workflow
  replacement outcome.
