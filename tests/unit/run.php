<?php
// ============================================================
// tests/unit/run.php
// Focused unit tests for pure helpers (no database, no session bootstrap,
// no network). Run from the repository root:
//   php tests/unit/run.php
// Exit code 0 = all passed. Environment label: STATIC/UNIT (PHP only).
// These tests do NOT exercise MariaDB; database behaviour is covered by
// docs/testing/mariadb_integration_checklist.md (to run against the
// isolated test database after approval).
// ============================================================

declare(strict_types=1);

$root = dirname(__DIR__, 2);

// Minimal stand-ins for session helpers used by the inspection timers.
if (!function_exists('currentUserId')) {
    function currentUserId(): int { return (int)($GLOBALS['__test_user'] ?? 7); }
}
$_SESSION = [];

require_once $root . '/includes/metrics.php';
require_once $root . '/includes/finance_records.php';
require_once $root . '/includes/client_rates.php';
require_once $root . '/includes/recruitment.php';
require_once $root . '/includes/soft_delete.php';
require_once $root . '/includes/trip_workflow.php';
require_once $root . '/includes/inspections.php';
require_once $root . '/includes/analytics_period.php';
require_once $root . '/includes/dashboard_metrics.php';

$passed = 0;
$failed = [];

function check(string $name, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
    } else {
        $failed[] = $name . ($detail !== '' ? ' — ' . $detail : '');
    }
}

