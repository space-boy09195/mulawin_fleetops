-- ============================================================
-- P21-11: RBAC seed for the NEW implementation (DRAFT FOR REVIEW)
-- File: db/target_migrations/20261011_p21_rbac_seed_review_only.sql
--
-- STATUS: NOT EXECUTED and NOT APPROVED FOR EXECUTION.
--   This file is a review draft only. Saving it is NOT approval to run it.
--   Execute it only after explicit approval, against a disposable scratch
--   copy of the target schema first, with a backup taken beforehand.
--
-- TARGET DATABASE: mulawin_fleetops_new ONLY.
--   Do NOT apply to the legacy database (mulawin_fleetops).
--   This file lives outside db/*_migration.sql so the legacy setup step
--   never picks it up. It contains no USE statement: the operator must
--   select the target database explicitly before running it.
--
-- PURPOSE:
--   Seed the 12 approved roles (identified by roles.system_key), the
--   permission catalogue used by the application, and least-privilege
--   grants. Role IDs are never hard-coded. Legacy numeric IDs and legacy
--   grants are NOT copied.
--
-- WHAT IT DOES:
--   1. Inserts each of the 12 roles only if no role already has that
--      system_key AND no role already has that role_name.
--   2. Inserts each permission only if no permission already has that
--      permission_key. Existing permission rows are never changed.
--   3. Inserts each grant only if that role/permission pair is missing.
--      Admin receives every permission present in the permissions table.
-- WHAT IT NEVER DOES:
--   No UPDATE, DELETE, TRUNCATE, DROP or ALTER. It does not change any
--   existing role, permission, grant, user, user role assignment or
--   password. It does not assign a system_key to an existing role. It does
--   not create user accounts.
--
-- DEPENDENCIES (all must be applied and verified first):
--   - The target schema (db/reference/mulawin_fleetops_new.sql): tables
--     roles, permissions, role_permissions.
--   - P21-7M (db/target_migrations/20261010_p21_roles_system_key.sql):
--     adds roles.system_key. WITHOUT IT THE FIRST INSERT FAILS with an
--     unknown-column error and the transaction must be rolled back.
--   - Independent of drafts 1A, 1B, S-36 and P-21M.
--
-- ROLE KEYS (lowercase, stable):
--   admin, operations_head, car_carrier_dispatcher,
--   container_wing_van_dispatcher, maintenance, purchasing_officer,
--   finance, billing_collection, payroll, audit, driver, helper
--
-- SINGLE-HOLDER FLAGS:
--   0 (multi-holder, approved): both dispatcher roles, maintenance,
--     driver, helper.
--   1 for the others: PENDING STAFFING VERIFICATION. The application does
--     not read is_single_holder yet, so this flag has no effect until code
--     enforces it. Review before execution.
--
-- NEW PERMISSION KEYS (not in the legacy catalogue):
--   Checked by code (Phases 1 and 3): billing.export, trips.export,
--   budgets.manage.
--   Not yet checked by code: finance.expenses.edit, parts.catalog.manage.
--
-- REPORTS.VIEW (updated 2026-10-14, Option B decision 5):
--   Granted to admin (all permissions) and operations_head. Until the B7
--   analytics change lands, pages/analytics.php still selects sections by
--   numeric role ID, so Operations Head sees only the shared sections.
-- LEGACY KEYS DELIBERATELY NOT SEEDED (no code checks them):
--   dispatch.shift.day, dispatch.shift.night, dispatch.vehicle.car_carrier,
--   dispatch.vehicle.container, dispatch.vehicle.wing_van, messages.view,
--   messages.send, users.view, employees.view, routes.view, trips.approve
--
-- PRIVILEGED KEYS (granted to admin ONLY):
--   legacy.role.1, permissions.manage, roles.manage, users.manage,
--   approvals.manage
--
-- KNOWN GAPS UNTIL CODE BATCHES LAND (see the P21-11 report):
--   - Trip pay (resolved): ajax/trip_pay_handler.php requires payroll.manage
--     (Payroll owns trip pay; Finance has no trip-pay access). The payroll
--     role below reaches it through the dedicated pages/trip_pay.php page
--     (Accounting > Trip Pay, payroll.manage); pages/trip_costs.php stays
--     Finance-only (finance.view/finance.manage).
--   - Exports (resolved, Phase 3): ajax/billing_export.php requires
--     billing.export; ajax/trip_operations_export.php requires
--     trips.export. reports.export is no longer checked by any export.
--   - finance.expenses.edit and parts.catalog.manage are not checked by
--     code yet. ajax/trip_completion_handler.php enforces trips.complete.
--   - ROLE-MAPPING SAFEGUARDS (unchanged): this file never assigns a key
--     to an existing role, never changes users or user role assignments,
--     and pre-check c is a STOP condition. Legacy and unknown roles are
--     preserved; any user remapping is a separate, per-user approval.
--   - legacy.role.2 is granted to NO department role because it would let
--     dispatchers change truck status through ajax/update_truck_status.php.
--
-- NOTE: SQL examples in these comments intentionally have no statement
--   terminators, so no tool that splits on terminators can turn a comment
--   into an executable statement.
--
-- READ-ONLY PRE-CHECKS (run first, expected result in brackets):
--   a) Correct database                                  -- [mulawin_fleetops_new]
--        SELECT DATABASE()
--   b) roles.system_key exists (P21-7M applied)          -- [1 row]
--        SELECT COLUMN_NAME FROM information_schema.COLUMNS
--         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles'
--           AND COLUMN_NAME = 'system_key'
--   c) Existing roles that use one of the 12 names but no key. ANY ROW
--      MEANS STOP: the seed would skip that role. Assigning a key to an
--      existing role needs a separate, business-approved step.
--        SELECT role_id, role_name, system_key FROM roles
--         WHERE system_key IS NULL AND LOWER(TRIM(role_name)) IN
--           ('admin','operations head','car carrier dispatcher',
--            'container & wing van dispatcher','maintenance',
--            'purchasing officer','finance','billing and collection',
--            'payroll','audit','driver','helper')
--   d) Keys already in use (information, compare with the 12 keys)
--        SELECT role_id, role_name, system_key, is_active FROM roles
--         WHERE system_key IS NOT NULL ORDER BY system_key
--   e) Existing permissions (information, existing rows are kept as-is)
--        SELECT permission_key, permission_name, module FROM permissions
--         ORDER BY permission_key
--   f) Existing grants (information, never removed by this file)
--        SELECT COUNT(*) FROM role_permissions
--
-- POST-CHECKS (after COMMIT):
--   - All 12 keys present and active          -- [12 rows, is_active 1]
--       SELECT system_key, role_name, is_active FROM roles
--        WHERE system_key IN ('admin','operations_head',
--          'car_carrier_dispatcher','container_wing_van_dispatcher',
--          'maintenance','purchasing_officer','finance',
--          'billing_collection','payroll','audit','driver','helper')
--   - Privileged keys held only by admin      -- [only system_key admin]
--       SELECT p.permission_key, r.role_name, r.system_key
--         FROM role_permissions rp
--         JOIN roles r ON r.role_id = rp.role_id
--         JOIN permissions p ON p.permission_id = rp.permission_id
--        WHERE p.permission_key IN ('legacy.role.1','permissions.manage',
--          'roles.manage','users.manage','approvals.manage')
--   - Grant counts per key: compare with the P21-11 matrix
--       SELECT r.system_key, COUNT(*) FROM role_permissions rp
--         JOIN roles r ON r.role_id = rp.role_id
--        GROUP BY r.system_key ORDER BY r.system_key
--
-- RERUN / IDEMPOTENCY:
--   Every INSERT is guarded by NOT EXISTS, so a rerun inserts only what is
--   missing and changes nothing else. Pre-existing grants are not removed,
--   so a rerun does NOT revoke over-broad grants added by other means.
--   Review post-checks after every run.
--
-- TRANSACTIONS AND FAILURE:
--   The statements are DML on InnoDB inside one transaction. Run with a
--   client that stops on the first error (the mysql client default without
--   --force). If any statement fails, issue ROLLBACK before disconnecting
--   and investigate. Nothing is committed until the final COMMIT.
--
-- ROLLBACK AFTER COMMIT (only with explicit approval, and only if no
-- users were assigned to the new roles yet): delete the grants and roles
-- whose system_key is one of the 12 keys, then the permissions this file
-- inserted. Restoring the pre-run backup is the safer option.
-- ============================================================

START TRANSACTION;

-- ------------------------------------------------------------
-- 1. Roles (only when neither the key nor the name exists)
-- ------------------------------------------------------------
INSERT INTO roles (role_name, system_key, description, is_single_holder, is_active)
SELECT v.role_name, v.system_key, v.description, v.is_single_holder, 1
FROM (
              SELECT 'Admin' AS role_name, 'admin' AS system_key,
                     'Top-level authority with cross-department access' AS description,
                     1 AS is_single_holder
    UNION ALL SELECT 'Operations Head', 'operations_head',
                     'Trip operations oversight, dispatch planning and clearance', 1
    UNION ALL SELECT 'Car Carrier Dispatcher', 'car_carrier_dispatcher',
                     'Dispatch for car carrier trucks within assigned shifts', 0
    UNION ALL SELECT 'Container & Wing Van Dispatcher', 'container_wing_van_dispatcher',
                     'Dispatch for container and wing van trucks within assigned shifts', 0
    UNION ALL SELECT 'Maintenance', 'maintenance',
                     'Maintenance records, inspections and truck status', 0
    UNION ALL SELECT 'Purchasing Officer', 'purchasing_officer',
                     'Supplier, purchase order and parts functions', 1
    UNION ALL SELECT 'Finance', 'finance',
                     'Financial expenses, funds, payables and monitoring', 1
    UNION ALL SELECT 'Billing and Collection', 'billing_collection',
                     'Billing, collections and client billing data', 1
    UNION ALL SELECT 'Payroll', 'payroll',
                     'Payroll, trip pay and attendance', 1
    UNION ALL SELECT 'Audit', 'audit',
                     'Read-only audit logs and approved reports', 1
    UNION ALL SELECT 'Driver', 'driver',
                     'Self-service attendance and assigned trip completion', 0
    UNION ALL SELECT 'Helper', 'helper',
                     'Self-service attendance', 0
) v
WHERE NOT EXISTS (SELECT 1 FROM roles r WHERE r.system_key = v.system_key)
  AND NOT EXISTS (SELECT 1 FROM roles r WHERE r.role_name = v.role_name);

-- ------------------------------------------------------------
-- 2. Permission catalogue (only keys that do not exist yet)
-- ------------------------------------------------------------
INSERT INTO permissions (permission_key, permission_name, module, description)
SELECT v.permission_key, v.permission_name, v.module, v.description
FROM (
              SELECT 'admin.supplies.manage' AS permission_key,
                     'Manage Office Supplies Inventory' AS permission_name,
                     'admin' AS module,
                     'Manage office supplies inventory' AS description
    UNION ALL SELECT 'admin.supplies.view', 'View Office Supplies Inventory', 'admin', 'View office supplies inventory'
    UNION ALL SELECT 'approvals.manage', 'Configure Approval Chains', 'approvals', 'Configure approver roles and approval order'
    UNION ALL SELECT 'approvals.review', 'Review Approval Requests', 'approvals', 'Review assigned approval requests'
    UNION ALL SELECT 'audit.view', 'View Audit History', 'audit', 'View audit history'
    UNION ALL SELECT 'billing.export', 'Export Billing Data', 'billing', 'Export billing and collection records'
    UNION ALL SELECT 'billing.manage', 'Manage Billing & Collections', 'billing', 'Manage billing and collection records'
    UNION ALL SELECT 'billing.view', 'View Billing & Collections', 'billing', 'View billing and collection records'
    UNION ALL SELECT 'budgets.manage', 'Manage Budgets', 'finance', 'Create and update budgets'
    UNION ALL SELECT 'clients.manage', 'Manage Clients', 'clients', 'Manage client records'
    UNION ALL SELECT 'clients.view', 'View Clients', 'clients', 'View client records'
    UNION ALL SELECT 'company.dashboard.view', 'View Company Dashboard', 'company', 'View the company-wide management dashboard'
    UNION ALL SELECT 'dispatch.clear', 'Grant Dispatch Clearance', 'dispatch', 'Grant dispatch clearance'
    UNION ALL SELECT 'dispatch.instructions.encode', 'Encode Dispatch Instructions', 'dispatch', 'Encode Operations Head dispatch instructions'
    UNION ALL SELECT 'dispatch.instructions.manage', 'Manage Shift Dispatch Instructions', 'dispatch', 'Create and cancel shift dispatch instructions'
    UNION ALL SELECT 'documents.download', 'Download Authorized Documents', 'documents', 'Download authorized documents'
    UNION ALL SELECT 'documents.manage', 'Manage Document Records', 'documents', 'Manage document records'
    UNION ALL SELECT 'documents.upload', 'Upload Documents', 'documents', 'Upload documents'
    UNION ALL SELECT 'documents.view', 'View Authorized Documents', 'documents', 'View authorized documents'
    UNION ALL SELECT 'employees.manage', 'Manage Employee & Crew Records', 'employees', 'Manage employee and crew records'
    UNION ALL SELECT 'finance.ap.manage', 'Manage Accounts Payable & Vouchers', 'finance', 'Create accounts payable and payment vouchers'
    UNION ALL SELECT 'finance.ap.view', 'View Accounts Payable & Vouchers', 'finance', 'View accounts payable and payment vouchers'
    UNION ALL SELECT 'finance.disbursements.manage', 'Record Approved Disbursements', 'finance', 'Record approved disbursements'
    UNION ALL SELECT 'finance.disbursements.view', 'View Disbursement Records', 'finance', 'View disbursement records'
    UNION ALL SELECT 'finance.expenses.edit', 'Edit Trip Expenses', 'finance', 'Edit recorded trip expenses with audit logging'
    UNION ALL SELECT 'finance.funds.manage', 'Manage Fund Requests & Cash Advances', 'finance', 'Create and manage fund requests and cash advances'
    UNION ALL SELECT 'finance.funds.view', 'View Fund Requests & Cash Advances', 'finance', 'View fund requests and cash advances'
    UNION ALL SELECT 'finance.manage', 'Manage Finance Records', 'finance', 'Manage finance records'
    UNION ALL SELECT 'finance.view', 'View Finance Records', 'finance', 'View finance records and reports'
    UNION ALL SELECT 'fleet.manage', 'Manage Fleet', 'fleet', 'Create and update fleet records'
    UNION ALL SELECT 'fleet.status.update', 'Update Vehicle Status', 'fleet', 'Update vehicle availability and status'
    UNION ALL SELECT 'fleet.view', 'View Fleet', 'fleet', 'View fleet records and availability'
    UNION ALL SELECT 'hr.attendance.manage', 'Record & Update Attendance', 'hr', 'Record and update employee attendance'
    UNION ALL SELECT 'hr.attendance.report', 'View & Export Attendance Reports', 'hr', 'View and export attendance reports'
    UNION ALL SELECT 'hr.attendance.view', 'View Attendance & Timekeeping', 'hr', 'View employee attendance and timekeeping'
    UNION ALL SELECT 'hr.recruitment.manage', 'Manage Recruitment Candidates', 'hr', 'Create and update recruitment candidates'
    UNION ALL SELECT 'hr.recruitment.view', 'View Recruitment Candidates', 'hr', 'View recruitment candidates'
    UNION ALL SELECT 'incidents.manage', 'Report & Resolve Trip Incidents', 'incidents', 'Report and resolve trip incidents'
    UNION ALL SELECT 'legacy.role.1', 'Legacy Admin Page Access', 'legacy', 'Access granted to legacy Head Management surfaces'
    UNION ALL SELECT 'legacy.role.2', 'Legacy Dispatcher Page Access', 'legacy', 'Access granted to legacy Dispatcher surfaces'
    UNION ALL SELECT 'legacy.role.3', 'Legacy Maintenance Page Access', 'legacy', 'Access granted to legacy Maintenance surfaces'
    UNION ALL SELECT 'legacy.role.4', 'Legacy Accounting Page Access', 'legacy', 'Access granted to legacy Accounting surfaces'
    UNION ALL SELECT 'maintenance.manage', 'Manage Maintenance Records', 'maintenance', 'Create and update maintenance records'
    UNION ALL SELECT 'maintenance.reports.view', 'View Maintenance Reports', 'maintenance', 'View maintenance downtime and cost reports'
    UNION ALL SELECT 'maintenance.view', 'View Maintenance Records', 'maintenance', 'View maintenance history and jobs'
    UNION ALL SELECT 'operations.dashboard.view', 'View Operations Head Dashboard', 'operations', 'View the Operations Head dashboard'
    UNION ALL SELECT 'operations.reports.view', 'View Operations Performance Reports', 'operations', 'View filtered operations performance reports'
    UNION ALL SELECT 'parts.catalog.manage', 'Manage Parts Catalog', 'inventory', 'Add and edit parts catalog entries'
    UNION ALL SELECT 'parts.manage', 'Manage Parts Inventory', 'inventory', 'Manage inventory stock and adjustments'
    UNION ALL SELECT 'parts.view', 'View Parts Inventory', 'inventory', 'View inventory and stock movements'
    UNION ALL SELECT 'payroll.manage', 'Prepare & Manage Payroll', 'payroll', 'Prepare and manage payroll'
    UNION ALL SELECT 'payroll.view', 'View Payroll Records', 'payroll', 'View payroll records'
    UNION ALL SELECT 'permissions.manage', 'Manage Role Permissions', 'admin', 'Manage role permission grants'
    UNION ALL SELECT 'purchasing.manage', 'Create & Receive Purchase Orders', 'purchasing', 'Create and receive purchase orders'
    UNION ALL SELECT 'purchasing.view', 'View Suppliers & Purchase Orders', 'purchasing', 'View suppliers and purchase orders'
    UNION ALL SELECT 'reports.export', 'Export Report Data', 'reports', 'Export report data'
    UNION ALL SELECT 'reports.view', 'View Reports', 'reports', 'View reports'
    UNION ALL SELECT 'roles.manage', 'Create System Roles', 'admin', 'Create and manage system roles'
    UNION ALL SELECT 'routes.approve', 'Approve Route Requests', 'routes', 'Approve or reject route requests'
    UNION ALL SELECT 'routes.manage', 'Manage Routes & Locations', 'routes', 'Manage routes and locations'
    UNION ALL SELECT 'routes.request', 'Submit Route Requests', 'routes', 'Submit new route requests'
    UNION ALL SELECT 'trips.assign', 'Assign Trucks & Crew to Trips', 'trips', 'Assign trucks and crew to trips'
    UNION ALL SELECT 'trips.complete', 'Submit Trip Completion Records', 'trips', 'Submit completed trip records'
    UNION ALL SELECT 'trips.create', 'Create Trips', 'trips', 'Create trip or dispatch requests'
    UNION ALL SELECT 'trips.export', 'Export Trip Operations Data', 'trips', 'Export trip operations reports'
    UNION ALL SELECT 'trips.update', 'Update Trip Information', 'trips', 'Update trip information and progress'
    UNION ALL SELECT 'trips.view', 'View Trips', 'trips', 'View trips and dispatch requests'
    UNION ALL SELECT 'users.manage', 'Manage User Accounts', 'users', 'Create, edit, and deactivate user accounts'
) v
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.permission_key = v.permission_key);

-- ------------------------------------------------------------
-- 3a. Admin: every permission present in the permissions table
-- ------------------------------------------------------------
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.system_key = 'admin'
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id
  );

-- ------------------------------------------------------------
-- 3b. Department roles: least-privilege grants
-- ------------------------------------------------------------
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM (
              SELECT 'operations_head' AS role_key, 'operations.dashboard.view' AS permission_key
    UNION ALL SELECT 'operations_head', 'operations.reports.view'
    -- reports.view for Operations Head: approved 2026-10-14 (Option B
    -- decision 5). Analytics sections are to be chosen by role key (B7).
    UNION ALL SELECT 'operations_head', 'reports.view'
    UNION ALL SELECT 'operations_head', 'trips.view'
    UNION ALL SELECT 'operations_head', 'trips.assign'
    UNION ALL SELECT 'operations_head', 'trips.export'
    UNION ALL SELECT 'operations_head', 'dispatch.instructions.manage'
    UNION ALL SELECT 'operations_head', 'dispatch.clear'
    UNION ALL SELECT 'operations_head', 'approvals.review'
    UNION ALL SELECT 'operations_head', 'routes.manage'
    UNION ALL SELECT 'operations_head', 'routes.approve'
    UNION ALL SELECT 'operations_head', 'fleet.view'
    UNION ALL SELECT 'operations_head', 'fleet.manage'
    UNION ALL SELECT 'operations_head', 'maintenance.view'
    UNION ALL SELECT 'operations_head', 'clients.view'
    UNION ALL SELECT 'operations_head', 'incidents.manage'
    UNION ALL SELECT 'operations_head', 'documents.view'
    UNION ALL SELECT 'operations_head', 'documents.download'

    UNION ALL SELECT 'car_carrier_dispatcher', 'dispatch.instructions.encode'
    UNION ALL SELECT 'car_carrier_dispatcher', 'trips.view'
    UNION ALL SELECT 'car_carrier_dispatcher', 'trips.create'
    UNION ALL SELECT 'car_carrier_dispatcher', 'trips.update'
    UNION ALL SELECT 'car_carrier_dispatcher', 'trips.assign'
    UNION ALL SELECT 'car_carrier_dispatcher', 'routes.request'
    UNION ALL SELECT 'car_carrier_dispatcher', 'fleet.view'
    UNION ALL SELECT 'car_carrier_dispatcher', 'clients.view'
    UNION ALL SELECT 'car_carrier_dispatcher', 'incidents.manage'
    UNION ALL SELECT 'car_carrier_dispatcher', 'documents.view'
    UNION ALL SELECT 'car_carrier_dispatcher', 'documents.upload'
    UNION ALL SELECT 'car_carrier_dispatcher', 'documents.download'

    UNION ALL SELECT 'container_wing_van_dispatcher', 'dispatch.instructions.encode'
    UNION ALL SELECT 'container_wing_van_dispatcher', 'trips.view'
    UNION ALL SELECT 'container_wing_van_dispatcher', 'trips.create'
    UNION ALL SELECT 'container_wing_van_dispatcher', 'trips.update'
    UNION ALL SELECT 'container_wing_van_dispatcher', 'trips.assign'
    UNION ALL SELECT 'container_wing_van_dispatcher', 'routes.request'
    UNION ALL SELECT 'container_wing_van_dispatcher', 'fleet.view'
    UNION ALL SELECT 'container_wing_van_dispatcher', 'clients.view'
    UNION ALL SELECT 'container_wing_van_dispatcher', 'incidents.manage'
    UNION ALL SELECT 'container_wing_van_dispatcher', 'documents.view'
    UNION ALL SELECT 'container_wing_van_dispatcher', 'documents.upload'
    UNION ALL SELECT 'container_wing_van_dispatcher', 'documents.download'

    UNION ALL SELECT 'maintenance', 'maintenance.view'
    UNION ALL SELECT 'maintenance', 'maintenance.manage'
    UNION ALL SELECT 'maintenance', 'maintenance.reports.view'
    UNION ALL SELECT 'maintenance', 'fleet.view'
    UNION ALL SELECT 'maintenance', 'fleet.status.update'
    UNION ALL SELECT 'maintenance', 'legacy.role.3'
    UNION ALL SELECT 'maintenance', 'parts.view'
    UNION ALL SELECT 'maintenance', 'incidents.manage'
    UNION ALL SELECT 'maintenance', 'documents.view'
    UNION ALL SELECT 'maintenance', 'documents.upload'
    UNION ALL SELECT 'maintenance', 'documents.download'

    UNION ALL SELECT 'purchasing_officer', 'purchasing.view'
    UNION ALL SELECT 'purchasing_officer', 'purchasing.manage'
    UNION ALL SELECT 'purchasing_officer', 'admin.supplies.view'
    UNION ALL SELECT 'purchasing_officer', 'admin.supplies.manage'
    UNION ALL SELECT 'purchasing_officer', 'parts.view'
    UNION ALL SELECT 'purchasing_officer', 'parts.manage'
    UNION ALL SELECT 'purchasing_officer', 'parts.catalog.manage'
    UNION ALL SELECT 'purchasing_officer', 'documents.view'
    UNION ALL SELECT 'purchasing_officer', 'documents.upload'
    UNION ALL SELECT 'purchasing_officer', 'documents.download'

    UNION ALL SELECT 'finance', 'finance.view'
    UNION ALL SELECT 'finance', 'finance.manage'
    UNION ALL SELECT 'finance', 'finance.funds.view'
    UNION ALL SELECT 'finance', 'finance.funds.manage'
    UNION ALL SELECT 'finance', 'finance.disbursements.view'
    UNION ALL SELECT 'finance', 'finance.disbursements.manage'
    UNION ALL SELECT 'finance', 'finance.ap.view'
    UNION ALL SELECT 'finance', 'finance.ap.manage'
    UNION ALL SELECT 'finance', 'finance.expenses.edit'
    UNION ALL SELECT 'finance', 'budgets.manage'
    UNION ALL SELECT 'finance', 'billing.view'
    UNION ALL SELECT 'finance', 'legacy.role.4'
    UNION ALL SELECT 'finance', 'documents.view'
    UNION ALL SELECT 'finance', 'documents.upload'
    UNION ALL SELECT 'finance', 'documents.download'

    UNION ALL SELECT 'billing_collection', 'billing.view'
    UNION ALL SELECT 'billing_collection', 'billing.manage'
    UNION ALL SELECT 'billing_collection', 'billing.export'
    UNION ALL SELECT 'billing_collection', 'clients.view'
    UNION ALL SELECT 'billing_collection', 'clients.manage'
    UNION ALL SELECT 'billing_collection', 'documents.view'
    UNION ALL SELECT 'billing_collection', 'documents.upload'
    UNION ALL SELECT 'billing_collection', 'documents.download'

    UNION ALL SELECT 'payroll', 'payroll.view'
    UNION ALL SELECT 'payroll', 'payroll.manage'
    UNION ALL SELECT 'payroll', 'hr.attendance.view'
    UNION ALL SELECT 'payroll', 'hr.attendance.manage'
    UNION ALL SELECT 'payroll', 'hr.attendance.report'
    UNION ALL SELECT 'payroll', 'documents.view'
    UNION ALL SELECT 'payroll', 'documents.download'

    UNION ALL SELECT 'audit', 'audit.view'

    UNION ALL SELECT 'driver', 'trips.complete'
) g
JOIN roles r ON r.system_key = g.role_key
JOIN permissions p ON p.permission_key = g.permission_key
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id
);

COMMIT;
