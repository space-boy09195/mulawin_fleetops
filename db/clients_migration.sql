CREATE TABLE clients (
  client_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_name VARCHAR(150) NOT NULL,
  contact_person VARCHAR(150) NULL,
  phone VARCHAR(50) NULL,
  email VARCHAR(150) NULL,
  address VARCHAR(255) NULL,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (client_id),
  UNIQUE KEY uq_clients_name (client_name),
  KEY idx_clients_active (is_active),
  CONSTRAINT fk_clients_created_by FOREIGN KEY (created_by)
    REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
