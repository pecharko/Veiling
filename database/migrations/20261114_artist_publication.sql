-- Additive artist publication control. Existing rows stay unpublished until explicitly approved.
-- One-time migration in separately authorized maintenance window, after verified full backup.
-- Older snapshots are preserved but do not need to restore after this schema revision.
ALTER TABLE lot_sellers
  ADD COLUMN artist_published TINYINT(1) NOT NULL DEFAULT 0;
