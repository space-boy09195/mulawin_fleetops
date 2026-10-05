-- Restrict the dedicated Management Analytics page (reports.view) to the
-- management roles that need it: Admin, Management / Head, Operations Head.
-- Idempotent: only removes reports.view from the listed operational roles.
-- reports.export and every other permission are untouched.
DELETE rp
FROM role_permissions rp
JOIN permissions p ON p.permission_id = rp.permission_id
JOIN roles r ON r.role_id = rp.role_id
WHERE p.permission_key = 'reports.view'
  AND r.role_name IN (
    'Dispatcher',
    'Maintenance',
    'Accounting',
    'Admin Officer',
    'Car Carrier Dispatcher - Day',
    'Car Carrier Dispatcher - Night',
    'Container & Wing Van Dispatcher - Day',
    'Container & Wing Van Dispatcher - Night',
    'Purchasing Officer',
    'Finance',
    'Billing and Collection',
    'Payroll'
  );