function same(string $name, mixed $expected, mixed $actual): void {
    check($name, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function throws(string $name, callable $fn, string $class): void {
    try {
        $fn();
        check($name, false, "no $class thrown");
    } catch (Throwable $e) {
        check($name, $e instanceof $class, 'threw ' . get_class($e) . ': ' . $e->getMessage());
    }
}

// ── metrics.php ────────────────────────────────────────────────────────────
same('metricRate 3/4', 75.0, metricRate(3, 4));
same('metricRate 1/3 rounds to 1 dp', 33.3, metricRate(1, 3));
same('metricRate 2/3 precision 0', 67.0, metricRate(2, 3, 0));
same('metricRate zero denominator is null (not 0, no division)', null, metricRate(0, 0));
same('metricRate negative denominator is null', null, metricRate(5, -1));
same('metricRate can exceed 100 (collections of earlier billings)', 150.0, metricRate(3, 2));
throws('metricAlias rejects injection', fn() => metricAlias('t; DROP TABLE trips'), InvalidArgumentException::class);
same('metricSqlList escapes quotes', "'O''Brien','A'", metricSqlList(["O'Brien", 'A']));
check('late = measurable and arrival after schedule', str_contains(tripLateSql('t'), '> t.scheduled_arrival') && str_contains(tripLateSql('t'), 't.scheduled_arrival IS NOT NULL'));
check('on-time = measurable and arrival not after schedule', str_contains(tripOnTimeSql('x'), '<= x.scheduled_arrival'));
check('overdue only for active statuses', str_contains(tripOverdueSql('t'), "'In Progress'") && !str_contains(tripOverdueSql('t'), "'Delivered'"));
check('delivered statuses', str_contains(tripDeliveredSql('t'), "'Delivered','Returned','Completed'"));

// ── finance_records.php ────────────────────────────────────────────────────
same('trip pay notes compose', 'Paid 2026-10-01. Night run', tripPayComposeNotes('2026-10-01', 'Night run'));
same('trip pay notes compose without notes', 'Paid 2026-10-01', tripPayComposeNotes('2026-10-01', null));
same('trip pay notes round trip', ['paid_date' => '2026-10-01', 'notes' => 'Night run'], tripPaySplitNotes(tripPayComposeNotes('2026-10-01', 'Night run')));
same('trip pay notes round trip without notes', ['paid_date' => '2026-10-01', 'notes' => null], tripPaySplitNotes('Paid 2026-10-01'));
same('trip pay legacy notes kept', ['paid_date' => null, 'notes' => 'free text'], tripPaySplitNotes('free text'));
same('trip pay null notes', ['paid_date' => null, 'notes' => null], tripPaySplitNotes(null));
same('trip pay notes capped at 255 chars', 255, mb_strlen(tripPayComposeNotes('2026-10-01', str_repeat('é', 400)), 'UTF-8'));
same('expense description falls back to type', 'Fuel', tripExpenseDescription('Fuel', null, ''));
same('expense description joins other + notes', 'Tires — paid cash', tripExpenseDescription('Other', 'Tires', 'paid cash'));
check('operating expenses exclude maintenance / parts / payroll rows',
    str_contains(operatingExpenseSql('er'), "'Driver Allowance'")
    && !str_contains(operatingExpenseSql('er'), "'Maintenance'")
    && !str_contains(operatingExpenseSql('er'), "'Payroll'")
    && !str_contains(operatingExpenseSql('er'), "'Parts'"));
check('revenue excludes Draft and Cancelled billings',
    !str_contains(billingRevenueSql('b'), "'Draft'") && !str_contains(billingRevenueSql('b'), "'Cancelled'"));
check('collected subquery uses its own alias', str_contains(billingCollectedSql('b'), 'collections cpay') && str_contains(billingCollectedSql('b'), 'cpay.billing_id = b.billing_id'));
check('Driver Allowance is not a Trip Pay type', !in_array('Driver Allowance', TRIP_PAY_TYPES, true));

// ── client_rates.php (known values) ────────────────────────────────────────
same('route rate is the flat amount', 1500.46, clientRateTripAmount(['rate_type' => 'Route', 'rate_amount' => '1500.456'], 80.0));
same('fixed rate is the flat amount', 900.0, clientRateTripAmount(['rate_type' => 'Fixed', 'rate_amount' => '900'], null));
same('distance rate = rate x km', 3060.0, clientRateTripAmount(['rate_type' => 'Distance', 'rate_amount' => '120'], 25.5));
same('distance rate without distance cannot be computed', null, clientRateTripAmount(['rate_type' => 'Distance', 'rate_amount' => '120'], null));
same('distance rate with zero distance cannot be computed', null, clientRateTripAmount(['rate_type' => 'Distance', 'rate_amount' => '120'], 0.0));
same('unknown rate type', null, clientRateTripAmount(['rate_type' => 'Weight', 'rate_amount' => '1'], 10.0));

// ── recruitment.php ────────────────────────────────────────────────────────
same('candidate number', 'APP-000012', recruitmentCandidateNumber(12));
same('candidate number parse', 12, recruitmentIdFromNumber('APP-000012'));
same('candidate number parse rejects other text', null, recruitmentIdFromNumber('APP-12; DROP'));
same('recruitment notes round trip',
    ['email' => 'a@example.com', 'source' => 'Referral', 'notes' => "Line 1\nLine 2"],
    recruitmentSplitNotes(recruitmentComposeNotes('a@example.com', 'Referral', "Line 1\nLine 2")));
same('recruitment notes empty', null, recruitmentComposeNotes(null, '', null));
same('recruitment legacy notes', ['email' => null, 'source' => null, 'notes' => 'Walk-in'], recruitmentSplitNotes('Walk-in'));

// ── soft_delete.php payload formats ────────────────────────────────────────
same('legacy archive payload', ['row' => ['id' => 1], 'children' => []], softDeletePayload('{"id":1}'));
same('format 2 archive payload',
    ['row' => ['id' => 2], 'children' => ['billing_items' => [['id' => 9]]]],
    softDeletePayload('{"__format":2,"row":{"id":2},"children":{"billing_items":[{"id":9}]}}'));
same('invalid archive payload', null, softDeletePayload('not json'));
check('soft delete refuses tables outside the allowlist', (function (): bool {
    try { softDeleteConfig('users; DROP'); return false; } catch (Throwable $e) { return true; }
})());

// ── trip_workflow.php delivery notes parsing ───────────────────────────────
$values = tripDeliveryFormValues(
    ['delivery_notes' => "Left at gate\nDelivery receipt: DR-55\nDelivered units: 12", 'waybill_number' => 'WB-1', 'received_by' => 'Ana'],
    ['return_notes' => "Empty return\nReturn location: Yard 2"]
);
same('delivery receipt parsed', 'DR-55', $values['delivery_receipt_number'] ?? null);
same('delivered units parsed', '12', $values['delivered_unit_count'] ?? null);
same('delivery notes without labelled lines', 'Left at gate', $values['delivery_notes']);
same('return location parsed', 'Yard 2', $values['return_location']);
same('return notes without labelled line', 'Empty return', $values['return_notes']);
same('waybill kept', 'WB-1', $values['shared_waybill_reference']);
same('no delivery and no return', null, tripDeliveryFormValues(null, null));

// ── inspections.php session timer ──────────────────────────────────────────
$_SESSION = [];
$GLOBALS['__test_user'] = 7;
$token = startInspectionFormTimer('checklist', 42);
check('timer token is 64 hex chars', (bool)preg_match('/^[a-f0-9]{64}$/', $token));
throws('timer enforces minimum duration', fn() => consumeInspectionFormTimer($token, 'checklist', 42, 300), DomainException::class);
throws('timer bound to its target', fn() => consumeInspectionFormTimer($token, 'checklist', 43, 0), DomainException::class);
throws('timer bound to its form type', fn() => consumeInspectionFormTimer($token, 'inspection_general', 42, 0), DomainException::class);
$GLOBALS['__test_user'] = 8;
throws('timer bound to its user', fn() => consumeInspectionFormTimer($token, 'checklist', 42, 0), DomainException::class);
$GLOBALS['__test_user'] = 7;
$started = consumeInspectionFormTimer($token, 'checklist', 42, 0);
check('timer consumed once', $started > 0);
throws('timer cannot be reused', fn() => consumeInspectionFormTimer($token, 'checklist', 42, 0), DomainException::class);
restoreInspectionFormTimer($token, 'checklist', 42, $started);
same('restored timer usable again', $started, consumeInspectionFormTimer($token, 'checklist', 42, 0));
throws('malformed token rejected', fn() => consumeInspectionFormTimer('abc', 'checklist', 42, 0), DomainException::class);
for ($i = 0; $i < 30; $i++) startInspectionFormTimer('checklist', $i);
check('open timers capped', count($_SESSION['inspection_timers']) <= INSPECTION_TIMER_MAX_OPEN);
throws('timer rejects unknown form type', fn() => startInspectionFormTimer('bogus', 1), InvalidArgumentException::class);

// ── analytics_period.php (known values) ────────────────────────────────────
$month = buildBuckets(new DateTime('2026-01-15'), new DateTime('2026-03-02'), 'month');
same('month buckets', ['2026-01', '2026-02', '2026-03'], $month['keys']);
same('month labels', ['Jan 2026', 'Feb 2026', 'Mar 2026'], $month['labels']);
$week = buildBuckets(new DateTime('2026-10-14'), new DateTime('2026-10-27'), 'week');
same('week buckets start on Monday', ['2026-10-12', '2026-10-19', '2026-10-26'], $week['keys']);
same('day buckets inclusive', 7, count(buildBuckets(new DateTime('2026-10-01'), new DateTime('2026-10-07'), 'day')['keys']));
same('bucket SQL month', "DATE_FORMAT(b.billing_date, '%Y-%m')", bucketExpr('b.billing_date', 'month'));
same('pctChange +10%', 10.0, pctChange(110.0, 100.0));
same('pctChange -25%', -25.0, pctChange(75.0, 100.0));
same('pctChange 0 -> 0 is flat', 0.0, pctChange(0.0, 0.0));
same('pctChange from 0 has no baseline', null, pctChange(5.0, 0.0));
check('rising cost is shown as unfavourable', str_contains(trendBadge(120, 100, false), 'an-trend-down') && str_contains(trendBadge(120, 100, false), '+20.0%'));
check('rising revenue is shown as favourable', str_contains(trendBadge(120, 100, true), 'an-trend-up'));
same('no badge without comparison', '', trendBadge(120, 100, true, false));
same('no badge when value unavailable', '', trendBadgePts(null, 80.0));
check('rate points difference', str_contains(trendBadgePts(85.0, 80.0), '+5.0 pts'));
same('rate text unavailable', '—', rateText(null));
same('rate text whole', '75%', rateText(75.0));
same('rate text decimal', '33.3%', rateText(33.3));
same('rate class unavailable is neutral', '', rateClass(null));
same('rate class good', 'an-value-green', rateClass(90.0));
same('rate class poor', 'an-value-red', rateClass(10.0));

// ── dashboard_metrics.php ──────────────────────────────────────────────────
$keys = dashboardMonthKeys(6);
same('6 month keys', 6, count($keys['keys']));
same('last month key is the current month', date('Y-m'), end($keys['keys']));
same('month keys clamp to 36', 36, count(dashboardMonthKeys(500)['keys']));
throws('unknown monthly series rejected', fn() => dashboardMonthlySeries(new class extends PDO { public function __construct() {} }, 'bogus', 3), InvalidArgumentException::class);
same('truck statuses are the target ENUM', ['Available', 'Assigned', 'On Trip', 'Under Maintenance', 'Unavailable'], TRUCK_STATUSES);

// ── Document expiry: "Expiry date − current date" ─────────────────────────
$d0 = new DateTimeImmutable('2026-10-14');
same('expiry 10 days ahead', 10, daysUntilExpiry('2026-10-24', $d0));
same('expiry today is 0', 0, daysUntilExpiry('2026-10-14', $d0));
same('expired 3 days ago is -3', -3, daysUntilExpiry('2026-10-11', $d0));
same('expiry across a year end', 18, daysUntilExpiry('2027-01-01', new DateTimeImmutable('2026-12-14')));
same('datetime value uses its date part', 1, daysUntilExpiry('2026-10-15 23:59:00', $d0));
same('time of day of "today" is ignored', 1, daysUntilExpiry('2026-10-15', new DateTimeImmutable('2026-10-14 23:30:00')));
same('no expiry date is null', null, daysUntilExpiry(null, $d0));
same('invalid expiry date is null', null, daysUntilExpiry('not a date', $d0));
same('expiry text: days left', '1 day left', expiryDaysText(1));
same('expiry text: today', 'expires today', expiryDaysText(0));
same('expiry text: expired', 'expired 2 days ago', expiryDaysText(-2));
same('expiry text: none', '', expiryDaysText(null));

// ── Report ────────────────────────────────────────────────────────────────
echo "UNIT TESTS (STATIC, PHP " . PHP_VERSION . ", no database): {$passed} passed, " . count($failed) . " failed\n";
foreach ($failed as $failure) {
    echo "  FAIL: $failure\n";
}
exit($failed ? 1 : 0);
