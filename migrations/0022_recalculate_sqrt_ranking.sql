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

-- EXP() overflows (returns NULL) when 2*ressourcesPillees/50000 > ~709, i.e. ressourcesPillees > ~17.7M.
-- tanh(x) = (e^2x - 1)/(e^2x + 1) → 1.0 as x→∞, so cap at 1.0 when argument > 700.
UPDATE autre SET totalPoints = ROUND(
    1.0 * SQRT(GREATEST(0, points))
    + 1.5 * SQRT(GREATEST(0, ROUND(5.0 * SQRT(ABS(pointsAttaque)))))
    + 1.5 * SQRT(GREATEST(0, ROUND(5.0 * SQRT(ABS(pointsDefense)))))
    + 1.0 * SQRT(GREATEST(0, tradeVolume))
    + 1.2 * SQRT(GREATEST(0, ROUND(
        CASE WHEN (2 * ressourcesPillees / 50000) > 700 THEN 1.0
             ELSE (EXP(2 * ressourcesPillees / 50000) - 1) / (EXP(2 * ressourcesPillees / 50000) + 1)
        END * 80
    )))
);

-- Also fix prestige.login width (H-045)
-- LOW-042: Note: migration 0015 already widened this column to VARCHAR(255); this MODIFY is idempotent (re-running is safe).
ALTER TABLE prestige MODIFY login VARCHAR(255) NOT NULL;

-- Block web access to migrations (H-046) — handled by .htaccess file
