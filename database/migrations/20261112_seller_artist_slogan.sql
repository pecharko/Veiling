-- Add an optional artist slogan to existing seller profiles.
-- Additive only: preserves all existing sellers, works and photographs.
-- Requires owner decision on legacy snapshot restore policy before production migration.
ALTER TABLE lot_sellers
  ADD COLUMN artist_slogan VARCHAR(250) NOT NULL DEFAULT '';
