<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/app_settings.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';

header('Content-Type: application/json');

requireLogin();
requirePostMethod();
enforceCsrf();

$pdo = getDBConnection();
$action = $_POST['action'] ?? '';

if ($action === 'create_role') {
    requirePermission('roles.manage');
    $roleName = requiredString('role_name', 'Role name', 50);
    if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} &()_-]*$/u', $roleName)) {
        jsonFail('Role name contains unsupported characters.');
    }

    try {
        $pdo->prepare('INSERT INTO roles (role_name) VALUES (?)')->execute([$roleName]);
        $roleId = (int)$pdo->lastInsertId();
        auditLog('CREATE_ROLE', 'roles', $roleId, null, ['role_name' => $roleName]);
        jsonOk(['role_id' => $roleId], 'Role created. Assign its permissions before creating accounts.');
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            jsonFail('A role with that name already exists.', 409);
        }
        error_log('permissions_handler/create_role: ' . $e->getMessage());
        jsonFail('Could not create the role.', 500);
    }
}

if ($action === 'save_approval_chains') {
    requirePermission('approvals.manage');
    $submitted = $_POST['approver_roles'] ?? [];
    if (!is_array($submitted)) {
        jsonFail('Approval role selection is invalid.');
    }

    $types = $pdo->query(
        'SELECT request_type FROM approval_request_types WHERE is_active = 1'
    )->fetchAll(PDO::FETCH_COLUMN);
    $roleRows = $pdo->query('SELECT role_id FROM roles')->fetchAll(PDO::FETCH_COLUMN);
    $validRoles = array_map('intval', $roleRows);
    $newChains = [];
    foreach ($types as $type) {
        $selected = $submitted[$type] ?? [];
        if (!is_array($selected)) {
            jsonFail('Approval role selection is invalid.');
        }
        $selectedIds = [];
        foreach ($selected as $order => $value) {
            if (!is_numeric($order) || (int)$order < 1 || (int)$order > 6) {
                jsonFail('Approval step order is invalid.');
            }
            if ($value === '') {
                continue;
            }
            $roleId = filter_var($value, FILTER_VALIDATE_INT);
            if ($roleId === false || !in_array((int)$roleId, $validRoles, true)) {
                jsonFail('One or more approver roles are invalid.');
            }
            $reviewPermission = $pdo->prepare(
                "SELECT 1
                 FROM role_permissions rp
                 JOIN permissions p ON p.permission_id = rp.permission_id
                 WHERE rp.role_id = ? AND p.permission_key = 'approvals.review'
                 LIMIT 1"
            );
            $reviewPermission->execute([(int)$roleId]);
            if (!$reviewPermission->fetchColumn()) {
                jsonFail('Every approver role must have the approvals.review permission.');
            }
            if (in_array((int)$roleId, $selectedIds, true)) {
                jsonFail('A role can only appear once in an approval chain.');
            }
            $selectedIds[(int)$order] = (int)$roleId;
        }
        if (!$selectedIds) {
            jsonFail('Choose at least one approver role for each request type.');
        }
        $newChains[$type] = $selectedIds;
    }

    $oldChains = $pdo->query(
        'SELECT request_type, step_order, approver_role_id
         FROM approval_role_steps
         ORDER BY request_type, step_order'
    )->fetchAll(PDO::FETCH_ASSOC);
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM approval_role_steps');
        $insertStep = $pdo->prepare(
            'INSERT INTO approval_role_steps (request_type, step_order, approver_role_id)
             VALUES (?, ?, ?)'
        );
        foreach ($newChains as $type => $roleIds) {
            foreach ($roleIds as $stepOrder => $roleId) {
                $insertStep->execute([$type, $stepOrder, $roleId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('permissions_handler/save_approval_chains: ' . $e->getMessage());
        jsonFail('Could not save approval chains.', 500);
    }
    auditLog('UPDATE_APPROVAL_CHAINS', 'approval_role_steps', null, $oldChains, $newChains);
    jsonOk([], 'Approval chains saved.');
}

requirePermission('permissions.manage');

if ($action === 'save_role_permissions') {
    $roleId = requiredInt('role_id', 'Role', 1);
    $permissionIds = $_POST['permission_ids'] ?? [];
    if (!is_array($permissionIds)) {
        jsonFail('Permission selection is invalid.');
    }
    $permissionIds = array_values(array_unique(array_map(
        static function ($value): int {
            if (!is_string($value) && !is_int($value)) {
                jsonFail('Permission selection is invalid.');
            }
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                jsonFail('Permission selection is invalid.');
            }
            return (int)$id;
        },
        $permissionIds
    )));

    findOrFail($pdo, 'roles', 'role_id', $roleId, 'Role not found.');
    if ($permissionIds) {
        $placeholders = implode(',', array_fill(0, count($permissionIds), '?'));
        $validQuery = $pdo->prepare("SELECT COUNT(*) FROM permissions WHERE permission_id IN ($placeholders)");
        $validQuery->execute($permissionIds);
        if ((int)$validQuery->fetchColumn() !== count($permissionIds)) {
            jsonFail('One or more selected permissions are invalid.');
        }
    }

    try {
        $pdo->beginTransaction();
        $managerPermissionQuery = $pdo->prepare(
            "SELECT permission_id
             FROM permissions
             WHERE permission_key = 'permissions.manage'
             FOR UPDATE"
        );
        $managerPermissionQuery->execute();
        $managerPermissionId = (int)$managerPermissionQuery->fetchColumn();

        $roleLock = $pdo->prepare('SELECT role_id FROM roles WHERE role_id = ? FOR UPDATE');
        $roleLock->execute([$roleId]);
        if (!$roleLock->fetchColumn()) {
            $pdo->rollBack();
            jsonFail('Role not found.', 404);
        }

        $oldQuery = $pdo->prepare(
            'SELECT permission_id FROM role_permissions WHERE role_id = ? ORDER BY permission_id FOR UPDATE'
        );
        $oldQuery->execute([$roleId]);
        $oldIds = array_map('intval', $oldQuery->fetchAll(PDO::FETCH_COLUMN));
        sort($permissionIds);
        $addedIds = array_values(array_diff($permissionIds, $oldIds));
        $removedIds = array_values(array_diff($oldIds, $permissionIds));

        if ($managerPermissionId > 0
            && in_array($managerPermissionId, $oldIds, true)
            && in_array($managerPermissionId, $removedIds, true)) {
            $remainingManagers = $pdo->prepare(
                'SELECT COUNT(DISTINCT u.user_id)
                 FROM users u
                 JOIN role_permissions rp ON rp.role_id = u.role_id
                 WHERE u.is_active = 1 AND rp.permission_id = ? AND u.role_id <> ?'
            );
            $remainingManagers->execute([$managerPermissionId, $roleId]);
            if ((int)$remainingManagers->fetchColumn() === 0) {
                $pdo->rollBack();
                jsonFail('At least one active user in another role must retain permission-management access.', 409);
            }
        }

        if ($removedIds) {
            $placeholders = implode(',', array_fill(0, count($removedIds), '?'));
            $remove = $pdo->prepare(
                "DELETE FROM role_permissions WHERE role_id = ? AND permission_id IN ($placeholders)"
            );
            $remove->execute(array_merge([$roleId], $removedIds));
        }
        if ($addedIds) {
            $insert = $pdo->prepare(
                'INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)'
            );
            foreach ($addedIds as $permissionId) {
                $insert->execute([$roleId, $permissionId]);
            }
        }

        if ($addedIds || $removedIds) {
            auditLog('UPDATE_ROLE_PERMISSIONS', 'roles', $roleId, $oldIds, $permissionIds);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('permissions_handler/save_role_permissions: ' . $e->getMessage());
        jsonFail('Could not save role permissions.', 500);
    }

    jsonOk([
        'added_count' => count($addedIds),
        'removed_count' => count($removedIds),
    ], sprintf(
        'Role permissions saved: %d added, %d removed.',
        count($addedIds),
        count($removedIds)
    ));
}

if ($action === 'save_email_domain') {
    $domain = strtolower(trim(requiredString('domain', 'Email domain', 253)));
    if (str_starts_with($domain, '@') || !filter_var('user@' . $domain, FILTER_VALIDATE_EMAIL)) {
        jsonFail('Enter a valid domain, such as rpm.com.');
    }

    $oldDomain = getAppSetting($pdo, 'company_email_domain', COMPANY_EMAIL_DOMAIN);
    setAppSetting($pdo, 'company_email_domain', $domain, currentUserId());
    auditLog(
        'UPDATE_SETTING',
        'app_settings',
        null,
        ['company_email_domain' => $oldDomain],
        ['company_email_domain' => $domain]
    );
    jsonOk([], 'Company email domain updated.');
}

if ($action === 'save_reminder_thresholds') {
    $rawThresholds = requiredString('thresholds', 'Reminder thresholds', 100);
    $values = array_map('trim', explode(',', $rawThresholds));
    if (!$values || count($values) > 12) {
        jsonFail('Enter between 1 and 12 comma-separated reminder thresholds.');
    }
    $thresholds = [];
    foreach ($values as $value) {
        if (!preg_match('/^\d{1,3}$/', $value) || (int)$value < 1 || (int)$value > 365) {
            jsonFail('Each reminder threshold must be a whole number from 1 to 365 days.');
        }
        $thresholds[] = (int)$value;
    }
    $thresholds = array_values(array_unique($thresholds));
    rsort($thresholds, SORT_NUMERIC);
    $formatted = implode(',', $thresholds);
    $oldThresholds = getAppSetting($pdo, 'reminder_thresholds_days', '60,30,15,7');
    setAppSetting($pdo, 'reminder_thresholds_days', $formatted, currentUserId());
    auditLog(
        'UPDATE_SETTING',
        'app_settings',
        null,
        ['reminder_thresholds_days' => $oldThresholds],
        ['reminder_thresholds_days' => $formatted]
    );
    jsonOk([], 'Reminder thresholds updated.');
}

if ($action === 'save_crew_label') {
    $label = requiredString('label', 'Crew label', 50);
    if (!preg_match('/^[\p{L}\p{N} &()_-]+$/u', $label)) {
        jsonFail('Crew label contains unsupported characters.');
    }
    $oldLabel = getAppSetting($pdo, 'crew_module_label', 'Drivers & Helpers');
    setAppSetting($pdo, 'crew_module_label', $label, currentUserId());
    auditLog(
        'UPDATE_SETTING',
        'app_settings',
        null,
        ['crew_module_label' => $oldLabel],
        ['crew_module_label' => $label]
    );
    jsonOk([], 'Crew label updated.');
}

jsonFail('Unknown action.');
