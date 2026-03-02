-- Add isotope variant column to molecules table
-- 0 = Normal (default), 1 = Stable, 2 = Réactif, 3 = Catalytique
ALTER TABLE molecules ADD COLUMN isotope TINYINT DEFAULT 0;
