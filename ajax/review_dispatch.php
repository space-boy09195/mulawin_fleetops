<?php
// ============================================================
// ajax/review_dispatch.php
// Records the current approval step of a dispatch request.
//
// Target schema (D2): the trip already exists (status 'Pending Approval').
// Final approval:  dispatch_requests -> Approved, trip -> Approved, truck
//                  reserved (truck_assignments 'Reserved', trucks.status
//                  'Assigned' — the target value for the legacy 'Deployed').
// Final rejection: dispatch_requests -> Rejected, trip -> Cancelled.
// Concurrency: the dispatch request and trip rows are locked first; the
// approval request row is locked inside recordApprovalDecision(); every
// status update is conditional and checked, so a second reviewer (or a
// replayed request) changes nothing.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/approval_workflow.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/idempotency.php';
require_once __DIR__ . '/../includes/dispatch_assignment.php';
require_once __DIR__ . '/../includes/truck_status_history.php';
require_once __DIR__ . '/../includes/trip_workflow.php';
require_once __DIR__ . '/../includes/trip_queries.php';

header('Content-Type: application/json');

requirePermission('approvals.review');
requirePostMethod();
enforceCsrf();

$dispatchId = requiredInt('dispatch_id', 'Dispatch ID', 1);
$status     = requiredEnum('status', ['Approved', 'Rejected'], 'Status');
$remarks    = optionalString('remarks');
// Needed only when Admin decides a step assigned to another role.
$overrideReason = adminOverrideReason();

