-- Additive role permissions for explicitly approved targeted access changes.
-- No tables, business records, or existing grants are removed or modified.
-- Apply once against a database containing the target RBAC tables and roles
-- with system_key values admin, audit, operations_head, car_carrier_dispatcher,
-- and container_wing_van_dispatcher.

START TRANSACTION;

INSERT INTO permissions (permission_key, permission_name, module, description)
SELECT v.permission_key, v.permission_name, v.module, v.description
FROM (
    SELECT 'dispatch.reports.view' AS permission_key,
           'View My Scoped Operational Reports' AS permission_name,
           'dispatch' AS module,
           'View operational trips and requests within the dispatcher scope' AS description
    UNION ALL SELECT 'finance.trip_allowance.request',
           'Submit Trip Allowance Requests', 'finance',
           'Submit Trip Allowance requests only for the dispatcher''s own confirmed trip'
    UNION ALL SELECT 'maintenance.reports.operations.view',
           'View Limited Operations Maintenance Report', 'maintenance',
           'View recorded maintenance cost and limited work-order summaries'
) v
WHERE NOT EXISTS (
    SELECT 1 FROM permissions p WHERE p.permission_key = v.permission_key
);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'dispatch.reports.view',
    'finance.trip_allowance.request',
    'maintenance.reports.operations.view'
)
WHERE r.system_key = 'admin'
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM (
    SELECT 'car_carrier_dispatcher' AS role_key, 'dispatch.reports.view' AS permission_key
    UNION ALL SELECT 'car_carrier_dispatcher', 'finance.trip_allowance.request'
    UNION ALL SELECT 'container_wing_van_dispatcher', 'dispatch.reports.view'
    UNION ALL SELECT 'container_wing_van_dispatcher', 'finance.trip_allowance.request'
    UNION ALL SELECT 'operations_head', 'maintenance.reports.operations.view'
    UNION ALL SELECT 'audit', 'billing.view'
    UNION ALL SELECT 'audit', 'finance.view'
    UNION ALL SELECT 'audit', 'finance.funds.view'
    UNION ALL SELECT 'audit', 'finance.disbursements.view'
    UNION ALL SELECT 'audit', 'finance.ap.view'
    UNION ALL SELECT 'audit', 'payroll.view'
) g
JOIN roles r ON r.system_key = g.role_key
JOIN permissions p ON p.permission_key = g.permission_key
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id
);

COMMIT;
