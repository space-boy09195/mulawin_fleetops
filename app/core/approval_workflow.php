<?php
// ============================================================
// includes/approval_workflow.php
// Target-schema approval engine:
//   approval_workflows        workflow_key -> entity_type
//   approval_workflow_steps   (T-A1) step template per workflow
//   approval_requests         one request per workflow + entity;
//                             uq_approval_pending (T-A1) allows at most one
//                             Pending request per workflow + entity
//   approval_steps            per-request copy of the template; status
//                             Pending / Approved / Rejected / Skipped
//   approval_history          Submitted / Approved / Rejected / Cancelled /
//                             Overridden
// The current step is the lowest-numbered Pending step (the target has no
// current_step column). Every function writes on the caller's connection and
// expects the caller to own the transaction.
// ============================================================

require_once __DIR__ . '/notifications.php';

/**
 * Raised before any approval write when an Admin would decide a step
 * assigned to another role without a valid override reason. $bypassed holds
 * only documented audit context keys.
 */
class AdminOverrideReasonRequired extends DomainException {
    public array $bypassed;

    public function __construct(array $bypassed) {
        parent::__construct('An Admin override reason (1-500 characters) is required to decide a step assigned to another role.');
        $this->bypassed = $bypassed;
    }
}

/**
 * Raised when a second Pending request for the same workflow and entity is
 * attempted (code check, or uq_approval_pending duplicate-key error 1062).
 */
class ApprovalAlreadyPending extends DomainException {
    public function __construct() {
        parent::__construct('An approval request for this record is already pending.');
    }
}

/**
 * Active workflow row for a workflow key, or null.
 */
