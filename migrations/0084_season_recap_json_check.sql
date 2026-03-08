-- Migration 0084: season_recap JSON check
-- NOTE: The season_recap table uses individual columns (not a recap_data TEXT column).
-- No schema change needed. This migration is a documented no-op.
SELECT 1;
