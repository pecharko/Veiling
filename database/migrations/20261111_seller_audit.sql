-- Additive audit fields for centrally managed sellers.
-- Run only after lot_sellers and admins exist. Existing sellers are retained.
-- NULL actor means pre-audit record / unavailable historical actor.
-- Separately authorized production migration with verified full backup required.
ALTER TABLE lot_sellers
  ADD COLUMN created_by_admin_id INT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN created_at DATETIME NULL DEFAULT NULL,
  ADD COLUMN updated_by_admin_id INT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN updated_at DATETIME NULL DEFAULT NULL,
  ADD INDEX idx_lot_sellers_created_by (created_by_admin_id),
  ADD INDEX idx_lot_sellers_updated_by (updated_by_admin_id);
