-- INFRADB-P26-007: Drop redundant index on news(id) — PRIMARY KEY already provides this index.
-- The redundant idx_id_desc index wastes storage and increases INSERT overhead.
ALTER TABLE news DROP INDEX IF EXISTS idx_id_desc;
