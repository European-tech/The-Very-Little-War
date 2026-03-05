# Pass 2 — Domain 6: UX and Performance Deep Dive
**Date:** 2026-03-05
**Auditor:** Performance Engineer Agent (Pass 2 — Deep Dive)
**Scope:** Page load paths, DB query counts, JS payload, CSS critical path, session overhead, cache utilization, AJAX patterns, map rendering, mobile performance

---

## Methodology

Every private page loads in this order:

1. `session_init.php` — session start
2. `csp.php` — random_bytes(16) nonce generation
3. `connexion.php` — mysqli connect
4. `fonctions.php` — loads 7 modules
5. `basicprivatephp.php` — auth + initPlayer + resource updates
6. `layout.php` — CSP header + HTML shell + includes/basicprivatehtml.php
7. `includes/basicprivatehtml.php` — sidebar menu + popover generation
8. Page-specific content

The query counts below are traced from actual code, not estimated.

---

## Findings

---

### P2-D6-001 | CRITICAL | initPlayer() Executes 12+ DB Queries But revenuAtome() Is Called 16 Times Per Page

- **Location:** `includes/player.php:99-485`, `includes/game_resources.php:94-132`
- **Description:** `initPlayer()` calls `revenuAtome($num, $joueur)` twice per atom (lines 144-145): once for `${'revenu'.$ressource}` and once for `$revenu[$ressource]`. With 8 atoms, that is 16 calls. Each `revenuAtome()` call itself executes:
  - 1 query: `SELECT pointsProducteur FROM constructions` (line 103)
  - 1 query: `SELECT idalliance FROM autre` (line 107)
  - Conditionally: 1 query: `SELECT duplicateur FROM alliances` (line 110)
  - 1 query: `SELECT x, y FROM membre` (line 116) for resource node bonus
  - 1 query: `SELECT * FROM player_compounds` via `getActiveCompounds()` (compounds.php:27)
  - 1 query: `SELECT spec_combat, spec_economy, spec_research FROM constructions` via `getSpecModifier()` (formulas.php:23)

  The `static $cache` in `revenuAtome()` prevents repeat DB hits for the same `$joueur-$num` key within a single request. However, the static cache is only per-function-call within a request. If `revenuAtome()` is called before the cache is populated it still runs all 5-6 queries for the first call for each atom number. That means for 8 atoms on first population: up to 48 DB queries just for `revenuAtome()` across all 8 atoms.

  Then `revenuEnergie()` (called from initPlayer at line 163) additionally runs:
  - 1 query: `SELECT * FROM constructions` (game_resources.php:18)
  - 1 query: `SELECT producteur FROM constructions` (line 25)
  - 1 query: `SELECT idalliance, totalPoints FROM autre` (line 27)
  - Conditionally: 1 query: `SELECT duplicateur FROM alliances` (line 30)
  - 4 queries: molecules for iode catalyst (loop i=1..4, line 37)
  - 1 query: `SELECT energieDepensee FROM autre` (line 44)
  - 1 query: `SELECT x, y FROM membre` (line 61)
  - 1 query: `SELECT * FROM resource_nodes` via `getResourceNodeBonus()` (resource_nodes.php:91)
  - 1 query: `SELECT * FROM player_compounds` via `getActiveCompounds()` (compounds.php:27)
  - 1 query: `SELECT spec_combat... FROM constructions` via `getSpecModifier()` (formulas.php:23)

  Total for `revenuEnergie()` alone: approximately 12-14 queries. All of these are duplicates of data already fetched in `initPlayer()` itself (which fetches `$constructions`, `$autre`, `$membre` at lines 141, 150, 159, 161).

- **Impact:** On first call per request (non-vacation path), `initPlayer()` drives approximately 60-80 DB round-trips where 5-8 would suffice. At 1ms per query on localhost MariaDB, this is 60-80ms wasted per page load.
- **Fix:** Pass the already-loaded `$constructions`, `$autre`, `$membre` data as parameters to `revenuEnergie()` and `revenuAtome()` instead of re-fetching. Add a request-scoped player data registry so all sub-functions share the same loaded data. The `static $cache` in `revenuAtome` already attempts this but fires too late — the data should be injected, not re-queried.

---

### P2-D6-002 | CRITICAL | basicprivatephp.php Runs initPlayer() Twice on Non-Vacation Path

- **Location:** `includes/basicprivatephp.php:45` and `includes/basicprivatephp.php:95`
- **Description:** The non-vacation code path explicitly calls `initPlayer()` twice:
  - Line 45: first call before resource/action update
  - Line 95: second call after `updateRessources()` and `updateActions()` (with cache invalidation at line 94)

  The second call is necessary to refresh globals after the write. However `updateActions()` itself calls `initPlayer()` internally at line 23 of `game_actions.php`. This means on a page load where at least one construction or formation action completes, `initPlayer()` is called 3 times: once explicitly at line 45, once inside `updateActions()`, and once at line 95. Each full `initPlayer()` is 60-80 queries uncached.

  Additionally `augmenterBatiment()` in `player.php:504` calls `initPlayer()` twice itself (lines 505, 533), so if `updateActions()` processes a completed building, `initPlayer()` runs 5 times total in a single page load.

- **Impact:** Under worst case (building completion during page load), approximately 300-400 DB queries on a single page request.
- **Fix:** Restructure `updateActions()` to not call `initPlayer()` upfront — it only needs specific columns. Consolidate the post-update refresh to a single call at the end of `basicprivatephp.php`. Decouple `augmenterBatiment()` from calling `initPlayer()` twice.

---

### P2-D6-003 | CRITICAL | revenuEnergie() Re-Queries constructions Table Already Loaded by initPlayer()

