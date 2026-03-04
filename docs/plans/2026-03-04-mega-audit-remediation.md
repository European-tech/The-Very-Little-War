# Mega Audit Remediation Plan — March 2026

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Remediate all verified findings from the 5-agent comprehensive audit (security, code quality, game balance, UI/UX, database integrity).

**Architecture:** Batched remediation — each batch targets one audit domain, ordered by severity and independence. Each task is self-contained with clear test criteria. No inter-batch dependencies except where noted.

**Tech Stack:** PHP 8.2, MariaDB 10.11, vanilla JS, Framework7 Material CSS

**Prior Art:** Batches A-I and V4 balance overhaul already completed (~150+ items fixed). This plan covers the REMAINING verified findings.

---

## Audit Summary — Verified Findings

### False Positives Filtered Out
| Claimed Finding | Reality |
|---|---|
| BBcode() function undefined | `BBCode()` exists at `includes/bbcode.php:315`. PHP function names are case-insensitive. |
| don.php missing CSRF | Has `csrfField()`/`csrfCheck()` — verified by grep. |
| marche.php missing withTransaction | Has 2 instances of `withTransaction()` — verified by grep. |
| "No confirmation on destructive actions" | 0 `confirm()` calls found, but most destructive actions are multi-step (form + page reload). Account deletion is the main concern. |

### Verified Findings by Severity

**CRITICAL (4):**
- C-001: Rate limiter stores files in `/tmp/tvlw_rates` — world-readable, clearable by OS
- C-002: 111 raw `mysqli_fetch_array` calls across 43 files (bypasses prepared statement helpers)
- C-003: 36 inline `window.location`/`document.location` JS redirects across 18 PHP files (blocks CSP)
- C-004: No foreign key constraints on any of 28 tables (orphaned data possible)

**HIGH (7):**
- H-001: 45 bare `die()`/`exit()` calls across 24 files (no error logging, bad UX)
- H-002: 10 files still use `stripslashes()` (dead code since PHP 5.4)
- H-003: 3 files with inline `onclick`/`onsubmit`/`onchange` handlers (blocks CSP)
- H-004: Account deletion has no confirmation dialog
- H-005: Medal thresholds for Attaque/Defense/Pillage upper tiers likely unreachable in 31-day season
- H-006: Isotope Stable is strictly inferior to Normal at equal decay (design intent unclear)
- H-007: Prestige production/combat bonuses (+5%) compound with medals creating veteran snowball

**MEDIUM (8):**
- M-001: `creerBBcode()` function is a no-op (no toolbar, just displays "BBcode activé")
- M-002: No CHECK constraints on numeric columns (negative values possible at DB level)
- M-003: No empty-state messages for lists (armies, reports, messages)
- M-004: Missing `<label>` associations on form inputs (accessibility)
- M-005: Dead JS files and unused BBcode toolbar functions in `includes/bbcode.php`
- M-006: No CI pipeline — 370 tests never run automatically on push
- M-007: No monitoring — no health endpoint, no uptime checks, no error tracking
- M-008: Dead/redundant files: `constantes.php` (empty wrapper), root `atomes.php` (duplicate with wrong field), old jQuery 1.7.2/UI 1.8.18 files

---

## Batch J: Code Quality — mysqli_fetch_array Migration

**Priority:** CRITICAL — These bypass the prepared statement helpers and prevent future DB abstraction.

**Strategy:** File-by-file migration from raw `mysqli_fetch_array($result)` loops to `dbFetchAll()`/`dbFetchOne()` calls. Each file is independent.

**Key constraint:** Many callsites use the pattern `$result = dbQuery(...); while ($row = mysqli_fetch_array($result)) { ... }`. The migration is: `$rows = dbFetchAll(...); foreach ($rows as $row) { ... }`.

### Task J-1: Migrate core includes (7 files, 21 occurrences)

**Files:**
- Modify: `includes/combat.php` (3 occurrences)
- Modify: `includes/game_actions.php` (7 occurrences)
- Modify: `includes/game_resources.php` (1 occurrence)
- Modify: `includes/player.php` (8 occurrences)
- Modify: `includes/basicpublicphp.php` (1 occurrence)
- Modify: `includes/basicprivatephp.php` (1 occurrence)
- Modify: `includes/copyright.php` (1 occurrence, if present)

**Step 1:** For each file, find all `mysqli_fetch_array` patterns and replace:
```php
// OLD:
$result = dbQuery($base, 'SELECT ...', ...);
while ($row = mysqli_fetch_array($result)) { ... }

// NEW:
$rows = dbFetchAll($base, 'SELECT ...', ...);
foreach ($rows as $row) { ... }
```

