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

// Objective 1.10: fifteen-minute minimum. The value is read from the config
// source (config/app.php is not loaded: it reads .env).
$appConfigSource = (string)file_get_contents($root . '/config/app.php');
check('config minimum inspection time is 900 s',
    (bool)preg_match("/define\('MAINTENANCE_FORM_MIN_DURATION_SECONDS',\s*900\)/", $appConfigSource));
if (!defined('MAINTENANCE_FORM_MIN_DURATION_SECONDS')
    && preg_match("/define\('MAINTENANCE_FORM_MIN_DURATION_SECONDS',\s*(\d+)\)/", $appConfigSource, $minMatch)) {
    define('MAINTENANCE_FORM_MIN_DURATION_SECONDS', (int)$minMatch[1]);
}
same('a 300 s template cannot lower the minimum', 900, minimumInspectionSeconds(['minimum_duration_seconds' => 300]));
same('a longer template minimum applies', 1200, minimumInspectionSeconds(['minimum_duration_seconds' => 1200]));
same('general inspection uses the config minimum', 900, minimumInspectionSeconds(null));
$_SESSION = [];
$token900 = startInspectionFormTimer('inspection_departure', 5);
throws('submission before 900 s is refused', fn() => consumeInspectionFormTimer($token900, 'inspection_departure', 5, minimumInspectionSeconds(null)), DomainException::class);
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

// ── expiry_reminders.php tiers (objectives 1.3 / 2.7) ───────────────────────
// The include pulls in config/database.php (reads .env), so only the three
// pure functions are loaded from the source.
$reminderSource = (string)file_get_contents($root . '/includes/expiry_reminders.php');
preg_match_all('/^function (?:expiryReminderTier|parseExpiryReminderThresholds|expiryDaysPhrase)\b.*?^}/ms', $reminderSource, $reminderFunctions);
check('reminder tier functions found', count($reminderFunctions[0]) === 3);
foreach ($reminderFunctions[0] as $functionSource) {
    eval($functionSource);
}
$tiers = [60, 30, 15, 7];
same('reminder: 30 days left uses the 30-day tier', 30, expiryReminderTier(30, $tiers));
same('reminder: missed 30-day run still sends the 30-day tier at 28 days', 30, expiryReminderTier(28, $tiers));
same('reminder: 10 days left uses the 15-day tier', 15, expiryReminderTier(10, $tiers));
same('reminder: 3 days left uses the 7-day tier', 7, expiryReminderTier(3, $tiers));
same('reminder: outside every threshold', null, expiryReminderTier(61, $tiers));
same('reminder: already expired', null, expiryReminderTier(-1, $tiers));
same('reminder: on the expiry date without a 0 threshold', 7, expiryReminderTier(0, $tiers));
same('reminder: 0 threshold notifies on the expiry date', 0, expiryReminderTier(0, [30, 7, 0]));
same('reminder thresholds parsed and de-duplicated', [60, 30, 7, 0], parseExpiryReminderThresholds('60, 30, 7, 7, 0'));
throws('reminder threshold above 365 refused', fn() => parseExpiryReminderThresholds('400'), RuntimeException::class);
throws('reminder threshold text refused', fn() => parseExpiryReminderThresholds('soon'), RuntimeException::class);
same('reminder phrase today', 'today', expiryDaysPhrase(0));
same('reminder phrase days', 'in 12 days', expiryDaysPhrase(12));
// Part warranties: advance tiers plus the expiry-date notice (decision E5).
preg_match('/^function withExpiryDateNotice\b.*?^}/ms', $reminderSource, $expiryNoticeFunction);
check('expiry-date notice function found', isset($expiryNoticeFunction[0]));
eval($expiryNoticeFunction[0] ?? '');
$partTiers = withExpiryDateNotice($tiers);
same('part warranty: 0 tier added to the advance thresholds', [60, 30, 15, 7, 0], $partTiers);
same('part warranty: 0 tier not duplicated', [30, 0], withExpiryDateNotice([30, 0]));
same('part warranty: 3 days left still uses the 7-day tier', 7, expiryReminderTier(3, $partTiers));
same('part warranty: expiry date gets its own 0-day notice', 0, expiryReminderTier(0, $partTiers));
same('part warranty: already expired gets nothing', null, expiryReminderTier(-1, $partTiers));

