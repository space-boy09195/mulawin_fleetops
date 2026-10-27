<?php
// tests/static/capture_sql.php — SQL capture harness (STATIC): runs the data layer of a page (everything
// before its first line that is exactly the closing tag) against a recording stub PDO,
// once per simulated role key, and writes every SQL string it built as
// literal $pdo->prepare('...') calls for scripts/schema_check.php.
// No database connection is made; config/database.php and .env are never
// loaded. Usage: php capture_sql.php <page.php> <out.php> <roleKey,...>
$repo = dirname(__DIR__, 2);
[$self, $page, $out, $roles] = $argv + [null, null, null, 'admin'];

$GLOBALS['__captured'] = [];
$GLOBALS['__role'] = 'admin';

class CaptureStatement extends PDOStatement {
    public function execute(?array $params = null): bool { return true; }
    public function fetchColumn(int $column = 0): mixed { return '0'; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $o = PDO::FETCH_ORI_NEXT, int $off = 0): mixed { return []; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
    public function rowCount(): int { return 0; }
}
class CapturePDO extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $GLOBALS['__captured'][] = $query;
        return new CaptureStatement();
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $GLOBALS['__captured'][] = $query;
        return new CaptureStatement();
    }
    public function lastInsertId(?string $name = null): string|false { return '0'; }
    public function beginTransaction(): bool { return true; }
    public function commit(): bool { return true; }
    public function rollBack(): bool { return true; }
    public function inTransaction(): bool { return false; }
}

// Stubs for session / layout functions used by data layers.
define('APP_BASE', '');
function getDBConnection(): PDO { return new CapturePDO(); }
function requirePermission(string $p): void {}
function requireAnyPermission(array $p): void {}
function requireLogin(): void {}
function currentRoleKey(): ?string { return $GLOBALS['__role']; }
function isAdmin(): bool { return $GLOBALS['__role'] === 'admin'; }
function currentUserHasAnyPermission(array $p): bool { return true; }
function currentUserId(): int { return 1; }
function currentRoleId(): int { return 1; }
function layoutHead(...$a): void {}
function isValidDate(string $d): bool { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); }
function currentUserHasActiveEmployee(): bool { return true; }

require_once $repo . '/app/shared/metrics.php';
require_once $repo . '/app/modules/finance/finance_records.php';
foreach ((array)($GLOBALS['__extra_includes'] ?? []) as $inc) require_once $inc;
$extra = getenv('CAPTURE_INCLUDES');
if ($extra) foreach (explode(',', $extra) as $inc) require_once (preg_match('#^([A-Za-z]:)?[/\\\\]#', $inc) ? $inc : $repo . '/' . $inc);

$src = file_get_contents($page);
$lines = preg_split('/\R/', $src);
$body = [];
foreach ($lines as $i => $line) {
    if ($i > 0 && trim($line) === '?>') break;
    if ($i === 0) continue; // opening tag line
    if (preg_match('/^\s*require(_once)?\s/', $line)) continue;
    $body[] = $line;
}
$code = implode("\n", $body);
// Functions declared in the data layer must be declared once only.
$declared = false;
foreach (explode(',', $roles) as $role) {
    $GLOBALS['__role'] = $role;
    $_GET = [];
    $run = $declared
        ? preg_replace('/^function\s+\w+\s*\(.*?^\}\s*$/ms', '', $code)
        : $code;
    $declared = true;
    (static function () use ($run) { eval($run); })();
}
$sqls = array_values(array_unique($GLOBALS['__captured']));
$php = "<?php\n";
foreach ($sqls as $n => $sql) {
    $php .= '$s' . $n . ' = $pdo->prepare(' . var_export(trim(preg_replace('/\s+/', ' ', $sql)), true) . ");\n";
}
file_put_contents($out, $php);
echo 'captured ' . count($sqls) . " distinct statements from " . basename($page) . " for roles $roles\n";
