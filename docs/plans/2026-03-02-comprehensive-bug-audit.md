# Comprehensive Bug Audit & Fix Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Fix all 18 identified bugs across season management, combat, buildings, diplomacy, economy, and infrastructure in The Very Little War legacy PHP game.

**Architecture:** Direct surgical fixes to existing PHP files. No framework changes. Each task groups related bugs by subsystem for efficient implementation.

**Tech Stack:** PHP 8.2, MySQLi with prepared statements, procedural architecture

---

### Task 1: Fix Season Reset System (CRITICAL + HIGH)

**Bugs addressed:**
- BUG #1 (CRITICAL): Season-end email uses `$_SESSION['login']` instead of `$vainqueurManche` for winner name
- BUG #2 (HIGH): No actual 24h pause between seasons - reset happens immediately despite "24 hours" message

**Files:**
- Modify: `includes/basicprivatephp.php` (lines 110-267)

**Context:** The season reset runs in `basicprivatephp.php` when the first player logs in during a new month. It archives the game, sends emails, and resets everything. Two bugs: (1) the email text on lines 221-228 uses `$_SESSION['login']` (triggering player) instead of `$vainqueurManche` (actual winner determined on line 139), and (2) the code sets maintenance=1 and shows a "24h pause" message but immediately proceeds with the full reset in the same request.

**Step 1: Fix winner name in emails**

In `includes/basicprivatephp.php`, replace `$_SESSION['login']` with `$vainqueurManche` on lines 221-228 in both `$message_txt` and `$message_html`. There are exactly 2 occurrences in `$message_txt` and 2 in `$message_html` that reference `$_SESSION['login']` as the winner.

**Step 2: Implement actual 24h pause**

Split the season reset into two phases:
- Phase 1 (first login in new month): Set maintenance=1, show pause message, store `$debut` timestamp in a new column or use existing `debut` to track when maintenance started. Do NOT proceed with archiving/reset.
- Phase 2 (first login after 24h of maintenance): Check if maintenance has been active for >= 86400 seconds (24h). If yes, proceed with archiving, reset, and turn off maintenance.

The check should be: if `maintenance == 1` AND current time >= `debut + 86400` (where debut was updated when maintenance started), then do the full reset. Otherwise just show the maintenance message.

**Step 3: Fix encoding issue in email dates**

Lines 222-226 use `date('d/m/Y Ã H\hi')` which has a corrupted UTF-8 character. Replace `Ã` with `à`.

**Step 4: Commit**

```bash
git add includes/basicprivatephp.php
git commit -m "fix: correct winner name in season emails and implement actual 24h pause"
```

---

### Task 2: Fix Combat System (HIGH)

**Bugs addressed:**
- BUG #4 (HIGH): Wrong damage variable in attacker loss calculation (`$degatsAttaquant` instead of `$degatsDefenseur`)
- BUG #3 (HIGH): Buildings `champdeforce` and `producteur` can reach level 0 via combat damage (should minimum be level 1)
- BUG #5 (LOW): Pillage on draws - attacker can pilage even on a draw (`$gagnant == 0`)

**Files:**
- Modify: `includes/combat.php` (lines 94, 166, 266, 279)

**Context:** `combat.php` resolves attacks between players. It calculates damage, deaths, pillaging, and building destruction.

**Step 1: Fix damage allocation variable**

In `includes/combat.php` line 94, inside the attacker death loop (lines 79-100), change:
```php
$degatsUtilises = $degatsAttaquant;
```
to:
```php
$degatsUtilises = $degatsDefenseur;
```

This is in the block where a class of attackers survives (not all killed). The variable should indicate that all defender damage has been consumed, not attacker damage.

**Step 2: Fix building minimum levels**

In `includes/combat.php`:
- Line 266: Change `if ($constructions['champdeforce'] > 0)` to `if ($constructions['champdeforce'] > 1)`
- Line 279: Change `if ($constructions['producteur'] > 0)` to `if ($constructions['producteur'] > 1)`

These should match lines 253 and 292 which already correctly use `> 1` for generateur and depot.

**Step 3: Fix pillaging on draws**

In `includes/combat.php` line 166, change:
```php
if ($gagnant == 2 || $gagnant == 0) {
```
to:
```php
if ($gagnant == 2) {
```

Only the attacker should pilage on a clear win, not on a draw.

**Step 4: Commit**

```bash
git add includes/combat.php
git commit -m "fix: correct combat damage variable, building minimum levels, and draw pillaging"
```

---

### Task 3: Fix Building & Condenseur System (MEDIUM)

**Bugs addressed:**
- BUG #8 (MEDIUM): `diminuerBatiment()` allows buildings to go to level 0
- BUG #9 (MEDIUM): Condenseur point redistribution can create negative values
- BUG #10 (HIGH): Duplicateur display shows 0.1% per level but combat applies 1% per level

