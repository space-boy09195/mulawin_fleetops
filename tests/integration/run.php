<?php
// ============================================================
// tests/integration/run.php
// HTTP + MariaDB integration runner for the approval workflows (E2).
//
// Modes:
//   php tests/integration/run.php
//       Preflight only (default). Checks PHP and lists the scenarios.
//       No configuration, no database, no HTTP.
//   php tests/integration/run.php --config=PATH
//       Preflight only. Also validates the runner configuration, the
//       disposable working-tree copy and its .env. Still no database
//       connection and no HTTP request.
//   php tests/integration/run.php --config=PATH --integration
//       Opt-in. After the offline checks, connects to the disposable
//       database with the application-test account (read-only queries) and
//       verifies identity, grants, marker, data and schema. HTTP scenarios
//       run only when E2E_HTTP_SCENARIOS_APPROVED is true (it is false).
//
// The runner never holds the setup/cleanup credential, never provisions,
// restores or drops anything, and never writes to a database itself. All
// writes come from the copied application through its real HTTP handlers.
// See tests/integration/README.md before using it.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const E2E_DB_NAME_PATTERN = '/^mulawin_fleetops_e2e(_[a-z0-9]+)?$/';
// The normal working database. Never a test target, whatever the config says.
const E2E_WORKING_DB_NAME = 'mulawin_fleetops_test';
const E2E_APP_PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];
const E2E_LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1'];
const E2E_MARKER_KEY = 'e2e_marker';
const E2E_DEFAULT_SESSION_NAME = 'mulawin_session';

// Stage gate. HTTP scenarios (application writes to the disposable database)
// need a separate approval; until then --integration stops after the
// read-only database checks.
const E2E_HTTP_SCENARIOS_APPROVED = false;

// Synthetic accounts created by seed_synthetic.sql.
const E2E_USERS = [
    'admin'          => ['username' => 'e2e_admin',          'role_key' => 'admin',                  'active' => 1],
    'admin_inactive' => ['username' => 'e2e_admin_inactive', 'role_key' => 'admin',                  'active' => 0],
    'finance'        => ['username' => 'e2e_finance',        'role_key' => 'finance',                'active' => 1],
    'dispatcher'     => ['username' => 'e2e_dispatcher',     'role_key' => 'car_carrier_dispatcher', 'active' => 1],
    'purchasing'     => ['username' => 'e2e_purchasing',     'role_key' => 'purchasing_officer',     'active' => 1],
];
const E2E_TRIP_NUMBER = 'E2E-TRIP-0001';
const E2E_SUPPLIER_NAME = 'E2E Fictional Supplier';
const E2E_PART_NUMBER = 'E2E-PART-001';

// The copy must run the same code as the working tree for these files.
const E2E_COPY_MUST_MATCH = [
    'login.php',
    'auth/login_handler.php',
    'ajax/finance_handler.php',
    'ajax/approval_decision_handler.php',
    'ajax/purchasing_handler.php',
    'includes/approval_workflow.php',
    'includes/audit.php',
    'includes/csrf.php',
    'includes/dispatcher_scope.php',
    'includes/env.php',
    'includes/idempotency.php',
    'includes/notifications.php',
    'includes/session.php',
    'includes/validate.php',
    'config/app.php',
    'config/database.php',
];

// Tables and columns read or written by the scenarios.
const E2E_REQUIRED_COLUMNS = [
    'app_settings'            => ['setting_key', 'setting_value'],
    'roles'                   => ['role_id', 'system_key', 'is_active'],
    'permissions'             => ['permission_id', 'permission_key'],
    'role_permissions'        => ['role_id', 'permission_id'],
    'users'                   => ['user_id', 'employee_id', 'username', 'email', 'password_hash', 'role_id', 'is_active', 'auth_version'],
    'employees'               => ['employee_id', 'first_name', 'last_name'],
    'dispatcher_assignments'  => ['employee_id', 'dispatcher_role_type', 'shift', 'start_date', 'end_date', 'is_active'],
    'clients'                 => ['client_id', 'client_name'],
    'trips'                   => ['trip_id', 'trip_number', 'trip_type', 'shift', 'status', 'scheduled_departure'],
    'dispatch_requests'       => ['trip_id', 'submitted_by'],
    'suppliers'               => ['supplier_id', 'supplier_name', 'status'],
    'parts'                   => ['part_id', 'part_number', 'is_active'],
    'fund_requests'           => ['fund_request_id', 'trip_id', 'requested_by', 'request_type', 'amount', 'status'],
    'disbursements'           => ['disbursement_id', 'reference_type', 'reference_id', 'amount'],
    'purchase_requests'       => ['purchase_request_id', 'requested_by', 'status'],
    'purchase_orders'         => ['purchase_order_id', 'purchase_request_id'],
    'approval_workflows'      => ['workflow_id', 'workflow_key', 'entity_type', 'is_active'],
    'approval_workflow_steps' => ['workflow_id', 'step_number', 'approver_role_id'],
    'approval_requests'       => ['approval_request_id', 'workflow_id', 'entity_type', 'entity_id', 'requested_by', 'status'],
    'approval_steps'          => ['approval_request_id', 'step_number', 'approver_role_id', 'status', 'acted_by'],
    'approval_history'        => ['approval_request_id', 'action', 'acted_by'],
    'notifications'           => ['notification_id', 'user_id', 'entity_type', 'entity_id'],
    'audit_logs'              => ['audit_id', 'action', 'entity_type', 'entity_id', 'old_values', 'new_values'],
    'idempotency_requests'    => ['user_id', 'action_key', 'request_key', 'expires_at'],
    'login_attempts'          => ['identifier', 'ip_address', 'attempted_at'],
];

// status: implemented = code below, never executed yet;
//         planned     = needs setup-credential support that is not approved;
//         disabled    = needs a fault-injection method that is not approved.
// needs_d1: the configured fund_request approver must be Finance (D1).
const E2E_SCENARIOS = [
    1 => ['title' => 'Dispatcher fund request goes to the configured Finance step', 'status' => 'implemented', 'needs_d1' => true],
    2 => ['title' => 'Finance fund request is routed to Admin for that request only', 'status' => 'implemented', 'needs_d1' => true],
    3 => ['title' => 'Finance cannot decide its own routed request', 'status' => 'implemented', 'needs_d1' => true],
    4 => ['title' => 'Admin self-review needs a reason and is audited', 'status' => 'implemented', 'needs_d1' => false],
    5 => ['title' => 'Admin without approvals.review: safe failure, no partial rows', 'status' => 'planned', 'needs_d1' => true,
          'reason' => 'needs a setup-credential grant change and a restore between scenarios (not approved)'],
    6 => ['title' => 'Notification insert failure rolls back the request', 'status' => 'disabled', 'needs_d1' => true,
          'reason' => 'needs an approved fault-injection method (temporary trigger or other); none exists'],
    7 => ['title' => 'Disbursement before approval is rejected', 'status' => 'implemented', 'needs_d1' => false],
    8 => ['title' => 'Purchase request keeps its configured approver', 'status' => 'implemented', 'needs_d1' => false],
];

final class E2eRefusal extends RuntimeException {}

// ---- Output --------------------------------------------------

function e2eSection(string $title): void {
    echo "\n== {$title}\n";
}

function e2eOk(string $message): void {
    echo "  [ok]   {$message}\n";
}

function e2eNote(string $message): void {
    echo "  [note] {$message}\n";
}

