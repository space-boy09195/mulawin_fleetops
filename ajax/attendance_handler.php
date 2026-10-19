<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';

header('Content-Type: application/json');
requireLogin();
requirePostMethod();
enforceCsrf();
requirePermission('hr.attendance.manage');

$pdo = getDBConnection();
if (($_POST['action'] ?? '') !== 'save') {
    jsonFail('Unknown attendance action.');
}
foreach (['attendance_date', 'employee_id', 'status', 'notes'] as $field) {
    if (isset($_POST[$field]) && !is_string($_POST[$field])) {
        jsonFail('Attendance fields must be submitted as text values.');
    }
}

$employeeId = requiredInt('employee_id', 'Employee', 1);
$date = requiredDate('attendance_date', 'Attendance date', true);
$status = optionalString('status', '');
$notes = optionalString('notes', null, 500);
if (!in_array($status, ['Present', 'Absent', 'Leave', 'On Duty'], true)) {
    jsonFail('Select a valid attendance status.');
}

$employee = $pdo->prepare(
    "SELECT employee_id FROM employees WHERE employee_id = ? AND employment_status = 'Active'"
);
$employee->execute([$employeeId]);
if (!$employee->fetchColumn()) {
    jsonFail('The active employee record could not be found.', 404);
}

try {
    $pdo->beginTransaction();
    $employeeLock = $pdo->prepare('SELECT employee_id FROM employees WHERE employee_id = ? FOR UPDATE');
    $employeeLock->execute([$employeeId]);

    $existingQuery = $pdo->prepare(
        'SELECT attendance_id, status, time_in, time_out, remarks, overtime_minutes,
                overtime_reason, recorded_by
         FROM attendance_records
         WHERE employee_id = ? AND attendance_date = ?
         FOR UPDATE'
    );
    $existingQuery->execute([$employeeId, $date]);
    $existing = $existingQuery->fetch(PDO::FETCH_ASSOC) ?: null;

    $timeIn = $existing['time_in'] ?? null;
    $timeOut = $existing['time_out'] ?? null;
    $overtimeMinutes = (int)($existing['overtime_minutes'] ?? 0);
    $overtimeReason = $existing['overtime_reason'] ?? null;
    $stmt = $pdo->prepare(
        'INSERT INTO attendance_records
         (employee_id, attendance_date, status, time_in, time_out, remarks, overtime_minutes, overtime_reason, recorded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           status = VALUES(status), remarks = VALUES(remarks), recorded_by = VALUES(recorded_by)'
    );
    $stmt->execute([
        $employeeId,
        $date,
        $status,
        $timeIn,
        $timeOut,
        $notes,
        $overtimeMinutes,
        $overtimeReason,
        currentUserId(),
    ]);

    $before = $existing === null ? null : [
        'attendance_date' => $date,
        'status' => $existing['status'],
        'notes' => $existing['remarks'],
        'recorded_by' => (int)$existing['recorded_by'],
    ];
    $after = [
        'attendance_date' => $date,
        'status' => $status,
        'notes' => $notes,
        'recorded_by' => currentUserId(),
    ];
    $attendanceId = $existing === null
        ? (int)$pdo->lastInsertId()
        : (int)$existing['attendance_id'];
    auditLog(
        $existing === null ? 'ADD_ATTENDANCE' : 'EDIT_ATTENDANCE',
        'employee_attendance',
        $attendanceId,
        $before,
        $after
    );
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
        jsonFail('Attendance was updated by another request. Refresh the page and try again.', 409);
    }
    error_log('attendance_handler: ' . $e->getMessage());
    jsonFail('Could not update attendance. Please try again.', 500);
}

jsonOk([], 'Attendance saved.');
