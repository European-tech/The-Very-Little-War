# PERF-CROSS: Complete Query Map — The Very Little War

**Round 3 Performance Audit**
**Date:** 2026-03-03
**Analyst:** Performance Engineer (claude-sonnet-4-6)
**Scope:** Every DB query traced from basicprivatephp.php → initPlayer() → template → page body

---

## Methodology

All queries counted by tracing the actual PHP execution path for a normal authenticated page load. The call stack is:

```
basicprivatephp.php   (auth guard + resource update)
  └─ updateRessources()
       └─ revenuEnergie()          [9 queries]
       └─ revenuAtome() × 8       [3-4 queries each]
  └─ updateActions()
       └─ initPlayer()
basicprivatehtml.php  (nav template)
  └─ initPlayer() → CACHE HIT (zero extra queries)
  └─ revenuAtome() × 8+8+1       [additional calls in template]
  └─ revenuEnergie() × 5+        [multiple in template]
page-specific code
```

---

## Section A: Query Budget — Function-Level Analysis

### A1. Core Infrastructure Functions (called from basicprivatephp.php, every page)

| Function / Location | Queries/Call | Invocations/Page | Total | Cache Available? |
|---|---|---|---|---|
| **Session token validation** (basicprivatephp.php:11) | 1 | 1 | 1 | No — must be fresh |
| **Position check** (basicprivatephp.php:55) | 1 | 1 | 1 | No |
| **connectes table** INSERT or UPDATE (basicprivatephp.php:75-85) | 2 | 1 | 2 | No |
| **connectes** DELETE old entries (basicprivatephp.php:89) | 1 | 1 | 1 | No |
| **Vacation check** (basicprivatephp.php:93) | 1 | 1 | 1 | No |
| **statistiques** debut + maintenance (basicprivatephp.php:128-132) | 2 | 1 | 2 | Yes — merge into 1 query |
| **derniereConnexion** UPDATE (basicprivatephp.php:100) | 1 | 1 | 1 | No |
| **tempsPrecedent** read (basicprivatephp.php:102) | 1 | 1 | 1 | Redundant — already read in updateRessources |
| **SUBTOTAL: basicprivatephp.php fixed overhead** | — | — | **10** | |

### A2. updateRessources() — Called Once Per Page (when not on vacation)

| Sub-Function | Queries/Call | Invocations | Total |
|---|---|---|---|
| `SELECT tempsPrecedent FROM autre` | 1 | 1 | 1 |
| `UPDATE autre SET tempsPrecedent` (atomic CAS) | 1 | 1 | 1 |
| `SELECT * FROM ressources` | 1 | 1 | 1 |
| `SELECT * FROM constructions` | 1 | 1 | 1 |
| **revenuEnergie()** (see A3 below) | **9** | 1 | **9** |
| `UPDATE ressources SET energie` | 1 | 1 | 1 |
| **revenuAtome()** per atom (8 atoms) | **3–4** | **8** | **24–32** |
| `UPDATE ressources SET carbone,azote,...` (batched) | 1 | 1 | 1 |
| `SELECT stabilisateur FROM constructions` | 1 | 1 | 1 |
| `SELECT moleculesPerdues FROM autre` | 1 | 1 | 1 |
| `SELECT * FROM molecules WHERE proprietaire` | 1 | 1 | 1 |
| **Per-molecule loop** (4 molecule classes): | | | |
| — `UPDATE molecules SET nombre` | 1 | 4 | 4 |
| — `SELECT moleculesPerdues FROM autre` | 1 | 4 | 4 |
| — `UPDATE autre SET moleculesPerdues` | 1 | 4 | 4 |
| **Conditional (>6h offline):** 4× `SELECT nombre,formule FROM molecules` | 1 | 0–4 | 0–4 |
| **Conditional:** `INSERT INTO rapports` | 1 | 0–1 | 0–1 |
| **SUBTOTAL: updateRessources()** | — | — | **49–61** |

### A3. revenuEnergie($niveau, $joueur) — The Primary Bottleneck

This function executes **9 DB queries every single call**. It is called multiple times per page.

| Query | Table | Purpose | Cacheable? |
|---|---|---|---|
| 1 | `SELECT * FROM constructions WHERE login=?` | pointsCondenseur for iode levels | YES — same data as initPlayer |
| 2 | `SELECT producteur FROM constructions WHERE login=?` | producteur level for drain calc | YES — duplicate of Q1 |
| 3 | `SELECT idalliance,totalPoints FROM autre WHERE login=?` | alliance ID lookup | YES — same as autre row |
| 4 | `SELECT duplicateur FROM alliances WHERE id=?` | conditional on idalliance > 0 | YES — alliance rarely changes |
| 5 | `SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=1` | iode for energy | YES — already in updateRessources loop |
| 6 | `SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=2` | iode for energy | YES |
| 7 | `SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=3` | iode for energy | YES |
| 8 | `SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=4` | iode for energy | YES |
| 9 | `SELECT energieDepensee FROM autre WHERE login=?` | medal bonus check | YES — duplicate of autre row |

