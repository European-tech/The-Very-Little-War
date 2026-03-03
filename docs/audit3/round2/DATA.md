# Data Integrity Deep-Dive — Round 2

**Scope:** All data modification paths across 10 files
**Analyst:** database-optimizer agent
**Date:** 2026-03-03
**Database:** MariaDB 10.11, InnoDB (no FK constraints declared in schema)

---

## Summary

28 issues found across 10 files. Severity breakdown:

| Severity | Count |
|----------|-------|
| CRITICAL | 3 |
| HIGH     | 10 |
| MEDIUM   | 9 |
| LOW      | 6 |

**Key themes:**
- `remiseAZero()` resets every player/alliance row but runs 16 bare UPDATEs/DELETEs with zero transaction wrapper — any mid-reset crash leaves the database in a split state.
- `supprimerJoueur()` is now transactional but still orphans 5 tables: `prestige`, `attack_cooldowns`, `sanctions`, `moderation`, `reponses` (forum posts by the player).
- `inscrire()` is transactional but leaves the `actionsconstruction` row absent for newly registered players.
- `attaquer.php` deducts molecules and records the attack action in two non-atomic statements — a crash between them results in lost molecules with no combat recorded.
- Alliance leave (`alliance.php`) is a single bare UPDATE with no cleanup of pending invitations or grade records.
- `constructions.php::traitementConstructions()` charges resources and queues the build in two non-atomic steps.
- No table in the game uses declared FOREIGN KEY constraints, so every orphan scenario is silent — MariaDB will never raise a referential integrity error.

---

## Issues

---

### DATA-R2-001

**Severity:** CRITICAL
**Title:** `remiseAZero()` — 16 destructive statements run with no transaction wrapper
**File:** `includes/player.php` lines 793–838
**Affected tables:** `autre`, `constructions`, `alliances`, `molecules`, `membre`, `ressources`, `declarations`, `invitations`, `messages`, `rapports`, `actionsconstruction`, `actionsformation`, `actionsenvoi`, `actionsattaques`, `statistiques`, `attack_cooldowns`

**Description:**
`remiseAZero()` issues 16 UPDATEs and DELETEs sequentially with no `BEGIN` / `COMMIT`. A PHP fatal error, OOM kill, or DB timeout at any point between statements leaves the database in a partially reset state. Example failure modes:
- `autres` reset but `ressources` not yet cleared → players have 0 points but full resource stockpiles.
- `declarations` (wars/pacts) deleted but `alliances.energieAlliance` not yet zeroed → alliances retain energy but have no diplomacy history.
- Process killed after `UPDATE molecules` but before `DELETE FROM actionsattaques` → in-flight attacks reference reset armies.

The prestige post-reset block (lines 829–834) also runs outside any transaction: if it fails, some players get level-2 generators while others do not, with no way to detect which state applies.

**Fix:**

```php
function remiseAZero()
{
    global $base;
    global $nomsRes;
    global $nbRes;

    withTransaction($base, function() use ($base, $nomsRes, $nbRes) {
        dbExecute($base, 'UPDATE autre SET points=0, niveaututo=1, nbattaques=0, neutrinos=default,moleculesPerdues=0, energieDepensee=0, energieDonnee=0, bombe=0, batMax=1, totalPoints=0, pointsAttaque=0, pointsDefense=0, ressourcesPillees=0, tradeVolume=0, missions=\'\'');
        dbExecute($base, 'UPDATE constructions SET generateur=default, producteur=default, pointsProducteur=default, pointsProducteurRestants=default, pointsCondenseur=default, pointsCondenseurRestants=default, champdeforce=default, lieur=default, ionisateur=default, depot=1, stabilisateur=default, condenseur=0, coffrefort=0, formation=0, vieGenerateur=?, vieChampdeforce=?, vieProducteur=?, vieDepot=?', 'dddd', pointsDeVie(1), vieChampDeForce(0), pointsDeVie(1), pointsDeVie(1));
        dbExecute($base, 'UPDATE alliances SET energieAlliance=0, duplicateur=0, catalyseur=0, fortification=0, reseau=0, radar=0, bouclier=0');
        dbExecute($base, 'UPDATE molecules SET formule="Vide", nombre=0');
        dbExecute($base, 'UPDATE membre SET timestamp=?', 'i', time());

        $chaine = "";
        foreach ($nomsRes as $num => $ressource) {
            $plus = ($num < $nbRes) ? "," : "";
            $chaine .= $ressource . '=default' . $plus;
        }
        dbExecute($base, 'UPDATE ressources SET energie=default, terrain=default, revenuenergie=default, niveauclasse=1, ' . $chaine);
        dbExecute($base, 'DELETE FROM declarations');
        dbExecute($base, 'DELETE FROM invitations');
        dbExecute($base, 'DELETE FROM messages');
        dbExecute($base, 'DELETE FROM rapports');
        dbExecute($base, 'DELETE FROM actionsconstruction');
        dbExecute($base, 'DELETE FROM actionsformation');
        dbExecute($base, 'DELETE FROM actionsenvoi');
        dbExecute($base, 'DELETE FROM actionsattaques');
        dbExecute($base, 'UPDATE statistiques SET nbDerniere=0, tailleCarte=1');
        dbExecute($base, 'UPDATE membre SET x=-1000, y=-1000');
        dbExecute($base, 'DELETE FROM attack_cooldowns');

        // Prestige unlocks inside the same transaction
        $prestigePlayers = dbQuery($base, 'SELECT login, unlocks FROM prestige WHERE unlocks LIKE ?', 's', '%debutant_rapide%');
        if ($prestigePlayers) {
            while ($pp = mysqli_fetch_array($prestigePlayers)) {
                dbExecute($base, 'UPDATE constructions SET generateur=2, vieGenerateur=? WHERE login=?', 'ds', pointsDeVie(2), $pp['login']);
            }
        }
    });
}
```

