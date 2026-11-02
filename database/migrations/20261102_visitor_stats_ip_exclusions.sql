-- PROPOSAL ONLY: requires separate production SQL authorization before rollout.
-- User decision: oude_snapshots_herstellen = nee for this change package.
-- These tables are deliberately outside SNAP_TABLES and snapshot restores.
-- No foreign key: restoring/deleting admin rows must not erase exclusions.
CREATE TABLE IF NOT EXISTS visitor_stats_ip_exclusions (
  ip_address VARBINARY(16) NOT NULL,
  admin_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ip_address, admin_id),
  CONSTRAINT chk_stats_exclusion_ip CHECK (OCTET_LENGTH(ip_address) IN (4,16)),
  CONSTRAINT chk_stats_exclusion_admin CHECK (admin_id > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS visitor_stats_ip_exclusion_state (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  enabled TINYINT UNSIGNED NOT NULL DEFAULT 1,
  CONSTRAINT chk_stats_exclusion_singleton CHECK (id=1),
  CONSTRAINT chk_stats_exclusion_enabled CHECK (enabled IN (0,1))
) ENGINE=InnoDB;
INSERT IGNORE INTO visitor_stats_ip_exclusion_state (id,enabled) VALUES (1,1);
