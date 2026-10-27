<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/reporting.php';
require_once __DIR__ . '/../app/modules/people/attendance.php';

requireLogin();

$pdo = getDBConnection();
$canViewAll = currentUserHasAnyPermission(['hr.attendance.view']);
$canManage = currentUserHasAnyPermission(['hr.attendance.manage']);
$canReport = currentUserHasAnyPermission(['hr.attendance.report']);
$linkedEmployee = $pdo->prepare(
    "SELECT employee_id,
            COALESCE(
                NULLIF(TRIM(CONCAT_WS(' ',
                    NULLIF(TRIM(first_name), ''),
                    NULLIF(TRIM(middle_name), ''),
                    NULLIF(TRIM(last_name), ''),
                    NULLIF(TRIM(suffix), '')
                )), ''),
                CONCAT('Employee #', employee_id)
            ) AS full_name
     FROM employees
     WHERE user_id = ? AND employment_status = 'Active'
     ORDER BY employee_id"
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
// Historical clock values remain visible, but attendance is now entered
// manually by authorized users.
$attendanceHm = static fn(?string $value): string => ($value !== null && $value !== '')
    ? date('H:i', strtotime($value))
    : '';
$date = $_GET['date'] ?? date('Y-m-d');
if (!is_string($date) || !isValidDate($date)) {
    $date = date('Y-m-d');
}
$employees = $canViewAll
    ? $pdo->query(
        // employee_code is added to the target by
        // db/target_migrations/20261010_s36_employee_fields.sql.
        "SELECT employee_id,
                employee_code,
                COALESCE(
                    NULLIF(TRIM(CONCAT_WS(' ',
                        NULLIF(TRIM(first_name), ''),
                        NULLIF(TRIM(middle_name), ''),
                        NULLIF(TRIM(last_name), ''),
                        NULLIF(TRIM(suffix), '')
                    )), ''),
                    CONCAT('Employee #', employee_id)
                ) AS full_name,
                position
         FROM employees WHERE employment_status = 'Active' ORDER BY full_name"
    )->fetchAll(PDO::FETCH_ASSOC)
    : ($linkedEmployee ? [[
        'employee_id' => $linkedEmployee['employee_id'],
        'employee_code' => '',
        'full_name' => $linkedEmployee['full_name'],
        'position' => '',
    ]] : []);
$attendance = $pdo->prepare(
    // recorded_by and updated_at: who last saved the record and when.
    "SELECT ea.employee_id, ea.status, ea.time_in, ea.time_out, ea.remarks AS notes, ea.overtime_reason,
            ea.updated_at, COALESCE(NULLIF(TRIM(re.full_name), ''), ru.username) AS recorded_by_name
     FROM attendance_records ea
     LEFT JOIN users ru ON ru.user_id = ea.recorded_by
     LEFT JOIN employees re ON re.employee_id = ru.employee_id
     WHERE ea.attendance_date = ?" . (!$canViewAll ? ' AND ea.employee_id = ?' : '')
);
$attendance->execute($canViewAll
    ? [$date]
    : [$date, (int)$linkedEmployee['employee_id']]);
$attendanceByEmployee = [];
foreach ($attendance->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $attendanceByEmployee[(int)$row['employee_id']] = $row;
}
// Totals for the date, from saved records only: an employee without a saved
// record is "Not recorded" (never counted as Present).
$recordedCount = count(array_filter($employees, static fn(array $employee): bool =>
    isset($attendanceByEmployee[(int)$employee['employee_id']])));
$notRecordedCount = count($employees) - $recordedCount;
// The handler refuses past dates (requiredDate(..., true)), so their rows
// are shown but cannot be saved.
$canSaveDate = $canManage && !isPassedDate($date);
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
        // Every selected employee column is listed in GROUP BY so the query
        // is valid under ONLY_FULL_GROUP_BY on MySQL 8 and MariaDB 10.4.
        "SELECT e.employee_id, e.employee_code,
                COALESCE(
                    NULLIF(TRIM(CONCAT_WS(' ',
                        NULLIF(TRIM(e.first_name), ''),
                        NULLIF(TRIM(e.middle_name), ''),
                        NULLIF(TRIM(e.last_name), ''),
                        NULLIF(TRIM(e.suffix), '')
                    )), ''),
                    CONCAT('Employee #', e.employee_id)
                ) AS full_name,
                e.position,
                COUNT(*) AS recorded_days,
                SUM(ea.status = 'Present') AS present_days,
                SUM(ea.status = 'Absent') AS absent_days,
                SUM(ea.status = 'Leave') AS leave_days,
                SUM(ea.status = 'On Duty') AS on_duty_days,
                SUM(ea.overtime_minutes) AS overtime_minutes
         FROM attendance_records ea
         JOIN employees e ON e.employee_id = ea.employee_id
         WHERE " . implode(' AND ', $reportConditions) . "
         GROUP BY e.employee_id, e.employee_code, e.first_name, e.middle_name,
                  e.last_name, e.suffix, e.position
         ORDER BY full_name"
    );
    $reportStmt->execute($reportParams);
    $reportRows = $reportStmt->fetchAll(PDO::FETCH_ASSOC);
}
layoutHead('Attendance');
?>
<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-3">
  <div><h1 class="page-title">Attendance Records</h1><p class="page-subtitle">Review attendance and maintain employee status records.</p></div>
  <form method="get" class="d-flex gap-2"><input class="form-control" type="date" name="date" value="<?= htmlspecialchars($date) ?>"><button class="btn btn-outline-secondary">View date</button></form>