Note: `TRUNCATE` cannot be used inside a transaction on MariaDB for InnoDB if other transactions are open. Use `DELETE FROM` as already done. For large tables consider `TRUNCATE` in a maintenance window instead.

---

### DATA-R2-002

**Severity:** CRITICAL
**Title:** `attaquer.php` — molecule deduction and attack INSERT are non-atomic
**File:** `attaquer.php` lines 148–156
**Affected tables:** `molecules`, `actionsattaques`, `ressources`

**Description:**
The attack submission performs three separate statements with no transaction:
1. Loop: `UPDATE molecules SET nombre=? WHERE id=?` (deducts troops, one UPDATE per molecule class).
2. `INSERT INTO actionsattaques ...` (records the attack).
3. `ajouter('energie', ...)` (deducts energy cost).

If the process dies after step 1 but before step 2, the player has permanently lost molecules with no corresponding attack created. If the process dies after step 2 but before step 3, the player launches an attack without paying the energy cost.

**Fix:**

```php
// Wrap all three mutation steps in a transaction
withTransaction($base, function() use ($base, $ex, $nomsRes, $troupes, $_SESSION, $_POST, $tempsTrajet, $cout) {
    $ex = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $_SESSION['login']);
    $c = 1;
    while ($moleculesAttaque = mysqli_fetch_array($ex)) {
        $newNombre = $moleculesAttaque['nombre'] - $_POST['nbclasse' . $c];
        dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', $newNombre, $moleculesAttaque['id']);
        $c++;
    }
    $now = time();
    dbExecute($base, 'INSERT INTO actionsattaques VALUES(default,?,?,?,?,?,?,0,default)', 'ssiiiss',
        $_SESSION['login'], $_POST['joueurAAttaquer'], $now, ($now + $tempsTrajet), ($now + 2 * $tempsTrajet), $troupes);
    ajouter('energie', 'ressources', -$cout, $_SESSION['login']);
    ajouter('energieDepensee', 'autre', $cout, $_SESSION['login']);
});
```

---

### DATA-R2-003

**Severity:** CRITICAL
**Title:** `constructions.php::traitementConstructions()` — resource deduction and build queue INSERT are non-atomic
**File:** `constructions.php` lines 238–290
**Affected tables:** `ressources`, `actionsconstruction`, `autre`

**Description:**
`traitementConstructions()` deducts resources with a raw `UPDATE ressources` (lines 251–263), then separately inserts into `actionsconstruction` (line 286) and updates `energieDepensee` (line 289). No transaction wraps these. A failure between any two steps produces:
- Resources deducted but no construction queued (player loses resources silently).
- Resources deducted, construction queued, but `energieDepensee` not updated (construction-point accounting corrupted).

**Fix:**

```php
withTransaction($base, function() use ($base, $liste, $nomsRes, $nbRes, $ressources, $constructions, $autre, $_SESSION) {
    // Re-read resources with lock
    $locked = dbFetchOne($base, 'SELECT energie FROM ressources WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
    // ... cost checks ...
    // UPDATE ressources
    // INSERT INTO actionsconstruction
    // UPDATE autre SET energieDepensee
});
```

---

### DATA-R2-004

**Severity:** HIGH
**Title:** `supprimerJoueur()` — orphans rows in `prestige`, `attack_cooldowns`, `sanctions`, `moderation`, `reponses`
**File:** `includes/player.php` lines 752–778
**Affected tables:** `prestige`, `attack_cooldowns`, `sanctions`, `moderation`, `reponses`

**Description:**
`supprimerJoueur()` was updated in the bug audit (commit a9d8c60) to include 5 previously missing tables. However, five more are still omitted:

| Table | Foreign key column | Impact |
|-------|-------------------|--------|
| `prestige` | `login` | Deleted player retains cross-season prestige row; cannot be reused if login is re-registered |
| `attack_cooldowns` | `attacker` / `defender` | Cooldown rows reference deleted player's login; never expire cleanly |
| `sanctions` | `joueur` | Deleted player's sanction history orphaned |
| `moderation` | `destinataire` | Admin resource grants orphaned |
| `reponses` | `auteur` | Forum posts by deleted player remain with a dead author reference |

The `reponses` omission is especially visible: the player profile page queries `reponses` to count forum posts, and those posts remain attached to the deleted player name.

**Fix — add to the `withTransaction` block in `supprimerJoueur()`:**

```php
dbExecute($base, 'DELETE FROM prestige WHERE login=?', 's', $joueur);
dbExecute($base, 'DELETE FROM attack_cooldowns WHERE attacker=? OR defender=?', 'ss', $joueur, $joueur);
dbExecute($base, 'DELETE FROM sanctions WHERE joueur=?', 's', $joueur);
dbExecute($base, 'DELETE FROM moderation WHERE destinataire=?', 's', $joueur);
// Forum posts: either delete or anonymize. Anonymize preserves forum history.
dbExecute($base, 'UPDATE reponses SET auteur=? WHERE auteur=?', 'ss', '[supprimé]', $joueur);
dbExecute($base, 'UPDATE sujets SET auteur=? WHERE auteur=?', 'ss', '[supprimé]', $joueur);
```

---

### DATA-R2-005

**Severity:** HIGH
**Title:** `supprimerAlliance()` — `allianceadmin.php` pact-break report INSERT not in transaction
**File:** `allianceadmin.php` lines 236–241 (pact deletion)
**Affected tables:** `declarations`, `rapports`

**Description:**
When breaking a pact from `allianceadmin.php`, the code does:
1. `DELETE FROM declarations WHERE ...` (line 237)
2. `INSERT INTO rapports ...` (line 241)

These are two bare statements. If step 1 succeeds but step 2 fails (e.g. DB connection drops), the pact is deleted but the other alliance's chef receives no notification. The inverse (INSERT succeeds, DELETE fails) is not possible since INSERT is second, but it leaves a dangling report about a pact that still exists.

