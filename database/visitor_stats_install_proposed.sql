-- PROPOSAL ONLY: install the two missing archive tables, NEVER alter visitor_stats_daily.
-- OVH inspection 2026-10-22 confirms visitor_stats_daily exists with
-- DATE, BINARY(32), VARCHAR(8), BIGINT UNSIGNED, INT UNSIGNED and
-- PRIMARY (visit_date, visitor_id, page_kind, lot_id).
-- Before separate production DDL authorization, independently confirm selected
-- database and that both aggregate targets are absent. DDL is not transactional.
-- Intentionally NO IF NOT EXISTS, DROP, ALTER, UPDATE or DELETE.
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
