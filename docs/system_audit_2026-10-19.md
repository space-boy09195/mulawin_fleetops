# Mulawin FleetOps System Audit

**Audit date:** 2026-10-19  
**Scope:** Current worktree and the database reached through the application's configured PDO connection.  
**Safety:** Database inspection used read-only `SELECT`/metadata queries. No records, grants, schema, or migrations were changed. No sensitive business values or credentials are included.

**Post-audit code follow-up (2026-10-19):** `ajax/trip_completion_handler.php` now enforces `trips.complete` in addition to its active-driver and trip-assignment checks. The finding below records the code state observed during the audit; the fix passed PHP lint and the existing unit and static suites. No database changes were made.

## Executive summary

The running local application responds at `http://localhost/mulawin_fleetops/login.php`. The application's own `getDBConnection()` configuration successfully connected to **`mulawin_fleetops_test`**, a local MariaDB 10.4.32 database on port 3306. The connection is the same singleton PDO path used by application pages and handlers. The database is classified as a local test/development database based on its name, XAMPP runtime, and the repository's test-database instructions; there is no evidence that it is staging or production.

The live database contains **83 InnoDB tables and 3 views**. Its object inventory matches the 70-table target reference schema plus the 13 tables created by the ordered target migration set. All 14 target migration `ALTER TABLE` targets exist. The actual columns, enum values, foreign-key mappings, and explicitly defined indexes align with the target model after accounting for MariaDB's JSON alias and a false-positive in the repository parser for a nullable generated attendance column. The three additional scoped-report/allowance permissions and their live role assignments are present.

The highest-priority findings are:

1. **The configured database principal has broad global privileges, all grantable**, including schema changes, data writes, user creation, file, shutdown, and replication privileges. The database is currently local/test, but this is not an acceptable production application privilege boundary.
2. **Seven live trips are Completed while the live database has no vehicle inspection records**, although the current trip workflow requires departure inspections before readiness/completion. These may be historical/test fixtures from before the current workflow; the record origin cannot be proven.
3. **Trip Allowance can be requested, approved, and disbursed in code, but there is no implemented liquidation workflow** despite a `cash_advance_liquidations` table. The live test database has no fund requests, approvals, disbursements, or liquidations to demonstrate the path.
4. **The driver-completion endpoint does not check the database `trips.complete` permission.** It instead checks an active linked employee profile whose position contains “driver” and assignment to the trip. That is a record-level restriction but does not enforce the declared RBAC permission.
5. **Several root-level migration files describe the legacy schema, not the current target schema.** Applying them to the target database would be unsafe or fail; do not treat their absence as an unapplied target migration.

All findings below label their evidence source. “Database verified” means inspected against the connected live test database; it does not imply production data or workflow behavior.

## 1. Current database identification

| Item | Finding | Evidence |
|---|---|---|
| Configuration source | `config/database.php` loads `includes/env.php`, which reads the project-root `.env`; configured host/database/charset resolve through that code. No connection secret is reproduced here. | **Code verified** |
| Effective database | `mulawin_fleetops_test` | **Database verified** via the same `getDBConnection()` used in application code |
| Configured host | `localhost` | **Database/config verified** |
| Port | No explicit `DB_PORT` is added to the PDO DSN; the MySQL PDO default is used. The server reports port `3306`. | **Code + database verified** |
| Engine/version | MariaDB 10.4.32, XAMPP binary distribution | **Database verified** |
| Character set | `utf8mb4` in the effective PDO configuration | **Code verified** |
| Connections | One MySQL PDO connection factory, cached statically for the PHP request. The repository scan found no second production PDO/mysqli connection configuration. | **Code verified** |
| Runtime reachability | The local Apache application returned HTTP 200 for the login page; a PHP CLI request through the exact application configuration connected and queried metadata successfully. | **Partially verified**: a particular authenticated web request's PDO session was not inspected, but the running application and configured connection path were both verified |
| Environment | Local test/development, not production: database name ends in `_test`; local XAMPP Apache/MariaDB processes were running; repository setup documentation names this as the isolated test DB. | **Database + repository evidence** |

The repository checklist calls the test-database setup “PREPARED — NOT EXECUTED.” That statement is stale relative to this audit: the database exists, the application is configured to it, and the connection works. The checklist's expected total of 86 objects is consistent with what is present if interpreted as **83 tables + 3 views**. No schema-version or migration-history table was found, so the database's exact migration execution order/date cannot be proven from the database.

The effective database account identity and credentials were deliberately omitted. Read-only privilege metadata for the connected account showed **global privileges with `IS_GRANTABLE=YES`**, including `ALTER`, `CREATE`, `CREATE USER`, `DELETE`, `DROP`, `FILE`, `INSERT`, `UPDATE`, `SHUTDOWN`, replication privileges, and `SELECT`. This is **Database verified**; the account name and any credential material are not reported. It means the application connection is not least-privileged. This is high risk if reused outside the local test environment.

