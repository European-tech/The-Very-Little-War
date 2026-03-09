-- Migration 0110: Create news table if not exists (fresh deploy guard).
-- The news table predates the migration system and exists on the live DB,
-- but a fresh deployment has no migration to create it.
-- Columns: id (PK), titre (title), contenu (body), timestamp (INT unix ts).
CREATE TABLE IF NOT EXISTS news (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  titre     VARCHAR(255) NOT NULL DEFAULT '',
  contenu   TEXT         NOT NULL,
  timestamp INT          NOT NULL DEFAULT 0,
  INDEX idx_id_desc (id)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
