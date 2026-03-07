-- Migration 0022: Recalculate totalPoints using sqrt ranking formula
-- Source: V4-19 (sqrt ranking system)
-- Must be run AFTER deploying the PHP code changes.
-- This is a one-time recalculation for existing players.
--
-- IMPORTANT: Apply ONLY after deploying updated PHP code. This UPDATE uses new formula.
-- MANUAL STEP: Verify PHP deployed before running.

-- The PHP formula is:
-- totalPoints = round(
--   1.0 * SQRT(MAX(0, points))
--   + 1.5 * SQRT(MAX(0, 5.0 * SQRT(ABS(pointsAttaque))))
--   + 1.5 * SQRT(MAX(0, 5.0 * SQRT(ABS(pointsDefense))))
--   + 1.0 * SQRT(MAX(0, tradeVolume))
--   + 1.2 * SQRT(MAX(0, ROUND(TANH(ressourcesPillees / 50000) * 80)))
-- )

UPDATE autre SET totalPoints = ROUND(
    1.0 * SQRT(GREATEST(0, points))
    + 1.5 * SQRT(GREATEST(0, ROUND(5.0 * SQRT(ABS(pointsAttaque)))))
    + 1.5 * SQRT(GREATEST(0, ROUND(5.0 * SQRT(ABS(pointsDefense)))))
    + 1.0 * SQRT(GREATEST(0, tradeVolume))
    + 1.2 * SQRT(GREATEST(0, ROUND(
        (EXP(2 * ressourcesPillees / 50000) - 1) / (EXP(2 * ressourcesPillees / 50000) + 1) * 80
    )))
);

-- Also fix prestige.login width (H-045)
ALTER TABLE prestige MODIFY login VARCHAR(255) NOT NULL;

-- Block web access to migrations (H-046) — handled by .htaccess file
