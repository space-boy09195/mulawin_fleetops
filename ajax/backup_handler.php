<?php
// ============================================================
// ajax/backup_handler.php
// action=create   — Admin only: full database dump + document copy
// action=download — Admin only: download a backup's SQL dump
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/backup.php';

requireLogin();
if (!isAdmin()) {
    if (isJsonRequest()) {
        header('Content-Type: application/json');
        sendJsonAccessResponse(403, 'You do not have permission to perform this action.');
    }
    http_response_code(403);
    include __DIR__ . '/../pages/403.php';
    exit;
}
requirePostMethod();
enforceCsrf();

$action = $_POST['action'] ?? '';

if ($action === 'download') {
    $name = (string)($_POST['name'] ?? '');
    $file = isValidBackupName($name) ? backupRootDirectory() . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'database.sql' : '';
    if ($file === '' || !is_file($file)) {
        http_response_code(404);
        exit('Backup not found.');
    }
    auditLog('backup_downloaded', 'system_backup', null, null, ['name' => $name]);
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="mulawin_fleetops_' . $name . '.sql"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

header('Content-Type: application/json');

if ($action === 'create') {
    set_time_limit(0);
    try {
        $result = createSystemBackup(getDBConnection());
    } catch (Throwable $e) {
        error_log('Backup failed: ' . $e->getMessage());
        jsonFail('The backup could not be completed. Please check the server logs.', 500);
    }
    auditLog('backup_created', 'system_backup', null, null, $result);
    jsonOk($result, 'Backup completed.');
}

jsonFail('Unknown action.');
