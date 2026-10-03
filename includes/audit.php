<?php
// ============================================================
// includes/audit.php
// Write to audit_logs table — call after any critical action
// ============================================================

require_once __DIR__ . '/../config/database.php';

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
 * Log a user action to audit_logs.
 *
 * @param string   $action    e.g. 'LOGIN', 'LOGOUT', 'CREATE', 'UPDATE'
 * @param string   $tableName The affected table name
 * @param int|null $recordId  The affected record's PK (if applicable)
 * @param mixed    $oldValue  Previous value (will be JSON-encoded)
 * @param mixed    $newValue  New value (will be JSON-encoded)
 */
function auditLog(
    string $action,
    string $tableName,
    ?int   $recordId = null,
    mixed  $oldValue = null,
    mixed  $newValue = null
): void {
    $pdo    = getDBConnection();
    $userId = currentUserId() ?: null;
    $ip     = auditClientIp();

    $sql = "INSERT INTO audit_logs
                (user_id, action, table_name, record_id, old_value, new_value, ip_address)
            VALUES
                (:user_id, :action, :table_name, :record_id, :old_value, :new_value, :ip)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':user_id'    => $userId,
        ':action'     => $action,
        ':table_name' => $tableName,
        ':record_id'  => $recordId,
        ':old_value'  => $oldValue !== null ? json_encode($oldValue) : null,
        ':new_value'  => $newValue !== null ? json_encode($newValue) : null,
        ':ip'         => $ip,
    ]);
}
