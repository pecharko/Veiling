-- STAGED ONLY: NOT AUTHORIZED FOR EXECUTION ON OVH.
-- Must be reviewed together with cron/archive/snapshot integration.
-- Separate geographic visitor-days from existing page-kind/lot metrics.
-- Geolocation is optional. Unknown location is explicitly supported.
CREATE TABLE IF NOT EXISTS visitor_geo_daily (
  visit_date DATE NOT NULL,
  visitor_id BINARY(32) NOT NULL,
  country_code CHAR(2) NOT NULL DEFAULT 'ZZ',
  region_code VARCHAR(16) NOT NULL DEFAULT '',
  city_name VARCHAR(120) NOT NULL DEFAULT '',
  views INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (visit_date, visitor_id),
  KEY idx_geo_date (visit_date, country_code, region_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visitor_geo_day_totals (
  visit_date DATE NOT NULL,
  country_code CHAR(2) NOT NULL DEFAULT 'ZZ',
  region_code VARCHAR(16) NOT NULL DEFAULT '',
  city_name VARCHAR(120) NOT NULL DEFAULT '',
  visitor_days INT UNSIGNED NOT NULL DEFAULT 0,
  views BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (visit_date, country_code, region_code, city_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
