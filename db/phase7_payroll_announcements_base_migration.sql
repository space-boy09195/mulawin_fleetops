-- Phase 14 bootstrap fix: create the `payroll_records` and `announcements`
-- base tables. Both were referenced by later migrations/app code
-- (phase7_payroll_components_migration.sql, phase7_payroll_deduction_items_
-- migration.sql, announcement_duration_audience_migration.sql, and the
-- payroll/announcements pages/handlers) but neither table was ever actually
-- created by any migration file — `announcements`' schema only existed as
-- documentation in db/announcement.md, and `payroll_records` had no CREATE
-- statement anywhere. Pages already degrade gracefully (see pages/billing.php,
-- pages/analytics.php) when payroll_records is missing, which is how this
-- gap stayed invisible. Apply this file before phase7_payroll_components_
-- migration.sql / phase7_payroll_deduction_items_migration.sql and before
-- announcement_duration_audience_migration.sql. Safe to re-run (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS payroll_records (
  payroll_id        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  employee_id       INT UNSIGNED  NOT NULL,
  pay_period_start  DATE          NOT NULL,
  pay_period_end    DATE          NOT NULL,
  amount_paid       DECIMAL(14,2) NOT NULL,
  paid_date         DATE          NOT NULL,
  notes             VARCHAR(500)  NULL,
  recorded_by       INT UNSIGNED  NOT NULL,
  created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (payroll_id),
  KEY idx_payroll_records_employee (employee_id),
  KEY idx_payroll_records_paid_date (paid_date),
  CONSTRAINT fk_payroll_records_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id),
  CONSTRAINT fk_payroll_records_recorder FOREIGN KEY (recorded_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
  announcement_id INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  created_by      INT UNSIGNED  NOT NULL,
  title           VARCHAR(200)  NOT NULL,
  body            TEXT          NOT NULL,
  is_pinned       TINYINT(1)    NOT NULL DEFAULT 0,
  created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (announcement_id),
  INDEX idx_announcements_pinned (is_pinned, created_at),
  CONSTRAINT fk_announcements_creator FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
