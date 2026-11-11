-- One-time, separately authorized MySQL 8.4 migration. NEVER run on production automatically.
-- Requires verified database backup; no old snapshot compatibility requirement.
-- Seller contact and bank data are private and must not enter public pages or logs.
CREATE TABLE lot_sellers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  seller_type ENUM('kunstenaar','particulier') NOT NULL,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(254) NOT NULL DEFAULT '',
  street VARCHAR(150) NOT NULL DEFAULT '',
  house_number VARCHAR(20) NOT NULL DEFAULT '',
  postal_code VARCHAR(20) NOT NULL DEFAULT '',
  municipality VARCHAR(120) NOT NULL DEFAULT '',
  bank_account_name VARCHAR(100) NOT NULL DEFAULT '',
  bank_account_iban VARCHAR(34) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  artist_name VARCHAR(50) NOT NULL DEFAULT '',
  artist_url VARCHAR(2048) NOT NULL DEFAULT '',
  INDEX idx_lot_sellers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE lots
  ADD COLUMN seller_id INT UNSIGNED NULL DEFAULT NULL,
  ADD INDEX idx_lots_seller (seller_id),
  ADD CONSTRAINT fk_lots_seller FOREIGN KEY (seller_id) REFERENCES lot_sellers(id) ON DELETE RESTRICT;
