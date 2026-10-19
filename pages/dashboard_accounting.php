<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireAnyPermission(['billing.view', 'billing.manage']);

$pdo = getDBConnection();
$canFinance = currentUserHasAnyPermission(['finance.view']);
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
?>
<div class="da-page">
  <header class="da-header">
    <div>
      <p class="da-eyebrow">Billing and collection work queue</p>
      <h1 class="da-title">Accounting Workspace</h1>
      <p class="da-subtitle">Recent billing records. Reporting totals are on Analytics.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a href="<?= APP_BASE ?>/pages/billing.php" class="btn da-btn-primary">Open Billing &amp; Collections</a>
      <?php if ($canFinance): ?><a href="<?= APP_BASE ?>/pages/finance.php" class="btn btn-outline-primary">Open Finance</a><?php endif; ?>
      <a href="<?= APP_BASE ?>/pages/analytics.php" class="btn btn-outline-secondary">Analytics</a>
    </div>
  </header>

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
