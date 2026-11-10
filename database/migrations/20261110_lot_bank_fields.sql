-- Separate, one-time MySQL 8.4 production migration; never run as part of a page request.
-- Requires an independently verified database backup and catalog snapshot.
-- New fields are private administrative lot data; not part of public catalog output.
ALTER TABLE lots
  ADD COLUMN bank_account_name VARCHAR(100) NOT NULL DEFAULT '',
  ADD COLUMN bank_account_iban VARCHAR(34) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '';
