-- Migration 0089: autre.streak_days NOT NULL DEFAULT 0
-- NOTE: streak_days is already INT(11) NOT NULL DEFAULT 0. No schema change needed.
SELECT 1;
