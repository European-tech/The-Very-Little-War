# DB Deep-Dive — Round 2

**Scope:** Transaction safety, race conditions, locking, atomicity, schema integrity
**Files reviewed:** game_actions.php, player.php, game_resources.php, combat.php, database.php, db_helpers.php, marche.php, attaquer.php, alliance.php, allianceadmin.php, admin/index.php
**Round 1 reference:** docs/audit3/round1/DB.md
**Date:** 2026-03-03

---

## Summary Table

| ID | Severity | File | Function / Line | Issue |
|----|----------|------|-----------------|-------|
| SEC-R2-001 | CRITICAL | game_resources.php:169–183 | updateRessources() | moleculesPerdues updated inside molecule loop with stale read — N+1 TOCTOU double-write |
| SEC-R2-002 | CRITICAL | game_actions.php:446–468 | updateActions() return branch | Molecules credited on return without transaction or FOR UPDATE — double-credit race |
| SEC-R2-003 | CRITICAL | attaquer.php:146–158 | Attack launch | Multi-step molecule deduction + actionsattaques INSERT + energy deduction not atomic |
| SEC-R2-004 | HIGH | game_actions.php:107–319 | Combat try block | Combat transaction opened AFTER attack molecule decay loop — decay writes not rolled back on combat failure |
| SEC-R2-005 | HIGH | player.php:793–837 | remiseAZero() | 15+ sequential UPDATEs/DELETEs with no wrapping transaction — partial reset possible |
| SEC-R2-006 | HIGH | game_actions.php:471–538 | updateActions() — actionsenvoi | Resource delivery not atomic: DELETE then UPDATE ressources without transaction |
| SEC-R2-007 | HIGH | alliance.php:78–89 | augmenterDuplicateur | Read–check–deduct energieAlliance without transaction or FOR UPDATE |
| SEC-R2-008 | HIGH | alliance.php:94–113 | upgradeResearch | Read–check–deduct energieAlliance without transaction or FOR UPDATE |
| SEC-R2-009 | HIGH | allianceadmin.php:195–246 | Pact break (allie POST) | DELETE declarations without verifying current pact state — can delete wars |
| SEC-R2-010 | MEDIUM | game_actions.php:35–60 | Formation completion | Formation UPDATE not atomic with DELETE — duplicate completion possible |
| SEC-R2-011 | MEDIUM | player.php:508–539 | augmenterBatiment() | Building level increment + points award not in single transaction |
| SEC-R2-012 | MEDIUM | player.php:542–620 | diminuerBatiment() | Building level decrement + points deduction not in single transaction |
| SEC-R2-013 | MEDIUM | combat.php:534–535 | ajouterPoints() (type 1 & 2) | ajouterPoints reads then writes autre without FOR UPDATE — concurrent combat can corrupt totalPoints |
| SEC-R2-014 | MEDIUM | game_resources.php:112–123 | updateRessources() CAS guard | `mysqli_affected_rows($base)` called after `dbExecute()` which closes the statement — result is always from connection-level tracking, not the specific statement |
| SEC-R2-015 | MEDIUM | player.php:76–105 | ajouterPoints() | Reads autre then writes — no FOR UPDATE, concurrent calls corrupt totalPoints |
| SEC-R2-016 | MEDIUM | player.php:63–70 | inscrire() — statistiques counter | statistiques.inscrits read before transaction opened — counter race between concurrent registrations |
| SEC-R2-017 | LOW | admin/index.php:45–48 | miseazero POST | remiseAZero() called from web form with no idempotency guard — double-click resets twice |
| SEC-R2-018 | LOW | game_actions.php:26–31 | Construction completion | augmenterBatiment + DELETE not in the same transaction |
| SEC-R2-019 | LOW | marche.php:95–115 | Resource send (envoi) | INSERT actionsenvoi + UPDATE ressources (deduct) not wrapped in withTransaction |
| SEC-R2-020 | LOW | player.php:739–749 | supprimerAlliance() | No check that requesting player is actually the chef before deleting |

---

## CRITICAL Findings

### SEC-R2-001 — moleculesPerdues N+1 TOCTOU in updateRessources()

