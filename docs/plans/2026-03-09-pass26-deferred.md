# Pass 26 — Deferred Issues (not yet fixed)

## CRITICAL / HIGH — Fix immediately

| ID | File | Issue |
|----|------|-------|
| MARKET-P26-001 | marche.php ~108 | $membre and $revenu undefined before closure (HIGH) |
| AUTH-P26-005 | basicpublicphp.php | Concurrent login overwrites session_token race (HIGH) |
| AUTH-P26-006 | inscription.php | logLoginEvent called before session setup — can abort registration (HIGH) |
| AUTH-P26-007 | comptetest.php ~63 | Visitor create missing logLoginEvent for multi-account detection (HIGH) |
| AUTH-P26-008 | comptetest.php ~165 | Visitor convert missing logLoginEvent (HIGH) |
| ANTICHEAT-P26-005 | voter.php | estExclu not re-checked for GET endpoint (HIGH) |
| ANTICHEAT-P26-006 | don.php | Donation checks skip banned/deleted chef (HIGH) |
| INFRADB-P26-004 | database.php | mysqli_commit() return value not checked (HIGH) |
| INFRADB-P26-005 | database.php | mysqli_rollback() return value not checked (HIGH) |
| SEASON-P26-003 | player.php ~1387 | queueSeasonEndEmails() needs code idempotency guard (column added, code not yet) (CRITICAL) |
| SEASON-P26-004 | player.php ~1258 | VP award flags reset before awards complete (HIGH) |
| ANTICHEAT-P26-001 | admin/multiaccount.php ~37 | Flag status UPDATE no existence pre-check (HIGH) |
| ANTICHEAT-P26-002 | admin/multiaccount.php ~57 | Manual flag accepts banned accounts (HIGH) |
| COMBAT-P26-014 | combat.php ~843 | Attacker resources no max(0,...) floor on pillage add (MEDIUM) |

## MEDIUM — Fix in next pass

| ID | File | Issue |
|----|------|-------|
| AUTH-P26-003 | comptetest.php ~98 | Visitor email uniqueness check outside transaction (MEDIUM) |
| AUTH-P26-010 | inscription.php ~49 | Registration email TOCTOU (MEDIUM, DB UNIQUE constraint catches it) |
| AUTH-P26-011 | comptetest.php ~49 | Session regen before DB writes in visitor create (MEDIUM) |
| ANTICHEAT-P26-010 | multiaccount.php ~289 | timing correlation $overlap null check missing (MEDIUM) |
| ANTICHEAT-P26-012 | rate_limiter.php ~62 | array_filter no array_values re-index (MEDIUM) |
| SEASON-P26-008 | player.php ~1128 | alliance_name empty string instead of NULL in season_recap (MEDIUM) |
| ADMIN-P26-004 | admin/listenews.php | News INSERT/UPDATE/DELETE not in transactions (MEDIUM) |
| INFRADB-P26-009 | database.php ~159 | $depthIncremented flag set after $depth++ (MEDIUM, race window) |
| COMBAT-P26-008 | combat.php | Division by zero if both players have 0 molecules (MEDIUM) |
| COMBAT-P26-015 | combat.php | Spec modifier bounds not validated (negative > -1) (MEDIUM) |

## LOW / COSMETIC — Address when convenient

| ID | Issue |
|----|-------|
| INFRASEC-P26-001..004 | CSP nonce not escaped (base64 is safe, but defence-in-depth) |
| ANTICHEAT-P26-008 | Email masking weak (shows first 2 chars) |
| ANTICHEAT-P26-013 | GC probability too low for low-traffic servers |
| SEASON-P26-010 | Silent prestige idempotency skip — add log message |
| INFRADB-P26-006 | migration 0107 missing CHARACTER SET latin1 |
| PLAYER-P26-010 | BUG comment never resolved in augmenterBatiment |
| COMBAT-P26-019 | Zombie molecule class check missing |
