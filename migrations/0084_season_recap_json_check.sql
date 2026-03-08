-- Migration 0084: Add JSON_VALID check constraint on season_recap.recap_data
ALTER TABLE season_recap MODIFY recap_data JSON NOT NULL;
