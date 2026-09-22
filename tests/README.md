# Tests

## Running locally

```bash
composer install
composer test
# or directly:
vendor/bin/phpunit
```

## What's covered

Unit tests for the pure, side-effect-free business-logic functions:

- `includes/date_helpers.php` — `isValidDate()`, `isPassedDate()`
- `includes/employee_validation.php` — `validateEmpFields()` (used by both
  the Add Employee form and the batch CSV import — one test explicitly
  covers the `$allowPassedDates` flag the import uses) and
  `duplicateEmployeeExists()` (tested against an in-memory SQLite database,
  since its query is portable standard SQL)

These two files used to be defined inline inside `includes/session.php` and
`ajax/users_handler.php`. Both of those have top-level side effects
(`session.php` starts a session and hardens cookies as soon as it's loaded;
`users_handler.php` runs `requireRole()`/`requirePostMethod()`/`enforceCsrf()`
immediately) that make them impossible to `require` in a test process. The
pure logic was extracted into its own files so it's testable without
triggering any of that — `session.php` and `users_handler.php` still
`require` the extracted files, so behavior elsewhere is unchanged.

## What's NOT covered yet

- The `ajax/*.php` handlers themselves (the actual HTTP endpoints) aren't
  tested. They're procedural scripts that read `$_POST` and call `header()`
  directly rather than exposing testable functions, and most of them talk to
  a real MySQL database. Testing them properly would mean either a larger
  refactor (splitting each handler into a thin script + a testable function,
  the same pattern used for employee validation above) or standing up a real
  MySQL instance in CI (GitHub Actions supports this via a service
  container) and writing integration tests against it — worth doing next,
  but bigger than this first pass.
- `includes/login_throttle.php`'s database queries use MySQL-specific syntax
  (`DATE_SUB(NOW(), INTERVAL ? MINUTE)`), which isn't portable to the
  in-memory SQLite used for the employee-duplicate-check test above. It's
  reasoned through manually in code review for now; a real integration test
  needs a MySQL service container the same way the handlers above would.
- The CI lint job only checks that every file **parses** (`php -l`) — it
  doesn't verify runtime correctness, which is what the unit tests above are
  for.
