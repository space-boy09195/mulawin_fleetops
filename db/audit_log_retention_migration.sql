-- Adds the audit-log retention/pruning job to the existing automation
-- framework. audit_logs previously had no retention policy at all — the
-- recycle bin's audit view just capped its query at 500 rows with no
-- cleanup. This job deletes audit_logs rows older than 365 days once
-- enabled, same on/off pattern as the other automation jobs.
INSERT INTO automation_settings (setting_key, setting_value)
VALUES ('audit_log_retention', '0')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);
