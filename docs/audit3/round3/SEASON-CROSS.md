# SEASON-CROSS: Cross-Domain Analysis of the Season/Reset System

**Audit Date:** 2026-03-03
**Scope:** Complete season lifecycle -- detection, maintenance, archiving, prestige awards, reset, email, and all cross-domain interactions
**Files Analyzed:**
- `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php` (lines 126-309) -- season detection and orchestration
- `/home/guortates/TVLW/The-Very-Little-War/includes/player.php` (lines 793-838) -- `remiseAZero()` function
- `/home/guortates/TVLW/The-Very-Little-War/includes/prestige.php` -- prestige point calculation and awards
- `/home/guortates/TVLW/The-Very-Little-War/includes/formulas.php` -- victory point formulas
- `/home/guortates/TVLW/The-Very-Little-War/admin/index.php` -- admin reset trigger
- `/home/guortates/TVLW/The-Very-Little-War/classement.php` -- rankings display
- `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php` -- combat/formation during reset
- `/home/guortates/TVLW/The-Very-Little-War/includes/config.php` -- game constants
- `/home/guortates/TVLW/The-Very-Little-War/includes/catalyst.php` -- weekly catalyst (interacts with reset)

---

## 1. Complete Season Reset Flow (Step-by-Step)

The season reset is a two-phase system triggered by player page loads, not by a cron job.

### Phase 1: Maintenance Activation
**Trigger:** Any authenticated player loads a page when the calendar month has changed.
**File:** `basicprivatephp.php:299-304`

```php
} elseif (date('n', time()) != date('n', $debut["debut"]) && $maintenance['maintenance'] == 0) {
    $erreur = "Une nouvelle partie recommencera dans 24 heures.";
    dbExecute($base, 'UPDATE statistiques SET maintenance = 1');
    dbExecute($base, 'UPDATE statistiques SET debut = ?', 'i', $now);
}
```

- Sets `maintenance=1` in `statistiques`
- Updates `debut` timestamp to current time (starts the 24h countdown)
- Shows player an error message

### Phase 2: Full Reset (24h after Phase 1)
**Trigger:** Any authenticated player loads a page when maintenance=1 AND 24h have passed.
**File:** `basicprivatephp.php:134-297`

**Step-by-step execution order:**

1. **Advisory lock acquisition** (line 137): `GET_LOCK('tvlw_season_reset', 0)` -- non-blocking
2. **Archive top 20 players** (lines 144-165): Builds a formatted string of top 20 player stats
3. **Archive top 20 alliances** (lines 168-176): Builds a formatted string of top 20 alliance stats
4. **Archive top 20 wars** (lines 179-189): Builds a formatted string of top 20 wars by casualties
5. **Award player victory points** (lines 192-197): Iterates ALL players sorted by totalPoints, calls `ajouter('victoires', 'autre', ...)` for each
6. **Award alliance victory points** (lines 199-209): Iterates ALL alliances sorted by pointstotaux, updates alliance VP and each member's VP
7. **Award prestige points** (line 215): Calls `awardPrestigePoints()` -- cross-season progression
8. **Store archived data** (line 220): `INSERT INTO parties`
9. **Execute `remiseAZero()`** (line 221): The mass reset function (18+ SQL statements)
10. **Update season start time** (line 224): `UPDATE statistiques SET debut = ?`
11. **Create news entry** (lines 226-231): Winner announcement
12. **Send emails to ALL players** (lines 234-288): Individual emails via `mail()`
13. **Disable maintenance** (line 292): `UPDATE statistiques SET maintenance = 0`
14. **Release advisory lock** (line 295): `RELEASE_LOCK('tvlw_season_reset')`

### `remiseAZero()` Internal Steps (player.php:793-838)

```
1. UPDATE autre SET points=0, niveaututo=1, nbattaques=0, neutrinos=default, moleculesPerdues=0, ...
2. UPDATE constructions SET generateur=default, producteur=default, ...
3. UPDATE alliances SET energieAlliance=0, duplicateur=0, catalyseur=0, ...
4. UPDATE molecules SET formule="Vide", nombre=0
5. UPDATE membre SET timestamp=<now>
6. UPDATE ressources SET energie=default, terrain=default, ... <all 8 atoms>=default
7. DELETE FROM declarations
8. DELETE FROM invitations
9. DELETE FROM messages
10. DELETE FROM rapports
11. DELETE FROM actionsconstruction
12. DELETE FROM actionsformation
13. DELETE FROM actionsenvoi
14. DELETE FROM actionsattaques
15. UPDATE statistiques SET nbDerniere=0, tailleCarte=1
16. UPDATE membre SET x=-1000, y=-1000
17. Prestige unlock application (generateur=2 for debutant_rapide players)
18. DELETE FROM attack_cooldowns
```

---

## 2. Findings

---

### SEASON-CROSS-001 -- CRITICAL -- No Game Action Blocking During Maintenance

