<?php
// ============================================================
// ajax/route_request_handler.php
// Route requests on the target schema. routes has no approval columns, so:
//   request  routes row with is_active = 0 and created_by = requester, plus
//            an approval request (workflow 'route_request', entity 'route');
//            the request notes go on the Submitted history row
//   review   the approval engine records the decision; final approval sets
//            is_active = 1, rejection keeps the route inactive
// A route is "approved for dispatch" exactly when it is active.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/core/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../app/shared/validate.php';
require_once __DIR__ . '/../app/shared/db_helpers.php';
require_once __DIR__ . '/../app/core/approval_workflow.php';
require_once __DIR__ . '/../app/modules/trips/route_options.php';

header('Content-Type: application/json');
requirePostMethod();
enforceCsrf();

$pdo = getDBConnection();
$action = $_POST['action'] ?? '';

if ($action === 'request') {
    requirePermission('routes.request');
    $name = requiredString('route_name', 'Route name', 150);
    $origin = requiredString('origin', 'Origin', 255);
    $pickup = requiredString('pickup_location', 'Pickup location', 255);
    $delivery = requiredString('delivery_location', 'Delivery location', 255);
    $distance = optionalFloat('distance_km');
    $requestNotes = optionalString('request_notes', null, 500);
    if ($distance !== null && $distance < 0) {
        jsonFail('Distance cannot be negative.');
    }
    if ($distance !== null && $distance > 100000) {
        jsonFail('Distance is outside the allowed range.');
    }
    if (existsWhere($pdo, 'routes', 'route_name', $name)) {
        jsonFail('A route with that name already exists.');
    }
    $sameOrigin = findRouteByOrigin($pdo, $origin);
    if ($sameOrigin) {
        jsonFail('A route from that origin already exists ("' . $sameOrigin['route_name']
            . '"). Ask the Operations Head to add the pickup and delivery locations to it as options.', 409);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO routes (route_name, origin, destination, distance, is_active, created_by)
             VALUES (?, ?, ?, ?, 0, ?)'
        )->execute([$name, $origin, $delivery, $distance, currentUserId()]);
        $id = (int)$pdo->lastInsertId();
        addRouteOptions($pdo, $id, [['pickup' => $pickup, 'delivery' => $delivery]]);
        $approvalId = createApprovalRequest($pdo, 'route_request', 'route', $id, currentUserId(), $requestNotes);
        auditLog('REQUEST_ROUTE', 'routes', $id, null, ['route_name' => $name, 'approval_request_id' => $approvalId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof DomainException) {
            jsonFail($e->getMessage(), 409);
        }
        error_log('route_request_handler/request: ' . $e->getMessage());
        jsonFail('Could not submit the route request. Please try again.', 500);
    }
    jsonOk(['id' => $id], 'Route request submitted for Operations Head approval.');
}

if ($action === 'review') {
    requirePermission('routes.approve');
    $routeId = requiredInt('route_id', 'Route', 1);
    $status = requiredEnum('status', ['Approved', 'Rejected'], 'Status');
    $remarks = optionalString('remarks', null, 1000);
    $overrideReason = adminOverrideReason();

    // The route row is locked for the whole review; the approval engine
    // locks the request and decides only a Pending step, so only one
    // reviewer can decide it. The decision and its audit rows commit or roll
    // back together.
    $override = false;
    $stepOverride = null;
    $approvalId = null;
    try {
        $pdo->beginTransaction();
        $routeQuery = $pdo->prepare(
            'SELECT route_id, is_active, created_by FROM routes WHERE route_id = ? FOR UPDATE'
        );
        $routeQuery->execute([$routeId]);
        $route = $routeQuery->fetch(PDO::FETCH_ASSOC);
        if (!$route) {
            $pdo->rollBack();
            jsonFail('Route not found.', 404);
        }
        $approvalId = pendingApprovalRequestId($pdo, 'route_request', 'route', $routeId);
        if ($approvalId === null) {
            $pdo->rollBack();
            jsonFail('This route request has already been reviewed.', 409);
        }

        // A reviewer cannot decide their own request (routes.created_by).
        // Admin may, only as an audited override with a reason. A request whose
        // requester no longer exists (NULL) has no owner to match.
        $requesterId = $route['created_by'] === null ? null : (int)$route['created_by'];
        $ownRequest = $requesterId !== null && $requesterId === currentUserId();
        if ($ownRequest && !isAdmin()) {
            $pdo->rollBack();
            jsonFail('You cannot review your own route request.', 403);
        }
        if ($ownRequest) {
            $overrideBypassed = [
                'restriction'   => 'self_review',
                'owner_user_id' => $requesterId,
            ];
            if ($overrideReason === null) {
                adminOverrideRefused('routes', $routeId, $overrideBypassed);
                jsonFail('An Admin override reason (1-500 characters) is required to review your own route request.', 422);
            }
            adminOverrideBegin('routes', $routeId, $overrideBypassed, $overrideReason);
            $override = true;
        }

        $workflowStatus = recordApprovalDecision(
            $pdo,
            $approvalId,
            $status,
            currentUserId(),
            currentRoleId(),
            $remarks,
            isAdmin(),
            $overrideReason,
            $stepOverride
        );
        if ($stepOverride !== null && !$override) {
            adminOverrideBegin('approval_requests', $approvalId, $stepOverride, (string)$overrideReason);
            $override = true;
        }
        if ($workflowStatus === 'Approved') {
            $pdo->prepare('UPDATE routes SET is_active = 1 WHERE route_id = ?')->execute([$routeId]);
        }
        auditLog('REVIEW_ROUTE_REQUEST', 'routes', $routeId, ['approval_status' => 'Pending'], [
            'approval_status'     => $workflowStatus,
            'decision'            => $status,
            'approval_request_id' => $approvalId,
        ]);
        if ($override) {
            auditAdminOverride([
                'action'     => 'review_route_request',
                'decision'   => $status,
                'old_status' => 'Pending',
                'new_status' => $workflowStatus,
            ]);
        }
        $pdo->commit();
        if ($override) adminOverrideSucceeded();
    } catch (AdminOverrideReasonRequired $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        adminOverrideRefused('approval_requests', $approvalId, $e->bypassed);
        jsonFail($e->getMessage(), 422);
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonFail($e->getMessage(), 409);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('route_request_handler/review: ' . $e->getMessage());
        jsonFail('Could not review the route request. Please try again.', 500);
    }
    jsonOk([], $workflowStatus === 'Pending'
        ? 'Approval step recorded and forwarded to the next approver.'
        : "Route request {$status}.");
}

jsonFail('Unknown action.');
