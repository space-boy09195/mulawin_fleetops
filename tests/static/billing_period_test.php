<?php
// ============================================================
// tests/static/billing_period_test.php
// STATIC test of the period filters in pages/billing.php. Runs the page's
// data layer against a recording stub PDO (no database connection, .env
// never read) once per period option, and checks the four period-filtered
// statements: billings, collections, payroll, and Trip Pay.
//
// For each bounded period it checks that the statement has the existing
// lower bound and an exclusive upper bound "< :rangeEnd"; that :rangeEnd is
// the start of tomorrow ('Y-m-d' for the date-filtered fields); that
// :rangeStart matches the period; and that every named placeholder appears
// once and is bound exactly once (native prepared statements). For All Time
// it checks that the statements carry no period placeholders and no binds.
//
// Run from the repository root:  php tests/static/billing_period_test.php
// (optionally followed by the path of another copy of the page).
// This is NOT a MariaDB test: it proves the SQL text and bound values the
// page builds, not query results.
// ============================================================

$repo = dirname(__DIR__, 2);

$GLOBALS['__stmts'] = [];

class RecordingStatement extends PDOStatement {
    public string $sql = '';
    public array $binds = [];
    public ?array $executeParams = null;
    public int $executeCalls = 0;
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool {
        $this->binds[] = [(string)$param, $value];
        return true;
    }
    public function execute(?array $params = null): bool {
        $this->executeCalls++;
        $this->executeParams = $params;
        return true;
    }
    public function fetchColumn(int $column = 0): mixed { return '0'; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $o = PDO::FETCH_ORI_NEXT, int $off = 0): mixed { return []; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
    public function rowCount(): int { return 0; }
}
class RecordingPDO extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $s = new RecordingStatement();
        $s->sql = $query;
        $GLOBALS['__stmts'][] = $s;
        return $s;
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        return $this->prepare($query);
    }
}

// Stubs for session / layout functions used by the data layer (same set as
// tests/static/capture_sql.php). Every permission is granted, so every
// period-filtered statement is built.
define('APP_BASE', '');
function getDBConnection(): PDO { return new RecordingPDO(); }
function requirePermission(string $p): void {}
function requireAnyPermission(array $p): void {}
function requireLogin(): void {}
function currentRoleKey(): ?string { return 'admin'; }
function isAdmin(): bool { return true; }
function currentUserHasAnyPermission(array $p): bool { return true; }
function currentUserId(): int { return 1; }
function currentRoleId(): int { return 1; }
function layoutHead(...$a): void {}
function isValidDate(string $d): bool { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); }
function currentUserHasActiveEmployee(): bool { return true; }

require_once $repo . '/config/enums.php';
require_once $repo . '/includes/metrics.php';
require_once $repo . '/app/modules/finance/finance_records.php';

// Data layer = everything before the first line that is exactly the closing
// tag, without the opening tag line and require lines.
// Optional argument: another copy of the page (used as a negative control
// against a pre-fix backup); default is the repository page.
$pagePath = $argv[1] ?? $repo . '/pages/billing.php';
$lines = preg_split('/\R/', file_get_contents($pagePath));
$body = [];
foreach ($lines as $i => $line) {
    if ($i > 0 && trim($line) === '?>') break;
    if ($i === 0) continue;
    if (preg_match('/^\s*require(_once)?\s/', $line)) continue;
    $body[] = $line;
}
$code = implode("\n", $body);
$codeWithoutFunctions = preg_replace('/^function\s+\w+\s*\(.*?^\}\s*$/ms', '', $code);

function runBillingDataLayer(string $period, bool $first): array {
    global $code, $codeWithoutFunctions;
    $GLOBALS['__stmts'] = [];
    $_GET = $period === '' ? [] : ['period' => $period];
    $run = $first ? $code : $codeWithoutFunctions;
    (static function () use ($run) { eval($run); })();
    return $GLOBALS['__stmts'];
}

// The four statements, the SQL each bounded filter must contain, and whether
// the filtered column is DATETIME (the others are DATE).
$targets = [
    'billings'        => ['FROM billings b',              'b.billing_date', false],
    'collections'     => ['FROM collections c',           'c.payment_date', false],
    'payroll'         => ['FROM payroll_records pr',      'paid_date', false],
    'Trip Pay'        => ['FROM trip_pay_records tp',     tripPayDateSql('t'), false],
];

$passed = 0;
$failed = [];
function check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; } else { $failed[] = $name . ($detail !== '' ? " — $detail" : ''); }
}
function findStatement(array $stmts, string $marker): array {
    // Word boundary after the alias: "FROM collections c" must not match the
    // collected-amount subquery "FROM collections cpay".
    $pattern = '/' . preg_quote($marker, '/') . '\b/';
    return array_values(array_filter($stmts, fn(RecordingStatement $s) => (bool)preg_match($pattern, $s->sql)));
}
function namedPlaceholders(string $sql): array {
    preg_match_all('/(?<![:\w]):([A-Za-z_][A-Za-z0-9_]*)/', $sql, $m);
    return $m[1];
}