For single-row fetches:
```php
// OLD:
$result = dbQuery($base, 'SELECT ...', ...);
$row = mysqli_fetch_array($result);

// NEW:
$row = dbFetchOne($base, 'SELECT ...', ...);
```

**Step 2:** Run the test suite to verify nothing broke:
```bash
cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit
```

**Step 3:** Commit
```bash
git add includes/combat.php includes/game_actions.php includes/game_resources.php includes/player.php includes/basicpublicphp.php includes/basicprivatephp.php includes/copyright.php
git commit -m "refactor: migrate 21 mysqli_fetch_array calls to dbFetchAll/dbFetchOne in core includes"
```

### Task J-2: Migrate page files — batch 1 (15 files, ~45 occurrences)

**Files:** `alliance.php`, `allianceadmin.php`, `armee.php`, `attaque.php`, `attaquer.php`, `classement.php`, `constructions.php`, `ecriremessage.php`, `editer.php`, `forum.php`, `guerre.php`, `historique.php`, `index.php`, `joueur.php`, `listesujets.php`

**Step 1:** Same pattern replacement as J-1 for each file.

**Step 2:** Run tests: `php vendor/bin/phpunit`

**Step 3:** Commit
```bash
git add alliance.php allianceadmin.php armee.php attaque.php attaquer.php classement.php constructions.php ecriremessage.php editer.php forum.php guerre.php historique.php index.php joueur.php listesujets.php
git commit -m "refactor: migrate ~45 mysqli_fetch_array calls to dbFetchAll/dbFetchOne in page files (batch 1)"
```

### Task J-3: Migrate page files — batch 2 + admin (remaining ~45 occurrences)

**Files:** `marche.php`, `messages.php`, `messagesenvoyes.php`, `messageCommun.php`, `moderationForum.php`, `molecule.php`, `rapports.php`, `sujet.php`, `connectes.php`, `atomes.php`, `maintenance.php`, `admin/index.php`, `admin/tableau.php`, `admin/ip.php`, `admin/listenews.php`, `admin/listesujets.php`, `admin/supprimerreponse.php`, `moderation/index.php`, `moderation/ip.php`

**Step 1:** Same migration pattern.

**Step 2:** Run tests: `php vendor/bin/phpunit`

**Step 3:** Verify zero `mysqli_fetch_array` remaining:
```bash
grep -r "mysqli_fetch_array" --include="*.php" | grep -v "vendor/" | grep -v "docs/" | wc -l
# Expected: 0
```

**Step 4:** Commit
```bash
git add [all modified files]
git commit -m "refactor: migrate final ~45 mysqli_fetch_array calls — zero remaining"
```

---

## Batch K: Code Quality — die()/exit() and stripslashes() Cleanup

### Task K-1: Remove stripslashes() from 10 files

**Files:**
- `index.php`, `allianceadmin.php`, `moderation/index.php`, `alliance.php`, `admin/listesujets.php`, `admin/redigernews.php`, `admin/listenews.php`, `admin/supprimerreponse.php`, `ecriremessage.php`, `maintenance.php`

`stripslashes()` was only relevant with `magic_quotes_gpc` which was removed in PHP 5.4. These calls are dead code.

**Step 1:** In each file, remove `stripslashes()` wrapper calls, leaving the inner expression:
```php
// OLD:
stripslashes($variable)
// NEW:
$variable

// OLD:
stripslashes(preg_replace('#(\\\r\\\n|\\\r|\\\n)#', "\n", ($_POST['contenu'])))
// NEW:
preg_replace('#(\r\n|\r|\n)#', "\n", $_POST['contenu'])
```
Note: also fix the escaped backslashes in the regex — `\\\r\\\n` was meant for magic_quotes-escaped input.

**Step 2:** Run tests: `php vendor/bin/phpunit`

**Step 3:** Commit
```bash
git add [all 10 files]
git commit -m "fix: remove dead stripslashes() calls from 10 files (magic_quotes_gpc removed in PHP 5.4)"
```

### Task K-2: Replace bare die()/exit() with proper error handling (24 files, 45 calls)

**Strategy:** Replace `die()` with `logEvent()` + redirect or HTTP error response. Group by pattern:

1. **Auth guards** (`die('Accès interdit')` etc.) → `http_response_code(403); exit;` (already correct pattern, just log)
2. **DB errors** (`die('Erreur...')`) → `logEvent('error', '...')` + generic error page
3. **Redirect-after-POST** (`exit` after `header('Location: ...')`) → keep as-is (correct pattern)
4. **Validation failures** (`die(...)`) → redirect with error message

