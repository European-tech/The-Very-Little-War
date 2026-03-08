-- Migration 0087: Add unique constraint on voter_log (voter, target, date)
-- Note: the date column is named 'date' in the original schema.
-- If the column is named 'vote_date' in your installation, replace `date` with `vote_date` below.
ALTER TABLE voter_log ADD UNIQUE KEY uk_vote (voter, target, `date`);
