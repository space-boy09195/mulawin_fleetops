CREATE TABLE truck_status_history (
  status_history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  truck_id INT UNSIGNED NOT NULL,
  previous_status ENUM('Available', 'Deployed', 'Under Maintenance', 'Inactive') NULL,
  new_status ENUM('Available', 'Deployed', 'Under Maintenance', 'Inactive') NOT NULL,
  reason VARCHAR(500) NOT NULL,
  changed_by INT UNSIGNED NULL,
  changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (status_history_id),
  INDEX idx_truck_status_history_truck_time (truck_id, changed_at, status_history_id),
  INDEX idx_truck_status_history_changed_by (changed_by),
  CONSTRAINT fk_truck_status_history_truck
    FOREIGN KEY (truck_id) REFERENCES trucks (truck_id) ON DELETE CASCADE,
  CONSTRAINT fk_truck_status_history_user
    FOREIGN KEY (changed_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO truck_status_history
    (truck_id, previous_status, new_status, reason, changed_by)
SELECT truck_id, NULL, status,
       'Status snapshot at history rollout; earlier changes are unavailable.',
       NULL
FROM trucks;
