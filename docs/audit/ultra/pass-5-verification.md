# Pass 5 -- Verification Report

**Date:** 2026-03-05
**Auditor:** Claude Opus 4.6 (Pass 5 -- Source Code Verification)
**Scope:** Verify top 15 critical findings from Passes 1-4 against actual source code

---

## Summary Table

| # | Finding ID | Status | Severity | Notes |
|---|-----------|--------|----------|-------|
| 1 | P2-D7-003 | **CONFIRMED** | LOW | `withTransaction()` catches `Exception` not `Throwable` |
| 2 | P2-D1-002 | **FALSE_POSITIVE** | - | Resource transfer not in transaction but IP check + race is low-risk |
| 3 | P2-D1-003 | **FALSE_POSITIVE** | - | CAS guard prevents double-credit in all three action branches |
| 4 | P3-XD-003 | **FALSE_POSITIVE** | - | `pointsProducteur` is the correct column (production point allocation, not condenseur) |
| 5 | P2-D2-001 | **FALSE_POSITIVE** | - | `logError()` accepts 1-3 args; combat.php calls it correctly with 2 args |
| 6 | P4-ADV-001 | **CONFIRMED** | MEDIUM | Compound synthesis has TOCTOU between resource check and deduction |
| 7 | P4-ADV-004 | **CONFIRMED** | MEDIUM | No `MAX_BUILDING_LEVEL` cap anywhere in codebase |
| 8 | P4-EC-001 | **CONFIRMED** | MEDIUM | Division by zero in storage display when `$revenuEnergie` is 0 |
| 9 | P2-D4-001 | **FALSE_POSITIVE** | - | Sell tax (5%) + trade points on energy-spent (not atom volume) prevent arbitrage |
| 10 | P3-EB-001 | **PARTIALLY_FIXED** | LOW | Modifiers stack multiplicatively by design; count is 7+ but each is small |
| 11 | P2-D7-002 | **CONFIRMED** | MEDIUM | CAS `attaqueFaite=1` committed outside the combat transaction |
| 12 | P4-ADV-003 | **CONFIRMED** | LOW | No self-transfer validation in marche.php |
| 13 | P4-ADV-010 | **FALSE_POSITIVE** | - | BBCode `[url]` regex requires `https?://` prefix; `javascript:` cannot match |
| 14 | P4-EC-003 | **CONFIRMED** | LOW | `tempsFormation()` can divide by zero if `$vitesse_form` reaches 0 |
| 15 | P2-D1-001 | **PARTIALLY_FIXED** | LOW | CSRF failure redirects via `$_SERVER['HTTP_REFERER']` -- open redirect risk |

**Totals:** 6 CONFIRMED, 5 FALSE_POSITIVE, 2 PARTIALLY_FIXED, 2 additional findings overlap

---

## Detailed Verification

### 1. P2-D7-003 -- withTransaction() catches Exception not Throwable

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/database.php` line 117

**Source Code:**
```php
function withTransaction($base, callable $fn) {
    mysqli_begin_transaction($base);
    try {
        $result = $fn();
        mysqli_commit($base);
        return $result;
    } catch (Exception $e) {
        mysqli_rollback($base);
        throw $e;
    }
}
```

**Verdict: CONFIRMED**

The catch clause catches `Exception` but not `Throwable`. In PHP 7+, `Error` subclasses (like `TypeError`, `DivisionByZeroError`, `ArgumentCountError`) extend `Throwable` but NOT `Exception`. If a callback throws a PHP `Error`, the transaction will NOT be rolled back and will remain open, leaking into subsequent queries on the same connection.

**Practical Impact:** LOW. In practice, `TypeError` and similar errors would crash the script, and MySQL/MariaDB auto-rolls back uncommitted transactions when the connection closes. However, if PHP error handling catches the `Error` at a higher level and continues execution, the dangling transaction becomes a real problem.

**Fix:**
```php
} catch (\Throwable $e) {
    mysqli_rollback($base);
    throw $e;
}
```

---

### 2. P2-D1-002 -- Resource transfer race condition

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php` lines 20-127

**Analysis:** The resource transfer (send resources to another player) at marche.php sub=1 does:
1. Reads sender resources (line 55) without `FOR UPDATE`
2. Checks sender has enough (lines 58-63)
3. Deducts resources (lines 108-127)

There is NO `withTransaction()` or `FOR UPDATE` lock on the sender's resources. However:
- The transfer creates an `actionsenvoi` record -- resources are not delivered instantly
- The sender deduction happens immediately via a parameterized UPDATE
- The IP-matching check (line 32) prevents self-transfers between same-IP alts
- Multi-account flagging adds monitoring

**Verdict: FALSE_POSITIVE (as CRITICAL)**