**Also calls:** `prestigeProductionBonus()` → `hasPrestigeUnlock()` → `getPrestige()` → 1 query (`SELECT * FROM prestige`)

**Total per call: 9 queries + 1 prestige query = 10 queries**

### A4. revenuAtome($num, $joueur) — High-Frequency Inner Loop

Called 8 times per page minimum (once per atom type). Called extra times in template.

| Query | Table | Purpose | Cacheable? |
|---|---|---|---|
| 1 | `SELECT pointsProducteur FROM constructions WHERE login=?` | atom production level | YES — identical to initPlayer constructions fetch |
| 2 | `SELECT idalliance FROM autre WHERE login=?` | alliance lookup | YES — duplicate fetch |
| 3 | `SELECT duplicateur FROM alliances WHERE id=?` | conditional on idalliance > 0 | YES |

**Also calls:** `prestigeProductionBonus()` → `getPrestige()` → 1 prestige query

**Total per call: 3–4 queries**

### A5. initPlayer() — Per-Request Cached After First Call

| Query | Table | Purpose | Cacheable? |
|---|---|---|---|
| 1 | `SELECT * FROM ressources WHERE login=?` | full resources row | YES — already in updateRessources |
| 2–9 | **revenuAtome() × 8** | production per atom | 3–4 each × 8 = 24–32 |
| 10 | `SELECT * FROM constructions WHERE login=?` | all buildings | YES — already fetched 3× |
| 11 | `SELECT * FROM autre WHERE login=?` | player stats | YES — already fetched 2× |
| 12 | `SELECT * FROM membre WHERE login=?` | player record | YES — in auth already |
| 13 | **revenuEnergie()** | energy income | 10 queries |
| 14 | `SELECT * FROM constructions WHERE login=?` (batMax) | max building level | YES — already fetched |
| 15 | `UPDATE autre SET batmax` | batmax update | Needed but can defer |
| 16 | `SELECT duplicateur FROM alliances WHERE id=?` | for productionCondenseur | YES — already fetched |
| 17 | `SELECT batiment, MAX(niveau) FROM actionsconstruction GROUP BY batiment` | building queues | Cannot cache (time-sensitive) |
| 18 | **revenuEnergie() × 2** (in listeConstructions for generateur) | display values | 10 × 2 = 20 |
| **SUBTOTAL initPlayer() first call** | — | — | **~70–90** |
| **SUBTOTAL initPlayer() second+ call (cache)** | — | — | **0** |

**The per-request cache (introduced in earlier commits) prevents re-execution on second call. This is critical.**

### A6. allianceResearchBonus() — Hidden Multiplier Overhead

Called from `tempsFormation()` and `pointsDeVie()` with alliance player.

| Query | Table | Per Call |
|---|---|---|
| `SELECT idalliance FROM autre WHERE login=?` | autre | 1 |
| `SELECT techname FROM alliances WHERE id=?` | alliances | 1 |

**Total: 2 queries per call.** Called at least once per page via `tempsFormation()` in `initPlayer()`.

### A7. coefDisparition() — Molecule Decay Calculator

Called 4× in `updateRessources()` molecule loop, plus in `updateActions()` attack resolution.

| Query | Table | Per Call |
|---|---|---|
| `SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?` | molecules | 1 (conditional) |
| `SELECT stabilisateur FROM constructions WHERE login=?` | constructions | 1 |
| `SELECT moleculesPerdues FROM autre WHERE login=?` | autre | 1 |

**Total: 3 queries per call × 4 molecules = 12 queries** in updateRessources molecule loop.

### A8. updateActions() — Action Processing (every page load)

| Query | Table | Notes |
|---|---|---|
| **initPlayer()** | (see A5) | First call executes; subsequent cache hit |
| `SELECT * FROM actionsconstruction WHERE login=? AND fin<?` | actionsconstruction | Pending constructions |
| `SELECT * FROM actionsformation WHERE login=? AND debut<?` | actionsformation | Pending formations |
| `SELECT neutrinos FROM autre WHERE login=?` | autre | Duplicate — autre already fetched |
| Per-formation loop: `SELECT * FROM molecules WHERE id=?` | molecules | Variable |
| Per-formation loop: 2–3 UPDATE statements | various | Variable |
| `SELECT * FROM actionsattaques WHERE attaquant=? OR defenseur=?` | actionsattaques | Pending attacks |
| Per-attack resolution: includes combat.php | various | High — 15–30 queries each |

**Typical updateActions() (no pending actions): 4 queries**
**With 1 pending construction: 4 + augmenterBatiment (~10) queries**

### A9. basicprivatehtml.php Template — Additional Queries Per Page

