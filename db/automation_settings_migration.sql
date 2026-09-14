-- Automation control settings.
-- This creates controls only; no automated job is enabled or scheduled by this migration.
CREATE TABLE IF NOT EXISTS automation_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value VARCHAR(20) NOT NULL DEFAULT '0',
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_automation_settings_user
        FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
);

INSERT INTO automation_settings (setting_key, setting_value)
VALUES ('automation_engine_enabled', '0')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

CREATE TABLE IF NOT EXISTS automation_runs (
    run_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_key VARCHAR(100) NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    status ENUM('running', 'success', 'failed') NOT NULL DEFAULT 'running',
    items_processed INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(500) NULL,
    PRIMARY KEY (run_id),
    INDEX idx_automation_runs_job (job_key, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_deliveries (
    delivery_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_key VARCHAR(100) NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (delivery_id),
    UNIQUE KEY uq_automation_delivery (job_key, fingerprint, user_id),
    CONSTRAINT fk_automation_delivery_user
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO automation_settings (setting_key, setting_value)
VALUES
    ('pending_approval_reminders', '0'),
    ('expiry_reminders', '0'),
    ('maintenance_due_reminders', '0'),
    ('unpaid_billing_reminders', '0'),
    ('daily_analytics_summary', '0'),
    ('system_health_checks', '0')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);
