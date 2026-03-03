# Mega Remediation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix all CRITICAL and HIGH findings from the mega audit (198 unique issues across 51 agent reports)

**Architecture:** Batch fixes by dependency order — data integrity first (prevents data loss), then security (prevents exploits), then game bugs (prevents crashes), then performance, then QoL. Each batch is self-contained and deployable.

**Tech Stack:** PHP 8.2, MariaDB 10.11, Apache 2, Framework7 CSS, jQuery 3.7.1

---

## Batch 1: Data Integrity — Transaction Wrapping (CRITICAL)

**Rationale:** These are the highest-risk issues. Without transactions, concurrent requests can duplicate resources, double-spend atoms, or corrupt the database during season reset.

### Task 1.1: Wrap remiseAZero() in withTransaction()

**Files:**
- Modify: `includes/modules/player.php:793-838`

**Step 1:** Read player.php and locate remiseAZero()

**Step 2:** Wrap the function body in withTransaction:
```php
function remiseAZero($base) {
    withTransaction($base, function() use ($base) {
        // all existing UPDATE/DELETE statements go here
        // ...
    });
}
```

**Step 3:** Also add missing table resets inside the transaction:
```php
dbExecute($base, "DELETE FROM cours");
dbExecute($base, "DELETE FROM connectes");
dbExecute($base, "UPDATE molecules SET isotope = NULL");
dbExecute($base, "UPDATE constructions SET spec_combat = 0, spec_economy = 0, spec_research = 0");
dbExecute($base, "DELETE FROM grades");
dbExecute($base, "DELETE FROM vacances");
```

**Step 4:** Verify: Run existing PHPUnit tests
```bash
cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit
```

**Step 5:** Commit
```bash
git add includes/modules/player.php && git commit -m "fix(C-001): wrap remiseAZero in withTransaction, add missing table resets"
```

### Task 1.2: Wrap attack launch in withTransaction()

**Files:**
- Modify: `attaquer.php:146-158`

**Step 1:** Read attaquer.php, locate the attack submission block (~line 146)

**Step 2:** Wrap molecule deduction + INSERT + energy updates in transaction with FOR UPDATE:
```php
withTransaction($base, function() use ($base, $nbClasses, ...) {
    // Lock molecules
    $molecules = dbFetchAll($base, "SELECT * FROM molecules WHERE login = ? FOR UPDATE", [$_SESSION['login']]);
    // Lock ressources
    $res = dbFetchOne($base, "SELECT * FROM ressources WHERE login = ? FOR UPDATE", [$_SESSION['login']]);

    // Re-validate counts against locked values
    // ... deduction loop ...
    // INSERT actionsattaques
    // UPDATE energie
});
```

**Step 3:** Verify: Run phpunit tests
**Step 4:** Commit: `git commit -m "fix(C-003): wrap attack launch in withTransaction with FOR UPDATE"`

### Task 1.3: Wrap build queue in withTransaction()

**Files:**
- Modify: `constructions.php:251-290`

**Step 1:** Read constructions.php, locate traitementConstructions block

**Step 2:** Wrap resource deduction + INSERT actionsconstruction in transaction:
```php
withTransaction($base, function() use ($base, ...) {
    $res = dbFetchOne($base, "SELECT * FROM ressources WHERE login = ? FOR UPDATE", [$_SESSION['login']]);
    // Re-validate resource amounts against locked values
    // UPDATE ressources
    // INSERT actionsconstruction
    // UPDATE autre energieDepensee
});
```

**Step 3:** Verify + commit: `"fix(C-009): wrap build queue in withTransaction with FOR UPDATE"`

### Task 1.4: Fix combat pre-transaction decay writes

**Files:**
- Modify: `includes/modules/game_actions.php:94-107`

**Step 1:** Read game_actions.php, locate the molecule decay loop and the manual BEGIN/COMMIT

