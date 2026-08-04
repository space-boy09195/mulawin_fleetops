<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/recommendations.php';

requireRole([ROLE_ACCOUNTING]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/dashboard_accounting.js';
layoutHead('Dashboard', APP_BASE . '/assets/css/dashboard_accounting.css');

$pdo = getDBConnection();

// ── Stat cards ────────────────────────────────────────────────────────────────
$bilStats = $pdo->query("
    SELECT
        SUM(billed_amount)                        AS total_billed,
        SUM(total_collected)                      AS total_collected,
        SUM(CASE WHEN status!='Paid' THEN balance ELSE 0 END) AS pending,
        SUM(CASE WHEN status!='Paid' AND due_date < CURDATE() THEN balance ELSE 0 END) AS overdue,
        COUNT(CASE WHEN status!='Paid' AND due_date < CURDATE() THEN 1 END) AS overdue_count
    FROM v_billing_summary
")->fetch(PDO::FETCH_ASSOC);

$totalBilled    = (float)($bilStats['total_billed']    ?? 0);
$totalCollected = (float)($bilStats['total_collected'] ?? 0);
$pending        = (float)($bilStats['pending']         ?? 0);
$overdue        = (float)($bilStats['overdue']         ?? 0);
$overdueCount   = (int)($bilStats['overdue_count']     ?? 0);
$collectionRate = $totalBilled > 0 ? round(($totalCollected / $totalBilled) * 100, 1) : 0;

// ── Trips with billings but no documents ──────────────────────────────────────
$tripsNoDocs = $pdo->query("
    SELECT b.billing_number, b.amount, b.status AS billing_status,
           t.trip_number, r.origin, r.destination,
           (SELECT COUNT(*) FROM billing_documents bd WHERE bd.billing_id = b.billing_id) AS doc_count
    FROM billings b
    JOIN trips t              ON b.trip_id    = t.trip_id
    JOIN dispatch_requests dr ON t.dispatch_id = dr.dispatch_id
    JOIN routes r             ON dr.route_id   = r.route_id
    WHERE b.status != 'Paid'
    HAVING doc_count = 0
    ORDER BY b.created_at DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ── Overdue invoices ──────────────────────────────────────────────────────────
$overdueInvoices = $pdo->query("
    SELECT b.billing_id, b.billing_number, b.client_name, b.due_date, vs.balance, t.trip_number
    FROM billings b
    JOIN v_billing_summary vs ON b.billing_id = vs.billing_id
    JOIN trips t              ON b.trip_id    = t.trip_id
    WHERE b.status != 'Paid' AND b.due_date < CURDATE()
    ORDER BY b.due_date ASC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ── Invoices due soon — not yet overdue, due within 7 days ────────────────────
$dueSoonInvoices = $pdo->query("
    SELECT b.billing_id, b.billing_number, b.client_name, b.due_date, vs.balance, t.trip_number
    FROM billings b
    JOIN v_billing_summary vs ON b.billing_id = vs.billing_id
    JOIN trips t              ON b.trip_id    = t.trip_id
    WHERE b.status != 'Paid'
      AND b.due_date >= CURDATE()
      AND b.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY b.due_date ASC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ── This week's personal activity ─────────────────────────────────────────────
$weekStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
$currentUserId = $_SESSION['user_id'] ?? 0;

$weeklyPayments = $pdo->prepare("
    SELECT COUNT(*), COALESCE(SUM(amount_paid), 0)
    FROM collections
    WHERE recorded_by = ? AND created_at >= ?
");
$weeklyPayments->execute([$currentUserId, $weekStart]);
[$weeklyPaymentCount, $weeklyPaymentTotal] = $weeklyPayments->fetch(PDO::FETCH_NUM);
$weeklyPaymentCount = (int)$weeklyPaymentCount;
$weeklyPaymentTotal = (float)$weeklyPaymentTotal;

$weeklyBillings = $pdo->prepare("
    SELECT COUNT(*) FROM billings WHERE created_by = ? AND created_at >= ?
");
$weeklyBillings->execute([$currentUserId, $weekStart]);
$weeklyBillingCount = (int)$weeklyBillings->fetchColumn();

// ── Recommended Actions (rule-based) ──────────────────────────────────────────
$recommendations = getCollectionsRecommendations($pdo, 6);

// ── Monthly billed vs collected — last 6 months ───────────────────────────────
$monthlyRows = $pdo->query("
    SELECT DATE_FORMAT(b.created_at,'%b') AS month,
           MONTH(b.created_at)            AS mnum,
           YEAR(b.created_at)             AS yr,
           SUM(b.amount)                  AS billed,
           COALESCE(SUM(c.total_paid),0)  AS collected
    FROM billings b
    LEFT JOIN (
        SELECT billing_id, SUM(amount_paid) AS total_paid
        FROM collections GROUP BY billing_id
    ) c ON b.billing_id = c.billing_id
    WHERE b.created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
    GROUP BY YEAR(b.created_at), MONTH(b.created_at)
    ORDER BY YEAR(b.created_at), MONTH(b.created_at)
")->fetchAll(PDO::FETCH_ASSOC);

$monthLabels    = array_column($monthlyRows, 'month');
$billedData     = array_map(fn($r) => round((float)$r['billed'], 2),    $monthlyRows);
$collectedData  = array_map(fn($r) => round((float)$r['collected'], 2), $monthlyRows);

// ── Collection rate per month ─────────────────────────────────────────────────
$rateData = array_map(function($r) {
    $b = (float)$r['billed'];
    $c = (float)$r['collected'];
    return $b > 0 ? round(($c / $b) * 100, 1) : 0;
}, $monthlyRows);

$GLOBALS['dash_data'] = json_encode([
    'monthly' => ['labels' => $monthLabels, 'billed' => $billedData, 'collected' => $collectedData],
    'rate'    => ['labels' => $monthLabels, 'data'   => $rateData],
]);
?>
<div class="da-page">

  <!-- Header -->
  <div class="da-header">
    <div>
      <h1 class="da-title">Dashboard</h1>
      <p class="da-subtitle">Operational analytics and diagnostic alerts</p>
    </div>
    <a href="<?= APP_BASE ?>/pages/billing.php" class="btn da-btn-primary">
      <i class="bi bi-receipt me-1"></i> Create Billing
    </a>
  </div>

  <!-- Your activity this week -->
  <div class="da-activity-strip">
    <span class="da-activity-label"><i class="bi bi-calendar-week"></i> This Week</span>
    <span class="da-activity-item"><strong><?= $weeklyBillingCount ?></strong> billings created</span>
    <span class="da-activity-item da-activity-green"><strong><?= $weeklyPaymentCount ?></strong> payments recorded</span>
    <span class="da-activity-item da-activity-green">₱<strong><?= number_format($weeklyPaymentTotal, 2) ?></strong> collected</span>
  </div>

  <!-- Recommended Actions -->
  <?php if (!empty($recommendations)): ?>
  <div class="da-rec-panel">
    <div class="da-rec-header">
      <i class="bi bi-lightbulb"></i> Collection Priorities
      <span class="da-rec-count"><?= count($recommendations) ?></span>
    </div>
    <div class="da-rec-list">
      <?php foreach ($recommendations as $rec): ?>
      <div class="da-rec-card da-rec-<?= $rec['priority'] ?>">
        <div class="da-rec-body">
          <div class="da-rec-title"><?= htmlspecialchars($rec['title']) ?></div>
          <div class="da-rec-detail"><?= htmlspecialchars($rec['detail']) ?></div>
        </div>
        <a href="<?= APP_BASE . $rec['action_url'] ?>" class="da-rec-action"><?= htmlspecialchars($rec['action_label']) ?></a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Stat cards -->
  <div class="da-stats">
    <div class="da-stat-card">
      <div class="da-stat-label">Total Billed</div>
      <div class="da-stat-value da-val-blue">₱<?= number_format($totalBilled / 1000, 0) ?>K</div>
      <div class="da-stat-sub"><?= date('M Y') ?></div>
    </div>
    <div class="da-stat-card da-stat-success">
      <div class="da-stat-label">Collected</div>
      <div class="da-stat-value da-val-green">₱<?= number_format($totalCollected / 1000, 0) ?>K</div>
      <div class="da-stat-sub"><?= $collectionRate ?>% of billed</div>
    </div>
    <div class="da-stat-card <?= $pending > 0 ? 'da-stat-warn' : '' ?>">
      <div class="da-stat-label">Pending</div>
      <div class="da-stat-value da-val-orange">₱<?= number_format($pending / 1000, 0) ?>K</div>
      <div class="da-stat-sub">Pending payment</div>
    </div>
    <div class="da-stat-card <?= $overdue > 0 ? 'da-stat-alert' : '' ?>">
      <div class="da-stat-label">Overdue</div>
      <div class="da-stat-value da-val-red">₱<?= number_format($overdue / 1000, 0) ?>K</div>
      <div class="da-stat-sub"><?= $overdueCount ?> invoice<?= $overdueCount !== 1 ? 's' : '' ?></div>
    </div>
  </div>

  <!-- Main grid -->
  <div class="da-grid">

    <!-- Trips awaiting documents -->
    <div class="da-widget">
      <div class="da-widget-header">
        <span class="da-widget-title"><i class="bi bi-folder2-open me-2"></i>Billings Without Documents</span>
        <a href="<?= APP_BASE ?>/pages/billing.php" class="da-link">View All</a>
      </div>
      <?php if (empty($tripsNoDocs)): ?>
      <div class="da-empty"><i class="bi bi-folder-check"></i><span>All billings have documents</span></div>
      <?php else: ?>
      <div class="da-table-wrap">
        <table class="table da-table">
          <thead><tr><th>Trip</th><th>Billing No.</th><th>Amount</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($tripsNoDocs as $td): ?>
            <tr>
              <td>
                <span class="da-ref"><?= htmlspecialchars($td['trip_number']) ?></span>
                <span class="da-muted"><?= htmlspecialchars($td['origin']) ?> → <?= htmlspecialchars($td['destination']) ?></span>
              </td>
              <td class="da-billing-num"><?= htmlspecialchars($td['billing_number']) ?></td>
              <td class="da-amount">₱<?= number_format($td['amount'], 2) ?></td>
              <td><span class="da-badge da-badge-orange">No Docs</span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Overdue Invoices -->
    <div class="da-widget">
      <div class="da-widget-header">
        <span class="da-widget-title"><i class="bi bi-calendar-x me-2"></i>Overdue Invoices</span>
        <a href="<?= APP_BASE ?>/pages/billing.php" class="da-link">View All</a>
      </div>
      <?php if (empty($overdueInvoices)): ?>
      <div class="da-empty"><i class="bi bi-calendar-check"></i><span>No overdue invoices</span></div>
      <?php else: ?>
      <div class="da-table-wrap">
        <table class="table da-table">
          <thead><tr><th>Invoice</th><th>Client</th><th>Date</th><th>Amount</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($overdueInvoices as $ov): ?>
            <tr>
              <td><span class="da-ref"><?= htmlspecialchars($ov['billing_number']) ?></span></td>
              <td><?= $ov['client_name'] ? htmlspecialchars($ov['client_name']) : '<span class="da-muted">' . htmlspecialchars($ov['trip_number']) . '</span>' ?></td>
              <td class="da-late"><?= date('Y-m-d', strtotime($ov['due_date'])) ?></td>
              <td class="da-amount">₱<?= number_format($ov['balance'], 2) ?></td>
              <td>
                <a href="<?= APP_BASE ?>/pages/billing.php?quick_payment=<?= $ov['billing_id'] ?>" class="da-quick-pay-btn">
                  <i class="bi bi-cash-coin"></i> Pay
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Invoices Due Soon -->
    <div class="da-widget">
      <div class="da-widget-header">
        <span class="da-widget-title"><i class="bi bi-calendar2-week me-2"></i>Due Soon (Next 7 Days)</span>
        <a href="<?= APP_BASE ?>/pages/billing.php" class="da-link">View All</a>
      </div>
      <?php if (empty($dueSoonInvoices)): ?>
      <div class="da-empty"><i class="bi bi-calendar2-check"></i><span>Nothing due in the next 7 days</span></div>
      <?php else: ?>
      <div class="da-table-wrap">
        <table class="table da-table">
          <thead><tr><th>Invoice</th><th>Client</th><th>Due</th><th>Amount</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($dueSoonInvoices as $ds): ?>
            <tr>
              <td><span class="da-ref"><?= htmlspecialchars($ds['billing_number']) ?></span></td>
              <td><?= $ds['client_name'] ? htmlspecialchars($ds['client_name']) : '<span class="da-muted">' . htmlspecialchars($ds['trip_number']) . '</span>' ?></td>
              <td class="da-muted"><?= date('M d', strtotime($ds['due_date'])) ?></td>
              <td class="da-amount">₱<?= number_format($ds['balance'], 2) ?></td>
              <td>
                <a href="<?= APP_BASE ?>/pages/billing.php?quick_payment=<?= $ds['billing_id'] ?>" class="da-quick-pay-btn">
                  <i class="bi bi-cash-coin"></i> Pay
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Billed vs Collected bar chart -->
    <div class="da-widget da-widget-wide">
      <div class="da-widget-header">
        <span class="da-widget-title"><i class="bi bi-bar-chart me-2"></i>Revenue: Billed vs Collected (₱ thousands)</span>
      </div>
      <div class="da-chart-wrap">
        <canvas id="billedCollectedChart"></canvas>
      </div>
    </div>

    <!-- Collection Rate line chart -->
    <div class="da-widget da-widget-wide">
      <div class="da-widget-header">
        <span class="da-widget-title"><i class="bi bi-graph-up me-2"></i>Collection Rate (%)</span>
      </div>
      <div class="da-chart-wrap">
        <canvas id="collectionRateChart"></canvas>
      </div>
    </div>

  </div>
</div>

<script>window.DASH_DATA = <?= $GLOBALS['dash_data'] ?>;</script>
<?php layoutFoot(); ?>