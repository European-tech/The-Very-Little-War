-- Migration 0097: Add login1/login2 columns to admin_alerts for pair-specific dedup (ANTI-P10-001)
-- Prevents all same-type alerts from being silenced when any single pair triggered one in 24h.
ALTER TABLE admin_alerts
    ADD COLUMN IF NOT EXISTS login1 VARCHAR(50) DEFAULT NULL AFTER details,
    ADD COLUMN IF NOT EXISTS login2 VARCHAR(50) DEFAULT NULL AFTER login1,
    ADD INDEX IF NOT EXISTS idx_alerts_pair (alert_type, login1, login2, created_at);