**Fix:**

```php
withTransaction($base, function() use ($base, $chef, $allianceAdverse, $now) {
    dbExecute($base, 'DELETE FROM declarations WHERE ((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?)) AND type=1', 'iiii', $chef['id'], $allianceAdverse['id'], $chef['id'], $allianceAdverse['id']);
    dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default)', 'isss', $now, $rapportTitre, $rapportContenu, $allianceAdverse['chef']);
});
```

Apply the same pattern to war-end (lines 287–291).

---

### DATA-R2-006

**Severity:** HIGH
**Title:** `alliance.php` — player leave (`quitter`) has no cleanup and is not atomic
**File:** `alliance.php` lines 69–71
**Affected tables:** `autre`, `grades`, `invitations`

**Description:**
When a player leaves their alliance, only one statement is executed:

```php
dbExecute($base, 'UPDATE autre SET idalliance=0 WHERE login=?', 's', $_SESSION['login']);
```

Two related cleanup steps are missing:
1. `DELETE FROM grades WHERE login=? AND idalliance=?` — if the player had a grade, it remains as a ghost grade. The grade record points to a player no longer in the alliance. When the chef views the grade list via `allianceadmin.php`, the dead grade entry appears and can cause errors if the chef tries to delete it (the check `dansLAlliance > 0` will pass for the delete action but the player is gone).
2. `DELETE FROM invitations WHERE invite=?` — pending invitations sent to this player from other alliances are not cleaned up. If the player re-joins a different alliance immediately, those old invitations are still visible and actionable.

**Fix:**

```php
withTransaction($base, function() use ($base, $idalliance) {
    dbExecute($base, 'UPDATE autre SET idalliance=0 WHERE login=?', 's', $_SESSION['login']);
    dbExecute($base, 'DELETE FROM grades WHERE login=? AND idalliance=?', 'si', $_SESSION['login'], $idalliance['idalliance']);
    dbExecute($base, 'DELETE FROM invitations WHERE invite=?', 's', $_SESSION['login']);
});
```

---

### DATA-R2-007

**Severity:** HIGH
**Title:** `allianceadmin.php` — war declaration deletes pending pacts non-atomically before inserting war
**File:** `allianceadmin.php` lines 263–269
**Affected tables:** `declarations`, `rapports`

**Description:**
Before declaring war, the code deletes pending (unaccepted) pact declarations in two statements:

```php
dbExecute($base, 'DELETE FROM declarations WHERE alliance1=? AND alliance2=? AND fin=0 AND valide=0', ...);
dbExecute($base, 'DELETE FROM declarations WHERE alliance2=? AND alliance1=? AND fin=0 AND valide=0', ...);
dbExecute($base, 'INSERT INTO declarations VALUES(default, 0, ?, ?, ?, ...)', ...);
```

If the process dies after the DELETEs but before the INSERT, pending pacts are destroyed but no war is created. The two alliances are left with no relationship record, making it impossible to track that they were in a pending-pact state.

**Fix:** Wrap all three in `withTransaction()`.

---

### DATA-R2-008

**Severity:** HIGH
**Title:** `alliance.php` — alliance creation is non-atomic between INSERT and UPDATE
**File:** `alliance.php` lines 44–49
**Affected tables:** `alliances`, `autre`

**Description:**
Alliance creation does:
1. `INSERT INTO alliances VALUES(...)` — creates the alliance row.
2. `SELECT id FROM alliances WHERE tag=?` — re-fetches the new ID.
3. `UPDATE autre SET idalliance=? WHERE login=?` — assigns the player to the alliance.

Steps 1 and 3 are separate non-transactional statements. A crash after INSERT but before UPDATE leaves an alliance row with no members. The chef column references the player login but `autre.idalliance` is still 0, so the alliance-existence check in `alliance.php` (`supprimerAlliance()` trigger at lines 149–157) detects the orphan and deletes it on next load. However, this automatic cleanup runs only when _someone visits_ the orphaned alliance's page — the alliance can persist indefinitely until then.

The SELECT between INSERT and UPDATE is also a race condition: under concurrent load, `SELECT id FROM alliances WHERE tag=?` is not guaranteed to return the row just inserted by this session.

**Fix:**

```php
withTransaction($base, function() use ($base, $idalliance_val, $_POST, $_SESSION) {
    dbExecute($base, 'INSERT INTO alliances VALUES (default, ?, ?, ?, default, ?, default, default, default, default, default, default, default, default)', 'ssss', $_POST['nomalliance'], $_POST['tagalliance'], '', $_SESSION['login']);
    // Use LAST_INSERT_ID() — no extra SELECT needed, no race condition
    $newAllianceId = mysqli_insert_id($base);
    dbExecute($base, 'UPDATE autre SET idalliance=? WHERE login=?', 'is', $newAllianceId, $_SESSION['login']);
});
```

---

### DATA-R2-009

**Severity:** HIGH
**Title:** `marche.php` send-resources — resource deduction and envoi INSERT are non-atomic
**File:** `marche.php` lines 95–115
**Affected tables:** `ressources`, `actionsenvoi`

**Description:**
Resource sending (the convoy mechanic) does:
1. `INSERT INTO actionsenvoi VALUES(...)` (line 95–96) — records the in-transit transfer.
2. `UPDATE ressources SET energie=?, <atoms>=? WHERE login=?` (lines 108–115) — deducts from sender.

These are two separate non-transactional statements. If step 1 succeeds but step 2 fails, the sender receives their resources "in transit" without having actually paid for them — effectively duplicating resources. The order is also wrong for integrity: deduction should happen first (step 2 before step 1) so that failure before recording the convoy results in no loss and no phantom convoy.

**Fix:**

