<?php

require_once __DIR__ . '/app_settings.php';
require_once __DIR__ . '/notifications.php';

function expiryReminderRecipients(PDO $pdo, array $permissionKeys): array {
    $placeholders = implode(',', array_fill(0, count($permissionKeys), '?'));
    $stmt = $pdo->prepare(
        "SELECT DISTINCT u.user_id
         FROM users u
         JOIN role_permissions rp ON rp.role_id = u.role_id
         JOIN permissions p ON p.permission_id = rp.permission_id
         WHERE u.is_active = 1 AND p.permission_key IN ($placeholders)"
    );
    $stmt->execute($permissionKeys);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function deliverExpiryReminder(
    PDO $pdo,
    array $recipients,
    string $entityType,
    int $entityId,
    string $title,
    string $message,
    string $link,
    int $days,
    string $dueDate
): int {
    $delivery = $pdo->prepare(
        'INSERT IGNORE INTO reminder_deliveries
            (recipient_user_id, entity_type, entity_id, reminder_days, due_date)
         VALUES (?, ?, ?, ?, ?)'
    );
    $legacyFingerprint = hash(
        'sha256',
        ($entityType === 'document' ? 'document:' : 'license:')
            . $entityId . ':' . $dueDate
    );
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
                    createNotification($pdo, $userId, $title, $message, $link);
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
    $employeeRecipients = expiryReminderRecipients($pdo, ['employees.manage', 'fleet.manage']);
    $documentQuery = $pdo->prepare(
        'SELECT document_id, file_name, doc_type
         FROM documents
         WHERE expiry_date = ?
         ORDER BY document_id'
    );
    $licenseQuery = $pdo->prepare(
        'SELECT employee_id, full_name
         FROM employees
         WHERE is_active = 1 AND license_expiry = ?
         ORDER BY employee_id'
    );
    $partWarrantyQuery = $pdo->prepare(
        'SELECT part_id, part_name, part_number
         FROM parts_inventory
         WHERE warranty_expiry = ?
         ORDER BY part_id'
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
                APP_BASE . '/pages/documents.php',
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
                $employee['full_name'] . "'s driver license expires in " . $days . ' days.',
                APP_BASE . '/pages/users.php',
                $days,
                $dueDate
            );
        }

        $partWarrantyQuery->execute([$dueDate]);
        foreach ($partWarrantyQuery->fetchAll(PDO::FETCH_ASSOC) as $part) {
            $partReference = $part['part_number']
                ? ' (' . $part['part_number'] . ')'
                : '';
            $delivered += deliverExpiryReminder(
                $pdo,
                $employeeRecipients,
                'part_warranty',
                (int)$part['part_id'],
                'Part warranty expiry approaching',
                $part['part_name'] . $partReference . ' warranty expires in ' . $days . ' days.',
                APP_BASE . '/pages/parts.php',
                $days,
                $dueDate
            );
        }
    }

    return $delivered;
}