**File:** `basicprivatephp.php:299-307`
**Severity:** CRITICAL

**Problem:** When maintenance mode is active (Phase 1, the 24h window, or during Phase 2 reset execution), the system only sets an `$erreur` variable to display a message. It does NOT:
- Redirect the player away from the page
- Block any game actions (attacks, constructions, market trades, formations)
- Call `exit()` or `die()` to stop page execution

```php
// basicprivatephp.php:305-307 -- Phase 1 still in maintenance
} elseif ($maintenance['maintenance'] == 1 && (time() - $debut["debut"]) < 86400) {
    $erreur = "Une nouvelle partie recommencera dans 24 heures.";
    // NO exit(), NO redirect, page continues loading normally
}
```

No individual game action page (`attaquer.php`, `marche.php`, `constructions.php`, `don.php`, etc.) checks the maintenance flag independently.

**Impact:** During the 24h maintenance window, players can:
- Launch attacks that will resolve AFTER the reset (troops permanently lost)
- Start constructions that will be deleted during reset (resources wasted)
- Make market trades that affect prices which are never cleared
- Send resources to other players (resources disappear on reset)

During Phase 2 (the reset itself is executing), if a player's request hits `basicprivatephp.php` concurrently with the reset, `updateRessources()` and `updateActions()` run on lines 98-103 BEFORE the maintenance check on line 131. This means combat resolution and resource updates can interleave with the reset SQL statements.

**Fix:** Add a hard block at the top of `basicprivatephp.php`, immediately after session validation:

```php
$maintenanceCheck = dbFetchOne($base, 'SELECT maintenance FROM statistiques');
if ($maintenanceCheck['maintenance'] == 1) {
    header('Location: maintenance.php');
    exit();
}
```

---

### SEASON-CROSS-002 -- CRITICAL -- remiseAZero() Has No Transaction Wrapper

**File:** `player.php:793-838`
**Severity:** CRITICAL

**Problem:** The `remiseAZero()` function executes 18+ separate SQL statements with no transaction boundary. If the process crashes, is killed by OOM, or a statement fails mid-execution, the database is left in a partially-reset state that is extremely difficult to recover from.

Example failure scenarios:
- Crash after line 802 (`UPDATE molecules`) but before line 815 (`DELETE FROM declarations`): All players have zero molecules but old wars/pacts still reference alliance states that were also reset
- Crash after line 821 (`DELETE FROM actionsenvoi`) but before line 825 (`UPDATE membre SET x=-1000,y=-1000`): Some players are on the map with reset stats, others are not
- PHP `max_execution_time` hit during the email loop in `basicprivatephp.php:234-288`: Reset is done but maintenance flag stays at 1 forever (game permanently locked)

The `withTransaction()` helper exists in `database.php:111-121` and is already used by `inscrire()`, `supprimerJoueur()`, and `supprimerAlliance()`. It has not been applied to `remiseAZero()`.

**Fix:**
```php
function remiseAZero() {
    global $base, $nomsRes, $nbRes;
    withTransaction($base, function() use ($base, $nomsRes, $nbRes) {
        // ... all 18 statements ...
    });
}
```

---

### SEASON-CROSS-003 -- CRITICAL -- Race Condition: Player Actions Interleave with Reset

**File:** `basicprivatephp.php:96-103` vs `basicprivatephp.php:134-297`
**Severity:** CRITICAL

**Problem:** When the first player connects after the 24h maintenance window, the code in `basicprivatephp.php` runs in this order:

1. Lines 96-104: `updateRessources()` and `updateActions()` execute for the current player (resolving combats, completing constructions, updating resources)
2. Line 131: Maintenance flag is checked
3. Lines 134-297: Season reset executes

The advisory lock on line 137 prevents two concurrent reset attempts, but it does NOT prevent concurrent player requests from running `updateActions()` while the reset is in progress. A second player loading a page will:
- Run `updateRessources()` which reads partially-reset data
- Run `updateActions()` which may resolve combats against an opponent whose molecules were already set to 0

Furthermore, the victory points are awarded (lines 192-209) based on `totalPoints`, but `updateActions()` on line 103 can still modify `totalPoints` via combat resolution. A combat resolving at the exact moment of archiving can change the rankings after they were already read.

**Impact:** Data corruption, incorrect victory point awards, combat against zero-molecule opponents.

**Fix:** Move maintenance check to BEFORE `updateRessources()`/`updateActions()`, and add a hard exit:

```php
// Check maintenance FIRST, before any game state mutations
$maintenanceRow = dbFetchOne($base, 'SELECT maintenance FROM statistiques');
if ($maintenanceRow['maintenance'] == 1) {
    header('Location: maintenance.php');
    exit();
}

// Only then proceed with game state updates
if (!$joueurEnVac['vacance']) {
    updateRessources($_SESSION['login']);
    // ...
}
```

---

