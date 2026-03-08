-- Migration 0020: Login history for multi-account detection
-- Source: NEW-2a (master plan Sprint 2)

CREATE TABLE IF NOT EXISTS login_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(255) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    user_agent VARCHAR(512) DEFAULT NULL,
    fingerprint VARCHAR(64) DEFAULT NULL,
    timestamp INT NOT NULL,
    event_type ENUM('login', 'register', 'action') NOT NULL DEFAULT 'login',
    INDEX idx_login_history_login (login),
    INDEX idx_login_history_ip (ip),
    INDEX idx_login_history_fingerprint (fingerprint),
    INDEX idx_login_history_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- NOTE: charset fixed from utf8mb4 to latin1 to avoid FK charset mismatch with membre.login
-- (Migration 0033 converted this table; this ensures the original creation is also latin1.)

-- Widen membre.ip for IPv6 support (was VARCHAR(11))
ALTER TABLE membre MODIFY ip VARCHAR(45) NOT NULL DEFAULT '';
