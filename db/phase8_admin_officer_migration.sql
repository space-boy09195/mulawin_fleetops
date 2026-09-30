INSERT IGNORE INTO permissions (permission_key, module_name, action_name, description) VALUES
  ('hr.recruitment.view', 'hr', 'view_recruitment', 'View recruitment candidates'),
  ('hr.recruitment.manage', 'hr', 'manage_recruitment', 'Create and update recruitment candidates'),
  ('admin.supplies.view', 'admin', 'view_supplies', 'View office supplies inventory'),
  ('admin.supplies.manage', 'admin', 'manage_supplies', 'Manage office supplies inventory'),
  ('operations.reports.view', 'operations', 'view_reports', 'View filtered operations performance reports'),
  ('maintenance.reports.view', 'maintenance', 'view_reports', 'View maintenance downtime and cost reports');

CREATE TABLE IF NOT EXISTS recruitment_candidates (
  candidate_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  candidate_number VARCHAR(40) NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  desired_position VARCHAR(100) NOT NULL,
  phone VARCHAR(50) NULL,
  email VARCHAR(150) NULL,
  applied_on DATE NOT NULL,
  source VARCHAR(100) NULL,
  status ENUM('New','Screening','Interview','Reference Check','Offer','Hired','Rejected','Withdrawn')
    NOT NULL DEFAULT 'New',
  notes TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (candidate_id),
  UNIQUE KEY uq_recruitment_candidate_number (candidate_number),
  KEY idx_recruitment_candidates_status (status, applied_on),
  CONSTRAINT fk_recruitment_candidates_creator FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS office_supply_items (
  item_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_name VARCHAR(150) NOT NULL,
  unit VARCHAR(50) NOT NULL DEFAULT 'piece',
  quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
  reorder_level DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (item_id),
  UNIQUE KEY uq_office_supply_items_name (item_name),
  CONSTRAINT fk_office_supply_items_creator FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS office_supply_movements (
  movement_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id INT UNSIGNED NOT NULL,
  movement_type ENUM('Stock In','Issue','Adjustment') NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  quantity_change DECIMAL(12,2) NOT NULL,
  reference_number VARCHAR(100) NULL,
  notes VARCHAR(500) NULL,
  recorded_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (movement_id),
  KEY idx_office_supply_movements_item (item_id, created_at),
  CONSTRAINT fk_office_supply_movements_item FOREIGN KEY (item_id) REFERENCES office_supply_items (item_id),
  CONSTRAINT fk_office_supply_movements_user FOREIGN KEY (recorded_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name = 'Admin'
  AND p.permission_key IN (
  'hr.recruitment.view', 'hr.recruitment.manage',
  'admin.supplies.view', 'admin.supplies.manage',
  'operations.reports.view', 'maintenance.reports.view'
);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name = 'Admin Officer'
  AND p.permission_key IN (
  'hr.recruitment.view', 'hr.recruitment.manage',
  'admin.supplies.view', 'admin.supplies.manage'
);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name IN ('Management / Head', 'Operations Head')
  AND p.permission_key = 'operations.reports.view';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name IN ('Management / Head', 'Operations Head', 'Maintenance')
  AND p.permission_key = 'maintenance.reports.view';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name = 'Management / Head'
  AND p.permission_key = 'approvals.review';
