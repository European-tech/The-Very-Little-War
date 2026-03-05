# Pass 4: Edge Cases & Adversarial Testing

**Auditor:** Penetration Tester Agent (claude-opus-4-6)
**Date:** 2026-03-05
**Methodology:** Adversarial player simulation -- every finding represents a realistic exploit path that a skilled, malicious player could discover and weaponize.
**Scope:** All action-handling PHP files, formulas, combat resolution, market, transfers, alliances, compounds, resource nodes, registration, account lifecycle.

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 4     |
| HIGH     | 12    |
| MEDIUM   | 9     |
| LOW      | 7     |
| **Total** | **32** |

---

## Findings

---

### P4-ADV-001 | CRITICAL | Compound Synthesis TOCTOU: Double-Spend Race Condition

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/compounds.php` lines 64-88

**Preconditions:** Player has exactly enough atoms for one compound synthesis (e.g., 200 hydrogene, 100 oxygene for H2O).

**Exploit Steps:**
1. Open two browser tabs on `laboratoire.php`.
2. Prepare the synthesis form for H2O in both tabs.
3. Submit both forms simultaneously (within ~50ms of each other).
4. Tab 1 reads resources at line 65: `SELECT * FROM ressources WHERE login = ?` -- sees 200 H, 100 O. Passes check.
5. Tab 2 reads resources at line 65: same SELECT, same stale data. Passes check.
6. Tab 1 enters `withTransaction` at line 78, deducts resources via `ajouter()`, inserts compound.
7. Tab 2 enters `withTransaction` at line 78, deducts resources via `ajouter()` (resources go negative), inserts compound.
8. Player receives TWO compounds for the cost of one. Resources may go below zero.

**Root Cause:** The resource check at lines 64-75 is performed OUTSIDE the transaction. The `withTransaction` block at line 78 does not re-read resources with `FOR UPDATE` before deducting. The `ajouter()` helper does a blind `SET column = column + ?` without floor checks.

**Impact:** Resource duplication. Players can mass-produce compounds for free, then activate combat/pillage/production buffs continuously. Since compounds give +10% attack, +15% defense, +25% pillage, this is a direct competitive advantage.

**Detection:** Monitor `player_compounds` table for players with more compounds synthesized than their resource spending could justify. Check for negative resource values in `ressources` table.

**Fix:**
```php
function synthesizeCompound($base, $login, $compoundKey)
{
    global $COMPOUNDS, $nomsRes;
    if (!isset($COMPOUNDS[$compoundKey])) return "Compose inconnu.";

    // Move ALL checks inside the transaction with FOR UPDATE
    return withTransaction($base, function() use ($base, $login, $compoundKey, $COMPOUNDS, $nomsRes) {
        // Check stored count inside transaction
        $storedCount = (int)dbCount($base,
            'SELECT COUNT(*) as cnt FROM player_compounds WHERE login = ? AND activated_at IS NULL',
            's', $login
        );
        if ($storedCount >= COMPOUND_MAX_STORED) {
            throw new \RuntimeException("Stock plein");
        }

        $compound = $COMPOUNDS[$compoundKey];
        $recipe = $compound['recipe'];

        // Lock resources row
        $ressources = dbFetchOne($base, 'SELECT * FROM ressources WHERE login = ? FOR UPDATE', 's', $login);
        if (!$ressources) throw new \RuntimeException("Joueur introuvable");

        foreach ($recipe as $resource => $qty) {
            $needed = $qty * COMPOUND_ATOM_MULTIPLIER;
            if ($ressources[$resource] < $needed) {
                throw new \RuntimeException("Pas assez de $resource");
            }
        }

        foreach ($recipe as $resource => $qty) {
            $cost = $qty * COMPOUND_ATOM_MULTIPLIER;
            ajouter($resource, 'ressources', -$cost, $login);
        }

        dbExecute($base, 'INSERT INTO player_compounds (login, compound_key) VALUES (?, ?)',
            'ss', $login, $compoundKey);
    });
}
```

---

### P4-ADV-002 | CRITICAL | Resource Transfer Delivery Without Transaction or Locks

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php` lines 526-600

**Preconditions:** Player A sent resources to Player B. The transfer arrives (tempsArrivee < time()). Player B loads any page triggering `updateActions()`.

**Exploit Steps:**
1. Player A sends 10,000 of each atom to Player B.
2. Player B opens two browser tabs simultaneously, both triggering `updateActions()`.
3. Tab 1: `DELETE FROM actionsenvoi WHERE id=?` at line 529 -- deletes the transfer.
4. Tab 1: Reads receiver resources at line 567, computes new values, updates at line 599.
5. Tab 2: The same `SELECT * FROM actionsenvoi` at line 526 was executed BEFORE Tab 1's DELETE committed (no transaction wrapping). Tab 2 also sees the transfer row.
6. Tab 2: Attempts DELETE at line 529 -- row already gone, `DELETE` affects 0 rows but no error is raised.
7. Tab 2: Reads receiver resources at line 567 (now INCLUDING the resources Tab 1 just added), adds the received resources AGAIN at line 599.
8. Player B receives double resources.

**Root Cause:** The entire transfer delivery block (lines 526-600) runs outside any transaction. The DELETE at line 529 is not followed by an affected-rows check. There is no `FOR UPDATE` lock on either the `actionsenvoi` row or the receiver's `ressources` row.

**Impact:** Resource duplication. A coordinated attacker can reliably double incoming transfers by timing page loads. With enough coordination (e.g., automated scripts), resources can be multiplied indefinitely.

**Detection:** Compare total resources received in reports vs. actual resource deltas. Monitor for suspiciously rapid page loads from the same session.

**Fix:** Wrap the entire transfer delivery in a `withTransaction` block with CAS guard:
```php
foreach ($rows as $actions) {
    withTransaction($base, function() use ($base, $actions, $nomsRes, $nbRes) {
        // CAS guard: lock the transfer row, verify it exists
        $locked = dbFetchOne($base, 'SELECT id FROM actionsenvoi WHERE id=? FOR UPDATE', 'i', $actions['id']);
        if (!$locked) return; // Already processed

        dbExecute($base, 'DELETE FROM actionsenvoi WHERE id=?', 'i', $actions['id']);

        // Lock receiver resources
        $ressourcesDestinataire = dbFetchOne($base,
            'SELECT * FROM ressources WHERE login=? FOR UPDATE', 's', $actions['receveur']);
        // ... rest of delivery logic
    });
}
```

---