// Stops the run. Messages never contain passwords, DSNs or cookies.
function e2eRefuse(string $message): never {
    throw new E2eRefusal($message);
}

function e2eCheck(bool $condition, string $okMessage, string $refusal): void {
    if (!$condition) {
        e2eRefuse($refusal);
    }
    e2eOk($okMessage);
}

// ---- Arguments -----------------------------------------------

function e2eUsage(): string {
    return "Usage: php tests/integration/run.php [--config=PATH] [--integration]\n"
        . "  (no options)        preflight only: environment and scenario list\n"
        . "  --config=PATH       preflight only: also validate config, working copy and its .env\n"
        . "  --integration       opt-in: read-only database checks (needs --config)\n";
}

function e2eParseArgs(array $argv): array {
    $args = ['config' => null, 'integration' => false];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--config=')) {
            $args['config'] = substr($arg, 9);
        } elseif ($arg === '--integration') {
            $args['integration'] = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            echo e2eUsage();
            exit(0);
        } else {
            fwrite(STDERR, "Unknown option: {$arg}\n" . e2eUsage());
            exit(2);
        }
    }
    if ($args['integration'] && ($args['config'] === null || $args['config'] === '')) {
        fwrite(STDERR, "--integration requires --config=PATH.\n" . e2eUsage());
        exit(2);
    }
    return $args;
}

// ---- Paths and .env ------------------------------------------

function e2eNormalPath(string $path): string {
    $normal = rtrim(str_replace('\\', '/', $path), '/');
    return DIRECTORY_SEPARATOR === '\\' ? strtolower($normal) : $normal;
}

function e2ePathInside(string $child, string $parent): bool {
    $child = e2eNormalPath($child);
    $parent = e2eNormalPath($parent);
    return $child === $parent || str_starts_with($child, $parent . '/');
}

// Same parsing rules as includes/env.php loadEnv(): '#' lines skipped, the
// first '=' splits, values trimmed, one pair of matching quotes removed, a
// later line wins. Inline comments are NOT stripped (loadEnv keeps them).
function e2eParseEnvFile(string $path): array {
    $values = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        e2eRefuse('Cannot read ' . basename($path) . '.');
    }
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '' || $key === '0') {
            continue;
        }
        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        $values[$key] = $value;
    }
    return $values;
}

// ---- Configuration -------------------------------------------

function e2eLoadConfig(string $path): array {
    $real = realpath($path);
    if ($real === false || !is_file($real)) {
        e2eRefuse('Config file not found.');
    }
    $config = json_decode((string)file_get_contents($real), true);
    if (!is_array($config)) {
        e2eRefuse('Config file is not a JSON object.');
    }
    $config['_path'] = $real;
    return $config;
}

function e2eValidateConfig(array $config, string $repoRoot): array {
    foreach (['working_copy', 'db_host', 'db_name', 'app_db_user', 'setup_db_user', 'marker', 'port', 'd1_applied_confirmed'] as $key) {
        if (!array_key_exists($key, $config)) {
            e2eRefuse("Config is missing \"{$key}\".");
        }
    }
    e2eCheck(!e2ePathInside($config['_path'], $repoRoot),
        'Config file is outside the repository',
        'The config file must be outside the repository (it names test accounts and the marker).');

    $dbName = $config['db_name'];
    e2eCheck(is_string($dbName) && preg_match(E2E_DB_NAME_PATTERN, $dbName) === 1,
        'Database name matches ^mulawin_fleetops_e2e(_[a-z0-9]+)?$',
        'Database name must match ^mulawin_fleetops_e2e(_[a-z0-9]+)?$.');
    e2eCheck(strcasecmp($dbName, E2E_WORKING_DB_NAME) !== 0,
        'Database name is not the working database (' . E2E_WORKING_DB_NAME . ')',
        'The working database ' . E2E_WORKING_DB_NAME . ' is never a test target.');

    // Only DB_NAME and SESSION_NAME are taken from the project .env.
    $projectDbName = null;
    $projectSession = E2E_DEFAULT_SESSION_NAME;
    if (is_file($repoRoot . '/.env')) {
        $projectEnv = e2eParseEnvFile($repoRoot . '/.env');
        $projectDbName = $projectEnv['DB_NAME'] ?? null;
        $projectSession = $projectEnv['SESSION_NAME'] ?? E2E_DEFAULT_SESSION_NAME;
        unset($projectEnv);
    }
    e2eCheck($projectDbName === null || strcasecmp($dbName, (string)$projectDbName) !== 0,
        'Database name differs from DB_NAME in the project .env',
        'Database name equals DB_NAME in the project .env; refusing.');

    e2eCheck(in_array($config['db_host'], E2E_LOOPBACK_HOSTS, true),
        'Database host is loopback',
        'db_host must be localhost, 127.0.0.1 or ::1.');

    $appUser = $config['app_db_user'];
    $setupUser = $config['setup_db_user'];
    e2eCheck(is_string($appUser) && preg_match('/^[A-Za-z0-9_]{1,32}$/', $appUser) === 1 && strcasecmp($appUser, 'root') !== 0,
        'Application-test account name is valid and not root',
        'app_db_user must be 1-32 letters, digits or underscores, and not root.');
    e2eCheck(is_string($setupUser) && preg_match('/^[A-Za-z0-9_]{1,32}$/', $setupUser) === 1 && strcasecmp($setupUser, $appUser) !== 0,
        'Setup account is a different account (name only; its password is never read)',
        'setup_db_user must be a valid account name different from app_db_user.');

    e2eCheck(is_string($config['marker']) && preg_match('/^[0-9a-f]{64}$/', $config['marker']) === 1,
        'Marker is 64 hex characters',
        'marker must be the 64-hex-character value generated in the setup stage.');

    $port = $config['port'];
    e2eCheck(is_int($port) && $port >= 1024 && $port <= 65535,
        'Port is between 1024 and 65535',
        'port must be an integer between 1024 and 65535.');
    e2eCheck(is_bool($config['d1_applied_confirmed']),
        'd1_applied_confirmed is ' . ($config['d1_applied_confirmed'] ? 'true' : 'false'),
        'd1_applied_confirmed must be true or false.');

    return ['project_session_name' => $projectSession];
}

// ---- Working copy --------------------------------------------

