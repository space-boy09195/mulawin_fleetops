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
        'SELECT e.employee_id, e.full_name, e.position, e.employment_type
         FROM employees e
         WHERE e.user_id = ? AND e.is_active = 1
         ORDER BY e.employee_id'
    );
    $query->execute([$userId]);
    $employees = $query->fetchAll(PDO::FETCH_ASSOC);
    if (count($employees) > 1) {
        error_log('Multiple active employee profiles are linked to user_id ' . $userId . '.');
        return null;
    }
    return $employees[0] ?? null;
}
