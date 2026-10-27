<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/app.php';

requirePermission('operations.reports.view');

header('Location: ' . APP_BASE . '/pages/analytics_fleet.php', true, 302);
exit;