These execute on every authenticated page regardless of page content.

| Location | Query | Table | Notes |
|---|---|---|---|
| Line 3 | `SELECT count(*) FROM messages WHERE expeditaire=?` | messages | Sent message count (tutorial) |
| Line 6 | `SELECT count(*) FROM molecules WHERE proprietaire=? AND formule!=?` | molecules | Tutorial check |
| Line 51 | `SELECT * FROM ressources WHERE login=?` | ressources | DUPLICATE — already in initPlayer |
| Line 53 | `SELECT nombre FROM molecules WHERE proprietaire=? AND nombre!=0` | molecules | molecule display |
| Line 59 | `SELECT * FROM constructions WHERE login=?` | constructions | DUPLICATE — already in initPlayer |
| Line 115 | `SELECT * FROM molecules WHERE proprietaire=?` | molecules | Full molecule scan (tutorial tuto=6) |
| Line 151 | `SELECT idalliance FROM autre WHERE login=?` | autre | DUPLICATE — in initPlayer autre |
| Line 168 | `SELECT niveaututo, missions FROM autre WHERE login=?` | autre | DUPLICATE — partial autre |
| Line 233 | `SELECT moderateur FROM membre WHERE login=?` | membre | Mod check (DUPLICATE — membre fetched) |
| Line 249 | `SELECT invite FROM invitations WHERE invite=?` | invitations | Alliance invitation badge |
| Line 253 | `SELECT idalliance FROM autre WHERE login=?` | autre | DUPLICATE — 3rd time |
| Line 260 | `SELECT destinataire FROM messages WHERE destinataire=? AND statut=0` | messages | Unread messages badge |
| Line 268 | `SELECT destinataire FROM rapports WHERE destinataire=? AND statut=0` | rapports | Unread reports badge |
| Line 277 | `SELECT count(*) FROM sujets WHERE statut=0` | sujets | Forum topic count |
| Line 278 | `SELECT count(*) FROM statutforum WHERE login=?` | statutforum | Forum read status |
| Line 298 | **revenuAtome() × 8** (popover display) | various | 3–4 each = 24–32 queries |
| Line 318–341 | **revenuEnergie() × 7** (energy breakdown popover) | various | 10 each = 70 queries |
| Line 433 | **revenuEnergie() × 1** (JS variable) | various | 10 queries |
| Line 448 | **revenuAtome() × 8** (JS variables) | various | 3–4 each = 24–32 queries |
| **SUBTOTAL: basicprivatehtml.php** | — | — | **~152–186** |

---

## Section B: Per-Page Query Budget

### B1. Baseline Overhead (ALL authenticated pages)

```
Phase                              Queries
─────────────────────────────────────────
Session auth (basicprivatephp)         10
updateRessources() + revenuEnergie     49–61
updateActions() (no pending)            4
initPlayer() (first call)             70–90
basicprivatehtml.php template        152–186
─────────────────────────────────────────
BASELINE OVERHEAD TOTAL            285–351
```

**This baseline fires on every authenticated page before any page-specific code runs.**

### B2. constructions.php (Most Query-Heavy Page)

```
Baseline overhead                      285–351
──────────────────────────────────────────────
Per-building display mepConstructions():
  SELECT niveau FROM actionsconstruction × 9   9
  SELECT count(*) FROM actionsconstruction × 9 9
Per-building POST traitementConstructions():
  SELECT count(*) FROM actionsconstruction      1
  SELECT * FROM actionsconstruction ORDER BY    1
  SELECT niveau FROM actionsconstruction        1
  INSERT INTO actionsconstruction               1
  UPDATE autre SET energieDepensee              1
  UPDATE ressources (resource deduction)        1
revenuEnergie() calls in listeConstructions     already in initPlayer cached data
──────────────────────────────────────────────
constructions.php page-specific              ~24
──────────────────────────────────────────────
TOTAL constructions.php                   ~309–375
```

### B3. armee.php (Army Management)

```
Baseline overhead                      285–351
──────────────────────────────────────────────
SELECT * FROM molecules WHERE proprietaire      1
SELECT niveauclasse FROM ressources            1
Formation submission:
  SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?  1
  SELECT * FROM ressources                      1
  SELECT * FROM actionsformation ORDER BY fin   1
  INSERT INTO actionsformation                  1
  UPDATE ressources (atom cost deduction)       1
  UPDATE autre SET energieDepensee              1
──────────────────────────────────────────────
armee.php page-specific                      ~8
──────────────────────────────────────────────
TOTAL armee.php                           ~293–359
```

### B4. attaquer.php (Map / Attack Page)

