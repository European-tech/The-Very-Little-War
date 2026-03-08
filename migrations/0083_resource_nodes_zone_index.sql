-- Migration 0083: Add index on resource_nodes.resource_type for performance
-- Note: resource_nodes has no 'zone' column (audit MEDIUM-002 was incorrect about column name).
-- Adding index on resource_type which is used in filtering queries.
ALTER TABLE resource_nodes ADD INDEX idx_resource_type (resource_type);
