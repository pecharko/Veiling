ALTER TABLE artist_publication_mail_queue
  ADD COLUMN is_profile_update TINYINT(1) NOT NULL DEFAULT 0;
