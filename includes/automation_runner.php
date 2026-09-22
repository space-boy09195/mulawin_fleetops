<?php
// Read-only automation runner. Intended for CLI/Task Scheduler, never a page request.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';

function automationTableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function automationColumnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function automationUsers(PDO $pdo, int $role): array {
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE role_id = ? AND is_active = 1");
    $stmt->execute([$role]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function automationNotify(PDO $pdo, string $job, string $fingerprint, array $users, string $title, string $message, ?string $link): int {
    $stmt = $pdo->prepare("INSERT IGNORE INTO automation_deliveries (job_key, fingerprint, user_id) VALUES (?, ?, ?)");
    $notice = $pdo->prepare("INSERT INTO notifications (user_id, title, message, link) VALUES (?, ?, ?, ?)");
    $sent = 0;
    foreach (array_unique($users) as $userId) {
        $stmt->execute([$job, $fingerprint, $userId]);
        if ($stmt->rowCount() === 1) {
            $notice->execute([$userId, $title, $message, $link]);
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

    $head = automationUsers($pdo, ROLE_HEAD_MANAGEMENT);
    $dispatcher = automationUsers($pdo, ROLE_DISPATCHER);
    $maintenance = automationUsers($pdo, ROLE_MAINTENANCE);
    $accounting = automationUsers($pdo, ROLE_ACCOUNTING);
    $results = [];
    $today = date('Y-m-d');

    if (($settings['pending_approval_reminders'] ?? '0') === '1') {
        $results[] = automationRunJob($pdo, 'pending_approval_reminders', function () use ($pdo, $head) {
            $count = 0;
            $routes = $pdo->query("SELECT route_id, route_name FROM routes WHERE approval_status = 'Pending' AND requested_by IS NOT NULL AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchAll();
            foreach ($routes as $row) $count += automationNotify($pdo, 'pending_approval_reminders', hash('sha256', 'route:' . $row['route_id'] . ':' . date('Y-m-d')), $head, 'Route approval pending', 'Route "' . $row['route_name'] . '" has been waiting for more than 24 hours.', '/pages/requests.php');
            $dispatches = $pdo->query("SELECT dispatch_id FROM dispatch_requests WHERE status = 'Pending' AND requested_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($dispatches as $id) $count += automationNotify($pdo, 'pending_approval_reminders', hash('sha256', 'dispatch:' . $id . ':' . date('Y-m-d')), $head, 'Dispatch approval pending', 'Dispatch request #' . $id . ' has been waiting for more than 24 hours.', '/pages/requests.php');
            return $count;
        });
    }

    if (($settings['expiry_reminders'] ?? '0') === '1') {
        $results[] = automationRunJob($pdo, 'expiry_reminders', function () use ($pdo, $head, $maintenance, $today) {
            $count = 0;
            $stmt = $pdo->query("SELECT employee_id, full_name, license_expiry FROM employees WHERE is_active = 1 AND license_expiry IS NOT NULL AND license_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
            foreach ($stmt as $row) $count += automationNotify($pdo, 'expiry_reminders', hash('sha256', 'license:' . $row['employee_id'] . ':' . $row['license_expiry']), array_merge($head, $maintenance), 'License expiry reminder', $row['full_name'] . '\'s license expires on ' . $row['license_expiry'] . '.', '/pages/users.php');
            if (automationColumnExists($pdo, 'documents', 'expiry_date')) {
                foreach ($pdo->query("SELECT document_id, file_name, expiry_date FROM documents WHERE expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)") as $row) {
                    $count += automationNotify($pdo, 'expiry_reminders', hash('sha256', 'document:' . $row['document_id'] . ':' . $row['expiry_date']), $head, 'Document expiry reminder', $row['file_name'] . ' expires on ' . $row['expiry_date'] . '.', '/pages/documents.php');
                }
            }
            return $count;
        });
    }

    if (($settings['maintenance_due_reminders'] ?? '0') === '1') {
        $results[] = automationRunJob($pdo, 'maintenance_due_reminders', function () use ($pdo, $maintenance) {
            $count = 0;
            foreach ($pdo->query("SELECT mr.record_id, mr.truck_id, mr.next_due_date, t.plate_number FROM maintenance_records mr JOIN trucks t ON t.truck_id = mr.truck_id WHERE mr.next_due_date IS NOT NULL AND mr.next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)") as $row) {
                $count += automationNotify($pdo, 'maintenance_due_reminders', hash('sha256', 'maintenance:' . $row['record_id'] . ':' . $row['next_due_date']), $maintenance, 'Maintenance due reminder', 'Truck ' . $row['plate_number'] . ' is due for maintenance on ' . $row['next_due_date'] . '.', '/pages/maintenance.php');
            }
            return $count;
        });
    }

    if (($settings['unpaid_billing_reminders'] ?? '0') === '1') {
        $results[] = automationRunJob($pdo, 'unpaid_billing_reminders', function () use ($pdo, $accounting) {
            $count = 0;
            foreach ($pdo->query("SELECT billing_id, billing_number, client_name, due_date FROM billings WHERE (status IS NULL OR status NOT IN ('Paid', 'Cancelled')) AND due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)") as $row) {
                $label = $row['billing_number'] ?: ('Billing #' . $row['billing_id']);
                $count += automationNotify($pdo, 'unpaid_billing_reminders', hash('sha256', 'billing:' . $row['billing_id'] . ':' . $row['due_date']), $accounting, 'Unpaid billing reminder', $label . ' for ' . ($row['client_name'] ?: 'an unnamed client') . ' is due or overdue.', '/pages/billing.php');
            }
            return $count;
        });
    }

    if (($settings['daily_analytics_summary'] ?? '0') === '1') {
        $results[] = automationRunJob($pdo, 'daily_analytics_summary', function () use ($pdo, $head, $today) {
            $trips = (int)$pdo->query("SELECT COUNT(*) FROM trips WHERE status = 'Completed' AND DATE(created_at) = CURDATE()")->fetchColumn();
            $late = (int)$pdo->query("SELECT COUNT(*) FROM trips WHERE status = 'Completed' AND is_late = 1 AND DATE(created_at) = CURDATE()")->fetchColumn();
            $maintenance = (float)$pdo->query("SELECT COALESCE(SUM(cost), 0) FROM maintenance_records WHERE date_performed = CURDATE()")->fetchColumn();
            $collections = (float)$pdo->query("SELECT COALESCE(SUM(amount_paid), 0) FROM collections WHERE payment_date = CURDATE()")->fetchColumn();
            $message = "Completed trips: $trips; late trips: $late; maintenance cost: ₱" . number_format($maintenance, 2) . "; collections: ₱" . number_format($collections, 2) . '.';
            return automationNotify($pdo, 'daily_analytics_summary', hash('sha256', 'summary:' . $today), $head, 'Daily FleetOps summary', $message, '/pages/analytics.php');
        });
    }

    if (($settings['system_health_checks'] ?? '0') === '1') {
        $results[] = automationRunJob($pdo, 'system_health_checks', function () use ($pdo, $head) {
            $required = ['users', 'employees', 'trucks', 'routes', 'dispatch_requests', 'trips', 'maintenance_records', 'billings', 'collections', 'documents', 'notifications'];
            $missing = array_values(array_filter($required, fn($table) => !automationTableExists($pdo, $table)));
            $missingFiles = 0;
            if (!$missing && automationTableExists($pdo, 'documents')) {
                foreach ($pdo->query("SELECT stored_name FROM documents WHERE stored_name IS NOT NULL") as $doc) if (!is_file(dirname(__DIR__) . '/uploads/' . basename($doc['stored_name']))) $missingFiles++;
            }
            if (!$missing && $missingFiles === 0) return 0;
            $message = $missing ? 'Missing database tables: ' . implode(', ', $missing) . '.' : "$missingFiles document file(s) are missing from uploads.";
            return automationNotify($pdo, 'system_health_checks', hash('sha256', $message . ':' . date('Y-m-d')), $head, 'FleetOps health warning', $message, '/pages/automation.php');
        });
    }
    if (($settings['audit_log_retention'] ?? '0') === '1') {
        $results[] = automationRunJob($pdo, 'audit_log_retention', function () use ($pdo, $head, $today) {
            if (!automationTableExists($pdo, 'audit_logs')) return 0;
            $retentionDays = 365;
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE logged_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
            $stmt->execute([$retentionDays]);
            $count = (int)$stmt->fetchColumn();
            if ($count === 0) return 0;
            $pdo->prepare("DELETE FROM audit_logs WHERE logged_at < DATE_SUB(NOW(), INTERVAL ? DAY)")
                ->execute([$retentionDays]);
            automationNotify($pdo, 'audit_log_retention', hash('sha256', 'audit_prune:' . $today),
                $head, 'Audit log retention', "Pruned $count audit log entr" . ($count === 1 ? 'y' : 'ies') . " older than $retentionDays days.", '/pages/recycle_bin.php');
            return $count;
        });
    }
    return $results;
}
