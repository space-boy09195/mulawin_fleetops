<?php
// ============================================================
// tests/static/helper_statements.php
// SQL produced by shared helper functions, written out as literal
// statements for scripts/schema_check.php (used by tests/static/run.php).
// Only function-definition includes are loaded; no database connection.
// Usage: php helper_statements.php <out.php>
// ============================================================
$root = dirname(__DIR__, 2);
require_once $root . '/includes/metrics.php';
require_once $root . '/includes/finance_records.php';
require_once $root . '/includes/trip_queries.php';
require_once $root . '/includes/employee_profile.php';
require_once $root . '/includes/truck_records.php';
require_once $root . '/includes/dashboard_metrics.php';

$sqls = [
    'tripSelect'      => tripSelectSql() . ' WHERE t.trip_id = ?',
    'tripMetrics'     => 'SELECT COUNT(*) FROM trips t WHERE ' . tripLateSql('t') . ' AND ' . tripOnTimeSql('t') . ' AND ' . tripOverdueSql('t'),
    'employeeName'    => 'SELECT ' . employeeNameSql('e') . ' AS n FROM employees e WHERE e.employee_id = ?',
    'tripMonitor'     => 'SELECT ' . tripSelectColumnsSql() . ', EXISTS (SELECT 1 FROM trip_incidents i WHERE i.trip_id = t.trip_id) AS has_problem '
                         . tripFromSql() . ' WHERE ' . tripOperationalSql('t', 'dr') . ' AND t.helper_id = ?',
    'tripExport'      => "SELECT t.trip_number, c.client_name, cb.booking_reference, td.waybill_number, cb.quantity, " . tripArrivalSql('t') . " AS a
                          FROM trips t LEFT JOIN dispatch_requests dr ON dr.trip_id = t.trip_id JOIN clients c ON c.client_id = t.client_id
                          LEFT JOIN client_bookings cb ON cb.booking_id = t.booking_id LEFT JOIN trip_deliveries td ON td.trip_id = t.trip_id
                          WHERE " . tripOperationalSql('t', 'dr') . ' AND ' . tripLateSql('t'),
    'truckProfile'    => 'SELECT ' . truckProfileColumnsSql() . ' FROM trucks t WHERE t.truck_id = ?',
    'billingStatus'   => 'SELECT ' . billingDisplayStatusSql('b') . ', ' . billingCollectedSql('b') . ' FROM billings b WHERE ' . billingRevenueSql('b'),
    'maintenanceDate' => 'SELECT ' . maintenanceRecordDateSql('mr') . ' FROM maintenance_records mr',
    'tripPayDate'     => 'SELECT ' . tripPayDateSql('t') . ' FROM trip_pay_records tp JOIN trips t ON t.trip_id = tp.trip_id',
    'payroll'         => 'SELECT ' . payrollPaidDateSql('pr') . ', ' . payrollNotesSql('pr') . ' FROM payroll_records pr',
    'operatingExp'    => 'SELECT SUM(er.amount) FROM expense_records er WHERE ' . operatingExpenseSql('er'),
    'maintenanceDue'  => dashboardMaintenanceDueSql(),
];
$php = "<?php\n";
foreach ($sqls as $name => $sql) {
    $php .= '$' . $name . ' = $pdo->prepare(' . var_export(trim(preg_replace('/\s+/', ' ', $sql)), true) . ");\n";
}
file_put_contents($argv[1], $php);
echo 'captured ' . count($sqls) . " helper statements\n";