**Step 2:** Move the decay loop (lines ~94-103) to INSIDE the manual transaction block (after mysqli_begin_transaction)

**Step 3:** Verify + commit: `"fix(C-011): move combat decay loop inside transaction block"`

### Task 1.5: Block game actions during maintenance

**Files:**
- Modify: `includes/basicprivatephp.php:299-307`

**Step 1:** After the maintenance check sets $erreur, add an exit:
```php
if ($maintenance) {
    // For AJAX/form submissions, block entirely
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Le jeu est en maintenance']);
        exit;
    }
}
```

**Step 2:** Verify + commit: `"fix(C-012): block POST actions during maintenance mode"`

### Task 1.6: Fix ajouterPoints to use atomic UPDATE

**Files:**
- Modify: `includes/modules/player.php:73-106`

**Step 1:** Replace SELECT+UPDATE with atomic UPDATE:
```php
function ajouterPoints($base, $login, $points) {
    dbExecute($base, "UPDATE autre SET points = points + ?, totalPoints = totalPoints + ? WHERE login = ?",
              [$points, max(0, $points), $login]);
}
```

**Step 2:** Verify + commit: `"fix(H-017): atomic ajouterPoints prevents concurrent lost updates"`

### Task 1.7: Wrap remaining HIGH transaction gaps (batch)

**Files:**
- Modify: `includes/modules/game_actions.php` (return trip, formation completion)
- Modify: `armee.php` (neutrino formation, molecule creation, formation queue)
- Modify: `marche.php` (resource send)
- Modify: `alliance.php` (research upgrade)

**Step 1-7:** For each operation listed in DATA-CROSS HIGH findings (H-001, H-002, H-011–016), wrap in withTransaction() with appropriate FOR UPDATE locks.

Pattern for each:
```php
withTransaction($base, function() use ($base, ...) {
    $row = dbFetchOne($base, "SELECT ... FOR UPDATE", [...]);
    // Re-validate
    // Perform writes
});
```

**Step 8:** Verify all tests pass
**Step 9:** Commit: `"fix(H-001 through H-016): wrap all HIGH-priority operations in transactions"`

---

## Batch 2: Combat Null Guards & Error Handling (CRITICAL)

### Task 2.1: Add null guards to all combat dbFetchOne calls

**Files:**
- Modify: `includes/combat.php`

**Step 1:** Read combat.php completely

**Step 2:** For each dbFetchOne call (lines 5, 15, 28-35, 41, 43, 48, 55, 360, 427, 557), add null guard:
```php
$niveauxAttaquant = dbFetchOne($base, "SELECT ...", [...]);
if (!$niveauxAttaquant) {
    logError("Combat: attacker constructions missing for $loginAttaquant");
    // Return troops to attacker
    returnTroopsOnError($base, $loginAttaquant, $troupes);
    return;
}
```

**Step 3:** Create helper function returnTroopsOnError() that credits molecules back and deletes the action.

**Step 4:** Verify + commit: `"fix(C-002): null guards on all combat dbFetchOne calls, troop return on error"`

### Task 2.2: Fix combat formula bug — wrong isotope variable

**Files:**
- Modify: `includes/combat.php:206`

**Step 1:** Change attacker damage to use attacker's isotope modifier, not defender's.

**Step 2:** Verify + commit: `"fix(C-025): use attacker isotope modifier in attacker damage formula"`

### Task 2.3: Fix division by zero in formation processing

**Files:**
- Modify: `includes/modules/game_actions.php:44,51`

**Step 1:** Add guard:
```php
if ($actions['tempsPourUn'] <= 0) {
    logError("Formation tempsPourUn <= 0 for action " . $actions['id']);
    continue;
}
```

**Step 2:** Verify + commit: `"fix(C-014): guard against division by zero in formation processing"`

### Task 2.4: Fix undefined variables in combat report template

**Files:**
- Modify: `includes/modules/game_actions.php:150-153`