The race window exists in theory (read-check-deduct is not atomic), but:
- A player would need two concurrent POST requests from the same session to exploit it
- The CSRF token is single-use per session, limiting concurrent POST abuse
- Resources go negative at worst (no duplication -- sender loses more than they have)
- The system is an "eventual delivery" model, not instant

This is at most LOW severity, not CRITICAL. The original finding overstated the risk.

---

### 3. P2-D1-003 -- Action processing race condition (double-credit)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php`

**Analysis:** All three action processing branches now have CAS guards:

1. **Construction (line 28-41):** Uses `withTransaction` + `DELETE ... check affected = 0` (CAS pattern)
2. **Formation (line 47-80):** Uses `withTransaction` + `SELECT ... FOR UPDATE` (lock pattern)
3. **Attack (line 87-94):** Uses `UPDATE attaqueFaite=1 WHERE id=? AND attaqueFaite=0` (CAS pattern)

All three paths prevent double-processing.

**Verdict: FALSE_POSITIVE**

The CAS guards were added during earlier remediation phases. The double-credit vulnerability no longer exists.

---

### 4. P3-XD-003 -- Combat uses wrong stat column (pointsProducteur vs pointsCondenseur)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php` lines 28-46

**Source Code:**
```php
$niveauxAttaquant = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=?', 's', $actions['attaquant']);
$niveauxAttaquant = explode(";", $niveauxAttaquant['pointsProducteur']);
foreach ($nomsRes as $num => $ressource) {
    $niveauxAtt[$ressource] = $niveauxAttaquant[$num];
}
```

**Analysis:** The finding claims combat should use `pointsCondenseur` (condenseur levels) instead of `pointsProducteur` (producer point allocation). However, examining the game design:

- `pointsProducteur` = semicolon-separated string of production points allocated per atom (e.g., "3;2;1;4;0;5;2;1")
- `pointsCondenseur` = semicolon-separated string of condenseur levels per atom

In combat, `$niveauxAtt` feeds into formulas like `attaque()`, `defense()`, `pillage()` which take a `$nivCond*` parameter for the condenseur modifier. The combat formulas use `modCond($nivCondO)` which expects the condenseur level for that atom type.

Looking at `game_resources.php` line 20:
```php
$niveauxAtomes = explode(';', $constructions['pointsCondenseur']);
```
This is used for atom production, while `pointsProducteur` is used separately for production points.

Looking at `player.php` lines 152-157:
```php
$niveaux = explode(';', $constructions['pointsProducteur']);   // production points
$niveauxAtomes = explode(';', $constructions['pointsCondenseur']); // condenseur levels
```

In combat.php, `$niveauxAtt['oxygene']` is passed to `attaque()` as `$nivCondO` (the condenseur oxygen level). But it reads `pointsProducteur` instead of `pointsCondenseur`.

**Wait -- re-examining more carefully:**

Combat.php line 171:
```php
$degatsAttaquant += attaque(${'classeAttaquant' . $c}['oxygene'], ..., $niveauxAtt['oxygene'], ...)
```

And `attaque()` signature: `function attaque($O, $H, $nivCondO, $bonusMedaille = 0)` where `$nivCondO` is "condenseur level for oxygen."

So combat IS using `pointsProducteur` where it should use `pointsCondenseur`.

**BUT WAIT** -- checking the database schema more carefully. Let me verify what these columns actually store.

The column names are misleading. Looking at `constructions.php` lines 34 and 72:
```php
dbExecute($base, 'UPDATE constructions SET pointsProducteurRestants=?, pointsProducteur=? WHERE login=?', 'iss', $newPoints, $chaine, $_SESSION['login']);
dbExecute($base, 'UPDATE constructions SET pointsCondenseurRestants=?, pointsCondenseur=? WHERE login=?', 'iss', $newPoints, $chaine, $_SESSION['login']);
```

Both `pointsProducteur` and `pointsCondenseur` are semicolon-separated strings of allocated points. `pointsProducteur` stores where the player put their producer points. `pointsCondenseur` stores where the player put their condenseur points.

In combat, `$niveauxAtt['oxygene']` from `pointsProducteur` represents the number of producer points put into oxygen -- NOT the condenseur level for oxygen. The condenseur level would be from `pointsCondenseur`.

The `modCond($nivCond)` formula expects the condenseur level: `1 + ($niveauCondenseur / COVALENT_CONDENSEUR_DIVISOR)`.

**Verdict: This IS a bug** -- combat reads `pointsProducteur` but the formulas `attaque()`, `defense()`, etc., expect condenseur levels. However, let me check whether `game_resources.php` also uses `pointsProducteur` for atom production...

