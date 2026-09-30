<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/expiry_reminders.php';

$pdo = getDBConnection();
$delivered = runConfiguredExpiryReminders($pdo);
fwrite(STDOUT, 'Expiry reminders delivered: ' . $delivered . PHP_EOL);