</div>
<div id="attendanceFeedback" class="alert d-none" role="alert"></div>
<?php if (!$linkedEmployee && $canViewAll): ?>
<div class="alert alert-warning" role="status">
  No unique active employee profile is linked to system account #<?= currentUserId() ?>.
  Link its existing employee record before using personal timekeeping.
</div>
<?php endif; ?>
<div class="card"><div class="card-header-custom d-flex justify-content-between align-items-center flex-wrap gap-2"><h2 class="card-title-custom mb-0"><?= $canViewAll ? 'Attendance for ' : 'My attendance for ' ?><?= htmlspecialchars($date) ?></h2>
<?php if ($canViewAll): ?>
<div class="d-flex align-items-center flex-wrap gap-2">
  <span class="small" id="attendanceTotals"><strong><?= $recordedCount ?></strong> recorded / <strong><?= $notRecordedCount ?></strong> not recorded</span>
  <div class="btn-group btn-group-sm" role="group" aria-label="Show rows">
    <button type="button" class="btn btn-outline-secondary active" data-attendance-filter="all">All</button>
    <button type="button" class="btn btn-outline-secondary" data-attendance-filter="missing">Not recorded</button>
    <button type="button" class="btn btn-outline-secondary" data-attendance-filter="recorded">Recorded</button>
  </div>
</div>
<?php endif; ?>
</div>
<?php if ($canManage): ?>
<div class="card-body-custom border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
  <?php if ($canSaveDate): ?>
  <span class="small text-muted">Choose a status for each employee who needs a record, then save all changed rows at once. Rows you did not change are not sent.</span>
  <button type="button" class="btn btn-primary" id="saveAttendanceRows" disabled>Save changed rows (<span id="attendanceChangedCount">0</span>) for <?= htmlspecialchars(date('M j', strtotime($date))) ?></button>
  <?php else: ?>
  <span class="small text-muted">Attendance for a past date cannot be saved (existing rule). Records for this date are shown for review only.</span>
  <?php endif; ?>
</div>
<?php endif; ?>
<div class="table-responsive">
<table class="table align-middle mb-0"><thead><tr><th>Employee</th><th>Position</th><th>Status</th><th>Time in</th><th>Time out</th><th>Notes</th><?php if ($canViewAll): ?><th>Last saved</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($employees as $employee):
    $row = $attendanceByEmployee[(int)$employee['employee_id']] ?? null;
    $savedStatus = $row !== null ? (string)$row['status'] : '';
    $savedNotes = $row !== null ? (string)($row['notes'] ?? '') : '';
?>
<tr data-employee="<?= (int)$employee['employee_id'] ?>" data-recorded="<?= $row !== null ? '1' : '0' ?>"
    data-original-status="<?= htmlspecialchars($savedStatus) ?>" data-original-notes="<?= htmlspecialchars($savedNotes) ?>">