```php
withTransaction($base, function() use ($base, ...) {
    // Deduct first with FOR UPDATE lock
    $locked = dbFetchOne($base, 'SELECT energie FROM ressources WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
    // Verify funds still available ...
    // UPDATE ressources (deduct)
    // INSERT INTO actionsenvoi
});
```

---

### DATA-R2-010

**Severity:** HIGH
**Title:** `armee.php` — molecule class deletion leaves actionsattaques with stale troop counts but does not cancel the in-flight attack
**File:** `armee.php` lines 40–53
**Affected tables:** `actionsattaques`, `actionsformation`, `molecules`

**Description:**
When a player deletes a molecule class while an attack is in flight, the code zeroes out that class in the `troupes` column of every pending `actionsattaques` row (lines 40–53). However:

1. If all four troop counts become 0 after the deletion, the attack still exists in `actionsattaques` with `troupes = "0;0;0;0;"`. When `updateActions()` later resolves this "attack", it will execute combat with zero attacker molecules, causing a guaranteed defender victory with no actual battle.
2. The formation/deletion is not wrapped in a transaction with the attack-troupes update. If the process dies between deleting the formation and updating `actionsattaques`, the attack retains the original (now-deleted) troop type counts.
3. The `actionsformation` DELETE (line 26) and the `actionsformation` re-scheduling loop (lines 28–37) also run outside a transaction.

**Fix:**

```php
withTransaction($base, function() use ($base, ...) {
    // Reset molecule class
    dbExecute($base, 'UPDATE molecules SET formule = default, ..., nombre = default WHERE proprietaire=? AND numeroclasse=?', ...);
    dbExecute($base, 'DELETE FROM actionsformation WHERE login=? AND idclasse=?', ...);
    // Reschedule remaining formations
    // ...
    // Update or cancel in-flight attacks
    $ex = dbQuery($base, 'SELECT * FROM actionsattaques WHERE attaquant=?', ...);
    while ($aa = mysqli_fetch_array($ex)) {
        $explosion = explode(";", $aa['troupes']);
        // Zero out the deleted class
        // If all zeros, DELETE the attack entirely to avoid a ghost zero-troop combat
        $total = array_sum($explosion);
        if ($total == 0) {
            dbExecute($base, 'DELETE FROM actionsattaques WHERE id=?', 'i', $aa['id']);
        } else {
            dbExecute($base, 'UPDATE actionsattaques SET troupes=? WHERE id=?', 'si', $newChain, $aa['id']);
        }
    }
});
```

---

### DATA-R2-011

**Severity:** HIGH
**Title:** `armee.php` — neutrino purchase deducts energy and adds neutrinos in two bare statements
**File:** `armee.php` lines 70–76
**Affected tables:** `autre`, `ressources`

**Description:**
Neutrino purchase performs three non-transactional updates:
1. `UPDATE autre SET neutrinos=? WHERE login=?` (line 70)
2. `UPDATE ressources SET energie=? WHERE login=?` (line 74)
3. `UPDATE autre SET energieDepensee=? WHERE login=?` (line 76)

A crash between 1 and 2 gives the player free neutrinos. A crash between 2 and 3 leaves `energieDepensee` understated (minor accounting error but still an inconsistency).

There is also a TOCTOU race: the code reads `$autre['neutrinos']` from the cached `initPlayer()` call (potentially stale), not from a `FOR UPDATE` lock. Two concurrent requests can both pass the energy check using the same cached value and both succeed, resulting in double neutrino grants and double energy deductions.

**Fix:**

```php
withTransaction($base, function() use ($base, $_POST, $_SESSION, $coutNeutrino) {
    $locked = dbFetchOne($base, 'SELECT neutrinos FROM autre WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
    $lockedRes = dbFetchOne($base, 'SELECT energie FROM ressources WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
    if ($lockedRes['energie'] < $_POST['nombreneutrinos'] * $coutNeutrino) {
        throw new Exception('NOT_ENOUGH_ENERGY');
    }
    $newNeutrinos = $locked['neutrinos'] + $_POST['nombreneutrinos'];
    dbExecute($base, 'UPDATE autre SET neutrinos=? WHERE login=?', 'is', $newNeutrinos, $_SESSION['login']);
    $newEnergie = max(0, $lockedRes['energie'] - $_POST['nombreneutrinos'] * $coutNeutrino);
    dbExecute($base, 'UPDATE ressources SET energie=? WHERE login=?', 'ds', $newEnergie, $_SESSION['login']);
    $newEnergieDepensee = $autre['energieDepensee'] + $_POST['nombreneutrinos'] * $coutNeutrino;
    dbExecute($base, 'UPDATE autre SET energieDepensee=? WHERE login=?', 'ds', $newEnergieDepensee, $_SESSION['login']);
});
```

---

### DATA-R2-012

**Severity:** HIGH
**Title:** `armee.php` — molecule formation deducts atoms and inserts `actionsformation` non-atomically
**File:** `armee.php` lines 118–140
**Affected tables:** `ressources`, `actionsformation`

**Description:**
Formation launch:
1. `INSERT INTO actionsformation VALUES(...)` (line 118)
2. `UPDATE ressources SET <atoms>=<computed> WHERE login=?` (lines 131–140)

If step 1 succeeds and step 2 fails, the player has a formation queued but has not paid the atom cost — effectively training molecules for free. The atom check at lines 96–99 uses the cached (potentially stale) `$ressources` from `initPlayer()`, not a locked DB read, so a race between two concurrent formation requests can bypass the "enough atoms" check.

**Fix:** Wrap INSERT + UPDATE in `withTransaction()` and re-read resources with `FOR UPDATE` inside.

---

### DATA-R2-013

