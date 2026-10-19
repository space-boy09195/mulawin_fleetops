# Repository cleanup review

Finalization pass, 2026-10-14. Branch `system-hardening`, HEAD
`be5452194c0baeae4db1c5cec03bfed0995481b6`. **This review deleted nothing.**
The repository was not cleaned, reset or committed. `.env` was not opened.

## Method

- Inventory: 196 tracked paths (`git ls-files`) and 54 untracked,
  non-ignored paths (`git ls-files -o --exclude-standard`), as of this review.
- References: every PHP file in `ajax/ includes/ pages/ auth/ scripts/
  config/`, and every file in `assets/js/` and `assets/css/`, was searched
  for by file name across `*.php`, `*.js`, `*.css`, `*.md` and `*.yml`
  (outside `docs/`). Only `auth/forgot_password_request_handler.php` had no
  reference (see below). Dynamic links (`APP_BASE . '/pages/…'`,
  `NOTIFICATION_ENTITY_LINKS`, nav items) are plain file names, so they are
  covered by this search.
- Searched for stray files: `*.bak`, `*.orig`, `*.tmp`, `*~`, `*copy*`,
  `*old*`, `*backup*`, `*.log`, `Thumbs.db`, `*.zip`, `download*`, empty
  directories and files over 500 KB. No matches except the vendored Bootstrap
  source map (kept, see below).
- History checked for the files already deleted in the working tree.
- `tests/static/.captured/` (generated SQL captures) is created and removed by
  `tests/static/run.php`. It does not exist after a run, so nothing to clean.

## Result

**No additional file can be confidently classified as SAFE TO REMOVE.** No
deletion is proposed and none needs approval.

Three tracked files were **already deleted in the working tree before this
pass** (git status shows ` D`). This pass did not delete them. Their removal is
justified below; it becomes final only when the owner commits it.

| Path | Purpose | Evidence | References checked | Git tracked | Risk | Classification / action |
|---|---|---|---|---|---|---|
| `assets/download` | Apache caching rules (mod_expires / headers / deflate) saved under a browser-download name instead of `.htaccess` | Contents start `# Browser caching for static assets`. Apache reads only `.htaccess`, so these rules **never took effect**. `assets/vendor/README.md` already notes that no `assets/.htaccess` exists | No code or config references the name. Added in `c8c8359` | Yes (deleted in working tree) | None at runtime. If browser caching for `assets/` is wanted, recreate it as `assets/.htaccess` (owner decision) | **SAFE TO REMOVE** (already removed; keep removed) |
| `uploads/download (1)` | Same kind of misnamed caching rules for `uploads/` | Never active (not `.htaccess`). It also contradicts `uploads/.htaccess`, which denies all direct access (files are served only through `ajax/document_download.php`) | None. Added in `c8c8359` | Yes (deleted) | None | **SAFE TO REMOVE** (already removed; keep removed) |
| `includes/autoload.php` | Unused PSR-4 autoloader for a `classes/` folder that does not exist | No `require` of it anywhere. No `classes/` directory | All PHP and Markdown | Yes (deleted) | None | **SAFE TO REMOVE** (already removed; keep removed) |

## Inventory by category

