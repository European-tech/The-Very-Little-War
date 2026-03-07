-- LOW-033: Soft delete for messages — sender and recipient delete independently
-- Each party can delete their copy without affecting the other party's view.
-- Rows are physically removed only when both parties have marked as deleted.
ALTER TABLE messages
  ADD COLUMN IF NOT EXISTS deleted_by_sender    TINYINT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS deleted_by_recipient TINYINT NOT NULL DEFAULT 0;

-- Index to speed up the cleanup query (DELETE WHERE both=1)
CREATE INDEX IF NOT EXISTS idx_messages_soft_delete
  ON messages (deleted_by_sender, deleted_by_recipient);