$pdo = getDBConnection();
$requestKey = requestIdempotencyKey();
if ($requestKey === null) {
    jsonFail('Missing or invalid request idempotency key.', 400);
}
$approvalId = false;
$approvalCreated = false;
$override = null;
$overrideBegun = false;
$requestStatusChanged = false;
$tripId = null;
$tripNumber = null;
$pdo->beginTransaction();
try {
    if (!claimIdempotencyKey($pdo, 'dispatch.review.' . $dispatchId, $requestKey)) {
        $pdo->rollBack();
        jsonFail('This dispatch review has already been processed.', 409);
    }

    $dr = $pdo->prepare(
        "SELECT dispatch_request_id, trip_id, submitted_by, status
         FROM dispatch_requests
         WHERE dispatch_request_id = ? AND status = 'Pending'
         FOR UPDATE"
    );
    $dr->execute([$dispatchId]);
    $dispatch = $dr->fetch(PDO::FETCH_ASSOC);
    if (!$dispatch) {
        $pdo->rollBack();
        jsonFail('Request not found or already reviewed.', 404);
    }
    $tripId = (int)$dispatch['trip_id'];
    $trip = lockTripForWorkflow($pdo, $tripId);
    if (!$trip || $trip['status'] !== 'Pending Approval') {
        throw new DomainException('The trip for this request is no longer awaiting approval. An Admin must check this dispatch.');
    }
    $tripNumber = (string)$trip['trip_number'];

    // uq_approval_pending (T-A1) allows at most one Pending request.
    $approvalId = pendingApprovalRequestId($pdo, 'dispatch', 'dispatch_request', $dispatchId);
    if ($approvalId === null) {
        // A dispatch that is still Pending must not get a second decision when
        // its approval request was already approved or rejected.
        $decidedApproval = $pdo->prepare(
            "SELECT 1
             FROM approval_requests ar
             JOIN approval_workflows w ON w.workflow_id = ar.workflow_id
             WHERE w.workflow_key = 'dispatch'
               AND ar.entity_type = 'dispatch_request'
               AND ar.entity_id = ?
               AND ar.status IN ('Approved', 'Rejected')
             LIMIT 1"
        );
        $decidedApproval->execute([$dispatchId]);
        if ($decidedApproval->fetchColumn()) {
            throw new DomainException('The approval for this dispatch was already decided, but the dispatch is still pending. An Admin must check this dispatch.');
        }
        $approvalId = createApprovalRequest(
            $pdo,
            'dispatch',
            'dispatch_request',
            $dispatchId,
            (int)$dispatch['submitted_by']
        );
        $approvalCreated = true;
    }
    $workflowStatus = recordApprovalDecision(
        $pdo,
        (int)$approvalId,
        $status,
        currentUserId(),
        currentRoleId(),
        $remarks,
        isAdmin(),
        $overrideReason,
        $override
    );
    if ($override !== null) {
        // An approval request created in this transaction may roll back, so
        // its ID is not used as the audit entity ID.
        adminOverrideBegin(
            'approval_requests',
            $approvalCreated ? null : (int)$approvalId,
            $override,
            $overrideReason
        );
        $overrideBegun = true;
    }

    $requestStatusChanged = $workflowStatus !== 'Pending';
    if (!$requestStatusChanged) {
        createNotification(
            $pdo,
            (int)$dispatch['submitted_by'],
            'Approval step completed',
            "A step was approved for dispatch request #{$dispatchId} ({$tripNumber}); it is awaiting the next approver.",
            'dispatch',
            'trip',
            $tripId
        );
    } else {
        $truckId = (int)$trip['truck_id'];
        $previousTruckStatus = null;
        if ($status === 'Approved') {
            $crewIds = array_values(array_filter([
                $trip['driver_id'] === null ? null : (int)$trip['driver_id'],
                $trip['second_driver_id'] === null ? null : (int)$trip['second_driver_id'],
                $trip['helper_id'] === null ? null : (int)$trip['helper_id'],
            ], static fn($id) => $id !== null));
            if ($truckId <= 0 || $trip['driver_id'] === null) {
                throw new DomainException('The trip has no assigned truck or driver.');
            }
            // Locks the truck (must still be Available) and every crew row.
            lockDispatchResources($pdo, $truckId, $crewIds);
            assertDispatchResourcesAvailable($pdo, $truckId, $crewIds, (string)$trip['scheduled_at'], $tripId);
            $previousTruckStatus = 'Available';

            $driverLicense = $pdo->prepare('SELECT license_number FROM employees WHERE employee_id = ?');
            $driverLicense->execute([(int)$trip['driver_id']]);
            if (!$driverLicense->fetchColumn()) {
                throw new DomainException('The assigned driver no longer has a valid license.');
            }
            if ($trip['second_driver_id'] !== null) {
                $driverLicense->execute([(int)$trip['second_driver_id']]);
                if (!$driverLicense->fetchColumn()) {
                    throw new DomainException('The assigned second driver no longer has a valid license.');
                }
            }
            if ($trip['route_id'] !== null) {
                $routeCheck = $pdo->prepare('SELECT 1 FROM routes WHERE route_id = ? AND is_active = 1');
                $routeCheck->execute([(int)$trip['route_id']]);
                if (!$routeCheck->fetchColumn()) {
                    throw new DomainException('The assigned route is no longer approved and active.');
                }
            }
            $clientCheck = $pdo->prepare("SELECT 1 FROM clients WHERE client_id = ? AND status = 'Active'");
            $clientCheck->execute([(int)$trip['client_id']]);
            if (!$clientCheck->fetchColumn()) {
                throw new DomainException('The selected client is no longer active.');
            }
        }

        // Only a Pending dispatch changes, and exactly once.
        $dispatchUpdate = $pdo->prepare(
            "UPDATE dispatch_requests
                SET status = ?, reviewed_by = ?, review_notes = ?, reviewed_at = NOW()
              WHERE dispatch_request_id = ? AND status = 'Pending'"
        );
        $dispatchUpdate->execute([$status, currentUserId(), $remarks, $dispatchId]);
        if ($dispatchUpdate->rowCount() !== 1) {
            throw new DomainException('Request not found or already reviewed.');
        }

        if ($status === 'Approved') {
            transitionTripStatus($pdo, $tripId, ['Pending Approval'], 'Approved');
            reserveTruckForTrip($pdo, $truckId, $tripId, (string)$trip['scheduled_at'], $trip['expected_arrival']);
            recordTruckStatusHistory(
                $pdo,
                $truckId,
                (string)$previousTruckStatus,
                'Assigned',
                "Dispatch request #{$dispatchId} approved; truck reserved for {$tripNumber}."
            );
            recordTripWorkflowEvent(
                $pdo,
                $tripId,
                4,
                'request-approved',
                currentUserId(),
                $remarks ?: 'Operations Head approved the trip request.',
                'Pending Approval',
                'Approved'
            );

            $driverUsers = $pdo->prepare(
                'SELECT user_id FROM employees WHERE employee_id IN (?, ?) AND user_id IS NOT NULL'
            );
            $driverUsers->execute([(int)$trip['driver_id'], (int)($trip['second_driver_id'] ?? 0)]);
            foreach ($driverUsers->fetchAll(PDO::FETCH_COLUMN) as $userId) {
                createNotification(
                    $pdo,
                    (int)$userId,
                    'Dispatch approved',
                    "Your dispatch {$tripNumber} was approved.",
                    'dispatch',
                    'trip',
                    $tripId
                );
            }
        } else {
            transitionTripStatus($pdo, $tripId, ['Pending Approval'], 'Cancelled');
            recordTripWorkflowEvent(
                $pdo,
                $tripId,
                0,
                'request-rejected',
                currentUserId(),
                $remarks ?: 'Operations Head rejected the trip request.',
                'Pending Approval',
                'Cancelled'
            );
        }
        createNotification(
            $pdo,
            (int)$dispatch['submitted_by'],
            'Dispatch ' . strtolower($status),
            "Your dispatch request #{$dispatchId} ({$tripNumber}) was {$status}.",
            'dispatch',
            'trip',
            $tripId
        );
    }
    if ($requestStatusChanged) {
        auditLog('UPDATE', 'dispatch_requests', $dispatchId, ['status' => 'Pending'], ['status' => $status]);
        auditLog('UPDATE', 'trips', $tripId, ['status' => 'Pending Approval'], [
            'status' => $status === 'Approved' ? 'Approved' : 'Cancelled',
        ]);
    }
    auditLog('APPROVAL_DECISION', 'approval_requests', (int)$approvalId, ['status' => 'Pending'], [
        'status'    => $workflowStatus,
        'decision'  => $status,
        'entity_id' => $dispatchId,
    ]);
    if ($override !== null) {
        auditAdminOverride([
            'action'      => 'approval_decision',
            'decision'    => $status,
            'new_status'  => $workflowStatus,
            'dispatch_id' => $dispatchId,
        ]);
    }
    $pdo->commit();
    if ($override !== null) adminOverrideSucceeded();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof AdminOverrideReasonRequired) {
        adminOverrideRefused('approval_requests', $approvalCreated ? null : ($approvalId ? (int)$approvalId : null), $e->bypassed);
        jsonFail($e->getMessage(), 422);
    }
    // recordApprovalDecision() sets $override (by reference) only after the
    // override is identified and its reason accepted, before its first write.
    // If a later write failed, tracking may not have begun: start it now,
    // after the rollback above, so the shutdown handler records
    // ADMIN_OVERRIDE_FAILED with the real HTTP status.
    if (!empty($override) && !$overrideBegun && $overrideReason !== null) {
        try {
            adminOverrideBegin(
                'approval_requests',
                $approvalCreated ? null : ($approvalId ? (int)$approvalId : null),
                $override,
                $overrideReason
            );
        } catch (Throwable $trackingError) {
            error_log('review_dispatch: override failure tracking could not start: ' . $trackingError->getMessage());
        }
    }
    if ($e instanceof DomainException) {
        jsonFail($e->getMessage(), 409);
    }
    error_log('review_dispatch: ' . $e->getMessage());
    // 1205 = lock wait timeout, 1213 = deadlock: the whole transaction,
    // including the idempotency claim, was rolled back above, so nothing was
    // saved and the review can be retried.
    if ($e instanceof PDOException && in_array((int)($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
        jsonFail('Another dispatch review was being saved at the same time. Nothing was saved; please try again.', 409);
    }
    jsonFail('Could not review the dispatch request.', 500);
}

if (!$requestStatusChanged) {
    jsonOk([], 'Approval step recorded and forwarded to the next approver.');
}
jsonOk($status === 'Approved' ? ['trip_id' => $tripId] : [], "Request {$status}.");
