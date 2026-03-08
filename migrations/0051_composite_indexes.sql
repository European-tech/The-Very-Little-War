-- Migration 0051: Composite indexes for query performance
--
-- season_recap (login, season_number): used by season_recap.php to retrieve
--   a specific player's history for a given season.
-- season_recap (season_number, final_rank): used to display the top players
--   of a past season sorted by rank, without a full table scan.
-- account_flags (status, created_at): used by admin/multiaccount.php to list
--   open/investigating flags ordered by creation time without scanning all rows.
--
-- ADD INDEX IF NOT EXISTS requires MariaDB 10.1.4+ (available in MariaDB 10.11).

ALTER TABLE season_recap
    ADD INDEX IF NOT EXISTS idx_season_login (login, season_number);

ALTER TABLE season_recap
    ADD INDEX IF NOT EXISTS idx_season_rank (season_number, final_rank);

ALTER TABLE account_flags
    ADD INDEX IF NOT EXISTS idx_flags_status_created (status, created_at);
