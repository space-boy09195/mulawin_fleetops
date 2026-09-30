CREATE TABLE IF NOT EXISTS payroll_deductions (
  deduction_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  payroll_id INT UNSIGNED NOT NULL,
  deduction_name VARCHAR(100) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (deduction_id),
  KEY idx_payroll_deductions_payroll (payroll_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
