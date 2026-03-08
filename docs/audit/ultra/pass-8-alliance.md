# Ultra Security Audit — Pass 8: Alliance System
**Date:** 2026-03-08  
**Domain:** Alliance system integrity (alliance.php, allianceadmin.php, validerpacte.php, includes/player.php, guerre.php)

---

## Pass 7 Fix Verification

### ALL-P7-001 ✓ VERIFIED
**Status:** FIXED — supprimerJoueur checks if player is chef and calls supprimerAlliance BEFORE transaction.

**File:** includes/player.php:918-924
```php
$chefOfAlliance = dbFetchOne($base, 'SELECT id FROM alliances WHERE chef=?', 's', $joueur);
if ($chefOfAlliance) {
    supprimerAlliance($chefOfAlliance['id']);
}
```
The check is correctly placed BEFORE the withTransaction block that deletes the player's autre/membre rows.

---

### ALL-P7-004 ✗ NOT FIXED
**Status:** MISSING — supprimerAlliance does NOT clear alliance_left_at for expelled members.

**File:** includes/player.php:893-909
```php
function supprimerAlliance($alliance) {
    global $base;
    withTransaction($base, function() use ($base, $alliance) {
        // ... cleanup ...
        dbExecute($base, 'UPDATE autre SET idalliance=0 WHERE idalliance=?', 'i', $alliance);
        // alliance_left_at NOT cleared here
    });
}
```

**Issue:** When an alliance is dissolved (e.g., because the chef was deleted), the members' `alliance_left_at` column is not set. This leaves them in a state where:
- If `alliance_left_at` was NULL → they can immediately rejoin ANY alliance (no cooldown)
- If `alliance_left_at` was set → stale cooldown from a previous departure

**Expected:** When an alliance is forcibly dissolved, all members should have `alliance_left_at=UNIX_TIMESTAMP()` to enforce rejoin cooldown.

**Fix:** In supprimerAlliance(), after `UPDATE autre SET idalliance=0`, add:
```php
dbExecute($base, 'UPDATE autre SET alliance_left_at=UNIX_TIMESTAMP() WHERE idalliance=?', 'i', $alliance);
```

---

### ALL-P7-005 ✗ NOT FIXED
**Status:** MISSING — Grade creation has no transaction or duplicate-key handler.

**File:** allianceadmin.php:84-132

The grade creation block (lines 107-120) performs several checks and then executes a raw INSERT without:
1. **No transaction wrap** — Multiple queries run outside withTransaction()
2. **No UNIQUE constraint** — grades table has (login, nom, idalliance) but no UNIQUE constraint
3. **No duplicate-key error handler** — Raw dbExecute() with no try/catch
4. **TOCTOU race** — Between the uniqueness check at line 94 and the INSERT at line 119, another admin could insert a grade with the same name

**File:** allianceadmin.php:94
```php
elseif (dbCount($base, 'SELECT COUNT(*) FROM grades WHERE idalliance=? AND nom=?', 'is', $chef['id'], $_POST['nomgrade']) > 0) {
    $erreur = "Un grade avec ce nom existe déjà dans votre alliance.";
}
```

**File:** allianceadmin.php:119
```php
dbExecute($base, 'INSERT INTO grades VALUES(?,?,?,?)', 'ssss', $_POST['personnegrade'], $gradeStr, $chef['id'], $_POST['nomgrade']);
```

**Expected:** Wrap in transaction with FOR UPDATE:
```php
try {
    withTransaction($base, function() use ($base, $chef, $_POST, $gradeStr) {
        // Re-check inside transaction with lock
        $dup = dbCount($base, 'SELECT COUNT(*) FROM grades WHERE idalliance=? AND nom=? FOR UPDATE', 'is', $chef['id'], $_POST['nomgrade']);
        if ($dup > 0) {
            throw new \RuntimeException('DUPLICATE');
        }
        dbExecute($base, 'INSERT INTO grades VALUES(?,?,?,?)', 'ssss', $_POST['personnegrade'], $gradeStr, $chef['id'], $_POST['nomgrade']);
    });
} catch (\RuntimeException $e) {
    $erreur = "Un grade avec ce nom existe déjà dans votre alliance.";
}
```

---

## New Issues

### ALL-P8-001 MEDIUM — War winner column never written
**Status:** OPEN DESIGN GAP — declarations.winner column does not exist; no winner tracking.

**File:** allianceadmin.php:390
```php
dbExecute($base, 'UPDATE declarations SET fin=? WHERE alliance1=? AND alliance2=? AND fin=0 AND type=0', 'iii', $now, $chef['id'], $allianceAdverse['id']);
```

**Database Schema:** declarations table has columns: id, type, alliance1, alliance2, timestamp, pertes1, pertes2, fin, pertesTotales, valide
- **NO winner column exists**

**Issue:** Wars are closed by only setting `fin=UNIX_TIMESTAMP()`. The victor can be inferred by comparing pertes1 and pertes2, but this is error-prone and not explicit. The system has no way to record which alliance declared the war (alliance1) also won it, or if the attacker lost (critical for lore/narrative).

