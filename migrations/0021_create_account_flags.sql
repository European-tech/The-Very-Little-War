-- Migration 0021: Account suspicion flags and admin alert queue
-- Source: NEW-2b (master plan Sprint 2)

CREATE TABLE IF NOT EXISTS account_flags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(255) NOT NULL,
    flag_type ENUM('same_ip', 'same_fingerprint', 'coord_attack', 'coord_transfer', 'timing_correlation', 'manual') NOT NULL,
    related_login VARCHAR(255) DEFAULT NULL,
    evidence TEXT NOT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
    status ENUM('open', 'investigating', 'confirmed', 'dismissed') NOT NULL DEFAULT 'open',
    created_at INT NOT NULL,
    resolved_at INT DEFAULT NULL,
    resolved_by VARCHAR(255) DEFAULT NULL,
    INDEX idx_flags_login (login),
    INDEX idx_flags_related (related_login),
    INDEX idx_flags_status (status),
    INDEX idx_flags_severity (severity)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- NOTE: charset fixed from utf8mb4 to latin1 to avoid FK charset mismatch with membre.login
-- (Migration 0033 converted this table; this ensures the original creation is also latin1.)

CREATE TABLE IF NOT EXISTS admin_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alert_type VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    details TEXT DEFAULT NULL,
    severity ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'warning',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at INT NOT NULL,
    INDEX idx_alerts_unread (is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- NOTE: charset fixed from utf8mb4 to latin1 to match project standard (all InnoDB tables latin1)
