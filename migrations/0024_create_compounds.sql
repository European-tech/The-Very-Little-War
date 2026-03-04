-- Migration 0024: Create player_compounds table for compound synthesis system
-- Source: V3-20 (compound synthesis lab)

CREATE TABLE IF NOT EXISTS player_compounds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(255) NOT NULL,
    compound_key VARCHAR(20) NOT NULL,
    activated_at INT DEFAULT NULL,
    expires_at INT DEFAULT NULL,
    INDEX idx_compounds_login (login),
    INDEX idx_compounds_active (login, expires_at),
    CONSTRAINT fk_compounds_login FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
