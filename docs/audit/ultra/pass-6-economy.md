# Pass 6 Economy Audit

## Findings

---

### ECO-P6-001 [HIGH] `tradeVolume` DB column capped at `MARKET_POINTS_MAX` (80) — `TRADE_VOLUME_CAP` (10M) in ranking is dead code

**File:** `marche.php:304` and `marche.php:455`
**Description:**
`tradeVolume` is written to the DB with an atomic cap at `MARKET_POINTS_MAX` (80) immediately after each trade:

```sql
UPDATE autre SET tradeVolume = LEAST(tradeVolume, 80) WHERE login=?
```

Then `recalculerTotalPointsJoueur()` reads `tradeVolume` from the DB and passes it to `calculerTotalPoints()`, which applies `TRADE_VOLUME_CAP` (10,000,000):

```php
$cappedCommerce = min($commerce, TRADE_VOLUME_CAP); // min(80_or_less, 10_000_000) = always 80_or_less
```

The `TRADE_VOLUME_CAP` constant is completely inert — the real DB cap of 80 dominates. This has two problems:

1. **Intent mismatch / dead constant confusion**: `TRADE_VOLUME_CAP` (10M) and `MARKET_POINTS_MAX` (80) are measuring different things (raw volume vs. computed points) but are applied to the same column in incompatible ways. The comment on `TRADE_VOLUME_CAP` says "cap on trade volume", yet the DB column is actually cumulative energy spent, not atoms traded.
2. **Broken two-tier system**: The original design intent was `tradeVolume` = raw energy spent (uncapped at 10M), then `calculerTotalPoints()` caps at 10M before sqrt-ranking. The current code caps the raw column at 80 instead, making ranking trade points max out at `sqrt(80) ≈ 9` total points contribution — far lower than intended.

**Code:**
```php
// marche.php:301-304 (buy path)
$tradeVolumeDelta = round($coutAchat * $reseauBonus);
dbExecute($base, 'UPDATE autre SET tradeVolume = tradeVolume + ? WHERE login=?', 'ds', $tradeVolumeDelta, $_SESSION['login']);
// HIGH-039: Enforce MARKET_POINTS_MAX cap atomically...
dbExecute($base, 'UPDATE autre SET tradeVolume = LEAST(tradeVolume, ?) WHERE login=?', 'ds', MARKET_POINTS_MAX, $_SESSION['login']);
```

**Fix:** The two caps serve different purposes and must be separated. Remove the `MARKET_POINTS_MAX` DB cap from `marche.php` (both buy and sell paths, lines 304 and 455). Let `tradeVolume` accumulate raw energy-spent up to `TRADE_VOLUME_CAP` (10M). `calculerTotalPoints()` already correctly applies `TRADE_VOLUME_CAP` before the sqrt. If a per-trade display cap is desired, apply `MARKET_POINTS_MAX` only in the UI display of "trade points", not on the raw column.

---

### ECO-P6-002 [MEDIUM] Transfer IP-bypass allows same-LAN multi-account transfers when VPN/proxy differs

**File:** `marche.php:34-41`
**Description:**
The same-IP transfer block only compares raw IP strings:

```php
$ipdd = dbFetchOne($base, 'SELECT ip FROM membre WHERE login=?', 's', $_POST['destinataire']);
$ipmm = dbFetchOne($base, 'SELECT ip FROM membre WHERE login=?', 's', $_SESSION['login']);
// ...
} elseif ($ipmm['ip'] != $ipdd['ip']) {   // only passes if IPs differ
```

This means:
- Two accounts created from the same IP can never directly transfer between them (good).
- However, if one account later connects from a different IP (e.g., mobile vs. home WiFi) and the other stays on the original IP, the check passes even if they are the same person — the `areFlaggedAccounts()` multiaccount check is the only safety net for this case and only fires if they were previously flagged.
- More critically, if both players connect via the same NAT (school, office, shared apartment), all transfers are blocked even between legitimate different players.

The bigger economic bug: **the IP check runs outside the transaction** (lines 34-41 are before the `withTransaction` call on line 70). A race window exists where both IPs are read before either player can change their IP mid-request, but the `FOR UPDATE` locks inside the transaction don't protect the IP comparison — the IP used for the block decision is a stale read.

This is a low-exploitability race (IP changes rarely), but the blocking logic is architecturally flawed for NAT environments.

**Code:**
```php
$ipdd = dbFetchOne($base, 'SELECT ip FROM membre WHERE login=?', 's', $_POST['destinataire']);
$ipmm = dbFetchOne($base, 'SELECT ip FROM membre WHERE login=?', 's', $_SESSION['login']);
// ...
} elseif ($ipmm['ip'] != $ipdd['ip']) {
```

