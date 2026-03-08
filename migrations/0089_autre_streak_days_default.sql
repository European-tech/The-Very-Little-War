-- Migration 0089: Set streak_days to NOT NULL DEFAULT 0 on autre table
UPDATE autre SET streak_days = 0 WHERE streak_days IS NULL;
ALTER TABLE autre MODIFY streak_days INT NOT NULL DEFAULT 0;
UPDATE autre SET streak_last_date = '1970-01-01' WHERE streak_last_date IS NULL;