**Step 1:** For each file, replace bare die() calls:
```php
// Pattern 1 — Auth guard (keep but add logging):
// OLD: die('Accès interdit');
// NEW: logEvent('warning', 'Unauthorized access attempt', ['page' => basename(__FILE__)]); http_response_code(403); exit;

// Pattern 2 — DB error:
// OLD: die('Erreur technique');
// NEW: logEvent('error', 'Database error', ['page' => basename(__FILE__)]); http_response_code(500); exit;

// Pattern 3 — After redirect (keep as-is):
header('Location: index.php'); exit; // This is fine
```

**Step 2:** Run tests: `php vendor/bin/phpunit`

**Step 3:** Commit
```bash
git add [all modified files]
git commit -m "refactor: replace 45 bare die()/exit() with proper error logging and HTTP status codes"
```

---

## Batch L: Security Hardening

### Task L-1: Move rate limiter storage out of /tmp

**Files:**
- Modify: `includes/rate_limiter.php`

**Step 1:** Change default directory from `/tmp/tvlw_rates` to a game-local directory:
```php
// OLD:
define('RATE_LIMIT_DIR', '/tmp/tvlw_rates');

// NEW:
define('RATE_LIMIT_DIR', __DIR__ . '/../data/rates');
```

**Step 2:** Create the data directory with appropriate permissions:
```bash
mkdir -p /home/guortates/TVLW/The-Very-Little-War/data/rates
chmod 750 /home/guortates/TVLW/The-Very-Little-War/data
chmod 750 /home/guortates/TVLW/The-Very-Little-War/data/rates
```

**Step 3:** Add `.htaccess` protection:
```
# data/.htaccess
Deny from all
```

**Step 4:** Add `data/` to `.gitignore` (rate files are transient).

**Step 5:** Run tests: `php vendor/bin/phpunit`

**Step 6:** Commit
```bash
git add includes/rate_limiter.php data/.htaccess .gitignore
git commit -m "security: move rate limiter storage from /tmp to data/rates (not world-readable)"
```

### Task L-2: Add account deletion confirmation

**Files:**
- Modify: `compte.php` (the account settings page with delete option)

**Step 1:** Find the account deletion form/link and add a JavaScript confirmation:
```javascript
// In the external JS file or inline (will be extracted in CSP batch):
document.querySelector('[name="supprimercompte"]')?.closest('form')?.addEventListener('submit', function(e) {
    if (!confirm('Êtes-vous sûr de vouloir supprimer votre compte ? Cette action est irréversible.')) {
        e.preventDefault();
    }
});
```

**Step 2:** Test manually — verify deletion requires confirmation click.

**Step 3:** Commit
```bash
git add compte.php
git commit -m "ux: add confirmation dialog before account deletion"
```

---

## Batch M: CSP Preparation — Extract Inline JavaScript

**Priority:** HIGH — This is the prerequisite for enabling Content Security Policy headers.

**Strategy:** Extract all inline JavaScript from PHP files into external `.js` files. This covers:
- 36 `window.location`/`document.location` inline redirects across 18 files
- 3 `onclick`/`onsubmit`/`onchange` handlers across 3 files
- Any `<script>` blocks embedded in PHP files

### Task M-1: Create redirect utility JS

**Files:**
- Create: `js/redirect.js`

**Step 1:** Create a utility that handles form-based redirections:
```javascript
/**
 * TVLW redirect utilities.
 * Replaces inline window.location assignments with data-attribute-driven redirects.
 */
(function () {
    "use strict";

    // Handle all elements with data-redirect attribute
    document.addEventListener("click", function (e) {
        var target = e.target.closest("[data-redirect]");
        if (target) {
            e.preventDefault();
            window.location.href = target.getAttribute("data-redirect");
        }
    });

    // Handle delayed redirects (set via data-redirect-delay on body or specific element)
    var delayed = document.querySelectorAll("[data-redirect-after]");
    delayed.forEach(function (el) {
        var url = el.getAttribute("data-redirect-after");
        var delay = parseInt(el.getAttribute("data-redirect-delay") || "2000", 10);
        setTimeout(function () {
            window.location.href = url;
        }, delay);
    });
})();
```

**Step 2:** Include in `includes/copyright.php` alongside countdown.js.

**Step 3:** Commit
```bash
git add js/redirect.js includes/copyright.php
git commit -m "feat: add redirect.js utility for CSP-compliant redirections"
```

### Task M-2: Extract inline JS from page files — batch 1 (9 files)