**Severity:** MEDIUM
**Title:** `combat.php` (included in `game_actions.php`) — CAS guard marks `attaqueFaite=1` before the transaction opens
**File:** `includes/game_actions.php` lines 71–75, then line 107
**Affected tables:** `actionsattaques`, `molecules`, `ressources`, `autre`, `constructions`, `rapports`, `declarations`, `attack_cooldowns`

**Description:**
The Compare-And-Swap guard at line 71–75 issues:

```php
$casAffected = dbExecute($base, 'UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0', 'i', $actions['id']);
```

Then the transaction opens at line 107 (`mysqli_begin_transaction($base)`), only after the travel-decay loop (lines 94–103) which contains multiple additional UPDATEs to `autre.moleculesPerdues`. These decay-loop UPDATEs run outside the transaction. Failure between CAS and `BEGIN` leaves `attaqueFaite=1` set (no second attempt possible) but `moleculesPerdues` accounting may be partially updated.

Additionally, the `actionsattaques` deletion for zero-return combats (line 248) runs inside the transaction, which is correct. However the preceding report INSERTs and the subsequent `actionsattaques` return-trip processing (lines 446–468) are entirely outside any transaction.

**Fix:** Move the CAS guard inside the transaction boundary:

```php
mysqli_begin_transaction($base);
try {
    $casAffected = dbExecute($base, 'UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0', 'i', $actions['id']);
    if ($casAffected === 0 || $casAffected === false) {
        mysqli_rollback($base);
        continue;
    }
    // Decay loop
    // include combat.php
    // Report inserts
    mysqli_commit($base);
} catch (Exception $e) {
    mysqli_rollback($base);
}
```

Also wrap the return-trip resource restore (lines 446–468) in its own transaction.

---

### DATA-R2-014

**Severity:** MEDIUM
**Title:** `game_actions.php` — resource convoy delivery has no transaction
**File:** `includes/game_actions.php` lines 471–539
**Affected tables:** `actionsenvoi`, `ressources`, `rapports`

**Description:**
When a convoy arrives, `updateActions()` performs:
1. `DELETE FROM actionsenvoi WHERE id=?` (line 474) — removes the in-transit record.
2. `INSERT INTO rapports ...` (line 510) — creates the arrival report.
3. `UPDATE ressources SET ... WHERE login=?` (line 538) — credits atoms to recipient.

All three run as bare statements. A crash between 1 and 3 destroys the convoy record and the recipient never receives their atoms. A crash between 1 and 2 destroys the convoy and the recipient gets atoms but no report. The operations should proceed in reverse order (credit first, then delete the convoy record) so a failure is idempotent: the convoy record remains and will be re-processed on next page load.

**Fix:**

```php
withTransaction($base, function() use ($base, $actions, ...) {
    // Credit recipient first (idempotent if we crash before DELETE)
    dbExecute($base, 'UPDATE ressources SET ... WHERE login=?', ..., $actions['receveur']);
    dbExecute($base, 'INSERT INTO rapports VALUES(...)', ...);
    dbExecute($base, 'DELETE FROM actionsenvoi WHERE id=?', 'i', $actions['id']);
});
```

---

### DATA-R2-015

**Severity:** MEDIUM
**Title:** `supprimerAlliance()` — `grades` table references players who may be gone (race condition on player delete)
**File:** `includes/player.php` line 748
**Affected tables:** `grades`, `alliances`, `autre`

**Description:**
`supprimerAlliance()` deletes `grades WHERE idalliance=?`. However if `supprimerJoueur()` and `supprimerAlliance()` run concurrently (e.g. a player deletes their account at the exact moment the alliance is being disbanded), the grade row may already be deleted by `supprimerJoueur()` or may be missed by `supprimerAlliance()` depending on execution order. Both functions are already transactional (via `withTransaction`), but they each hold short independent transactions with no coordination between them — a classic TOCTOU gap.

This is low probability but the lack of FK constraints means MariaDB provides no serialization guarantee. The practical impact is minimal (orphaned grade row at worst), but it illustrates the systemic need for FK constraints.

**Recommendation:** Declare `FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE` on `grades(login)`, and similarly across all login-keyed tables.

---

### DATA-R2-016

**Severity:** MEDIUM
**Title:** `comptetest.php` — account rename misses `actionsattaques` and `actionsenvoi` columns
**File:** `comptetest.php` lines 66–83
**Affected tables:** `actionsattaques`, `actionsenvoi`

**Description:**
The visitor-to-registered account rename wraps 15 table UPDATEs in a transaction. However two tables are omitted:

| Table | Column(s) referencing login |
|-------|----------------------------|
| `actionsattaques` | `attaquant`, `defenseur` |
| `actionsenvoi` | `envoyeur`, `receveur` |

If a visitor account (e.g. "Visiteur42") has pending attacks or in-transit resource convoys at the moment they upgrade their account to "Alice", those records continue to reference "Visiteur42". When `updateActions()` runs, it queries by login and will find nothing, effectively losing the pending action or convoy.

**Fix — add to the `withTransaction` block:**

```php
dbExecute($base, 'UPDATE actionsattaques SET attaquant=? WHERE attaquant=?', 'ss', $newLogin, $oldLogin);
dbExecute($base, 'UPDATE actionsattaques SET defenseur=? WHERE defenseur=?', 'ss', $newLogin, $oldLogin);
dbExecute($base, 'UPDATE actionsenvoi SET envoyeur=? WHERE envoyeur=?', 'ss', $newLogin, $oldLogin);
dbExecute($base, 'UPDATE actionsenvoi SET receveur=? WHERE receveur=?', 'ss', $newLogin, $oldLogin);
```

Also: `actionsformation.login` and `actionsconstruction.login` should be included but the visitor tutorial flow prevents formations and constructions from existing at rename time (low risk).

---

### DATA-R2-017

**Severity:** MEDIUM
**Title:** `voter.php` — INSERT and UPDATE to `reponses` have a race condition (check-then-act)
**File:** `voter.php` lines 40–49
**Affected tables:** `reponses`

