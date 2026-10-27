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
require_once $root . '/includes/home_tasks.php';

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
    public array $references = [];   // entity_id => record label (approvalEntityReference)
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
            $this->requests[$this->lastId] = ['workflow_id' => $p[0], 'entity_type' => $p[1], 'entity_id' => $p[2], 'requested_by' => $p[3], 'status' => 'Pending'];
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
            $this->notifications[] = ['user_id' => $p[0], 'type' => $p[1], 'title' => $p[2], 'message' => $p[3], 'entity_id' => $p[5], 'is_read' => 0];
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
        if (str_starts_with($sql, 'SELECT CONCAT(')) {
            return [isset($this->references[$p[0]]) ? [[$this->references[$p[0]]]] : [], 0];
        }
        if ($sql === 'SELECT entity_type, entity_id FROM approval_requests WHERE approval_request_id = ?') {
            $row = $this->requests[$p[0]] ?? null;
            return [$row ? [['entity_type' => $row['entity_type'], 'entity_id' => $row['entity_id']]] : [], 0];
        }
        if (str_starts_with($sql, 'UPDATE notifications SET is_read = 1')) {
            $count = 0;
            foreach ($this->notifications as &$n) {
                if (!$n['is_read'] && $n['type'] === $p[0] && $n['entity_id'] === $p[1] && in_array($n['title'], array_slice($p, 2), true)) {
                    $n['is_read'] = 1;
                    $count++;
                }
            }
            unset($n);
            return [[], $count];
        }
        if (str_starts_with($sql, "UPDATE approval_requests SET status = 'Cancelled'")) {
            $hit = ($this->requests[$p[0]]['status'] ?? null) === 'Pending';
            if ($hit) $this->requests[$p[0]]['status'] = 'Cancelled';
            return [[], $hit ? 1 : 0];
        }
        if (str_starts_with($sql, "UPDATE approval_steps SET status = 'Skipped'")) {
            return [[], 0];
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

// ── dispatcher_scope.php: truck type decides, not shift (2026-10-26) ───────
// The scope code is loaded from source without its session/database
// includes and runs against a fake PDO that answers the two scope queries
// with the rows the database would return. No database is touched.
// dispatcherScopeResult() caches per request, so every scenario loads its
// own copy in a separate namespace (fresh cache).
function currentRoleKey(): ?string { return $GLOBALS['__scope']['role']; }
function isAdmin(): bool { return $GLOBALS['__scope']['role'] === 'admin'; }
function currentRoleId(): int { return 99; }
function currentUserHasAnyPermission(array $keys): bool { return (bool)array_intersect($keys, $GLOBALS['__scope']['permissions']); }
function getDBConnection(): PDO { return new ScopeFakePdo(); }

final class ScopeFakeStatement extends PDOStatement {
    private array $rows = [];
    public function __construct(private string $sql) {}
    public function execute(?array $params = null): bool {
        $scope = $GLOBALS['__scope'];
        if ($this->sql === 'SELECT employee_id FROM users WHERE user_id = ?') {
            $this->rows = [['employee_id' => $scope['employee_id']]];
        } elseif (str_contains($this->sql, 'FROM dispatcher_assignments')) {
            $this->rows = $scope['assignments'];
        } else {
            throw new RuntimeException('Unexpected scope SQL: ' . $this->sql);
        }
        return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column = 0): mixed {
        $row = array_shift($this->rows);
        return $row === null ? false : array_values($row)[$column];
    }
}

final class ScopeFakePdo extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new ScopeFakeStatement(preg_replace('/\s+/', ' ', trim($query)));
    }
}

$scopeSource = preg_replace(['/^<\?php/', '/^require_once [^;]+;\s*$/m'], '', (string)file_get_contents($root . '/includes/dispatcher_scope.php'));
$scopeCopy = 0;
// Sets up one signed-in user and returns the namespace of a fresh copy.
$scopeScenario = static function (string $role, array $assignments = [], int $employeeId = 30, array $permissions = []) use ($scopeSource, &$scopeCopy): string {
    $GLOBALS['__scope'] = ['role' => $role, 'assignments' => $assignments, 'employee_id' => $employeeId, 'permissions' => $permissions];
    $namespace = 'ScopeCopy' . (++$scopeCopy);
    eval("namespace $namespace; use PDO, Throwable, InvalidArgumentException;" . $scopeSource);
    return $namespace . '\\';
};
$carDay = [['dispatcher_role_type' => 'CAR_CARRIER', 'shift' => 'Day']];
$carNight = [['dispatcher_role_type' => 'CAR_CARRIER', 'shift' => 'Night']];
$containerNight = [['dispatcher_role_type' => 'CONTAINER_WING_VAN', 'shift' => 'Night']];
$wrongTypeMessage = 'This dispatcher account cannot encode the selected truck category.';
$noAssignmentMessage = 'This dispatcher account does not have one active dispatcher assignment for this date. Ask an administrator to check the assignment.';
$previousErrorLog = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

// Car Carrier dispatcher assigned to Day: Night Car Carrier is now allowed.
$ns = $scopeScenario('car_carrier_dispatcher', $carDay);
same('scope: Day Car Carrier dispatcher may handle a Night Car Carrier trip', null, ($ns . 'dispatcherScopeViolation')('Night', 'Car Carrier', '2026-10-26'));
same('scope: Day Car Carrier dispatcher may handle a Day Car Carrier trip', null, ($ns . 'dispatcherScopeViolation')('Day', 'Car Carrier', '2026-10-26'));
same('scope: Car Carrier dispatcher refused a Container trip', $wrongTypeMessage, ($ns . 'dispatcherScopeViolation')('Night', 'Container', '2026-10-26'));
same('scope: Car Carrier dispatcher refused a Wing Van trip', $wrongTypeMessage, ($ns . 'dispatcherScopeViolation')('Day', 'Wing Van', '2026-10-26'));
same('scope: page filter view has truck types and no shift', ['truck_types' => ['Car Carrier']], ($ns . 'dispatcherScope')());
same('scope: assignment shift still reported for Dispatcher Reports', 'Day', ($ns . 'dispatcherScopeResult')()['shift']);
$scopeParams = [];
same('scope: list filter limits truck type only', ' AND tr.truck_type IN (:dscopeType0)', ($ns . 'dispatcherScopeReadSql')('tr.truck_type', 't.shift', $scopeParams));
same('scope: list filter parameters', [':dscopeType0' => 'Car Carrier'], $scopeParams);
throws('scope: list filter still rejects an unsafe column', fn() => ($ns . 'dispatcherScopeReadSql')('tr.truck_type', 't.shift; DROP', $scopeParams), InvalidArgumentException::class);

// Car Carrier dispatcher assigned to Night: Day Car Carrier is allowed.
$ns = $scopeScenario('car_carrier_dispatcher', $carNight);
same('scope: Night Car Carrier dispatcher may handle a Day Car Carrier trip', null, ($ns . 'dispatcherScopeViolation')('Day', 'Car Carrier', '2026-10-26'));

// Container & Wing Van dispatcher: both of its types, either shift; never Car Carrier.
$ns = $scopeScenario('container_wing_van_dispatcher', $containerNight);
same('scope: Container dispatcher may handle a Day Container trip', null, ($ns . 'dispatcherScopeViolation')('Day', 'Container', '2026-10-26'));
same('scope: Container dispatcher may handle a Night Wing Van trip', null, ($ns . 'dispatcherScopeViolation')('Night', 'Wing Van', '2026-10-26'));
same('scope: Container dispatcher refused a Car Carrier trip', $wrongTypeMessage, ($ns . 'dispatcherScopeViolation')('Night', 'Car Carrier', '2026-10-26'));

// Denied dispatchers stay refused on every shift and see nothing.
$ns = $scopeScenario('car_carrier_dispatcher', []);
same('scope: dispatcher without an active assignment refused (Day)', $noAssignmentMessage, ($ns . 'dispatcherScopeViolation')('Day', 'Car Carrier', '2026-10-26'));
same('scope: dispatcher without an active assignment refused (Night)', $noAssignmentMessage, ($ns . 'dispatcherScopeViolation')('Night', 'Car Carrier', '2026-10-27'));
$scopeParams = [];
same('scope: denied dispatcher list filter matches nothing', ' AND 1 = 0', ($ns . 'dispatcherScopeReadSql')('tr.truck_type', 't.shift', $scopeParams));
same('scope: denied page filter view stays denied', ['truck_types' => [''], 'denied' => true], ($ns . 'dispatcherScope')());
$ns = $scopeScenario('car_carrier_dispatcher', [$carDay[0], $carNight[0]]);
same('scope: two applicable assignments refused', $noAssignmentMessage, ($ns . 'dispatcherScopeViolation')('Day', 'Car Carrier', '2026-10-26'));
$ns = $scopeScenario('car_carrier_dispatcher', $containerNight);
same('scope: assignment type not matching the role refused', $noAssignmentMessage, ($ns . 'dispatcherScopeViolation')('Night', 'Container', '2026-10-26'));
$ns = $scopeScenario('car_carrier_dispatcher', $carDay, 0);
same('scope: dispatcher without a linked employee refused', $noAssignmentMessage, ($ns . 'dispatcherScopeViolation')('Day', 'Car Carrier', '2026-10-26'));
$ns = $scopeScenario('finance', [], 30, ['trips.create']);
same('scope: unrecognized role holding dispatch permissions refused', $noAssignmentMessage, ($ns . 'dispatcherScopeViolation')('Day', 'Car Carrier', '2026-10-26'));

// Roles without a dispatcher scope are unchanged.
foreach (['admin', 'operations_head', 'finance'] as $unscopedRole) {
    $ns = $scopeScenario($unscopedRole);
    $scopeParams = [];
    same("scope: $unscopedRole has no dispatcher scope", [null, '', []], [
        ($ns . 'dispatcherScopeViolation')('Night', 'Container', '2026-10-26'),
        ($ns . 'dispatcherScopeReadSql')('tr.truck_type', 't.shift', $scopeParams),
        ($ns . 'dispatcherScope')(),
    ]);
}
ini_set('error_log', $previousErrorLog === false ? '' : $previousErrorLog);

// Rules this change must keep, and the report left out of it.
$submitDispatch = (string)file_get_contents($root . '/ajax/submit_dispatch.php');
check('scope: encoding still must match the instruction shift', str_contains($submitDispatch, "|| \$booking['shift'] !== \$shift"));
check('scope: encoding still enforces the truck-type scope', str_contains($submitDispatch, "enforceDispatcherScope(\$shift, (string)\$truck['truck_type']"));
$dispatchInbox = (string)file_get_contents($root . '/pages/dispatch_inbox.php');
check('scope: dispatch inbox no longer filters by shift', !str_contains($dispatchInbox, 'i.shift = ?'));
check('scope: dispatch inbox still filters by truck type', str_contains($dispatchInbox, "' AND i.truck_type IN ('"));
$dispatcherReports = (string)file_get_contents($root . '/pages/dispatcher_reports.php');
check('scope: Dispatcher Reports calculations unchanged (assignment shift)', str_contains($dispatcherReports, 't.shift = :scopeShift') && str_contains($dispatcherReports, 'b.shift = da.shift'));

// ── trip_workflow.php: "What should I do next?" guide ──────────────────────
$guideOwner = ['is_owner' => true, 'is_admin' => false, 'can_assign' => true, 'can_update' => true];
$guideAdmin = ['is_owner' => false, 'is_admin' => true, 'can_review' => true, 'can_assign' => true, 'can_update' => true, 'can_clear' => true, 'can_inspect' => false];
$guideOpsHead = ['is_owner' => false, 'is_admin' => false, 'can_review' => true, 'can_assign' => true, 'can_clear' => true];
$guideMaintenance = ['is_owner' => false, 'is_admin' => false, 'can_inspect' => true];
$guideRole = static fn(array $next): array => [$next['role'], $next['can_act']];
$dispatcherRole = 'Dispatcher who submitted this trip';

