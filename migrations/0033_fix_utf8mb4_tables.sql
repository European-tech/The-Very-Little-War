-- Migration 0033: Convert utf8mb4 tables to latin1, add missing foreign keys
-- All new tables were incorrectly created with utf8mb4 charset.
-- latin1 is required for FK compatibility with membre (latin1_swedish_ci).
-- All constraint additions use IF NOT EXISTS to remain idempotent.

-- attack_cooldowns (created in 0004)
-- Columns: id, attacker VARCHAR(50), defender VARCHAR(50), expires INT
-- attacker/defender reference membre.login but no CASCADE semantics needed
-- (cooldowns are transient and expire naturally)
ALTER TABLE attack_cooldowns CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;

-- login_history (created in 0020)
-- Columns: id, login VARCHAR(255), ip VARCHAR(45), user_agent VARCHAR(512),
--          fingerprint VARCHAR(64), timestamp INT, event_type ENUM
-- login references membre.login
ALTER TABLE login_history CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;
ALTER TABLE login_history
    ADD CONSTRAINT fk_login_history_login
    FOREIGN KEY IF NOT EXISTS (login) REFERENCES membre(login) ON DELETE CASCADE;

-- account_flags (created in 0021)
-- Columns: id, login VARCHAR(255), flag_type ENUM, related_login VARCHAR(255),
--          evidence TEXT, severity ENUM, status ENUM, created_at INT,
--          resolved_at INT, resolved_by VARCHAR(255)
-- login references membre.login; related_login also references membre.login but
-- may be NULL and the related account may be deleted independently, so SET NULL
ALTER TABLE account_flags CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;
ALTER TABLE account_flags
    ADD CONSTRAINT fk_account_flags_login
    FOREIGN KEY IF NOT EXISTS (login) REFERENCES membre(login) ON DELETE CASCADE;
ALTER TABLE account_flags
    ADD CONSTRAINT fk_account_flags_related
    FOREIGN KEY IF NOT EXISTS (related_login) REFERENCES membre(login) ON DELETE SET NULL;

-- admin_alerts (created in 0021)
-- Columns: id, alert_type VARCHAR(50), message TEXT, details TEXT,
--          severity ENUM, is_read TINYINT, created_at INT
-- No FK to membre needed (system-level alerts)
ALTER TABLE admin_alerts CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;

-- resource_nodes (created in 0023)
-- Columns: id, x INT, y INT, resource_type ENUM, bonus_pct DECIMAL,
--          radius INT, active TINYINT
-- No FK to membre (map nodes are global, not player-owned)
ALTER TABLE resource_nodes CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;

-- prestige (created in 0007)
-- Columns: login VARCHAR(255) PRIMARY KEY, total_pp INT, unlocks VARCHAR(255)
-- login is the PK and references membre.login
-- Note: prestige.login was widened to VARCHAR(255) in migrations 0015 and 0022.
-- The engine was not set in 0007 (defaults to MyISAM on old servers). Ensure InnoDB
-- so the FK can be applied.
ALTER TABLE prestige ENGINE=InnoDB;
ALTER TABLE prestige CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;
ALTER TABLE prestige
    ADD CONSTRAINT fk_prestige_login
    FOREIGN KEY IF NOT EXISTS (login) REFERENCES membre(login) ON DELETE CASCADE;
