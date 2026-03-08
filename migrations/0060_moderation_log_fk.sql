-- Migration 0060: Add FK to moderation_log.moderator_login with ON DELETE SET NULL
-- PASS4-MEDIUM-003
--
-- moderation_log.moderator_login records which moderator edited a post.
-- Without a FK the column is an unconstrained VARCHAR — deletions of a moderator
-- account leave orphaned string values that no longer correspond to any membre row,
-- making audit queries unreliable.
--
-- Using ON DELETE SET NULL rather than CASCADE preserves the audit trail: the log
-- entry survives the moderator's account deletion, with moderator_login set to NULL
-- to indicate the actor is no longer registered. ON UPDATE CASCADE propagates login
-- renames so existing audit rows stay accurate.
--
-- Steps:
-- 1. Allow NULL on moderator_login (required for SET NULL FK behaviour).
-- 2. Nullify any existing orphan rows (moderator_login values that have no
--    corresponding membre row) so the FK can be applied without error.
-- 3. Add the FK (idempotent guard).

SET FOREIGN_KEY_CHECKS=0;

-- Step 1: Make the column nullable (idempotent — MODIFY is safe to re-run)
ALTER TABLE moderation_log MODIFY moderator_login VARCHAR(255) DEFAULT NULL;

-- Step 2: Nullify orphaned rows (moderator deleted before this migration ran)
UPDATE moderation_log
    SET moderator_login = NULL
    WHERE moderator_login IS NOT NULL
      AND moderator_login NOT IN (SELECT login FROM membre);

-- Step 3: Add FK (idempotent existence guard)
SET @existFk = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = 'tvlw'
      AND TABLE_NAME   = 'moderation_log'
      AND CONSTRAINT_NAME = 'fk_modlog_moderator'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @addFk = IF(@existFk = 0,
    'ALTER TABLE moderation_log ADD CONSTRAINT fk_modlog_moderator FOREIGN KEY (moderator_login) REFERENCES membre(login) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE stmt2 FROM @addFk; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

SET FOREIGN_KEY_CHECKS=1;
