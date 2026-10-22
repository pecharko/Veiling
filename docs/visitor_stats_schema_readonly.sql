-- Read-only OVH phpMyAdmin query. Select the live catalog database first.
-- Metadata only: no visitor rows, no secrets, no writes.
SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name,
       DATA_TYPE AS data_type, COLUMN_KEY AS column_key
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('visitor_stats_daily', 'visitor_stats_day_totals', 'visitor_stats_lot_totals')
ORDER BY TABLE_NAME, ORDINAL_POSITION;