**Files:** `attaquer.php`, `attaque.php`, `constructions.php`, `alliance.php`, `allianceadmin.php`, `validerpacte.php`, `compte.php`, `armee.php`, `sinstruire.php`

**Step 1:** For each file, replace inline `<script>window.location='...'</script>` with either:
- `header('Location: ...'); exit;` (if server-side redirect is possible — preferred)
- `data-redirect="..."` attribute on the triggering element (if client-side needed)
- Or move to a dedicated `js/[pagename].js` file for complex logic

**Step 2:** Replace inline event handlers:
```php
// OLD:
<button onclick="window.location='page.php?action=do'">

// NEW:
<a href="page.php?action=do" class="button">
```

**Step 3:** Run tests: `php vendor/bin/phpunit`

**Step 4:** Commit
```bash
git add [all modified files] js/
git commit -m "refactor: extract inline JS from 9 page files for CSP compliance"
```

### Task M-3: Extract inline JS from page files — batch 2 (9 files)

**Files:** `editer.php`, `deconnexion.php`, `ecriremessage.php`, `forum.php`, `inscription.php`, `listesujets.php`, `marche.php`, `messages.php`, `messageCommun.php`

Same pattern as M-2.

**Commit:**
```bash
git commit -m "refactor: extract inline JS from 9 more page files for CSP compliance"
```

### Task M-4: Extract inline JS from includes + remaining files

**Files:** `includes/basicprivatehtml.php` (7 occurrences!), `includes/redirectionVacance.php`, `includes/copyright.php`, `rapports.php`, `comptetest.php`, `don.php`, `moderation/mdp.php`, `admin/redirectionmotdepasse.php`

Same pattern. `basicprivatehtml.php` has the most — these are likely the Framework7 navigation helpers.

**Commit:**
```bash
git commit -m "refactor: extract inline JS from includes and remaining files — zero inline JS remaining"
```

### Task M-5: Add CSP headers

**Files:**
- Modify: `includes/basicprivatehtml.php` (add header)
- Modify: `includes/basicpublicphp.php` (add header)
- Or create: `includes/headers.php`

**Step 1:** Add CSP header without `unsafe-inline`:
```php
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data:; font-src 'self' https://cdnjs.cloudflare.com;");
```

Note: `unsafe-inline` kept for styles only (Framework7 requires it). Scripts are CSP-clean.

**Step 2:** Test all 34 pages — verify no CSP violations in browser console.

**Step 3:** Commit
```bash
git commit -m "security: add Content-Security-Policy headers (no unsafe-inline for scripts)"
```

---

## Batch N: Game Balance V4.1 Adjustments

**Priority:** HIGH — These are design-level balance issues found during deep formula analysis. Each is independent.

**Note:** These require playtesting to verify. Implement with clear constants in config.php so they're easy to tune.

### Task N-1: Rebalance Isotope Stable

**Problem:** Stable gives -10% attack, +20% HP, -30% decay. But the HP gain doesn't offset the attack loss in combat, and the decay reduction is only significant for very large molecules. Normal is strictly better for most play patterns.

**Files:**
- Modify: `includes/config.php`

**Step 1:** Buff Stable to make it a genuine tank choice:
```php
// OLD:
define('ISOTOPE_STABLE_ATTACK_MOD', -0.10);
define('ISOTOPE_STABLE_HP_MOD', 0.20);
define('ISOTOPE_STABLE_DECAY_MOD', -0.30);

// NEW — reduce attack penalty, increase HP bonus:
define('ISOTOPE_STABLE_ATTACK_MOD', -0.05);  // -5% attack (was -10%)
define('ISOTOPE_STABLE_HP_MOD', 0.30);       // +30% HP (was +20%)
define('ISOTOPE_STABLE_DECAY_MOD', -0.30);   // unchanged
```

**Step 2:** Update `$ISOTOPES` description:
```php
ISOTOPE_STABLE => ['name' => 'Stable', 'desc' => '-5% attaque, +30% points de vie, -30% vitesse de disparition. Rôle : tank/défenseur.'],
```

**Step 3:** Update regles.php if the isotope table mentions specific numbers.

**Step 4:** Commit
```bash
git add includes/config.php regles.php
git commit -m "balance: buff Isotope Stable (-5% atk instead of -10%, +30% HP instead of +20%)"
```

### Task N-2: Adjust medal thresholds for 31-day seasons

**Problem:** Upper-tier medal thresholds (Rubis/Diamant/Diamant Rouge) for Attaque, Defense, and Pillage are mathematically unreachable in a 31-day season. Example: Diamant Rouge Attaque requires 10,000,000 attack points — even with 100 fights/day producing 20 points each, that's only 62,000 in a month.

