-- Additive kavel audit. Existing lot rows, photos, numbers and creator fields are retained.
-- Existing created_by_admin_id and created_at remain the source of creation history.
-- Audit dates are UTC and only set by a verified content/photo mutation.
-- Older snapshots do not need restore compatibility, by owner design choice.
-- Production migration requires independent full backup and separately scoped approval.
ALTER TABLE lots
  ADD COLUMN last_edited_by_admin_id INT NULL DEFAULT NULL,
  ADD COLUMN last_edited_at DATETIME NULL DEFAULT NULL,
  ADD INDEX idx_lots_last_edited_by (last_edited_by_admin_id);