**Fix:** Move the IP reads inside the transaction (alongside the `FOR UPDATE` on `ressources`). More importantly, rely on `areFlaggedAccounts()` as the primary multiaccount guard and demote the raw-IP check to an advisory log only, since NAT makes the current binary IP-block both too broad (legitimate same-NAT players blocked) and too narrow (VPN-hopping bypasses it).

---

### ECO-P6-003 [MEDIUM] Recipient storage check in transfer uses partial logic — only blocks if ALL sent resources exceed capacity

**File:** `marche.php:93-111`
**Description:**
The transfer recipient storage check rejects the transfer only if every resource being sent has no room:

```php
if ($sentCount > 0 && $noRoomCount === $sentCount) {
    throw new \RuntimeException('RECIPIENT_STORAGE_FULL');
}
```

This means: if a player sends 8 resource types and 7 of them would overflow the recipient's storage but 1 has room, the transfer proceeds. All 8 resources are then deposited, and the 7 that were already full get clamped at the storage cap — the sender loses atoms but the recipient only receives whatever fit. The sender gets no partial-delivery notification (the success message shows the full sent amounts, not the clamped received amounts).

This is both an economy exploit (sender burns full resources for minimal recipient gain) and a UX issue (silent resource destruction). The `actionsenvoi.ressourcesRecues` is stored with the ratio-scaled amounts, but those are computed from the production-ratio formula, not from available storage space.

**Code:**
```php
$noRoomCount = 0;
$sentCount = 0;
// ...
if ($sentCount > 0 && $noRoomCount === $sentCount) {
    throw new \RuntimeException('RECIPIENT_STORAGE_FULL');
}
```

**Fix:** Change the condition to `$noRoomCount > 0` (block if ANY resource exceeds storage), or calculate per-resource clamped amounts at delivery time (`actionsenvoi.php` resolution) to avoid silently destroying resources.

---

### ECO-P6-004 [MEDIUM] `coefDisparition()` static cache is not invalidated after molecule mutations within the same request

**File:** `includes/formulas.php:217-219`
**Description:**
`coefDisparition()` uses a `static $cache` (per-process, not per-request) keyed by `$joueur . '-' . $classeOuNbTotal . '-' . $type`. This cache is populated on first call and never invalidated.

Within `updateRessources()` in `game_resources.php:259`, molecule decay is computed and written inside a transaction loop. After the loop, the function also calls `coefDisparition()` for neutrino decay (line 279). If any other code path within the same PHP request already populated the cache (e.g., `demiVie()` called from the UI before `updateRessources()`), the cache will serve stale pre-decay atom counts, resulting in incorrect neutrino decay calculations.

More critically: if a compound synthesis or molecule modification occurs earlier in the same request, the cached decay coefficient for that class is wrong for any subsequent decay computation. Since PHP processes are reused (FastCGI, PHP-FPM), the `static $cache` survives across requests within the same worker — however PHP-FPM does not reuse static locals across requests (each request starts fresh) so the cross-request risk is low, but within a single request the stale-cache issue is real.

**Code:**
```php
static $cache = [];
$cacheKey = $joueur . '-' . $classeOuNbTotal . '-' . $type;
if (isset($cache[$cacheKey])) return $cache[$cacheKey];
```

**Fix:** Add a `coefDisparitionInvalidateCache($joueur)` function (similar to `invalidatePlayerCache()`) that clears all keys for a given player. Call it after any molecule write. Alternatively, since the function does DB reads anyway, assess whether the cache provides meaningful benefit compared to the staleness risk and consider removing it for `type=0` calls where the molecule atom counts change.

---

### ECO-P6-005 [MEDIUM] `updateRessources()` energy/atom delta can be negative when `revenuEnergie`/`revenuAtome` return 0 but `drainageProducteur` is positive

**File:** `includes/game_resources.php:211-213`
**Description:**
`revenuEnergie()` calls `max(0, round($prodProducteur))` at line 76, correctly clamping to 0. However, `updateRessources()` computes:

```php
$revenuenergie = revenuEnergie($depot['generateur'], $joueur); // returns max(0, ...)
$energieDelta = $revenuenergie * ($nbsecondes / SECONDS_PER_HOUR);
dbExecute($base, 'UPDATE ressources SET energie = LEAST(GREATEST(0, energie + ?), ?) WHERE login=?', 'dds', $energieDelta, $placeMax, $joueur);
```

`revenuEnergie()` returns `max(0, ...)` so `$energieDelta` is always `>= 0`. Energy cannot go negative here. This part is safe.

