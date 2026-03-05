-- Create sondages (polls) table referenced by voter.php
-- Uses latin1 for FK compatibility with existing tables
CREATE TABLE IF NOT EXISTS sondages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question VARCHAR(500) NOT NULL,
    options TEXT NOT NULL COMMENT 'Comma-separated poll options',
    date INT NOT NULL COMMENT 'Unix timestamp of creation',
    active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