<td><?= htmlspecialchars($employee['full_name']) ?><br><span class="text-muted small"><?= htmlspecialchars($employee['employee_code']) ?></span></td>
<td><?= htmlspecialchars($employee['position']) ?></td>
<?php if ($canManage): ?>
<td><select class="form-select form-select-sm attendance-status" data-refresh-options aria-label="Attendance status for <?= htmlspecialchars($employee['full_name']) ?>"<?= $canSaveDate ? '' : ' disabled' ?>>
<?php if ($row === null): ?><option value="" selected>Not recorded</option><?php endif; ?>
<?php foreach (ATTENDANCE_STATUSES as $statusOption): ?><option value="<?= htmlspecialchars($statusOption) ?>"<?= $savedStatus === $statusOption ? ' selected' : '' ?>><?= htmlspecialchars($statusOption) ?></option><?php endforeach; ?>
</select></td>
<td><?= htmlspecialchars($attendanceHm($row['time_in'] ?? null) ?: '—') ?></td>
<td><?= htmlspecialchars($attendanceHm($row['time_out'] ?? null) ?: '—') ?></td>
<td><input class="form-control form-control-sm attendance-notes" maxlength="500" value="<?= htmlspecialchars($savedNotes) ?>" aria-label="Notes for <?= htmlspecialchars($employee['full_name']) ?>"<?= $canSaveDate ? '' : ' disabled' ?>></td>
<?php else: ?><td><?= $row !== null ? htmlspecialchars($savedStatus) : '<span class="badge text-bg-secondary">Not recorded</span>' ?></td><td><?= htmlspecialchars($attendanceHm($row['time_in'] ?? null) ?: '—') ?></td><td><?= htmlspecialchars($attendanceHm($row['time_out'] ?? null) ?: '—') ?></td><td><?= htmlspecialchars($savedNotes) ?></td><?php endif; ?>
<?php if ($canViewAll): ?>
<td class="small"><?php if ($row !== null): ?><?= htmlspecialchars((string)($row['recorded_by_name'] ?? 'Unknown user')) ?><br><span class="text-muted"><?= htmlspecialchars(date('M j, g:i A', strtotime((string)$row['updated_at']))) ?></span><?php else: ?><span class="badge text-bg-secondary">Not recorded</span><?php endif; ?></td>
<?php endif; ?>
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
<?php if ($canViewAll): ?><script>
// Show filter: All / Not recorded / Recorded. It only hides rows on screen;
// the date, statuses and notes the user typed are untouched.
(() => {
  let attendanceFilter = 'all';
  const applyAttendanceFilter = () => {
    document.querySelectorAll('tr[data-recorded]').forEach(row => {
      const recorded = row.dataset.recorded === '1';
      row.classList.toggle('d-none', (attendanceFilter === 'missing' && recorded) || (attendanceFilter === 'recorded' && !recorded));
    });
    document.querySelectorAll('[data-attendance-filter]').forEach(button =>
      button.classList.toggle('active', button.dataset.attendanceFilter === attendanceFilter));
  };
  document.addEventListener('click', event => {
    const button = event.target.closest?.('[data-attendance-filter]');
    if (!button) return;
    attendanceFilter = button.dataset.attendanceFilter;
    applyAttendanceFilter();
  });
  // A soft refresh after saving redraws the rows: keep the chosen filter.
  document.addEventListener('fleetops:refreshed', applyAttendanceFilter);
})();
</script><?php endif; ?>
<?php if ($canSaveDate): ?><script>
// Batch save: only rows whose status or notes differ from the saved record
// are sent, with what the page showed (expected_status / expected_notes) so
// the server refuses a row someone else changed meanwhile. The server saves
// all rows or none. window.sendAction (assets/js/layout.js) keeps the button
// busy and shows success only when the server confirms it.
(() => {
  const saveButton = document.getElementById('saveAttendanceRows');
  const feedback = document.getElementById('attendanceFeedback');
  let saving = false;
  const isChanged = row => row.querySelector('.attendance-status').value !== row.dataset.originalStatus
    || row.querySelector('.attendance-notes').value.trim() !== row.dataset.originalNotes.trim();
  const changedRows = () => Array.from(document.querySelectorAll('tr[data-recorded]')).filter(isChanged);
  const updateChanged = () => {
    document.querySelectorAll('tr[data-recorded]').forEach(row => row.classList.toggle('table-warning', isChanged(row)));
    const count = changedRows().length;
    // The counter is inside the button, which shows "Saving…" during a save.
    const counter = document.getElementById('attendanceChangedCount');
    if (counter) counter.textContent = count;
    saveButton.disabled = count === 0 || saving;
  };
  document.addEventListener('change', event => { if (event.target.closest?.('tr[data-recorded]')) updateChanged(); });
  document.addEventListener('input', event => { if (event.target.closest?.('tr[data-recorded]')) updateChanged(); });
  document.addEventListener('fleetops:refreshed', updateChanged);

  saveButton.addEventListener('click', async () => {
    const rows = changedRows();
    if (!rows.length) return;
    // A new record needs an explicit status; never assume one.
    const missingStatus = rows.filter(row => row.querySelector('.attendance-status').value === '')
      .map(row => row.querySelector('td').firstChild.textContent.trim());
    if (missingStatus.length) {
      feedback.textContent = 'Nothing was saved. Choose a status for: ' + missingStatus.join(', ') + '.';
      feedback.className = 'alert alert-danger';
      return;
    }
    const payload = rows.map(row => ({
      employee_id: Number(row.dataset.employee),
      status: row.querySelector('.attendance-status').value,
      notes: row.querySelector('.attendance-notes').value,
      expected_status: row.dataset.originalStatus,
      expected_notes: row.dataset.originalNotes
    }));
    saving = true;
    await window.sendAction('<?= APP_BASE ?>/ajax/attendance_handler.php', new URLSearchParams({
      action: 'save_batch',
      attendance_date: '<?= htmlspecialchars($date, ENT_QUOTES) ?>',
      rows: JSON.stringify(payload),
      [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN
    }), { feedback, button: saveButton, label: 'save the attendance' });
    saving = false;
    updateChanged();
  });

  // Leaving the page (another date, another page) with unsaved rows asks
  // first; not while a save is running, as it may reload the page itself.
  window.addEventListener('beforeunload', event => {
    if (!saving && changedRows().length) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
  updateChanged();
})();
</script><?php endif; ?>
<?php layoutFoot(); ?>