### SEASON-CROSS-004 -- HIGH -- Victory Points Accumulate Across Seasons (Intentional but Undocumented Design Decision)

**File:** `basicprivatephp.php:192-209` and `player.php:799`
**Severity:** HIGH (if unintentional) / LOW (if intentional)

**Problem:** The `victoires` column in `autre` is NOT reset by `remiseAZero()`. Line 799:

```php
dbExecute($base, 'UPDATE autre SET points=0, niveaututo=1, nbattaques=0, neutrinos=default,
    moleculesPerdues=0, energieDepensee=0, energieDonnee=0, bombe=0, batMax=1, totalPoints=0,
    pointsAttaque=0, pointsDefense=0, ressourcesPillees=0, tradeVolume=0, missions=\'\'');
// NOTE: victoires is NOT in this list
```

Meanwhile, victory points are awarded using `ajouter('victoires', 'autre', ...)` which does an atomic increment (`SET victoires = victoires + ?`). This means `victoires` accumulates across all seasons.

The alliance `pointsVictoire` is also not reset (line 801 resets `energieAlliance`, `duplicateur`, and research columns, but not `pointsVictoire`).

If this is intentional (cumulative career VP), it should be documented. If it's a bug, players who have played more seasons have an unfair advantage in the VP column on the leaderboard.

**Impact:** The classement page shows cumulative VP from all seasons, not current season VP. New players can never catch up in the "Victoire" ranking column.

---

### SEASON-CROSS-005 -- HIGH -- Prestige Calculation Uses `tradeVolume` Which Was Already Reset

**File:** `prestige.php:80` and `basicprivatephp.php:215-221`
**Severity:** HIGH

**Problem:** The sequence in `basicprivatephp.php` is:

```
Line 192-209: Award victory points (reads totalPoints, modifies victoires)
Line 215: awardPrestigePoints()  <-- reads autre.tradeVolume, autre.nbattaques, autre.energieDonnee
Line 221: remiseAZero()          <-- resets tradeVolume=0, nbattaques=0, energieDonnee=0
```

The prestige calculation in `calculatePrestigePoints()` (prestige.php:46-85) reads from the `autre` table:

```php
if ($autre['nbattaques'] >= 10) $pp += 5;    // line 79
if ($autre['tradeVolume'] >= 20) $pp += 3;   // line 80
if ($autre['energieDonnee'] > 0) $pp += 2;   // line 81
```

This appears correct -- prestige is calculated BEFORE `remiseAZero()` clears these values. However, if the reset crashes after `awardPrestigePoints()` but before `remiseAZero()`, and the admin manually triggers a new reset, prestige would be double-awarded because `awardPrestigePoints()` uses `ON DUPLICATE KEY UPDATE total_pp = total_pp + ?` (prestige.php:112).

There is no flag or lock to prevent double-awarding prestige if the reset is re-run.

**Fix:** Add an idempotency guard -- set a flag in `statistiques` (e.g., `prestige_awarded_season`) before awarding, check it at the top.

---

### SEASON-CROSS-006 -- HIGH -- initPlayer Cache Not Invalidated After remiseAZero()

**File:** `player.php:793` and `player.php:114-121`
**Severity:** HIGH

**Problem:** `remiseAZero()` mass-updates the `autre`, `constructions`, `ressources`, and `molecules` tables. However, it does not clear the `$GLOBALS['_initPlayerCache']` array. If any code in the same request calls `initPlayer()` after `remiseAZero()`, it will serve stale pre-reset data from the cache.

The reset is triggered within `basicprivatephp.php` which is `include()`-ed by every authenticated page. After the reset code runs (lines 134-297), the page continues to execute its normal logic (e.g., `classement.php`, `constructions.php`). Those pages call `initPlayer()` via `includes/constantes.php:13`, and the cache returns pre-reset values.

```php
// player.php:114-121
if (isset($GLOBALS['_initPlayerCache'][$joueur])) {
    foreach ($GLOBALS['_initPlayerCache'][$joueur] as $key => $value) {
        $GLOBALS[$key] = $value;
    }
    return; // Returns stale data after reset
}
```

**Fix:** Add `$GLOBALS['_initPlayerCache'] = [];` at the start of `remiseAZero()`.

---

### SEASON-CROSS-007 -- HIGH -- remiseAZero() Does Not Reset molecules.isotope Column

**File:** `player.php:802` and `migrations/0008_add_isotope_column.sql`
**Severity:** HIGH

**Problem:** Line 802 resets molecules:

```php
dbExecute($base, 'UPDATE molecules SET formule="Vide", nombre=0');
```

But it does not reset `isotope` to 0. After a season reset, players' molecule rows retain their previous isotope choices (Stable, Reactif, Catalytique). When they create new molecules in the next season, these ghost isotope values could affect combat calculations if the isotope column is read before the player explicitly sets a new one.

