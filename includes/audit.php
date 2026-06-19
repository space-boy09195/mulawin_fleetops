<?php
// ============================================================
// includes/audit.php
// Write to audit_logs table — call after any critical action
// ============================================================

require_once __DIR__ . '/../config/database.php';

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
    $ip     = $_SERVER['HTTP_X_FORWARDED_FOR']
           ?? $_SERVER['REMOTE_ADDR']
           ?? null;

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
