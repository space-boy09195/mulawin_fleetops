# Tests

Web access to this folder is denied by `.htaccess`. Run everything from the
repository root with XAMPP's PHP (`C:\xampp\php\php.exe`).

| Command | What it proves | Environment |
|---|---|---|
| `php tests/unit/run.php` | Pure helpers: metric rates and divide-by-zero, delivery / late / on-time definitions, finance note formats, client rate amounts, recruitment notes, soft-delete payloads, delivery-note parsing, inspection timers, analytics buckets / period change / badges, dashboard month keys | STATIC / UNIT — no database |
| `php tests/static/run.php` | Runs the data layer of the analytics page, every role dashboard and Operations Performance against a recording stub PDO, then checks every SQL string they build (plus shared helper SQL) against the target schema model with `scripts/schema_check.php`; includes a negative control that must fail | STATIC — no database, `.env` never read |
| `php tests/static/billing_period_test.php` (also run by `tests/static/run.php`) | Billing page period filters: for each bounded period, the seven period-filtered statements (billings, collections, payroll, Trip Pay, trip expenses, maintenance cost, parts purchased) keep their lower bound and have an exclusive upper bound at the start of tomorrow, with `Y-m-d` / `Y-m-d 00:00:00` values for DATE / DATETIME columns and every named placeholder bound once; All Time has no period filter. Optional argument: path of another copy of the page (negative control) | STATIC — recording stub PDO, no database |
| `tests/sql/analytics_known_dataset.sql` | Analytics definitions against a small known dataset (expected values in the header) | **MariaDB test database only, after approval** — ends in ROLLBACK |
| `php tests/integration/run.php` | Approval-workflow integration harness (E2). Default is preflight only; see `tests/integration/README.md` for the opt-in modes, safety checks and planned scenarios | Preflight: no database, no HTTP. **Never run against a database yet** |

Static and unit results are not database results: MariaDB and browser
checks are listed in `docs/testing/`.

## ISO/IEC 25010:2023 evaluation readiness (objectives 6.1–6.7)

Status as of 2026-10-21. **No formal ISO/IEC 25010:2023 evaluation has been
carried out.** Code checks and automated tests below are inputs to that
evaluation, not its result. Nothing in this table is a respondent score,
response time, reliability statistic or safety result.

| Characteristic | Executed and observed | Pending | Needs |
|---|---|---|---|
| 6.1 Functional suitability | `php tests/unit/run.php` and `php tests/static/run.php` (pass/fail recorded with each change) | Per-role workflow runs in `docs/testing/mariadb_integration_checklist.md` and `browser_e2e_checklist.md` (NOT TESTED) | Isolated test database; testers per role |
| 6.2 Performance efficiency | None | Page and endpoint response times; small concurrent-user load test | Isolated environment; measurement tool (browser dev tools, JMeter or k6) |
| 6.3 Usability | None | Task-based usability test and questionnaire (for example SUS or a 25010-aligned Likert instrument) | Real participants per role; signed responses. Results must not be invented |
| 6.4 Reliability | None (transactions, row locks and idempotency keys exist in code only) | Double-submit and concurrent-approval tests; backup and restore drill; database-outage handling | Isolated test database |
| 6.5 Maintainability | Static schema-conformance check of captured SQL (`tests/static/run.php`, with a negative control) | Code-metrics review | Reviewer; metrics tool |
| 6.6 Security | None executed (controls exist in code: CSRF, prepared statements, bcrypt, session revocation, login throttling, server-side permissions, dispatcher scope) | Direct-request authorization matrix (each role against each endpoint, expect 403); OWASP ZAP baseline scan; session-revocation test | Isolated environment; one account per role |
| 6.7 Safety | None (departure gate requires a passed inspection, Operations Head clearance and a 15-minute minimum inspection time, in code only) | Hazard scenarios: dispatching a truck under maintenance, an unlicensed driver, an early inspection submission | Isolated test database |

Record every executed item with date, tester, environment, PASS/FAIL and
evidence (screenshot, query result or command output) in the checklist it
belongs to.
