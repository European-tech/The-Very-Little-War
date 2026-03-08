# Pass 3 Remediation Plan

> **For Claude:** Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix all CRITICAL and HIGH bugs found by Pass 3 audit (12 domain agents).

**Architecture:** 9 parallel fix batches, grouped by file ownership with zero cross-batch conflicts.

**Context:** All fixes go directly on `main` branch. After all batches: run tests, push to GitHub, deploy to VPS, then execute live browser testing plan.

**Test command:** `php vendor/bin/phpunit --no-coverage 2>&1 | tail -5`

**Deploy:** `ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "cd /var/www/html && git pull origin main"`

---

## CRITICAL BUGS OVERVIEW

| ID | Severity | Domain | File | Issue |
|----|----------|--------|------|-------|
| C1 | CRITICAL | Combat | game_actions.php | Attacker casualties always 0 (undefined vars) |
| C2 | CRITICAL | Combat | game_actions.php | Pillage always "Aucune" (variable-variable mismatch) |
| C3 | HIGH | Combat | combat.php | hpPerMol truncation inconsistency |
| C4 | HIGH | Combat | combat.php | Defender compound snapshot outside transaction |
| C5 | HIGH | Combat | combat.php | Dispersée overkill redistribution wrong denominator |
| C6 | HIGH | CSP | .htaccess + copyright.php | Dual conflicting security headers; Google Charts eval blocks charts |
| C7 | HIGH | Prestige | classement.php + prestige.php | VP wrong on page 2+; streak_pp_today never set |
| C8 | HIGH | Tutorial | basicprivatephp.php + tutoriel.php | No transaction on mission grant; off-by-one offset |
| C9 | HIGH | SQL | player.php | augmenterBatiment/diminuerBatiment missing transactions |
| C10 | HIGH | XSS | ui_components.php + index.php | carteForum CSS injection; javascript: href |
| C11 | HIGH | Alliance | alliance.php + forum.php | Private forum readable by non-members; stale grade check |
| C12 | HIGH | Admin | moderation/index.php | Atom grants uncapped (only energy capped) |
| C13 | MEDIUM | Economy | marche.php + don.php | Sell energy discarded silently; buy stale price |
| C14 | HIGH | Migrations | 0033*.sql + 0007*.sql | Invalid FK syntax; missing ENGINE/CHARSET |

---

## Batch A — Critical Combat Report Fixes

**Files:** `includes/game_actions.php` only

**Context:**
- `combat.php` stores casualties in arrays: `$attaquantMort[1..4]` and `$defenseurMort[1..4]`
- `combat.php` stores pillage in array: `$ressourcePille['carbone']`, `$ressourcePille['azote']`, etc.
- `game_actions.php` tries to use flat variables `$classe1AttaquantMort`, `$classe2AttaquantMort` etc. → UNDEFINED, always 0
- `game_actions.php` pillage display uses `${$ressource . 'Pille'}` variable-variables → never set, always "Aucune"

### Step 1: Find the `include("includes/combat.php")` line

In `game_actions.php` around line 141:
```php
include("includes/combat.php");
```

### Step 2: After that include, add flat-variable mapping

Insert immediately after `include("includes/combat.php");`:
```php
// Map array casualties to flat vars expected by report template
$classe1AttaquantMort = $attaquantMort[1] ?? 0;
$classe2AttaquantMort = $attaquantMort[2] ?? 0;
$classe3AttaquantMort = $attaquantMort[3] ?? 0;
$classe4AttaquantMort = $attaquantMort[4] ?? 0;
$classe1DefenseurMort = $defenseurMort[1] ?? 0;
$classe2DefenseurMort = $defenseurMort[2] ?? 0;
$classe3DefenseurMort = $defenseurMort[3] ?? 0;
$classe4DefenseurMort = $defenseurMort[4] ?? 0;
```

### Step 3: Fix pillage report (around line 160-168)

