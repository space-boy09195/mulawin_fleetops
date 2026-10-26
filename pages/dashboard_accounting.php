<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dashboard_metrics.php';

requireAnyPermission(['billing.view', 'billing.manage']);

$pdo = getDBConnection();
$canFinance = currentUserHasAnyPermission(['finance.funds.view', 'finance.disbursements.view', 'finance.ap.view']);
$canApprovals = currentUserHasAnyPermission(['approvals.review']);
$canAnalytics = currentUserHasAnyPermission(['reports.view', 'operations.reports.view', 'maintenance.reports.view', 'billing.view']);
$receivables = dashboardReceivables($pdo);
$pendingApprovals = $canApprovals ? dashboardPendingApprovalCount($pdo, isAdmin(), currentRoleId()) : 0;
$billings = $pdo->query("
    SELECT b.billing_id, b.invoice_number, b.billing_date, b.total_amount, b.status,
           cl.client_name, t.trip_number
    FROM billings b
    LEFT JOIN clients cl ON cl.client_id = b.client_id
    LEFT JOIN trips t ON t.trip_id = b.trip_id
    ORDER BY b.billing_date DESC, b.billing_id DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$GLOBALS['page_needs_chart'] = false;
layoutHead('Accounting Workspace', APP_BASE . '/assets/css/dashboard_accounting.css');
$fullName = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
$roleName = (string)($_SESSION['role_name'] ?? 'Accounting');
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
?>
<div class="da-page">
  <header class="da-header">
    <div>
      <p class="da-eyebrow">Billing and collection work queue</p>
      <h1 class="da-title"><?= htmlspecialchars($greeting . ($fullName !== '' ? ', ' . $fullName : '')) ?></h1>
      <p class="da-subtitle"><?= htmlspecialchars($roleName) ?> · Billing, collection, and permitted finance records.</p>
    </div>
    <time class="text-muted"><?= date('l, F j, Y') ?></time>
  </header>

  <section class="row row-cols-1 row-cols-md-2 row-cols-xl-4 g-3 mb-4" aria-label="Dashboard summary">
    <div class="col"><a class="stat-card text-decoration-none h-100" href="<?= APP_BASE ?>/pages/billing.php"><span class="stat-icon blue"><i class="bi bi-receipt"></i></span><span class="stat-info"><span class="stat-value"><?= count($billings) ?></span><span class="stat-label">Recent billing records shown</span></span></a></div>
    <div class="col"><a class="stat-card text-decoration-none h-100" href="<?= APP_BASE ?>/pages/billing.php"><span class="stat-icon amber"><i class="bi bi-cash-coin"></i></span><span class="stat-info"><span class="stat-value">₱<?= number_format($receivables['outstanding'], 2) ?></span><span class="stat-label">Outstanding balances</span></span></a></div>
    <div class="col"><a class="stat-card text-decoration-none h-100" href="<?= APP_BASE ?>/pages/billing.php"><span class="stat-icon red"><i class="bi bi-exclamation-circle"></i></span><span class="stat-info"><span class="stat-value"><?= $receivables['overdue_count'] ?></span><span class="stat-label">Overdue bills</span></span></a></div>
    <?php if ($canApprovals): ?><div class="col"><a class="stat-card text-decoration-none h-100" href="<?= APP_BASE ?>/pages/requests.php"><span class="stat-icon purple"><i class="bi bi-inbox"></i></span><span class="stat-info"><span class="stat-value"><?= $pendingApprovals ?></span><span class="stat-label">Pending approvals</span></span></a></div><?php endif; ?>
  </section>

  <section class="card mb-4" aria-labelledby="accounting-quick-heading">
    <div class="card-header-custom"><h2 id="accounting-quick-heading" class="card-title-custom mb-0">Quick Actions</h2></div>
    <div class="card-body-custom d-flex flex-wrap gap-2">
      <a href="<?= APP_BASE ?>/pages/billing.php" class="btn da-btn-primary">Open Billing &amp; Collections</a>
      <?php if ($canFinance): ?><a href="<?= APP_BASE ?>/pages/finance.php" class="btn btn-outline-primary">Open Finance</a><?php endif; ?>
      <?php if ($canAnalytics): ?><a href="<?= APP_BASE ?>/pages/analytics.php" class="btn btn-outline-secondary">Analytics</a><?php endif; ?>
      <?php if ($canApprovals): ?><a href="<?= APP_BASE ?>/pages/requests.php" class="btn btn-outline-primary">Finance requests</a><?php endif; ?>
    </div>
  </section>

  <section class="da-widget mb-4" aria-labelledby="accounting-work-heading">
    <div class="da-widget-header"><span id="accounting-work-heading" class="da-widget-title"><i class="bi bi-clipboard-check me-2"></i>Work to Address</span></div>
    <?php if ($receivables['outstanding'] > 0 || $pendingApprovals > 0): ?>
      <ul class="list-group list-group-flush">
        <?php if ($receivables['outstanding'] > 0): ?><li class="list-group-item d-flex justify-content-between align-items-center gap-3"><span><strong>Outstanding balances</strong><span class="d-block small text-muted">₱<?= number_format($receivables['outstanding'], 2) ?> · <?= (int)$receivables['overdue_count'] ?> overdue bills</span></span><a href="<?= APP_BASE ?>/pages/billing.php" class="btn btn-sm btn-outline-primary">Open billing</a></li><?php endif; ?>
        <?php if ($canApprovals && $pendingApprovals > 0): ?><li class="list-group-item d-flex justify-content-between align-items-center gap-3"><span><strong>Requests awaiting approval</strong><span class="d-block small text-muted"><?= $pendingApprovals ?> request<?= $pendingApprovals === 1 ? '' : 's' ?></span></span><a href="<?= APP_BASE ?>/pages/requests.php" class="btn btn-sm btn-outline-primary">Review</a></li><?php endif; ?>
      </ul>
    <?php else: ?><p class="text-muted p-3 mb-0">No outstanding billing balance or approval is pending in your access scope.</p><?php endif; ?>
  </section>

  <section class="da-widget" aria-labelledby="accounting-recent-heading">
    <div class="da-widget-header"><span id="accounting-recent-heading" class="da-widget-title"><i class="bi bi-receipt me-2"></i>Recent billing records</span></div>
    <?php if (!$billings): ?><div class="da-empty"><i class="bi bi-receipt"></i><span>No billing records are available.</span></div>
    <?php else: ?><div class="da-table-wrap"><table class="table da-table">
      <thead><tr><th>Invoice</th><th>Client</th><th>Trip</th><th>Billing date</th><th>Amount</th><th>Stored status</th></tr></thead>
      <tbody><?php foreach ($billings as $billing): ?>
        <tr>
          <td><?= htmlspecialchars($billing['invoice_number'] ?? '—') ?></td>
          <td><?= htmlspecialchars($billing['client_name'] ?? '—') ?></td>
          <td><?= htmlspecialchars($billing['trip_number'] ?? '—') ?></td>
          <td><?= htmlspecialchars((string)$billing['billing_date']) ?></td>
          <td>₱<?= number_format((float)$billing['total_amount'], 2) ?></td>
          <td><?= htmlspecialchars($billing['status']) ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
  </section>
</div>
<?php layoutFoot(); ?>
