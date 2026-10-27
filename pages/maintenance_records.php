<?php
// ============================================================
// pages/maintenance_records.php
// Maintenance Records & History: PM schedules, work orders, maintenance
// records, checklists and inspection history, with the period filter and
// the record actions. It uses the same data, markup and access checks as
// the Maintenance work page (pages/maintenance.php), which shows this view
// when MAINTENANCE_RECORDS_PAGE is defined.
// ============================================================

define('MAINTENANCE_RECORDS_PAGE', true);
require __DIR__ . '/maintenance.php';
