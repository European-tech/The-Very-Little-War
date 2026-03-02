-- Player specialization columns: 0 = not chosen, 1 = option A, 2 = option B
ALTER TABLE constructions ADD COLUMN spec_combat TINYINT DEFAULT 0;
ALTER TABLE constructions ADD COLUMN spec_economy TINYINT DEFAULT 0;
ALTER TABLE constructions ADD COLUMN spec_research TINYINT DEFAULT 0;
