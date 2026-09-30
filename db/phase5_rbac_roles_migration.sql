INSERT IGNORE INTO permissions (permission_key, module_name, action_name, description) VALUES
  ('company.dashboard.view', 'company', 'view_dashboard', 'View the company-wide management dashboard'),
  ('dispatch.shift.day', 'dispatch', 'day_shift', 'Encode Day-shift dispatches'),
  ('dispatch.shift.night', 'dispatch', 'night_shift', 'Encode Night-shift dispatches'),
  ('dispatch.vehicle.car_carrier', 'dispatch', 'car_carrier', 'Encode Car Carrier dispatches'),
  ('dispatch.vehicle.container', 'dispatch', 'container', 'Encode Container dispatches'),
  ('dispatch.vehicle.wing_van', 'dispatch', 'wing_van', 'Encode Wing Van dispatches');

INSERT INTO roles (role_name)
SELECT requested.role_name
FROM (
  SELECT 'Management / Head' AS role_name
  UNION ALL SELECT 'Admin Officer'
  UNION ALL SELECT 'Car Carrier Dispatcher - Day'
  UNION ALL SELECT 'Car Carrier Dispatcher - Night'
  UNION ALL SELECT 'Container & Wing Van Dispatcher - Day'
  UNION ALL SELECT 'Container & Wing Van Dispatcher - Night'
  UNION ALL SELECT 'Purchasing Officer'
  UNION ALL SELECT 'Finance'
  UNION ALL SELECT 'Billing and Collection'
  UNION ALL SELECT 'Payroll'
) requested
WHERE NOT EXISTS (
  SELECT 1 FROM roles existing WHERE existing.role_name = requested.role_name
);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, permission_id
FROM permissions;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'company.dashboard.view',
  'reports.view', 'reports.export',
  'trips.view', 'fleet.view', 'maintenance.view',
  'billing.view', 'finance.view', 'payroll.view',
  'employees.view', 'employees.manage', 'clients.view', 'routes.view',
  'documents.view', 'audit.view', 'users.view', 'users.manage'
)
WHERE r.role_name = 'Management / Head';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'trips.view', 'fleet.view',
  'employees.view', 'employees.manage',
  'clients.view', 'routes.view',
  'billing.view', 'finance.view',
  'documents.view', 'documents.upload', 'documents.download', 'documents.manage',
  'reports.view', 'reports.export', 'audit.view'
)
WHERE r.role_name = 'Admin Officer';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'operations.dashboard.view',
  'dispatch.instructions.manage',
  'trips.view', 'trips.approve', 'trips.assign', 'dispatch.clear',
  'approvals.review',
  'fleet.view', 'employees.view', 'clients.view', 'routes.view',
  'maintenance.view', 'incidents.manage', 'documents.view',
  'reports.view', 'reports.export'
)
WHERE r.role_name = 'Operations Head';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'dispatch.instructions.encode',
  'trips.view', 'trips.create', 'trips.update', 'trips.assign', 'trips.complete',
  'fleet.view', 'employees.view', 'clients.view', 'routes.view', 'routes.request',
  'incidents.manage',
  'documents.view', 'documents.upload', 'documents.download',
  'reports.view', 'dispatch.shift.day', 'dispatch.vehicle.car_carrier'
)
WHERE r.role_name = 'Car Carrier Dispatcher - Day';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'dispatch.instructions.encode',
  'trips.view', 'trips.create', 'trips.update', 'trips.assign', 'trips.complete',
  'fleet.view', 'employees.view', 'clients.view', 'routes.view', 'routes.request',
  'incidents.manage',
  'documents.view', 'documents.upload', 'documents.download',
  'reports.view', 'dispatch.shift.night', 'dispatch.vehicle.car_carrier'
)
WHERE r.role_name = 'Car Carrier Dispatcher - Night';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'dispatch.instructions.encode',
  'trips.view', 'trips.create', 'trips.update', 'trips.assign', 'trips.complete',
  'fleet.view', 'employees.view', 'clients.view', 'routes.view', 'routes.request',
  'incidents.manage',
  'documents.view', 'documents.upload', 'documents.download',
  'reports.view', 'dispatch.shift.day',
  'dispatch.vehicle.container', 'dispatch.vehicle.wing_van'
)
WHERE r.role_name = 'Container & Wing Van Dispatcher - Day';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'dispatch.instructions.encode',
  'trips.view', 'trips.create', 'trips.update', 'trips.assign', 'trips.complete',
  'fleet.view', 'employees.view', 'clients.view', 'routes.view', 'routes.request',
  'incidents.manage',
  'documents.view', 'documents.upload', 'documents.download',
  'reports.view', 'dispatch.shift.night',
  'dispatch.vehicle.container', 'dispatch.vehicle.wing_van'
)
WHERE r.role_name = 'Container & Wing Van Dispatcher - Night';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'fleet.view', 'maintenance.view',
  'parts.view', 'parts.manage',
  'documents.view', 'documents.upload', 'documents.download',
  'reports.view'
)
WHERE r.role_name = 'Purchasing Officer';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'trips.view', 'billing.view', 'finance.view', 'finance.manage',
  'documents.view', 'documents.upload', 'documents.download',
  'reports.view', 'reports.export'
)
WHERE r.role_name = 'Finance';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'clients.view', 'clients.manage', 'trips.view',
  'billing.view', 'billing.manage',
  'documents.view', 'documents.upload', 'documents.download',
  'reports.view', 'reports.export'
)
WHERE r.role_name = 'Billing and Collection';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'employees.view', 'trips.view', 'payroll.view', 'payroll.manage',
  'documents.view', 'documents.upload', 'documents.download',
  'reports.view', 'reports.export'
)
WHERE r.role_name = 'Payroll';

UPDATE trucks
SET truck_type = CASE LOWER(TRIM(truck_type))
  WHEN 'car carrier' THEN 'Car Carrier'
  WHEN 'car-carrier' THEN 'Car Carrier'
  WHEN 'car_carrier' THEN 'Car Carrier'
  WHEN 'container' THEN 'Container'
  WHEN 'container truck' THEN 'Container'
  WHEN 'container trailer' THEN 'Container'
  WHEN 'wing van' THEN 'Wing Van'
  WHEN 'wing-van' THEN 'Wing Van'
  WHEN 'wingvan' THEN 'Wing Van'
  ELSE truck_type
END
WHERE LOWER(TRIM(truck_type)) IN (
  'car carrier', 'car-carrier', 'car_carrier',
  'container', 'container truck', 'container trailer',
  'wing van', 'wing-van', 'wingvan'
);
