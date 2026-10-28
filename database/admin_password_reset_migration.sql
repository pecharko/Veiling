-- MANUAL MIGRATION: separate authorization and tested DB backup required.
-- Dedicated one-use reset tokens and per-IP/per-address request throttling.
CREATE TABLE admin_password_resets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  admin_id BIGINT UNSIGNED NULL,
  ip_hash CHAR(64) NOT NULL,
  email_hash CHAR(64) NOT NULL,
  token_hash CHAR(64) NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  used_at DATETIME NULL,
  INDEX idx_reset_ip_time (ip_hash, created_at),
  INDEX idx_reset_email_time (email_hash, created_at),
  INDEX idx_reset_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Confirm the admins.id column type on production before applying.
-- Rows older than 48h may later be purged manually, after explicit approval.
