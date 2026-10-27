<?php
// ============================================================
// includes/audit.php
// Write to the target audit_logs table — call after any critical action.
// Target columns only (entity_type, entity_id, old_values, new_values,
// user_agent, created_at); there is deliberately no fallback to the legacy
// table_name/record_id/old_value/new_value/logged_at columns.
// ============================================================

require_once __DIR__ . '/../../config/database.php';

/**
 * Resolve a validated client address for audit attribution.
 */
function auditClientIp(): ?string {
    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($remoteAddress) || filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
        return null;
    }

    $trustedProxyIps = array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXY_IPS', ''))
    ));
    $remotePacked = inet_pton($remoteAddress);
    $isTrustedProxy = false;
    foreach ($trustedProxyIps as $trustedProxyIp) {
        if (filter_var($trustedProxyIp, FILTER_VALIDATE_IP) !== false
            && inet_pton($trustedProxyIp) === $remotePacked) {
            $isTrustedProxy = true;
            break;
        }
    }

    if (!$isTrustedProxy) {
        return $remoteAddress;
    }

    $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if (!is_string($forwardedFor) || trim($forwardedFor) === '') {
        return $remoteAddress;
    }

    // The trusted proxy must overwrite X-Forwarded-For or append the verified client IP last.
    $forwardedAddresses = explode(',', $forwardedFor);
    $candidate = trim((string) end($forwardedAddresses));
    return filter_var($candidate, FILTER_VALIDATE_IP) !== false
        ? $candidate
        : $remoteAddress;
}

/**
 * Raised when an audit record cannot be written because its data is invalid
 * or cannot be encoded. It extends PDOException on purpose: callers already
 * treat a failed audit INSERT (a PDOException) as a failed write and roll back,
 * so every audit failure takes that same path instead of being mistaken for
 * a user validation error (InvalidArgumentException) or escaping a
 * catch (PDOException) block.
 */
class AuditLogException extends PDOException {
    public function __construct(string $message, ?Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
    }
}

// Column limits of the target audit_logs table.
const AUDIT_ACTION_MAX_LENGTH      = 80;  // action VARCHAR(80)
const AUDIT_ENTITY_TYPE_MAX_LENGTH = 60;  // entity_type VARCHAR(60)
const AUDIT_USER_AGENT_MAX_LENGTH  = 500; // user_agent VARCHAR(500)

/**
 * Request user agent, made valid UTF-8 and cut to the column length.
 */
function auditUserAgent(): ?string {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (!is_string($userAgent) || trim($userAgent) === '') {
        return null;
    }
    return mb_substr(mb_scrub(trim($userAgent), 'UTF-8'), 0, AUDIT_USER_AGENT_MAX_LENGTH, 'UTF-8');
}

/**
 * JSON for the old_values/new_values columns. Invalid UTF-8 is replaced
 * rather than dropped. Values JSON cannot represent (INF/NAN, resources,
 * recursive or over-deep structures) throw AuditLogException, so the
 * caller's transaction rolls back instead of losing the audit record.
 */
function auditJson(mixed $value): ?string {
    if ($value === null) {
        return null;
    }
    try {
        return json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new AuditLogException('Audit value could not be JSON-encoded: ' . $e->getMessage(), $e);
    }
}

/**
 * Log a user action to the target audit_logs table.
 *
 * Writes on the shared connection from getDBConnection(), so a call made
 * inside a caller's transaction commits or rolls back with it. Every failure
 * throws a PDOException (AuditLogException for invalid or unencodable data)
 * to the caller; nothing is swallowed here.
 *
 * @param string   $action    e.g. 'LOGIN', 'LOGOUT', 'CREATE', 'UPDATE' (max 80 chars)
 * @param string   $tableName Affected table, stored as entity_type (max 60 chars)
 * @param int|null $recordId  Affected record's PK, stored as entity_id
 * @param mixed    $oldValue  Previous value, JSON-encoded into old_values
 * @param mixed    $newValue  New value, JSON-encoded into new_values
 */
function auditLog(
    string $action,
    string $tableName,
    ?int   $recordId = null,
    mixed  $oldValue = null,
    mixed  $newValue = null
): void {
    if ($action === '' || strlen($action) > AUDIT_ACTION_MAX_LENGTH) {
        throw new AuditLogException('Audit action must be 1-' . AUDIT_ACTION_MAX_LENGTH . ' characters.');
    }
    if ($tableName === '' || strlen($tableName) > AUDIT_ENTITY_TYPE_MAX_LENGTH) {
        throw new AuditLogException('Audit entity type must be 1-' . AUDIT_ENTITY_TYPE_MAX_LENGTH . ' characters.');
    }
    if ($recordId !== null && $recordId < 0) {
        throw new AuditLogException('Audit entity ID cannot be negative.');
    }

    $pdo    = getDBConnection();
    $userId = currentUserId() ?: null;

    $sql = "INSERT INTO audit_logs
                (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
            VALUES
                (:user_id, :action, :entity_type, :entity_id, :old_values, :new_values, :ip, :user_agent)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':user_id'     => $userId,
        ':action'      => $action,
        ':entity_type' => $tableName,
        ':entity_id'   => $recordId,
        ':old_values'  => auditJson($oldValue),
        ':new_values'  => auditJson($newValue),
        ':ip'          => auditClientIp(),
        ':user_agent'  => auditUserAgent(),
    ]);
}

