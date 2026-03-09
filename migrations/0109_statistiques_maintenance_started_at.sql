-- Migration 0109: Add maintenance_started_at column to statistiques
-- Separates the "season start" timestamp (debut) from the "maintenance triggered at" timestamp.
-- Previously, basicprivatephp.php overwrote debut with time() when Phase 1 maintenance fired,
-- corrupting any season-duration calculation that depended on debut = real season start.
-- Now debut is never touched during maintenance; maintenance_started_at tracks when Phase 1 fired.
ALTER TABLE statistiques ADD COLUMN maintenance_started_at INT NOT NULL DEFAULT 0;