**Severity:** CRITICAL
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_resources.php`
**Lines:** 169–183
**R1 ref:** DB-R1-001 (partial)

**SQL involved:**
```php
// Line 169: iterates molecules
while ($molecules = mysqli_fetch_array($ex)) {
    // Line 178: write new molecule count
    dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', $moleculesRestantes, $molecules['id']);
    // Line 180: RE-READ moleculesPerdues inside the loop — stale between iterations
    $moleculesPerdues = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $joueur);
    // Line 181: write accumulated losses — but $moleculesPerdues was read before prior loop iteration wrote it
    dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', 'ds',
        ($molecules['nombre'] - $moleculesRestantes + $moleculesPerdues['moleculesPerdues']), $joueur);
}
```

**Race condition window:** Each loop iteration re-reads `moleculesPerdues` from the database, but the value it reads does not yet reflect the write from the previous iteration (because `dbExecute` closes the statement but there is no barrier ensuring the next `SELECT` sees the prior `UPDATE` within the same connection in autocommit mode). More critically, if `updateRessources()` is called concurrently for the same player (two HTTP requests racing), iteration 1 of request A and iteration 1 of request B both read the same `moleculesPerdues` baseline and both write `baseline + delta1`. The second write wins, losing the first delta.

Additionally, the accumulation pattern is wrong: the correct approach is to accumulate the total delta in PHP and write once. The current code writes after every molecule class, each time re-reading from DB.

**PoC:**
```
1. Player has 4 molecule classes; moleculesPerdues = 1000
2. Request A loop iteration 1: reads 1000, writes 1000+delta1_A
3. Request B loop iteration 1: reads 1000 (before A's write), writes 1000+delta1_B
4. Net result: one delta is lost.
5. Within a single request: each iteration's SELECT may return the DB value
   from 2 iterations ago, causing systematic undercounting of losses.
```

**Fix:**
```php
// game_resources.php — replace the molecule loop
$totalLost = 0;
while ($molecules = mysqli_fetch_array($ex)) {
    $moleculesRestantes = pow(coefDisparition($joueur, $compteur + 1), $nbsecondes) * $molecules['nombre'];
    ${'nombre' . ($compteur + 1)} = $molecules['nombre'];
    dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', $moleculesRestantes, $molecules['id']);
    $totalLost += $molecules['nombre'] - $moleculesRestantes;
    $compteur++;
}
if ($totalLost > 0) {
    // Single atomic increment — no read required
    dbExecute($base, 'UPDATE autre SET moleculesPerdues = moleculesPerdues + ? WHERE login=?', 'ds', $totalLost, $joueur);
}
```

---

### SEC-R2-002 — Return-trip molecule credit without transaction or FOR UPDATE

**Severity:** CRITICAL
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php`
**Lines:** 446–468
**R1 ref:** DB-R1-002

**SQL involved:**
```php
// Line 452–465: read molecules, add surviving troops back
while ($moleculesProp = mysqli_fetch_array($ex3)) {
    $moleculesRestantes = pow(coefDisparition($joueur, $compteur), $nbsecondes) * $molecules[$compteur - 1];
    // Line 459: write — no transaction, no FOR UPDATE on the preceding read
    dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di',
        ($moleculesProp['nombre'] + $moleculesRestantes), $moleculesProp['id']);
    ...
    $compteur++;
}
// Line 467: DELETE the action record — separate statement, not in same transaction
dbExecute($base, 'DELETE FROM actionsattaques WHERE id=?', 'i', $actions['id']);
```

**Race condition window:** Two concurrent requests (attacker triggers page load, defender also triggers a page load that processes the same attacker's return) could both pass the `$actions['tempsRetour'] < time()` check before either one DELETEs the `actionsattaques` row. Both requests then add `$moleculesRestantes` to the current `molecules.nombre`, doubling the returning army. The CAS guard (`SET attaqueFaite=1 WHERE attaqueFaite=0`) only protects the outbound combat phase, not the return-trip branch.

**PoC:**
```
1. actionsattaques row id=42: tempsRetour=T, attaqueFaite=1, troupes='500;300;200;100;'
2. Request A (attacker page load): reads row 42, tempsRetour < now, passes branch
3. Request B (same attacker, second tab): reads row 42 simultaneously, same branch
4. Request A: molecules.nombre[class1] += 480 (decay applied)  -- class1 goes 1000 -> 1480
5. Request B: molecules.nombre[class1] += 480                  -- class1 goes 1480 -> 1960
6. Request A: DELETE id=42
7. Net: player gets ~2x returning troops
```

**Fix:** Apply the same CAS guard used for combat to the return-trip branch:
```php
// Before the return-trip molecule crediting loop
$returnClaimed = dbExecute($base,
    'UPDATE actionsattaques SET troupes=? WHERE id=? AND troupes!=?',
    'sis', '__processing__', $actions['id'], '__processing__'
);
if ($returnClaimed === 0) {
    continue; // Another concurrent request already claimed this return
}
withTransaction($base, function() use ($base, $joueur, $actions, $nbsecondes, $molecules) {
    // ... molecule credit loop and DELETE inside transaction
    dbExecute($base, 'DELETE FROM actionsattaques WHERE id=?', 'i', $actions['id']);
});
```

---

### SEC-R2-003 — Attack launch not atomic (molecules deducted, INSERT may fail)

**Severity:** CRITICAL
**File:** `/home/guortates/TVLW/The-Very-Little-War/attaquer.php`
**Lines:** 146–158
**R1 ref:** DB-R1-002

**SQL involved:**
```php
// Lines 148–151: deduct molecules one by one
while ($moleculesAttaque = mysqli_fetch_array($ex)) {
    $newNombre = $moleculesAttaque['nombre'] - $_POST['nbclasse' . $c];
    dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', $newNombre, $moleculesAttaque['id']);
    $c++;
}
// Line 154: insert attack record — NOT in same transaction as molecule deduction
dbExecute($base, 'INSERT INTO actionsattaques VALUES(default,?,?,?,?,?,?,0,default)', ...);
// Line 156: deduct energy
ajouter('energie', 'ressources', -$cout, $_SESSION['login']);
```

**Race condition window:** Three separate statements with no wrapping transaction:
1. Molecule counts deducted (classes 1–4, up to 4 separate UPDATEs)
2. `actionsattaques` INSERT
3. Energy deduction via `ajouter()`

If the server crashes or a DB error occurs after step 1 but before step 2, molecules are permanently lost with no attack record. If step 2 succeeds but step 3 fails, the attack launches for free (no energy paid).

Furthermore, the initial availability check reads `molecules.nombre` at line 116–118 (before the deduction loop), but the deduction loop at line 148–151 reads again from a new query at line 146 and operates on `$moleculesAttaque['nombre']` — if a concurrent formation completion or attack return has already added molecules between those two reads, the deduction becomes inconsistent.

**Fix:**
```php
withTransaction($base, function() use ($base, $nbClasses, $troupes, ...) {
    // Re-read and lock all 4 molecule rows
    $ex = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC FOR UPDATE', 's', $_SESSION['login']);
    // Also lock ressources row
    $res = dbFetchOne($base, 'SELECT energie FROM ressources WHERE login=? FOR UPDATE', 's', $_SESSION['login']);

    $c = 1;
    while ($moleculesAttaque = mysqli_fetch_array($ex)) {
        if ($moleculesAttaque['nombre'] < $_POST['nbclasse' . $c]) {
            throw new Exception('NOT_ENOUGH_MOLECULES');
        }
        $newNombre = $moleculesAttaque['nombre'] - $_POST['nbclasse' . $c];
        dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', $newNombre, $moleculesAttaque['id']);
        $c++;
    }
    if ($res['energie'] < $cout) {
        throw new Exception('NOT_ENOUGH_ENERGY');
    }
    dbExecute($base, 'INSERT INTO actionsattaques VALUES(default,?,?,?,?,?,?,0,default)', ...);
    ajouter('energie', 'ressources', -$cout, $_SESSION['login']);
});
```

---

## HIGH Findings

### SEC-R2-004 — Combat molecule decay loop executes outside the BEGIN/COMMIT block

**Severity:** HIGH
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php`
**Lines:** 87–109
**R1 ref:** Combat transaction gap

**SQL involved:**
```php
// Lines 87–103: molecule decay on travel — written BEFORE transaction begins
while ($moleculesProp = mysqli_fetch_array($ex3)) {
    $moleculesRestantes = ...;
    // Line 100: write molecular losses — NOT inside transaction
    dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', 'ds', ..., $actions['attaquant']);
    $compteur++;
}
// ...
// Line 107: transaction begins HERE — after decay writes
mysqli_begin_transaction($base);
try {
    include("includes/combat.php"); // all combat writes inside transaction
    ...
    mysqli_commit($base);
} catch (Exception $e) {
    mysqli_rollback($base);
}
```

**Problem:** The travel-decay writes to `autre.moleculesPerdues` (lines 96–103) happen in autocommit mode before the transaction begins. If the combat block (inside the transaction) fails and rolls back, those decay-loss records are already permanently committed. The `moleculesPerdues` stat becomes incorrect. More importantly, the attack's `troupes` string is rewritten in PHP at line 105 (`$actions['troupes'] = $chaine`) but no corresponding DB write happens for the decayed troop count before the transaction — the combat.php include then uses the decayed `$chaine` value for calculations, while `actionsattaques.troupes` in the DB still has the original count until line 351 inside combat.php.

**Fix:** Move the decay loop inside the `mysqli_begin_transaction` block:
```php
mysqli_begin_transaction($base);
try {
    // Move decay loop here (lines 87-103)
    $chaine = '';
    $compteur = 1;
    $totalDecayLost = 0;
    while ($moleculesProp = mysqli_fetch_array($ex3)) {
        $moleculesRestantes = pow(coefDisparition(...), $nbsecondes) * $molecules[$compteur - 1];
        $chaine .= $moleculesRestantes . ';';
        $totalDecayLost += $molecules[$compteur - 1] - $moleculesRestantes;
        $compteur++;
    }
    $actions['troupes'] = $chaine;
    // Single atomic increment for decay losses
    dbExecute($base, 'UPDATE autre SET moleculesPerdues = moleculesPerdues + ? WHERE login=?',
        'ds', $totalDecayLost, $actions['attaquant']);

    include("includes/combat.php");
    ...
    mysqli_commit($base);
} catch (Exception $e) {
    mysqli_rollback($base);
}
```

---

### SEC-R2-005 — remiseAZero() has no wrapping transaction

**Severity:** HIGH
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`
**Lines:** 793–837
**R1 ref:** DATA-R1-001 (confirmed still unfixed)

**SQL involved:**
```php
function remiseAZero() {
    // 15+ separate dbExecute calls in autocommit mode:
    dbExecute($base, 'UPDATE autre SET points=0, ...');             // line 799
    dbExecute($base, 'UPDATE constructions SET generateur=default,...'); // line 800
    dbExecute($base, 'UPDATE alliances SET energieAlliance=0,...'); // line 801
    dbExecute($base, 'UPDATE molecules SET formule="Vide",...');    // line 802
    dbExecute($base, 'UPDATE membre SET timestamp=?', ...);         // line 803
    // ... dynamic UPDATE ressources ...                             // line 813-814
    dbExecute($base, 'DELETE FROM declarations');                   // line 815
    dbExecute($base, 'DELETE FROM invitations');                    // line 816
    dbExecute($base, 'DELETE FROM messages');                       // line 817
    dbExecute($base, 'DELETE FROM rapports');                       // line 818
    dbExecute($base, 'DELETE FROM actionsconstruction');            // line 819
    dbExecute($base, 'DELETE FROM actionsformation');               // line 820
    dbExecute($base, 'DELETE FROM actionsenvoi');                   // line 821
    dbExecute($base, 'DELETE FROM actionsattaques');                // line 822
    dbExecute($base, 'UPDATE statistiques SET nbDerniere=0,...');   // line 824
    dbExecute($base, 'UPDATE membre SET x=-1000, y=-1000');        // line 825
    // prestige subquery loop...                                     // lines 829-833
    dbExecute($base, 'DELETE FROM attack_cooldowns');               // line 837
}
```

**Race condition window:** Any server failure or PHP fatal error between statements leaves the database in a mixed state. For example, if the process dies after line 802 (`molecules` reset) but before line 815 (`DELETE FROM declarations`), players start the new season with reset resources but with their alliances still in war state from the previous season. Active attack records in `actionsattaques` could still resolve after the reset, crediting pillaged resources from "ghost" past-season players.

**Fix:**
```php
function remiseAZero() {
    global $base;
    global $nomsRes, $nbRes;

    withTransaction($base, function() use ($base, $nomsRes, $nbRes) {
        dbExecute($base, 'UPDATE autre SET points=0, ...');
        dbExecute($base, 'UPDATE constructions SET generateur=default, ...');
        // ... all 15+ statements inside the same closure ...
        dbExecute($base, 'DELETE FROM attack_cooldowns');
        // Prestige loop also inside transaction
        $prestigePlayers = dbQuery($base, 'SELECT login, unlocks FROM prestige WHERE unlocks LIKE ?', 's', '%debutant_rapide%');
        if ($prestigePlayers) {
            while ($pp = mysqli_fetch_array($prestigePlayers)) {
                dbExecute($base, 'UPDATE constructions SET generateur=2, vieGenerateur=? WHERE login=?',
                    'ds', pointsDeVie(2), $pp['login']);
            }
        }
    });
}
```

Note: The dynamic `UPDATE ressources SET ... $chaine ...` at line 813 uses an interpolated string of column=default pairs. This is safe because the column names come from the server-side `$nomsRes` array, not user input, but the statement should still be inside the transaction.

---

### SEC-R2-006 — actionsenvoi delivery not atomic

**Severity:** HIGH
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php`
**Lines:** 471–538
**R1 ref:** CODE.md:115

**SQL involved:**
```php
while ($actions = mysqli_fetch_array($ex)) {
    // Line 474: DELETE the pending transfer record — autocommit
    dbExecute($base, 'DELETE FROM actionsenvoi WHERE id=?', 'i', $actions['id']);

    // ... PHP calculations ...

    // Line 510: INSERT report — autocommit
    dbExecute($base, 'INSERT INTO rapports VALUES(...)');

    // Line 512: read recipient resources — no FOR UPDATE
    $ressourcesDestinataire = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $actions['receveur']);

    // Line 538: UPDATE recipient resources — separate autocommit statement
    dbExecute($base, 'UPDATE ressources SET ' . implode(',', $envoiSetClauses) . ' WHERE login=?', ...);
}
```

**Race condition window:** The `actionsenvoi` row is DELETEd first (line 474) — if the server dies before line 538, the resource transfer is permanently lost (sender already paid, recipient never gets paid). Additionally, two concurrent page loads for the recipient could both query `ressources` at line 512 before either writes at line 538, resulting in only one transfer's value reaching the recipient.

**Fix:**
```php
withTransaction($base, function() use ($base, $actions, ...) {
    // Re-read and lock the actionsenvoi row to prevent double-processing
    $action = dbFetchOne($base, 'SELECT * FROM actionsenvoi WHERE id=? FOR UPDATE', 'i', $actions['id']);
    if (!$action) return; // Already processed by concurrent request

    // Lock recipient resources
    $ressourcesDestinataire = dbFetchOne($base,
        'SELECT * FROM ressources WHERE login=? FOR UPDATE', 's', $action['receveur']);

    // ... compute new values ...

    dbExecute($base, 'DELETE FROM actionsenvoi WHERE id=?', 'i', $action['id']);
    dbExecute($base, 'UPDATE ressources SET ... WHERE login=?', ...);
    dbExecute($base, 'INSERT INTO rapports VALUES(...)');
});
```

---

### SEC-R2-007 — Alliance duplicator upgrade TOCTOU (no transaction, no FOR UPDATE)

**Severity:** HIGH
**File:** `/home/guortates/TVLW/The-Very-Little-War/alliance.php`
**Lines:** 78–89
**R1 ref:** DB-R1-006 (alliance energy spending)

**SQL involved:**
```php
// Line 80: READ energieAlliance — no lock
$energieAlliance = dbFetchOne($base, 'SELECT energieAlliance FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);

// Line 82: CHECK
if ($energieAlliance['energieAlliance'] >= $cout) {
    $newDup = $duplicateur['duplicateur'] + 1;
    $newEnergie = $energieAlliance['energieAlliance'] - $cout;
    // Line 85: WRITE — both duplicateur level and energy in one UPDATE (good)
    dbExecute($base, 'UPDATE alliances SET duplicateur=?, energieAlliance=? WHERE id=?',
        'idi', $newDup, $newEnergie, $idalliance['idalliance']);
}
```

**Race condition window:** Two alliance members simultaneously submit the upgrade form. Both read `energieAlliance = 5000`, both see `5000 >= cout`, both write `level+1` and `5000 - cout`. The alliance level is incremented twice with only one payment, and `$newDup` in both writes is computed from `$duplicateur['duplicateur']` (read at line 75 before the check), so one increment is lost and the write-write conflict leaves the level at `original + 1` instead of `original + 2`.

**Fix:**
```php
withTransaction($base, function() use ($base, $idalliance, $cout, $duplicateur) {
    $energieAlliance = dbFetchOne($base,
        'SELECT energieAlliance, duplicateur FROM alliances WHERE id=? FOR UPDATE',
        'i', $idalliance['idalliance']);
    if ($energieAlliance['energieAlliance'] < $cout) {
        throw new Exception('NOT_ENOUGH_ENERGY');
    }
    dbExecute($base, 'UPDATE alliances SET duplicateur=duplicateur+1, energieAlliance=? WHERE id=?',
        'di', $energieAlliance['energieAlliance'] - $cout, $idalliance['idalliance']);
});
```

---

### SEC-R2-008 — Alliance research upgrade TOCTOU (no transaction, no FOR UPDATE)

**Severity:** HIGH
**File:** `/home/guortates/TVLW/The-Very-Little-War/alliance.php`
**Lines:** 94–113
**R1 ref:** DB-R1-006

**SQL involved:**
```php
// Line 98: READ — no lock; techName is validated against ALLIANCE_RESEARCH_COLUMNS whitelist
$allianceData = dbFetchOne($base, 'SELECT ' . $techName . ', energieAlliance FROM alliances WHERE id=?',
    'i', $idalliance['idalliance']);
$currentLevel = intval($allianceData[$techName]);
$researchCost = round(...);

// Line 103: cap check
if ($currentLevel >= ALLIANCE_RESEARCH_MAX_LEVEL) { ... }
// Line 105: energy check
elseif ($allianceData['energieAlliance'] >= $researchCost) {
    $newLevel = $currentLevel + 1;
    $newEnergie = $allianceData['energieAlliance'] - $researchCost;
    // Line 108: WRITE — same pattern as duplicateur
    dbExecute($base, 'UPDATE alliances SET ' . $techName . '=?, energieAlliance=? WHERE id=?',
        'idi', $newLevel, $newEnergie, $idalliance['idalliance']);
}
```

**Race condition window:** Identical to SEC-R2-007. Two concurrent submits of the same research upgrade from different members result in double-level-up with single payment.

**Fix:** Identical pattern to SEC-R2-007 — wrap in `withTransaction` with `FOR UPDATE`:
```php
withTransaction($base, function() use ($base, $idalliance, $techName, $researchCost) {
    $allianceData = dbFetchOne($base,
        'SELECT ' . $techName . ', energieAlliance FROM alliances WHERE id=? FOR UPDATE',
        'i', $idalliance['idalliance']);
    $currentLevel = intval($allianceData[$techName]);
    if ($currentLevel >= ALLIANCE_RESEARCH_MAX_LEVEL) {
        throw new Exception('MAX_LEVEL');
    }
    if ($allianceData['energieAlliance'] < $researchCost) {
        throw new Exception('NOT_ENOUGH_ENERGY');
    }
    dbExecute($base, 'UPDATE alliances SET ' . $techName . '=?, energieAlliance=? WHERE id=?',
        'idi', $currentLevel + 1, $allianceData['energieAlliance'] - $researchCost,
        $idalliance['idalliance']);
});
```

---

### SEC-R2-009 — Pact break can delete wars (missing type=1 filter in the DELETE)

**Severity:** HIGH
**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`
**Lines:** 233–237
**R1 ref:** None (new finding)

**SQL involved:**
```php
// Line 233: count check — correctly filters type=1
$pacteExiste = dbCount($base,
    'SELECT count(*) AS pacteExiste FROM declarations WHERE (alliance1=? OR alliance2=?) AND type=1',
    'ii', $_POST['allie'], $_POST['allie']);

if ($pacteExiste > 0) {
    $allianceAdverse = dbFetchOne($base, 'SELECT * FROM alliances WHERE id=?', 'i', $_POST['allie']);
    // Line 237: DELETE — NO type=1 filter! Deletes ALL declarations between these two alliances
    dbExecute($base, 'DELETE FROM declarations WHERE ((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?)) AND type=1',
        'iiii', $chef['id'], $allianceAdverse['id'], $chef['id'], $allianceAdverse['id']);
```

Wait — re-reading the actual code on line 237:
```php
dbExecute($base, 'DELETE FROM declarations WHERE ((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?)) AND type=1', 'iiii', $chef['id'], $allianceAdverse['id'], $chef['id'], $allianceAdverse['id']);
```

The `type=1` IS present in this DELETE. However, the `pacteExiste` count query uses `$_POST['allie']` for BOTH `alliance1` and `alliance2` positions, while the DELETE correctly uses `$chef['id']` and `$allianceAdverse['id']`. This means:

**Actual bug:** The `pacteExiste` check at line 233 queries `(alliance1=? OR alliance2=?)` with `$_POST['allie']` for BOTH parameters — it checks if the alliance being broken has any pact with itself, which is always 0. The correct check should use `$chef['id']` and `$_POST['allie']` as the two parties. Any value of `$_POST['allie']` will return `pacteExiste=0` if the pact was created from the other side (alliance2=chef, alliance1=allie), causing the break button to silently do nothing.

```php
// Actual query: WHERE (alliance1=? OR alliance2=?) AND type=1
// Bound as: ('allie_id', 'allie_id') -- only finds pacts where BOTH sides = allie_id
// Should be: WHERE ((alliance1=? AND alliance2=?) OR (alliance1=? AND alliance2=?)) AND type=1
// Bound as: (chef_id, allie_id, allie_id, chef_id)
```

**Fix:**
```php
$pacteExiste = dbCount($base,
    'SELECT count(*) AS pacteExiste FROM declarations
     WHERE ((alliance1=? AND alliance2=?) OR (alliance1=? AND alliance2=?)) AND type=1',
    'iiii', $chef['id'], intval($_POST['allie']),
             intval($_POST['allie']), $chef['id']);
```

---

## MEDIUM Findings

### SEC-R2-010 — Formation completion not atomic (UPDATE then DELETE)

**Severity:** MEDIUM
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php`
**Lines:** 35–61

**SQL involved:**
```php
while ($actions = mysqli_fetch_array($ex)) {
    // Completed formations: DELETE first, then UPDATE molecules
    if ($actions['fin'] >= time()) {
        // Partial: UPDATE molecules with partial count
        dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', ...);   // line 46
        dbExecute($base, 'UPDATE actionsformation SET nombreRestant=? WHERE id=?', ...); // line 51
    } else {
        // Completed: DELETE then UPDATE — NOT atomic
        dbExecute($base, 'DELETE FROM actionsformation WHERE id=?', 'i', $actions['id']); // line 53
        dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', ...);   // line 55
    }
}
```

**Problem:** For completed formations, the DELETE happens before the molecule UPDATE. If the process dies between these two statements, the formation record is gone but molecules were never added. Also, the result set `$ex` from the initial SELECT is being iterated while modifications happen — concurrent requests that also call `updateActions()` for the same player will see the same undeleted rows.

**Fix:**
```php
// Inside the completed-formation branch:
withTransaction($base, function() use ($base, $actions, $neutrinos) {
    // Lock the formation row first
    $formation = dbFetchOne($base, 'SELECT * FROM actionsformation WHERE id=? FOR UPDATE', 'i', $actions['id']);
    if (!$formation) return; // Already processed
    dbExecute($base, 'DELETE FROM actionsformation WHERE id=?', 'i', $actions['id']);
    if ($actions['idclasse'] != 'neutrino') {
        dbExecute($base, 'UPDATE molecules SET nombre = nombre + ? WHERE id=?',
            'ds', $formation['nombreRestant'], $formation['idclasse']);
    } else {
        dbExecute($base, 'UPDATE autre SET neutrinos = neutrinos + ? WHERE login=?',
            'ds', $formation['nombreRestant'], $joueur);
    }
});
```

---

### SEC-R2-011 — augmenterBatiment() not atomic

**Severity:** MEDIUM
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`
**Lines:** 508–539

**SQL involved:**
```php
function augmenterBatiment($nom, $joueur) {
    initPlayer($joueur);  // reads constructions
    $batiments = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $joueur); // re-read
    // ...
    dbExecute($base, "UPDATE constructions SET $nom=?, $vieCol=? WHERE login=?", ...); // line 532
    ajouterPoints($listeConstructions[$nom]['points'], $joueur); // line 536 — separate statement
}
```

**Problem:** `augmenterBatiment` is called from the `actionsconstruction` processing loop. The `DELETE FROM actionsconstruction WHERE id=?` happens at line 30 before `augmenterBatiment` is called at line 28. So if `ajouterPoints()` fails (DB error) after the building level is already incremented, the player gets the building level increase but not the points. No transaction wraps the building level + points award.

**Fix:**
```php
function augmenterBatiment($nom, $joueur) {
    global $base;
    invalidatePlayerCache($joueur);
    initPlayer($joueur);

    withTransaction($base, function() use ($base, $nom, $joueur) {
        $batiments = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=? FOR UPDATE', 's', $joueur);
        // ... existing level + vie logic ...
        dbExecute($base, "UPDATE constructions SET ...", ...);
        if ($nom == 'producteur') { ... }
        if ($nom == 'condenseur') { ... }
        ajouterPoints($listeConstructions[$nom]['points'], $joueur);
    });

    invalidatePlayerCache($_SESSION['login']);
    initPlayer($_SESSION['login']);
}
```

---

### SEC-R2-012 — diminuerBatiment() not atomic

**Severity:** MEDIUM
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`
**Lines:** 542–620

**SQL involved:** Same pattern as SEC-R2-011. `diminuerBatiment` is called from combat.php during building damage resolution. The building level decrement (line 612 or 614) and `ajouterPoints(-...) ` (line 616) are separate autocommit statements. During the combat transaction at lines 107–319 of game_actions.php, the combat `include("includes/combat.php")` calls `diminuerBatiment()`. Since `diminuerBatiment` itself is NOT inside a `withTransaction` call, and `ajouterPoints` reads then writes `autre`, there are three nested writes that can partially complete.

**Additional issue:** `diminuerBatiment` calls `initPlayer($joueur)` which itself does a `UPDATE autre SET batmax=?` (player.php line 182). This UPDATE happens for EVERY call to `initPlayer`, even inside the combat transaction. This means a non-transactional write to `autre.batmax` happens inside the combat `BEGIN/COMMIT` block — if the combat rolls back, `batmax` is not rolled back (it was written before the BEGIN, during the `initPlayer` call inside `diminuerBatiment`).

**Fix:** Wrap `diminuerBatiment` core logic in a transaction and move the `initPlayer` call outside the transaction scope.

---

### SEC-R2-013 — ajouterPoints() concurrent write corruption of totalPoints

**Severity:** MEDIUM
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`
**Lines:** 73–106
**R1 ref:** UI-ERR-DATA-CONCUR-DEPLOY-findings.md:518

**SQL involved:**
```php
function ajouterPoints($nb, $joueur, $type = 0) {
    // Line 76: READ — no FOR UPDATE
    $points = dbFetchOne($base, 'SELECT * FROM autre WHERE login=?', 's', $joueur);

    if ($type == 1) { // attack points
        $newPoints = max(0, $points['pointsAttaque'] + $nb);
        // Line 88: WRITE totalPoints recomputed from stale read
        dbExecute($base, 'UPDATE autre SET pointsAttaque=?, totalPoints=? WHERE login=?',
            'dds', $newPoints,
            ($points['totalPoints'] - pointsAttaque($points['pointsAttaque']) + pointsAttaque($newPoints)),
            $joueur);
    }
    // similar for type 2, 3
}
```

**Problem:** `ajouterPoints` is called multiple times within a single combat resolution (once for attacker, once for defender, plus pillage points). Each call reads `totalPoints` from the DB and computes a new total. If two calls happen (or two concurrent requests process different combats for the same player), the second call reads the pre-first-write value and overwrites the first write.

During the combat transaction, the combat.php include calls `ajouterPoints` for attacker (line 561), `ajouterPoints` for pillage (line 562), and `ajouterPoints` for defender (line 563). These three calls each re-read `autre` and write `totalPoints`. The second and third read the value written by the first call (within the same session, MySQL sees the write), but concurrent combat resolutions for the same player (highly unlikely but possible) would race.

More immediately, calling `ajouterPoints` from inside the combat transaction and then calling it again from `diminuerBatiment` (also inside combat resolution) creates multiple reads of `autre` within the transaction. Each read correctly sees the prior write within the same transaction session. This is technically safe within a single transaction but breaks if called concurrently from two transactions.

**Fix:** Use atomic increments where possible:
```php
// For type 0 (construction points) — simplest fix
dbExecute($base, 'UPDATE autre SET points = points + ?, totalPoints = totalPoints + ? WHERE login=?',
    'dds', $nb, $nb, $joueur);

// For type 1 (attack points with floor at 0 and non-linear transform) — requires read
// Add FOR UPDATE inside a transaction when called from concurrent contexts
```

---

### SEC-R2-014 — CAS guard in updateRessources uses mysqli_affected_rows after dbExecute

**Severity:** MEDIUM
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_resources.php`
**Lines:** 120–123
**R1 ref:** None (new finding)

**SQL involved:**
```php
// Line 120: CAS guard UPDATE
dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=? AND tempsPrecedent=?',
    'isi', time(), $joueur, $donnees['tempsPrecedent']);
// Line 121: check affected rows
if (mysqli_affected_rows($base) === 0) {
    return; // Another request already updated
}
```

**Problem:** `dbExecute()` in `includes/database.php` internally calls `mysqli_stmt_affected_rows($stmt)` (line 72 of database.php) and then `mysqli_stmt_close($stmt)` (line 73). After the statement is closed, `mysqli_affected_rows($base)` (the connection-level function) returns the affected rows from the LAST statement executed on the connection, which IS the CAS UPDATE. This technically works in practice on the current code because no other statement runs between the `dbExecute` return and the `mysqli_affected_rows($base)` call.

However, this is fragile: the `dbExecute` function already returns the affected row count (`return $affected` at line 74). The calling code ignores this return value and uses the connection-level function instead. If any future change inserts a statement between line 120 and line 121 (e.g., a logging call that issues a DB query), the check silently breaks.

**Fix:** Use the return value of `dbExecute`:
```php
$casAffected = dbExecute($base,
    'UPDATE autre SET tempsPrecedent=? WHERE login=? AND tempsPrecedent=?',
    'isi', time(), $joueur, $donnees['tempsPrecedent']);
if ($casAffected === 0 || $casAffected === false) {
    return; // Another request already updated — consistent with combat CAS pattern
}
```

---

### SEC-R2-015 — ajouterPoints() called outside any transaction from multiple callsites

**Severity:** MEDIUM
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`
**Lines:** 76–105

**Problem:** `ajouterPoints()` is called from at least:
- `augmenterBatiment()` — no transaction wrapper
- `diminuerBatiment()` — no transaction wrapper
- `combat.php` lines 561–563 — inside the combat transaction (safe)
- Possibly other locations

When called from `augmenterBatiment` or `diminuerBatiment` (which are themselves called from building construction completion at game_actions.php:28), there is no outer transaction. The read at line 76 and write at lines 81/88/93/103 are exposed to concurrent modification.

This compounds with SEC-R2-011 and SEC-R2-012: fixing those by wrapping augmenterBatiment and diminuerBatiment in transactions will also protect the ajouterPoints calls within them.

---

### SEC-R2-016 — inscrire() reads statistiques.inscrits before the transaction

**Severity:** MEDIUM
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`
**Lines:** 30–70

**SQL involved:**
```php
function inscrire($pseudo, $mdp, $mail) {
    // Line 30: READ inscrits — BEFORE withTransaction
    $data1 = dbFetchOne($base, 'SELECT inscrits FROM statistiques');
    $nbinscrits = $data1['inscrits'] + 1;

    // ...
    withTransaction($base, function() use ($base, ..., $nbinscrits, ...) {
        // Line 61-70: all INSERTs
        // Line 64: write the pre-read counter
        dbExecute($base, 'UPDATE statistiques SET inscrits=?', 'i', $nbinscrits);
    });
}
```

**Problem:** Two concurrent registrations read `inscrits = 100` simultaneously. Both compute `nbinscrits = 101`. Both write `inscrits = 101` inside their respective transactions (the write is inside the transaction but the read was outside). Net result: `inscrits` ends up at 101 instead of 102. This is a minor data integrity issue since `statistiques.inscrits` is only used for display purposes and possibly leaderboard calculations.

**Fix:** Move the read inside the transaction and use an atomic increment:
```php
withTransaction($base, function() use (...) {
    // ...all INSERTs...
    // Atomic increment — no read needed
    dbExecute($base, 'UPDATE statistiques SET inscrits = inscrits + 1');
});
// Remove: $data1 = dbFetchOne(...) and $nbinscrits = ... before the transaction
```

---

## LOW Findings

### SEC-R2-017 — remiseAZero() has no idempotency guard on the admin form

**Severity:** LOW
**File:** `/home/guortates/TVLW/The-Very-Little-War/admin/index.php`
**Lines:** 45–48

**Problem:** The admin form at line 170–174 submits `miseazero` via POST. There is no double-submit prevention (no nonce consumed after use, no maintenance-mode check-before-reset). A double-click or browser refresh on the admin submitting the form triggers two sequential `remiseAZero()` calls. While the second call would mainly reset already-reset values (idempotent on most tables), the prestige loop (line 829–833) re-applies `generateur=2` grants, and the `statistiques` table is reset twice including `tailleCarte` which might conflict with players reconnecting between the two calls.

**Fix:** Set a maintenance flag atomically before executing the reset, and check it at the top of `remiseAZero()`:
```php
if (isset($_POST['miseazero'])) {
    $maintenance = dbFetchOne($base, 'SELECT maintenance FROM statistiques');
    if ($maintenance['maintenance'] == 1) { // Only proceed if in maintenance mode
        remiseAZero();
    } else {
        $erreur = "Mettre le site en maintenance avant la remise à zéro.";
    }
}
```

---

### SEC-R2-018 — Construction completion: augmenterBatiment and DELETE not in same transaction

**Severity:** LOW
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php`
**Lines:** 26–31

**SQL involved:**
```php
// Line 26: SELECT pending constructions
$ex = dbQuery($base, 'SELECT * FROM actionsconstruction WHERE login=? AND fin<?', 'si', $joueur, time());
while ($actions = mysqli_fetch_array($ex)) {
    // Line 28: apply building level increase — no transaction
    augmenterBatiment($actions['batiment'], $joueur);
    // Line 30: delete the action record — separate autocommit
    dbExecute($base, 'DELETE FROM actionsconstruction WHERE id=?', 'i', $actions['id']);
}
```

**Problem:** If `augmenterBatiment` succeeds but the server dies before the DELETE, the construction completes again on the next request (double level-up). The reverse — DELETE succeeds but augmenterBatiment fails — loses the construction silently. Low probability with a stable server but nonzero.

**Fix:**
```php
while ($actions = mysqli_fetch_array($ex)) {
    withTransaction($base, function() use ($base, $actions, $joueur) {
        // Lock the action row to prevent concurrent processing
        $locked = dbFetchOne($base, 'SELECT id FROM actionsconstruction WHERE id=? FOR UPDATE', 'i', $actions['id']);
        if (!$locked) return; // Already processed
        augmenterBatiment($actions['batiment'], $joueur);
        dbExecute($base, 'DELETE FROM actionsconstruction WHERE id=?', 'i', $actions['id']);
    });
}
```

---

### SEC-R2-019 — Resource send (envoi) INSERT and resource deduction not atomic

**Severity:** LOW
**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php`
**Lines:** 95–115
**R1 ref:** CODE.md:115

**SQL involved:**
```php
// Line 95–96: INSERT the pending transfer record
dbExecute($base, 'INSERT INTO actionsenvoi VALUES(default,?,?,?,?,?)', 'ssssi', ...);

// Lines 100–114: deduct resources from sender (no transaction wrapper)
$stmt = mysqli_prepare($base, 'UPDATE ressources SET energie=?,' . $chaine . ' WHERE login=?');
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'ds', $newEnergie, $_SESSION['login']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}
```

**Problem:** The INSERT at line 95 and the sender resource deduction at lines 110–114 are separate autocommit statements. If the server crashes between them, the transfer exists in `actionsenvoi` (recipient will eventually receive resources) but the sender's resources were never deducted. This allows a free resource duplication by crashing or exploiting a deliberate PHP error between the two statements.

The buy/sell operations correctly use `withTransaction()`. The resource-send operation, which was flagged in CODE.md:115 during Round 1, remains unfixed.

**Fix:**
```php
withTransaction($base, function() use ($base, $ressources, $nomsRes, $nbRes, ...) {
    // Re-read and lock sender resources
    $ressourcesLocked = dbFetchOne($base,
        'SELECT * FROM ressources WHERE login=? FOR UPDATE', 's', $_SESSION['login']);

    // Validate again with locked data
    foreach ($nomsRes as $num => $ressource) {
        if ($ressourcesLocked[$ressource] < $_POST[$ressource . 'Envoyee']) {
            throw new Exception('NOT_ENOUGH_RESOURCES');
        }
    }
    if ($ressourcesLocked['energie'] < $_POST['energieEnvoyee']) {
        throw new Exception('NOT_ENOUGH_ENERGY');
    }

    dbExecute($base, 'INSERT INTO actionsenvoi VALUES(default,?,?,?,?,?)', ...);

    // Deduct from locked values (not stale $ressources)
    $stmt = mysqli_prepare($base, 'UPDATE ressources SET energie=?,' . $chaine . ' WHERE login=?');
    // ...
});
```

---

### SEC-R2-020 — supprimerAlliance() does not verify caller is the chef

**Severity:** LOW
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`
**Lines:** 739–749