// ============================================================
// Admin override audit (request-scoped)
// ============================================================
// An Admin override is an action allowed only because the actor is Admin,
// bypassing a normal role, approval-step, ownership or instruction rule.
// Expected calling order in a handler:
//   1. $reason = adminOverrideReason()          (from POST override_reason)
//      If null: adminOverrideRefused(...), then return HTTP 422.
//      A refusal never starts the failure hook.
//   2. adminOverrideBegin(...)                  (after the reason is valid)
//   3. auditAdminOverride(...)                  (inside the transaction,
//                                                before commit)
//   4. $pdo->commit();
//      adminOverrideSucceeded();                (on the very next line)
// If the request ends after step 2 without step 4, a single shutdown handler
// rolls back any open transaction and writes ADMIN_OVERRIDE_FAILED.
//
// Known limitation (ambiguous commit): if commit() throws although the
// server did commit (for example the connection drops after COMMIT), the
// override is reported as failed even though the change was saved.
//
// Context values must be IDs, keys and short labels only. Non-scalar values
// are dropped and strings are cut to 255 characters. The reason is stored
// only in these audit rows, never in business remarks.

const ADMIN_OVERRIDE_REASON_MAX_LENGTH = 500;

/**
 * The override reason from POST override_reason, trimmed, or null when it
 * is missing, not text, empty, or longer than 500 characters.
 */
function adminOverrideReason(): ?string {
    $value = $_POST['override_reason'] ?? null;
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if ($value === '' || mb_strlen($value, 'UTF-8') > ADMIN_OVERRIDE_REASON_MAX_LENGTH) {
        return null;
    }
    return $value;
}

/**
 * Request-scoped override state, shared by the helpers below.
 */
function &adminOverrideState(): array {
    static $state = [
        'begun'           => false,
        'succeeded'       => false,
        'success_audited' => false,
        'terminal_logged' => false,
        'refused_logged'  => false,
        'hook_registered' => false,
        'context'         => null,
    ];
    return $state;
}

/**
 * Keep only scalar or null values with string keys, strings cut to 255.
 */
function adminOverrideCleanContext(array $values): array {
    $clean = [];
    foreach ($values as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        if (is_string($value)) {
            $clean[$key] = mb_substr($value, 0, 255, 'UTF-8');
        } elseif ($value === null || is_int($value) || is_bool($value)
            || (is_float($value) && is_finite($value))) {
            // NAN and INF are dropped: JSON cannot encode them.
            $clean[$key] = $value;
        }
    }
    return $clean;
}

/**
 * Start an Admin override after its reason has been validated. Registers the
 * failure shutdown handler once per request. A second call in the same
 * request is ignored and logged as a programming error.
 *
 * @param string   $entityType Affected table (max 60 chars, as auditLog)
 * @param int|null $entityId   Affected record, null if not created yet
 * @param array    $bypassed   The rule bypassed, e.g. restriction, required
 *                             role ID/key, step ID/order, owner user ID
 * @param string   $reason     Validated reason from adminOverrideReason()
 */
function adminOverrideBegin(string $entityType, ?int $entityId, array $bypassed, string $reason): void {
    $state = &adminOverrideState();
    if ($state['begun']) {
        error_log('adminOverrideBegin called more than once in one request; ignored.');
        return;
    }
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason, 'UTF-8') > ADMIN_OVERRIDE_REASON_MAX_LENGTH) {
        throw new InvalidArgumentException('An Admin override needs a reason of 1-500 characters.');
    }
    // Same rule as auditLog(), checked before the shutdown handler is armed.
    if ($entityType === '' || strlen($entityType) > AUDIT_ENTITY_TYPE_MAX_LENGTH) {
        throw new InvalidArgumentException('Admin override entity type must be 1-' . AUDIT_ENTITY_TYPE_MAX_LENGTH . ' characters.');
    }
    $state['begun'] = true;
    $state['context'] = [
        'entity_type' => $entityType,
        'entity_id'   => $entityId,
        'bypassed'    => adminOverrideCleanContext($bypassed),
        'reason'      => $reason,
    ];
    if (!$state['hook_registered']) {
        $state['hook_registered'] = true;
        register_shutdown_function('adminOverrideShutdown');
    }
}