**Description:**
The vote flow reads the existing vote count, then branches to INSERT or UPDATE:

```php
$existing = dbFetchOne($base, 'SELECT count(*) AS nb FROM reponses WHERE login=? AND sondage=?', ...);
if ($existing['nb'] == 0) {
    dbExecute($base, 'INSERT INTO reponses VALUES(default, ?, ?, ?)', ...);
} else {
    dbExecute($base, 'UPDATE reponses SET reponse=? WHERE login=? AND sondage=?', ...);
}
```

Two concurrent requests for the same user and same poll can both read `nb == 0`, then both attempt INSERT, resulting in a duplicate vote row. No UNIQUE constraint on `(login, sondage)` is declared in the schema.

**Fix:**

```sql
ALTER TABLE reponses ADD UNIQUE KEY uq_vote (login, sondage);
```

```php
// Use INSERT ... ON DUPLICATE KEY UPDATE instead of read-then-write:
dbExecute($base, 'INSERT INTO reponses (login, sondage, reponse) VALUES(?, ?, ?) ON DUPLICATE KEY UPDATE reponse=VALUES(reponse)', 'sis', $login, $sondageId, $reponse);
```

---

### DATA-R2-018

**Severity:** MEDIUM
**Title:** `compte.php` — vacation activation deletes `actionsformation` outside a transaction with `INSERT INTO vacances`
**File:** `compte.php` lines 18–25
**Affected tables:** `actionsformation`, `vacances`, `membre`

**Description:**
Vacation activation does:
1. `DELETE FROM actionsformation WHERE login=?` (line 18) — cancels all molecule formations.
2. `INSERT INTO vacances VALUES(...)` (line 22) — records vacation start.
3. `UPDATE membre SET vacance=1 WHERE id=?` (line 23) — sets vacation flag.

No transaction wraps these. A crash between step 1 and step 2 loses all in-progress formations without the player entering vacation mode. The player also loses the atoms already invested in those formations (since the atoms were deducted at formation-launch time in `armee.php`).

**Fix:**

```php
withTransaction($base, function() use ($base, $membreId, $date, $_SESSION) {
    dbExecute($base, 'DELETE FROM actionsformation WHERE login=?', 's', $_SESSION['login']);
    dbExecute($base, 'INSERT INTO vacances VALUES (default, ?, CURRENT_DATE, ?)', 'is', $membreId, $date);
    dbExecute($base, 'UPDATE membre SET vacance=1 WHERE id=?', 'i', $membreId);
});
```

---

### DATA-R2-019

**Severity:** MEDIUM
**Title:** `inscrire()` — new player row in `constructions` is missing columns added during refactor
**File:** `includes/player.php` lines 69
**Affected tables:** `constructions`

**Description:**
The `INSERT INTO constructions` statement (line 69) uses positional `?` placeholders and inserts the columns available in the original schema. The refactored `constructions` table gained columns `formation`, `coffrefort`, `pointsCondenseur`, `pointsCondenseurRestants` during the Phase 7/8 work. The INSERT statement has 17 positional placeholders and supplies 5 values — the remainder rely on DEFAULT.

This works today because all new columns have DEFAULT values. However it is fragile: any future ALTER TABLE that adds a NOT NULL column without a DEFAULT will silently fail registration, and the meaning of each positional column is opaque to future developers.

**Recommendation:** Use named-column INSERT to make the registration statement explicit and future-proof:

```php
dbExecute($base, 'INSERT INTO constructions (login, vieGenerateur, vieChampdeforce, vieProducteur, vieDepot) VALUES (?, ?, ?, ?, ?)', 'sdddd', $safePseudo, $vieGen, $vieCDF, $vieGen, $vieGen);
```

---

### DATA-R2-020

**Severity:** MEDIUM
**Title:** `declarations.php` does not exist — pact/war declarations handled in `allianceadmin.php` without CSRF-per-action isolation
**File:** `allianceadmin.php` lines 195–296
**Affected tables:** `declarations`

**Description:**
War and pact operations (declare, accept, break) all route through `allianceadmin.php` with a single `csrfCheck()` per block. The pact acceptance route that actually matters for data integrity is `validerpacte.php`, which correctly verifies the requesting player is the target alliance chef. However, the `allianceadmin.php` pact-break path at line 233 only checks `pacteExiste > 0` by alliance ID supplied via POST — if an attacker can forge `$_POST['allie']` with a valid alliance ID from a different alliance that also has a pact, they could terminate a pact they are not party to.

The `csrfCheck()` call mitigates this. But the check `pacteExiste > 0` verifies that _some_ pact exists involving the POST-supplied `allie` ID, not that the current player's alliance is actually party to that pact. The query at line 233 does:

```sql
SELECT count(*) FROM declarations WHERE (alliance1=? OR alliance2=?) AND type=1
```

with `$_POST['allie']` as both parameters. This returns `> 0` if the supplied alliance ID has any pact with anyone, not necessarily with the current player's alliance. Combined with the following DELETE at line 237 which correctly filters by `$chef['id']`, the check is redundant but the mismatch between the check condition and the delete condition means the error message ("Ce pacte n'existe pas") is misleading: an admin could break a pact between two other alliances if they know the target ID, but the delete is properly scoped by chef alliance. Risk is low due to CSRF but the logic inconsistency warrants fixing.

**Fix:** Tighten the existence check:

```php
$pacteExiste = dbCount($base, 'SELECT count(*) AS pacteExiste FROM declarations WHERE ((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?)) AND type=1', 'iiii', $chef['id'], $_POST['allie'], $chef['id'], $_POST['allie']);
```

---

### DATA-R2-021

**Severity:** LOW
**Title:** `combat.php` — `attack_cooldowns` INSERT has no duplicate-key protection
**File:** `includes/combat.php` line 338
**Affected tables:** `attack_cooldowns`