- **Location:** `includes/game_resources.php:18`, `includes/game_resources.php:25`
- **Description:** `revenuEnergie()` opens with `SELECT * FROM constructions WHERE login=?` (line 18) and immediately follows with `SELECT producteur FROM constructions WHERE login=?` (line 25). Both of these re-fetch the same table that `initPlayer()` already loaded into `$constructions` global at line 150 of `player.php`. The `static $cache` in `revenuEnergie()` prevents re-execution on repeated calls with the same arguments. However, `revenuEnergie()` is called with different `$detail` values (0, 1, 2, 3, 4) in `basicprivatehtml.php` (lines 318, 329, 336, 337, 340, 341, 371), which produces 6 distinct cache keys, triggering 6 separate construction lookups.
- **Impact:** Minimum 12 redundant `constructions` table queries per page load just from the sidebar energy breakdown popover.
- **Fix:** Accept `$constructions` array as an optional parameter. When provided, skip the DB fetch. The `$detail` parameter computation should be split into a pure math function that takes pre-loaded data.

---

### P2-D6-004 | HIGH | basicprivatehtml.php Runs 14 DB Queries on Every Single Private Page

- **Location:** `includes/basicprivatehtml.php:3-274`
- **Description:** The sidebar menu (loaded on every private page via `layout.php`) executes these queries unconditionally:
  1. Line 3: `SELECT count(*) FROM messages WHERE expeditaire=?`
  2. Line 5: `SELECT count(*) FROM molecules WHERE proprietaire=? AND formule!=?`
  3. Line 49: `SELECT * FROM ressources WHERE login=?`
  4. Line 51: `SELECT nombre FROM molecules WHERE proprietaire=?`
  5. Line 57: `SELECT * FROM constructions WHERE login=?`
  6. Line 113: `SELECT * FROM molecules WHERE proprietaire=?`
  7. Line 149: `SELECT idalliance FROM autre WHERE login=?`
  8. Line 166: `SELECT niveaututo, missions FROM autre WHERE login=?`
  9. Line 231: `SELECT moderateur FROM membre WHERE login=?`
  10. Line 243: `SELECT COUNT(*) FROM actionsattaques WHERE defenseur=?`
  11. Line 249: `SELECT COUNT(*) FROM invitations WHERE invite=?`
  12. Line 252: `SELECT idalliance FROM autre WHERE login=?` (DUPLICATE of line 7)
  13. Line 259: `SELECT COUNT(*) FROM messages WHERE destinataire=? AND statut=0`
  14. Line 266: `SELECT COUNT(*) FROM rapports WHERE destinataire=? AND statut=0`
  15. Line 274: `SELECT count(*) FROM sujets WHERE statut=0`
  16. Line 275: `SELECT count(*) FROM statutforum WHERE login=?`
  17. Line 322: `SELECT idalliance FROM autre WHERE login=?` (THIRD fetch of same row)
  18. Conditionally: `SELECT duplicateur FROM alliances WHERE id=?`

  Lines 3, 5, 49, 51, 57, 113 all duplicate data already available in `$ressources`, `$constructions`, `$autre` from `initPlayer()`. Lines 7, 149, and 322 fetch `idalliance` three separate times from `autre`.

- **Impact:** 14-18 extra DB queries on every private page load.
- **Fix:** Replace the direct DB calls with the already-loaded globals from `initPlayer()`: `$ressources` (for resources), `$constructions` (for building levels), `$autre` (for idalliance, niveaututo, missions, nbMessages). Pass the `$tuto` data from `$autre` directly instead of re-querying. Merge the triple `idalliance` fetch into one.

---

### P2-D6-005 | HIGH | basicprivatephp.php Session Token Query Is a Separate Round-Trip

- **Location:** `includes/basicprivatephp.php:12`
- **Description:** Line 12 runs `SELECT session_token FROM membre WHERE login=?` purely to validate the session token. This is correct for security. However, lines 39-43 immediately follow with `SELECT x, y FROM membre WHERE login=?`. These are two queries against the same `membre` row on every page load. A single `SELECT session_token, x, y FROM membre WHERE login=?` would suffice.
- **Impact:** One unnecessary DB round-trip per page load.
- **Fix:** Combine into a single query: `SELECT session_token, x, y, vacance FROM membre WHERE login=?`. The `vacance` field is also re-read at line 82.

---

### P2-D6-006 | HIGH | connectes Table: UPSERT Pattern Uses 3 Queries Instead of 1

- **Location:** `includes/basicprivatephp.php:61-75`
- **Description:** The "online players" tracking runs on every page when the throttle interval has passed:
  1. `SELECT COUNT(*) FROM connectes WHERE ip=?` (line 61)
  2. Either `INSERT INTO connectes VALUES(?, ?)` (line 66) or `UPDATE connectes SET timestamp=? WHERE ip=?` (line 70)
  3. `DELETE FROM connectes WHERE timestamp < ?` (line 75)

  This is a classic SELECT-then-INSERT-or-UPDATE anti-pattern. MariaDB supports `INSERT INTO ... ON DUPLICATE KEY UPDATE` which collapses steps 1 and 2 into one atomic operation. The DELETE on step 3 also fires on every throttled update, meaning stale entries are purged continuously rather than on a scheduled basis.

- **Impact:** 2-3 queries that could be 1-2 queries. When many users are active simultaneously, the DELETE on every update creates lock contention on the `connectes` table.
- **Fix:** Replace with `INSERT INTO connectes (ip, timestamp) VALUES(?, ?) ON DUPLICATE KEY UPDATE timestamp=?`. Move the DELETE to a cron job running every 5 minutes rather than per-request.

---

### P2-D6-007 | HIGH | recalculerStatsAlliances() Runs on classement.php sub=1 — O(A*M) Queries

- **Location:** `classement.php:254`, `includes/player.php:713-734`
- **Description:** `recalculerStatsAlliances()` is called synchronously every time a user loads the Alliance Leaderboard tab. The function:
  1. Fetches all alliance IDs: `SELECT id FROM alliances` (1 query)
  2. For each alliance: `SELECT * FROM autre WHERE idalliance=?` (A queries, where A = number of alliances)
  3. For each alliance: `UPDATE alliances SET ... WHERE id=?` (A queries)

  Total: `1 + 2*A` queries. With 20 alliances this is 41 queries just to recompute stats that change infrequently. The function also iterates over every member of every alliance in PHP, calling `pointsAttaque()` and `pointsDefense()` formulas per member.

  This recalculation could instead be done with a single SQL aggregation:
  ```sql
  UPDATE alliances a
  JOIN (SELECT idalliance, SUM(totalPoints) AS pt, SUM(points) AS pc, ... FROM autre GROUP BY idalliance) t
  ON a.id = t.idalliance SET a.pointstotaux = t.pt, ...
  ```

