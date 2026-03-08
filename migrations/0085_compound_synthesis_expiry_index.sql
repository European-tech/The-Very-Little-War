-- Migration 0085: Add expiry index on compound_synthesis for performance
ALTER TABLE compound_synthesis ADD INDEX idx_expires (expires_at);
