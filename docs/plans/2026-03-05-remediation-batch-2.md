# Remediation Batch 2 — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix remaining combat race conditions, audit 7 never-reviewed files, and close top MEDIUM findings from the ultra audit.

**Architecture:** All transaction fixes follow the established `withTransaction()` + `FOR UPDATE` pattern already used across the codebase. File access restrictions use `.htaccess` deny rules. All fixes are minimal, targeted changes.

**Tech Stack:** PHP 8.2, MariaDB 10.11, Apache 2 `.htaccess`

---

## Task 1: Combat FOR UPDATE on molecule reads

**Files:**
- Modify: `includes/combat.php:5` and `includes/combat.php:15`

**Context:** `combat.php` is included inside the combat transaction in `includes/game_actions.php:102` (`mysqli_begin_transaction`). The molecule SELECTs for defender (line 5) and attacker (line 15) lack `FOR UPDATE`, allowing concurrent combats against the same player to read stale molecule counts.

**Step 1: Read the file**

Verify current state of `includes/combat.php` lines 1-25.

**Step 2: Add FOR UPDATE to defender molecule read**

In `includes/combat.php` line 5, change:
```php
$rowsDefenseur = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $actions['defenseur']);
```
to:
```php
$rowsDefenseur = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC FOR UPDATE', 's', $actions['defenseur']);
```

**Step 3: Add FOR UPDATE to attacker molecule read**

In `includes/combat.php` line 15, change:
```php
$rowsAttaquant = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $actions['attaquant']);
```
to:
```php
$rowsAttaquant = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC FOR UPDATE', 's', $actions['attaquant']);
```

**Step 4: Add FOR UPDATE to construction reads**

In `includes/combat.php` line 28, change:
```php
$niveauxAttaquant = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=?', 's', $actions['attaquant']);
```
to:
```php
$niveauxAttaquant = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=? FOR UPDATE', 's', $actions['attaquant']);
```

In `includes/combat.php` line 38, change:
```php
$niveauxDefenseur = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=?', 's', $actions['defenseur']);
```
to:
```php
$niveauxDefenseur = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=? FOR UPDATE', 's', $actions['defenseur']);
```

**Step 5: Run tests**

Run: `cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --no-configuration tests/`
Expected: All tests pass (415+)

**Step 6: Commit**

```bash
git add includes/combat.php
git commit -m "fix: add FOR UPDATE locks to combat molecule/construction reads (GAP-004)"
```

---

## Task 2: comptetest.php — FOR UPDATE on rename transaction

**Files:**
- Modify: `comptetest.php:66`

**Context:** The visitor→player rename transaction (line 66) updates 21 tables but never locks the source `membre` row first. A concurrent rename of the same visitor could cause partial updates. Add `SELECT ... FOR UPDATE` on `membre` as the first statement inside the transaction.

**Step 1: Read the file**

Verify current state of `comptetest.php` lines 60-95.

**Step 2: Add FOR UPDATE lock inside transaction**

In `comptetest.php`, inside the `withTransaction` callback (line 66), add as the FIRST statement:
```php
withTransaction($base, function() use ($base, $newLogin, $oldLogin, $hashedPassword, $email) {
    // Lock source row to prevent concurrent renames (GAP-013)
    $locked = dbFetchOne($base, 'SELECT login FROM membre WHERE login = ? FOR UPDATE', 's', $oldLogin);
    if (!$locked) {
        throw new \RuntimeException('Source account not found');
    }
    dbExecute($base, 'UPDATE autre SET login = ? WHERE login = ?', 'ss', $newLogin, $oldLogin);
```

Specifically, add these 4 lines after line 66 and before line 67:
```php
    // Lock source row to prevent concurrent renames (GAP-013)
    $locked = dbFetchOne($base, 'SELECT login FROM membre WHERE login = ? FOR UPDATE', 's', $oldLogin);
    if (!$locked) {
        throw new \RuntimeException('Source account not found');
    }
```

**Step 3: Run tests**

Run: `cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --no-configuration tests/`
Expected: All tests pass

**Step 4: Commit**

```bash
git add comptetest.php
git commit -m "fix: add FOR UPDATE lock in visitor rename transaction (comptetest.php)"
```

---

## Task 3: moderationForum.php — Fix broken datepicker selector

**Files:**
- Modify: `moderationForum.php:76`

**Context:** The datepicker jQuery UI selector targets `#dateFin` (line 76) but the input element has `id="calVacs"` (line 63). The datepicker is silently broken — moderators must type dates manually.

**Step 1: Read the file**

Verify current state of `moderationForum.php` lines 60-85.

**Step 2: Fix the jQuery selector**

In `moderationForum.php` line 76, change:
```js
$("#dateFin").datepicker({
```
to:
```js
$("#calVacs").datepicker({
```