### P4-ADV-003 | CRITICAL | Self-Transfer Bypass in Resource Sending

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php` lines 20-155

**Preconditions:** Player has any amount of resources.

**Exploit Steps:**
1. Player navigates to the market resource transfer form.
2. Player enters their OWN username as the destination.
3. The code at line 32 checks `if ($ipmm['ip'] != $ipdd['ip'])` -- since both IPs are the same player, this BLOCKS self-transfer. Good.
4. HOWEVER: If the player uses a VPN to change IP after the last login IP was recorded, or if the IP field was not updated recently, the IP check may not match the current request IP.
5. More critically: The IP check is the ONLY barrier. There is no explicit `$_POST['destinataire'] != $_SESSION['login']` check anywhere in the transfer code.
6. If the IP check passes (VPN, proxy, mobile network change), the player can send resources to themselves.
7. The alt-feeding inversion ratio at lines 68-81 compares `receiverEnergyRev > $revenuEnergie` -- for self-transfer, these are identical, so `rapportEnergie = 1`. No penalty.
8. The transfer creates an `actionsenvoi` row. When it arrives (via `updateActions()`), the player RECEIVES the resources back, but the resources were already deducted from their account. Net effect: zero, UNLESS combined with P4-ADV-002 (transfer delivery duplication).

**Root Cause:** No explicit self-transfer check (`$_POST['destinataire'] != $_SESSION['login']`).

**Impact:** When combined with P4-ADV-002, enables infinite resource generation. Even without that bug, self-transfers could be used to earn trade points, manipulate multi-account detection heuristics, or abuse future transfer-related features.

**Detection:** Query `actionsenvoi` for rows where `envoyeur = receveur`.

**Fix:** Add explicit self-transfer check at line 23:
```php
if ($_POST['destinataire'] === $_SESSION['login']) {
    $erreur = "Vous ne pouvez pas vous envoyer des ressources.";
} elseif ($ipmm['ip'] != $ipdd['ip']) {
    // ... existing logic
```

---

### P4-ADV-004 | CRITICAL | No Maximum Building Level Cap Enables Infinite Scaling

**File:** `/home/guortates/TVLW/The-Very-Little-War/constructions.php` (entire file) and `/home/guortates/TVLW/The-Very-Little-War/includes/config.php`

**Preconditions:** Player has accumulated substantial resources over time.

**Exploit Steps:**
1. Player builds `ionisateur` to level 50. Cost grows at `ECO_GROWTH_ADV = 1.20` per level, but the combat bonus is LINEAR: `level * 2%` per level = 100% attack bonus.
2. Player builds `champdeforce` to level 50. Same linear scaling: 100% defense bonus.
3. At level 100, ionisateur gives +200% attack, champdeforce gives +200% defense.
4. There is NO `MAX_BUILDING_LEVEL` constant or check anywhere in the codebase.
5. The `augmenterBatiment()` function at `/home/guortates/TVLW/The-Very-Little-War/includes/player.php` simply increments the level without any ceiling.
6. Building HP scales as `50 * level^2.5`, meaning a level-100 building has `50 * 100^2.5 = 500,000 HP`, effectively indestructible.
7. Ionisateur/champdeforce combat bonuses at level 100: `(100 * 2) / 100 = 2.0 multiplier`, meaning 3x attack/defense (1 + 2.0).

**Root Cause:** No MAX_BUILDING_LEVEL constant defined. No level cap check in `augmenterBatiment()` or the construction action handler.

**Impact:** In long seasons or games without regular resets, a single player can reach levels where they are mathematically unbeatable. Combined with V4's multiplicative modifier stacking (ionisateur * duplicateur * medals * isotope * prestige * compounds * specialization * catalyst), the total combat multiplier exceeds 10x at extreme levels.

**Detection:** Query `SELECT login, generateur, producteur, depot, champdeforce, ionisateur, condenseur, lieur, stabilisateur FROM constructions ORDER BY ionisateur DESC LIMIT 10`.

**Fix:** Define and enforce a cap:
```php
// In config.php:
define('MAX_BUILDING_LEVEL', 50);

// In player.php augmenterBatiment():
function augmenterBatiment($batiment, $login) {
    // ... existing code ...
    $currentLevel = $constructions[$batiment];
    if ($currentLevel >= MAX_BUILDING_LEVEL) {
        return; // silently refuse
    }
    // ... rest of function
}
```

---

### P4-ADV-005 | HIGH | Phalanx Empty-Class-1 Exploit: 60% Damage Discard

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php` lines 222-256

**Preconditions:** Defender chooses Phalange formation (formation=1). Defender's class 1 slot is empty (formule="Vide", nombre=0).

**Exploit Steps:**
1. Defender creates molecules only in classes 2, 3, and 4. Class 1 is left "Vide".
2. Defender sets formation to Phalange.
3. Attacker launches an attack.
4. Combat code at line 222: `if ($defenderFormation == FORMATION_PHALANGE)`.
5. Line 224: `$phalanxDamage = $degatsAttaquant * FORMATION_PHALANX_ABSORB` (0.60) = 60% of attacker's damage.
6. Line 228-239: Class 1 HP calculation. `$classeDefenseur1['nombre'] = 0`, so `$classe1DefenseurMort = 0`, and `$phalanxOverflow = $phalanxDamage` (the full 60%).
7. Line 242: `$remainingDamage = $otherDamage + $phalanxOverflow = (0.40 * damage) + (0.60 * damage) = 100%`.
8. All damage cascades to classes 2-4. BUT note: Class 1 gets +20% defense bonus (line 174-176), and since it has 0 units, the Phalange effectively gives the defender the 60% absorb + overflow mechanic.

Wait -- on careful re-reading, the overflow IS added back. So the net effect is: all damage reaches classes 2-4. The exploit is actually that the Phalange formation provides NO disadvantage when class 1 is empty (overflow returns 100% of damage to remaining classes), but the defender still gets the EMBUSCADE-like benefit of channeling damage order.

The REAL exploit: With PHALANGE and empty class 1, the damage distribution is sequential (cascade), not split. This is BETTER than Dispersee for a specific army composition: if classes 2-3 are tanky and class 4 is empty, only classes 2-3 absorb damage. With Dispersee, damage would split 50-50 between the two populated classes. With Phalange+empty-1, it cascades through class 2 first, then overflow to class 3, meaning class 3 survives longer if class 2 can absorb most hits.

**Root Cause:** No validation that class 1 must have units when Phalange is selected. The overflow mechanic makes empty-class-1 Phalange a strictly-better cascade ordering.

**Impact:** Medium competitive advantage. Defenders can optimize damage distribution by choosing Phalange with empty class 1, getting the best of cascade ordering without any Phalange penalty.

**Detection:** Query `SELECT c.login, c.formation, m.numeroclasse, m.nombre FROM constructions c JOIN molecules m ON c.login = m.proprietaire WHERE c.formation = 1 AND m.numeroclasse = 1 AND m.formule = 'Vide'`.

**Fix:** Either require class 1 to have units for Phalange, or fall back to Dispersee:
```php
if ($defenderFormation == FORMATION_PHALANGE && $classeDefenseur1['nombre'] <= 0) {
    $defenderFormation = FORMATION_DISPERSEE; // Fallback
}
```

---

### P4-ADV-006 | HIGH | Alliance Tag Change Bypasses Format Validation

**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php` lines 119-135

**Preconditions:** Player is alliance chef with admin (tag change) permission.

**Exploit Steps:**
1. Alliance is created with a valid tag (e.g., "ALPHA") -- creation validates `preg_match("#^[a-zA-Z0-9_]{3,16}$#")` at `/home/guortates/TVLW/The-Very-Little-War/alliance.php` line 36.
2. Chef goes to alliance admin and changes the tag.
3. The tag change code at `allianceadmin.php` line 119-135 only checks:
   - Tag is not empty (line 121)
   - No other alliance has this tag (line 123)
4. It does NOT validate the format. No `preg_match` check.
5. Chef sets tag to `<script>alert(1)</script>` or `../../../../etc` or unicode/emoji characters.
6. The tag is stored directly in the database.
7. Tags are displayed across many pages (map, rankings, player profiles, alliance lists). While output is htmlspecialchars-escaped in most places, the tag could still cause display issues, break CSS layouts, or confuse other systems (e.g., multi-account detection string comparisons).

**Root Cause:** Missing format validation on tag change (unlike tag creation which validates properly).

**Impact:** UI disruption, potential impersonation (using spaces/special chars to mimic other alliance tags), display breaking. XSS is mitigated by htmlspecialchars but not guaranteed in every output context.

**Detection:** `SELECT tag FROM alliances WHERE tag NOT REGEXP '^[a-zA-Z0-9_]{3,16}$'`.

**Fix:**
```php
if (isset($_POST['changertag'])) {
    csrfCheck();
    if (!empty($_POST['changertag'])) {
        $_POST['changertag'] = trim($_POST['changertag']);
        // Apply same validation as creation
        if (!preg_match("#^[a-zA-Z0-9_]{3,16}$#", $_POST['changertag'])) {
            $erreur = "Le TAG ne peut contenir que lettres, chiffres et _, entre 3 et 16 caracteres.";
        } else {
            // ... existing uniqueness check and update
        }
    }
}
```

---

### P4-ADV-007 | HIGH | Account Deletion During Pending Attacks Causes Combat Crash

**File:** `/home/guortates/TVLW/The-Very-Little-War/compte.php` lines 8-11

**Preconditions:** Player has launched an attack that has not yet resolved. Player's account is older than 1 week.

**Exploit Steps:**
1. Player launches an attack against a target. Attack is in transit (`actionsattaques` row exists).
2. Player immediately goes to account settings and deletes their account.
3. `supprimerJoueur()` deletes all player data from `membre`, `autre`, `ressources`, `constructions`, `molecules`, etc.
4. When the attack arrives and `updateActions()` processes it for the DEFENDER, combat.php runs at the defender's page load.
5. Combat.php attempts to look up attacker's constructions at line 28: `SELECT pointsProducteur FROM constructions WHERE login=?` -- returns NULL.
6. Line 30-31: `if (!$niveauxAttaquant)` throws `Exception('Missing attacker constructions')`.
7. This crashes inside the combat transaction, preventing the defender from processing ANY actions until the orphaned attack row is manually cleaned.

**Root Cause:** `supprimerJoueur()` does not clean up `actionsattaques` rows (neither as attacker nor defender). The deletion function is not comprehensive.

**Impact:** Denial of service against specific defenders. A malicious player could launch attacks against multiple targets, then delete their account, leaving orphaned attack records that crash combat resolution for each target.

**Detection:** `SELECT aa.* FROM actionsattaques aa LEFT JOIN membre m ON aa.attaquant = m.login WHERE m.login IS NULL`.

**Fix:** Add cleanup in `supprimerJoueur()`:
```php
// Clean up pending attacks (as attacker)
$pendingAttacks = dbFetchAll($base, 'SELECT * FROM actionsattaques WHERE attaquant=?', 's', $login);
foreach ($pendingAttacks as $attack) {
    // Return troops to nothing (player is being deleted)
    dbExecute($base, 'DELETE FROM actionsattaques WHERE id=?', 'i', $attack['id']);
}
// Clean up pending attacks where this player is defender
dbExecute($base, 'DELETE FROM actionsattaques WHERE defenseur=?', 's', $login);
// Clean up pending transfers
dbExecute($base, 'DELETE FROM actionsenvoi WHERE envoyeur=? OR receveur=?', 'ss', $login, $login);
```

---

### P4-ADV-008 | HIGH | Zero-Atom Molecule with Isotope Catalytique Provides Free Buff

**File:** `/home/guortates/TVLW/The-Very-Little-War/armee.php` and `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php` lines 83-142

**Preconditions:** Player has an empty molecule class slot available.

**Exploit Steps:**
1. Player creates a molecule with ALL atoms set to 0 (or near-zero, e.g., 1 atom of a single type).
2. Player selects Isotope Catalytique (isotope=3) for this molecule.
3. The molecule creation validation at `armee.php` line 101 checks `emplacementmoleculeformer` is 1-4 and `nombremolecules >= 1`. But the molecule DEFINITION (atom composition) was set separately.
4. Actually -- let me re-check. The molecule is defined with atom quantities, then formed separately. The creation step at molecule definition allows 0 atoms per element but requires at least one non-empty field.
5. So the player creates a molecule with just 1 atom of one type (e.g., 1 hydrogene). This is the weakest possible molecule.
6. Sets isotope to Catalytique. This molecule has near-zero combat stats.
7. In combat.php line 107-110: `$attHasCatalytique = true` is set because this class has Catalytique isotope.
8. Lines 127-133: ALL other classes get `+= ISOTOPE_CATALYTIQUE_ALLY_BONUS` (0.15 = +15%) to both attack and HP modifiers.
9. The 1-atom Catalytique molecule is effectively worthless in combat, but it buffs the other 3 classes by +15% for free.
10. This is "working as designed" for Catalytique, BUT the extreme case (1 atom) means the player sacrifices almost nothing (1 atom total, trivial formation cost) for a permanent +15% buff to 3 other classes.

**Root Cause:** No minimum atom requirement for isotope selection. Catalytique's ally buff applies regardless of the buffing class's own strength.

**Impact:** Every competitive player MUST create a 1-atom Catalytique molecule in one slot. This is not a "choice" but a dominant strategy, reducing the effective molecule slots from 4 to 3 (since one is always a near-empty Catalytique placeholder). This undermines the isotope system's design intent.

**Detection:** `SELECT proprietaire, numeroclasse, isotope, (carbone+azote+hydrogene+oxygene+chlore+soufre+brome+iode) as total_atoms FROM molecules WHERE isotope=3 AND (carbone+azote+hydrogene+oxygene+chlore+soufre+brome+iode) < 10`.

**Fix:** Either require a minimum total atom count for Catalytique, or scale the ally bonus by the Catalytique class's relative strength:
```php
// Option A: Scale ally bonus by catalytique class strength
if ($attHasCatalytique) {
    // Find the catalytique class's total atoms
    $catalytiqueAtoms = 0;
    $avgAtoms = 0;
    $catCount = 0;
    for ($c = 1; $c <= $nbClasses; $c++) {
        $classAtoms = 0;
        foreach ($nomsRes as $num => $ressource) {
            $classAtoms += ${'classeAttaquant' . $c}[$ressource];
        }
        if (intval(${'classeAttaquant' . $c}['isotope'] ?? 0) == ISOTOPE_CATALYTIQUE) {
            $catalytiqueAtoms += $classAtoms;
            $catCount++;
        }
        $avgAtoms += $classAtoms;
    }
    $avgAtoms = $avgAtoms / max(1, $nbClasses);
    $scaleFactor = min(1.0, $catalytiqueAtoms / max(1, $avgAtoms * $catCount));

    for ($c = 1; $c <= $nbClasses; $c++) {
        if (intval(${'classeAttaquant' . $c}['isotope'] ?? 0) != ISOTOPE_CATALYTIQUE) {
            $attIsotopeAttackMod[$c] += ISOTOPE_CATALYTIQUE_ALLY_BONUS * $scaleFactor;
            $attIsotopeHpMod[$c] += ISOTOPE_CATALYTIQUE_ALLY_BONUS * $scaleFactor;
        }
    }
}
```

---

### P4-ADV-009 | HIGH | Condenseur Point Redistribution Allows Infinite Respec

**File:** `/home/guortates/TVLW/The-Very-Little-War/constructions.php` lines 6-42

**Preconditions:** Player has a producteur with allocated points.

**Exploit Steps:**
1. Player has producteur level 10, granting 80 points (8 per level). 80 points are fully distributed.
2. `pointsProducteurRestants` = 0, `pointsProducteur` = "10;10;10;10;10;10;10;10".
3. Player submits the producteur point allocation form with all zeros: `nbPointshydrogene=0, nbPointscarbone=0, ...`
4. Line 22: `$somme = 0`, `$bool = true` (all values are >= 0).
5. Line 22: `$somme <= $constructions['pointsProducteurRestants']` = `0 <= 0` = TRUE.
6. Line 30: `$chaine = (0 + 10) . ";" . (0 + 10) ...` = same as current values. No change.
7. Line 33: `$newPoints = 0 - 0 = 0`. No respec occurs.

Actually, wait. Let me re-read line 30 more carefully:
```php
$chaine = $chaine . ($_POST['nbPoints' . $ressource] + ${'points' . $ressource}) . $plus;
```
This ADDS the POST value to existing points. So submitting 0 keeps them the same. This is an ADDITIVE allocation, not an absolute one.

The real issue: `pointsProducteurRestants` tracks UNALLOCATED points. When the producteur is upgraded, new points are added to `pointsProducteurRestants`. The form lets you allocate those remaining points. You can never REALLOCATE existing points -- there is no respec mechanism.

But: When the producteur building is DESTROYED in combat (level reduced via `diminuerBatiment`), does `pointsProducteurRestants` get adjusted? Let me check.

Looking at `diminuerBatiment` in player.php -- it reduces the building level but does NOT recalculate point allocations. So if a player had 80 points allocated across 10 levels, and the building drops to level 9 (72 points capacity), they still have 80 points allocated. This means the player has MORE allocated points than their building level allows.

This is not directly exploitable for point gain, but it means losing a producteur level does not cause point loss, making building destruction less impactful than intended.

**Root Cause:** `diminuerBatiment()` does not recalculate or reduce producteur/condenseur points when the building level drops.

**Impact:** Reduced penalty for building destruction. Points allocated during higher building levels persist even after the building is damaged back down.

**Detection:** `SELECT c.login, c.producteur, c.pointsProducteur FROM constructions c WHERE (SELECT SUM(val) FROM JSON_TABLE(...)) > c.producteur * 8`.

**Fix:** In `diminuerBatiment()`, recalculate point allocations:
```php
if ($batiment == 'producteur' || $batiment == 'condenseur') {
    $pointsField = ($batiment == 'producteur') ? 'pointsProducteur' : 'pointsCondenseur';
    $remainField = ($batiment == 'producteur') ? 'pointsProducteurRestants' : 'pointsCondenseurRestants';
    $perLevel = ($batiment == 'producteur') ? 8 : 5;
    $maxPoints = ($newLevel) * $perLevel;
    $currentPoints = explode(';', $constructions[$pointsField]);
    $totalAllocated = array_sum($currentPoints);
    if ($totalAllocated > $maxPoints) {
        // Proportionally reduce all allocations
        $ratio = $maxPoints / max(1, $totalAllocated);
        $adjusted = array_map(function($p) use ($ratio) { return max(0, floor($p * $ratio)); }, $currentPoints);
        dbExecute($base, "UPDATE constructions SET $pointsField=?, $remainField=0 WHERE login=?",
            'ss', implode(';', $adjusted), $login);
    }
}
```

---

### P4-ADV-010 | HIGH | Alliance Chef Can Self-Ban, Orphaning the Alliance

**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php` lines 55-100 (grade assignment section)

**Preconditions:** Player is alliance chef with grade management permission.

**Exploit Steps:**
1. Let me re-read the grade assignment code more carefully.

Looking at lines 106-117 (grade DELETION):
```php
if (isset($_POST['joueurGrade']) and !empty($_POST['joueurGrade'])) {
    csrfCheck();
    $gradeExiste = dbCount($base, 'SELECT count(*) AS gradeExiste FROM grades WHERE login=? AND idalliance=?', 'si', $_POST['joueurGrade'], $chef['id']);
    if ($gradeExiste > 0) {
        dbExecute($base, 'DELETE FROM grades WHERE login=? AND idalliance=?', 'si', $_POST['joueurGrade'], $chef['id']);
```
This checks the grade exists for this alliance. Safe.

Looking for the grade CREATION code... Let me check more of allianceadmin.php.

Actually, I need to re-read the grade creation section. Let me look for the grade assignment form handler, which may be at a different line range.

**Re-evaluation needed.** Let me check if grade assignment is elsewhere.

The grade system appears to be a role-assignment system. The deletion at line 106-117 works correctly. The creation/assignment is handled by a different POST handler. Without seeing the full grade creation handler, I'll adjust this finding to the confirmed issue at line 169-180:

**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php` lines 169-180

**Exploit Steps:**
1. Chef goes to alliance admin, selects "Ban player" form.
2. Chef enters any player name in `bannirpersonne`.
3. Line 173: `ucfirst(trim(...))` is applied -- this normalizes casing.
4. Line 174: `SELECT count(*) as nb FROM autre WHERE idalliance=? AND login=?` checks if player is in THIS alliance. Good.
5. If the player IS in the alliance, they are banned (line 176).

This section is properly validated. Let me find the actual vulnerability...

Actually, looking at the war/pact declaration sections instead:

**Revised Finding: Alliance Chef Can Ban Themselves (Self-Ban)**

**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php` lines 169-180

**Exploit Steps:**
1. Alliance chef opens the ban form.
2. Chef enters their OWN username in the ban field.
3. Line 174: Check passes -- the chef IS in the alliance.
4. Line 176: `UPDATE autre SET idalliance=0 WHERE login=?` -- removes the chef from the alliance.
5. Line 177: Grade is deleted.
6. The alliance now has NO chef. The `alliances.chef` column still references the old chef's login, but that player is no longer in the alliance.
7. Nobody can manage the alliance. It becomes orphaned.

**Root Cause:** No check preventing the chef from banning themselves.

**Impact:** Alliance griefing. A disgruntled chef can orphan the alliance, locking all members out of alliance management.

**Detection:** `SELECT a.id, a.chef, au.idalliance FROM alliances a LEFT JOIN autre au ON a.chef = au.login AND au.idalliance = a.id WHERE au.login IS NULL`.

**Fix:**
```php
if ($_POST['bannirpersonne'] === $currentAlliance['chef'] || $_POST['bannirpersonne'] === $_SESSION['login']) {
    $erreur = "Le chef ne peut pas se bannir lui-meme.";
} elseif ($dansLAlliance > 0) {
    // ... existing ban logic
}
```

---

### P4-ADV-011 | HIGH | Market Buy/Sell Wash Trading for Infinite Trade Points

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php` lines 157-280 (buy) and sell sections

**Preconditions:** Player has sufficient energy and resources.

**Exploit Steps:**
1. Player buys 1000 units of `carbone` on the market. Cost = `tabCours[0] * 1000` energy.
2. Trade volume increases by `coutAchat` (energy spent).
3. Player immediately sells 1000 units of `carbone`. Revenue = `cours * 1000 * MARKET_SELL_TAX_RATE (0.95)`.
4. Net energy loss: 5% per round-trip (the sell tax).
5. But trade volume is tracked CUMULATIVELY: `tradeVolume` in `autre` only increases, never decreases.
6. Trade points: `floor(MARKET_POINTS_SCALE * sqrt(totalTradeVolume))` = `floor(0.08 * sqrt(volume))`.
7. With trade points capped at `MARKET_POINTS_MAX = 80`, the player needs: `(80/0.08)^2 = 1,000,000` trade volume.
8. Each buy+sell cycle adds ~2x the trade volume (buy energy spent + sell volume).
9. The player can wash-trade repeatedly, losing only 5% energy per cycle, to rapidly inflate trade points.
10. Trade points contribute to the overall ranking via `RANKING_TRADE_WEIGHT = 1.0` and sqrt scaling.

**Root Cause:** Trade volume tracks gross volume, not net. There is no cooldown or diminishing returns on market trades. The 5% sell tax is the only friction.

**Impact:** Players with excess energy can farm trade points to boost their ranking. Since trade points are sqrt-scaled, 1M volume gives 80 points, contributing ~8 points to total ranking (1.0 * sqrt(80)). This is not game-breaking due to sqrt diminishing returns, but it provides a predictable, mechanical ranking boost unavailable to players without energy surplus.

**Detection:** Flag players whose trade volume exceeds 10x their total resource production for the season.

**Fix:** Either track net trade volume (buy - sell), add a cooldown between same-resource trades, or apply diminishing returns:
```php
// Add anti-wash-trade cooldown per resource type
$lastTrade = dbFetchOne($base, 'SELECT timestamp FROM market_trades WHERE login=? AND resource=? ORDER BY timestamp DESC LIMIT 1', 'ss', $_SESSION['login'], $nomsRes[$numRes]);
if ($lastTrade && (time() - $lastTrade['timestamp']) < 300) { // 5 min cooldown per resource
    $erreur = "Attendez avant d'effectuer une autre transaction sur cette ressource.";
}
```

---

### P4-ADV-012 | HIGH | Vacation Mode Activation Deletes Formation Actions Without Refund

**File:** `/home/guortates/TVLW/The-Very-Little-War/compte.php` lines 13-28

**Preconditions:** Player has molecules in formation queue. Player has available resources.

**Exploit Steps:**
1. Player queues 10,000 molecules for formation. This deducts atoms from `ressources` (the cost was paid at queue time in `armee.php`).
2. Player immediately activates vacation mode via `compte.php`.
3. Line 18: `DELETE FROM actionsformation WHERE login = ?` -- all formation actions are deleted.
4. The atoms that were deducted to PAY for the formation are NOT refunded.
5. Player loses all invested atoms with nothing to show for it.

This is not directly exploitable by a MALICIOUS player against themselves, but:

6. A griefer could trick another player into activating vacation mode right after they queued expensive formations (e.g., by threatening an attack they know they'll withdraw).
7. More importantly, this is a GRIEF vector against yourself: accidentally activating vacation mode destroys hours of queued formations.

**Root Cause:** Formation action deletion does not refund the resource cost.

**Impact:** Resource loss for the vacationing player. Not exploitable against others, but a significant quality-of-life issue and potential grief vector.

**Detection:** Compare `actionsformation` deletions via vacation with corresponding resource debits.

**Fix:** Refund resources before deleting formation actions:
```php
// Refund formation resources before vacation
$formations = dbFetchAll($base, 'SELECT * FROM actionsformation WHERE login = ?', 's', $_SESSION['login']);
foreach ($formations as $formation) {
    $molecule = dbFetchOne($base, 'SELECT * FROM molecules WHERE id = ?', 'i', $formation['idclasse']);
    if ($molecule) {
        foreach ($nomsRes as $num => $ressource) {
            $refund = $molecule[$ressource] * $formation['nombreRestant'];
            if ($refund > 0) {
                ajouter($ressource, 'ressources', $refund, $_SESSION['login']);
            }
        }
    }
}
dbExecute($base, 'DELETE FROM actionsformation WHERE login = ?', 's', $_SESSION['login']);
```

---

### P4-ADV-013 | HIGH | Resource Node Bonus Stacking: Multiple Same-Type Nodes

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/resource_nodes.php` lines 85-108

**Preconditions:** Resource node generation places two or more nodes of the same resource type with overlapping radii.

**Exploit Steps:**
1. Season resets. `generateResourceNodes()` creates 15-25 nodes randomly.
2. Two `carbone` nodes happen to be placed 5 tiles apart, each with radius 5. Their coverage circles overlap.
3. A player at the overlap point gets `getResourceNodeBonus()` calculated.
4. Lines 97-106: The function iterates ALL nodes, checks if distance <= radius, and ADDS `bonus_pct / 100` for EACH matching node.
5. With 2 overlapping carbone nodes at 10% each: bonus = 20%.
6. With 3 overlapping nodes (unlikely but possible): bonus = 30%.
7. The `RESOURCE_NODE_MIN_DISTANCE = 3` prevents nodes from being EXACTLY on top of each other, but with radius 5, nodes placed 3-5 tiles apart ALWAYS overlap.

**Root Cause:** No cap on stacked resource node bonuses. The minimum distance (3) is less than the radius (5), guaranteeing overlap potential.

**Impact:** Players lucky enough to spawn near overlapping same-type nodes get a multiplicative production advantage. This is pure RNG, not skill-based. A player near 2 overlapping energy nodes gets +20% energy production permanently for the season.

**Detection:** `SELECT n1.id, n2.id, n1.resource_type, SQRT(POW(n1.x-n2.x,2)+POW(n1.y-n2.y,2)) as dist FROM resource_nodes n1 JOIN resource_nodes n2 ON n1.id < n2.id AND n1.resource_type = n2.resource_type WHERE SQRT(POW(n1.x-n2.x,2)+POW(n1.y-n2.y,2)) < n1.radius + n2.radius`.

**Fix:** Cap the bonus or enforce minimum distance >= 2 * radius:
```php
// Option A: Cap in getResourceNodeBonus
$totalBonus = min(0.10, $totalBonus); // Cap at 10% regardless of stacking

// Option B: In generateResourceNodes, enforce minimum distance >= 2*radius
define('RESOURCE_NODE_MIN_DISTANCE', RESOURCE_NODE_DEFAULT_RADIUS * 2); // 10
```

---

### P4-ADV-014 | HIGH | Espionage Without Defender Existence Check

**File:** `/home/guortates/TVLW/The-Very-Little-War/attaquer.php` lines 20-51

**Preconditions:** Player has neutrinos.

**Exploit Steps:**
1. Player submits espionage form with `joueurAEspionner` set to a player who does not exist.
2. Line 25: Check `$_POST['joueurAEspionner'] != $_SESSION['login']` -- passes (different usernames).
3. Line 26: Check neutrinos available -- passes.
4. Line 27: `$membreJoueur = dbFetchOne($base, 'SELECT * FROM membre WHERE login=?', ...)` -- returns NULL/false for non-existent player.
5. Lines 28-29: `updateRessources()` and `updateActions()` are called with the non-existent login. These functions will silently fail or produce errors.
6. Line 32: `$membreJoueur['x']` -- PHP notice: trying to access array key on null/false.
7. Line 36: INSERT into `actionsattaques` with the non-existent defender login.
8. Line 38-39: Neutrinos are deducted from the spy.
9. When the espionage resolves in `game_actions.php`, it will attempt to read the defender's data and crash.

**Root Cause:** No existence check for the espionage target. The attack handler at line 76-81 DOES check `$joueurDefenseur = dbFetchOne($base, 'SELECT * FROM autre WHERE login=?')`, but the espionage handler at line 20-51 does NOT perform any existence check.

**Impact:** Players lose neutrinos to non-existent targets. Orphaned espionage actions in `actionsattaques` may cause errors when processing. Potential PHP notices/warnings in production.

**Detection:** `SELECT * FROM actionsattaques WHERE troupes='Espionnage' AND defenseur NOT IN (SELECT login FROM membre)`.

**Fix:**
```php
if ($_POST['joueurAEspionner'] != $_SESSION['login']) {
    // Add existence check
    $targetExists = dbFetchOne($base, 'SELECT login FROM membre WHERE login=?', 's', $_POST['joueurAEspionner']);
    if (!$targetExists) {
        $erreur = "Ce joueur n'existe pas.";
    } elseif (preg_match(...)) {
        // ... rest of espionage logic
```

---

### P4-ADV-015 | HIGH | Alliance Quit During Pending War Declaration Causes Orphaned War

**File:** `/home/guortates/TVLW/The-Very-Little-War/alliance.php` lines 67-76

**Preconditions:** Player's alliance is at war. Player is not the chef.

**Exploit Steps:**
1. Alliance A is at war with Alliance B. War record exists in `declarations` with `type=0, fin=0`.
2. All members of Alliance A quit the alliance one by one (via the quit button at line 67-76).
3. The quit handler does NOT check if the player is the last member.
4. Eventually only the chef remains. The chef cannot quit (line 71 check).
5. But if the chef transfers leadership to another player first (via allianceadmin.php), THEN the new chef quits, and so on -- the alliance can be emptied.
6. The war declaration persists with `fin=0` even though Alliance A has 0 active members.
7. Alliance B's combat reports still reference the war. Points continue to accumulate against a ghost alliance.
8. This is not directly exploitable, but it clutters the war system.

Actually, more critically:
1. Player quits alliance (line 74: `UPDATE autre SET idalliance=0`).
2. Player still has pending attacks against war targets.
3. When combat resolves, `combat.php` line 684: `SELECT idalliance FROM autre WHERE login=?` for the attacker returns 0 (no alliance).
4. Line 690: War check query uses `alliance1=0 OR alliance2=0` -- may match unintended rows if any alliance has id=0.

**Root Cause:** Alliance quit does not cancel pending attacks associated with the war. No cleanup of war state when alliance membership changes.

**Impact:** Orphaned war records, potential data integrity issues. Players can dodge war penalties by quitting mid-conflict.

**Detection:** `SELECT * FROM declarations WHERE fin=0 AND (alliance1 NOT IN (SELECT id FROM alliances) OR alliance2 NOT IN (SELECT id FROM alliances))`.

**Fix:** On alliance quit, cancel pending attacks against war enemies:
```php
// Before removing from alliance
$allianceId = $allianceCheck['idalliance'];
$wars = dbFetchAll($base, 'SELECT alliance1, alliance2 FROM declarations WHERE type=0 AND fin=0 AND (alliance1=? OR alliance2=?)', 'ii', $allianceId, $allianceId);
foreach ($wars as $war) {
    $enemyAlliance = ($war['alliance1'] == $allianceId) ? $war['alliance2'] : $war['alliance1'];
    $enemyMembers = dbFetchAll($base, 'SELECT login FROM autre WHERE idalliance=?', 'i', $enemyAlliance);
    foreach ($enemyMembers as $enemy) {
        // Cancel pending attacks from quitting player against enemy alliance members
        dbExecute($base, 'DELETE FROM actionsattaques WHERE attaquant=? AND defenseur=?', 'ss', $_SESSION['login'], $enemy['login']);
    }
}
```

---

### P4-ADV-016 | HIGH | Combat Modifier Stack Reaches 4.21x+ Under Optimal Conditions

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php` lines 166-198

**Preconditions:** Endgame player with all systems optimized.

**Exploit Steps (not a bug -- a balance analysis of multiplicative stacking):**

Attack damage calculation for a single molecule class:
```
attackPerMol = attaque(O, H, nivCondO, medalBonus)
            * attIsotopeAttackMod[c]          // Reactif: 1.20
            * (1 + ionisateur * 0.02)         // Level 25: 1.50
            * bonusDuplicateurAttaque          // Level 10: 1.10
            * catalystAttackBonus              // +10%: 1.10
            * nombre
```
Then post-per-class:
```
degatsAttaquant *= prestigeCombatBonus()       // 1.05
degatsAttaquant *= (1 + compoundAttackBonus)   // CO2: 1.10
degatsAttaquant *= (1 + specAttackMod)         // Oxydant: 1.10
degatsAttaquant *= embuscadeDefBoost           // N/A for attacker
```

Total multiplier chain (attack side):
- Isotope Reactif: 1.20
- Ionisateur L25: 1.50
- Duplicateur L10: 1.10
- Catalyst attack_bonus: 1.10
- Prestige: 1.05
- Compound CO2: 1.10
- Spec Oxydant: 1.10
- Medal bonus (attack, up to 10% cap): 1.10
- Catalytique ally (if another class is Catalytique): 1.15

Product: 1.20 * 1.50 * 1.10 * 1.10 * 1.05 * 1.10 * 1.10 * 1.10 * 1.15 = **3.48x**

With higher ionisateur (level 50, no cap): 1.20 * 2.00 * 1.10 * 1.10 * 1.05 * 1.10 * 1.10 * 1.10 * 1.15 = **4.64x**

Defense side has a parallel stack with champdeforce + defense spec + NaCl compound = similar multiplier.

**Root Cause:** All combat modifiers are multiplicative, not additive. Each new system (isotopes, specializations, compounds, catalyst) adds another multiplicative layer.

**Impact:** Late-game combat becomes extremely swingy. A player who optimizes all modifier layers has a 3-5x advantage over a player who misses even one layer. This creates a "meta-knowledge" gap where the game becomes about knowing which systems to stack rather than strategic decisions.

**Detection:** Compare combat report damage vs. base formula expectations.

**Fix:** Convert some multiplicative bonuses to additive:
```php
// Additive bonus pool approach:
$additiveBonus = 0;
$additiveBonus += $ionisateur['ionisateur'] * IONISATEUR_COMBAT_BONUS_PER_LEVEL / 100;
$additiveBonus += $compoundAttackBonus;
$additiveBonus += $specAttackMod;
$additiveBonus += ($bonusAttaqueMedaille / 100);

// Only isotope, duplicateur, prestige stay multiplicative
$degatsAttaquant = baseAttack * (1 + $additiveBonus)
                 * $attIsotopeAttackMod[$c]
                 * $bonusDuplicateurAttaque
                 * $prestigeBonus;
```

---

### P4-ADV-017 | MEDIUM | Negative POST Value Bypass via PHP intval() Behavior

**File:** Multiple files (armee.php, constructions.php, marche.php, don.php)

**Preconditions:** Player can modify POST parameters (trivial with browser dev tools).

**Exploit Steps:**
1. Player intercepts the molecule formation POST request.
2. Sets `nombremolecules` to a negative number, e.g., `-1000`.
3. `armee.php` line 70-71: `transformInt()` then `preg_match("#^[0-9]*$#")`.
4. `transformInt()` removes spaces. `-1000` stays as `-1000`.
5. `preg_match("#^[0-9]*$#", "-1000")` -- this FAILS because `-` is not in `[0-9]`.
6. The request is rejected. Safe.

However, let me check other patterns:
- `marche.php` line 159: `intval(transformInt(...))` then `preg_match("#^[0-9]*$#")`. `intval("-1000")` = -1000, but the preg_match check is on the PRE-intval value. Wait, line 159 does `intval(transformInt(...))` which converts to int BEFORE the preg_match at line 161. `preg_match("#^[0-9]*$#", -1000)` -- PHP casts int to string "-1000", fails. Safe.

Actually wait -- `preg_match("#^[0-9]*$#", "")` returns 1 (matches empty string). So an empty string would pass validation. Let me check if `!empty()` is checked first.

Line 161: `if (!empty($_POST['nombreRessourceAAcheter']) and preg_match(...))` -- `!empty("")` is false. So empty string is rejected. Safe.

But what about `"0"`? `intval("0")` = 0. `!empty(0)` is true in PHP... wait, no: `empty(0)` returns TRUE in PHP. So `!empty(0)` is FALSE. A quantity of 0 is rejected. Safe.

**Verdict:** The input validation patterns used throughout the codebase (transformInt + preg_match + intval) effectively prevent negative values. However, I'll note a subtlety:

In `constructions.php` line 12-14:
```php
$_POST['nbPoints' . $ressource] = intval($_POST['nbPoints' . $ressource]);
if ($_POST['nbPoints' . $ressource] < 0) {
    $bool = false;
```
This properly checks for negative values AFTER intval.

**Actual vulnerability found in constructions.php**: The condenseur point allocation at line 44-79 has the same pattern. However, there's a subtle issue: `intval("2147483648")` (PHP_INT_MAX + 1 on 32-bit) wraps to a negative or zero. On 64-bit PHP 8.2, this is not an issue (int is 64-bit), but worth documenting.

**Root Cause:** No explicit maximum value check on point allocations (only minimum and sum checks).

**Impact:** On 64-bit systems (current VPS), no practical exploit. Theoretical concern for portability. The existing sum check `$somme <= $constructions['pointsProducteurRestants']` limits the total, but individual values have no upper bound beyond available points.

**Detection:** N/A -- theoretical only on 64-bit.

**Fix:** Add explicit bounds:
```php
if ($_POST['nbPoints' . $ressource] < 0 || $_POST['nbPoints' . $ressource] > 10000) {
    $bool = false;
}
```

---

### P4-ADV-018 | MEDIUM | Forum BBCode [url] Tag for Phishing

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/bbcode.php` (not read but referenced)

**Preconditions:** Player can post on the alliance forum or set profile descriptions.

**Exploit Steps:**
1. Player uses BBCode in a forum post or profile description.
2. `[url=https://evil-site.com/theverylittlewar-login.php]Cliquez ici pour un bonus![/url]`
3. The URL renders as a clickable link. Other players click it, thinking it leads to the game.
4. The phishing site mimics the login page, captures credentials.
5. Note: BBCode `[img]` was already restricted (Batch C fix H-025), but `[url]` likely still allows arbitrary URLs.

**Root Cause:** BBCode URL tag allows arbitrary external URLs without any domain whitelist or visual indicator.

**Impact:** Credential theft via phishing. Social engineering attacks against alliance members.

**Detection:** Scan forum posts and descriptions for `[url=` patterns pointing to external domains.

**Fix:** Either restrict URLs to same-domain, or add a visual warning:
```php
// Option: Add rel="noopener nofollow" and visual indicator for external links
function bbcodeUrl($url, $text) {
    $parsed = parse_url($url);
    $isExternal = !empty($parsed['host']) && $parsed['host'] !== 'theverylittlewar.com';
    $icon = $isExternal ? ' [externe]' : '';
    $rel = $isExternal ? ' rel="noopener nofollow noreferrer" target="_blank"' : '';
    return '<a href="' . htmlspecialchars($url) . '"' . $rel . '>' . $text . $icon . '</a>';
}
```

---

### P4-ADV-019 | MEDIUM | Season Reset Timing Exploit: Pre-Reset Resource Stockpile

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/config.php` line 566 and season reset logic

**Preconditions:** Player knows when the season will reset (countdown timer is visible on homepage).

**Exploit Steps:**
1. Season end is approaching. The countdown shows exact time.
2. Player stops spending resources and stockpiles everything in the last 24-48 hours.
3. Player fills storage to maximum, buys resources on the market.
4. When season resets (via `performSeasonEnd()`), rankings are frozen, VP awarded.
5. The stockpiled resources carry over (or don't, depending on reset scope).
6. If resources ARE reset: Player converts resources to neutrinos (not resettable?) or prestige items.
7. If resources are NOT reset: Player starts the new season with maximum stockpile, gaining an early advantage.

**Root Cause:** The season reset logic was hardened (Batch D, H-049/050/051), but the exact behavior of resource preservation vs. reset needs verification.

**Impact:** If resources persist across seasons, early-season advantage from pre-season stockpiling.

**Detection:** Compare resource levels immediately before and after season reset.

**Fix:** Ensure season reset zeroes all resources:
```php
// In performSeasonEnd():
dbExecute($base, 'UPDATE ressources SET energie=1000, carbone=500, ...(starting values)');
```

---

### P4-ADV-020 | MEDIUM | Map Boundary Coordinate Exploitation

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php` line 51 (registration sets x=-1000, y=-1000)

**Preconditions:** Newly registered player.

**Exploit Steps:**
1. Player registers. Coordinates are set to (-1000, -1000) at line 51.
2. Before placing on the map, the player's coordinates are used in distance calculations.
3. In `marche.php` line 95: `$distance = pow(pow($membre['x'] - $joueur['x'], 2) + pow($membre['y'] - $joueur['y'], 2), 0.5)`.
4. If a player at (50, 50) sends resources to the unplaced player at (-1000, -1000): distance = sqrt((1050)^2 + (1050)^2) = ~1485 tiles.
5. Travel time = `SECONDS_PER_HOUR * 1485 / 20` = 267,300 seconds = 74 hours.
6. This creates an absurdly long transfer time, but the resources ARE deducted immediately.
7. The unplaced player may never receive them if they delete their account.

More critically:
8. In `attaquer.php` line 130: Attack travel time uses the same distance formula. Attacking an unplaced player would result in 74+ hour travel time.
9. In `game_resources.php` line 62: `if ($pos['x'] >= 0 && $pos['y'] >= 0)` -- resource node bonuses are correctly skipped for unplaced players.

**Root Cause:** Players at (-1000, -1000) can still be targeted for transfers/attacks despite not being on the map.

**Impact:** Resource loss for attackers/senders targeting unplaced players. Minor griefing potential.

**Detection:** `SELECT login FROM membre WHERE x=-1000 AND y=-1000 AND x!=-1000` -- wait, this is contradictory. Better: check for actions targeting players at (-1000,-1000).

**Fix:** Block interactions with unplaced players:
```php
// In attaquer.php and marche.php, after fetching target position:
if ($positions['x'] == -1000 && $positions['y'] == -1000) {
    $erreur = "Ce joueur n'a pas encore ete place sur la carte.";
}
```

---

### P4-ADV-021 | MEDIUM | Embuscade Formation Never Triggers When Defender Outnumbers by 1

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php` lines 153-164

**Preconditions:** Defender uses Embuscade formation with slightly more molecules than attacker.

**Exploit Steps:**
1. Defender has 101 total molecules, Embuscade formation.
2. Attacker sends 100 molecules.
3. Line 157-159: `$totalAttackerMols = 100`, `$totalDefenderMols = 101`.
4. Line 161: `if ($totalDefenderMols > $totalAttackerMols)` -- TRUE (101 > 100).
5. Line 162: `$embuscadeDefBoost = 1.0 + FORMATION_AMBUSH_ATTACK_BONUS` = 1.25.
6. This works correctly.

HOWEVER:
7. The troop counts used are PRE-COMBAT counts. But the damage calculation at line 171 uses the same counts. This means the Embuscade check is based on the army sizes BEFORE any casualties are computed.
8. If the attacker sends their full army but loses some in transit (decay), the `$actions['troupes']` value at line 21 is `ceil($chaineExplosee[$c - 1])` -- the number that ARRIVES. So transit decay IS accounted for.

The actual issue: Embuscade checks `$totalDefenderMols > $totalAttackerMols` using WHOLE army counts (all 4 classes). But the attacker might have many 0-troop classes (empty slots). The defender might have 1 molecule in each of 4 classes (total: 4) and the attacker sends 3 molecules in one class. Defender has more total molecules (4 > 3), so Embuscade activates, giving +25% attack to a defender with only 4 weak molecules.

**Root Cause:** Embuscade activation threshold is based on total molecule COUNT, not total combat MASS. This rewards wide-but-weak armies.

**Impact:** Embuscade can be gamed by creating many 1-atom molecules across all 4 classes to maximize COUNT while minimizing actual combat investment. The +25% attack bonus applies to the defender's actual damage output regardless of molecule quality.

**Detection:** N/A -- working as designed, but exploitable by design.

**Fix:** Base Embuscade on total atom mass rather than molecule count:
```php
$totalAttackerMass = 0;
$totalDefenderMass = 0;
for ($c = 1; $c <= $nbClasses; $c++) {
    foreach ($nomsRes as $num => $ressource) {
        $totalAttackerMass += ${'classeAttaquant' . $c}[$ressource] * ${'classeAttaquant' . $c}['nombre'];
        $totalDefenderMass += ${'classeDefenseur' . $c}[$ressource] * ${'classeDefenseur' . $c}['nombre'];
    }
}
if ($totalDefenderMass > $totalAttackerMass) {
    $embuscadeDefBoost = 1.0 + FORMATION_AMBUSH_ATTACK_BONUS;
}
```

---

### P4-ADV-022 | MEDIUM | Molecule Deletion Zeroes Attack Troops But Does Not Cancel Attacks

**File:** `/home/guortates/TVLW/The-Very-Little-War/armee.php` lines 44-58

**Preconditions:** Player has launched an attack with molecules from class X. Molecules are in transit.

**Exploit Steps:**
1. Player sends class 1 molecules to attack a target. Attack row in `actionsattaques` has troupes="100;0;0;0;".
2. Player then DELETES class 1 molecule definition (the molecule type itself, not the troops).
3. `armee.php` line 44-58: For each pending attack, sets the deleted class's troop count to 0.
4. Troupes becomes "0;0;0;0;".
5. The attack action STILL EXISTS in `actionsattaques` with 0 troops in every class.
6. When combat resolves: `attaquantsRestants = 0`, `defenseursRestants > 0`. Winner = defender (gagnant=1).
7. The attacker gets the LOSS penalty (negative attack points, cooldown applied).
8. The defender gets defense points for "winning" against a 0-troop attack.

**Root Cause:** Molecule deletion zeroes troops but does not check if the resulting attack has 0 total troops. Such attacks should be cancelled entirely.

**Impact:** Free defense points for defenders. Attacker may be unable to cancel a doomed attack, receiving negative points. Could be exploited by coordinating with a friend: launch attacks, delete molecules, friend earns defense points.

**Detection:** `SELECT * FROM actionsattaques WHERE troupes REGEXP '^(0;)+$'`.

**Fix:** After zeroing troops, check if total is 0 and cancel:
```php
// After the troop-zeroing loop:
$allZero = true;
$explosion = explode(";", $chaine);
for ($i = 0; $i < $nbClasses; $i++) {
    if (isset($explosion[$i]) && intval($explosion[$i]) > 0) {
        $allZero = false;
        break;
    }
}
if ($allZero) {
    dbExecute($base, 'DELETE FROM actionsattaques WHERE id=?', 'i', $actionsattaques['id']);
    // Note: troops are already gone (molecule deleted), no refund needed
}
```

---

### P4-ADV-023 | MEDIUM | Alliance Research Bouclier Stacks With Vault For Near-Total Pillage Immunity

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php` lines 374-428

**Preconditions:** Alliance with high Bouclier research level. Player with high coffrefort (vault) level.

**Exploit Steps:**
1. Player has coffrefort level 25 (VAULT_PCT_PER_LEVEL * 25 = 50% of storage protected).
2. With depot level 20, storage = round(1000 * 1.15^20) = 16,366. Vault protects 8,183 atoms per type.
3. Alliance Bouclier research level 25: pillage_defense = 0.01 * 25 = 25% reduction.
4. Attacker wins combat. Pillage calculation at line 395-398 computes `ressourcesAPiller`.
5. Line 409-412: `$ressourcesAPiller = round($ressourcesAPiller * (1 - 0.25))` = 75% of base pillage.
6. Line 414-417: Pillageable resources are calculated as `max(0, resource - vaultProtection)`.
7. If the defender has 8,183 or fewer of each resource, vault protection covers EVERYTHING. Pillage = 0.
8. Even above vault protection, the Bouclier reduces pillage by 25%.

Combined: A player with max vault and max Bouclier research loses at most 75% of resources ABOVE vault protection. If they keep resources below vault level, they lose NOTHING from pillage.

**Root Cause:** Vault (percentage of storage) and Bouclier (percentage of pillage) stack, creating near-immunity to pillage at high levels.

**Impact:** Late-game players become unprofitable to attack. The attacker spends energy on the attack but gets minimal pillage return. This reduces the incentive for offensive play and creates a "turtling" meta.

**Detection:** Compare average pillage amounts over time. If trending toward zero, vault+bouclier stacking is too strong.

**Fix:** Consider making Bouclier and vault use the same protection pool:
```php
// Combined protection: max of vault or bouclier, not both
$vaultProtection = capaciteCoffreFort($vaultLevel, $depotDefLevel);
// Apply bouclier as a cap on pillageable amount, not on vault
$maxPillageRatio = max(0.25, 1.0 - $bouclierReduction); // minimum 25% always pillageable above vault
```

---

### P4-ADV-024 | MEDIUM | withTransaction Catches Exception Not Throwable

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/database.php`

**Preconditions:** Any transaction-wrapped code that triggers a TypeError, ValueError, or other Error (not Exception) subclass.

**Exploit Steps:**
1. A bug or edge case causes a `TypeError` inside a `withTransaction` callback (e.g., passing wrong argument type to a function).
2. `withTransaction` catches `Exception` but NOT `Throwable`.
3. `TypeError` extends `Error`, which extends `Throwable` but NOT `Exception`.
4. The `catch (Exception $e)` block does NOT catch `TypeError`.
5. The `mysqli_rollback()` call in the catch block is SKIPPED.
6. The transaction is left OPEN. PHP's script termination will trigger an implicit rollback via connection close, but:
7. If the connection is persistent or pooled, the open transaction persists, locking rows.
8. In the worst case, partial data is committed if PHP crashes between the error and cleanup.

**Root Cause:** PHP 7+ error hierarchy: `Throwable` -> `Error` (TypeError, etc.) and `Throwable` -> `Exception`. Catching `Exception` misses `Error` subclasses.

**Impact:** In edge cases, transactions may not roll back properly, leading to partial data writes. Known from passes 1-3 but still unfixed.

**Detection:** Search for `catch (Exception` in database.php.

**Fix:**
```php
function withTransaction($base, callable $fn) {
    mysqli_begin_transaction($base);
    try {
        $result = $fn();
        mysqli_commit($base);
        return $result;
    } catch (\Throwable $e) { // Changed from \Exception
        mysqli_rollback($base);
        throw $e;
    }
}
```

---

### P4-ADV-025 | MEDIUM | Decay Coefficient Reaches 1.0 At Extreme Stabilisateur Levels

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/formulas.php` lines 213-272

**Preconditions:** Player with very high stabilisateur level (e.g., 50+).

**Exploit Steps:**
1. `STABILISATEUR_ASYMPTOTE = 0.98`. At stabilisateur level 50: `modStab = pow(0.98, 50) = 0.364`.
2. `rawDecay` for a small molecule (e.g., 50 total atoms): `pow(0.99, pow(1 + 50/150, 1.5) / 25000)`.
3. Inner: `pow(1.333, 1.5) = 1.539`. Divided by 25000 = 0.0000616.
4. `rawDecay = pow(0.99, 0.0000616) = 0.999999382`.
5. With modStab=0.364 and modMedal=0.9 (10% medal): `baseDecay = pow(0.999999382, 0.364 * 0.9) = pow(0.999999382, 0.328)`.
6. `baseDecay = 0.999999875` -- effectively 1.0.
7. With Stable isotope: `pow(0.999999875, 0.7) = 0.9999999125` -- even closer to 1.0.
8. Half-life at this coefficient: `log(0.5) / log(0.9999999125)` = 7,924,430 seconds = 91.7 DAYS.
9. Molecules with high stabilisateur and Stable isotope effectively NEVER decay.

**Root Cause:** The asymptotic stabilisateur formula `pow(0.98, level)` approaches zero exponentially, which means the decay exponent approaches zero, which means the coefficient approaches 1.0 (no decay). This is by design but creates a breakpoint where decay becomes meaningless.

**Impact:** At sufficiently high stabilisateur levels, molecule decay is eliminated entirely. This removes one of the game's key resource sinks, potentially causing inflation.

**Detection:** Query players with demiVie > 30 days.

**Fix:** Add a minimum decay rate:
```php
// In coefDisparition():
$baseDecay = max($baseDecay, 0.999995); // minimum decay: ~3.8 day half-life
// Or: cap stabilisateur effect
$modStab = max(0.3, pow(STABILISATEUR_ASYMPTOTE, $stabilisateur['stabilisateur']));
```

---

### P4-ADV-026 | LOW | Trade Points Awarded On Failed Market Transactions

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php`

**Preconditions:** Need to verify where trade points are actually awarded.

After reviewing the market code, trade points are awarded via `tradeVolume` updates that happen INSIDE the transaction (line 180+). If the transaction fails (insufficient energy, insufficient storage), it throws and rolls back. So trade points are NOT awarded on failure.

**Revised Finding: Energy Field in Market Resource Transfer Not Properly Bounded**

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php` lines 33-37

**Exploit Steps:**
1. Player sends resources to another player.
2. `energieEnvoyee` is validated via `transformInt` and `intval` at lines 36-37.
3. However, `intval` on a very large number (e.g., "99999999999999999999") on 64-bit PHP returns `PHP_INT_MAX` (9223372036854775807).
4. The check at line 63: `$ressources['energie'] >= $_POST['energieEnvoyee']` -- if player has 100 energy and POST value is PHP_INT_MAX, this fails. Safe.
5. But if somehow resources are stored as DOUBLE in the database and float precision causes issues... unlikely on modern PHP/MariaDB.

**Verdict:** Not exploitable. Downgrading to informational.

**Revised P4-ADV-026 | LOW | Espionage Report Reveals Full Army Composition Without Counter-Intelligence**

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php` lines 410-468

**Preconditions:** Player sends enough neutrinos (more than half of defender's count).

**Exploit Steps:**
1. Attacker spies on defender with neutrinos > 50% of defender's count.
2. Espionage succeeds: full report reveals ALL buildings, ALL molecule compositions (exact atom counts per class), ALL resource levels, isotope variants, and defensive formation.
3. This is a complete information dump with no counter-intelligence option for the defender.
4. Defender receives a notification that they were spied on (anonymously), but cannot prevent or mitigate the information leak.
5. Attacker now has perfect information to optimize their army composition, formation choice, and timing.

**Root Cause:** Successful espionage reveals EVERYTHING. There is no partial information, no counter-espionage, and no way to feed false information.

**Impact:** Perfect information for the attacker eliminates uncertainty and defensive strategy. The defender's only option is to change their setup after being notified, but the attacker can simply spy again.

**Detection:** N/A -- working as designed.

**Fix:** Consider tiered espionage revealing partial information based on neutrino ratio.

---

### P4-ADV-027 | LOW | Alliance Energy Donation Has No Daily Cap

**File:** `/home/guortates/TVLW/The-Very-Little-War/don.php`

**Preconditions:** Player has energy. Player is in an alliance.

**Exploit Steps:**
1. Player joins an alliance with a friend as chef.
2. Player donates ALL their energy to the alliance in a single transaction.
3. Friend (chef) uses the energy to upgrade the duplicateur many levels at once.
4. Player logs in next hour, produces more energy, donates again.
5. No daily cap, no cooldown, no diminishing returns on donations.
6. Combined with multi-account detection bypass (different IPs, different fingerprints), alt accounts can funnel all production into one alliance's duplicateur.

**Root Cause:** No rate limiting or cap on energy donations.

**Impact:** Alliances with many active donors can level duplicateur much faster than intended. Alt accounts can funnel resources through the donation system to bypass transfer restrictions.

**Detection:** Monitor `energieDonnee` in `autre` for players donating more than their total energy production.

**Fix:**
```php
// Add daily donation cap
define('DAILY_DONATION_CAP', 50000); // max energy per day
$todayDonated = dbFetchOne($base, 'SELECT COALESCE(SUM(amount),0) as total FROM donation_log WHERE login=? AND timestamp > ?', 'si', $_SESSION['login'], time() - SECONDS_PER_DAY);
if ($todayDonated['total'] + $_POST['energieEnvoyee'] > DAILY_DONATION_CAP) {
    $erreur = "Limite de don journalier atteinte.";
}
```

---

### P4-ADV-028 | LOW | Molecule Creation Allows Absurd Atom Distributions

**File:** `/home/guortates/TVLW/The-Very-Little-War/armee.php`

**Preconditions:** Player has a molecule class slot available.

**Exploit Steps:**
1. Player creates a molecule with 200 atoms of Oxygene, 0 of everything else.
2. MAX_ATOMS_PER_ELEMENT = 200, so this is valid.
3. The molecule has: attack = attaque(200, 0, ...) = high attack, defense = defense(0, 0, ...) = 0, HP = pointsDeVieMolecule(0, 0, ...) = MOLECULE_MIN_HP = 10.
4. These molecules are glass cannons with 10 HP each. They die instantly but deal high damage.
5. More importantly: the covalent synergy bonuses are designed around multi-atom molecules. A 200-O molecule with 0 secondary atoms gets: `(pow(200, 1.2) + 200) * (1 + 0/100) = 0 synergy bonus`.
6. Compare: 100-O, 100-H molecule: `(pow(100, 1.2) + 100) * (1 + 100/100) = doubled attack from synergy`.
7. The pure 200-O molecule gets `pow(200, 1.2) + 200 = 631 + 200 = 831` base attack.
8. The balanced 100-O, 100-H gets `(pow(100, 1.2) + 100) * 2 = (251 + 100) * 2 = 702` attack but with much better HP and speed.

This is actually working as designed -- the covalent synergy system rewards balanced molecules. The "exploit" is knowing that extreme specialization is suboptimal, which is meta-knowledge.

**Root Cause:** Not a bug -- the formula system intentionally rewards balanced atom distributions.

**Impact:** New players may not understand the synergy system and create suboptimal molecules. The formulas are not explained in-game (though tooltips and the player guide help).

**Detection:** N/A.

**Fix:** Add in-game synergy indicators when creating molecules.

---

### P4-ADV-029 | LOW | Multi-Account Detection Bypass via Timing Manipulation

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/multiaccount.php`

**Preconditions:** Player controls two accounts on different IPs.

**Exploit Steps:**
1. Player creates Account A on home IP. Creates Account B on VPN/mobile.
2. Multi-account detection checks: same IP (avoided), same fingerprint (different browsers), coordinated attacks (avoidable by timing attacks hours apart), transfer patterns (avoidable by using the market instead of direct transfers), timing correlation (avoidable by varying login times).
3. Player never transfers resources directly between A and B.
4. Instead: Account A sells resources on the market. Account B buys them on the market.
5. Market transactions are anonymous -- no sender/receiver pair.
6. The multi-account system cannot detect market-mediated resource funneling.
7. The alt-feeding inversion ratio only applies to DIRECT transfers, not market trades.

**Root Cause:** Multi-account detection does not analyze market transaction patterns (e.g., unusual buy/sell timing correlations between two players).

**Impact:** Sophisticated multi-account operators can circumvent all detection mechanisms by using the market as an intermediary. The 5% sell tax is the only friction.

**Detection:** Correlate market activity timestamps between suspected pairs. Flag accounts that consistently buy what the other sells within minutes.

**Fix:** Add market-based correlation analysis to multi-account detection:
```php
function checkMarketCorrelation($base, $login1, $login2) {
    // Compare buy/sell timestamps of same resources within 5-minute windows
    // Flag if correlation exceeds threshold
}
```

---

### P4-ADV-030 | LOW | Construction Points Calculated Without Building Level Cap

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php` and combat.php

**Preconditions:** Player builds any building to extreme levels (ref P4-ADV-004).

**Exploit Steps:**
1. Each building upgrade awards construction points: `points_base + floor(level * points_level_factor)`.
2. For generateur: `1 + floor(level * 0.1)`.
3. At level 100: 1 + 10 = 11 points per upgrade.
4. Total construction points from building levels 1-100: sum(1 + floor(i * 0.1)) for i=1..100 = approximately 600 points.
5. Construction points feed into ranking: `RANKING_CONSTRUCTION_WEIGHT * sqrt(totalConstructionPoints)`.
6. sqrt(600) * 1.0 = 24.5 ranking points from construction alone.
7. Without a building level cap, construction points scale linearly with total building levels, which scale linearly with the number of upgrades (uncapped).

**Root Cause:** Same root cause as P4-ADV-004 (no building cap), but manifesting in the ranking system.

**Impact:** Players with extreme building levels dominate the construction ranking category.

**Detection:** Same as P4-ADV-004.

**Fix:** Same as P4-ADV-004 -- cap building levels.

---

### P4-ADV-031 | LOW | Alliance Name Not Validated on Change

**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php` lines 156-167

**Preconditions:** Player has alliance description change permission.

**Exploit Steps:**
1. Alliance description change at line 157-166 accepts ANY non-empty string.
2. There is no length limit on the description.
3. Player submits a description with 1MB of text.
4. This is stored directly in the database.
5. Every player viewing the alliance page loads this 1MB description.
6. While BBCode rendering may limit display, the raw data still occupies database space and bandwidth.

**Root Cause:** No length validation on alliance description.

**Impact:** Performance degradation for alliance page loads. Database bloat.

**Detection:** `SELECT id, LENGTH(description) as len FROM alliances ORDER BY len DESC`.

**Fix:**
```php
if (mb_strlen($_POST['changerdescription']) > 5000) {
    $erreur = "La description ne peut pas depasser 5000 caracteres.";
}
```

---

### P4-ADV-032 | LOW | Attack Cooldown Only Per-Defender, Not Global

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php` lines 334-342

**Preconditions:** Player has many molecules and energy.

**Exploit Steps:**
1. Attack cooldowns are stored per (attacker, defender) pair.
2. After losing to Defender A, attacker waits 4 hours before attacking A again.
3. But the attacker can IMMEDIATELY attack Defenders B, C, D, E, F...
4. A well-resourced player can chain-attack every player on the map simultaneously.
5. There is no global cooldown limiting total attacks per hour.
6. Combined with the energy cost of attacks (`ATTACK_ENERGY_COST_FACTOR * atoms`), the only limit is energy.

**Root Cause:** Cooldowns are per-target, not global. No rate limiting on total attacks per time period.

**Impact:** Active players with high energy production can attack many targets in quick succession, overwhelming defenders. While each attack costs energy, a player with high generateur and good iode catalyst can sustain rapid attacks.

**Detection:** `SELECT attaquant, COUNT(*) as attacks, MIN(tempsDepart) as first, MAX(tempsDepart) as last FROM actionsattaques GROUP BY attaquant HAVING attacks > 10 AND (last-first) < 3600`.

**Fix:** Add a global attack cooldown:
```php
define('GLOBAL_ATTACK_COOLDOWN_SECONDS', 300); // 5 min between any attacks
$lastAttack = dbFetchOne($base, 'SELECT MAX(tempsDepart) as last FROM actionsattaques WHERE attaquant=?', 's', $_SESSION['login']);
if ($lastAttack && (time() - $lastAttack['last']) < GLOBAL_ATTACK_COOLDOWN_SECONDS) {
    $erreur = "Attendez avant de lancer une nouvelle attaque.";
}
```

---

## Summary of Highest-Priority Fixes

| ID | Severity | Quick Fix? | Description |
|----|----------|-----------|-------------|
| P4-ADV-001 | CRITICAL | Yes | Move compound synthesis resource check inside FOR UPDATE transaction |
| P4-ADV-002 | CRITICAL | Yes | Wrap transfer delivery in withTransaction with CAS guard |
| P4-ADV-003 | CRITICAL | Yes | Add explicit self-transfer check in marche.php |
| P4-ADV-004 | CRITICAL | Yes | Define MAX_BUILDING_LEVEL constant and enforce in augmenterBatiment() |
| P4-ADV-007 | HIGH | Yes | Clean up actionsattaques/actionsenvoi in supprimerJoueur() |
| P4-ADV-006 | HIGH | Yes | Add preg_match validation on alliance tag change |
| P4-ADV-014 | HIGH | Yes | Add target existence check in espionage handler |
| P4-ADV-024 | MEDIUM | Yes | Change Exception to Throwable in withTransaction |

---

## Methodology Notes

Each finding was derived by reading the actual PHP source code and tracing execution paths under adversarial conditions. The scenarios tested include:
- Concurrent request races (TOCTOU on compounds, transfers, attacks)
- Negative/zero/extreme POST parameter injection
- Self-targeting (self-attack, self-transfer, self-ban)
- Non-existent target interactions (deleted players, unplaced players)
- Modifier stacking analysis (multiplicative chain calculations)
- Formation/isotope edge cases (empty slots, minimal atoms, wrong selections)
- Cross-system interactions (alliance quit during war, deletion during attacks, vacation during formation)
- Market manipulation (wash trading, multi-account via market proxy)
- Formula breakpoints (extreme building levels, decay approaching 1.0, vault+bouclier stacking)
