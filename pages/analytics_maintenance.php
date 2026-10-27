<?php
// ============================================================
// pages/analytics_maintenance.php
// Maintenance Analytics: the Maintenance section of pages/analytics.php
// (completed maintenance cost, cost by type and truck, record status) on
// its own page. Same access rule as that section: maintenance.reports.view
// (Admin holds every permission). Queries, formulas and explanations stay
// in pages/analytics.php.
// ============================================================
require_once __DIR__ . '/../includes/session.php';

requirePermission('maintenance.reports.view');

define('ANALYTICS_SECTION', 'maintenance');
require __DIR__ . '/analytics.php';
