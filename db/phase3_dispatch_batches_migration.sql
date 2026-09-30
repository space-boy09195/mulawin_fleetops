CREATE TABLE dispatch_instruction_batches (
  batch_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_by INT UNSIGNED NOT NULL,
  instruction_count SMALLINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (batch_id),
  INDEX idx_dispatch_instruction_batch_created (created_at),
  CONSTRAINT fk_dispatch_instruction_batch_creator
    FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE dispatch_instructions
  ADD COLUMN batch_id INT UNSIGNED NULL AFTER instruction_id,
  ADD INDEX idx_dispatch_instruction_batch (batch_id, status),
  ADD CONSTRAINT fk_dispatch_instruction_batch
    FOREIGN KEY (batch_id) REFERENCES dispatch_instruction_batches (batch_id)
    ON DELETE SET NULL;
