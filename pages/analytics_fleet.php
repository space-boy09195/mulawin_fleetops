<?php
// ============================================================
// pages/analytics_fleet.php
// Fleet Overview: the Operations section of pages/analytics.php (trips,
// trip status, clients, trucks and fleet status) on its own page. Same
// access rule as that section: operations.reports.view or reports.view
// (Admin holds every permission). Queries, formulas and explanations stay
// in pages/analytics.php.
// ============================================================
require_once __DIR__ . '/../includes/session.php';

requireAnyPermission(['operations.reports.view', 'reports.view']);

define('ANALYTICS_SECTION', 'operations');
require __DIR__ . '/analytics.php';
