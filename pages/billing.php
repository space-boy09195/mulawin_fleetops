<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/finance_records.php';

requireAnyPermission(['billing.view', 'billing.manage']);
$isHead = isAdmin();
// Billing and Collection staff see billings and collections only. The
// payroll report is reviewed by Finance as well as Payroll
// (client questionnaire Q1: "Finance Personnel / Audit Personnel /
// Management"), so it needs payroll.view or finance.view. Recording a
// salary payment still needs payroll.manage, as in ajax/payroll_handler.php.
$canViewPayroll   = currentUserHasAnyPermission(['payroll.view', 'finance.view']);
$canManagePayroll = currentUserHasAnyPermission(['payroll.manage']);
$canManageBilling = currentUserHasAnyPermission(['billing.manage']);
$needsPayTotals   = $canViewPayroll;

$GLOBALS['page_js'] = APP_BASE . '/assets/js/billing.js';

layoutHead('Billing & Collections', APP_BASE . '/assets/css/billing.css');

$pdo = getDBConnection();
$clients = $pdo->query("SELECT client_name FROM clients WHERE status = 'Active' ORDER BY client_name")->fetchAll(PDO::FETCH_COLUMN);

// ── Period filter (scopes the billing and collection lists below) ────────────
$periods = [
    'today' => 'Today',
    '1w'    => 'Last 7 Days',
    '1m'    => 'Last 1 Month',
    '3m'    => 'Last 3 Months',
    '6m'    => 'Last 6 Months',
    '1y'    => 'Last 12 Months',
    'all'   => 'All Time',
];
$period = $_GET['period'] ?? 'all';
if (!isset($periods[$period])) $period = 'all';
$rangeStart = match ($period) {
    'today' => new DateTime('today'),
    '1w'    => (new DateTime('today'))->modify('-6 days'),
    '1m'    => (new DateTime('today'))->modify('-1 months'),
    '3m'    => (new DateTime('today'))->modify('-3 months'),
    '6m'    => (new DateTime('today'))->modify('-6 months'),
    '1y'    => (new DateTime('today'))->modify('-12 months'),
    default => null,
};
$rangeStartDate = $rangeStart ? $rangeStart->format('Y-m-d') : null;
// A bounded period ends today: the half-open window [start, tomorrow) keeps
// records dated after today out, as on the accounting dashboard. All Time
// stays unbounded.
$rangeEnd = $rangeStart ? new DateTime('tomorrow') : null;
$rangeEndDate = $rangeEnd ? $rangeEnd->format('Y-m-d') : null;
// Every figure on this page uses the definitions in includes/finance_records.php.
$billingDateFilter    = $rangeStartDate ? "AND b.billing_date >= :rangeStart AND b.billing_date < :rangeEnd" : '';
$collectionDateFilter = $rangeStartDate ? "AND c.payment_date >= :rangeStart AND c.payment_date < :rangeEnd" : '';

// ── Billings (target billings + collections) ─────────────────────────────────
// Status labels are normalized for the view; they are not recomputed from
// due dates because billing terms and the overdue rule are not confirmed.
$billingSql = "
    SELECT
        b.billing_id,
        b.invoice_number AS billing_number,
        b.invoice_number,
        b.billing_date AS invoice_date,
        c.client_name,
        b.total_amount AS amount,
        b.due_date,
        CASE b.status WHEN 'Issued' THEN 'Unpaid' WHEN 'Overdue' THEN 'Unpaid'
                      WHEN 'Partially Paid' THEN 'Partial' ELSE b.status END AS status,
        b.status AS target_status,
        NULL AS notes,
        b.created_at,
        t.trip_number,
        COALESCE(ue.full_name, u.username) AS created_by_name,
        " . billingCollectedSql('b') . " AS total_collected,
        b.total_amount - " . billingCollectedSql('b') . " AS balance
    FROM billings b
    JOIN trips t   ON b.trip_id   = t.trip_id
    JOIN clients c ON c.client_id = b.client_id
    JOIN users u   ON b.created_by = u.user_id
    LEFT JOIN employees ue ON ue.employee_id = u.employee_id
    WHERE b.status NOT IN ('Draft', 'Cancelled') $billingDateFilter
    ORDER BY b.billing_date DESC, b.billing_id DESC
    LIMIT 2000
