-- Migration 0090: Add index on membre.alliance if not exists
-- LOW-009 audit note: All migration filenames in this project use 4-digit zero-padded
-- numbers (e.g., 0001, 0055, 0090). This convention MUST be maintained for all future
-- migrations so that migrate.php processes them in correct numeric order.
ALTER TABLE membre ADD INDEX idx_alliance (alliance);
