<?php

require_once __DIR__ . '/app_settings.php';
require_once __DIR__ . '/notifications.php';

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

function runConfiguredExpiryReminders(PDO $pdo): int {
    $thresholdSetting = getAppSetting($pdo, 'reminder_thresholds_days', '60,30,15,7');
    $thresholdValues = array_map('trim', explode(',', (string)$thresholdSetting));
    $thresholds = [];
    foreach ($thresholdValues as $value) {
        if (!preg_match('/^\d{1,3}$/', $value) || (int)$value < 1 || (int)$value > 365) {
            throw new RuntimeException('Reminder threshold setting contains an invalid value.');
        }
        $thresholds[] = (int)$value;
    }
    $thresholds = array_values(array_unique($thresholds));
    if (!$thresholds) {
        throw new RuntimeException('No valid document reminder thresholds are configured.');
    }

    $documentRecipients = expiryReminderRecipients($pdo, ['documents.manage']);
    $employeeRecipients = expiryReminderRecipients($pdo, ['users.manage']);
    $partRecipients = expiryReminderRecipients($pdo, ['parts.manage']);
    $fleetRecipients = expiryReminderRecipients($pdo, ['fleet.manage']);
    // Target: documents (metadata) + files (original name).
    $documentQuery = $pdo->prepare(
        'SELECT d.document_id, f.original_name AS file_name, d.document_category AS doc_type
         FROM documents d
         JOIN files f ON f.file_id = d.file_id
         WHERE d.expiry_date = ?
         ORDER BY d.document_id'
    );
    // full_name / is_active are the T-E1 compatibility columns.
    $licenseQuery = $pdo->prepare(
        "SELECT employee_id, full_name
         FROM employees
         WHERE employment_status = 'Active' AND license_expiry = ?
         ORDER BY employee_id"
    );
    // Truck registration / insurance papers (truck_documents.expiry_date).
    $truckDocumentQuery = $pdo->prepare(
        'SELECT td.truck_document_id, td.truck_id, td.document_type, t.plate_number
         FROM truck_documents td
         JOIN trucks t ON t.truck_id = td.truck_id
         WHERE td.expiry_date = ?
         ORDER BY td.truck_document_id'
    );
    // The target keeps a warranty period per part, not an expiry date per
    // stock item. A part installed during maintenance is under warranty
    // until its issue date plus that period.
    $partWarrantyQuery = $pdo->prepare(
        'SELECT mp.maintenance_part_id, mp.maintenance_id, p.part_name, p.part_number, t.plate_number
         FROM maintenance_parts mp
         JOIN parts p ON p.part_id = mp.part_id
         JOIN maintenance_records mr ON mr.maintenance_id = mp.maintenance_id
         JOIN trucks t ON t.truck_id = mr.truck_id
         WHERE p.warranty_period_days IS NOT NULL AND p.warranty_period_days > 0
           AND DATE_ADD(DATE(mp.issued_at), INTERVAL p.warranty_period_days DAY) = ?
         ORDER BY mp.maintenance_part_id'
    );
    $delivered = 0;

    foreach ($thresholds as $days) {
        $dueDate = (new DateTimeImmutable('today'))
            ->modify('+' . $days . ' days')
            ->format('Y-m-d');

        $documentQuery->execute([$dueDate]);
        foreach ($documentQuery->fetchAll(PDO::FETCH_ASSOC) as $document) {
            $delivered += deliverExpiryReminder(
                $pdo,
                $documentRecipients,
                'document',
                (int)$document['document_id'],
                'Document expiry approaching',
                $document['doc_type'] . ' "' . $document['file_name'] . '" expires in ' . $days . ' days.',
                'document',
                $days,
                $dueDate
            );
        }

        $licenseQuery->execute([$dueDate]);
        foreach ($licenseQuery->fetchAll(PDO::FETCH_ASSOC) as $employee) {
            $delivered += deliverExpiryReminder(
                $pdo,
                $employeeRecipients,
                'employee_license',
                (int)$employee['employee_id'],
                'Driver license expiry approaching',
                ($employee['full_name'] ?? ('Employee #' . $employee['employee_id'])) . "'s driver license expires in " . $days . ' days.',
                'employee',
                $days,
                $dueDate
            );
        }

        $truckDocumentQuery->execute([$dueDate]);
        foreach ($truckDocumentQuery->fetchAll(PDO::FETCH_ASSOC) as $truckDocument) {
            $delivered += deliverExpiryReminder(
                $pdo,
                $fleetRecipients,
                'truck_document',
                (int)$truckDocument['truck_document_id'],
                'Truck document expiry approaching',
                $truckDocument['document_type'] . ' of truck ' . $truckDocument['plate_number'] . ' expires in ' . $days . ' days.',
                'truck',
                $days,
                $dueDate,
                (int)$truckDocument['truck_id']
            );
        }

        $partWarrantyQuery->execute([$dueDate]);
        foreach ($partWarrantyQuery->fetchAll(PDO::FETCH_ASSOC) as $part) {
            $partReference = $part['part_number']
                ? ' (' . $part['part_number'] . ')'
                : '';
            $delivered += deliverExpiryReminder(
                $pdo,
                $partRecipients,
                'part_warranty',
                (int)$part['maintenance_part_id'],
                'Part warranty expiry approaching',
                $part['part_name'] . $partReference . ' installed on truck ' . $part['plate_number'] . ' leaves warranty in ' . $days . ' days.',
                'maintenance',
                $days,
                $dueDate,
                (int)$part['maintenance_id']
            );
        }
    }

    return $delivered;
}