";
$billingStmt = $pdo->prepare($billingSql);
if ($rangeStartDate) {
    $billingStmt->bindValue(':rangeStart', $rangeStartDate);
    $billingStmt->bindValue(':rangeEnd', $rangeEndDate);
}
$billingStmt->execute();
$billings = $billingStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Collections (recent 150) ──────────────────────────────────────────────────
$collectionsSql = "
    SELECT
        c.collection_id,
        c.amount AS amount_paid,
        c.payment_date,
        c.payment_method AS payment_mode,
        c.reference_number AS reference_no,
        NULL AS remarks,
        c.created_at,
        b.invoice_number AS billing_number,
        b.billing_id,
        cl.client_name,
        COALESCE(ue.full_name, u.username) AS recorded_by_name
    FROM collections c
    JOIN billings b  ON c.billing_id  = b.billing_id
    JOIN clients cl  ON cl.client_id  = c.client_id
    JOIN users u     ON c.received_by = u.user_id
    LEFT JOIN employees ue ON ue.employee_id = u.employee_id
    WHERE 1=1 $collectionDateFilter
    ORDER BY c.payment_date DESC, c.collection_id DESC
    LIMIT 150
";
$collectionsStmt = $pdo->prepare($collectionsSql);
if ($rangeStartDate) {
    $collectionsStmt->bindValue(':rangeStart', $rangeStartDate);
    $collectionsStmt->bindValue(':rangeEnd', $rangeEndDate);
}
$collectionsStmt->execute();
$collections = $collectionsStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Trips with Completed status and no billing ────────────────────────────────
// The target allows one billing per trip (uq_billing_trip), so the new
// billing dropdown and the unbilled list are the same set.
$unbilledSql = "
    SELECT t.trip_id, t.trip_number,
           COALESCE(r.origin, t.origin) AS origin, COALESCE(r.destination, t.destination) AS destination,
           c.client_name, t.scheduled_departure AS scheduled_at
    FROM trips t
    JOIN clients c       ON c.client_id = t.client_id
    LEFT JOIN routes r   ON r.route_id  = t.route_id
    LEFT JOIN billings b ON b.trip_id   = t.trip_id
    WHERE t.status = 'Completed' AND b.billing_id IS NULL
    ORDER BY t.trip_number DESC
    LIMIT 1000
";
$unbilledTrips = $pdo->query($unbilledSql)->fetchAll(PDO::FETCH_ASSOC);