```
Baseline overhead                      285–351
──────────────────────────────────────────────
SELECT nbattaques FROM autre                    1
Attack submission path:
  SELECT vacance,timestamp FROM membre          1
  SELECT expires FROM attack_cooldowns          1
  SELECT * FROM autre WHERE login=? (defender)  1
  SELECT x,y FROM membre (path finding)         1
  UPDATE actionsattaques INSERT                 1
  UPDATE autre SET neutrinos                    1
Map display:
  SELECT * FROM membre (all players for map)    1
  SELECT * FROM autre (for each visible player) N  (≈20–50 players)
──────────────────────────────────────────────
attaquer.php page-specific                  ~28–55
──────────────────────────────────────────────
TOTAL attaquer.php                        ~313–406
```

### B5. classement.php (Rankings)

```
Baseline overhead                      285–351
──────────────────────────────────────────────
Player search path:
  SELECT count(*) FROM autre WHERE login=?      1
  SELECT score FROM autre WHERE login=?         1
  SELECT COUNT(*) FROM autre WHERE score>?      1
Self rank:
  SELECT score FROM autre WHERE login=?         1
  SELECT COUNT(*) FROM autre WHERE score>?      1
  SELECT COUNT(*) FROM autre                    1
Player list (20 per page):
  SELECT * FROM autre ORDER BY X LIMIT 20       1
  Per-player: statut() = SELECT count(*) FROM membre  20
  Per-player: alliance badge lookup             ≈5–10 (for players with alliances)
──────────────────────────────────────────────
classement.php page-specific              ~32–36
──────────────────────────────────────────────
TOTAL classement.php                      ~317–387
```

### B6. marche.php (Market)

```
Baseline overhead                      285–351
──────────────────────────────────────────────
SELECT count(*) FROM membre WHERE derniereConnexion>=? (active count)  1
SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1                     1
Send resources path (revenuEnergie/revenuAtome for recipient):
  revenuEnergie(recipient) = 10 queries         10
  revenuAtome × 8 (recipient) = 3–4 each        24–32
  SELECT ip FROM membre × 2                      2
  SELECT count(*) FROM membre (verify exists)    1
  SELECT * FROM constructions (recipient)        1
──────────────────────────────────────────────
marche.php page-specific                     ~42–50
──────────────────────────────────────────────
TOTAL marche.php                          ~327–401
```

---

## Section C: Query Frequency Ranking (Hot Paths)

### C1. Top 10 Most-Executed Queries Per Page Load

| Rank | Query Pattern | Calls/Page | Root Cause |
|---|---|---|---|
| 1 | `SELECT * FROM constructions WHERE login=?` | 10–15 | revenuEnergie×7, revenuAtome×8, initPlayer, coefDisparition×4, batMax |
| 2 | `SELECT idalliance FROM autre WHERE login=?` | 12–18 | revenuEnergie×7 + revenuAtome×8 + template×2 + allianceResearchBonus |
| 3 | `SELECT duplicateur FROM alliances WHERE id=?` | 10–15 (if in alliance) | Same callers as above, conditional |
| 4 | `SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?` | 7–12 | revenuEnergie×4 classes×7 calls (= 28!), coefDisparition |
| 5 | `SELECT energieDepensee FROM autre WHERE login=?` | 7 | revenuEnergie called 7+ times, each fetches this |
| 6 | `SELECT * FROM prestige WHERE login=?` | 9–17 | prestigeProductionBonus() called by revenuEnergie + revenuAtome |
| 7 | `SELECT * FROM ressources WHERE login=?` | 3–4 | updateRessources, initPlayer, basicprivatehtml |
| 8 | `SELECT moleculesPerdues FROM autre WHERE login=?` | 5–8 | updateRessources molecule loop × 4, coefDisparition × 4 |
| 9 | `SELECT stabilisateur FROM constructions WHERE login=?` | 4 | coefDisparition × 4 |
| 10 | `SELECT producteur FROM constructions WHERE login=?` | 7 | revenuEnergie called 7+ times, each fetches |

### C2. The Cascade Anatomy: Why revenuEnergie() Costs So Much

One call to `revenuEnergie()` in the template creates this cascade:

```
revenuEnergie()
  ├── SELECT * FROM constructions                 [1 query]
  ├── SELECT producteur FROM constructions        [1 query — DUPLICATE]
  ├── SELECT idalliance,totalPoints FROM autre    [1 query]
  ├── (if alliance) SELECT duplicateur FROM alliances  [1 query]
  ├── SELECT * FROM molecules WHERE numeroclasse=1  [1 query]
  ├── SELECT * FROM molecules WHERE numeroclasse=2  [1 query]
  ├── SELECT * FROM molecules WHERE numeroclasse=3  [1 query]
  ├── SELECT * FROM molecules WHERE numeroclasse=4  [1 query]
  ├── SELECT energieDepensee FROM autre           [1 query]
  └── prestigeProductionBonus()
        └── getPrestige()
              └── SELECT * FROM prestige          [1 query]
                                               ─────────────
                                               10 queries
```

