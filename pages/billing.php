<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_ACCOUNTING]);

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

// ── Summary stats ─────────────────────────────────────────────────────────────
$totalBilled    = array_sum(array_column($billings, 'amount'));
$totalCollected = array_sum(array_column($billings, 'total_collected'));
$totalBalance   = array_sum(array_column($billings, 'balance'));
$unpaidCount    = count(array_filter($billings, fn($b) => $b['status'] === 'Unpaid'));

$paymentModes = ['Cash', 'Check', 'Bank Transfer', 'GCash', 'Other'];

// ── Overdue helper ────────────────────────────────────────────────────────────
function isOverdue(string $dueDate, string $status): bool {
    return $status !== 'Paid' && strtotime($dueDate) < strtotime('today');
}
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
            <input type="date" class="form-control bil-input" id="bilDueDate" required>
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
                   value="<?= date('Y-m-d') ?>" required>
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

<?php layoutFoot(); ?>