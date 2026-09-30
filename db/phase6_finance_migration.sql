INSERT IGNORE INTO permissions (permission_key, module_name, action_name, description) VALUES
  ('finance.funds.view', 'finance', 'view_funds', 'View fund requests and cash advances'),
  ('finance.funds.manage', 'finance', 'manage_funds', 'Create and manage fund requests and cash advances'),
  ('finance.disbursements.view', 'finance', 'view_disbursements', 'View disbursement records'),
  ('finance.disbursements.manage', 'finance', 'manage_disbursements', 'Record approved disbursements'),
  ('finance.ap.view', 'finance', 'view_payables', 'View accounts payable and payment vouchers'),
  ('finance.ap.manage', 'finance', 'manage_payables', 'Create accounts payable and payment vouchers');

CREATE TABLE IF NOT EXISTS fund_requests (
  fund_request_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_number VARCHAR(40) NOT NULL,
  requested_by INT UNSIGNED NOT NULL,
  request_type ENUM('Trip Allowance','Cash Advance','Operating Fund','Other') NOT NULL,
  trip_id INT UNSIGNED NULL,
  purpose VARCHAR(255) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  approval_id BIGINT UNSIGNED NULL,
  status ENUM('Pending Approval','Approved','Rejected','Disbursed','Settled','Cancelled')
    NOT NULL DEFAULT 'Pending Approval',
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes TEXT NULL,
  PRIMARY KEY (fund_request_id),
  UNIQUE KEY uq_fund_requests_number (request_number),
  KEY idx_fund_requests_status (status),
  KEY idx_fund_requests_trip (trip_id),
  KEY idx_fund_requests_approval (approval_id),
  CONSTRAINT fk_fund_requests_requester FOREIGN KEY (requested_by) REFERENCES users (user_id),
  CONSTRAINT fk_fund_requests_trip FOREIGN KEY (trip_id) REFERENCES trips (trip_id),
  CONSTRAINT fk_fund_requests_approval FOREIGN KEY (approval_id) REFERENCES approval_requests (approval_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_disbursements (
  disbursement_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  disbursement_number VARCHAR(40) NOT NULL,
  fund_request_id INT UNSIGNED NULL,
  payee_name VARCHAR(150) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  payment_mode VARCHAR(50) NOT NULL,
  reference_no VARCHAR(100) NULL,
  disbursed_by INT UNSIGNED NOT NULL,
  disbursed_at DATE NOT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (disbursement_id),
  UNIQUE KEY uq_finance_disbursements_number (disbursement_number),
  KEY idx_finance_disbursements_request (fund_request_id),
  KEY idx_finance_disbursements_date (disbursed_at),
  CONSTRAINT fk_finance_disbursements_request FOREIGN KEY (fund_request_id) REFERENCES fund_requests (fund_request_id),
  CONSTRAINT fk_finance_disbursements_user FOREIGN KEY (disbursed_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounts_payable (
  payable_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  payable_number VARCHAR(40) NOT NULL,
  supplier_name VARCHAR(150) NOT NULL,
  invoice_number VARCHAR(100) NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  due_date DATE NULL,
  status ENUM('Open','Partially Paid','Paid','Cancelled') NOT NULL DEFAULT 'Open',
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (payable_id),
  UNIQUE KEY uq_accounts_payable_number (payable_number),
  KEY idx_accounts_payable_status (status),
  KEY idx_accounts_payable_due_date (due_date),
  CONSTRAINT fk_accounts_payable_user FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_vouchers (
  voucher_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  voucher_number VARCHAR(40) NOT NULL,
  payable_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  approval_id BIGINT UNSIGNED NULL,
  status ENUM('Pending Approval','Approved','Paid','Rejected','Cancelled')
    NOT NULL DEFAULT 'Pending Approval',
  prepared_by INT UNSIGNED NOT NULL,
  prepared_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes TEXT NULL,
  PRIMARY KEY (voucher_id),
  UNIQUE KEY uq_payment_vouchers_number (voucher_number),
  KEY idx_payment_vouchers_payable (payable_id),
  KEY idx_payment_vouchers_status (status),
  KEY idx_payment_vouchers_approval (approval_id),
  CONSTRAINT fk_payment_vouchers_payable FOREIGN KEY (payable_id) REFERENCES accounts_payable (payable_id),
  CONSTRAINT fk_payment_vouchers_approval FOREIGN KEY (approval_id) REFERENCES approval_requests (approval_id),
  CONSTRAINT fk_payment_vouchers_user FOREIGN KEY (prepared_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE finance_disbursements
  ADD COLUMN IF NOT EXISTS payment_voucher_id INT UNSIGNED NULL,
  ADD KEY IF NOT EXISTS idx_finance_disbursements_voucher (payment_voucher_id),
  ADD CONSTRAINT fk_finance_disbursements_voucher
    FOREIGN KEY (payment_voucher_id) REFERENCES payment_vouchers (voucher_id);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'finance.funds.view', 'finance.funds.manage',
  'finance.disbursements.view', 'finance.disbursements.manage',
  'finance.ap.view', 'finance.ap.manage'
)
WHERE r.role_name IN ('Admin', 'Finance');

INSERT IGNORE INTO approval_request_types (request_type, display_name)
VALUES ('fund_request', 'Fund Request');

INSERT IGNORE INTO approval_role_steps (request_type, step_order, approver_role_id)
SELECT 'fund_request', 1, r.role_id
FROM roles r
WHERE r.role_name = 'Management / Head';

UPDATE approval_role_steps ars
JOIN roles r ON r.role_name = 'Management / Head'
SET ars.approver_role_id = r.role_id
WHERE ars.request_type = 'fund_request' AND ars.step_order = 1;