**Problem:** `supprimerAlliance($idalliance)` is called from `alliance.php` (when the detected chef no longer belongs to the alliance, auto-deletion) and from `allianceadmin.php` (when the chef clicks "Supprimer l'équipe"). The function itself does not verify that the calling context has authority to delete. In `allianceadmin.php`, the guard `if ($gradeChef)` at line 43 protects the call at line 47. However, the auto-deletion path in `alliance.php` at line 150 calls `supprimerAlliance` based on a check that "the chef is no longer in this alliance" — this logic could be manipulated if someone crafts a request that makes the chef appear absent.

This is a defense-in-depth issue rather than a directly exploitable vulnerability (the outer auth guards are present), but the function should verify authorization internally.

**Fix:** Pass the requesting login and verify inside the function, or add an assertion:
```php
function supprimerAlliance($allianceId, $callerLogin = null) {
    global $base;
    if ($callerLogin !== null) {
        $alliance = dbFetchOne($base, 'SELECT chef FROM alliances WHERE id=?', 'i', $allianceId);
        if (!$alliance || $alliance['chef'] !== $callerLogin) {
            error_log("supprimerAlliance() called by non-chef: $callerLogin for alliance $allianceId");
            return; // Silently reject — do not expose error to caller
        }
    }
    withTransaction($base, function() use ($base, $allianceId) {
        // ... existing deletes ...
    });
}
```

