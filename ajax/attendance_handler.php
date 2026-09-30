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

$pdo = getDBConnection();
if (!in_array($_POST['action'] ?? '', ['save', 'clock_in', 'clock_out'], true)) {
    jsonFail('Unknown action.');
}

$action = $_POST['action'];
if ($action === 'save') {
    requirePermission('hr.attendance.manage');
}
$employeeId = $action === 'save'
    ? requiredInt('employee_id', 'Employee', 1)
    : 0;
$date = $action === 'save' ? requiredDate('attendance_date', 'Attendance date', true) : date('Y-m-d');
$status = optionalString('status', '');
$timeIn = optionalString('time_in', null, 5);
$timeOut = optionalString('time_out', null, 5);
$notes = optionalString('notes', null, 500);
$overtimeReason = optionalString('overtime_reason', null, 255);
$overtime = filter_var($_POST['overtime'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($action !== 'save') {
    $linked = $pdo->prepare('SELECT employee_id FROM employees WHERE user_id = ? AND is_active = 1');
    $linked->execute([currentUserId()]);
    $employeeId = (int)$linked->fetchColumn();
    if (!$employeeId) {
        jsonFail('Your account is not linked to an active employee record.', 409);
    }
    $existing = $pdo->prepare(
        'SELECT attendance_id, attendance_date, status, time_in, time_out, overtime_minutes, overtime_reason
         FROM employee_attendance
         WHERE employee_id = ? AND attendance_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND CURDATE()
         ORDER BY attendance_date DESC LIMIT 1'
    );
    $existing->execute([$employeeId]);
    $current = $existing->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($action === 'clock_in') {
        if ($current && $current['time_in'] !== null && $current['time_out'] === null) {
            jsonFail('You have an open shift. Clock out before starting another shift.', 409);
        }
        $date = date('Y-m-d');
        $timeIn = date('H:i');
        $timeOut = null;
        $status = 'Present';
    } else {
        if (!$current || $current['time_in'] === null) jsonFail('Clock in before clocking out.', 409);
        if ($current['time_out'] !== null) jsonFail('You are already clocked out today.', 409);
        $date = $current['attendance_date'];
        $timeIn = substr((string)$current['time_in'], 0, 5);
        $timeOut = date('H:i');
        $status = 'Present';
        $overtime = (int)$current['overtime_minutes'] > 0 || $overtime;
        $overtimeReason = $current['overtime_reason'] ?: $overtimeReason;
        $shiftStart = new DateTimeImmutable($date . ' ' . $timeIn);
        $shiftEnd = new DateTimeImmutable();
        if ($shiftEnd < $shiftStart) {
            jsonFail('System time is earlier than the recorded clock-in time.', 409);
        }
        $duration = (int)floor(($shiftEnd->getTimestamp() - $shiftStart->getTimestamp()) / 60);
        if ($duration > 16 * 60) jsonFail('A shift cannot exceed 16 hours.', 409);
        $overtimeMinutes = max(0, $duration - 8 * 60);
        if ($overtimeMinutes > 0 && !$overtimeReason) {
            jsonFail('This shift exceeds 8 hours. Confirm overtime and provide a reason.');
        }
    }
}

if (!in_array($status, ['Present', 'Absent', 'Leave', 'On Duty'], true)) {
    jsonFail('Select a valid attendance status.');
}
if (($timeIn !== null && !preg_match('/^\d{2}:\d{2}$/', $timeIn))
    || ($timeOut !== null && !preg_match('/^\d{2}:\d{2}$/', $timeOut))) {
    jsonFail('Time in and time out must use HH:MM format.');
}
if ($timeIn !== null && $timeOut !== null && $timeOut < $timeIn) {
    $endMinutes = (int)substr($timeOut, 0, 2) * 60 + (int)substr($timeOut, 3, 2) + 1440;
    $startMinutes = (int)substr($timeIn, 0, 2) * 60 + (int)substr($timeIn, 3, 2);
    if ($endMinutes - $startMinutes > 16 * 60) jsonFail('A shift cannot exceed 16 hours.');
} elseif ($timeIn !== null && $timeOut !== null) {
    $startMinutes = (int)substr($timeIn, 0, 2) * 60 + (int)substr($timeIn, 3, 2);
    $endMinutes = (int)substr($timeOut, 0, 2) * 60 + (int)substr($timeOut, 3, 2);
    if ($endMinutes - $startMinutes > 16 * 60) jsonFail('A shift cannot exceed 16 hours.');
}
if ($action === 'save' && $timeIn !== null && $timeOut !== null) {
    $startMinutes = (int)substr($timeIn, 0, 2) * 60 + (int)substr($timeIn, 3, 2);
    $endMinutes = (int)substr($timeOut, 0, 2) * 60 + (int)substr($timeOut, 3, 2);
    if ($endMinutes < $startMinutes) $endMinutes += 1440;
    $duration = $endMinutes - $startMinutes;
    $overtimeMinutes = max(0, $duration - 8 * 60);
    if ($overtimeMinutes > 0 && (!$overtime || !$overtimeReason)) {
        jsonFail('Shifts over 8 hours require the overtime option and a reason.');
    }
} elseif ($action === 'save') {
    $overtimeMinutes = 0;
}

$employee = $pdo->prepare('SELECT employee_id FROM employees WHERE employee_id = ?');
$employee->execute([$employeeId]);
if (!$employee->fetchColumn()) {
    jsonFail('Employee not found.');
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO employee_attendance
         (employee_id, attendance_date, status, time_in, time_out, notes, overtime_minutes, overtime_reason, recorded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           status = VALUES(status), time_in = VALUES(time_in), time_out = VALUES(time_out),
           notes = VALUES(notes), overtime_minutes = VALUES(overtime_minutes),
           overtime_reason = VALUES(overtime_reason), recorded_by = VALUES(recorded_by)'
    );
    $stmt->execute([$employeeId, $date, $status, $timeIn, $timeOut, $notes, $overtimeMinutes, $overtimeReason, currentUserId()]);
    auditLog('SAVE_ATTENDANCE', 'employee_attendance', $employeeId, null, [
        'attendance_date' => $date, 'status' => $status,
    ]);
    jsonOk([], 'Attendance saved.');
} catch (PDOException $e) {
    error_log('attendance_handler/save: ' . $e->getMessage());
    jsonFail('Could not save attendance.', 500);
}
