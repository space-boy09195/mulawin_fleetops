<?php
// ============================================================
// includes/notifications.php
// Target notifications table: user_id, notification_type, title, message,
// entity_type, entity_id, is_read, read_at. There is no link column: a
// notification points at a record (entity_type + entity_id) and the layout
// builds the link from a fixed allowlist (notificationLinkFor()).
// ============================================================

require_once __DIR__ . '/../../config/database.php';

const NOTIFICATION_TYPE_MAX_LENGTH  = 80;  // notification_type VARCHAR(80)
const NOTIFICATION_TITLE_MAX_LENGTH = 180; // title VARCHAR(180)
const NOTIFICATION_ENTITY_MAX_LENGTH = 60; // entity_type VARCHAR(60)

// entity_type => page that shows the record. Only these entity types get a
// clickable link; anything else is shown without one.
const NOTIFICATION_ENTITY_LINKS = [
    'approval_request'  => '/pages/requests.php',
    'dispatch_request'  => '/pages/dispatch_inbox.php',
    'trip'              => '/pages/trip_monitor.php',
    'trip_incident'     => '/pages/incidents.php',
    'trip_completion'   => '/pages/trip_monitor.php',
    'truck'             => '/pages/fleet_status.php',
    'truck_document'    => '/pages/fleet_status.php',
    'maintenance'       => '/pages/maintenance.php',
    'employee'          => '/pages/users.php',
    'document'          => '/pages/documents.php',
    'billing'           => '/pages/billing.php',
    'fund_request'      => '/pages/finance.php',
    'payment_voucher'   => '/pages/finance.php',
    'purchase_request'  => '/pages/purchasing.php',
    'route'             => '/pages/requests.php',
    // Workflow handoffs (includes/workflow_notifications.php). With an
    // entity_id they open the record itself (NOTIFICATION_RECORD_LINKS);
    // these pages are the fallback when no single record is linked.
    'trip_workflow'          => '/pages/trip_monitor.php',
    'trip_pre_departure'     => '/pages/maintenance.php?view=pre_departure',
    'trip_return_inspection' => '/pages/maintenance.php?view=return',
    'dispatch_instruction'   => '/pages/dispatch_inbox.php',
];

// entity_type => link to the one record; %d is the entity_id. Each page
// checks access itself, as for the links above.
const NOTIFICATION_RECORD_LINKS = [
    'trip_workflow'          => '/pages/trip_workflow.php?trip_id=%d',
    'trip_pre_departure'     => '/pages/maintenance.php?view=pre_departure&trip_id=%d',
    'trip_return_inspection' => '/pages/maintenance.php?view=return&trip_id=%d&inspection_stage=Return#inspectionModal',
    'dispatch_instruction'   => '/pages/dispatch.php?instruction_id=%d',
];

function notificationEntityType(?string $entityType): ?string {
    if ($entityType === null || $entityType === '') {
        return null;
    }
    if (strlen($entityType) > NOTIFICATION_ENTITY_MAX_LENGTH) {
        throw new InvalidArgumentException('Notification entity type is too long.');
    }
    return $entityType;
}

function createNotification(
    PDO $pdo,
    int $userId,
    string $title,
    string $message,
    string $type = 'general',
    ?string $entityType = null,
    ?int $entityId = null
): void {
    if ($type === '' || strlen($type) > NOTIFICATION_TYPE_MAX_LENGTH) {
        throw new InvalidArgumentException('Notification type must be 1-' . NOTIFICATION_TYPE_MAX_LENGTH . ' characters.');
    }
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, notification_type, title, message, entity_type, entity_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $type,
        mb_substr($title, 0, NOTIFICATION_TITLE_MAX_LENGTH, 'UTF-8'),
        $message,
        notificationEntityType($entityType),
        $entityId,
    ]);
}

function notifyUsersWithPermission(
    PDO $pdo,
    string $permissionKey,
    string $title,
    string $message,
    string $type = 'general',
    ?string $entityType = null,
    ?int $entityId = null
): void {
    // Admin holds every permission (isAdmin() rule), so active Admin users
    // are included even without an explicit grant row.
    $stmt = $pdo->prepare(
        "SELECT DISTINCT u.user_id
         FROM users u
         JOIN roles r ON r.role_id = u.role_id AND r.is_active = 1
         LEFT JOIN role_permissions rp ON rp.role_id = u.role_id
         LEFT JOIN permissions p ON p.permission_id = rp.permission_id
         WHERE u.is_active = 1
           AND (p.permission_key = ? OR r.system_key = 'admin')"
    );
    $stmt->execute([$permissionKey]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        createNotification($pdo, (int)$userId, $title, $message, $type, $entityType, $entityId);
    }
}

/**
 * Link for a stored notification, or null. Built only from the fixed map
 * above; the stored values never become part of the path.
 */
function notificationLinkFor(?string $entityType, $entityId): ?string {
    if ($entityType === null || !isset(NOTIFICATION_ENTITY_LINKS[$entityType])) {
        return null;
    }
    if (isset(NOTIFICATION_RECORD_LINKS[$entityType]) && (int)$entityId > 0) {
        return APP_BASE . sprintf(NOTIFICATION_RECORD_LINKS[$entityType], (int)$entityId);
    }
    return APP_BASE . NOTIFICATION_ENTITY_LINKS[$entityType];
}

/**
 * A task's notifications stop being actionable once the task is done,
 * rejected or cancelled: every recipient's unread copy (same type, record
 * and title) is marked read. Nothing is deleted, so the bell still lists it
 * as history. Call inside the transaction that finished the task. Returns
 * the number marked read.
 */
function resolveNotifications(PDO $pdo, string $type, int $entityId, array $titles): int {
    if (!$titles) {
        return 0;
    }
    $stmt = $pdo->prepare(
        'UPDATE notifications SET is_read = 1, read_at = NOW()
         WHERE notification_type = ? AND entity_id = ? AND is_read = 0
           AND title IN (' . implode(',', array_fill(0, count($titles), '?')) . ')'
    );
    $stmt->execute(array_merge([$type, $entityId], array_values($titles)));
    return $stmt->rowCount();
}

/**
 * Like resolveNotifications() for notices that point at no single record
 * (entity_id NULL, e.g. a batch notice): the unread copies with this type
 * and title held by the users of one role are marked read. Nothing is
 * deleted. Returns the number marked read.
 */
function resolveUnlinkedNotificationsForRole(PDO $pdo, string $type, string $title, string $roleKey): int {
    $stmt = $pdo->prepare(
        'UPDATE notifications n
         JOIN users u ON u.user_id = n.user_id
         JOIN roles r ON r.role_id = u.role_id
         SET n.is_read = 1, n.read_at = NOW()
         WHERE n.notification_type = ? AND n.entity_id IS NULL AND n.title = ?
           AND n.is_read = 0 AND r.system_key = ?'
    );
    $stmt->execute([$type, $title, $roleKey]);
    return $stmt->rowCount();
}
