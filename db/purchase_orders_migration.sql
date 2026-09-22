-- Adds a trackable purchase-order workflow on top of the existing parts
-- reorder-level alerting. Previously a "Low Stock" flag had no follow-up
-- record — this lets a request move through Pending -> Ordered -> Received
-- (or Cancelled), and "Received" ties back into the existing
-- parts_movements ledger as a normal Stock In, so stock history stays in
-- one place.
CREATE TABLE IF NOT EXISTS purchase_orders (
  po_id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_number       VARCHAR(30) NOT NULL UNIQUE,
  part_id         INT UNSIGNED NOT NULL,
  quantity        INT UNSIGNED NOT NULL,
  unit_cost       DECIMAL(10,2) NULL,
  supplier        VARCHAR(150) NULL,
  status          ENUM('Pending','Ordered','Received','Cancelled') NOT NULL DEFAULT 'Pending',
  notes           VARCHAR(500) NULL,
  requested_by    INT UNSIGNED NOT NULL,
  requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ordered_at      DATETIME NULL,
  received_at     DATETIME NULL,
  received_by     INT UNSIGNED NULL,
  cancelled_at    DATETIME NULL,
  movement_id     INT UNSIGNED NULL COMMENT 'Set once received — links to the parts_movements row created for the delivered stock',
  FOREIGN KEY (part_id) REFERENCES parts_inventory(part_id),
  FOREIGN KEY (requested_by) REFERENCES users(user_id),
  FOREIGN KEY (received_by) REFERENCES users(user_id),
  FOREIGN KEY (movement_id) REFERENCES parts_movements(movement_id),
  INDEX idx_po_status (status),
  INDEX idx_po_part (part_id)
);
