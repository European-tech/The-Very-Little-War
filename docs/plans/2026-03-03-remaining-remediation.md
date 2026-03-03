# TVLW Remaining Remediation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix all remaining CRITICAL and HIGH findings from the 198-item mega audit, deploy HTTPS, and add the missing prestige UI page.

**Architecture:** Batch fixes ordered by risk and dependency: quick bug fixes first (immediate deploy), then transaction safety, security hardening, season reset, new features, infrastructure, and finally game balance. Each batch is self-contained and deployable.

**Tech Stack:** PHP 8.2, MariaDB 10.11, Apache 2, Framework7 CSS, jQuery 3.7.1

---

## Status Summary

**Mega Audit:** 198 findings (32 CRITICAL, 62 HIGH, 59 MEDIUM, 25 LOW, 20 QoL)

**Already Fixed:** ~30 CRITICAL items, ~30 HIGH items across 49 commits
- All prepared statements, CSRF, bcrypt, XSS hardening, rate limiting
- Transactions in: attaquer.php, constructions.php, marche.php, player.php, prestige.php, comptetest.php
- Combat null guards, maintenance POST blocking, session security, dead file cleanup
- Full documentation suite (10 docs), 370 tests / 2326 assertions

**Still Open:** Listed below by batch, priority-ordered.

---

## Batch A: Quick Bug Fixes (30 min, deploy immediately)

### Task A.1: Fix undefined combat report variables (C-015)

**Files:**
- Modify: `includes/game_actions.php:155-165`

**Step 1: Read game_actions.php and combat.php to trace variable names**

Combat.php sets: `$pointsAttaquant`, `$pointsDefenseur`, `$totalPille`
Report template uses: `$attaquePts`, `$defensePts`, `$pillagePts`, `$pillagePts1`

These 4 variables are never assigned. Every combat report has PHP warnings and empty display.

**Step 2: Add variable assignments after combat.php include (after line 121)**

```php
// Map combat output to report template variables
$attaquePts = number_format($pointsAttaquant, 0, ' ', ' ');
$defensePts = number_format($pointsDefenseur, 0, ' ', ' ');
$pillagePts = number_format($totalPille, 0, ' ', ' ');
$pillagePts1 = $pillagePts; // Defender sees same pillage amount
```

**Step 3: Run tests**

```bash
cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --no-coverage
```
Expected: 370 tests pass

**Step 4: Commit**

```bash
git add includes/game_actions.php
git commit -m "fix(C-015): assign combat report template variables from combat output"
```

### Task A.2: Delete dead notification.js (C-019)

**Files:**
- Delete: `js/notification.js`

**Step 1: Verify notification.js is not referenced anywhere meaningful**

```bash
grep -r "notification.js" --include="*.php" --include="*.html" .
```

If no references found, delete it.

**Step 2: Delete and commit**

```bash
rm js/notification.js
git add -A js/notification.js
git commit -m "fix(C-019): remove dead push notification script"
```

### Task A.3: Fix formation time uninitialized $niveauazote (H-055)

**Files:**
- Modify: `armee.php:116`

**Step 1: Read armee.php around line 116**

Find where $niveauazote is used but never fetched from DB.

**Step 2: Fetch actual azote level from constructions table**

```php
$niveauAzoteRow = dbFetchOne($base, 'SELECT azote FROM constructions WHERE login = ?', 's', $_SESSION['login']);
$niveauazote = $niveauAzoteRow ? $niveauAzoteRow['azote'] : 0;
```

**Step 3: Run tests, commit**

```bash
git commit -m "fix(H-055): fetch actual azote level for formation time calculation"
```

### Task A.4: Fix producteur negative energy at startup (H-054)

**Files:**
- Modify: `includes/game_resources.php`

**Step 1: Find energy calculation and floor at 0**

After computing total energy production (which can go negative if producteur drain exceeds iode production), add:

```php
$energie = max(0, $energie);
```

**Step 2: Run tests, commit**

```bash
git commit -m "fix(H-054): floor energy at 0 after production calculation"
```

