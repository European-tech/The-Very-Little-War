-- Migration 0106: Convert actionsconstruction debut/fin from VARCHAR/DATETIME to INT (UNIX timestamps)
-- These columns store UNIX timestamps and are compared against time() in PHP;
-- INT storage avoids implicit string/datetime conversions and enables range-index scans.
ALTER TABLE actionsconstruction
    MODIFY debut INT NOT NULL DEFAULT 0,
    MODIFY fin   INT NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_actionsconstruction_login_fin ON actionsconstruction (login, fin);
