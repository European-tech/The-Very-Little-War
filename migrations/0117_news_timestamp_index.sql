-- P28-MED-004: Add index on news.timestamp for efficient ORDER BY timestamp DESC queries.
-- Migration 0113 dropped the redundant idx_id_desc (duplicate of PK), leaving news with
-- no non-PK index. Every homepage news query now scans the full table.
ALTER TABLE news ADD INDEX IF NOT EXISTS idx_news_timestamp (timestamp);