## 2. Actual database inventory

### Objects, defaults, and constraints

- **83 base tables**, all using InnoDB; **3 views**.
- **149 foreign-key column mappings** and **46 non-primary unique indexes**.
- All explicit target-schema index names, uniqueness, and ordered columns matched the target schema model.
- All target-schema foreign-key column/table mappings matched exactly.
- No triggers, stored procedures/functions, or scheduled database events were found.
- Auto-increment integer/bigint primary keys are common. Timestamp columns commonly default to `CURRENT_TIMESTAMP`; mutable tables commonly use `ON UPDATE CURRENT_TIMESTAMP`. The live column metadata was inspected for type, default, nullability, enum values, generated-column attributes, and extra attributes.
- MariaDB exposes the target's `JSON` columns as its `LONGTEXT` JSON alias in `information_schema`; this is an engine representation difference, not an application-column omission.
- `attendance_records.open_shift_employee_id` is a nullable virtual generated column with a unique index. Nullability is intentional: multiple closed shifts produce `NULL`, while the unique key prevents two open shifts for one employee. A simple parser falsely flags it because it sees `IS NOT NULL` inside the generated expression.

### Table inventory

**Identity, authorization, audit, shared services, and HR:**  
`roles`, `permissions`, `role_permissions`, `users`, `employees`, `dispatcher_assignments`, `attendance_records`, `login_attempts`, `idempotency_requests`, `app_settings`, `audit_logs`, `workflow_events`, `deleted_records`, `approval_workflows`, `approval_workflow_steps`, `approval_requests`, `approval_steps`, `approval_history`, `notifications`, `automation_settings`, `automation_runs`, `automation_deliveries`, `reminder_deliveries`, `announcements`, `recruitment_records`, `office_supplies`, `office_supply_transactions`, `files`, `documents`, `document_links`.

**Clients, dispatch, trips, fleet, and inspections:**  
`clients`, `client_locations`, `client_rates`, `client_bookings`, `client_nominations`, `routes`, `dispatch_requests`, `dispatch_clearances`, `trips`, `trip_number_counters`, `truck_assignments`, `trucks`, `truck_documents`, `trip_updates`, `trip_deliveries`, `trip_returns`, `trip_incidents`, `trip_completion_reports`, `inspection_templates`, `inspection_items`, `vehicle_inspections`, `inspection_findings`, `inspection_damage_comparisons`, `inspection_photos`.

**Maintenance, purchasing, and inventory:**  
`suppliers`, `parts`, `inventory`, `inventory_transactions`, `maintenance_records`, `maintenance_findings`, `maintenance_parts`, `preventive_maintenance_schedules`, `purchase_requests`, `purchase_request_items`, `purchase_orders`, `purchase_order_items`, `purchase_receipts`.

**Finance, billing, payroll, and budgets:**  
`fund_requests`, `cash_advance_liquidations`, `expense_records`, `accounts_payable`, `payment_vouchers`, `disbursements`, `billings`, `billing_items`, `collections`, `payroll_periods`, `payroll_records`, `trip_pay_records`, `payroll_deductions`, `payslips`, `payroll_disbursements`, `budgets`.

**Views:** `v_completed_unbilled_trips`, `v_expiring_documents`, and `v_low_stock_parts`. No references to these view names were found in current PHP pages, AJAX handlers, or includes; they appear unused by application queries.

### Major columns and workflow enums

- **RBAC:** `roles.role_id`, `role_name`, `system_key`, `is_active`; `permissions.permission_key`; unique `(role_id, permission_id)` in `role_permissions`.
- **Dispatcher scoping:** `dispatcher_assignments.employee_id`, `dispatcher_role_type` (`CAR_CARRIER` / `CONTAINER_WING_VAN`), `shift` (`Day` / `Night`), date range, `is_active`.
- **Dispatch/trips:** `dispatch_requests.trip_id`, `submitted_by`, status (`Pending`, `Approved`, `Rejected`, `Cancelled`); `trips.trip_id`, unique `trip_number`, `trip_type`, client/booking/route, scheduled departure, truck/driver/helper/dispatcher, shift, status (`Pending Approval`, `Approved`, `Assigned`, `Ready for Dispatch`, `Dispatched`, `In Progress`, `Delivered`, `Returned`, `Completed`, `Cancelled`, `For Review`).
- **Inspections:** templates/stages (`Departure`, `Arrival`), required items, inspection result (`Passed`, `Flagged`, `Failed`), finding result (`Good`, `Flagged`, `N/A`), completion/duration fields.
- **Maintenance:** `maintenance_records.record_source` (`record`, `work_order`, `inspection`, `pm_service`), status (`Reported`, `Scheduled`, `In Progress`, `Waiting for Parts`, `Completed`, `Cancelled`), schedule/due dates and recorded cost fields.
- **Inventory/purchasing:** `inventory.quantity_on_hand`; `inventory_transactions.transaction_type` (`Stock In`, `Stock Out`, `Adjustment`, `Return`); purchase request/order statuses and item/receipt keys.
- **Finance:** fund request type includes `Trip Allowance`; statuses include `Pending Approval`, `Approved`, `Processing`, `Disbursed`, `Settled`; disbursement uses polymorphic `reference_type`/`reference_id`. Billing status includes `Draft`, `Issued`, `Partially Paid`, `Paid`, `Overdue`, `Cancelled`.
- **Payroll:** payroll period status includes `Draft`, `Prepared`, `Pending Approval`, `Approved`, `Disbursed`, `Closed`; payroll records have base, trip pay, allowance, deduction, and net amount columns; trip pay has a separate status (`Draft`, `Ready`, `Included in Payroll`, `Paid`, `Cancelled`).
- **Other constraints:** unique billing per trip and invoice number; unique active pending approval key; unique active trip-completion report; unique trip-pay row per trip/employee/pay type; unique inventory row per part; unique attendance per employee/date and unique open-shift generated value.