// ── approval_workflow.php: self-role routing (decision E2) ─────────────────
// The engine and notification helper are loaded from source without their
// config/database.php include (which reads .env) and run against an
// in-memory fake PDO. No database is touched. Unknown SQL throws, so a new
// query cannot pass unnoticed.
foreach (['includes/notifications.php' => 'createNotification', 'includes/approval_workflow.php' => 'createApprovalRequest'] as $engineFile => $engineFunction) {
    if (!function_exists($engineFunction)) {
        $engineSource = (string)file_get_contents($root . '/' . $engineFile);
        eval(preg_replace(['/^<\?php/', '/^require_once [^;]+;\s*$/m'], '', $engineSource));
    }
}

final class ApprovalFakeStatement extends PDOStatement {
    private array $rows = [];
    private int $affected = 0;
    public function __construct(private ApprovalFakePdo $db, private string $sql) {}
    public function execute(?array $params = null): bool {
        [$this->rows, $this->affected] = $this->db->run($this->sql, $params ?? []);
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return array_shift($this->rows) ?? false;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $rows = $this->rows;
        $this->rows = [];
        return $mode === PDO::FETCH_COLUMN ? array_map(static fn(array $row) => reset($row), $rows) : $rows;
    }
    public function fetchColumn(int $column = 0): mixed {
        $row = array_shift($this->rows);
        return $row === null ? false : array_values($row)[$column];
    }
    public function rowCount(): int { return $this->affected; }
}

final class ApprovalFakePdo extends PDO {
    public array $workflows = [];      // workflow_key => row
    public array $template = [];       // workflow_id => [[step_number, approver_role_id]]
    public array $roles = [];          // role_id => [system_key, is_active]
    public array $users = [];          // user_id => [role_id, is_active]
    public array $reviewRoles = [];    // role IDs holding approvals.review
    public array $requests = [];
    public array $steps = [];
    public array $history = [];
    public array $notifications = [];
    public array $sqlLog = [];
    public bool $failNotifications = false;
    private int $lastId = 0;
    private ?array $snapshot = null;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new ApprovalFakeStatement($this, preg_replace('/\s+/', ' ', trim($query)));
    }
    public function lastInsertId(?string $name = null): string|false { return (string)$this->lastId; }
    public function beginTransaction(): bool {
        $this->snapshot = [$this->requests, $this->steps, $this->history, $this->notifications];
        return true;
    }
    public function commit(): bool { $this->snapshot = null; return true; }
    public function rollBack(): bool {
        [$this->requests, $this->steps, $this->history, $this->notifications] = $this->snapshot;
        $this->snapshot = null;
        return true;
    }
    public function inTransaction(): bool { return $this->snapshot !== null; }

    /** Returns [rows, affected row count] for one statement. */
    public function run(string $sql, array $p): array {
        $this->sqlLog[] = $sql;
        if (str_contains($sql, 'FROM approval_workflows WHERE workflow_key = ?')) {
            $row = $this->workflows[$p[0]] ?? null;
            return [$row ? [$row] : [], 0];
        }
        if (str_contains($sql, 'FROM approval_workflow_steps WHERE workflow_id = ?')) {
            return [$this->template[$p[0]] ?? [], 0];
        }
        if ($sql === 'SELECT role_id FROM users WHERE user_id = ?') {
            return [isset($this->users[$p[0]]) ? [['role_id' => $this->users[$p[0]][0]]] : [], 0];
        }
        if ($sql === "SELECT role_id FROM roles WHERE system_key = 'admin' AND is_active = 1") {
            foreach ($this->roles as $id => [$key, $active]) {
                if ($key === 'admin' && $active) return [[['role_id' => $id]], 0];
            }
            return [[], 0];
        }
        if (str_contains($sql, "SELECT 1 FROM role_permissions rp") && str_contains($sql, "'approvals.review'")) {
            return [in_array((int)$p[0], $this->reviewRoles, true) ? [[1]] : [], 0];
        }
        if (str_contains($sql, 'SELECT DISTINCT u.user_id')) {
            $ids = [];
            foreach ($this->users as $id => [$roleId, $active]) {
                if ($active && $roleId === (int)$p[0] && in_array($roleId, $this->reviewRoles, true)) $ids[] = ['user_id' => $id];
            }
            return [$ids, 0];
        }
        if (str_contains($sql, 'SELECT ar.approval_request_id FROM approval_requests ar')) {
            return [[], 0];
        }
        if (str_starts_with($sql, 'INSERT INTO approval_requests')) {
            $this->lastId = count($this->requests) + 1;
            $this->requests[$this->lastId] = ['workflow_id' => $p[0], 'requested_by' => $p[3], 'status' => 'Pending'];
            return [[], 1];
        }
        if (str_starts_with($sql, 'INSERT INTO approval_steps')) {
            $id = count($this->steps) + 1;
            $this->steps[$id] = ['approval_step_id' => $id, 'approval_request_id' => $p[0], 'step_number' => $p[1], 'approver_role_id' => $p[2], 'status' => 'Pending'];
            return [[], 1];
        }
        if (str_starts_with($sql, 'INSERT INTO approval_history')) {
            $this->history[] = $p;
            return [[], 1];
        }
        if (str_starts_with($sql, 'INSERT INTO notifications')) {
            if ($this->failNotifications) throw new PDOException('notification insert failed (test)');
            $this->notifications[] = ['user_id' => $p[0], 'message' => $p[3]];
            return [[], 1];
        }
        if ($sql === 'SELECT approval_request_id, status FROM approval_requests WHERE approval_request_id = ? FOR UPDATE') {
            $row = $this->requests[$p[0]] ?? null;
            return [$row ? [['approval_request_id' => $p[0], 'status' => $row['status']]] : [], 0];
        }
        if (str_contains($sql, 'SELECT approval_step_id, step_number, approver_role_id FROM approval_steps')) {
            foreach ($this->steps as $step) {
                if ($step['approval_request_id'] === $p[0] && $step['status'] === 'Pending') {
                    return [[['approval_step_id' => $step['approval_step_id'], 'step_number' => $step['step_number'], 'approver_role_id' => $step['approver_role_id']]], 0];
                }
            }
            return [[], 0];
        }
        if (str_starts_with($sql, 'UPDATE approval_steps SET status = ?, acted_by = ?')) {
            $this->steps[$p[3]]['status'] = $p[0];
            return [[], 1];
        }
        if (str_starts_with($sql, 'UPDATE approval_requests SET status = ?')) {
            $this->requests[$p[1]]['status'] = $p[0];
            return [[], 1];
        }
        throw new LogicException('Unexpected SQL in approval test: ' . $sql);
    }
}