function e2eValidateWorkingCopy(array $config, string $repoRoot, string $projectSession): array {
    $copy = is_string($config['working_copy']) ? realpath($config['working_copy']) : false;
    if ($copy === false || !is_dir($copy)) {
        e2eRefuse('working_copy does not exist or is not a directory.');
    }
    e2eCheck(!e2ePathInside($copy, $repoRoot) && !e2ePathInside($repoRoot, $copy),
        'Working copy is not the project and does not contain it',
        'working_copy must be a separate copy, not the project directory or a parent/child of it.');
    // On this setup the project's parent is the Apache document root
    // (htdocs); a copy there would also be served by Apache.
    e2eCheck(!e2ePathInside($copy, dirname($repoRoot)),
        'Working copy is outside the web server document root',
        'working_copy must not be inside ' . dirname($repoRoot) . ' (served by Apache).');

    $mismatched = [];
    foreach (E2E_COPY_MUST_MATCH as $file) {
        if (!is_file($copy . '/' . $file)) {
            e2eRefuse("Working copy is missing {$file}.");
        }
        if (hash_file('sha256', $copy . '/' . $file) !== hash_file('sha256', $repoRoot . '/' . $file)) {
            $mismatched[] = $file;
        }
    }
    e2eCheck(!$mismatched,
        'Working copy matches the working tree for ' . count(E2E_COPY_MUST_MATCH) . ' key files',
        'Working copy is stale; re-copy it. Differs: ' . implode(', ', $mismatched));

    $envPath = $copy . '/.env';
    if (!is_file($envPath)) {
        e2eRefuse('Working copy has no .env.');
    }
    $env = e2eParseEnvFile($envPath);
    // Every DB_* key must be explicit: config/database.php falls back to
    // root / mulawin_fleetops when a key is missing.
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'SESSION_NAME', 'DOCUMENT_STORAGE_PATH'] as $key) {
        if (!isset($env[$key]) || $env[$key] === '') {
            e2eRefuse("Copy .env must set {$key} explicitly.");
        }
    }
    e2eCheck($env['DB_NAME'] === $config['db_name'], 'Copy .env DB_NAME equals the configured database',
        'Copy .env DB_NAME does not equal the configured db_name.');
    e2eCheck($env['DB_HOST'] === $config['db_host'], 'Copy .env DB_HOST equals the configured loopback host',
        'Copy .env DB_HOST does not equal the configured db_host.');
    e2eCheck($env['DB_USER'] === $config['app_db_user'], 'Copy .env DB_USER is the application-test account',
        'Copy .env DB_USER is not the configured app_db_user.');
    e2eCheck(strcasecmp($env['DB_USER'], $config['setup_db_user']) !== 0 && strcasecmp($env['DB_USER'], 'root') !== 0,
        'Copy .env does not use the setup account or root',
        'Copy .env must not use the setup account or root.');
    e2eCheck(array_key_exists('APP_BASE', $env) && $env['APP_BASE'] === '',
        'Copy .env APP_BASE is empty (copy served at the server root)',
        'Copy .env must set APP_BASE= (empty).');
    e2eCheck($env['SESSION_NAME'] !== $projectSession,
        'Copy .env SESSION_NAME differs from the project session name',
        'Copy .env SESSION_NAME must differ from the project session name.');
    $storage = realpath($env['DOCUMENT_STORAGE_PATH']);
    e2eCheck($storage !== false && !e2ePathInside($storage, $repoRoot),
        'Copy document storage exists and is outside the project',
        'Copy .env DOCUMENT_STORAGE_PATH must exist and be outside the project.');

    return [
        'path'        => $copy,
        'env'         => $env,
        'env_hash'    => hash_file('sha256', $envPath),
        'csrf_name'   => $env['CSRF_TOKEN_NAME'] ?? 'csrf_token',
        'timezone'    => $env['APP_TIMEZONE'] ?? 'Asia/Manila',
    ];
}

// ---- Database checks (read-only, application-test account) ---