/**
 * Write the successful ADMIN_OVERRIDE event inside the business transaction,
 * before commit, so it commits or rolls back with the change. Throws like
 * auditLog(), so a failed audit write fails the action.
 *
 * @param array    $performed What was done (action, decision, new status)
 * @param int|null $entityId  Record ID if it only became known after begin
 */
function auditAdminOverride(array $performed, ?int $entityId = null): void {
    $state = &adminOverrideState();
    if (!$state['begun']) {
        throw new AuditLogException('auditAdminOverride called before adminOverrideBegin.');
    }
    if ($state['success_audited']) {
        error_log('auditAdminOverride called more than once in one request; ignored.');
        return;
    }
    if ($entityId !== null) {
        $state['context']['entity_id'] = $entityId;
    }
    $context = $state['context'];
    // The validated reason and the outcome are set last, so caller-supplied
    // keys with the same names can never replace them.
    $details = adminOverrideCleanContext($performed);
    $details['reason']  = $context['reason'];
    $details['outcome'] = 'succeeded';
    auditLog(
        'ADMIN_OVERRIDE',
        $context['entity_type'],
        $context['entity_id'],
        $context['bypassed'],
        $details
    );
    $state['success_audited'] = true;
}

/**
 * Mark the override successful. Call on the line right after the business
 * transaction's commit() returns. After this the shutdown handler does
 * nothing.
 */
function adminOverrideSucceeded(): void {
    $state = &adminOverrideState();
    // Without its ADMIN_OVERRIDE row an override is not recorded as
    // successful: the flag stays unset so the shutdown handler writes
    // ADMIN_OVERRIDE_FAILED with the request's HTTP status and context.
    if ($state['begun'] && !$state['success_audited']) {
        error_log('adminOverrideSucceeded called before auditAdminOverride wrote the ADMIN_OVERRIDE event; not marked successful.');
        return;
    }
    $state['succeeded'] = true;
}

/**
 * Best-effort record of an override refused because no valid reason was
 * given. Rolls back any open transaction on the shared connection, writes
 * ADMIN_OVERRIDE_REFUSED on its own, never throws, never starts the failure
 * hook. Call immediately before returning the HTTP 422 refusal.
 */
function adminOverrideRefused(string $entityType, ?int $entityId, array $bypassed): void {
    $state = &adminOverrideState();
    if ($state['refused_logged']) {
        return;
    }
    $state['refused_logged'] = true;
    // A refusal is the terminal event for an override that already began, so
    // the shutdown handler must not add ADMIN_OVERRIDE_FAILED for it.
    if ($state['begun']) {
        $state['terminal_logged'] = true;
    }
    // Same rule as auditLog(): an invalid entity type skips the write safely.
    if ($entityType === '' || strlen($entityType) > AUDIT_ENTITY_TYPE_MAX_LENGTH) {
        error_log('Admin override refusal not audited: entity type must be 1-' . AUDIT_ENTITY_TYPE_MAX_LENGTH . ' characters.');
        return;
    }
    try {
        $pdo = getDBConnection();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        auditLog(
            'ADMIN_OVERRIDE_REFUSED',
            $entityType,
            $entityId,
            adminOverrideCleanContext($bypassed),
            ['outcome' => 'refused', 'refusal' => 'missing_or_invalid_reason']
        );
    } catch (Throwable $e) {
        error_log('Admin override refusal could not be audited: ' . $e->getMessage());
    }
}

/**
 * Shutdown handler, registered at most once by adminOverrideBegin(). Writes
 * ADMIN_OVERRIDE_FAILED once for an override that began but was not marked
 * successful. Best-effort: never throws, produces no output.
 */
function adminOverrideShutdown(): void {
    $state = &adminOverrideState();
    if (!$state['begun'] || $state['succeeded'] || $state['terminal_logged']) {
        return;
    }
    $state['terminal_logged'] = true;
    try {
        $pdo = getDBConnection();
        // The failed request's uncommitted work must not absorb this row.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = http_response_code();
        $lastError = error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
        $context = $state['context'];
        auditLog(
            'ADMIN_OVERRIDE_FAILED',
            $context['entity_type'],
            $context['entity_id'],
            $context['bypassed'],
            [
                'reason'           => $context['reason'],
                'outcome'          => 'failed',
                'http_status'      => is_int($status) ? $status : null,
                'fatal_error_type' => ($lastError !== null && in_array($lastError['type'], $fatalTypes, true))
                    ? $lastError['type']
                    : null,
            ]
        );
    } catch (Throwable $e) {
        error_log('Admin override failure could not be audited: ' . $e->getMessage());
    }
}
