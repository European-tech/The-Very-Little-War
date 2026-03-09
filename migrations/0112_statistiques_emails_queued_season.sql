-- SEASON-P26-003: Add emails_queued_season column to statistiques for email queue idempotency.
-- Prevents duplicate season-end emails when performSeasonEnd() is retried after partial failure.
ALTER TABLE statistiques
    ADD COLUMN IF NOT EXISTS emails_queued_season INT NOT NULL DEFAULT 0
        COMMENT 'Last season number for which season-end emails were queued; guards against duplicate queueing on retry';
