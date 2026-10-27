<?php
// ============================================================
// pages/analytics_expenses.php
// Billing & Collections Analytics: the Accounting section of
// pages/analytics.php (billed and collected amounts, billing status,
// clients and payment methods) on its own page. Same access rule as that
// section: billing.view (Admin holds every permission). Queries, formulas
// and explanations stay in pages/analytics.php.
// ============================================================
require_once __DIR__ . '/../includes/session.php';

requirePermission('billing.view');

define('ANALYTICS_SECTION', 'accounting');
require __DIR__ . '/analytics.php';