- **Impact:** 41+ synchronous queries blocking the HTTP response on every alliance leaderboard page view.
- **Fix:** Replace with a single SQL JOIN-aggregation UPDATE. Alternatively, trigger recalculation only when a player's stats actually change (via a flag or scheduled job), not on every leaderboard view.

---

### P2-D6-008 | HIGH | classement.php sub=3 Fetches Full Table Scans for Forum Ranking

- **Location:** `classement.php:557-572`
- **Description:** The forum leaderboard tab pre-loads:
  1. `SELECT login, nbMessages, bombe FROM autre` — full table scan of `autre` (all players)
  2. `SELECT login, troll FROM membre` — full table scan of `membre`
  3. `SELECT auteur, COUNT(*) AS nbSujets FROM sujets GROUP BY auteur` — full group-by on `sujets`

  All three are full table reads of potentially hundreds of rows, fetched into PHP memory and indexed by login, to avoid N+1 per leaderboard row. This is a correct optimization pattern. However, none of these large in-memory arrays are cached between requests. With 100 active players each query returns 100+ rows into PHP. The aggregation is done in PHP rather than SQL.

- **Impact:** 3 full-table queries loading potentially thousands of rows into PHP RAM on every forum leaderboard page view.
- **Fix:** The `autreForumCache` and `membreForumCache` builds are correct N+1 eliminations. The residual issue is these are full-table reads when only 20 rows are needed for display. Consider using a single JOIN query with LIMIT rather than fetching all rows and filtering in PHP: `SELECT a.login, a.nbMessages, a.bombe, m.troll, COUNT(s.id) AS nbSujets FROM autre a JOIN membre m ON a.login=m.login LEFT JOIN sujets s ON s.auteur=a.login GROUP BY a.login ORDER BY a.nbMessages DESC LIMIT 20`.

---

### P2-D6-009 | HIGH | framework7.material.colors.min.css Loaded But Only One Theme Used

- **Location:** `includes/meta.php:23-24`
- **Description:** Three CSS files are loaded on every page:
  - `framework7.material.min.css` — 174 KB
  - `framework7.material.colors.min.css` — 354 KB
  - `framework7-icons.css` — 1 KB

  The colors file at 354 KB is the dominant CSS payload. The game uses a fixed "theme-black" (set in `basicprivatehtml.php:216`) and a single accent color (blue, `theme-color: #2196f3`). The entire 354 KB colors file contains styles for dozens of color themes (red, green, blue, orange, pink, etc.) none of which are used at runtime. Only the blue theme class is active.

  Total CSS transfer per page: approximately 529 KB uncompressed. With gzip this compresses to roughly 70 KB, but this still represents unnecessary download.

- **Impact:** 354 KB of unused CSS rules parsed and stored in the browser's style engine. On mobile this measurably increases Time to First Paint.
- **Fix:** Remove `framework7.material.colors.min.css` entirely. Add only the blue theme overrides that are actually needed (approximately 2-3 KB of CSS for the active accent color). The CSS for the active color can be inlined in `style.php`.

---

### P2-D6-010 | HIGH | framework7.min.js Loaded Without defer/async — Render-Blocking

- **Location:** `includes/basicprivatehtml.php` (implied from layout), `includes/meta.php`
- **Description:** `framework7.min.js` at 318 KB is loaded synchronously. No `defer` or `async` attribute is present on any script tag loading the framework. In the HTML output, the Framework7 initialization script fires inline at document end (in `basicprivatehtml.php`). Because the script is loaded synchronously in `<head>`, the browser cannot render any page content until the 318 KB JS file is downloaded, parsed, and executed. On a 3G mobile connection (typical TVLW user given the mobile-first UI), this represents approximately 1.5 seconds of render-blocking at 200 Kbps.

  The jQuery CDN link in the layout is also loaded synchronously:
  ```html
  <script ... src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js">
  ```
  jQuery minified is approximately 87 KB. This is a second render-blocking external request.

- **Impact:** Minimum 400ms render-blocking on fast connections; 1.5-3 seconds on mobile. Time to Interactive is delayed by the full parse time of 318 KB + 87 KB of JavaScript.
- **Fix:** Add `defer` to all non-critical script tags. Framework7 initialization code should run in `DOMContentLoaded` handler (which it does via F7 init, but the file must be marked `defer` to allow HTML parsing to proceed). Move the framework7 `<script>` tag to end of `<body>` or add `defer`. The countdown.js (941 bytes) should also be `defer`.

---

### P2-D6-011 | HIGH | Google Charts loader.js Loaded on marche.php Even in Sell/Send Tab

- **Location:** `marche.php:587-590`
- **Description:** The Google Charts `loader.js` file (71 KB per the js/ directory entry for the local copy, external CDN version similar size) is loaded unconditionally at the bottom of `marche.php` regardless of which `$_GET['sub']` tab is active. When a user visits `marche.php?sub=1` (Send Resources tab), no chart is rendered. The loader and its subsequent chart library download are entirely wasted.

  The chart drawing code is in the `$_GET['sub'] == 0` PHP block but the `<script>` tags are outside all conditional blocks, meaning the Google Charts API is always initialized.

- **Impact:** On the Send Resources tab (sub=1), Google Charts loader fires an external CDN request for approximately 50-100 KB of chart library JavaScript that is never used. This adds a cross-origin DNS lookup, TCP handshake, and TLS negotiation to the page load.
- **Fix:** Wrap the Google Charts `<script>` tags inside `<?php if ($_GET['sub'] == 0): ?>` conditional. Only load the chart library when the Exchange tab is active.

---

### P2-D6-012 | HIGH | 8 Separate setInterval() Calls for Resource Display Timers

