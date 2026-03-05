-- 0028: Add comeback tracking for welcome-back bonus (P1-D8-044/047)
ALTER TABLE autre
  ADD COLUMN last_catch_up INT NOT NULL DEFAULT 0,
  ADD COLUMN comeback_shield_until INT NOT NULL DEFAULT 0;
