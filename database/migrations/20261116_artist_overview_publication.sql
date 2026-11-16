-- Proposed isolated artist overview publication flag; production migration requires
-- separate database authorization, verified backup and snapshot-compatibility decision.
-- Defaults OFF: existing public artist profiles are not deleted or changed.
ALTER TABLE settings ADD COLUMN show_artists_overview TINYINT(1) NOT NULL DEFAULT 0;