### Task A.5: Deploy Batch A

```bash
git push origin main
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "cd /var/www/html && git pull origin main"
```

---

## Batch B: Transaction Safety — armee.php + alliance.php (1h)

These files have NO withTransaction wrapping. Concurrent requests can double-spend atoms, energy, and alliance resources.

### Task B.1: Wrap neutrino formation in transaction (H-013)

**Files:**
- Modify: `armee.php:70-76`

**Step 1: Read armee.php, locate neutrino formation block**

**Step 2: Wrap energy deduction + INSERT actionsformation in withTransaction():**

```php
withTransaction($base, function() use ($base) {
    $res = dbFetchOne($base, 'SELECT energie FROM ressources WHERE login = ? FOR UPDATE', 's', $_SESSION['login']);
    // Re-validate energy
    // UPDATE ressources SET energie = ...
    // INSERT INTO actionsformation ...
});
```

**Step 3: Run tests, commit**

```bash
git commit -m "fix(H-013): wrap neutrino formation in withTransaction"
```

### Task B.2: Wrap molecule formation queue in transaction (H-014)

**Files:**
- Modify: `armee.php:118-139`

Same pattern: withTransaction + SELECT ressources FOR UPDATE + re-validate + writes.

```bash
git commit -m "fix(H-014): wrap molecule formation in withTransaction"
```

### Task B.3: Wrap molecule class creation in transaction (H-015)

**Files:**
- Modify: `armee.php:191-220`

```bash
git commit -m "fix(H-015): wrap molecule class creation in withTransaction"
```

### Task B.4: Wrap alliance research upgrade in transaction (H-016)

**Files:**
- Modify: `alliance.php:106-108`

```php
withTransaction($base, function() use ($base) {
    $alliance = dbFetchOne($base, 'SELECT * FROM alliances WHERE id = ? FOR UPDATE', 'i', $idAlliance);
    // Re-validate alliance can afford upgrade
    // UPDATE alliances SET ...
});
```

```bash
git commit -m "fix(H-016): wrap alliance research upgrade in withTransaction"
```

### Task B.5: Wrap return trip molecule credit in transaction (H-001)

**Files:**
- Modify: `includes/game_actions.php:446-468`

```bash
git commit -m "fix(H-001): wrap return trip molecule credit in withTransaction"
```

### Task B.6: Add CAS guard on formation/construction completion (H-002)

**Files:**
- Modify: `includes/game_actions.php:26-31, 40-60`

Prevent double-processing by checking action still exists before processing:

```php
$action = dbFetchOne($base, 'SELECT * FROM actionsformation WHERE id = ? FOR UPDATE', 'i', $actionId);
if (!$action) continue; // Already processed by concurrent request
```

```bash
git commit -m "fix(H-002): CAS guard prevents formation/construction double-processing"
```

### Task B.7: Deploy Batch B

```bash
git push origin main
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "cd /var/www/html && git pull origin main"
```

---

## Batch C: Remaining Security Fixes (1h)

### Task C.1: Fix BBCode [img] to prevent CSRF via external images (H-025)

**Files:**
- Modify: `includes/bbcode.php:332`

**Step 1: Restrict [img] to self-hosted images only:**

```php
// Only allow images from same domain
preg_replace_callback('!\[img\](.*?)\[/img\]!isU', function($matches) {
    $url = $matches[1];
    if (strpos($url, '/') === 0 || strpos($url, 'images/') === 0) {
        return '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="image" />';
    }
    return '[image externe bloquée]';
}, $texte);
```

```bash
git commit -m "fix(H-025): restrict BBCode [img] to self-hosted images only"
```

### Task C.2: Fix grade name stored XSS (H-026)

**Files:**
- Modify: `allianceadmin.php` (grade display)

Apply htmlspecialchars() to grade name on all output locations.

```bash
git commit -m "fix(H-026): escape grade name output with htmlspecialchars"
```

### Task C.3: Fix PHP-to-JS injection (H-035)

