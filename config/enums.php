<?php
// ============================================================
// config/enums.php
// Single source of truth for fixed sets of allowed values.
// Previously these lists (truck statuses, fuel types, etc.)
// were re-declared inline in multiple ajax/*.php files, which
// risks drifting out of sync with each other and with the DB.
// Update here once; every handler picks up the change.
// ============================================================

define('TRUCK_STATUSES', ['Available', 'Deployed', 'Under Maintenance', 'Inactive']);

define('TRUCK_FUEL_TYPES', ['Diesel', 'Gasoline', 'LPG', 'Electric']);

define('INCIDENT_TYPES', ['Vehicle Breakdown', 'Item Damage', 'Delay', 'Other']);

// Matches the `status` ENUM on the trips table exactly (db/Mulawin_DB-Phase 1).
define('TRIP_STATUSES', ['Loading', 'In Transit', 'Unloading', 'Completed', 'Cancelled']);

// Dispatch request approval status (separate ENUM, different table).
define('DISPATCH_REQUEST_STATUSES', ['Pending', 'Approved', 'Rejected']);

// Matches the maintenance record's truck_status ENUM.
define('MAINTENANCE_TRUCK_STATUSES', ['Operational', 'Scheduled Maintenance', 'Under Repair']);

// Matches the billing/trip_costs `status` ENUM.
define('BILLING_STATUSES', ['Unpaid', 'Partial', 'Paid']);

// Trip statuses that mean a trip is no longer active/editable.
define('TRIP_TERMINAL_STATUSES', ['Completed', 'Cancelled']);

// Announcement priority levels (announcement_handler.php).
define('ANNOUNCEMENT_PRIORITIES', ['high', 'medium', 'low']);

// Budget planning categories (budgets_handler.php).
define('BUDGET_CATEGORIES', ['Revenue', 'Maintenance', 'Fuel', 'Toll', 'Driver Allowance', 'Other', 'Payroll']);

// Collection/payment modes for recording billing payments (billing_handler.php).
define('PAYMENT_MODES', ['Cash', 'Check', 'Bank Transfer', 'GCash', 'Other']);

// Uploadable document categories (document_handler.php).
define('DOCUMENT_TYPES', [
    'OR/CR', 'Delivery Receipt', 'Waybill',
    'Maintenance Record', 'Billing Record',
    'Company Document', 'Other',
]);

// Maintenance record types (maintenance_handler.php).
define('MAINTENANCE_TYPES', ['Preventive', 'Corrective', 'Inspection']);

// Vehicle inspection viewpoints and part conditions (maintenance_handler.php).
define('INSPECTION_VIEWS', ['Front', 'Side', 'Rear', 'Top']);
define('INSPECTION_CONDITIONS', ['Good', 'Needs Attention', 'Damaged', 'Missing', 'Leaking', 'Worn', 'Not Checked']);

// Parts inventory movement types (parts_handler.php).
define('PARTS_MOVEMENT_TYPES', ['Stock In', 'Stock Out', 'Adjustment']);

// Trip expense categories (trip_costs_handler.php).
define('TRIP_EXPENSE_TYPES', ['Fuel', 'Toll', 'Driver Allowance', 'Other']);