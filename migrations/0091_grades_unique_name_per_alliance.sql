-- ALLIANCE-MED-002: Ensure grade name is unique per alliance for DB-level TOCTOU guard.
ALTER TABLE grades ADD UNIQUE INDEX IF NOT EXISTS idx_grade_name_alliance (idalliance, nom);