// Fixture: roles 1 admin, 2 operations_head, 3 dispatcher, 7 finance (IDs
// chosen to differ from production so nothing depends on fixed IDs). The
// fund_request template is shown as it would be after D1 (Finance).
$approvalFixture = static function (): ApprovalFakePdo {
    $db = new ApprovalFakePdo();
    $db->roles = [11 => ['admin', 1], 12 => ['operations_head', 1], 13 => ['car_carrier_dispatcher', 1], 17 => ['finance', 1]];
    $db->users = [101 => [11, 1], 102 => [12, 1], 103 => [13, 1], 107 => [17, 1]];
    $db->reviewRoles = [11, 12, 17];
    $db->workflows = [
        'fund_request'    => ['workflow_id' => 4, 'workflow_key' => 'fund_request', 'workflow_name' => 'Fund Request', 'entity_type' => 'fund_request'],
        'payment_voucher' => ['workflow_id' => 5, 'workflow_key' => 'payment_voucher', 'workflow_name' => 'Payment Voucher', 'entity_type' => 'payment_voucher'],
        'dispatch'        => ['workflow_id' => 1, 'workflow_key' => 'dispatch', 'workflow_name' => 'Dispatch', 'entity_type' => 'dispatch_request'],
    ];
    $db->template = [
        4 => [['step_number' => 1, 'approver_role_id' => 17]],
        5 => [['step_number' => 1, 'approver_role_id' => 11]],
        1 => [['step_number' => 1, 'approver_role_id' => 12]],
    ];
    return $db;
};
$stepRole = static fn(ApprovalFakePdo $db, int $approvalId): int => (int)current(array_filter($db->steps, static fn($s) => $s['approval_request_id'] === $approvalId))['approver_role_id'];
$notified = static fn(ApprovalFakePdo $db): array => array_column($db->notifications, 'user_id');