Replace the variable-variable pillage block:
```php
$chaine = "Aucune";
foreach ($nomsRes as $num => $ressource) {
    if (${$ressource . 'Pille'} > 0) {
        if ($chaine == "Aucune") {
            $chaine = nombreAtome($num, ${$ressource . 'Pille'});
        } else {
            $chaine = $chaine . nombreAtome($num, ${$ressource . 'Pille'});
        }
    }
}
```

With:
```php
$chaine = "Aucune";
foreach ($nomsRes as $num => $ressource) {
    $pilleAmount = $ressourcePille[$ressource] ?? 0;
    if ($pilleAmount > 0) {
        if ($chaine == "Aucune") {
            $chaine = nombreAtome($num, $pilleAmount);
        } else {
            $chaine = $chaine . nombreAtome($num, $pilleAmount);
        }
    }
}
```

### Step 4: Run tests
```bash
cd /home/guortates/TVLW/The-Very-Little-War
php vendor/bin/phpunit --no-coverage 2>&1 | tail -5
```
Expected: 0 failures (38 integration errors OK).

### Step 5: Commit
```bash
git add includes/game_actions.php
git commit -m "fix(combat): map array casualties and pillage to flat report vars

$classeXAttaquantMort/$classeXDefenseurMort were undefined (combat.php
uses $attaquantMort[]/defenseurMort[] arrays). Pillage always showed
Aucune because game_actions used variable-variable but combat.php
uses $ressourcePille[] array (LOW-015 fix). Both now correctly mapped.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Batch B — Combat Physics Fixes

**Files:** `includes/combat.php` only

### B1: Fix hpPerMol truncation (C3)

Around line 208: `intdiv((int)$remainingDamage, (int)$hpPerMol)` — truncation causes kills * hpPerMol != actual damage deducted.

The issue: `$hpPerMol` is float. `(int)$hpPerMol` truncates it. Then `$remainingDamage -= $kills * $hpPerMol` uses the original float. Inconsistency.

**Fix:** Keep `$hpPerMol` as float throughout. Use `(int)` only for the `intdiv` but compute `$remainingDamage` subtraction consistently:
```php
// Before (buggy): $kills = min($classeAttaquant[$i]['nombre'], intdiv((int)$remainingDamage, (int)$hpPerMol));
// After:
$kills = ($hpPerMol > 0) ? min($classeAttaquant[$i]['nombre'], (int)floor($remainingDamage / $hpPerMol)) : $classeAttaquant[$i]['nombre'];
$remainingDamage -= $kills * $hpPerMol;
```
Apply same pattern to all similar `intdiv((int)$remainingDamage, (int)$hpPerMol)` calls for defender casualties (same pattern in lines ~232, ~253, ~287, ~313).

### B2: Fix Dispersée redistribution denominator (C5)

Find the Dispersée formation block. The audit found: "overkill redistribution denominator miscounts remaining classes."

Search for `FORMATION_DISPERSEE` or `Dispersée` in combat.php. When counting remaining active classes for damage redistribution, ensure the count decrements per class exhausted (not per initial active class count).

The fix: when redistributing `$remainingDamage` after a class is wiped, count remaining classes that still have `$classeDefenseur[$i]['nombre'] - $defenseurMort[$i] > 0`, not the initial `$remainingActiveClasses` count.

### B3: Fix defender compound snapshot race (C4)

Find where `getCompoundBonus($base, $actions['defenseur'], ...)` is called. This should be inside the `mysqli_begin_transaction($base)` block (after line 108), not before it. Read the actual line numbers and move the compound fetch inside the transaction.

### Step 4: Run tests
```bash
php vendor/bin/phpunit --no-coverage 2>&1 | tail -5
```

### Step 5: Commit
```bash
git add includes/combat.php
git commit -m "fix(combat): hpPerMol truncation, Dispersée redistribution, compound race

- Use floor() not intdiv((int)) for consistent HP kill counting
- Fix Dispersée overkill redistribution to count remaining live classes
- Move defender compound bonus fetch inside transaction to prevent race

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Batch C — Headers / CSP Fixes

**Files:** `.htaccess`, `includes/copyright.php`, `messages.php`, `migrations/.htaccess`

### C1: Remove duplicate headers from .htaccess

