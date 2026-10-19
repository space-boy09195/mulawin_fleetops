<?php
// ============================================================
// auth/seed_users.php
// ONE-TIME script — run once to create test accounts
// DELETE THIS FILE from the server after running it!
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/employee_profile.php';

// ---- Initial Admin password ---------------------------------
// The password is never stored in this file. It is read twice through a
// hidden prompt before any database connection. If input cannot be hidden
// (not an interactive terminal, or no hidden prompt on this platform), the
// script refuses to run instead of echoing the password.

// One line from the terminal without echo, or null when hidden input cannot
// be established.
function seedReadHiddenLine(string $prompt): ?string
{
    if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) {
        return null;
    }
    return PHP_OS_FAMILY === 'Windows'
        ? seedReadHiddenLineWindows($prompt)
        : seedReadHiddenLineStty($prompt);
}

// Windows: PowerShell reads the value from the console as a SecureString (no
// echo) and returns it to this process over a pipe. The prompt is fixed text;
// Read-Host adds its own ": ", so a trailing colon is removed here. Returns
// null when PowerShell cannot be started; a prompt that starts but is
// cancelled or fails ends the script with a neutral message.
function seedReadHiddenLineWindows(string $prompt): ?string
{
    $label = rtrim($prompt, ': ');
    $script = '$ErrorActionPreference = "Stop"; '
        . '[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding $false; '
        . '$s = Read-Host -AsSecureString -Prompt "' . $label . '"; '
        . '$b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s); '
        . 'try { [Console]::Out.Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($b)) } '
        . 'finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b) }';
    $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
    $process = @proc_open(
        ['powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', $encoded],
        [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR],
        $pipes
    );
    if (!is_resource($process)) {
        return null;
    }
    $value = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0 || !is_string($value)) {
        throw new RuntimeException('Password entry was cancelled or failed. Nothing was changed.');
    }
    return rtrim($value, "\r\n");
}

