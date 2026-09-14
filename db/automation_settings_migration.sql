-- Automation control settings.
-- This creates controls only; no automated job is enabled or scheduled by this migration.
CREATE TABLE IF NOT EXISTS automation_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value VARCHAR(20) NOT NULL DEFAULT '0',
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_automation_settings_user
        FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
);

INSERT INTO automation_settings (setting_key, setting_value)
VALUES ('automation_engine_enabled', '0')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);
