<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/reporting.php';

requirePermission('hr.attendance.report');
$range = reportDateRange($_GET, 'attendance_from', 'attendance_to');
$employeeId = null;
$employeeRaw = trim((string)($_GET['employee_id'] ?? ''));
if ($employeeRaw !== '') {
    $employeeId = filter_var($employeeRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($employeeId === false) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Employee filter is invalid.';
        exit;
    }
}

$where = [];
$params = [];
if ($range['from'] !== null) {
    $where[] = 'ea.attendance_date >= ?';
    $params[] = $range['from'];
}
if ($range['to'] !== null) {
    $where[] = 'ea.attendance_date <= ?';
    $params[] = $range['to'];
}
if ($employeeId !== null) {
    $where[] = 'ea.employee_id = ?';
    $params[] = $employeeId;
}
// Times stay HH:MM:SS (as the legacy TIME columns exported) for spreadsheets
// that rely on that format; empty times stay empty. Column order matches the
// CSV headers below.
$stmt = getDBConnection()->prepare(
    "SELECT ea.attendance_date, e.employee_code,
            COALESCE(
                NULLIF(TRIM(CONCAT_WS(' ',
                    NULLIF(TRIM(e.first_name), ''),
                    NULLIF(TRIM(e.middle_name), ''),
                    NULLIF(TRIM(e.last_name), ''),
                    NULLIF(TRIM(e.suffix), '')
                )), ''),
                CONCAT('Employee #', e.employee_id)
            ) AS full_name,
            e.position, ea.status,
            DATE_FORMAT(ea.time_in, '%H:%i:%s') AS time_in,
            DATE_FORMAT(ea.time_out, '%H:%i:%s') AS time_out,
            ea.overtime_minutes, ea.overtime_reason, ea.remarks AS notes
     FROM attendance_records ea
     JOIN employees e ON e.employee_id = ea.employee_id
     " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
     ORDER BY ea.attendance_date DESC, full_name"
);
$stmt->execute($params);
$rows = static function () use ($stmt): Generator {
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        yield $row;
    }
};
streamCsvReport(
    'fleetops-attendance.csv',
    ['Date', 'Employee Code', 'Employee', 'Position', 'Status', 'Time In', 'Time Out', 'Overtime Minutes', 'Overtime Reason', 'Notes'],
    $rows()
);
