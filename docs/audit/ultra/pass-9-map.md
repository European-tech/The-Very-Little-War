# Pass 9 — Map & Coordinates Security Audit

**Date:** 2026-03-08
**Auditor:** Narrow-domain security agent
**Scope:** `attaquer.php` (map + coordinate sections), `includes/player.php` (coordonneesAleatoires, inscrire), `includes/resource_nodes.php`, `includes/game_resources.php` (proximity bonus calls), `includes/basicprivatephp.php` (coordinate assignment on login)

---

## Audit Questions Coverage

| # | Question | Status |
|---|----------|--------|
| 1 | Arbitrary coordinates (out-of-bounds move) | See MAP-P9-001, MAP-P9-002 |
| 2 | CSRF on move/coordinate-update actions | See MAP-P9-003 |
| 3 | SQL injection in coordinate queries | See MAP-P9-004 |
| 4 | Authorization — moving another player's unit | See MAP-P9-005 |
| 5 | Race condition in coordonneesAleatoires | See MAP-P9-006 |
| 6 | Resource node proximity — integer overflow, division by zero | See MAP-P9-007, MAP-P9-008 |
| 7 | Map boundary enforcement | See MAP-P9-001, MAP-P9-009 |
| 8 | Teleport via direct POST of coordinates | See MAP-P9-003, MAP-P9-010 |

---

## Findings

---

### MAP-P9-001
**Severity:** MEDIUM
**File:** `attaquer.php:386-396`
**Description — Unvalidated GET x/y used for map scroll centre without boundary clamping**

When `$_GET['type'] == 0` the map view accepts `?x=` and `?y=` parameters and passes them directly to the scroll JavaScript without clamping them to valid map bounds:

```php
if (isset($_GET['x'])) {
    $x = intval($_GET['x']);        // ← no bounds check
} else {
    $x = $centre['x'];
}
```

The values are used only as pixel-scroll targets (`tailleTile * ($x + 0.5)`), not to index the PHP `$carte` array, so there is no array-out-of-bounds crash and no DB write.  However:

- A negative or huge value produces meaningless pixel offsets and may cause layout jank.
- Because `$x`/`$y` come from a GET parameter they are trivially manipulated. The current code offers no feedback that the centre the player requested is outside the map, which could confuse users who share crafted URLs.
- `$x` and `$y` are used in an inline `<script>` tag (`parseInt(<?php echo $tailleTile * ($x + 0.5); ?>)`). The value is the output of `intval()` so it is safe from XSS, but the arithmetic product could produce an astronomically large JS literal if `$x` is `PHP_INT_MAX / MAP_TILE_SIZE_PX`.

**Recommended fix:** Clamp `$x` and `$y` to `[0, tailleCarte - 1]` immediately after `intval()`.

---

### MAP-P9-002
**Severity:** MEDIUM
**File:** `attaquer.php:420-434` / `includes/player.php:778-780`
**Description — Map render: DB players with x/y >= tailleCarte cause PHP undefined-offset notice and silently corrupt the $carte array**

The map rendering loop creates a square `$carte` array of dimension `tailleCarte × tailleCarte` and then writes all players into it:

```php
$allPlayers = dbFetchAll(...);          // includes ALL players regardless of position
...
$carte[$tableau['x']][$tableau['y']] = [...];   // ← no bounds guard
```

During a season transition `tailleCarte` is reset to 1 (`UPDATE statistiques SET tailleCarte=1`). If any player reconnects and receives a fresh coordinate assignment at (say) `x=5, y=3` but another concurrent request reads `tailleCarte=1` before the map has grown, the `$carte` array is `1×1` but the player's stored DB coordinates are `(5, 3)`. PHP will silently create `$carte[5][3]` as an auto-vivified element outside the declared array bounds, generating undefined-offset E_NOTICE errors and causing those player tiles to be omitted from the visual loop (which only iterates `0..tailleCarte-1`).

The same issue exists in `coordonneesAleatoires()` at player.php:778-780 where inactive players with coordinates >= current `tailleCarte` are read and inserted into the in-transaction `$carte` array.

**Recommended fix:** Add `if ($tableau['x'] >= 0 && $tableau['x'] < $tailleCarte['tailleCarte'] && $tableau['y'] >= 0 && $tableau['y'] < $tailleCarte['tailleCarte'])` guards before the array write in both locations.

---

### MAP-P9-003
**Severity:** LOW
**File:** `attaquer.php:21, 86`
**Description — CSRF protection is present and correct on all state-mutating actions**

Both POST handlers (espionage and attack) call `csrfCheck()` as the very first step before any game-state changes. The attack form and espionage form both emit `csrfField()` (`attaquer.php:512, 638`). No state-changing coordinate operation is reachable without a valid CSRF token.

**Verdict:** No finding. Included for completeness as audit question 2 and 8.

---

### MAP-P9-004
**Severity:** INFO
**File:** `attaquer.php:136, 184, 505, 519` / `includes/player.php:778`
**Description — All coordinate-related SQL queries use prepared statements; no injection surface**

