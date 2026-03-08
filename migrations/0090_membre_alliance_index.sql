-- Migration 0090: Add index on membre.alliance
-- NOTE: Migration naming convention: NNNN_description.sql (4-digit zero-padded)
-- NOTE: membre table has no alliance column. Alliance is idalliance on autre table (already indexed).
-- No schema change needed - documented no-op.
SELECT 1;
