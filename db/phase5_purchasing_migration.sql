INSERT IGNORE INTO permissions (permission_key, module_name, action_name, description) VALUES
  ('purchasing.view', 'purchasing', 'view', 'View suppliers and purchase orders'),
  ('purchasing.manage', 'purchasing', 'manage', 'Create and receive purchase orders');

CREATE TABLE IF NOT EXISTS suppliers (
  supplier_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  supplier_name VARCHAR(150) NOT NULL,
  contact_person VARCHAR(150) NULL,
  phone VARCHAR(50) NULL,
  email VARCHAR(150) NULL,
  address VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (supplier_id),
  UNIQUE KEY uq_supplier_name (supplier_name),
  KEY idx_suppliers_active (is_active),
  CONSTRAINT fk_suppliers_created_by FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_headers (
  purchase_order_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  po_number VARCHAR(40) NOT NULL,
  supplier_id INT UNSIGNED NOT NULL,
  requested_by INT UNSIGNED NOT NULL,
  approval_id BIGINT UNSIGNED NULL,
  status ENUM('Pending Approval','Approved','Ordered','Partially Received','Received','Cancelled')
    NOT NULL DEFAULT 'Pending Approval',
  ordered_at DATETIME NULL,
  expected_at DATE NULL,
  notes TEXT NULL,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (purchase_order_id),
  UNIQUE KEY uq_purchase_order_headers_number (po_number),
  KEY idx_purchase_order_headers_status (status),
  KEY idx_purchase_order_headers_supplier (supplier_id),
  KEY idx_purchase_order_headers_approval (approval_id),
  CONSTRAINT fk_purchase_order_headers_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (supplier_id),
  CONSTRAINT fk_purchase_order_headers_requester FOREIGN KEY (requested_by) REFERENCES users (user_id),
  CONSTRAINT fk_purchase_order_headers_approval FOREIGN KEY (approval_id) REFERENCES approval_requests (approval_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_lines (
  purchase_order_item_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  purchase_order_id INT UNSIGNED NOT NULL,
  part_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  unit_cost DECIMAL(14,2) NOT NULL,
  received_quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (purchase_order_item_id),
  KEY idx_purchase_order_lines_order (purchase_order_id),
  KEY idx_purchase_order_lines_part (part_id),
  CONSTRAINT fk_purchase_order_lines_order FOREIGN KEY (purchase_order_id)
    REFERENCES purchase_order_headers (purchase_order_id) ON DELETE CASCADE,
  CONSTRAINT fk_purchase_order_lines_part FOREIGN KEY (part_id)
    REFERENCES parts_inventory (part_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN ('purchasing.view', 'purchasing.manage')
WHERE r.role_name IN ('Admin', 'Purchasing Officer');