### Important foreign-key relationships

- `users.role_id -> roles.role_id`; `role_permissions` joins roles to permissions; `users.employee_id` and `employees.user_id` link logins/profiles.
- `trips` links to clients, bookings, nominations, routes, trucks, drivers, second drivers, helpers, and dispatcher employees. `dispatch_requests.trip_id -> trips.trip_id`; the live schema makes the dispatch/trip relation unique.
- `truck_assignments` links trucks to trips; inspection rows link to trips/trucks, findings, templates/items, and files/photos.
- `billings` link to trips and clients; billing items and collections link to billing/client records.
- Maintenance records link to trucks, incidents, inspections, suppliers, and users; maintenance parts link to a maintenance record and part; inventory rows/movements link to parts.
- Purchase requests/orders/items/receipts link through supplier, part, request, order, and user keys.
- Payroll records link to period and employee; deductions/payslips link to payroll records; payroll disbursements link to periods.
- Approval request history/steps link to approval request, configured workflow, role, and acting user.
- Polymorphic links such as `documents.entity_type/entity_id`, `expense_records.reference_type/reference_id`, `inventory_transactions.reference_type/reference_id`, `payment_vouchers.reference_type/reference_id`, and `disbursements.reference_type/reference_id` do not have a conventional FK to every possible target. Their integrity is partly application-enforced.

### Sanitized aggregate data

Exact `COUNT(*)` queries returned: 14 trips and 14 dispatch requests; 11 truck assignments; 8 trip deliveries; 6 approved workflow templates and 6 workflow steps; 5 billings, 5 billing items, and 3 collections; 14 trip-pay rows; 3 maintenance records; 4 parts, 4 inventory rows, and 4 inventory movement rows; 95 audit-log rows; 12 roles, 71 permissions, and 175 role-permission assignments. There are 3 inspection templates and 56 checklist items but **0 vehicle inspections**. Fund requests, approval requests/history, disbursements, liquidations, purchase records, payroll periods/records, budgets, documents, and files currently have zero rows. No record values or personal/financial details were queried for the report.

## 3. Database-to-code mapping

