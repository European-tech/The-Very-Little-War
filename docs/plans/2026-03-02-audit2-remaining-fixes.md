# Audit #2 — Remaining Fixes (HIGH + MEDIUM + LOW)

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix all remaining deferred HIGH, MEDIUM, and LOW issues from the Audit #2 findings tracker, then run code review agents to verify everything.

**Architecture:** Fixes are grouped into 12 tasks by domain. Each task is self-contained and commits independently. Security fixes first, then game logic, then code quality, then cosmetic cleanup. Final task runs review agents.

**Tech Stack:** PHP 8.2, MariaDB 10.11, Apache 2, Framework7 Material CSS. Helpers: `dbFetchOne`, `dbExecute`, `dbQuery`, `withTransaction`, `csrfCheck`, `csrfField`, `rateLimitCheck`, `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`.

**Key Files:**
- `includes/session_init.php` — centralized session hardening
- `includes/basicprivatephp.php` — private page auth guard (validates session_token against DB)
- `includes/basicpublicphp.php` — public page login flow
- `includes/config.php` — game constants
- `includes/formulas.php` — combat/resource formulas (has DB queries that shouldn't be there)
- `includes/database.php` — `withTransaction()`, `dbFetchOne()`, etc.
- `includes/csrf.php` — `csrfCheck()`, `csrfField()`

**Test command:** `php vendor/bin/phpunit --no-configuration tests/unit/CsrfTest.php tests/unit/ValidationTest.php tests/unit/SecurityFunctionsTest.php tests/unit/ConfigConsistencyTest.php`

**PHP syntax check:** `php -l <file>`

**VPS deploy:** `ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'cd /var/www/html && git pull origin main'`

---

## Task 1: HIGH — XSS Escaping (attaquer.php, marche.php, voter.php)

**Files:**
- Modify: `attaquer.php:42` (espionage message), `attaquer.php:358` (map player names)
- Modify: `marche.php:335-338` (send/receive player names in action table)
- Modify: `voter.php:36,41` (escape poll response on output, not input)

**Step 1: Fix attaquer.php espionage XSS (line 42)**

Find:
```php
$information = 'Vous avez lancé l\'espionnage de ' . $_POST['joueurAEspionner'] . ' !';
```
Replace with:
```php
$information = 'Vous avez lancé l\'espionnage de ' . htmlspecialchars($_POST['joueurAEspionner'], ENT_QUOTES, 'UTF-8') . ' !';
```

**Step 2: Fix attaquer.php map XSS (line 358)**

The map renders `$carte[$i][$j][1]` (player login) unescaped in both href and display text. Find the map rendering loop (around line 358) and add escaping. Before the `echo` that renders each map tile with a player name, add:
```php
$safeLogin = htmlspecialchars($carte[$i][$j][1], ENT_QUOTES, 'UTF-8');
```
Then use `$safeLogin` in place of `$carte[$i][$j][1]` for both the href and display span.

**Step 3: Fix marche.php action table XSS (lines 335-338)**

Find the section that displays send/receive actions. Each row outputs `$actionsenvoi['receveur']` and `$actionsenvoi['envoyeur']` directly in HTML. Escape both with `htmlspecialchars()` before output.

**Step 4: Fix voter.php — response is a radio button value (1,2,3,4), not free text**

Read voter.php more carefully. The `$reponse` is from a radio button (`$_POST['reponse']`), typically a small integer. But stored and displayed without validation. Add `intval()` cast:
```php
$reponse = intval($_POST['reponse'] ?? $_GET['reponse'] ?? 0);
if ($reponse < 1) { exit(json_encode(["erreur" => true])); }
```

**Step 5: Run syntax check**
```bash
php -l attaquer.php && php -l marche.php && php -l voter.php
```

**Step 6: Commit**
```bash
git add attaquer.php marche.php voter.php
git commit -m "security: XSS escaping on attaquer.php map+espionage, marche.php actions, voter.php input"
```

---

## Task 2: HIGH — Login CSRF + Session Token Validation (api.php, voter.php, basicpublicphp.php)

**Files:**
- Modify: `includes/basicpublicphp.php:20` (add CSRF check to login POST)
- Modify: `includes/basicpublichtml.php` (add CSRF field to login form)
- Modify: `api.php:16` (add session token DB validation)
- Modify: `voter.php:2` (use session_init.php + add session token validation)

**Step 1: Add CSRF check to login form processing**

In `includes/basicpublicphp.php`, after line 20 (`if (isset($_POST['loginConnexion'])...`), add:
```php
csrfCheck();
```
NOTE: `basicpublicphp.php` already includes `fonctions.php` which loads `csrf.php`. The CSRF token is generated in the session on page load.

**Step 2: Add CSRF field to login form HTML**

Read `includes/basicpublichtml.php` and find the login form. Add `<?php echo csrfField(); ?>` inside the form.

**Step 3: Add session token validation to api.php**

After the `if (empty($_SESSION['login']))` check (line 16-20), add DB session token validation:
```php
include("includes/connexion.php"); // move this BEFORE the check
$row = dbFetchOne($base, 'SELECT session_token FROM membre WHERE login = ?', 's', $_SESSION['login']);
if (!$row || !isset($_SESSION['session_token']) || !$row['session_token'] || !hash_equals($row['session_token'], $_SESSION['session_token'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid session']);
    exit;
}
```
NOTE: This requires `includes/connexion.php` to be loaded before the check. Currently it's loaded at line 32, so move it up.

**Step 4: Fix voter.php session handling**

Replace bare `session_start()` with `require_once("includes/session_init.php")`. Add session token validation after login check:
```php
require_once("includes/session_init.php");
include("includes/connexion.php");
require_once("includes/database.php");
require_once("includes/csrf.php");

if (!isset($_SESSION['login'])) {
    exit(json_encode(["erreur" => true]));
}

// Validate session token against DB
$row = dbFetchOne($base, 'SELECT session_token FROM membre WHERE login = ?', 's', $_SESSION['login']);
if (!$row || !isset($_SESSION['session_token']) || !$row['session_token'] || !hash_equals($row['session_token'], $_SESSION['session_token'])) {
    session_destroy();
    exit(json_encode(["erreur" => true]));
}
```

**Step 5: Run syntax check**
```bash
php -l includes/basicpublicphp.php && php -l api.php && php -l voter.php
```

**Step 6: Commit**
```bash
git add includes/basicpublicphp.php includes/basicpublichtml.php api.php voter.php
git commit -m "security: login CSRF protection + session token validation on api.php and voter.php"
```

---

## Task 3: HIGH — Session Idle Timeout + Timing-Safe MD5

**Files:**
- Modify: `includes/session_init.php` (add gc_maxlifetime)
- Modify: `includes/basicprivatephp.php` (add idle timeout check)
- Modify: `includes/basicpublicphp.php:47` (hash_equals for MD5 comparison)

**Step 1: Add gc_maxlifetime to session_init.php**

Add before `session_start()`:
```php
ini_set('session.gc_maxlifetime', 3600); // 1 hour
```

**Step 2: Add idle timeout to basicprivatephp.php**

After the session token validation block (around line 23), add:
```php
// Idle session timeout (1 hour)
$idle_timeout = 3600;
if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > $idle_timeout) {
    session_destroy();
    header('Location: index.php');
    exit();
}
$_SESSION['last_activity'] = time();
```

**Step 3: Fix timing-unsafe MD5 comparison**

In `includes/basicpublicphp.php` line 47, change:
```php
elseif (md5($passwordInput) === $storedHash) {
```
to:
```php
elseif (hash_equals(md5($passwordInput), $storedHash)) {
```

**Step 4: Run syntax check + tests**
```bash
php -l includes/session_init.php && php -l includes/basicprivatephp.php && php -l includes/basicpublicphp.php
```

**Step 5: Commit**
```bash
git add includes/session_init.php includes/basicprivatephp.php includes/basicpublicphp.php
git commit -m "security: 1h idle session timeout + timing-safe MD5 legacy comparison"
```

---

## Task 4: HIGH — Pagination Validation Fix (8 locations)

**Files:**
- Modify: `messages.php:45`
- Modify: `rapports.php:46`
- Modify: `sujet.php:47`
- Modify: `listesujets.php:65`
- Modify: `classement.php:104,273,432,515`

**Step 1: Fix all 8 pagination instances**

In each file, replace this pattern:
```php
if (isset($_GET['page']) AND $_GET['page'] <= $nombreDePages AND $_GET['page'] > 0 AND preg_match("#\d#",$_GET['page']))
{
    $page = $_GET['page'];
```
With:
```php
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($page >= 1 && $page <= $nombreDePages)
{
```
And remove the `else { $page = 1; }` block (or `$page = $nombreDePages` where applicable — check each instance to preserve existing default behavior).

NOTE: classement.php has 4 pagination blocks. Each must be fixed individually. Check each block's default page value.

**Step 2: Run syntax check**
```bash
php -l messages.php && php -l rapports.php && php -l sujet.php && php -l listesujets.php && php -l classement.php
```

**Step 3: Commit**
```bash
git add messages.php rapports.php sujet.php listesujets.php classement.php
git commit -m "fix: strict pagination validation — intval cast replaces weak regex across 8 locations"
```

---

## Task 5: HIGH — Market Buy Race Condition + Energy Negativity Guard

**Files:**
- Modify: `marche.php` (buy section ~line 145-236 — move validation inside transaction + add FOR UPDATE)
- Modify: `marche.php` (energy deduction — add SQL-level guard)

**Step 1: Read marche.php buy section and send section fully**

Read `marche.php` lines 145-315 to understand the buy and sell flows. Identify exactly where the transaction starts and where the energy check happens.

**Step 2: Move energy validation inside withTransaction for buying**

The buy section already has `withTransaction()`. Move the energy check INSIDE the transaction with `SELECT ... FOR UPDATE`:
```php
withTransaction($base, function() use ($base, ...) {
    $current = dbFetchOne($base, 'SELECT energie FROM ressources WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
    if ($current['energie'] < $coutAchat) {
        throw new \RuntimeException("NOT_ENOUGH_ENERGY");
    }
    // ... rest of deductions
});
```

**Step 3: Add SQL-level non-negative guard to all energy deductions**

Where energy is deducted, use conditional UPDATE:
```sql
UPDATE ressources SET energie = GREATEST(0, energie - ?) WHERE login = ?
```
Or better, add a check constraint (if MariaDB version supports it — 10.11 does):
```sql
-- Run this migration on VPS:
ALTER TABLE ressources ADD CONSTRAINT chk_energy_nonneg CHECK (energie >= 0);
```

**Step 4: Run syntax check**
```bash
php -l marche.php
```

**Step 5: Commit**
```bash
git add marche.php
git commit -m "fix: market buy race condition — energy check inside transaction + FOR UPDATE"
```

---

## Task 6: MEDIUM — Game Logic Fixes

**Files:**
- Modify: `includes/combat.php` (~line 205) — apply defender isotope attack mod
- Modify: `includes/update.php` (~line 45-56) — track decay stats in `autre.moleculesPerdues`
- Modify: `marche.php` (sell section ~line 253-315) — award trade points on selling
- Modify: `includes/config.php` (lines 27-28) — remove or implement NEW_PLAYER_BOOST
- Modify: `moderationForum.php:35` — add date validation

**Step 1: Read combat.php to find exact isotope attack mod location**

Read `includes/combat.php` fully. Find where `$defIsotopeAttackMod` is defined and where defender damage is calculated. Apply the missing modifier to defender damage output.

**Step 2: Fix defender isotope attack mod**

Find where defender deals counter-damage and multiply by `$defIsotopeAttackMod[$i]`. This should be symmetrical with attacker isotope handling.

**Step 3: Fix decay stat tracking in update.php**

After the molecule decay UPDATE (around line 50), add:
```php
$decayedAmount = $molecules['nombre'] - $moleculesRestantes;
if ($decayedAmount > 0) {
    dbExecute($base, 'UPDATE autre SET moleculesPerdues = moleculesPerdues + ? WHERE login=?', 'ds', $decayedAmount, $targetPlayer);
}
```

**Step 4: Award trade points on selling in marche.php**

Read the sell section. After the sell transaction succeeds, add trade point logic mirroring the buy section's trade point code. Use the same formula with `allianceResearchBonus` and `MARKET_POINTS_SCALE`/`MARKET_POINTS_MAX`.

**Step 5: Remove NEW_PLAYER_BOOST dead code from config.php**

Delete lines 27-28 (the two unused constants). They were never implemented and create false expectations.

**Step 6: Add date validation to moderationForum.php**

After `explode('/', $_POST['dateFin'])`, validate with `checkdate()`:
```php
$parts = explode('/', $_POST['dateFin']);
if (count($parts) !== 3 || !checkdate((int)$parts[1], (int)$parts[0], (int)$parts[2])) {
    $erreur = "<strong>Erreur</strong> : Date invalide.";
} else {
    list($jour, $mois, $annee) = $parts;
    $date = $annee . '-' . $mois . '-' . $jour;
    // ... proceed with INSERT
}
```

**Step 7: Run syntax check on all modified files**
```bash
php -l includes/combat.php && php -l includes/update.php && php -l marche.php && php -l includes/config.php && php -l moderationForum.php
```

**Step 8: Commit**
```bash
git add includes/combat.php includes/update.php marche.php includes/config.php moderationForum.php
git commit -m "fix: defender isotope mod, decay tracking, sell trade points, date validation"
```

---

## Task 7: MEDIUM — Race Conditions + classement.php Cleanup

**Files:**
- Modify: `armee.php` (~line 107-119) — fix formation queue race condition with FOR UPDATE
- Modify: `classement.php` (~line 390-393) — stop deleting alliances during display
- Modify: `moderation/ip.php` — use session_init.php instead of bare session_start()

**Step 1: Fix armee.php formation race condition**

Read `armee.php` around lines 107-119 where the formation queue is read and extended. Wrap in transaction with FOR UPDATE:
```php
withTransaction($base, function() use ($base, ...) {
    $ex = dbQuery($base, 'SELECT MAX(fin) AS nextStart FROM actionsformation WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
    $row = mysqli_fetch_array($ex);
    $tempsDebut = ($row && $row['nextStart']) ? (int)$row['nextStart'] : time();
    // ... calculate finTemps, INSERT
});
```

**Step 2: Stop classement.php from deleting alliances during display**

Replace the delete block (lines 390-393):
```php
else {
    dbExecute($base, 'DELETE FROM alliances WHERE id=?', 'i', $donnees['id']);
    dbExecute($base, 'DELETE FROM invitations WHERE idalliance=?', 'i', $donnees['id']);
}
```
With a simple skip:
```php
else {
    continue; // Skip empty alliances in display
}
```
NOTE: Empty alliance cleanup should be done by the admin panel or a scheduled maintenance task, not during page rendering.

**Step 3: Fix moderation/ip.php session handling**

In `moderation/mdp.php`, replace bare `session_start()` with:
```php
require_once(__DIR__ . '/../includes/session_init.php');
```

**Step 4: Run syntax check**
```bash
php -l armee.php && php -l classement.php && php -l moderation/mdp.php
```

**Step 5: Commit**
```bash
git add armee.php classement.php moderation/mdp.php
git commit -m "fix: formation race condition, remove alliance deletion from display, session_init in moderation"
```

---

## Task 8: MEDIUM — Code Quality (variable variables, formulas DB queries)

**Files:**
- Modify: `armee.php` (~line 177-207) — replace variable variables with array
- Modify: `includes/formulas.php` (~line 79-227) — extract medal bonus calculation out of formula functions

**Step 1: Fix armee.php variable variables**

Replace the `$$ressource` pattern with an `$atomCounts` array:
```php
$atomCounts = [];
foreach ($nomsRes as $num => $ressource) {
    if (!empty($_POST[$ressource])) {
        $atomCounts[$ressource] = intval($_POST[$ressource]);
        $formule = $formule . $lettre[$num] . '<sub>' . $atomCounts[$ressource] . '</sub>';
    } else {
        $atomCounts[$ressource] = 0;
    }
}
```
Then update all later references from `$$ressource` to `$atomCounts[$ressource]`.

**Step 2: Refactor formulas.php — extract medal bonus helper**

Add a pure helper function at the top of formulas.php:
```php
function getMedalBonus($points, $paliers, $bonusMedailles) {
    $bonus = 0;
    foreach ($paliers as $num => $palier) {
        if ($points >= $palier) {
            $bonus = $bonusMedailles[$num];
        }
    }
    return $bonus;
}
```

Then update `attaque()`, `defense()`, `pillage()` to accept an optional `$medalBonus` parameter. Keep backward compatibility by defaulting to DB lookup if not provided:
```php
function attaque($oxygene, $niveau, $joueur, $medalBonus = null) {
    global $paliersAttaque, $bonusMedailles, $base;
    if ($medalBonus === null) {
        $donneesMedaille = dbFetchOne($base, 'SELECT pointsAttaque FROM autre WHERE login=?', 's', $joueur);
        $medalBonus = getMedalBonus($donneesMedaille['pointsAttaque'], $paliersAttaque, $bonusMedailles);
    }
    return round((1 + (ATTACK_ATOM_COEFFICIENT * $oxygene) * (ATTACK_ATOM_COEFFICIENT * $oxygene) + $oxygene) * (1 + $niveau / ATTACK_LEVEL_DIVISOR) * (1 + $medalBonus / 100));
}
```

Then in `includes/combat.php`, load medal data ONCE before the combat loop:
```php
$attMedals = dbFetchOne($base, 'SELECT pointsAttaque, pointsDefense, ressourcesPillees FROM autre WHERE login=?', 's', $attacker);
$defMedals = dbFetchOne($base, 'SELECT pointsAttaque, pointsDefense, ressourcesPillees FROM autre WHERE login=?', 's', $defender);
$attAttackBonus = getMedalBonus($attMedals['pointsAttaque'], $paliersAttaque, $bonusMedailles);
// ... etc, then pass to formula calls
```

**Step 3: Run syntax check**
```bash
php -l armee.php && php -l includes/formulas.php && php -l includes/combat.php
```

**Step 4: Run tests**
```bash
php vendor/bin/phpunit --no-configuration tests/unit/CsrfTest.php tests/unit/ValidationTest.php tests/unit/SecurityFunctionsTest.php tests/unit/ConfigConsistencyTest.php
```

**Step 5: Commit**
```bash
git add armee.php includes/formulas.php includes/combat.php
git commit -m "refactor: replace variable variables in armee.php, extract medal bonus from formulas"
```

---

## Task 9: MEDIUM — Database Index Migration

**Files:**
- Create: `migrations/add_actionsattaques_indexes.sql`

**Step 1: Create migration SQL file**

```sql
-- Migration: Add missing indexes to actionsattaques table
-- Date: 2026-03-02
-- Issue: DB-007

-- Composite index for attack lookups (covers most WHERE clauses)
ALTER TABLE actionsattaques ADD INDEX idx_attaquant (attaquant(50));
ALTER TABLE actionsattaques ADD INDEX idx_defenseur (defenseur(50));

-- Verify
SHOW INDEX FROM actionsattaques;
```

**Step 2: Run on VPS**
```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'mysql -u tvlw -pmLLIoTy2ByGTBNb9RQpTGnqPhXQfUR tvlw < /var/www/html/migrations/add_actionsattaques_indexes.sql'
```

**Step 3: Commit**
```bash
git add migrations/add_actionsattaques_indexes.sql
git commit -m "perf: add indexes to actionsattaques for attack/defense lookups"
```

---

## Task 10: LOW — HTML5 Modernization + Cleanup

**Files (batch edit across ~15 files):**
- All `admin/*.php` files: DOCTYPE → HTML5, remove IE shims, fix charset meta
- `moderation/index.php`, `moderation/ip.php`: same
- `deconnexion.php`, `annonce.php`, `validerpacte.php`: DOCTYPE → HTML5
- `includes/partenariat.php`: HTTP → HTTPS URLs
- Remove `type="text/javascript"` and `type="text/css"` across all files
- Remove `console.log` from `js/PushNotification.js`
- Fix `admin/listesujets.php` iso-8859-1 → UTF-8

**Step 1: Batch update all DOCTYPE declarations**

For each file with XHTML DOCTYPE, replace:
```html
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="fr" >
```
With:
```html
<!DOCTYPE html>
<html lang="fr">
```

**Step 2: Remove IE conditional comments**

Delete all `<!--[if IE]>...<![endif]-->` blocks.

**Step 3: Fix charset meta tags**

Replace `<meta http-equiv="content-type" content="text/html; charset=utf-8" />` with `<meta charset="utf-8">`.

Fix `admin/listesujets.php`: change `charset=iso-8859-1` to `charset=utf-8`.

**Step 4: Remove deprecated type attributes**

Remove `type="text/javascript"` from `<script>` tags and `type="text/css"` from `<style>` and `<link>` tags across all files.

**Step 5: Fix partenariat.php HTTP URLs**

Change `http://www.theverylittlewar.com` to `https://www.theverylittlewar.com` (3 occurrences).

**Step 6: Remove console.log from PushNotification.js**

Remove all 6 `console.log()` statements from `js/PushNotification.js`.

**Step 7: Run syntax check on all modified PHP files**
```bash
for f in admin/*.php moderation/*.php deconnexion.php annonce.php includes/partenariat.php; do php -l "$f"; done
```

**Step 8: Commit**
```bash
git add admin/ moderation/ deconnexion.php annonce.php validerpacte.php includes/partenariat.php js/PushNotification.js
git commit -m "cleanup: HTML5 modernization, remove IE shims, fix charsets, remove console.log"
```

---

## Task 11: Update Findings Tracker + Push + Deploy

**Files:**
- Modify: `docs/audit-2-findings-tracker.md`

**Step 1: Update the findings tracker**

Mark all newly-fixed issues as FIXED. Update the summary counts.

**Step 2: Commit the tracker**
```bash
git add docs/audit-2-findings-tracker.md
git commit -m "docs: update audit-2 findings tracker — all HIGH/MEDIUM/LOW fixes complete"
```

**Step 3: Push to GitHub**
```bash
git push origin main
```

**Step 4: Deploy to VPS**
```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'cd /var/www/html && git pull origin main'
```

**Step 5: Smoke test all pages on VPS**
```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'for page in index.php deconnexion.php comptetest.php don.php guerre.php historique.php moderationForum.php marche.php alliance.php forum.php joueur.php classement.php sinstruire.php attaquer.php armee.php voter.php api.php messages.php rapports.php sujet.php listesujets.php; do status=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost/$page"); echo "$page: $status"; done'
```

**Step 6: Check for PHP errors**
```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'tail -20 /var/log/apache2/error.log | grep -i "php"'
```

---

## Task 12: Code Review Agents

**Step 1: Launch 2 code review agents in parallel**

Use these agent types:
1. `feature-dev:code-reviewer` — reviews all modified files for bugs, logic errors, security
2. `voltagent-qa-sec:code-reviewer` — comprehensive code review focusing on security

Each agent should review ALL files modified in Tasks 1-10 against the audit tracker.

**Step 2: Launch 1 security-specific agent**

Use `comprehensive-review:security-auditor` to audit the final state of all auth/session/XSS/CSRF fixes.

**Step 3: Address any findings from review agents**

If agents identify new issues at HIGH confidence (>85%), fix them. If MEDIUM confidence, document in the tracker as DEFERRED. If LOW confidence, ignore.

**Step 4: Final commit if needed**
```bash
git add -A
git commit -m "fix: address code review findings"
git push origin main
```

**Step 5: Final VPS deploy + smoke test**
```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'cd /var/www/html && git pull origin main'
```
