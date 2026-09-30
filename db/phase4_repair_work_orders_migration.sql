CREATE TABLE repair_work_orders (
  work_order_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  truck_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  description TEXT NOT NULL,
  priority ENUM('Low', 'Normal', 'High', 'Urgent') NOT NULL DEFAULT 'Normal',
  status ENUM('Open', 'In Progress', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Open',
  expected_completion_at DATETIME NULL,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  closure_notes VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (work_order_id),
  INDEX idx_repair_work_order_truck_status (truck_id, status),
  INDEX idx_repair_work_order_status_eta (status, expected_completion_at),
  CONSTRAINT fk_repair_work_order_truck
    FOREIGN KEY (truck_id) REFERENCES trucks (truck_id),
  CONSTRAINT fk_repair_work_order_creator
    FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