The isotope column has `DEFAULT 0` in the migration, but `UPDATE ... SET formule="Vide", nombre=0` does not touch it.

**Fix:** Change to `UPDATE molecules SET formule="Vide", nombre=0, isotope=0`.

---

### SEASON-CROSS-008 -- HIGH -- remiseAZero() Does Not Reset Specialization Columns

**File:** `player.php:800` and `migrations/0011_add_specializations.sql`
**Severity:** HIGH

**Problem:** Migration 0011 adds `spec_combat`, `spec_economy`, `spec_research` columns to `constructions` with `DEFAULT 0`. These represent per-season irreversible choices. However, `remiseAZero()` line 800 does not include them:

```php
dbExecute($base, 'UPDATE constructions SET generateur=default, producteur=default,
    pointsProducteur=default,pointsProducteurRestants=default, pointsCondenseur=default,
    pointsCondenseurRestants=default,champdeforce=default, lieur=default,ionisateur=default,
    depot=1, stabilisateur=default, condenseur=0, coffrefort=0, formation=0, ...');
// NOTE: spec_combat, spec_economy, spec_research are NOT reset
```

If specializations are intended to be "per-season" choices (which the name "irreversible" implies within a season), they persist incorrectly into the next season.

**Fix:** Add `spec_combat=0, spec_economy=0, spec_research=0` to the constructions UPDATE.

---

### SEASON-CROSS-009 -- HIGH -- cours (Market Price History) Table Not Cleared

**File:** `player.php:793-838` (absence of `DELETE FROM cours`)
**Severity:** HIGH

**Problem:** The `cours` table stores market price history entries for the chart on `marche.php`. `remiseAZero()` does not truncate or delete from this table. After a season reset:

1. All resources are reset to defaults (prices should start at 1.0)
2. The market price chart (`marche.php:578`: `SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1000`) shows previous season's prices mixed with new season data
3. The latest `cours` row (used by `marche.php:10` for current prices) contains end-of-season prices, not the baseline 1.0

This means the new season starts with the old season's market prices, giving unfair advantages to players who understand the carryover.

**Fix:** Add to `remiseAZero()`:
```php
dbExecute($base, 'DELETE FROM cours');
$defaultPrices = implode(',', array_fill(0, count($nomsRes), '1'));
dbExecute($base, 'INSERT INTO cours VALUES(default, ?, ?)', 'si', $defaultPrices, time());
```

---

### SEASON-CROSS-010 -- HIGH -- Email Loop Can Timeout, Leaving Game Permanently Locked

**File:** `basicprivatephp.php:234-288`
**Severity:** HIGH

**Problem:** After `remiseAZero()` completes (line 221), the code enters a `while` loop sending individual emails to every registered player via PHP's `mail()` function (line 287). Each `mail()` call blocks on the local MTA. With 100+ players, this can take 30-60+ seconds.

If PHP's `max_execution_time` is reached during this loop:
1. The script dies
2. `remiseAZero()` has already completed -- game data is wiped
3. `UPDATE statistiques SET maintenance = 0` on line 292 NEVER executes
4. The game stays in maintenance=1 forever
5. Every player sees "Une nouvelle partie recommencera dans 24 heures" permanently
6. Only manual DB intervention can fix it

The advisory lock (`RELEASE_LOCK`) on line 295 also never executes, though MySQL releases session-level locks when the connection closes.

**Fix:** Move `UPDATE statistiques SET maintenance = 0` to BEFORE the email loop, or send emails asynchronously (queue to a file, process with cron).

---

### SEASON-CROSS-011 -- HIGH -- Admin Reset Has No Pre-Reset Steps (Prestige, VP, Archives)

**File:** `admin/index.php:45-48`
**Severity:** HIGH

**Problem:** The admin panel's "Remise a zero" button directly calls `remiseAZero()`:

```php
if (isset($_POST['miseazero'])) {
    remiseAZero();
}
```

This skips ALL pre-reset steps that `basicprivatephp.php` performs:
- No victory point awards
- No alliance victory point awards
- No prestige point awards
- No data archiving to `parties` table
- No winner announcement news entry
- No email notifications
- No maintenance mode toggle
- No advisory lock

An admin clicking this button permanently destroys an entire season's progress without any record or reward.

**Fix:** Either:
1. Replace with a proper season-end sequence that includes all steps
2. Add a confirmation dialog + audit log
3. Remove the button entirely and rely on the automatic system

---

### SEASON-CROSS-012 -- HIGH -- Rankings Not Frozen/Snapshotted Before VP Awards

**File:** `basicprivatephp.php:192-209`
**Severity:** HIGH

**Problem:** Victory points are awarded based on live `totalPoints` rankings queried at the moment of reset:

```php
$classement = dbQuery($base, 'SELECT * FROM autre ORDER BY totalPoints DESC');
$c = 1;
while ($pointsVictoire = mysqli_fetch_array($classement)) {
    ajouter('victoires', 'autre', pointsVictoireJoueur($c), $pointsVictoire['login']);
    $c++;
}
```

