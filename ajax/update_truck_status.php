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

header('Content-Type: application/json');

// ---- Auth check -------------------------------------------
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

if (!in_array(currentRoleId(), [ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER], true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

// ---- Only accept POST -------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// ---- Validate inputs --------------------------------------
$truckId   = filter_input(INPUT_POST, 'truck_id', FILTER_VALIDATE_INT);
$newStatus = trim($_POST['status'] ?? '');

$allowedStatuses = ['Available', 'Deployed', 'Under Maintenance', 'Inactive'];

if (!$truckId || $truckId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid truck ID.']);
    exit;
}

if (!in_array($newStatus, $allowedStatuses, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status value.']);
    exit;
}

// ---- Fetch current status for audit log -------------------
$pdo  = getDBConnection();
$stmt = $pdo->prepare("SELECT status FROM trucks WHERE truck_id = :id LIMIT 1");
$stmt->execute([':id' => $truckId]);
$truck = $stmt->fetch();

if (!$truck) {
    echo json_encode(['success' => false, 'message' => 'Truck not found.']);
    exit;
}

$oldStatus = $truck['status'];

if ($oldStatus === $newStatus) {
    echo json_encode(['success' => true, 'message' => 'No change needed.']);
    exit;
}

// ---- Update -----------------------------------------------
$update = $pdo->prepare(
    "UPDATE trucks SET status = :status WHERE truck_id = :id"
);
$update->execute([':status' => $newStatus, ':id' => $truckId]);

// ---- Audit log -------------------------------------------
auditLog('UPDATE', 'trucks', $truckId, ['status' => $oldStatus], ['status' => $newStatus]);

echo json_encode(['success' => true, 'message' => 'Truck status updated.']);