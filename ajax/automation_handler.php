<?php
// ============================================================
// ajax/automation_handler.php
// Future automation controls — no jobs are enabled yet.
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
if ($action !== 'toggle_engine') {
    jsonFail('Unknown automation action.');
}

// Keep the endpoint safe until a real, reviewed job registry exists.
jsonFail('No automation jobs are configured yet. Nothing was changed.', 409);
