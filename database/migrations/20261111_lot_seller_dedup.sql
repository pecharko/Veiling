-- Destructive, explicit option B: do not copy old per-lot artist/bank data.
-- Only after the seller registry migration has created lots.seller_id and lot_sellers.
-- Run once on production ONLY with separately authorized migration, verified full
-- database backup, exact scope and rollback. Existing lots/photos/sellers remain.
ALTER TABLE lots
  DROP CHECK chk_lots_artist_url_requires_name,
  DROP COLUMN artist_name,
  DROP COLUMN artist_url,
  DROP COLUMN bank_account_name,
  DROP COLUMN bank_account_iban;
