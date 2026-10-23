-- Read-only SQL for OVH phpMyAdmin; run manually with SELECT-only access.
-- Does not insert test visitors, change any schema or show visitor identifiers.
SELECT table_name, column_name, data_type, column_key
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN ('visitor_stats_daily','visitor_stats_day_totals','visitor_stats_lot_totals')
ORDER BY table_name, ordinal_position;

SELECT 'visitor_stats_daily' AS source, COUNT(*) AS rows_present,
       COALESCE(SUM(views),0) AS page_views
FROM visitor_stats_daily;
SELECT visit_date, COUNT(*) AS rows_present, SUM(views) AS page_views
FROM visitor_stats_daily
GROUP BY visit_date ORDER BY visit_date DESC LIMIT 14;
SELECT 'visitor_stats_day_totals' AS source, COUNT(*) AS rows_present,
       COALESCE(SUM(views),0) AS page_views
FROM visitor_stats_day_totals;
SELECT 'visitor_stats_lot_totals' AS source, COUNT(*) AS rows_present,
       COALESCE(SUM(views),0) AS page_views
FROM visitor_stats_lot_totals;
