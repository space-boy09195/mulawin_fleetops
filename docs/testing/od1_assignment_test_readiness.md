# OD-1 dispatcher-assignment test readiness

**Status: ASSESSMENT ONLY — NOT EXECUTED.** No database has been created,
connected to, imported into or queried for this assessment. No SQL was
written or run. No test described here has been run.
`database_setup_checklist.md` and `mariadb_integration_checklist.md` remain
not executed and not tested.

Prepared 2026-10-15. Branch `system-hardening`, HEAD
`be5452194c0baeae4db1c5cec03bfed0995481b6`. Related:

- [`../owner_access_decision_worksheet.md`](../owner_access_decision_worksheet.md)
  (OD-1, topics 1-a to 1-e);
- [`database_setup_checklist.md`](database_setup_checklist.md);
- [`mariadb_integration_checklist.md`](mariadb_integration_checklist.md).

## 1. Current readiness: not ready for meaningful assignment checks

The isolated test database planned in `database_setup_checklist.md`
(`mulawin_fleetops_test`, built from the reference schema plus the migration
drafts) would contain the `dispatcher_assignments` table but **no assignment
rows**. Unless test data is loaded, it would also have **no users and no
trips**.

Repository / schema findings (inspection of files only):

- The legacy schema file `db/mulawin_fleetops_db.sql` has **no**
  `dispatcher_assignments` table. The code keeps a fallback that derives a
  dispatcher's shift and truck types from legacy role names (for example
  "Car Carrier Dispatcher - Day") when that table is absent.
- The reference schema `db/reference/mulawin_fleetops_new.sql` and the 21
  reviewed migration drafts in `db/target_migrations/` contain **no inserts
  into `dispatcher_assignments`**.

**Not verified:** these findings come from repository files, not from a
database. The actual legacy database (`mulawin_fleetops`) has not been
connected to or inspected. Whether it matches its schema file, or holds any
assignment data, is unknown.

## 2. Agreed testing direction: synthetic data first

The agreed first direction is to validate the **dispatcher-assignment logic**
with **synthetic test data**.

- Synthetic data can exercise the application's logic and known edge cases
  (for example fail-closed denial, scope filtering, the submitter-only action
  rule).
- **Synthetic results do not establish** the correctness of real dispatcher
  assignments or the real roster in the legacy system.
- **Reconstructing or migrating real assignment data is a separate, future
  decision and task.** It is not part of this direction, and no rule or
  migration draft for it exists.

### Proposed synthetic scenarios (planned, not written, not run)

| # | Scenario | Expected behaviour per the reviewed code (to be confirmed by a test) |
|---|---|---|
| S1 | One valid active assignment matching the user's role | Scope = role truck types + assigned shift |
| S2 | Two overlapping active assignments for the same dispatcher | **Variant A:** two active rows covering both today and the scheduled date → scope denied (fail closed); error-log line. **Variant B:** a future-only overlap does not count yet when the first row covers the dates the submission requires (today and the scheduled date) → submission accepted. Both are code-based expectations, not test results; see the matrix S2 for the full specification |
| S3 | Assignment whose dispatcher type does not match the user's role | Scope denied |
| S4 | Dispatcher user with no linked employee | Scope denied |
| S5 | Trips without a recorded shift, and trips without a truck | Left out of the dispatcher dashboard (shift filter); a no-shift trip **with a truck** may still appear on the submitting dispatcher's general dashboard (no shift filter); trips with no truck are excluded from truck-type-scoped views; dispatcher actions refused |
| S6 | Truck whose current type differs from its trip's stored type | Scoped figures follow the truck's current type |

The "expected behaviour" column restates the reviewed code. It is **not a
test result**. No synthetic data, SQL, fixture or test account has been
created.

## 3. Candidate read-only checks (from worksheet 1-e)

Described in words only; not written as SQL; not run.

| Check | Could establish (on the data loaded) | Cannot establish |
|---|---|---|
| One active, non-overlapping row per dispatcher covering the check date | Whether rows satisfy the scope rule on that date | Real roster correctness; other dates; production state |
| Row type matches the dispatcher's role | Row / role consistency for linked users | The person's actual job |
| Every dispatcher user linked to an employee | Missing or invalid links | That the link is the right person |
| Trips with no shift or no truck | Their count in the loaded data | Anything about real trips unless migrated |
| Trucks whose type differs from their trips' stored type | Mismatches in the loaded data | Which value is correct, or when a truck was retyped |

With synthetic data these checks confirm only that the scenarios were loaded
as intended. On an empty database they return nothing.

## 4. Unresolved decisions

**Affect how results are interpreted** (all OWNER DECISION REQUIRED — NOT
IMPLEMENTED):

- **1-a, correction handling** (end-and-replace / edit-in-place / mixed):
  whether several rows per dispatcher are expected history or errors.
- **1-b, historical reporting** (assignment on the trip date / current
  assignment): whether only rows covering today matter.
- **1-e:**
  - treatment of trips with no shift;
  - per-role vs per-person truck types;
  - whether filters follow the truck's current type or the trip's stored
    type;
  - what to do with overlapping or mismatched rows;
  - which checks to include.
- **Source of any future real assignment data:** derive from legacy role
  names, enter through the decided assignment screen, or other. Separate
  future decision.
- Client question **CQ-1** (trip date basis), for any date-based check.

**Needed before implementation, not for interpreting tests:**

- 1-c, page access. **Exception:** 1-c is also relevant to interpreting S1's
  general-dashboard result. The existing behaviour is no shift filter there;
  whether one should be added (the desired page-access rule) is unresolved;
- 1-d, workload definition.

**Already decided (documentation only, not approved for implementation):**

- 1-a: Operations Head or Admin maintains assignments;
- a dedicated permission-protected screen;
- an assignment-change audit trail.

## 5. Prerequisites for any future test-database use

From `database_setup_checklist.md` §0 (none confirmed):

- a fresh backup of the legacy database exists **and was restored
  successfully elsewhere** (owner verifies);
- the target is the **local XAMPP** MariaDB instance;
- the name `mulawin_fleetops_test` is available (or may be dropped);
- static checks reviewed and passing on the code to be tested;
- automation, reminders, email and external notifications stay **disabled**.

Target safety (from the checklist and the assessment):

- every command names `mulawin_fleetops_test` explicitly, and the target is
  confirmed (`SELECT DATABASE()` / `SHOW DATABASES LIKE`) before each step;
- the reference schema's own `CREATE DATABASE` / `USE` lines are stripped from
  a filtered copy first;
- passwords are typed by the owner, never stored;
- `.env` is changed only by the owner;
- **nothing ever names, connects to, imports into, drops or truncates the
  legacy `mulawin_fleetops` database.**

## 6. Separate explicit approval required for each step

Each of the following needs its own explicit owner approval. None has been
given:

1. Confirmation that the legacy backup exists and was test-restored.
2. Creating the test database `mulawin_fleetops_test`.
3. Importing the filtered reference schema.
4. Applying migration drafts, named individually (all 21 or a listed subset).
5. Loading synthetic test data (and, separately and later, any real assignment
   data).
6. Creating test accounts (roles, holders, fake identities).
7. Running database queries, including the read-only checks (naming which).
8. Who runs each command and enters credentials.

*Proposed (pending owner confirmation):* T-T1
(`db/target_migrations/20261014_tT1_trip_second_driver.sql`) may be included
conditionally in the migration set as a technical prerequisite only. Applying
it still requires item 4's separate approval, and it does not decide D3's final
design (see the owner worksheet, D3).