| Database area | Current code that reads/writes it | Notes |
|---|---|---|
| `users`, `employees`, `roles`, `permissions`, `role_permissions` | `includes/session.php`, `auth/login_handler.php`, `ajax/permissions_handler.php`, `pages/permissions.php`, dashboards/layout | Login status and role grants are revalidated; role permissions are loaded for server-side checks. |
| `dispatcher_assignments`, `dispatch_requests`, `trips`, `truck_assignments`, `client_bookings` | `includes/dispatcher_scope.php`, `includes/dispatch_assignment.php`, `ajax/submit_dispatch.php`, `ajax/review_dispatch.php`, `ajax/approval_decision_handler.php` | Dispatch submission and final approval coordinate trip, request, booking, truck/crew, approval, and audit writes. |
| `approval_workflows`, `approval_workflow_steps`, `approval_requests`, `approval_steps`, `approval_history` | `includes/approval_workflow.php`, `ajax/approval_decision_handler.php`, `ajax/review_dispatch.php`, `ajax/purchasing_handler.php`, `ajax/finance_handler.php` | Approval entity identity is stored as `entity_type` + `entity_id`; that link to the business row is not an FK. |
| `vehicle_inspections`, `inspection_*`, `trip_updates`, `trip_deliveries`, `trip_returns`, `trip_completion_reports`, `workflow_events` | `includes/trip_workflow.php`, `ajax/trip_workflow_handler.php`, `ajax/update_trip_status.php`, `ajax/trip_completion_handler.php`, `pages/trip_workflow.php` | Trip actions are status-gated; completed reports are distinct from official trip completion. |
| `billings`, `billing_items`, `collections`, `documents`, `files` | `ajax/billing_handler.php`, `pages/billing.php`, `ajax/billing_export.php`, `ajax/document_handler.php`, `ajax/document_download.php`, `includes/attachments.php` | Billing links to a trip with an FK; document-to-entity links use a polymorphic reference. |
| `fund_requests`, `disbursements`, `accounts_payable`, `payment_vouchers`, `cash_advance_liquidations` | `ajax/finance_handler.php`, `pages/finance.php`, `ajax/approval_decision_handler.php` | Fund requests and disbursements are implemented. No current PHP reference to `cash_advance_liquidations` was found. |
| `maintenance_records`, `maintenance_findings`, `maintenance_parts`, `preventive_maintenance_schedules`, `trip_incidents` | `ajax/maintenance_handler.php`, `pages/maintenance.php`, `pages/maintenance_reports.php`, `pages/operations_maintenance_report.php` | Work orders are maintenance records differentiated by `record_source`; parts issuance also updates inventory. |
| `parts`, `inventory`, `inventory_transactions`, `purchase_*`, `suppliers` | `ajax/parts_handler.php`, `ajax/purchasing_handler.php`, `pages/parts.php`, `pages/purchasing.php` | Receiving and stock movement use transaction boundaries; the purchase-item reference on stock movements is polymorphic. |
| `payroll_periods`, `payroll_records`, `payroll_disbursements`, `trip_pay_records` | `ajax/payroll_handler.php`, `ajax/trip_pay_handler.php`, `pages/payroll.php`, `pages/trip_pay.php`, `pages/payroll_slip.php` | Trip Pay is separate from periodic payroll; no automatic transfer from trip-pay rows to payroll records was found. |
| `budgets` | `ajax/budgets_handler.php` | The endpoint supports listing/upserting monthly budget entries; no separate budget page, dashboard variance, or actual-vs-budget report reference was found in the current application paths. |
| `audit_logs`, `workflow_events`, `truck_assignments` status history | `includes/audit.php`, `includes/workflow_events.php`, `includes/truck_status_history.php`, `pages/audit_history.php` | Audit records capture actions; generic entity IDs are not FK-constrained. |
| Dashboard/report/export data | `pages/dashboard*.php`, `analytics/*.php`, `pages/dispatcher_reports.php`, `pages/operations_maintenance_report.php`, `ajax/*_export.php`, `includes/finance_records.php` | Three views exist in MariaDB but appear unused; current reports query tables directly. |

## 4. Database discrepancy matrix

