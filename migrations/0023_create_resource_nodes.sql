-- Migration 0023: Create resource_nodes table for map resource nodes
-- Source: V3-19 (resource nodes on map)

CREATE TABLE IF NOT EXISTS resource_nodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    x INT NOT NULL,
    y INT NOT NULL,
    resource_type ENUM('carbone','azote','hydrogene','oxygene','chlore','soufre','brome','iode','energie') NOT NULL,
    bonus_pct DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    radius INT NOT NULL DEFAULT 5,
    active TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_nodes_coords (x, y),
    INDEX idx_nodes_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
