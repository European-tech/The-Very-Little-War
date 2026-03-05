-- Create poll responses table for voter.php
-- voter.php was querying columns (login, sondage, reponse) that don't exist on the forum reponses table
CREATE TABLE IF NOT EXISTS reponses_sondage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(255) NOT NULL,
    sondage INT NOT NULL,
    reponse INT NOT NULL,
    UNIQUE KEY uk_login_sondage (login, sondage),
    KEY idx_sondage (sondage)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