check('Billing page omits summary and aging analytics',
    !str_contains($code, 'Total Billed') &&
    !str_contains($code, 'Receivables aging') &&
    !str_contains($code, 'Overdue by client'));
check('Billing page omits overdue-date calculation and expense aggregation',
    !str_contains($code, 'isOverdue(') &&
    !str_contains($code, 'FROM expense_records er') &&
    !str_contains($code, 'Recorded Margin'));

$periods = [
    'today' => fn(DateTime $t) => clone $t,
    '1w'    => fn(DateTime $t) => (clone $t)->modify('-6 days'),
    '1m'    => fn(DateTime $t) => (clone $t)->modify('-1 months'),
    '3m'    => fn(DateTime $t) => (clone $t)->modify('-3 months'),
    '6m'    => fn(DateTime $t) => (clone $t)->modify('-6 months'),
    '1y'    => fn(DateTime $t) => (clone $t)->modify('-12 months'),
];

$first = true;
foreach (array_merge(array_keys($periods), ['all']) as $period) {
    // Re-run if the date changed during the run (test started just before midnight).
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $dayBefore = (new DateTime('today'))->format('Y-m-d');
        $stmts = runBillingDataLayer($period, $first);
        $first = false;
        if ((new DateTime('today'))->format('Y-m-d') === $dayBefore) break;
    }
    $today = new DateTime($dayBefore);
    $tomorrow = (clone $today)->modify('+1 day');

    foreach ($targets as $label => [$marker, $column, $isDateTime]) {
        $name = "[$period] $label";
        $found = findStatement($stmts, $marker);
        check("$name: exactly one statement found", count($found) === 1, count($found) . ' found');
        if (count($found) !== 1) continue;
        $s = $found[0];
        $placeholders = namedPlaceholders($s->sql);
        $bound = array_column($s->binds, 0);

        if ($period === 'all') {
            check("$name: no :rangeStart / :rangeEnd placeholder", !preg_grep('/^range(Start|End)$/', $placeholders), implode(',', $placeholders));
            check("$name: no period bind", !preg_grep('/^:range(Start|End)$/', $bound), implode(',', $bound));
            continue;
        }

        $fmt = $isDateTime ? 'Y-m-d 00:00:00' : 'Y-m-d';
        $expectStart = $periods[$period]($today)->format($fmt);
        $expectEnd = $tomorrow->format($fmt);
        $bindMap = [];
        foreach ($s->binds as [$k, $v]) { $bindMap[$k][] = $v; }

        check("$name: lower bound kept", str_contains($s->sql, "$column >= :rangeStart"));
        check("$name: exclusive upper bound", str_contains($s->sql, "$column < :rangeEnd"));
        check("$name: no inclusive upper bound", !str_contains($s->sql, '<= :rangeEnd'));
        check("$name: each placeholder used once", count($placeholders) === count(array_unique($placeholders)), implode(',', $placeholders));
        $expectedKeys = array_map(fn($p) => ':' . $p, $placeholders);
        $boundKeys = array_keys($bindMap);
        sort($expectedKeys);
        sort($boundKeys);
        check("$name: every placeholder bound exactly once",
            $expectedKeys === $boundKeys && !array_filter($bindMap, fn($v) => count($v) !== 1),
            'placeholders ' . implode(',', $expectedKeys) . ' / binds ' . implode(',', $boundKeys));
        check("$name: execute() takes no parameter array", $s->executeCalls === 1 && $s->executeParams === null);
        check("$name: :rangeStart = $expectStart", ($bindMap[':rangeStart'][0] ?? null) === $expectStart, (string)($bindMap[':rangeStart'][0] ?? 'missing'));
        check("$name: :rangeEnd = $expectEnd (start of tomorrow)", ($bindMap[':rangeEnd'][0] ?? null) === $expectEnd, (string)($bindMap[':rangeEnd'][0] ?? 'missing'));
        check("$name: format " . ($isDateTime ? 'DATETIME' : 'DATE'),
            (bool)preg_match($isDateTime ? '/^\d{4}-\d{2}-\d{2} 00:00:00$/' : '/^\d{4}-\d{2}-\d{2}$/', (string)($bindMap[':rangeEnd'][0] ?? ''))
            && (bool)preg_match($isDateTime ? '/^\d{4}-\d{2}-\d{2} 00:00:00$/' : '/^\d{4}-\d{2}-\d{2}$/', (string)($bindMap[':rangeStart'][0] ?? '')));
    }
}

echo "BILLING PERIOD TESTS (STATIC, PHP " . PHP_VERSION . ", no database): {$passed} passed, " . count($failed) . " failed\n";
foreach ($failed as $f) echo "  FAIL: $f\n";
exit($failed ? 1 : 0);
