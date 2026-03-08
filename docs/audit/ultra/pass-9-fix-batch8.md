# Pass-9 Fix Batch 8 — Map Bounds & Sentinel Player Fixes

Date: 2026-03-08
Files modified: attaquer.php, includes/player.php

---

## P9-MED-015 — GET x/y not clamped to map bounds

**File:** attaquer.php, lines 386-396 (replaced)

**Fix:** Replaced raw `intval($_GET['x'])` / `intval($_GET['y'])` with clamped
variables using `max(0, min((int)$_GET['x'], $mapSize - 1))`. The local variable
`$mapSize` is derived from `(int)$tailleCarte['tailleCarte']` immediately before
the clamp so it is available for both the scroll coordinate assignment and the
later bounds check (MED-016).

The resulting `$scrollX` / `$scrollY` values are assigned to `$x` / `$y`
(which are referenced in the JS scroll output at lines 495-496), so the JS
`scrollTop` / `scrollLeft` calculation now uses only in-bounds values.

---

## P9-MED-016 — Array bounds check before writing to $carte

**File:** attaquer.php, around line 434 (foreach loop body)

**Fix:** The direct write `$carte[$tableau['x']][$tableau['y']] = [...]` was
replaced with an explicit bounds guard:

```php
$px = (int)$tableau['x'];
$py = (int)$tableau['y'];
if ($px >= 0 && $px < $mapSize && $py >= 0 && $py < $mapSize) {
    $carte[$px][$py] = [$tableau['id'], $tableau['login'], $tableau['points'], $type];
}
```

This prevents PHP undefined-offset notices (and potential garbage cell data)
when a player's stored coordinates are stale or outside the current map size.

**File:** includes/player.php, around line 780 (registration coord-finding loop)

**Fix:** The write `$carte[$joueurs['x']][$joueurs['y']] = 1` used to find a
free cell for a new player was similarly guarded:

```php
$jx = (int)$joueurs['x'];
$jy = (int)$joueurs['y'];
if ($jx >= 0 && $jx < $inscrits['tailleCarte'] && $jy >= 0 && $jy < $inscrits['tailleCarte']) {
    $carte[$jx][$jy] = 1;
}
```

Note: the SQL query at that location already filters `WHERE x >= 0 AND y >= 0`,
so the guard is a defense-in-depth measure in case coordinates exceed the
current map size (e.g., after a map shrink or data corruption).

---

## P9-MED-017 — Sentinel players (x=-1000) included in map query

**File:** attaquer.php, line 402

**Fix:** Added `WHERE m.x >= 0 AND m.y >= 0` to the `$allPlayers` query that
populates the map render loop:

```sql
SELECT m.id, m.login, m.x, m.y, a.points, a.idalliance
FROM membre m JOIN autre a ON m.login = a.login
WHERE m.x >= 0 AND m.y >= 0
```

Sentinel/inactive players use the magic coordinate x=-1000 / y=-1000.
Without this filter they were included in the foreach loop and the subsequent
bounds guard (MED-016) would silently drop them — but more importantly the
query was wasteful and the player would appear in alliance/war classification
logic unnecessarily.

---

## Lint Results

```
No syntax errors detected in attaquer.php
No syntax errors detected in includes/player.php
```

---

## Summary

| ID | Severity | File | Status |
|----|----------|------|--------|
| P9-MED-015 | MEDIUM | attaquer.php | FIXED |
| P9-MED-016 | MEDIUM | attaquer.php + includes/player.php | FIXED |
| P9-MED-017 | MEDIUM | attaquer.php | FIXED |

All three findings resolved with minimal, targeted changes. Zero new PHP syntax
errors introduced.