| Database object | Repository definition | Actual database state | Application usage | Difference | Risk | Status |
|---|---|---|---|---|---|---|
| Target schema object inventory | `db/reference/mulawin_fleetops_new.sql` defines 70 tables and 3 views; the ordered target migrations create 13 more tables. | 83 tables + 3 views; no target table missing and no unexplained table found. All tables are InnoDB. | Current PHP uses target-schema objects. | Object counts and names match exactly. | Low | **Database verified** |
| Columns, enums, generated columns | Target reference plus ordered migrations. | All 83 tables and columns matched the model on names, base types, signedness, nullability, enums, auto-increment, and generated status, with two MariaDB-specific comparison notes: JSON is represented as LONGTEXT; a parser misreads `IS NOT NULL` inside the nullable virtual attendance expression. The live attendance column is nullable as intended. | Current queries use the target names and fields. | No substantive column discrepancy identified. The comparison did not normalize every default literal across all columns. | Low | **Database + code verified**, default-value comparison partially verified |
| FKs and explicit indexes | Target reference and target migrations. | 149 FK mappings match the model exactly; all explicit named index definitions match. The model also counts implicit single-column FK-support indexes that are covered in MariaDB by existing unique/composite indexes. | Relationship joins and unique guards use these constraints. | No missing/extra FK mapping or explicit index found. | Low | **Database verified** |
| Scoped report and allowance permissions | `db/target_migrations/20261015_tA2_scoped_reports_and_allowance_permissions.sql` defines three permissions and role assignments. | All three permission keys exist. `dispatch.reports.view` and `finance.trip_allowance.request` are granted to both dispatcher roles and Admin; `maintenance.reports.operations.view` is granted to Operations Head and Admin. Audit has the read-only billing/finance/payroll view grants defined in the migration. | `pages/dispatcher_reports.php`, `ajax/finance_handler.php`, `pages/trip_workflow.php`, and `pages/operations_maintenance_report.php` check these permissions. | Live grant effects match the SQL definitions; the database alone cannot prove which execution applied them. | Low | **Database + code verified** |
| Target migration execution | `db/target_migrations/` contains 22 files; 21 are in the schema checker's recommended model order. `20261015_tA2...` is not in that order. | Target objects and the T-A2 permission effects are present. | Current code depends on the target columns and permissions. | No migration-history table exists; exact execution order cannot be proven. `schema_check.php --drafts=all --lint-drafts --no-scan` emits one warning that T-A2 is not in the recommended order list. | Medium (repeatable deployment/audit trail) | **Partially verified** |
| Legacy root SQL/migrations | `db/mulawin_fleetops_db.sql` and several `db/*_migration.sql` files retain older schema assumptions. | Live DB uses the new target schema. For example, the live table is `attendance_records`, not legacy `employee_attendance`; live trip completion reports use `dispatch_request_id` via trip, not the legacy `dispatch_id`; live `users` has no `full_name` column. | Current code targets the new schema. | The root attendance, trip-completion, and employee-link scripts are not interchangeable with target migrations and are not evidenced as applied. | High if mistakenly applied to this database | **Database + code verified** |
| Hard-coded role IDs | `config/app.php` comments say role IDs must match `roles`. | Live IDs are Admin 1, Operations Head 2, Car Carrier Dispatcher 3, Container & Wing Van Dispatcher 4, Maintenance 5, Purchasing 6, Finance 7, Billing 8, Payroll 9, Audit 10, Driver 11, Helper 12. | Current checked authorization generally uses `roles.system_key` and permission keys; the `requireRole()` helper translates old numeric values to `legacy.role.N`. No active route call to `requireRole()` was found. | Several `ROLE_*` constants (for example Operations Head 5 and Maintenance 3) do not equal live role IDs. No current bypass was demonstrated, but the source comment/config is stale and future numeric checks could misauthorize. | Medium | **Database + code verified** |
| Trip completion permission | `permissions` grants `trips.complete` to Driver. | Driver role has one grant; Helper has none. | `ajax/trip_completion_handler.php` calls `requireLogin()` but does not check `trips.complete`; it requires an active linked employee with driver position and requires that employee to be assigned to the trip. | Endpoint authorization is assignment/profile-based rather than permission-based; a qualifying non-Driver account would not be denied by the declared permission check because no such check is made. | Medium | **Database + code verified** |
| Completed trip inspections / workflow history | Current workflow code requires inspection checks and records workflow events. | 7 trips have status `Completed`; `vehicle_inspections` and `workflow_events` each have 0 rows. | `includes/trip_workflow.php` requires completed departure checks before ready-for-dispatch and arrival checks for completion; current UI explicitly notes old trips may predate the 13-step workflow. | Existing completed records have no inspection evidence or event timeline in this DB. This may be historical/demo data rather than a current bypass. | Medium (auditability/operational assurance) | **Database + code verified**, origin **not verified** |
| Liquidation, budget, and views | `cash_advance_liquidations`, `budgets`, and three views are defined in target schema. | Liquidations and budgets have 0 rows; all three views exist. | No PHP code references liquidation table or view names. Budget handler is present, but no budget page/report consumer was found. | Schema support is ahead of current feature implementation; no end-to-end behavior demonstrated. | Medium (workflow completeness) | **Database + code verified** |
| Connected database account | Application configuration supplies credentials from `.env`; exact account identity is omitted. | Current connected principal has broad global privileges and `IS_GRANTABLE=YES` on the privilege set, not just this schema. | All application DB access uses this connection factory. | Application DB credential has server-wide capability beyond routine app DML. | High if deployed beyond local test; moderate in isolated test | **Database + code verified** |

The static schema checker parsed all target tables and scanned 123 PHP files: **0 confirmed, 0 uncertain, and 0 dynamic SQL/schema findings** against the repository target model. This is a code-to-model check, not proof of a workflow at runtime. Unit and static tests are documented under “Evidence and limitations.”

## 5. Workflow data lineage

### Dispatch request -> approval -> assignment -> trip

- **Source/destination:** `submit_dispatch.php` creates `trips` (`trip_id`) in `Pending Approval`, then `dispatch_requests` (`dispatch_request_id`) in `Pending`; the latter has an FK to the trip and a unique constraint per trip.
- **Approval:** `createApprovalRequest()` creates an `approval_requests` record for entity type `dispatch_request` and the request ID, copies configured roles into `approval_steps`, and records history. `approval_requests.entity_type/entity_id` is an application-level link, not an FK.
- **Decision:** `review_dispatch.php` and `approval_decision_handler.php` update approval/request status, trip status, truck assignment/status history, and notifications in a transaction. Final approval changes the trip to `Approved`; `trip_workflow_handler.php` confirms assignment (`Assigned`), prepares departure (`Ready for Dispatch`), and requires dispatch clearance before departure. Rejection cancels the trip.
- **Observed states:** 14 requests and 14 trips; status pairs are 2 Pending/Pending Approval, 11 Approved with their trips at Assigned or later, and 1 Cancelled/Cancelled. No missing dispatch-to-trip link was found.
- **Integrity:** Related changes are transaction-protected with row locks/idempotency on the main paths. **Database and code verified.**