There is no snapshot mechanism. The rankings at the moment of reset can differ from what players saw on `classement.php` during the season because:
1. `updateRessources()` and `updateActions()` on lines 98-103 may have just resolved combats that changed `totalPoints`
2. `totalPoints` is modified by `ajouterPoints()` which is called by combat resolution, construction completion, and market trades
3. A concurrent request from another player could be mid-combat-resolution, changing both players' `totalPoints`

The alliance rankings are also live-queried (line 199) and can be affected by `recalculerStatsAlliances()` being called by `classement.php?sub=1` from another concurrent request.

**Fix:** Take a snapshot of rankings into a temporary structure before awarding, or use `SELECT ... FOR UPDATE` to lock the `autre` table during VP distribution.

---

### SEASON-CROSS-013 -- MEDIUM -- nbMessages Not Reset But messages Table Is Deleted

**File:** `player.php:799` and `player.php:817`
**Severity:** MEDIUM

**Problem:** `remiseAZero()` deletes all messages (`DELETE FROM messages` on line 817) and all rapports (`DELETE FROM rapports` on line 818), but does NOT reset `nbMessages` in the `autre` table (line 799 does not include it).

After reset:
- `autre.nbMessages` shows the count from the previous season
- The actual `messages` and `rapports` tables are empty
- The forum ranking in `classement.php?sub=3` shows inflated counts
- The tutorial mission "Ecrivez sur le forum" (`basicprivatehtml.php:34`) uses `$autre['nbMessages'] > 0`, which would be pre-satisfied from the previous season

**Fix:** Add `nbMessages=0` to the `UPDATE autre` statement in `remiseAZero()`.

---

### SEASON-CROSS-014 -- MEDIUM -- vacances Table Not Cleared on Reset

**File:** `player.php:793-838` (absence of `DELETE FROM vacances`)
**Severity:** MEDIUM

**Problem:** Players in vacation mode have entries in the `vacances` table with a `dateFin` field. `remiseAZero()` does not clean this table. After reset:
- Players who were on vacation remain on vacation in the new season
- Their `dateFin` is an absolute date from the old season, which may have already passed
- `basicprivatephp.php:110-123` handles vacation end detection, but only when the player connects

While the auto-detection code handles this gracefully (vacation ends when `dateFin` is past), the stale data is technically incorrect and could confuse debugging.

**Fix:** Add `DELETE FROM vacances` to `remiseAZero()` and `UPDATE membre SET vacance=0`.

---

### SEASON-CROSS-015 -- MEDIUM -- grades Table Not Cleared on Reset

**File:** `player.php:793-838` (absence of `DELETE FROM grades`)
**Severity:** MEDIUM

**Problem:** The `grades` table stores alliance rank/title assignments. Alliance research columns are reset to 0, but the `grades` table is not cleared. After reset, alliance members retain their previous rank titles even though the alliance state has been effectively wiped.

`grades` is only cleaned when a player is individually deleted (`supprimerJoueur()` line 768) or an alliance is deleted (`supprimerAlliance()` line 748).

**Fix:** Add `DELETE FROM grades` to `remiseAZero()`.

---

### SEASON-CROSS-016 -- MEDIUM -- Alliance pointsVictoire Not Reset

**File:** `player.php:801`
**Severity:** MEDIUM

**Problem:** Line 801 resets alliance stats:

```php
dbExecute($base, 'UPDATE alliances SET energieAlliance=0,duplicateur=0,catalyseur=0,
    fortification=0,reseau=0,radar=0,bouclier=0');
// NOTE: pointsVictoire NOT reset, pointstotaux NOT reset
```

Neither `pointsVictoire` nor `pointstotaux` are reset. `pointstotaux` will be recalculated by `recalculerStatsAlliances()` (called on next alliance ranking view), so it effectively resets. But `pointsVictoire` accumulates across seasons, similar to `victoires` in `autre`.

This may be intentional (career alliance VP), but is inconsistent -- if alliance research and resources are reset but VP is not, it creates a confusing hybrid state.

---

### SEASON-CROSS-017 -- MEDIUM -- news Table Not Cleared on Reset

**File:** `player.php:793-838` (absence of `DELETE FROM news`) and `basicprivatephp.php:231`
**Severity:** MEDIUM

**Problem:** The `news` table accumulates entries across seasons. `remiseAZero()` does not clean it. A new news entry is added after each reset (line 231), but old entries persist. The maintenance page (`maintenance.php:6`) shows the latest news, which works correctly. However, any news listing page shows all historical entries, mixing seasons.

**Fix:** Either clear news on reset or add a season identifier column.

---

### SEASON-CROSS-018 -- MEDIUM -- connectes Table Not Cleared on Reset

