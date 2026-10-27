-- B3.2: ontwerp-migratie voor de BESTAANDE OVH-MySQL-database.
-- NIET AUTOMATISCH UITVOEREN. Vereist aparte expliciete DB-autorisatie,
-- read-only inspectie, data-only back-up, least-privilege en herstelplan.
-- Nieuwe controllerclaims vallen buiten de huidige catalogussnapshots.
-- NOOIT wissen via catalogus-reset, herstel, DROP, TRUNCATE of DELETE.
-- MySQL 8 / InnoDB. Review definitieve kolommen/rechten voor productie.
CREATE TABLE controller_release_claims (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  repository VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  issue_id BIGINT UNSIGNED NOT NULL,
  comment_id BIGINT UNSIGNED NOT NULL,
  release_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  release_sha CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  claim_state ENUM('CLAIMED','DISPATCH_UNKNOWN','DISPATCH_CONFIRMED','CLOSED')
    NOT NULL DEFAULT 'CLAIMED',
  workflow_run_id BIGINT UNSIGNED NULL,
  claimed_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_claim_comment (repository, issue_id, comment_id),
  UNIQUE KEY uq_claim_release_sha (repository, release_id, release_sha),
  UNIQUE KEY uq_claim_release_id (repository, release_id),
  CONSTRAINT chk_claim_issue_positive CHECK (issue_id > 0),
  CONSTRAINT chk_claim_comment_positive CHECK (comment_id > 0),
  CONSTRAINT chk_claim_release_sha CHECK (release_sha REGEXP '^[0-9a-f]{40}$'),
  CONSTRAINT chk_claim_run_positive CHECK (workflow_run_id IS NULL OR workflow_run_id > 0)
) ENGINE=InnoDB;

-- Alleen read-only verificaties na apart goedgekeurde migratie:
-- SHOW CREATE TABLE controller_release_claims;
-- SHOW INDEX FROM controller_release_claims;
-- SELECT COUNT(*) FROM controller_release_claims;
-- Extra UNIQUE(repo,release_id) voorkomt replay via gewijzigde main SHA.
-- Dat is bewust strenger dan het oorspronkelijke dubbele-key contract.
-- Geen GRANT/ALTER USER, geen DML, geen deploymentdispatch in deze file.
