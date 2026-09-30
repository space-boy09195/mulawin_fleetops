CREATE TABLE IF NOT EXISTS trip_workflow_state (
  dispatch_id INT UNSIGNED NOT NULL,
  trip_id INT UNSIGNED NULL,
  current_step TINYINT UNSIGNED NOT NULL DEFAULT 1,
  assignment_confirmed_by INT UNSIGNED NULL,
  assignment_confirmed_at DATETIME NULL,
  documents_status ENUM('Prepared', 'Not Required') NULL,
  documents_notes TEXT NULL,
  allowance_status ENUM('Approved', 'Not Required') NULL,
  allowance_fund_request_id INT UNSIGNED NULL,
  allowance_notes TEXT NULL,
  prepared_by INT UNSIGNED NULL,
  prepared_at DATETIME NULL,
  clearance_by INT UNSIGNED NULL,
  clearance_at DATETIME NULL,
  clearance_notes TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (dispatch_id),
  UNIQUE KEY uq_trip_workflow_trip (trip_id),
  KEY idx_trip_workflow_step (current_step),
  CONSTRAINT fk_trip_workflow_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES dispatch_requests (dispatch_id),
  CONSTRAINT fk_trip_workflow_trip
    FOREIGN KEY (trip_id) REFERENCES trips (trip_id),
  CONSTRAINT fk_trip_workflow_assignment_user
    FOREIGN KEY (assignment_confirmed_by) REFERENCES users (user_id),
  CONSTRAINT fk_trip_workflow_preparer
    FOREIGN KEY (prepared_by) REFERENCES users (user_id),
  CONSTRAINT fk_trip_workflow_clearance_user
    FOREIGN KEY (clearance_by) REFERENCES users (user_id),
  CONSTRAINT fk_trip_workflow_fund_request
    FOREIGN KEY (allowance_fund_request_id) REFERENCES fund_requests (fund_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_workflow_events (
  event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dispatch_id INT UNSIGNED NOT NULL,
  trip_id INT UNSIGNED NULL,
  step_number TINYINT UNSIGNED NOT NULL,
  event_key VARCHAR(100) NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  details TEXT NULL,
  event_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id),
  UNIQUE KEY uq_trip_workflow_event_key (dispatch_id, event_key),
  KEY idx_trip_workflow_events_trip (trip_id, step_number, event_at),
  CONSTRAINT fk_trip_workflow_event_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES dispatch_requests (dispatch_id),
  CONSTRAINT fk_trip_workflow_event_trip
    FOREIGN KEY (trip_id) REFERENCES trips (trip_id),
  CONSTRAINT fk_trip_workflow_event_user
    FOREIGN KEY (actor_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_delivery_return_details (
  trip_id INT UNSIGNED NOT NULL,
  dispatch_id INT UNSIGNED NOT NULL,
  delivered_unit_count DECIMAL(10,2) NULL,
  delivery_receipt_number VARCHAR(100) NULL,
  shared_waybill_reference VARCHAR(100) NULL,
  co_load_reference VARCHAR(100) NULL,
  delivery_notes TEXT NOT NULL,
  return_location VARCHAR(255) NULL,
  return_notes TEXT NOT NULL,
  recorded_by INT UNSIGNED NOT NULL,
  recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (trip_id),
  KEY idx_trip_delivery_dispatch (dispatch_id),
  KEY idx_trip_delivery_waybill (shared_waybill_reference),
  KEY idx_trip_delivery_coload (co_load_reference),
  CONSTRAINT fk_trip_delivery_trip
    FOREIGN KEY (trip_id) REFERENCES trips (trip_id),
  CONSTRAINT fk_trip_delivery_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES dispatch_requests (dispatch_id),
  CONSTRAINT fk_trip_delivery_user
    FOREIGN KEY (recorded_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
