# Pass 7 Alliance Integrity Audit

Audited files: `alliance.php`, `allianceadmin.php`, `validerpacte.php`, `includes/player.php` (supprimerAlliance / supprimerJoueur), `guerre.php`, `includes/combat.php`.

---

## Findings

### ALL-P7-001 [HIGH] — Chef account deletion orphans alliance (no dissolution)

**File:** `includes/player.php:911–944` (`supprimerJoueur`)

**Proof:**
`supprimerJoueur` deletes the player's row from `autre` (line 920) and from `membre` (line 921), but does **not** check whether the deleted player is the `chef` of an alliance nor call `supprimerAlliance()`. After deletion the `alliances.chef` column still holds the deleted login, which no longer exists in `autre` or `membre`.

The deferred auto-repair logic in `alliance.php:257–272` (`$chefExiste == 0`) does dissolve the alliance on the *next page visit* to that alliance's page — but this is a lazy, non-atomic cleanup that:

- Leaves the alliance row alive (with all its war/pact declarations) from the moment of deletion until the next visit.
- Can be completely skipped if no player ever visits that alliance page again before a season reset.
- During that window, other players still see the alliance in rankings and can still be invited to it via existing invitations (invitations for the alliance are not deleted until `supprimerAlliance` is eventually triggered).
- The `alliances.chef` FK points to a non-existent login; if `alliances.chef` ever gains a proper FK constraint (currently it does not), this would be a constraint violation.

**Fix required:**
Inside `supprimerJoueur`, before deleting the player, check whether they are a chef and call `supprimerAlliance` (or promote the most-senior member):

```php
// In supprimerJoueur, before DELETE FROM autre:
$chefOfAlliance = dbFetchOne($base, 'SELECT id FROM alliances WHERE chef=?', 's', $joueur);
if ($chefOfAlliance) {
    supprimerAlliance($chefOfAlliance['id']);
}
```

Note: `supprimerAlliance` must be called **before** the `DELETE FROM autre` so the member list is still intact for cleanup of attack_cooldowns.

---

### ALL-P7-002 [MEDIUM] — War end-state has no DB-level winner record; both sides can unilaterally claim victory in UI

**File:** `allianceadmin.php:380–400`, `guerre.php`, `includes/combat.php:792–796`

**Proof:**
The `declarations` table stores `pertes1` and `pertes2` (molecule losses) and `fin` (end timestamp). There is no `winner` column, no `pointsVictoire` award on war close, and no server-side determination of winner at the time the war is ended. The war is closed by `UPDATE declarations SET fin=? WHERE alliance1=? ... AND fin=0` — meaning only the **declaring** alliance (alliance1) can close it (correct per HIGH-005), but the closure simply timestamps it; neither side is credited in `alliances.pointsVictoire`.

Consequence: the `pointsVictoire` displayed on alliance pages is unrelated to wars. There is no inconsistency in the DB (both sides cannot simultaneously claim a win because nothing writes a win), but the war outcome is economically inert — the point-of-wars feature is currently cosmetic only. This is a **design gap** rather than a bug causing inconsistent state, but it means wars have no mechanical consequence, which may conflict with intended game design.

No "both alliances think they won" scenario is possible because victory is never recorded — there is no win state at all, only a loss-count per side via `pertes1`/`pertes2`.

**Severity: MEDIUM (design gap / missing feature, no data corruption)**

**Fix required (design):**
Add a `winner` column (`TINYINT DEFAULT NULL`) to `declarations`. On war close (`UPDATE declarations SET fin=?`), also compute and set `winner` (0=draw, 1=alliance1, 2=alliance2) based on `pertes1 < pertes2` → alliance1 wins, and award `pointsVictoire` to the winning alliance.

---

### ALL-P7-003 [LOW] — Pact double-accept: no DB-level UNIQUE constraint, but application guard is adequate

**File:** `validerpacte.php:9`, `allianceadmin.php:280–286`

**Proof:**
`validerpacte.php` runs inside a transaction with `FOR UPDATE` on the pending pact row (`WHERE d.valide=0`). After the first `UPDATE declarations SET valide=1`, subsequent calls with the same `idDeclaration` will fail the `WHERE valide=0` filter and `$declaration` will be `null`, causing the function to `return` silently. This correctly prevents double-accept.

The `declarations` table has **no UNIQUE constraint** on `(type, alliance1, alliance2)`. The duplicate-check on pact *proposal* is handled in-transaction (lines 280–283 of `allianceadmin.php`) and is robust. However, there is no DB-level uniqueness backup. If the application logic were ever bypassed (e.g., direct SQL injection that bypassed prepared statements — already mitigated, or a future code path), two simultaneous active pacts between the same alliances could exist.

**Severity: LOW (defense-in-depth gap only; application logic is correct)**

**Fix required (hardening):**
Add a partial unique index to prevent more than one active pact or open war between the same pair of alliances:

```sql
-- Prevents duplicate active wars
CREATE UNIQUE INDEX IF NOT EXISTS uidx_declarations_active_war
  ON declarations (LEAST(alliance1,alliance2), GREATEST(alliance1,alliance2), type, fin)
  WHERE type=0 AND fin=0;

-- Prevents duplicate accepted pacts
CREATE UNIQUE INDEX IF NOT EXISTS uidx_declarations_active_pact
  ON declarations (LEAST(alliance1,alliance2), GREATEST(alliance1,alliance2), type, valide)
  WHERE type=1 AND valide=1;
```

Note: MariaDB does not support partial/filtered indexes like Postgres. The equivalent is a generated column or an application-enforced constraint plus the existing in-transaction FOR UPDATE check (which is already correct). The FOR UPDATE guard is the correct mitigation for MariaDB; document it as intentional and verified.

---

