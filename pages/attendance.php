<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/reporting.php';

requireLogin();

$pdo = getDBConnection();
$canViewAll = currentUserHasAnyPermission(['hr.attendance.view']);
$canManage = currentUserHasAnyPermission(['hr.attendance.manage']);
$canReport = currentUserHasAnyPermission(['hr.attendance.report']);
$linkedEmployee = $pdo->prepare(
    'SELECT employee_id, full_name
     FROM employees
     WHERE user_id = ? AND is_active = 1
     ORDER BY employee_id'
);
$linkedEmployee->execute([currentUserId()]);
$linkedEmployees = $linkedEmployee->fetchAll(PDO::FETCH_ASSOC);
$linkedEmployee = count($linkedEmployees) === 1 ? $linkedEmployees[0] : null;
if (count($linkedEmployees) > 1) {
    error_log('Multiple active employee profiles are linked to user_id ' . currentUserId() . '.');
}
if (!$canViewAll && !$linkedEmployee) {
    http_response_code(409);
    exit(
        'Attendance requires exactly one active employee record linked to your account. '
        . 'Ask HR or an administrator to correct the employee link.'
    );
}
$clock = null;
$clockStale = false;
if ($linkedEmployee) {
    $clockStmt = $pdo->prepare(
        'SELECT attendance_date, time_in, time_out, overtime_minutes, overtime_reason
         FROM employee_attendance
         WHERE employee_id = ? AND (
             attendance_date = ? OR
             (time_in IS NOT NULL AND time_out IS NULL)
         )
         ORDER BY (time_in IS NOT NULL AND time_out IS NULL) DESC, attendance_date DESC LIMIT 1'
    );
    $clockStmt->execute([
        (int)$linkedEmployee['employee_id'],
        date('Y-m-d'),
    ]);
    $clock = $clockStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $clockStale = $clock && $clock['time_in'] && !$clock['time_out']
        && (time() - strtotime($clock['attendance_date'] . ' ' . $clock['time_in'])) > 16 * 3600;
}
$date = $_GET['date'] ?? date('Y-m-d');
if (!is_string($date) || !isValidDate($date)) {
    $date = date('Y-m-d');
}
$employees = $canViewAll
    ? $pdo->query(
        "SELECT employee_id, employee_code, full_name, position
         FROM employees WHERE is_active = 1 ORDER BY full_name"
    )->fetchAll(PDO::FETCH_ASSOC)
    : ($linkedEmployee ? [[
        'employee_id' => $linkedEmployee['employee_id'],
        'employee_code' => '',
        'full_name' => $linkedEmployee['full_name'],
        'position' => '',
    ]] : []);
$attendance = $pdo->prepare(
    'SELECT ea.employee_id, ea.status, ea.time_in, ea.time_out, ea.notes, ea.overtime_reason
     FROM employee_attendance ea
     WHERE ea.attendance_date = ?' . (!$canViewAll ? ' AND ea.employee_id = ?' : '')
);
$attendance->execute($canViewAll
    ? [$date]
    : [$date, (int)$linkedEmployee['employee_id']]);
