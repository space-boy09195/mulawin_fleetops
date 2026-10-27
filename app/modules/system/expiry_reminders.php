<?php

require_once __DIR__ . '/../../core/app_settings.php';
require_once __DIR__ . '/../../core/notifications.php';

function expiryReminderRecipients(PDO $pdo, array $permissionKeys): array {
    $placeholders = implode(',', array_fill(0, count($permissionKeys), '?'));
    $stmt = $pdo->prepare(
        "SELECT DISTINCT u.user_id
         FROM users u
         JOIN roles r ON r.role_id = u.role_id AND r.is_active = 1
         JOIN role_permissions rp ON rp.role_id = u.role_id
         JOIN permissions p ON p.permission_id = rp.permission_id
         WHERE u.is_active = 1 AND p.permission_key IN ($placeholders)"
    );
    $stmt->execute($permissionKeys);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// $linkEntity / $linkEntityId: the record the notification links to
// (see NOTIFICATION_ENTITY_LINKS); the reminder itself is keyed by
// $entityType / $entityId in reminder_deliveries.
function deliverExpiryReminder(
    PDO $pdo,
    array $recipients,
    string $entityType,
    int $entityId,
    string $title,
    string $message,
    string $linkEntity,
    int $days,
    string $dueDate,
    ?int $linkEntityId = null
): int {
    $delivery = $pdo->prepare(
        'INSERT IGNORE INTO reminder_deliveries
            (recipient_user_id, entity_type, entity_id, reminder_days, due_date)
         VALUES (?, ?, ?, ?, ?)'
    );
    $legacyPrefix = match ($entityType) {
        'document' => 'document:',
        'employee_license' => 'license:',
        default => $entityType . ':',
    };
    $legacyFingerprint = hash('sha256', $legacyPrefix . $entityId . ':' . $dueDate);
    $legacyDelivery = $pdo->prepare(
        'SELECT 1
         FROM automation_deliveries
         WHERE job_key = ? AND fingerprint = ? AND user_id = ?
         LIMIT 1'
    );
    $delivered = 0;
    foreach ($recipients as $userId) {
        $pdo->beginTransaction();
        try {
            $delivery->execute([$userId, $entityType, $entityId, $days, $dueDate]);
            if ($delivery->rowCount() === 1) {
                $legacyDelivery->execute(['expiry_reminders', $legacyFingerprint, $userId]);
                if (!$legacyDelivery->fetchColumn()) {
                    createNotification($pdo, $userId, $title, $message, 'reminder', $linkEntity, $linkEntityId ?? $entityId);
                    $delivered++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
    return $delivered;
}

/**
 * Reminder tier for an item: the smallest configured threshold (days
 * before expiry) that still covers the days left, or null when the item is
 * not yet inside any threshold or has already expired. A run that was
 * skipped therefore still sends the tier that applies today instead of
 * missing it; the delivery key (reminder_deliveries) keeps each tier to one
 * notice per recipient. A threshold of 0 means "on the expiry date".
 */
function expiryReminderTier(int $daysLeft, array $thresholds): ?int {
    if ($daysLeft < 0) {
        return null;
    }
    $tier = null;
    foreach ($thresholds as $threshold) {
        if ($threshold >= $daysLeft && ($tier === null || $threshold < $tier)) {
            $tier = (int)$threshold;
        }
    }
    return $tier;
}

/** Parses the reminder_thresholds_days setting ("60,30,15,7"; 0-365). */
function parseExpiryReminderThresholds(string $setting): array {
    $thresholds = [];
    foreach (array_map('trim', explode(',', $setting)) as $value) {
        if (!preg_match('/^\d{1,3}$/', $value) || (int)$value > 365) {
            throw new RuntimeException('Reminder threshold setting contains an invalid value.');
        }
        $thresholds[] = (int)$value;
    }
    $thresholds = array_values(array_unique($thresholds));
    if (!$thresholds) {
        throw new RuntimeException('No valid document reminder thresholds are configured.');
    }
    return $thresholds;
}

/**
 * Adds the expiry-date tier (0) to the configured advance thresholds. Part
 * warranties get an advance reminder and a notice on the expiry date itself
 * (owner decision E5); the other reminder types keep the setting as is.
 */
function withExpiryDateNotice(array $thresholds): array {
    return in_array(0, $thresholds, true) ? $thresholds : array_merge($thresholds, [0]);
}

function expiryDaysPhrase(int $daysLeft): string {
    return match ($daysLeft) {
        0 => 'today',
        1 => 'in 1 day',
        default => 'in ' . $daysLeft . ' days',
    };
}

function runConfiguredExpiryReminders(PDO $pdo): int {
    $thresholds = parseExpiryReminderThresholds(
        (string)getAppSetting($pdo, 'reminder_thresholds_days', '60,30,15,7')
    );
    $partThresholds = withExpiryDateNotice($thresholds);
    $today = new DateTimeImmutable('today');
    $windowStart = $today->format('Y-m-d');
    $windowEnd = $today->modify('+' . max($thresholds) . ' days')->format('Y-m-d');

    $documentRecipients = expiryReminderRecipients($pdo, ['documents.manage']);
    $employeeRecipients = expiryReminderRecipients($pdo, ['users.manage']);
    $partRecipients = expiryReminderRecipients($pdo, ['parts.manage']);
    $fleetRecipients = expiryReminderRecipients($pdo, ['fleet.manage']);
    // Target: documents (metadata) + files (original name).
    $documentQuery = $pdo->prepare(
        'SELECT d.document_id, f.original_name AS file_name, d.document_category AS doc_type,
                d.expiry_date AS due_date
         FROM documents d
         JOIN files f ON f.file_id = d.file_id
         WHERE d.expiry_date BETWEEN ? AND ?
         ORDER BY d.document_id'
    );
    // full_name / is_active are the T-E1 compatibility columns.
    $licenseQuery = $pdo->prepare(
        "SELECT employee_id, full_name, license_expiry AS due_date
         FROM employees
         WHERE employment_status = 'Active' AND license_expiry BETWEEN ? AND ?
         ORDER BY employee_id"
    );
    // Truck registration / insurance papers (truck_documents.expiry_date).
    $truckDocumentQuery = $pdo->prepare(
        'SELECT td.truck_document_id, td.truck_id, td.document_type, t.plate_number,
                td.expiry_date AS due_date
         FROM truck_documents td
         JOIN trucks t ON t.truck_id = td.truck_id
         WHERE td.expiry_date BETWEEN ? AND ?
         ORDER BY td.truck_document_id'
    );
    // The target keeps a warranty period per part, not an expiry date per
    // stock item. A part installed during maintenance is under warranty
    // until its issue date plus that period.
    $partWarrantyQuery = $pdo->prepare(
        'SELECT mp.maintenance_part_id, mp.maintenance_id, p.part_name, p.part_number, t.plate_number,
                DATE_ADD(DATE(mp.issued_at), INTERVAL p.warranty_period_days DAY) AS due_date
         FROM maintenance_parts mp
         JOIN parts p ON p.part_id = mp.part_id
         JOIN maintenance_records mr ON mr.maintenance_id = mp.maintenance_id
         JOIN trucks t ON t.truck_id = mr.truck_id
         WHERE p.warranty_period_days IS NOT NULL AND p.warranty_period_days > 0
           AND DATE_ADD(DATE(mp.issued_at), INTERVAL p.warranty_period_days DAY) BETWEEN ? AND ?
         ORDER BY mp.maintenance_part_id'
    );
    // Each source: [query, row => [recipients, entity type, entity id, title,
    // message, link entity, link entity id], thresholds].
    $sources = [
        [$documentQuery, static fn(array $row, string $when): array => [
            $documentRecipients, 'document', (int)$row['document_id'],
            'Document expiry approaching',
            $row['doc_type'] . ' "' . $row['file_name'] . '" expires ' . $when . '.',
            'document', null,
        ], $thresholds],
        [$licenseQuery, static fn(array $row, string $when): array => [
            $employeeRecipients, 'employee_license', (int)$row['employee_id'],
            'Driver license expiry approaching',
            ($row['full_name'] ?? ('Employee #' . $row['employee_id'])) . "'s driver license expires " . $when . '.',
            'employee', null,
        ], $thresholds],
        [$truckDocumentQuery, static fn(array $row, string $when): array => [
            $fleetRecipients, 'truck_document', (int)$row['truck_document_id'],
            'Truck document expiry approaching',
            $row['document_type'] . ' of truck ' . $row['plate_number'] . ' expires ' . $when . '.',
            'truck', (int)$row['truck_id'],
        ], $thresholds],
        [$partWarrantyQuery, static fn(array $row, string $when): array => [
            $partRecipients, 'part_warranty', (int)$row['maintenance_part_id'],
            $when === 'today' ? 'Part warranty expires today' : 'Part warranty expiry approaching',
            $row['part_name'] . ($row['part_number'] ? ' (' . $row['part_number'] . ')' : '')
                . ' installed on truck ' . $row['plate_number'] . ' leaves warranty ' . $when . '.',
            'maintenance', (int)$row['maintenance_id'],
        ], $partThresholds],
    ];

    $delivered = 0;
    foreach ($sources as [$query, $describe, $sourceThresholds]) {
        $query->execute([$windowStart, $windowEnd]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dueDate = (string)$row['due_date'];
            $daysLeft = (int)$today->diff(new DateTimeImmutable($dueDate))->format('%r%a');
            $tier = expiryReminderTier($daysLeft, $sourceThresholds);
            if ($tier === null) {
                continue;
            }
            [$recipients, $entityType, $entityId, $title, $message, $linkEntity, $linkEntityId]
                = $describe($row, expiryDaysPhrase($daysLeft));
            $delivered += deliverExpiryReminder(
                $pdo,
                $recipients,
                $entityType,
                $entityId,
                $title,
                $message,
                $linkEntity,
                $tier,
                $dueDate,
                $linkEntityId
            );
        }
    }

    return $delivered;
}