// ── Active employees (for the Log Payroll Payment form) ───────────────────────
// Drivers and Helpers are on-call and receive per-trip wages through Trip
// Pay. Keep them out of this periodic fixed-salary payroll form; Driver
// Allowance remains a separate trip expense and may coexist with Trip Pay.
$activeEmployees = !$canManagePayroll ? [] : $pdo->query("
    SELECT employee_id, full_name, position
    FROM employees
    WHERE employment_status = 'Active'
      AND (position IS NULL OR position NOT IN ('Driver', 'Helper'))
    ORDER BY full_name
")->fetchAll(PDO::FETCH_ASSOC);

// ── Payroll: payroll_records + payroll_periods; paid date from the record's
// disbursement (includes/finance_records.php).
$payrollSql = "
    SELECT * FROM (
        SELECT
            pr.payroll_record_id AS payroll_id, pr.employee_id,
            pp.period_start AS pay_period_start, pp.period_end AS pay_period_end,
            pr.net_amount AS amount_paid, " . payrollPaidDateSql('pr') . " AS paid_date,
            " . payrollNotesSql('pr') . " AS notes,
            e.full_name AS employee_name, e.position,
            '—' AS recorded_by_name, pr.created_at
        FROM payroll_records pr
        JOIN payroll_periods pp ON pp.payroll_period_id = pr.payroll_period_id
        JOIN employees e ON pr.employee_id = e.employee_id
        WHERE pr.status = 'Disbursed'
    ) payroll_rows
    WHERE 1=1 " . ($rangeStartDate ? "AND paid_date >= :rangeStart AND paid_date < :rangeEnd" : "") . "
    ORDER BY paid_date DESC, created_at DESC
";
$payrollRecords = [];
if ($needsPayTotals) {
    $payrollStmt = $pdo->prepare($payrollSql);
    if ($rangeStartDate) {
        $payrollStmt->bindValue(':rangeStart', $rangeStartDate);
        $payrollStmt->bindValue(':rangeEnd', $rangeEndDate);
    }
    $payrollStmt->execute();
    $payrollRecords = $payrollStmt->fetchAll(PDO::FETCH_ASSOC);
}
// ── Crew pay: Driver/Helper per-trip wages (trip_pay_records, T-P1) ───────────
// Dated by the trip (completion, else scheduled departure); the entered
// paid date is shown from the notes.
$tripPaySql = "
    SELECT
        tp.trip_pay_id, tp.employee_id,
        IF(tp.pay_type = 'Helper Trip Pay', 'Helper', 'Driver') AS crew_role,
        tp.amount, tp.notes AS stored_notes, " . tripPayDateSql('t') . " AS trip_date,
        e.full_name AS employee_name, e.position,
        t.trip_number,
        COALESCE(ue.full_name, u.username, '—') AS recorded_by_name
    FROM trip_pay_records tp
    JOIN employees e ON tp.employee_id = e.employee_id
    JOIN trips t     ON tp.trip_id     = t.trip_id
    LEFT JOIN users u ON tp.recorded_by = u.user_id
    LEFT JOIN employees ue ON ue.employee_id = u.employee_id
    WHERE tp.status <> 'Cancelled' " . ($rangeStartDate ? "AND " . tripPayDateSql('t') . " >= :rangeStart AND " . tripPayDateSql('t') . " < :rangeEnd" : "") . "
    ORDER BY trip_date DESC, tp.trip_pay_id DESC
";
$tripPayRecords = [];
if ($needsPayTotals) {
    $tripPayStmt = $pdo->prepare($tripPaySql);
    if ($rangeStartDate) {
        $tripPayStmt->bindValue(':rangeStart', $rangeStartDate);
        $tripPayStmt->bindValue(':rangeEnd', $rangeEndDate);
    }
    $tripPayStmt->execute();
    $tripPayRecords = $tripPayStmt->fetchAll(PDO::FETCH_ASSOC);
}
foreach ($tripPayRecords as &$tripPayRow) {
    $split = tripPaySplitNotes($tripPayRow['stored_notes']);
    $tripPayRow['paid_date'] = $split['paid_date'] ?? $tripPayRow['trip_date'];
    $tripPayRow['notes'] = $split['notes'];
}
unset($tripPayRow);
$paymentModes = PAYMENT_MODES;

?>

<div class="bil-page">

  <!-- Header -->
  <div class="bil-header d-flex align-items-center justify-content-between mb-4">
    <div>
      <h1 class="bil-title mb-0">Billing &amp; Collections</h1>
      <p class="bil-subtitle mb-0">Manage billing statements and payment collections</p>
    </div>
    <div class="d-flex align-items-center gap-2">
      <form method="get" class="d-flex">
        <select name="period" class="form-select bil-period-select" onchange="this.form.submit()">
          <?php foreach ($periods as $key => $label): ?>
          <option value="<?= $key ?>" <?= $key === $period ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php if ($canManageBilling): ?>
      <button class="btn btn-bil-primary" data-bs-toggle="modal" data-bs-target="#createBillingModal">
        <i class="bi bi-plus-lg me-1"></i> Create Billing
      </button>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($period !== 'all'): ?>
  <p class="bil-period-note">
    <i class="bi bi-funnel"></i> Showing <?= htmlspecialchars($periods[$period]) ?> —
    billing, collection and (when available) payroll/Trip Pay records use this period; the no-billing trip list is not period-filtered.
  </p>
  <?php endif; ?>

  <!-- Tabs -->
  <ul class="nav bil-tabs mb-4" id="bilTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="bil-tab active" id="tab-billings" data-bs-toggle="tab"
              data-bs-target="#pane-billings" type="button" role="tab">
        <i class="bi bi-receipt me-1"></i> Billings
        <span class="bil-tab-count"><?= count($billings) ?></span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="bil-tab" id="tab-unbilled" data-bs-toggle="tab"
              data-bs-target="#pane-unbilled" type="button" role="tab">
        <i class="bi bi-exclamation-circle me-1"></i> Completed-Status Trips Without Billing
        <span class="bil-tab-count"><?= count($unbilledTrips) ?></span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="bil-tab" id="tab-collections" data-bs-toggle="tab"
              data-bs-target="#pane-collections" type="button" role="tab">
        <i class="bi bi-cash-stack me-1"></i> Collections
        <span class="bil-tab-count"><?= count($collections) ?></span>
      </button>
    </li>
    <?php if ($canViewPayroll): ?>
    <li class="nav-item" role="presentation">
      <button class="bil-tab" id="tab-payroll" data-bs-toggle="tab"
              data-bs-target="#pane-payroll" type="button" role="tab">
        <i class="bi bi-people me-1"></i> Payroll &amp; Trip Pay
        <span class="bil-tab-count"><?= count($payrollRecords) + count($tripPayRecords) ?></span>
      </button>
    </li>
    <?php endif; ?>
  </ul>

  <div class="tab-content">

    <!-- ── Billings pane ─────────────────────────────────────────────────── -->
    <div class="tab-pane fade show active" id="pane-billings" role="tabpanel">

      <div class="bil-filters d-flex flex-wrap gap-2 mb-3">
        <select id="filterBilStatus" class="form-select bil-filter-select">
          <option value="">All Statuses</option>
          <option value="Unpaid">Unpaid</option>
          <option value="Partial">Partial</option>
          <option value="Paid">Paid</option>
        </select>
        <input type="search" id="filterBilSearch" class="form-control bil-filter-search"
               placeholder="Search billing no., trip, client…">
        <?php if (currentUserHasAnyPermission(['billing.export'])): ?>
        <a class="btn btn-bil-outline ms-auto" href="<?= APP_BASE ?>/ajax/billing_export.php?period=<?= urlencode($period) ?>">
          <i class="bi bi-download me-1"></i> Download AR Report (CSV)
        </a>
        <?php endif; ?>
      </div>

      <div class="bil-table-wrap">
        <?php if (empty($billings)): ?>
        <div class="bil-empty">
          <i class="bi bi-receipt bil-empty-icon"></i>
          <p>No billings created yet.</p>
        </div>
        <?php else: ?>
        <table class="table bil-table" id="billingsTable">
          <thead>
            <tr>
              <th>Billing No.</th>
              <th>Invoice No.</th>
              <th>Trip</th>
              <th>Client</th>
              <th>Amount</th>
              <th>Collected</th>
              <th>Balance</th>
              <th>Due Date</th>
              <th>Status</th>
              <th>Created By</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($billings as $bil): ?>
            <tr
              data-status="<?= htmlspecialchars($bil['status']) ?>"
              data-search="<?= htmlspecialchars(strtolower(
                $bil['billing_number'] . ' ' . $bil['trip_number'] . ' ' . ($bil['client_name'] ?? '') . ' ' . ($bil['invoice_number'] ?? '')
              )) ?>"
              data-id="<?= $bil['billing_id'] ?>"
            >
              <td>
                <span class="bil-number"><?= htmlspecialchars($bil['billing_number']) ?></span>
              </td>
              <td class="bil-invoice-number">
                <?= $bil['invoice_number'] ? htmlspecialchars($bil['invoice_number']) : '<span class="text-muted">—</span>' ?>
              </td>
              <td>
                <span class="bil-trip-ref"><?= htmlspecialchars($bil['trip_number']) ?></span>
              </td>
              <td class="bil-client">
                <?= $bil['client_name'] ? htmlspecialchars($bil['client_name']) : '<span class="text-muted">—</span>' ?>
              </td>
              <td class="bil-amount">₱<?= number_format($bil['amount'], 2) ?></td>
              <td class="bil-collected">₱<?= number_format($bil['total_collected'], 2) ?></td>
              <td class="bil-balance <?= $bil['balance'] > 0 ? 'bil-balance-due' : '' ?>">
                ₱<?= number_format($bil['balance'], 2) ?>
              </td>
              <td class="bil-date">
                <?= $bil['due_date'] ? date('M d, Y', strtotime($bil['due_date'])) : '<span class="text-muted">—</span>' ?>
              </td>
              <td>
                <span class="bil-status-badge bil-status-<?= strtolower($bil['status']) ?>">
                  <?= $bil['status'] ?>
                </span>
              </td>
              <td class="bil-created-by"><?= htmlspecialchars($bil['created_by_name']) ?></td>
              <td class="text-end">
                <?php if ($canManageBilling && $bil['status'] !== 'Paid'): ?>
                <button class="btn btn-sm btn-bil-collect"
                        data-id="<?= $bil['billing_id'] ?>"
                        data-number="<?= htmlspecialchars($bil['billing_number']) ?>"
                        data-balance="<?= $bil['balance'] ?>">
                  Record Payment
                </button>
                <?php elseif ($bil['status'] === 'Paid'): ?>
                <span class="bil-paid-check" title="Fully paid">
                  <i class="bi bi-check-circle-fill text-success"></i>
                </span>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div id="noBillingResults" class="no-results d-none">
          <i class="bi bi-search"></i>
          <span>No billings match your filters.</span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Trips with Completed status and no billing ───────────────────── -->
    <div class="tab-pane fade" id="pane-unbilled" role="tabpanel">
      <?php if (empty($unbilledTrips)): ?>
      <div class="bil-empty">
        <i class="bi bi-check-circle bil-empty-icon"></i>
        <p>No trips with Completed status are missing billing.</p>
      </div>
      <?php else: ?>
      <div class="bil-table-wrap">
        <table class="table bil-table">
          <thead>
            <tr>
              <th>Trip</th>
              <th>Route</th>
              <th>Client</th>
              <th>Scheduled</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($unbilledTrips as $ut): ?>
            <tr>
              <td><span class="bil-trip-ref"><?= htmlspecialchars($ut['trip_number']) ?></span></td>
              <td><?= htmlspecialchars($ut['origin']) ?> → <?= htmlspecialchars($ut['destination']) ?></td>
              <td><?= $ut['client_name'] ? htmlspecialchars($ut['client_name']) : '<span class="text-muted">—</span>' ?></td>
              <td><?= $ut['scheduled_at'] ? date('M d, Y', strtotime($ut['scheduled_at'])) : '<span class="text-muted">—</span>' ?></td>
              <td class="text-end">
                <?php if ($canManageBilling): ?>
                <button class="btn btn-sm btn-bil-primary bil-unbilled-create-btn"
                        data-trip-id="<?= $ut['trip_id'] ?>"
                        data-bs-toggle="modal" data-bs-target="#createBillingModal">
                  Create Billing
                </button>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Collections pane ─────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="pane-collections" role="tabpanel">

      <div class="bil-filters d-flex flex-wrap gap-2 mb-3">
        <select id="filterColMode" class="form-select bil-filter-select">
          <option value="">All Payment Modes</option>
          <?php foreach ($paymentModes as $pm): ?>
          <option value="<?= $pm ?>"><?= $pm ?></option>
          <?php endforeach; ?>
        </select>
        <input type="search" id="filterColSearch" class="form-control bil-filter-search"
               placeholder="Search billing no., client, reference…">
      </div>

      <div class="bil-table-wrap">
        <?php if (empty($collections)): ?>
        <div class="bil-empty">
          <i class="bi bi-cash-stack bil-empty-icon"></i>
          <p>No payments recorded yet.</p>
        </div>
        <?php else: ?>
        <table class="table bil-table" id="collectionsTable">
          <thead>
            <tr>
              <th>Billing No.</th>
              <th>Client</th>
              <th>Amount Paid</th>
              <th>Payment Mode</th>
              <th>Reference No.</th>
              <th>Payment Date</th>
              <th>Remarks</th>
              <th>Recorded By</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($collections as $col): ?>
            <tr
              data-mode="<?= htmlspecialchars($col['payment_mode']) ?>"
              data-search="<?= htmlspecialchars(strtolower(
                $col['billing_number'] . ' ' . ($col['client_name'] ?? '') . ' ' . ($col['reference_no'] ?? '')
              )) ?>"
            >
              <td><span class="bil-number"><?= htmlspecialchars($col['billing_number']) ?></span></td>
              <td class="bil-client">
                <?= $col['client_name'] ? htmlspecialchars($col['client_name']) : '<span class="text-muted">—</span>' ?>
              </td>
              <td class="bil-amount bil-paid-amount">₱<?= number_format($col['amount_paid'], 2) ?></td>
              <td>
                <span class="bil-mode-badge"><?= htmlspecialchars($col['payment_mode']) ?></span>
              </td>
              <td class="bil-ref">
                <?= $col['reference_no'] ? htmlspecialchars($col['reference_no']) : '<span class="text-muted">—</span>' ?>
              </td>
              <td class="bil-date"><?= date('M d, Y', strtotime($col['payment_date'])) ?></td>
              <td class="bil-desc-cell">
                <span class="bil-desc-text" title="<?= htmlspecialchars($col['remarks'] ?? '') ?>">
                  <?= $col['remarks'] ? htmlspecialchars($col['remarks']) : '<span class="text-muted">—</span>' ?>
                </span>
              </td>
              <td><?= htmlspecialchars($col['recorded_by_name']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div id="noCollectionResults" class="no-results d-none">
          <i class="bi bi-search"></i>
          <span>No payments match your filters.</span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($canViewPayroll): ?>
    <!-- ── Payroll pane (payroll.view) ───────────────────────────────────── -->
    <div class="tab-pane fade" id="pane-payroll" role="tabpanel">

      <?php
      // ── Show periodic payroll_records and per-trip trip_pay together in a
      //    date-sorted disbursement report while keeping their types distinct. ──
      $payrollReportRows = [];
      foreach ($payrollRecords as $pr) {
          $payrollReportRows[] = [
              'type'         => 'Salary',
              'employee_name'=> $pr['employee_name'],
              'position'     => $pr['position'],
              'reference'    => date('M d', strtotime($pr['pay_period_start'])) . ' – ' . date('M d, Y', strtotime($pr['pay_period_end'])),
              'amount'       => (float)$pr['amount_paid'],
              'paid_date'    => $pr['paid_date'],
              'recorded_by'  => $pr['recorded_by_name'],
              'notes'        => $pr['notes'],
              'delete_type'  => 'payroll',
              'delete_id'    => $pr['payroll_id'],
          ];
      }
      foreach ($tripPayRecords as $tp) {
          $payrollReportRows[] = [
              'type'         => 'Trip Pay',
              'employee_name'=> $tp['employee_name'],
              'position'     => $tp['crew_role'],
              'reference'    => 'Trip ' . $tp['trip_number'],
              'amount'       => (float)$tp['amount'],
              'paid_date'    => $tp['paid_date'],
              'recorded_by'  => $tp['recorded_by_name'],
              'notes'        => $tp['notes'],
              'delete_type'  => 'trip_pay',
              'delete_id'    => $tp['trip_pay_id'],
          ];
      }
      usort($payrollReportRows, fn($a, $b) => strcmp($b['paid_date'], $a['paid_date']));
      ?>

      <div class="bil-payroll-note">
        <i class="bi bi-info-circle me-1"></i>
        This disbursement report lists periodic payroll (Salary) and per-trip Driver/Helper wages (Trip Pay) as separate types.
        It does not include <strong>Driver Allowance</strong>, a trip expense/reimbursement rather than wages; allowance expenses
        are tracked separately on the Trip Costs page and may coexist with Trip Pay.
      </div>

      <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <span class="text-muted small">Individual disbursement records for <?= htmlspecialchars($periods[$period]) ?>.</span>
        <div class="d-flex gap-2 flex-wrap">
          <?php if (!$isHead && $canManagePayroll): ?>
          <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#sendPayrollReportModal">
            <i class="bi bi-send me-1"></i> Send to Head
          </button>
          <?php endif; ?>
          <?php if ($canManagePayroll): ?>
          <button class="btn btn-bil-primary btn-sm" data-bs-toggle="modal" data-bs-target="#logPayrollModal">
            <i class="bi bi-plus-lg me-1"></i> Log Salary Payment
          </button>
          <?php endif; ?>
        </div>
      </div>

      <div class="bil-table-wrap">
        <?php if (empty($payrollReportRows)): ?>
        <div class="no-results">
          <i class="bi bi-cash-coin"></i>
          <span>No payroll or Trip Pay logged for this period yet.</span>
        </div>
        <?php else: ?>
        <table class="table bil-table">
          <thead>
            <tr>
              <th>Type</th>
              <th>Employee</th>
              <th>Position / Role</th>
              <th>Reference</th>
              <th>Amount</th>
              <th>Paid Date</th>
              <th>Recorded By</th>
              <th>Notes</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($payrollReportRows as $pr): ?>
            <tr>
              <td><span class="badge <?= $pr['type'] === 'Salary' ? 'bg-primary-subtle text-primary-emphasis' : 'bg-success-subtle text-success-emphasis' ?>"><?= $pr['type'] ?></span></td>
              <td><?= htmlspecialchars($pr['employee_name']) ?></td>
              <td><?= htmlspecialchars($pr['position']) ?></td>
              <td><?= htmlspecialchars($pr['reference']) ?></td>
              <td>₱<?= number_format($pr['amount'], 2) ?></td>
              <td><?= date('M d, Y', strtotime($pr['paid_date'])) ?></td>
              <td><?= htmlspecialchars($pr['recorded_by']) ?></td>
              <td><?= $pr['notes'] ? htmlspecialchars($pr['notes']) : '<span class="text-muted">—</span>' ?></td>
              <td>
                <?php if ($isHead): ?>
                <button type="button" class="btn btn-sm btn-outline-danger bil-delete-payroll-btn"
                        data-id="<?= $pr['delete_id'] ?>" data-type="<?= $pr['delete_type'] ?>" title="Delete">
                  <i class="bi bi-trash"></i>
                </button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

    </div>
    <?php endif; ?>

  </div><!-- /tab-content -->
</div>

<?php if ($canManageBilling): ?>
<!-- ══ Create Billing Modal ════════════════════════════════════════════════ -->
<div class="modal fade" id="createBillingModal" tabindex="-1" aria-labelledby="createBillingLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content bil-modal-content">
      <div class="modal-header bil-modal-header-primary">
        <h5 class="modal-title" id="createBillingLabel">
          <i class="bi bi-receipt me-2"></i>Create Billing
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body bil-modal-body">
        <div id="billingFormAlert" class="alert d-none" role="alert"></div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label bil-label" for="bilTripId">Trip</label>
            <select class="form-select bil-input" id="bilTripId" required>
              <option value="">— Select trip with Completed status —</option>
              <?php foreach ($unbilledTrips as $tr): ?>
              <option value="<?= (int)$tr['trip_id'] ?>" data-client="<?= htmlspecialchars($tr['client_name'] ?? '', ENT_QUOTES) ?>">
                <?= htmlspecialchars($tr['trip_number']) ?> —
                <?= htmlspecialchars($tr['origin']) ?> → <?= htmlspecialchars($tr['destination']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label bil-label" for="bilClientName">Client Name *</label>
            <input type="text" class="form-control bil-input" id="bilClientName"
                   list="billingClientsList" maxlength="180" placeholder="Select a registered client" required>
            <datalist id="billingClientsList">
              <?php foreach ($clients as $client): ?>
              <option value="<?= htmlspecialchars($client, ENT_QUOTES) ?>"></option>
              <?php endforeach; ?>
            </datalist>
          </div>
          <div class="col-md-4">
            <label class="form-label bil-label" for="bilAmount">Amount (₱)</label>
            <input type="number" class="form-control bil-input" id="bilAmount"
                   min="0.01" step="0.01" placeholder="0.00" required>
          </div>
          <div class="col-md-4">
            <label class="form-label bil-label" for="bilDueDate">Due Date</label>
            <input type="date" class="form-control bil-input" id="bilDueDate" min="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label bil-label" for="bilBillingNumber">Billing No.</label>
            <input type="text" class="form-control bil-input" id="bilBillingNumber"
                   placeholder="e.g. BIL-2025-0001" required>
          </div>
          <div class="col-md-4">
            <label class="form-label bil-label" for="bilInvoiceNumber">Client Invoice No. (optional)</label>
            <input type="text" class="form-control bil-input" id="bilInvoiceNumber"
                   maxlength="50" placeholder="Client's own invoice reference">
          </div>
          <div class="col-md-4">
            <label class="form-label bil-label" for="bilInvoiceDate">Invoice Date (optional)</label>
            <input type="date" class="form-control bil-input" id="bilInvoiceDate">
          </div>
          <div class="col-12">
            <label class="form-label bil-label" for="bilNotes">Notes (optional)</label>
            <textarea class="form-control bil-input" id="bilNotes" rows="2"
                      placeholder="Any additional remarks…"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer bil-modal-footer">
        <button type="button" class="btn btn-bil-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-bil-primary" id="submitBillingBtn">
          <span id="bilBtnText">Create Billing</span>
          <span id="bilBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ══ Record Payment Modal ════════════════════════════════════════════════ -->
<?php if ($canManageBilling): ?>
<div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bil-modal-content">
      <div class="modal-header bil-modal-header-success">
        <h5 class="modal-title" id="paymentModalLabel">
          <i class="bi bi-cash-stack me-2"></i>Record Payment
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <?php endif; ?>
      <div class="modal-body bil-modal-body">
        <div id="paymentFormAlert" class="alert d-none" role="alert"></div>
        <p class="mb-3">
          Recording payment for <strong id="payBillingNumber"></strong>.
          Outstanding balance: <strong id="payBalance" class="text-danger"></strong>
        </p>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label bil-label" for="payAmount">Amount Paid (₱)</label>
            <input type="number" class="form-control bil-input" id="payAmount"
                   min="0.01" step="0.01" placeholder="0.00" required>
          </div>
          <div class="col-md-6">
            <label class="form-label bil-label" for="payDate">Payment Date</label>
            <input type="date" class="form-control bil-input" id="payDate"
                   value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label bil-label" for="payMode">Payment Mode</label>
            <select class="form-select bil-input" id="payMode" required>
              <option value="">— Select mode —</option>
              <?php foreach ($paymentModes as $pm): ?>
              <option value="<?= $pm ?>"><?= $pm ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label bil-label" for="payReference">Reference No.</label>
            <input type="text" class="form-control bil-input" id="payReference"
                   placeholder="Check no., transaction ID…">
          </div>
          <div class="col-12">
            <label class="form-label bil-label" for="payRemarks">Remarks (optional)</label>
            <textarea class="form-control bil-input" id="payRemarks" rows="2"
                      placeholder="Any notes about this payment…"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer bil-modal-footer">
        <button type="button" class="btn btn-bil-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-bil-success" id="submitPaymentBtn">
          <span id="payBtnText">Record Payment</span>
          <span id="payBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<?php if ($canViewPayroll): ?>
<?php if ($canManagePayroll): ?>
<!-- ══ Log Payroll Payment Modal ═══════════════════════════════════════════ -->
<div class="modal fade" id="logPayrollModal" tabindex="-1" aria-labelledby="logPayrollLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="logPayrollLabel"><i class="bi bi-cash-coin me-2"></i>Log Payroll Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="payrollFormAlert" class="alert d-none" role="alert"></div>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label bil-label" for="prEmployee">Employee</label>
            <select class="form-select bil-input" id="prEmployee" required>
              <option value="">Select employee…</option>
              <?php foreach ($activeEmployees as $emp): ?>
              <option value="<?= $emp['employee_id'] ?>"><?= htmlspecialchars($emp['full_name']) ?> — <?= htmlspecialchars($emp['position']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label bil-label" for="prPeriodStart">Pay Period Start</label>
            <input type="date" class="form-control bil-input" id="prPeriodStart" required>
          </div>
          <div class="col-md-6">
            <label class="form-label bil-label" for="prPeriodEnd">Pay Period End</label>
            <input type="date" class="form-control bil-input" id="prPeriodEnd" required>
          </div>
          <div class="col-md-6">
            <label class="form-label bil-label" for="prAmount">Amount Paid (₱)</label>
            <input type="number" class="form-control bil-input" id="prAmount" min="0.01" step="0.01" placeholder="0.00" required>
          </div>
          <div class="col-md-6">
            <label class="form-label bil-label" for="prPaidDate">Paid Date</label>
            <input type="date" class="form-control bil-input" id="prPaidDate" max="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="col-12">
            <label class="form-label bil-label" for="prNotes">Notes (optional)</label>
            <textarea class="form-control bil-input" id="prNotes" rows="2" placeholder="e.g. Includes overtime, minus cash advance…"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer bil-modal-footer">
        <button type="button" class="btn btn-bil-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-bil-primary" id="submitPayrollBtn">
          <span id="prBtnText">Log Payment</span>
          <span id="prBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>
<?php if (!$isHead && $canManagePayroll): ?>
<!-- ══ Send Payroll Report to Head Modal ═══════════════════════════════════ -->
<div class="modal fade" id="sendPayrollReportModal" tabindex="-1" aria-labelledby="sendPayrollReportLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="sendPayrollReportLabel"><i class="bi bi-send me-2"></i>Send Payroll &amp; Trip Pay Report to Head</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="sendReportAlert" class="alert d-none" role="alert"></div>
        <p class="text-secondary" style="font-size:0.85rem;">
          Click <strong>Print Report</strong> first and save it as a PDF, then attach that file here.
          It'll appear on the Documents page, which Head Management already has access to.
        </p>
        <div class="mb-3">
          <label class="form-label bil-label" for="reportFile">Payroll &amp; Trip Pay Report File (PDF)</label>
          <input type="file" class="form-control bil-input" id="reportFile" accept="application/pdf">
        </div>
        <div class="mb-3">
          <label class="form-label bil-label" for="reportDescription">Description</label>
          <input type="text" class="form-control bil-input" id="reportDescription"
                 value="Payroll & Trip Pay Report — <?= htmlspecialchars($periods[$period]) ?> (generated <?= date('M d, Y') ?>)">
        </div>
      </div>
      <div class="modal-footer bil-modal-footer">
        <button type="button" class="btn btn-bil-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-bil-primary" id="submitSendReportBtn">
          <span id="srBtnText">Send</span>
          <span id="srBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php layoutFoot(); ?>