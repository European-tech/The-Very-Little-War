# Pass 7 Audit — MAPS Domain
**Date:** 2026-03-08
**Agent:** Pass7-B7-MAPS

## Summary
| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 0 |
| MEDIUM | 2 |
| LOW | 1 |
| INFO | 1 |
| **Total** | **4** |

---

## MEDIUM Findings

### MEDIUM-001 — Resource node rendering lacks lower bounds check (negative coordinates)
**File:** `attaquer.php:483`
**Description:** The resource node rendering checks if nodes exceed upper map bounds (`node['x'] >= tailleCarte || node['y'] >= tailleCarte`) but does NOT verify non-negative. Nodes with negative x or y (DB corruption, admin manipulation) render with negative CSS pixel offsets, creating rendering artifacts.
**Fix:**
```php
// BEFORE:
if ($node['x'] >= $tailleCarte || $node['y'] >= $tailleCarte) continue;
// AFTER:
if ($node['x'] < 0 || $node['y'] < 0 || $node['x'] >= $tailleCarte || $node['y'] >= $tailleCarte) continue;
```

### MEDIUM-002 — Player position query uses `>= 0` instead of `> 0` for sentinel filtering
**File:** `attaquer.php:396`
**Description:** The SQL query filters `WHERE m.x >= 0 AND m.y >= 0` to exclude inactive players (sentinel x=-1000, y=-1000). However, if a player's coordinates were corrupted to a negative value other than -1000, they would pass this check and render at negative pixel offsets. Strict inequality (`> 0`) is safer.
**Fix:**
```sql
-- Change:
WHERE m.x >= 0 AND m.y >= 0
-- To:
WHERE m.x > 0 AND m.y > 0
```
Note: Verify that `x=0, y=0` is not a valid map position; if it is, use `>= 0` with the explicit sentinel filter already in place and add the node-level bounds check.

---

## LOW Findings

### LOW-001 — getResourceNodeBonus() does not validate node coordinates before distance calculation
**File:** `includes/resource_nodes.php:137-138`
**Description:** The bonus function calculates distances to all cached nodes without validating that node x/y are within map bounds. Out-of-bounds nodes (DB manipulation or migration bug) produce incorrect distance values and wrong bonuses.
**Fix:** Add a bounds check inside the loop:
```php
foreach ($nodes as $node) {
    if ($node['x'] < 0 || $node['y'] < 0 || $node['x'] >= MAP_SIZE || $node['y'] >= MAP_SIZE) continue;
    $dist = ...;
}
```

---

## INFO Findings

### INFO-001 — TOCTOU between tailleCarte and player positions fetch
**File:** `attaquer.php:373-396`
**Description:** `tailleCarte` is read from DB (line 375) and then player positions fetched (line 396) in separate queries without a wrapping transaction. Map expansion between the two reads could cause stale boundary checks. Map expansion is extremely rare so the impact is negligible.
**Recommendation:** Wrap both reads in a read-only transaction if strict consistency is needed.

---

## Verified Clean

- **CSRF:** `csrfCheck()` on attaquer.php (lines 21, 86) — clean.
- **Auth:** `basicprivatephp.php` included on all map endpoints; all operations keyed to `$_SESSION['login']` — clean.
- **Player impersonation:** Cannot send armies for another player; POST handler validates `$_SESSION['login']` — clean.
- **XSS on map data:** Player names and resource types escaped with `htmlspecialchars()` (lines 475, 485, 544, 639) — clean.
- **Scroll coordinate clamping:** `max(0, min(...))` applied on navigation (lines 387-388) — clean.
- **Sentinel filtering:** Inactive players (x=-1000, y=-1000) excluded from map display — clean.
- **Resource node generation:** Nodes generated within map bounds with margin guards — clean.
- **Transaction safety:** Army send and espionage wrapped in `withTransaction()` with FOR UPDATE locks — clean.
- **Beginner protection:** Both attack and espionage respect `BEGINNER_PROTECTION_SECONDS` — clean.
- **Alliance protection:** Attacker cannot target alliance members — clean.
- **Comeback shield:** Defenders with active shield cannot be attacked — clean.
- **Energy cost validation:** Re-validated inside transaction under FOR UPDATE — clean.
- **Molecule availability:** Troop counts re-validated under FOR UPDATE — clean.