same('guide: pending approval → Operations Head can act', ['Operations Head', 'yes'], $guideRole(tripNextAction('Pending Approval', 4, $guideOpsHead)));
same('guide: pending approval → Admin acts as override', ['Operations Head', 'override'], $guideRole(tripNextAction('Pending Approval', 4, $guideAdmin)));
same('guide: pending approval → dispatcher waits', ['Operations Head', 'no'], $guideRole(tripNextAction('Pending Approval', 4, $guideOwner)));
same('guide: approved → owner confirms (step 5)', [$dispatcherRole, 'yes'], $guideRole(tripNextAction('Approved', 4, $guideOwner)));
check('guide: approved → names the Step 5 button', str_contains(tripNextAction('Approved', 4, $guideOwner)['where'], 'Confirm truck and driver'));
same('guide: approved → Operations Head is not the owner', [$dispatcherRole, 'no'], $guideRole(tripNextAction('Approved', 4, $guideOpsHead)));
same('guide: approved → Admin only as override', [$dispatcherRole, 'override'], $guideRole(tripNextAction('Approved', 4, $guideAdmin)));
same('guide: another dispatcher (not owner) cannot act', [$dispatcherRole, 'no'], $guideRole(tripNextAction('Assigned', 5, ['is_owner' => false, 'is_admin' => false, 'can_assign' => true])));
same('guide: assigned step 5 → owner prepares (step 6)', [$dispatcherRole, 'yes'], $guideRole(tripNextAction('Assigned', 5, $guideOwner)));
same('guide: pending allowance is a step 6 blocker', ['Trip Allowance request FR-1 is awaiting approval. Step 6 can be recorded after it is approved or rejected.'],
    tripNextAction('Assigned', 5, $guideOwner + ['pending_allowance' => 'FR-1'])['blockers']);
same('guide: no allowance → no blockers', [], tripNextAction('Assigned', 5, $guideOwner)['blockers']);
$guideDeparture = tripNextAction('Assigned', 6, $guideMaintenance + ['departure' => ['required' => 32, 'covered' => 8, 'flagged' => 0]]);
same('guide: assigned step 6 → Maintenance inspects', ['Maintenance', 'yes'], $guideRole($guideDeparture));
same('guide: departure blocker from saved inspection counts', ['Departure checks: 8 of 32 required items passed.'], $guideDeparture['blockers']);
same('guide: departure step links to the Pre-Departure Inspection', ['pre_departure'], $guideDeparture['links']);
same('guide: return step links to the Return Inspection', ['return_inspection'], tripNextAction('Delivered', 11, $guideMaintenance)['links']);
same('guide: Admin is not told to inspect', ['Maintenance', 'no'], $guideRole(tripNextAction('Assigned', 6, $guideAdmin + ['departure' => ['required' => 32, 'covered' => 0, 'flagged' => 0]])));
same('guide: missing departure template is a blocker', ['No active departure inspection template is configured.'],
    tripNextAction('Assigned', 6, $guideMaintenance + ['departure' => ['required' => 0, 'covered' => 0, 'flagged' => 0]])['blockers']);
same('guide: ready for dispatch → Operations Head clears', ['Operations Head', 'yes'], $guideRole(tripNextAction('Ready for Dispatch', 7, $guideOpsHead)));
same('guide: ready for dispatch → dispatcher waits', ['Operations Head', 'no'], $guideRole(tripNextAction('Ready for Dispatch', 7, $guideOwner)));
$guideDispatched = tripNextAction('Dispatched', 8, $guideOwner);
same('guide: dispatched → owner records departure', [$dispatcherRole, 'yes'], $guideRole($guideDispatched));
check('guide: dispatched → In Progress through Trip Monitoring', str_contains($guideDispatched['where'], 'Trip Monitoring') && str_contains($guideDispatched['where'], '“In Progress”'));
same('guide: dispatched → Operations Head cannot update status', [$dispatcherRole, 'no'], $guideRole(tripNextAction('Dispatched', 8, $guideOpsHead)));
check('guide: in progress step 9 → Step 10 card', str_contains(tripNextAction('In Progress', 9, $guideOwner)['where'], 'Step 10'));
check('guide: in progress step 10 → Step 11 card', str_contains(tripNextAction('In Progress', 10, $guideOwner)['where'], 'Step 11'));
check('guide: in progress step 11 → mark Delivered', str_contains(tripNextAction('In Progress', 11, $guideOwner)['where'], '“Delivered”'));
$guideArrival = tripNextAction('Delivered', 11, $guideMaintenance + ['arrival' => ['required' => 24, 'covered' => 0, 'flagged' => 0]]);
same('guide: delivered → Maintenance return inspection', ['Maintenance', 'yes'], $guideRole($guideArrival));
same('guide: arrival blocker from saved inspection counts', ['Arrival inspection: 0 of 24 required items recorded.'], $guideArrival['blockers']);
check('guide: returned → mark Completed', str_contains(tripNextAction('Returned', 12, $guideOwner)['where'], '“Completed”'));
$guideCompleted = tripNextAction('Completed', 13, $guideOwner);
same('guide: completed → no responsible role', [null, 'no'], $guideRole($guideCompleted));
check('guide: completed → Billing & Collection and Payroll reminder', str_contains((string)$guideCompleted['note'], 'Billing & Collection') && str_contains((string)$guideCompleted['note'], 'Payroll'));
same('guide: completed → Billing and Trip Pay links', ['billing', 'trip_pay'], $guideCompleted['links']);
same('guide: cancelled → no action', [null, 'no'], $guideRole(tripNextAction('Cancelled', 4, $guideOwner)));
$guideMismatch = tripNextAction('Approved', 7, $guideOwner);
same('guide: status and step mismatch → no guessed action', [null, 'no'], $guideRole($guideMismatch));
check('guide: mismatch tells the user to ask an Admin', str_contains((string)$guideMismatch['note'], 'Ask an Admin'));
$guideTexts = '';
foreach ([['Pending Approval', 4], ['Approved', 4], ['Assigned', 5], ['Assigned', 6], ['Ready for Dispatch', 7], ['Dispatched', 8],
          ['In Progress', 9], ['In Progress', 10], ['In Progress', 11], ['Delivered', 11], ['Returned', 12], ['Completed', 13]] as [$guideStatus, $guideStep]) {
    $guideNext = tripNextAction($guideStatus, $guideStep, $guideOwner);
    $guideTexts .= $guideNext['where'] . ' ' . $guideNext['note'] . ' ' . implode(' ', $guideNext['blockers']);
}
check('guide: no percentage anywhere', !str_contains($guideTexts, '%'));

// Step 6 inline upload reuses the existing document handler.
$workflowPage = (string)file_get_contents($root . '/pages/trip_workflow.php');
$workflowJs = (string)file_get_contents($root . '/assets/js/trip_workflow.js');
check('upload: Step 6 no longer sends users to Documents to upload', !str_contains($workflowPage, 'Upload or review trip documents'));
check('upload: "Open in Documents" link kept', str_contains($workflowPage, 'Open in Documents'));
check('upload: button gated on documents.upload in Step 6', str_contains($workflowPage, "\$canUploadAttachment = \$showDeparturePreparation && currentUserHasAnyPermission(['documents.upload']);"));
check('upload: document type must be chosen', (bool)preg_match('/<select[^>]*id="tripAttachmentType"[^>]*required/', $workflowPage) && str_contains($workflowPage, '<option value="">— Choose a document type —</option>'));
check('upload: posts to the existing document handler with action upload', str_contains($workflowJs, '/ajax/document_handler.php') && str_contains($workflowJs, "body.append('action', 'upload');"));
check('upload: sends an idempotency key and CSRF token', str_contains($workflowJs, 'fleetOpsIdempotencyHeaders(scope)') && str_contains($workflowJs, 'window.CSRF_TOKEN_NAME, window.CSRF_TOKEN'));
check('upload: does not call the trip workflow handler (Step 6 not recorded)', (bool)preg_match("/form\.id !== 'tripAttachmentForm'(?:(?!trip_workflow_handler).)*document_handler\.php/s", $workflowJs));
check('upload: attachment list keeps the Prepared visibility rule', str_contains($workflowPage, "AND COALESCE(d.visibility_scope, 'all') IN ('all', 'operations')"));
$documentHandler = (string)file_get_contents($root . '/ajax/document_handler.php');
check('upload: handler still requires documents.upload', str_contains($documentHandler, "requirePermission('documents.upload');"));
$prepareHandler = (string)file_get_contents($root . '/ajax/trip_workflow_handler.php');

// ── Step 6: optional attachments (interim policy, 2026-10-26) ─────────────
preg_match("/elseif \(\\\$action === 'prepare_departure'\) \{(.*?)\} elseif \(\\\$action === 'departure_clearance'\)/s", $prepareHandler, $prepareBlock);
$prepareBlock = $prepareBlock[1] ?? '';
check('step 6: prepare_departure block found', $prepareBlock !== '');
check('step 6: no packet status is required', !str_contains($prepareBlock, 'documents_status') && !str_contains($prepareBlock, 'documents_notes'));
check('step 6: no document upload or reason is required', !str_contains($prepareBlock, 'Upload at least one trip document') && !str_contains($prepareBlock, 'State why no additional trip documents'));
// Used exactly three times: assigned, then written to the event and the audit.
check('step 6: attachment count is not a condition', preg_match_all('/\$attachmentCount\b/', $prepareBlock) === 3);
check('step 6: event records the attachment count', (bool)preg_match("/\\\$packet = json_encode\(\[\s*'attachment_count'\s*=> \\\$attachmentCount,/", $prepareBlock));
check('step 6: audit records the attachment count', (bool)preg_match("/\\\$auditDetails = \[\s*'attachment_count' => \\\$attachmentCount,/", $prepareBlock));
check('step 6: workflow order check kept', str_contains($prepareBlock, "if (\$trip['status'] !== 'Assigned' || \$currentStep !== 5) {"));
check('step 6: allowance rules kept', str_contains($prepareBlock, "requiredEnum('allowance_status', ['Approved', 'Not Required'], 'Allowance status')")
    && str_contains($prepareBlock, 'An active Trip Allowance request is linked to this trip.')
    && str_contains($prepareBlock, 'State why a trip allowance is not required.'));
check('step 6: owner and permission checks kept', str_contains($prepareHandler, "'prepare_departure' => 'trips.assign',")
    && str_contains($prepareHandler, 'Only the dispatcher who submitted this trip can complete this workflow action.'));
check('step 6: page has no packet dropdown', !str_contains($workflowPage, 'documents_status') && !str_contains($workflowPage, 'id="documentsStatus"'));
check('step 6: script no longer handles packet fields', !str_contains($workflowJs, 'documentsStatus') && !str_contains($workflowJs, 'documentsNotes'));
same('step 6: new event text shows the attachment count', 'Attachments: 0. Allowance: Not Required — No allowance for this trip.',
    tripPreparationEventText(['attachment_count' => 0, 'allowance_status' => 'Not Required', 'allowance_fund_request_id' => null, 'allowance_notes' => 'No allowance for this trip']));
same('step 6: new event text with an approved allowance', 'Attachments: 2. Allowance: Approved.',
    tripPreparationEventText(['attachment_count' => 2, 'allowance_status' => 'Approved', 'allowance_fund_request_id' => 5, 'allowance_notes' => null]));
same('step 6: old packet-status events stay readable', 'Documents: Not Required — Client sends papers. Allowance: Approved.',
    tripPreparationEventText(['documents_status' => 'Not Required', 'documents_notes' => 'Client sends papers', 'allowance_status' => 'Approved']));
same('step 6: incomplete old events do not fail', 'Documents: —. Allowance: —.', tripPreparationEventText([]));

