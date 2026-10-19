<?php
// Read-only automation runner. Intended for CLI/Task Scheduler, never a page request.
// Every job is off unless enabled in automation_settings (T-S1 seeds all to '0').
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/document_storage.php';
require_once __DIR__ . '/expiry_reminders.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/metrics.php';

function automationTableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

// Active users in active roles that hold any of the given permissions.
// Recipients come from grants, never from role IDs. The Admin role is left
// out: Admin has its own notices (automationAdminUsers) and holds every
// permission, so it would otherwise receive every department's reminders.
function automationPermissionUsers(PDO $pdo, array $permissionKeys): array {
    $placeholders = implode(',', array_fill(0, count($permissionKeys), '?'));
    $stmt = $pdo->prepare(
        "SELECT DISTINCT u.user_id
         FROM users u
         JOIN roles r ON r.role_id = u.role_id
         JOIN role_permissions rp ON rp.role_id = u.role_id
         JOIN permissions p ON p.permission_id = rp.permission_id
         WHERE u.is_active = 1 AND r.is_active = 1
           AND (r.system_key IS NULL OR r.system_key <> 'admin')
           AND p.permission_key IN ($placeholders)"
    );
    $stmt->execute($permissionKeys);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// Active users in the active role whose system_key is 'admin'. Admin identity
// comes from the stable key, never from a role ID. This runs without a
// session, so it queries the database instead of calling isAdmin().
function automationAdminUsers(PDO $pdo): array {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT u.user_id
         FROM users u
         JOIN roles r ON r.role_id = u.role_id
         WHERE r.system_key = ? AND r.is_active = 1 AND u.is_active = 1"
    );
    $stmt->execute(['admin']);
    $users = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$users) {
        error_log('automation: no active Admin user (active role with system_key admin); Admin notifications (approval reminders, daily summary, health warnings) have no recipients.');
    }
    return $users;
}

// $entityType / $entityId: the record the notification links to
// (NOTIFICATION_ENTITY_LINKS in includes/notifications.php).
function automationNotify(PDO $pdo, string $job, string $fingerprint, array $users, string $title, string $message, ?string $entityType = null, ?int $entityId = null): int {
    $stmt = $pdo->prepare("INSERT IGNORE INTO automation_deliveries (job_key, fingerprint, user_id) VALUES (?, ?, ?)");
    $sent = 0;
    foreach (array_unique($users) as $userId) {
        $stmt->execute([$job, $fingerprint, $userId]);
        if ($stmt->rowCount() === 1) {
            createNotification($pdo, (int)$userId, $title, $message, 'automation:' . $job, $entityType, $entityId);
            $sent++;
        }
    }
    return $sent;
}

function automationRunJob(PDO $pdo, string $job, callable $callback): array {
    $started = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO automation_runs (job_key, started_at) VALUES (?, ?)");
    $stmt->execute([$job, $started]);
    $runId = (int)$pdo->lastInsertId();
    try {
        $count = (int)$callback();
        $stmt = $pdo->prepare("UPDATE automation_runs SET finished_at = NOW(), status = 'success', items_processed = ? WHERE run_id = ?");
        $stmt->execute([$count, $runId]);
        return ['job' => $job, 'status' => 'success', 'items' => $count];
    } catch (Throwable $e) {
        $stmt = $pdo->prepare("UPDATE automation_runs SET finished_at = NOW(), status = 'failed', error_message = ? WHERE run_id = ?");
        $stmt->execute([mb_substr($e->getMessage(), 0, 500), $runId]);
        error_log("automation[$job]: " . $e->getMessage());
        return ['job' => $job, 'status' => 'failed', 'items' => 0];
    }
}