And line 83, change:
```js
$("#dateFin").datepicker("option", "dateFormat", "dd/mm/yy");
```
to:
```js
$("#calVacs").datepicker("option", "dateFormat", "dd/mm/yy");
```

**Step 3: Verify BBcode motif rendering is safe**

In `moderationForum.php` line 123, `BBcode($sanction['motif'])` renders the ban reason. The `BBcode()` function should already sanitize output (it was hardened in Batch C). Confirm by reading `includes/bbcode.php` and verifying that `[img]` is restricted and event handlers are stripped. If BBcode() is already hardened, no change needed here.

**Step 4: Run tests**

Run: `cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --no-configuration tests/`
Expected: All tests pass

**Step 5: Commit**

```bash
git add moderationForum.php
git commit -m "fix: correct broken datepicker selector in moderationForum.php (#dateFin → #calVacs)"
```

---

## Task 4: includes/cardsprivate.php — Remove dead DB query

**Files:**
- Modify: `includes/cardsprivate.php:5`

**Context:** Line 5 executes `dbFetchOne($base, 'SELECT niveaututo FROM autre WHERE login = ?', ...)` but the result `$niveaututoRow` is NEVER used anywhere in the file. The tutorial level is already available in `$tuto['niveaututo']` (loaded by `initPlayer()`). This is a wasted DB query on every private page load.

**Step 1: Read the file**

Verify `$niveaututoRow` is never referenced after line 5.

**Step 2: Remove the dead query**

Delete lines 5-6 from `includes/cardsprivate.php`:
```php
$niveaututoRow = dbFetchOne($base, 'SELECT niveaututo FROM autre WHERE login = ?', 's', $_SESSION['login']);
```

(Keep the blank line or remove it — cosmetic choice.)

**Step 3: Run tests**

Run: `cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --no-configuration tests/`
Expected: All tests pass

**Step 4: Commit**

```bash
git add includes/cardsprivate.php
git commit -m "perf: remove dead DB query in cardsprivate.php (niveaututoRow never used)"
```

---

## Task 5: includes/atomes.php — Fix ceil inflation + use SQL SUM

**Files:**
- Modify: `includes/atomes.php:4-9`

**Context:** The current code fetches all molecule rows and sums `ceil()` of each individual class count. This inflates the displayed total — e.g., 3 classes with 1.3 each show as 6 instead of `ceil(3.9)=4`. Also, a single `SUM()` query is more efficient than fetching all rows.

**Step 1: Read the file**

Verify current state of `includes/atomes.php`.

**Step 2: Replace PHP loop with SQL SUM**

Replace lines 4-9:
```php
            $nb_molecules = 0;
            $nbRows = dbFetchAll($base, 'SELECT nombre FROM molecules WHERE proprietaire=?', 's', $_SESSION['login']);
            foreach($nbRows as $nb){

                $nb_molecules += ceil($nb['nombre']);
            }
```

With:
```php
            $nbRow = dbFetchOne($base, 'SELECT COALESCE(SUM(nombre), 0) AS total FROM molecules WHERE proprietaire=?', 's', $_SESSION['login']);
            $nb_molecules = ceil($nbRow['total']);
```

**Step 3: Run tests**

Run: `cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --no-configuration tests/`
Expected: All tests pass

**Step 4: Commit**

```bash
git add includes/atomes.php
git commit -m "fix: use SQL SUM for molecule count to prevent ceil-per-class inflation (atomes.php)"
```

---

## Task 6: Block web access to admin/tableau.php and tools/

**Files:**
- Modify: `.htaccess` (project root)
- Create: `tools/.htaccess`

**Context:** `admin/tableau.php` is a 795-line CRPJ (French police report) parser that has nothing to do with TVLW — it has innerHTML XSS and variable shadowing bugs, but since it's a separate app entirely, the safest fix is to block web access. `tools/balance_simulator.php` is a CLI-only script that leaks internal game formulas if accessed via HTTP. Both should be web-inaccessible.

**Step 1: Block tools/ directory via .htaccess**

Create `tools/.htaccess`:
```apache
# CLI-only tools — deny all HTTP access
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
```

**Step 2: Add CLI guard to balance_simulator.php**

In `tools/balance_simulator.php`, add at line 2 (after `<?php`):
```php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
```

**Step 3: Block admin/tableau.php specifically**

In `admin/` directory, check if there's already an `.htaccess`. If not, add a comment in the root `.htaccess` or create `admin/.htaccess` that blocks `tableau.php`:

Add to root `.htaccess`:
```apache
# Block non-TVLW admin tool (CRPJ parser)
<Files "admin/tableau.php">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
</Files>
```

Actually, `<Files>` doesn't support paths — use a `<Location>` or `<FilesMatch>` in the admin directory. The simplest approach: check if `admin/tableau.php` has an admin auth guard already (it does — `redirectionmotdepasse.php`). Since the file already requires admin password auth, the XSS is only exploitable by admins. The variable shadowing bug just breaks the tool. Since this isn't a TVLW file, the safest approach is:

