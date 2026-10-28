-- Issue #354: manual-only database migration. NEVER apply automatically.
-- Requires separate authorization, verified backup, preflight and rollback plan.
-- All new TIMESTAMP values use UTC at the application boundary.
ALTER TABLE admins
  ADD COLUMN last_login_at DATETIME NULL DEFAULT NULL;

CREATE TABLE admin_invitations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  role VARCHAR(32) NOT NULL,
  token_hash CHAR(64) NULL UNIQUE,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  accepted_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  INDEX idx_admin_invitation_email (email),
  INDEX idx_admin_invitation_pending (accepted_at, revoked_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Follow-up read-only preflight:
-- SHOW COLUMNS FROM admins LIKE 'last_login_at';
-- SHOW CREATE TABLE admin_invitations;
-- Existing account passwords, roles and sessions are untouched.
-- The application MUST use an explicit UTC clock when writing DATETIME.