**File:** `player.php:793-838` (absence of `DELETE FROM connectes`)
**Severity:** MEDIUM

**Problem:** The `connectes` table tracks currently online players by IP. `remiseAZero()` does not clean it. While `basicprivatephp.php:88-89` auto-cleans entries older than 5 minutes, immediately after reset the table still contains entries from the maintenance period. These show stale "online" counts until they expire naturally.

**Fix:** Add `DELETE FROM connectes` to `remiseAZero()`.

---

### SEASON-CROSS-019 -- MEDIUM -- Double-Click on Admin Reset Button Can Execute Twice

**File:** `admin/index.php:45-48`
**Severity:** MEDIUM

**Problem:** The admin reset button submits a POST form. There is no idempotency guard -- a double-click, or browser retry on timeout, will call `remiseAZero()` twice. The second execution runs on already-reset data, which is mostly harmless but:
- Rebuilds HP values again (no-op with same formula)
- Deletes from empty tables (no-op)
- Re-applies prestige unlocks (may cause issues if the prestige table was also modified between clicks)

More critically, if the admin navigates away and returns, the browser may re-submit the POST.

**Fix:** Set a flag in `statistiques` at the start of `remiseAZero()` and check it at the top:

```php
function remiseAZero() {
    global $base;
    $check = dbFetchOne($base, 'SELECT maintenance FROM statistiques');
    if ($check['maintenance'] == 0) return; // Already reset or not in maintenance
    // ... rest of function ...
}
```

---

### SEASON-CROSS-020 -- MEDIUM -- Prestige calculatePrestigePoints() Medal Tier Counting Uses Raw Values Not Season-Specific

**File:** `prestige.php:58-76`
**Severity:** MEDIUM

**Problem:** `calculatePrestigePoints()` counts medal tiers based on cumulative stats like `pointsAttaque`, `pointsDefense`, `ressourcesPillees`. However, these stats represent the current season only (they are reset by `remiseAZero()`). This is actually correct for per-season prestige awards.

However, the `moleculesPerdues` counter (used in prestige.php:67) includes molecules lost to decay, not just combat. A player who builds many large, fast-decaying molecules will accumulate `moleculesPerdues` much faster than an actual combat-focused player, earning more prestige for "Pertes" tiers.

This is a balance concern rather than a bug, but it inflates prestige awards for certain playstyles disproportionately.

---

### SEASON-CROSS-021 -- MEDIUM -- Archiving Uses War Declarations That Reference Alliance IDs, Not Tags

**File:** `basicprivatephp.php:179-189`
**Severity:** MEDIUM

**Problem:** The war archiving code on line 184 queries:

```php
$req1 = dbQuery($base, 'SELECT login FROM autre WHERE idalliance = ?', 'i', $data['id']);
$nbjoueurs = mysqli_num_rows($req1);
```

But `$data['id']` here is the `declarations.id` (the war record ID), not an alliance ID. The query uses the war record's primary key as an alliance ID, which will return zero or incorrect results. This means wars with the same `id` as a valid alliance `id` will show incorrect member counts, and most wars will show `$nbjoueurs == 0`, causing the war to be silently skipped from the archive.

**Fix:** Change to query by `$data['alliance1']` or `$data['alliance2']`, or simply remove the member count filter since it's an archive record.

---

### SEASON-CROSS-022 -- MEDIUM -- Phase 1 Detection Uses date('n') Which Can Cause Issues at Year Boundary

**File:** `basicprivatephp.php:299`
**Severity:** MEDIUM

**Problem:** Phase 1 detection compares months:

```php
date('n', time()) != date('n', $debut["debut"])
```

`date('n')` returns the month number (1-12). At the December-to-January boundary, this correctly detects a month change (12 != 1). However, if the season start (`$debut["debut"]`) is from a previous year (e.g., December 2025) and the current time is January 2027, the comparison `1 != 12` triggers correctly. But if the game was offline for exactly 12 months, `1 != 1` would NOT trigger, potentially skipping a reset entirely.

More practically: if `$debut["debut"]` is very old (server was down), Phase 1 triggers correctly but the 24h window starts from a stale timestamp, potentially causing immediate Phase 2 execution.

---

### SEASON-CROSS-023 -- MEDIUM -- Email From Address Has Typo

**File:** `basicprivatephp.php:263`
**Severity:** MEDIUM (reputation/deliverability)

**Problem:** The From header has a typo:

```php
$header = "From: \"The Very Little War\"<noreply@theverylittewar.com>" . $passage_ligne;
```

`theverylittewar.com` is missing an 'l' -- should be `theverylittlewar.com`. This causes:
- Emails may be flagged as spam (domain mismatch)
- Reply-to also has the typo on line 264: `theverylittewar@gmail.com`
- SPF/DKIM validation will fail if the domain doesn't match

---

### SEASON-CROSS-024 -- LOW -- Catalyst State Not Reset