function approvalWorkflowByKey(PDO $pdo, string $workflowKey): ?array {
    $stmt = $pdo->prepare(
        'SELECT workflow_id, workflow_key, workflow_name, entity_type
         FROM approval_workflows
         WHERE workflow_key = ? AND is_active = 1'
    );
    $stmt->execute([$workflowKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function roleHasApprovalReview(PDO $pdo, int $roleId): bool {
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM role_permissions rp
         JOIN permissions p ON p.permission_id = rp.permission_id
         WHERE rp.role_id = ? AND p.permission_key = 'approvals.review'
         LIMIT 1"
    );
    $stmt->execute([$roleId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Active users of a role that holds approvals.review (the role is checked by
 * the callers before this runs).
 */
function approvalReviewerUserIds(PDO $pdo, int $roleId): array {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT u.user_id
         FROM users u
         JOIN role_permissions rp ON rp.role_id = u.role_id
         JOIN permissions p ON p.permission_id = rp.permission_id
         WHERE u.is_active = 1
           AND u.role_id = ?
           AND p.permission_key = 'approvals.review'"
    );
    $stmt->execute([$roleId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Pending approval request for a workflow + entity, locked FOR UPDATE.
 */
function pendingApprovalRequestId(PDO $pdo, string $workflowKey, string $entityType, int $entityId): ?int {
    $stmt = $pdo->prepare(
        "SELECT ar.approval_request_id
         FROM approval_requests ar
         JOIN approval_workflows w ON w.workflow_id = ar.workflow_id
         WHERE w.workflow_key = ? AND ar.entity_type = ? AND ar.entity_id = ?
           AND ar.status = 'Pending'
         ORDER BY ar.approval_request_id DESC
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([$workflowKey, $entityType, $entityId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

/**
 * Workflows whose request goes to Admin when the requester belongs to the
 * role configured for the first approval step (owner decision E2): a Finance
 * user's own fund request is reviewed by Admin, not by Finance. Other
 * workflows keep their configured approver roles.
 */
const APPROVAL_SELF_ROLE_TO_ADMIN_WORKFLOWS = ['fund_request'];

/**
 * Role of the requesting user account, or null when the account is missing.
 */
function approvalRequesterRoleId(PDO $pdo, int $userId): ?int {
    $stmt = $pdo->prepare('SELECT role_id FROM users WHERE user_id = ?');
    $stmt->execute([$userId]);
    $roleId = $stmt->fetchColumn();
    return $roleId === false || $roleId === null ? null : (int)$roleId;
}

/**
 * The active Admin role (roles.system_key = 'admin'), or null.
 */
function approvalAdminRoleId(PDO $pdo): ?int {
    $stmt = $pdo->prepare("SELECT role_id FROM roles WHERE system_key = 'admin' AND is_active = 1");
    $stmt->execute();
    $roleId = $stmt->fetchColumn();
    return $roleId === false ? null : (int)$roleId;
}

/**
 * Per-request copy of the workflow's step template. For a workflow listed in
 * APPROVAL_SELF_ROLE_TO_ADMIN_WORKFLOWS, when the requester's role is the
 * first step's approver role, that request's first step is assigned to the
 * Admin role instead; the template (approval_workflow_steps) is never
 * changed. Returns [steps, routing], routing being null when nothing was
 * rerouted. Throws when the requester or an active Admin role is missing, so
 * no request is created that nobody may review.
 */
function approvalStepsForRequester(PDO $pdo, string $workflowKey, array $configuredSteps, int $requestedBy): array {
    if (!$configuredSteps || !in_array($workflowKey, APPROVAL_SELF_ROLE_TO_ADMIN_WORKFLOWS, true)) {
        return [$configuredSteps, null];
    }
    $requesterRoleId = approvalRequesterRoleId($pdo, $requestedBy);
    if ($requesterRoleId === null) {
        throw new RuntimeException('The requesting user account was not found.');
    }
    $configuredRoleId = (int)$configuredSteps[0]['approver_role_id'];
    if ($requesterRoleId !== $configuredRoleId) {
        return [$configuredSteps, null];
    }
    $adminRoleId = approvalAdminRoleId($pdo);
    if ($adminRoleId === null) {
        throw new RuntimeException('No active Admin role is available to review this request.');
    }
    if ($adminRoleId === $configuredRoleId) {
        // Already an Admin step (an Admin's own request): the decision
        // handler's audited self-review rule applies.
        return [$configuredSteps, null];
    }
    $configuredSteps[0]['approver_role_id'] = $adminRoleId;
    return [$configuredSteps, [
        'reason'             => 'requester_in_approver_role',
        'step_number'        => (int)$configuredSteps[0]['step_number'],
        'configured_role_id' => $configuredRoleId,
        'routed_role_id'     => $adminRoleId,
    ]];
}

/**
 * Names the record behind an approval for the reviewer's notification, e.g.
 * "Trip Allowance FR-20261027015113-105 for TRP-2026-0016 (₱1,000.00)", or
 * null when the type has no extra details (the caller keeps "#id").
 */
function approvalEntityReference(PDO $pdo, string $entityType, int $entityId): ?string {
    if ($entityType === 'fund_request') {
        $stmt = $pdo->prepare(
            "SELECT CONCAT(fr.request_type, ' ', fr.request_number,
                           IF(t.trip_number IS NULL, '', CONCAT(' for ', t.trip_number)),
                           ' (₱', FORMAT(fr.amount, 2), ')')
             FROM fund_requests fr LEFT JOIN trips t ON t.trip_id = fr.trip_id
             WHERE fr.fund_request_id = ?"
        );
    } elseif ($entityType === 'dispatch_request') {
        $stmt = $pdo->prepare(
            "SELECT CONCAT('Dispatch request #', dr.dispatch_request_id, ' for ', t.trip_number)
             FROM dispatch_requests dr JOIN trips t ON t.trip_id = dr.trip_id
             WHERE dr.dispatch_request_id = ?"
        );
    } elseif ($entityType === 'payment_voucher') {
        $stmt = $pdo->prepare(
            "SELECT CONCAT('Payment voucher ', voucher_number, ' for ', payee, ' (₱', FORMAT(amount, 2), ')')
             FROM payment_vouchers WHERE voucher_id = ?"
        );
    } else {
        return null;
    }
    $stmt->execute([$entityId]);
    $reference = $stmt->fetchColumn();
    return $reference === false || $reference === null ? null : (string)$reference;
}

/**
 * Create a Pending approval request for an entity from the workflow's step
 * template. $entityType must equal the workflow's configured entity_type.
 * $routing is set (by reference) when the first step was routed to Admin by
 * approvalStepsForRequester(), with the users notified; the caller audits it.
 */
function createApprovalRequest(
    PDO $pdo,
    string $workflowKey,
    string $entityType,
    int $entityId,
    int $requestedBy,
    ?string $remarks = null,
    ?array &$routing = null
): int {
    $routing = null;
    $workflow = approvalWorkflowByKey($pdo, $workflowKey);
    if ($workflow === null) {
        throw new RuntimeException('No active approval workflow is configured for ' . $workflowKey . '.');
    }
    if ($workflow['entity_type'] !== $entityType) {
        // A mismatch would let two workflows race on one entity; refuse it.
        throw new LogicException('Approval workflow ' . $workflowKey . ' is for ' . $workflow['entity_type'] . ', not ' . $entityType . '.');
    }
    $workflowId = (int)$workflow['workflow_id'];

    $stepsQuery = $pdo->prepare(
        'SELECT step_number, approver_role_id
         FROM approval_workflow_steps
         WHERE workflow_id = ?
         ORDER BY step_number'
    );
    $stepsQuery->execute([$workflowId]);
    $configuredSteps = $stepsQuery->fetchAll(PDO::FETCH_ASSOC);
    if (!$configuredSteps) {
        throw new RuntimeException('No approval roles are configured for this request type.');
    }
    [$requestSteps, $routedFrom] = approvalStepsForRequester($pdo, $workflowKey, $configuredSteps, $requestedBy);
    foreach ($requestSteps as $step) {
        if (!roleHasApprovalReview($pdo, (int)$step['approver_role_id'])) {
            throw new RuntimeException('An approval-chain role is missing the approvals.review permission.');
        }
    }

    // Code-level check first (clear message); uq_approval_pending is the
    // concurrency guarantee.
    if (pendingApprovalRequestId($pdo, $workflowKey, $entityType, $entityId) !== null) {
        throw new ApprovalAlreadyPending();
    }
    try {
        $pdo->prepare(
            "INSERT INTO approval_requests
                (workflow_id, entity_type, entity_id, requested_by, status)
             VALUES (?, ?, ?, ?, 'Pending')"
        )->execute([$workflowId, $entityType, $entityId, $requestedBy]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            throw new ApprovalAlreadyPending();
        }
        throw $e;
    }
    $approvalId = (int)$pdo->lastInsertId();

    $insertStep = $pdo->prepare(
        "INSERT INTO approval_steps (approval_request_id, step_number, approver_role_id, status)
         VALUES (?, ?, ?, 'Pending')"
    );
    foreach ($requestSteps as $step) {
        $insertStep->execute([
            $approvalId,
            (int)$step['step_number'],
            (int)$step['approver_role_id'],
        ]);
    }

    $pdo->prepare(
        "INSERT INTO approval_history (approval_request_id, action, acted_by, remarks)
         VALUES (?, 'Submitted', ?, ?)"
    )->execute([$approvalId, $requestedBy, $remarks]);

    $firstRoleId = (int)$requestSteps[0]['approver_role_id'];
    $notifiedUserIds = [];
    $reference = approvalEntityReference($pdo, $entityType, $entityId)
        ?? $workflow['workflow_name'] . ' #' . $entityId;
    foreach (approvalReviewerUserIds($pdo, $firstRoleId) as $userId) {
        createNotification(
            $pdo,
            $userId,
            'Approval required',
            $reference . ' is awaiting your review on Requests & Approvals.'
                . ($routedFrom !== null ? ' Routed to Admin because the requester belongs to the approver role.' : ''),
            'approval',
            'approval_request',
            $approvalId
        );
        $notifiedUserIds[] = $userId;
    }
    if ($routedFrom !== null) {
        $routing = $routedFrom + ['notified_user_ids' => $notifiedUserIds];
    }

    return $approvalId;
}

/**
 * Lowest-numbered Pending step of a request (the current step), locked.
 */
function currentApprovalStep(PDO $pdo, int $approvalId): ?array {
    $stmt = $pdo->prepare(
        "SELECT approval_step_id, step_number, approver_role_id
         FROM approval_steps
         WHERE approval_request_id = ? AND status = 'Pending'
         ORDER BY step_number
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([$approvalId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Record an Approved/Rejected decision on the current step. Returns the
 * request's resulting status: 'Pending' (more steps), 'Approved' or
 * 'Rejected'. The request row is locked first, so concurrent decisions on
 * one request are serialized and the second sees it is no longer pending.
 */
function recordApprovalDecision(
    PDO $pdo,
    int $approvalId,
    string $decision,
    int $actorUserId,
    int $actorRoleId,
    ?string $comment = null,
    bool $actorIsAdmin = false,
    ?string $overrideReason = null,
    ?array &$override = null
): string {
    $override = null;
    if (!in_array($decision, ['Approved', 'Rejected'], true)) {
        throw new InvalidArgumentException('Approval decision must be Approved or Rejected.');
    }
    // Admin holds every permission by the central system-key rule
    // ($actorIsAdmin comes from isAdmin()). Every other role still needs an
    // explicit approvals.review grant.
    if (!$actorIsAdmin && !roleHasApprovalReview($pdo, $actorRoleId)) {
        throw new DomainException('Your role cannot review approval requests.');
    }

    $requestQuery = $pdo->prepare(
        'SELECT approval_request_id, status
         FROM approval_requests
         WHERE approval_request_id = ?
         FOR UPDATE'
    );
    $requestQuery->execute([$approvalId]);
    $request = $requestQuery->fetch(PDO::FETCH_ASSOC);
    if (!$request || $request['status'] !== 'Pending') {
        throw new DomainException('Approval request is not pending.');
    }

    $step = currentApprovalStep($pdo, $approvalId);
    $ownStep = $step && (int)$step['approver_role_id'] === $actorRoleId;
    if (!$step || (!$ownStep && !$actorIsAdmin)) {
        throw new DomainException('This approval is not assigned to your role or is no longer pending.');
    }
    if (!$ownStep) {
        // Admin is deciding a step assigned to another role: an override.
        // The reason is checked here, before any approval write.
        $bypassed = [
            'restriction'      => 'approval_step_role',
            'approval_step_id' => (int)$step['approval_step_id'],
            'step_order'       => (int)$step['step_number'],
            'required_role_id' => (int)$step['approver_role_id'],
        ];
        if ($overrideReason === null) {
            throw new AdminOverrideReasonRequired($bypassed);
        }
        $override = $bypassed;
    }

    $updated = $pdo->prepare(
        "UPDATE approval_steps
         SET status = ?, acted_by = ?, remarks = ?, acted_at = NOW()
         WHERE approval_step_id = ? AND status = 'Pending'"
    );
    $updated->execute([$decision, $actorUserId, $comment, (int)$step['approval_step_id']]);
    if ($updated->rowCount() !== 1) {
        throw new DomainException('This approval step was already decided.');
    }

    $newStatus = $decision;
    $nextStep = null;

    if ($decision === 'Rejected') {
        // The target step ENUM has no 'Cancelled'; later steps are Skipped.
        $pdo->prepare(
            "UPDATE approval_steps SET status = 'Skipped'
             WHERE approval_request_id = ? AND status = 'Pending'"
        )->execute([$approvalId]);
    } else {
        $nextStep = currentApprovalStep($pdo, $approvalId);
        if ($nextStep) {
            $newStatus = 'Pending';
        }
    }

    if ($newStatus !== 'Pending') {
        $pdo->prepare(
            'UPDATE approval_requests SET status = ?, completed_at = NOW() WHERE approval_request_id = ?'
        )->execute([$newStatus, $approvalId]);
    }

    $history = $pdo->prepare(
        'INSERT INTO approval_history (approval_request_id, approval_step_id, action, acted_by, remarks)
         VALUES (?, ?, ?, ?, ?)'
    );
    if ($override !== null) {
        // The override reason is kept only in the audit log (ADMIN_OVERRIDE).
        $history->execute([
            $approvalId,
            (int)$step['approval_step_id'],
            'Overridden',
            $actorUserId,
            'Admin decided a step assigned to another role.',
        ]);
    }
    $history->execute([
        $approvalId,
        (int)$step['approval_step_id'],
        $decision,
        $actorUserId,
        $comment,
    ]);

    // This step is decided: the other reviewers' "Approval required"
    // notices are no longer a task (kept as read history).
    resolveNotifications($pdo, 'approval', $approvalId, ['Approval required']);

    if ($nextStep) {
        $entity = $pdo->prepare('SELECT entity_type, entity_id FROM approval_requests WHERE approval_request_id = ?');
        $entity->execute([$approvalId]);
        $entityRow = $entity->fetch(PDO::FETCH_ASSOC);
        $reference = $entityRow ? approvalEntityReference($pdo, (string)$entityRow['entity_type'], (int)$entityRow['entity_id']) : null;
        foreach (approvalReviewerUserIds($pdo, (int)$nextStep['approver_role_id']) as $userId) {
            createNotification(
                $pdo,
                $userId,
                'Approval required',
                ($reference ?? 'Approval #' . $approvalId) . ' is awaiting your review on Requests & Approvals.',
                'approval',
                'approval_request',
                $approvalId
            );
        }
    }

    return $newStatus;
}

/**
 * Cancel a Pending request (for example when its entity is withdrawn).
 * Remaining Pending steps become Skipped. Returns false when nothing was
 * pending.
 */
function cancelApprovalRequest(PDO $pdo, int $approvalId, int $actorUserId, ?string $remarks = null): bool {
    $cancel = $pdo->prepare(
        "UPDATE approval_requests SET status = 'Cancelled', completed_at = NOW()
         WHERE approval_request_id = ? AND status = 'Pending'"
    );
    $cancel->execute([$approvalId]);
    if ($cancel->rowCount() !== 1) {
        return false;
    }
    $pdo->prepare(
        "UPDATE approval_steps SET status = 'Skipped'
         WHERE approval_request_id = ? AND status = 'Pending'"
    )->execute([$approvalId]);
    $pdo->prepare(
        "INSERT INTO approval_history (approval_request_id, action, acted_by, remarks)
         VALUES (?, 'Cancelled', ?, ?)"
    )->execute([$approvalId, $actorUserId, $remarks]);
    resolveNotifications($pdo, 'approval', $approvalId, ['Approval required']);
    return true;
}
