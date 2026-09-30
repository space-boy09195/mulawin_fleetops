<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/reporting.php';

requireAnyPermission(['billing.view', 'billing.manage']);

// Reuses the same named period buckets as pages/billing.php so the
// downloaded CSV always matches whatever the user is currently looking at.
$periods = [
    'today' => 'Today',
    '1w'    => 'This Week',
    '1m'    => 'This Month',
    '3m'    => 'Last 3 Months',
    '6m'    => 'Last 6 Months',
    '1y'    => 'Last 12 Months',
    'all'   => 'All Time',
];
$period = $_GET['period'] ?? 'all';
if (!isset($periods[$period])) {
    $period = 'all';
}
$rangeStart = match ($period) {
    'today' => new DateTime('today'),
    '1w'    => (new DateTime('today'))->modify('-6 days'),
    '1m'    => (new DateTime('today'))->modify('-1 months'),
    '3m'    => (new DateTime('today'))->modify('-3 months'),
    '6m'    => (new DateTime('today'))->modify('-6 months'),
    '1y'    => (new DateTime('today'))->modify('-12 months'),
    default => null,
};
$rangeStartSql = $rangeStart ? $rangeStart->format('Y-m-d 00:00:00') : null;

$pdo = getDBConnection();
$sql = "
    SELECT
        b.billing_number, b.invoice_number, b.invoice_date, t.trip_number,
        b.client_name, b.amount, vs.total_collected, vs.balance,
        b.due_date, b.status, u.full_name AS created_by_name, b.created_at
    FROM billings b
    JOIN trips t ON b.trip_id    = t.trip_id
    JOIN users u ON b.created_by = u.user_id
    JOIN v_billing_summary vs ON b.billing_id = vs.billing_id
    WHERE 1=1 " . ($rangeStartSql ? 'AND b.created_at >= :rangeStart' : '') . "
    ORDER BY b.created_at DESC
";
$stmt = $pdo->prepare($sql);
if ($rangeStartSql) {
    $stmt->bindValue(':rangeStart', $rangeStartSql);
}
$stmt->execute();
$rows = static function () use ($stmt): Generator {
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        yield $row;
    }
};

streamCsvReport(
    'fleetops-ar-billings-' . $period . '.csv',
    ['Billing No.', 'Invoice No.', 'Invoice Date', 'Trip', 'Client', 'Amount', 'Collected', 'Balance',
     'Due Date', 'Status', 'Created By', 'Created At'],
    $rows()
);
