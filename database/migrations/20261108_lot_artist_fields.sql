-- One-time MySQL 8.4 migration: execute only after an independent catalog
-- snapshot and database backup, when the columns are confirmed absent.
-- Existing lots remain valid and default to empty artist metadata.
-- This ALTER is NOT executed by the PHP code release.
ALTER TABLE lots
  ADD COLUMN artist_name VARCHAR(250) NOT NULL DEFAULT '' AFTER subtitle,
  ADD COLUMN artist_url VARCHAR(2048) NOT NULL DEFAULT '' AFTER artist_name,
  ADD CONSTRAINT chk_lots_artist_url_requires_name
    CHECK (artist_url = '' OR CHAR_LENGTH(TRIM(artist_name)) > 0);