### Trip -> truck/crew -> inspection -> delivery/completion

- **Source/destination:** `trips` references truck, driver, second driver, helper, dispatcher employee, client, booking/nomination, and route. `truck_assignments` records truck/time/status. Inspection, delivery, return, incident, trip-update, and completion-report tables link to trip and/or truck.
- **Code/statuses:** `trip_workflow_handler.php`, `update_trip_status.php`, `trip_completion_handler.php`, and `includes/trip_workflow.php` lock/update trips and use conditional status transitions. Required departure template items must be `Good` in a completed `Passed` inspection; arrival requires completed findings for each active required item. Driver completion reports are separate (`Pending`, `Acknowledged`, `Rejected`) and do not themselves make the trip officially Completed.
- **Observed state:** 14 trips include 7 Completed; 0 vehicle inspections, 0 trip completion reports, 0 trip updates, and 0 workflow events. The 7 completed trips therefore have no live departure-inspection evidence. This cannot establish when/how those rows were created; current page copy says pre-workflow history is not reconstructed.
- **Integrity:** Current transition paths are transactional; the existing rows' pre-change history is incomplete. **Database + code verified; historical origin not verified.**

### Completed trip -> supporting documents -> invoice -> collection

- **Source/destination:** `trips` -> `billings` (unique FK by `trip_id`, also client FK) -> `billing_items`; collections link to both billing and client. Files/documents can be associated with entities through `document_links.entity_type/entity_id`, which is polymorphic and not FK-enforced.
- **Code/statuses:** `ajax/billing_handler.php` and `pages/billing.php` manage invoice/payment records; exports read billing/collection tables. Billing states include Draft, Issued, Partially Paid, Paid, Overdue, Cancelled.
- **Observed state:** 5 billing records and 5 billing items; billing statuses: 2 Issued, 1 Partially Paid, 2 Paid; 3 collections. No orphan billing-to-trip link was found. Documents/files are empty, so supporting document lineage cannot be verified in the live data.
- **Integrity:** Billing/collection paths use linked identifiers and handler transactions; document links remain application-level. **Database + code verified; supporting-document execution not verified.**

### Trip Allowance request -> approval -> Finance disbursement -> liquidation

- **Source/destination:** `fund_requests` has `trip_id`, `requested_by`, request type, amount, and status; it links to `trips` and `users`. The generic approval points back by `entity_type/entity_id`; `disbursements` uses `reference_type/reference_id`. `cash_advance_liquidations` links to the fund request by FK.
- **Code/statuses:** Dispatcher requests are accepted only under `finance.trip_allowance.request`, for the submitting dispatcher’s own trip in `Assigned` state and within current dispatcher scope. `ajax/finance_handler.php` creates the fund request and approval in one transaction with idempotency; approval updates it to Approved/Rejected; Finance disbursement updates fund/disbursement status transactionally. Recognized states include Pending Approval, Approved, Processing, Disbursed, Settled. No current handler/page code references `cash_advance_liquidations`, so no liquidation transition or payment/receipt reconciliation is implemented in the application.
- **Observed state:** 0 fund requests, approval requests, disbursements, and liquidations. The data path is **Code verified** but not exercised against live business rows.
- **Integrity:** Fund/approval and disbursement references are polymorphic and not database-FK constrained. Transaction boundaries protect creation/approval/payment writes; no code path establishes Settled through a liquidation.

### Maintenance finding/work order -> parts -> inventory -> truck availability

- **Source/destination:** Incidents/inspections can link to `maintenance_records`; `maintenance_findings` link to the maintenance record; `maintenance_parts` link to the job and `parts`; inventory/transactions link to parts. Maintenance records link to trucks.
- **Code/statuses:** `ajax/maintenance_handler.php` creates and transitions records/work orders; `ajax/parts_handler.php` uses transactions to update stock, write `inventory_transactions`, and record issued `maintenance_parts`. Work-order states are Reported, Scheduled, In Progress, Waiting for Parts, Completed, Cancelled. Truck status/availability updates are handled by fleet status code and maintenance workflow.
- **Observed state:** 3 maintenance records: 1 in-progress work order, 1 completed work order, and 1 scheduled PM-service record; 4 parts, 4 inventory rows, and 4 inventory transactions; no maintenance parts/findings, vehicle inspections, or incidents. All four current inventory movements have `reference_type='demo'`; this is not a typed FK and does not prove a corresponding purchase or maintenance source. No expense-to-trip orphan was found (21 trip-referenced expense records, 0 missing trips).
- **Integrity:** Main stock updates are transactional; polymorphic movement references can be orphaned or refer to nonstandard source labels. **Database + code verified.**

### Purchase request/order -> supplier -> receiving -> stock/cost