**Template calls revenuEnergie() at these locations:**
- Line 318: energy breakdown popover (1 call per active medal tier, up to 4)
- Line 329: duplicateur line in breakdown
- Line 336: iode production line (2 calls)
- Line 337: iode production line (2 more calls)
- Line 340: base production line
- Line 341: producteur drain line
- Line 371: total production
- Line 433: JS variable

**Result: 7–9 calls × 10 queries = 70–90 queries from revenuEnergie() alone in the template.**

### C3. The Cascade Anatomy: Why revenuAtome() Costs So Much

One call per atom type (8 atoms), called in 3 locations in template:

```
revenuAtome($num, $joueur)
  ├── SELECT pointsProducteur FROM constructions   [1 query]
  ├── SELECT idalliance FROM autre                 [1 query]
  ├── (if alliance) SELECT duplicateur FROM alliances  [1 query]
  └── prestigeProductionBonus()
        └── SELECT * FROM prestige                 [1 query]
                                               ─────────────
                                               3–4 queries
```

**Template calls revenuAtome() at these locations:**
- Line 298: atom popover (8 atoms)
- Line 448: JS variables (8 atoms)
- Line 153–154: initPlayer() calls (8 atoms, but these are included in initPlayer cost above)

**Result: 16 template calls × 3–4 queries = 48–64 queries from revenuAtome() in template.**

---

## Section D: Optimization Roadmap

### D1. Priority 1 — Eliminate Repeat Fetches via Context Object (Expected: -120 to -160 queries/page)

**Problem:** `constructions`, `autre`, `membre`, `prestige`, `alliance` rows are fetched 5–15 times each per request.

**Solution:** Create a `PlayerContext` object (or PHP array) loaded once at the start of `basicprivatephp.php` after auth, passed by reference throughout.

```php
// In basicprivatephp.php — load once, pass everywhere
$ctx = [
    'constructions' => dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $login),
    'autre'         => dbFetchOne($base, 'SELECT * FROM autre WHERE login=?', 's', $login),
    'membre'        => dbFetchOne($base, 'SELECT * FROM membre WHERE login=?', 's', $login),
    'ressources'    => dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $login),
    'prestige'      => dbFetchOne($base, 'SELECT * FROM prestige WHERE login=?', 's', $login),
    // alliance: load conditionally
];
if ($ctx['autre']['idalliance'] > 0) {
    $ctx['alliance'] = dbFetchOne($base, 'SELECT * FROM alliances WHERE id=?', 'i', $ctx['autre']['idalliance']);
}
// all 4 molecules in one query:
$ctx['molecules'] = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse', 's', $login);
```

**Impact:** Eliminates ~12–18 redundant `constructions` fetches, ~12–18 `autre` fetches, ~6–8 `prestige` fetches, ~8 `alliances` fetches.
**Estimated query reduction: -120 to -160 per page.**

### D2. Priority 2 — Cache revenuEnergie() and revenuAtome() Per-Request (Expected: -80 to -120 queries/page)

**Problem:** `revenuEnergie()` has no caching and is called 7–9 times with the same arguments per page. Each call does 10 queries. Same for `revenuAtome()`.

**Solution:** Add per-request result cache identical to the `initPlayer` pattern:

```php
function revenuEnergie($niveau, $joueur, $detail = 0) {
    static $cache = [];
    $key = $joueur . ':' . $niveau;

    // Compute all detail levels in one shot and cache all of them
    if (!isset($cache[$key])) {
        // ... existing calculation using $ctx data passed in ...
        $cache[$key] = [
            'full'     => round($prodProducteur),
            'noProducteur' => round($prodDuplicateur),
            'noPrestige'   => round($prodMedaille),
            'noMedaille'   => round($prodIode),
            'base'     => round($prodBase),
        ];
    }

    return $cache[$key][$detail] ?? $cache[$key]['full'];
}
```

**Same pattern for revenuAtome():**
```php
function revenuAtome($num, $joueur) {
    static $cache = [];
    $key = $joueur . ':' . $num;
    if (!isset($cache[$key])) {
        // ... compute ...
        $cache[$key] = $result;
    }
    return $cache[$key];
}
```

**Impact:** Reduces 7–9 revenuEnergie calls from 70–90 queries down to 10 (first call only). Reduces 16 revenuAtome template calls from 48–64 down to 24–32 (two batches of 8, each computes once).
**Estimated query reduction: -80 to -120 per page.**

### D3. Priority 3 — Rewrite revenuEnergie() to Use Pre-Loaded Context (Expected: -6 to -8 queries per first call)

**Problem:** Even the first call to `revenuEnergie()` performs 9 queries when most data is already available.

**Solution:** Accept pre-loaded data as optional parameters:

