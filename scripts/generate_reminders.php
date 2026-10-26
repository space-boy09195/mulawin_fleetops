<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/expiry_reminders.php';
require_once __DIR__ . '/../includes/automation_runner.php';

// Each run is recorded in automation_runs (job 'expiry_reminders', the same
// key the automation runner uses). Safe to repeat: reminder_deliveries keeps
// one notice per recipient, item and reminder tier.
$pdo = getDBConnection();
$result = automationRunJob($pdo, 'expiry_reminders', static fn(): int => runConfiguredExpiryReminders($pdo));
fwrite(STDOUT, 'Expiry reminders: ' . $result['status'] . ', delivered: ' . $result['items'] . PHP_EOL);
exit($result['status'] === 'success' ? 0 : 1);