**Description:**
When a combat results in a draw or defender victory, a cooldown is inserted:

```php
dbExecute($base, 'INSERT INTO attack_cooldowns (attacker, defender, expires) VALUES (?, ?, ?)', 'ssi', ...);
```

If the CAS guard fails to prevent double-execution (e.g., the CAS row update is not fully visible across a read replica), two cooldown rows could be inserted for the same attacker/defender pair. When the cooldown lookup in `attaquer.php` queries `expires > time()`, it returns the first row found — this is likely fine in practice but there is no UNIQUE constraint to prevent accumulation.

**Fix:**

```sql
ALTER TABLE attack_cooldowns ADD UNIQUE KEY uq_cooldown (attacker, defender);
```

```php
dbExecute($base, 'INSERT INTO attack_cooldowns (attacker, defender, expires) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE expires=?', 'ssii', $actions['attaquant'], $actions['defenseur'], $cooldownExpires, $cooldownExpires);
```

---

### DATA-R2-022

**Severity:** LOW
**Title:** `remiseAZero()` — does not reset `cours` (market price history) table
**File:** `includes/player.php` lines 793–838
**Affected tables:** `cours`

**Description:**
`remiseAZero()` resets all game state but leaves the `cours` table intact. At season start, the market price chart shows the entire previous season's price history going back potentially months. While this does not corrupt data, it means the market chart (`marche.php`) shows stale prices that no longer reflect the new season's economics, potentially misleading players about current atom values.

**Fix:**

```php
dbExecute($base, 'DELETE FROM cours');
// Insert a single baseline row so the chart has a starting point
$baselinePrices = implode(',', array_fill(0, 8, '1'));
dbExecute($base, 'INSERT INTO cours VALUES(default, ?, ?)', 'si', $baselinePrices, time());
```

---

### DATA-R2-023

**Severity:** LOW
**Title:** `remiseAZero()` — does not reset `connectes` table if used
**File:** `includes/player.php` lines 793–838
**Affected tables:** `connectes`

**Description:**
The `connectes` table is present in the schema (CREATE TABLE in SQL dump) and likely tracks active sessions or recently-connected players. `remiseAZero()` does not touch it. If the table holds player-keyed rows, they become stale after reset. This is low severity if the table is ephemeral (rebuilt on login), but warrants confirmation.

**Fix:** Audit usage of `connectes` table. If it holds per-player state, add `DELETE FROM connectes` to `remiseAZero()`.

---

### DATA-R2-024

**Severity:** LOW
**Title:** `supprimerJoueur()` — does not clean up `actionsconstruction` rows
**File:** `includes/player.php` lines 752–778
**Affected tables:** `actionsconstruction`

**Description:**
`supprimerJoueur()` already deletes `actionsformation` and `actionsattaques`. However `actionsconstruction` is not listed in the DELETE block. A player with queued constructions at deletion time leaves orphaned `actionsconstruction` rows keyed to their login. These rows are never processed (no player to apply them to) and accumulate indefinitely.

**Fix:** Add to `supprimerJoueur()` transaction:

```php
dbExecute($base, 'DELETE FROM actionsconstruction WHERE login=?', 's', $joueur);
```

---

### DATA-R2-025

**Severity:** LOW
**Title:** `game_actions.php` — espionage report INSERT has no transaction
**File:** `includes/game_actions.php` lines 433–442
**Affected tables:** `rapports`, `actionsattaques`

**Description:**
Espionage resolution (lines 324–443) inserts the spy report and the detection report, then deletes the actionsattaques row — all as bare statements. A crash between the report INSERT and the DELETE leaves the espionage action stuck in `actionsattaques` and will be reprocessed on next page load, potentially generating duplicate spy reports for the same mission.

**Fix:** Wrap the espionage resolution block in `withTransaction()`.

---

### DATA-R2-026

**Severity:** LOW
**Title:** `allianceadmin.php` — grade creation does not verify the target player is in the alliance
**File:** `allianceadmin.php` lines 79–96
**Affected tables:** `grades`

**Description:**
When creating a grade, the code verifies the player exists in `membre` (line 81) but does NOT verify the player is a member of the current alliance (`autre.idalliance = $chef['id']`). A chef can grant a grade to any registered player, including players in rival alliances. The grade row is inserted with `idalliance = $chef['id']`, so it has no effect on the graded player's actual alliance membership, but it pollutes the grades table and appears in the alliance admin grade list, causing confusion.

**Fix:**

```php
$inAlliance = dbCount($base, 'SELECT count(*) AS nb FROM autre WHERE login=? AND idalliance=?', 'si', $_POST['personnegrade'], $chef['id']);
if ($inAlliance < 1) {
    $erreur = "Ce joueur n'est pas dans votre équipe.";
} else {
    // insert grade
}
```

---

### DATA-R2-027

**Severity:** LOW
**Title:** `inscrire()` — `statistiques.inscrits` counter can desync under concurrent registrations
**File:** `includes/player.php` lines 30–31, 64
**Affected tables:** `statistiques`

**Description:**
The registration function reads `inscrits` at line 30, increments in PHP at line 31, then writes it back at line 64 (inside the transaction). However the read at line 30 is outside the transaction. Under concurrent registrations, two sessions can both read the same `inscrits` value (e.g. 100), both compute 101, and both write 101, resulting in the counter being 101 instead of 102.

**Fix:** Move the read inside the transaction, or use an atomic increment:

```php
// Inside the withTransaction block:
dbExecute($base, 'UPDATE statistiques SET inscrits = inscrits + 1');
```

The `inscrits` counter drives no game logic (it is display-only in classement) so the impact is cosmetic, but the pattern is a textbook lost-update bug.

---

### DATA-R2-028

**Severity:** LOW
**Title:** No foreign key constraints declared — all orphan scenarios are silent
**File:** Schema (SQL dump) — all tables
**Affected tables:** All 28 tables

