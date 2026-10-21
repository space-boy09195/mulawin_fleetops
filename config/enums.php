<?php
// ============================================================
// config/enums.php
// Single source of truth for fixed sets of allowed values.
// Previously these lists (truck statuses, fuel types, etc.)
// were re-declared inline in multiple ajax/*.php files, which
// risks drifting out of sync with each other and with the DB.
// Update here once; every handler picks up the change.
//
// Values match the target schema (db/reference/mulawin_fleetops_new.sql)
// plus the approved drafts in db/target_migrations/. A value that is not in
// the column's ENUM is rejected by MariaDB/MySQL in strict mode, so every
// list below must stay a subset of its column definition.
// ============================================================

// trucks.status. 'Assigned' = reserved for an approved trip (the legacy
// 'Deployed'); 'On Trip' = departed; 'Unavailable' = the legacy 'Inactive'.
define('TRUCK_STATUSES', ['Available', 'Assigned', 'On Trip', 'Under Maintenance', 'Unavailable']);
// Statuses an operator may set by hand; Assigned / On Trip are owned by the
// trip workflow.
define('TRUCK_MANUAL_STATUSES', ['Available', 'Under Maintenance', 'Unavailable']);
// trucks.truck_type / trips.trip_type / client_rates.truck_type.
define('TRUCK_CATEGORIES', ['Car Carrier', 'Container', 'Wing Van']);

// trucks.fuel_type is VARCHAR(40) in the target; the list is an app rule.
define('TRUCK_FUEL_TYPES', ['Diesel', 'Gasoline', 'LPG', 'Electric']);

// trip_incidents.incident_type and severity.
define('INCIDENT_TYPES', ['Breakdown', 'Accident', 'Delay', 'Damage', 'Missing Equipment', 'Other']);
define('INCIDENT_SEVERITIES', ['Low', 'Medium', 'High', 'Critical']);

// trips.status (target ENUM, in workflow order).
define('TRIP_STATUSES', [
    'Pending Approval', 'Approved', 'Assigned', 'Ready for Dispatch', 'Dispatched',
    'In Progress', 'Delivered', 'Returned', 'Completed', 'Cancelled', 'For Review',
]);

// dispatch_requests.status.
define('DISPATCH_REQUEST_STATUSES', ['Pending', 'Approved', 'Rejected', 'Cancelled']);

// client_bookings.status (Operations Head dispatch instructions).
define('BOOKING_STATUSES', ['Pending', 'Confirmed', 'Completed', 'Cancelled']);

// maintenance_records.status.
define('MAINTENANCE_STATUSES', ['Reported', 'Scheduled', 'In Progress', 'Waiting for Parts', 'Completed', 'Cancelled']);
// Legacy maintenance form value -> truck status it implies (the target keeps
// the truck's state only on trucks.status).
define('MAINTENANCE_TRUCK_STATUSES', ['Operational', 'Scheduled Maintenance', 'Under Repair']);

// billings.status.
define('BILLING_STATUSES', ['Draft', 'Issued', 'Partially Paid', 'Paid', 'Overdue', 'Cancelled']);

// Trip statuses that mean a trip is no longer active/editable.
define('TRIP_TERMINAL_STATUSES', ['Completed', 'Cancelled']);

// Announcement priority levels (announcement_handler.php, T-D1).
define('ANNOUNCEMENT_PRIORITIES', ['high', 'medium', 'low']);

// Announcement audiences (announcement_handler.php, T-D1).
define('ANNOUNCEMENT_AUDIENCES', ['all', 'maintenance', 'accounting', 'operations']);

// Budget planning categories (budgets_handler.php, T-B1).
define('BUDGET_CATEGORIES', ['Revenue', 'Maintenance', 'Fuel', 'Toll', 'Driver Allowance', 'Other', 'Payroll']);

// collections.payment_method / disbursements.payment_method (target ENUM).
// The legacy 'GCash' mode has no target value: record it as 'Other' with the
// e-wallet reference in reference_number.
define('PAYMENT_MODES', ['Cash', 'Check', 'Bank Transfer', 'Other']);

// Uploadable document categories (documents.document_category, VARCHAR).
define('DOCUMENT_TYPES', [
    'OR/CR', 'Delivery Receipt', 'Waybill', 'GPS Report',
    'Maintenance Record', 'Billing Record',
    'Company Document', 'Other',
]);

// maintenance_records.maintenance_type (legacy 'Corrective' = 'Repair').
define('MAINTENANCE_TYPES', ['Preventive', 'Repair', 'Inspection', 'Other']);

// Vehicle inspection viewpoints and part conditions (maintenance forms).
// Conditions are mapped to inspection_findings.result: 'Good' -> Good,
// 'Not Checked' -> N/A, anything else -> Flagged (condition kept in
// condition_description).
define('INSPECTION_VIEWS', ['Front', 'Side', 'Rear', 'Top']);
define('INSPECTION_CONDITIONS', ['Good', 'Needs Attention', 'Damaged', 'Missing', 'Leaking', 'Worn', 'Not Checked']);
define('INSPECTION_STAGES', ['Departure', 'Arrival']);

// inventory_transactions.transaction_type.
define('PARTS_MOVEMENT_TYPES', ['Stock In', 'Stock Out', 'Adjustment', 'Return']);

// Trip expense categories (expense_records.expense_type with T-F1).
define('TRIP_EXPENSE_TYPES', ['Fuel', 'Toll', 'Driver Allowance', 'Other']);