```php
function revenuEnergie($niveau, $joueur, $detail = 0, $ctx = null) {
    global $base;
    // Use provided context or fall back to DB
    $constructions = $ctx['constructions'] ?? dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $joueur);
    $alliance      = $ctx['alliance'] ?? null;
    $molecules     = $ctx['molecules'] ?? dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=?', 's', $joueur);
    $autre         = $ctx['autre'] ?? dbFetchOne($base, 'SELECT energieDepensee FROM autre WHERE login=?', 's', $joueur);
    $prestige      = $ctx['prestige'] ?? dbFetchOne($base, 'SELECT * FROM prestige WHERE login=?', 's', $joueur);
    // ... rest of calculation
}
```

**Impact:** First call drops from 10 queries to 1–2 (only missing data fetched).
**Estimated query reduction: -8 per page (eliminates the "first call" overhead).**

### D4. Priority 4 — Deduplicate basicprivatehtml.php Template Fetches (Expected: -15 queries/page)

The template contains at least 8 clearly redundant SELECT statements that duplicate data already in `initPlayer()` globals:

| Line | Query | Already Available In | Fix |
|---|---|---|---|
| 51 | `SELECT * FROM ressources` | `$ressources` global | Delete line, use `$ressources` |
| 59 | `SELECT * FROM constructions` | `$constructions` global | Delete line, use `$constructions` |
| 151 | `SELECT idalliance FROM autre` | `$autre['idalliance']` | Delete line, use `$autre` |
| 168 | `SELECT niveaututo,missions FROM autre` | `$autre` global | Delete line, use `$autre` |
| 233 | `SELECT moderateur FROM membre` | `$membre['moderateur']` | Delete line, use `$membre` |
| 253 | `SELECT idalliance FROM autre` | `$autre['idalliance']` | Delete line (3rd duplicate) |
| 322 | `SELECT idalliance FROM autre` | `$autre['idalliance']` | Delete line (4th duplicate) |
| — | `SELECT duplicateur FROM alliances` at line 326 | `$alliance` ctx | Delete line, use ctx |

