<?php

function requestIdempotencyKey(): ?string {
    $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
    if (!is_string($key) || !preg_match('/^[A-Za-z0-9._-]{16,80}$/', $key)) {
        return null;
    }
    return $key;
}

function claimIdempotencyKey(PDO $pdo, string $actionKey, string $requestKey): bool {
    $pdo->exec('DELETE FROM idempotency_requests WHERE expires_at < NOW()');
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO idempotency_requests
                (user_id, action_key, request_key, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))'
        );
        $stmt->execute([currentUserId(), $actionKey, $requestKey]);
        return true;
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) === 1062) {
            return false;
        }
        throw $e;
    }
}