**Files:**
- Modify: `includes/basicprivatehtml.php:433-459`

Replace all `<?php echo $var; ?>` in JS context with `<?php echo json_encode($var); ?>`.

```bash
git commit -m "fix(H-035): use json_encode for all PHP-to-JS value injection"
```

### Task C.4: Fix remaining XSS in alliance data (H-036)

**Files:**
- Modify: `alliance.php:196,209,212,224,227,432`

Apply htmlspecialchars() to all alliance data outputs.

```bash
git commit -m "fix(H-036): escape alliance data output with htmlspecialchars"
```

### Task C.5: Add column whitelist guards for SQL interpolation (H-037 through H-042)

**Files:**
- Modify: `alliance.php:94-113` (research column from POST)
- Modify: `game_resources.php:152-158` (resource column)
- Modify: `combat.php:579-612` ($nomsRes columns)
- Modify: `marche.php:100-115` (market SET clause)
- Modify: `constructions.php:240-263` (construction UPDATE)
- Modify: `game_actions.php:528-538` (resource delivery)

For each, add `in_array($col, ALLOWED_COLUMNS)` guard before interpolation.

```bash
git commit -m "fix(H-037 to H-042): column whitelist guards on all SQL interpolation"
```

### Task C.6: Fix stored XSS via message titles in debutCarte (H-034)

**Files:**
- Modify: `includes/ui_components.php:16-19`

Apply htmlspecialchars() inside debutCarte() itself so all callers are safe.

```bash
git commit -m "fix(H-034): escape title in debutCarte to prevent stored XSS"
```

### Task C.7: Deploy Batch C

```bash
git push origin main && ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "cd /var/www/html && git pull origin main"
```

---

## Batch D: Season Reset Hardening (45 min)

### Task D.1: Fix email loop timeout locking game in maintenance (H-048)

**Files:**
- Modify: `includes/basicprivatephp.php:292`

Set maintenance=0 BEFORE the email loop, not after. If emails fail, game stays accessible.

```bash
git commit -m "fix(H-048): clear maintenance before email loop to prevent game lock"
```

### Task D.2: Fix admin reset to use full season-end flow (H-049)

**Files:**
- Modify: `admin/index.php`

Admin reset button should call the same season-end flow (VP awards, prestige, archiving) before remiseAZero().

```bash
git commit -m "fix(H-049): admin reset uses full season-end flow with VP/prestige/archiving"
```

### Task D.3: Add missing column resets in remiseAZero (H-050, H-051)

**Files:**
- Modify: `includes/player.php` (remiseAZero function)

Add inside transaction:
```php
dbExecute($base, "UPDATE molecules SET isotope = NULL");
dbExecute($base, "UPDATE constructions SET spec_combat = 0, spec_economy = 0, spec_research = 0");
dbExecute($base, "DELETE FROM cours");
dbExecute($base, "DELETE FROM connectes");
dbExecute($base, "DELETE FROM grades");
dbExecute($base, "DELETE FROM vacances");
```

```bash
git commit -m "fix(H-050, H-051): reset isotopes, specs, cours, connectes, grades, vacances on season end"
```

### Task D.4: Freeze rankings before VP awards (H-052)

**Files:**
- Modify: `includes/basicprivatephp.php`

SELECT all rankings into array first, then loop to award VP (prevents concurrent changes during awards).

```bash
git commit -m "fix(H-052): freeze rankings snapshot before awarding VP"
```

### Task D.5: Deploy Batch D

```bash
git push origin main && ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "cd /var/www/html && git pull origin main"
```

---

## Batch E: Prestige UI Page (1h)

### Task E.1: Create prestige.php page (C-030)

**Files:**
- Create: `prestige.php`
- Modify: `includes/basicprivatehtml.php` (add menu link)

This is the ONLY game system with backend functions but ZERO UI. Players cannot:
- See their PP balance
- Purchase prestige unlocks
- View available unlocks and costs

**Step 1: Create prestige.php with standard page template**

