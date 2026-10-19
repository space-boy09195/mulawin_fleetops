<?php
// ============================================================
// includes/notifications.php
// Target notifications table: user_id, notification_type, title, message,
// entity_type, entity_id, is_read, read_at. There is no link column: a
// notification points at a record (entity_type + entity_id) and the layout
// builds the link from a fixed allowlist (notificationLinkFor()).
// ============================================================

require_once __DIR__ . '/../config/database.php';

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
    return APP_BASE . NOTIFICATION_ENTITY_LINKS[$entityType];
}
