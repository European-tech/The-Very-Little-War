-- Migration 0056: Convert resource_nodes to latin1
-- resource_nodes was created with utf8mb4 charset in migration 0023.
-- All project tables must use latin1 for FK compatibility with the membre table.

ALTER TABLE resource_nodes CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;