**Step 1:** Before report template, assign variables from combat resolution output:
```php
$attaquePts = $resultatCombat['attaquePts'] ?? 0;
$defensePts = $resultatCombat['defensePts'] ?? 0;
$pillagePts = $resultatCombat['pillagePts'] ?? 0;
```

**Step 2:** Verify + commit: `"fix(C-015): assign combat report template variables from combat output"`

### Task 2.5: Fix season winner undefined variable

**Files:**
- Modify: `includes/basicprivatephp.php:155-170`

**Step 1:** Initialize before loop and guard:
```php
$vainqueurManche = null;
// ... existing while loop ...
if ($vainqueurManche !== null) {
    // prestige award and email
}
```

**Step 2:** Verify + commit: `"fix(C-017): guard season winner variable against empty rankings"`

### Task 2.6: Fix null dereference in auth guard

**Files:**
- Modify: `includes/basicprivatephp.php:75-77`

**Step 1:** Add null guard:
```php
$donnees = dbFetchOne($base, "SELECT ...", [...]);
if (!$donnees) {
    session_destroy();
    header('Location: index.php');
    exit;
}
```

**Step 2:** Verify + commit: `"fix(C-018): null guard in auth for deleted-but-sessioned players"`

### Task 2.7: Fix return trip if/if → if/elseif

**Files:**
- Modify: `includes/modules/game_actions.php:446`

**Step 1:** Change the return-trip `if` to `elseif` so it only fires when combat branch didn't fire.

**Step 2:** Verify + commit: `"fix(H-056): return trip uses elseif to prevent double-processing"`

### Task 2.8: Fix array bounds check on troupes string

**Files:**
- Modify: `includes/modules/game_actions.php:95,457`

**Step 1:** Before array access:
```php
$troupesParts = explode(";", $actions['troupes']);
if (count($troupesParts) < $nbClasses) {
    logError("Malformed troupes string: " . $actions['troupes']);
    continue;
}
```

**Step 2:** Verify + commit: `"fix(H-057): validate troupes array length before index access"`

---

## Batch 3: Security Fixes (CRITICAL + HIGH)

### Task 3.1: Fix admin session security

**Files:**
- Modify: `admin/index.php`
- Modify: `moderation/index.php`

**Step 1:** Add session_regenerate_id(true) after successful admin login
**Step 2:** Add CSRF token to admin login form
**Step 3:** Use separate session name: session_name('tvlw_admin')
**Step 4:** Verify + commit: `"fix(C-005): admin session hardening — regenerate, CSRF, separate namespace"`

### Task 3.2: Fix admin stored XSS

**Files:**
- Modify: `admin/tableau.php`

**Step 1:** Apply htmlspecialchars() to all database values before echo.
**Step 2:** Replace strip_tags with htmlspecialchars for news content output.
**Step 3:** Verify + commit: `"fix(C-008): htmlspecialchars on all admin output"`

### Task 3.3: Fix invitation ownership check

**Files:**
- Modify: `alliance.php:116-134`

**Step 1:** Add WHERE invite = ? to invitation queries:
```php
$invitation = dbFetchOne($base, "SELECT * FROM invitations WHERE id = ? AND invite = ?",
                         [$_POST['id'], $_SESSION['login']]);
```

**Step 2:** Verify + commit: `"fix(C-016): invitation acceptance requires ownership verification"`

### Task 3.4: Fix stale grade access to alliance admin

**Files:**
- Modify: `allianceadmin.php:17-24`
- Modify: `alliance.php` (quit handler)

**Step 1:** Add alliance membership check to allianceadmin.php:
```php
$joueur = dbFetchOne($base, "SELECT idalliance FROM autre WHERE login = ?", [$_SESSION['login']]);
if ($joueur['idalliance'] != $idalliance) { header('Location: alliance.php'); exit; }
```

**Step 2:** Clear grades on alliance quit:
```php
dbExecute($base, "DELETE FROM grades WHERE login = ?", [$_SESSION['login']]);
```