// 1. A non-Finance requester (dispatcher) follows the configured approver role.
$db = $approvalFixture();
$id = createApprovalRequest($db, 'fund_request', 'fund_request', 501, 103, null, $routing);
same('E2: dispatcher request stays on the configured Finance step', 17, $stepRole($db, $id));
same('E2: dispatcher request is not rerouted', null, $routing);
same('E2: dispatcher request notifies Finance reviewers', [107], $notified($db));

// 2 + 4. A Finance requester is routed to Admin, and Admin is notified.
$db = $approvalFixture();
$id = createApprovalRequest($db, 'fund_request', 'fund_request', 502, 107, null, $routing);
same('E2: Finance requester routed to the Admin role', 11, $stepRole($db, $id));
same('E2: routing reports configured and routed roles', [17, 11], [$routing['configured_role_id'] ?? null, $routing['routed_role_id'] ?? null]);
same('E2: routed request notifies Admin only (not Finance)', [101], $notified($db));
same('E2: routing reports who was notified', [101], $routing['notified_user_ids'] ?? null);
check('E2: Admin notification says why it was routed', str_contains((string)($db->notifications[0]['message'] ?? ''), 'Routed to Admin'));

// 3. The global workflow template is never changed.
same('E2: fund_request template still assigns Finance', 17, $db->template[4][0]['approver_role_id']);
check('E2: no statement writes approval_workflow_steps', !array_filter($db->sqlLog, static fn($s) => preg_match('/^(UPDATE|INSERT INTO|DELETE FROM) approval_workflow_steps/', $s)));

// 5. Missing Admin role, missing permission or missing requester fail safely.
$db = $approvalFixture();
$db->roles[11][1] = 0;
throws('E2: inactive Admin role refuses a routed request', fn() => createApprovalRequest($db, 'fund_request', 'fund_request', 503, 107), RuntimeException::class);
same('E2: nothing written when no Admin role exists', [0, 0, 0], [count($db->requests), count($db->steps), count($db->notifications)]);
$db = $approvalFixture();
$db->reviewRoles = [12, 17];
throws('E2: Admin without approvals.review refuses a routed request', fn() => createApprovalRequest($db, 'fund_request', 'fund_request', 504, 107), RuntimeException::class);
same('E2: nothing written when Admin lacks approvals.review', [0, 0], [count($db->requests), count($db->steps)]);
$db = $approvalFixture();
throws('E2: unknown requester account is refused', fn() => createApprovalRequest($db, 'fund_request', 'fund_request', 505, 999), RuntimeException::class);

// 6. The requester cannot approve the routed request through the Finance role.
$db = $approvalFixture();
$id = createApprovalRequest($db, 'fund_request', 'fund_request', 506, 107);
throws('E2: Finance cannot decide a request routed to Admin', fn() => recordApprovalDecision($db, $id, 'Approved', 107, 17), DomainException::class);
same('E2: routed step still pending after the refused attempt', 'Pending', $db->requests[$id]['status']);
$decisionHandler = (string)file_get_contents($root . '/ajax/approval_decision_handler.php');
check('E2: decision handler still blocks deciding your own request', str_contains($decisionHandler, "if (\$ownRequest && !isAdmin()) {") && str_contains($decisionHandler, "jsonFail('You cannot decide your own request.', 403);"));
// The ordinary Admin path decides the routed step without an override.
try {
    $override = ['not reset'];
    $status = recordApprovalDecision($db, $id, 'Approved', 101, 11, null, true, null, $override);
} catch (Throwable $e) {
    $status = get_class($e) . ': ' . $e->getMessage();
}
same('E2: Admin decides the routed step as its own step', 'Approved', $status);
same('E2: routed decision is not an override', null, $override);