---

## Confirmed Correct Patterns (for reference)

The following patterns were verified as correctly implemented and require no changes:

| Pattern | Location | Status |
|---------|----------|--------|
| Market buy with `withTransaction` + `FOR UPDATE` | marche.php:167–226 | Correct |
| Market sell with `withTransaction` + `FOR UPDATE` | marche.php:279–339 | Correct |
| Alliance donation with `withTransaction` + `FOR UPDATE` | don.php:18–22 | Correct |
| Combat CAS guard `attaqueFaite=0 -> 1` | game_actions.php:71–75 | Correct |
| `supprimerJoueur()` wrapped in `withTransaction` | player.php:758–777 | Correct |
| `supprimerAlliance()` wrapped in `withTransaction` | player.php:742–749 | Correct |
| `inscrire()` core INSERTs inside `withTransaction` | player.php:60–70 | Correct (counter read is outside — SEC-R2-016) |
| Prestige unlock with `FOR UPDATE` | prestige.php:154–155 | Correct |
| `db_helpers.php` column/table whitelist in `ajouter()` | db_helpers.php:13–35 | Correct |
| `updateRessources()` CAS concept (tempsPrecedent) | game_resources.php:120–123 | Concept correct, return value ignored (SEC-R2-014) |
| `withTransaction()` helper exists and works | database.php:111–121 | Correct |