**Files:**
- Modify: `includes/config.php`

**Step 1:** Recalculate achievable thresholds based on realistic 31-day play:
```php
// Attaque/Defense — based on combat points formula: max 20 per fight, ~5-10 fights/day
// Realistic 31-day max: ~5000-8000 raw points → sqrt scaling → ~350-450 display points
// Adjust to make Diamant achievable by top players, Diamant Rouge aspirational

$MEDAL_THRESHOLDS_ATTAQUE = [50, 200, 500, 1500, 4000, 8000, 15000, 30000];
$MEDAL_THRESHOLDS_DEFENSE = [50, 200, 500, 1500, 4000, 8000, 15000, 30000];

// Pillage — based on tanh scaling, 50k divisor, 80 multiplier
// Realistic max pillage points: ~60-70 in a season
$MEDAL_THRESHOLDS_PILLAGE = [500, 5000, 25000, 100000, 500000, 2000000, 5000000, 10000000];
```

**Step 2:** Run tests: `php vendor/bin/phpunit` (medal tests may need threshold updates).

**Step 3:** Commit
```bash
git add includes/config.php
git commit -m "balance: adjust medal thresholds for Attaque/Defense/Pillage to be achievable in 31-day seasons"
```

### Task N-3: Add diminishing returns to prestige bonuses

**Problem:** +5% production and +5% combat per prestige season compounds with medal bonuses, creating an ever-widening gap between veterans and new players.

**Files:**
- Modify: `includes/config.php`
- Modify: `includes/formulas.php` (where prestige bonuses are applied)

**Step 1:** Cap prestige bonuses:
```php
// Add maximum prestige multiplier cap
define('PRESTIGE_MAX_PRODUCTION_BONUS', 1.25); // Max +25% (5 seasons of prestige)
define('PRESTIGE_MAX_COMBAT_BONUS', 1.15);     // Max +15% (3 seasons of prestige)
```

**Step 2:** In formulas.php, apply the cap:
```php
$prestigeBonus = min(PRESTIGE_MAX_PRODUCTION_BONUS, pow(PRESTIGE_PRODUCTION_BONUS, $prestigeSeasons));
```

**Step 3:** Commit
```bash
git add includes/config.php includes/formulas.php
git commit -m "balance: cap prestige bonuses at +25% production, +15% combat to reduce veteran snowball"
```

---

## Batch O: Database Integrity

### Task O-1: Add CHECK constraints on critical numeric columns

**Files:**
- Create: `migrations/017_add_check_constraints.sql`

**Step 1:** Write migration:
```sql
-- Prevent negative resource values
ALTER TABLE joueurs
    ADD CONSTRAINT chk_energie_nonneg CHECK (energie >= 0),
    ADD CONSTRAINT chk_carbone_nonneg CHECK (carbone >= 0),
    ADD CONSTRAINT chk_azote_nonneg CHECK (azote >= 0),
    ADD CONSTRAINT chk_hydrogene_nonneg CHECK (hydrogene >= 0),
    ADD CONSTRAINT chk_oxygene_nonneg CHECK (oxygene >= 0),
    ADD CONSTRAINT chk_chlore_nonneg CHECK (chlore >= 0),
    ADD CONSTRAINT chk_soufre_nonneg CHECK (soufre >= 0),
    ADD CONSTRAINT chk_brome_nonneg CHECK (brome >= 0),
    ADD CONSTRAINT chk_iode_nonneg CHECK (iode >= 0);

-- Prevent negative building levels
ALTER TABLE joueurs
    ADD CONSTRAINT chk_generateur_nonneg CHECK (generateur >= 0),
    ADD CONSTRAINT chk_producteur_nonneg CHECK (producteur >= 0),
    ADD CONSTRAINT chk_depot_nonneg CHECK (depot >= 0),
    ADD CONSTRAINT chk_champdeforce_nonneg CHECK (champdeforce >= 0),
    ADD CONSTRAINT chk_ionisateur_nonneg CHECK (ionisateur >= 0),
    ADD CONSTRAINT chk_condenseur_nonneg CHECK (condenseur >= 0),
    ADD CONSTRAINT chk_lieur_nonneg CHECK (lieur >= 0),
    ADD CONSTRAINT chk_stabilisateur_nonneg CHECK (stabilisateur >= 0),
    ADD CONSTRAINT chk_coffrefort_nonneg CHECK (coffrefort >= 0);

-- Prevent negative molecule atoms
ALTER TABLE molecules
    ADD CONSTRAINT chk_mol_carbone CHECK (carbone >= 0),
    ADD CONSTRAINT chk_mol_azote CHECK (azote >= 0),
    ADD CONSTRAINT chk_mol_hydrogene CHECK (hydrogene >= 0),
    ADD CONSTRAINT chk_mol_oxygene CHECK (oxygene >= 0),
    ADD CONSTRAINT chk_mol_chlore CHECK (chlore >= 0),
    ADD CONSTRAINT chk_mol_soufre CHECK (soufre >= 0),
    ADD CONSTRAINT chk_mol_brome CHECK (brome >= 0),
    ADD CONSTRAINT chk_mol_iode CHECK (iode >= 0),
    ADD CONSTRAINT chk_mol_level CHECK (niveau >= 0);
```

