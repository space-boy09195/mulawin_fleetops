# B0 — Static schema check (Option B)

`scripts/schema_check.php` compares the SQL written in the application's PHP
files with a schema **model** built from SQL files: the target reference
(`db/reference/mulawin_fleetops_new.sql`) plus the migration drafts you
select. It is the B0 tool for planning Option B; it does not change anything.

## Safety

- **No database connection.** The tool contains no PDO, mysqli or socket
  code and never loads `config/` or `.env`. You can run it with MySQL
  stopped.
- **No SQL is executed.** SQL files are read as text and parsed.
- **No application code is run.** PHP files are tokenized with
  `token_get_all()`, never included.
- **Writes nothing by default.** Output goes to the terminal. `--out=FILE`
  writes a report and **refuses any path inside the repository**; use a
  folder such as `C:\Users\acer\mulawin_patches\reports\`.
- **CLI only.** A web request gets 404, and the root `.htaccess` already
  blocks `scripts/` from the web.

## Commands (run from the repository root)

```
php scripts/schema_check.php --help
php scripts/schema_check.php --self-test
php scripts/schema_check.php --lint-drafts --no-scan
php scripts/schema_check.php --drafts=all
php scripts/schema_check.php --drafts=all --only=ajax/review_dispatch.php,includes/trip_number.php
php scripts/schema_check.php --drafts=all --format=csv --out=C:\Users\acer\mulawin_patches\reports\scan.csv
```

On XAMPP use `C:\xampp\php\php.exe` if `php` is not on the PATH.

- `--drafts=none|all|existing|t-series|<file,file>` selects the drafts that
  are applied on top of the target schema. They are always applied in the
  recommended order (listed in `SC_DRAFT_ORDER` in the script), whatever the
  order on the command line.
- `--lint-drafts` checks drafts: duplicate tables, columns and index names;
  constraint names reused anywhere in the database; ALTER on a missing table;
  `AFTER` a missing column; foreign-key targets, type and signedness;
  unindexed FK targets; forbidden statements (`USE`, `CREATE DATABASE`,
  `DROP`, `TRUNCATE`, views in drafts); missing header markers; DML that
  references a column or table that does not exist **at that point in the
  order** (a missing dependency).
- `--fail-on-confirmed` exits with code 1 if anything is CONFIRMED or a
  lint error exists (useful before approving a phase).

## Reading the results

| Certainty | Meaning |
|---|---|
| CONFIRMED | A literal SQL reference that the model definitely does not satisfy: a missing table/view, `alias.column` that does not exist, an INSERT column that does not exist, an INSERT that omits a `NOT NULL` column without default, an ENUM value assigned that the column does not allow, or an unqualified column in a literal single-table statement without subqueries. |
| UNCERTAIN | Heuristic: unqualified columns in statements built at runtime or with subqueries, an alias reused for different tables, ENUM values in comparisons (the query runs but never matches). Verify by hand. |
| DYNAMIC | SQL built at runtime (interpolated variables, concatenation, `.=` fragments, table names in variables). Only the literal parts are checked; the rest needs manual review. |

Known limitations:

- The SQL parser is pattern-based, not a full SQL grammar. Unusual syntax
  (comma joins, nested derived tables with reused aliases, `INSERT … SET`)
  can be missed; the self-test covers the supported cases.
- Column types and lengths are not compared with the values the code writes,
  and ENUM checks only cover `col = 'x'` and `col IN ('x', …)`.
- SQL is recognised by an UPPER-CASE leading verb (`SELECT`, `INSERT INTO`,
  `UPDATE … SET`, `DELETE … FROM`, `REPLACE INTO`, `WITH`), so lower-case
  SQL strings would be skipped. The codebase uses upper-case SQL.
- Views of the reference schema are accepted as existing objects; their
  columns are not modelled.
- Results describe the **model**, not a live database. A real server can
  differ (missing migrations, manual changes). Hostinger compatibility is
  not verified by this tool.

## Important note on the reference schema

`db/reference/mulawin_fleetops_new.sql` itself contains
`CREATE DATABASE IF NOT EXISTS mulawin_fleetops_new` and
`USE mulawin_fleetops_new`. Imported unmodified it builds
**`mulawin_fleetops_new`**, not the approved test database
`mulawin_fleetops_test`. Building the test database is a later, separately
approved step; the tool only reports this (lint INFO).

## Recommended draft order (all drafts are review-only, none executed)

1. `20261010_batch1a_security_support_tables.sql` (1A)
2. `20261010_batch1b_login_attempts.sql` (1B)
3. `20261010_p21_roles_system_key.sql` (7M)
4. `20261010_s36_employee_fields.sql` (S-36)
5. `20261014_tE1_employee_compat_columns.sql` (T-E1)
6. `20261011_p21_rbac_seed_review_only.sql` (RBAC seed; needs 7M)
7. `20261014_tS1_support_tables.sql` (T-S1)
8. `20261014_tK1_truck_fuel_efficiency.sql` (T-K1, gated D14)
9. `20261014_tA1_approval_workflow_steps.sql` (T-A1; needs 7M + seed)
10. `20261014_tT1_trip_second_driver.sql` (T-T1, gated D3)
11. `20261014_tT2_trip_completion_reports.sql` (T-T2, gated D4/D5)
12. `20261014_tI1_trip_incident_resolution.sql` (T-I1, gated D10)
13. `20261014_tD1_announcement_audience.sql` (T-D1, gated D8)
14. `20261014_tP1_trip_pay_records_guard.sql` (T-P1)
15. `20261014_tF1_expense_categories.sql` (T-F1, gated D6)
16. `20261014_tB1_budgets.sql` (T-B1, gated D7)
17. `20261014_tM1_maintenance_next_due.sql` (T-M1, gated D11)
18. `20261010_p21_attendance_records.sql` (P-21M; B6, any time after step 5)

Executing any draft requires explicit approval, a verified backup, and the
target database selected explicitly (no draft contains `USE`).
