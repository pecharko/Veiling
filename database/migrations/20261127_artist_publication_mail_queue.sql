CREATE TABLE artist_publication_mail_queue (
  seller_id INT UNSIGNED NOT NULL PRIMARY KEY,
  CONSTRAINT fk_artist_publication_mail_seller
    FOREIGN KEY (seller_id) REFERENCES lot_sellers(id) ON DELETE CASCADE
) ENGINE=InnoDB;
