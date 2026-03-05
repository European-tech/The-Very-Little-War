-- Add UNIQUE constraints on alliances.tag and alliances.nom
-- Prevents race condition where two concurrent requests could create
-- alliances with the same tag or name (application-level check alone is insufficient)

ALTER TABLE alliances ADD UNIQUE INDEX idx_alliances_tag (tag);
ALTER TABLE alliances ADD UNIQUE INDEX idx_alliances_nom (nom);