**File:** `catalyst.php` and `player.php:793-838`
**Severity:** LOW

**Problem:** The weekly catalyst system stores `catalyst` and `catalyst_week` in `statistiques`. `remiseAZero()` does not touch these columns. The catalyst auto-rotates based on the current ISO week number (catalyst.php:61-66), so it continues rotating normally across seasons.

However, if a reset happens mid-week, the catalyst effect that was active during the old season continues into the first days of the new season. For example, if "Volatilite" (+30% decay) was active when the reset happens, new players in the fresh season immediately face accelerated molecule decay before they even understand the mechanic.

**Impact:** Minor gameplay annoyance, not a data integrity issue.

---

### SEASON-CROSS-025 -- LOW -- Archived Data Format Is Brittle

**File:** `basicprivatephp.php:144-189`
**Severity:** LOW

**Problem:** The archival data is stored as formatted strings with bracket-and-comma separators:

```php
$chaine = $chaine . '[' . $data['login'] . ',' . $data['totalPoints'] . ',' . $alliance['tag'] . ',' . ...
```

If any player login or alliance tag contains a comma or bracket character, the archived data becomes unparseable. There is no escaping. While `antihtml()` sanitizes during registration, it does not strip commas or brackets.

**Fix:** Use `json_encode()` for the archive format.

---

### SEASON-CROSS-026 -- LOW -- awardPrestigePoints() Iterates All Players Without Filtering Inactive

**File:** `prestige.php:90-117`
**Severity:** LOW

**Problem:** `awardPrestigePoints()` queries ALL players:

```php
$players = dbQuery($base, 'SELECT login, totalPoints FROM autre ORDER BY totalPoints DESC');
```

