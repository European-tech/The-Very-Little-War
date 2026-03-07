-- Migration 0016: Add PRIMARY KEY to connectes table (H-044)
-- The connectes table tracks online players but has no PRIMARY KEY.
-- Remove duplicates first (keep latest entry per IP), then add PK.

-- Step 1: Deduplicate — keep only the latest timestamp per ip
CREATE TEMPORARY TABLE connectes_dedup AS
SELECT ip, MAX(timestamp) AS timestamp FROM connectes GROUP BY ip;

DELETE FROM connectes;

-- Explicit column names to avoid column-order-dependent INSERT (see PASS1-LOW-037).
INSERT INTO connectes (ip, timestamp) SELECT ip, timestamp FROM connectes_dedup;

DROP TEMPORARY TABLE connectes_dedup;

-- Step 2: Drop the redundant index (PK will cover ip lookups)
-- Note: For idempotency, use "DROP INDEX IF EXISTS" syntax (supported MariaDB 10.1.4+)
DROP INDEX idx_connectes_ip ON connectes;

-- Step 3: Add primary key on ip
ALTER TABLE connectes ADD PRIMARY KEY (ip);
