-- Attack cooldown table: prevents same attacker from hitting same target after failed attack
-- NOTE: charset fixed from utf8mb4 to latin1 to avoid FK charset mismatch with membre.login
-- (Migration 0033 converted this table; this ensures the original creation is also latin1.)
CREATE TABLE IF NOT EXISTS attack_cooldowns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attacker VARCHAR(50) NOT NULL,
    defender VARCHAR(50) NOT NULL,
    expires INT NOT NULL,
    INDEX idx_attacker_defender (attacker, defender),
    INDEX idx_expires (expires)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Clean up expired cooldowns (can be run periodically)
-- DELETE FROM attack_cooldowns WHERE expires < UNIX_TIMESTAMP();
