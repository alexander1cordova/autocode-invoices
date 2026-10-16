-- ============================================================
-- AutoCode Invoices — MySQL schema (MySQL 5.7+ / MariaDB 10.2+)
-- Run with:  mysql -u USER -p DBNAME < schema.sql
-- Or let install.php run it for you.
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
  setting_value TEXT NULL
)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  phone VARCHAR(50) NOT NULL DEFAULT '',
  email VARCHAR(150) NOT NULL DEFAULT '',
  address VARCHAR(255) NOT NULL DEFAULT '',
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_clients_name (last_name, first_name),
  INDEX idx_clients_phone (phone)
)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  year VARCHAR(10) NOT NULL DEFAULT '',
  make VARCHAR(100) NOT NULL DEFAULT '',
  model VARCHAR(100) NOT NULL DEFAULT '',
  vin VARCHAR(50) NOT NULL DEFAULT '',
  plate VARCHAR(20) NOT NULL DEFAULT '',
  mileage VARCHAR(20) NOT NULL DEFAULT '',
  color VARCHAR(50) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_vehicles_client FOREIGN KEY (client_id)
    REFERENCES clients (id) ON DELETE CASCADE,
  INDEX idx_vehicles_client (client_id),
  INDEX idx_vehicles_plate (plate),
  INDEX idx_vehicles_vin (vin)
)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jobs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  vehicle_id INT UNSIGNED NULL,
  problem_title VARCHAR(255) NOT NULL DEFAULT '',
  status ENUM('estimate','authorized','invoiced','paid') NOT NULL DEFAULT 'estimate',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_jobs_client FOREIGN KEY (client_id)
    REFERENCES clients (id) ON DELETE CASCADE,
  CONSTRAINT fk_jobs_vehicle FOREIGN KEY (vehicle_id)
    REFERENCES vehicles (id) ON DELETE SET NULL,
  INDEX idx_jobs_client (client_id),
  INDEX idx_jobs_status (status),
  INDEX idx_jobs_created (created_at)
)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  kind ENUM('service','part') NOT NULL DEFAULT 'service',
  description TEXT NOT NULL,
  qty DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  stage_added ENUM('estimate','authorization','invoice') NOT NULL DEFAULT 'estimate',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lines_job FOREIGN KEY (job_id)
    REFERENCES jobs (id) ON DELETE CASCADE,
  INDEX idx_lines_job (job_id)
)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_snapshots (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  stage ENUM('estimate','authorization','invoice') NOT NULL,
  snapshot_json MEDIUMTEXT NOT NULL,
  finalized_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_snapshots_job FOREIGN KEY (job_id)
    REFERENCES jobs (id) ON DELETE CASCADE,
  UNIQUE KEY uq_snapshot_stage (job_id, stage)
)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS signatures (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  stage ENUM('authorization','invoice') NOT NULL,
  signer_name VARCHAR(150) NOT NULL DEFAULT '',
  image_path VARCHAR(255) NOT NULL,
  signed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_signatures_job FOREIGN KEY (job_id)
    REFERENCES jobs (id) ON DELETE CASCADE,
  UNIQUE KEY uq_signature_stage (job_id, stage)
)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