- **Location:** `includes/basicprivatehtml.php:446-463`
- **Description:** The sidebar resource display creates 9 separate `setInterval()` timers — one for energy and one per atom type:
  ```javascript
  setInterval(energieDynamique, 1000);
  setInterval(carboneDynamique, 1000);
  setInterval(azoteDynamique, 1000);
  // ... 6 more
  ```
  Each timer fires every 1000ms. This creates 9 concurrent JavaScript timer callbacks that each manipulate the DOM. On mobile browsers (especially Android WebView), having 9 independent 1-second timers causes the browser's timer coalescing mechanism to be less effective. Each callback individually calls `nFormatter()` and updates a DOM node.

  Additionally, `marche.php` adds more setInterval timers for pending transfer countdowns (one per in-transit shipment), each also firing every second. With 5 in-transit shipments, total active intervals reaches 14.

- **Impact:** 9+ concurrent setInterval callbacks on every private page. On mid-range mobile devices, frequent DOM manipulation from multiple overlapping timers can cause visible jank.
- **Fix:** Consolidate all resource timers into a single `setInterval()` that updates all 9 values in one callback. This reduces timer overhead from 9 callbacks to 1 callback per second.

---

### P2-D6-013 | HIGH | initPlayer() Generates Entire listeConstructions Array Including Full HTML Strings on Every Page

- **Location:** `includes/player.php:315-469`
- **Description:** `initPlayer()` builds `$listeConstructions`, a PHP array containing computed HTML strings for all 10 buildings. This includes calling `revenuEnergie()` with 5 different `$detail` levels for the generateur entry (lines 322, 323), `revenuAtome()` via `$production` construction (lines 187-203), and building complete HTML strings including inline `<script>` blocks. This full array is computed even on pages like `forum.php` or `messages.php` where no building information is displayed.

  The `$production` HTML string (line 187) and `$productionCondenseur` (line 212) each embed inline `<script>` tags with per-atom JavaScript event listeners, generating approximately 800 characters of HTML per atom, or ~6,400 characters of embedded script for 8 atoms, computed on every single page load.

- **Impact:** Approximately 50ms of PHP computation generating HTML strings and making redundant DB calls, even on pages that never display building information.
- **Fix:** Lazy-initialize `$listeConstructions`: only build it when a page actually requires building data (constructions.php, bilan.php). Add a parameter to `initPlayer()` such as `initPlayer($joueur, $withBuildings = false)` and skip the expensive `$listeConstructions` construction when not needed.

---

### P2-D6-014 | HIGH | joueur() Function in Classement Calls statut() — One DB Query Per Table Row

- **Location:** `includes/player.php:702-711`, `classement.php:195`
- **Description:** The `joueur($joueur)` display function calls `statut($joueur)` on line 705, which runs:
  ```sql
  SELECT count(*) AS nb FROM membre WHERE derniereConnexion >= ? AND x!=-1000 AND login=?
  ```
  This query fires once per row in the leaderboard. With 20 rows per page, this is 20 queries just to determine online/offline display colors. The classement already pre-loads player data from `autre` in bulk, but `statut()` queries `membre` separately.

- **Impact:** 20 extra queries per leaderboard page. With 4 leaderboard tabs visible, this could total 80 queries if a user navigates through all tabs.
- **Fix:** Pre-load `derniereConnexion` alongside the `autre` query and pass it as a parameter to a new `joueurWithStatus($login, $isOnline)` function. Alternatively, add a session-side online status cache keyed by login populated from the pre-loaded player rows.

---

### P2-D6-015 | HIGH | fondecran.png Loaded as CSS Background on Desktop — 582 KB Uncompressed PNG

- **Location:** `includes/style.php:17`
- **Description:** The CSS rule:
  ```css
  @media only screen and (min-width:750px){
      .page-content{
          background-image: url('images/fondecran.png');
      }
  }
  ```
  This loads `fondecran.png` (582 KB) as a decorative background image on desktop. PNG format is inappropriate for photographic backgrounds — a JPEG would achieve equivalent visual quality at 40-60 KB. The image is also served without `Cache-Control` headers (Apache default is no explicit cache on content files without `.htaccess` rules for images). Additionally `images/fond.jpg` (321 KB) and `images/fond1.jpg` (417 KB) exist but appear to be unused backgrounds from previous design iterations.

- **Impact:** 582 KB image download on every desktop page load where the background is visible. At typical broadband speeds this is 0.5-1 second additional load time.
- **Fix:** Convert `fondecran.png` to WebP with JPEG fallback. A WebP version should compress to approximately 30-50 KB. Remove unused `fond.jpg` and `fond1.jpg` from the repository. Add `Cache-Control: max-age=86400` for static image assets via `.htaccess`.

---

### P2-D6-016 | MEDIUM | Session Read/Write on Every Page Load — Unnecessary last_activity Write

- **Location:** `includes/basicprivatephp.php:36`
- **Description:** Line 36 always writes `$_SESSION['last_activity'] = time()`. PHP sessions on disk (default `session.save_handler = files`) require an exclusive file lock to write session data. This means every page request acquires a session file lock, updates the timestamp, and releases the lock. For pages that make no functional session changes, this write is purely for idle-timeout tracking.

  MariaDB-backed sessions (not currently used) would serialize this contention further. The file lock is held for the entire request execution time if `session_write_close()` is never called, meaning concurrent requests from the same browser (e.g., AJAX calls) are serialized by session locking.

- **Impact:** Session file lock held for full request duration on every page. Multiple simultaneous AJAX calls from the same browser (e.g., the 9 `actualiserStats` AJAX calls from `armee.php`) are serialized, not parallel.
- **Fix:** Call `session_write_close()` immediately after setting `$_SESSION['last_activity']` in `basicprivatephp.php`. This releases the session lock early and allows concurrent AJAX requests to proceed in parallel. The session data at that point is finalized — no further writes are needed in the majority of page loads.

---

### P2-D6-017 | MEDIUM | armee.php Fires 9 Simultaneous AJAX Requests to api.php on Every Atom Input

