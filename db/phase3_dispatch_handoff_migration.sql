ALTER TABLE dispatch_requests
  ADD COLUMN shift ENUM('Day', 'Night') NOT NULL DEFAULT 'Day' AFTER scheduled_at,
  ADD COLUMN dispatch_instruction_id INT UNSIGNED NULL AFTER shift,
  ADD UNIQUE KEY uq_dispatch_instruction (dispatch_instruction_id),
  ADD INDEX idx_dispatch_shift_schedule (shift, scheduled_at);

ALTER TABLE trips
  ADD COLUMN shift ENUM('Day', 'Night') NOT NULL DEFAULT 'Day' AFTER status,
  ADD INDEX idx_trip_shift_status (shift, status);

CREATE TABLE dispatch_instructions (
  instruction_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  shift_date DATE NOT NULL,
  shift ENUM('Day', 'Night') NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  route_id INT UNSIGNED NOT NULL,
  scheduled_at DATETIME NOT NULL,
  unit_count DECIMAL(8,2) NULL,
  instruction_notes TEXT NULL,
  status ENUM('Open', 'Encoded', 'Cancelled') NOT NULL DEFAULT 'Open',
  created_by INT UNSIGNED NOT NULL,
  encoded_by INT UNSIGNED NULL,
  dispatch_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  encoded_at DATETIME NULL,
  PRIMARY KEY (instruction_id),
  UNIQUE KEY uq_dispatch_instruction_dispatch (dispatch_id),
  INDEX idx_dispatch_instruction_inbox (status, shift_date, shift, scheduled_at),
  CONSTRAINT fk_dispatch_instruction_client
    FOREIGN KEY (client_id) REFERENCES clients (client_id),
  CONSTRAINT fk_dispatch_instruction_route
    FOREIGN KEY (route_id) REFERENCES routes (route_id),
  CONSTRAINT fk_dispatch_instruction_creator
    FOREIGN KEY (created_by) REFERENCES users (user_id),
  CONSTRAINT fk_dispatch_instruction_encoder
    FOREIGN KEY (encoded_by) REFERENCES users (user_id) ON DELETE SET NULL,
  CONSTRAINT fk_dispatch_instruction_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES dispatch_requests (dispatch_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE dispatch_requests
  ADD CONSTRAINT fk_dispatch_instruction
    FOREIGN KEY (dispatch_instruction_id)
    REFERENCES dispatch_instructions (instruction_id) ON DELETE SET NULL;

INSERT IGNORE INTO permissions (permission_key, module_name, action_name, description) VALUES
  ('dispatch.instructions.manage', 'dispatch', 'manage_instructions', 'Create and cancel shift dispatch instructions'),
  ('dispatch.instructions.encode', 'dispatch', 'encode_instructions', 'Encode Operations Head dispatch instructions');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, permission_id
FROM permissions;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name = 'Operations Head'
  AND p.permission_key = 'dispatch.instructions.manage';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name = 'Dispatcher'
  AND p.permission_key = 'dispatch.instructions.encode';