Create `admin/.htaccess` if it doesn't exist, or add to it:
```apache
# Block CRPJ parser tool — not part of TVLW
<Files "tableau.php">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order deny,allow
        Deny from all
    </IfModule>
</Files>
```

**Step 4: Run tests**

Run: `cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --no-configuration tests/`
Expected: All tests pass

**Step 5: Commit**

```bash
git add tools/.htaccess tools/balance_simulator.php .htaccess
ls admin/.htaccess 2>/dev/null && git add admin/.htaccess
git commit -m "security: block web access to tools/ dir and admin/tableau.php"
```

---

## Task 7: listesujets.php — Replace hardcoded forum ID range

**Files:**
- Modify: `listesujets.php:10-18`

**Context:** Line 13 uses `$_GET['id'] > 8` to reject invalid forum IDs. If new forums are added (id > 8), they become inaccessible. Replace with a DB existence check.

**Step 1: Read the file**

Verify current state of `listesujets.php` lines 10-18.

**Step 2: Replace hardcoded range with DB check**

Replace lines 10-18:
```php
if(!isset($_GET['id'])
	or intval(trim($_GET['id'])) == 0
	or $_GET['id'] < 1
	or $_GET['id'] > 8
	or !preg_match("#^[0-9]*$#", $_GET['id'])
	) {
	header('Location: forum.php');
	exit();
}
```

With:
```php
if (!isset($_GET['id']) || !preg_match("#^[0-9]+$#", $_GET['id'])) {
	header('Location: forum.php');
	exit();
}
$getId = (int)$_GET['id'];
if ($getId < 1 || !dbFetchOne($base, 'SELECT id FROM forums WHERE id = ?', 'i', $getId)) {
	header('Location: forum.php');
	exit();
}
```

Note: this requires `$base` to be available. It is — `basicprivatephp.php` (line 4) and `basicpublicphp.php` (line 6) both include `connexion.php`. Also `database.php` must be loaded — verify it's included via `basicprivatephp.php` or `fonctions.php`.

Also update line 23 since `$getId` is now defined earlier — remove the duplicate:
```php
$_GET['id'] = trim($_GET['id']);
$getId = (int)$_GET['id'];
```
Replace with just:
```php
$_GET['id'] = trim($_GET['id']);
```
(Since `$getId` is already set.)

**Step 3: Run tests**

Run: `cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --no-configuration tests/`
Expected: All tests pass

**Step 4: Commit**

```bash
git add listesujets.php
git commit -m "fix: replace hardcoded forum ID range with DB existence check (P5-GAP-018)"
```

---

## Task 8: ecriremessage.php — Add broadcast rate limit

**Files:**
- Modify: `ecriremessage.php:12-26`

**Context:** The `[alliance]` broadcast (line 12) and `[all]` broadcast (line 20) have no rate limiting. A player can spam their entire alliance. Use the existing `rate_limiter.php` module.

**Step 1: Read the file**

Verify current state of `ecriremessage.php` lines 1-42.

**Step 2: Add rate_limiter include**

At the top of `ecriremessage.php`, after line 3:
```php
require_once("includes/rate_limiter.php");
```

**Step 3: Add rate limit check before alliance broadcast**

Before line 12, add:
```php
		if ($_POST['destinataire'] == "[alliance]") {
			// Rate limit: 3 alliance broadcasts per 5 minutes
			if (!rateLimitCheck($_SESSION['login'], 'broadcast_alliance', 3, 300)) {
				$erreur = "Vous envoyez trop de messages de masse. Veuillez patienter.";
			} else {
```

And close the else block after line 19 (before the `} elseif`):
```php
			}
```

**Step 4: Do the same for [all] broadcast**

The `[all]` broadcast is already admin-only (`$_SESSION['login'] == "Guortates"`). Rate limiting is optional here since only the game owner can use it. Skip this — admin is trusted.

**Step 5: Run tests**

Run: `cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --no-configuration tests/`
Expected: All tests pass

**Step 6: Commit**

```bash
git add ecriremessage.php
git commit -m "fix: add rate limit to alliance broadcast messages (P5-GAP-022)"
```

---

## Task 9: moderation/index.php — Atomic resource grant + log

**Files:**
- Modify: `moderation/index.php:106-139`

**Context:** The resource grant UPDATE (line 122) and audit log INSERT (line 140+) are two separate statements outside any transaction. If the INSERT fails, resources are granted without an audit trail.

**Step 1: Read the file**

Verify current state of `moderation/index.php` lines 100-155.

**Step 2: Wrap in withTransaction()**

Wrap the existing UPDATE + INSERT block in a transaction. The `withTransaction()` function is available from `includes/database.php`.

