-- PROPOSAL ONLY. Production execution requires separate explicit authorization.
-- Requires confirmed selected database and verified absence of all three tables.
-- Fail closed: deliberately no IF NOT EXISTS, DROP, ALTER, UPDATE or DELETE.
CREATE TABLE visitor_stats_daily (
  visit_date DATE NOT NULL,
  visitor_id BINARY(32) NOT NULL,
  page_kind ENUM('home','lot') NOT NULL,
  lot_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  views BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (visit_date, visitor_id, page_kind, lot_id),
  KEY idx_stats_lot_recent (page_kind, visit_date, lot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE visitor_stats_day_totals (
  visit_date DATE NOT NULL,
  visitor_days BIGINT UNSIGNED NOT NULL,
  views BIGINT UNSIGNED NOT NULL,
  lot_views BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (visit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE visitor_stats_lot_totals (
  visit_date DATE NOT NULL,
  lot_id BIGINT UNSIGNED NOT NULL,
  visitor_days BIGINT UNSIGNED NOT NULL,
  views BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (visit_date, lot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
