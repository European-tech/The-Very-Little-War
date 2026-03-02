-- Weekly catalyst system columns in statistiques
ALTER TABLE statistiques ADD COLUMN catalyst TINYINT DEFAULT 0;
ALTER TABLE statistiques ADD COLUMN catalyst_week INT DEFAULT 0;