// Independent checks this batch must not weaken.
$maintenanceHandler = (string)file_get_contents($root . '/ajax/maintenance_handler.php');
$statusHandler = (string)file_get_contents($root . '/ajax/update_trip_status.php');
$workflowLib = (string)file_get_contents($root . '/includes/trip_workflow.php');
check('kept: inspections still need status Assigned and step 6', str_contains($maintenanceHandler, ": (\$trip['status'] === 'Assigned' && \$trip['step'] >= 6);"));
check('kept: Ready for Dispatch still needs step 6 and passed departure checks', str_contains($workflowLib, "if (tripWorkflowCurrentStep(\$pdo, \$tripId) < 6 || !tripDepartureChecksPassed(\$pdo, \$tripId)) {"));
check('kept: departure still needs clearance', str_contains($statusHandler, 'Operations Head dispatch clearance is required before the truck can leave.'));
check('kept: departure still needs the departure inspection', str_contains($statusHandler, 'A complete departure vehicle inspection with every required item in good condition is required before the truck can leave.'));

// ── Departure: a required Trip Allowance must be fully disbursed ──────────
$allowanceRow = static fn(string $status): array => ['request_number' => 'FR-TEST', 'status' => $status];
$allowanceMessage = 'A required trip allowance must be fully disbursed by Finance before departure. Trip Allowance request FR-TEST is ';
same('allowance: no request → departure allowed', null, tripAllowanceDepartureMessage([]));
same('allowance: Pending Approval blocks departure', $allowanceMessage . 'Pending Approval.', tripAllowanceDepartureMessage([$allowanceRow('Pending Approval')]));
same('allowance: Approved but unpaid blocks departure', $allowanceMessage . 'Approved.', tripAllowanceDepartureMessage([$allowanceRow('Approved')]));
same('allowance: Processing (partial payment) blocks departure', $allowanceMessage . 'Processing.', tripAllowanceDepartureMessage([$allowanceRow('Processing')]));
same('allowance: Disbursed → departure allowed', null, tripAllowanceDepartureMessage([$allowanceRow('Disbursed')]));
same('allowance: Rejected does not block', null, tripAllowanceDepartureMessage([$allowanceRow('Rejected')]));
same('allowance: Cancelled does not block', null, tripAllowanceDepartureMessage([$allowanceRow('Cancelled')]));
same('allowance: Rejected then Disbursed → departure allowed', null, tripAllowanceDepartureMessage([$allowanceRow('Rejected'), $allowanceRow('Disbursed')]));
same('allowance: Rejected then a new pending request still blocks', $allowanceMessage . 'Pending Approval.', tripAllowanceDepartureMessage([$allowanceRow('Rejected'), $allowanceRow('Pending Approval')]));
same('allowance: Settled is not accepted (unverifiable)', $allowanceMessage . 'Settled. A settled request cannot be verified as fully paid yet; ask an Admin to check it.',
    tripAllowanceDepartureMessage([$allowanceRow('Settled')]));

