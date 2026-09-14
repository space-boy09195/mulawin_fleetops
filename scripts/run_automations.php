<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/automation_runner.php';
foreach (runFleetOpsAutomations() as $result) {
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