**Step 3:** Verify + commit: `"fix(H-027): clear grades on quit, verify alliance membership in admin"`

### Task 3.5: Fix BBCode ReDoS

**Files:**
- Modify: `includes/bbcode.php:331`

**Step 1:** Replace vulnerable URL regex with non-backtracking pattern:
```php
// Before: nested quantifiers
// After: atomic/possessive pattern
$text = preg_replace('/\[url=(https?:\/\/[^\]]+)\](.*?)\[\/url\]/si', '<a href="$1" target="_blank">$2</a>', $text);
```

**Step 2:** Verify + commit: `"fix(C-031): BBCode url regex rewrite to prevent ReDoS"`

### Task 3.6: Fix password minimum length

**Files:**
- Modify: `inscription.php`
- Modify: `compte.php`

**Step 1:** Add length check:
```php
if (mb_strlen($password) < 8) {
    $erreur = "Le mot de passe doit contenir au moins 8 caractères.";
}
```

**Step 2:** Verify + commit: `"fix(H-023): minimum 8 character password requirement"`

### Task 3.7: Fix forum post length limit

**Files:**
- Modify: `sujet.php`
- Modify: `listesujets.php`

**Step 1:** Add: `if (mb_strlen($_POST['contenu']) > 10000) { $erreur = "Message trop long"; }`
**Step 2:** Verify + commit: `"fix(H-024): forum post max 10000 characters"`

### Task 3.8: Fix BBCode [img] CSRF potential

**Files:**
- Modify: `includes/bbcode.php:332`

**Step 1:** Restrict [img] to self-hosted images only:
```php
// Only allow images from our domain
$text = preg_replace('/\[img=(https?:\/\/(?:www\.)?theverylittlewar\.com\/[^\]]+)\]/si', '<img src="$1">', $text);
// Block external images with informative text
$text = preg_replace('/\[img=([^\]]+)\]/si', '[Image externe bloquée]', $text);
```

**Step 2:** Verify + commit: `"fix(H-025): restrict BBCode img to self-hosted URLs only"`

### Task 3.9: Fix XSS in message titles (debutCarte)

**Files:**
- Modify: `includes/modules/ui_components.php:16-19`

**Step 1:** Escape inside debutCarte:
```php
function debutCarte($titre, ...) {
    $titre = htmlspecialchars($titre, ENT_QUOTES, 'UTF-8');
    // rest of function
}
```

**Step 2:** Verify + commit: `"fix(H-034): htmlspecialchars inside debutCarte prevents stored XSS"`

### Task 3.10: Fix SQL column interpolation whitelists

**Files:**
- Modify: `includes/modules/game_resources.php:152-158`
- Modify: `includes/combat.php:579-612`
- Modify: `includes/modules/game_actions.php:528-538`
- Modify: `alliance.php:94-113`

**Step 1:** Add whitelist guards before column interpolation:
```php
$allowedColumns = ['carbone', 'azote', 'hydrogene', 'oxygene', 'chlore', 'soufre', 'brome', 'iode'];
foreach ($nomsRes as $ressource) {
    if (!in_array($ressource, $allowedColumns)) { continue; }
    // existing code
}
```

**Step 2:** For alliance research, use ALLIANCE_RESEARCH_COLUMNS from db_helpers.php
**Step 3:** Verify + commit: `"fix(H-037 through H-042): column whitelist guards on all dynamic SQL"`

### Task 3.11: Protect /migrations/ directory

**Files:**
- Create: `migrations/.htaccess`

**Step 1:**
```apache
Deny from all
```

**Step 2:** Verify + commit: `"fix(H-046): deny web access to migrations directory"`

### Task 3.12: Move admin password to .env

**Files:**
- Modify: `includes/constantesBase.php:54`

**Step 1:** Replace hardcoded hash with:
```php
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD_HASH') ?: '$2y$10$...[current hash as fallback]');
```