**Files:**
- Modify: `includes/player.php` (lines 195, 472, 503-508)

**Context:** `player.php` contains `diminuerBatiment()` which reduces building levels (called from combat damage), and `initPlayer()` which sets up the duplicateur bonus display.

**Step 1: Fix diminuerBatiment minimum level**

In `includes/player.php` line 472, change:
```php
if ($batiments[$nom] > 0) {
```
to:
```php
if ($batiments[$nom] > 1) {
```

This prevents any building from going below level 1 when destroyed by combat.

**Step 2: Fix condenseur negative values**

In `includes/player.php` lines 503-508, the condenseur redistribution loop can create negative values. When a level is reduced to 0, the subtraction `$pointsAEnlever - (${'niveau' . $ressource} - 1)` becomes `$pointsAEnlever - (-1)` which adds 1, creating incorrect behavior.

Fix by changing the condenseur redistribution (lines 501-509):
```php
$chaine = "";
foreach ($nomsRes as $num => $ressource) {
    $currentLevel = ${'niveau' . $ressource};
    if ($pointsAEnlever > 0 && $currentLevel > 0) {
        $canRemove = min($pointsAEnlever, $currentLevel);
        $chaine = $chaine . ($currentLevel - $canRemove) . ";";
        $pointsAEnlever -= $canRemove;
    } else {
        $chaine = $chaine . $currentLevel . ";";
    }
}
```

**Step 3: Fix duplicateur display inconsistency**

In `includes/player.php` line 195, the duplicateur bonus is calculated as:
```php
$bonusDuplicateur = 1 + ((0.1 * $duplicateur['duplicateur']) / 100);
```
This gives 0.1% per level. But in `combat.php` line 49, it's:
```php
$bonusDuplicateurAttaque = 1 + ($duplicateurAttaque['duplicateur'] / 100);
```
This gives 1% per level.

Align them to 1% per level (the combat value) by changing player.php line 195:
```php
$bonusDuplicateur = 1 + ($duplicateur['duplicateur'] / 100);
```

**Step 4: Commit**

```bash
git add includes/player.php
git commit -m "fix: building minimum levels, condenseur negative values, and duplicateur display"
```

---

### Task 4: Fix Alliance & Diplomacy System (HIGH)

**Bugs addressed:**
- BUG #11 (HIGH): SQL operator precedence in pact deletion deletes wrong records
- BUG #13 (HIGH): No authorization check in `validerpacte.php` - any player can accept/reject any pact

**Files:**
- Modify: `allianceadmin.php` (line 237)
- Modify: `validerpacte.php` (lines 4-16)

**Context:** `allianceadmin.php` handles pact deletion with a SQL query that has operator precedence issues. `validerpacte.php` accepts/rejects pact proposals without checking if the current player is authorized.

**Step 1: Fix SQL operator precedence in pact deletion**

In `allianceadmin.php` line 237, change:
```php
dbExecute($base, 'DELETE FROM declarations WHERE (alliance1=? AND alliance2=?) OR ((alliance2=? AND alliance1=?)) AND type=1', 'iiii', $chef['id'], $allianceAdverse['id'], $chef['id'], $allianceAdverse['id']);
```
to:
```php
dbExecute($base, 'DELETE FROM declarations WHERE ((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?)) AND type=1', 'iiii', $chef['id'], $allianceAdverse['id'], $chef['id'], $allianceAdverse['id']);
```

The outer parentheses ensure `AND type=1` applies to both OR clauses.

**Step 2: Add authorization to validerpacte.php**

In `validerpacte.php`, after checking if the declaration exists, verify the current player is the chef of the target alliance. The `declarations` table has `alliance1` and `alliance2` columns. The pact was proposed by alliance1 to alliance2, so alliance2's chef should accept/reject.

Add after line 8:
```php
// Verify the current player is authorized to accept/reject this pact
$declaration = dbFetchOne($base, 'SELECT alliance2 FROM declarations WHERE id=? AND valide=0', 'i', $_POST['idDeclaration']);
if ($declaration) {
    $targetAlliance = dbFetchOne($base, 'SELECT chef FROM alliances WHERE id=?', 'i', $declaration['alliance2']);
    if (!$targetAlliance || $targetAlliance['chef'] !== $_SESSION['login']) {
        header('Location: rapports.php');
        exit();
    }
}
```

**Step 3: Commit**

```bash
git add allianceadmin.php validerpacte.php
git commit -m "fix: SQL precedence in pact deletion and add authorization to validerpacte"
```

---

### Task 5: Fix Economy & Market System (MEDIUM + LOW)