However, `revenuAtome()` at line 128 (game_resources.php) computes:

```php
$result = round($bonusDuplicateur * BASE_ATOMS_PER_POINT * $niveau * prestigeProductionBonus($joueur) * (1 + $nodeBonus) * (1 + $compoundProdBonus) * (1 + $specAtomMod));
```

If `$specAtomMod = -1.0` (a pathological specialization modifier), the product can be negative, and `round()` returns a negative value. The `LEAST/GREATEST` SQL guard handles it for the DB, but the issue is `$delta` can be negative — meaning atoms are actively drained during "production" tick — which is not intended behavior and would be confusing.

Currently the specialization options max at `atom_production: -0.10` so `-10%` is the floor, making this theoretical. But the code has no clamp on `revenuAtome()` return value — if future config adds a stronger negative modifier or a compound applies a negative stack, atoms drain silently.

**Code:**
```php
// game_resources.php:128
$result = round($bonusDuplicateur * BASE_ATOMS_PER_POINT * $niveau * prestigeProductionBonus($joueur) * (1 + $nodeBonus) * (1 + $compoundProdBonus) * (1 + $specAtomMod));
```

**Fix:** Add `$result = max(0, $result)` before caching/returning in `revenuAtome()`, matching the `max(0, ...)` guard already present in `revenuEnergie()`.

---

### ECO-P6-006 [LOW] Market price update loop uses `sizeof($txTabCours)` instead of `count()` and may silently include trailing empty entry

**File:** `marche.php:274-275` and `marche.php:423-424`
**Description:**
The price-update loop checks `$num < sizeof($txTabCours) - 1` to determine whether to append a comma. `$txTabCours` is created by `explode(',', $coursRow['tableauCours'])`. If `tableauCours` is ever stored with a trailing comma (which can happen because the price-building loop at line 288 appends `$fin = ""` only on the last iteration, but earlier passes write directly without a trailing comma — however if the DB value ever gets a trailing comma from a bug or manual edit), `explode(',', ...)` produces a trailing empty string element. This extra element would cause the price array to have 9 entries instead of 8, and the new `$chaine` written to the DB would have 9 values, corrupting future reads.

While `explode` on a well-formed value is safe, the code has no validation that `count($txTabCours) == $nbRes` before using it. A single corrupted DB row would propagate forever.

**Code:**
```php
// marche.php:273-292 (buy path)
foreach ($txTabCours as $num => $cours) {
    if ($num < sizeof($txTabCours) - 1) {
        $fin = ",";
    } else {
        $fin = "";
    }
    // ...
    $chaine = $chaine . $ajout . $fin;
```

**Fix:** Add a guard before the loop: `$txTabCours = array_slice($txTabCours, 0, $nbRes);` to ensure exactly `$nbRes` elements. Also replace `sizeof()` with `count()` (they are equivalent but `count()` is conventional for arrays). Apply the same fix to both the buy path and the sell path.

---

### ECO-P6-007 [LOW] Transfer `ressourcesRecues` stored as raw float string — recipient may receive fractional atoms

**File:** `marche.php:136,144`
**Description:**
The resource transfer "received" amounts stored in `actionsenvoi.ressourcesRecues` are computed as:

```php
$ressourcesRecues = $ressourcesRecues . (${'rapport' . $ressource} * $_POST[$ressource . 'Envoyee']) . ";";
// ...
$ressourcesRecues = $ressourcesRecues . $rapportEnergie * $_POST['energieEnvoyee'];
```

These are raw floating-point multiplications stored as strings without rounding. When `actionsenvoi.php` (the arrival processor) reads these back and applies them as integer resource increments, floating-point values like `0.666666...` or `499.9999...` may either truncate to 0 or apply as unexpected fractional bonuses depending on how the arrival code casts them.

If the arrival code uses `(int)` cast directly on `"499.9999"`, the recipient gets 499 instead of 500 — a systematic 1-atom loss per transfer when the ratio is close to 1.0 but not exact.

**Code:**
```php
$ressourcesRecues = $ressourcesRecues . (${'rapport' . $ressource} * $_POST[$ressource . 'Envoyee']) . ";";
```

**Fix:** Wrap with `round()`: `round(${'rapport' . $ressource} * $_POST[$ressource . 'Envoyee'])` and `round($rapportEnergie * $_POST['energieEnvoyee'])` before string concatenation. This ensures integer-valued amounts are stored in `actionsenvoi`.

---

### ECO-P6-008 [LOW] `diminuerBatiment()` does not check whether downgrade would make `pointsProducteurRestants` or `pointsCondenseurRestants` negative when points were already partially removed by combat

