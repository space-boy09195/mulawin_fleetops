<?php
// ============================================================
// ajax/approval_decision_handler.php
// Decide the current approval step of a purchase request, fund request,
// payment voucher or payroll approval (target approval engine, T-A1).
// Dispatch and route requests keep their own handlers
// (review_dispatch.php, route_request_handler.php) because their decisions
// change trips, trucks and routes.
//
// Safety: idempotency key; the approval request row is locked by the
// engine; a requester cannot decide their own request (Admin only with an
// audited override reason); the entity status changes only on the final
// decision and only from 'Pending Approval' (conditional update).
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/idempotency.php';
require_once __DIR__ . '/../includes/approval_workflow.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/workflow_notifications.php';
require_once __DIR__ . '/../includes/purchasing.php';

header('Content-Type: application/json');

requirePermission('approvals.review');
requirePostMethod();
enforceCsrf();

// workflow_key => [entity table, id column, notification entity, label]
const GENERIC_APPROVAL_WORKFLOWS = [
    'purchase_request' => ['purchase_requests', 'purchase_request_id', 'purchase_request', 'Purchase request'],
    'fund_request'     => ['fund_requests', 'fund_request_id', 'fund_request', 'Fund request'],
    'payment_voucher'  => ['payment_vouchers', 'voucher_id', 'payment_voucher', 'Payment voucher'],
    'payroll'          => ['payroll_periods', 'payroll_period_id', null, 'Payroll period'],
];

$approvalId = requiredInt('approval_request_id', 'Approval request', 1);
$decision   = requiredEnum('decision', ['Approved', 'Rejected'], 'Decision');
$remarks    = optionalString('remarks', null, 1000);
$overrideReason = adminOverrideReason();

$pdo = getDBConnection();
$requestKey = requestIdempotencyKey();
if ($requestKey === null) {
    jsonFail('Missing or invalid request idempotency key.', 400);
}