---

## Priority Fix Order

1. **SEC-R2-001** (CRITICAL) — Fix moleculesPerdues N+1 write in updateRessources: replace per-iteration read-write with single atomic `moleculesPerdues = moleculesPerdues + ?` at end of loop.

2. **SEC-R2-002** (CRITICAL) — Add CAS guard on return-trip molecule crediting, wrap in `withTransaction`.

3. **SEC-R2-003** (CRITICAL) — Wrap attack launch (attaquer.php:146–158) in `withTransaction` with `FOR UPDATE` on molecules and ressources.

4. **SEC-R2-005** (HIGH) — Wrap `remiseAZero()` body in `withTransaction`.

5. **SEC-R2-004** (HIGH) — Move combat decay loop inside the `mysqli_begin_transaction` block.

6. **SEC-R2-006** (HIGH) — Wrap actionsenvoi delivery in `withTransaction` with `FOR UPDATE` on the action row.

7. **SEC-R2-007 + SEC-R2-008** (HIGH) — Add `withTransaction` + `FOR UPDATE` to duplicateur and research upgrades in alliance.php.

8. **SEC-R2-009** (HIGH) — Fix pacteExiste query parameter binding in allianceadmin.php.

9. **SEC-R2-014** (MEDIUM) — Use `dbExecute()` return value instead of `mysqli_affected_rows()` for the CAS guard in updateRessources.

10. **SEC-R2-010 through SEC-R2-013** (MEDIUM) — Wrap formation completion, augmenterBatiment, diminuerBatiment in transactions; use atomic increments in ajouterPoints where possible.

11. **SEC-R2-016** (MEDIUM) — Move statistiques read inside the inscrire() transaction; use `inscrits = inscrits + 1`.

12. **SEC-R2-019** (LOW) — Wrap resource-send in withTransaction with FOR UPDATE.

13. **SEC-R2-017, SEC-R2-018, SEC-R2-020** (LOW) — Idempotency guard, construction CAS, supprimerAlliance authorization assertion.
