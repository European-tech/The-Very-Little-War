-- P29-LOW-INFRA-001: Composite index on email_queue(sent_at, created_at) for GC queries.
-- processEmailQueue() queries WHERE sent_at IS NULL — covered by existing idx_unsent.
-- remiseAZero() and cleanupEmailQueue() query WHERE sent_at IS NOT NULL AND created_at <= ?
-- — a composite index on (sent_at, created_at) makes this efficient.
-- The existing idx_unsent (sent_at) is kept; this composite adds coverage for the GC path.
ALTER TABLE email_queue ADD INDEX IF NOT EXISTS idx_email_queue_sent_created (sent_at, created_at);
