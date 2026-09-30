<?php

require_once __DIR__ . '/notifications.php';

function createApprovalRequest(
    PDO $pdo,
    string $requestType,
    string $entityType,
    int $entityId,
    int $requestedBy
): int {
    $stepsQuery = $pdo->prepare(
        'SELECT step_order, approver_role_id
         FROM approval_role_steps
         WHERE request_type = ?
         ORDER BY step_order'
    );
    $stepsQuery->execute([$requestType]);
    $configuredSteps = $stepsQuery->fetchAll(PDO::FETCH_ASSOC);
    if (!$configuredSteps) {
        throw new RuntimeException('No approval roles are configured for this request type.');
    }
    $reviewPermission = $pdo->prepare(
        'SELECT 1
         FROM role_permissions rp
         JOIN permissions p ON p.permission_id = rp.permission_id
         WHERE rp.role_id = ? AND p.permission_key = ?
         LIMIT 1'
    );
    foreach ($configuredSteps as $step) {
        $reviewPermission->execute([(int)$step['approver_role_id'], 'approvals.review']);
        if (!$reviewPermission->fetchColumn()) {
            throw new RuntimeException('An approval-chain role is missing the approvals.review permission.');
        }
    }

    $pdo->prepare(
        "INSERT INTO approval_requests
            (request_type, entity_type, entity_id, requested_by, status, current_step)
         VALUES (?, ?, ?, ?, 'Pending', ?)"
    )->execute([$requestType, $entityType, $entityId, $requestedBy, (int)$configuredSteps[0]['step_order']]);
    $approvalId = (int)$pdo->lastInsertId();

    $insertStep = $pdo->prepare(
        'INSERT INTO approval_steps (approval_id, step_order, approver_role_id)
         VALUES (?, ?, ?)'
    );
    foreach ($configuredSteps as $step) {
        $insertStep->execute([
            $approvalId,
            (int)$step['step_order'],
            (int)$step['approver_role_id'],
        ]);
    }

    $pdo->prepare(
        "INSERT INTO approval_history
            (approval_id, actor_user_id, action, new_status)
         VALUES (?, ?, 'submitted', 'Pending')"
    )->execute([$approvalId, $requestedBy]);

    $firstRoleId = (int)$configuredSteps[0]['approver_role_id'];
    $notify = $pdo->prepare(
        'SELECT DISTINCT u.user_id
         FROM users u
         JOIN role_permissions rp ON rp.role_id = u.role_id
         JOIN permissions p ON p.permission_id = rp.permission_id
         WHERE u.is_active = 1
           AND u.role_id = ?
           AND p.permission_key = ?'
    );
    $notify->execute([$firstRoleId, 'approvals.review']);
    foreach ($notify->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        createNotification(
            $pdo,
            (int)$userId,
            'Approval required',
            ucfirst(str_replace('_', ' ', $requestType)) . ' #' . $entityId . ' is awaiting review.'
        );
    }

    return $approvalId;
}

function recordApprovalDecision(
    PDO $pdo,
    int $approvalId,
    string $decision,
    int $actorUserId,
    int $actorRoleId,
    ?string $comment = null
): string {
    if (!in_array($decision, ['Approved', 'Rejected'], true)) {
        throw new InvalidArgumentException('Approval decision must be Approved or Rejected.');
    }
    $permission = $pdo->prepare(
        'SELECT 1
         FROM role_permissions rp
         JOIN permissions p ON p.permission_id = rp.permission_id
         WHERE rp.role_id = ? AND p.permission_key = ?
         LIMIT 1'
    );
    $permission->execute([$actorRoleId, 'approvals.review']);
    if (!$permission->fetchColumn()) {
        throw new DomainException('Your role cannot review approval requests.');
    }

    $requestQuery = $pdo->prepare(
        "SELECT approval_id, status, current_step
         FROM approval_requests
         WHERE approval_id = ?
         FOR UPDATE"
    );
    $requestQuery->execute([$approvalId]);
    $request = $requestQuery->fetch(PDO::FETCH_ASSOC);
    if (!$request || $request['status'] !== 'Pending') {
        throw new DomainException('Approval request is not pending.');
    }

    $stepQuery = $pdo->prepare(
        "SELECT approval_step_id, step_order, approver_role_id
         FROM approval_steps
         WHERE approval_id = ? AND step_order = ? AND status = 'Pending'
         FOR UPDATE"
    );
    $stepQuery->execute([$approvalId, (int)$request['current_step']]);
    $step = $stepQuery->fetch(PDO::FETCH_ASSOC);
    if (!$step || ((int)$step['approver_role_id'] !== $actorRoleId && $actorRoleId !== ROLE_ADMIN)) {
        throw new DomainException('This approval is not assigned to your role or is no longer pending.');
    }

    $pdo->prepare(
        'UPDATE approval_steps
         SET status = ?, decided_by = ?, decision_comment = ?, decided_at = NOW()
         WHERE approval_step_id = ?'
    )->execute([$decision, $actorUserId, $comment, (int)$step['approval_step_id']]);

    $oldStatus = 'Pending';
    $newStatus = $decision;
    $nextStep = null;

    if ($decision === 'Rejected') {
        $pdo->prepare(
            "UPDATE approval_steps SET status = 'Cancelled'
             WHERE approval_id = ? AND status = 'Pending'"
        )->execute([$approvalId]);
    } else {
        $nextQuery = $pdo->prepare(
            "SELECT step_order, approver_role_id
             FROM approval_steps
             WHERE approval_id = ? AND status = 'Pending'
             ORDER BY step_order
             LIMIT 1"
        );
        $nextQuery->execute([$approvalId]);
        $nextStep = $nextQuery->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($nextStep) {
            $newStatus = 'Pending';
            $pdo->prepare(
                'UPDATE approval_requests SET current_step = ? WHERE approval_id = ?'
            )->execute([(int)$nextStep['step_order'], $approvalId]);
        }
    }

    if ($newStatus !== 'Pending') {
        $pdo->prepare(
            'UPDATE approval_requests SET status = ?, completed_at = NOW() WHERE approval_id = ?'
        )->execute([$newStatus, $approvalId]);
    }

    $pdo->prepare(
        'INSERT INTO approval_history
            (approval_id, approval_step_id, actor_user_id, action, comment, old_status, new_status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $approvalId,
        (int)$step['approval_step_id'],
        $actorUserId,
        strtolower($decision),
        $comment,
        $oldStatus,
        $newStatus,
    ]);

    if ($nextStep) {
        $notify = $pdo->prepare(
            'SELECT DISTINCT u.user_id
             FROM users u
             JOIN role_permissions rp ON rp.role_id = u.role_id
             JOIN permissions p ON p.permission_id = rp.permission_id
             WHERE u.is_active = 1
               AND u.role_id = ?
               AND p.permission_key = ?'
        );
        $notify->execute([(int)$nextStep['approver_role_id'], 'approvals.review']);
        foreach ($notify->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            createNotification(
                $pdo,
                (int)$userId,
                'Approval required',
                'Approval #' . $approvalId . ' is awaiting your review.'
            );
        }
    }

    return $newStatus;
}
