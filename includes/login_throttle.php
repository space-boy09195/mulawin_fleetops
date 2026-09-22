<?php
// ============================================================
// includes/login_throttle.php
// Simple rolling-window rate limiter for the login form.
// Blocks further attempts once too many failures have happened
// for a given username OR a given IP within the window — either
// one tripping is enough to block, so this catches both a
// targeted attack on one account and a spray attack across many
// accounts from one IP.
// ============================================================

const LOGIN_MAX_ATTEMPTS   = 5;
const LOGIN_WINDOW_MINUTES = 15;

/**
 * Returns true if either the username or the IP has hit the failed-attempt
 * ceiling within the rolling window, meaning this login attempt should be
 * blocked before even checking the password.
 *
 * Fails open (returns false — attempt allowed) if the login_attempts table
 * doesn't exist yet, so a missed migration disables the throttle rather
 * than locking every user out of the login page entirely.
 */
function loginAttemptsExceeded(PDO $pdo, string $identifier, ?string $ip): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts
              WHERE identifier = :id AND attempted_at > DATE_SUB(NOW(), INTERVAL :mins MINUTE)"
        );
        $stmt->execute([':id' => $identifier, ':mins' => LOGIN_WINDOW_MINUTES]);
        if ((int)$stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS) {
            return true;
        }

        if ($ip !== null) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM login_attempts
                  WHERE ip_address = :ip AND attempted_at > DATE_SUB(NOW(), INTERVAL :mins MINUTE)"
            );
            $stmt->execute([':ip' => $ip, ':mins' => LOGIN_WINDOW_MINUTES]);
            // A slightly higher ceiling for the IP check alone, since a shared
            // office/campus connection can legitimately have several people
            // logging in around the same time.
            if ((int)$stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS * 3) {
                return true;
            }
        }

        return false;
    } catch (PDOException $e) {
        error_log('login_throttle: ' . $e->getMessage() . ' — is db/login_attempts_migration.sql applied?');
        return false;
    }
}

function recordFailedLoginAttempt(PDO $pdo, string $identifier, ?string $ip): void {
    try {
        $pdo->prepare("INSERT INTO login_attempts (identifier, ip_address) VALUES (?, ?)")
            ->execute([$identifier, $ip]);
    } catch (PDOException $e) {
        error_log('login_throttle: ' . $e->getMessage());
    }
}

/** Called on a successful login so a legitimate user who mistyped their
 * password a few times isn't left sitting near the lockout threshold. */
function clearLoginAttempts(PDO $pdo, string $identifier): void {
    try {
        $pdo->prepare("DELETE FROM login_attempts WHERE identifier = ?")->execute([$identifier]);
    } catch (PDOException $e) {
        error_log('login_throttle: ' . $e->getMessage());
    }
}