function runFleetOpsAutomations(): array {
    $pdo = getDBConnection();
    $settings = $pdo->query("SELECT setting_key, setting_value FROM automation_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (($settings['automation_engine_enabled'] ?? '0') !== '1') return [['status' => 'disabled']];

    $head = automationAdminUsers($pdo);
    // Reminder recipients can open the linked pages: maintenance.php
    // (maintenance.manage) and billing.php (billing.manage).
    $maintenance = automationPermissionUsers($pdo, ['maintenance.manage']);
    $accounting = automationPermissionUsers($pdo, ['billing.manage']);
    $results = [];
    $today = date('Y-m-d');

    if (($settings['pending_approval_reminders'] ?? '0') === '1') {
        $results[] = automationRunJob($pdo, 'pending_approval_reminders', function () use ($pdo, $head) {
            $count = 0;
            // Every workflow (dispatch, route requests, purchasing, funds,
            // vouchers, payroll) shares approval_requests in the target.
            $pending = $pdo->query(
                "SELECT ar.approval_request_id, ar.entity_id, w.workflow_name
                 FROM approval_requests ar
                 JOIN approval_workflows w ON w.workflow_id = ar.workflow_id
                 WHERE ar.status = 'Pending' AND ar.submitted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            )->fetchAll();
            foreach ($pending as $row) {
                $count += automationNotify(
                    $pdo,
                    'pending_approval_reminders',
                    hash('sha256', 'approval:' . $row['approval_request_id'] . ':' . date('Y-m-d')),
                    $head,
                    'Approval pending',
                    $row['workflow_name'] . ' #' . $row['entity_id'] . ' has been waiting for more than 24 hours.',
                    'approval_request',
                    (int)$row['approval_request_id']
                );
            }
            return $count;
        });
    }

    if (($settings['expiry_reminders'] ?? '0') === '1') {
        $results[] = automationRunJob(
            $pdo,
            'expiry_reminders',
            static fn(): int => runConfiguredExpiryReminders($pdo)
        );
    }

    if (($settings['maintenance_due_reminders'] ?? '0') === '1') {
        $results[] = automationRunJob($pdo, 'maintenance_due_reminders', function () use ($pdo, $maintenance) {
            $count = 0;
            // next_due_date: T-M1 draft column.
            $due = $pdo->query(
                "SELECT mr.maintenance_id, mr.truck_id, mr.next_due_date, t.plate_number
                 FROM maintenance_records mr
                 JOIN trucks t ON t.truck_id = mr.truck_id
                 WHERE mr.next_due_date IS NOT NULL
                   AND mr.next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)"
            );
            foreach ($due as $row) {
                $count += automationNotify(
                    $pdo,
                    'maintenance_due_reminders',
                    hash('sha256', 'maintenance:' . $row['maintenance_id'] . ':' . $row['next_due_date']),
                    $maintenance,
                    'Maintenance due reminder',
                    'Truck ' . $row['plate_number'] . ' is due for maintenance on ' . $row['next_due_date'] . '.',
                    'maintenance',
                    (int)$row['maintenance_id']
                );
            }
            return $count;
        });
    }

    if (($settings['unpaid_billing_reminders'] ?? '0') === '1') {
        $results[] = automationRunJob($pdo, 'unpaid_billing_reminders', function () use ($pdo, $accounting) {
            $count = 0;
            // Only issued invoices can be unpaid; Draft, Paid and Cancelled are skipped.
            $unpaid = $pdo->query(
                "SELECT b.billing_id, b.invoice_number, c.client_name, b.due_date
                 FROM billings b
                 JOIN clients c ON c.client_id = b.client_id
                 WHERE b.status IN ('Issued', 'Partially Paid', 'Overdue')
                   AND b.due_date IS NOT NULL
                   AND b.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
            );
            foreach ($unpaid as $row) {
                $label = $row['invoice_number'] ?: ('Billing #' . $row['billing_id']);
                $count += automationNotify(
                    $pdo,
                    'unpaid_billing_reminders',
                    hash('sha256', 'billing:' . $row['billing_id'] . ':' . $row['due_date']),
                    $accounting,
                    'Unpaid billing reminder',
                    $label . ' for ' . ($row['client_name'] ?: 'an unnamed client') . ' is due or overdue.',
                    'billing',
                    (int)$row['billing_id']
                );
            }
            return $count;
        });
    }

    if (($settings['daily_analytics_summary'] ?? '0') === '1') {
        $results[] = automationRunJob($pdo, 'daily_analytics_summary', function () use ($pdo, $head, $today) {
            // Same definitions as the analytics pages (includes/metrics.php).
            $trips = (int)$pdo->query("SELECT COUNT(*) FROM trips t WHERE t.status = 'Completed' AND DATE(t.completion_time) = CURDATE()")->fetchColumn();
            $late = (int)$pdo->query("SELECT COUNT(*) FROM trips t WHERE t.status = 'Completed' AND DATE(t.completion_time) = CURDATE() AND " . tripLateSql('t'))->fetchColumn();
            $maintenance = (float)$pdo->query("SELECT COALESCE(SUM(total_cost), 0) FROM maintenance_records WHERE status = 'Completed' AND actual_completion_date = CURDATE()")->fetchColumn();
            $collections = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM collections WHERE payment_date = CURDATE()")->fetchColumn();
            $message = "Completed trips: $trips; late trips: $late; maintenance cost: \u{20B1}" . number_format($maintenance, 2)
                . "; collections: \u{20B1}" . number_format($collections, 2) . '.';
            return automationNotify($pdo, 'daily_analytics_summary', hash('sha256', 'summary:' . $today), $head, 'Daily FleetOps summary', $message);
        });
    }

    if (($settings['system_health_checks'] ?? '0') === '1') {
        $results[] = automationRunJob($pdo, 'system_health_checks', function () use ($pdo, $head) {
            $required = ['users', 'employees', 'trucks', 'routes', 'dispatch_requests', 'trips', 'maintenance_records',
                'billings', 'collections', 'files', 'documents', 'notifications', 'approval_workflow_steps', 'deleted_records'];
            $missing = array_values(array_filter($required, fn($table) => !automationTableExists($pdo, $table)));
            $missingFiles = 0;
            if (!$missing) {
                foreach ($pdo->query("SELECT f.stored_name, f.storage_path FROM files f JOIN documents d ON d.file_id = f.file_id") as $doc) {
                    try {
                        if (!is_file(documentStorageFile($doc['stored_name'], $doc['storage_path']))) $missingFiles++;
                    } catch (Throwable $e) {
                        $missingFiles++;
                    }
                }
            }
            if (!$missing && $missingFiles === 0) return 0;
            $message = $missing ? 'Missing database tables: ' . implode(', ', $missing) . '.' : "$missingFiles document file(s) are missing from uploads.";
            return automationNotify($pdo, 'system_health_checks', hash('sha256', $message . ':' . date('Y-m-d')), $head, 'FleetOps health warning', $message);
        });
    }
    return $results;
}