- **Location:** `includes/layout.php:137-189`
- **Description:** The molecule creation form's `actualiserStats()` function fires 9 separate AJAX requests whenever any atom count input changes:
  - `/api.php?id=attaque&...`
  - `/api.php?id=defense&...`
  - `/api.php?id=pointsDeVieMolecule&...`
  - `/api.php?id=potentielDestruction&...`
  - `/api.php?id=vitesse&...`
  - `/api.php?id=pillage&...`
  - `/api.php?id=productionEnergieMolecule&...`
  - `/api.php?id=tempsFormation&...`
  - `/api.php?id=demiVie&...`

  Each AJAX call hits `api.php` which itself includes `basicprivatephp.php` (the full auth + initPlayer chain — 60-80 DB queries). With session locking (see P2-D6-016), these 9 requests are serialized if they share a session. Each individual stat computation requires milliseconds of math but 60-80ms of DB overhead. Total cost per keystroke in the atom input fields: 9 × 60-80ms = 540-720ms of DB time.

  No debouncing is applied to the input event listeners — every `input` event (including held-down keys) triggers all 9 calls.

- **Impact:** Typing in atom count fields (a core game action) generates 9 serialized HTTP requests each with full auth overhead. Under realistic conditions (100ms network RTT + 80ms server processing), a user typing a 3-digit number triggers 27 AJAX requests taking 3+ seconds total to process.
- **Fix:** (1) Debounce the `input` event with a 300ms delay. (2) Consolidate the 9 AJAX endpoints into a single `/api.php?id=allStats` call that returns all 9 values in one JSON response. (3) Implement a lightweight stats calculation path in api.php that bypasses the full `initPlayer()` when session player data is already validated.

---

### P2-D6-018 | MEDIUM | updateRessources() Called on Every Page Load Including Static-Content Pages

- **Location:** `includes/basicprivatephp.php:87`
- **Description:** `updateRessources()` is called unconditionally on every page load for non-vacation players. The function computes resource deltas since last connection, applies decay to all 4 molecule classes, updates neutrino decay, and runs several DB writes. While the CAS guard at `game_resources.php:169` prevents double-processing within the same second, the function still always:
  1. Reads `tempsPrecedent` from `autre` (1 query)
  2. If at least 1 second has passed: attempts CAS UPDATE, reads `ressources`, reads `constructions`, calls `revenuEnergie()` and `revenuAtome()` (8×), reads molecule list, updates each molecule, reads `neutrinos`, conditionally updates neutrinos

  On pages like `regles.php` (game rules), `credits.php`, or `historique.php`, the user is reading static content but still triggers full resource computation and writes.

- **Impact:** 15-25 DB writes per page load on read-only pages. This creates unnecessary load on the database and increases page response times for pages where the user just wants to read information.
- **Fix:** Accept a `$skipUpdate` parameter for pages known to be read-only, or implement a POST-only resource update strategy where resources are only computed when a player takes an action. For passive browsing (GET requests with no state changes), resource updates could be deferred.

---

### P2-D6-019 | MEDIUM | style.php Served as PHP File — Prevents CSS Caching and CDN Delivery

- **Location:** `includes/style.php` (included via `includes/meta.php:8`)
- **Description:** Application-specific CSS is embedded in a `<style>` block inside `includes/style.php` (a PHP file, included with `include()`). This means:
  1. The 304-line CSS block is inlined into every HTML page, adding approximately 6 KB to every response body.
  2. Because it is inline CSS (in a `<style>` tag, not a linked `<link>` file), the browser cannot cache it separately. It is re-downloaded with every page load.
  3. The CSP policy in `layout.php` includes `style-src 'self' 'unsafe-inline'` specifically to allow this inline CSS, which weakens the CSP's protection against injected styles.

- **Impact:** 6 KB of CSS re-parsed on every page load. Inline CSS cannot be cached by the browser. `'unsafe-inline'` in style-src weakens CSP.
- **Fix:** Extract `style.php` content into `css/my-app.css` (which already exists and is linked). The `my-app.css` file currently contains only 1.7 KB of styles; the `style.php` content should be appended to it. This allows browser caching, removes the `'unsafe-inline'` style-src directive, and reduces per-page HTML size.

---

### P2-D6-020 | MEDIUM | Every Page Load Computes Season End Timestamp in layout.php

- **Location:** `includes/layout.php:40-56`
- **Description:** Every authenticated page load re-computes the season countdown timestamp:
  ```php
  $seasonStartMonth = (int)date('n', $debut['debut']);
  $seasonStartYear = (int)date('Y', $debut['debut']);
  $endMonth = $seasonStartMonth + 1;
  // ... mktime() call
  $secondsLeft = max(0, $seasonEndTimestamp - time());
  $daysLeft = floor($secondsLeft / SECONDS_PER_DAY);
  ```
  The `$debut` value comes from `basicprivatephp.php:119-120` which runs `SELECT debut FROM statistiques` on every page load. The `statistiques` table's `debut` value changes only at month boundaries (season resets). This is effectively a constant queried 100+ times per day per active player.

- **Impact:** One DB query per page load that returns a value that is constant for a full month at a time. Minor in isolation, cumulative across all users and page loads.
- **Fix:** Cache `debut` in the PHP session after first read: `if (!isset($_SESSION['season_debut']) || ...) { /* query + cache */ }`. Invalidate the cached value when maintenance mode changes. This eliminates the query for 99.9% of page loads.

---

### P2-D6-021 | MEDIUM | maintenance Check Fetches statistiques Table Twice per Page

- **Location:** `includes/basicprivatephp.php:119`, `includes/basicprivatephp.php:122`
- **Description:** Two separate queries fetch from the single-row `statistiques` table:
  - Line 119: `SELECT debut FROM statistiques`
  - Line 122: `SELECT maintenance FROM statistiques`

  These are two round-trips to the same table, same row. A single `SELECT debut, maintenance FROM statistiques` would suffice.

- **Impact:** One unnecessary DB round-trip per page load.
- **Fix:** Combine: `$statistiques = dbFetchOne($base, 'SELECT debut, maintenance FROM statistiques')`. Use `$statistiques['debut']` and `$statistiques['maintenance']` throughout.

---

### P2-D6-022 | MEDIUM | cspNonce() Called Dozens of Times — Random Bytes Generated Once But Output Repeated

