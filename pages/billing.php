<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_ACCOUNTING]);
$isHead = currentRoleId() === ROLE_HEAD_MANAGEMENT;

$GLOBALS['page_js'] = APP_BASE . '/assets/js/billing.js';

layoutHead('Billing & Collections', APP_BASE . '/assets/css/billing.css');

$pdo = getDBConnection();

// ── Period filter (scopes the summary cards + both lists below) ──────────────
$periods = [
    'today' => 'Today',
    '1w'    => 'This Week',
    '1m'    => 'This Month',
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
$rangeStartSql = $rangeStart ? $rangeStart->format('Y-m-d 00:00:00') : null;
$billingDateFilter    = $rangeStartSql ? "AND b.created_at >= :rangeStart" : '';
$collectionDateFilter = $rangeStartSql ? "AND c.payment_date >= :rangeStart" : '';

// ── Billings via summary view ─────────────────────────────────────────────────
$billingSql = "
    SELECT
        b.billing_id,
        b.billing_number,
        b.client_name,
        b.amount,
        b.due_date,
        b.status,
        b.notes,
        b.created_at,
        t.trip_number,
        u.full_name        AS created_by_name,
        vs.total_collected,
        vs.balance
    FROM billings b
    JOIN trips t ON b.trip_id   = t.trip_id
    JOIN users u ON b.created_by = u.user_id
    JOIN v_billing_summary vs ON b.billing_id = vs.billing_id
    WHERE 1=1 $billingDateFilter
    ORDER BY b.created_at DESC
";
$billingStmt = $pdo->prepare($billingSql);
if ($rangeStartSql) $billingStmt->bindValue(':rangeStart', $rangeStartSql);
$billingStmt->execute();
$billings = $billingStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Collections (recent 150) ──────────────────────────────────────────────────
$collectionsSql = "
    SELECT
        c.collection_id,
        c.amount_paid,
        c.payment_date,
        c.payment_mode,
        c.reference_no,
        c.remarks,
        c.created_at,
        b.billing_number,
        b.billing_id,
        b.client_name,
        u.full_name AS recorded_by_name
    FROM collections c
    JOIN billings b ON c.billing_id  = b.billing_id
    JOIN users u    ON c.recorded_by = u.user_id
    WHERE 1=1 $collectionDateFilter
    ORDER BY c.created_at DESC
    LIMIT 150
";
$collectionsStmt = $pdo->prepare($collectionsSql);
if ($rangeStartSql) $collectionsStmt->bindValue(':rangeStart', $rangeStartSql);
$collectionsStmt->execute();
$collections = $collectionsStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Trips for new billing dropdown (completed, no billing yet) ────────────────
$tripsSql = "
    SELECT t.trip_id, t.trip_number, r.origin, r.destination
    FROM trips t
    JOIN dispatch_requests dr ON t.dispatch_id = dr.dispatch_id
    JOIN routes r             ON dr.route_id   = r.route_id
    WHERE t.status = 'Completed'
    ORDER BY t.trip_number DESC
";
$completedTrips = $pdo->query($tripsSql)->fetchAll(PDO::FETCH_ASSOC);

// ── Active employees (for the Log Payroll Payment form) ───────────────────────
// Drivers and Helpers are on-call, not fixed-salary — they're already paid
// per trip via the "Driver Allowance" line in trip_expenses (logged on the
// Trip Costs page). Excluding them here prevents accidentally double-paying
// the same person: once per trip through Driver Allowance, and again here
// through a periodic payroll entry.
$activeEmployees = $pdo->query("
    SELECT employee_id, full_name, position
    FROM employees
    WHERE is_active = 1
      AND position NOT IN ('Driver', 'Helper')
    ORDER BY full_name
")->fetchAll(PDO::FETCH_ASSOC);

// ── Payroll: recent records (for the Payroll tab table) ───────────────────────
// Wrapped defensively: if payroll_records hasn't been created yet (migration
// not run), degrade to "no payroll data" instead of a fatal crash — but keep
// a flag so the page can tell Accounting/Head *why* payroll is showing as
// zero, rather than silently under-reporting Total Expenses and Profit.
$payrollTableMissing = false;
$payrollRecords = [];
$payrollTotal   = 0.0;
try {
    $payrollSql = "
        SELECT
            pr.payroll_id, pr.employee_id, pr.pay_period_start, pr.pay_period_end,
            pr.amount_paid, pr.paid_date, pr.notes,
            e.full_name AS employee_name, e.position,
            u.full_name AS recorded_by_name
        FROM payroll_records pr
        JOIN employees e ON pr.employee_id = e.employee_id
        JOIN users u     ON pr.recorded_by = u.user_id
        WHERE 1=1 " . ($rangeStartSql ? "AND pr.paid_date >= :rangeStart" : "") . "
        ORDER BY pr.paid_date DESC, pr.created_at DESC
    ";
    $payrollStmt = $pdo->prepare($payrollSql);
    if ($rangeStartSql) $payrollStmt->bindValue(':rangeStart', $rangeStartSql);
    $payrollStmt->execute();
    $payrollRecords = $payrollStmt->fetchAll(PDO::FETCH_ASSOC);
    $payrollTotal   = array_sum(array_column($payrollRecords, 'amount_paid'));
} catch (PDOException $e) {
    // SQLSTATE 42S02 / MySQL 1146 = table doesn't exist. Anything else is a
    // real problem (bad credentials, syntax error, etc.) and should still
    // surface loudly rather than being swallowed here.
    if ($e->getCode() === '42S02' || str_contains($e->getMessage(), "doesn't exist")) {
        $payrollTableMissing = true;
    } else {
        throw $e;
    }
}

// ── Summary stats ─────────────────────────────────────────────────────────────
$totalBilled    = array_sum(array_column($billings, 'amount'));
$totalCollected = array_sum(array_column($billings, 'total_collected'));
$totalBalance   = array_sum(array_column($billings, 'balance'));
$unpaidCount    = count(array_filter($billings, fn($b) => $b['status'] === 'Unpaid'));

// ══════════════════════════════════════════════════════════════════════════
// EXPENSES & PROFIT — Revenue minus every real expense category, scoped to
// the same period filter as the rest of this page.
//
// Expense sources:
//   - trip_expenses: Fuel, Toll, Driver Allowance, Other (per-trip costs)
//   - maintenance_records.cost (per job)
//   - payroll_records.amount_paid (actual logged disbursements — not an
//     estimate from a salary rate, only what was actually paid out)
//
// Deliberately NOT included: parts_movements purchase cost. Maintenance
// staff currently enter a record's "Cost" as effectively the parts spend
// for that job, so adding parts_movements on top would double-count the
// same money under two different names. Parts-purchase spend is still
// shown below, but as a separate informational figure for inventory
// reference — it does not feed into Total Expenses or Profit.
// ══════════════════════════════════════════════════════════════════════════
$expenseDateFilter = $rangeStartSql ? "AND te.expense_date >= :rangeStart" : '';
$expByCategoryStmt = $pdo->prepare("
    SELECT te.expense_type, COALESCE(SUM(te.amount), 0) AS total
    FROM trip_expenses te
    WHERE 1=1 $expenseDateFilter
    GROUP BY te.expense_type
");
if ($rangeStartSql) $expByCategoryStmt->bindValue(':rangeStart', $rangeStartSql);
$expByCategoryStmt->execute();
$expenseCategories = ['Fuel' => 0.0, 'Toll' => 0.0, 'Driver Allowance' => 0.0, 'Other' => 0.0];
foreach ($expByCategoryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $expenseCategories[$row['expense_type']] = (float)$row['total'];
}

$maintDateFilter = $rangeStartSql ? "AND date_performed >= :rangeStart" : '';
$maintCostStmt = $pdo->prepare("
    SELECT COALESCE(SUM(cost), 0) FROM maintenance_records WHERE 1=1 $maintDateFilter
");
if ($rangeStartSql) $maintCostStmt->bindValue(':rangeStart', $rangeStartSql);
$maintCostStmt->execute();
$maintCostTotal = (float)$maintCostStmt->fetchColumn();

// Informational only — inventory reference, not part of Total Expenses (see note above).
$partsDateFilter = $rangeStartSql ? "AND moved_at >= :rangeStart" : '';
$partsPurchasedStmt = $pdo->prepare("
    SELECT COALESCE(SUM(unit_cost * ABS(quantity)), 0)
    FROM parts_movements
    WHERE movement_type = 'Stock In' AND unit_cost IS NOT NULL $partsDateFilter
");
if ($rangeStartSql) $partsPurchasedStmt->bindValue(':rangeStart', $rangeStartSql);
$partsPurchasedStmt->execute();
$partsPurchasedTotal = (float)$partsPurchasedStmt->fetchColumn();

$totalExpenses = $maintCostTotal + array_sum($expenseCategories) + $payrollTotal;
$profit        = $totalBilled - $totalExpenses;
$profitMargin  = $totalBilled > 0 ? round(($profit / $totalBilled) * 100, 1) : null;

$paymentModes = ['Cash', 'Check', 'Bank Transfer', 'GCash', 'Other'];

// ── Overdue helper ────────────────────────────────────────────────────────────
function isOverdue(string $dueDate, string $status): bool {
    return $status !== 'Paid' && strtotime($dueDate) < strtotime('today');
}
?>

<div class="bil-page">

  <?php if ($payrollTableMissing): ?>
  <div class="alert alert-warning d-flex align-items-start gap-2 mb-4" role="alert">
    <i class="bi bi-exclamation-triangle-fill mt-1"></i>
    <div>
      <strong>Payroll data is not available.</strong> The <code>payroll_records</code> table hasn't been created yet,
      so Total Expenses and Net Profit below do not include payroll. Run <code>db/payroll_migration.sql</code>
      against the database to fix this.
    </div>
  </div>
  <?php endif; ?>

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
      <button class="btn btn-bil-primary" data-bs-toggle="modal" data-bs-target="#createBillingModal">
        <i class="bi bi-plus-lg me-1"></i> Create Billing
      </button>
    </div>
  </div>
  <?php if ($period !== 'all'): ?>
  <p class="bil-period-note">
    <i class="bi bi-funnel"></i> Showing <?= htmlspecialchars($periods[$period]) ?> —
    the cards and lists below are scoped to this period.
  </p>
  <?php endif; ?>

  <!-- Summary cards -->
  <div class="bil-cards mb-4">
    <div class="bil-card">
      <div class="bil-card-value">₱<?= number_format($totalBilled, 2) ?></div>
      <div class="bil-card-label">Total Billed</div>
    </div>
    <div class="bil-card bil-card-success">
      <div class="bil-card-value">₱<?= number_format($totalCollected, 2) ?></div>
      <div class="bil-card-label">Total Collected</div>
    </div>
    <div class="bil-card <?= $totalBalance > 0 ? 'bil-card-alert' : '' ?>">
      <div class="bil-card-value">₱<?= number_format($totalBalance, 2) ?></div>
      <div class="bil-card-label">Outstanding Balance</div>
    </div>
    <div class="bil-card <?= $unpaidCount > 0 ? 'bil-card-warn' : '' ?>">
      <div class="bil-card-value"><?= $unpaidCount ?></div>
      <div class="bil-card-label">Unpaid Billings</div>
    </div>
  </div>

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
      <button class="bil-tab" id="tab-collections" data-bs-toggle="tab"
              data-bs-target="#pane-collections" type="button" role="tab">
        <i class="bi bi-cash-stack me-1"></i> Collections
        <span class="bil-tab-count"><?= count($collections) ?></span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="bil-tab" id="tab-expenses" data-bs-toggle="tab"
              data-bs-target="#pane-expenses" type="button" role="tab">
        <i class="bi bi-graph-down-arrow me-1"></i> Expenses &amp; Profit
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="bil-tab" id="tab-payroll" data-bs-toggle="tab"
              data-bs-target="#pane-payroll" type="button" role="tab">
        <i class="bi bi-people me-1"></i> Payroll
        <span class="bil-tab-count"><?= count($payrollRecords) ?></span>
      </button>
    </li>
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
            <?php foreach ($billings as $bil):
              $overdue = isOverdue($bil['due_date'], $bil['status']);
            ?>
            <tr
              data-status="<?= htmlspecialchars($bil['status']) ?>"
              data-search="<?= htmlspecialchars(strtolower(
                $bil['billing_number'] . ' ' . $bil['trip_number'] . ' ' . ($bil['client_name'] ?? '')
              )) ?>"
              data-id="<?= $bil['billing_id'] ?>"
            >
              <td>
                <span class="bil-number"><?= htmlspecialchars($bil['billing_number']) ?></span>
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
              <td class="bil-date <?= $overdue ? 'bil-overdue' : '' ?>">
                <?= date('M d, Y', strtotime($bil['due_date'])) ?>
                <?php if ($overdue): ?>
                <span class="bil-overdue-badge">Overdue</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="bil-status-badge bil-status-<?= strtolower($bil['status']) ?>">
                  <?= $bil['status'] ?>
                </span>
              </td>
              <td class="bil-created-by"><?= htmlspecialchars($bil['created_by_name']) ?></td>
              <td class="text-end">
                <?php if ($bil['status'] !== 'Paid'): ?>
                <button class="btn btn-sm btn-bil-collect"
                        data-id="<?= $bil['billing_id'] ?>"
                        data-number="<?= htmlspecialchars($bil['billing_number']) ?>"
                        data-balance="<?= $bil['balance'] ?>">
                  Record Payment
                </button>
                <?php else: ?>
                <span class="bil-paid-check" title="Fully paid">
                  <i class="bi bi-check-circle-fill text-success"></i>
                </span>
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

    <!-- ── Expenses & Profit pane ────────────────────────────────────────── -->
    <div class="tab-pane fade" id="pane-expenses" role="tabpanel">

      <div class="bil-profit-summary mb-4">
        <div class="bil-profit-card">
          <div class="bil-profit-label">Revenue</div>
          <div class="bil-profit-value">₱<?= number_format($totalBilled, 2) ?></div>
        </div>
        <div class="bil-profit-card">
          <div class="bil-profit-label">Total Expenses</div>
          <div class="bil-profit-value bil-profit-negative">₱<?= number_format($totalExpenses, 2) ?></div>
        </div>
        <div class="bil-profit-card bil-profit-highlight <?= $profit >= 0 ? 'bil-profit-positive-card' : 'bil-profit-negative-card' ?>">
          <div class="bil-profit-label">Net Profit</div>
          <div class="bil-profit-value <?= $profit >= 0 ? 'bil-profit-positive' : 'bil-profit-negative' ?>">
            <?= $profit >= 0 ? '' : '-' ?>₱<?= number_format(abs($profit), 2) ?>
          </div>
          <?php if ($profitMargin !== null): ?>
          <div class="bil-profit-sub"><?= $profitMargin ?>% margin</div>
          <?php endif; ?>
        </div>
      </div>

      <h6 class="bil-expense-breakdown-title">Expense Breakdown <?= htmlspecialchars($periods[$period]) ?></h6>
      <div class="bil-expense-cards mb-4">
        <div class="bil-expense-card">
          <div class="bil-expense-label"><i class="bi bi-people me-1"></i>Payroll</div>
          <div class="bil-expense-value">₱<?= number_format($payrollTotal, 2) ?></div>
        </div>
        <div class="bil-expense-card">
          <div class="bil-expense-label"><i class="bi bi-tools me-1"></i>Maintenance</div>
          <div class="bil-expense-value">₱<?= number_format($maintCostTotal, 2) ?></div>
        </div>
        <div class="bil-expense-card">
          <div class="bil-expense-label"><i class="bi bi-fuel-pump me-1"></i>Fuel</div>
          <div class="bil-expense-value">₱<?= number_format($expenseCategories['Fuel'], 2) ?></div>
        </div>
        <div class="bil-expense-card">
          <div class="bil-expense-label"><i class="bi bi-signpost-split me-1"></i>Toll</div>
          <div class="bil-expense-value">₱<?= number_format($expenseCategories['Toll'], 2) ?></div>
        </div>
        <div class="bil-expense-card">
          <div class="bil-expense-label"><i class="bi bi-person-badge me-1"></i>Driver Allowance</div>
          <div class="bil-expense-value">₱<?= number_format($expenseCategories['Driver Allowance'], 2) ?></div>
        </div>
        <div class="bil-expense-card">
          <div class="bil-expense-label"><i class="bi bi-three-dots me-1"></i>Other</div>
          <div class="bil-expense-value">₱<?= number_format($expenseCategories['Other'], 2) ?></div>
        </div>
      </div>

      <div class="bil-parts-note">
        <i class="bi bi-info-circle me-1"></i>
        Parts purchased this period: <strong>₱<?= number_format($partsPurchasedTotal, 2) ?></strong>
        — shown for inventory reference only. Not included in Total Expenses, since Maintenance Cost already
        reflects parts spend per job.
      </div>

    </div>

    <!-- ── Payroll pane ──────────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="pane-payroll" role="tabpanel">

      <div class="bil-payroll-note">
        <i class="bi bi-info-circle me-1"></i>
        This covers fixed-salary staff only. Drivers and Helpers are on-call and already paid per trip via
        <strong>Driver Allowance</strong> on the Trip Costs page — they don't appear here to avoid paying them twice.
      </div>

      <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div class="bil-payroll-total">
          Total logged <?= htmlspecialchars($periods[$period]) ?>: <strong>₱<?= number_format($payrollTotal, 2) ?></strong>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-outline-secondary btn-sm" id="printPayrollReportBtn">
            <i class="bi bi-printer me-1"></i> Print Report
          </button>
          <?php if (!$isHead): ?>
          <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#sendPayrollReportModal">
            <i class="bi bi-send me-1"></i> Send to Head
          </button>
          <?php endif; ?>
          <button class="btn btn-bil-primary btn-sm" data-bs-toggle="modal" data-bs-target="#logPayrollModal">
            <i class="bi bi-plus-lg me-1"></i> Log Payment
          </button>
        </div>
      </div>

      <div class="bil-table-wrap">
        <?php if (empty($payrollRecords)): ?>
        <div class="no-results">
          <i class="bi bi-cash-coin"></i>
          <span>No payroll payments logged for this period yet.</span>
        </div>
        <?php else: ?>
        <table class="table bil-table">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Position</th>
              <th>Pay Period</th>
              <th>Amount Paid</th>
              <th>Paid Date</th>
              <th>Recorded By</th>
              <th>Notes</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($payrollRecords as $pr): ?>
            <tr data-payroll-id="<?= $pr['payroll_id'] ?>">
              <td><?= htmlspecialchars($pr['employee_name']) ?></td>
              <td><?= htmlspecialchars($pr['position']) ?></td>
              <td><?= date('M d', strtotime($pr['pay_period_start'])) ?> &ndash; <?= date('M d, Y', strtotime($pr['pay_period_end'])) ?></td>
              <td>₱<?= number_format($pr['amount_paid'], 2) ?></td>
              <td><?= date('M d, Y', strtotime($pr['paid_date'])) ?></td>
              <td><?= htmlspecialchars($pr['recorded_by_name']) ?></td>
              <td><?= $pr['notes'] ? htmlspecialchars($pr['notes']) : '<span class="text-muted">—</span>' ?></td>
              <td>
                <?php if ($isHead): ?>
                <button type="button" class="btn btn-sm btn-outline-danger bil-delete-payroll-btn" data-id="<?= $pr['payroll_id'] ?>" title="Delete">
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

      <!-- Print-only view: hidden on screen, shown only when printing/saving as PDF -->
      <div class="bil-print-only">
        <div class="bil-print-header">
          <h2>RP Mulawin Trucking Services</h2>
          <h3>Payroll Report</h3>
          <p>Period: <?= htmlspecialchars($periods[$period]) ?> &middot; Generated: <?= date('F d, Y g:i A') ?></p>
        </div>
        <?php if (empty($payrollRecords)): ?>
        <p>No payroll payments logged for this period.</p>
        <?php else: ?>
        <table class="bil-print-table">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Position</th>
              <th>Pay Period</th>
              <th>Amount Paid</th>
              <th>Paid Date</th>
              <th>Notes</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($payrollRecords as $pr): ?>
            <tr>
              <td><?= htmlspecialchars($pr['employee_name']) ?></td>
              <td><?= htmlspecialchars($pr['position']) ?></td>
              <td><?= date('M d', strtotime($pr['pay_period_start'])) ?> &ndash; <?= date('M d, Y', strtotime($pr['pay_period_end'])) ?></td>
              <td>₱<?= number_format($pr['amount_paid'], 2) ?></td>
              <td><?= date('M d, Y', strtotime($pr['paid_date'])) ?></td>
              <td><?= $pr['notes'] ? htmlspecialchars($pr['notes']) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="3"><strong>Total</strong></td>
              <td><strong>₱<?= number_format($payrollTotal, 2) ?></strong></td>
              <td colspan="2"></td>
            </tr>
          </tfoot>
        </table>
        <?php endif; ?>
        <div class="bil-print-footer">
          <p>Prepared by: _______________________</p>
          <p>Reviewed by: _______________________</p>
        </div>
      </div>

    </div>

  </div><!-- /tab-content -->
</div>

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
              <option value="">— Select completed trip —</option>
              <?php foreach ($completedTrips as $tr): ?>
              <option value="<?= $tr['trip_id'] ?>">
                <?= htmlspecialchars($tr['trip_number']) ?> —
                <?= htmlspecialchars($tr['origin']) ?> → <?= htmlspecialchars($tr['destination']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label bil-label" for="bilClientName">Client Name</label>
            <input type="text" class="form-control bil-input" id="bilClientName"
                   placeholder="Bill-to name (optional)">
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

<!-- ══ Record Payment Modal ════════════════════════════════════════════════ -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bil-modal-content">
      <div class="modal-header bil-modal-header-success">
        <h5 class="modal-title" id="paymentModalLabel">
          <i class="bi bi-cash-stack me-2"></i>Record Payment
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
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

<?php if (!$isHead): ?>
<!-- ══ Send Payroll Report to Head Modal ═══════════════════════════════════ -->
<div class="modal fade" id="sendPayrollReportModal" tabindex="-1" aria-labelledby="sendPayrollReportLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="sendPayrollReportLabel"><i class="bi bi-send me-2"></i>Send Payroll Report to Head</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="sendReportAlert" class="alert d-none" role="alert"></div>
        <p class="text-secondary" style="font-size:0.85rem;">
          Click <strong>Print Report</strong> first and save it as a PDF, then attach that file here.
          It'll appear on the Documents page, which Head Management already has access to.
        </p>
        <div class="mb-3">
          <label class="form-label bil-label" for="reportFile">Payroll Report File (PDF)</label>
          <input type="file" class="form-control bil-input" id="reportFile" accept="application/pdf">
        </div>
        <div class="mb-3">
          <label class="form-label bil-label" for="reportDescription">Description</label>
          <input type="text" class="form-control bil-input" id="reportDescription"
                 value="Payroll Report — <?= htmlspecialchars($periods[$period]) ?> (generated <?= date('M d, Y') ?>)">
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

<?php layoutFoot(); ?>