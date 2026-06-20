<?php
// ============================================================
// ajax/review_dispatch.php
// Approves or rejects a dispatch request (Head Management only)
// On Approve: sets truck to Deployed + creates a trip row
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isLoggedIn() || currentRoleId() !== ROLE_HEAD_MANAGEMENT) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method.']);
    exit;
}

$dispatchId = filter_input(INPUT_POST, 'dispatch_id', FILTER_VALIDATE_INT);
$status     = trim($_POST['status']  ?? '');
$remarks    = trim($_POST['remarks'] ?? '') ?: null;

if (!$dispatchId || !in_array($status, ['Approved', 'Rejected'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid input.']);
    exit;
}

$pdo = getDBConnection();

// Fetch the dispatch request
$dr = $pdo->prepare(
    "SELECT dr.*, tr.truck_id FROM dispatch_requests dr
       JOIN trucks tr ON dr.truck_id = tr.truck_id
      WHERE dr.dispatch_id = :id AND dr.status = 'Pending' LIMIT 1"
);
$dr->execute([':id' => $dispatchId]);
$dispatch = $dr->fetch();

if (!$dispatch) {
    echo json_encode(['success' => false, 'message' => 'Request not found or already reviewed.']);
    exit;
}

// ---- Update dispatch_requests ----------------------------
$pdo->prepare(
    "UPDATE dispatch_requests
        SET status = :status, approved_by = :user, remarks = :remarks, reviewed_at = NOW()
      WHERE dispatch_id = :id"
)->execute([
    ':status'  => $status,
    ':user'    => currentUserId(),
    ':remarks' => $remarks,
    ':id'      => $dispatchId,
]);

if ($status === 'Approved') {

    // Mark truck as Deployed
    $pdo->prepare("UPDATE trucks SET status = 'Deployed' WHERE truck_id = :id")
        ->execute([':id' => $dispatch['truck_id']]);

    // Generate trip number: TRP-YYYY-NNNN
    $year      = date('Y');
    $countStmt = $pdo->query("SELECT COUNT(*) FROM trips WHERE YEAR(created_at) = $year");
    $count     = (int)$countStmt->fetchColumn() + 1;
    $tripNumber= 'TRP-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);

    // Create the trip
    $pdo->prepare(
        "INSERT INTO trips (dispatch_id, trip_number, status)
         VALUES (:dispatch, :number, 'Loading')"
    )->execute([':dispatch' => $dispatchId, ':number' => $tripNumber]);

    $tripId = (int)$pdo->lastInsertId();
    auditLog('CREATE', 'trips', $tripId, null, ['trip_number' => $tripNumber]);
}

auditLog('UPDATE', 'dispatch_requests', $dispatchId, ['status' => 'Pending'], ['status' => $status]);

echo json_encode(['success' => true, 'message' => "Request {$status}."]);