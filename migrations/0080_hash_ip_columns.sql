-- Migration 0080: Expand IP columns to VARCHAR(64) for HMAC-SHA256 storage
-- WARNING: This migration invalidates existing IP-match detections.
-- Existing plaintext IPs are NOT retroactively hashed — historical rows will not match new hashed entries.
-- This is acceptable: the purpose is GDPR compliance for future data, not historical re-hashing.
ALTER TABLE membre MODIFY COLUMN ip VARCHAR(64) CHARACTER SET latin1 COLLATE latin1_swedish_ci;
ALTER TABLE login_history MODIFY COLUMN ip VARCHAR(64) CHARACTER SET latin1 COLLATE latin1_swedish_ci;
