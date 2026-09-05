<?php
// ============================================================
// ajax/update_truck_status.php
// AJAX endpoint — updates a truck's status
// Method : POST
// Params : truck_id (int), status (string)
// Returns: JSON { success: bool, message: string }
// ============================================================

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';

header('Content-Type: application/json');

// ---- Auth check ---------------------------------------------
// Uses the shared requireRole() helper (same one every other
// handler uses) instead of a hand-rolled isLoggedIn() + role
// check, so RBAC logic only lives in one place.
requireRole([ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER]);

requirePostMethod();
enforceCsrf();

// ---- Validate inputs ------------------------------------------
$truckId   = requiredInt('truck_id', 'Truck ID', 1);
$newStatus = requiredEnum('status', TRUCK_STATUSES, 'Status');

// ---- Fetch current status for audit log -----------------------
$pdo   = getDBConnection();
$truck = findOrFail($pdo, 'trucks', 'truck_id', $truckId, 'Truck not found.');
$oldStatus = $truck['status'];

if ($oldStatus === $newStatus) {
    jsonOk([], 'No change needed.');
}

// ---- Update -----------------------------------------------
$update = $pdo->prepare("UPDATE trucks SET status = :status WHERE truck_id = :id");
$update->execute([':status' => $newStatus, ':id' => $truckId]);

// ---- Audit log -------------------------------------------
auditLog('UPDATE', 'trucks', $truckId, ['status' => $oldStatus], ['status' => $newStatus]);

jsonOk([], 'Truck status updated.');