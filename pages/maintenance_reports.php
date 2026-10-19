<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/app.php';

requirePermission('maintenance.reports.view');

header('Location: ' . APP_BASE . '/pages/analytics.php', true, 302);
exit;
