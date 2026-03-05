# Pass 2 — Deep Dive: Game Mechanics Audit
**Domain 5 — Full Action Flow Tracing**
Date: 2026-03-05
Auditor: Senior Game Developer Agent

---

## Methodology

Each finding traces the complete user-input → validation → state-change → feedback chain.
Files read line-by-line: armee.php, includes/combat.php, includes/game_actions.php,
includes/player.php, marche.php, alliance.php, laboratoire.php, attaquer.php,
includes/formulas.php, includes/game_resources.php, includes/compounds.php,
includes/db_helpers.php.

Pass 1 found 59 findings. This pass goes deeper on flow tracing, concurrency, edge cases,
and cascade failures.

---

## Findings

---

### P2-D5-001 | HIGH | Attack Action Survives Its Own Zombie State After CAS Guard

- **Location:** includes/game_actions.php:90-94
- **Flow:**
  1. Cron or player visit triggers `updateActions($joueur)`.
  2. Query fetches all `actionsattaques` rows for both attacker and defender.
  3. CAS guard at line 90: `UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0`.
  4. If `$casAffected === 0` (another concurrent request claimed it), code does `continue`.
  5. However, the row is now in state `attaqueFaite=1` and `tempsRetour` is in the future.
  6. On the **next** `updateActions` call, line 484 checks `if ($actions['tempsRetour'] < time() && $joueur == $actions['attaquant'] && $actions['troupes'] != 'Espionnage')`.
  7. But if the combat transaction rolled back (catch at line 357-360), `attaqueFaite` was set to 1 but the combat never completed — troops are permanently frozen mid-flight.
- **Impact:** After a rollback, the attack entry stays with `attaqueFaite=1` forever. Troops sent in the attack are never returned to the player. The attacker permanently loses their entire expeditionary force. The row is never deleted. This is a silent permanent troop loss.
- **Fix:** In the rollback catch block (line 357-359), reset `attaqueFaite` back to 0 so the next request can retry: `dbExecute($base, 'UPDATE actionsattaques SET attaqueFaite=0 WHERE id=?', 'i', $actions['id'])`. Or, implement a separate `attaqueFaite=2` state for "rollback retry needed" and clean it up after N attempts.

---

### P2-D5-002 | HIGH | Formation Queue Rescheduler Ignores Already-Started Actions

- **Location:** armee.php:34-42 (molecule deletion → actionsformation reschedule)
- **Flow:**
  1. Player deletes molecule class via `emplacementmoleculesupprimer`.
  2. Transaction at line 14 fetches all `actionsformation` rows `ORDER BY fin DESC`.
  3. Loop at line 34: if `time() < $actionsformation['debut']`, it reschedules debut/fin. Otherwise, it sets `$nvxDebut = $actionsformation['fin']`.
  4. **Bug:** "Otherwise" branch (line 40) fires when `time() >= $actionsformation['debut']`, meaning the action has ALREADY STARTED (molecules are currently being formed). The code then advances `$nvxDebut` to the end of that action without updating the DB.
  5. The NEXT pending action in the queue may be scheduled to start before the in-progress action finishes.
- **Impact:** Two concurrent formation queues end up overlapping in time. When `updateActions` resolves them both, the same molecules get double-counted (molecules added twice). Players receive free molecules equal to one full batch.
- **Fix:** Before the reschedule loop, separate actions into two groups: `debut < time()` (already started — do not touch) and `debut >= time()` (queued — reschedule). Only iterate over the second group for rescheduling.

---

### P2-D5-003 | HIGH | Combat Resources Read Without FOR UPDATE — Double-Pillage Race

- **Location:** includes/combat.php:362-372
- **Flow:**
  1. `$ressourcesDefenseur = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $actions['defenseur'])` — no `FOR UPDATE`.
  2. `$ressourcesJoueur = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $actions['attaquant'])` — no `FOR UPDATE`.
  3. Combat arithmetic computes pillage and writes new resource values.
  4. If defender or attacker are simultaneously involved in ANOTHER combat resolution (possible since `updateActions` can be triggered by any player visiting any page), both read the same stale resource snapshot.
  5. Both combats then write different final values calculated from the same starting state.
- **Impact:** In a simultaneous two-attack scenario (attacker sends two waves, or two different attackers both arrive at the same tick), the later writer wins entirely. The first winner's pillage is silently overwritten, or the defender's resources are only decremented once instead of twice — giving them a free "duplicate" of their resources after the first hit. Severity depends on how frequently players land simultaneous attacks.
- **Fix:** Add `FOR UPDATE` to both resource reads at combat.php:362 and combat.php:368, and wrap the entire pillage calculation and resource update (lines 362-678) inside the existing transaction. The current code uses `mysqli_begin_transaction` at game_actions.php:109 but the resource reads happen inside that scope — the transaction needs to explicitly lock these rows.

---

### P2-D5-004 | HIGH | Formation tempsPourUn Stored as FLOAT — Accumulated Rounding Error

- **Location:** includes/game_actions.php:63-70, armee.php:139
- **Flow:**
  1. At armee.php:139, `tempsFormation()` returns a float (e.g., 2.47 seconds per molecule).
  2. This float is stored directly as `tempsPourUn` in `actionsformation`.
  3. In `updateActions` at line 63: `$formed = floor((time() - $derniereFormation) / $actions['tempsPourUn'])`.
  4. `$derniereFormation = ($actions['nombreDebut'] - $actions['nombreRestant']) * $actions['tempsPourUn'] + $actions['debut']`.
  5. Because `tempsPourUn` is a float stored in MySQL (likely FLOAT or DECIMAL), tiny rounding errors compound over thousands of molecules.
  6. Example: 10,000 molecules at 2.47s/each = 24,700s total. Float error of 0.001s each = 10 seconds of drift. Players forming large batches may receive 1-4 molecules fewer than expected.
- **Impact:** Players forming large armies get slightly fewer molecules than they paid atoms for. The discrepancy grows with batch size. Not exploitable but causes player frustration.
- **Fix:** Store `tempsPourUn` as an integer (seconds, ceiling-rounded). In `tempsFormation()`, return `ceil($result)` and ensure the DB column is INT. Already partially done with `ceil()` at armee.php:285 for display, but the raw float is stored.

---

### P2-D5-005 | HIGH | Attack Troop Count Not Locked During Molecule Formation Completion

- **Location:** includes/game_actions.php:484-522, armee.php:113-171
- **Flow:**
  1. Player launches attack: molecules deducted from `molecules.nombre`, attack row inserted.
  2. Later, `updateActions` checks for `tempsRetour < time()` to return surviving troops.
  3. Simultaneously, if `actionsformation` for the SAME molecule class completes, line 73-76 does `UPDATE molecules SET nombre = molecule.nombre + nombreRestant WHERE id = ?`.
  4. The formation completion reads `molecule.nombre` (which is now lower because troops are away).
  5. The return trip at line 509 does `UPDATE molecules SET nombre = moleculesProp.nombre + moleculesRestantes WHERE id = ?`.
  6. Both execute at nearly the same time: `nombre` snapshot used in each computation is the same depleted value, then both ADD their increment. Result: molecules are double-counted.
- **Impact:** Players can simultaneously have troops away AND a formation completing, causing the returned troops to be added ON TOP of the formation completion, both computed from the same base. Net effect: player gets `returnedTroops + formationResult` instead of `currentNombre + returnedTroops + formationResult`. This is actually correct if done sequentially, but if the reads happen before either write, both reads see the same stale `nombre` and the final write is: `stale + returned` overlapping with `stale + formed`. The second write wins, and only one of the two increments is applied.
- **Fix:** Both formation completion and troop return must lock `molecules WHERE id=? FOR UPDATE` before reading `nombre`. The return trip already does this in its `withTransaction` at line 500, but only locks the action row, not the molecules row. Formation completion at line 66 uses a bare UPDATE without locking the molecule row for read-then-add.

