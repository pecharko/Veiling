-- Manually reviewed optional migration; not applied by CI.
CREATE TABLE IF NOT EXISTS visitor_stats_daily (
  visit_date DATE NOT NULL,
  visitor_id BINARY(32) NOT NULL,
  page_kind VARCHAR(8) NOT NULL,
  lot_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  views INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (visit_date, visitor_id, page_kind, lot_id),
  KEY idx_lot_top (visit_date, page_kind, lot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