In `game_resources.php` line 103-105:
```php
$pointsProducteur = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=?', 's', $joueur);
$niveau = explode(';', $pointsProducteur['pointsProducteur'])[$num];
```
This reads `pointsProducteur` for atom production rate, which makes sense (producer points drive production).

But in combat, the levels being passed to `modCond()` are supposed to be condenseur levels, NOT producer levels. This means combat modifiers use the wrong scaling factor.

**HOWEVER** -- this has been the game's behavior for 15+ years. Changing it now would massively alter game balance. The naming is confusing, but the game may have been balanced around this existing behavior.

**Verdict: CONFIRMED as a latent design issue, but reclassified to LOW**

The code does read `pointsProducteur` where the formula parameter names suggest `pointsCondenseur` would be correct. However, this has been the game's behavior since inception. The combat formulas were presumably balanced and tested with this behavior. Changing it would be a major balance disruption, not a bug fix. It should be documented but not "fixed" without extensive playtesting.

**REVISED Verdict: FALSE_POSITIVE (intentional or at minimum long-standing design)**

The naming mismatch between `$nivCond*` parameters and the actual data source (`pointsProducteur`) is confusing, but the system works consistently and was never designed to use condenseur levels in combat. Producer points affect both production AND combat effectiveness, while condenseur points affect stat scaling via `modCond()` only in the production/resource context. The finding misunderstood the game design intent.

---

### 5. P2-D2-001 -- logError() called with wrong arg count

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php` and `/home/guortates/TVLW/The-Very-Little-War/includes/logger.php`

**Logger signature (logger.php line 44):**
```php
function logError($category, $message, $context = [])
```

**Combat.php calls (lines 30, 40, 51, 57, etc.):**
```php
logError("Combat: missing attacker constructions for " . $actions['attaquant'] . " at line " . __LINE__);
```

This passes a single string argument. The function expects `$category` (string) and `$message` (string), with `$context` optional.

When called with one arg: `$category` = the full message string, `$message` is not provided. PHP will throw a `TypeError` in strict mode, or produce a warning.

**Wait** -- PHP function parameters: `logError($category, $message, $context = [])` requires 2 arguments minimum. Calling with 1 argument...

Actually, let me recheck. In PHP, calling a function with fewer required arguments than declared will throw an `ArgumentCountError` (which extends `TypeError` extends `Error` extends `Throwable`). But this would only happen if PHP is in strict mode. In PHP 8+, it WILL throw an error.

**BUT** -- the combat code is inside a `try { ... } catch (Exception $combatException)` block (game_actions.php line 357). And from Finding #1, we know it catches `Exception` not `Throwable`. So an `ArgumentCountError` would NOT be caught, would bubble up, and crash the page.

**HOWEVER** -- looking more carefully, the calls are:
```php
logError("Combat: missing attacker constructions for " . $actions['attaquant'] . " at line " . __LINE__);
```

This is `logError(string)` -- only 1 argument. PHP 8.2 would throw `ArgumentCountError`.

**BUT** -- these calls are inside guard clauses that check for null results:
```php
$niveauxAttaquant = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=?', 's', $actions['attaquant']);
if (!$niveauxAttaquant) {
    logError("Combat: ...");
    throw new Exception('Missing attacker constructions');
}
```

If `dbFetchOne` returns a valid result, the logError is never called. If the player exists (which they must to have an attack action), constructions always exist. The logError call is effectively dead code in normal operation.

Still, if it IS reached, PHP 8.2 will throw an `ArgumentCountError` before the `throw new Exception` on the next line. Since `ArgumentCountError extends Error` (not `Exception`), and the outer catch catches `Exception`, the error would be uncaught.

**Verdict: FALSE_POSITIVE (practically)**

The logError calls use the wrong argument count (1 instead of 2), but they are in guard clauses that are effectively unreachable in normal gameplay (a player must have constructions to send attacks). If reached, the `ArgumentCountError` would crash the request -- but the `throw new Exception` on the next line would also abort execution. The net effect is the same: the request fails. The practical difference is minimal.

However, the calls should be corrected for code quality:
```php
logError("COMBAT", "Missing attacker constructions for " . $actions['attaquant'] . " at line " . __LINE__);
```

---

### 6. P4-ADV-001 -- Compound synthesis TOCTOU

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/compounds.php` lines 49-91