**Step 2:** Add to .env on VPS: `ADMIN_PASSWORD_HASH=<hash>`
**Step 3:** Verify + commit: `"fix(C-013): admin password hash loaded from env variable"`

---

## Batch 4: Schema & Infrastructure Fixes

### Task 4.1: Fix actionsformation.idclasse type

**Files:**
- Create: `migrations/0014_fix_idclasse_type.sql`

```sql
ALTER TABLE actionsformation MODIFY idclasse VARCHAR(50) NOT NULL DEFAULT '0';
```

**Step 1:** Create migration file
**Step 2:** Verify + commit: `"fix(C-029): actionsformation.idclasse INT→VARCHAR(50) for neutrino support"`

### Task 4.2: Fix prestige.login VARCHAR length

**Files:**
- Create: `migrations/0015_fix_prestige_login.sql`

```sql
ALTER TABLE prestige MODIFY login VARCHAR(255) NOT NULL;
```

### Task 4.3: Fix charset to utf8mb4

**Files:**
- Modify: `includes/connexion.php:20`

```php
mysqli_set_charset($base, "utf8mb4");
```

### Task 4.4: Fix ISO-8859-1 charset declarations

**Files:**
- Modify: `admin/listesujets.php:8`
- Modify: `moderation/index.php`

Change to `<meta charset="UTF-8">`.

### Task 4.5: Add missing indexes

**Files:**
- Create: `migrations/0016_missing_indexes.sql`

```sql
ALTER TABLE autre ADD INDEX idx_totalPoints (totalPoints);
ALTER TABLE connectes ADD PRIMARY KEY (login);
```

### Task 4.6: Fix supprimerJoueur missing cleanups

**Files:**
- Modify: `includes/modules/player.php`

Add inside the existing withTransaction:
```php
dbExecute($base, "DELETE FROM prestige WHERE login = ?", [$login]);
dbExecute($base, "DELETE FROM attack_cooldowns WHERE login = ?", [$login]);
dbExecute($base, "DELETE FROM sanctions WHERE joueur = ?", [$login]);
```

### Task 4.7: Fix comptetest.php visitor rename missing tables

**Files:**
- Modify: `comptetest.php:66-83`

Add inside the rename transaction:
```php
dbExecute($base, "UPDATE prestige SET login = ? WHERE login = ?", [$newLogin, $oldLogin]);
dbExecute($base, "UPDATE attack_cooldowns SET login = ? WHERE login = ?", [$newLogin, $oldLogin]);
dbExecute($base, "UPDATE vacances SET idJoueur = (SELECT id FROM membre WHERE login = ?) WHERE idJoueur = (SELECT id FROM membre WHERE login = ?)", [$newLogin, $oldLogin]);
```

**Batch 4 commit:** `"fix(C-026–029, H-018–019, H-044–045): schema fixes, charset, indexes, cleanup"`

---

## Batch 5: Dead Code Removal & Code Quality

### Task 5.1: Delete 7 dead files

```bash
rm annonce.php video.php includes/update.php includes/cardspublic.php includes/mots.php includes/menus.php includes/partenariat.php
```

### Task 5.2: Remove $_SESSION['start'] dead code

**Files:** index.php:3, classement.php:2, joueur.php

Remove the `$_SESSION['start'] = ...` lines.

### Task 5.3: Remove dead Cordova/AES scripts from copyright.php

**Files:** includes/copyright.php:20-29

Remove the dead `<script>` tags for Cordova, AES, jcryption.

### Task 5.4: Fix scripts loaded after </html>

Move remaining scripts to before `</body>`.

**Batch 5 commit:** `"fix(M-050–059): remove dead files, dead code, dead scripts"`

---

## Batch 6: Performance Fixes (HIGH)

### Task 6.1: Add static cache to revenuEnergie/revenuAtome

**Files:**
- Modify: `includes/modules/game_resources.php`