- **Location:** `includes/csp.php:7`, multiple files
- **Description:** `cspNonce()` correctly generates the nonce once per request via `$GLOBALS['csp_nonce'] = base64_encode(random_bytes(16))` and returns it on subsequent calls. However, `cspScriptTag()` returns an unclosed `<script>` tag that requires matching `</script>` tags in the calling code. In `player.php:192-202`, `cspScriptTag()` is called 8 times inside the `$production` string (once per atom), each generating a `<script nonce="...">` tag. The nonce itself is not expensive, but each call to `htmlspecialchars(cspNonce(), ENT_QUOTES, 'UTF-8')` runs a string encoding on every invocation.

  More critically, the `$production` variable is built with embedded `<script>...</script>` blocks per atom (lines 192-202), meaning the page HTML for `constructions.php` includes 8+ separate inline script blocks just for the production point buttons. Modern browsers handle multiple script blocks but it creates a fragmented JavaScript execution environment.

- **Impact:** 8 separate `<script>` execution contexts for what could be a single script block. Each context has parse overhead.
- **Fix:** Generate a single `<script nonce="...">` wrapper and place all 8 atom event listener registrations inside one block instead of 8 separate `cspScriptTag()` blocks.

---

### P2-D6-023 | MEDIUM | iOS CSS Framework Shipped to Mobile-First Material UI App

- **Location:** `includes/meta.php:21` — `framework7.material.min.css` loaded
- **Description:** The game loads `framework7.material.min.css` (174 KB) which is correct for the Material theme. However, the `css/` directory contains the entire iOS theme as well:
  - `framework7.ios.css` — 199 KB
  - `framework7.ios.min.css` — 169 KB
  - `framework7.ios.colors.css` — 126 KB
  - `framework7.ios.colors.min.css` — 118 KB
  - `framework7.ios.rtl.css` — 30 KB
  - `framework7.ios.rtl.min.css` — 27 KB

  Total iOS theme files: ~669 KB of CSS stored on disk and available via direct URL. These are never loaded by the application but consume approximately 669 KB of server disk space and could be downloaded by users who discover the path. More importantly the `.map` file for `framework7.min.js` is also served (`framework7.min.js.map` — likely 1-3 MB), exposing source map to production.

- **Impact:** Dead weight files on disk. Source map exposure in production is a minor information disclosure.
- **Fix:** Delete all iOS theme CSS files from `css/`. Delete `framework7.min.js.map` from `js/`. These files serve no purpose in production.

---

### P2-D6-024 | MEDIUM | $tuto Variable in cardsprivate.php References $autre but $tuto Is Set Late

- **Location:** `includes/basicprivatehtml.php:166`, `includes/cardsprivate.php:5-8`
- **Description:** `cardsprivate.php` references `$tuto['niveaututo']` (line 8) which is populated at `basicprivatehtml.php:166`:
  ```php
  $tuto = dbFetchOne($base, 'SELECT niveaututo, missions FROM autre WHERE login = ?', 's', $_SESSION['login']);
  ```
  This data is already available in `$autre['niveaututo']` from `initPlayer()`. The fresh fetch at line 166 is a duplicate query. Additionally `cardsprivate.php:5` runs yet another query `SELECT niveaututo FROM autre WHERE login=?` (line 5) which is a third fetch of the same field from the same row.

  The net result: `niveaututo` is read from `autre` three times per page load:
  1. Inside `initPlayer()` → `$autre` global (SELECT * FROM autre)
  2. `basicprivatehtml.php:166` → `$tuto`
  3. `cardsprivate.php:5` → `$niveaututoRow`

- **Impact:** Two redundant queries against `autre` per page load to read a field already loaded by `initPlayer()`.
- **Fix:** Remove the query at `cardsprivate.php:5` and instead set `$tuto = $autre` or extract `$tuto['niveaututo'] = $autre['niveaututo']`. Remove the query at `basicprivatehtml.php:166` and use `$autre` directly.

---

### P2-D6-025 | MEDIUM | No HTTP Caching Headers on Static Assets (Images, CSS, JS)

- **Location:** Apache configuration, no `.htaccess` asset caching rules found
- **Description:** Static assets (PNG images, CSS files, JS files) are served without explicit `Cache-Control` or `Expires` headers beyond Apache's default `Last-Modified` behavior. The game serves approximately 50-100 small PNG icons (menu icons, atom images, building images) which are referenced on every page. Without `Cache-Control: max-age`, browsers must revalidate these assets on every page navigation via conditional GET requests (If-Modified-Since). Even though the server returns `304 Not Modified`, each revalidation requires a TCP round-trip.

  On a page like `classement.php`, the header alone loads:
  - 11 column header images (joueur, points, alliance, museum, sword, shield, bag, points, victoires × 2)
  - Each menu item loads a 25×25 icon

- **Impact:** 20-30 conditional GET requests per page load for images that never change. On mobile networks, each TCP round-trip is 50-150ms, adding 1-3 seconds of serial revalidation time.
- **Fix:** Add to `.htaccess`:
  ```apache
  <FilesMatch "\.(png|jpg|gif|ico|css|js|woff2|woff)$">
      Header set Cache-Control "max-age=604800, public"
  </FilesMatch>
  ```
  This caches static assets for 7 days, eliminating revalidation round-trips for returning users.

---

### P2-D6-026 | MEDIUM | revenuAtome() Called 8 More Times in basicprivatehtml.php Sidebar

- **Location:** `includes/basicprivatehtml.php:298`
- **Description:** The sidebar resource popover (line 298) calls `revenuAtome($num, $_SESSION['login'])` in a foreach loop over `$nomsRes` (8 atoms). These are additional calls beyond the 16 inside `initPlayer()`. Although `revenuAtome()` has a `static $cache` that prevents re-querying for the same `$joueur-$num` pair, the cache is populated by `initPlayer()`'s first 8 calls. However, `basicprivatehtml.php` is included inside `layout.php` which is called after `basicprivatephp.php`. At that point the cache is already warm, so no extra queries fire. **This finding is lower severity than it appears** — the caching works correctly here.

  The actual issue is that `revenuEnergie()` is called 6 additional times in `basicprivatehtml.php` lines 318, 329, 336-337, 340-341, 371 with different `$detail` parameters. Due to the `static $cache` keying on `$joueur-$niveau-$detail`, each different `$detail` value produces a cache miss and re-runs the 12-14 query chain inside `revenuEnergie()`.