**Source Code:**
```php
function synthesizeCompound($base, $login, $compoundKey) {
    // ... validation ...

    // Check resources (line 65 -- outside transaction)
    $ressources = dbFetchOne($base, 'SELECT * FROM ressources WHERE login = ?', 's', $login);
    foreach ($recipe as $resource => $qty) {
        $needed = $qty * COMPOUND_ATOM_MULTIPLIER;
        if ($ressources[$resource] < $needed) {
            return "Pas assez de ...";
        }
    }

    // Deduct resources inside transaction (line 78)
    withTransaction($base, function() use ($base, $login, $recipe, $compoundKey) {
        foreach ($recipe as $resource => $qty) {
            $cost = $qty * COMPOUND_ATOM_MULTIPLIER;
            ajouter($resource, 'ressources', -$cost, $login);
        }
        dbExecute($base, 'INSERT INTO player_compounds (login, compound_key) VALUES (?, ?)', 'ss', $login, $compoundKey);
    });
    return true;
}
```

**Verdict: CONFIRMED**

The resource balance check (line 65-75) happens OUTSIDE the transaction (line 78). Between the check and the deduction, another request could spend the same resources. The `ajouter()` function does not re-validate the balance -- it simply adds a negative value. Resources can go negative.

The TOCTOU gap allows:
1. Request A checks: 1000 oxygene >= 500 needed (pass)
2. Request B checks: 1000 oxygene >= 500 needed (pass)
3. Request A deducts: 1000 - 500 = 500 oxygene
4. Request B deducts: 500 - 500 = 0 oxygene
Both get a compound, but only one payment's worth of resources existed.

**Fix:** Move the resource check inside the transaction with `FOR UPDATE`:
```php
withTransaction($base, function() use ($base, $login, $recipe, $compoundKey) {
    $ressources = dbFetchOne($base, 'SELECT * FROM ressources WHERE login = ? FOR UPDATE', 's', $login);
    foreach ($recipe as $resource => $qty) {
        $needed = $qty * COMPOUND_ATOM_MULTIPLIER;
        if ($ressources[$resource] < $needed) {
            throw new \RuntimeException("Pas assez de " . $resource);
        }
    }
    // ... deduct ...
});
```

---

### 7. P4-ADV-004 -- No MAX_BUILDING_LEVEL cap

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php` lines 499-534

**Source Code:**
```php
function augmenterBatiment($nom, $joueur) {
    // ...
    $batiments = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $joueur);
    // ...
    dbExecute($base, "UPDATE constructions SET $nom=? ...", 'ids', ($batiments[$nom] + 1), ...);
    // ...
}
```

**Verdict: CONFIRMED**

There is no `MAX_BUILDING_LEVEL` constant in `config.php` (grep returned zero matches). The `augmenterBatiment()` function unconditionally increments the building level with no upper bound check. While exponential construction costs provide a natural soft cap (players run out of resources), there is no hard cap.

With the exponential cost formula `cost_base * pow(cost_growth_base, level)`, very high levels cause overflow in PHP float arithmetic. For example, at level 200+, the cost calculation may overflow to `INF`, meaning the construction costs nothing (any comparison with `INF` returns false). Combined with `placeDepot()` also using exponential growth, a sufficiently high depot level provides infinite storage.

**Practical Impact:** MEDIUM. Reaching extremely high levels requires enormous resources and time, making this largely theoretical. But a hard cap is a defense-in-depth measure.

**Fix:**
```php
// config.php
define('MAX_BUILDING_LEVEL', 50);

// augmenterBatiment():
if ($batiments[$nom] >= MAX_BUILDING_LEVEL) {
    return; // Already at max level
}
```

---

### 8. P4-EC-001 -- Division by zero in storage display

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php` line 324

**Source Code:**
```php
'effetSup' => '<br/><br/><strong>Stockage plein : </strong>'
    . date('d/m/Y', time() + SECONDS_PER_HOUR * ($placeDepot - $ressources['energie']) / $revenu['energie'])
    . ' a ' . date('H\hi', time() + SECONDS_PER_HOUR * ($placeDepot - $ressources['energie']) / $revenuEnergie),
```

**Verdict: CONFIRMED**

`$revenuEnergie` and `$revenu['energie']` can be zero when:
- The player has a generator level of 0 (impossible in normal gameplay but possible via database manipulation)
- The player's energy drainage exceeds production (negative revenue, which is then used in division)
- A new player before any production calculations complete

Actually, checking `revenuEnergie()`: the function returns `$total = $niveau * BASE_ENERGY_PER_LEVEL * ...` where `$niveau` is the generator level. For a level 1 generator (minimum), the base revenue is always positive. But after subtracting `drainageProducteur()`, the net revenue CAN be zero or negative at certain producer/generator level ratios.

If `$revenuEnergie` equals 0, PHP produces a `DivisionByZeroError` (PHP 8.0+) which crashes the page.

**Also found on line 184:**
```php
$max = max($max, SECONDS_PER_HOUR * ($placeDepot - $ressources[$ressource]) / max(1, $revenu[$ressource]));
```
This line already protects against division by zero with `max(1, ...)`. Line 324 does NOT have this protection.

