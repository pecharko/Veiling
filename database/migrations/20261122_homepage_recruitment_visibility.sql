-- Beide bestaande homepagesecties blijven zichtbaar na deze schema-update.
ALTER TABLE settings
  ADD COLUMN show_artist_recruitment TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN show_artwork_recruitment TINYINT(1) NOT NULL DEFAULT 1;
