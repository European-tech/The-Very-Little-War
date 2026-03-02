-- Attack cooldown table: prevents same attacker from hitting same target after failed attack
CREATE TABLE IF NOT EXISTS attack_cooldowns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attacker VARCHAR(50) NOT NULL,
    defender VARCHAR(50) NOT NULL,
    expires INT NOT NULL,
    INDEX idx_attacker_defender (attacker, defender),
    INDEX idx_expires (expires)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Clean up expired cooldowns (can be run periodically)
-- DELETE FROM attack_cooldowns WHERE expires < UNIX_TIMESTAMP();
