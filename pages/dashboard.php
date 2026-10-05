<?php
// ============================================================
// pages/dashboard.php
// Common home page for every non-Admin account. Sections are
// driven only by the signed-in user's role permissions, so new
// roles/users need no additional dashboard code.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireLogin();

$pdo = getDBConnection();

// Dashboard reads degrade to an empty state instead of a fatal error.
function dashboardQuery(callable $query, mixed $default): mixed {
    try {
        return $query();
    } catch (PDOException $e) {
        error_log('Dashboard query failed: ' . $e->getMessage());
        return $default;
    }
}

$can = static fn(string $permission): bool => currentUserHasAnyPermission([$permission]);

$canTrips       = $can('trips.view');
$canFleet       = $can('fleet.view');
$canMaintenance = $can('maintenance.view');
$canParts       = $can('parts.view');
$canBilling     = $can('billing.view');
$canPayroll     = $can('payroll.view');
$canApprovals   = $can('approvals.review');

$stats = [];

if ($canTrips) {
    $row = dashboardQuery(static fn() => $pdo->query(
        "SELECT SUM(status NOT IN ('Completed','Cancelled')) AS active_trips,
                SUM(is_late = 1 AND status NOT IN ('Completed','Cancelled')) AS late_trips
         FROM trips"
    )->fetch(PDO::FETCH_ASSOC), []);
    $stats[] = ['Active Trips', (int)($row['active_trips'] ?? 0), 'bi-map', 'blue', '/pages/trip_monitor.php'];
    $stats[] = ['Late Trips', (int)($row['late_trips'] ?? 0), 'bi-alarm', 'red', '/pages/trip_monitor.php'];
    $pendingDispatch = dashboardQuery(static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM dispatch_requests WHERE status = 'Pending'"
    )->fetchColumn(), 0);
    $stats[] = ['Pending Dispatch Requests', $pendingDispatch, 'bi-send', 'amber', '/pages/dispatch.php'];
}

if ($canFleet) {
    $row = dashboardQuery(static fn() => $pdo->query(
        "SELECT SUM(status = 'Available') AS available_trucks,
                SUM(status = 'Under Maintenance') AS maintenance_trucks
         FROM trucks"
    )->fetch(PDO::FETCH_ASSOC), []);
    $stats[] = ['Available Trucks', (int)($row['available_trucks'] ?? 0), 'bi-truck', 'green', '/pages/fleet_status.php'];
    $stats[] = ['Trucks Under Maintenance', (int)($row['maintenance_trucks'] ?? 0), 'bi-tools', 'amber', '/pages/fleet_status.php'];
}

if ($canMaintenance) {
    $openOrders = dashboardQuery(static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM repair_work_orders WHERE status IN ('Open','In Progress')"
    )->fetchColumn(), 0);
    $dueSchedules = dashboardQuery(static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM preventive_maintenance_schedules
         WHERE status = 'Active' AND next_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
    )->fetchColumn(), 0);
    $stats[] = ['Open Work Orders', $openOrders, 'bi-wrench-adjustable', 'blue', '/pages/maintenance.php'];
    $stats[] = ['PM Schedules Due (7 days)', $dueSchedules, 'bi-calendar-check', 'amber', '/pages/maintenance.php'];
}

if ($canParts) {
    $lowStock = dashboardQuery(static fn() => (int)$pdo->query(
        'SELECT COUNT(*) FROM v_low_stock_parts'
    )->fetchColumn(), 0);
    $stats[] = ['Low-Stock Parts', $lowStock, 'bi-box-seam', 'red', '/pages/parts.php'];
}

if ($canBilling) {
    $openBillings = dashboardQuery(static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM billings WHERE status IN ('Unpaid','Partial')"
    )->fetchColumn(), 0);
    $stats[] = ['Unpaid / Partial Invoices', $openBillings, 'bi-receipt', 'amber', '/pages/billing.php'];
}

if ($canPayroll) {
    $monthPayroll = dashboardQuery(static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM payroll_records
         WHERE pay_period_end >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
    )->fetchColumn(), 0);
    $stats[] = ['Payroll Records This Month', $monthPayroll, 'bi-cash-stack', 'blue', '/pages/payroll.php'];
}

