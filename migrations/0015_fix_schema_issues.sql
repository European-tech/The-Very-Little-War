-- Migration 0015: Fix schema issues found in audit #3
-- Run: mysql -u tvlw -p tvlw < migrations/0015_fix_schema_issues.sql

-- Fix actionsformation.idclasse: was INT, needs VARCHAR for 'neutrino' support
ALTER TABLE actionsformation MODIFY idclasse VARCHAR(50) NOT NULL DEFAULT '0';

-- Fix prestige.login VARCHAR length to match membre.login
ALTER TABLE prestige MODIFY login VARCHAR(255) NOT NULL;

-- Add missing index on totalPoints for ranking queries
ALTER TABLE autre ADD INDEX IF NOT EXISTS idx_totalPoints (totalPoints);

-- Add primary key to connectes if missing
-- Note: connectes uses ip as identifier, ensure no duplicates first
-- ALTER TABLE connectes ADD PRIMARY KEY (ip); -- Uncomment after verifying no duplicates

-- Runner inserts migration record; do not duplicate here.
-- The migrations table only has (id, filename, applied_at) columns.
-- The original INSERT INTO migrations (version, description, applied_at) was invalid
-- and would have caused a column-not-found error. Removed per PASS1-HIGH-010.
