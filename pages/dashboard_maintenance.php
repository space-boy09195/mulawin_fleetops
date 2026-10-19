<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dashboard_metrics.php';
require_once __DIR__ . '/../includes/finance_records.php';

requireAnyPermission(['maintenance.view', 'parts.view']);

$pdo = getDBConnection();
$canMaintenance = currentUserHasAnyPermission(['maintenance.view']);
$canParts = currentUserHasAnyPermission(['parts.view']);
$maintenanceDue = $canMaintenance ? dashboardMaintenanceDue($pdo, 14, 20) : [];
$openJobs = [];
if ($canMaintenance) {
    $maintDate = maintenanceRecordDateSql('mr');
    $stmt = $pdo->query("
        SELECT mr.maintenance_id, mr.reported_issue, mr.maintenance_type, mr.status,
               $maintDate AS record_date, tr.plate_number
        FROM maintenance_records mr
        LEFT JOIN trucks tr ON tr.truck_id = mr.truck_id
        WHERE mr.status IN (" . metricSqlList(MAINTENANCE_OPEN_STATUSES) . ")
        ORDER BY $maintDate ASC, mr.maintenance_id ASC
        LIMIT 20
    ");
    $openJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$parts = [];
if ($canParts) {
    $parts = $pdo->query("
        SELECT p.part_name, COALESCE(i.quantity_on_hand, 0) AS quantity,
               p.reorder_level, p.unit
        FROM parts p
        LEFT JOIN inventory i ON i.part_id = p.part_id
        WHERE p.is_active = 1
          AND COALESCE(i.quantity_on_hand, 0) <= p.reorder_level
        ORDER BY p.part_name
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$GLOBALS['page_needs_chart'] = false;
layoutHead('Maintenance Workspace', APP_BASE . '/assets/css/dashboard_maintenance.css');
?>
<div class="dm-page">
  <header class="dm-header">
    <div>
      <p class="dm-eyebrow">Maintenance and parts work queues</p>
      <h1 class="dm-title">Maintenance Workspace</h1>
      <p class="dm-subtitle">Open records and scheduled work from current system data.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <?php if ($canMaintenance): ?><a href="<?= APP_BASE ?>/pages/maintenance.php" class="btn btn-primary">Open Maintenance</a><?php endif; ?>
      <?php if ($canParts): ?><a href="<?= APP_BASE ?>/pages/parts.php" class="btn btn-outline-primary">Open Parts</a><?php endif; ?>
      <?php if ($canMaintenance && currentUserHasAnyPermission(['maintenance.reports.view'])): ?><a href="<?= APP_BASE ?>/pages/analytics.php" class="btn btn-outline-secondary">Analytics</a><?php endif; ?>
    </div>
  </header>

  <?php if ($canMaintenance): ?>
  <section class="dm-widget mb-4" aria-labelledby="maintenance-open-heading">
    <div class="dm-widget-header"><span id="maintenance-open-heading" class="dm-widget-title"><i class="bi bi-wrench-adjustable me-2"></i>Open maintenance records</span></div>
    <?php if (!$openJobs): ?><div class="dm-empty"><i class="bi bi-check-circle"></i><span>No open maintenance records.</span></div>
    <?php else: ?><div class="dm-table-wrap"><table class="table dm-table">
      <thead><tr><th>Truck</th><th>Record</th><th>Type</th><th>Status</th><th>Record date</th></tr></thead>
      <tbody><?php foreach ($openJobs as $job): ?>
        <tr>
          <td><?= htmlspecialchars($job['plate_number'] ?? '—') ?></td>
          <td><?= htmlspecialchars($job['reported_issue'] ?? '—') ?></td>
          <td><?= htmlspecialchars($job['maintenance_type'] ?? '—') ?></td>
          <td><?= htmlspecialchars($job['status']) ?></td>
          <td><?= htmlspecialchars((string)$job['record_date']) ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
  </section>

  <section class="dm-widget mb-4" aria-labelledby="maintenance-due-heading">
    <div class="dm-widget-header">
      <span id="maintenance-due-heading" class="dm-widget-title"><i class="bi bi-calendar2-week me-2"></i>Upcoming scheduled maintenance</span>
      <a href="<?= APP_BASE ?>/pages/maintenance.php" class="dm-link">Maintenance records</a>
    </div>
    <?php if (!$maintenanceDue): ?><div class="dm-empty"><i class="bi bi-calendar2-check"></i><span>No scheduled maintenance due in the next 14 days.</span></div>
    <?php else: ?><ul class="list-group list-group-flush">
      <?php foreach ($maintenanceDue as $item): ?>
        <li class="list-group-item d-flex justify-content-between gap-3">
          <span><?= htmlspecialchars($item['plate_number']) ?> · <?= htmlspecialchars($item['label']) ?></span>
          <time><?= htmlspecialchars($item['next_due_date']) ?></time>
        </li>
      <?php endforeach; ?>
    </ul><?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($canParts): ?>
  <section class="dm-widget" aria-labelledby="parts-reorder-heading">
    <div class="dm-widget-header">
      <span id="parts-reorder-heading" class="dm-widget-title"><i class="bi bi-box-seam me-2"></i>Parts at or below recorded reorder level</span>
      <a href="<?= APP_BASE ?>/pages/parts.php" class="dm-link">Parts inventory</a>
    </div>
    <?php if (!$parts): ?><div class="dm-empty"><i class="bi bi-box-seam"></i><span>No parts are at or below their recorded reorder level.</span></div>
    <?php else: ?><div class="dm-table-wrap"><table class="table dm-table">
      <thead><tr><th>Part</th><th>Quantity on hand</th><th>Reorder level</th></tr></thead>
      <tbody><?php foreach ($parts as $part): ?>
        <tr>
          <td><?= htmlspecialchars($part['part_name']) ?></td>
          <td><?= htmlspecialchars((string)$part['quantity']) ?> <?= htmlspecialchars($part['unit']) ?></td>
          <td><?= htmlspecialchars((string)$part['reorder_level']) ?> <?= htmlspecialchars($part['unit']) ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<?php layoutFoot(); ?>