if ($canApprovals) {
    $pendingApprovals = dashboardQuery(static function () use ($pdo): int {
        $sql = "SELECT COUNT(*)
                FROM approval_steps s
                JOIN approval_requests a ON a.approval_id = s.approval_id
                WHERE a.status = 'Pending' AND s.status = 'Pending'
                  AND s.step_order = a.current_step";
        $params = [];
        if (currentRoleId() !== ROLE_ADMIN) {
            $sql .= ' AND s.approver_role_id = ?';
            $params[] = currentRoleId();
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }, 0);
    $stats[] = ['Awaiting My Approval', $pendingApprovals, 'bi-inbox', 'amber', '/pages/requests.php'];
}

$recentTrips = [];
if ($canTrips) {
    $recentTrips = dashboardQuery(static fn() => $pdo->query(
        "SELECT t.trip_number, t.status, t.is_late, t.updated_at,
                r.origin, r.destination, tr.plate_number
         FROM trips t
         JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
         JOIN routes r             ON r.route_id = dr.route_id
         JOIN trucks tr            ON tr.truck_id = dr.truck_id
         WHERE t.status NOT IN ('Completed','Cancelled')
         ORDER BY t.is_late DESC, t.updated_at DESC
         LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC), []);
}

// Shortcuts to every module this account may open (excluding the dashboard itself).
$shortcuts = [];
$sectionLabel = '';
foreach (getNavItems() as $item) {
    if (isset($item['section'])) {
        $sectionLabel = $item['section'];
        continue;
    }
    if ($item['href'] === '/pages/dashboard.php' || !navItemVisible($item)) {
        continue;
    }
    $shortcuts[$sectionLabel][] = $item;
}

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$fullName = (string)($_SESSION['full_name'] ?? '');
$roleName = (string)($_SESSION['role_name'] ?? '');

layoutHead('Dashboard');
?>

<div class="page-header">
  <h1 class="page-title"><?= htmlspecialchars($greeting . ($fullName !== '' ? ', ' . $fullName : '')) ?></h1>
  <p class="page-subtitle">
    <?= htmlspecialchars($roleName !== '' ? $roleName : 'Account') ?> &middot; <?= date('l, F j, Y') ?>
  </p>
</div>

<?php if ($stats): ?>
<div class="row g-3 mb-4">
  <?php foreach ($stats as [$label, $value, $icon, $color, $href]): ?>
  <div class="col-6 col-xl-3">
    <a href="<?= htmlspecialchars(APP_BASE . $href) ?>" class="text-decoration-none">
      <div class="stat-card">
        <div class="stat-icon <?= htmlspecialchars($color) ?>"><i class="bi <?= htmlspecialchars($icon) ?>"></i></div>
        <div class="stat-info">
          <div class="stat-value"><?= (int)$value ?></div>
          <div class="stat-label"><?= htmlspecialchars($label) ?></div>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($canTrips): ?>
<div class="card mb-4">
  <div class="card-header-custom d-flex justify-content-between align-items-center">
    <h2 class="card-title-custom mb-0">Active trips</h2>
    <a href="<?= APP_BASE ?>/pages/trip_monitor.php" class="btn btn-sm btn-outline-primary">Trip monitoring</a>
  </div>
  <div class="table-responsive">
    <table class="table-custom">
      <thead><tr><th>Trip</th><th>Truck</th><th>Route</th><th>Status</th><th>Updated</th></tr></thead>
      <tbody>
      <?php if (!$recentTrips): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">No active trips right now.</td></tr>
      <?php else: foreach ($recentTrips as $trip): ?>
        <tr>
          <td><?= htmlspecialchars($trip['trip_number'] ?? '—') ?></td>
          <td><?= htmlspecialchars($trip['plate_number'] ?? '—') ?></td>
          <td><?= htmlspecialchars(($trip['origin'] ?? '—') . ' → ' . ($trip['destination'] ?? '—')) ?></td>
          <td>
            <?= htmlspecialchars($trip['status']) ?>
            <?php if ((int)$trip['is_late'] === 1): ?><span class="badge text-bg-danger ms-1">Late</span><?php endif; ?>
          </td>
          <td><?= $trip['updated_at'] ? date('M j, g:i A', strtotime($trip['updated_at'])) : '—' ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header-custom">
    <h2 class="card-title-custom mb-0">Your modules</h2>
  </div>
  <div class="p-3">
    <?php if (!$shortcuts): ?>
      <p class="text-muted mb-0">No modules are assigned to your role yet. Please contact your administrator.</p>
    <?php else: foreach ($shortcuts as $section => $items): ?>
      <div class="mb-3">
        <?php if ($section !== ''): ?>
          <div class="text-muted small fw-semibold text-uppercase mb-2"><?= htmlspecialchars($section) ?></div>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($items as $item): ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(APP_BASE . $item['href']) ?>">
              <i class="bi <?= htmlspecialchars($item['icon']) ?> me-1"></i><?= htmlspecialchars($item['label']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php layoutFoot(); ?>