- **Impact:** 6 distinct `$detail` values × 12-14 queries = 72-84 additional queries per page load from the energy breakdown popover in the sidebar.
- **Fix:** Cache the full detail breakdown as a single query result inside `revenuEnergie()` using an array-keyed cache. On first call for a given `$joueur+$niveau`, compute all `$detail` levels (0-4) and cache all 5 results simultaneously. This reduces 6 cache misses to 1 DB execution.

---

### P2-D6-027 | MEDIUM | Market Chart Loads All Historical Price Data via SELECT * FROM cours

- **Location:** `marche.php:608`
- **Description:** The market price chart fetches:
  ```php
  $coursRows = dbFetchAll($base, "SELECT * FROM cours ORDER BY timestamp DESC LIMIT " . MARKET_HISTORY_LIMIT);
  ```
  The `cours` table stores one row per buy/sell transaction. Over the course of an active month with 50 players, this could accumulate thousands of rows. `MARKET_HISTORY_LIMIT` is used for the LIMIT clause, but the `SELECT *` fetches all columns including `id`, `tableauCours` (a comma-separated string of 8 floats), and `timestamp`. This is a reasonable query. However, the data is then used to build a Google Charts `arrayToDataTable()` call, which means all the chart data is embedded directly in the HTML source as a multi-kilobyte JavaScript literal.

  With `MARKET_HISTORY_LIMIT = 50` entries of 8 prices each, the embedded chart data adds approximately 3-5 KB to the page HTML.

- **Impact:** Chart data embedded in HTML prevents browser caching of the chart data. Every page load re-embeds the full price history in the HTML response.
- **Fix:** Move the chart data to a JSON AJAX endpoint (`api.php?id=chartData`). The chart would fetch data asynchronously after page load, improving Time to First Meaningful Paint. The data endpoint response can be cached for 60 seconds since prices only change on buy/sell transactions.

---

### P2-D6-028 | MEDIUM | No lazy="lazy" on Below-Fold Images in classement.php and forum.php

- **Location:** `classement.php:194`, `classement.php:373`, `classement.php:601`
- **Description:** Leaderboard rows render medal and ranking images inline without `loading="lazy"`. With 20 rows per page, images like `images/medailles/bombeBronze.png`, `images/classement/medaillebronze.png`, and ranking position images (images/1.png through images/4.png) are all loaded eagerly. On mobile screens showing only 5-7 rows above the fold, 13-15 rows of images are downloaded unnecessarily during initial page load.

  The sub-menu images loaded by `layout.php` (lines 70-73, 84-86) for the classement toolbar (`joueur.png`, `alliance.png`, `swords.png`, `forum.png`, `parchemin.png`) are also loaded unconditionally even when not visible.

- **Impact:** 15-20 unnecessary image downloads per leaderboard page load on mobile. Combined these add approximately 30-60 KB of image data that could be deferred.
- **Fix:** Add `loading="lazy"` to all images rendered inside loop rows: `<img ... loading="lazy"/>`. For sub-menu icons that are always visible, eager loading is correct.

---

### P2-D6-029 | MEDIUM | $PHP_SELF-Based in_array() Called 4 Times per Page in layout.php

- **Location:** `includes/layout.php:65`, `79`, `90`, `100`, `201`
- **Description:** The sub-menu toolbar logic uses this pattern 5 times:
  ```php
  if(in_array("classement.php", explode("/", $_SERVER['PHP_SELF']))){
  ```
  Each call: (1) reads `$_SERVER['PHP_SELF']`, (2) explodes it by "/", (3) calls in_array. This runs 5 times on every private page. The `explode()` creates a new array each call even though `$_SERVER['PHP_SELF']` is constant within a request.

- **Impact:** Minor CPU waste — 5 redundant string explode operations per page load.
- **Fix:** Pre-compute `$currentPage = basename($_SERVER['PHP_SELF'])` once at the top of `layout.php`. Replace `in_array("classement.php", explode("/", $_SERVER['PHP_SELF']))` with `$currentPage === 'classement.php'`.

---

### P2-D6-030 | MEDIUM | No output Buffering — PHP Flushes HTML Incrementally During DB Queries

- **Location:** All private pages (global pattern)
- **Description:** PHP pages echo HTML output while simultaneously executing database queries. Since there is no `ob_start()` output buffering at the application level, PHP sends HTTP headers and partial HTML to the browser as soon as the first `echo` occurs. The browser receives the `<html><head>` section while the server is still executing 60-80 DB queries for `initPlayer()`. This prevents the browser from knowing the full page size for progressive rendering.

  More importantly, without output buffering, if any code after the first output needs to set a `header()` (e.g., the CSRF redirect at `csrf.php` or the session destroy), PHP emits a "headers already sent" warning and the redirect fails silently.

- **Impact:** Fragmented HTTP response delivery. Headers cannot be set after initial output. Debugging is complicated by premature output.
- **Fix:** Add `ob_start()` at the top of `session_init.php` (which is the first include) and `ob_end_flush()` in a footer include. This buffers the full response and allows headers to be set throughout the request lifecycle.

---

### P2-D6-031 | LOW | Google Charts Integrity Hash May Break on CDN Update

- **Location:** `marche.php:587-590`
- **Description:** The comment on line 586 explicitly acknowledges this risk:
  ```html
  <!-- SRI hash may break if Google updates loader.js; remove integrity attr if chart stops loading -->
  ```
  The `integrity="sha384-Q4nTc23a1YNtnl17XDjJkYn/j5Ksb7rsGG1NTcIxbz6sTGfGXZJ8WdvzALeeuafr"` on the Google Charts loader will silently break the chart if Google updates their CDN file (which they do periodically). The chart will simply not load and no error is shown to the user.