```php
function revenuEnergie($base, $login) {
    static $cache = [];
    if (isset($cache[$login])) return $cache[$login];
    // ... existing calculation ...
    $cache[$login] = $result;
    return $result;
}
```

Same pattern for revenuAtome.

### Task 6.2: Cache coefDisparition per request

**Files:**
- Modify: `includes/modules/formulas.php`

Same static cache pattern.

### Task 6.3: Fix double initPlayer call

**Files:**
- Modify: `includes/basicprivatephp.php`

Use `include_once` or `require_once` for constantes.php.

### Task 6.4: Throttle online tracking to once per 60s

```php
if (!isset($_SESSION['last_online_update']) || time() - $_SESSION['last_online_update'] > 60) {
    // existing UPDATE connectes
    $_SESSION['last_online_update'] = time();
}
```

### Task 6.5: Fix N+1 moleculesPerdues to atomic UPDATE

Replace SELECT+UPDATE loop with:
```php
dbExecute($base, "UPDATE autre SET moleculesPerdues = moleculesPerdues + ? WHERE login = ?", [$totalLost, $login]);
```

**Batch 6 commit:** `"fix(H-003 through H-006, H-043): performance — static caches, N+1 fixes, throttled tracking"`

---

## Batch 7: Game Balance (CRITICAL)

### Task 7.1: Rebalance stat formulas

**Files:**
- Modify: `includes/config.php:63-86`

Apply BAL-CROSS recommended coefficients (see BAL-CROSS.md for specific values).

### Task 7.2: Fix formation balance

Apply formation multiplier changes from BAL-CROSS.

### Task 7.3: Fix decay formula

Reduce decay exponent per BAL-CROSS recommendations.

### Task 7.4: Cap cross-season medal bonuses

```php
define('MAX_CROSS_SEASON_MEDAL_BONUS', 10); // % cap
```

**Batch 7 commit:** `"fix(C-021–024, C-032): game balance — formula rebalancing, decay fix, medal cap"`

---

## Batch 8: QoL Quick Wins

### Task 8.1: Create prestige.php page
### Task 8.2: Add new player tutorial redirect
### Task 8.3: Add medal progress bars
### Task 8.4: Fix formation time variable
### Task 8.5: Add season countdown to header
### Task 8.6: Fix cache invalidation in augmenter/diminuerBatiment

**Batch 8 commit:** `"feat: QoL — prestige page, tutorial redirect, medal bars, countdown"`

---

## Batch 9: Infrastructure (blocked on HTTPS)

### Task 9.1: Enable HTTPS via Let's Encrypt
### Task 9.2: Hardcode session.cookie_secure=1
### Task 9.3: Add HSTS header
### Task 9.4: Update CSP — add gstatic.com
### Task 9.5: Begin inline script extraction (Phase 2 CSP)

---

## Follow-Up Tracking Table

| Batch | Finding IDs | Status | Commit | Verified |
|---|---|---|---|---|
| 1 | C-001, C-003, C-009, C-011, C-012, H-017, H-001–016 | OPEN | — | [ ] |
| 2 | C-002, C-014, C-015, C-017, C-018, C-025, H-056, H-057 | OPEN | — | [ ] |
| 3 | C-005, C-008, C-013, C-016, C-031, H-023–029, H-034–042, H-046 | OPEN | — | [ ] |
| 4 | C-026–029, H-018–020, H-044–045 | OPEN | — | [ ] |
| 5 | M-050–059 | OPEN | — | [ ] |
| 6 | H-003–006, H-043 | OPEN | — | [ ] |
| 7 | C-021–024, C-032 | OPEN | — | [ ] |
| 8 | C-030, H-059–062, H-055, H-007 | OPEN | — | [ ] |
| 9 | C-004, C-006, C-007, C-019–020 | OPEN (blocked HTTPS) | — | [ ] |

**Total: 9 batches, ~45 tasks, covering all 32 CRITICAL + 62 HIGH findings**