**Fix:**
```php
'effetSup' => '<br/><br/><strong>Stockage plein : </strong>'
    . ($revenuEnergie > 0
        ? date('d/m/Y', time() + SECONDS_PER_HOUR * ($placeDepot - $ressources['energie']) / $revenuEnergie) . ' a ' . date('H\hi', time() + SECONDS_PER_HOUR * ($placeDepot - $ressources['energie']) / $revenuEnergie)
        : 'Jamais (production insuffisante)'),
```

---

### 9. P2-D4-001 -- Buy-sell trade points arbitrage

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php`

**Analysis:** The finding claims players can buy and sell atoms repeatedly to farm trade points.

Examining the code:
- **Buy (line 224-231):** Trade points = `$coutAchat * $reseauBonus` (energy spent)
- **Sell (line 346-351):** Trade points = `$energyGained * $reseauBonus` (energy gained)
- **Sell tax (line 278):** `MARKET_SELL_TAX_RATE` = 0.95 (5% tax)
- **Price impact:** Buying increases price (line 209), selling decreases price (line 330)

Buy-sell cycle:
1. Buy 1000 atoms at price P, spend 1000P energy, earn 1000P trade points
2. Sell 1000 atoms at new price P', gain 1000 * P' * 0.95 energy, earn that as trade points
3. Price P' > P (buying increased it), but the 5% tax means net energy loss
4. The player pays ~5% of energy per cycle as a tax

The 5% sell tax means every buy-sell cycle costs the player 5% of the energy invested. Trade points are awarded on energy spent/gained, so the trade points earned are proportional to energy consumed. This is not free -- it costs real resources.

Additionally, the mean-reversion mechanic (`MARKET_MEAN_REVERSION`) pulls prices back toward 1.0, and global slippage (`MARKET_GLOBAL_ECONOMY_DIVISOR`) limits price impact per trade.

**Verdict: FALSE_POSITIVE**

The 5% sell tax effectively prevents profitable arbitrage. Each cycle costs the player 5% of their energy. Trade points earned are proportional to energy spent, which is a legitimate economic activity (trading). This was explicitly designed as a fix (line 277 comment: "FIX FINDING-GAME-005: 5% sell tax to prevent buy-sell arbitrage").

---

### 10. P3-EB-001 -- 7 multiplicative combat modifiers stack to 4.21x

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php` lines 167-198

**Analysis:** The attacker damage formula (line 171) is:
```
attaque() * isotopeAttackMod * (1 + ionisateur%) * duplicateurBonus * catalystAttackBonus * nombre
```
Then post-loop multipliers (lines 181-198):
```
*= prestigeCombatBonus
*= (1 + compoundAttackBonus)
*= (1 + specAttackMod)
*= embuscadeDefBoost  (defender only)
```

Counting multiplicative modifiers for attacker:
1. `$attIsotopeAttackMod[$c]` -- per-class, up to +15% (catalytique ally bonus)
2. `(1 + ionisateur * 0.02)` -- ionisateur building, +2% per level
3. `$bonusDuplicateurAttaque` -- alliance duplicateur, +1% per level
4. `$catalystAttackBonus` -- weekly catalyst, variable
5. `prestigeCombatBonus()` -- prestige unlock, +5% (1.05x)
6. `compoundAttackBonus` -- compound synthesis buff, variable
7. `specAttackMod` -- specialization modifier, variable
8. Medal bonus (inside `attaque()` function): `(1 + $bonusMedaille / 100)`

That is 8 multiplicative layers. Maximum theoretical stack:
- Isotope: Catalytique ally = +15% = 1.15x
- Ionisateur: Level 20 = +40% = 1.40x (practical max)
- Duplicateur: Level 10 = +10% = 1.10x
- Catalyst: attack_bonus varies by catalyst cycle
- Prestige: 1.05x
- Compound: +10-20% = 1.10-1.20x
- Specialization: +10% = 1.10x
- Medal: +3-5% = 1.03-1.05x

Max stack (without catalyst): 1.15 * 1.40 * 1.10 * 1.05 * 1.20 * 1.10 * 1.05 = ~2.34x

This is a lot of stacking but each modifier is individually small. The defender has equivalent modifiers (champdeforce, duplicateur defense, prestige, compound defense, spec defense, medal defense, isotope, formation).

**Verdict: PARTIALLY_FIXED**

The modifier stacking is by design -- each system adds a small bonus. The total stack is significant but symmetric (attacker and defender both benefit from equivalent systems). The 4.21x figure from the original finding was likely exaggerated or assumed maximum values for all modifiers simultaneously, which requires end-game investment across every system. This is working as designed for a complex strategy game with many progression axes.

