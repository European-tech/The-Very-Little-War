-- P29-LOW-COMBAT-001: Dedicated index on attack_cooldowns(defender) for defender-side lookups.
-- The existing composite idx_attacker_defender (attacker, defender) only accelerates queries
-- that filter on attacker first. Queries filtering on defender alone (e.g. "can this player
-- be attacked?") benefit from a single-column index on defender.
ALTER TABLE attack_cooldowns ADD INDEX IF NOT EXISTS idx_attack_cooldowns_defender (defender);