**Step 2:** Run migration locally: `mysql -u tvlw -p tvlw < migrations/017_add_check_constraints.sql`

**Step 3:** Verify no existing data violates constraints:
```sql
SELECT COUNT(*) FROM joueurs WHERE energie < 0 OR carbone < 0 OR azote < 0;
SELECT COUNT(*) FROM molecules WHERE carbone < 0 OR niveau < 0;
```

**Step 4:** Commit
```bash
git add migrations/017_add_check_constraints.sql
git commit -m "db: add CHECK constraints preventing negative resources, building levels, and molecule atoms"
```

### Task O-2: Add foreign key constraints (cautious approach)

**Files:**
- Create: `migrations/018_add_foreign_keys.sql`

**Note:** FK constraints are HIGH value but HIGH risk on a 15-year-old codebase. Add only the most critical FKs and test carefully.

**Step 1:** Write migration for the safest, most impactful FKs:
```sql
-- Molecules must belong to existing players
-- First, clean up any orphaned molecules
DELETE FROM molecules WHERE login NOT IN (SELECT login FROM joueurs);

ALTER TABLE molecules
    ADD CONSTRAINT fk_molecules_joueur FOREIGN KEY (login) REFERENCES joueurs(login) ON DELETE CASCADE;

-- Messages must reference existing players
DELETE FROM messages WHERE destinataire NOT IN (SELECT login FROM joueurs);
DELETE FROM messages WHERE expediteur NOT IN (SELECT login FROM joueurs);

ALTER TABLE messages
    ADD CONSTRAINT fk_messages_dest FOREIGN KEY (destinataire) REFERENCES joueurs(login) ON DELETE CASCADE,
    ADD CONSTRAINT fk_messages_exp FOREIGN KEY (expediteur) REFERENCES joueurs(login) ON DELETE CASCADE;

-- Forum replies must reference existing subjects
DELETE FROM reponses WHERE id_sujet NOT IN (SELECT id FROM sujets);

ALTER TABLE reponses
    ADD CONSTRAINT fk_reponses_sujet FOREIGN KEY (id_sujet) REFERENCES sujets(id) ON DELETE CASCADE;
```

**Step 2:** Test on a DB copy first!
```bash
mysqldump -u tvlw -p tvlw > /tmp/tvlw_backup_before_fk.sql
mysql -u tvlw -p tvlw < migrations/018_add_foreign_keys.sql
```

**Step 3:** Run full test suite: `php vendor/bin/phpunit`

**Step 4:** Commit
```bash
git add migrations/018_add_foreign_keys.sql
git commit -m "db: add foreign key constraints on molecules, messages, and forum replies"
```

---

## Batch P: UX Polish

### Task P-1: Add empty-state messages for lists

**Files:**
- Modify: `armee.php` (no molecules message)
- Modify: `rapports.php` (no reports message)
- Modify: `messages.php` (no messages message)
- Modify: `messagesenvoyes.php` (no sent messages message)
- Modify: `historique.php` (no history message)

**Step 1:** For each list page, add a user-friendly empty state after the query:
```php
if (empty($rows)) {
    echo '<div class="card"><div class="card-content card-content-padding">';
    echo '<p style="text-align:center; color:#999;">Aucun élément à afficher.</p>';
    echo '</div></div>';
} else {
    // existing loop
}
```

Use contextual messages:
- armee.php: "Vous n'avez pas encore de molécules. Créez-en dans le laboratoire !"
- rapports.php: "Aucun rapport de combat. Lancez une attaque ou défendez-vous !"
- messages.php: "Votre boîte de réception est vide."

**Step 2:** Commit
```bash
git add armee.php rapports.php messages.php messagesenvoyes.php historique.php
git commit -m "ux: add empty-state messages for armies, reports, messages, and history lists"
```

### Task P-2: Add form label associations for accessibility