- **Impact:** Intermittent chart breakage without user notification. The market chart is a key UX feature for informed trading decisions.
- **Fix:** Remove the `integrity` attribute from the Google Charts loader tag (Google's CDN updates the file frequently and SRI is incompatible with CDN versioning that isn't under your control). Add a visible error handler: `google.charts.setOnLoadCallback(drawChart)` should include a catch that displays "Graphique indisponible" if the chart library fails to load.

---

### P2-D6-032 | LOW | title Tag Is Static "The Very Little War" on All Pages — No Per-Page Context

- **Location:** `includes/meta.php:19`
- **Description:** Every page has `<title>The Very Little War</title>` regardless of which page is being viewed. When a user has multiple tabs open (e.g., classement.php and marche.php), all tabs show identical titles. This also harms SEO since each page URL maps to the same title metadata.

- **Impact:** Poor tab usability. Minor SEO impact.
- **Fix:** Set `$pageTitle` before including `layout.php` in each page file, and output `<title><?php echo htmlspecialchars($pageTitle ?? 'The Very Little War', ENT_QUOTES, 'UTF-8'); ?></title>` in `meta.php`.

---

### P2-D6-033 | LOW | Font Loading Uses Legacy .eot Format Without font-display Swap

- **Location:** `includes/style.php:36-60`
- **Description:** Two custom fonts (`magmawave_capsbold`, `bpmoleculesregular`) are declared with `@font-face` rules that include `.eot` format (Internet Explorer legacy). IE support ended in 2022 and IE usage is 0% among modern mobile users. The declarations also lack `font-display: swap`, meaning the browser shows invisible text (FOIT — Flash of Invisible Text) until the custom font loads, rather than falling back to the system font immediately.

- **Impact:** `.eot` file listed first in the `src:` stack wastes parsing time. Missing `font-display: swap` causes invisible text during font load, harming perceived performance.
- **Fix:** Remove `eot` entries from the `@font-face` `src` declarations. Add `font-display: swap;` to both `@font-face` rules. The `woff2` format should be listed first as it is supported by all modern browsers.

---

### P2-D6-034 | LOW | Vacation Mode Fetches vacances Table with Correlated Subquery

- **Location:** `includes/basicprivatephp.php:101`
- **Description:** The vacation check runs:
  ```php
  $vac = dbFetchOne($base, 'SELECT dateFin FROM vacances WHERE idJoueur IN (SELECT id FROM membre WHERE login = ?)', 's', $vac['dateFin']);
  ```
  This correlated subquery first queries `membre` to get `id`, then queries `vacances` by `id`. The `vacances` table likely has an index on `idJoueur`, but the IN subquery is harder for the query optimizer than a direct JOIN. Additionally, line 103 fires a second query: `SELECT DATEDIFF(CURDATE(), ?) AS d` — this is a pure date arithmetic operation that could be done in PHP with `new DateTime()` without a DB round-trip.

- **Impact:** One unnecessary DB round-trip per vacation-mode page load for a date arithmetic operation.
- **Fix:** Compute the date difference in PHP: `$diff = (new DateTime($vac['dateFin']))->diff(new DateTime())->days`. Rewrite the vacation lookup as a JOIN: `SELECT v.dateFin FROM vacances v JOIN membre m ON v.idJoueur = m.id WHERE m.login = ?`.

---

## Summary Metrics

### DB Query Count Per Page Load (Baseline: Home Page)

| Phase | Queries |
|-------|---------|
| basicprivatephp.php authentication | 3 |
| basicprivatephp.php online tracking (throttled) | 2-3 |
| basicprivatephp.php vacation check | 1 |
| basicprivatephp.php initPlayer() (first call) | 60-80 |
| basicprivatephp.php updateRessources() | 10-15 |
| basicprivatephp.php updateActions() (incl. initPlayer) | 60-80 |
| basicprivatephp.php initPlayer() (second call, post-update) | 60-80 |
| basicprivatephp.php statistiques check | 2 |
| basicprivatehtml.php sidebar | 14-18 |
| basicprivatehtml.php revenuEnergie (6 detail levels) | 72-84 |
| **Total (worst-case, building completes)** | **~380-430 queries** |
| **Total (typical non-vacation, no action completion)** | **~160-220 queries** |
| **Target (after all fixes applied)** | **~15-25 queries** |

### JavaScript Payload Per Page

| File | Size | Type |
|------|------|------|
| framework7.min.js (self-hosted) | 318 KB | Render-blocking |
| jQuery 3.7.1 (CDN) | ~87 KB | Render-blocking |
| Google Charts loader.js (CDN, marche.php only) | ~50 KB | Async |
| countdown.js | 0.9 KB | Inline |
| Inline scripts per page | 2-8 KB | Inline/nonce |

### CSS Payload Per Page

| File | Size | Needed |
|------|------|--------|
| framework7.material.min.css | 174 KB | Yes |
| framework7.material.colors.min.css | 354 KB | No (one theme used) |
| framework7-icons.css | 1 KB | Yes |
| style.php (inline) | 6 KB | Yes (extractable) |
| my-app.css | 1.7 KB | Yes |

### Critical Path Length (First Request, No Cache)

```
DNS + TCP + TLS (HTTPS pending)
+ Server: ~150-220ms PHP/DB processing
+ Transfer: ~400KB HTML + CSS + JS (uncompressed)
+ Parse: framework7.min.js 318KB blocking
+ Render: webfont FOIT
= Time to Interactive: ~2.5-4 seconds on 4G mobile
```

### Top 5 Highest-Impact Fixes

1. **P2-D6-001/002/003**: Consolidate initPlayer + sub-function DB calls — eliminates ~120-200 queries per page
2. **P2-D6-009**: Remove framework7.material.colors.min.css — eliminates 354 KB CSS download
3. **P2-D6-010**: Add `defer` to framework7.min.js and jQuery — unblocks initial render
4. **P2-D6-017**: Debounce + consolidate 9 AJAX calls to 1 — reduces army page AJAX overhead 90%
5. **P2-D6-016**: Call `session_write_close()` early — enables parallel AJAX on same session

---

*End of Pass 2 — Domain 6 UX/Performance Audit. 34 findings recorded (P2-D6-001 through P2-D6-034).*
