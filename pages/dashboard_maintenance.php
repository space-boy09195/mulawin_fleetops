<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/recommendations.php';

requireRole([ROLE_MAINTENANCE]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/dashboard_maintenance.js';
layoutHead('Dashboard', APP_BASE . '/assets/css/dashboard_maintenance.css');

$pdo = getDBConnection();

// ── Stat cards ────────────────────────────────────────────────────────────────
$underMaint   = (int)$pdo->query("SELECT COUNT(*) FROM trucks WHERE status='Under Maintenance'")->fetchColumn();
$openIncidents = (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE resolved_at IS NULL")->fetchColumn();
$todayChecks  = (int)$pdo->query("SELECT COUNT(*) FROM maintenance_checklists WHERE DATE(submitted_at)=CURDATE()")->fetchColumn();
$lowStockCount = (int)$pdo->query("SELECT COUNT(*) FROM v_low_stock_parts")->fetchColumn();

// ── Trucks needing attention ──────────────────────────────────────────────────
$trucksAttention = $pdo->query("
    SELECT t.truck_id, t.plate_number, t.brand, t.model, t.status,
           mr.truck_status AS last_maint_status, mr.date_performed
    FROM trucks t
    LEFT JOIN (
        SELECT truck_id, truck_status, date_performed,
               ROW_NUMBER() OVER (PARTITION BY truck_id ORDER BY date_performed DESC, created_at DESC) AS rn
        FROM maintenance_records
    ) mr ON t.truck_id = mr.truck_id AND mr.rn = 1
    WHERE t.status IN ('Under Maintenance','Deployed')
       OR mr.truck_status IN ('Under Repair','Scheduled Maintenance')
    ORDER BY
        CASE t.status WHEN 'Under Maintenance' THEN 0 ELSE 1 END,
        mr.date_performed ASC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// ── Parts inventory with progress bars ───────────────────────────────────────
$partsInventory = $pdo->query("
    SELECT part_id, part_name, quantity, reorder_level, unit
    FROM parts_inventory
    ORDER BY (quantity / GREATEST(reorder_level,1)) ASC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// ── Critical maintenance (open incidents on trucks) ───────────────────────────
$criticalMaint = $pdo->query("
    SELECT i.incident_id, i.incident_type, t.trip_number, tr.plate_number, tr.truck_id
    FROM incidents i
    JOIN trips t              ON i.trip_id     = t.trip_id
    JOIN dispatch_requests dr ON t.dispatch_id = dr.dispatch_id
    JOIN trucks tr            ON dr.truck_id   = tr.truck_id
    WHERE i.resolved_at IS NULL AND i.incident_type = 'Vehicle Breakdown'
    ORDER BY i.reported_at DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// ── Upcoming maintenance due — based on each truck's latest next_due_date ────
$upcomingMaint = $pdo->query("
    SELECT tr.plate_number, tr.brand, tr.model, mr.next_due_date, mr.maintenance_type
    FROM trucks tr
    JOIN (
        SELECT truck_id, next_due_date, maintenance_type,
               ROW_NUMBER() OVER (PARTITION BY truck_id ORDER BY date_performed DESC, created_at DESC) AS rn
        FROM maintenance_records
        WHERE next_due_date IS NOT NULL
    ) mr ON tr.truck_id = mr.truck_id AND mr.rn = 1
    WHERE mr.next_due_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
    ORDER BY mr.next_due_date ASC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// ── This week's personal activity ─────────────────────────────────────────────
$weekStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
$currentUserId = $_SESSION['user_id'] ?? 0;

$weeklyChecklists = $pdo->prepare("
    SELECT COUNT(*) FROM maintenance_checklists
    WHERE submitted_by = ? AND submitted_at >= ?
");
$weeklyChecklists->execute([$currentUserId, $weekStart]);
$weeklyChecklistCount = (int)$weeklyChecklists->fetchColumn();

$weeklyRecords = $pdo->prepare("
    SELECT COUNT(*) FROM maintenance_records
    WHERE performed_by = ? AND created_at >= ?
");
$weeklyRecords->execute([$currentUserId, $weekStart]);
$weeklyRecordCount = (int)$weeklyRecords->fetchColumn();

$weeklyMovements = $pdo->prepare("
    SELECT COUNT(*) FROM parts_movements
    WHERE recorded_by = ? AND moved_at >= ?
");
$weeklyMovements->execute([$currentUserId, $weekStart]);
$weeklyMovementCount = (int)$weeklyMovements->fetchColumn();

// ── Recommended Actions (rule-based) ──────────────────────────────────────────
$maintRecs  = getMaintenanceRecommendations($pdo, 4);
$partsRecs  = getPartsReorderRecommendations($pdo, 4);
$recommendations = array_merge($maintRecs, $partsRecs);
usort($recommendations, fn($a, $b) =>
    ($a['priority'] === 'high' ? 0 : 1) <=> ($b['priority'] === 'high' ? 0 : 1)
);
$recommendations = array_slice($recommendations, 0, 6);

// ── Maintenance type distribution for donut ───────────────────────────────────
$maintTypeRows = $pdo->query("
    SELECT maintenance_type, COUNT(*) AS cnt
    FROM maintenance_records
    WHERE date_performed >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
    GROUP BY maintenance_type
")->fetchAll(PDO::FETCH_ASSOC);
$maintTypeMap = [];
foreach ($maintTypeRows as $r) $maintTypeMap[$r['maintenance_type']] = (int)$r['cnt'];
$donutLabels = ['Preventive', 'Corrective', 'Inspection'];
$donutData   = array_map(fn($l) => $maintTypeMap[$l] ?? 0, $donutLabels);
$donutColors = ['#198754', '#dc3545', '#0d6efd'];

// ── Monthly maintenance cost for bar chart ────────────────────────────────────
$costRows = $pdo->query("
    SELECT DATE_FORMAT(date_performed,'%b') AS month,
           MONTH(date_performed) AS mnum,
           SUM(cost) AS total
    FROM maintenance_records
    WHERE date_performed >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
    GROUP BY YEAR(date_performed), MONTH(date_performed)
    ORDER BY YEAR(date_performed), MONTH(date_performed)
")->fetchAll(PDO::FETCH_ASSOC);
$costLabels = array_column($costRows, 'month');
$costData   = array_map(fn($r) => round((float)$r['total'], 2), $costRows);

$GLOBALS['dash_data'] = json_encode([
    'donut' => ['labels' => $donutLabels, 'data' => $donutData, 'colors' => $donutColors],
    'cost'  => ['labels' => $costLabels,  'data' => $costData],
]);
?>
<div class="dm-page">

  <!-- Header -->
  <div class="dm-header">
    <div>
      <h1 class="dm-title">Dashboard</h1>
      <p class="dm-subtitle">Fleet health, checklists, and parts status</p>
    </div>
    <a href="<?= APP_BASE ?>/pages/maintenance.php" class="btn dm-btn-primary">
      <i class="bi bi-wrench me-1"></i> Log Record
    </a>
  </div>

  <!-- Your activity this week -->
  <div class="dm-activity-strip">
    <span class="dm-activity-label"><i class="bi bi-calendar-week"></i> This Week</span>
    <span class="dm-activity-item"><strong><?= $weeklyChecklistCount ?></strong> checklists submitted</span>
    <span class="dm-activity-item"><strong><?= $weeklyRecordCount ?></strong> records logged</span>
    <span class="dm-activity-item"><strong><?= $weeklyMovementCount ?></strong> parts movements</span>
  </div>

  <!-- Recommended Actions -->
  <?php if (!empty($recommendations)): ?>
  <div class="dm-rec-panel">
    <div class="dm-rec-header">
      <i class="bi bi-lightbulb"></i> Recommended Actions
      <span class="dm-rec-count"><?= count($recommendations) ?></span>
    </div>
    <div class="dm-rec-list">
      <?php foreach ($recommendations as $rec): ?>
      <div class="dm-rec-card dm-rec-<?= $rec['priority'] ?>">
        <div class="dm-rec-body">
          <div class="dm-rec-title"><?= htmlspecialchars($rec['title']) ?></div>
          <div class="dm-rec-detail"><?= htmlspecialchars($rec['detail']) ?></div>
        </div>
        <a href="<?= APP_BASE . $rec['action_url'] ?>" class="dm-rec-action"><?= htmlspecialchars($rec['action_label']) ?></a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Stat cards -->
  <div class="dm-stats">
    <div class="dm-stat-card <?= $underMaint > 0 ? 'dm-stat-warn' : '' ?>">
      <div class="dm-stat-label">Under Maintenance</div>
      <div class="dm-stat-value dm-val-orange"><?= $underMaint ?></div>
      <div class="dm-stat-sub">In garage now</div>
    </div>
    <div class="dm-stat-card <?= $openIncidents > 0 ? 'dm-stat-alert' : '' ?>">
      <div class="dm-stat-label">Open Incidents</div>
      <div class="dm-stat-value dm-val-red"><?= $openIncidents ?></div>
      <div class="dm-stat-sub">Requires immediate work</div>
    </div>
    <div class="dm-stat-card">
      <div class="dm-stat-label">Inspections Today</div>
      <div class="dm-stat-value dm-val-blue"><?= $todayChecks ?></div>
      <div class="dm-stat-sub">Pre-trip completed</div>
    </div>
    <div class="dm-stat-card <?= $lowStockCount > 0 ? 'dm-stat-warn' : '' ?>">
      <div class="dm-stat-label">Low Stock Items</div>
      <div class="dm-stat-value dm-val-orange"><?= $lowStockCount ?></div>
      <div class="dm-stat-sub">Reorder needed</div>
    </div>
  </div>

  <!-- Main grid -->
  <div class="dm-grid">

    <!-- Trucks needing attention -->
    <div class="dm-widget">
      <div class="dm-widget-header">
        <span class="dm-widget-title"><i class="bi bi-truck me-2"></i>Trucks Needing Attention</span>
        <a href="<?= APP_BASE ?>/pages/fleet_status.php" class="dm-link">View All</a>
      </div>
      <?php if (empty($trucksAttention)): ?>
      <div class="dm-empty"><i class="bi bi-check-circle"></i><span>All trucks operational</span></div>
      <?php else: ?>
      <div class="dm-table-wrap">
        <table class="table dm-table">
          <thead><tr><th>Truck</th><th>Plate</th><th>Health</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($trucksAttention as $tr):
              $health = match(true) {
                  $tr['status'] === 'Under Maintenance' => ['Critical', 'dm-health-critical'],
                  $tr['last_maint_status'] === 'Under Repair' => ['Critical', 'dm-health-critical'],
                  $tr['last_maint_status'] === 'Scheduled Maintenance' => ['Warning', 'dm-health-warning'],
                  default => ['OK', 'dm-health-ok'],
              };
              $statusCls = match($tr['status']) {
                  'Under Maintenance' => 'dm-badge-orange',
                  'Deployed' => 'dm-badge-blue',
                  'Available' => 'dm-badge-green',
                  default => 'dm-badge-gray',
              };
            ?>
            <tr>
              <td class="dm-muted"><?= htmlspecialchars($tr['brand'] . ' ' . $tr['model']) ?></td>
              <td><span class="dm-plate"><?= htmlspecialchars($tr['plate_number']) ?></span></td>
              <td><span class="dm-health-badge <?= $health[1] ?>"><?= $health[0] ?></span></td>
              <td><span class="dm-status-badge <?= $statusCls ?>"><?= htmlspecialchars($tr['status']) ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Parts inventory progress bars -->
    <div class="dm-widget">
      <div class="dm-widget-header">
        <span class="dm-widget-title"><i class="bi bi-box-seam me-2"></i>Parts Inventory</span>
        <a href="<?= APP_BASE ?>/pages/parts.php" class="dm-link">View All</a>
      </div>
      <?php if (empty($partsInventory)): ?>
      <div class="dm-empty"><i class="bi bi-box-seam"></i><span>No parts in inventory</span></div>
      <?php else: ?>
      <div class="dm-parts-list">
        <?php foreach ($partsInventory as $part):
          $reorder = max($part['reorder_level'] * 2, 1);
          $pct     = min(100, round(($part['quantity'] / $reorder) * 100));
          $barCls  = $part['quantity'] == 0 ? 'dm-bar-empty'
                   : ($part['quantity'] <= $part['reorder_level'] ? 'dm-bar-low' : 'dm-bar-ok');
        ?>
        <div class="dm-part-row">
          <div class="dm-part-header">
            <span class="dm-part-name"><?= htmlspecialchars($part['part_name']) ?></span>
            <span class="dm-part-qty <?= $part['quantity'] == 0 ? 'dm-qty-empty' : ($part['quantity'] <= $part['reorder_level'] ? 'dm-qty-low' : '') ?>">
              <?= $part['quantity'] ?> / <?= $part['reorder_level'] * 2 ?> <?= htmlspecialchars($part['unit']) ?>
            </span>
          </div>
          <div class="dm-bar-track">
            <div class="dm-bar-fill <?= $barCls ?>" style="width:<?= $pct ?>%"></div>
          </div>
          <?php if ($part['quantity'] <= $part['reorder_level']): ?>
          <a href="<?= APP_BASE ?>/pages/parts.php?quick_movement=<?= $part['part_id'] ?>&type=Stock+In"
             class="dm-reorder-btn">
            <i class="bi bi-box-arrow-in-down"></i> Reorder
          </a>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Maintenance type donut -->
    <div class="dm-widget">
      <div class="dm-widget-header">
        <span class="dm-widget-title"><i class="bi bi-pie-chart me-2"></i>Maintenance Type (90 Days)</span>
      </div>
      <div class="dm-chart-wrap">
        <canvas id="maintTypeChart"></canvas>
      </div>
    </div>

    <!-- Monthly cost bar chart -->
    <div class="dm-widget">
      <div class="dm-widget-header">
        <span class="dm-widget-title"><i class="bi bi-bar-chart me-2"></i>Maintenance Cost (₱)</span>
      </div>
      <div class="dm-chart-wrap">
        <canvas id="maintCostChart"></canvas>
      </div>
    </div>

    <!-- Upcoming Maintenance Due -->
    <div class="dm-widget dm-widget-wide">
      <div class="dm-widget-header">
        <span class="dm-widget-title"><i class="bi bi-calendar2-week me-2"></i>Upcoming Maintenance Due (Next 14 Days)</span>
        <a href="<?= APP_BASE ?>/pages/maintenance.php" class="dm-link">Log Record</a>
      </div>
      <?php if (empty($upcomingMaint)): ?>
      <div class="dm-empty"><i class="bi bi-calendar2-check"></i><span>Nothing due for preventive maintenance soon</span></div>
      <?php else: ?>
      <div class="dm-table-wrap">
        <table class="table dm-table">
          <thead><tr><th>Truck</th><th>Type</th><th>Due Date</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($upcomingMaint as $um):
              $isOverdue = strtotime($um['next_due_date']) < strtotime('today');
              $isSoon    = !$isOverdue && strtotime($um['next_due_date']) <= strtotime('+3 days');
            ?>
            <tr>
              <td class="dm-muted"><?= htmlspecialchars($um['brand'] . ' ' . $um['model']) ?></td>
              <td><?= htmlspecialchars($um['maintenance_type']) ?></td>
              <td><?= date('M d, Y', strtotime($um['next_due_date'])) ?></td>
              <td>
                <?php if ($isOverdue): ?>
                <span class="dm-health-badge dm-health-critical">Overdue</span>
                <?php elseif ($isSoon): ?>
                <span class="dm-health-badge dm-health-warning">Due Soon</span>
                <?php else: ?>
                <span class="dm-health-badge dm-health-ok">Upcoming</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- Critical maintenance banner -->
  <?php if ($criticalMaint): ?>
  <div class="dm-alert-banner">
    <i class="bi bi-exclamation-circle-fill dm-alert-icon"></i>
    <div class="dm-alert-body">
      <strong>Critical Maintenance Required</strong>
      <span>
        <?= htmlspecialchars($criticalMaint['plate_number']) ?>
        flagged with <?= htmlspecialchars($criticalMaint['incident_type']) ?> on trip
        <?= htmlspecialchars($criticalMaint['trip_number']) ?>.
        Schedule maintenance before next dispatch.
      </span>
    </div>
    <a href="<?= APP_BASE ?>/pages/maintenance.php" class="dm-alert-btn">Schedule</a>
  </div>
  <?php endif; ?>

</div>

<script>window.DASH_DATA = <?= $GLOBALS['dash_data'] ?>;</script>
<?php layoutFoot(); ?>