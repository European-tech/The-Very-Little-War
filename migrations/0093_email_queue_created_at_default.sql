-- NOTIF-MED-002: Add DEFAULT 0 to created_at to prevent INSERT failures if omitted.
ALTER TABLE email_queue MODIFY COLUMN created_at INT NOT NULL DEFAULT 0;
