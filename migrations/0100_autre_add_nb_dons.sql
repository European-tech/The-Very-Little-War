-- ECON16-001: Add nbDons counter for donation medal tracking
-- energieDonnee existed but nbDons was never created, so donation medals were always broken
ALTER TABLE autre ADD COLUMN IF NOT EXISTS nbDons INT NOT NULL DEFAULT 0;
