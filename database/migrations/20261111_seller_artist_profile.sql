-- Additive artist-profile extension for lot_sellers, after 20261111_lot_sellers.sql.
-- Existing seller and lot records remain; older snapshot formats need not restore.
-- Production requires a separately authorized migration and verified backup.
ALTER TABLE lot_sellers
  ADD COLUMN artist_intro VARCHAR(500) NOT NULL DEFAULT '';

CREATE TABLE seller_images (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  seller_id INT UNSIGNED NOT NULL,
  image_kind ENUM('work','portrait') NOT NULL,
  slot TINYINT UNSIGNED NOT NULL,
  path VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_seller_image_slot (seller_id,image_kind,slot),
  CONSTRAINT fk_seller_images_seller FOREIGN KEY (seller_id)
    REFERENCES lot_sellers(id) ON DELETE CASCADE,
  CONSTRAINT chk_seller_image_slot CHECK (
    (image_kind='work' AND slot BETWEEN 1 AND 3)
    OR (image_kind='portrait' AND slot=1)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