$attendanceByEmployee = [];
foreach ($attendance->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $attendanceByEmployee[(int)$row['employee_id']] = $row;
}
$reportRange = reportDateRange([
    'from' => $_GET['attendance_from'] ?? date('Y-m-01'),
    'to' => $_GET['attendance_to'] ?? date('Y-m-d'),
]);
$reportFrom = $reportRange['from'] ?? date('Y-m-01');
$reportTo = $reportRange['to'] ?? date('Y-m-d');
$reportEmployeeId = filter_var($_GET['employee_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$reportEmployeeId = $reportEmployeeId === false ? null : $reportEmployeeId;
$reportRows = [];
if ($canReport) {
    $reportConditions = ['ea.attendance_date >= ?', 'ea.attendance_date <= ?'];
    $reportParams = [$reportFrom, $reportTo];
    if ($reportEmployeeId !== null) {
        $reportConditions[] = 'ea.employee_id = ?';
        $reportParams[] = $reportEmployeeId;
    }
    $reportStmt = $pdo->prepare(
        'SELECT e.employee_id, e.employee_code, e.full_name, e.position,
                COUNT(*) AS recorded_days,
                SUM(ea.status = \'Present\') AS present_days,
                SUM(ea.status = \'Absent\') AS absent_days,
                SUM(ea.status = \'Leave\') AS leave_days,
                SUM(ea.status = \'On Duty\') AS on_duty_days,
                SUM(ea.overtime_minutes) AS overtime_minutes
         FROM employee_attendance ea
         JOIN employees e ON e.employee_id = ea.employee_id
         WHERE ' . implode(' AND ', $reportConditions) . '
         GROUP BY e.employee_id, e.employee_code, e.full_name, e.position
         ORDER BY e.full_name'
    );
    $reportStmt->execute($reportParams);
    $reportRows = $reportStmt->fetchAll(PDO::FETCH_ASSOC);
}
layoutHead('Attendance');
?>
<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-3">
  <div><h1 class="page-title">Attendance &amp; Timekeeping</h1><p class="page-subtitle">Record employee attendance, time in/out, and leave status.</p></div>
  <form method="get" class="d-flex gap-2"><input class="form-control" type="date" name="date" value="<?= htmlspecialchars($date) ?>"><button class="btn btn-outline-secondary">View date</button></form>
</div>
<div id="attendanceFeedback" class="alert d-none" role="alert"></div>
<?php if (!$linkedEmployee && $canViewAll): ?>
<div class="alert alert-warning" role="status">
  No unique active employee profile is linked to system account #<?= currentUserId() ?>.
  Link its existing employee record before using personal timekeeping.
</div>
<?php endif; ?>
<?php if ($linkedEmployee): ?>
<div class="card mb-4"><div class="card-header-custom"><h2 class="card-title-custom">My time clock</h2></div><div class="card-body-custom d-flex align-items-center gap-3 flex-wrap">
<span><?= htmlspecialchars($linkedEmployee['full_name']) ?></span>
<?php if ($clockStale): ?><span class="text-danger">Earlier attendance record (<?= htmlspecialchars($clock['attendance_date']) ?>, Time In <?= htmlspecialchars(substr($clock['time_in'], 0, 5)) ?>) is still open and past the 16-hour limit. Clock in is blocked until HR or an administrator adds its Time Out.</span>
<?php elseif (!$clock || !$clock['time_in']): ?><button type="button" class="btn btn-primary" id="clockInButton">Clock in</button>
<?php elseif (!$clock['time_out']): ?><button type="button" class="btn btn-outline-primary" id="clockOutButton">Clock out</button>
<?php else: ?><span class="text-muted">Clocked in <?= htmlspecialchars(substr($clock['time_in'], 0, 5)) ?> and out <?= htmlspecialchars(substr($clock['time_out'], 0, 5)) ?>.</span><?php endif; ?>
<span class="small text-muted">Regular shift: 8 hours. Maximum recorded shift: 16 hours. Overtime requires a reason.</span>
</div></div>
<?php endif; ?>
<div class="card"><div class="card-header-custom"><h2 class="card-title-custom"><?= $canViewAll ? 'Attendance for ' : 'My attendance for ' ?><?= htmlspecialchars($date) ?></h2></div><div class="table-responsive">
<table class="table align-middle mb-0"><thead><tr><th>Employee</th><th>Position</th><th>Status</th><th>Time in</th><th>Time out</th><th>Notes</th><?php if ($canManage): ?><th>Action</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($employees as $employee): $row = $attendanceByEmployee[(int)$employee['employee_id']] ?? ['status' => 'Present', 'time_in' => '', 'time_out' => '', 'notes' => '']; ?>
<tr data-employee="<?= (int)$employee['employee_id'] ?>">
<td><?= htmlspecialchars($employee['full_name']) ?><br><span class="text-muted small"><?= htmlspecialchars($employee['employee_code']) ?></span></td>
<td><?= htmlspecialchars($employee['position']) ?></td>
<?php if ($canManage): ?>
<td><select class="form-select form-select-sm attendance-status"><option <?= $row['status'] === 'Present' ? 'selected' : '' ?>>Present</option><option <?= $row['status'] === 'Absent' ? 'selected' : '' ?>>Absent</option><option <?= $row['status'] === 'Leave' ? 'selected' : '' ?>>Leave</option><option <?= $row['status'] === 'On Duty' ? 'selected' : '' ?>>On Duty</option></select></td>
<td><input class="form-control form-control-sm attendance-in" type="time" value="<?= htmlspecialchars((string)($row['time_in'] ?? '')) ?>"></td>
<td><input class="form-control form-control-sm attendance-out" type="time" value="<?= htmlspecialchars((string)($row['time_out'] ?? '')) ?>"></td>
<td><input class="form-control form-control-sm attendance-notes" maxlength="500" value="<?= htmlspecialchars((string)($row['notes'] ?? '')) ?>"><input class="form-control form-control-sm mt-1 attendance-overtime-reason" maxlength="255" placeholder="Overtime reason (if needed)" value="<?= htmlspecialchars((string)($row['overtime_reason'] ?? '')) ?>"></td>
<td><button type="button" class="btn btn-sm btn-primary save-attendance">Save</button></td>
<?php else: ?><td><?= htmlspecialchars($row['status']) ?></td><td><?= htmlspecialchars($row['time_in'] ?? '—') ?></td><td><?= htmlspecialchars($row['time_out'] ?? '—') ?></td><td><?= htmlspecialchars($row['notes'] ?? '') ?></td><?php endif; ?>
</tr>
<?php endforeach; ?>
<?php if (!$employees): ?><tr><td colspan="7" class="text-center text-muted py-4">No active employees found.</td></tr><?php endif; ?></tbody></table></div></div>
<?php if ($canReport): ?>
<div class="card mt-4">
  <div class="card-header-custom d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h2 class="card-title-custom mb-0">Attendance report</h2>
    <a class="btn btn-sm btn-outline-primary" href="<?= APP_BASE ?>/ajax/attendance_export.php?<?= htmlspecialchars(http_build_query(['attendance_from' => $reportFrom, 'attendance_to' => $reportTo, 'employee_id' => $reportEmployeeId]), ENT_QUOTES) ?>">Export CSV</a>
  </div>
  <div class="card-body-custom">
    <form method="get" class="row g-2 mb-3">
      <div class="col-md-3"><label class="form-label">From</label><input class="form-control" type="date" name="attendance_from" value="<?= htmlspecialchars($reportFrom) ?>"></div>
      <div class="col-md-3"><label class="form-label">To</label><input class="form-control" type="date" name="attendance_to" value="<?= htmlspecialchars($reportTo) ?>"></div>
      <div class="col-md-4"><label class="form-label">Employee</label><select class="form-select" name="employee_id"><option value="">All employees</option><?php foreach ($employees as $employee): ?><option value="<?= (int)$employee['employee_id'] ?>" <?= $reportEmployeeId === (int)$employee['employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars($employee['full_name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2 align-self-end"><button class="btn btn-primary w-100">Apply</button></div>
    </form>
    <div class="table-responsive"><table class="table align-middle mb-0">
      <thead><tr><th>Employee</th><th>Recorded days</th><th>Present</th><th>Absent</th><th>Leave</th><th>On duty</th><th>Overtime</th></tr></thead>
      <tbody>
      <?php foreach ($reportRows as $report): ?><tr><td><?= htmlspecialchars($report['full_name']) ?><br><span class="text-muted small"><?= htmlspecialchars($report['employee_code'] . ' · ' . $report['position']) ?></span></td><td><?= (int)$report['recorded_days'] ?></td><td><?= (int)$report['present_days'] ?></td><td><?= (int)$report['absent_days'] ?></td><td><?= (int)$report['leave_days'] ?></td><td><?= (int)$report['on_duty_days'] ?></td><td><?= number_format((int)$report['overtime_minutes'] / 60, 2) ?> hrs</td></tr><?php endforeach; ?>
      <?php if (!$reportRows): ?><tr><td colspan="7" class="text-center text-muted py-4">No attendance records for this filter.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php endif; ?>
<?php if ($canManage || $linkedEmployee): ?><script>
const sendAttendance = async (data) => {
  const feedback = document.getElementById('attendanceFeedback');
  try {
    const response = await fetch('<?= APP_BASE ?>/ajax/attendance_handler.php', {
      method: 'POST',
      body: data,
      headers: { Accept: 'application/json' }
    });
    const result = await response.json();
    if (!response.ok || !result.success) {
      throw new Error(result.message || 'Attendance could not be updated.');
    }
    feedback.textContent = result.message || 'Attendance updated.';
    feedback.className = 'alert alert-success';
    setTimeout(() => window.location.reload(), 500);
  } catch (error) {
    feedback.textContent = error.message || 'Attendance could not be updated. Please try again.';
    feedback.className = 'alert alert-danger';
  }
};
document.getElementById('clockInButton')?.addEventListener('click', () => sendAttendance(new URLSearchParams({action:'clock_in', [window.CSRF_TOKEN_NAME]:window.CSRF_TOKEN})));
document.getElementById('clockOutButton')?.addEventListener('click', () => {
  const reason = prompt('If this shift is over 8 hours, enter the overtime reason. Otherwise leave blank.') || '';
  sendAttendance(new URLSearchParams({action:'clock_out', overtime:reason !== '', overtime_reason:reason, [window.CSRF_TOKEN_NAME]:window.CSRF_TOKEN}));
});
<?php if ($canManage): ?>
document.querySelectorAll('.save-attendance').forEach(button => button.addEventListener('click', async () => {
  const row = button.closest('tr');
  const data = new URLSearchParams({action:'save', employee_id:row.dataset.employee, attendance_date:'<?= htmlspecialchars($date, ENT_QUOTES) ?>', status:row.querySelector('.attendance-status').value, time_in:row.querySelector('.attendance-in').value, time_out:row.querySelector('.attendance-out').value, overtime:row.querySelector('.attendance-overtime-reason').value !== '', overtime_reason:row.querySelector('.attendance-overtime-reason').value, notes:row.querySelector('.attendance-notes').value, [window.CSRF_TOKEN_NAME]:window.CSRF_TOKEN});
  sendAttendance(data);
}));
<?php endif; ?>
</script><?php endif; ?>
<?php layoutFoot(); ?>
