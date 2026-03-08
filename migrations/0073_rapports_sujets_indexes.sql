-- Migration 0073: Composite indexes for rapports and sujets tables
-- PERF-P7-003: rapports queried by (destinataire, timestamp) — missing composite index
-- PERF-P7-004: sujets queried by (idforum, statut, timestamp) — missing composite index

SET @idx1 = (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema=DATABASE() AND table_name='rapports' AND index_name='idx_rapports_dest_ts');
SET @sql1 = IF(@idx1=0,
    'ALTER TABLE rapports ADD INDEX idx_rapports_dest_ts (destinataire, timestamp)',
    'SELECT 1');
PREPARE s FROM @sql1; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx2 = (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema=DATABASE() AND table_name='sujets' AND index_name='idx_sujets_forum_statut_ts');
SET @sql2 = IF(@idx2=0,
    'ALTER TABLE sujets ADD INDEX idx_sujets_forum_statut_ts (idforum, statut, timestamp)',
    'SELECT 1');
PREPARE s FROM @sql2; EXECUTE s; DEALLOCATE PREPARE s;
