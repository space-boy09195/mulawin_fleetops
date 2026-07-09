<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_MAINTENANCE]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/dashboard.js';
layoutHead('Dashboard', APP_BASE . '/assets/css/dashboard.css');

$pdo = getDBConnection();

// ── Trucks under maintenance ──────────────────────────────────────────────────
$trucksMaint = $pdo->query("
    SELECT truck_id, plate_number, brand, model, status
    FROM trucks WHERE status = 'Under Maintenance'
    ORDER BY plate_number
")->fetchAll(PDO::FETCH_ASSOC);

// ── Pending checklists (approved dispatches without one) ──────────────────────
$pendingChecklists = $pdo->query("
    SELECT dr.dispatch_id, tr.plate_number, tr.brand, tr.model,
           e.full_name AS driver_name, r.origin, r.destination, dr.scheduled_at
    FROM dispatch_requests dr
    JOIN trucks tr    ON dr.truck_id  = tr.truck_id
    JOIN employees e  ON dr.driver_id = e.employee_id
    JOIN routes r     ON dr.route_id  = r.route_id
    WHERE dr.status = 'Approved'
      AND NOT EXISTS (
          SELECT 1 FROM maintenance_checklists mc WHERE mc.dispatch_id = dr.dispatch_id
      )
    ORDER BY dr.scheduled_at ASC LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// ── Open incidents ────────────────────────────────────────────────────────────
$openIncidents = $pdo->query("
    SELECT i.incident_type, i.reported_at, i.description,
           t.trip_number, tr.plate_number
    FROM incidents i
    JOIN trips t              ON i.trip_id     = t.trip_id
    JOIN dispatch_requests dr ON t.dispatch_id = dr.dispatch_id
    JOIN trucks tr            ON dr.truck_id   = tr.truck_id
    WHERE i.resolved_at IS NULL ORDER BY i.reported_at DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ── Low stock parts ───────────────────────────────────────────────────────────
$lowStock = $pdo->query("
    SELECT part_name, quantity, reorder_level, unit
    FROM v_low_stock_parts ORDER BY shortage DESC LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// ── Recent maintenance records ────────────────────────────────────────────────
$recentRecords = $pdo->query("
    SELECT mr.maintenance_type, mr.date_performed, mr.description,
           tr.plate_number
    FROM maintenance_records mr
    JOIN trucks tr ON mr.truck_id = tr.truck_id
    ORDER BY mr.date_performed DESC, mr.created_at DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="dash-page">
  <div class="dash-header mb-4">
    <h1 class="dash-title">Dashboard</h1>
    <p class="dash-subtitle">Welcome back, <?= htmlspecialchars($_SESSION['full_name']) ?></p>
  </div>

  <div class="dash-stats mb-4">
    <div class="stat-card <?= count($trucksMaint) > 0 ? 'stat-card-warn' : '' ?>"><div class="stat-icon stat-orange"><i class="bi bi-tools"></i></div><div class="stat-info"><div class="stat-value"><?= count($trucksMaint) ?></div><div class="stat-label">Under Maintenance</div></div></div>
    <div class="stat-card <?= count($pendingChecklists) > 0 ? 'stat-card-warn' : '' ?>"><div class="stat-icon stat-purple"><i class="bi bi-clipboard-check"></i></div><div class="stat-info"><div class="stat-value"><?= count($pendingChecklists) ?></div><div class="stat-label">Pending Checklists</div></div></div>
    <div class="stat-card <?= count($openIncidents) > 0 ? 'stat-card-alert' : '' ?>"><div class="stat-icon stat-red"><i class="bi bi-exclamation-triangle"></i></div><div class="stat-info"><div class="stat-value"><?= count($openIncidents) ?></div><div class="stat-label">Open Incidents</div></div></div>
    <div class="stat-card <?= count($lowStock) > 0 ? 'stat-card-warn' : '' ?>"><div class="stat-icon stat-orange"><i class="bi bi-box-seam"></i></div><div class="stat-info"><div class="stat-value"><?= count($lowStock) ?></div><div class="stat-label">Low Stock Parts</div></div></div>
  </div>

  <div class="dash-grid">
    <!-- Pending Checklists -->
    <div class="dash-widget dash-widget-wide">
      <div class="dash-widget-header">
        <span class="dash-widget-title"><i class="bi bi-clipboard-check me-2"></i>Pending Checklists</span>
        <a href="<?= APP_BASE ?>/pages/maintenance.php" class="dash-widget-link">View all</a>
      </div>
      <?php if (empty($pendingChecklists)): ?>
      <div class="dash-empty"><i class="bi bi-clipboard-check"></i><span>All dispatches have checklists</span></div>
      <?php else: ?>
      <div class="dash-table-wrap">
        <table class="table dash-table">
          <thead><tr><th>Truck</th><th>Driver</th><th>Route</th><th>Scheduled</th></tr></thead>
          <tbody>
            <?php foreach ($pendingChecklists as $cl): ?>
            <tr>
              <td><span class="dash-ref"><?= htmlspecialchars($cl['plate_number']) ?></span> <span class="dash-muted"><?= htmlspecialchars($cl['brand'] . ' ' . $cl['model']) ?></span></td>
              <td><?= htmlspecialchars($cl['driver_name']) ?></td>
              <td class="dash-route"><?= htmlspecialchars($cl['origin']) ?> <i class="bi bi-arrow-right"></i> <?= htmlspecialchars($cl['destination']) ?></td>
              <td><?= $cl['scheduled_at'] ? date('M d, H:i', strtotime($cl['scheduled_at'])) : '<span class="dash-muted">—</span>' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Open Incidents -->
    <div class="dash-widget">
      <div class="dash-widget-header">
        <span class="dash-widget-title"><i class="bi bi-exclamation-triangle me-2"></i>Open Incidents</span>
        <a href="<?= APP_BASE ?>/pages/incidents.php" class="dash-widget-link">View all</a>
      </div>
      <?php if (empty($openIncidents)): ?>
      <div class="dash-empty"><i class="bi bi-shield-check"></i><span>No open incidents</span></div>
      <?php else: ?>
      <ul class="dash-list">
        <?php foreach ($openIncidents as $inc): ?>
        <li class="dash-list-item">
          <span class="dash-list-icon dash-list-icon-red"><i class="bi bi-exclamation-triangle-fill"></i></span>
          <div class="dash-list-body"><span class="dash-list-main"><?= htmlspecialchars($inc['incident_type']) ?></span><span class="dash-list-sub"><?= htmlspecialchars($inc['trip_number']) ?> · <?= htmlspecialchars($inc['plate_number']) ?></span></div>
          <span class="dash-list-time"><?= date('M d', strtotime($inc['reported_at'])) ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

    <!-- Low Stock -->
    <div class="dash-widget">
      <div class="dash-widget-header">
        <span class="dash-widget-title"><i class="bi bi-box-seam me-2"></i>Low Stock Parts</span>
        <a href="<?= APP_BASE ?>/pages/parts.php" class="dash-widget-link">View all</a>
      </div>
      <?php if (empty($lowStock)): ?>
      <div class="dash-empty"><i class="bi bi-box-seam"></i><span>All parts adequately stocked</span></div>
      <?php else: ?>
      <ul class="dash-list">
        <?php foreach ($lowStock as $p): ?>
        <li class="dash-list-item">
          <span class="dash-list-icon dash-list-icon-orange"><i class="bi bi-box-seam"></i></span>
          <div class="dash-list-body"><span class="dash-list-main"><?= htmlspecialchars($p['part_name']) ?></span><span class="dash-list-sub"><?= $p['quantity'] ?>/<?= $p['reorder_level'] ?> <?= htmlspecialchars($p['unit']) ?></span></div>
          <?php if ($p['quantity'] == 0): ?><span class="dash-badge dash-badge-red">Out</span><?php else: ?><span class="dash-badge dash-badge-orange">Low</span><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

    <!-- Recent Maintenance Records -->
    <div class="dash-widget">
      <div class="dash-widget-header">
        <span class="dash-widget-title"><i class="bi bi-tools me-2"></i>Recent Records</span>
        <a href="<?= APP_BASE ?>/pages/maintenance.php" class="dash-widget-link">View all</a>
      </div>
      <?php if (empty($recentRecords)): ?>
      <div class="dash-empty"><i class="bi bi-tools"></i><span>No maintenance records yet</span></div>
      <?php else: ?>
      <ul class="dash-list">
        <?php foreach ($recentRecords as $rec): ?>
        <li class="dash-list-item">
          <span class="dash-list-icon dash-list-icon-blue"><i class="bi bi-wrench"></i></span>
          <div class="dash-list-body">
            <span class="dash-list-main"><?= htmlspecialchars($rec['maintenance_type']) ?> · <?= htmlspecialchars($rec['plate_number']) ?></span>
            <span class="dash-list-sub" style="overflow:hidden;white-space:nowrap;text-overflow:ellipsis;max-width:180px;"><?= htmlspecialchars($rec['description']) ?></span>
          </div>
          <span class="dash-list-time"><?= date('M d', strtotime($rec['date_performed'])) ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php layoutFoot(); ?>