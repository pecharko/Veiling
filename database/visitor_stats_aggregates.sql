-- OPTIONAL, NOT automatically applied. Requires separate production DB authorization.
-- Aggregate only: no visitor_id, IP or user agent.
CREATE TABLE IF NOT EXISTS visitor_stats_day_totals (
  visit_date DATE NOT NULL PRIMARY KEY,
  visitor_days INT UNSIGNED NOT NULL,
  views BIGINT UNSIGNED NOT NULL,
  lot_views BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS visitor_stats_lot_totals (
  visit_date DATE NOT NULL,
  lot_id BIGINT UNSIGNED NOT NULL,
  visitor_days INT UNSIGNED NOT NULL,
  views BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (visit_date, lot_id),
  KEY idx_lot_total (lot_id, visit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
