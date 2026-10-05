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
$action = $_POST['action'] ?? '';
if (!in_array($action, ['save', 'clock_in', 'clock_out'], true)) {
    jsonFail('Unknown action.');
}
foreach (['status', 'time_in', 'time_out', 'notes', 'overtime_reason'] as $field) {
    if (isset($_POST[$field]) && !is_string($_POST[$field])) {
        jsonFail('Attendance fields must be submitted as text values.');
    }
}
$overtimeRaw = $_POST['overtime'] ?? false;
if (!is_string($overtimeRaw) && !is_bool($overtimeRaw) && !is_int($overtimeRaw)) {
    jsonFail('Overtime selection is invalid.');
}
$overtime = filter_var($overtimeRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($overtime === null) {
    jsonFail('Overtime selection is invalid.');
}
if ($action === 'save') {
    requirePermission('hr.attendance.manage');
    if (isset($_POST['attendance_date']) && !is_string($_POST['attendance_date'])) {
        jsonFail('Attendance date must be submitted as a text value.');
    }
}

$employeeId = 0;
$date = date('Y-m-d');
$status = 'Present';
$timeIn = null;
$timeOut = null;
$notes = null;
$overtimeReason = null;
$overtimeMinutes = 0;

if ($action === 'save') {
    $employeeId = requiredInt('employee_id', 'Employee', 1);
    $date = requiredDate('attendance_date', 'Attendance date', true);
    $status = optionalString('status', '');
    $timeIn = optionalString('time_in', null, 5);
    $timeOut = optionalString('time_out', null, 5);
    $notes = optionalString('notes', null, 500);
    $overtimeReason = optionalString('overtime_reason', null, 255);
    if (!in_array($status, ['Present', 'Absent', 'Leave', 'On Duty'], true)) {
        jsonFail('Select a valid attendance status.');
    }
} else {
    $overtimeReason = optionalString('overtime_reason', null, 255);
    $linked = $pdo->prepare(
        'SELECT employee_id
         FROM employees
         WHERE user_id = ? AND is_active = 1
         ORDER BY employee_id'
    );
    $linked->execute([currentUserId()]);
    $employeeIds = array_map('intval', $linked->fetchAll(PDO::FETCH_COLUMN));
    if (count($employeeIds) !== 1) {
        error_log(sprintf(
            'Attendance rejected for user_id %d: expected one active linked employee, found %d.',
            currentUserId(),
            count($employeeIds)
        ));
        jsonFail(
            count($employeeIds) === 0
                ? 'No active employee record is linked to your account. Ask HR or an administrator to link your existing employee record before clocking in.'
                : 'More than one active employee record is linked to your account. Ask HR or an administrator to correct the employee link.',
            409
        );
    }
    $employeeId = $employeeIds[0];
}

if (($timeIn !== null && !preg_match('/^\d{2}:\d{2}$/', $timeIn))
    || ($timeOut !== null && !preg_match('/^\d{2}:\d{2}$/', $timeOut))) {
    jsonFail('Time in and time out must use HH:MM format.');
}
foreach ([$timeIn, $timeOut] as $time) {
    if ($time !== null) {
        [$hours, $minutes] = array_map('intval', explode(':', $time));
        if ($hours > 23 || $minutes > 59) {
            jsonFail('Time in and time out must be valid times of day.');
        }
    }
}

if ($action === 'save' && $timeIn !== null && $timeOut !== null) {
    $startMinutes = (int)substr($timeIn, 0, 2) * 60 + (int)substr($timeIn, 3, 2);
    $endMinutes = (int)substr($timeOut, 0, 2) * 60 + (int)substr($timeOut, 3, 2);
    if ($endMinutes < $startMinutes) {
        $endMinutes += 1440;
    }
    $duration = $endMinutes - $startMinutes;
    if ($duration > 16 * 60) {
        jsonFail('A shift cannot exceed 16 hours.');
    }
    $overtimeMinutes = max(0, $duration - 8 * 60);
    if ($overtimeMinutes > 0 && (!$overtime || !$overtimeReason)) {
        jsonFail('Shifts over 8 hours require the overtime option and a reason.');
    }
}

$employee = $pdo->prepare(
    'SELECT employee_id FROM employees WHERE employee_id = ? AND is_active = 1'
);
$employee->execute([$employeeId]);
if (!$employee->fetchColumn()) {
    jsonFail('The active employee record could not be found.', 404);
}

$attendanceResult = null;
try {
    $pdo->beginTransaction();
    if ($action === 'save') {
        $saveLock = $pdo->prepare('SELECT employee_id FROM employees WHERE employee_id = ? FOR UPDATE');
        $saveLock->execute([$employeeId]);
        if ($status !== 'Absent' && $status !== 'Leave' && $timeIn !== null && $timeOut === null) {
            $otherOpen = $pdo->prepare(
                'SELECT attendance_date
                 FROM employee_attendance
                 WHERE employee_id = ? AND attendance_date <> ?
                   AND time_in IS NOT NULL AND time_out IS NULL
                 LIMIT 1
                 FOR UPDATE'
            );
            $otherOpen->execute([$employeeId, $date]);
            $otherOpenDate = $otherOpen->fetchColumn();
            if ($otherOpenDate) {
                $pdo->rollBack();
                jsonFail('This employee already has an open attendance record dated ' . $otherOpenDate . '. Add its Time Out before saving another open record.', 409);
            }
        }
        $stmt = $pdo->prepare(
            'INSERT INTO employee_attendance
             (employee_id, attendance_date, status, time_in, time_out, notes, overtime_minutes, overtime_reason, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               status = VALUES(status), time_in = VALUES(time_in), time_out = VALUES(time_out),
               notes = VALUES(notes), overtime_minutes = VALUES(overtime_minutes),
               overtime_reason = VALUES(overtime_reason), recorded_by = VALUES(recorded_by)'
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
        auditLog('SAVE_ATTENDANCE', 'employee_attendance', $employeeId, null, [
            'attendance_date' => $date,
            'status' => $status,
        ]);
        $attendanceResult = ['attendance_date' => $date, 'time_in' => $timeIn, 'time_out' => $timeOut];
    } else {
        $employeeLock = $pdo->prepare(
            'SELECT employee_id
             FROM employees
             WHERE employee_id = ? AND user_id = ? AND is_active = 1
             FOR UPDATE'
        );
        $employeeLock->execute([$employeeId, currentUserId()]);
        if (!$employeeLock->fetchColumn()) {
            $pdo->rollBack();
            jsonFail('Your active employee link changed. Refresh the page and try again.', 409);
        }

        $openQuery = $pdo->prepare(
            'SELECT attendance_id, attendance_date, status, time_in, time_out,
                    overtime_minutes, overtime_reason
             FROM employee_attendance
             WHERE employee_id = ? AND time_in IS NOT NULL AND time_out IS NULL
             ORDER BY attendance_date DESC
             LIMIT 1
             FOR UPDATE'
        );
        $openQuery->execute([$employeeId]);        $openShift = $openQuery->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($action === 'clock_in') {
            if ($openShift) {
                $pdo->rollBack();
                if ($openShift['attendance_date'] === $date) {
                    jsonFail('You are already clocked in.', 409);
                }
                $openStart = new DateTimeImmutable($openShift['attendance_date'] . ' ' . $openShift['time_in']);
                $openMinutes = (int)floor((time() - $openStart->getTimestamp()) / 60);
                jsonFail(
                    $openMinutes > 16 * 60
                        ? 'An earlier attendance record dated ' . $openShift['attendance_date']
                            . ' (Time In ' . substr((string)$openShift['time_in'], 0, 5) . ') is still open and is past the 16-hour shift limit. '
                            . 'Time In is blocked until HR or an administrator corrects that record.'
                        : 'You still have an open shift from ' . $openShift['attendance_date']
                            . '. Clock out before starting another shift.',
                    409
                );
            }

            $todayQuery = $pdo->prepare(
                'SELECT attendance_id, status, time_in, time_out
                 FROM employee_attendance
                 WHERE employee_id = ? AND attendance_date = ?
                 FOR UPDATE'
            );
            $todayQuery->execute([$employeeId, $date]);
            $today = $todayQuery->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($today && $today['time_in'] !== null) {
                $pdo->rollBack();
                jsonFail(
                    $today['time_out'] === null
                        ? 'You are already clocked in.'
                        : 'You have already completed attendance for today.',
                    409
                );
            }
            if ($today && in_array($today['status'], ['Absent', 'Leave'], true)) {
                $pdo->rollBack();
                jsonFail('Your attendance is marked ' . strtolower($today['status']) . ' today. Contact HR if this is incorrect.', 409);
            }

            $timeIn = date('H:i:s');
            if ($today) {
                $insert = $pdo->prepare(
                    "UPDATE employee_attendance
                     SET status = IF(status = 'On Duty', status, 'Present'),
                         time_in = ?, recorded_by = ?
                     WHERE attendance_id = ? AND time_in IS NULL"
                );
                $insert->execute([$timeIn, currentUserId(), (int)$today['attendance_id']]);
                if ($insert->rowCount() !== 1) {
                    $pdo->rollBack();
                    jsonFail('Attendance was updated by another request. Refresh the page and try again.', 409);
                }
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO employee_attendance
                     (employee_id, attendance_date, status, time_in, time_out, overtime_minutes, recorded_by)
                     VALUES (?, ?, \'Present\', ?, NULL, 0, ?)'
                );
                $insert->execute([$employeeId, $date, $timeIn, currentUserId()]);
            }

            auditLog('CLOCK_IN', 'employee_attendance', $employeeId, null, [
                'attendance_date' => $date,
                'time_in' => $timeIn,
            ]);
            $attendanceResult = [
                'attendance_date' => $date,
                'time_in' => $timeIn,
                'time_out' => null,
                'started_at_ms' => (new DateTimeImmutable($date . ' ' . $timeIn))->getTimestamp() * 1000,
                'worked_minutes' => 0,
                'overtime_minutes' => 0,
            ];
        } else {
            if (!$openShift) {
                $pdo->rollBack();
                jsonFail('There is no open shift to clock out of.', 409);
            }

            $date = $openShift['attendance_date'];
            $timeIn = substr((string)$openShift['time_in'], 0, 8);
            $timeOut = date('H:i:s');
            $shiftStart = new DateTimeImmutable($date . ' ' . $timeIn);
            $shiftEnd = new DateTimeImmutable();
            if ($shiftEnd < $shiftStart) {
                $pdo->rollBack();
                jsonFail('System time is earlier than the recorded clock-in time.', 409);
            }
            $duration = (int)floor(($shiftEnd->getTimestamp() - $shiftStart->getTimestamp()) / 60);
            if ($duration > 16 * 60) {
                $pdo->rollBack();
                jsonFail('A shift cannot exceed 16 hours.', 409);
            }
            $overtimeMinutes = max(0, $duration - 8 * 60);
            if ($overtimeMinutes > 0 && (!$overtime || !$overtimeReason)) {
                $pdo->rollBack();
                jsonFail('This shift exceeds 8 hours. Confirm overtime and provide a reason.');
            }
            $overtimeReason = $overtimeReason ?: $openShift['overtime_reason'];

            $update = $pdo->prepare(
                'UPDATE employee_attendance
                 SET time_out = ?, overtime_minutes = ?, overtime_reason = ?, recorded_by = ?
                 WHERE attendance_id = ? AND time_out IS NULL'
            );
            $update->execute([
                $timeOut,
                $overtimeMinutes,
                $overtimeReason,
                currentUserId(),
                (int)$openShift['attendance_id'],
            ]);
            if ($update->rowCount() !== 1) {
                $pdo->rollBack();
                jsonFail('Attendance was updated by another request. Refresh the page and try again.', 409);
            }

            auditLog('CLOCK_OUT', 'employee_attendance', $employeeId, [
                'attendance_date' => $date,
                'time_in' => $timeIn,
            ], [
                'attendance_date' => $date,
                'time_in' => $timeIn,
                'time_out' => $timeOut,
                'overtime_minutes' => $overtimeMinutes,
                'overtime_reason' => $overtimeReason,
            ]);
            $attendanceResult = [
                'attendance_date' => $date,
                'time_in' => $timeIn,
                'time_out' => $timeOut,
                'worked_minutes' => $duration,
                'overtime_minutes' => $overtimeMinutes,
            ];
        }

        $monthStart = date('Y-m-01');
        $daysQuery = $pdo->prepare(
            "SELECT COUNT(DISTINCT attendance_date)
             FROM employee_attendance
             WHERE employee_id = ? AND attendance_date >= ?
               AND status IN ('Present', 'On Duty')"
        );
        $daysQuery->execute([$employeeId, $monthStart]);
        $attendanceResult['days_this_month'] = (int)$daysQuery->fetchColumn();
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($action === 'clock_in' && $e instanceof PDOException && $e->getCode() === '23000') {
        jsonFail("Attendance was recorded by another request. Refresh the page to see today's status.", 409);
    }
    if ($action === 'save' && $e instanceof PDOException && $e->getCode() === '23000') {
        jsonFail('This employee already has another open attendance record. Add its Time Out before saving another open record.', 409);
    }
    error_log('attendance_handler: ' . $e->getMessage());
    jsonFail('Could not update attendance. Please try again.', 500);
}

jsonOk(['attendance' => $attendanceResult], $action === 'clock_in'
    ? 'Time In recorded.'
    : ($action === 'clock_out' ? 'Time Out recorded.' : 'Attendance saved.'));
