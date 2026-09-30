<?php
// ============================================================
// ajax/review_dispatch.php
// Records an assigned approval step; final approval creates the trip.
// On Approve: sets truck to Deployed + creates a trip row
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/trip_number.php';
require_once __DIR__ . '/../includes/approval_workflow.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/idempotency.php';
require_once __DIR__ . '/../includes/dispatch_assignment.php';
require_once __DIR__ . '/../includes/truck_status_history.php';
require_once __DIR__ . '/../includes/trip_workflow.php';

header('Content-Type: application/json');

requirePermission('approvals.review');
requirePostMethod();
enforceCsrf();

$dispatchId = requiredInt('dispatch_id', 'Dispatch ID', 1);
$status     = requiredEnum('status', ['Approved', 'Rejected'], 'Status');
$remarks    = optionalString('remarks');

$pdo = getDBConnection();
$requestKey = requestIdempotencyKey();
if ($requestKey === null) {
    jsonFail('Missing or invalid request idempotency key.', 400);
}
$pdo->beginTransaction();
try {
    if (!claimIdempotencyKey($pdo, 'dispatch.review.' . $dispatchId, $requestKey)) {
        $pdo->rollBack();
        jsonFail('This dispatch review has already been processed.', 409);
    }

// Fetch the dispatch request
    $dr = $pdo->prepare(
        "SELECT dr.*
         FROM dispatch_requests dr
         WHERE dr.dispatch_id = :id AND dr.status = 'Pending'
         FOR UPDATE"
    );
    $dr->execute([':id' => $dispatchId]);
    $dispatch = $dr->fetch();

    if (!$dispatch) {
        $pdo->rollBack();
        jsonFail('Request not found or already reviewed.', 404);
    }
    if (($dispatch['status'] ?? '') !== 'Pending') {
        $pdo->rollBack();
        jsonFail('Request not found or already reviewed.', 404);
    }
    $approvalQuery = $pdo->prepare(
        "SELECT approval_id
         FROM approval_requests
         WHERE request_type = 'dispatch'
           AND entity_type = 'dispatch_request'
           AND entity_id = ?
           AND status = 'Pending'
         ORDER BY approval_id DESC
         LIMIT 1
         FOR UPDATE"
    );
    $approvalQuery->execute([$dispatchId]);
    $approvalId = $approvalQuery->fetchColumn();
    if (!$approvalId) {
        $approvalId = createApprovalRequest(
            $pdo,
            'dispatch',
            'dispatch_request',
            $dispatchId,
            (int)$dispatch['requested_by']
        );
    }
    $workflowStatus = recordApprovalDecision(
        $pdo,
        (int)$approvalId,
        $status,
        currentUserId(),
        currentRoleId(),
        $remarks
    );
        $tripId = null;
        $tripNumber = null;
        $requestStatusChanged = $workflowStatus !== 'Pending';

        if (!$requestStatusChanged) {
            createNotification(
                $pdo,
                (int)$dispatch['requested_by'],
                'Approval step completed',
                "A step was approved for dispatch request #{$dispatchId}; it is awaiting the next approver.",
                APP_BASE . '/pages/dispatch.php'
            );
        } else {
            if ($status === 'Approved') {
                $crewIds = array_filter([
                    (int)$dispatch['driver_id'],
                    $dispatch['second_driver_id'] === null ? null : (int)$dispatch['second_driver_id'],
                    $dispatch['helper_id'] === null ? null : (int)$dispatch['helper_id'],
                ], static fn($id) => $id !== null);
                lockDispatchResources($pdo, (int)$dispatch['truck_id'], $crewIds);
                assertDispatchResourcesAvailable(
                    $pdo,
                    (int)$dispatch['truck_id'],
                    $crewIds,
                    (string)$dispatch['scheduled_at'],
                    $dispatchId
                );
                $truckStatusQuery = $pdo->prepare(
                    'SELECT status FROM trucks WHERE truck_id = ? FOR UPDATE'
                );
                $truckStatusQuery->execute([(int)$dispatch['truck_id']]);
                $previousTruckStatus = $truckStatusQuery->fetchColumn();
                if ($previousTruckStatus !== 'Available') {
                    $pdo->rollBack();
                    jsonFail('The assigned truck is no longer Available.', 409);
                }

                $driverLicense = $pdo->prepare(
                    'SELECT license_number FROM employees WHERE employee_id = ?'
                );
                $driverLicense->execute([(int)$dispatch['driver_id']]);
                if (!$driverLicense->fetchColumn()) {
                    $pdo->rollBack();
                    jsonFail('The assigned driver no longer has a valid license.', 409);
                }
                if ($dispatch['second_driver_id'] !== null) {
                    $driverLicense->execute([(int)$dispatch['second_driver_id']]);
                    if (!$driverLicense->fetchColumn()) {
                        $pdo->rollBack();
                        jsonFail('The assigned second driver no longer has a valid license.', 409);
                    }
                }

                $routeCheck = $pdo->prepare(
                    "SELECT 1 FROM routes
                     WHERE route_id = ? AND is_active = 1 AND approval_status = 'Approved'"
                );
                $routeCheck->execute([(int)$dispatch['route_id']]);
                if (!$routeCheck->fetchColumn()) {
                    $pdo->rollBack();
                    jsonFail('The assigned route is no longer approved and active.', 409);
                }

                if (!empty($dispatch['client_id'])) {
                    $clientCheck = $pdo->prepare('SELECT is_active FROM clients WHERE client_id = ?');
                    $clientCheck->execute([(int)$dispatch['client_id']]);
                    if (!(int)$clientCheck->fetchColumn()) {
                        $pdo->rollBack();
                        jsonFail('The selected client is no longer active.', 409);
                    }
                    if ($dispatch['origin_location_id'] !== null && $dispatch['destination_location_id'] !== null) {
                        $locationCheck = $pdo->prepare(
                            'SELECT COUNT(*)
                             FROM client_locations
                             WHERE client_id = ? AND is_active = 1
                               AND location_id IN (?, ?)'
                        );
                        $locationCheck->execute([
                            (int)$dispatch['client_id'],
                            (int)$dispatch['origin_location_id'],
                            (int)$dispatch['destination_location_id'],
                        ]);
                        if ((int)$locationCheck->fetchColumn() !== 2) {
                            $pdo->rollBack();
                            jsonFail('An assigned client location is no longer active.', 409);
                        }
                    }
                }
            }

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
                $pdo->prepare("UPDATE trucks SET status = 'Deployed' WHERE truck_id = :id")
                    ->execute([':id' => $dispatch['truck_id']]);
                recordTruckStatusHistory(
                    $pdo,
                    (int)$dispatch['truck_id'],
                    (string)$previousTruckStatus,
                    'Deployed',
                    "Dispatch request #{$dispatchId} approved; trip deployed."
                );

                $tripNumber = nextTripNumber($pdo);

                $pdo->prepare(
                    "INSERT INTO trips (dispatch_id, trip_number, status, shift, expected_arrival)
                     VALUES (:dispatch, :number, 'Loading', :shift, :expected_arrival)"
                )->execute([
                    ':dispatch' => $dispatchId,
                    ':number' => $tripNumber,
                    ':shift' => $dispatch['shift'],
                    ':expected_arrival' => $dispatch['expected_arrival'],
                ]);

                $tripId = (int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE maintenance_checklists SET trip_id = ? WHERE dispatch_id = ? AND trip_id IS NULL')
                    ->execute([$tripId, $dispatchId]);
                $pdo->prepare(
                    'UPDATE trip_workflow_state SET trip_id = ? WHERE dispatch_id = ?'
                )->execute([$tripId, $dispatchId]);
                $pdo->prepare(
                    'UPDATE trip_workflow_events SET trip_id = ? WHERE dispatch_id = ? AND trip_id IS NULL'
                )->execute([$tripId, $dispatchId]);

                $driverUser = $pdo->prepare('SELECT user_id FROM employees WHERE employee_id = ?');
                $driverUser->execute([(int)$dispatch['driver_id']]);
                if ($userId = $driverUser->fetchColumn()) {
                    createNotification(
                        $pdo,
                        (int)$userId,
                        'Dispatch approved',
                        "Your dispatch {$tripNumber} was approved.",
                        APP_BASE . '/pages/trip_monitor.php'
                    );
                }
            }
            createNotification(
                $pdo,
                (int)$dispatch['requested_by'],
                'Dispatch ' . strtolower($status),
                "Your dispatch request #{$dispatchId} was {$status}.",
                APP_BASE . '/pages/dispatch.php'
            );
        }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof DomainException) {
        jsonFail($e->getMessage(), 409);
    }
    error_log('review_dispatch: ' . $e->getMessage());
    jsonFail('Could not review the dispatch request.', 500);
}

if ($requestStatusChanged) {
    auditLog('UPDATE', 'dispatch_requests', $dispatchId, ['status' => 'Pending'], ['status' => $status]);
}
auditLog('APPROVAL_DECISION', 'approval_requests', (int)$approvalId, ['status' => 'Pending'], [
    'status' => $workflowStatus,
    'decision' => $status,
    'entity_id' => $dispatchId,
]);
if ($tripId !== null) {
    auditLog('CREATE', 'trips', $tripId, null, ['trip_number' => $tripNumber]);
}

if (!$requestStatusChanged) {
    jsonOk([], 'Approval step recorded and forwarded to the next approver.');
}
jsonOk($tripId !== null ? ['trip_id' => $tripId] : [], "Request {$status}.");