**Bugs addressed:**
- BUG #7 (MEDIUM): Market purchases don't check storage capacity
- BUG #14 (LOW): `update.php` uses hardcoded `500 * depot` instead of `placeDepot()` function
- BUG #6 (LOW): Energy can go negative before clamping in resource updates

**Files:**
- Modify: `marche.php` (lines 159-169, 224-233)
- Modify: `includes/update.php` (lines 28-29, 39-40)

**Context:** The market allows buying/selling resources without checking storage limits. The update.php file hardcodes `500 * depot` for storage cap instead of using the `placeDepot()` function.

**Step 1: Add storage check to market purchases**

In `marche.php`, after calculating the resource amount to buy (around line 162), add a check:
```php
$placeDepotJoueur = placeDepot($constructions['depot']);
if ($newResVal > $placeDepotJoueur) {
    $erreur = "Vous n'avez pas assez de place dans votre stockage.";
} else {
    // ... proceed with purchase
}
```

Similarly for selling, energy gains should be capped at storage.

**Step 2: Fix hardcoded storage cap in update.php**

In `includes/update.php`, replace:
- Line 28: `if ($energie >= (500 * $depotAdverse['depot']))` with `if ($energie >= placeDepot($depotAdverse['depot']))`
- Line 29: `$energie = (500 * $depotAdverse['depot'])` with `$energie = placeDepot($depotAdverse['depot'])`
- Line 39: `if ($$ressource >= (500 * $depotAdverse['depot']))` with `if ($$ressource >= placeDepot($depotAdverse['depot']))`
- Line 40: `$$ressource = (500 * $depotAdverse['depot'])` with `$$ressource = placeDepot($depotAdverse['depot'])`

**Step 3: Commit**

```bash
git add marche.php includes/update.php
git commit -m "fix: add storage limit checks to market and use placeDepot() consistently"
```

---

### Task 6: Fix Infrastructure & Cleanup (MEDIUM + LOW)

**Bugs addressed:**
- BUG #12 (MEDIUM): `supprimerJoueur()` doesn't clean up `actionsattaques`, `actionsformation`, `actionsenvoi`
- BUG #13 (MEDIUM): Infinite loop risk in `coordonneesAleatoires()`
- BUG #15 (MEDIUM): Recursive `updateActions()` risk with mutual attacks
- BUG #16 (LOW): `$nbRes` naming confusion (sizeof-1 vs sizeof)

**Files:**
- Modify: `includes/player.php` (supprimerJoueur, coordonneesAleatoires)
- Modify: `includes/game_actions.php` (updateActions)

**Context:** Various infrastructure issues that could cause data leaks, infinite loops, or stack overflows.

**Step 1: Complete supprimerJoueur cleanup**

In `includes/player.php`, add to `supprimerJoueur()` function after line 658:
```php
dbExecute($base, 'DELETE FROM actionsattaques WHERE attaquant=? OR defenseur=?', 'ss', $joueur, $joueur);
dbExecute($base, 'DELETE FROM actionsformation WHERE login=?', 's', $joueur);
dbExecute($base, 'DELETE FROM actionsenvoi WHERE envoyeur=? OR receveur=?', 'ss', $joueur, $joueur);
dbExecute($base, 'DELETE FROM statutforum WHERE login=?', 's', $joueur);
dbExecute($base, 'DELETE FROM vacances WHERE idJoueur IN (SELECT id FROM membre WHERE login=?)', 's', $joueur);
```

**Step 2: Add loop safety to coordonneesAleatoires**

In `includes/player.php`, add a max iteration counter to the while loops (lines 562, 569):
```php
$maxAttempts = 100;
$attempts = 0;
while ($carte[$x][$y] != 0 && $attempts < $maxAttempts) {
    $x = mt_rand(0, $inscrits['tailleCarte'] - 1);
    $attempts++;
}
if ($attempts >= $maxAttempts) {
    // Force expand map
    $inscrits['tailleCarte'] += 1;
    $x = $inscrits['tailleCarte'] - 1;
    $y = 0;
}
```

**Step 3: Add recursion guard to updateActions**

In `includes/game_actions.php`, add a static recursion guard at the top of `updateActions()`:
```php
static $updating = [];
if (isset($updating[$joueur])) {
    return; // Prevent infinite recursion
}
$updating[$joueur] = true;
// ... existing code ...
unset($updating[$joueur]);
```

**Step 4: Commit**

```bash
git add includes/player.php includes/game_actions.php
git commit -m "fix: complete player cleanup, prevent infinite loops, and add recursion guard"
```

---

### Task 7: Final Review & Deploy

**Step 1: Review all changes**

Verify all 18 bugs are addressed. Run a quick syntax check on all modified files.

**Step 2: Push to GitHub**

```bash
git push origin main
```

**Step 3: Deploy to VPS**

SSH to VPS and pull latest changes.
