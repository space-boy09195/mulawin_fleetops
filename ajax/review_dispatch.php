<?php
// ============================================================
// ajax/review_dispatch.php
// Approves or rejects a dispatch request (Head Management only)
// On Approve: sets truck to Deployed + creates a trip row
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT]);
requirePostMethod();
enforceCsrf();

$dispatchId = requiredInt('dispatch_id', 'Dispatch ID', 1);
$status     = requiredEnum('status', ['Approved', 'Rejected'], 'Status');
$remarks    = optionalString('remarks');

$pdo = getDBConnection();

try {
    $pdo->beginTransaction();

    // FOR UPDATE locks this row for the duration of the transaction. If two
    // Head Management users approve/reject the same request at nearly the
    // same moment, the second request's query here blocks until the first
    // transaction commits — then re-checks status = 'Pending' and correctly
    // finds it already reviewed, instead of both proceeding and (on Approve)
    // both trying to deploy the truck and create a trip for the same dispatch.
    $dr = $pdo->prepare(
        "SELECT dr.*, tr.truck_id FROM dispatch_requests dr
           JOIN trucks tr ON dr.truck_id = tr.truck_id
          WHERE dr.dispatch_id = :id AND dr.status = 'Pending'
          LIMIT 1 FOR UPDATE"
    );
    $dr->execute([':id' => $dispatchId]);
    $dispatch = $dr->fetch();

    if (!$dispatch) {
        $pdo->rollBack();
        jsonFail('Request not found or already reviewed.', 409);
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

    $tripId = null;
    $tripNumber = null;

    if ($status === 'Approved') {

        // Mark truck as Deployed
        $pdo->prepare("UPDATE trucks SET status = 'Deployed' WHERE truck_id = :id")
            ->execute([':id' => $dispatch['truck_id']]);

        // Atomic trip-number sequence: INSERT ... ON DUPLICATE KEY UPDATE with
        // LAST_INSERT_ID(expr) is a standard MySQL idiom for a race-free
        // per-key counter — the increment happens inside a single atomic
        // statement, so two concurrent approvals (for different dispatches)
        // can never be handed the same number, unlike the old
        // "SELECT COUNT(*) ... then +1 in PHP" approach.
        $year = (int)date('Y');
        $pdo->prepare("
            INSERT INTO trip_number_counters (year, next_number) VALUES (:year, 1)
            ON DUPLICATE KEY UPDATE next_number = LAST_INSERT_ID(next_number + 1)
        ")->execute([':year' => $year]);
        $seq = (int)$pdo->lastInsertId();
        $tripNumber = 'TRP-' . $year . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

        // Create the trip
        $pdo->prepare(
            "INSERT INTO trips (dispatch_id, trip_number, status)
             VALUES (:dispatch, :number, 'Loading')"
        )->execute([':dispatch' => $dispatchId, ':number' => $tripNumber]);

        $tripId = (int)$pdo->lastInsertId();
    }

    $pdo->commit();

    if ($tripId !== null) {
        auditLog('CREATE', 'trips', $tripId, null, ['trip_number' => $tripNumber]);
    }
    auditLog('UPDATE', 'dispatch_requests', $dispatchId, ['status' => 'Pending'], ['status' => $status]);

    jsonOk([], "Request {$status}.");

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('review_dispatch: ' . $e->getMessage());
    jsonFail('A database error occurred. Please try again.', 500);
}