// Other systems: turn terminal echo off with stty and always restore it.
function seedReadHiddenLineStty(string $prompt): ?string
{
    $saved = trim((string)shell_exec('stty -g 2>/dev/null'));
    if ($saved === '' || !preg_match('/^[A-Za-z0-9:=-]+$/', $saved)) {
        return null;
    }
    exec('stty -echo 2>/dev/null', $output, $status);
    if ($status !== 0) {
        return null;
    }
    $restore = static function () use ($saved): void {
        exec('stty ' . escapeshellarg($saved) . ' 2>/dev/null');
    };
    // A signal would skip the finally block, so while the prompt is open,
    // SIGINT, SIGTERM, SIGHUP and SIGQUIT restore the terminal settings
    // before exiting (when the pcntl extension is available). The previous
    // handlers are put back as soon as the prompt ends, so they never affect
    // the database work that follows. SIGKILL cannot be caught; after one,
    // or without pcntl, `stty sane` restores the terminal.
    $previousHandlers = [];
    $previousAsync = null;
    if (function_exists('pcntl_signal') && function_exists('pcntl_signal_get_handler')
        && function_exists('pcntl_async_signals')) {
        $previousAsync = pcntl_async_signals(true);
        foreach (['SIGINT', 'SIGTERM', 'SIGHUP', 'SIGQUIT'] as $name) {
            if (!defined($name)) {
                continue;
            }
            $signal = constant($name);
            $previousHandlers[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, static function (int $signo) use ($restore): void {
                $restore();
                fwrite(STDERR, PHP_EOL . 'Cancelled.' . PHP_EOL);
                exit(128 + $signo);
            });
        }
    }
    try {
        fwrite(STDERR, $prompt);
        $line = fgets(STDIN);
    } finally {
        $restore();
        foreach ($previousHandlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
        if ($previousAsync !== null) {
            pcntl_async_signals($previousAsync);
        }
        fwrite(STDERR, PHP_EOL);
    }
    if ($line === false) {
        throw new RuntimeException('Password entry was cancelled or failed. Nothing was changed.');
    }
    return rtrim($line, "\r\n");
}

// Asks twice; at least 8 characters, the same minimum as the Admin
// password reset. $readHidden returns a line or null (no hidden input).
function seedPromptInitialPassword(callable $readHidden): string
{
    $unavailable = 'A hidden password prompt is not available here. '
        . 'Run this script from an interactive PowerShell or terminal window.';
    $first = $readHidden('Initial Admin password: ');
    if ($first === null) {
        throw new RuntimeException($unavailable);
    }
    if (strlen($first) < 8) {
        throw new RuntimeException('The password must be at least 8 characters.');
    }
    $second = $readHidden('Confirm initial Admin password: ');
    if ($second === null) {
        throw new RuntimeException($unavailable);
    }
    if (!hash_equals($first, $second)) {
        throw new RuntimeException('The passwords do not match.');
    }
    return $first;
}

try {
    $password = seedPromptInitialPassword('seedReadHiddenLine');
} catch (Throwable $e) {
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

try {
    $pdo = getDBConnection();

    // Target-schema preflight (read-only). The seeder needs these columns; on
    // a database without them (for example the legacy schema) it stops here,
    // before the transaction starts, and nothing is changed. The schema is the
    // connected database (DATABASE()); no name is hard-coded.
    $requiredColumns = [
        'users'     => ['employee_id'],
        'employees' => ['first_name', 'last_name', 'employment_type', 'user_id', 'full_name', 'is_active'],
        'roles'     => ['system_key'],
    ];
    $columnCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $missingColumns = [];
    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            $columnCheck->execute([$table, $column]);
            if ((int)$columnCheck->fetchColumn() === 0) {
                $missingColumns[] = $table . '.' . $column;
            }
        }
    }
    if ($missingColumns) {
        throw new RuntimeException(
            'The connected database does not have the target schema this seeder needs (missing: '
            . implode(', ', $missingColumns) . '). Nothing was changed.'
        );
    }

    $users = [
        // Values are derived from the existing "System Administrator" seed name.
        ['admin', 'System', 'Administrator', 'admin@mulawin.com'],
    ];

    $pdo->beginTransaction();
    // Admin identity is roles.system_key = 'admin' (P21-7M + reviewed RBAC
    // seed), never a role name or numeric ID. No such active role: nothing
    // is created.
    $roleStmt = $pdo->prepare("SELECT role_id FROM roles WHERE system_key = 'admin' AND is_active = 1");
    $roleStmt->execute();
    $roleId = $roleStmt->fetchColumn();
    if ($roleId === false) {
        throw new RuntimeException('No active role has system_key admin; no seed user was created.');
    }

    $findUser = $pdo->prepare(
        'SELECT user_id, role_id, username, email, employee_id
         FROM users
         WHERE username = ? OR email = ?
         FOR UPDATE'
    );
    $insertUser = $pdo->prepare(
        'INSERT INTO users (role_id, username, email, password_hash, is_active, auth_version)
         VALUES (?, ?, ?, ?, 1, 1)'
    );
    $findEmployees = $pdo->prepare('SELECT employee_id, user_id FROM employees WHERE user_id = ? FOR UPDATE');
    $findEmployee = $pdo->prepare('SELECT employee_id, user_id FROM employees WHERE employee_id = ? FOR UPDATE');
    $insertEmployee = $pdo->prepare(
        "INSERT INTO employees (user_id, first_name, last_name, position, employment_type)
         VALUES (?, ?, ?, 'System Administrator', 'Employee')"
    );
    $linkEmployee = $pdo->prepare('UPDATE users SET employee_id = ? WHERE user_id = ?');
    $linkUser = $pdo->prepare('UPDATE employees SET user_id = ? WHERE employee_id = ?');

    foreach ($users as [$username, $firstName, $lastName, $email]) {
        $findUser->execute([$username, $email]);
        $matches = $findUser->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) > 1) {
            throw new RuntimeException("Username or email for '$username' belongs to different users.");
        }

        $userEmployeeId = null;
        if ($matches) {
            $user = $matches[0];
            if ($user['username'] !== $username || $user['email'] !== $email) {
                throw new RuntimeException("Username or email for '$username' is already in use.");
            }
            if ((int)$user['role_id'] !== (int)$roleId) {
                throw new RuntimeException("Existing user '$username' does not have the " . ROLE_LABELS[ROLE_ADMIN] . ' role; no changes were made.');
            }
            $userId = (int)$user['user_id'];
            $userEmployeeId = $user['employee_id'] === null ? null : (int)$user['employee_id'];
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $insertUser->execute([$roleId, $username, $email, $hash]);
            $userId = (int)$pdo->lastInsertId();
        }

        $findEmployees->execute([$userId]);
        $employees = $findEmployees->fetchAll(PDO::FETCH_ASSOC);
        if (count($employees) > 1) {
            throw new RuntimeException("User '$username' is linked to multiple employees.");
        }
        if ($employees) {
            $employeeForUser = (int)$employees[0]['employee_id'];
            if ($userEmployeeId !== null && $userEmployeeId !== $employeeForUser) {
                throw new RuntimeException("User '$username' has conflicting employee links.");
            }
            $employeeId = $employeeForUser;
        } elseif ($userEmployeeId !== null) {
            $employeeId = $userEmployeeId;
        } else {
            $insertEmployee->execute([$userId, $firstName, $lastName]);
            $employeeId = (int)$pdo->lastInsertId();
            syncEmployeeCompatColumns($pdo, $employeeId);
        }

        $findEmployee->execute([$employeeId]);
        $employee = $findEmployee->fetch(PDO::FETCH_ASSOC);
        if (!$employee || ($employee['user_id'] !== null && (int)$employee['user_id'] !== $userId)) {
            throw new RuntimeException("User '$username' has a conflicting employee record.");
        }
        if ($employee['user_id'] === null) {
            $linkUser->execute([$userId, $employeeId]);
        }
        if ($userEmployeeId !== $employeeId) {
            $linkEmployee->execute([$employeeId, $userId]);
        }

        echo "Seed user is present: $username (role: " . ROLE_LABELS[ROLE_ADMIN] . "), employee linked.\n";
    }

    $pdo->commit();
    echo "Seed completed.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}