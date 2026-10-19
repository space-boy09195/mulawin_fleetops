<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/reporting.php';
require_once __DIR__ . '/../includes/finance_records.php';

// Exporting needs billing.export; viewing or managing billing is not enough.
requirePermission('billing.export');

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

$rangeStartDate = $rangeStart ? $rangeStart->format('Y-m-d') : null;

// Same rows and definitions as pages/billing.php (includes/finance_records.php).
$pdo = getDBConnection();
$sql = "
    SELECT
        b.invoice_number, b.billing_date, t.trip_number,
        c.client_name, b.total_amount, " . billingCollectedSql('b') . " AS total_collected,
        b.total_amount - " . billingCollectedSql('b') . " AS balance,
        b.due_date, " . billingDisplayStatusSql('b') . " AS status,
        COALESCE(ue.full_name, u.username) AS created_by_name, b.created_at
    FROM billings b
    JOIN trips t   ON b.trip_id    = t.trip_id
    JOIN clients c ON c.client_id  = b.client_id
    JOIN users u   ON b.created_by = u.user_id
    LEFT JOIN employees ue ON ue.employee_id = u.employee_id
    WHERE b.status NOT IN ('Draft', 'Cancelled') " . ($rangeStartDate ? 'AND b.billing_date >= :rangeStart' : '') . "
    ORDER BY b.billing_date DESC, b.billing_id DESC
";
$stmt = $pdo->prepare($sql);
if ($rangeStartDate) {
    $stmt->bindValue(':rangeStart', $rangeStartDate);
}
$stmt->execute();
$rows = static function () use ($stmt): Generator {
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        yield $row;
    }
};

streamCsvReport(
    'fleetops-ar-billings-' . $period . '.csv',
    ['Invoice No.', 'Invoice Date', 'Trip', 'Client', 'Amount', 'Collected', 'Balance',
     'Due Date', 'Status', 'Created By', 'Created At'],
    $rows()
);