**Description:**
The entire game schema uses InnoDB but declares zero FOREIGN KEY constraints. Every data relationship (login → membre, idalliance → alliances, proprietaire → molecules, etc.) is enforced solely by application code. Any gap in the application-level cleanup described in this report (DATA-R2-004, DATA-R2-006, DATA-R2-016, DATA-R2-024) creates silent orphans that MariaDB will never detect or report.

Key relationships that would benefit from FK constraints with `ON DELETE CASCADE`:

| Child table | Column | Parent |
|-------------|--------|--------|
| `autre` | `login` | `membre(login)` |
| `ressources` | `login` | `membre(login)` |
| `molecules` | `proprietaire` | `membre(login)` |
| `constructions` | `login` | `membre(login)` |
| `rapports` | `destinataire` | `membre(login)` |
| `grades` | `login` | `membre(login)` |
| `grades` | `idalliance` | `alliances(id)` |
| `invitations` | `idalliance` | `alliances(id)` |
| `declarations` | `alliance1` / `alliance2` | `alliances(id)` |
| `attack_cooldowns` | `attacker` / `defender` | `membre(login)` |
| `prestige` | `login` | `membre(login)` |
| `vacances` | `idJoueur` | `membre(id)` |

**Note:** Adding FK constraints requires `login` columns to use a consistent data type. Currently `membre.login` is `text` while `autre.login` is `varchar(255)`, `actionsattaques.attaquant` is `varchar(500)`, etc. Normalization of the `login` column type across all tables is a prerequisite.

---

## Fix Priority Order

1. **DATA-R2-001** — `remiseAZero()` transaction (season integrity)
2. **DATA-R2-002** — `attaquer.php` attack atomicity (resource duplication)
3. **DATA-R2-003** — `constructions.php` build atomicity (resource duplication)
4. **DATA-R2-004** — `supprimerJoueur()` orphan cleanup (data hygiene)
5. **DATA-R2-011** — neutrino purchase atomicity + TOCTOU (free neutrinos exploit)
6. **DATA-R2-012** — molecule formation atomicity + TOCTOU (free molecules exploit)
7. **DATA-R2-009** — marche.php send-resources atomicity (resource duplication)
8. **DATA-R2-013** — combat CAS guard timing (combat double-resolution)
9. **DATA-R2-008** — alliance creation atomicity + race on LAST_INSERT_ID
10. **DATA-R2-006** — alliance leave cleanup (orphaned grades)
11. **DATA-R2-016** — comptetest.php rename missing tables
12. **DATA-R2-017** — voter.php duplicate vote race + UNIQUE constraint
13. **DATA-R2-021** — attack_cooldowns UNIQUE constraint
14. **DATA-R2-014** — convoy delivery transaction ordering
15. **DATA-R2-018** — vacation atomicity (lost formation atoms)
16. Remaining MEDIUM/LOW items

---

## Schema Migration Notes

The following DDL changes are recommended alongside the code fixes:

```sql
-- Prevent duplicate votes
ALTER TABLE reponses ADD UNIQUE KEY uq_vote (login(255), sondage);

-- Prevent duplicate cooldowns
ALTER TABLE attack_cooldowns ADD UNIQUE KEY uq_cooldown (attacker(255), defender(255));

-- Normalize login column types (prerequisite for FK constraints)
ALTER TABLE actionsattaques MODIFY attaquant VARCHAR(255) NOT NULL;
ALTER TABLE actionsattaques MODIFY defenseur VARCHAR(255) NOT NULL;
ALTER TABLE actionsenvoi MODIFY envoyeur VARCHAR(255) NOT NULL;
ALTER TABLE actionsenvoi MODIFY receveur VARCHAR(255) NOT NULL;
ALTER TABLE membre MODIFY login VARCHAR(255) NOT NULL;

-- FK constraints (add after column type normalization)
ALTER TABLE autre ADD CONSTRAINT fk_autre_login FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE;
ALTER TABLE ressources ADD CONSTRAINT fk_res_login FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE;
ALTER TABLE molecules ADD CONSTRAINT fk_mol_login FOREIGN KEY (proprietaire) REFERENCES membre(login) ON DELETE CASCADE;
ALTER TABLE constructions ADD CONSTRAINT fk_con_login FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE;
ALTER TABLE grades ADD CONSTRAINT fk_grade_alliance FOREIGN KEY (idalliance) REFERENCES alliances(id) ON DELETE CASCADE;
ALTER TABLE invitations ADD CONSTRAINT fk_inv_alliance FOREIGN KEY (idalliance) REFERENCES alliances(id) ON DELETE CASCADE;
ALTER TABLE declarations ADD CONSTRAINT fk_decl_a1 FOREIGN KEY (alliance1) REFERENCES alliances(id) ON DELETE CASCADE;
ALTER TABLE declarations ADD CONSTRAINT fk_decl_a2 FOREIGN KEY (alliance2) REFERENCES alliances(id) ON DELETE CASCADE;
ALTER TABLE vacances ADD CONSTRAINT fk_vac_joueur FOREIGN KEY (idJoueur) REFERENCES membre(id) ON DELETE CASCADE;
ALTER TABLE attack_cooldowns ADD CONSTRAINT fk_cd_attacker FOREIGN KEY (attacker) REFERENCES membre(login) ON DELETE CASCADE;
ALTER TABLE attack_cooldowns ADD CONSTRAINT fk_cd_defender FOREIGN KEY (defender) REFERENCES membre(login) ON DELETE CASCADE;
ALTER TABLE prestige ADD CONSTRAINT fk_prestige_login FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE;
```

With FK constraints, many of the `supprimerJoueur()` DELETEs become redundant (handled by CASCADE), but keeping them explicit is clearer and faster than relying on multi-level cascade chains.