// 7. Admin self-review and other-role decisions still need an audited reason.
check('E2: Admin self-review still requires a reason', (bool)preg_match("/if \(\\\$ownRequest\) \{.*'restriction' => 'self_review'.*if \(\\\$overrideReason === null\) \{.*adminOverrideRefused.*422\);.*adminOverrideBegin/s", $decisionHandler));
check('E2: Admin self-review is written to the override audit', str_contains($decisionHandler, "'self_review' => \$selfOverride"));
$db = $approvalFixture();
$id = createApprovalRequest($db, 'fund_request', 'fund_request', 507, 103);
throws('E2: Admin deciding a Finance step without a reason is refused', fn() => recordApprovalDecision($db, $id, 'Approved', 101, 11, null, true), AdminOverrideReasonRequired::class);
// An Admin's own request is not rerouted (Finance reviews it after D1; before
// D1 it stays an Admin step and the self-review rule above applies).
$id = createApprovalRequest($db, 'fund_request', 'fund_request', 508, 101, null, $routing);
same('E2: Admin-created request keeps the Finance step', [17, null], [$stepRole($db, $id), $routing]);
$db = $approvalFixture();
$db->template[4] = [['step_number' => 1, 'approver_role_id' => 11]];
$id = createApprovalRequest($db, 'fund_request', 'fund_request', 509, 101, null, $routing);
same('E2: before D1, an Admin request stays on the Admin step', [11, null], [$stepRole($db, $id), $routing]);
$id = createApprovalRequest($db, 'fund_request', 'fund_request', 510, 107, null, $routing);
same('E2: before D1, a Finance request is not rerouted (template is Admin)', [11, null], [$stepRole($db, $id), $routing]);

// 8. Dispatchers may request but not approve or disburse.
$db = $approvalFixture();
$id = createApprovalRequest($db, 'fund_request', 'fund_request', 511, 103);
throws('E2: a role without approvals.review cannot decide', fn() => recordApprovalDecision($db, $id, 'Approved', 103, 13), DomainException::class);
$financeHandler = (string)file_get_contents($root . '/ajax/finance_handler.php');
check('E2: allowance-only access is limited to create_fund_request', str_contains($financeHandler, "\$tripAllowanceRequestOnly = \$action === 'create_fund_request'"));
check('E2: disbursement still requires finance.disbursements.manage', (bool)preg_match("/if \(\\\$action === 'create_disbursement'\) \{\s*requirePermission\('finance.disbursements.manage'\);/", $financeHandler));
check('E2: disbursement still limited to Approved or Processing', str_contains($financeHandler, "!in_array(\$fund['status'], ['Approved', 'Processing'], true)"));
check('E2: decisions still require approvals.review', str_contains($decisionHandler, "requirePermission('approvals.review');"));

// 9. A failed notification rolls the whole request back.
$db = $approvalFixture();
$db->failNotifications = true;
$db->beginTransaction();
try {
    createApprovalRequest($db, 'fund_request', 'fund_request', 512, 107);
    check('E2: notification failure propagates', false, 'no exception');
} catch (PDOException $e) {
    $db->rollBack();
    check('E2: notification failure propagates', true);
}
same('E2: nothing remains after the rollback', [0, 0, 0, 0], [count($db->requests), count($db->steps), count($db->history), count($db->notifications)]);
check('E2: fund request handler rolls back on any error', (bool)preg_match("/finance_handler\/create_fund_request/", $financeHandler) && str_contains($financeHandler, "if (\$pdo->inTransaction()) \$pdo->rollBack();"));

// 10. Other workflows keep their configured routing.
$db = $approvalFixture();
$id = createApprovalRequest($db, 'payment_voucher', 'payment_voucher', 601, 107, null, $routing);
same('E2: payment voucher keeps the Admin step', [11, null], [$stepRole($db, $id), $routing]);
$id = createApprovalRequest($db, 'dispatch', 'dispatch_request', 602, 102, null, $routing);
same('E2: dispatch keeps its role even when the requester holds it', [12, null], [$stepRole($db, $id), $routing]);
check('E2: other workflows do not look up the requester role', !in_array('SELECT role_id FROM users WHERE user_id = ?', $db->sqlLog, true));

// ── Report ────────────────────────────────────────────────────────────────
echo "UNIT TESTS (STATIC, PHP " . PHP_VERSION . ", no database): {$passed} passed, " . count($failed) . " failed\n";
foreach ($failed as $failure) {
    echo "  FAIL: $failure\n";
}
exit($failed ? 1 : 0);
