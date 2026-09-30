CREATE TABLE IF NOT EXISTS permissions (
  permission_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  permission_key VARCHAR(100) NOT NULL,
  module_name VARCHAR(50) NOT NULL,
  action_name VARCHAR(50) NOT NULL,
  description VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (permission_id),
  UNIQUE KEY uq_permissions_key (permission_key),
  INDEX idx_permissions_module (module_name, action_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id)
    REFERENCES roles (role_id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id)
    REFERENCES permissions (permission_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(100) NOT NULL,
  setting_value TEXT NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key),
  CONSTRAINT fk_app_settings_updater FOREIGN KEY (updated_by)
    REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_role_steps (
  request_type VARCHAR(50) NOT NULL,
  step_order SMALLINT UNSIGNED NOT NULL,
  approver_role_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (request_type, step_order),
  INDEX idx_approval_role_steps_role (approver_role_id),
  CONSTRAINT fk_approval_role_steps_role FOREIGN KEY (approver_role_id)
    REFERENCES roles (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_request_types (
  request_type VARCHAR(50) NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (request_type),
  INDEX idx_approval_request_types_active (is_active, display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_requests (
  approval_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_type VARCHAR(50) NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  requested_by INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Pending',
  current_step SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  PRIMARY KEY (approval_id),
  INDEX idx_approval_entity (entity_type, entity_id, request_type),
  INDEX idx_approval_status (status, requested_at),
  CONSTRAINT fk_approval_requests_requester FOREIGN KEY (requested_by)
    REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_steps (
  approval_step_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  approval_id BIGINT UNSIGNED NOT NULL,
  step_order SMALLINT UNSIGNED NOT NULL,
  approver_role_id INT UNSIGNED NOT NULL,
  assigned_to INT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Pending',
  decided_by INT UNSIGNED NULL,
  decision_comment TEXT NULL,
  decided_at DATETIME NULL,
  PRIMARY KEY (approval_step_id),
  UNIQUE KEY uq_approval_steps_order (approval_id, step_order),
  INDEX idx_approval_steps_inbox (approver_role_id, status, assigned_to),
  CONSTRAINT fk_approval_steps_request FOREIGN KEY (approval_id)
    REFERENCES approval_requests (approval_id) ON DELETE CASCADE,
  CONSTRAINT fk_approval_steps_role FOREIGN KEY (approver_role_id)
    REFERENCES roles (role_id),
  CONSTRAINT fk_approval_steps_assignee FOREIGN KEY (assigned_to)
    REFERENCES users (user_id) ON DELETE SET NULL,
  CONSTRAINT fk_approval_steps_decider FOREIGN KEY (decided_by)
    REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_history (
  history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  approval_id BIGINT UNSIGNED NOT NULL,
  approval_step_id BIGINT UNSIGNED NULL,
  actor_user_id INT UNSIGNED NULL,
  action VARCHAR(30) NOT NULL,
  comment TEXT NULL,
  old_status VARCHAR(20) NULL,
  new_status VARCHAR(20) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (history_id),
  INDEX idx_approval_history_request (approval_id, created_at),
  CONSTRAINT fk_approval_history_request FOREIGN KEY (approval_id)
    REFERENCES approval_requests (approval_id) ON DELETE CASCADE,
  CONSTRAINT fk_approval_history_step FOREIGN KEY (approval_step_id)
    REFERENCES approval_steps (approval_step_id) ON DELETE SET NULL,
  CONSTRAINT fk_approval_history_actor FOREIGN KEY (actor_user_id)
    REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attachments (
  attachment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_type VARCHAR(50) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  category VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  linked_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (attachment_id),
  INDEX idx_attachments_entity (entity_type, entity_id, category),
  CONSTRAINT fk_attachments_linker FOREIGN KEY (linked_by)
    REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attachment_versions (
  version_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attachment_id BIGINT UNSIGNED NOT NULL,
  document_id INT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  uploaded_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version_id),
  UNIQUE KEY uq_attachment_version_number (attachment_id, version_number),
  UNIQUE KEY uq_attachment_document (attachment_id, document_id),
  INDEX idx_attachment_versions_document (document_id),
  CONSTRAINT fk_attachment_versions_attachment FOREIGN KEY (attachment_id)
    REFERENCES attachments (attachment_id) ON DELETE CASCADE,
  CONSTRAINT fk_attachment_versions_uploader FOREIGN KEY (uploaded_by)
    REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS idempotency_requests (
  idempotency_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  action_key VARCHAR(100) NOT NULL,
  request_key VARCHAR(80) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (idempotency_id),
  UNIQUE KEY uq_idempotency_user_action_key (user_id, action_key, request_key),
  INDEX idx_idempotency_expiry (expires_at),
  CONSTRAINT fk_idempotency_user FOREIGN KEY (user_id)
    REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reminder_deliveries (
  delivery_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipient_user_id INT UNSIGNED NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  reminder_days SMALLINT UNSIGNED NOT NULL,
  due_date DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (delivery_id),
  UNIQUE KEY uq_reminder_delivery (recipient_user_id, entity_type, entity_id, reminder_days, due_date),
  CONSTRAINT fk_reminder_deliveries_recipient FOREIGN KEY (recipient_user_id)
    REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (permission_key, module_name, action_name, description) VALUES
  ('legacy.role.1', 'legacy', 'head_management', 'Access granted to legacy Head Management surfaces'),
  ('legacy.role.2', 'legacy', 'dispatcher', 'Access granted to legacy Dispatcher surfaces'),
  ('legacy.role.3', 'legacy', 'maintenance', 'Access granted to legacy Maintenance surfaces'),
  ('legacy.role.4', 'legacy', 'accounting', 'Access granted to legacy Accounting surfaces'),
  ('roles.manage', 'admin', 'manage_roles', 'Create and manage system roles'),
  ('permissions.manage', 'admin', 'manage_permissions', 'Manage role permission grants'),
  ('approvals.manage', 'approvals', 'manage_chains', 'Configure approver roles and approval order'),
  ('users.view', 'users', 'view', 'View user and employee accounts'),
  ('users.manage', 'users', 'manage', 'Create, edit, and deactivate user accounts'),
  ('fleet.view', 'fleet', 'view', 'View fleet records and availability'),
  ('fleet.manage', 'fleet', 'manage', 'Create and update fleet records'),
  ('fleet.status.update', 'fleet', 'update_status', 'Update vehicle availability and status'),
  ('employees.view', 'employees', 'view', 'View employee and crew records'),
  ('employees.manage', 'employees', 'manage', 'Manage employee and crew records'),
  ('clients.view', 'clients', 'view', 'View client records'),
  ('clients.manage', 'clients', 'manage', 'Manage client records'),
  ('routes.view', 'routes', 'view', 'View routes and locations'),
  ('routes.manage', 'routes', 'manage', 'Manage routes and locations'),
  ('trips.view', 'trips', 'view', 'View trips and dispatch requests'),
  ('trips.create', 'trips', 'create', 'Create trip or dispatch requests'),
  ('trips.update', 'trips', 'update', 'Update trip information and progress'),
  ('trips.assign', 'trips', 'assign', 'Assign trucks and crew to trips'),
  ('trips.approve', 'trips', 'approve', 'Approve trip requests'),
  ('dispatch.clear', 'dispatch', 'clear', 'Grant dispatch clearance'),
  ('trips.complete', 'trips', 'complete', 'Submit completed trip records'),
  ('approvals.review', 'approvals', 'review', 'Review assigned approval requests'),
  ('maintenance.view', 'maintenance', 'view', 'View maintenance history and jobs'),
  ('maintenance.manage', 'maintenance', 'manage', 'Create and update maintenance records'),
  ('parts.view', 'inventory', 'view', 'View inventory and stock movements'),
  ('parts.manage', 'inventory', 'manage', 'Manage inventory stock and adjustments'),
  ('billing.view', 'billing', 'view', 'View billing and collection records'),
  ('billing.manage', 'billing', 'manage', 'Manage billing and collection records'),
  ('finance.view', 'finance', 'view', 'View finance records and reports'),
  ('finance.manage', 'finance', 'manage', 'Manage finance records'),
  ('payroll.view', 'payroll', 'view', 'View payroll records'),
  ('payroll.manage', 'payroll', 'manage', 'Prepare and manage payroll'),
  ('documents.view', 'documents', 'view', 'View authorized documents'),
  ('documents.upload', 'documents', 'upload', 'Upload documents'),
  ('documents.download', 'documents', 'download', 'Download authorized documents'),
  ('documents.manage', 'documents', 'manage', 'Manage document records'),
  ('reports.view', 'reports', 'view', 'View reports'),
  ('reports.export', 'reports', 'export', 'Export report data'),
  ('audit.view', 'audit', 'view', 'View audit history'),
  ('messages.view', 'messages', 'view', 'View authorized conversations'),
  ('messages.send', 'messages', 'send', 'Send authorized messages');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_id = 1
;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'legacy.role.2', 'trips.view', 'trips.create', 'trips.update', 'trips.assign',
  'trips.complete', 'fleet.view', 'employees.view', 'clients.view', 'routes.view',
  'documents.view', 'documents.upload', 'documents.download',
  'reports.view', 'reports.export'
)
WHERE r.role_id = 2;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'legacy.role.3', 'fleet.view', 'fleet.status.update', 'maintenance.view',
  'maintenance.manage', 'parts.view', 'parts.manage', 'documents.view',
  'documents.upload', 'documents.download', 'reports.view'
)
WHERE r.role_id = 3;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'legacy.role.4', 'clients.view', 'billing.view', 'billing.manage',
  'finance.view', 'finance.manage', 'payroll.view', 'payroll.manage',
  'documents.view', 'documents.upload', 'documents.download', 'documents.manage', 'reports.view',
  'reports.export'
)
WHERE r.role_id = 4;

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
  ('reminder_thresholds_days', '60,30,15,7'),
  ('timezone', 'Asia/Manila');

INSERT IGNORE INTO approval_request_types (request_type, display_name) VALUES
  ('dispatch', 'Dispatch request'),
  ('trip_request', 'Trip request'),
  ('purchase_request', 'Purchase request'),
  ('fund_request', 'Fund request'),
  ('voucher', 'Payment voucher'),
  ('payroll', 'Payroll');

INSERT IGNORE INTO approval_role_steps (request_type, step_order, approver_role_id)
SELECT request_type, 1, 1
FROM (
  SELECT 'dispatch' AS request_type
  UNION ALL SELECT 'trip_request'
  UNION ALL SELECT 'purchase_request'
  UNION ALL SELECT 'fund_request'
  UNION ALL SELECT 'voucher'
  UNION ALL SELECT 'payroll'
) AS default_steps
WHERE EXISTS (SELECT 1 FROM roles WHERE role_id = 1);
