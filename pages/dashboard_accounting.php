<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_ACCOUNTING]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/dashboard.js';
layoutHead('Dashboard', APP_BASE . '/assets/css/dashboard.css');

$pdo = getDBConnection();

// ── Billing summary ───────────────────────────────────────────────────────────
$bilStats = $pdo->query("
    SELECT
        SUM(status = 'Unpaid')   AS unpaid_count,
        SUM(status = 'Partial')  AS partial_count,
        SUM(status = 'Paid')     AS paid_count,
        SUM(billed_amount)       AS total_billed,
        SUM(total_collected)     AS total_collected,
        SUM(balance)             AS total_outstanding
    FROM v_billing_summary
")->fetch(PDO::FETCH_ASSOC);

// ── Overdue billings ──────────────────────────────────────────────────────────
$overdue = $pdo->query("
    SELECT b.billing_number, b.client_name, b.due_date,
           vs.balance, t.trip_number
    FROM billings b
    JOIN v_billing_summary vs ON b.billing_id = vs.billing_id
    JOIN trips t              ON b.trip_id    = t.trip_id
    WHERE b.status != 'Paid' AND b.due_date < CURDATE()
    ORDER BY b.due_date ASC LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// ── Unpaid / partial billings ─────────────────────────────────────────────────
$unpaidBillings = $pdo->query("
    SELECT b.billing_number, b.client_name, b.due_date, b.status,
           vs.balance, t.trip_number
    FROM billings b
    JOIN v_billing_summary vs ON b.billing_id = vs.billing_id
    JOIN trips t              ON b.trip_id    = t.trip_id
    WHERE b.status IN ('Unpaid', 'Partial')
    ORDER BY b.due_date ASC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// ── Recent collections ────────────────────────────────────────────────────────
$recentCollections = $pdo->query("
    SELECT c.amount_paid, c.payment_mode, c.payment_date,
           b.billing_number, b.client_name
    FROM collections c
    JOIN billings b ON c.billing_id = b.billing_id
    ORDER BY c.created_at DESC LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="dash-page">
  <div class="dash-header mb-4">
    <h1 class="dash-title">Dashboard</h1>
    <p class="dash-subtitle">Welcome back, <?= htmlspecialchars($_SESSION['full_name']) ?></p>
  </div>

  <div class="dash-stats mb-4">
    <div class="stat-card"><div class="stat-icon stat-blue"><i class="bi bi-receipt"></i></div><div class="stat-info"><div class="stat-value">₱<?= number_format($bilStats['total_billed'] ?? 0, 0) ?></div><div class="stat-label">Total Billed</div></div></div>
    <div class="stat-card stat-card-success"><div class="stat-icon stat-green"><i class="bi bi-cash-stack"></i></div><div class="stat-info"><div class="stat-value">₱<?= number_format($bilStats['total_collected'] ?? 0, 0) ?></div><div class="stat-label">Total Collected</div></div></div>
    <div class="stat-card <?= ($bilStats['total_outstanding'] ?? 0) > 0 ? 'stat-card-alert' : '' ?>"><div class="stat-icon stat-red"><i class="bi bi-exclamation-circle"></i></div><div class="stat-info"><div class="stat-value">₱<?= number_format($bilStats['total_outstanding'] ?? 0, 0) ?></div><div class="stat-label">Outstanding</div></div></div>
    <div class="stat-card <?= ($bilStats['unpaid_count'] ?? 0) > 0 ? 'stat-card-warn' : '' ?>"><div class="stat-icon stat-orange"><i class="bi bi-hourglass-split"></i></div><div class="stat-info"><div class="stat-value"><?= (int)($bilStats['unpaid_count'] ?? 0) ?></div><div class="stat-label">Unpaid</div></div></div>
    <div class="stat-card <?= ($bilStats['partial_count'] ?? 0) > 0 ? 'stat-card-warn' : '' ?>"><div class="stat-icon stat-purple"><i class="bi bi-pie-chart"></i></div><div class="stat-info"><div class="stat-value"><?= (int)($bilStats['partial_count'] ?? 0) ?></div><div class="stat-label">Partial</div></div></div>
    <div class="stat-card <?= count($overdue) > 0 ? 'stat-card-alert' : '' ?>"><div class="stat-icon stat-red"><i class="bi bi-calendar-x"></i></div><div class="stat-info"><div class="stat-value"><?= count($overdue) ?></div><div class="stat-label">Overdue</div></div></div>
  </div>

  <div class="dash-grid">
    <!-- Unpaid/Partial Billings -->
    <div class="dash-widget dash-widget-wide">
      <div class="dash-widget-header">
        <span class="dash-widget-title"><i class="bi bi-receipt me-2"></i>Unpaid &amp; Partial Billings</span>
        <a href="<?= APP_BASE ?>/pages/billing.php" class="dash-widget-link">View all</a>
      </div>
      <?php if (empty($unpaidBillings)): ?>
      <div class="dash-empty"><i class="bi bi-check-circle"></i><span>All billings are settled</span></div>
      <?php else: ?>
      <div class="dash-table-wrap">
        <table class="table dash-table">
          <thead><tr><th>Billing No.</th><th>Trip</th><th>Client</th><th>Balance</th><th>Due Date</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($unpaidBillings as $b):
              $isOverdue = strtotime($b['due_date']) < time();
            ?>
            <tr>
              <td><span class="dash-ref"><?= htmlspecialchars($b['billing_number']) ?></span></td>
              <td><span class="dash-muted"><?= htmlspecialchars($b['trip_number']) ?></span></td>
              <td><?= $b['client_name'] ? htmlspecialchars($b['client_name']) : '<span class="dash-muted">—</span>' ?></td>
              <td class="dash-amount">₱<?= number_format($b['balance'], 2) ?></td>
              <td class="<?= $isOverdue ? 'dash-late' : '' ?>"><?= date('M d, Y', strtotime($b['due_date'])) ?></td>
              <td>
                <?php if ($b['status'] === 'Partial'): ?>
                <span class="dash-badge dash-badge-orange">Partial</span>
                <?php else: ?>
                <span class="dash-badge dash-badge-red">Unpaid</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Overdue Billings -->
    <div class="dash-widget">
      <div class="dash-widget-header">
        <span class="dash-widget-title"><i class="bi bi-calendar-x me-2"></i>Overdue</span>
        <a href="<?= APP_BASE ?>/pages/billing.php" class="dash-widget-link">View all</a>
      </div>
      <?php if (empty($overdue)): ?>
      <div class="dash-empty"><i class="bi bi-calendar-check"></i><span>No overdue billings</span></div>
      <?php else: ?>
      <ul class="dash-list">
        <?php foreach ($overdue as $o): ?>
        <li class="dash-list-item">
          <span class="dash-list-icon dash-list-icon-red"><i class="bi bi-calendar-x"></i></span>
          <div class="dash-list-body">
            <span class="dash-list-main"><?= htmlspecialchars($o['billing_number']) ?></span>
            <span class="dash-list-sub">₱<?= number_format($o['balance'], 2) ?> · <?= $o['client_name'] ? htmlspecialchars($o['client_name']) : $o['trip_number'] ?></span>
          </div>
          <span class="dash-list-time dash-late"><?= date('M d', strtotime($o['due_date'])) ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

    <!-- Recent Collections -->
    <div class="dash-widget">
      <div class="dash-widget-header">
        <span class="dash-widget-title"><i class="bi bi-cash-stack me-2"></i>Recent Collections</span>
        <a href="<?= APP_BASE ?>/pages/billing.php" class="dash-widget-link">View all</a>
      </div>
      <?php if (empty($recentCollections)): ?>
      <div class="dash-empty"><i class="bi bi-cash-stack"></i><span>No collections recorded yet</span></div>
      <?php else: ?>
      <ul class="dash-list">
        <?php foreach ($recentCollections as $c): ?>
        <li class="dash-list-item">
          <span class="dash-list-icon dash-list-icon-green"><i class="bi bi-cash"></i></span>
          <div class="dash-list-body">
            <span class="dash-list-main">₱<?= number_format($c['amount_paid'], 2) ?> · <?= htmlspecialchars($c['payment_mode']) ?></span>
            <span class="dash-list-sub"><?= htmlspecialchars($c['billing_number']) ?><?= $c['client_name'] ? ' · ' . htmlspecialchars($c['client_name']) : '' ?></span>
          </div>
          <span class="dash-list-time"><?= date('M d', strtotime($c['payment_date'])) ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php layoutFoot(); ?>