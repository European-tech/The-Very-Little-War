-- 0027: Add daily login streak tracking (P1-D8-041)
ALTER TABLE autre
  ADD COLUMN streak_days INT NOT NULL DEFAULT 0,
  ADD COLUMN streak_last_date DATE NULL DEFAULT NULL;
