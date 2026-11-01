-- DORMANT PROPOSAL. NIET AUTOMATISCH UITVOEREN.
-- Requires separate exact DB approval, verified existing schema, backup,
-- minimal privileges and independent anti-rollback authority outside OVH.
-- Companion to controller_release_claims; no ALTER or migration of live rows.
-- Claims predating this binding table must still fail the parent UNIQUE gates.
CREATE TABLE controller_release_claim_bindings (
  claim_id BIGINT UNSIGNED NOT NULL,
  nonce CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  binding_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  binding_json JSON NOT NULL,
  state ENUM('RESERVED','ATTEMPTED','ACCEPTED','UNKNOWN','ABORTED') NOT NULL,
  active_slot TINYINT GENERATED ALWAYS AS
    (CASE WHEN state = 'ABORTED' THEN NULL ELSE 1 END) STORED,
  PRIMARY KEY (claim_id),
  UNIQUE KEY uq_binding_nonce (nonce),
  UNIQUE KEY uq_binding_active (active_slot),
  CONSTRAINT fk_binding_claim FOREIGN KEY (claim_id)
    REFERENCES controller_release_claims(id),
  CONSTRAINT chk_binding_nonce CHECK (nonce REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_binding_digest CHECK (binding_sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB;
-- No GRANT, user/secret changes, cron, endpoint, cleanup or activation.
