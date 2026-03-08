-- Migration 0086: Convert historique_alliances.action to ENUM for validation
ALTER TABLE historique_alliances MODIFY action ENUM('join','leave','kick','promote','demote','create','dissolve','pact','war') NOT NULL;