**Files:**
- Modify: `inscription.php` (registration form)
- Modify: `compte.php` (account settings)
- Modify: `index.php` (login form)

**Step 1:** For each form, ensure all `<input>` elements have associated `<label>` tags:
```php
// OLD:
Login : <input type="text" name="login" ...>

// NEW:
<label for="login">Login :</label>
<input type="text" name="login" id="login" ...>
```

**Step 2:** Commit
```bash
git add inscription.php compte.php index.php
git commit -m "a11y: add label associations for all form inputs on login, registration, and account pages"
```

### Task P-3: Clean up dead JS and unused BBcode toolbar

**Files:**
- Modify: `includes/bbcode.php` — remove unused toolbar JS functions (keep only `BBCode()` function)
- Modify: `includes/display.php` — update `creerBBcode()` to either restore minimal functionality or remove

**Step 1:** In `includes/bbcode.php`, keep only the `BBCode()` function (line 315+) and the minimal JS needed. Remove unused functions: `storeCaret`, `storeCaretNoValueInto`, `storeCaretValue`, `storeCaretIMG` (5 functions totaling ~250 lines).

**Step 2:** In `includes/display.php`, update `creerBBcode()` to show a minimal help reference instead of just "BBcode activé":
```php
function creerBBcode() {
    echo '<small>BBcode : [b]gras[/b] [i]italique[/i] [u]souligné[/u] [url]lien[/url] [img]image[/img]</small>';
}
```

**Step 3:** Run tests: `php vendor/bin/phpunit`

**Step 4:** Commit
```bash
git add includes/bbcode.php includes/display.php
git commit -m "cleanup: remove ~250 lines of unused BBcode toolbar JS, improve creerBBcode() help text"
```

---

## Batch Q: CI Pipeline + Monitoring

**Priority:** HIGH — foundation for sustainable development.

### Task Q-1: GitHub Actions CI pipeline

**Files:**
- Create: `.github/workflows/ci.yml`

**Step 1:** Create CI workflow:
```yaml
name: CI
on:
  push:
    branches: [main]
  pull_request:
    branches: [main]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mysqli, mbstring
          coverage: none
      - name: Install dependencies
        run: composer install --prefer-dist --no-progress
      - name: Run tests
        run: vendor/bin/phpunit --colors=always
```

**Step 2:** Commit and push:
```bash
mkdir -p .github/workflows
git add .github/workflows/ci.yml
git commit -m "ci: add GitHub Actions pipeline — runs PHPUnit on every push"
git push origin main
```

**Step 3:** Verify the workflow runs green on GitHub.

### Task Q-2: Health check endpoint

**Files:**
- Create: `health.php`

**Step 1:** Create a minimal health check that verifies DB connectivity:
```php
<?php
require_once 'includes/connexion.php';
header('Content-Type: application/json');
header('Cache-Control: no-cache');
$db_ok = false;
try {
    $result = mysqli_query($base, 'SELECT 1');
    $db_ok = ($result !== false);
} catch (Exception $e) {
    $db_ok = false;
}
echo json_encode([
    'status' => $db_ok ? 'ok' : 'error',
    'db' => $db_ok,
    'disk_free_gb' => round(disk_free_space('/') / 1073741824, 1),
    'php' => PHP_VERSION,
    'ts' => time()
]);
```

**Step 2:** Commit:
```bash
git add health.php
git commit -m "ops: add /health.php endpoint for uptime monitoring"
```

### Task Q-3: Log rotation cron + .gitkeep for logs directory

**Files:**
- Create: `cron/cleanup-logs.sh`
- Create: `logs/.gitkeep`

**Step 1:** Create log cleanup script:
```bash
#!/bin/bash
# Delete TVLW game logs older than 30 days
find /var/www/html/logs -name "*.log" -mtime +30 -delete
```

**Step 2:** Ensure logs directory exists and is gitignored properly:
```bash
mkdir -p logs
touch logs/.gitkeep
# .gitignore should have: logs/*.log
```

**Step 3:** Commit:
```bash
git add cron/cleanup-logs.sh logs/.gitkeep
git commit -m "ops: add log rotation script and logs directory"
```

---

## Batch R: File Structure Cleanup

**Priority:** MEDIUM — removes confusion, dead code, and legacy shims.

### Task R-1: Delete dead/duplicate files

**Files:**
- Delete: `includes/constantes.php` (16 lines — empty wrapper, just requires constantesBase.php)
- Delete: root `atomes.php` (12 lines — duplicate of includes/atomes.php, uses wrong field name `points` vs `totalPoints`)

