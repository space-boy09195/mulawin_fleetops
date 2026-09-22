<?php
// ============================================================
// ajax/automation_handler.php
// Automation controls for independently managed, read-only jobs.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT]);
requirePostMethod();
enforceCsrf();

$action = $_POST['action'] ?? '';
$allowedJobs = ['pending_approval_reminders', 'expiry_reminders', 'maintenance_due_reminders',
    'unpaid_billing_reminders', 'daily_analytics_summary', 'system_health_checks'];
$enabledRaw = $_POST['enabled'] ?? null;
if (!in_array((string)$enabledRaw, ['0', '1'], true)) jsonFail('Invalid automation setting.');
$key = $action === 'toggle_engine' ? 'automation_engine_enabled' : trim($_POST['job_key'] ?? '');
if ($action !== 'toggle_engine' && ($action !== 'toggle_job' || !in_array($key, $allowedJobs, true))) {
    jsonFail('Unknown automation action.');
}
$pdo = getDBConnection();

// automation_settings is keyed by a VARCHAR setting_key, not an int PK, so it
// can't populate audit_logs.record_id (INT UNSIGNED). Capture the before/after
// state instead and keep setting_key in both snapshots so the row stays
// traceable via old_value/new_value (the recycle_bin audit search already
// matches against both).
$prevStmt = $pdo->prepare("SELECT setting_value FROM automation_settings WHERE setting_key = ?");
$prevStmt->execute([$key]);
$prevValue = $prevStmt->fetchColumn();
if ($prevValue === false) jsonFail('Automation settings are not installed. Run the automation migration first.', 409);

$stmt = $pdo->prepare("UPDATE automation_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?");
$stmt->execute([$enabledRaw, currentUserId(), $key]);

auditLog(
    'UPDATE',
    'automation_settings',
    null,
    ['setting_key' => $key, 'enabled' => $prevValue === '1'],
    ['setting_key' => $key, 'enabled' => $enabledRaw === '1']
);
header('Location: ' . APP_BASE . '/pages/automation.php');
exit;
