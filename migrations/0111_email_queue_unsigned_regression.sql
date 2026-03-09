-- Migration 0111: Restore UNSIGNED on email_queue.created_at.
-- Migration 0093 changed the column from INT UNSIGNED to INT (removing UNSIGNED)
-- while adding a DEFAULT. This restores the UNSIGNED attribute so negative
-- values cannot be stored, matching the INT UNSIGNED type used at creation.
ALTER TABLE email_queue
    MODIFY COLUMN created_at INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Unix timestamp of queue insertion';