// The departure query: only this trip's Trip Allowance rows, locked.
final class AllowanceFakeStatement extends PDOStatement {
    private array $rows = [];
    public function __construct(private AllowanceFakePdo $db, private string $sql) {}
    public function execute(?array $params = null): bool {
        $this->db->log[] = [$this->sql, $params];
        $this->rows = $this->db->rowsByTrip[$params[0] ?? 0] ?? [];
        return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
final class AllowanceFakePdo extends PDO {
    public array $log = [];
    public function __construct(public array $rowsByTrip = []) {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new AllowanceFakeStatement($this, preg_replace('/\s+/', ' ', trim($query)));
    }
}
$allowanceDb = new AllowanceFakePdo([7 => [$allowanceRow('Pending Approval')], 8 => [$allowanceRow('Disbursed')]]);
same('allowance: another trip\'s pending request does not block this trip', null, tripAllowanceDepartureBlocker($allowanceDb, 8));
same('allowance: this trip\'s pending request blocks', $allowanceMessage . 'Pending Approval.', tripAllowanceDepartureBlocker($allowanceDb, 7));
same('allowance: no requests for the trip → departure allowed', null, tripAllowanceDepartureBlocker($allowanceDb, 9));
[$allowanceSql, $allowanceParams] = $allowanceDb->log[0];
check('allowance: query limited to the trip and Trip Allowance type', str_contains($allowanceSql, "WHERE trip_id = ? AND request_type = 'Trip Allowance'") && $allowanceParams === [8]);
check('allowance: query is a locking read', str_ends_with($allowanceSql, 'FOR UPDATE'));

// Transaction order in the departure transition: trip locked first, then the
// allowance rows, all before the status change, inside one transaction.
preg_match("/if \(\\\$status === 'In Progress'\) \{\s*\\\$clearance(.*?)\n    \}\n/s", $statusHandler, $departureBlock);
$departureBlock = $departureBlock[1] ?? '';
$positions = [
    strpos($statusHandler, '$pdo->beginTransaction();'),
    strpos($statusHandler, '$tripRow = lockTripForWorkflow($pdo, $tripId);'),
    strpos($statusHandler, '$allowanceBlocker = tripAllowanceDepartureBlocker($pdo, $tripId);'),
    strpos($statusHandler, 'transitionTripStatus($pdo, $tripId, [$oldStatus], $status, $extraSet);'),
    strpos($statusHandler, '$pdo->commit();'),
];
$inOrder = !in_array(false, $positions, true);
for ($i = 1; $inOrder && $i < count($positions); $i++) {
    $inOrder = $positions[$i - 1] < $positions[$i];
}
check('allowance: checked inside the departure transaction, after the trip lock and before the status change', $inOrder);
check('allowance: check is part of the In Progress (departure) checks', str_contains($departureBlock, 'tripAllowanceDepartureBlocker($pdo, $tripId)'));
check('allowance: blocked departure rolls back with 409', (bool)preg_match('/if \(\$allowanceBlocker !== null\) \{\s*\$pdo->rollBack\(\);\s*jsonFail\(\$allowanceBlocker, 409\);/', $statusHandler));
check('allowance: Finance disbursement statuses unchanged', str_contains((string)file_get_contents($root . '/ajax/finance_handler.php'), "\$newStatus = \$totalPaid >= (float)\$fund['amount'] ? 'Disbursed' : 'Processing';"));
same('guide: dispatched trip shows the allowance blocker', [$allowanceMessage . 'Approved.'],
    tripNextAction('Dispatched', 8, $guideOwner + ['allowance_departure' => tripAllowanceDepartureMessage([$allowanceRow('Approved')])])['blockers']);
same('guide: dispatched trip without allowance has no blocker', [], tripNextAction('Dispatched', 8, $guideOwner + ['allowance_departure' => null])['blockers']);

// ── Inspection timers: both Pre-Departure sections 5 min, others 15 min ────
check('config: pre-trip checklist minimum is 300 s', (bool)preg_match("/define\('PRE_TRIP_CHECKLIST_MIN_DURATION_SECONDS',\s*300\)/", $appConfigSource));
check('config: departure inspection minimum is 300 s', (bool)preg_match("/define\('DEPARTURE_INSPECTION_MIN_DURATION_SECONDS',\s*300\)/", $appConfigSource));
check('config: return and general inspection minimum is still 900 s', (bool)preg_match("/define\('MAINTENANCE_FORM_MIN_DURATION_SECONDS',\s*900\)/", $appConfigSource));
foreach (['PRE_TRIP_CHECKLIST_MIN_DURATION_SECONDS', 'DEPARTURE_INSPECTION_MIN_DURATION_SECONDS'] as $timerConstant) {
    if (!defined($timerConstant) && preg_match("/define\('" . $timerConstant . "',\s*(\d+)\)/", $appConfigSource, $timerMatch)) {
        define($timerConstant, (int)$timerMatch[1]);
    }
}
$template300 = ['minimum_duration_seconds' => 300];
same('timer: pre-trip checklist needs 5 minutes', 300, minimumInspectionSeconds($template300, 'checklist'));
same('timer: departure inspection needs 5 minutes', 300, minimumInspectionSeconds($template300, 'inspection_departure'));
same('timer: a longer departure template still applies', 900, minimumInspectionSeconds(['minimum_duration_seconds' => 900], 'inspection_departure'));
$_SESSION = [];
$GLOBALS['__test_user'] = 7;
$departureEarlyToken = startInspectionFormTimer('inspection_departure', 15);
throws('timer: departure inspection submitted before 5 minutes is refused (server clock)',
    fn() => consumeInspectionFormTimer($departureEarlyToken, 'inspection_departure', 15, minimumInspectionSeconds($template300, 'inspection_departure')), DomainException::class);
same('timer: return inspection keeps 15 minutes', 900, minimumInspectionSeconds($template300, 'inspection_return'));
same('timer: general inspection keeps 15 minutes', 900, minimumInspectionSeconds(null, 'inspection_general'));
same('timer: no form type keeps 15 minutes', 900, minimumInspectionSeconds($template300));
same('timer: a longer checklist template still applies', 600, minimumInspectionSeconds(['minimum_duration_seconds' => 600], 'checklist'));
$_SESSION = [];
$GLOBALS['__test_user'] = 7;
$checklistToken = startInspectionFormTimer('checklist', 77);
throws('timer: checklist submitted before 5 minutes is refused (server clock)',
    fn() => consumeInspectionFormTimer($checklistToken, 'checklist', 77, minimumInspectionSeconds($template300, 'checklist')), DomainException::class);
$departureToken = startInspectionFormTimer('inspection_departure', 77);
throws('timer: a departure timer cannot be used for the checklist',
    fn() => consumeInspectionFormTimer($departureToken, 'checklist', 77, 0), DomainException::class);
$maintenanceHandlerSource = (string)file_get_contents($root . '/ajax/maintenance_handler.php');
check('timer: start reports the per-form minimum', str_contains($maintenanceHandlerSource, "inspectionTemplateForForm(\$pdo, \$formType), \$formType);"));
check('timer: checklist submit enforces the checklist minimum', str_contains($maintenanceHandlerSource, "consumeInspectionFormTimer(\$timerToken, 'checklist', \$dispatchId, minimumInspectionSeconds(\$template, 'checklist'));"));
check('timer: inspection submit enforces its own form minimum', str_contains($maintenanceHandlerSource, 'consumeInspectionFormTimer($timerToken, $formType, $timerTarget, minimumInspectionSeconds($template, $formType));'));
$maintenancePage = (string)file_get_contents($root . '/pages/maintenance.php');
check('timer: checklist section countdown shows 5 minutes', (bool)preg_match('/id="checklistSection"[^>]*data-min-duration-seconds="<\?= PRE_TRIP_CHECKLIST_MIN_DURATION_SECONDS \?>"/', $maintenancePage));
check('timer: departure section countdown shows 5 minutes', (bool)preg_match('/id="inspectionSection"[^>]*data-min-duration-seconds="<\?= DEPARTURE_INSPECTION_MIN_DURATION_SECONDS \?>"/', $maintenancePage));
check('timer: general/return inspection modal keeps 15 minutes', str_contains($maintenancePage, 'id="inspectionModal" data-min-duration-seconds="<?= MAINTENANCE_FORM_MIN_DURATION_SECONDS ?>"'));
check('timer: countdown follows the server minimum', str_contains((string)file_get_contents($root . '/assets/js/maintenance.js'), 'Number(response.minimum_seconds || minimumSeconds)'));
check('kept: departure inspection still needs every part Good', str_contains($maintenanceHandlerSource, "Departure inspection cannot pass while any standard truck part needs attention."));
check('kept: checklist fails unless every item passes', str_contains($maintenanceHandlerSource, "\$result = \$allPassed ? 'Passed' : 'Failed';"));
check('kept: departure checks need all required items Good', str_contains($workflowLib, "? \"AND vi.overall_result = 'Passed' AND f.result = 'Good'\""));

// ── Maintenance views and the unified Pre-Departure Inspection ────────────
$maintenanceJs = (string)file_get_contents($root . '/assets/js/maintenance.js');
foreach (["'overview'      => ['Work to Address'", "'pre_departure' => ['Pre-Departure Inspection'", "'return'        => ['Return Inspection'"] as $viewDefinition) {
    check("maintenance: view defined: $viewDefinition", str_contains($maintenancePage, $viewDefinition));
}
check('maintenance: old departure links open the Pre-Departure Inspection', str_contains($maintenancePage, "\$requestedInspectionStage === 'Departure' => 'pre_departure',"));
check('maintenance: old arrival links open the Return Inspection', str_contains($maintenancePage, "\$requestedInspectionStage === 'Return'    => 'return',"));
check('maintenance: work page has only the three work views', !str_contains($maintenancePage, "'records'       => ['Records & History'"));
$recordsPageSource = (string)file_get_contents($root . '/pages/maintenance_records.php');
check('records page: separate page reusing the Maintenance data and markup', str_contains($recordsPageSource, "define('MAINTENANCE_RECORDS_PAGE', true);")
    && str_contains($recordsPageSource, "require __DIR__ . '/maintenance.php';"));
check('records page: old records links redirect to it (period kept)', str_contains($maintenancePage, "if (!\$isRecordsPage && ((\$_GET['view'] ?? '') === 'records' || isset(\$_GET['period']))) {")
    && str_contains($maintenancePage, "header('Location: ' . APP_BASE . '/pages/maintenance_records.php' . \$recordsQuery);"));
check('records page: period filter posts to the records page itself', !str_contains($maintenancePage, '<input type="hidden" name="view" value="records">'));
check('records page: links between the two pages', str_contains($maintenancePage, 'pages/maintenance_records.php">')
    && str_contains($maintenancePage, 'pages/maintenance.php"><i class="bi bi-arrow-left me-1"></i>Back to Maintenance work'));
check('inspect: Inspect Vehicle button removed', !str_contains($maintenancePage, 'Inspect Vehicle'));
check('inspect: General Vehicle Inspection kept on the records page only',
    (bool)preg_match("/<\?php if \(\\\$isRecordsPage\): \?>\s*<div class=\"d-flex gap-2 align-items-center flex-wrap\">.*?General Vehicle Inspection/s", $maintenancePage));
check('inspect: records page offers only General, the Return view only Return',
    str_contains($maintenancePage, "<?php if (\$isRecordsPage): ?>\n                  <option value=\"General\" selected>General Vehicle Inspection</option>\n                  <?php else: ?>\n                  <option value=\"Return\" selected>Trip Return Inspection</option>"));
check('sidebar: Maintenance work page and Maintenance History page', str_contains((string)file_get_contents($root . '/includes/layout.php'), "'label' => 'Maintenance History', 'href' => '/pages/maintenance_records.php'"));
check('home: maintenance record links open the records page', substr_count((string)file_get_contents($root . '/pages/dashboard.php'), "/pages/maintenance_records.php'") === 2);
check('maintenance: page has two switch buttons', str_contains($maintenancePage, 'data-pre-departure-switch="checklist"') && str_contains($maintenancePage, 'data-pre-departure-switch="departure"')
    && str_contains($maintenancePage, 'Pre-Trip Checklist <span class="badge') && str_contains($maintenancePage, 'Departure Inspection <span class="badge'));
check('maintenance: two sections, one shown at a time', str_contains($maintenancePage, 'data-pre-departure-section="checklist"') && str_contains($maintenancePage, 'class="mnt-table-wrap p-3 mb-4 d-none" id="inspectionSection" data-pre-departure-section="departure"'));
check('maintenance: each section shows its completion state', str_contains($maintenancePage, 'data-section-badge="checklist"') && str_contains($maintenancePage, 'data-section-badge="departure"')
    && str_contains($maintenancePage, "['Completed', 'text-bg-success']") && str_contains($maintenancePage, "['Failed · submit again', 'text-bg-danger']"));
check('maintenance: forms only for Maintenance, a ready trip and an unfinished section',
    str_contains($maintenancePage, "\$showChecklistForm = \$canEnterInspections && \$preDeparture['ready'] && !\$preDeparture['checklist']['passed'];")
    && str_contains($maintenancePage, "\$showDepartureForm = \$canEnterInspections && \$preDeparture['ready'] && !\$preDeparture['departure']['passed'];"));
check('maintenance: ready uses the handler rule (approved, Assigned, step 6)', str_contains($maintenancePage, "'ready'     => \$preDepartureTrip['status'] === 'Assigned' && \$tripStep >= 6"));
check('maintenance: checklist is tied to this trip\'s dispatch', str_contains($maintenancePage, '<option value="<?= (int)$pdTrip[\'dispatch_id\'] ?>" selected>'));
check('maintenance: departure inspection is tied to this trip and its truck', str_contains($maintenancePage, 'data-truck-id="<?= (int)$pdTruck[\'truck_id\'] ?>" data-trip-status="Loading"'));
check('maintenance: way back to Work to Address and the Trip Workflow', str_contains($maintenancePage, 'pages/maintenance.php?view=overview"><i class="bi bi-arrow-left me-1"></i>Work to Address')
    && str_contains($maintenancePage, 'pages/trip_workflow.php?trip_id=<?= (int)$pdTrip[\'trip_id\'] ?>">Trip Workflow'));
check('maintenance: old separate checklist modal and "New Checklist" button removed', !str_contains($maintenancePage, 'id="checklistModal"') && !str_contains($maintenancePage, 'New Checklist'));
check('maintenance: departure removed from the general/return inspection modal', !str_contains($maintenancePage, '<option value="Departure" <?='));
check('maintenance: inspection modal only on the records page and the Return view', str_contains($maintenancePage, "<?php if (\$isRecordsPage || \$view === 'return'): ?>\n    <!-- Interactive vehicle inspection"));
foreach (['pane-pm-schedules', 'pane-work-orders', 'pane-records', 'pane-checklists', 'pane-inspections', 'recordModal', 'workOrderModal', 'workOrderUpdateModal', 'pmScheduleModal', 'descModal'] as $keptId) {
    check("maintenance: kept $keptId", str_contains($maintenancePage, 'id="' . $keptId . '"'));
}
check('maintenance: records tabs are only on the records page', str_contains($maintenancePage, "<?php if (\$isRecordsPage): ?>\n  <!-- Tabs -->"));
check('switch: a section timer starts only the first time it is opened', str_contains($maintenanceJs, 'if (openedPreDepartureSections.has(name)) return;')
    && str_contains($maintenanceJs, "checklistTimer.start('checklist', clDispatchId.value);") && str_contains($maintenanceJs, 'beginInspectionTimer();'));
check('switch: switching never resets a timer', !preg_match('/function showPreDepartureSection[^}]*\.reset\(\)/s', $maintenanceJs));
check('switch: a submit on this view does not reload while the other section runs', str_contains($maintenanceJs, "finishPreDepartureSection('checklist', res.result === 'Passed', res.message);")
    && str_contains($maintenanceJs, "if (res.success && preDepartureRoot) finishPreDepartureSection('departure', true, res.message);")
    && str_contains($maintenanceJs, "if (badges.every(item => item.textContent.trim() === 'Completed')) {"));
check('switch: a failed checklist needs a new timer', (bool)preg_match("/if \(!passed\) \{.*?timer\.start\('checklist', clDispatchId\.value\);\s*return;/s", $maintenanceJs));
check('switch: section forms reuse the existing handlers', str_contains($maintenanceJs, "action:      'submit_checklist',") && str_contains($maintenanceJs, "action: 'save_inspection',"));
check('switch: sections are never opened as modals', str_contains($maintenanceJs, "if (location.hash === '#inspectionModal' && inspectionModal?.classList.contains('modal')) {"));
$_SESSION = [];
$checklistOnlyToken = startInspectionFormTimer('checklist', 15);
throws('timer: a checklist timer cannot be used for the departure inspection',
    fn() => consumeInspectionFormTimer($checklistOnlyToken, 'inspection_departure', 15, 0), DomainException::class);
check('kept: readiness counts every required item of all active departure templates', str_contains($workflowLib, "WHERE it.inspection_stage = ? AND it.is_active = 1\n           AND ii.is_active = 1 AND ii.is_required = 1"));
same('kept: return inspection still uses the 15-minute form', 900, minimumInspectionSeconds(['minimum_duration_seconds' => 300], 'inspection_return'));

// ── Approval notices name the record and close when decided ───────────────
$db = $approvalFixture();
$db->template[4] = [['step_number' => 1, 'approver_role_id' => 11]];
$db->users[105] = [11, 1]; // a second Admin
$db->references[601] = 'Trip Allowance FR-601 for TRP-2026-0016 (₱1,000.00)';
$id = createApprovalRequest($db, 'fund_request', 'fund_request', 601, 103);
same('approval notice: every Admin reviewer is told', [101, 105], $notified($db));
same('approval notice: names the request, trip and amount', 'Trip Allowance FR-601 for TRP-2026-0016 (₱1,000.00) is awaiting your review on Requests & Approvals.', $db->notifications[0]['message']);
$db->beginTransaction();
recordApprovalDecision($db, $id, 'Approved', 101, 11);
$db->rollBack();
same('approval notice: a rolled-back decision leaves the task open', [0, 0], array_column($db->notifications, 'is_read'));
recordApprovalDecision($db, $id, 'Approved', 101, 11);
same('approval notice: once decided, no reviewer still has it as a task', [1, 1], array_column($db->notifications, 'is_read'));
$id = createApprovalRequest($db, 'fund_request', 'fund_request', 602, 103);
same('approval notice: without details it falls back to the number', 'Fund Request #602 is awaiting your review on Requests & Approvals.', end($db->notifications)['message']);
recordApprovalDecision($db, $id, 'Rejected', 105, 11);
same('approval notice: a rejection also closes the task', [1, 1], array_slice(array_column($db->notifications, 'is_read'), -2));
$id = createApprovalRequest($db, 'fund_request', 'fund_request', 603, 103);
same('approval notice: cancelling the request closes the task', [true, [1, 1]], [cancelApprovalRequest($db, $id, 103), array_slice(array_column($db->notifications, 'is_read'), -2)]);
throws('approval notice: a second decision is refused', fn() => recordApprovalDecision($db, $id, 'Approved', 101, 11), DomainException::class);

// ── Handoff notifications (includes/workflow_notifications.php) ───────────
if (!defined('DISPATCHER_SCOPE_KEYS')) {
    preg_match('/^const DISPATCHER_SCOPE_KEYS = \[.*?^\];/ms', (string)file_get_contents($root . '/includes/dispatcher_scope.php'), $scopeKeysSource);
    eval($scopeKeysSource[0]);
}
if (!function_exists('notifyTripHandoff')) {
    eval(preg_replace(['/^<\?php/', '/^require_once [^;]+;\s*$/m'], '', (string)file_get_contents($root . '/includes/workflow_notifications.php')));
}
same('notify: Car Carrier instructions go to the Car Carrier dispatcher role', ['car_carrier_dispatcher'], dispatcherRoleKeysForTruckTypes(['Car Carrier']));
same('notify: Wing Van instructions go to the Container & Wing Van role', ['container_wing_van_dispatcher'], dispatcherRoleKeysForTruckTypes(['Wing Van']));
same('notify: a mixed batch goes to both dispatcher roles', ['car_carrier_dispatcher', 'container_wing_van_dispatcher'], dispatcherRoleKeysForTruckTypes(['Container', 'Car Carrier']));
same('notify: departure checks go to Maintenance, not Admin or Operations Head',
    ['maintenance.manage', ['admin', 'operations_head']], [TRIP_HANDOFF_NOTICES['departure_checks']['permission'], TRIP_HANDOFF_NOTICES['departure_checks']['exclude']]);
same('notify: clearance goes to dispatch.clear holders except Admin',
    ['dispatch.clear', ['admin']], [TRIP_HANDOFF_NOTICES['clearance']['permission'], TRIP_HANDOFF_NOTICES['clearance']['exclude']]);
same('notify: return inspection goes to Maintenance', 'maintenance.manage', TRIP_HANDOFF_NOTICES['return_inspection']['permission']);
check('notify: cleared, returned and allowance-disbursed go to the trip owner only',
    !empty(TRIP_HANDOFF_NOTICES['cleared']['owner']) && !empty(TRIP_HANDOFF_NOTICES['returned']['owner']) && !empty(TRIP_HANDOFF_NOTICES['allowance_disbursed']['owner']));

// Fake database: the trip lookup, the role/permission recipient query and
// the notification inserts.
final class NotifyFakeStatement extends PDOStatement {
    private array $rows = [];
    public function __construct(private NotifyFakePdo $db, private string $sql) {}
    public function execute(?array $params = null): bool {
        $params = $params ?? [];
        $this->db->log[] = [$this->sql, $params];
        if (str_starts_with($this->sql, 'INSERT INTO notifications')) {
            $this->db->sent[] = ['user' => $params[0], 'title' => $params[2], 'entity' => $params[4], 'id' => $params[5]];
            $this->db->stored[] = ['user' => $params[0], 'type' => $params[1], 'title' => $params[2], 'message' => $params[3], 'id' => $params[5], 'read' => 0];
            $this->rows = [];
        } elseif (str_starts_with($this->sql, 'UPDATE notifications SET is_read = 1')) {
            foreach ($this->db->stored as &$stored) {
                if (!$stored['read'] && $stored['type'] === $params[0] && $stored['id'] === $params[1] && in_array($stored['title'], array_slice($params, 2), true)) {
                    $stored['read'] = 1;
                }
            }
            unset($stored);
            $this->rows = [];
        } elseif (str_contains($this->sql, 'FROM fund_requests fr LEFT JOIN trips t')) {
            $this->rows = isset($this->db->funds[$params[0]]) ? [$this->db->funds[$params[0]]] : [];
        } elseif (str_contains($this->sql, 'FROM payment_vouchers WHERE voucher_id = ?')) {
            $this->rows = isset($this->db->vouchers[$params[0]]) ? [$this->db->vouchers[$params[0]]] : [];
        } elseif (str_contains($this->sql, 'WHERE u.is_active = 1 AND r.system_key IN (')) {
            // notifyDispatchersForTruckTypes(): users of the listed roles.
            $this->rows = array_map(static fn(array $u): array => ['user_id' => $u[0]], array_values(array_filter(
                $this->db->users,
                static fn(array $u): bool => in_array($u[1], $params, true)
            )));
        } elseif (str_starts_with($this->sql, 'SELECT 1 FROM users u')) {
            // userHasRolePermission(): Admin or a role holding the permission.
            $user = current(array_filter($this->db->users, static fn(array $u): bool => $u[0] === $params[0]));
            $this->rows = $user && ($user[1] === 'admin' || in_array($params[1], $user[2], true)) ? [[1]] : [];
        } elseif (str_contains($this->sql, 'FROM trips t LEFT JOIN dispatch_requests dr')) {
            $this->rows = isset($this->db->trips[$params[0]]) ? [$this->db->trips[$params[0]]] : [];
        } elseif (str_contains($this->sql, 'JOIN role_permissions rp')) {
            $excluded = array_slice($params, 1);
            $this->rows = array_map(static fn(array $u): array => ['user_id' => $u[0]], array_values(array_filter(
                $this->db->users,
                static fn(array $u): bool => in_array($params[0], $u[2], true) && !in_array($u[1], $excluded, true)
            )));
        } elseif (str_starts_with($this->sql, 'SELECT COUNT(*) FROM client_bookings')) {
            // resolveDispatchListNotices(): open instructions of the role's truck types.
            $this->rows = [[count(array_filter($this->db->openBookings, static fn(string $type): bool => in_array($type, $params, true)))]];
        } elseif (str_starts_with($this->sql, 'UPDATE notifications n JOIN users u')) {
            // resolveUnlinkedNotificationsForRole(): [type, title, role key].
            $roleUsers = array_column(array_filter($this->db->users, static fn(array $u): bool => $u[1] === $params[2]), 0);
            $this->affected = 0;
            foreach ($this->db->stored as &$stored) {
                if (!$stored['read'] && $stored['type'] === $params[0] && $stored['id'] === null
                    && $stored['title'] === $params[1] && in_array($stored['user'], $roleUsers, true)) {
                    $stored['read'] = 1;
                    $this->affected++;
                }
            }
            unset($stored);
            $this->rows = [];
        } else {
            throw new RuntimeException('Unexpected notification SQL: ' . $this->sql);
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return array_shift($this->rows) ?? false;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        return $mode === PDO::FETCH_COLUMN ? array_map(static fn(array $row) => reset($row), $this->rows) : $this->rows;
    }
    public function fetchColumn(int $column = 0): mixed {
        $row = array_shift($this->rows);
        return $row === null ? false : array_values($row)[$column];
    }
    private int $affected = 0;
    public function rowCount(): int { return $this->affected; }
}
final class NotifyFakePdo extends PDO {
    public array $log = [];
    public array $sent = [];
    public array $stored = [];
    // Truck types of the open (Pending, scheduled) instructions, one per instruction.
    public array $openBookings = [];
    public array $trips = [15 => ['trip_number' => 'TRP-2026-0015', 'submitted_by' => 3]];
    // fundRequestNoticeDetails() rows: 40 = the dispatcher's Trip Allowance,
    // 41 = a Trip Allowance Finance filed, 42 = Finance's own Cash Advance.
    public array $funds = [
        40 => ['fund_request_id' => 40, 'request_number' => 'FR-40', 'request_type' => 'Trip Allowance', 'amount' => '1000.00',
               'status' => 'Approved', 'requested_by' => 3, 'trip_id' => 15, 'trip_number' => 'TRP-2026-0015', 'trip_owner' => 3],
        41 => ['fund_request_id' => 41, 'request_number' => 'FR-41', 'request_type' => 'Trip Allowance', 'amount' => '500.00',
               'status' => 'Approved', 'requested_by' => 7, 'trip_id' => 15, 'trip_number' => 'TRP-2026-0015', 'trip_owner' => 3],
        42 => ['fund_request_id' => 42, 'request_number' => 'FR-42', 'request_type' => 'Cash Advance', 'amount' => '300.00',
               'status' => 'Approved', 'requested_by' => 7, 'trip_id' => null, 'trip_number' => null, 'trip_owner' => null],
    ];
    public array $vouchers = [9 => ['voucher_number' => 'PV-9', 'payee' => 'Supplier A', 'amount' => '250.00']];
    // [user_id, role key, permissions]
    public array $users = [
        [1, 'admin', ['maintenance.manage', 'dispatch.clear', 'finance.disbursements.manage']],
        [2, 'operations_head', ['dispatch.clear', 'maintenance.view']],
        [3, 'car_carrier_dispatcher', ['trips.assign', 'trips.view']],
        [5, 'maintenance', ['maintenance.manage']],
        [7, 'finance', ['finance.disbursements.manage']],
    ];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new NotifyFakeStatement($this, preg_replace('/\s+/', ' ', trim($query)));
    }
}
$notifyDb = new NotifyFakePdo();
same('notify: departure checks reach only the Maintenance user', 1, notifyTripHandoff($notifyDb, 15, 'departure_checks'));
same('notify: departure checks recipient and link', [['user' => 5, 'title' => 'Departure checks needed', 'entity' => 'trip_pre_departure', 'id' => 15]], $notifyDb->sent);
$notifyDb = new NotifyFakePdo();
notifyTripHandoff($notifyDb, 15, 'clearance');
same('notify: clearance reaches only the Operations Head', [2], array_column($notifyDb->sent, 'user'));
$notifyDb = new NotifyFakePdo();
notifyTripHandoff($notifyDb, 15, 'cleared');
same('notify: clearance result reaches only the trip owner', [3], array_column($notifyDb->sent, 'user'));
$notifyDb = new NotifyFakePdo();
same('notify: unknown trip sends nothing', 0, notifyTripHandoff($notifyDb, 99, 'cleared'));
throws('notify: unknown handoff refused', fn() => notifyTripHandoff(new NotifyFakePdo(), 15, 'bogus'), InvalidArgumentException::class);
check('notify: recipient query requires the permission and active users and roles', str_contains(implode(' ', array_column((function () {
    $db = new NotifyFakePdo();
    notifyTripHandoff($db, 15, 'return_inspection');
    return $db->log;
})(), 0)), "WHERE u.is_active = 1 AND p.permission_key = ? AND (r.system_key IS NULL OR r.system_key NOT IN (?,?))"));
$workflowNotifySource = (string)file_get_contents($root . '/includes/workflow_notifications.php');
check('notify: notifications never change workflow records', !preg_match('/\b(UPDATE|DELETE)\s/', $workflowNotifySource) && !str_contains($workflowNotifySource, 'transitionTripStatus'));

// Each notification is sent once, inside the transaction of the change that
// makes the next action possible.
$workflowHandlerSource = $prepareHandler;
check('notify: Step 6 hands off to Maintenance or the Operations Head', str_contains($workflowHandlerSource,
    "\$readyForDispatch = recordPreDepartureReadyIfComplete(\$pdo, \$tripId, currentUserId());\n        notifyTripHandoff(\$pdo, \$tripId, \$readyForDispatch ? 'clearance' : 'departure_checks');"));
check('notify: clearance notifies the owner right after the step-8 change', (bool)preg_match("/'Ready for Dispatch',\s*'Dispatched'\s*\);\s*resolveTripHandoffs\(\\\$pdo, \\\$tripId, \['clearance'\]\);\s*notifyTripHandoff\(\\\$pdo, \\\$tripId, 'cleared'\);/", $workflowHandlerSource));
check('notify: passed departure checks notify only when Ready for Dispatch was reached', substr_count($maintenanceHandlerSource, "if (recordPreDepartureReadyIfComplete(\$pdo, \$tripId, currentUserId())) {\n") === 2
    && substr_count($maintenanceHandlerSource, "notifyTripHandoff(\$pdo, \$tripId, 'clearance');") === 2);
check('notify: return inspection result notifies the owner after Returned', (bool)preg_match("/transitionTripStatus\(\\\$pdo, \\\$tripId, \['Delivered'\], 'Returned'[^;]*;\s*resolveTripHandoffs\(\\\$pdo, \\\$tripId, \['return_inspection'\]\);\s*notifyTripHandoff\(\\\$pdo, \\\$tripId, 'returned'\);/", $maintenanceHandlerSource));
$deliveredPos = strpos($statusHandler, "notifyTripHandoff(\$pdo, \$tripId, 'return_inspection');");
check('notify: Delivered notifies Maintenance after the status change and before commit', $deliveredPos !== false
    && $deliveredPos > strpos($statusHandler, 'transitionTripStatus($pdo, $tripId, [$oldStatus], $status, $extraSet);')
    && $deliveredPos < strpos($statusHandler, '$pdo->commit();')
    && str_contains($statusHandler, "if (\$status === 'Delivered') {\n        // Next: Maintenance's return inspection (step 12).\n        notifyTripHandoff"));
$approvalDecisionSource = (string)file_get_contents($root . '/ajax/approval_decision_handler.php');
check('notify: only an approved fund request goes to Finance', (bool)preg_match("/if \(\\\$workflowStatus === 'Approved'\) \{\s*\/\/ Next: Finance records the disbursement.\s*notifyFundRequestAwaitingDisbursement\(\\\$pdo, \\\$entityId\);/", $approvalDecisionSource));
$financeSource = (string)file_get_contents($root . '/ajax/finance_handler.php');
check('notify: a recorded disbursement notifies after the fund request status update', (bool)preg_match("/UPDATE fund_requests SET status = \?, processed_at = NOW\(\)[^;]*;\s*\/\/[^\n]*\n\s*\/\/[^\n]*\n\s*notifyFundRequestDisbursementRecorded\(\\\$pdo, \\\$requestId, \\\$amount, \\\$newStatus, currentUserId\(\)\);/", $financeSource));
check('notify: a fully paid voucher resolves its disbursement notices', str_contains($financeSource,
    "\$pdo->prepare(\"UPDATE payment_vouchers SET status = 'Paid' WHERE voucher_id = ?\")->execute([\$voucherId]);\n                resolveNotifications(\$pdo, 'payment_voucher', \$voucherId, ['Voucher disbursement needed']);"));
check('notify: an approved voucher goes to Finance', (bool)preg_match("/The payment voucher is no longer pending.'\);\s*\}\s*if \(\\\$workflowStatus === 'Approved'\) \{\s*\/\/ Next: Finance records the disbursement.\s*notifyVoucherAwaitingDisbursement\(\\\$pdo, \\\$entityId\);/", $approvalDecisionSource));
check('notify: fund request decision uses the record-specific notice', str_contains($approvalDecisionSource, "if (\$row['workflow_key'] === 'fund_request') {")
    && str_contains($approvalDecisionSource, 'notifyFundRequestDecision($pdo, $entityId, $workflowStatus);'));

// Fund request handoffs, run against the fake database.
$notifyDb = new NotifyFakePdo();
same('fund: approved request goes to Finance only (not Admin)', 1, notifyFundRequestAwaitingDisbursement($notifyDb, 40));
same('fund: Finance notice names request, trip and amount', [7, 'Disbursement needed', 'fund_request', 40],
    [$notifyDb->stored[0]['user'], $notifyDb->stored[0]['title'], $notifyDb->sent[0]['entity'], $notifyDb->stored[0]['id']]);
check('fund: Finance notice text identifies the record', str_contains($notifyDb->stored[0]['message'], 'Trip Allowance FR-40 for TRP-2026-0015 (₱1,000.00) was approved'));
$notifyDb = new NotifyFakePdo();
notifyFundRequestDecision($notifyDb, 40, 'Approved');
same('fund: dispatcher requester is linked to the trip, not the Finance page', [[3, 'trip_workflow', 15]],
    array_map(static fn(array $n): array => [$n['user'], $n['entity'], $n['id']], $notifyDb->sent));
$notifyDb = new NotifyFakePdo();
notifyFundRequestDecision($notifyDb, 41, 'Rejected');
same('fund: a requester without trips.view is linked to the Finance page', [[7, 'fund_request', 41, 'Fund request rejected']],
    array_map(static fn(array $n): array => [$n['user'], $n['entity'], $n['id'], $n['title']], $notifyDb->sent));
same('fund: unknown request sends nothing', 0, notifyFundRequestDecision(new NotifyFakePdo(), 99, 'Approved'));

$notifyDb = new NotifyFakePdo();
notifyFundRequestAwaitingDisbursement($notifyDb, 40);
notifyFundRequestDisbursementRecorded($notifyDb, 40, 400.0, 'Processing', 7);
same('fund: part payment tells the requester; Finance task stays open', [['Disbursement needed', 0], ['Disbursement recorded', 0]],
    array_map(static fn(array $n): array => [$n['title'], $n['read']], $notifyDb->stored));
check('fund: part payment says more is still to be disbursed', str_contains($notifyDb->stored[1]['message'], '₱400.00') && str_contains($notifyDb->stored[1]['message'], 'still to be disbursed'));
notifyFundRequestDisbursementRecorded($notifyDb, 40, 600.0, 'Disbursed', 7);
same('fund: full payment resolves the Finance task and tells the owner once', [['Disbursement needed', 1], ['Disbursement recorded', 0], ['Trip allowance disbursed', 0]],
    array_map(static fn(array $n): array => [$n['title'], $n['read']], $notifyDb->stored));
$notifyDb = new NotifyFakePdo();
notifyFundRequestDisbursementRecorded($notifyDb, 41, 500.0, 'Disbursed', 1);
same('fund: Finance-filed allowance tells both the trip owner and the requester', [[3, 'Trip allowance disbursed'], [7, 'Disbursement recorded']],
    array_map(static fn(array $n): array => [$n['user'], $n['title']], $notifyDb->stored));
$notifyDb = new NotifyFakePdo();
same('fund: the person who recorded it is not notified about their own request', 0, notifyFundRequestDisbursementRecorded($notifyDb, 42, 300.0, 'Disbursed', 7));

$notifyDb = new NotifyFakePdo();
same('voucher: approved voucher goes to Finance only', [7], (notifyVoucherAwaitingDisbursement($notifyDb, 9) ? array_column($notifyDb->sent, 'user') : []));
check('voucher: notice names voucher, payee and amount', str_contains($notifyDb->stored[0]['message'], 'PV-9 for Supplier A (₱250.00)'));

// Trip handoffs are resolved once the next step is done.
$notifyDb = new NotifyFakePdo();
notifyTripHandoff($notifyDb, 15, 'departure_checks');
notifyTripHandoff($notifyDb, 15, 'clearance');
resolveTripHandoffs($notifyDb, 15, ['departure_checks']);
same('resolve: only the finished handoff is marked read', [['Departure checks needed', 1], ['Dispatch clearance needed', 0]],
    array_map(static fn(array $n): array => [$n['title'], $n['read']], $notifyDb->stored));
resolveTripHandoffs($notifyDb, 16, ['clearance']);
same('resolve: another trip\'s notices are untouched', 0, $notifyDb->stored[1]['read']);
throws('resolve: unknown handoff refused', fn() => resolveTripHandoffs(new NotifyFakePdo(), 15, ['bogus']), InvalidArgumentException::class);
same('resolve: no titles writes nothing', 0, resolveNotifications(new NotifyFakePdo(), 'trip_handoff', 15, []));

// Instruction notices point at the instruction and can be resolved.
$notifyDb = new NotifyFakePdo();
$notifyDb->users[] = [8, 'car_carrier_dispatcher', []];
notifyDispatchersForTruckTypes($notifyDb, ['Car Carrier'], 'Dispatch instruction ready', 'INS-000012 is ready to encode.', 12);
same('instruction: single notice links to the instruction', ['dispatch_instruction', 12], [$notifyDb->sent[0]['entity'], $notifyDb->sent[0]['id']]);
resolveDispatchInstructionNotices($notifyDb, 12);
same('instruction: encoded or cancelled instruction is no longer a task', [1], array_unique(array_column($notifyDb->stored, 'read')));
$notifyDb = new NotifyFakePdo();
$notifyDb->users[] = [8, 'car_carrier_dispatcher', []];
notifyDispatchersForTruckTypes($notifyDb, ['Car Carrier'], 'Dispatch list ready', '2 dispatch instructions are ready to encode.');
same('instruction: batch notice has no single record', [null, null], array_column($notifyDb->sent, 'id'));

// Batch notices are resolved per dispatcher role once that role has no open
// instruction left; other roles and single-instruction notices are untouched.
$notifyDb = new NotifyFakePdo();
$notifyDb->users[] = [8, 'car_carrier_dispatcher', []];
$notifyDb->users[] = [9, 'container_wing_van_dispatcher', []];
notifyDispatchersForTruckTypes($notifyDb, ['Car Carrier', 'Wing Van'], 'Dispatch list ready', '3 dispatch instructions are ready to encode.');
notifyDispatchersForTruckTypes($notifyDb, ['Car Carrier'], 'Dispatch instruction ready', 'INS-000020 is ready to encode.', 20);
$batchReads = static fn(NotifyFakePdo $db): array => array_map(
    static fn(array $n): array => [$n['user'], $n['title'], $n['read']], $db->stored
);
$notifyDb->openBookings = ['Car Carrier', 'Wing Van'];
same('batch: another open Car Carrier instruction keeps every notice', 0, resolveDispatchListNotices($notifyDb, 'Car Carrier'));
same('batch: nothing is marked read while instructions remain', [0], array_values(array_unique(array_column($notifyDb->stored, 'read'))));
$notifyDb->openBookings = ['Wing Van'];
same('batch: last Car Carrier instruction encoded resolves the Car Carrier batch notices', 2, resolveDispatchListNotices($notifyDb, 'Car Carrier'));
same('batch: only Car Carrier batch notices are read; Wing Van batch and the single notice stay open', [
    [3, 'Dispatch list ready', 1], [8, 'Dispatch list ready', 1], [9, 'Dispatch list ready', 0],
    [3, 'Dispatch instruction ready', 0], [8, 'Dispatch instruction ready', 0],
], $batchReads($notifyDb));
$notifyDb->openBookings = [];
same('batch: last Wing Van instruction cancelled resolves the Container & Wing Van batch notice', 1, resolveDispatchListNotices($notifyDb, 'Wing Van'));
same('batch: the single-instruction notice is still open', [0, 0], array_column(array_slice($notifyDb->stored, 3), 'read'));
same('batch: an unknown truck type resolves nothing', 0, resolveDispatchListNotices(new NotifyFakePdo(), 'Bogus'));
$notifyDb = new NotifyFakePdo();
$notifyDb->users[] = [9, 'container_wing_van_dispatcher', []];
notifyDispatchersForTruckTypes($notifyDb, ['Container', 'Wing Van'], 'Dispatch list ready', '2 dispatch instructions are ready to encode.');
$notifyDb->openBookings = ['Container'];
same('batch: an open Container instruction keeps the Container & Wing Van notice after a Wing Van encode', 0, resolveDispatchListNotices($notifyDb, 'Wing Van'));

// Handlers resolve notices inside the same transaction as the change.
check('resolve: departure recorded resolves "Cleared for departure"', str_contains($statusHandler, "} elseif (\$status === 'In Progress') {\n        // The departure is recorded: \"Cleared for departure\" is done.\n        resolveTripHandoffs(\$pdo, \$tripId, ['cleared']);"));
check('resolve: completed trip resolves "Return inspection complete"', str_contains($statusHandler, "} elseif (\$status === 'Completed') {\n        resolveTripHandoffs(\$pdo, \$tripId, ['returned']);"));
check('resolve: cancelled trip resolves every handoff', str_contains($statusHandler, "resolveTripHandoffs(\$pdo, \$tripId, array_keys(TRIP_HANDOFF_NOTICES));"));
$resolvePos = strpos($statusHandler, "resolveTripHandoffs(\$pdo, \$tripId, ['cleared']);");
check('resolve: trip status notices resolve before commit', $resolvePos !== false && $resolvePos < strpos($statusHandler, '$pdo->commit();'));
check('resolve: passed departure checks resolve Maintenance\'s notice', substr_count($maintenanceHandlerSource, "resolveTripHandoffs(\$pdo, \$tripId, ['departure_checks']);\n") === 2);
$instructionHandlerSource = (string)file_get_contents($root . '/ajax/dispatch_instructions_handler.php');
$cancelPos = strpos($instructionHandlerSource, 'resolveDispatchInstructionNotices($pdo, $instructionId);');
check('resolve: cancelled instruction resolves its notices after the guarded update, before commit', $cancelPos !== false
    && $cancelPos > strpos($instructionHandlerSource, "jsonFail('Only an unencoded instruction can be cancelled.', 409);")
    && $cancelPos < strpos($instructionHandlerSource, "jsonOk([], 'Dispatch instruction cancelled.');"));
check('instruction: a single new instruction is linked to its notice', str_contains($instructionHandlerSource, "\$action === 'create_batch' ? null : \$bookingIds[0]"));
$submitDispatchSource = (string)file_get_contents($root . '/ajax/submit_dispatch.php');
$encodePos = strpos($submitDispatchSource, "resolveDispatchInstructionNotices(\$pdo, (int)\$booking['booking_id']);");
check('resolve: encoded instruction resolves its notices after the guarded update', $encodePos !== false
    && $encodePos > strpos($submitDispatchSource, "throw new DomainException('This dispatch instruction was already encoded.');")
    && str_contains($submitDispatchSource, "require_once __DIR__ . '/../includes/workflow_notifications.php';"));
$encodeListPos = strpos($submitDispatchSource, "resolveDispatchListNotices(\$pdo, (string)\$booking['truck_type']);");
check('batch: encoding checks the batch notices right after the instruction notice', $encodeListPos !== false && $encodeListPos > $encodePos);
$cancelListPos = strpos($instructionHandlerSource, 'resolveDispatchListNotices($pdo, (string)$truckTypeQuery->fetchColumn());');
check('batch: cancelling checks the batch notices after the guarded update, before commit', $cancelListPos !== false
    && $cancelListPos > $cancelPos
    && $cancelListPos < strpos($instructionHandlerSource, '$pdo->commit();', $cancelPos));

// Links: each record link opens a page that exists, and records without an
// ID fall back to the list page.
if (!defined('APP_BASE')) {
    define('APP_BASE', '/mulawin_fleetops');
}
same('link: trip handoff opens the trip workflow', APP_BASE . '/pages/trip_workflow.php?trip_id=15', notificationLinkFor('trip_workflow', 15));
same('link: departure checks open that trip on Maintenance', APP_BASE . '/pages/maintenance.php?view=pre_departure&trip_id=15', notificationLinkFor('trip_pre_departure', '15'));
same('link: batch instruction notice opens the Dispatcher Queue', APP_BASE . '/pages/dispatch_inbox.php', notificationLinkFor('dispatch_instruction', null));
same('link: unknown entity has no link', null, notificationLinkFor('bogus', 1));
same('link: existing fund request link unchanged', APP_BASE . '/pages/finance.php', notificationLinkFor('fund_request', 40));
foreach (NOTIFICATION_RECORD_LINKS as $linkEntity => $linkPattern) {
    check("link: $linkEntity page exists", is_file($root . strtok($linkPattern, '?')));
}
$instructionSource = (string)file_get_contents($root . '/ajax/dispatch_instructions_handler.php');
check('notify: new instructions go to the matching dispatcher role, not every encoder', str_contains($instructionSource, 'notifyDispatchersForTruckTypes(')
    && !str_contains($instructionSource, "notifyUsersWithPermission(\n            \$pdo,\n            'dispatch.instructions.encode'"));

// ── Home page work items (includes/home_tasks.php) ─────────────────────────
$homeNow = strtotime('2026-10-26 18:00:00');
$homeTrip = static fn(string $status, string $departs = '2026-10-26 20:00:00', int $late = 0): array => [
    'trip_id' => 15, 'trip_number' => 'TRP-2026-0015', 'status' => $status, 'plate_number' => 'NAA 1003',
    'scheduled_at' => $departs, 'is_late' => $late, 'requested_by' => 3,
];
$ownerFacts = ['is_owner' => true, 'is_admin' => false, 'can_assign' => true, 'can_update' => true];
$otherDispatcherFacts = ['is_owner' => false, 'is_admin' => false, 'can_assign' => true, 'can_update' => true];
$opsHeadFacts = ['is_owner' => false, 'is_admin' => false, 'can_assign' => true, 'can_clear' => true];
$maintenanceFacts = ['is_owner' => false, 'is_admin' => false, 'can_inspect' => true];
$adminFacts = ['is_owner' => false, 'is_admin' => true, 'can_assign' => true, 'can_update' => true, 'can_clear' => true];
$ownerTask = tripHomeTask($homeTrip('Approved'), 4, $ownerFacts, $homeNow);
same('home: owner sees the step-5 task with its working page', ['Confirm truck and driver (Step 5)', '/pages/trip_workflow.php?trip_id=15', null],
    [$ownerTask['title'], $ownerTask['url'], $ownerTask['urgent']]);
check('home: task shows trip, truck, status and departure', $ownerTask['detail'] === 'TRP-2026-0015 · NAA 1003 · Approved · Departs Oct 26, 8:00 PM');
same('home: another dispatcher does not get the task', null, tripHomeTask($homeTrip('Approved'), 4, $otherDispatcherFacts, $homeNow));
same('home: Admin gets no task (override only)', null, tripHomeTask($homeTrip('Approved'), 4, $adminFacts, $homeNow));
$maintenanceTask = tripHomeTask($homeTrip('Assigned'), 6, $maintenanceFacts, $homeNow);
same('home: Maintenance gets departure checks linked to the Pre-Departure Inspection',
    '/pages/maintenance.php?view=pre_departure&trip_id=15', $maintenanceTask['url']);
same('home: Maintenance gets the return inspection link',
    '/pages/maintenance.php?view=return&trip_id=15&inspection_stage=Return#inspectionModal', tripHomeTask($homeTrip('Delivered'), 11, $maintenanceFacts, $homeNow)['url']);
same('home: Maintenance has no task while the dispatcher prepares', null, tripHomeTask($homeTrip('Assigned'), 5, $maintenanceFacts, $homeNow));
same('home: Operations Head gets the clearance task', 'Provide dispatch clearance (Step 8)', tripHomeTask($homeTrip('Ready for Dispatch'), 7, $opsHeadFacts, $homeNow)['title']);
same('home: Maintenance gets no clearance task', null, tripHomeTask($homeTrip('Ready for Dispatch'), 7, $maintenanceFacts, $homeNow));
same('home: Pending Approval is left to the approvals alert', null, tripHomeTask($homeTrip('Pending Approval'), 4, $opsHeadFacts + ['can_review' => true], $homeNow));
same('home: completed trip is no longer a task', null, tripHomeTask($homeTrip('Completed'), 13, $ownerFacts, $homeNow));
same('home: cancelled trip is no longer a task', null, tripHomeTask($homeTrip('Cancelled'), 4, $ownerFacts, $homeNow));
same('home: a step already done is no longer offered', null, tripHomeTask($homeTrip('Assigned'), 6, $ownerFacts, $homeNow));
same('home: passed departure before leaving is urgent', 'Scheduled departure has passed', tripHomeTask($homeTrip('Assigned', '2026-10-26 17:00:00'), 5, $ownerFacts, $homeNow)['urgent']);
same('home: future departure is not urgent', null, tripHomeTask($homeTrip('Assigned'), 5, $ownerFacts, $homeNow)['urgent']);
same('home: trip past its ETA is urgent', 'Past ETA', tripHomeTask($homeTrip('In Progress', '2026-10-26 10:00:00', 1), 9, $ownerFacts, $homeNow)['urgent']);
same('home: trip on time after departure is not urgent', null, tripHomeTask($homeTrip('In Progress', '2026-10-26 10:00:00', 0), 9, $ownerFacts, $homeNow)['urgent']);
$instructionTask = dispatchInstructionHomeTask(['booking_id' => 3, 'booking_reference' => 'INS-000003', 'truck_type' => 'Car Carrier', 'shift' => 'Night', 'scheduled_departure' => '2026-10-26 20:00:00'], $homeNow);
same('home: open instruction links to its encoding form', ['/pages/dispatch.php?instruction_id=3', null], [$instructionTask['url'], $instructionTask['urgent']]);
check('home: instruction with a passed departure is flagged', (string)dispatchInstructionHomeTask(['booking_id' => 3, 'booking_reference' => 'INS-000003', 'truck_type' => 'Car Carrier', 'shift' => 'Night', 'scheduled_departure' => '2026-10-26 17:00:00'], $homeNow)['urgent'] !== '');
$fundRow = ['request_number' => 'FR-1', 'request_type' => 'Trip Allowance', 'remaining' => '1500', 'trip_number' => 'TRP-2026-0015', 'trip_status' => 'Dispatched', 'scheduled_departure' => '2026-10-26 17:00:00'];
same('home: allowance blocking a late departure is urgent', '/pages/finance.php', fundDisbursementHomeTask($fundRow, $homeNow)['url']);
check('home: allowance blocking a late departure is flagged', fundDisbursementHomeTask($fundRow, $homeNow)['urgent'] !== null);
same('home: allowance for a trip that already left is not urgent', null, fundDisbursementHomeTask(['trip_status' => 'In Progress'] + $fundRow, $homeNow)['urgent']);
same('home: cash advance without a trip is not urgent', null,
    fundDisbursementHomeTask(['request_type' => 'Cash Advance', 'trip_number' => null, 'trip_status' => null, 'scheduled_departure' => null] + $fundRow, $homeNow)['urgent']);
same('home: urgent tasks come first, order otherwise kept', ['b', 'a', 'c'],
    array_column(sortHomeTasks([['title' => 'a', 'urgent' => null], ['title' => 'b', 'urgent' => 'x'], ['title' => 'c', 'urgent' => null]]), 'title'));
$dashboardSource = (string)file_get_contents($root . '/pages/dashboard.php');
check('home: Admin gets no task items', str_contains($dashboardSource, '$isAdminViewer = isAdmin();')
    && substr_count($dashboardSource, '!$isAdminViewer') >= 4);
check('home: trips with a task are not repeated as Past ETA alerts', str_contains($dashboardSource, "!isset(\$alert['trip_id']) || !in_array(\$alert['trip_id'], \$taskTripIds, true)"));
check('home: Urgent badge only for urgent items', str_contains($dashboardSource, "<?php if (!empty(\$alert['urgent'])): ?> <span class=\"badge text-bg-danger\">Urgent</span><?php endif; ?>"));
check('home: instruction tasks use the Dispatcher Queue scope', str_contains($dashboardSource, "\$instructionScope['status'] === 'scoped'") && str_contains($dashboardSource, "AND truck_type IN ("));
foreach (['/pages/trip_workflow.php', '/pages/maintenance.php', '/pages/dispatch.php', '/pages/finance.php'] as $taskPage) {
    check("home: task destination $taskPage exists", is_file($root . $taskPage));
}

// ── Action feedback (assets/js/layout.js sendAction, pages/finance.php) ─────
// Behaviour is checked by tests/unit/send_action.js (node); these checks keep
// the wiring in place.
$layoutJs = (string)file_get_contents($root . '/assets/js/layout.js');
$financeSource = (string)file_get_contents($root . '/pages/finance.php');
check('feedback: sendAction is defined in layout.js', str_contains($layoutJs, 'window.sendAction = async function (url, body, options = {})'));
check('feedback: sendAction asks for JSON so an expired session answers 401 JSON', str_contains($layoutJs, "headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }"));
check('feedback: soft refresh keeps an empty result box message', str_contains($layoutJs, 'if (isEmptyFeedbackBox(newEl)) return;'));
check('feedback: soft refresh updates opted-in select options only', str_contains($layoutJs, "if (!oldEl.hasAttribute('data-refresh-options')"));
check('finance: all four forms use sendAction', substr_count($financeSource, 'send(event, ') === 4 && str_contains($financeSource, 'window.sendAction('));
check('finance: old fire-and-forget send removed', !str_contains($financeSource, 'const result = await response.json(); feedback.textContent'));
check('finance: request and voucher selects refresh their options', substr_count($financeSource, 'data-refresh-options') === 4);

// Batch 2: ordinary AJAX save forms on five more pages use sendAction with
// their existing result box. [page, result box id, sendAction calls]
foreach ([
    'office_supplies' => ['supplyFeedback', 1],
    'recruitment'     => ['recruitmentFeedback', 1],
    'purchasing'      => ['purchasingFeedback', 1],
    'payroll'         => ['payrollFeedback', 1],
    'attendance'      => ['attendanceFeedback', 1],
] as $feedbackPage => [$feedbackBox, $sendCalls]) {
    $pageSource = (string)file_get_contents($root . "/pages/$feedbackPage.php");
    check("feedback: $feedbackPage uses sendAction", substr_count($pageSource, 'window.sendAction(') === $sendCalls);
    check("feedback: $feedbackPage keeps its result box", str_contains($pageSource, "<div id=\"$feedbackBox\" class=\"alert d-none\" role=\"alert\"></div>"));
}
$suppliesSource = (string)file_get_contents($root . '/pages/office_supplies.php');
check('feedback: office supplies forms pass their action labels', str_contains($suppliesSource, "supplySend(event,'add the item',") && str_contains($suppliesSource, "supplySend(event,'save the movement',"));
check('feedback: office supplies old unguarded send removed', !str_contains($suppliesSource, 'const result=await response.json();'));
check('feedback: new supply items appear in the movement item list', str_contains($suppliesSource, '<select id="movementItem" class="form-select" data-refresh-options required>'));
$recruitmentSource = (string)file_get_contents($root . '/pages/recruitment.php');
check('feedback: candidate status Save disables its own row button', str_contains($recruitmentSource, "{label: 'save the candidate status', button}"));
$purchasingSource = (string)file_get_contents($root . '/pages/purchasing.php');
check('feedback: supplier form uses sendAction', str_contains($purchasingSource, "label: 'save the supplier', form: event.target"));
check('feedback: purchase order form left unchanged (line items kept in script state)', str_contains($purchasingSource, "body: JSON.stringify(items)") === false
    && str_contains($purchasingSource, "items: JSON.stringify(items)") && substr_count($purchasingSource, 'const result = await response.json(); show(') === 1);
check('feedback: supplier lists refresh after a save', str_contains($purchasingSource, '<select id="supplierPick" class="form-select" data-refresh-options>')
    && str_contains($purchasingSource, '<select id="purchaseSupplier" class="form-select" data-refresh-options required>'));
$payrollSource = (string)file_get_contents($root . '/pages/payroll.php');
check('feedback: payroll busy state is on Record payment, not Add deduction', str_contains($payrollSource, "button: event.submitter || event.target.querySelector('[type=\"submit\"]')"));
check('feedback: payroll rebuilds its deduction rows only after success', str_contains($payrollSource, "if (result && result.success) {\n    // The deduction rows are built by this script: start again with one\n    // empty row and recalculated totals.\n    deductionContainer.replaceChildren();"));
$attendanceSource = (string)file_get_contents($root . '/pages/attendance.php');
check('feedback: attendance batch Save uses sendAction with its own button', str_contains($attendanceSource, "{ feedback, button: saveButton, label: 'save the attendance' }"));
// The success message survives the full-reload fallback (seen on Payroll:
// the new row has a link, so the in-place refresh reloads the page).
check('feedback: payroll payment answers with a success message', str_contains((string)file_get_contents($root . '/ajax/payroll_handler.php'), "jsonOk(['payroll_id' => \$newId], 'Payroll payment recorded.');"));
$layoutJsLf = str_replace("\r\n", "\n", $layoutJs); // layout.js has CRLF line endings
check('feedback: softReload reports whether it updated in place', str_contains($layoutJsLf, "const updated = await softRefresh(window.location.href);\n    if (!updated) window.location.reload();\n    return updated;"));
check('feedback: success message saved before the refresh, dropped when updated in place', str_contains($layoutJs, "if (feedback && feedback.id) rememberActionMessage(feedback.id, message);")
    && str_contains($layoutJs, "if (await window.softReload()) forgetActionMessage();"));
check('feedback: saved message shown once, on the same page only', str_contains($layoutJsLf, "forgetActionMessage();\n    if (!saved || saved.page !== window.location.pathname + window.location.search) return;"));

// ── Attendance (includes/attendance.php, ajax/attendance_handler.php) ─────
require_once $root . '/includes/attendance.php';
same('attendance: all six statuses are selectable', ['Present', 'Late', 'Half Day', 'Absent', 'Leave', 'On Duty'], ATTENDANCE_STATUSES);
$attendanceNames = [5 => 'Ana Cruz', 6 => 'Ben Reyes'];
$attendanceRow = static fn(array $extra = []): array => $extra + ['employee_id' => 5, 'status' => 'Present', 'notes' => '', 'expected_status' => '', 'expected_notes' => ''];
[$cleanRows, $rowErrors] = validateAttendanceBatch([], $attendanceNames);
same('attendance: an empty batch saves nothing', [[], ['There are no changed rows to save.']], [$cleanRows, $rowErrors]);
same('attendance: unreadable rows are refused', [], validateAttendanceBatch('not a list', $attendanceNames)[0]);
check('attendance: unreadable rows explain the problem', validateAttendanceBatch(['a' => 1], $attendanceNames)[1] !== []);
foreach (ATTENDANCE_STATUSES as $attendanceStatus) {
    same("attendance: status $attendanceStatus is accepted", [$attendanceStatus, []],
        [validateAttendanceBatch([$attendanceRow(['status' => $attendanceStatus])], $attendanceNames)[0][0]['status'] ?? null,
         validateAttendanceBatch([$attendanceRow(['status' => $attendanceStatus])], $attendanceNames)[1]]);
}
foreach (['', 'present', 'Overtime', null, ['Present']] as $badStatus) {
    check('attendance: invalid status ' . json_encode($badStatus) . ' is refused', validateAttendanceBatch([$attendanceRow(['status' => $badStatus])], $attendanceNames)[1] !== []);
}
[$cleanRows, $rowErrors] = validateAttendanceBatch([$attendanceRow(), $attendanceRow(['employee_id' => 6, 'status' => 'Bogus'])], $attendanceNames);
same('attendance: one bad row means nothing in the batch is saved', [], $cleanRows);
same('attendance: the error names the affected employee', ['Ben Reyes: choose a status (Present, Late, Half Day, Absent, Leave, On Duty).'], $rowErrors);
check('attendance: unknown or inactive employee is refused', str_contains(implode(' ', validateAttendanceBatch([$attendanceRow(['employee_id' => 99])], $attendanceNames)[1]), 'Employee #99: no active employee record'));
check('attendance: the same employee twice is refused', validateAttendanceBatch([$attendanceRow(), $attendanceRow()], $attendanceNames)[1] !== []);
check('attendance: notes over 500 characters are refused', validateAttendanceBatch([$attendanceRow(['notes' => str_repeat('x', 501)])], $attendanceNames)[1] !== []);
check('attendance: an invalid expected status is refused', validateAttendanceBatch([$attendanceRow(['expected_status' => 'Bogus'])], $attendanceNames)[1] !== []);
same('attendance: empty notes are saved as no notes', null, validateAttendanceBatch([$attendanceRow(['notes' => '   '])], $attendanceNames)[0][0]['notes']);
check('attendance: too many rows are refused', validateAttendanceBatch(array_fill(0, ATTENDANCE_BATCH_MAX_ROWS + 1, $attendanceRow()), $attendanceNames)[1] !== []);
// Overwrite protection: the saved record must still be what the page showed.
check('attendance: new record when the page showed Not recorded', attendanceRecordUnchanged(null, '', ''));
check('attendance: a record saved meanwhile blocks a "Not recorded" row', !attendanceRecordUnchanged(['status' => 'Absent', 'remarks' => null], '', ''));
check('attendance: unchanged saved record may be updated', attendanceRecordUnchanged(['status' => 'Late', 'remarks' => ' traffic '], 'Late', 'traffic'));
check('attendance: a status changed meanwhile blocks the row', !attendanceRecordUnchanged(['status' => 'Present', 'remarks' => null], 'Late', ''));
check('attendance: notes changed meanwhile block the row', !attendanceRecordUnchanged(['status' => 'Late', 'remarks' => 'sick'], 'Late', ''));
check('attendance: a deleted record blocks a row that showed one', !attendanceRecordUnchanged(null, 'Present', ''));

$attendanceHandler = (string)file_get_contents($root . '/ajax/attendance_handler.php');
check('attendance: still only Admin/Payroll (hr.attendance.manage) may save', str_contains($attendanceHandler, "requireLogin();\nrequirePostMethod();\nenforceCsrf();\nrequirePermission('hr.attendance.manage');"));
check('attendance: past-date rule unchanged', str_contains($attendanceHandler, "\$date = requiredDate('attendance_date', 'Attendance date', true);"));
check('attendance: single save accepts the same six statuses', str_contains($attendanceHandler, "if (!in_array(\$status, ATTENDANCE_STATUSES, true)) {\n        jsonFail('Select a valid attendance status.');"));
$validatePos = strpos($attendanceHandler, '[$rows, $errors] = validateAttendanceBatch($submitted, $employeeNames);');
check('attendance: every row is checked before the transaction starts', $validatePos !== false && $validatePos < strpos($attendanceHandler, '$pdo->beginTransaction();')
    && str_contains($attendanceHandler, "if (\$errors) {\n        jsonFail('Nothing was saved. ' . implode(' ', \$errors), 422);"));
check('attendance: all rows are saved in one transaction, or none', (bool)preg_match('/\$pdo->beginTransaction\(\);\s*foreach \(\$rows as \$row\) \{\s*saveAttendanceRow\([^;]*;\s*\}\s*\$pdo->commit\(\);\s*\} catch \(Throwable \$e\) \{\s*if \(\$pdo->inTransaction\(\)\) \{\s*\$pdo->rollBack\(\);/', $attendanceHandler));
check('attendance: a record changed by someone else stops the batch', str_contains($attendanceHandler, "if (\$expectedStatus !== null && !attendanceRecordUnchanged(\$existing, \$expectedStatus, \$expectedNotes)) {\n        throw new AttendanceChanged((string)\$employeeId);"));
check('attendance: every failure reply says nothing was saved', substr_count($attendanceHandler, "jsonFail('Nothing was saved.") === 4);
check('attendance: success is reported only after commit', strpos($attendanceHandler, 'jsonOk([\'saved\' => count($rows)]') > strpos($attendanceHandler, '$pdo->commit();'));
check('attendance: audit before/after kept for each row', str_contains($attendanceHandler, "\$existing === null ? 'ADD_ATTENDANCE' : 'EDIT_ATTENDANCE',\n        'employee_attendance',\n        \$attendanceId,\n        \$before,\n        \$after"));
check('attendance: time in/out and overtime are kept on save', str_contains($attendanceHandler, "ON DUPLICATE KEY UPDATE\n           status = VALUES(status), remarks = VALUES(remarks), recorded_by = VALUES(recorded_by)'"));

$attendancePage = str_replace("\r\n", "\n", $attendanceSource); // the page has CRLF line endings
check('attendance: a missing record is never shown as Present', !str_contains($attendancePage, "?? ['status' => 'Present'")
    && str_contains($attendancePage, "<?php if (\$row === null): ?><option value=\"\" selected>Not recorded</option><?php endif; ?>"));
check('attendance: read-only view shows Not recorded too', str_contains($attendancePage, "<td><?= \$row !== null ? htmlspecialchars(\$savedStatus) : '<span class=\"badge text-bg-secondary\">Not recorded</span>' ?></td>"));
check('attendance: totals count saved records only', str_contains($attendancePage, "\$recordedCount = count(array_filter(\$employees, static fn(array \$employee): bool =>\n    isset(\$attendanceByEmployee[(int)\$employee['employee_id']])));\n\$notRecordedCount = count(\$employees) - \$recordedCount;"));
check('attendance: filters All / Not recorded / Recorded only hide rows', substr_count($attendancePage, 'data-attendance-filter="') === 3
    && str_contains($attendancePage, "row.classList.toggle('d-none', (attendanceFilter === 'missing' && recorded) || (attendanceFilter === 'recorded' && !recorded));"));
check('attendance: only changed rows are sent', str_contains($attendancePage, "const changedRows = () => Array.from(document.querySelectorAll('tr[data-recorded]')).filter(isChanged);")
    && str_contains($attendancePage, 'const payload = rows.map(row => ({'));
check('attendance: a new row needs an explicit status before saving', str_contains($attendancePage, "const missingStatus = rows.filter(row => row.querySelector('.attendance-status').value === '')"));
check('attendance: last saved by and when come from the record', str_contains($attendancePage, 'LEFT JOIN users ru ON ru.user_id = ea.recorded_by') && str_contains($attendancePage, "\$row['updated_at']"));
check('attendance: past dates are view-only on the page, as on the server', str_contains($attendancePage, '$canSaveDate = $canManage && !isPassedDate($date);'));
check('attendance: unsaved rows warn before leaving the page', str_contains($attendancePage, "if (!saving && changedRows().length) {"));

// ── Report ────────────────────────────────────────────────────────────────
echo "UNIT TESTS (STATIC, PHP " . PHP_VERSION . ", no database): {$passed} passed, " . count($failed) . " failed\n";
foreach ($failed as $failure) {
    echo "  FAIL: $failure\n";
}
exit($failed ? 1 : 0);
