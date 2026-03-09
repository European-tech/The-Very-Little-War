# Pass 26 — Deferred Issues Status

## ALL HIGH/CRITICAL — FIXED in commits 4d02800 + 1806069

| ID | File | Status |
|----|------|--------|
| MARKET-P26-001 | marche.php | FALSE POSITIVE — globals set by initPlayer() before closure |
| AUTH-P26-005 | basicpublicphp.php | FALSE POSITIVE — session_token uses FOR UPDATE lock |
| AUTH-P26-006 | inscription.php | FALSE POSITIVE — logLoginEvent already wrapped in try-catch |
| AUTH-P26-007 | comptetest.php | FIXED (1806069) — logLoginEvent added after visitor create |
| AUTH-P26-008 | comptetest.php | FIXED (1806069) — logLoginEvent added after visitor convert |
| ANTICHEAT-P26-005 | voter.php | FALSE POSITIVE — estExclu checked in session_token query |
| ANTICHEAT-P26-006 | don.php | FIXED (1806069) — AND estExclu=0 on chef/officer IP queries |
| INFRADB-P26-004 | database.php | FIXED (1806069) — mysqli_commit return checked |
| INFRADB-P26-005 | database.php | FIXED (1806069) — mysqli_rollback return checked |
| SEASON-P26-003 | player.php | FIXED (1806069) — emails_queued_season idempotency guard |
| SEASON-P26-004 | player.php | FIXED (1806069) — VP flag pre-reset removed |
| ANTICHEAT-P26-001 | admin/multiaccount.php | FIXED (1806069) — existence pre-check before UPDATE |
| ANTICHEAT-P26-002 | admin/multiaccount.php | FIXED (1806069) — estExclu=0 check for both accounts |
| COMBAT-P26-014 | combat.php | FIXED (1806069) — max(0)+min(maxStorage) on pillage add |

## MEDIUM — Fixed or false positives

| ID | File | Status |
|----|------|--------|
| AUTH-P26-003 | comptetest.php | DEFERRED — UNIQUE constraint catches race; low risk |
| AUTH-P26-010 | inscription.php | DEFERRED — UNIQUE constraint catches race; low risk |
| AUTH-P26-011 | comptetest.php | DEFERRED — session regen before DB write minor risk |
| ANTICHEAT-P26-010 | multiaccount.php | FALSE POSITIVE — $overlap && guard already present |
| ANTICHEAT-P26-012 | rate_limiter.php | FIXED (1806069) — array_values() re-index added |
| SEASON-P26-008 | player.php | FIXED (1806069) — NULL instead of '' for alliance_name |
| ADMIN-P26-004 | admin/listenews.php | FALSE POSITIVE — single-stmt ops are already atomic |
| INFRADB-P26-009 | database.php | FIXED (1806069) — $depthIncremented=false initialized early |
| COMBAT-P26-008 | combat.php | FALSE POSITIVE — != 0 guard at line 501 prevents it |
| COMBAT-P26-015 | combat.php | FALSE POSITIVE — spec values bounded in config (-0.05 min) |

## LOW — Remaining

| ID | Issue |
|----|-------|
| INFRASEC-P26-001..004 | CSP nonce not escaped (base64 is safe; defence-in-depth) |
| ANTICHEAT-P26-008 | Email masking weak (shows first 2 chars) |
| ANTICHEAT-P26-013 | GC probability too low for low-traffic servers |
| SEASON-P26-010 | Silent prestige idempotency skip — add log message |
| INFRADB-P26-006 | migration 0107 missing CHARACTER SET latin1 |
| PLAYER-P26-010 | BUG comment never resolved in augmenterBatiment |
| COMBAT-P26-019 | Zombie molecule class check missing |
