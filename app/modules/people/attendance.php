<?php
// ============================================================
// includes/attendance.php
// Attendance statuses and the checks shared by pages/attendance.php and
// ajax/attendance_handler.php. Admin and Payroll (hr.attendance.manage)
// record attendance for employees; a date and employee without a saved
// attendance_records row is "Not recorded" (never assumed Present).
// ============================================================

// Every value of attendance_records.status, in the order shown.
const ATTENDANCE_STATUSES = ['Present', 'Late', 'Half Day', 'Absent', 'Leave', 'On Duty'];

// Upper limit for one batch save (one row per active employee is far below).
const ATTENDANCE_BATCH_MAX_ROWS = 500;

/**
 * Checks the rows of a batch save before anything is written.
 *
 * $rows: decoded JSON list; each row has employee_id, status, notes and what
 * the page showed when it was loaded: expected_status ('' = not recorded)
 * and expected_notes.
 * $employeeNames: employee_id => name of the active employees.
 *
 * Returns [clean rows, errors]. Each error names the employee, so the user
 * can find the row. Nothing is saved when there is any error.
 */
function validateAttendanceBatch($rows, array $employeeNames): array {
    if (!is_array($rows) || !array_is_list($rows)) {
        return [[], ['The changed rows could not be read. Reload the page and try again.']];
    }
    if (!$rows) {
        return [[], ['There are no changed rows to save.']];
    }
    if (count($rows) > ATTENDANCE_BATCH_MAX_ROWS) {
        return [[], ['Save at most ' . ATTENDANCE_BATCH_MAX_ROWS . ' rows at a time.']];
    }
    $clean = [];
    $errors = [];
    $seen = [];
    foreach ($rows as $index => $row) {
        $employeeId = is_array($row) ? filter_var($row['employee_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($employeeId === false) {
            $errors[] = 'Row ' . ($index + 1) . ': the employee is missing.';
            continue;
        }
        $name = $employeeNames[$employeeId] ?? ('Employee #' . $employeeId);
        if (!isset($employeeNames[$employeeId])) {
            $errors[] = $name . ': no active employee record was found.';
            continue;
        }
        if (isset($seen[$employeeId])) {
            $errors[] = $name . ': this employee appears more than once.';
            continue;
        }
        $seen[$employeeId] = true;
        $status = $row['status'] ?? null;
        $notes = $row['notes'] ?? '';
        $expectedStatus = $row['expected_status'] ?? null;
        $expectedNotes = $row['expected_notes'] ?? '';
        if (!is_string($status) || !in_array($status, ATTENDANCE_STATUSES, true)) {
            $errors[] = $name . ': choose a status (' . implode(', ', ATTENDANCE_STATUSES) . ').';
            continue;
        }
        if (!is_string($notes) || mb_strlen(trim($notes), 'UTF-8') > 500) {
            $errors[] = $name . ': notes must be text of at most 500 characters.';
            continue;
        }
        if (!is_string($expectedStatus) || ($expectedStatus !== '' && !in_array($expectedStatus, ATTENDANCE_STATUSES, true))
            || !is_string($expectedNotes)) {
            $errors[] = $name . ': the row could not be checked against the saved record. Reload the page.';
            continue;
        }
        $notes = trim($notes);
        $clean[] = [
            'employee_id'     => $employeeId,
            'name'            => $name,
            'status'          => $status,
            'notes'           => $notes === '' ? null : $notes,
            'expected_status' => $expectedStatus,
            'expected_notes'  => trim($expectedNotes),
        ];
    }
    return [$errors ? [] : $clean, $errors];
}

/**
 * True when the saved record still matches what the page showed. $existing
 * is the locked attendance_records row or null; $expectedStatus '' means the
 * page showed "Not recorded". Prevents overwriting someone else's save.
 */
function attendanceRecordUnchanged(?array $existing, string $expectedStatus, string $expectedNotes): bool {
    if ($existing === null) {
        return $expectedStatus === '';
    }
    return (string)$existing['status'] === $expectedStatus
        && trim((string)($existing['remarks'] ?? '')) === $expectedNotes;
}