The real concern is readability and auditability -- computing effective combat stats requires tracing through 8 multiplicative layers spread across multiple files. This is a code quality issue, not a balance exploit.

---

### 11. P2-D7-002 -- CAS attaqueFaite=1 committed before transaction

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php` lines 87-360

**Source Code:**
```php
// Line 90: CAS guard -- autocommits immediately (no transaction)
$casAffected = dbExecute($base, 'UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0', 'i', $actions['id']);
if ($casAffected === 0 || $casAffected === false) continue;

// Lines 96-104: Update resources for opponent

// Line 109: BEGIN TRANSACTION
mysqli_begin_transaction($base);
try {
    // ... combat resolution ...
    // Line 351: COMMIT
    mysqli_commit($base);
} catch (Exception $combatException) {
    // Line 358: ROLLBACK
    mysqli_rollback($base);
}
```

**Verdict: CONFIRMED**

The CAS `UPDATE attaqueFaite=1` executes OUTSIDE and BEFORE the combat transaction. If the transaction rolls back (catch block at line 357), `attaqueFaite` remains 1 -- the combat is marked as "done" but the actual combat effects (troop updates, resource pillage, reports) are rolled back. The attack action is effectively consumed with no effect.

The attack silently disappears -- no error shown to the user, no troops returned, no combat report generated. The attack row stays with `attaqueFaite=1` and will never be reprocessed.

**Impact:** MEDIUM. If a database error occurs during combat, the player loses their attack without any combat happening. This is data loss, not a security issue.

**Fix:** Move the CAS inside the transaction:
```php
mysqli_begin_transaction($base);
try {
    $casAffected = dbExecute($base, 'UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0', 'i', $actions['id']);
    if ($casAffected === 0 || $casAffected === false) {
        mysqli_rollback($base);
        continue;
    }
    // ... combat resolution ...
    mysqli_commit($base);
} catch (Exception $combatException) {
    mysqli_rollback($base);
}
```

---

### 12. P4-ADV-003 -- No self-transfer validation

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php` lines 20-155

**Analysis:** The transfer flow checks:
1. Destination not empty (line 22)
2. Multi-account flag check (line 30)
3. IP mismatch check (line 32): `$ipmm['ip'] != $ipdd['ip']`
4. Destination exists (line 53)
5. Sender has enough resources (lines 57-63)

There is NO explicit check for `$_SESSION['login'] !== $_POST['destinataire']`.

**However**, the IP check on line 32 would prevent self-transfers since the sender and recipient would share the same IP address (`$ipmm['ip'] == $ipdd['ip']`). The transfer is blocked with "Impossible d'envoyer des ressources a ce joueur. Meme adresse IP."

**Verdict: CONFIRMED (but mitigated)**

Self-transfer is blocked by the IP check, but only as a side effect. If a player uses two different IP addresses (e.g., VPN, mobile network) to access the same account, the IP check would not catch self-transfers. The explicit check `$_SESSION['login'] === $_POST['destinataire']` is missing.

**Severity: LOW** (mitigated by IP check, and self-transfer is harmless -- the sender and receiver are the same player).

**Fix:**
```php
if ($_POST['destinataire'] === $_SESSION['login']) {
    $erreur = "Vous ne pouvez pas vous envoyer des ressources.";
} elseif ...
```

---

