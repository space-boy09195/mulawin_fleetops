<?php

/**
 * Return the single active employee profile linked to the signed-in user.
 * Employee records are maintained by HR and are never fabricated at login.
 */
function employeeForCurrentUser(PDO $pdo): ?array {
    $userId = currentUserId();
    if ($userId <= 0) {
        throw new RuntimeException('A signed-in user is required.');
    }

    $query = $pdo->prepare(
        "SELECT e.employee_id,
                COALESCE(
                    NULLIF(TRIM(CONCAT_WS(' ',
                        NULLIF(TRIM(e.first_name), ''),
                        NULLIF(TRIM(e.middle_name), ''),
                        NULLIF(TRIM(e.last_name), ''),
                        NULLIF(TRIM(e.suffix), '')
                    )), ''),
                    CONCAT('Employee #', e.employee_id)
                ) AS full_name,
                e.position, e.employment_type
         FROM employees e
         WHERE e.user_id = ? AND e.employment_status = 'Active'
         ORDER BY e.employee_id"
    );
    $query->execute([$userId]);
    $employees = $query->fetchAll(PDO::FETCH_ASSOC);
    if (count($employees) > 1) {
        error_log('Multiple active employee profiles are linked to user_id ' . $userId . '.');
        return null;
    }
    return $employees[0] ?? null;
}

/**
 * T-E1 maintenance contract (db/target_migrations/20261014_tE1_employee_compat_columns.sql).
 * employees.full_name and employees.is_active are ordinary columns derived
 * from the name parts and employment_status. Call this after every INSERT or
 * UPDATE that writes those source columns, on the same connection and inside
 * the same transaction, so the derived values commit with the change. The
 * expressions are the migration's backfill expressions, so the drift check
 * in that file stays empty.
 */
function syncEmployeeCompatColumns(PDO $pdo, int $employeeId): void {
    $pdo->prepare(
        "UPDATE employees
            SET full_name = NULLIF(TRIM(CONCAT_WS(' ',
                    NULLIF(TRIM(first_name), ''),
                    NULLIF(TRIM(middle_name), ''),
                    NULLIF(TRIM(last_name), ''),
                    NULLIF(TRIM(suffix), '')
                )), ''),
                is_active = IF(employment_status = 'Active', 1, 0)
          WHERE employee_id = ?"
    )->execute([$employeeId]);
}

/**
 * SQL expression for an employee's display name from the target name parts.
 * $alias must be a fixed table alias from the calling query (letters, digits
 * and underscores only); it is never user input.
 */
function employeeNameSql(string $alias): string {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Invalid table alias.');
    }
    return "NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM({$alias}.first_name), ''), NULLIF(TRIM({$alias}.middle_name), ''), "
         . "NULLIF(TRIM({$alias}.last_name), ''), NULLIF(TRIM({$alias}.suffix), ''))), '')";
}