It does not filter out inactive players (those who haven't logged in for 31+ days). Inactive players with `totalPoints = 0` still get a rank position, which means an active player at rank 51 might actually be rank 5 among active players. The rank bonus (line 100-108) awards less PP than deserved.

The `calculatePrestigePoints()` function does check activity (prestige.php:52-56) for the "+5 active in final week" bonus, but the rank-based bonus is calculated on absolute rank including inactives.

**Fix:** Filter the query: `WHERE totalPoints > 0` or join with `membre` to check `derniereConnexion`.

---

### SEASON-CROSS-027 -- LOW -- idalliance Not Reset in autre Table

**File:** `player.php:799`
**Severity:** LOW

**Problem:** After `remiseAZero()`, players retain their `idalliance` value in the `autre` table. Alliance research is reset to 0 (line 801), declarations are deleted (line 815), and invitations are deleted (line 816). But the player-alliance membership persists.

This appears intentional -- alliances survive across seasons. However:
- Alliance `energieAlliance` is reset to 0 (line 801)
- Alliance research is reset to 0
- Declarations (wars/pacts) are deleted
- But the alliance still has members, and `pointsVictoire` carries over

The result is alliances persist but in a partially-reset state. This is a design decision but should be documented.

---

### SEASON-CROSS-028 -- LOW -- No Audit Logging of Season Reset

**File:** `basicprivatephp.php:134-297` and `admin/index.php:45-48`
**Severity:** LOW

**Problem:** Neither the automatic nor manual reset produces any audit log entry via the `logger.php` system. The only evidence of a reset is:
- The `parties` table archive (only in automatic reset)
- The `news` table entry (only in automatic reset)
- The `statistiques.debut` timestamp update

The admin reset has no logging at all -- no `logInfo()` or `logWarn()` call.

**Fix:** Add `logInfo('SEASON', 'Season reset executed', ['trigger' => 'automatic/admin', 'winner' => $vainqueurManche])`.

---

## 3. Cross-Domain Interaction Summary

| Domain | During Maintenance Window (24h) | During Reset Execution | After Reset |
|---|---|---|---|
| **Combat** | NOT BLOCKED -- attacks can be launched and resolve normally | Combat resolution can interleave with reset SQL | Attacks in `actionsattaques` deleted; in-flight attacks lost |
| **Market** | NOT BLOCKED -- trades execute normally, affect `cours` table | Market trades can interleave | `cours` table retains old prices; new season starts with stale prices |
| **Constructions** | NOT BLOCKED -- buildings can be upgraded | Construction completions interleave | `actionsconstruction` deleted; in-progress constructions lost |
| **Formation** | NOT BLOCKED -- molecules can be formed | Formation completions interleave | `actionsformation` deleted; in-progress formations lost |
| **Alliance** | NOT BLOCKED -- research, donations work | Alliance state partially reset | Alliance persists with members but 0 research/energy |
| **Prestige** | N/A | Prestige awarded before reset | Prestige table preserved correctly (cross-season) |
| **Forum** | NOT BLOCKED | Messages deleted but nbMessages counter kept | Ghost counter mismatch |
| **Vacation** | Players stay in vacation | `vacances` not cleared | Vacation mode persists from old season |

---

## 4. Tables Reset vs Not Reset

### Tables CORRECTLY Reset:
| Table | Reset Method | Line |
|---|---|---|
| `autre` | UPDATE SET columns=0/default | player.php:799 |
| `constructions` | UPDATE SET columns=default | player.php:800 |
| `alliances` | UPDATE SET research=0 | player.php:801 |
| `molecules` | UPDATE SET formule="Vide", nombre=0 | player.php:802 |
| `membre` | UPDATE timestamp, x=-1000, y=-1000 | player.php:803, 825 |
| `ressources` | UPDATE SET columns=default | player.php:813-814 |
| `declarations` | DELETE | player.php:815 |
| `invitations` | DELETE | player.php:816 |
| `messages` | DELETE | player.php:817 |
| `rapports` | DELETE | player.php:818 |
| `actionsconstruction` | DELETE | player.php:819 |
| `actionsformation` | DELETE | player.php:820 |
| `actionsenvoi` | DELETE | player.php:821 |
| `actionsattaques` | DELETE | player.php:822 |
| `statistiques` | UPDATE nbDerniere, tailleCarte | player.php:824 |
| `attack_cooldowns` | DELETE | player.php:837 |

### Tables NOT Reset (Missing from remiseAZero):
| Table | Severity | Impact |
|---|---|---|
| `cours` | HIGH | Stale market prices carry into new season |
| `news` | MEDIUM | Old announcements accumulate |
| `connectes` | MEDIUM | Stale online data |
| `vacances` | MEDIUM | Players stuck in vacation mode |
| `grades` | MEDIUM | Stale alliance ranks |
| `sujets` | LOW | Forum topics persist (probably intentional) |
| `reponses` | LOW | Forum replies persist (probably intentional) |
| `statutforum` | LOW | Forum read status persists |
| `moderation` | LOW | Admin moderation log persists |
| `prestige` | N/A | Intentionally preserved (cross-season) |
| `parties` | N/A | Intentionally preserved (archive) |

### Columns NOT Reset Within Reset Tables:
| Table.Column | Severity | Impact |
|---|---|---|
| `autre.victoires` | HIGH | VP accumulates across seasons |
| `autre.nbMessages` | MEDIUM | Ghost counter after messages deleted |
| `autre.idalliance` | LOW | Alliance membership persists (likely intentional) |
| `alliances.pointsVictoire` | MEDIUM | Alliance VP accumulates |
| `alliances.pointstotaux` | LOW | Recalculated on next view |
| `molecules.isotope` | HIGH | Ghost isotope choice persists |
| `constructions.spec_combat` | HIGH | Specialization persists |
| `constructions.spec_economy` | HIGH | Specialization persists |
| `constructions.spec_research` | HIGH | Specialization persists |
| `membre.vacance` | MEDIUM | Vacation flag persists |

---

## 5. Priority Fix Order

### Immediate (CRITICAL):

1. **SEASON-CROSS-001**: Block all game actions during maintenance. Add `header('Location: maintenance.php'); exit();` after maintenance check.
2. **SEASON-CROSS-002**: Wrap `remiseAZero()` body in `withTransaction()`.
3. **SEASON-CROSS-003**: Move maintenance check before `updateRessources()`/`updateActions()`.

### High Priority:

4. **SEASON-CROSS-010**: Move `maintenance=0` update to before the email loop (or make emails asynchronous).
5. **SEASON-CROSS-011**: Add prestige/VP/archive steps to admin reset, or remove the button.
6. **SEASON-CROSS-006**: Add `$GLOBALS['_initPlayerCache'] = [];` at start of `remiseAZero()`.
7. **SEASON-CROSS-007**: Add `isotope=0` to molecules UPDATE.
8. **SEASON-CROSS-008**: Add `spec_combat=0, spec_economy=0, spec_research=0` to constructions UPDATE.
9. **SEASON-CROSS-009**: Add `DELETE FROM cours` + baseline insert.
10. **SEASON-CROSS-005**: Add idempotency guard for prestige awards.

### Medium Priority:

11. **SEASON-CROSS-013**: Add `nbMessages=0` to autre UPDATE.
12. **SEASON-CROSS-014**: Add `DELETE FROM vacances` and `UPDATE membre SET vacance=0`.
13. **SEASON-CROSS-015**: Add `DELETE FROM grades`.
14. **SEASON-CROSS-016**: Document or reset `pointsVictoire` in alliances.
15. **SEASON-CROSS-021**: Fix war archiving query (`$data['id']` vs alliance ID).
16. **SEASON-CROSS-023**: Fix email domain typo.

### Low Priority:

17. **SEASON-CROSS-019**: Add `DELETE FROM connectes`.
18. **SEASON-CROSS-025**: Use `json_encode()` for archive format.
19. **SEASON-CROSS-026**: Filter inactive players from prestige ranking.
20. **SEASON-CROSS-028**: Add audit logging for season resets.