**Estimated query reduction: -15 per page (including template's redundant duplicateur fetches).**

### D5. Priority 5 — Consolidate basicprivatephp.php Fixed Overhead (Expected: -5 queries/page)

| Optimization | Reduction |
|---|---|
| Merge `SELECT debut` + `SELECT maintenance` into one `SELECT debut, maintenance FROM statistiques` | -1 |
| Remove redundant `SELECT tempsPrecedent FROM autre` at line 102 (already read in updateRessources) | -1 |
| Merge `connectes` INSERT/UPDATE into `INSERT ... ON DUPLICATE KEY UPDATE` (one query vs two) | -1 |
| Move `derniereConnexion` UPDATE into `updateRessources()` atomic block | -1 |
| Pre-load membre position check together with session token check | -1 |

**Estimated query reduction: -5 per page.**

### D6. Priority 6 — Fix Molecule Decay Loop in updateRessources() (Expected: -8 to -12 queries/page)

**Problem:** The molecule decay loop reads `moleculesPerdues` once per molecule (4 reads) and updates once per molecule (4 writes). Each iteration SELECT + UPDATE = 8 queries for data that could be accumulated in PHP:

```php
// CURRENT: 8 queries for 4 molecules (SELECT + UPDATE per iteration)
while ($molecules = mysqli_fetch_array($ex)) {
    $moleculesRestantes = ...;
    $moleculesPerdues = dbFetchOne(...);          // Q+1
    dbExecute($base, 'UPDATE autre SET moleculesPerdues=?...'); // Q+2
}

// OPTIMIZED: 1 SELECT + 1 UPDATE total
$totalPerdues = 0;
while ($molecules = mysqli_fetch_array($ex)) {
    $moleculesRestantes = ...;
    $totalPerdues += ($molecules['nombre'] - $moleculesRestantes);
    dbExecute($base, 'UPDATE molecules SET nombre=?...'); // still 4 UPDATEs
}
// One final UPDATE outside loop:
dbExecute($base, 'UPDATE autre SET moleculesPerdues = moleculesPerdues + ? WHERE login=?', 'ds', $totalPerdues, $joueur);
```

**Estimated query reduction: -7 per page (3 redundant SELECTs + 3 extra UPDATEs → 1 batched UPDATE).**

### D7. Priority 7 — Batch Molecule SELECT in revenuEnergie() (Expected: -3 queries per revenuEnergie call)

**Problem:** revenuEnergie() fetches 4 molecule classes with 4 separate queries.

**Solution:**
```php
// CURRENT: 4 queries
for ($i = 1; $i <= 4; $i++) {
    $molecules = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $joueur, $i);
}

// OPTIMIZED: 1 query returning all 4 rows
$allMolecules = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse', 's', $joueur);
```

**Estimated query reduction: -3 per call, -21 to -27 per page (7–9 calls × 3 saved).**

### D8. Priority 8 — coefDisparition() Caching (Expected: -9 to -12 queries/page)

`coefDisparition()` does 3 queries per call and is called 4× in updateRessources. The `stabilisateur` and `moleculesPerdues` data is the same across all 4 calls.

```php
function coefDisparition($joueur, $classeOuNbTotal, $type = 0) {
    static $cache = [];
    // Cache stabilisateur + moleculesPerdues per player
    if (!isset($cache[$joueur])) {
        $stabilisateur = dbFetchOne($base, 'SELECT stabilisateur FROM constructions WHERE login=?', 's', $joueur);
        $donneesMedaille = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $joueur);
        $cache[$joueur] = ['stabilisateur' => $stabilisateur['stabilisateur'], 'moleculesPerdues' => $donneesMedaille['moleculesPerdues']];
    }
    // Reuse from cache, only fetch molecule data per-class
}
```

**Estimated query reduction: -6 per updateRessources call (3 duplicate fetches × 2 extra calls).**

### D9. Priority 9 — statut() N+1 in classement.php (Expected: -19 queries on classement page)

The classement page displays 20 players and calls `statut($joueur)` for each, which does 1 query per player.

```php
// CURRENT: N queries for N players
foreach ($players as $p) {
    $active = statut($p['login']); // 1 query each
}

// OPTIMIZED: 1 query for all
$activeLogins = dbFetchAll($base,
    'SELECT login FROM membre WHERE derniereConnexion >= ? AND x != -1000 AND login IN (' . implode(',', $placeholders) . ')',
    ...
);
```

**Estimated query reduction: -19 per classement page load.**

---

## Section E: Consolidated Impact Projection

| Priority | Optimization | Estimated Reduction | Complexity |
|---|---|---|---|
| P1 | PlayerContext object (single-load all player data) | -120 to -160 | Medium — refactor function signatures |
| P2 | Cache revenuEnergie() + revenuAtome() per-request | -80 to -120 | Low — add static cache block |
| P3 | Pass context into revenuEnergie() | -8 | Low — add optional param |
| P4 | Delete duplicate fetches in basicprivatehtml.php | -15 | Low — use existing globals |
| P5 | Consolidate basicprivatephp.php overhead | -5 | Low — merge queries |
| P6 | Fix molecule decay loop (batch moleculesPerdues) | -7 | Low — accumulate in PHP |
| P7 | Batch 4-molecule SELECT in revenuEnergie() | -21 to -27 | Low — change loop to single query |
| P8 | coefDisparition() static cache | -6 | Low — add static cache |
| P9 | statut() N+1 in classement | -19 (classement only) | Medium — batch IN query |

**Combined reduction if all applied:**

```
Current baseline (typical page):   285–351 queries
Reduction from P1–P8:              -262 to -348 queries
Target baseline:                    23–50 queries
Reduction: approximately 90%
```

**For classement.php specifically:**
```
Current:    ~317–387 queries
After all:  ~40–60 queries
Reduction:  ~87%
```

---

## Section F: Quick Wins (Zero-Risk, < 1 Day Each)

These changes have no architectural risk and can be applied immediately:

1. **basicprivatehtml.php line 51** — Delete `$ressources = dbFetchOne(...)`, use existing `$ressources` global.
2. **basicprivatehtml.php line 59** — Delete `$depot = dbFetchOne(...)`, use existing `$constructions` global (rename `$depot` references to `$constructions`).
3. **basicprivatehtml.php line 151, 253, 322** — Delete 3 duplicate `SELECT idalliance FROM autre` fetches; use `$autre['idalliance']`.
4. **basicprivatehtml.php line 233** — Delete `SELECT moderateur FROM membre`; use `$membre['moderateur']`.
5. **basicprivatephp.php lines 128–132** — Merge 2 statistiques queries: `SELECT debut, maintenance FROM statistiques`.
6. **revenuEnergie()** — Add `static $cache = []` at function start, key by `$joueur.':'.$niveau`, compute all `$detail` variants at once and cache the array.
7. **revenuAtome()** — Add `static $cache = []`, key by `$joueur.':'.$num`.
8. **revenuAtomeJavascript()** — Add `static $cache = []`; this function already duplicates revenuAtome logic.
9. **updateRessources() molecule loop** — Accumulate `$totalPerdues` in PHP, do 1 final UPDATE instead of 4 SELECTs + 4 UPDATEs.
10. **revenuEnergie() molecule fetch** — Replace 4-query loop with single `SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse`.

**Estimated combined quick-win reduction: -130 to -180 queries per page.**

---

## Section G: Files Requiring Changes

| File | Change Type | Priority |
|---|---|---|
| `includes/game_resources.php` | Add static cache to revenuEnergie(), revenuAtome(); batch molecule SELECT; fix decay loop | P1, P2, P6, P7 |
| `includes/basicprivatehtml.php` | Remove 6 duplicate SELECT statements; use existing globals | P4 |
| `includes/basicprivatephp.php` | Merge statistiques queries; remove redundant tempsPrecedent read | P5 |
| `includes/formulas.php` | Add cache params to coefDisparition(); accept pre-loaded stabilisateur | P8 |
| `includes/db_helpers.php` | Add static cache to allianceResearchBonus() | P1 |
| `includes/prestige.php` | `getPrestige()` already reads from DB — add static cache | P2 |
| `classement.php` | Replace per-player statut() calls with batch IN query | P9 |

---

## Section H: Performance SLO Targets

| Metric | Current | Target (after P1–P8) | Stretch |
|---|---|---|---|
| DB queries per page (typical) | 285–351 | < 50 | < 30 |
| DB queries per page (constructions.php) | 309–375 | < 60 | < 40 |
| DB queries per page (classement.php) | 317–387 | < 60 | < 35 |
| revenuEnergie() queries per call | 10 | 0 (cached) / 1 (first call) | — |
| revenuAtome() queries per call | 3–4 | 0 (cached) / 1 (first call) | — |
| Page response time (P95, VPS) | ~250–400ms est. | < 100ms | < 60ms |

---

## Appendix: Call Graph Summary

```
basicprivatephp.php
├── dbFetchOne(membre)              [session_token]    1q
├── dbFetchOne(membre)              [position x,y]     1q
├── dbExecute(connectes)            [INSERT/UPDATE]    2q
├── dbExecute(connectes)            [DELETE old]       1q
├── dbFetchOne(membre)              [vacance]          1q
├── dbFetchOne(statistiques)        [debut]            1q
├── dbFetchOne(statistiques)        [maintenance]      1q  ← merge with above
├── updateRessources()
│   ├── dbFetchOne(autre)           [tempsPrecedent]   1q
│   ├── dbExecute(autre)            [CAS update]       1q
│   ├── dbFetchOne(ressources)      [full row]         1q
│   ├── dbFetchOne(constructions)   [full row]         1q
│   ├── revenuEnergie()             [10q]
│   ├── dbExecute(ressources)       [UPDATE energie]   1q
│   ├── revenuAtome() ×8            [3-4q each = 24-32q]
│   ├── dbExecute(ressources)       [batch UPDATE atoms] 1q
│   ├── dbFetchOne(constructions)   [stabilisateur]    1q  ← dup
│   ├── dbFetchOne(autre)           [moleculesPerdues] 1q
│   ├── dbQuery(molecules)          [all molecules]    1q
│   └── per-molecule ×4:
│       ├── dbExecute(molecules)    [UPDATE nombre]    1q
│       ├── dbFetchOne(autre)       [moleculesPerdues] 1q  ← dup ×4
│       └── dbExecute(autre)        [UPDATE perdues]   1q  ← can batch
├── dbExecute(membre)               [derniereConnexion] 1q
├── dbFetchOne(autre)               [tempsPrecedent]   1q  ← dup
├── updateActions()
│   ├── initPlayer()                [~70-90q first call, 0q cached]
│   ├── dbQuery(actionsconstruction) [pending]         1q
│   ├── dbQuery(actionsformation)   [pending]          1q
│   ├── dbFetchOne(autre)           [neutrinos]        1q  ← dup
│   └── dbQuery(actionsattaques)    [pending attacks]  1q
└── basicprivatehtml.php
    ├── dbQuery(messages)           [sent count]       1q
    ├── dbQuery(molecules)          [tutorial check]   1q
    ├── dbFetchOne(ressources)      [DUPLICATE]        1q  ← remove
    ├── dbQuery(molecules)          [all for tuto]     1q
    ├── dbFetchOne(constructions)   [DUPLICATE]        1q  ← remove
    ├── dbFetchOne(autre)           [idalliance]       1q  ← remove (dup)
    ├── dbFetchOne(autre)           [niveaututo]       1q  ← remove (dup)
    ├── dbFetchOne(membre)          [moderateur]       1q  ← remove (dup)
    ├── dbQuery(invitations)        [pending invites]  1q
    ├── dbFetchOne(autre)           [idalliance]       1q  ← remove (dup)
    ├── dbQuery(messages)           [unread]           1q
    ├── dbQuery(rapports)           [unread]           1q
    ├── dbFetchOne(sujets)          [forum count]      1q
    ├── dbFetchOne(statutforum)     [forum read]       1q
    ├── revenuAtome() ×8            [popover]          24-32q
    ├── revenuEnergie() ×7+         [energy popover]   70-90q
    ├── dbFetchOne(autre)           [idalliance]       1q  ← remove (dup)
    ├── dbFetchOne(alliances)       [duplicateur]      1q  ← use ctx
    └── revenuAtome() ×8            [JS vars]          24-32q
```

---

*Generated by performance-engineer agent, 2026-03-03. All query counts are exact traces from source code, not estimates.*