### ALL-P7-004 [LOW] — Alliance dissolution does not reset `alliance_left_at` for expelled members

**File:** `includes/player.php:896–908` (`supprimerAlliance`)

**Proof:**
`supprimerAlliance` runs:
```
UPDATE autre SET energieDonnee=0 WHERE idalliance=?
UPDATE autre SET idalliance=0 WHERE idalliance=?
```
It does **not** update `alliance_left_at` for the members being expelled on dissolution. This means members who were dissolved out of an alliance receive the same rejoin-cooldown behavior as if they voluntarily left, which is incorrect (they were forcibly dissolved, not by their choice).

Additionally, if the chef's account is deleted via `supprimerJoueur` (finding ALL-P7-001), the surviving members' `idalliance` is only zeroed by the lazy `supprimerAlliance` call, and `alliance_left_at` is never set — so they retain stale idalliance until the cleanup fires, then get no cooldown at all, which is the opposite problem.

**Severity: LOW (unfair UX, not a security issue)**

**Fix required:**
In `supprimerAlliance`, after zeroing `idalliance`, also set `alliance_left_at=NULL` for expelled members (dissolution should not impose a cooldown):

```php
dbExecute($base, 'UPDATE autre SET idalliance=0, alliance_left_at=NULL WHERE idalliance=?', 'i', $alliance);
```

---

### ALL-P7-005 [LOW] — Grade permission escalation via race is mitigated but not fully atomic

**File:** `allianceadmin.php:84–128` (grade creation)

**Proof:**
Grade creation checks:
1. Target player is in the alliance (`inAlliance >= 1`).
2. Target does not already have a grade (`$gradee < 1`).
3. Grade count cap not exceeded.

These three checks are sequential reads with **no enclosing transaction or FOR UPDATE lock**. A concurrent double-submit (or two simultaneous officers) could create two grade rows for the same player in the same alliance before either check sees the other's write.

However, migration `0048_fix_grades_pk_safe.sql` likely adds a PK or UNIQUE constraint on `(login, idalliance)` in `grades` — if so, the second INSERT would fail with a duplicate-key error, which is uncaught and would result in a PHP/mysqli error rather than a graceful message.

**A graded officer cannot grant themselves a higher permission than the chef:** the permission bits are set by the chef via POST, and officers with `guerreDroit`/`pacteDroit` cannot access the grade-creation form (it is gated by `if ($gradeChef)` on line 500). So privilege escalation is not possible.

The race risk is a duplicate INSERT only.

**Severity: LOW (duplicate grade row possible, no privilege escalation)**

**Fix required:**
Wrap the grade-creation block in a `withTransaction` with a `FOR UPDATE` guard, or rely on the DB UNIQUE constraint to produce a clean error and catch it:

```php
try {
    dbExecute($base, 'INSERT INTO grades VALUES(?,?,?,?)', ...);
} catch (\Exception $e) {
    $erreur = "Cette personne est déjà gradée.";
}
```

---

### ALL-P7-006 [INFORMATIONAL] — Alliance tag and name uniqueness: DB-level constraint present

**File:** `migrations/0030_alliance_unique_constraints.sql`

Migration 0030 adds `UNIQUE INDEX` on `alliances.tag` and `alliances.nom`. Combined with the in-transaction duplicate check in `alliance.php:43–53` and `allianceadmin.php:64–77` / `155–169`, tag and name uniqueness is fully enforced at both application and DB levels.

**Status: CLEAN — no finding.**

---

### ALL-P7-007 [INFORMATIONAL] — Alliance dissolution cleanup: complete

**File:** `includes/player.php:893–909` (`supprimerAlliance`)

`supprimerAlliance` correctly:
- Zeros `idalliance` for all members.
- Deletes all `declarations` (wars + pacts) via ON DELETE CASCADE FK (migration 0055) **and** explicit DELETE.
- Deletes all `grades`, `invitations`.
- Cleans `attack_cooldowns` for all members.
- Runs inside a single `withTransaction`.

**Status: CLEAN — no finding (except the `alliance_left_at` gap noted in ALL-P7-004).**

---

### ALL-P7-008 [INFORMATIONAL] — Research upgrade is atomic; no double-spend possible

**File:** `alliance.php:156–176`

Research upgrade uses `FOR UPDATE` on the alliance row inside `withTransaction`. Level and energy are read and written atomically. No double-spend is possible.

**Status: CLEAN — no finding.**

---

## Summary

| ID | Severity | Description |
|----|----------|-------------|
| ALL-P7-001 | HIGH | Chef account deletion orphans alliance — `supprimerJoueur` must call `supprimerAlliance` when the deleted player is a chef |
| ALL-P7-002 | MEDIUM | War outcome is not recorded in DB; `pointsVictoire` is never awarded on war close; wars are economically inert |
| ALL-P7-003 | LOW | No DB-level UNIQUE constraint on active pacts/wars; FOR UPDATE guard is correct but lacks DB backstop |
| ALL-P7-004 | LOW | Alliance dissolution does not clear `alliance_left_at` for expelled members; wrongly imposes rejoin cooldown |
| ALL-P7-005 | LOW | Grade creation lacks transaction wrapper; concurrent double-submit could produce duplicate grade row (no privilege escalation possible) |
| ALL-P7-006 | INFO | Alliance tag/name uniqueness: CLEAN |
| ALL-P7-007 | INFO | Alliance dissolution cleanup completeness: CLEAN |
| ALL-P7-008 | INFO | Research upgrade atomicity: CLEAN |

**Critical path to fix:** ALL-P7-001 (HIGH) should be fixed immediately as it leaves alliances in a broken state whenever a chef deletes their account. ALL-P7-002 (MEDIUM) is a game design gap with no data corruption risk.
