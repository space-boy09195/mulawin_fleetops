<?php

require_once __DIR__ . '/../config/database.php';

function getAppSetting(PDO $pdo, string $key, ?string $default = null): ?string {
    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string)$value;
}

function setAppSetting(PDO $pdo, string $key, string $value, ?int $updatedBy): void {
    $stmt = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
    );
    $stmt->execute([$key, $value, $updatedBy]);
}

function isCompanyEmail(string $email, string $domain): bool {
    $at = strrpos($email, '@');
    if ($at === false || $domain === '') {
        return false;
    }
    return strtolower(substr($email, $at + 1)) === strtolower(ltrim($domain, '@'));
}
