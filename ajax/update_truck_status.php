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
require_once __DIR__ . '/../includes/truck_status_history.php';

header('Content-Type: application/json');

// ---- Auth check ---------------------------------------------
// Uses the shared requireRole() helper (same one every other
// handler uses) instead of a hand-rolled isLoggedIn() + role
// check, so RBAC logic only lives in one place.
requireRole([ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER, ROLE_MAINTENANCE]);

requirePostMethod();
enforceCsrf();

// ---- Validate inputs ------------------------------------------
$truckId = requiredInt('truck_id', 'Truck ID', 1);
$pdo   = getDBConnection();

if (($_POST['action'] ?? '') === 'history') {
    findOrFail($pdo, 'trucks', 'truck_id', $truckId, 'Truck not found.');
    $history = $pdo->prepare(
        'SELECT h.previous_status, h.new_status, h.reason, h.changed_at,
                u.full_name AS changed_by_name
         FROM truck_status_history h
         LEFT JOIN users u ON u.user_id = h.changed_by
         WHERE h.truck_id = ?
         ORDER BY h.changed_at DESC, h.status_history_id DESC
         LIMIT 100'
    );
    $history->execute([$truckId]);
    jsonOk(['history' => $history->fetchAll(PDO::FETCH_ASSOC)]);
}

$newStatus = requiredEnum('status', TRUCK_STATUSES, 'Status');
$reason = optionalString('reason', null, 500);

try {
    $pdo->beginTransaction();
    $truckQuery = $pdo->prepare('SELECT status FROM trucks WHERE truck_id = ? FOR UPDATE');
    $truckQuery->execute([$truckId]);
    $oldStatus = $truckQuery->fetchColumn();
    if ($oldStatus === false) {
        $pdo->rollBack();
        jsonFail('Truck not found.', 404);
    }
    if ($oldStatus === $newStatus) {
        $pdo->rollBack();
        jsonOk([], 'No change needed.');
    }
    if ($reason === null) {
        $pdo->rollBack();
        jsonFail('Enter a reason for the status change.');
    }

    $update = $pdo->prepare("UPDATE trucks SET status = :status WHERE truck_id = :id");
    $update->execute([':status' => $newStatus, ':id' => $truckId]);
    recordTruckStatusHistory($pdo, $truckId, (string)$oldStatus, $newStatus, $reason);
    $pdo->commit();
    auditLog('UPDATE', 'trucks', $truckId, ['status' => $oldStatus], [
        'status' => $newStatus,
        'reason' => $reason,
    ]);
    jsonOk([], 'Truck status updated.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('update_truck_status: ' . $e->getMessage());
    jsonFail('Could not update truck status.', 500);
}