In `.htaccess`, find and remove:
- Line with `X-Frame-Options: SAMEORIGIN` — conflicts with `layout.php`'s `DENY`
- Line with `X-XSS-Protection: 1; mode=block` — conflicts with `layout.php`'s `0`

Keep these headers ONLY in `includes/layout.php` (which already sets them correctly).

### C2: Fix Google Charts loader.js loaded sitewide (eval() breaks CSP)

In `includes/copyright.php`, remove the `loader.js` script tag (it uses `eval()` internally, blocked by CSP, breaking all charts sitewide even on pages with no charts).

Add it instead ONLY in `marche.php`, just before the closing `</body>` or in the head section where the chart is used. Find the existing chart code in `marche.php` and add:
```html
<script src="js/loader.js" nonce="<?= cspNonce() ?>"></script>
```
Note: This still won't work if CSP blocks eval(). Best fix is to also add `'unsafe-eval'` to the `script-src` directive in `includes/layout.php` ONLY for marche.php by checking `$_SERVER['SCRIPT_NAME']` — OR simpler: replace Google Charts with Chart.js which is already referenced. For now, just move loader.js loading to marche.php to stop breaking ALL other pages. Add a TODO comment about the eval issue.

### C3: Fix messages.php inline onclick bypassing CSP

In `messages.php` around line 89, find a button with `onclick="return confirm('...')"`.

Replace with `data-confirm="..."` attribute pattern:
```php
// Before:
echo '<button onclick="return confirm(\'Êtes-vous sûr ?\')">';
// After:
echo '<button data-confirm="Êtes-vous sûr ?">';
```
The `data-confirm` delegated handler in `includes/copyright.php` already handles this pattern.

### C4: Fix migrations/.htaccess Apache 2.4 syntax

`migrations/.htaccess` contains only `Deny from all` (Apache 2.2 syntax, ignored on Apache 2.4 without mod_authz_compat).

Replace with:
```apache
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Deny from all
</IfModule>
```

