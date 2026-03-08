# Pass 7 Season Reset Audit
**Date:** 2026-03-08
**Scope:** `includes/player.php` — `remiseAZero()`, `performSeasonEnd()`, `archiveSeasonData()`
**Focus:** Season reset completeness and data integrity

---

## Findings

### SRS-P7-001 [MEDIUM] — `archiveSeasonData` runs before VP award; `season_recap.victoires` is always 0

**File:** `includes/player.php:1025` (Phase 0), `includes/player.php:1110,1135` (Phases 1b/1c)

**Proof:**

`performSeasonEnd()` executes in this order:

```
Phase 0:  archiveSeasonData($base);          // stores autre.victoires → season_recap.victoires
Phase 1b: ajouter('victoires', ..., VP);     // adds rank VP to autre.victoires
Phase 1c: ajouter('victoires', ..., VP);     // adds alliance VP to autre.victoires
Phase 2:  remiseAZero();                     // sets autre.victoires = 0
```

`victoires` is **never incremented during a season** — it is only touched by `remiseAZero` (→ 0) and the end-of-season VP award (Phases 1b/1c). Because `archiveSeasonData` runs *before* Phases 1b/1c, it always captures `victoires = 0` (the value set by the previous season's `remiseAZero`). The VP earned from this season's ranking is then written into `autre.victoires` and immediately wiped by Phase 2.

Result: every row in `season_recap` will have `victoires = 0` regardless of end-of-season placement. The historical VP data is discarded.

**Fix:** Move `archiveSeasonData($base)` to **after Phase 1c** (after both VP-award loops complete) but still before `remiseAZero()`. Alternatively, archive the computed VP separately as part of Phase 1b/1c and add it to `season_recap` rows via an `UPDATE`. The simplest correct ordering:

```php
// Phase 1b + 1c: award VP (modifies autre.victoires)
// Phase 1d: awardPrestigePoints()
archiveSeasonData($base);   // now captures the just-awarded VP
remiseAZero();              // then reset
```

---

### SRS-P7-002 [LOW] — `alliance_left_at` not cleared in `remiseAZero`

**File:** `includes/player.php:1263` (the `UPDATE autre SET ...` in `remiseAZero`)

**Proof:**

`alliance_left_at` (added by migration `0034_add_alliance_left_at.sql`) stores the Unix timestamp of when a player last left or was kicked from an alliance. It is used to enforce a 24-hour rejoin cooldown (`ALLIANCE_REJOIN_COOLDOWN_SECONDS`) in `alliance.php:211`.

`remiseAZero()` does not include `alliance_left_at` in its `UPDATE autre SET ...` statement. A player kicked from an alliance shortly before season end will carry that cooldown timestamp into the new season, preventing them from joining any alliance for up to 24 hours of the new season despite the full reset.

**Fix:** Add `alliance_left_at = NULL` to the `UPDATE autre SET ...` in `remiseAZero()`:

```php
dbExecute($base, 'UPDATE autre SET points=0, ..., alliance_left_at=NULL');
```

---

### SRS-P7-003 [LOW] — `season_recap.streak_max` stores end-of-season streak, not peak streak

**File:** `includes/player.php:992` in `archiveSeasonData`, `migrations/0029_create_season_recap.sql:16`

**Proof:**

`archiveSeasonData` archives `a.streak_days` (the player's *current* streak at archive time) into `season_recap.streak_max`. However, `streak_days` reflects only the consecutive days logged in up to the moment of archiving. A player who reached a 28-day streak mid-season, then missed a day two weeks before season end, would have `streak_days = 12` (or similar) at archive time, so `season_recap.streak_max` would record `12`, not `28`.

There is no separate `streak_max` (peak streak) column in `autre` to track the highest streak reached during the season.

**Fix (two-part):**
1. Add `streak_peak INT NOT NULL DEFAULT 0` column to `autre` via migration.
2. In `updateLoginStreak()`, after incrementing `streak_days`, also update `streak_peak = GREATEST(streak_peak, streak_days)`.
3. In `archiveSeasonData()`, read `a.streak_peak` instead of `a.streak_days` as `streak_max`.
4. In `remiseAZero()`, reset both `streak_days=0, streak_last_date=NULL, streak_peak=0`.

---

### SRS-P7-004 [INFO] — `MEDAL_GRACE_PERIOD_DAYS` / `MEDAL_GRACE_CAP_TIER` are dead constants

**File:** `includes/config.php:523-524`

**Proof:**

```php
define('MEDAL_GRACE_PERIOD_DAYS', 14);  // first N days: use grace cap
define('MEDAL_GRACE_CAP_TIER', 3);      // max tier index during grace (Gold = 6%)
```

A code-wide search (`grep -rn "MEDAL_GRACE"`) finds no usage of either constant in any `.php` file other than `config.php`. The constants suggest a planned feature — capping cross-season medal bonuses to lower tiers during the first two weeks of a new season to reduce veteran advantage — but the feature was never implemented.

`computeMedalBonus()` applies `MAX_CROSS_SEASON_MEDAL_BONUS` but does not check `MEDAL_GRACE_PERIOD_DAYS`.

**Fix:** Either implement the grace period in `computeMedalBonus()` (compare `time() - statistiques.debut` against `MEDAL_GRACE_PERIOD_DAYS * SECONDS_PER_DAY`, then use `MEDAL_GRACE_CAP_TIER` as the tier ceiling), or remove the two dead constants to eliminate documentation debt.

---

## Checklist — Pass 7 Verification

| Check | Result |
|-------|--------|
| All player-specific action tables cleared (actionsattaques, actionsformation, actionsenvoi, actionsconstruction) | PASS — all DELETEd in `remiseAZero` lines 1283-1286 |
| ressources reset (energie, terrain, revenuenergie, all atom columns) | PASS — `UPDATE ressources SET energie=default, ...` line 1277 |
| constructions reset (all buildings, HP, points allocations, specs, vault, formation) | PASS — line 1264 resets all columns including coffrefort, spec_*, formation, vieIonisateur |
| autre reset (points, medals, streaks, comeback, missions, bombe, energieDonnee) | PASS — line 1263 covers all season-specific columns |
| alliance_left_at reset | **FAIL** — see SRS-P7-002 |
| player_compounds cleared | PASS — `DELETE FROM player_compounds` line 1306 |
| resource_nodes cleared + regenerated | PASS — deleted line 1307, regenerated line 1158 |
| attack_cooldowns cleared | PASS — `DELETE FROM attack_cooldowns` line 1305 |
| connectes cleared | PASS — `DELETE FROM connectes` line 1288 |
| vacances cleared + membre.vacance reset | PASS — lines 1289-1290 |
| grades cleared | PASS — `DELETE FROM grades` line 1291 |
| news cleared | PASS — `DELETE FROM news WHERE 1` line 1314 |
| sanctions: temp/expired removed, permanent preserved | PASS — line 1311 |
| login_history preserved (security audit trail) | PASS — intentionally not cleared |
| account_flags preserved (multi-account tracking) | PASS — intentionally not cleared |
| prestige table preserved + PP awarded before reset | PASS — Phase 1d before Phase 2 |
| alliances: research/stats reset, pointsVictoire preserved | PASS — line 1265 |
| molecules reset (formule="Vide", nombre=0, isotope=0) | PASS — line 1266 |
| membre.x/y set to -1000 (all players off-map) | PASS — line 1298 |
| statistiques.tailleCarte=1, nbDerniere=0 | PASS — line 1297 |
| statistiques.debut updated to now | PASS — line 1162 |
| session tokens invalidated post-reset | PASS — line 1149 |
| tempsPrecedent reset (production timer) | PASS — line 1295 |
| streak_days=0, streak_last_date=NULL | PASS — line 1263 |
| comeback tracking reset (last_catch_up, comeback_shield_until) | PASS — line 1263 |
| archiveSeasonData before VP award | **FAIL** — see SRS-P7-001 |
| streak_max captures peak streak | **FAIL** — see SRS-P7-003 |
| MEDAL_GRACE constants implemented | INFO — see SRS-P7-004 |
| Advisory lock prevents double-reset | PASS — `GET_LOCK('tvlw_season_reset', 0)` |
| archiveSeasonData called before remiseAZero | PASS (Phase 0 before Phase 2) — but see SRS-P7-001 |
| Login history preserved for security | PASS |
| Forum data (sujets, reponses, statutforum) preserved | PASS — intentional per line 1317 comment |
| email_queue preserved for async delivery | PASS — intentional, reset emails queued post-reset |

---

## Summary

4 findings: 1 MEDIUM, 2 LOW, 1 INFO. No CRITICAL or HIGH issues.

**SRS-P7-001 (MEDIUM)** is the most impactful: `season_recap.victoires` will be 0 for every player in every season because `archiveSeasonData` runs before the VP award phases. This corrupts the historical record of cross-season VP performance. Fix by reordering: archive after VP is awarded.

**SRS-P7-002 (LOW):** `alliance_left_at` carries over from the old season, blocking players from joining alliances in the first 24 h of the new season. One-line fix: add `alliance_left_at=NULL` to the `remiseAZero` UPDATE.

**SRS-P7-003 (LOW):** `season_recap.streak_max` is the end-of-season current streak, not the true peak streak. Requires a new `streak_peak` column in `autre`.

**SRS-P7-004 (INFO):** Two dead constants (`MEDAL_GRACE_PERIOD_DAYS`, `MEDAL_GRACE_CAP_TIER`) are defined but never applied. Either implement the grace-period feature or remove the constants.

All other season-reset tables and columns verified as correctly handled.