Before line 106 (the `$ressourcesDestinataire = ...` line), add:
```php
					withTransaction($base, function() use ($base, $nomsRes, &$erreur) {
```

After the INSERT for the moderation log, close the transaction:
```php
					}); // end withTransaction
```

Adjust variable scoping as needed — the closure needs `$_POST` values and `$nomsRes`. Since `$_POST` is superglobal, it's accessible inside the closure without `use`.

**Step 3: Run tests**

Run: `cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --no-configuration tests/`
Expected: All tests pass

**Step 4: Commit**

```bash
git add moderation/index.php
git commit -m "fix: wrap resource grant + audit log in transaction (P5-GAP-027)"
```

---

## Task 10: marche.php — Reject quantity=0 in buy/sell

**Files:**
- Modify: `marche.php:176-180` (buy) and sell section

**Context:** Market buy/sell accepts `quantity=0` through `intval()` which returns 0 for empty strings. The `!empty()` check on line 180 catches this for buy since `!empty(0)` is false. But verify the sell path too.

**Step 1: Read the file**

Read `marche.php` fully around the buy and sell handlers to check current validation.

**Step 2: Verify buy path**

Line 180: `if (!empty($_POST['nombreRessourceAAcheter']) and preg_match(...))` — `!empty(0)` is false in PHP, so quantity=0 is already rejected for buy. Confirm this is correct.

**Step 3: Check sell path**

Read the sell section of `marche.php`. Verify whether `$_POST['nombreRessourceAVendre']` is validated similarly. If it uses `intval()` and `!empty()`, it's fine. If not, add:
```php
if ($_POST['nombreRessourceAVendre'] <= 0) {
    $erreur = "La quantité doit être supérieure à 0.";
}
```

**Step 4: Run tests**

Run: `cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --no-configuration tests/`
Expected: All tests pass

**Step 5: Commit (only if changes were needed)**

```bash
git add marche.php
git commit -m "fix: reject quantity=0 in market operations (P4-ADV-006)"
```

---

## Task 11: resource_nodes.php — Cap bonus stacking

**Files:**
- Modify: `includes/resource_nodes.php:97-108`
- Modify: `includes/config.php` (add constant)

**Context:** `getResourceNodeBonus()` linearly stacks all overlapping node bonuses without any cap. With enough nearby nodes, the bonus could be unbounded.

**Step 1: Add constant to config.php**

In `includes/config.php`, in the resource nodes section, add:
```php
define('MAX_NODE_BONUS_PCT', 0.50); // 50% max resource node bonus
```

**Step 2: Cap the bonus return**

In `includes/resource_nodes.php` line 108, change:
```php
    return $totalBonus;
```
to:
```php
    return min($totalBonus, MAX_NODE_BONUS_PCT);
```

**Step 3: Run tests**

Run: `cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --no-configuration tests/`
Expected: All tests pass

**Step 4: Commit**

```bash
git add includes/resource_nodes.php includes/config.php
git commit -m "fix: cap resource node bonus stacking at 50% (P5-GAP-019)"
```

---

## Task 12: Push to GitHub + Deploy to VPS

**Files:** None (deployment)

**Step 1: Push to GitHub**

```bash
cd /home/guortates/TVLW/The-Very-Little-War
git push origin main
```

**Step 2: Deploy to VPS**

```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'cd /var/www/html && git pull origin main'
```

**Step 3: Verify VPS**

```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'curl -s -o /dev/null -w "%{http_code}" http://localhost/health.php'
```
Expected: `200`

**Step 4: Run full tests one more time**

```bash
cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --no-configuration tests/
```
Expected: All tests pass

---

## Summary

| Task | Finding | Severity | File(s) |
|------|---------|----------|---------|
| 1 | GAP-004: Combat FOR UPDATE | HIGH | includes/combat.php |
| 2 | GAP-013: Rename FOR UPDATE | HIGH | comptetest.php |
| 3 | Broken datepicker | MEDIUM | moderationForum.php |
| 4 | Dead DB query per page | LOW | includes/cardsprivate.php |
| 5 | Ceil inflation + N+1 | MEDIUM | includes/atomes.php |
| 6 | Web-accessible non-TVLW tools | HIGH | tools/, admin/tableau.php |
| 7 | Hardcoded forum ID range | MEDIUM | listesujets.php |
| 8 | Broadcast rate limit | MEDIUM | ecriremessage.php |
| 9 | Non-atomic resource grant | MEDIUM | moderation/index.php |
| 10 | Market quantity=0 | MEDIUM | marche.php |
| 11 | Uncapped node bonus | MEDIUM | includes/resource_nodes.php, config.php |
| 12 | Deploy | — | GitHub + VPS |

**Total: 11 code fixes + 1 deployment = 12 tasks, ~11 commits**
