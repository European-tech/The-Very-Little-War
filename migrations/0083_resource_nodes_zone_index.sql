-- Migration 0083: Add index on resource_nodes.zone for performance
ALTER TABLE resource_nodes ADD INDEX idx_zone (zone);
