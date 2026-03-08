-- Migration 0061: CHECK constraints for spec_* columns in constructions
-- PASS4-MEDIUM-004
--
-- constructions.spec_combat, spec_economy, spec_research are TINYINT columns
-- added in migration 0011, holding a player's chosen specialization per category:
--   0 = not chosen, 1 = option A, 2 = option B
-- Without CHECK constraints any value (including negative or > 2) can be stored,
-- potentially causing undefined behaviour in the specialization modifier logic.
--
-- MariaDB 10.4.3+ supports ADD CONSTRAINT IF NOT EXISTS for CHECK constraints
-- (the same syntax used in migration 0049). Re-running this migration is safe.
--
-- Note on migration 0035: that migration uses DATABASE() in information_schema
-- WHERE clauses. DATABASE() resolves correctly as long as the migration runner
-- selects the tvlw schema before executing (which migrate.php does). No change
-- is required here; the issue is documented for completeness.

ALTER TABLE constructions
    ADD CONSTRAINT IF NOT EXISTS chk_spec_combat_range
        CHECK (spec_combat BETWEEN 0 AND 2);

ALTER TABLE constructions
    ADD CONSTRAINT IF NOT EXISTS chk_spec_economy_range
        CHECK (spec_economy BETWEEN 0 AND 2);

ALTER TABLE constructions
    ADD CONSTRAINT IF NOT EXISTS chk_spec_research_range
        CHECK (spec_research BETWEEN 0 AND 2);
