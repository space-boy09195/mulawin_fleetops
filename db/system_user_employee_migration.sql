-- ===== BEGIN SOURCE: system_user_employee_migration.sql =====
-- FleetOps rule: every system login is also an employee.
-- Link only unambiguous existing employee records. Do not create employee
-- records from login names or system roles; HR must enter missing real data.

UPDATE employees e
JOIN users u
  ON e.user_id IS NULL
 AND e.is_active = 1
 AND e.full_name = u.full_name
JOIN (
    SELECT full_name
    FROM employees
    WHERE user_id IS NULL AND is_active = 1
    GROUP BY full_name
    HAVING COUNT(*) = 1
) unique_employee_names
  ON unique_employee_names.full_name = e.full_name
JOIN (
    SELECT full_name
    FROM users
    WHERE is_active = 1
    GROUP BY full_name
    HAVING COUNT(*) = 1
) unique_user_names
  ON unique_user_names.full_name = u.full_name
LEFT JOIN (
    SELECT user_id
    FROM employees
    WHERE user_id IS NOT NULL AND is_active = 1
    GROUP BY user_id
) already_linked_users
  ON already_linked_users.user_id = u.user_id
SET e.user_id = u.user_id
WHERE u.is_active = 1
  AND already_linked_users.user_id IS NULL;

-- Review these accounts and link/create their actual employee profiles before
-- enabling attendance for them. This SELECT does not modify employee data.
SELECT u.user_id, u.username, u.full_name, r.role_name
FROM users u
JOIN roles r ON r.role_id = u.role_id
LEFT JOIN employees e ON e.user_id = u.user_id AND e.is_active = 1
WHERE u.is_active = 1
GROUP BY u.user_id, u.username, u.full_name, r.role_name
HAVING COUNT(e.employee_id) <> 1
ORDER BY u.user_id;
-- ===== END SOURCE: system_user_employee_migration.sql =====
