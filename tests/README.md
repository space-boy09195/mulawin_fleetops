# Tests

Web access to this folder is denied by `.htaccess`. Run everything from the
repository root with XAMPP's PHP (`C:\xampp\php\php.exe`).

| Command | What it proves | Environment |
|---|---|---|
| `php tests/unit/run.php` | Pure helpers: metric rates and divide-by-zero, delivery / late / on-time definitions, finance note formats, client rate amounts, recruitment notes, soft-delete payloads, delivery-note parsing, inspection timers, analytics buckets / period change / badges, dashboard month keys | STATIC / UNIT — no database |
| `php tests/static/run.php` | Runs the data layer of the analytics page, every role dashboard and Operations Performance against a recording stub PDO, then checks every SQL string they build (plus shared helper SQL) against the target schema model with `scripts/schema_check.php`; includes a negative control that must fail | STATIC — no database, `.env` never read |
| `php tests/static/billing_period_test.php` (also run by `tests/static/run.php`) | Billing page period filters: for each bounded period, the seven period-filtered statements (billings, collections, payroll, Trip Pay, trip expenses, maintenance cost, parts purchased) keep their lower bound and have an exclusive upper bound at the start of tomorrow, with `Y-m-d` / `Y-m-d 00:00:00` values for DATE / DATETIME columns and every named placeholder bound once; All Time has no period filter. Optional argument: path of another copy of the page (negative control) | STATIC — recording stub PDO, no database |
| `tests/sql/analytics_known_dataset.sql` | Analytics definitions against a small known dataset (expected values in the header) | **MariaDB test database only, after approval** — ends in ROLLBACK |

Static and unit results are not database results: MariaDB and browser
checks are listed in `docs/testing/`.