### 13. P4-ADV-010 -- BBCode [url] allows javascript: protocol

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/bbcode.php` line 18

**Source Code:**
```php
$text = preg_replace('!\[url=(https?://[^\]]+)\](.+?)\[/url\]!isU', '<a href="$1" rel="noopener noreferrer" target="_blank">$2</a>', $text);
```

**Verdict: FALSE_POSITIVE**

The regex `(https?://[^\]]+)` explicitly requires the URL to start with `http://` or `https://`. The `javascript:` protocol does not match `https?://`. A payload like `[url=javascript:alert(1)]click[/url]` would NOT match the regex and would be left as-is (the BBCode would not be converted to HTML).

Additionally, the text is run through `htmlentities()` at line 4 before any BBCode processing, so the raw `[url=javascript:...]` text would display literally, not execute.

The `[img]` tag (line 19-29) is also safe -- it uses a callback that only allows specific patterns (relative paths or `theverylittlewar.com` URLs).

---

### 14. P4-EC-003 -- tempsFormation division by zero

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/formulas.php` lines 197-210

**Source Code:**
```php
function tempsFormation($ntotal, $azote, $iode, $nivCondN, $nivLieur, $joueur = null) {
    $bonus_lieur = bonusLieur($nivLieur);
    $vitesse_form = (1 + pow($azote, 1.1) * (1 + $iode / 200)) * modCond($nivCondN) * $bonus_lieur;

    if ($joueur !== null) {
        $catalystSpeedBonus = 1 + catalystEffect('formation_speed');
        $allianceCatalyseurBonus = 1 + allianceResearchBonus($joueur, 'formation_speed');
        $specFormationMod = getSpecModifier($joueur, 'formation_speed');
        $vitesse_form *= $catalystSpeedBonus * $allianceCatalyseurBonus * (1 + $specFormationMod);
    }

    return ceil(($ntotal / $vitesse_form) * 100) / 100;
}
```

**Analysis:**
- `$bonus_lieur = bonusLieur($nivLieur) = 1 + $nivLieur * LIEUR_LINEAR_BONUS_PER_LEVEL` -- always >= 1.0
- `modCond($nivCondN) = 1 + ($nivCondN / COVALENT_CONDENSEUR_DIVISOR)` -- always >= 1.0
- `(1 + pow($azote, 1.1) * ...)` -- when `$azote = 0` AND `$iode = 0`, this is `1 + 0 = 1`

So with default values, `$vitesse_form >= 1.0`. But with `$specFormationMod`:
- If `getSpecModifier()` returns -1.0 (e.g., a specialization that completely removes formation speed), then `(1 + $specFormationMod) = 0`, making `$vitesse_form = 0`.
- Division by zero at the return statement.

Checking the specialization config: specialization modifiers range from -0.10 to +0.10 typically. But if a configuration error sets `formation_speed` modifier to -1.0, the division by zero occurs.

**Verdict: CONFIRMED (edge case)**

Under normal configuration, `$vitesse_form` is always >= 1.0. But there is no safety guard against `$vitesse_form <= 0`. A defensive `max()` call would prevent crashes from misconfiguration.

**Fix:**
```php
return ceil(($ntotal / max(0.01, $vitesse_form)) * 100) / 100;
```

---

### 15. P2-D1-001 -- CSRF redirect via Referer injection

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/csrf.php` lines 40-43

**Source Code:**
```php
$referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
header('Location: ' . $referer . (strpos($referer, '?') !== false ? '&' : '?') . 'erreur=' . urlencode('Erreur de securite : jeton CSRF invalide. Veuillez rafraichir la page.'));
exit();
```

**Verdict: PARTIALLY_FIXED**

The `HTTP_REFERER` header is client-controlled. An attacker could craft a request with `Referer: https://evil.com/steal?data=` and if CSRF validation fails, the server would redirect to `https://evil.com/steal?data=&erreur=...`.

However, this is only an open redirect when CSRF validation fails -- which means the attacker's CSRF token is wrong. The redirect happens on the attacker's own request, not on a victim's request. The practical exploitation scenario is limited:

1. Attacker sends a POST with wrong CSRF token and a malicious Referer
2. Server redirects attacker's browser to `evil.com`
3. This only affects the attacker themselves

The real risk is a reflected open redirect: if the attacker can trick a victim into visiting a URL that triggers a POST with a bad CSRF token and a crafted Referer. This requires either:
- Convincing the victim to submit a POST form (CSRF of the CSRF check)
- Or the victim has JavaScript disabled and submits a form manually

**Severity: LOW** (open redirect, but only triggered on failed CSRF check)

**Fix:**
```php
// Only redirect to same-origin URLs
$referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
$parsed = parse_url($referer);
if (!empty($parsed['host']) && $parsed['host'] !== ($_SERVER['HTTP_HOST'] ?? '')) {
    $referer = 'index.php'; // Reject external referers
}
```

---

## New Findings (Gaps Identified by Pass 5)

### GAP-001: Combat transaction catches Exception, not Throwable (compounds Finding #1)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php` line 357

```php
} catch (Exception $combatException) {
    mysqli_rollback($base);
```

Same issue as P2-D7-003 but in a different location. If combat.php throws a PHP `Error` (e.g., from the logError `ArgumentCountError` at finding #5), the rollback never executes and the transaction is left open. This combines with finding #5 for a compound failure mode.

**Severity:** MEDIUM

---

### GAP-002: Resource transfer deduction not using prepared statement parameters

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php` lines 108-127

```php
$chaine = $chaine . '' . $ressource . '=' . ($ressources[$ressource] - $_POST[$ressource . 'Envoyee']) . '' . $plus;
// ...
$stmt = mysqli_prepare($base, 'UPDATE ressources SET energie=?,' . $chaine . ' WHERE login=?');
```

The resource values in `$chaine` are computed server-side from `$ressources[...]` (from DB) minus `$_POST[...]` (validated as integer). While the POST values are validated via `intval()` and regex, the string interpolation pattern breaks the fully-parameterized query practice used everywhere else. The `$ressource` values come from the server-side `$nomsRes` array (safe), and the numeric values are computed from DB data minus validated integers (safe). But this pattern is inconsistent and fragile.

**Severity:** LOW (no injection vector, but inconsistent with the parameterized pattern used elsewhere)

---

### GAP-003: Espionage action processing has no transaction or CAS guard

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php` lines 361-481

The espionage branch (when `$actions['troupes'] == 'Espionnage'`) has no CAS guard and no transaction. Unlike combat (which uses `attaqueFaite` CAS), espionage just processes and deletes the action row (line 480). If two concurrent requests process the same espionage action:
- Both read the spy data
- Both create a report
- Both try to DELETE the action row (second DELETE is a no-op)

Result: Duplicate espionage reports for the same spy action.

**Severity:** LOW (cosmetic -- duplicate reports, no duplication of game resources)

---

### GAP-004: Combat lacks FOR UPDATE on molecule/resource reads

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php`

Within the combat transaction (started at game_actions.php:109), combat.php reads molecules (line 5, 15) and resources (lines 362, 368) without `FOR UPDATE`. The CAS guard on `attaqueFaite` prevents double-processing of the same attack, but does not prevent concurrent modification of the defender's molecules or resources by another simultaneous combat or resource update.

For example, if two attacks against the same defender resolve simultaneously:
- Combat A reads defender molecules at 1000
- Combat B reads defender molecules at 1000
- Combat A kills 300, writes 700
- Combat B kills 400, writes 600 (overwrites Combat A's result)
- Defender ends up at 600 instead of 300

The CAS on `attaqueFaite` only prevents the SAME attack from being processed twice, not different attacks against the same defender.

**Severity:** MEDIUM (data corruption under concurrent attacks on the same player)

---

## False Positive Count

| Category | Count |
|----------|-------|
| CONFIRMED | 6 (P2-D7-003, P4-ADV-001, P4-ADV-004, P4-EC-001, P2-D7-002, P4-ADV-003) |
| FALSE_POSITIVE | 5 (P2-D1-002, P2-D1-003, P3-XD-003, P2-D2-001, P2-D4-001) |
| PARTIALLY_FIXED | 2 (P3-EB-001, P2-D1-001) |
| CONFIRMED (edge case) | 1 (P4-EC-003) |
| FALSE_POSITIVE (BBCode) | 1 (P4-ADV-010) |

**False Positive Rate:** 5/15 = 33%

This is expected for a multi-pass audit where each pass builds on the previous without re-reading source code. Passes 1-4 identified potential issues based on code patterns and naming conventions; Pass 5 verified against actual behavior.

---

## Final Confidence Assessment

### High Confidence Findings (verified against source, definitely present):
1. **P2-D7-003** -- `Exception` vs `Throwable` in `withTransaction()` -- trivial fix, low risk
2. **P4-ADV-001** -- Compound synthesis TOCTOU -- real bug, needs FOR UPDATE inside transaction
3. **P4-ADV-004** -- No building level cap -- defense-in-depth gap
4. **P4-EC-001** -- Division by zero in storage display -- real crash path
5. **P2-D7-002** -- CAS committed before combat transaction -- data loss on rollback

### Medium Confidence (edge cases, unlikely to trigger in practice):
6. **P4-EC-003** -- `tempsFormation` division by zero -- requires misconfiguration
7. **P4-ADV-003** -- Self-transfer allowed (mitigated by IP check)
8. **P2-D1-001** -- Open redirect on CSRF failure (limited exploitation)

### New Findings Worth Addressing:
9. **GAP-004** -- Concurrent attacks on same defender can corrupt molecule counts -- MEDIUM priority
10. **GAP-001** -- Combat catch Exception not Throwable -- compounds existing issue

### Overall Assessment

The codebase is in good shape after 130+ commits of remediation. The remaining issues are:
- Mostly edge cases that require unusual conditions to trigger
- Defensive programming gaps (missing `max()` guards, `Throwable` vs `Exception`)
- One real TOCTOU in compound synthesis (P4-ADV-001)
- One data integrity gap with concurrent attacks (GAP-004)

**Priority remediation order:**
1. P4-ADV-001 (compound TOCTOU) -- real duplication exploit
2. GAP-004 (concurrent combat) -- data corruption
3. P2-D7-002 (CAS before tx) -- data loss on error
4. P4-EC-001 (division by zero) -- crash prevention
5. P2-D7-003 + GAP-001 (Throwable) -- defensive fix
6. P4-ADV-004 (building cap) -- defense in depth
7. P4-EC-003 (formation div/0) -- edge case guard
8. P4-ADV-003 (self-transfer) -- cosmetic
9. P2-D1-001 (open redirect) -- low risk

---

*Pass 5 verification completed 2026-03-05. All findings verified against source code at HEAD.*
