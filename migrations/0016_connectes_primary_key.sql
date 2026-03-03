-- Migration 0016: Add PRIMARY KEY to connectes table (H-044)
-- The connectes table tracks online players but has no PRIMARY KEY.
-- Remove duplicates first (keep latest entry per IP), then add PK.

-- Step 1: Deduplicate — keep only the latest timestamp per ip
CREATE TEMPORARY TABLE connectes_dedup AS
SELECT ip, MAX(timestamp) AS timestamp FROM connectes GROUP BY ip;

DELETE FROM connectes;

INSERT INTO connectes SELECT * FROM connectes_dedup;

DROP TEMPORARY TABLE connectes_dedup;

-- Step 2: Drop the redundant index (PK will cover ip lookups)
DROP INDEX idx_connectes_ip ON connectes;

-- Step 3: Add primary key on ip
ALTER TABLE connectes ADD PRIMARY KEY (ip);