$override = null;
$overrideBegun = false;
$selfOverride = false;
try {
    $pdo->beginTransaction();
    if (!claimIdempotencyKey($pdo, 'approval.decision.' . $approvalId, $requestKey)) {
        $pdo->rollBack();
        jsonFail('This approval decision has already been processed.', 409);
    }
    $request = $pdo->prepare(
        "SELECT ar.approval_request_id, ar.entity_type, ar.entity_id, ar.requested_by, ar.status,
                w.workflow_key
         FROM approval_requests ar
         JOIN approval_workflows w ON w.workflow_id = ar.workflow_id
         WHERE ar.approval_request_id = ?
         FOR UPDATE"
    );
    $request->execute([$approvalId]);
    $row = $request->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['status'] !== 'Pending') {
        $pdo->rollBack();
        jsonFail('Approval request is not pending.', 409);
    }
    $config = GENERIC_APPROVAL_WORKFLOWS[$row['workflow_key']] ?? null;
    if ($config === null) {
        $pdo->rollBack();
        jsonFail('Review this request from its own page.', 409);
    }
    [$table, $idColumn, $notifyEntity, $label] = $config;
    $entityId = (int)$row['entity_id'];

    // No one decides their own request; Admin only as an audited override.
    $ownRequest = (int)$row['requested_by'] === currentUserId();
    if ($ownRequest && !isAdmin()) {
        $pdo->rollBack();
        jsonFail('You cannot decide your own request.', 403);
    }
    if ($ownRequest) {
        $bypassed = ['restriction' => 'self_review', 'owner_user_id' => (int)$row['requested_by']];
        if ($overrideReason === null) {
            adminOverrideRefused('approval_requests', $approvalId, $bypassed);
            jsonFail('An Admin override reason (1-500 characters) is required to decide your own request.', 422);
        }
        adminOverrideBegin('approval_requests', $approvalId, $bypassed, $overrideReason);
        $overrideBegun = true;
        $selfOverride = true;
    }

    $workflowStatus = recordApprovalDecision(
        $pdo,
        $approvalId,
        $decision,
        currentUserId(),
        currentRoleId(),
        $remarks,
        isAdmin(),
        $overrideReason,
        $override
    );
    if ($override !== null && !$overrideBegun) {
        adminOverrideBegin('approval_requests', $approvalId, $override, (string)$overrideReason);
        $overrideBegun = true;
    }

    if ($workflowStatus !== 'Pending') {
        switch ($row['workflow_key']) {
            case 'purchase_request':
                applyPurchaseRequestDecision($pdo, $entityId, $workflowStatus, currentUserId());
                break;
            case 'fund_request':
                $update = $pdo->prepare(
                    "UPDATE fund_requests SET status = ? WHERE fund_request_id = ? AND status = 'Pending Approval'"
                );
                $update->execute([$workflowStatus, $entityId]);
                if ($update->rowCount() !== 1) {
                    throw new DomainException('The fund request is no longer pending.');
                }
                if ($workflowStatus === 'Approved') {
                    // Next: Finance records the disbursement.
                    notifyFundRequestAwaitingDisbursement($pdo, $entityId);
                }
                break;
            case 'payment_voucher':
                $update = $pdo->prepare(
                    "UPDATE payment_vouchers
                     SET status = ?, approved_by = IF(? = 'Approved', ?, approved_by),
                         approved_at = IF(? = 'Approved', NOW(), approved_at)
                     WHERE voucher_id = ? AND status = 'Pending Approval'"
                );
                $update->execute([$workflowStatus, $workflowStatus, currentUserId(), $workflowStatus, $entityId]);
                if ($update->rowCount() !== 1) {
                    throw new DomainException('The payment voucher is no longer pending.');
                }
                if ($workflowStatus === 'Approved') {
                    // Next: Finance records the disbursement.
                    notifyVoucherAwaitingDisbursement($pdo, $entityId);
                }
                break;
            case 'payroll':
                $update = $pdo->prepare(
                    "UPDATE payroll_periods
                     SET status = IF(? = 'Approved', 'Approved', 'Draft'),
                         approved_by = IF(? = 'Approved', ?, approved_by),
                         approved_at = IF(? = 'Approved', NOW(), approved_at)
                     WHERE payroll_period_id = ? AND status = 'Pending Approval'"
                );
                $update->execute([$workflowStatus, $workflowStatus, currentUserId(), $workflowStatus, $entityId]);
                if ($update->rowCount() !== 1) {
                    throw new DomainException('The payroll period is no longer pending.');
                }
                break;
        }
        if ($row['workflow_key'] === 'fund_request') {
            // Names the request and trip, and links a dispatcher to the
            // trip (they cannot open the Finance page).
            notifyFundRequestDecision($pdo, $entityId, $workflowStatus);
        } else {
            createNotification(
                $pdo,
                (int)$row['requested_by'],
                $label . ' ' . strtolower($workflowStatus),
                $label . ' #' . $entityId . ' was ' . strtolower($workflowStatus) . '.',
                'approval',
                $notifyEntity ?? 'approval_request',
                $notifyEntity !== null ? $entityId : $approvalId
            );
        }
    }

    auditLog('APPROVAL_DECISION', 'approval_requests', $approvalId, ['status' => 'Pending'], [
        'status'       => $workflowStatus,
        'decision'     => $decision,
        'workflow_key' => $row['workflow_key'],
        'entity_type'  => $row['entity_type'],
        'entity_id'    => $entityId,
    ]);
    if ($overrideBegun) {
        auditAdminOverride([
            'action'     => 'approval_decision',
            'decision'   => $decision,
            'new_status' => $workflowStatus,
            'self_review' => $selfOverride,
        ]);
    }
    $pdo->commit();
    if ($overrideBegun) adminOverrideSucceeded();
} catch (AdminOverrideReasonRequired $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    adminOverrideRefused('approval_requests', $approvalId, $e->bypassed);
    jsonFail($e->getMessage(), 422);
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonFail($e->getMessage(), 409);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('approval_decision_handler: ' . $e->getMessage());
    if ($e instanceof PDOException && in_array((int)($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
        jsonFail('Another decision was being saved at the same time. Nothing was saved; please try again.', 409);
    }
    jsonFail('Could not record the approval decision.', 500);
}

jsonOk(['status' => $workflowStatus], $workflowStatus === 'Pending'
    ? 'Approval step recorded and forwarded to the next approver.'
    : 'Request ' . strtolower($workflowStatus) . '.');