**File:** `includes/player.php:681-724`
**Description:**
`diminuerBatiment()` checks `$batiments[$nom] > 1` before downgrading. When downgrading a `producteur`, if `$batiments['pointsProducteurRestants'] >= $points['producteur']`, it simply deducts points from the remaining pool. However, if a player has already manually allocated most producteur points AND the `pointsProducteurRestants` balance was reduced by a previous partial downgrade or combat event, the math `$batiments['pointsProducteurRestants'] - $points['producteur']` can underflow past 0. The code handles this branch:

```php
} else {
    $pointsAEnlever = $points['producteur'] - $batiments['pointsProducteurRestants'];
    dbExecute($base, 'UPDATE constructions SET pointsProducteurRestants=0 WHERE login=?', ...);
    // then strips from allocations
```

This path looks correct. However, when stripping allocations from the `$chaine`, if `$pointsAEnlever` exceeds the total sum of all allocations (edge case: all allocations summed is less than what needs removing), the remaining `$pointsAEnlever` is silently ignored — the allocations are zeroed but the residual unremoved points are not accounted for. The player ends up with more effective production than they should.

**Code:**
```php
// player.php:691-699
foreach ($nomsRes as $num => $ressource) {
    if ($pointsAEnlever <= $producteurAllocs[$num]) {
        $chaine = $chaine . ($producteurAllocs[$num] - $pointsAEnlever) . ";";
        $pointsAEnlever = 0;
    } else {
        $chaine = $chaine . "0;";
        $pointsAEnlever = $pointsAEnlever - $producteurAllocs[$num];
    }
}
// No check for $pointsAEnlever > 0 after the loop
```

**Fix:** After the `foreach`, add a check: `if ($pointsAEnlever > 0) { /* points were unremovable — log error or apply to remaining pool */ }`. This guards against silent point leakage on edge-case downgrades.

---

### ECO-P6-009 [INFO] `calculerTotalPoints()` applies `TRADE_VOLUME_CAP` to raw `tradeVolume` but `tradeVolume` semantics changed — column now stores energy-spent, not atom-trade-volume

**File:** `includes/formulas.php:108`, `includes/config.php:506`
**Description:**
The constant `TRADE_VOLUME_CAP` is defined with the comment "Cap on trade volume to prevent ranking inflation via repeated self-trading." Originally `tradeVolume` tracked atom volumes. After the energy-based trade points refactor, `tradeVolume` now accumulates energy spent on buys and energy gained from sells. The cap of 10M still makes mathematical sense but is semantically mislabeled. The constant name and its docblock should reflect that it caps *energy transacted*, not atom volume.

No code bug, just documentation/naming drift that could confuse future maintainers.

**Fix:** Rename to `TRADE_ENERGY_CAP` or update the comment. Document the unit clearly.

---

## Summary

Total: 9 findings — 0 CRITICAL / 1 HIGH / 3 MEDIUM / 3 LOW / 2 INFO

| ID | Severity | Description |
|----|----------|-------------|
| ECO-P6-001 | HIGH | `tradeVolume` DB-capped at 80 makes `TRADE_VOLUME_CAP` (10M) dead code; ranking broken |
| ECO-P6-002 | MEDIUM | Transfer IP check runs outside transaction and fails for NAT/VPN scenarios |
| ECO-P6-003 | MEDIUM | Recipient storage check only blocks if ALL resources exceed capacity, not ANY |
| ECO-P6-004 | MEDIUM | `coefDisparition()` static cache not invalidated after molecule mutations in same request |
| ECO-P6-005 | MEDIUM | `revenuAtome()` return value not clamped to `max(0, ...)`, unlike `revenuEnergie()` |
| ECO-P6-006 | LOW | Market price loop has no `count($txTabCours) == $nbRes` guard; trailing-comma corruption propagates |
| ECO-P6-007 | LOW | Transfer `ressourcesRecues` stores unrounded floats, causing systematic 1-atom loss at arrival |
| ECO-P6-008 | LOW | `diminuerBatiment()` producteur downgrade silently ignores residual points if allocations sum < points-to-remove |
| ECO-P6-009 | INFO | `TRADE_VOLUME_CAP` semantics/name mislabeled after energy-based trade points refactor |

**Most urgent fix: ECO-P6-001** — the trade-ranking system is functionally broken. `tradeVolume` is capped at 80 in the DB, meaning a full season of active trading contributes `sqrt(80) ≈ 9` total ranking points maximum. With `RANKING_TRADE_WEIGHT = 1.0`, the intended design (10M cap → `sqrt(10M) = 3162` points) is completely suppressed. Active traders are not rewarded as intended.
