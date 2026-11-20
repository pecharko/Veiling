-- #638: Additive-only, MANUAL migration after data-only backup and owner DDL authorization.
-- Before: SHOW COLUMNS FROM lot_sellers LIKE 'artist_instagram_url'; must return 0 rows.
-- Run once only. Re-run is refused; no existing artists, works or photos are modified.
ALTER TABLE lot_sellers
  ADD COLUMN artist_instagram_url VARCHAR(2048) NOT NULL DEFAULT '' AFTER artist_url;
-- After: SHOW COLUMNS FROM lot_sellers LIKE 'artist_instagram_url';