Page sections:
1. Current PP balance display
2. Available unlocks (from PRESTIGE_UNLOCKS in config.php) with buy buttons
3. Already purchased unlocks (greyed out)
4. PP earning explanation

**Step 2: Add navigation link**

Add "Prestige" link in the sidebar/menu system.

**Step 3: Test manually on VPS**

**Step 4: Commit and deploy**

```bash
git commit -m "feat(C-030): add prestige.php page with PP display, unlock shop, and earning guide"
```

---

## Batch F: HTTPS + Infrastructure (30 min, requires DNS)

**BLOCKED ON:** Domain theverylittlewar.com must point to 212.227.38.111 first.

### Task F.1: Point domain DNS to VPS

This is a manual step in the Ionos domain management panel. Change A record to 212.227.38.111.

### Task F.2: Enable HTTPS via Let's Encrypt (C-006, C-007)

```bash
ssh root@212.227.38.111
certbot --apache -d theverylittlewar.com -d www.theverylittlewar.com
```

### Task F.3: Harden session cookies and add HSTS

**Files:**
- Modify: `includes/session_init.php` (hardcode cookie_secure=1)
- Modify: `.htaccess` (add HSTS header)

```apache
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

```bash
git commit -m "fix(C-006, C-007): HSTS header and session.cookie_secure=1 after HTTPS"
```

---

## Batch G: Game Balance (design decisions needed)

These are NOT bugs — they're balance tuning decisions that affect gameplay. Each should be discussed before implementing.

| Finding | Issue | Recommendation |
|---------|-------|----------------|
| C-021 | Quadratic stat snowball | Change to sqrt or log scaling |
| C-022 | Iode energy too weak | Buff iode formula or add unique mechanic |
| C-023 | Decay kills diversity | Reduce decay exponent |
| C-024 | Building destruction is pure RNG | Add targeting priority |
| C-032 | Medal bonuses cross-season | Already capped at 10% (verify) |
| H-008 | Soufre pillage S/3 divisor | Remove divisor or increase base |
| H-009 | Chlore speed diminishing returns | Linear or soft-cap |
| H-010 | Formation balance | Rebalance multipliers |
| H-053 | Ionisateur non-damageable asymmetry | Make both damageable or neither |

**These require playtesting before committing.** Previous sessions already rebalanced combat, market, beginner protection, and iode. Further tuning depends on live player feedback.

---

## Batch H: CSP Hardening (large effort, defer)

**C-004: Remove unsafe-inline from CSP**

This requires extracting 76+ inline `<script>` blocks across 15+ PHP files into external .js files, then switching to nonce-based CSP. Estimated 4-6 hours.

**Recommendation:** Defer until after HTTPS is live and game is stable. The current CSP with unsafe-inline still blocks external script injection — the real XSS vectors are the inline PHP-to-JS issues fixed in Batch C.

---

## Batch I: Remaining MEDIUM + LOW (backlog)

Full list in `docs/audit3/mega-findings-tracker.md` sections M-001 through M-059 and L-001 through L-025. Key items:

- M-001 through M-008: More transaction wrapping (lower risk operations)
- M-009 through M-035: UX improvements (tutorial, market, medals, alliance)
- M-036 through M-045: Schema fixes (FK constraints, column types)
- M-046 through M-059: Minor code quality, dead code, CSS cleanup
- L-001 through L-025: Polish items

---

## Execution Order

```
Batch A (quick fixes)      → 30 min  → deploy
Batch B (transactions)     → 1h      → deploy
Batch C (security)         → 1h      → deploy
Batch D (season reset)     → 45 min  → deploy
Batch E (prestige UI)      → 1h      → deploy
Batch F (HTTPS)            → 30 min  → blocked on DNS
Batch G (balance)          → TBD     → needs playtesting
Batch H (CSP)              → 4-6h    → defer
Batch I (backlog)          → ongoing → prioritize as needed
```

**Total for Batches A-E:** ~4-5 hours of active work
**Total for all batches:** ~10-15 hours including balance and CSP