### Step 5: Run tests and commit
```bash
php vendor/bin/phpunit --no-coverage 2>&1 | tail -5
git add .htaccess includes/copyright.php messages.php migrations/.htaccess
git commit -m "fix(csp): remove duplicate security headers, relocate loader.js, fix onclick

- Remove X-Frame-Options/X-XSS-Protection from .htaccess (layout.php owns these)
- Move loader.js to marche.php only (sitewide loading broke CSP due to eval())
- Replace messages.php onclick= with data-confirm= (CSP-safe pattern)
- Fix migrations/.htaccess to Apache 2.4 syntax (Require all denied)

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Batch D — Rankings / Prestige / Tutorial Fixes

**Files:** `classement.php`, `includes/prestige.php`, `includes/basicprivatephp.php`, `tutoriel.php`

### D1: Fix VP rank wrong on classement.php page 2+

In `classement.php` around line 277, find where `$compteur` is used as the rank display. The VP column shows `$compteur` (a loop counter) instead of the DENSE_RANK computed by SQL.

Read the SQL query for the leaderboard. If it uses `DENSE_RANK() OVER (ORDER BY totalPoints DESC) AS rang`, use `$row['rang']` instead of `$compteur` for display.

If there is no SQL DENSE_RANK, add it to the query or compute it in PHP using `$previousScore` tracking:
```php
$rang = 1;
$previousScore = null;
foreach ($rows as $i => $row) {
    if ($row['totalPoints'] !== $previousScore) {
        $rang = $i + 1;
        $previousScore = $row['totalPoints'];
    }
    // Use $rang for display, not $i+1
}
```

### D2: Fix streak_pp_today session variable never set

In `includes/basicprivatephp.php`, find where daily streak PP is computed (search for `streak_days`, `STREAK_MILESTONES`, `dailyStreakPP`).

After the streak PP is computed, ensure it's stored in session:
```php
$_SESSION['streak_pp_today'] = $streakPP ?? 0;
```

Check `includes/prestige.php` where `$_SESSION['streak_pp_today']` is read and displayed. If it's reading from session, the session must be set in basicprivatephp.php on login or each page load.

### D3: Fix login bot PP farming (non-milestone daily PP uncapped)

In `includes/player.php` or `includes/basicprivatephp.php`, find where daily non-milestone PP is awarded (e.g., 1 PP per login on non-milestone days).

Add a guard: only award daily non-milestone PP once per calendar day by checking `streak_last_date`. If `streak_last_date` is today, skip the non-milestone daily award. The streak system already tracks `streak_last_date` — ensure the PP award check is gated on it.

### D4: Fix System B mission grant — add transaction

In `includes/basicprivatephp.php` around lines 241-280, find the System B mission completion block. Wrap it in `withTransaction()`:
```php
withTransaction($base, function() use ($base, $login, $claim) {
    // Lock player row
    $current = dbFetchOne($base, 'SELECT tuto_claims FROM autre WHERE login=? FOR UPDATE', 's', $login);
    // Check not already granted
    if (!($current['tuto_claims'] & $bit)) {
        // award mission
        dbExecute($base, 'UPDATE autre SET tuto_claims = tuto_claims | ? WHERE login=?', 'is', $bit, $login);
    }
});
```

### D5: Fix System B off-by-one ($tutoOffset = 19 vs 20 missions)

In `tutoriel.php` around line 179/277, find `$tutoOffset = 19`. If `listeMissions` has 20 entries (indices 0-19), then `$tutoOffset` must be `count($listeMissions)` or explicitly match the actual count. Change to the correct value (verify by counting `$listeMissions` entries).

### D6: Fix tutoriel.php claim transaction — re-verify objective from DB

In `tutoriel.php` around lines 194, 198-244, the claim transaction must re-verify the objective condition inside the transaction (to prevent double-claiming via race conditions).

Inside each claim's `withTransaction()`, add a DB re-check before awarding:
```php
withTransaction($base, function() use ($base, $login, $missionId) {
    $player = dbFetchOne($base, 'SELECT tuto_claims FROM autre WHERE login=? FOR UPDATE', 's', $login);
    $bit = (1 << $missionId);
    if ($player['tuto_claims'] & $bit) return; // already claimed
    // re-verify objective met (e.g. SELECT buildings/map position)
    // ... objective check ...
    dbExecute($base, 'UPDATE autre SET tuto_claims = tuto_claims | ? WHERE login=?', 'is', $bit, $login);
});
```

### Step 7: Run tests and commit
```bash
php vendor/bin/phpunit --no-coverage 2>&1 | tail -5
git add classement.php includes/prestige.php includes/basicprivatephp.php tutoriel.php
git commit -m "fix(prestige+tutorial): VP rank display, streak session, mission transactions

- classement.php: use DENSE_RANK not loop counter for VP column page 2+
- basicprivatephp.php: set streak_pp_today in session (was never set)
- basicprivatephp.php: gate daily non-milestone PP on streak_last_date
- basicprivatephp.php: wrap System B mission grant in withTransaction + FOR UPDATE
- tutoriel.php: fix tutoOffset off-by-one; add DB re-verify in claim transactions

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Batch E — Player.php Transaction Fixes

**Files:** `includes/player.php` only

### E1: Fix diminuerBatiment — add transaction

Find `function diminuerBatiment(` in `player.php` (around line 637-714). It has NO transaction at all. All reads and writes are non-atomic.

Wrap the entire function body in `withTransaction()` with `FOR UPDATE` on the constructions and constructions_vie rows.

### E2: Fix augmenterBatiment — reads outside transaction

Find `function augmenterBatiment(` in `player.php` (around line 596-630). It reads constructions data outside the transaction, then writes inside.

Move the initial reads inside the `withTransaction()` block and add `FOR UPDATE` to the SELECT.

### E3: Fix TOCTOU on statistiques.inscrits

In `player.php` around lines 40-41 and 108, `statistiques.inscrits` is read, incremented in PHP, and written back. This is a race condition.

Replace with atomic SQL:
```php
// Before: read-modify-write PHP
dbExecute($base, 'UPDATE statistiques SET inscrits = inscrits + 1');
```

