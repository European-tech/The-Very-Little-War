-- 0108: Add batmax column to season_recap for building level archiving
ALTER TABLE season_recap ADD COLUMN IF NOT EXISTS batmax INT NOT NULL DEFAULT 0;