---

### P2-D5-006 | HIGH | Phalange Formation: Class 1 HP Computed from Wrong niveaux Array

- **Location:** includes/combat.php:228-239
- **Flow:**
  1. Phalange branch computes `$hpPerMol1 = pointsDeVieMolecule($classeDefenseur1['brome'], $classeDefenseur1['carbone'], $niveauxDef['brome'])`.
  2. Correct signature: `pointsDeVieMolecule($Br, $C, $nivCondBr)`.
  3. `$niveauxDef['brome']` is correct (condenseur level for brome).
  4. **However**, for classes 2-4 in the Phalange branch (line 246): `$hpPerMol = pointsDeVieMolecule(${'classeDefenseur' . $i}['brome'], ${'classeDefenseur' . $i}['carbone'], $niveauxDef['brome'])`.
  5. All four classes use the SAME `$niveauxDef['brome']` condenseur level, even though each class may have a different molecule composition with different brome atom counts. The condenseur level should be per-player (which it is — defender's condenseur is shared for all their molecules), so this is actually fine.
  6. **Real bug**: In ATTACKER casualty computation (lines 203-217), `$hpPerMol = pointsDeVieMolecule(${'classeAttaquant' . $i}['brome'], ${'classeAttaquant' . $i}['carbone'], $niveauxAtt['brome'])`. The attacker's HP is computed using the ATTACKER'S brome condenseur level `$niveauxAtt['brome']` — correct. But `$niveauxAtt` is populated from `$niveauxAttaquant = explode(";", $niveauxAttaquant['pointsProducteur'])` at lines 33-36. This is the PRODUCTEUR points string, not the CONDENSEUR. The condenseur points are in `pointsCondenseur`, not `pointsProducteur`.
- **Impact:** Attacker molecule HP in combat is calculated using atom PRODUCTION levels (producteur points) instead of atom CONDENSEUR levels. High-production attackers get artifically inflated HP. High-condenseur defenders also get this error. Both attacker and defender HP calculations are wrong by a factor proportional to producteur vs condenseur investment difference.
- **Fix:** At combat.php:28-46, change `SELECT pointsProducteur FROM constructions` to `SELECT pointsCondenseur FROM constructions` for both attacker and defender niveaux lookups used in HP calculation. The `pointsProducteur` should only be used for production revenue, not combat HP.

---

### P2-D5-007 | HIGH | Market Buy: Price Calculated Before Transaction, Applied Inside

- **Location:** marche.php:171-232
- **Flow:**
  1. Pre-check at line 171-177 uses `$tabCours[$numRes]` fetched at line 10-11 (before any POST handling).
  2. Cost computed: `$coutAchat = round($tabCours[$numRes] * $_POST['nombreRessourceAAcheter'])`.
  3. Inside transaction (line 182-183): re-reads `energie` with FOR UPDATE, checks `$locked['energie'] - $coutAchat < 0`.
  4. **Bug**: `$coutAchat` inside the transaction still uses the STALE price from line 10-11. If another market transaction raised the price between page load and form submission, the inside-transaction check uses the OLD lower price. The actual deduction at line 195 uses `$diffEnergieAchat = $locked['energie'] - $coutAchat` (stale price).
  5. `$tabCours` is only re-read AFTER the transaction (line 237-238), but the transaction uses the pre-computed `$coutAchat`.
- **Impact:** If market price spikes (via someone else buying the same resource simultaneously), a player can buy at the pre-spike price. With high volatility, this could mean buying at 30-50% below market rate. Exploitable with two accounts: account B buys to spike price, account A submits a buy at old price before the page re-renders.
- **Fix:** Inside the transaction, re-fetch `tabCours` with `SELECT tableauCours FROM cours ORDER BY timestamp DESC LIMIT 1 FOR UPDATE` and recompute `$coutAchat` from the fresh price. Validate that the fresh cost matches the user's submitted cost within an acceptable tolerance (e.g., 5%).

---

### P2-D5-008 | HIGH | Resource Transfer Not Atomic — Sender Deduct and Receiver Credit Are Separate

- **Location:** marche.php:108-127
- **Flow:**
  1. INSERT into `actionsenvoi` at line 101 (will be processed later by `updateActions`).
  2. Sender's resources deducted immediately at line 122-127 via a non-parameterized prepared statement (though dynamic SQL uses server-computed values).
  3. The two operations are NOT in a transaction: the INSERT and the UPDATE happen sequentially without `mysqli_begin_transaction`.
  4. If the UPDATE fails (e.g., DB error after the INSERT), the goods have been dispatched but the sender's resources were NOT deducted. Player can send goods for free.
  5. Conversely, if INSERT succeeds but UPDATE fails partially, the sender still has their resources AND a delivery is in transit.
- **Impact:** Race condition or DB error between lines 101 and 122 creates free resource duplication. Unlikely under normal conditions but exploitable if a player can trigger a DB timeout at the right moment.
- **Fix:** Wrap both the INSERT into `actionsenvoi` AND the sender resource deduction in a single `withTransaction()` call.

---

### P2-D5-009 | HIGH | Espionage Neutrino Deduction Happens Outside Transaction

- **Location:** attaquer.php:36-40
- **Flow:**
  1. INSERT into `actionsattaques` at line 36.
  2. `$newNeutrinos = $autre['neutrinos'] - $_POST['nombreneutrinos']` computed from cached session data.
  3. UPDATE `autre SET neutrinos=?` at line 39.
  4. Neither operation is wrapped in a transaction.
  5. Race: two simultaneous espionage submissions can both read the same `$autre['neutrinos']` (from session cache), both compute valid costs, and both INSERT successfully — spending the same neutrinos twice.
- **Impact:** Players can double-spend neutrinos: submit two espionage forms simultaneously (e.g., via two browser tabs), both pass the `<= $autre['neutrinos']` check using stale session data, and both INSERT, sending two spies for the price of one.
- **Fix:** Wrap both operations in a `withTransaction()` with `SELECT neutrinos FROM autre WHERE login=? FOR UPDATE`, validate against the live DB value, then INSERT and UPDATE atomically.

---

### P2-D5-010 | HIGH | Combat Decay Loop Iterates All 4 Classes Regardless of Molecule Definition

- **Location:** includes/game_actions.php:112-131
- **Flow:**
  1. `$moleculesRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $actions['attaquant'])` — fetches all 4 classes including "Vide" ones.
  2. Loop at line 118: `foreach ($moleculesRows as $moleculesProp)`.
  3. For a "Vide" molecule class (formule = "Vide"), all atom counts are 0.
  4. `coefDisparition()` with 0 atoms: `$nbAtomes = 0`, so `pow(DECAY_BASE, pow(1 + 0 / DECAY_ATOM_DIVISOR, DECAY_MASS_EXPONENT) / DECAY_POWER_DIVISOR)` = `pow(DECAY_BASE, 1/DECAY_POWER_DIVISOR)`. This is a valid non-zero decay coefficient < 1.
  5. `$moleculesRestantes = pow(coef, nbsecondes) * $molecules[$compteur-1]`. If `troupes[index]` is 0 (Vide class sent 0 troops), this correctly yields 0. No actual bug for the 0-troop case.
  6. **Real bug**: If the `troupes` string has fewer semicolon-separated values than there are molecule rows (e.g., malformed troupes string from a race condition in armee.php molecule deletion), `$molecules[$compteur-1]` is `null`, and `null * float = 0` in PHP, silently zeroing out that class's troop count without error.
- **Impact:** If `troupes` string is malformed (e.g., molecule deleted mid-flight), surviving troops from that class are silently zeroed — permanent troop loss without any error. The logError at line 121 fires but the update still writes 0 troops.
- **Fix:** After `break` at line 122, continue to mark the entire attack as failed/errored and log which action ID was affected. Optionally set `attaqueFaite=1` and insert an error report to both players.

---

### P2-D5-011 | MEDIUM | Alliance Invitation Acceptance Race: Team Full Check Not Atomic

- **Location:** alliance.php:157-172
- **Flow:**
  1. `$nombreJoueurs = count($joueurRows)` at line 158 counts current members.
  2. `if ($nombreJoueurs < $joueursEquipe)` at line 159.
  3. If check passes, `withTransaction` at line 161 updates `autre SET idalliance`.
  4. **Bug**: The member count check and the transaction are NOT atomic. Two players can both accept invitations simultaneously, both pass the count check (e.g., count = 19, max = 20), and both join, making the alliance 21 members.
- **Impact:** Alliances can exceed the `MAX_ALLIANCE_MEMBERS` limit, creating unfair balance advantages. No hard database constraint prevents this.
- **Fix:** Move the member count check INSIDE the `withTransaction` with a `SELECT COUNT(*) ... FOR UPDATE` on the alliance members, before inserting the new member.

---

### P2-D5-012 | MEDIUM | Alliance Creation: Name/Tag Uniqueness Check Not Atomic

- **Location:** alliance.php:38-54
- **Flow:**
  1. `$nballiance = count(dbFetchAll($base, 'SELECT nom FROM alliances WHERE tag=? OR nom=?', ...))`.
  2. `if ($nballiance == 0)` — then insert.
  3. No transaction wraps check + insert.
  4. Two simultaneous requests with the same tag can both pass the check and both insert, creating duplicate alliances.
- **Impact:** Duplicate alliance names/tags in the database. Viewing the duplicate alliance causes undefined behavior; alliance page uses tag as identifier. Could cause permanent confusion and data corruption.
- **Fix:** Wrap the check and INSERT in `withTransaction`. Add a UNIQUE constraint on `alliances.tag` and `alliances.nom` at the database level (primary defense).

---

### P2-D5-013 | MEDIUM | Building Damage Report Shows Pre-Diminish HP Percentage

- **Location:** includes/combat.php:502-512, 515-525, 527-537, 540-551, 553-565
- **Flow:**
  1. `$destructionGenEnergie` computed: `round($constructions['vieGenerateur'] / pointsDeVie($constructions['generateur']) * 100) . "%" ... max(round(($constructions['vieGenerateur'] - $degatsGenEnergie) / pointsDeVie($constructions['generateur']) * 100), 0) . "%"`.
  2. If damage exceeds HP (`$degatsGenEnergie >= $constructions['vieGenerateur']`), `diminuerBatiment()` is called which reduces the building level AND resets HP to the new level's max HP.
  3. The destruction string is computed BEFORE `diminuerBatiment()` is called, using the old level's `pointsDeVie()`.
  4. After `diminuerBatiment()`, the building is now at level N-1 with full HP. But the report string shows "0% HP remaining" based on the old level.
  5. The next time the player sees their constructions page, HP shows as full (new level). The report and reality contradict.
- **Impact:** Players see "building destroyed to 0%" in the combat report, but then log in to see full HP. Causes confusion and distrust of the combat system. Not a balance issue, but a UX/data integrity issue.
- **Fix:** Compute the destruction display string AFTER calling `diminuerBatiment()`. Alternatively, set the display to "Niveau dégradé de N à N-1" when a level reduction occurs, which is more accurate.

---

### P2-D5-014 | MEDIUM | `diminuerBatiment`: Building HP Set to Full Max, Not Prorated

- **Location:** includes/player.php:599-611 (diminuerBatiment)
- **Flow:**
  1. When building is downleveled: line 602 `$vieVal = vieChampDeForce($batiments[$nom] - 1)` (for champdeforce).
  2. This sets HP to `vieChampDeForce(level - 1)` — the FULL max HP of the new lower level.
  3. However, the building was destroyed because its current HP hit 0. Setting it to full HP at the lower level means the building instantly has full HP after being "destroyed".
- **Impact:** Buildings downleveled by combat immediately have full HP at the new level. An attacker needs to do the equivalent of full HP damage again to downlevel further. While this is potentially intentional game design, it creates a "regeneration" effect that is not clearly communicated and may surprise players. More importantly, an attacker who nearly destroyed a level-3 building to level-2 sees it at 100% HP on their next attack, feeling like no progress was made.
- **Fix:** Consider setting the new level's HP to a low initial value (e.g., 10%) when degraded via combat, representing a damaged building that needs repair. Or document this as intentional in the game rules.

---

### P2-D5-015 | MEDIUM | `updateActions` Calls `updateActions($enFace)` Recursively During Attack Resolution

- **Location:** includes/game_actions.php:96-104
- **Flow:**
  1. When resolving an attack where `$actions['attaquant'] == $joueur`, code calls `updateActions($actions['defenseur'])` at line 99.
  2. `updateActions` is protected from infinite self-recursion via `static $updating = []` guard.
  3. However, `updateActions($defenseur)` will itself query `actionsattaques WHERE attaquant=? OR defenseur=?` using the DEFENDER'S login.
  4. If the DEFENDER also has an active attack against the ATTACKER (mutual attack), it will try to call `updateActions($attaquant)` — which IS already in `$updating` so it's blocked.
  5. The defender's attack against the attacker will be resolved, but it calls `updateRessources($attaquant)` INSIDE the outer transaction (game_actions.php:98 calls `updateRessources` before the transaction begins — but the combat transaction starts at line 109).
  6. Actually `updateRessources` for the attacker is called at line 98 BEFORE the transaction. Inside `updateActions($defenseur)`, another `updateRessources($attaquant)` call occurs at line 98-103 of that inner invocation.
  7. Since `updateRessources` uses an atomic CAS guard (`UPDATE ... WHERE tempsPrecedent=?`), the second call within the same second will be a no-op. No double resources, but the second call wastes queries.
- **Impact:** No data corruption but significant performance overhead. Mutual attacks create deeply nested call stacks. On a server with many active players, this can cause PHP stack depth warnings or timeouts.
- **Fix:** Defer recursive `updateActions` calls using a queue instead of direct recursion. Collect a list of players to update during the current request, then process them in a second pass after the current player's updates complete.

---

### P2-D5-016 | MEDIUM | Molecule Deletion Mid-Flight Attack: Troop String Zeroed But Not Validated

- **Location:** armee.php:44-58 (molecule deletion) vs game_actions.php decay loop
- **Flow:**
  1. Player deletes molecule class 2 while an attack using class 2 is in flight.
  2. `actionsattaques.troupes` is updated to zero out slot 2: `"class1count;0;class3count;class4count;"`.
  3. The attack continues in flight with 0 troops of class 2.
  4. When the attack resolves, combat.php loads all 4 defender molecule classes but only 3 attacker classes have troops.
  5. The class 2 attacker molecules (`classeAttaquant2`) now represent the CURRENT class 2 molecule definition (which might be "Vide" or reassigned to a different formula), not the original attacking formula.
  6. Combat uses these fresh definitions: `classeAttaquant2['azote']`, `['brome']`, etc. — now from a potentially different molecule type.
- **Impact:** Attack stats for class 2 are computed from wrong molecule data (Vide = 0 atoms = 0 attack/defense/etc.), which is actually safe since 0 troops are sent. However, if the player CREATES a new class 2 molecule after deletion, those new atoms are used in the attack formula lookup even though class 2 was zeroed. In practice, 0 troops means 0 damage, so this is cosmetic. The real risk is the pillage report showing the wrong formula name for class 2.
- **Impact rating correction:** LOW-MEDIUM. Cosmetic report inaccuracy, no balance impact since 0 troops = 0 effect.
- **Fix:** When zeroing out troops in a flight, also store a snapshot of the molecule formula at attack time (or accept the cosmetic inaccuracy as acceptable).

---

### P2-D5-017 | MEDIUM | Compound `synthesizeCompound` — No Transaction Lock on Stock Count

- **Location:** includes/compounds.php:49-91
- **Flow:**
  1. `countStoredCompounds()` at line 57 runs outside a transaction.
  2. Resource check at lines 70-75 runs outside a transaction.
  3. `withTransaction` begins at line 78 — but it does NOT re-verify the stock count inside the transaction.
  4. Two simultaneous synthesis requests can both pass the `COMPOUND_MAX_STORED` check and both insert new compounds.
- **Impact:** Player can exceed `COMPOUND_MAX_STORED` limit by submitting two synthesis requests simultaneously. With limit of 3, player can get 4 or more stored compounds. This gives unfair combat/production buffs.
- **Fix:** Inside `withTransaction`, re-run `countStoredCompounds` with a locking read (or use `SELECT COUNT(*) FROM player_compounds WHERE login=? AND activated_at IS NULL FOR UPDATE`) and throw if limit exceeded.

---

### P2-D5-018 | MEDIUM | Compound `getCompoundBonus` Static Cache Persists Across Multiple Combats in Same Request

- **Location:** includes/compounds.php:143-162
- **Flow:**
  1. `static $cache = []` in `getCompoundBonus`.
  2. In a single request where `updateActions` processes multiple attack rows (e.g., player and two incoming attacks), compound bonuses are computed once and cached.
  3. If a compound expires between the first and second attack resolution in the same request, the cached value is still used.
  4. `cleanupExpiredCompounds` at laboratoire.php:30 only runs when the player visits the lab page.
- **Impact:** Expired compounds can still grant bonuses during combat resolution if the request processes multiple attacks in one PHP execution. Extremely rare timing window but theoretically exploitable with precisely timed attacks.
- **Fix:** Invalidate the static cache when `cleanupExpiredCompounds` is called, or use `time()` check inside the cache to expire entries older than 60 seconds.

---

### P2-D5-019 | MEDIUM | Market Price Update Doesn't Lock `cours` Table Row

- **Location:** marche.php:200-222 (buy) and marche.php:321-343 (sell)
- **Flow:**
  1. `$tabCours` fetched at line 10-11: `SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1`.
  2. Price manipulation computed inside transaction and new row inserted: `INSERT INTO cours VALUES (default,?,?)`.
  3. Two simultaneous transactions read the SAME `tabCours` row and both insert new price rows.
  4. The second insert uses the pre-first-update price, so the price impact of the first transaction is lost.
  5. Both transactions commit successfully. Market has a "phantom" price record that skips one update.
- **Impact:** Market price manipulation is unreliable under concurrent load. High-volume trading causes price to move less than intended (volatility is halved when two buyers hit simultaneously). This dampens market dynamics but doesn't create exploitable value. Medium severity as it affects game economy feel.
- **Fix:** Inside the transaction, re-fetch the latest price with `SELECT tableauCours FROM cours ORDER BY timestamp DESC LIMIT 1 FOR UPDATE` before computing the new price.

---

### P2-D5-020 | MEDIUM | `updateRessources` Applies Resource Node Bonus on Every Tick But Node Can Expire

- **Location:** includes/game_resources.php:113-120 (revenuAtome), includes/resource_nodes.php (getResourceNodeBonus)
- **Flow:**
  1. `revenuAtome()` calls `getResourceNodeBonus($base, $pos['x'], $pos['y'], $nomsRes[$num])`.
  2. `updateRessources` calls `revenuAtome` for each resource type per update tick.
  3. Resource nodes have an expiration time. If a node expires DURING a long offline period, the resource revenue for the entire offline period is calculated using the expired node bonus (or without it, depending on timing).
  4. The issue: `updateRessources` applies the CURRENT node status to ALL elapsed time since last connection. If a node appeared or expired during a player's offline period, the full offline production is incorrectly credited/missed.
- **Impact:** Players who log off near node expiration get either too much or too little production. Not exploitable but creates inconsistent player experience, especially for players whose resource nodes expire while they sleep.
- **Fix:** This is a fundamental limitation of the "apply-to-whole-period" resource model. Consider dividing the offline period at node boundary timestamps, or document that node bonuses are only applied at connection time (simpler but less fair).

---

### P2-D5-021 | MEDIUM | `revenuEnergie` Has Per-Request Static Cache That Ignores Level Parameter

- **Location:** includes/game_resources.php:7-91
- **Flow:**
  1. Cache key at line 10: `$cacheKey = $joueur . '-' . $niveau . '-' . $detail`.
  2. The `$niveau` parameter is the GENERATOR level — passed by the caller, not always the current level.
  3. In `initPlayer` at player.php:163: `$revenuEnergie = revenuEnergie($constructions['generateur'], $joueur)`.
  4. In `listeConstructions` at player.php:323: `revenuEnergie($niveauActuel['niveau'] + 1, $joueur, 4)` — passes projected level+1.
  5. Both correctly use different cache keys due to `$niveau` in the key.
  6. **However**, `revenuEnergie` internally fetches `constructions` from DB again (line 18), ignoring the passed `$niveau` for some calculations (e.g., iode catalyst uses current molecule counts, duplicateur uses current alliance level). These are correct — they should use current values.
  7. **Real issue**: When `augmenterBatiment('generateur', $joueur)` runs, it calls `invalidatePlayerCache($joueur)`, but the static cache in `revenuEnergie` is NEVER cleared. The function's static cache can serve stale values for the rest of the request after a generator upgrade completes.
- **Impact:** After a building upgrade completes in the same request, `revenuEnergie` returns cached (pre-upgrade) revenue for display purposes on the constructions page. Player sees old revenue figures until next page load. Cosmetic but misleading.
- **Fix:** Add an `invalidateRevenuCache($joueur)` function that clears the static caches in `revenuEnergie` and `revenuAtome`. Call it from `invalidatePlayerCache`.

---

### P2-D5-022 | MEDIUM | War Loss Tracking Race: Two Attacks in Same War Resolve Simultaneously

- **Location:** includes/combat.php:690-699
- **Flow:**
  1. `$guerres = dbFetchAll($base, 'SELECT * FROM declarations WHERE type=0 AND fin=0 ...')`.
  2. War row fetched without FOR UPDATE.
  3. `UPDATE declarations SET pertes1=?, pertes2=? WHERE id=?` — overwrites entire pertes values.
  4. If two alliance members both attack the enemy simultaneously and both combats resolve in the same second, both read the same `pertes1`/`pertes2` values, both compute `pertes1 + pertesAttaquant`, and both write. The second write overwrites the first — one battle's losses are lost from the war record.
- **Impact:** Alliance war loss totals are undercounted when multiple allied members attack simultaneously. War victory conditions based on loss thresholds may trigger late or never, or alliances appear to have taken fewer losses than they did. Affects war resolution fairness.
- **Fix:** Use atomic increments: `UPDATE declarations SET pertes1 = pertes1 + ?, pertes2 = pertes2 + ? WHERE id=?` instead of read-then-overwrite. Add `FOR UPDATE` to the SELECT or switch to atomic UPDATE.

---

### P2-D5-023 | MEDIUM | `inscrire`: Registration Does Not Validate Email Format

- **Location:** includes/player.php:27-61
- **Flow:**
  1. `$safeMail = antihtml(trim($mail))` — XSS sanitized but not validated as email.
  2. Stored directly into `membre` table.
  3. End-of-season email notifications use this address. Invalid addresses cause mailer errors.
  4. No uniqueness check on email — multiple accounts can share the same email address.
- **Impact:** Players can register with invalid emails (e.g., "asdf" or empty string), then receive no season-end notifications. Also allows one real player to control multiple accounts sharing the same email, evading multi-account detection based on email.
- **Fix:** Add `filter_var($mail, FILTER_VALIDATE_EMAIL)` check before inserting. Add a UNIQUE constraint on `membre.mail` if multi-account prevention is desired.

---

### P2-D5-024 | MEDIUM | `coordonneesAleatoires` — Map Expansion Is Not Atomic

- **Location:** includes/player.php:620-679
- **Flow:**
  1. Reads `tailleCarte` and `nbDerniere` from `statistiques`.
  2. If map edge is full, increments `tailleCarte`.
  3. `UPDATE statistiques SET tailleCarte=?, nbDerniere=?` — not inside a transaction.
  4. Two simultaneous registrations can both read the same `tailleCarte`, both decide to expand, and both write the expanded size — but only increment `tailleCarte` by 1 instead of 2. One registration's player is placed at coordinates that exceed the actual map size.
- **Impact:** Race condition during simultaneous registration: two new players are assigned overlapping coordinates, or one player ends up at coordinates outside the map bounds. The second player's position would be `x = tailleCarte`, which is out of the array bounds when the map renders (0-indexed).
- **Fix:** Wrap the entire `coordonneesAleatoires` function in a transaction with a `SELECT ... FOR UPDATE` on `statistiques`.

---

### P2-D5-025 | MEDIUM | Attack Speed: JS Client Preview Doesn't Account for Compound Speed Boost

- **Location:** attaquer.php:143-149 (PHP applies boost) vs attaquer.php:516 (JS computes display)
- **Flow:**
  1. Server-side attack dispatch at attaquer.php:147-149 applies `speedBoost`: `$tempsTrajet = max(1, round($tempsTrajet / (1 + $speedBoost)))`.
  2. Client-side JS preview at attaquer.php:516: `tempsAttaque[' . ($c - 1) . '] = ' . round($distance / vitesse(...) * SECONDS_PER_HOUR) . ';` — no speed boost applied.
  3. Player UI shows a travel time that is longer than the actual dispatch time.
- **Impact:** When a player has an active speed compound, the attack timer shown in the UI is wrong (too high). They submit the attack thinking it'll take 2 hours, but it actually arrives in 1.67 hours. Defender gets less warning than the UI implies. Not a security issue, but a UX deception that makes the game feel unreliable.
- **Fix:** Pass `$speedBoost` from PHP to JS as a variable, then multiply the JS time preview by `1 / (1 + speedBoost)`.

---

### P2-D5-026 | MEDIUM | Constructeur Point Deduction in `diminuerBatiment` Uses `$listeConstructions` From Cache

- **Location:** includes/player.php:612
- **Flow:**
  1. `ajouterPoints(-$listeConstructions[$nom]['points'], $joueur)`.
  2. `$listeConstructions` is populated in `initPlayer` and may be cached in `$GLOBALS['_initPlayerCache']`.
  3. The points value in `$listeConstructions[$nom]['points']` depends on `$niveauActuel['niveau']` at the time `initPlayer` was called.
  4. `diminuerBatiment` calls `invalidatePlayerCache` and `initPlayer` before reading `$batiments` (the current level before decrement), so `$listeConstructions` should reflect the current state.
  5. However, `$listeConstructions[$nom]['points']` uses the NEXT level's point value (line 328 in player.php: `$BUILDING_CONFIG['generateur']['points_base'] + floor($niveauActuel['niveau'] * ...)`), where `niveauActuel` is the current building level from the queue — not the level being decreased to.
  6. `diminuerBatiment` should subtract the same points that were awarded when the building was upgraded. Those were computed at the PRIOR level. But `$listeConstructions` computes points at the CURRENT level (before the decrement). If building cost formula is linear, this is exact. If it has any nonlinear component, points subtracted ≠ points originally awarded.
- **Impact:** Construction point totals slowly drift up or down over time as buildings are repeatedly upgraded and downgraded by combat. Players' construction rankings gradually become inaccurate. The magnitude depends on how nonlinear the points formula is.
- **Fix:** Store the points awarded when a building is upgraded (in `actionsconstruction` or in `constructions`), and deduct the same stored value when downgrading. Alternatively, recompute total points from scratch on each downgrade.

---

### P2-D5-027 | MEDIUM | `updateActions` Processes Attacks Order DESC but Return Trips Can Conflict

- **Location:** includes/game_actions.php:84
- **Flow:**
  1. `$rows = dbFetchAll($base, 'SELECT * FROM actionsattaques WHERE attaquant=? OR defenseur=? ORDER BY tempsAttaque DESC', ...)`.
  2. With `ORDER BY tempsAttaque DESC`, newer attacks are processed first.
  3. If a player sent two attacks to the same target, the SECOND (newer) attack resolves first. This affects the defender's molecule count for the first attack: the first attack's combat might see a defender who has already lost troops from the second attack.
  4. Processing ORDER DESC means the more recent attack resolves against more troops, while the older attack resolves against fewer troops — INVERTED from the intended chronological order.
- **Impact:** Players who time multiple attacks to arrive simultaneously will have the attacks resolve in reverse chronological order. The first-sent attack (which should hit the fresh defender) actually hits a depleted defender, and the later attack hits the full defender. This creates a counter-intuitive exploit: send a strong first wave, then a weak second wave. The weak wave hits the full defender (and loses), while the strong wave mops up the already-depleted defender.
- **Fix:** Change `ORDER BY tempsAttaque DESC` to `ORDER BY tempsAttaque ASC` to process attacks in chronological order.

---

### P2-D5-028 | MEDIUM | `revenuAtomeJavascript` Does Not Include Node Bonus or Compound Bonus

- **Location:** includes/game_resources.php:134-152
- **Flow:**
  1. `revenuAtomeJavascript` outputs a JS function that calculates atom revenue client-side.
  2. Formula used: `return Math.round(' . $bonusDuplicateur . '*' . BASE_ATOMS_PER_POINT . '*niveau)`.
  3. Server-side `revenuAtome()` includes node bonus, compound bonus, prestige bonus, specialization modifier.
  4. The JS function omits all of these.
  5. On the constructions page, when players click the `+` button to allocate producteur points, the live preview shows revenue WITHOUT node/compound/prestige/spec bonuses.
- **Impact:** Player sees an incorrect (lower) atom production preview on the constructions page. They may misallocate producteur points based on wrong projections. Once saved, the actual production is higher (server-calculated), but the user experience is misleading.
- **Fix:** Pass all the relevant multipliers as JS variables (prestige factor, compound bonus, node bonus) and include them in the `revenuAtomeJavascript` formula, or display a note that previews don't include active bonuses.

---

### P2-D5-029 | LOW | `ajouter()` Function: Atomic Increment Allows Negative Values for Some Columns

- **Location:** includes/db_helpers.php:34
- **Flow:**
  1. `dbExecute($base, "UPDATE $bdd SET $champ = $champ + ? WHERE login=?", 'ds', $nombre, $joueur)`.
  2. When `$nombre` is negative (e.g., energy deduction), the result could go below zero if there's no floor constraint.
  3. `energie` in `ressources` has no MySQL CHECK constraint (migration 0017 adds CHECK constraints for resources, but its status on production was noted as "may need careful testing" in MEMORY.md).
  4. If `ajouter('energie', 'ressources', -99999, $login)` is called and the player has 100 energy, the result is -99899 — a negative energy balance.
  5. Legitimate call sites (neutrino formation, attack energy cost) validate before calling `ajouter`, but edge cases in concurrent scenarios could bypass the pre-check.
- **Impact:** Negative energy balance is possible in concurrent edge cases. Players with negative energy can still produce resources (energy production formula uses `max(0, round($prodProducteur))`) but their energy won't increase until production overcomes the deficit. This could lock a player out of market purchases until their energy recovers.
- **Fix:** Add `GREATEST(0, $champ + ?)` in the SQL for energy-related columns: `UPDATE ressources SET energie = GREATEST(0, energie + ?) WHERE login=?`. Or enforce the CHECK constraint from migration 0017.

---

### P2-D5-030 | LOW | Combat Report: Defender Info Overwritten Before Report HTML Finalized

- **Location:** includes/game_actions.php:263-307
- **Flow:**
  1. Lines 228-236: `$classeDefenseur1['nombre']` etc. are formatted with `separerZeros` for the `$milieuDefenseur` string.
  2. Lines 263-280: if `$attaquantsRestants == 0`, the SAME variables are overwritten with `"?"` for the attacker report.
  3. Lines 284-307: `$milieuAttaquant` is built using the now-overwritten variables.
  4. **Bug**: `$milieuDefenseur` was built BEFORE the overwrite, so it correctly uses real values. But at line 279, `dbExecute('DELETE FROM actionsattaques WHERE id=?')` runs before the commit. If the commit fails, the attack row is deleted but never re-inserted.
  5. More directly: `$milieuDefenseur` is built with `separerZeros`-formatted numbers. Then the variables are set to `"?"`. `$milieuAttaquant` reuses the same PHP variable names (lines 285-306) but these are now `"?"`. This is actually intentional — the attacker can't see defender info when all their troops died. But `$milieuDefenseur` shows defender numbers in the DEFENDER'S report, while `$milieuAttaquant` shows `"?"` in the ATTACKER'S report. This is correct behavior.
  6. **Actual bug found**: Line 279 DELETEs the attack row from `actionsattaques` inside the transaction. If the transaction commits successfully, the row is gone (correct). But the `$milieuDefenseur` string was built with pre-`separerZeros` values at line 228 — which is AFTER the `$classeDefenseur1['nombre']` was set to `ceil()` at combat.php:10. The display correctly uses ceil'd values. No actual data bug here.
  7. **Real issue**: The defender's report (`$contenuRapportDefenseur`) at line 344 uses `$milieuDefenseur` (real values) which is correct. The attacker's report (`$contenuRapportAttaquant`) at line 343 uses `$milieuAttaquant` (which reuses `$classeDefenseur*` variables set to `"?"` or real values). If attacker survived, `milieuAttaquant` contains real defender data that the ATTACKER can see — exposing defender force composition.
- **Impact:** When attacker survives, they see the EXACT defender molecule composition and counts in their combat report. This is potentially intentional (scouting via successful combat), but combined with espionage, makes defender secrecy impossible. Not a bug per se, but a game design decision worth documenting.
- **Note:** This is already documented as existing behavior. No code fix needed unless secrecy is desired.

---

### P2-D5-031 | LOW | Beginner Protection Checked Against `membre.timestamp` Not Registration Date

- **Location:** attaquer.php:65-68
- **Flow:**
  1. Defender: `time() - $enVac['timestamp'] < BEGINNER_PROTECTION_SECONDS` — `timestamp` is last login time.
  2. Attacker: `time() - $membre['timestamp'] < BEGINNER_PROTECTION_SECONDS` — same `timestamp`.
  3. **Bug**: `membre.timestamp` is updated to `time()` on EVERY login (see basicprivatephp.php or session init). This means a new player who logs in every day has their protection RESET each time they log in.
  4. A player registered 3 days ago but logging in daily: `timestamp` = today = 0 seconds since last login. Protection appears active for another 3 days. Protection effectively lasts until the player goes 3 days without logging in.
- **Impact:** Active new players can remain under beginner protection indefinitely as long as they log in more than once every 3 days. This fundamentally breaks the intended "first 3 days" protection mechanic. Veterans cannot attack active new players even after weeks.
- **Fix:** Store a separate `inscriptionTimestamp` column in `membre` set once at registration and never updated. Use this for protection calculations instead of the login timestamp.

---

### P2-D5-032 | LOW | `actionsformation` DELETE Before Molecules Updated Inside Same Transaction

- **Location:** includes/game_actions.php:72
- **Flow:**
  1. In the `fin < time()` branch (action complete): `dbExecute('DELETE FROM actionsformation WHERE id=?')` at line 72.
  2. Then `UPDATE molecules SET nombre=? WHERE id=?` at line 73-76.
  3. Both are inside `withTransaction`.
  4. If the molecule UPDATE fails (e.g., molecule was deleted by the player in another concurrent request that deleted the class), the DELETE of the action row was already executed but the transaction would rollback both.
  5. However, the `FOR UPDATE` lock on `actionsformation` at line 51 prevents other concurrent requests from processing the same action, but does NOT prevent the PLAYER from deleting the molecule class (which happens in a separate transaction in armee.php:14).
  6. Player deleting molecule class removes the formation action from `actionsformation` at armee.php:31: `DELETE FROM actionsformation WHERE login=? AND idclasse=?`.
  7. The updateActions transaction locks `actionsformation` via `SELECT ... FOR UPDATE`, but the armee.php delete transaction could have already run and removed the row before updateActions started.
  8. The `IF (!$actions) return;` at line 52 handles this correctly — if the action was deleted, the SELECT FOR UPDATE returns nothing and the transaction exits cleanly.
- **Impact:** Actually handled correctly. No bug. This is a note that the CAS guard at line 51-52 correctly handles the race condition.
- **Verdict:** No fix needed.

---

### P2-D5-033 | LOW | Season Reset: No Explicit Lock on `statistiques.seasonActive` Flag

- **Location:** No season reset file found (`fin_de_manche.php` does not exist in current codebase)
- **Flow:**
  1. The season reset system referenced in MEMORY.md (Batch D: performSeasonEnd) uses a two-phase maintenance system.
  2. Without seeing the actual reset code, the risk is that two admin requests or two cron triggers can both initiate season reset simultaneously.
  3. If the `statistiques.seasonActive` flag isn't checked with FOR UPDATE before being set to 0, two resets could both run, causing double prestige distribution, double rank-to-victory-points conversion, etc.
- **Impact:** Potential double-rewards during season end. Severity depends on implementation.
- **Fix:** Ensure the season reset code uses `UPDATE statistiques SET seasonActive=0 WHERE seasonActive=1` as an atomic gate before proceeding with any other reset operations.

---

### P2-D5-034 | LOW | `activateCompound`: No Check That Compound's Effect Value Is Positive

- **Location:** includes/compounds.php:98-133
- **Flow:**
  1. `$COMPOUNDS[$key]['effect_value']` is summed in `getCompoundBonus` at line 156.
  2. No validation that `effect_value > 0` before activation.
  3. If a compound definition in `$COMPOUNDS` config array has a negative `effect_value` by mistake (e.g., a debuff compound), `getCompoundBonus` would return a negative multiplier.
  4. In combat: `$compoundAttackBonus = getCompoundBonus($base, $actions['attaquant'], 'attack_boost')`. Then `if ($compoundAttackBonus > 0) $degatsAttaquant *= (1 + $compoundAttackBonus)` — the `> 0` check correctly skips negative bonuses for attack.
  5. But for production in `revenuEnergie`: `$compoundProdBonus = getCompoundBonus($base, $joueur, 'production_boost')`. Used as `$prodCompound = $prodNodes * (1 + $compoundProdBonus)`. If negative, this REDUCES production below baseline, which could be intentional (debuff) but is not guarded.
- **Impact:** Misconfigured compound with negative effect_value could silently reduce a player's production. Low risk since the config is server-side, but worth hardening.
- **Fix:** In `synthesizeCompound`, validate that `$COMPOUNDS[$compoundKey]['effect_value'] > 0`. Or in `getCompoundBonus`, add `max(0.0, $totalBonus)` return to prevent negative bonuses being applied where not intended.

---

### P2-D5-035 | LOW | Alliance Quitter Action Uses Wrong Form Name (Hidden Input Instead of Proper Name)

- **Location:** alliance.php:287-288
- **Flow:**
  1. The "quitter" (leave alliance) form submits via image button click: `<input type="image" ... name="quitteralliance" ...>`.
  2. Hidden input: `<input type="hidden" name="quitter"/>`.
  3. Server-side check at line 67: `if (isset($_POST['quitter']))`.
  4. The image button sends `quitteralliance.x` and `quitteralliance.y` (pixel coordinates) when clicked. The hidden `quitter` input is always present in the form.
  5. Result: `$_POST['quitter']` is always set when this form is submitted (it's a hidden field with empty value). However, `isset()` on an empty string returns `true`, so the leave check fires on ANY submit of this form.
  6. Since the only submit button in this form is the image button, this works as intended.
  7. **Actual bug**: The form at alliance.php:282 uses `echo '<form action="alliance.php" method="post">'; echo csrfField();` but the `finCarte()` call at line 284 wraps additional HTML including another form (the duplicateur form). The leave-alliance form is never explicitly closed with `</form>` — it's closed by `finCarte()`.
- **Impact:** Minor HTML structure issue. Form is implicitly closed by the card's div structure. Browsers handle this via error recovery. Not a security issue, but invalid HTML.
- **Fix:** Add explicit `</form>` before `finCarte()` call for the leave-alliance form.

---

### P2-D5-036 | LOW | Espionage Success Check Uses `<` Instead of `<=`

- **Location:** includes/game_actions.php:367
- **Flow:**
  1. `if ($espionageThreshold < $actions['nombreneutrinos'])` — spy succeeds if neutrinos sent > threshold.
  2. `$espionageThreshold = ($nDef['neutrinos'] * ESPIONAGE_SUCCESS_RATIO) * $radarDiscount`.
  3. With `ESPIONAGE_SUCCESS_RATIO = 0.5`: threshold = defender neutrinos * 0.5.
  4. If attacker sends EXACTLY half of defender neutrinos: `threshold = defender/2`, `nombreneutrinos = defender/2`. Condition: `defender/2 < defender/2` = FALSE. Spy fails at EXACTLY the threshold.
  5. The player guide/rules likely say "more than half" — which would be correct with `<`. But the exact phrasing matters.
  6. If description says "at least half" (">= threshold"), then the code is wrong (should use `<=`).
- **Impact:** Edge case. Players sending exactly the minimum neutrinos get a failure instead of success. Causes one wasted neutrino batch per affected spy attempt. Low impact but inconsistent with player expectation.
- **Fix:** Verify the intended game design. If "more than half" is intended, the `<` operator is correct. If "at least half" is intended, change to `<=`.

---

### P2-D5-037 | LOW | `attaquer.php`: Attack Cost JS Display Doesn't Apply `$coutPourUnAtome` Properly for Multiple Classes

- **Location:** attaquer.php:498-507
- **Flow:**
  1. For each molecule class, JS computes: `cout += document.getElementById("nbclasse' . $i . '").value * ' . ($totAtomes * $coutPourUnAtome) . ';`.
  2. `$totAtomes` is per-class, `$coutPourUnAtome` is global. Correct.
  3. **Bug**: `$totAtomes` at line 501 is computed as `foreach ($nomsRes as $num => $res) { $totAtomes += $molecules1[$res]; }` using `$molecules1 = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $_SESSION['login'], $i)`.
  4. This runs INSIDE the attack form display loop at line 483: `for ($i = 1; $i <= $nbClasses; $i++)`.
  5. `$nbClasses` at line 472 is `count($moleculesJsRows)` — count of NON-VIDE molecules.
  6. But `numeroclasse` values of non-vide molecules may not be 1,2,3,4 sequentially — if the player has classes 1, 3 (skipping 2 because it's Vide), the loop with `$i=2` queries `numeroclasse=2` (Vide) and gets 0 atoms. The JS cost calculation for this slot would be 0, which is correct since 0 troops are sent from Vide classes.
  7. **Actual miscount**: The loop goes from 1 to `$nbClasses` (number of non-vide classes), but queries by `numeroclasse=$i`. If `$nbClasses = 2` (classes 1 and 3 are non-vide), the loop only executes for `$i=1,2`, missing `numeroclasse=3`.
- **Impact:** If a player has non-contiguous molecule classes (e.g., class 1 and class 3 defined, class 2 is Vide), the JS cost calculator only shows costs for classes 1 and 2 in the preview. The actual attack cost includes class 3. UI shows wrong (lower) cost estimate. Player submits thinking they have enough energy, but they might not.
- **Fix:** Replace `for ($i = 1; $i <= $nbClasses; $i++)` with iteration over `$moleculesJsRows` (the actual non-vide molecule list), using `$molecules['numeroclasse']` as the class number.

---

### P2-D5-038 | LOW | Building Upgrade Queue: Two Simultaneous Upgrades of Same Building Create Phantom Level

- **Location:** constructions.php (not read, but inferred from `actionsconstruction` table usage)
- **Flow:**
  1. Construction uses MAX queue level: `SELECT MAX(niveau) AS niveau FROM actionsconstruction WHERE login=? AND batiment=?`.
  2. If player somehow submits two simultaneous construction requests for the same building (double-click or two browser tabs), two rows in `actionsconstruction` may be inserted with the same `niveau`.
  3. When `updateActions` processes them: both DELETE + `augmenterBatiment` calls happen in separate transactions. The CAS guard (`DELETE ... return if affected=0`) prevents the second from running.
  4. However, the INSERT of the second construction action is not guarded — both can INSERT before either is processed.
  5. The first INSERT: `niveau = currentLevel + 1`. Second INSERT: also `niveau = currentLevel + 1` (read from same MAX before either was inserted).
- **Impact:** Two duplicate rows for the same building level exist in `actionsconstruction`. The first is processed correctly. The second is prevented by the CAS guard (DELETE returns 0 affected). Second is abandoned but never cleaned up — it sits in the table forever. Over time, orphaned rows accumulate. No functional impact, just DB bloat.
- **Fix:** Add a UNIQUE constraint on `(login, batiment, niveau)` in `actionsconstruction`. This would make the second INSERT fail at the DB level.

---

### P2-D5-039 | LOW | `coefDisparition` Cache: Static Cache Not Keyed on Isotope State

- **Location:** includes/formulas.php:213-271
- **Flow:**
  1. `static $cache = []` with key `$joueur . '-' . $classeOuNbTotal . '-' . $type`.
  2. Isotope type is read from `$donnees['isotope']` inside the function at line 260-267.
  3. If a player changes their isotope (creates a new molecule class overwriting an old one) mid-session, the cache still returns the old decay coefficient.
  4. In practice, isotope changes require molecule deletion and recreation, which involves `updateRessources` and `updateActions` calls that create new DB reads — but the static cache in `coefDisparition` would not be cleared.
- **Impact:** After changing a molecule's isotope, the decay rate displayed and used for the remainder of the request is stale. As static caches persist only per-request (PHP shared-nothing), this only matters if `coefDisparition` is called multiple times in the same request after an isotope change. Extremely rare edge case.
- **Fix:** Include isotope type in the cache key: `$cacheKey = $joueur . '-' . $classeOuNbTotal . '-' . $type . '-' . ($donnees['isotope'] ?? 0)`. Or invalidate the static cache when a molecule is modified.

---

### P2-D5-040 | LOW | Market Chart: `$tot` String Concatenation Builds JSON in Reverse Order

- **Location:** marche.php:607-620
- **Flow:**
  1. `$coursRows = dbFetchAll($base, "SELECT * FROM cours ORDER BY timestamp DESC LIMIT " . MARKET_HISTORY_LIMIT)`.
  2. Rows returned in descending timestamp order (newest first).
  3. Loop: `$tot = '["date",' . $cours['tableauCours'] . ']' . $fin . $tot` — prepends each entry.
  4. After the loop, `$tot` is in ascending time order (oldest first = correct for a left-to-right chart).
  5. The `$fin` comma separator logic: `if ($c != 1) $fin = ","` else `$fin = ""`. Since `$c` starts at 1 and increments, the FIRST row (most recent) gets no comma, and all subsequent rows get commas. But due to prepending, the final `$tot` has commas in wrong positions — the LAST item (oldest, now first in string) has a trailing comma missing.
  6. Actually tracing it: row 1 (newest): `$fin=""`, `$tot = '[newest]'`. Row 2: `$fin=","`, `$tot = '[row2]' . "," . '[newest]'`. Final string: `[oldest],...,[newest]`. This is correct for chart left-to-right.
  7. **Actual bug**: `$fin` is computed as `$c != 1 ? "," : ""`. For row 1 (newest, processed first), `$fin = ""`. For subsequent rows (older), `$fin = ","`. The concatenation is `[row] . $fin . $tot`. So: row 3: `[oldest] . "," . [middle],[newest]` = `[oldest],[middle],[newest]`. This looks correct!
  8. Wait — edge case: what if exactly 1 row is in the result? `$tot = '[single]'`. Chart renders with one point. Google Charts requires at least 2 data points for line charts. With 1 point, the chart renders as empty or throws a JS error.
- **Impact:** When the market has only 1 price history row (fresh install, just after season reset), the Google Charts line chart fails to render. Players see an empty chart area. Low impact (only at very start of season).
- **Fix:** Add a minimum data check: if `count($coursRows) < 2`, skip the chart or show a "Not enough data" message.

---

## Summary Table

| ID | Severity | Category | File |
|----|----------|----------|------|
| P2-D5-001 | HIGH | Combat Race / Data Loss | game_actions.php |
| P2-D5-002 | HIGH | Formation Queue / Free Molecules | armee.php |
| P2-D5-003 | HIGH | Combat Race / Double Pillage | combat.php |
| P2-D5-004 | HIGH | Formation Precision / Troop Loss | game_actions.php |
| P2-D5-005 | HIGH | Formation+Return Race / Troop Loss | game_actions.php |
| P2-D5-006 | HIGH | Combat HP / Wrong Stat Array | combat.php |
| P2-D5-007 | HIGH | Market Race / Price Stale | marche.php |
| P2-D5-008 | HIGH | Transfer Atomicity / Free Items | marche.php |
| P2-D5-009 | HIGH | Espionage Atomicity / Double-Spend | attaquer.php |
| P2-D5-010 | HIGH | Formation Decay / Silent Troop Loss | game_actions.php |
| P2-D5-011 | MEDIUM | Alliance Cap Race | alliance.php |
| P2-D5-012 | MEDIUM | Alliance Name Race | alliance.php |
| P2-D5-013 | MEDIUM | Combat Report HP % Inaccurate | combat.php |
| P2-D5-014 | MEDIUM | Building HP Full on Downlevel | player.php |
| P2-D5-015 | MEDIUM | Recursive updateActions Performance | game_actions.php |
| P2-D5-016 | MEDIUM | Mid-Flight Molecule Deletion Report | armee.php |
| P2-D5-017 | MEDIUM | Compound Stock Race | compounds.php |
| P2-D5-018 | MEDIUM | Compound Cache Cross-Combat | compounds.php |
| P2-D5-019 | MEDIUM | Market Price Lock Missing | marche.php |
| P2-D5-020 | MEDIUM | Resource Node Tick Boundary | game_resources.php |
| P2-D5-021 | MEDIUM | revenuEnergie Cache Stale After Upgrade | game_resources.php |
| P2-D5-022 | MEDIUM | War Loss Counter Race | combat.php |
| P2-D5-023 | MEDIUM | Email Not Validated on Registration | player.php |
| P2-D5-024 | MEDIUM | Map Expansion Race | player.php |
| P2-D5-025 | MEDIUM | Speed Compound Not In JS Preview | attaquer.php |
| P2-D5-026 | MEDIUM | Point Deduction Formula Drift | player.php |
| P2-D5-027 | MEDIUM | Attack Processing Order Reversed | game_actions.php |
| P2-D5-028 | MEDIUM | JS Atom Preview Missing Bonuses | game_resources.php |
| P2-D5-029 | LOW | Negative Energy Possible | db_helpers.php |
| P2-D5-030 | LOW | Defender Composition Visible to Attacker | game_actions.php |
| P2-D5-031 | LOW | Beginner Protection Uses Login Time | attaquer.php |
| P2-D5-032 | LOW | Formation Delete Order (Non-Bug Noted) | game_actions.php |
| P2-D5-033 | LOW | Season Reset Lock | (season reset file) |
| P2-D5-034 | LOW | Compound Negative Effect Unguarded | compounds.php |
| P2-D5-035 | LOW | Alliance Leave Form HTML Structure | alliance.php |
| P2-D5-036 | LOW | Espionage Threshold Boundary | game_actions.php |
| P2-D5-037 | LOW | Attack Cost JS Non-Contiguous Classes | attaquer.php |
| P2-D5-038 | LOW | Construction Queue Duplicate Row | (constructions.php) |
| P2-D5-039 | LOW | coefDisparition Cache Isotope Stale | formulas.php |
| P2-D5-040 | LOW | Market Chart Single Row Edge Case | marche.php |

---

## Critical Path Findings (Priority Fix Order)

### Tier 1 — Data Loss / Durable State Corruption
1. **P2-D5-001**: Combat rollback leaves `attaqueFaite=1` with no recovery — permanent troop loss.
2. **P2-D5-006**: Combat HP uses producteur points instead of condenseur points — all HP calculations wrong.
3. **P2-D5-003**: Resource pillage reads without FOR UPDATE — concurrent attacks produce wrong final state.
4. **P2-D5-005**: Formation completion and troop return both ADD to stale `nombre` — molecule duplication or loss.

### Tier 2 — Exploitable Race Conditions
5. **P2-D5-009**: Double-spend neutrinos via simultaneous espionage forms.
6. **P2-D5-008**: Resource transfer not atomic — free goods possible.
7. **P2-D5-002**: Formation queue reschedule bug — free molecules for mid-flight deletions.
8. **P2-D5-007**: Market price computed outside transaction — buy-at-old-price exploit.

### Tier 3 — Important Balance/Fairness
9. **P2-D5-027**: Attack resolution in reverse chronological order — tactical exploit.
10. **P2-D5-031**: Beginner protection tied to login time — exploitable indefinite protection.
11. **P2-D5-022**: War loss counter race — alliance war totals undercounted.
12. **P2-D5-011**: Alliance member cap not atomic — cap bypass possible.

---

## Patterns Observed

1. **Atomic CAS Guard Pattern Is Inconsistent**: Combat uses it for `actionsattaques`, construction uses it for `actionsconstruction`, but formation, espionage, and transfer operations lack equivalent protection.

2. **FOR UPDATE Missing in Resource Reads**: The most common class of vulnerability. Resource reads before state mutations should universally use FOR UPDATE inside transactions.

3. **Static Cache Invalidation Absent**: Four different static caches (`revenuEnergie`, `revenuAtome`, `coefDisparition`, `getCompoundBonus`) are never explicitly invalidated. Stale values persist within a request after state mutations.

4. **JS Preview vs. Server Reality Gap**: Multiple UI previews (atom revenue, attack cost, attack travel time) use simplified PHP-to-JS formulas that omit compound bonuses, node bonuses, prestige factors, and speed modifiers.

5. **Transaction Boundary Too Narrow**: Several multi-step operations start transactions after one of the key reads, leaving the read outside the serializable boundary. The pattern `read → compute → begin_transaction → write` should be `begin_transaction → read FOR UPDATE → compute → write`.

---

*End of Pass 2 — Domain 5 — Game Mechanics Audit*
*40 findings: 10 HIGH, 18 MEDIUM, 12 LOW*