Coordinates are fetched via `dbFetchOne($base, 'SELECT x,y FROM membre WHERE login=?', 's', ...)` and used in arithmetic (not concatenated into SQL). The `$_GET['x']` and `$_GET['y']` values pass through `intval()` and are only used in PHP arithmetic and JS output — never in a SQL string. `tailleCarte` is read from the DB, not from user input.

**Verdict:** No SQL injection surface found.

---

### MAP-P9-005
**Severity:** INFO
**File:** `attaquer.php:1-275`
**Description — No mechanism exists by which a player can move another player's unit**

Player coordinates are only written in two places:

1. `basicprivatephp.php:59` — `UPDATE membre SET x=?, y=? WHERE login=?` using `$_SESSION['login']` (the authenticated player only).
2. `player.php:1310` — bulk reset `UPDATE membre SET x=-1000, y=-1000` called only inside the season-reset function `remiseAZero()`, which is admin-gated.

Neither the attack flow nor any map view writes coordinates for any player other than the session owner.

**Verdict:** No authorization gap found.

---

### MAP-P9-006
**Severity:** LOW
**File:** `includes/player.php:753-831`
**Description — coordonneesAleatoires() race condition residual: force-expand branch assigns coordinates outside the locked $carte bounds**

The function wraps the placement algorithm in a `withTransaction()` with `SELECT … FOR UPDATE` on `statistiques`, which is correct. However, the force-expand branch (executed when both edges are full) sets coordinates that extend *beyond* the newly declared `$carte` array without re-allocating it:

```php
// horizontal edge full:
$inscrits['tailleCarte'] += 1;
$x = $inscrits['tailleCarte'] - 1;   // ← new row index
$y = 0;
// $carte was built for the *old* tailleCarte and does NOT have row $x
// The collision check "while ($carte[$x][$y] != 0)" references an undefined index
```

When the force-expand path fires, `$carte[$x][$y]` is undefined (PHP auto-vivifies it as `null`, so `!= 0` evaluates to `false` and the loop body is skipped). The coordinate assignment is therefore functionally correct (the expanded cell is known to be empty), but the undefined-index notice leaks through error logs on PHP 8.2 with `E_NOTICE` reporting enabled, and the logic is fragile — it relies on PHP's implicit null rather than an explicit check.

**Recommended fix:** After expanding `tailleCarte`, append the new row(s) to `$carte` or simply skip the collision check when the force-expand path is taken (the cell is provably empty).

---

### MAP-P9-007
**Severity:** LOW
**File:** `includes/resource_nodes.php:137`
**Description — sqrt(0) in proximity calculation is safe; no division-by-zero risk in node bonus**

The proximity formula is:

```php
$dist = sqrt(pow($px - $node['x'], 2) + pow($py - $node['y'], 2));
if ($dist <= $node['radius']) {
    $totalBonus += $node['bonus_pct'] / 100.0;
}
```

`sqrt(0)` returns `0.0` in PHP (valid), which occurs when the player is on exactly the same cell as a node. No division is performed. `$node['radius']` is always `RESOURCE_NODE_DEFAULT_RADIUS` (5, a positive constant) and is used as a right-hand comparator, not a divisor. `$node['bonus_pct']` is always `RESOURCE_NODE_DEFAULT_BONUS_PCT` (10.0) — also not a divisor.

**Verdict:** No overflow or division-by-zero surface found. Finding included for completeness as audit question 6.

---

### MAP-P9-008
**Severity:** INFO
**File:** `includes/resource_nodes.php:47-48`
**Description — Node generation uses integer coordinates; no integer overflow possible at expected map sizes**

`mt_rand($margin, $mapSize - 1 - $margin)` produces PHP integers. With `mapSize` capped at `MAP_INITIAL_SIZE` (20) by default and growing at most to ~200 for a large player base, values are far below PHP_INT_MAX. The `sqrt(pow(...) + pow(...))` proximity calculation uses floating-point arithmetic throughout. No integer overflow is possible.

**Verdict:** No overflow surface found.

---

### MAP-P9-009
**Severity:** MEDIUM
**File:** `attaquer.php:402` / `attaquer.php:434`
**Description — Map display fetches ALL players including negative-coordinate sentinels; inactive players (x=-1000, y=-1000) written into $carte produce PHP warning**

```php
$allPlayers = dbFetchAll($base, 'SELECT m.id, m.login, m.x, m.y, ... FROM membre m JOIN autre a ...', '');
...
$carte[$tableau['x']][$tableau['y']] = [...];
```

There is no `WHERE m.x != -1000` guard. Players with the sentinel value `x=-1000, y=-1000` (inactive/banned — `INACTIVE_PLAYER_X` constant) are fetched and written into `$carte[-1000][-1000]`, which PHP handles as a valid (negative) array index. This does not cause a crash, but:

1. PHP creates array keys `-1000 => [...]` in `$carte`, silently growing the array with a ghost entry.
2. The render loop `for ($i = 0; $i < $tailleCarte; $i++)` only iterates non-negative indices, so inactive players are never rendered — correct by accident.
3. The ghost entries waste memory proportional to the number of inactive players and may accumulate over a long season.

In `coordonneesAleatoires()` this is already handled correctly (`WHERE x >= 0 AND y >= 0`), but the attaquer.php map display is inconsistent.

**Recommended fix:** Add `AND m.x >= 0` to the `$allPlayers` query in `attaquer.php:402`.

---

### MAP-P9-010
**Severity:** INFO
**File:** `includes/basicprivatephp.php:56-59`
**Description — No teleport possible: coordinate assignment is fully server-side**

A player cannot POST arbitrary coordinates to move their position. The only coordinate-write path (`UPDATE membre SET x=?, y=? WHERE login=?`) uses coordinates exclusively from `coordonneesAleatoires()`, which is a server-controlled function. There is no endpoint that reads user-supplied x/y and writes them to `membre`. The `?x=` and `?y=` GET parameters in `attaquer.php` affect only the scroll position of the map viewport in the browser and are never persisted to the database.

**Verdict:** No teleport vector found.

---

### MAP-P9-011
**Severity:** LOW
**File:** `attaquer.php:485`
**Description — Resource node out-of-bounds guard is one-sided (missing lower bound check)**

When rendering resource nodes on the map:

```php
foreach ($mapNodes as $node) {
    if ($node['x'] >= $tailleCarte['tailleCarte'] || $node['y'] >= $tailleCarte['tailleCarte']) continue;
    // render node
}
```

The guard correctly skips nodes whose x/y exceed the current map size, but it does not check for negative values (`$node['x'] < 0`). Nodes are generated by `generateResourceNodes()` which uses `mt_rand($margin, $mapSize - 1 - $margin)` — so negative coordinates are not normally possible. However, if the `resource_nodes` table were manually edited or seeded with negative values (e.g., via a future migration bug), a node with `x=-1` or `y=-1` would be rendered with `top: -96px; left: -96px` in CSS, placing a diamond marker outside the visible map area. This is a cosmetic issue with no security impact, but the guard should be symmetric.

**Recommended fix:** Extend the guard to `if ($node['x'] < 0 || $node['y'] < 0 || $node['x'] >= ... || $node['y'] >= ...) continue;`

---

### MAP-P9-012
**Severity:** INFO
**File:** `attaquer.php:184-185`
**Description — Distance calculation uses player's own DB coordinates (not a user-supplied value); no travel-time manipulation possible**

Travel time for attacks is:

```php
$distance = pow(pow($membre['x'] - $positions['x'], 2) + pow($membre['y'] - $positions['y'], 2), 0.5);
$tempsTrajet = max($tempsTrajet, round($distance / vitesse(...) * SECONDS_PER_HOUR));
```

Both `$membre['x'/'y']` (attacker) and `$positions['x'/'y']` (defender) are fetched directly from the DB using parameterised queries. There is no user-supplied coordinate that could reduce `$distance` to zero or negative. The `vitesse()` function returns `max(1.0, ...)`, so the divisor is never zero. `$tempsTrajet` uses `max()` over all classes.

**Verdict:** No manipulation surface for travel time via coordinate injection.

---

## Summary Table

| ID | Severity | File | Issue |
|----|----------|------|-------|
| MAP-P9-001 | MEDIUM | attaquer.php:386-396 | GET x/y not clamped to map bounds (scroll only, no DB write) |
| MAP-P9-002 | MEDIUM | attaquer.php:434, player.php:780 | Players with coords >= tailleCarte cause undefined-offset notices and silent array corruption |
| MAP-P9-003 | LOW | attaquer.php:21,86 | (INFO) CSRF present and correct — no finding |
| MAP-P9-004 | INFO | Multiple | No SQL injection surface in coordinate queries |
| MAP-P9-005 | INFO | attaquer.php | No authorization gap — cannot move other players |
| MAP-P9-006 | LOW | player.php:753-831 | Force-expand in coordonneesAleatoires references undefined $carte index |
| MAP-P9-007 | LOW | resource_nodes.php:137 | (INFO) No division-by-zero in proximity calc |
| MAP-P9-008 | INFO | resource_nodes.php:47 | No integer overflow in node coordinates |
| MAP-P9-009 | MEDIUM | attaquer.php:402 | Inactive sentinel players (x=-1000) included in map query; ghost array entries |
| MAP-P9-010 | INFO | basicprivatephp.php:56-59 | No teleport vector — coordinates are server-assigned only |
| MAP-P9-011 | LOW | attaquer.php:485 | Node render guard missing lower-bound (x<0, y<0) check |
| MAP-P9-012 | INFO | attaquer.php:184-185 | Travel time calculation not manipulable via user input |

---

FINDINGS: 0 critical, 0 high, 3 medium, 3 low
