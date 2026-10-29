<?php
// ============================================================
// pages/fleet_status.php
// Fleet Status — view, add, and edit trucks
// Access is controlled by the fleet.view permission.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../app/core/audit.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../app/modules/fleet/truck_records.php';
require_once __DIR__ . '/../app/modules/trips/trip_queries.php';
require_once __DIR__ . '/../app/shared/metrics.php';

requirePermission('fleet.view');

$GLOBALS['page_js'] = APP_BASE . '/assets/js/fleet_status.js';

$pdo = getDBConnection();

// ── Full truck list with active trip info ─────────────────────────────────────
// The current trip comes from a derived table (ROW_NUMBER per truck), so a
// truck with several open trips still appears once. Profile fields kept in
// related tables come from includes/truck_records.php.
$trucks = $pdo->query("
    SELECT
        " . truckProfileColumnsSql() . ",
        cur.trip_number,
        cur.driver_name,
        cur.origin,
        cur.destination
    FROM trucks t
    LEFT JOIN (
        SELECT
            tp.truck_id, tp.trip_number, e.full_name AS driver_name,
            COALESCE(r.origin, tp.origin) AS origin,
            COALESCE(r.destination, tp.destination) AS destination,
            ROW_NUMBER() OVER (PARTITION BY tp.truck_id ORDER BY tp.created_at DESC) AS rn
        FROM trips tp
        LEFT JOIN dispatch_requests dr ON dr.trip_id = tp.trip_id
        LEFT JOIN employees e ON tp.driver_id = e.employee_id
        LEFT JOIN routes r    ON tp.route_id  = r.route_id
        WHERE tp.truck_id IS NOT NULL
          AND tp.status NOT IN ('Completed','Cancelled')
          AND " . tripOperationalSql('tp', 'dr') . "
    ) cur ON cur.truck_id = t.truck_id AND cur.rn = 1
    ORDER BY
        FIELD(t.status,'On Trip','Assigned','Available','Under Maintenance','Unavailable'),
        t.plate_number
")->fetchAll();
foreach ($trucks as &$truckRow) {
    // The legacy single image column is the front view.
    $truckRow['image_path'] = $truckRow['image_front_path'];
}
unset($truckRow);

$isHead = isAdmin() || currentUserHasAnyPermission(['fleet.manage']); // Truck add/edit, matching ajax/trucks_handler.php

layoutHead('Fleet Status', APP_BASE . '/assets/css/fleet_status.css');
?>
<?php require __DIR__ . '/../resources/views/fleet_status/page.php'; ?>
<?php layoutFoot(); ?>