- **Source/destination:** `purchase_requests` -> `purchase_orders` -> order items and `purchase_receipts`; items link to parts, orders to supplier, receipts to order. Receipt movements reference order items through `inventory_transactions.reference_type/reference_id`, with stock changed in `inventory`.
- **Code/statuses:** `ajax/purchasing_handler.php` creates request/order and approval, then receives goods under a transaction, writes inventory movements, updates stock, and transitions order to Partially Received/Received and request to Completed. The approval handler sets purchase request status to Approved/Rejected/Ordered.
- **Observed state:** No supplier or purchase request/order/item/receipt rows. No actual purchase-to-stock lineage can be tested.
- **Integrity:** Transactional for create/approval/receipt paths; movement-to-order-item link is polymorphic. **Code verified; data lineage not verified.**

### Payroll/trip pay -> Finance payment

- **Source/destination:** `payroll_periods` -> `payroll_records` -> `payroll_disbursements`; deductions and payslips link to payroll records. `trip_pay_records` separately link to trip and employee.
- **Code/statuses:** `ajax/payroll_handler.php` records periodic payroll, deductions, payment, and audit data in one transaction. `ajax/trip_pay_handler.php` upserts per-trip crew pay under a unique trip/employee/pay-type key and logs it as paid; it is separate from periodic payroll. `payroll_records.trip_pay` and `allowance_amount` are numeric fields, not FK links to trip-pay/fund-request rows; the periodic payroll handler explicitly enters zero trip pay for its payroll records.
- **Observed state:** 14 trip-pay rows (8 Ready, 6 Paid); 0 payroll periods, payroll records, or payroll disbursements. No automatic transfer of trip-pay or approved Trip Allowance records into payroll was found.
- **Integrity:** Trip-pay writes and payroll writes are transactional independently; no automatic cross-module handoff exists. **Database + code verified.**

### Budget -> actual financial records -> variance report

- **Source/destination:** `budgets` stores category, month, amount, and `set_by`; it does not store links to actual expenses/invoices/payroll.
- **Code:** `ajax/budgets_handler.php` supports list and multi-entry upsert; there is no current page/report reference joining budget entries to actuals. The multi-entry save loops through independent upserts without an explicit transaction, so an error can leave a partial batch; audit logging occurs after the loop.
- **Observed state:** 0 budget rows.
- **Integrity:** Not transactionally atomic for a multi-entry save; no variance workflow is implemented. **Database + code verified.**

### Operational/financial audit and historical records

- `audit_logs` stores user/action/entity/old/new values and attribution, with an FK to `users` but generic entity ID. `workflow_events`, trip updates, truck assignments/status history, approval history, and archived `deleted_records` represent separate histories.
- Current code writes audit rows through `includes/audit.php`; critical workflow handlers generally write audit records inside the same transaction. Failure to write audit data can therefore roll back those writes.
- Live counts: 95 audit logs, 0 workflow events, 0 approval history, 0 deleted records. The empty workflow/approval histories mean historical state transitions cannot be reconstructed from those tables for the existing records.

## 6. Role and permission verification

### Actual RBAC inventory

All 12 roles were active and used `system_key`; permission counts from the live `role_permissions` join were:

| Role | Live role ID | Permission count | Relevant boundary observed |
|---|---:|---:|---|
| Admin | 1 | 71 | Has all 71 current permissions; `isAdmin()` also grants permission checks through the validated `admin` system key. |
| Operations Head | 2 | 19 | Operations, approvals, dispatch clearance, trip/report view, and limited maintenance report; no general Finance/Payroll grants. |
| Car Carrier Dispatcher | 3 | 14 | Scoped dispatch and trip permissions; `dispatch.reports.view` and `finance.trip_allowance.request`; no general Finance fund/disbursement management. |
| Container & Wing Van Dispatcher | 4 | 14 | Same scoped report/allowance boundary as Car Carrier Dispatcher. |
| Maintenance | 5 | 11 | Maintenance, parts view, and fleet/incident functions; no finance/payroll grants. |
| Purchasing Officer | 6 | 8 | Purchasing and parts/catalog permissions; no finance disbursement/payroll grants. |
| Finance | 7 | 15 | Finance, funds, disbursements, AP, budget, and billing view; not billing management or payroll management. |
| Billing and Collection | 8 | 8 | Billing/collection management and client permissions; no finance disbursement or payroll grants. |
| Payroll | 9 | 7 | Payroll and attendance permissions. |
| Audit | 10 | 7 | Audit plus read-only billing, Finance, AP, funds, disbursements, and payroll view grants; no corresponding manage grants. |
| Driver | 11 | 1 | `trips.complete`. |
| Helper | 12 | 0 | No role grants; application has a special read-only own-trip path. |

There are 71 permission definitions and 175 role-permission assignments. The old `legacy.role.1`–`legacy.role.4` keys remain assigned to Admin and selected Maintenance/Finance roles as compatibility grants; the target RBAC migration explicitly describes these as legacy-page access, and the dispatcher legacy grant is intentionally absent.

