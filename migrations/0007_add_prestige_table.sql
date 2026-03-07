-- Prestige system: cross-season progression for all active players
CREATE TABLE IF NOT EXISTS prestige (
    login VARCHAR(50) PRIMARY KEY,
    total_pp INT DEFAULT 0,
    unlocks VARCHAR(255) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Populate existing players with 0 prestige
INSERT IGNORE INTO prestige (login) SELECT login FROM membre;