### E4: Fix SELECT * in recalculerStatsAlliances

In `player.php` around line 820, `SELECT * FROM autre WHERE idalliance=?` fetches all columns but only 5 are used.

Replace with:
```php
dbFetchAll($base, 'SELECT login, totalPoints, points, pointsAttaque, pointsDefense, ressourcesPillees FROM autre WHERE idalliance=?', 'i', $allianceId)
```

### Step 5: Run tests and commit
```bash
php vendor/bin/phpunit --no-coverage 2>&1 | tail -5
git add includes/player.php
git commit -m "fix(player): add transactions to augmenterBatiment/diminuerBatiment, fix TOCTOU

- augmenterBatiment: move reads inside transaction with FOR UPDATE
- diminuerBatiment: wrap entire function in withTransaction
- statistiques.inscrits: use atomic UPDATE inscrits+1 not read-modify-write
- recalculerStatsAlliances: replace SELECT * with explicit 6 columns

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Batch F — XSS Security Fixes

**Files:** `includes/ui_components.php`, `index.php`

### F1: Fix carteForum() $grade['couleur'] CSS injection

In `includes/ui_components.php` around line 644, `$grade['couleur']` is inserted into a CSS `color:` context without escaping. A player with a grade color containing `}body{...` could inject CSS.

Fix: validate/sanitize `$grade['couleur']` to only allow valid CSS color values:
```php
// Strip everything except hex colors, rgb(), and named colors
$safeColor = preg_replace('/[^a-zA-Z0-9#(),%. ]/', '', $grade['couleur']);
```
Or simply use `htmlspecialchars($grade['couleur'], ENT_QUOTES, 'UTF-8')` for the CSS context (sufficient to prevent injection via attribute breakout).

### F2: Fix chip() and submit() unescaped $id attributes

In `ui_components.php`, `chip()` function around line 482 and `submit()` around line 572, the `$id` parameter is inserted into HTML attributes without escaping.

Fix: apply `htmlspecialchars($id, ENT_QUOTES, 'UTF-8')` to `$id` before using in HTML attribute context.

### F3: Fix index.php strip_tags() allows javascript: href

In `index.php` around line 118, `strip_tags($content, '<a>')` allows `<a>` tags to pass through, including those with `href="javascript:..."`.

Fix: After `strip_tags()`, apply a secondary pass to strip dangerous href values:
```php
$content = strip_tags($content, '<a><br><strong><em>');
// Remove javascript: and data: hrefs from <a> tags
$content = preg_replace('/href\s*=\s*["\']?\s*(javascript|data):[^"\'>\s]*/i', 'href="#"', $content);
```

### Step 4: Run tests and commit
```bash
php vendor/bin/phpunit --no-coverage 2>&1 | tail -5
git add includes/ui_components.php index.php
git commit -m "fix(xss): escape chip/submit id attrs, sanitize CSS color, strip js: hrefs

- ui_components.php: htmlspecialchars on chip()/submit() $id parameters
- ui_components.php: sanitize $grade['couleur'] before CSS context insertion
- index.php: strip javascript:/data: hrefs after strip_tags()

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Batch G — Alliance / Forum Fixes

**Files:** `alliance.php`, `forum.php`, `sujet.php`, `listesujets.php`

### G1: Fix alliance-private forum readable by non-members

In `forum.php`, `sujet.php`, and `listesujets.php`, forum topics marked as alliance-private should only be readable by members of the owning alliance.

Find where the forum privacy check is applied (likely in POST/write paths). Ensure GET/read paths also check:
```php
if ($forum['alliance_only'] ?? false) {
    // Verify viewer is a member of the alliance that owns this forum
    $viewerAlliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
    if (!$viewerAlliance || $viewerAlliance['idalliance'] != $forum['alliance_id']) {
        $erreur = "Accès refusé.";
        // redirect or exit
    }
}
```
Read the actual forum/alliance linking structure in the DB schema to implement correctly.

### G2: Fix stale $allianceJoueur in grade check (race)

In `alliance.php` around line 337, after an alliance auto-delete scenario, `$allianceJoueur` may be stale.

Add a DB re-fetch of the alliance data inside the grade check:
```php
// Re-fetch alliance data atomically before grade-based operations
$freshAlliance = dbFetchOne($base, 'SELECT * FROM alliances WHERE id=?', 'i', $allianceJoueur['id']);
if (!$freshAlliance) {
    $erreur = "L'alliance n'existe plus.";
    // redirect
    return;
}
```

### Step 3: Run tests and commit
```bash
php vendor/bin/phpunit --no-coverage 2>&1 | tail -5
git add alliance.php forum.php sujet.php listesujets.php
git commit -m "fix(alliance): private forum access control, stale grade check

- forum.php/sujet.php/listesujets.php: enforce alliance membership check on GET paths
- alliance.php: re-fetch alliance from DB before grade operations to avoid race

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Batch H — Admin / Economy Fixes

**Files:** `moderation/index.php`, `marche.php`, `don.php`

### H1: Fix moderation atom grants uncapped

In `moderation/index.php` around lines 106-110, only `energie` is capped when granting resources. All 8 atom types (`carbone`, `azote`, `hydrogene`, `oxygene`, `chlore`, `soufre`, `brome`, `iode`) can be granted in unlimited amounts.

Add caps for atom grants using storage limits:
```php
// Apply same cap logic as energy for all atoms
$maxAtoms = placeDepot($base, $targetLogin); // or a sensible cap constant
foreach ($nomsRes as $num => $atom) {
    if (isset($_POST[$atom]) && (int)$_POST[$atom] > 0) {
        $amount = min((int)$_POST[$atom], MAX_ADMIN_GRANT_ATOMS); // define constant e.g. 100000
        dbExecute($base, "UPDATE ressources SET $atom = LEAST($atom + ?, ?) WHERE login=?", 'iis', $amount, $maxAtoms, $targetLogin);
    }
}
```

### H2: Fix sell — energy silently discarded at storage cap

In `marche.php` around lines 383-394, when selling atoms for energy, if the player's energy is already at cap, the energy credit is silently discarded but atoms are still consumed.

Fix: check energy capacity BEFORE consuming atoms:
```php
$energieDispo = $maxEnergie - $currentEnergie; // room available
if ($energieDispo <= 0) {
    $erreur = "Votre stockage d'énergie est plein.";
    // do NOT proceed
} else {
    $energieCredite = min($energieGagnee, $energieDispo);
    // consume atoms, credit $energieCredite energy
    // Update success message to show actual $energieCredite, not $energieGagnee
}
```

### H3: Fix buy — stale price in success message

In `marche.php` around line 301, the success message reports the price fetched BEFORE the transaction (which may have changed due to market volatility inside the transaction).

Fix: read the actual charged amount from the transaction result (the amount debited from energie) and display that in the success message.

### H4: Fix don.php preg_match dead code

In `don.php` around line 20, `preg_match` is called on an already-cast int (dead code, confusing).

Remove the dead `preg_match` call, keep only the int cast.

### Step 5: Run tests and commit
```bash
php vendor/bin/phpunit --no-coverage 2>&1 | tail -5
git add moderation/index.php marche.php don.php
git commit -m "fix(admin+economy): cap atom grants, fix sell overflow, fix buy stale price

- moderation/index.php: apply LEAST() cap on all 8 atom types (not just energy)
- marche.php: check energy storage capacity before consuming atoms on sell
- marche.php: show actually-charged amount in buy success message
- don.php: remove dead preg_match on already-cast int

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Batch I — Migration Fixes

**Files:** `migrations/0007_*.sql`, `migrations/0033_*.sql`, one of the `migrations/0039_*.sql` files

### I1: Fix 0039 triple prefix conflict

Three files share prefix `0039`:
- `0039_add_compound_snapshot_to_actionsattaques.sql`
- `0039_add_season_recap_fk.sql`
- `0039_idempotent_add_columns.sql`

**Important:** These files are already tracked in the VPS `migrations` table — DO NOT rename applied migrations. Instead, rename the LEAST useful one (the idempotent_add_columns which serves no clear purpose) by:
1. Check VPS: `mysql -u tvlw -pmLLIoTy2ByGTBNb9RQpTGnqPhXQfUR tvlw -e "SELECT filename FROM migrations WHERE filename LIKE '0039%'"`
2. If `0039_idempotent_add_columns.sql` is applied on VPS, add a note comment but don't rename it (would break migration tracking).
3. If it's NOT on VPS, rename it to `0049_idempotent_add_columns.sql`.

### I2: Fix 0007 missing ENGINE and CHARSET

In `migrations/0007_add_prestige_table.sql`, if the `CREATE TABLE` statement has no `ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci`, add it.

**Check first:** `mysql -u tvlw -pmLLIoTy2ByGTBNb9RQpTGnqPhXQfUR tvlw -e "SHOW CREATE TABLE prestige\G"` — if already correctly set on VPS, just fix the file for fresh installs.

Fix the SQL file:
```sql
CREATE TABLE IF NOT EXISTS prestige (
  -- existing columns --
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
```

### I3: Fix 0033 invalid FK IF NOT EXISTS syntax

In `migrations/0033_fix_utf8mb4_tables.sql`, if it contains `ADD FOREIGN KEY IF NOT EXISTS`, replace with:
```sql
-- Drop first if exists, then re-add
ALTER TABLE ... DROP FOREIGN KEY IF EXISTS fk_name;
ALTER TABLE ... ADD CONSTRAINT fk_name FOREIGN KEY (...) REFERENCES ...;
```

**Check first:** `mysql -u tvlw -pmLLIoTy2ByGTBNb9RQpTGnqPhXQfUR tvlw -e "SELECT filename, applied_at FROM migrations WHERE filename='0033_fix_utf8mb4_tables.sql'"`

If already applied on VPS, the fix only matters for fresh installs. Fix the file anyway for correctness.

### Step 4: Run tests and commit
```bash
php vendor/bin/phpunit --no-coverage 2>&1 | tail -5
git add migrations/
git commit -m "fix(migrations): ENGINE/CHARSET in 0007, fix FK syntax in 0033, resolve 0039 prefix

- 0007: add ENGINE=InnoDB CHARSET=latin1 to prestige CREATE TABLE
- 0033: replace invalid FOREIGN KEY IF NOT EXISTS with DROP+ADD pattern
- 0039 prefix: resolve triple-prefix conflict (rename/annotate idempotent file)

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Final Phase — Deploy and Test

### After all 9 batches committed:

**Step 1: Run full test suite**
```bash
php vendor/bin/phpunit --no-coverage 2>&1 | tail -10
```
Expected: 570+ tests, 0 failures (38 integration OK).

**Step 2: Push to GitHub**
```bash
git push origin main
```

**Step 3: Deploy to VPS**
```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "cd /var/www/html && git pull origin main"
```

**Step 4: Smoke test VPS**
```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "for page in index.php inscription.php classement.php marche.php attaquer.php prestige.php bilan.php laboratoire.php; do code=\$(curl -s -o /dev/null -w '%{http_code}' http://localhost/\$page); echo \"\$code \$page\"; done"
```
Expected: all 200.

**Step 5: Execute Live Browser Testing Plan**

Execute `docs/plans/2026-03-08-live-browser-testing.md` using Chrome DevTools MCP.

---

## Completion Criteria

- [ ] Batch A committed (combat report blank data fixed)
- [ ] Batch B committed (combat physics fixed)
- [ ] Batch C committed (headers/CSP fixed)
- [ ] Batch D committed (prestige/rankings/tutorial fixed)
- [ ] Batch E committed (player.php transactions fixed)
- [ ] Batch F committed (XSS fixed)
- [ ] Batch G committed (alliance/forum fixed)
- [ ] Batch H committed (admin/economy fixed)
- [ ] Batch I committed (migrations fixed)
- [ ] Tests pass: 0 failures
- [ ] VPS deployed, all pages HTTP 200
- [ ] Live browser testing completed