**Expected:** Either:
1. Add a `winner` column (0=alliance1, 1=alliance2, NULL=tie) to declarations table via migration
2. Or document that pertes1 < pertes2 implies alliance1 won, and maintain this invariant

**Severity:** MEDIUM — Current system works (alliances can infer winner from losses) but lacks explicit tracking.

---

### ALL-P8-002 MEDIUM — Alliance dissolution does not enforce cooldown
**Status:** OPEN — When alliance is dissolved, members have no rejoin cooldown.

**File:** includes/player.php:893-909 (supprimerAlliance)

**Issue:** When supprimerAlliance() dissolves an alliance:
1. Members' `idalliance` is set to 0
2. Members' `alliance_left_at` is NOT updated
3. Result: Members can immediately rejoin any alliance (no cooldown)

This means if an alliance's chef is deleted (e.g., account deletion, ban), the entire alliance dissolves and all members immediately have access to new alliances. This may be intentional, but it's a loophole:
- Player A joins Alliance X
- Player A makes Alliance X chef
- Player A deletes their account → Alliance X dissolved
- Player A's allies can rejoin the same or new alliances instantly
- Expected: 24-hour cooldown should apply to forced dissolution

**File:** includes/player.php:893-909

**Fix:** In supprimerAlliance(), add:
```php
dbExecute($base, 'UPDATE autre SET alliance_left_at=UNIX_TIMESTAMP() WHERE idalliance=?', 'i', $alliance);
```
(See ALL-P7-004 above)

---

### ALL-P8-003 LOW — Pact form injection works but button name mismatch
**Status:** WORKS WITH MINOR UX ISSUE

**File:** rapports.php:41-59 (injection logic)
**File:** validerpacte.php:4-38 (acceptation handler)

The pact form is correctly injected fresh with viewer's CSRF token:
```php
if (preg_match('/\[PACT_ID:(\d+)\]/', $content, $pactMatch)) {
    // ... validation ...
    $pactForm = '<form action="validerpacte.php" method="post">'
        . csrfField()
        . '<input type="hidden" name="idDeclaration" value="' . $declId . '"/>'
        . '<button type="submit" name="accepter" class="button button-small button-fill color-green">Accepter</button> '
        . '<button type="submit" name="refuser" class="button button-small button-fill color-red">Refuser</button>'
        . '</form>';
}
```

**Minor issue:** The form submits with button name="accepter" or name="refuser", but validerpacte.php only checks for `$_POST['accepter']` (line 32). If the player clicks "Refuser", the else branch at line 34 executes, deleting the pact. This is correct behavior but the button is named "refuser" not "rejeter" (inconsistent with French convention). Not a security issue, works as designed.

**Verdict:** ✓ SECURE

---

### ALL-P8-004 LOW — Leadership transfer race condition mitigated
**Status:** SECURE with transaction locks

**File:** allianceadmin.php:176-202

Transfer correctly uses transaction with FOR UPDATE on both member and alliance rows:
```php
withTransaction($base, function() use ($base, $currentAlliance, $newChef) {
    $member = dbFetchOne($base, 'SELECT login FROM autre WHERE idalliance=? AND login=? FOR UPDATE', ...);
    $oldChef = dbFetchOne($base, 'SELECT chef FROM alliances WHERE id=? FOR UPDATE', ...);
    dbExecute($base, 'UPDATE alliances SET chef=? WHERE id=?', 'si', $newChef, ...);
    dbExecute($base, 'DELETE FROM grades WHERE login=? AND idalliance=?', 'si', $oldChef['chef'], ...);
});
```

The fix at line 191 removes the old chef's grade, preventing privilege escalation. ✓ SECURE

---

### ALL-P8-005 LOW — Alliance invitation re-fetches live count
**Status:** SECURE

**File:** allianceadmin.php:407-409

Correctly re-fetches member count inside the form processing (not from page-load stale count):
```php
$liveCount = dbCount($base, 'SELECT COUNT(*) AS cnt FROM autre WHERE idalliance=?', 'i', $currentAlliance['idalliance']);
if ($liveCount < $joueursEquipe) {
```

No transaction lock here, but acceptable because invitation is just an insert to `invitations` table, not a membership change. The actual member count check happens during pact accept (alliance.php:225 with FOR UPDATE). ✓ SECURE

---

## Summary

**Pass 7 Fixes Status:**
- ✓ ALL-P7-001: VERIFIED
- ✗ ALL-P7-004: NOT FIXED
- ✗ ALL-P7-005: NOT FIXED

**New Findings:**
- **MEDIUM (2):** ALL-P8-001 (war winner column), ALL-P8-002 (dissolution cooldown)
- **LOW (3):** ALL-P8-003 (pact form UX), ALL-P8-004 (leadership transfer), ALL-P8-005 (invitation refetch)

**Critical Actions Needed:**
1. Fix supprimerAlliance() to set alliance_left_at=UNIX_TIMESTAMP() when dissolving
2. Wrap grade creation in transaction with duplicate-key handler
3. Consider adding winner column to declarations table for explicit war outcome tracking
