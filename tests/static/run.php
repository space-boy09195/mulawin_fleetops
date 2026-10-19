<?php
// ============================================================
// tests/static/run.php
// STATIC SQL checks for pages whose SQL is assembled at runtime (dashboards,
// analytics, reports). For each page, tests/static/capture_sql.php runs the
// page's data layer against a recording stub PDO (no database connection,
// .env never read) and writes the exact SQL strings it built; then
// scripts/schema_check.php checks them against the target schema model
// (reference schema + all drafts). A negative control (a deliberately wrong
// column) must be reported, proving the check can fail.
//
// Run from the repository root:  php tests/static/run.php
// Exit code 0 = no CONFIRMED / UNCERTAIN findings and the control failed as
// expected. This is NOT a MariaDB test: it proves table/column/ENUM names,
// not query results.
// ============================================================

$root = dirname(__DIR__, 2);
$php = PHP_BINARY;
$outDir = $root . '/tests/static/.captured';
if (!is_dir($outDir) && !mkdir($outDir, 0777, true)) {
    fwrite(STDERR, "Cannot create $outDir\n");
    exit(2);
}

$stubs = $root . '/tests/static/stubs';
// page => [roles, includes the data layer needs (relative or absolute)]
$targets = [
    'pages/analytics.php'                 => ['admin,operations_head,car_carrier_dispatcher,maintenance,finance', [$stubs . '/dispatcher_scope.php', 'includes/analytics_period.php']],
    'pages/dashboard_head.php'            => ['admin', ['includes/dashboard_metrics.php', 'includes/trip_queries.php', 'includes/recommendations.php', 'includes/employee_profile.php']],
    'pages/dashboard_operations_head.php' => ['operations_head', ['includes/dashboard_metrics.php', 'includes/trip_queries.php']],
    'pages/dashboard_dispatcher.php'      => ['car_carrier_dispatcher', [$stubs . '/dispatcher_scope.php', 'includes/dashboard_metrics.php', 'includes/trip_queries.php', 'includes/recommendations.php']],
    'pages/dashboard_maintenance.php'     => ['maintenance', ['includes/dashboard_metrics.php', 'includes/recommendations.php']],
    'pages/dashboard_accounting.php'      => ['finance', ['includes/dashboard_metrics.php', 'includes/recommendations.php']],
    'pages/dashboard.php'                 => ['payroll,driver,helper', [$stubs . '/general_dashboard.php', 'includes/dashboard_metrics.php', 'includes/trip_queries.php', 'includes/trip_completion.php']],
    'pages/dispatcher_reports.php'        => ['car_carrier_dispatcher', [$stubs . '/dispatcher_scope.php']],
    'pages/operations_maintenance_report.php' => ['operations_head', []],
    'pages/audit_history.php'             => ['audit', []],
];

function runCommand(string $command, array $env = []): array {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, null, $env + getenv());
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out . $err];
}

$failures = 0;
$captured = [];
foreach ($targets as $page => [$roles, $includes]) {
    $out = $outDir . '/' . basename($page, '.php') . '.sql.php';
    [$code, $output] = runCommand(
        escapeshellarg($php) . ' ' . escapeshellarg($root . '/tests/static/capture_sql.php') . ' '
        . escapeshellarg($root . '/' . $page) . ' ' . escapeshellarg($out) . ' ' . escapeshellarg($roles),
        ['CAPTURE_INCLUDES' => implode(',', $includes)]
    );
    $line = trim(strtok($output, "\n") ?: '');
    if ($code !== 0 || !is_file($out)) {
        echo "CAPTURE FAIL $page: " . trim($output) . "\n";
        $failures++;
        continue;
    }
    echo "  $line\n";
    $captured[] = 'tests/static/.captured/' . basename($out);
}

// SQL fragments from shared helpers (trip, finance, maintenance, payroll).
$helperOut = $outDir . '/helper_statements.sql.php';
[$code, $output] = runCommand(escapeshellarg($php) . ' ' . escapeshellarg($root . '/tests/static/helper_statements.php') . ' ' . escapeshellarg($helperOut));
if ($code !== 0 || !is_file($helperOut)) {
    echo 'CAPTURE FAIL helper statements: ' . trim($output) . "\n";
    $failures++;
} else {
    echo '  ' . trim($output) . "\n";
    $captured[] = 'tests/static/.captured/helper_statements.sql.php';
}

// Negative control: a copy of a captured statement with a column that does not exist.
$control = $outDir . '/negative_control.sql.php';
file_put_contents($control, "<?php\n\$s = \$pdo->prepare('SELECT b.amount_due_bogus FROM billings b WHERE b.billing_id = ?');\n");

$scan = static function (array $files) use ($php, $root): array {
    return runCommand(
        escapeshellarg($php) . ' ' . escapeshellarg($root . '/scripts/schema_check.php')
        . ' --drafts=all --only=' . escapeshellarg(implode(',', $files))
    );
};

[, $report] = $scan($captured);
preg_match('/CONFIRMED (\d+), UNCERTAIN (\d+)/', $report, $m);
$confirmed = (int)($m[1] ?? -1);
$uncertain = (int)($m[2] ?? -1);
echo "Captured SQL scan: CONFIRMED $confirmed, UNCERTAIN $uncertain (" . count($captured) . " files)\n";
if ($confirmed !== 0 || $uncertain !== 0) {
    echo $report;
    $failures++;
}

[, $controlReport] = $scan(['tests/static/.captured/negative_control.sql.php']);
$controlCaught = str_contains($controlReport, 'billings.amount_due_bogus') && str_contains($controlReport, '[CONFIRMED]');
echo 'Negative control (bogus column) reported: ' . ($controlCaught ? 'yes' : 'NO') . "\n";
if (!$controlCaught) $failures++;

// Billing page period filters (bounded periods; tests/static/billing_period_test.php).
[$code, $output] = runCommand(escapeshellarg($php) . ' ' . escapeshellarg($root . '/tests/static/billing_period_test.php'));
echo trim($output) . "\n";
if ($code !== 0) $failures++;

if (!in_array('--keep', $argv, true)) {
    array_map('unlink', glob($outDir . '/*.php'));
    @rmdir($outDir);
}
echo $failures === 0 ? "STATIC SQL CHECKS: PASS\n" : "STATIC SQL CHECKS: FAIL ($failures)\n";
exit($failures === 0 ? 0 : 1);
