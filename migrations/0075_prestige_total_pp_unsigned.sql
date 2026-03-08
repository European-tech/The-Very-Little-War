-- Migration 0075: Make prestige.total_pp UNSIGNED to enforce DB-level floor (PRES-P7-003)
-- Note: ALTER TABLE MODIFY COLUMN is idempotent; safe to run multiple times.
ALTER TABLE prestige MODIFY COLUMN total_pp INT UNSIGNED NOT NULL DEFAULT 0;
