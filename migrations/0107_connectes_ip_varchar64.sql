-- Migration 0107: Widen connectes.ip to VARCHAR(64)
-- IPv6 addresses are up to 39 characters but can appear in expanded or mapped form
-- (e.g. ::ffff:192.0.2.1) up to 45 chars; 64 gives comfortable headroom.
ALTER TABLE connectes MODIFY ip VARCHAR(64) NOT NULL DEFAULT '';
