-- P27-032: Add index on reponses_sondage(sondage) for efficient vote tallying
-- voter.php queries like SELECT COUNT(*) FROM reponses_sondage WHERE sondage=?
-- and GROUP BY reponse currently cause full table scans without this index.
-- The UNIQUE INDEX on (login, sondage) covers queries filtering on both columns,
-- but a single-column index on sondage speeds up aggregate queries on just sondage.
ALTER TABLE reponses_sondage ADD INDEX IF NOT EXISTS idx_rs_sondage (sondage);
