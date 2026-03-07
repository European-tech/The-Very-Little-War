-- Migration 0038: Create email_queue table for async season reset notifications.
--
-- HIGH-017: Season reset emails are now queued here instead of being sent
-- synchronously in the user's request. processEmailQueue() drains the queue
-- probabilistically (1% of page loads) via basicprivatephp.php, preventing
-- HTTP timeouts when the player count is high.
--
-- Charset must be latin1 for FK compatibility with the membre table.
-- recipient_email is stored as received from membre.email (latin1 column).

CREATE TABLE IF NOT EXISTS email_queue (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  recipient_email VARCHAR(100)  NOT NULL,
  subject         VARCHAR(255)  NOT NULL,
  body_html       TEXT          NOT NULL,
  created_at      INT UNSIGNED  NOT NULL COMMENT 'Unix timestamp of queue insertion',
  sent_at         INT UNSIGNED  NULL     DEFAULT NULL COMMENT 'Unix timestamp of successful send; NULL = unsent',
  INDEX idx_unsent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
