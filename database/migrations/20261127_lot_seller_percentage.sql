-- Apply with the separately approved production migration after a verified database backup.
ALTER TABLE lots
  ADD COLUMN seller_percentage TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD CONSTRAINT chk_lots_seller_percentage CHECK (seller_percentage IN (0, 30, 60));
