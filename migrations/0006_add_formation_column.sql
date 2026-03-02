-- Add defensive formation column to constructions table
-- 0 = Dispersée (default), 1 = Phalange, 2 = Embuscade
ALTER TABLE constructions ADD COLUMN formation TINYINT DEFAULT 0;
