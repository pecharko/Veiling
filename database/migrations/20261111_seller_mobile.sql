-- Additive extension of central seller management for artists and private sellers.
-- Apply only after 20261111_lot_sellers.sql. Existing sellers remain unchanged.
-- Production SQL requires separately scoped owner authorization and a verified backup.
ALTER TABLE lot_sellers
  ADD COLUMN mobile_number VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' AFTER email;