### Enforcement and navigation

- `includes/session.php` revalidates account/role activity and `auth_version`; Admin identity comes from the validated `roles.system_key`, not a role name. `requirePermission()`/`requireAnyPermission()` consult live role grants; Admin passes every current/future permission check.
- `includes/layout.php` conditions navigation on permissions. The audited report pages and finance/dispatch handlers also perform server-side checks; navigation visibility is not the only enforcement.
- **Scoped reports:** `pages/dispatcher_reports.php` requires `dispatch.reports.view`, allows only Admin or the two dispatcher system keys, and limits dispatcher rows to requests they submitted plus their current shift/truck-type scope. The page explicitly says historical records are filtered using the current assignment.
- **Trip allowance:** `pages/trip_workflow.php` exposes the action only to dispatcher roles holding the grant. The direct finance handler independently enforces the grant, Trip Allowance type, own submitted trip, Assigned status, current scope, and one active request check inside a transaction.
- **Limited maintenance report:** `pages/operations_maintenance_report.php` requires `maintenance.reports.operations.view` and additionally restricts non-Admin access to Operations Head.
- **Driver/helper record-level access:** helpers have no RBAC grant but `includes/crew_trip_access.php` restricts trip reads to their linked employee assignment and refuses helper access to unrelated endpoints. The trip completion endpoint has a separate profile/assignment check but omits `trips.complete` as described in the discrepancy matrix.
- The live permission migration grants match their SQL definitions. **Database + code verified.** Role-specific browser sessions were not available, so visual navigation/endpoint behavior was not exercised as each role.

## 7. Data integrity risks and follow-up

1. **Global DB grants:** Use a dedicated account restricted to the application schema and only required runtime privileges before any production deployment. No grants were changed as part of this audit.
2. **Inspection provenance:** Investigate how the seven Completed trips reached that state and whether they predate the current inspection gate. The existing test database has no inspection records or workflow events for them. Do not infer a present-day bypass from these counts alone.
3. **Trip completion RBAC:** Align the endpoint with `trips.complete` or document an explicit alternative authorization policy and cover it with tests. This audit made no code changes.
4. **Allowance liquidation:** Implement or explicitly retire the liquidation tables/workflow; currently the schema suggests a path that the application does not use.
5. **Migration discipline:** Keep the target schema/migrations separate from root legacy SQL. Update the target migration order/ledger so the T-A2 permission migration has an auditable place. Do not apply the legacy attendance, trip completion, or employee-link migrations to this target DB.
6. **Role ID constants:** Align or remove stale role ID constants/comments after confirming no hidden external callers depend on them; current key-based authorization is the active pattern.
7. **Budget batch atomicity:** If multi-entry updates are intended to be all-or-nothing, wrap the writes and audit event in a transaction and validate/reject invalid rows rather than partially saving. No budget records currently exist.
8. **Polymorphic references:** Consider consistency checks or explicit link tables for generic entity references where a dangling link would affect payment, document, or inventory reconciliation.

## 8. Evidence and limitations

- **Database verified:** Effective target database, server version/port, table/view inventory, full column metadata, enum definitions, foreign-key mappings, explicit indexes, role grants, global account privilege metadata, safe aggregate counts, status totals, selected orphan counts, absence of triggers/routines/events, and the seven Completed trips without departure inspection rows.
- **Code verified:** Connection factory/singleton, handlers and page authorization for the scoped reports, allowance flow, dispatch/approval, trip inspections/completion, finance/billing, maintenance, purchasing/inventory, payroll, budgets, and audit paths. Polymorphic application links and transaction boundaries are identified above.
- **Test verified:** `tests/unit/run.php` passed 96 tests. `tests/static/run.php` passed; this includes 254 billing-period checks and static SQL/dashboard/report checks. `scripts/schema_check.php --self-test` passed 24 checks. Full static scan against the target schema model scanned 123 PHP files with 0 confirmed, 0 uncertain, and 0 dynamic findings. Draft lint found 0 errors and one warning: T-A2 is not in the recommended order list.
- **Not a live workflow test:** The test suite and schema checker did not write to or exercise business workflows in the live database. The database was inspected with read-only queries only.
- **Partially verified:** A local web request returned HTTP 200 and the exact application PDO configuration connected via PHP CLI, but no authenticated web request was made to read application data. Role-specific visual navigation was not browser-tested.
- **Not verified:** Production/staging configuration, the legacy `mulawin_fleetops` database (the repository instructions say never to connect/import/migrate it during test setup), external hosted database privileges, the provenance of current test records, backup/restore health, or whether any manual process outside the PHP code uses currently unreferenced tables.
- The worktree contained numerous pre-existing modified/untracked files before the audit. This audit added only this report; no existing application code, migrations, data, or grants were changed.
