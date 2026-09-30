UPDATE roles
SET role_name = 'Admin'
WHERE role_id = 1 AND role_name = 'Head Management';

INSERT INTO roles (role_name)
SELECT 'Operations Head'
WHERE NOT EXISTS (
  SELECT 1 FROM roles WHERE role_name = 'Operations Head'
);

INSERT IGNORE INTO permissions (permission_key, module_name, action_name, description) VALUES
  ('operations.dashboard.view', 'operations', 'view_dashboard', 'View the Operations Head dashboard'),
  ('routes.request', 'routes', 'request', 'Submit new route requests'),
  ('routes.approve', 'routes', 'approve', 'Approve or reject route requests'),
  ('incidents.manage', 'incidents', 'manage', 'Report and resolve trip incidents');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, permission_id
FROM permissions;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name = 'Operations Head'
  AND p.permission_key IN (
    'operations.dashboard.view',
    'trips.view',
    'trips.approve',
    'approvals.review',
    'fleet.view',
    'employees.view',
    'clients.view',
    'routes.view',
    'routes.manage',
    'routes.approve',
    'incidents.manage',
    'reports.export'
  );

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name = 'Dispatcher'
  AND p.permission_key IN (
    'legacy.role.2',
    'trips.view',
    'trips.create',
    'trips.update',
    'trips.assign',
    'trips.complete',
    'fleet.view',
    'employees.view',
    'clients.view',
    'routes.view',
    'routes.request',
    'incidents.manage',
    'documents.view',
    'documents.upload',
    'documents.download',
    'reports.view',
    'reports.export'
  );

UPDATE approval_role_steps ars
JOIN roles operation_role ON operation_role.role_name = 'Operations Head'
SET ars.approver_role_id = operation_role.role_id
WHERE ars.request_type = 'dispatch'
  AND ars.step_order = 1
  AND ars.approver_role_id = 1;