function e2eConnect(array $config, array $copy): PDO {
    try {
        return new PDO(
            'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
            $copy['env']['DB_USER'],
            $copy['env']['DB_PASS'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        // Only the SQLSTATE: driver messages can name accounts and hosts.
        e2eRefuse('Cannot connect as the application-test account (SQLSTATE ' . $e->getCode() . ').');
    }
}

function e2eValue(PDO $pdo, string $sql, array $params = []): mixed {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function e2eRows(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// The account may hold exactly SELECT, INSERT, UPDATE, DELETE on the one
// disposable database, plus the USAGE line every account has. Anything else
// (other databases, global or table grants, roles, GRANT OPTION, schema or
// trigger privileges) refuses. Grant lines are never printed: they can carry
// a password hash.
function e2eVerifyGrants(PDO $pdo, string $dbName): void {
    $escapedName = str_replace('_', '\\_', $dbName);
    $dbGrants = 0;
    foreach ($pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
        if (stripos($grant, 'WITH GRANT OPTION') !== false) {
            e2eRefuse('The application-test account has GRANT OPTION.');
        }
        if (preg_match('/^GRANT USAGE ON \*\.\* TO /i', $grant)) {
            continue;
        }
        if (preg_match('/^GRANT (.+?) ON `([^`]+)`\.\* TO /i', $grant, $m)) {
            // Unescaped _ or % in a database grant is a wildcard.
            if ($m[2] !== $escapedName) {
                e2eRefuse('The application-test account has a grant on another or wildcard database name.');
            }
            $privileges = array_map(static fn($p) => strtoupper(trim($p)), explode(',', $m[1]));
            sort($privileges);
            $expected = E2E_APP_PRIVILEGES;
            sort($expected);
            if ($privileges !== $expected) {
                e2eRefuse('The application-test account must hold exactly SELECT, INSERT, UPDATE, DELETE on the disposable database.');
            }
            $dbGrants++;
            continue;
        }
        e2eRefuse('The application-test account has a global, table, role or proxy grant.');
    }
    e2eCheck($dbGrants === 1,
        'Account holds only SELECT, INSERT, UPDATE, DELETE on the disposable database',
        'Expected exactly one database grant for the application-test account.');
    e2eCheck(e2eValue($pdo, 'SELECT CURRENT_ROLE()') === null,
        'No active role adds privileges',
        'The application-test account has an active role.');
}

function e2eRoleId(PDO $pdo, string $roleKey): int {
    $rows = e2eRows($pdo, 'SELECT role_id, is_active FROM roles WHERE system_key = ?', [$roleKey]);
    if (count($rows) !== 1 || (int)$rows[0]['is_active'] !== 1) {
        e2eRefuse("Role {$roleKey} is missing or inactive.");
    }
    return (int)$rows[0]['role_id'];
}

function e2eRoleHas(PDO $pdo, int $roleId, string $permissionKey): bool {
    return (bool)e2eValue($pdo,
        'SELECT 1 FROM role_permissions rp JOIN permissions p ON p.permission_id = rp.permission_id
         WHERE rp.role_id = ? AND p.permission_key = ? LIMIT 1',
        [$roleId, $permissionKey]);
}

function e2eWorkflowSteps(PDO $pdo, string $workflowKey): array {
    $steps = e2eRows($pdo,
        'SELECT s.step_number, s.approver_role_id
         FROM approval_workflow_steps s
         JOIN approval_workflows w ON w.workflow_id = s.workflow_id
         WHERE w.workflow_key = ? AND w.is_active = 1
         ORDER BY s.step_number',
        [$workflowKey]);
    if (!$steps) {
        e2eRefuse("Workflow {$workflowKey} is inactive or has no steps.");
    }
    return array_map(static fn($s) => ['step_number' => (int)$s['step_number'], 'approver_role_id' => (int)$s['approver_role_id']], $steps);
}

function e2eVerifyDatabase(PDO $pdo, array $config): array {
    e2eSection('Database identity (application-test account, read-only)');
    e2eCheck(e2eValue($pdo, 'SELECT DATABASE()') === $config['db_name'],
        'SELECT DATABASE() is the configured disposable database',
        'SELECT DATABASE() is not the configured database.');
    $current = (string)e2eValue($pdo, 'SELECT CURRENT_USER()');
    $at = strrpos($current, '@');
    $user = $at === false ? $current : substr($current, 0, $at);
    $host = $at === false ? '' : substr($current, $at + 1);
    e2eCheck($user === $config['app_db_user'] && in_array($host, E2E_LOOPBACK_HOSTS, true),
        'CURRENT_USER() is the application-test account at a loopback host',
        'CURRENT_USER() is not the configured application-test account at a loopback host.');
    e2eVerifyGrants($pdo, $config['db_name']);

    e2eSection('Schema');
    $columns = [];
    foreach (e2eRows($pdo, 'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()') as $row) {
        $columns[strtolower($row['TABLE_NAME'])][strtolower($row['COLUMN_NAME'])] = true;
    }
    $missing = [];
    foreach (E2E_REQUIRED_COLUMNS as $table => $required) {
        foreach ($required as $column) {
            if (!isset($columns[$table][$column])) {
                $missing[] = "{$table}.{$column}";
            }
        }
    }
    e2eCheck(!$missing, count(E2E_REQUIRED_COLUMNS) . ' required tables and their columns exist',
        'Missing schema objects: ' . implode(', ', $missing));

    e2eSection('Marker and data');
    $markers = e2eRows($pdo, 'SELECT setting_value FROM app_settings WHERE setting_key = ?', [E2E_MARKER_KEY]);
    e2eCheck(count($markers) === 1 && hash_equals($config['marker'], (string)$markers[0]['setting_value']),
        'Setup marker matches the configured marker',
        'Setup marker is missing or does not match; this is not the database set up for this run.');
    $foreign = [
        'users'     => "SELECT COUNT(*) FROM users WHERE username NOT LIKE 'e2e\\_%' OR email NOT LIKE '%@example.invalid'",
        'clients'   => "SELECT COUNT(*) FROM clients WHERE client_name NOT LIKE 'E2E %'",
        'trips'     => "SELECT COUNT(*) FROM trips WHERE trip_number NOT LIKE 'E2E-%'",
        'suppliers' => "SELECT COUNT(*) FROM suppliers WHERE supplier_name NOT LIKE 'E2E %'",
    ];
    foreach ($foreign as $table => $sql) {
        e2eCheck((int)e2eValue($pdo, $sql) === 0, "{$table}: synthetic rows only",
            "{$table} contains non-synthetic rows; refusing (this may be a copy of real data).");
    }

    e2eSection('Configuration and fixtures');
    $roles = [];
    foreach (['admin', 'finance', 'car_carrier_dispatcher', 'purchasing_officer'] as $key) {
        $roles[$key] = e2eRoleId($pdo, $key);
    }
    e2eOk('Roles admin, finance, car_carrier_dispatcher, purchasing_officer exist and are active');
    $users = [];
    foreach (E2E_USERS as $label => $spec) {
        $rows = e2eRows($pdo, 'SELECT user_id, employee_id, role_id, is_active FROM users WHERE username = ?', [$spec['username']]);
        if (count($rows) !== 1
            || (int)$rows[0]['role_id'] !== $roles[$spec['role_key']]
            || (int)$rows[0]['is_active'] !== $spec['active']) {
            e2eRefuse("Synthetic user {$spec['username']} is missing or has the wrong role or status.");
        }
        $users[$label] = ['user_id' => (int)$rows[0]['user_id'], 'employee_id' => (int)$rows[0]['employee_id']];
    }
    e2eOk(count(E2E_USERS) . ' synthetic users have the expected roles and status');

    $trip = e2eRows($pdo,
        "SELECT t.trip_id, t.status, t.shift, t.trip_type, dr.submitted_by
         FROM trips t JOIN dispatch_requests dr ON dr.trip_id = t.trip_id
         WHERE t.trip_number = ?", [E2E_TRIP_NUMBER]);
    e2eCheck(count($trip) === 1 && $trip[0]['status'] === 'Assigned' && $trip[0]['shift'] === 'Day'
        && $trip[0]['trip_type'] === 'Car Carrier' && (int)$trip[0]['submitted_by'] === $users['dispatcher']['user_id'],
        'Synthetic trip is Assigned, Day, Car Carrier, submitted by the dispatcher',
        'Synthetic trip ' . E2E_TRIP_NUMBER . ' is missing or not in its seeded state.');
    $assignments = (int)e2eValue($pdo,
        "SELECT COUNT(*) FROM dispatcher_assignments
         WHERE employee_id = ? AND is_active = 1 AND dispatcher_role_type = 'CAR_CARRIER' AND shift = 'Day'
           AND start_date <= CURDATE() AND (end_date IS NULL OR end_date >= CURDATE())",
        [$users['dispatcher']['employee_id']]);
    e2eCheck($assignments === 1, 'Dispatcher has one active Car Carrier Day assignment',
        'The synthetic dispatcher needs exactly one active Car Carrier Day assignment.');
    $supplierId = (int)e2eValue($pdo, "SELECT supplier_id FROM suppliers WHERE supplier_name = ? AND status = 'Active'", [E2E_SUPPLIER_NAME]);
    $partId = (int)e2eValue($pdo, 'SELECT part_id FROM parts WHERE part_number = ? AND is_active = 1', [E2E_PART_NUMBER]);
    e2eCheck($supplierId > 0 && $partId > 0, 'Synthetic supplier and part exist', 'Synthetic supplier or part is missing.');

    $fundSteps = e2eWorkflowSteps($pdo, 'fund_request');
    $purchaseSteps = e2eWorkflowSteps($pdo, 'purchase_request');
    $stepRolesReview = static function (array $steps) use ($pdo): bool {
        foreach ($steps as $step) {
            if (!e2eRoleHas($pdo, $step['approver_role_id'], 'approvals.review')) {
                return false;
            }
        }
        return true;
    };
    $d1InDatabase = count($fundSteps) === 1
        && $fundSteps[0]['approver_role_id'] === $roles['finance']
        && e2eRoleHas($pdo, $roles['finance'], 'approvals.review');
    if ($config['d1_applied_confirmed'] && !$d1InDatabase) {
        e2eRefuse('Config says D1 was applied, but the fund_request workflow is not a single Finance step with approvals.review.');
    }
    $activeAllowance = (int)e2eValue($pdo,
        "SELECT COUNT(*) FROM fund_requests
         WHERE trip_id = ? AND request_type = 'Trip Allowance'
           AND status IN ('Pending Approval', 'Approved', 'Processing', 'Disbursed', 'Settled')",
        [(int)$trip[0]['trip_id']]);

    return [
        'roles'           => $roles,
        'users'           => $users,
        'trip_id'         => (int)$trip[0]['trip_id'],
        'supplier_id'     => $supplierId,
        'part_id'         => $partId,
        'fund_steps'      => $fundSteps,
        'purchase_steps'  => $purchaseSteps,
        'd1_ready'        => $config['d1_applied_confirmed'] && $d1InDatabase,
        'preconditions'   => [
            1 => ['dispatcher has finance.trip_allowance.request' => e2eRoleHas($pdo, $roles['car_carrier_dispatcher'], 'finance.trip_allowance.request'),
                  'dispatcher lacks finance.funds.manage'         => !e2eRoleHas($pdo, $roles['car_carrier_dispatcher'], 'finance.funds.manage'),
                  'no active allowance on the synthetic trip (fresh restore)' => $activeAllowance === 0],
            2 => ['finance has finance.funds.manage'              => e2eRoleHas($pdo, $roles['finance'], 'finance.funds.manage'),
                  'admin role has approvals.review'               => e2eRoleHas($pdo, $roles['admin'], 'approvals.review')],
            3 => ['finance has approvals.review'                  => e2eRoleHas($pdo, $roles['finance'], 'approvals.review')],
            4 => ['every fund_request step role has approvals.review' => $stepRolesReview($fundSteps)],
            7 => ['finance has finance.disbursements.manage'      => e2eRoleHas($pdo, $roles['finance'], 'finance.disbursements.manage'),
                  'every fund_request step role has approvals.review' => $stepRolesReview($fundSteps)],
            8 => ['purchasing has purchasing.manage'              => e2eRoleHas($pdo, $roles['purchasing_officer'], 'purchasing.manage'),
                  'every purchase_request step role has approvals.review' => $stepRolesReview($purchaseSteps)],
        ],
    ];
}

// ---- Scenario readiness --------------------------------------

// Returns [id => reason or null]. Null means ready. Without database facts
// (offline preflight) nothing is ready.
function e2eReadiness(?array $facts): array {
    $readiness = [];
    foreach (E2E_SCENARIOS as $id => $scenario) {
        if ($scenario['status'] !== 'implemented') {
            $readiness[$id] = strtoupper($scenario['status']) . ': ' . $scenario['reason'];
        } elseif ($facts === null) {
            $readiness[$id] = 'NOT READY: disposable database not provisioned or verified';
        } elseif ($scenario['needs_d1'] && !$facts['d1_ready']) {
            $readiness[$id] = 'NOT READY: depends on D1, which must be separately approved, applied in the disposable database and confirmed in the config';
        } else {
            $failed = array_keys(array_filter($facts['preconditions'][$id] ?? [], static fn($ok) => !$ok));
            $readiness[$id] = $failed ? 'NOT READY: ' . implode('; ', $failed) : null;
        }
    }
    return $readiness;
}

function e2ePrintReadiness(array $readiness): void {
    foreach (E2E_SCENARIOS as $id => $scenario) {
        $state = $readiness[$id] ?? null;
        printf("  %d. %s\n     %s%s\n", $id, $scenario['title'],
            $state === null ? 'READY' : $state,
            $scenario['needs_d1'] ? ' [depends on D1]' : '');
    }
}

// ---- Local server --------------------------------------------

// Router for PHP's built-in server: answers the identity probe, refuses
// dot-files (.env) and non-public folders, serves everything else.
function e2eRouterSource(): string {
    return <<<'PHP'
<?php
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if ($path === '/__e2e_identity') {
    header('Content-Type: text/plain');
    echo hash('sha256', getenv('E2E_SERVER_NONCE') . '|' . realpath($_SERVER['DOCUMENT_ROOT']));
    exit;
}
if (preg_match('#(^|/)\.|^/(tests|db|backups|scripts|config|includes|docs|instructions|uploads)(/|$)#i', str_replace('\\', '/', $path))) {
    http_response_code(404);
    exit;
}
return false;
PHP;
}

function e2eStartServer(array $copy, int $port): array {
    $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.5);
    if ($probe !== false) {
        fclose($probe);
        e2eRefuse("Port {$port} on 127.0.0.1 is already in use; refusing to send requests to an unknown server.");
    }
    $router = sys_get_temp_dir() . '/e2e_router_' . bin2hex(random_bytes(8)) . '.php';
    $log = sys_get_temp_dir() . '/e2e_server_' . bin2hex(random_bytes(8)) . '.log';
    if (file_put_contents($router, e2eRouterSource()) === false) {
        e2eRefuse('Cannot write the temporary router file.');
    }
    $nonce = bin2hex(random_bytes(16));
    // Minimal environment: no DB_* or other variables reach the application,
    // so env()'s $_SERVER fallback cannot supply a database setting.
    $env = ['E2E_SERVER_NONCE' => $nonce];
    foreach (['SystemRoot', 'windir', 'PATH', 'TEMP', 'TMP'] as $name) {
        if (getenv($name) !== false) {
            $env[$name] = getenv($name);
        }
    }
    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $copy['path'], $router],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        $copy['path'],
        $env
    );
    if (!is_resource($process)) {
        e2eRefuse('Cannot start the PHP built-in server.');
    }
    $server = ['process' => $process, 'pipes' => $pipes, 'router' => $router, 'log' => $log,
               'base' => 'http://127.0.0.1:' . $port];
    $expected = hash('sha256', $nonce . '|' . realpath($copy['path']));
    for ($i = 0; $i < 50; $i++) {
        usleep(200000);
        $client = new E2eHttpClient($server['base']);
        $response = $client->request('GET', '/__e2e_identity');
        if ($response['status'] === 200 && hash_equals($expected, trim($response['body']))) {
            e2eOk('Built-in server on 127.0.0.1:' . $port . ' answers with this run\'s identity and serves the copy');
            return $server;
        }
    }
    e2eStopServer($server);
    e2eRefuse('The built-in server did not answer with this run\'s identity.');
}

function e2eStopServer(?array $server): void {
    if ($server === null) {
        return;
    }
    foreach ($server['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (is_resource($server['process'])) {
        proc_terminate($server['process']);
        proc_close($server['process']);
    }
    @unlink($server['router']);
    @unlink($server['log']);
}

// ---- HTTP client ---------------------------------------------

// One client per synthetic user. Each request uses a new curl handle; the
// user's cookies live only in a shared in-memory cookie store (never written
// to disk, never printed). Redirects are not followed.
final class E2eHttpClient {
    private CurlShareHandle $cookies;

    public function __construct(private string $base) {
        if (!preg_match('#^http://127\.0\.0\.1:\d+$#', $base)) {
            e2eRefuse('HTTP requests are allowed only to 127.0.0.1.');
        }
        $this->cookies = curl_share_init();
        curl_share_setopt($this->cookies, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);
    }

    public function request(string $method, string $path, array $fields = [], array $headers = []): array {
        $location = null;
        $curl = curl_init($this->base . $path);
        curl_setopt_array($curl, [
            CURLOPT_SHARE          => $this->cookies,
            CURLOPT_COOKIEFILE     => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_NOPROXY        => '*',
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location): int {
                if (stripos($line, 'Location:') === 0) {
                    $location = trim(substr($line, 9));
                }
                return strlen($line);
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $body = is_string($body) ? $body : '';
        $json = json_decode($body, true);
        return ['status' => $status, 'location' => $location, 'body' => $body, 'json' => is_array($json) ? $json : null];
    }
}

// Real login through auth/login_handler.php, then the CSRF token that
// includes/layout.php prints on an authenticated page.
function e2eLogin(string $base, string $csrfName, string $username, string $password): array {
    $client = new E2eHttpClient($base);
    $page = $client->request('GET', '/login.php');
    $quotedName = preg_quote(htmlspecialchars($csrfName), '/');
    if ($page['status'] !== 200 || !preg_match('/name="' . $quotedName . '" value="([^"]+)"/', $page['body'], $m)) {
        e2eRefuse('Login page did not return a CSRF token.');
    }
    $login = $client->request('POST', '/auth/login_handler.php',
        ['username' => $username, 'password' => $password, $csrfName => html_entity_decode($m[1])]);
    if ($login['status'] !== 302 || $login['location'] === null || str_contains($login['location'], 'login.php')) {
        e2eRefuse("Login failed for {$username} (HTTP {$login['status']}).");
    }
    $dashboard = $client->request('GET', (string)parse_url($login['location'], PHP_URL_PATH));
    if ($dashboard['status'] !== 200 || !preg_match('/window\.CSRF_TOKEN="([0-9a-f]+)"/', $dashboard['body'], $t)) {
        e2eRefuse("No CSRF token on the dashboard after login for {$username}.");
    }
    return ['client' => $client, 'csrf' => $t[1], 'csrf_name' => $csrfName];
}

// JSON handler request: separate HTTP request, so the handler's transaction
// has committed or rolled back before the runner reads the database.
function e2ePost(array $session, string $path, array $fields): array {
    $fields[$session['csrf_name']] = $session['csrf'];
    return $session['client']->request('POST', $path, $fields, [
        'Accept: application/json',
        'X-Requested-With: XMLHttpRequest',
        'Idempotency-Key: e2e-' . bin2hex(random_bytes(16)),
    ]);
}

// ---- Scenario helpers ----------------------------------------

final class E2eResult {
    public array $failures = [];
    public int $passed = 0;

    public function check(bool $condition, string $label): bool {
        if ($condition) {
            $this->passed++;
        } else {
            $this->failures[] = $label;
        }
        return $condition;
    }
}

function e2eMaxIds(PDO $pdo): array {
    $ids = [];
    foreach (['fund_requests' => 'fund_request_id', 'approval_requests' => 'approval_request_id',
              'notifications' => 'notification_id', 'audit_logs' => 'audit_id',
              'disbursements' => 'disbursement_id', 'purchase_requests' => 'purchase_request_id'] as $table => $column) {
        $ids[$table] = (int)e2eValue($pdo, "SELECT COALESCE(MAX({$column}), 0) FROM {$table}");
    }
    return $ids;
}

function e2eResponseIs(E2eResult $r, array $response, int $status, bool $success, ?string $message): void {
    $r->check($response['status'] === $status, "HTTP {$status} (got {$response['status']})");
    $r->check(($response['json']['success'] ?? null) === $success, 'JSON success is ' . ($success ? 'true' : 'false'));
    if ($message !== null) {
        $r->check(($response['json']['message'] ?? null) === $message,
            "message \"{$message}\" (got \"" . ($response['json']['message'] ?? '') . '")');
    }
}

function e2eApprovalFor(PDO $pdo, string $entityType, int $entityId): ?array {
    $rows = e2eRows($pdo,
        'SELECT approval_request_id, requested_by, status FROM approval_requests WHERE entity_type = ? AND entity_id = ?',
        [$entityType, $entityId]);
    return count($rows) === 1 ? $rows[0] : null;
}

function e2eStepsOf(PDO $pdo, int $approvalId): array {
    return array_map(
        static fn($s) => ['step_number' => (int)$s['step_number'], 'approver_role_id' => (int)$s['approver_role_id'],
                          'status' => $s['status'], 'acted_by' => $s['acted_by'] === null ? null : (int)$s['acted_by']],
        e2eRows($pdo, 'SELECT step_number, approver_role_id, status, acted_by FROM approval_steps
                       WHERE approval_request_id = ? ORDER BY step_number', [$approvalId]));
}

function e2eNotifiedUsers(PDO $pdo, int $approvalId, int $afterId): array {
    $ids = array_map('intval', array_column(e2eRows($pdo,
        "SELECT user_id FROM notifications WHERE entity_type = 'approval_request' AND entity_id = ? AND notification_id > ?",
        [$approvalId, $afterId]), 'user_id'));
    sort($ids);
    return $ids;
}

function e2eActiveUsersOfRole(PDO $pdo, int $roleId): array {
    $ids = array_map('intval', array_column(e2eRows($pdo,
        'SELECT user_id FROM users WHERE role_id = ? AND is_active = 1', [$roleId]), 'user_id'));
    sort($ids);
    return $ids;
}

function e2eAuditRows(PDO $pdo, int $afterId, string $action, string $entityType, int $entityId): array {
    return e2eRows($pdo,
        'SELECT old_values, new_values FROM audit_logs WHERE audit_id > ? AND action = ? AND entity_type = ? AND entity_id = ?',
        [$afterId, $action, $entityType, $entityId]);
}

// [step number, approver role] pairs, for comparing a request with a template.
function e2eStepRoles(array $steps): array {
    return array_map(static fn($s) => [$s['step_number'], $s['approver_role_id']], $steps);
}

// ---- Scenarios (implemented, never executed in this stage) ---
// Expected statuses and messages come from the current handlers:
// ajax/finance_handler.php, ajax/approval_decision_handler.php,
// ajax/purchasing_handler.php.

function e2eScenario1(PDO $pdo, array $facts, array $sessions, array &$state): E2eResult {
    $r = new E2eResult();
    $before = e2eMaxIds($pdo);
    $response = e2ePost($sessions['dispatcher'], '/ajax/finance_handler.php', [
        'action' => 'create_fund_request', 'request_type' => 'Trip Allowance',
        'purpose' => 'E2E trip allowance', 'amount' => '1500.00', 'trip_id' => (string)$facts['trip_id'],
    ]);
    e2eResponseIs($r, $response, 200, true, 'Fund request submitted for approval.');
    $fundId = (int)($response['json']['fund_request_id'] ?? 0);
    $fund = e2eRows($pdo, 'SELECT requested_by, status, trip_id FROM fund_requests WHERE fund_request_id = ?', [$fundId]);
    $r->check(count($fund) === 1 && (int)$fund[0]['requested_by'] === $facts['users']['dispatcher']['user_id']
        && $fund[0]['status'] === 'Pending Approval' && (int)$fund[0]['trip_id'] === $facts['trip_id'],
        'fund request is Pending Approval, by the dispatcher, on the synthetic trip');
    $approval = e2eApprovalFor($pdo, 'fund_request', $fundId);
    if (!$r->check($approval !== null && $approval['status'] === 'Pending', 'one Pending approval request')) {
        return $r;
    }
    $approvalId = (int)$approval['approval_request_id'];
    $r->check(e2eStepRoles(e2eStepsOf($pdo, $approvalId)) === e2eStepRoles($facts['fund_steps']),
        'request steps equal the configured template (Finance)');
    $r->check(e2eNotifiedUsers($pdo, $approvalId, $before['notifications']) === e2eActiveUsersOfRole($pdo, $facts['roles']['finance']),
        'notifications went to the active Finance users only');
    $r->check(count(e2eAuditRows($pdo, $before['audit_logs'], 'CREATE', 'fund_requests', $fundId)) === 1, 'one CREATE audit row');
    $r->check(!e2eAuditRows($pdo, $before['audit_logs'], 'APPROVAL_ROUTED_TO_ADMIN', 'approval_requests', $approvalId),
        'no routing audit row');
    return $r;
}

function e2eScenario2(PDO $pdo, array $facts, array $sessions, array &$state): E2eResult {
    $r = new E2eResult();
    $before = e2eMaxIds($pdo);
    $templateBefore = e2eWorkflowSteps($pdo, 'fund_request');
    $response = e2ePost($sessions['finance'], '/ajax/finance_handler.php', [
        'action' => 'create_fund_request', 'request_type' => 'Cash Advance',
        'purpose' => 'E2E cash advance', 'amount' => '2500.00',
    ]);
    e2eResponseIs($r, $response, 200, true, 'Fund request submitted for Admin approval, because you belong to the approving role.');
    $fundId = (int)($response['json']['fund_request_id'] ?? 0);
    $approval = e2eApprovalFor($pdo, 'fund_request', $fundId);
    if (!$r->check($approval !== null && $approval['status'] === 'Pending', 'one Pending approval request')) {
        return $r;
    }
    $approvalId = (int)$approval['approval_request_id'];
    $steps = e2eStepsOf($pdo, $approvalId);
    $r->check(count($steps) === 1 && $steps[0]['approver_role_id'] === $facts['roles']['admin'], 'request step is on Admin');
    $r->check(e2eWorkflowSteps($pdo, 'fund_request') === $templateBefore, 'global fund_request template unchanged');
    $admins = e2eActiveUsersOfRole($pdo, $facts['roles']['admin']);
    $notified = e2eNotifiedUsers($pdo, $approvalId, $before['notifications']);
    $r->check($notified === $admins, 'notifications went to active Admin users only');
    $r->check(!in_array($facts['users']['admin_inactive']['user_id'], $notified, true), 'inactive Admin not notified');
    $audit = e2eAuditRows($pdo, $before['audit_logs'], 'APPROVAL_ROUTED_TO_ADMIN', 'approval_requests', $approvalId);
    if ($r->check(count($audit) === 1, 'one APPROVAL_ROUTED_TO_ADMIN audit row')) {
        $old = json_decode((string)$audit[0]['old_values'], true);
        $new = json_decode((string)$audit[0]['new_values'], true);
        $r->check((int)($old['approver_role_id'] ?? 0) === $facts['roles']['finance'], 'audit old approver is Finance');
        $r->check((int)($new['approver_role_id'] ?? 0) === $facts['roles']['admin'], 'audit new approver is Admin');
        $r->check((int)($new['fund_request_id'] ?? 0) === $fundId, 'audit names the fund request');
        $r->check((int)($new['notified_users'] ?? -1) === count($admins), 'audit notified count matches');
    }
    $state['routed_approval_id'] = $approvalId;
    $state['routed_fund_id'] = $fundId;
    return $r;
}

function e2eScenario3(PDO $pdo, array $facts, array $sessions, array &$state): E2eResult {
    $r = new E2eResult();
    if (!$r->check(isset($state['routed_approval_id']), 'scenario 2 produced a routed request')) {
        return $r;
    }
    $approvalId = $state['routed_approval_id'];
    $before = e2eMaxIds($pdo);
    $response = e2ePost($sessions['finance'], '/ajax/approval_decision_handler.php', [
        'approval_request_id' => (string)$approvalId, 'decision' => 'Approved',
    ]);
    e2eResponseIs($r, $response, 403, false, 'You cannot decide your own request.');
    $approval = e2eRows($pdo, 'SELECT status FROM approval_requests WHERE approval_request_id = ?', [$approvalId]);
    $r->check(($approval[0]['status'] ?? null) === 'Pending', 'approval request still Pending');
    foreach (e2eStepsOf($pdo, $approvalId) as $step) {
        $r->check($step['status'] === 'Pending' && $step['acted_by'] === null, "step {$step['step_number']} untouched");
    }
    $r->check(e2eValue($pdo, 'SELECT status FROM fund_requests WHERE fund_request_id = ?', [$state['routed_fund_id']]) === 'Pending Approval',
        'fund request still Pending Approval');
    $r->check(!e2eAuditRows($pdo, $before['audit_logs'], 'APPROVAL_DECISION', 'approval_requests', $approvalId), 'no decision audit row');
    return $r;
}

function e2eScenario4(PDO $pdo, array $facts, array $sessions, array &$state): E2eResult {
    $r = new E2eResult();
    $create = e2ePost($sessions['admin'], '/ajax/finance_handler.php', [
        'action' => 'create_fund_request', 'request_type' => 'Operating Fund',
        'purpose' => 'E2E admin operating fund', 'amount' => '500.00',
    ]);
    e2eResponseIs($r, $create, 200, true, 'Fund request submitted for approval.');
    $fundId = (int)($create['json']['fund_request_id'] ?? 0);
    $approval = e2eApprovalFor($pdo, 'fund_request', $fundId);
    if (!$r->check($approval !== null, 'one approval request')) {
        return $r;
    }
    $approvalId = (int)$approval['approval_request_id'];
    $r->check(e2eStepRoles(e2eStepsOf($pdo, $approvalId)) === e2eStepRoles($facts['fund_steps']),
        'Admin request is not rerouted (steps equal the template)');

    $before = e2eMaxIds($pdo);
    $refused = e2ePost($sessions['admin'], '/ajax/approval_decision_handler.php', [
        'approval_request_id' => (string)$approvalId, 'decision' => 'Approved',
    ]);
    e2eResponseIs($r, $refused, 422, false, 'An Admin override reason (1-500 characters) is required to decide your own request.');
    $r->check(e2eValue($pdo, 'SELECT status FROM approval_requests WHERE approval_request_id = ?', [$approvalId]) === 'Pending',
        'still Pending after the refusal');
    $r->check(count(e2eAuditRows($pdo, $before['audit_logs'], 'ADMIN_OVERRIDE_REFUSED', 'approval_requests', $approvalId)) === 1,
        'one ADMIN_OVERRIDE_REFUSED audit row');

    $before = e2eMaxIds($pdo);
    $decided = e2ePost($sessions['admin'], '/ajax/approval_decision_handler.php', [
        'approval_request_id' => (string)$approvalId, 'decision' => 'Approved',
        'override_reason' => 'E2E self-review check',
    ]);
    $r->check($decided['status'] === 200 && ($decided['json']['success'] ?? null) === true, 'decision with a reason succeeds');
    $steps = e2eStepsOf($pdo, $approvalId);
    $r->check(($steps[0]['status'] ?? null) === 'Approved' && ($steps[0]['acted_by'] ?? null) === $facts['users']['admin']['user_id'],
        'step 1 Approved by the Admin');
    $override = e2eAuditRows($pdo, $before['audit_logs'], 'ADMIN_OVERRIDE', 'approval_requests', $approvalId);
    if ($r->check(count($override) === 1, 'one ADMIN_OVERRIDE audit row')) {
        $new = json_decode((string)$override[0]['new_values'], true);
        $r->check(($new['self_review'] ?? null) === true, 'override audit flags self_review');
        $r->check(($new['reason'] ?? null) === 'E2E self-review check', 'override audit keeps the reason');
    }
    $r->check(count(e2eAuditRows($pdo, $before['audit_logs'], 'APPROVAL_DECISION', 'approval_requests', $approvalId)) === 1,
        'one APPROVAL_DECISION audit row');
    if (($decided['json']['status'] ?? null) === 'Approved') {
        $r->check(e2eValue($pdo, 'SELECT status FROM fund_requests WHERE fund_request_id = ?', [$fundId]) === 'Approved',
            'single-step workflow: fund request Approved');
    }
    return $r;
}

function e2eScenario7(PDO $pdo, array $facts, array $sessions, array &$state): E2eResult {
    $r = new E2eResult();
    $create = e2ePost($sessions['admin'], '/ajax/finance_handler.php', [
        'action' => 'create_fund_request', 'request_type' => 'Other',
        'purpose' => 'E2E pending request for disbursement check', 'amount' => '300.00',
    ]);
    e2eResponseIs($r, $create, 200, true, 'Fund request submitted for approval.');
    $fundId = (int)($create['json']['fund_request_id'] ?? 0);
    $before = e2eMaxIds($pdo);
    $paidSql = "SELECT COALESCE(SUM(amount), 0) FROM disbursements WHERE reference_type = 'fund_request' AND reference_id = ?";
    $paidBefore = (string)e2eValue($pdo, $paidSql, [$fundId]);
    $response = e2ePost($sessions['finance'], '/ajax/finance_handler.php', [
        'action' => 'create_disbursement', 'fund_request_id' => (string)$fundId,
        'payee_name' => 'E2E Payee', 'amount' => '100.00', 'payment_mode' => 'Cash',
        'disbursed_at' => $state['today'],
    ]);
    e2eResponseIs($r, $response, 409, false, 'Only approved fund requests can be disbursed.');
    $r->check(e2eMaxIds($pdo)['disbursements'] === $before['disbursements'], 'no disbursement row created');
    $r->check((string)e2eValue($pdo, $paidSql, [$fundId]) === $paidBefore, 'amount disbursed for the request unchanged');
    $r->check(e2eValue($pdo, 'SELECT status FROM fund_requests WHERE fund_request_id = ?', [$fundId]) === 'Pending Approval',
        'fund request still Pending Approval');
    $r->check((int)e2eValue($pdo, "SELECT COUNT(*) FROM audit_logs WHERE audit_id > ? AND entity_type = 'disbursements'",
        [$before['audit_logs']]) === 0, 'no disbursement audit row');
    return $r;
}

function e2eScenario8(PDO $pdo, array $facts, array $sessions, array &$state): E2eResult {
    $r = new E2eResult();
    $before = e2eMaxIds($pdo);
    $response = e2ePost($sessions['purchasing'], '/ajax/purchasing_handler.php', [
        'action' => 'create_order', 'supplier_id' => (string)$facts['supplier_id'],
        'items' => json_encode([['part_id' => $facts['part_id'], 'quantity' => 1, 'unit_cost' => 100]]),
        'notes' => 'E2E purchase order',
    ]);
    e2eResponseIs($r, $response, 200, true, 'Purchase order submitted for approval.');
    $requestId = (int)e2eValue($pdo, 'SELECT purchase_request_id FROM purchase_orders WHERE purchase_order_id = ?',
        [(int)($response['json']['purchase_order_id'] ?? 0)]);
    $approval = e2eApprovalFor($pdo, 'purchase_request', $requestId);
    if (!$r->check($approval !== null && $approval['status'] === 'Pending', 'one Pending purchase approval')) {
        return $r;
    }
    $approvalId = (int)$approval['approval_request_id'];
    $steps = e2eStepsOf($pdo, $approvalId);
    if (!$r->check(e2eStepRoles($steps) === e2eStepRoles($facts['purchase_steps']), 'steps equal the purchase_request template')) {
        return $r;
    }
    $r->check(e2eNotifiedUsers($pdo, $approvalId, $before['notifications']) === e2eActiveUsersOfRole($pdo, $steps[0]['approver_role_id']),
        'notifications went to the configured first-step role');
    $r->check(!e2eAuditRows($pdo, $before['audit_logs'], 'APPROVAL_ROUTED_TO_ADMIN', 'approval_requests', $approvalId),
        'no routing audit row');
    return $r;
}

function e2eRunScenarios(PDO $pdo, array $config, array $copy, array $facts, array $readiness): int {
    $password = getenv('E2E_USER_PASSWORD');
    if (!is_string($password) || $password === '') {
        e2eRefuse('Set E2E_USER_PASSWORD (the synthetic users\' password chosen in the setup stage).');
    }
    $server = null;
    try {
        if (hash_file('sha256', $copy['path'] . '/.env') !== $copy['env_hash']) {
            e2eRefuse('The copy .env changed after it was checked.');
        }
        $server = e2eStartServer($copy, $config['port']);
        $sessions = [];
        foreach (['admin', 'finance', 'dispatcher', 'purchasing'] as $label) {
            $sessions[$label] = e2eLogin($server['base'], $copy['csrf_name'], E2E_USERS[$label]['username'], $password);
        }
        e2eOk('Signed in ' . count($sessions) . ' synthetic users through auth/login_handler.php');

        $state = ['today' => (new DateTimeImmutable('now', new DateTimeZone($copy['timezone'])))->format('Y-m-d')];
        $runners = [1 => 'e2eScenario1', 2 => 'e2eScenario2', 3 => 'e2eScenario3', 4 => 'e2eScenario4',
                    7 => 'e2eScenario7', 8 => 'e2eScenario8'];
        $failed = 0;
        e2eSection('Scenarios');
        foreach ($runners as $id => $function) {
            if ($readiness[$id] !== null) {
                echo "  [skip] {$id}. {$readiness[$id]}\n";
                continue;
            }
            $result = $function($pdo, $facts, $sessions, $state);
            if ($result->failures) {
                $failed++;
                echo "  [FAIL] {$id}. " . E2E_SCENARIOS[$id]['title'] . "\n";
                foreach ($result->failures as $failure) {
                    echo "         - {$failure}\n";
                }
            } else {
                echo "  [pass] {$id}. " . E2E_SCENARIOS[$id]['title'] . " ({$result->passed} checks)\n";
            }
        }
        foreach ([5, 6] as $id) {
            echo "  [skip] {$id}. {$readiness[$id]}\n";
        }
        return $failed === 0 ? 0 : 1;
    } finally {
        e2eStopServer($server);
    }
}

// ---- Main ----------------------------------------------------

$args = e2eParseArgs($argv);
$repoRoot = realpath(__DIR__ . '/../..');

try {
    e2eSection('Mode');
    echo '  ' . ($args['integration'] ? 'INTEGRATION (opt-in): read-only database checks' : 'PREFLIGHT ONLY: no database connection, no HTTP request') . "\n";

    e2eSection('Environment');
    e2eCheck(PHP_VERSION_ID >= 80100, 'PHP ' . PHP_VERSION, 'PHP 8.1 or later is required.');
    foreach (['curl', 'pdo_mysql', 'json'] as $extension) {
        e2eCheck(extension_loaded($extension), "Extension {$extension} loaded", "PHP extension {$extension} is required.");
    }

    if ($args['config'] === null) {
        e2eSection('Scenarios');
        e2ePrintReadiness(e2eReadiness(null));
        echo "\nPreflight only. No config given: no database connection and no HTTP request were made.\n";
        exit(0);
    }

    e2eSection('Configuration');
    $config = e2eLoadConfig($args['config']);
    $validated = e2eValidateConfig($config, $repoRoot);

    e2eSection('Working copy');
    $copy = e2eValidateWorkingCopy($config, $repoRoot, $validated['project_session_name']);

    if (!$args['integration']) {
        e2eSection('Scenarios');
        e2ePrintReadiness(e2eReadiness(null));
        echo "\nPreflight passed. No database connection and no HTTP request were made.\n";
        exit(0);
    }

    $pdo = e2eConnect($config, $copy);
    $facts = e2eVerifyDatabase($pdo, $config);
    $readiness = e2eReadiness($facts);
    e2eSection('Scenario readiness');
    e2ePrintReadiness($readiness);

    if (!E2E_HTTP_SCENARIOS_APPROVED) {
        echo "\nDatabase checks passed. HTTP scenarios are disabled in this stage "
            . "(E2E_HTTP_SCENARIOS_APPROVED = false): no server started, no HTTP request sent.\n";
        exit(3);
    }
    exit(e2eRunScenarios($pdo, $config, $copy, $facts, $readiness));
} catch (E2eRefusal $e) {
    echo "  [REFUSED] {$e->getMessage()}\n\nStopped. Nothing further was run.\n";
    exit(1);
} catch (PDOException $e) {
    echo '  [REFUSED] Database query failed (SQLSTATE ' . $e->getCode() . ").\n\nStopped. Nothing further was run.\n";
    exit(1);
}
