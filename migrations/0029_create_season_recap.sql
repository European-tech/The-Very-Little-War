-- 0029: Season recap archive table (P1-D8-052)
CREATE TABLE IF NOT EXISTS season_recap (
    id INT AUTO_INCREMENT PRIMARY KEY,
    season_number INT NOT NULL,
    login VARCHAR(255) NOT NULL,
    final_rank INT NOT NULL DEFAULT 0,
    total_points INT NOT NULL DEFAULT 0,
    points_attaque INT NOT NULL DEFAULT 0,
    points_defense INT NOT NULL DEFAULT 0,
    trade_volume DOUBLE NOT NULL DEFAULT 0,
    ressources_pillees BIGINT NOT NULL DEFAULT 0,
    nb_attaques INT NOT NULL DEFAULT 0,
    victoires INT NOT NULL DEFAULT 0,
    molecules_perdues DOUBLE NOT NULL DEFAULT 0,
    alliance_name VARCHAR(255) DEFAULT NULL,
    streak_max INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_season (season_number),
    INDEX idx_login (login)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