### KEEP — application and runtime
| Path(s) | Reason |
|---|---|
| `login.php`, `ajax/*.php` (35 tracked + `approval_decision_handler.php`), `pages/*.php` (39 tracked + `trip_pay.php`), `includes/*.php` (28 tracked + 12 new), `auth/login_handler.php`, `config/app.php`, `config/database.php`, `config/enums.php` | Application code. Every file except one is referenced by another file |
| `analytics/*.php` (4) | Required by `includes/alerts.php`. Web access denied by `.htaccess` |
| `assets/css/*.css` (20), `assets/js/*.js` (29 + `dashboard_operations_head.js`) | Referenced by pages |
| `assets/vendor/**` incl. `bootstrap.min.css.map`, `bootstrap.bundle.min.js.map` | Vendored libraries. The maps are part of the documented update procedure in `assets/vendor/README.md` and are referenced by `sourceMappingURL` (removing them only causes 404s in browser dev tools) |
| `assets/images/README.md`, `assets/images/inspection/*` | SVG placeholders used by `pages/fleet_status.php`, `assets/js/fleet_status.js` and `pages/maintenance.php` |
| `uploads/.htaccess`, `uploads/trucks/.htaccess` | Security controls (deny direct access / no script execution). Uploaded business files must never be deleted as clean-up |
| `auth/forgot_password_request_handler.php` | Only unreferenced file. It is a **deliberate stub**: it disables the old self-service reset (an account-takeover risk) and redirects to `login.php?reset=disabled`, which `login.php` handles. Keeping it neutralises old bookmarks and cached forms. Optional removal later = REVIEW REQUIRED, low value |

### KEEP — deployment and configuration
`.htaccess`, `.gitignore`, `.env.example`, `.github/workflows/ci.yml` (PHP
lint + JS check; it does not run `tests/`), `scripts/run_automations.php` and
`scripts/generate_reminders.php` (cron entry points; must stay unscheduled
until approved). `.env` exists locally, is git-ignored and was not opened.

### KEEP — schema references, drafts, seeds
| Path(s) | Reason |
|---|---|
| `db/reference/mulawin_fleetops_new.sql` | Target schema the code is built for |
| `db/target_migrations/*.sql` (21) | Review-only drafts, **NOT EXECUTED**. Required by the B0–B8 record |
| `auth/seed_users.php` | Admin seed for disposable local databases. **Not run.** README says to remove it from a deployed server; keep it in the repository |

### REVIEW REQUIRED — legacy schema files
| Path | Purpose | Evidence | Recommendation |
|---|---|---|---|
| `db/mulawin_fleetops_db.sql` | Full legacy schema (`mulawin_fleetops`) | Referenced by README and `instructions/instructions.md` as the legacy install. It does not match the ported code | Keep while the legacy database is in use and as the migration source. Remove only after the owner retires the legacy database |
| `db/analytics_access_migration.sql`, `db/attendance_single_open_shift_migration.sql`, `db/auth_session_version_migration.sql`, `db/driver_trip_completion_migration.sql`, `db/system_user_employee_migration.sql` | Incremental legacy migrations | Only `auth_session_version_migration.sql` is referenced (setup guide, legacy path). The rest are referenced nowhere, but they record how the legacy database was changed | Keep with the legacy schema. Owner decides together with the legacy retirement. `driver_trip_completion_migration.sql` is read-only on disk (`-r--r--r--`), which suggests it was protected on purpose |

### KEEP — tests
`tests/README.md`, `tests/unit/run.php`, `tests/static/run.php`,
`tests/static/capture_sql.php`, `tests/static/helper_statements.php`,
`tests/static/stubs/*.php`, `tests/sql/analytics_known_dataset.sql`,
`scripts/schema_check.php`.

### KEEP — documentation
`README.md`, `instructions/instructions.md`, `assets/vendor/README.md`,
`docs/implementation_progress.md`, `docs/B0_schema_check.md`,
`docs/testing/*.md`, `docs/client_data_analytics_mapping.md`,
`docs/analytics_metric_traceability.md`,
`docs/analytics_data_gaps_and_client_questions.md`, this file.

Note: `instructions/instructions.md` still describes the legacy installation.
This pass added a schema note pointing to the target schema and the test
database checklist. A full rewrite of the guide is still open.

## Suggestions (not applied)

- If browser caching is wanted, add a proper `assets/.htaccess` (the deleted
  `assets/download` holds the intended rules in git history at `HEAD`).
- Consider adding `php tests/unit/run.php` and `php tests/static/run.php` to
  `.github/workflows/ci.yml`. Both need no database.