**Step 1:** Find all files that include `constantes.php` and update them to include `constantesBase.php` directly:
```bash
grep -rl "constantes.php" --include="*.php" | grep -v "constantesBase" | grep -v vendor | grep -v docs
```
Update each include path.

**Step 2:** Find all files that include root `atomes.php` and update to use `includes/atomes.php`:
```bash
grep -rl "atomes.php" --include="*.php" | grep -v "includes/atomes" | grep -v vendor | grep -v docs
```

**Step 3:** Delete the dead files and run tests:
```bash
rm includes/constantes.php atomes.php
php vendor/bin/phpunit
```

**Step 4:** Commit:
```bash
git add -A
git commit -m "cleanup: delete dead constantes.php wrapper and duplicate root atomes.php"
```

### Task R-2: Remove old jQuery/JS files

**Step 1:** Identify which jQuery files are actually loaded. Search includes for script tags:
```bash
grep -r "jquery" --include="*.php" includes/ | grep -i "script\|src="
```

**Step 2:** If jQuery 1.7.2, jQuery UI 1.8.18, or other old versions are not referenced, delete them:
- `js/jquery-1.7.2.min.js` (if exists)
- `js/jquery-ui-1.8.18.custom.min.js` (if exists)
- `js/jcryption.js` (deprecated encryption library)
- `js/aes.js`, `js/sha.js`, `js/sha1.js`, `js/aes-json-format.js` (client-side crypto — if unused)

**Step 3:** Run tests and verify no pages break.

**Step 4:** Commit:
```bash
git add -A
git commit -m "cleanup: remove unused old jQuery 1.7.2, jQuery UI 1.8.18, and dead JS libraries"
```

### Task R-3: Rename tout.php → layout.php

**Files:**
- Rename: `includes/tout.php` → `includes/layout.php`

**Step 1:** Find all files that include tout.php:
```bash
grep -rl "tout.php" --include="*.php" | grep -v vendor | grep -v docs
```

**Step 2:** Rename and update all references:
```bash
mv includes/tout.php includes/layout.php
sed -i 's/tout\.php/layout.php/g' [each file from step 1]
```

**Step 3:** Run tests: `php vendor/bin/phpunit`

**Step 4:** Commit:
```bash
git add -A
git commit -m "refactor: rename tout.php → layout.php for clarity"
```

---

## Execution Order

1. **Batch Q** (CI + monitoring) — foundation, fast, highest value-per-minute
2. **Batch R** (file cleanup) — quick wins, removes confusion
3. **Batch J** (mysqli_fetch_array migration) — largest, most mechanical, highest code impact
4. **Batch K** (die/exit + stripslashes cleanup) — quick wins, improves error handling
5. **Batch L** (security: rate limiter + confirmation) — focused security fixes
6. **Batch M** (CSP: extract inline JS + add headers) — significant effort, big security payoff
7. **Batch N** (game balance V4.1) — independent, can be done in any order
8. **Batch O** (database constraints) — requires careful testing, do after J ensures helpers are clean
9. **Batch P** (UX polish) — lowest priority, best for last

**Total estimated tasks:** 23
**Total estimated commits:** ~23

---

## Items Deferred (Not In This Plan)

| Item | Reason |
|---|---|
| FK constraints on ALL 15+ tables | Too risky without full integration test suite. O-2 covers the 3 most critical. |
| HTTPS/SSL | Blocked on DNS (theverylittlewar.com → 212.227.38.111). Separate task. |
| VPS deployment | Requires Ionos panel access. Separate task. |
| Full BBcode toolbar restoration | Low value — current users know BBcode syntax. |
| Beginner protection extension (3→7 days) | Needs playtesting data first. |
| Formations rework for defenders | V4 already added 3 formations. Needs combat data. |
| Laravel/Symfony migration | Not justified at 80 files. Current procedural architecture is organized and tested. |
| React/Vue SPA frontend | Server-rendered pages + jQuery 3.7.1 is correct for a turn-based game. |
| Docker/Kubernetes | One app, one server. No benefit. |
| ELK/Grafana/Prometheus | Enterprise monitoring tools for clusters. UptimeRobot + Sentry free tier is sufficient. |
| Sentry integration | Good idea but requires account setup — recommend as manual follow-up after CI is live. |
| PHP 8.2 → 8.4 upgrade | 8.2 EOL Dec 2026. Plan upgrade in Q3 2026 via Sury PPA. Low risk for procedural code. |
| PHPStan static analysis | Add to CI after pipeline is stable. Start at level 1. |
| constantesBase.php elimination | Legacy shim translating config.php → old French variable names. Requires touching ~30 files. Low priority. |
