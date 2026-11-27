-- Keep the sending preference outside catalogue snapshots.
CREATE TABLE artist_publication_mail_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;
