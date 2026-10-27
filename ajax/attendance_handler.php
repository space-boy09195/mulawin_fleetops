<?php
// ============================================================
// ajax/attendance_handler.php
// Admin and Payroll (hr.attendance.manage) record attendance for employees.
//   save        one employee (kept for existing callers)
//   save_batch  the rows changed on the Attendance page, all or nothing:
//               every row is checked first, then all are saved in one
//               transaction; any problem saves none of them.
// Each saved row keeps its time in/out and overtime and is audited
// (ADD_ATTENDANCE / EDIT_ATTENDANCE with before and after values).
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/attendance.php';

header('Content-Type: application/json');
requireLogin();
requirePostMethod();
enforceCsrf();
requirePermission('hr.attendance.manage');

$pdo = getDBConnection();
$action = $_POST['action'] ?? '';
if (!in_array($action, ['save', 'save_batch'], true)) {
    jsonFail('Unknown attendance action.');
}
foreach (['attendance_date', 'employee_id', 'status', 'notes', 'rows'] as $field) {
    if (isset($_POST[$field]) && !is_string($_POST[$field])) {
        jsonFail('Attendance fields must be submitted as text values.');
    }
}
$date = requiredDate('attendance_date', 'Attendance date', true);

// Thrown when a saved record changed after the page was loaded.
class AttendanceChanged extends DomainException {}

/**
 * Saves one employee's attendance for $date inside the caller's
 * transaction. With $expectedStatus (batch save) the saved record must
 * still be what the page showed, or AttendanceChanged is thrown.
 */
function saveAttendanceRow(PDO $pdo, int $employeeId, string $date, string $status, ?string $notes,
                           ?string $expectedStatus = null, string $expectedNotes = ''): void {
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
    if ($expectedStatus !== null && !attendanceRecordUnchanged($existing, $expectedStatus, $expectedNotes)) {
        throw new AttendanceChanged((string)$employeeId);
    }

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
}

// Rows to save: [employee_id, name, status, notes, expected_status|null, expected_notes].
if ($action === 'save') {
    $employeeId = requiredInt('employee_id', 'Employee', 1);
    $status = optionalString('status', '');
    $notes = optionalString('notes', null, 500);
    if (!in_array($status, ATTENDANCE_STATUSES, true)) {
        jsonFail('Select a valid attendance status.');
    }
    $employee = $pdo->prepare(
        "SELECT employee_id FROM employees WHERE employee_id = ? AND employment_status = 'Active'"
    );
    $employee->execute([$employeeId]);
    if (!$employee->fetchColumn()) {
        jsonFail('The active employee record could not be found.', 404);
    }
    $rows = [[
        'employee_id' => $employeeId, 'name' => 'Employee #' . $employeeId, 'status' => $status,
        'notes' => $notes, 'expected_status' => null, 'expected_notes' => '',
    ]];
} else {
    $submitted = json_decode((string)($_POST['rows'] ?? ''), true);
    $submittedIds = [];
    if (is_array($submitted)) {
        foreach ($submitted as $row) {
            $id = is_array($row) ? filter_var($row['employee_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($id !== false) {
                $submittedIds[] = $id;
            }
        }
    }
    $employeeNames = [];
    if ($submittedIds) {
        $names = $pdo->prepare(
            "SELECT employee_id,
                    COALESCE(NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM(first_name), ''), NULLIF(TRIM(last_name), ''))), ''),
                             CONCAT('Employee #', employee_id)) AS full_name
             FROM employees
             WHERE employment_status = 'Active'
               AND employee_id IN (" . implode(',', array_fill(0, count(array_unique($submittedIds)), '?')) . ')'
        );
        $names->execute(array_values(array_unique($submittedIds)));
        foreach ($names->fetchAll(PDO::FETCH_ASSOC) as $nameRow) {
            $employeeNames[(int)$nameRow['employee_id']] = $nameRow['full_name'];
        }
    }
    [$rows, $errors] = validateAttendanceBatch($submitted, $employeeNames);
    if ($errors) {
        jsonFail('Nothing was saved. ' . implode(' ', $errors), 422);
    }
}

try {
    $pdo->beginTransaction();
    foreach ($rows as $row) {
        saveAttendanceRow($pdo, $row['employee_id'], $date, $row['status'], $row['notes'],
            $row['expected_status'], $row['expected_notes']);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof AttendanceChanged) {
        $changedName = 'Employee #' . $e->getMessage();
        foreach ($rows as $row) {
            if ((string)$row['employee_id'] === $e->getMessage()) {
                $changedName = $row['name'];
            }
        }
        jsonFail('Nothing was saved. ' . $changedName . ': this attendance was changed by someone else after you opened the page. Reload the page and check it.', 409);
    }
    if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
        jsonFail('Nothing was saved. Attendance was updated by another request. Reload the page and try again.', 409);
    }
    error_log('attendance_handler: ' . $e->getMessage());
    jsonFail('Nothing was saved. Could not update attendance. Please try again.', 500);
}

$label = date('M j, Y', strtotime($date));
jsonOk(['saved' => count($rows)], count($rows) === 1
    ? 'Attendance saved for ' . $label . '.'
    : 'Attendance saved for ' . count($rows) . ' employees on ' . $label . '.');
