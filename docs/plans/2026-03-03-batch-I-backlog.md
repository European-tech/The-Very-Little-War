# Batch I: MEDIUM + LOW Backlog Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix all remaining MEDIUM and LOW findings from the 198-item mega audit — security gaps, missing transactions, schema fixes, dead code, and UX polish.

**Architecture:** 12 tasks grouped into 4 sub-batches by category: security first, then data integrity, code cleanup, and UX polish. Each sub-batch deploys independently.

**Tech Stack:** PHP 8.2, MariaDB 10.11, Apache 2, Framework7 CSS, jQuery 3.7.1

---

## Status Summary

**Mega Audit:** 198 findings total. After Phases 1-10 (Batches A-E), ~140 findings fixed.
**Remaining from verification:** 12 actionable items listed below.
**Already fixed (confirmed):** M-002, M-006, M-007, M-009, M-013, M-016, M-019, M-020, M-025, M-026, M-036, M-038, M-046, M-047, M-048, M-050, M-051, M-053, M-056, M-059, H-003, H-004, H-005, H-006, H-007, H-011, H-012, H-017, H-018, H-019, H-021, H-022, H-023, H-024, H-029, H-032, H-043, H-056, H-057, L-017, L-020

---

## Sub-batch I.1: Security Fixes (3 tasks)

### Task I.1: Fix rapports.php strip_tags XSS vulnerability (L-023)

**Files:**
- Modify: `rapports.php:30-31`

**Why:** `strip_tags()` with allowed tags like `<img>` permits `<img onerror=alert(1)>` XSS. Reports are server-generated HTML but include player names and alliance tags that could be attacker-controlled.

**Step 1: Read rapports.php around line 31**

Confirm: `echo strip_tags($rapports['contenu'], $allowedTags);`

**Step 2: Replace strip_tags with a safe HTML sanitization approach**

Since report content is server-generated HTML (from combat.php and game_actions.php), the safe approach is to keep strip_tags but remove dangerous allowed tags:

```php
// Remove <img> from allowed tags to prevent onerror XSS
// Report images use inline CSS backgrounds or text, not <img> tags
$allowedTags = '<a><br><strong><b><i><em><p><div><span><table><tr><td><th><ul><ol><li><hr>';
echo strip_tags($rapports['contenu'], $allowedTags);
```

Check if any report content actually uses `<img>` tags by searching game_actions.php and combat.php for `<img` in report generation. If reports DO use `<img>` tags, instead use:

```php
$content = strip_tags($rapports['contenu'], $allowedTags);
// Strip event handlers from allowed HTML tags
$content = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $content);
$content = preg_replace('/\s+on\w+\s*=\s*\S+/i', '', $content);
echo $content;
```

**Step 3: Run tests**

```bash
cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --no-coverage
```

**Step 4: Commit**

```bash
git add rapports.php
git commit -m "fix(L-023): strip event handlers from report HTML to prevent XSS"
```

### Task I.2: Add charset declaration to moderation panel (H-033)

**Files:**
- Modify: `moderation/index.php:157`

**Why:** HTML `<head>` section has no `<meta charset>` — French characters may corrupt depending on browser defaults.

**Step 1: Add charset meta tag after `<head>` on line 157**

Change line 157-158 from:
```html
	<head>
		<title>The Very Little War - Modération</title>
```
to:
```html
	<head>
		<meta charset="UTF-8">
		<title>The Very Little War - Modération</title>
```

**Step 2: Run tests, commit**

```bash
git add moderation/index.php
git commit -m "fix(H-033): add charset UTF-8 declaration to moderation panel"
```

### Task I.3: Fix stale alliance admin grade access (H-027)

**Files:**
- Modify: `allianceadmin.php` (top auth section)

**Why:** When a player quits an alliance, their grades are deleted but they can still access allianceadmin.php if they had a cached session. The file checks `$chef['login'] == $_SESSION['login']` for chef access, but grade-based access has no alliance membership verification.

**Step 1: Read allianceadmin.php top section (first 30 lines) to understand auth flow**

**Step 2: Add alliance membership check**

After the existing auth/grade checks, verify the player actually belongs to an alliance:

```php
// Verify player still belongs to an alliance
$currentAlliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login = ?', 's', $_SESSION['login']);
if (!$currentAlliance || $currentAlliance['idalliance'] == 0) {
    header('Location: alliance.php');
    exit();
}
```

**Step 3: Run tests, commit**

```bash
git add allianceadmin.php
git commit -m "fix(H-027): verify alliance membership before allowing admin access"
```

---

## Sub-batch I.2: Data Integrity (3 tasks)

### Task I.4: Wrap molecule deletion in withTransaction (M-001)

**Files:**
- Modify: `armee.php:5-58`

**Why:** Deleting a molecule class runs 5+ SQL statements (UPDATE niveauclasse, UPDATE molecules, DELETE actionsformation, UPDATE actionsformation reorder, UPDATE actionsattaques). Partial failure leaves orphaned data.

**Step 1: Read armee.php lines 5-58 for the full deletion block**

**Step 2: Wrap the entire deletion block in withTransaction**

```php
if ($molecules['formule'] != "Vide") {
    withTransaction($base, function() use ($base, $molecules, $nomsRes, $nbRes, $nbClasses) {
        $login = $_SESSION['login'];

        // Lock resources first
        $niveauclasse = dbFetchOne($base, 'SELECT niveauclasse FROM ressources WHERE login = ? FOR UPDATE', 's', $login);
        $newNiveauClasse = $niveauclasse['niveauclasse'] - 1;
        dbExecute($base, 'UPDATE ressources SET niveauclasse = ? WHERE login = ?', 'is', $newNiveauClasse, $login);

        // Build column reset string (server-side values only)
        $chaine = "";
        foreach ($nomsRes as $num => $ressource) {
            $plus = ($num < $nbRes) ? "," : "";
            $chaine .= $ressource . '=default' . $plus;
        }

        dbExecute($base, 'UPDATE molecules SET formule = default, ' . $chaine . ', nombre = default WHERE proprietaire = ? AND numeroclasse = ?', 'si', $login, $_POST['emplacementmoleculesupprimer']);
        dbExecute($base, 'DELETE FROM actionsformation WHERE login = ? AND idclasse = ?', 'si', $login, $molecules['id']);

        // Reorder remaining formation actions
        $nvxDebut = time();
        $exActuActions = dbQuery($base, 'SELECT * FROM actionsformation WHERE login = ?', 's', $login);
        while ($actionsformation = mysqli_fetch_array($exActuActions)) {
            if (time() < $actionsformation['debut']) {
                $newFin = $nvxDebut + $actionsformation['nombreRestant'] * $actionsformation['tempsPourUn'];
                dbExecute($base, 'UPDATE actionsformation SET debut = ?, fin = ? WHERE id = ?', 'iii', $nvxDebut, $newFin, $actionsformation['id']);
                $nvxDebut += $actionsformation['nombreRestant'] * $actionsformation['tempsPourUn'];
            } else {
                $nvxDebut = $actionsformation['fin'];
            }
        }

        // Zero out troops of this class in pending attacks
        $ex = dbQuery($base, 'SELECT * FROM actionsattaques WHERE attaquant = ?', 's', $login);
        while ($actionsattaques = mysqli_fetch_array($ex)) {
            $explosion = explode(";", $actionsattaques['troupes']);
            $chaine = "";
            for ($i = 1; $i <= $nbClasses; $i++) {
                if ($i == $_POST['emplacementmoleculesupprimer']) {
                    $chaine .= "0;";
                } else {
                    $chaine .= $explosion[$i - 1] . ";";
                }
            }
            dbExecute($base, 'UPDATE actionsattaques SET troupes = ? WHERE id = ?', 'si', $chaine, $actionsattaques['id']);
        }
    });

    $information = "Vous avez supprimé la classe de molécules.";
}
```

**Step 3: Run tests, commit**

```bash
git add armee.php
git commit -m "fix(M-001): wrap molecule deletion in withTransaction"
```

### Task I.5: Wrap war declaration in withTransaction (M-005)

**Files:**
- Modify: `allianceadmin.php:270-278`

**Why:** War declaration runs 2 DELETEs + 1 INSERT (declarations) + 1 INSERT (rapports) without transaction. Partial failure could delete existing entries without creating the declaration.

**Step 1: Read allianceadmin.php lines 260-285**

**Step 2: Wrap the war block in withTransaction**

```php
if ($nbDeclarations['nbDeclarations'] == 0 and $nbDeclarations1['nbDeclarations'] == 0) {
    withTransaction($base, function() use ($base, $chef, $allianceAdverse) {
        dbExecute($base, 'DELETE FROM declarations WHERE alliance1 = ? AND alliance2 = ? AND fin = 0 AND valide = 0', 'ii', $allianceAdverse['id'], $chef['id']);
        dbExecute($base, 'DELETE FROM declarations WHERE alliance2 = ? AND alliance1 = ? AND fin = 0 AND valide = 0', 'ii', $allianceAdverse['id'], $chef['id']);
        $now = time();
        dbExecute($base, 'INSERT INTO declarations VALUES(default, 0, ?, ?, ?, default, default, default, default, default)', 'iii', $chef['id'], $allianceAdverse['id'], $now);
        $rapportTitre = 'L\'alliance ' . $chef['tag'] . ' vous déclare la guerre.';
        $rapportContenu = 'L\'alliance <a href="alliance.php?id=' . $chef['tag'] . '">' . $chef['tag'] . '</a> vous déclare la guerre.';
        dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default)', 'isss', $now, $rapportTitre, $rapportContenu, $allianceAdverse['chef']);
    });
    $information = "Vous avez déclaré la guerre à l'équipe " . htmlspecialchars($_POST['guerre'], ENT_QUOTES, 'UTF-8') . ".";
}
```

**Step 3: Run tests, commit**

```bash
git add allianceadmin.php
git commit -m "fix(M-005): wrap war declaration in withTransaction"
```

### Task I.6: Fix listesujets.php forum ID lookup race condition (L-019)

**Files:**
- Modify: `listesujets.php:30-34`

**Why:** After INSERT, the code finds the subject ID via `SELECT id FROM sujets WHERE contenu = ?` — if two identical posts are created simultaneously, this returns the wrong ID.

**Step 1: Read listesujets.php lines 30-34**

Current code:
```php
dbExecute($base, 'INSERT INTO sujets VALUES(default, ?, ?, ?, ?, default, ?)', 'isssi', $getId, $_POST['titre'], $_POST['contenu'], $_SESSION['login'], $timestamp);
$sujet = dbFetchOne($base, 'SELECT id FROM sujets WHERE contenu = ?', 's', $_POST['contenu']);
dbExecute($base, 'INSERT INTO statutforum VALUES(?, ?, ?)', 'sii', $_SESSION['login'], $sujet['id'], $getId);
```

**Step 2: Replace with mysqli_insert_id**

```php
dbExecute($base, 'INSERT INTO sujets VALUES(default, ?, ?, ?, ?, default, ?)', 'isssi', $getId, $_POST['titre'], $_POST['contenu'], $_SESSION['login'], $timestamp);
$sujetId = mysqli_insert_id($base);
dbExecute($base, 'INSERT INTO statutforum VALUES(?, ?, ?)', 'sii', $_SESSION['login'], $sujetId, $getId);
```

**Step 3: Run tests, commit**

```bash
git add listesujets.php
git commit -m "fix(L-019): use mysqli_insert_id instead of content-based subject lookup"
```

---

## Sub-batch I.3: Code Cleanup (3 tasks)

### Task I.7: Delete dead PushNotification.js and remove MD5 migration (L-013, L-018)

**Files:**
- Delete: `js/PushNotification.js`
- Modify: `includes/basicprivatephp.php` (remove legacy MD5 session block)

**Step 1: Verify PushNotification.js is not referenced**

```bash
grep -r "PushNotification" --include="*.php" --include="*.html" .
```

If no references, delete it.

**Step 2: Find and remove the MD5 session migration block in basicprivatephp.php**

Search for the legacy fallback code that handles password-hash-based sessions and migrates them to session_token. This code was needed for 30 days after the bcrypt migration — that window has passed.

Look for a block that checks for old-style session authentication (pre-session_token) and remove it. Keep the current session_token-based auth intact.

**Step 3: Run tests, commit**

```bash
git add -A js/PushNotification.js includes/basicprivatephp.php
git commit -m "fix(L-013, L-018): delete dead PushNotification.js, remove legacy MD5 session migration"
```

### Task I.8: Add PRIMARY KEY to connectes table (H-044)

**Files:**
- Create: `migrations/0016_connectes_primary_key.sql`

**Why:** The `connectes` table has no primary key, just an INDEX on ip. This allows duplicate rows and prevents efficient lookups.

**Step 1: Create migration file**

```sql
-- Migration: Add PRIMARY KEY to connectes table
-- First check for and remove duplicates, keeping the latest entry per IP
DELETE c1 FROM connectes c1
INNER JOIN connectes c2
WHERE c1.ip = c2.ip AND c1.timestamp < c2.timestamp;

-- Now add the primary key
ALTER TABLE connectes ADD PRIMARY KEY (ip);
```

**Note:** If the table uses a different structure (check with `DESCRIBE connectes`), adjust the migration accordingly. The ip column should become the primary key since it tracks unique online sessions.

**Step 2: Test migration locally if possible, then commit**

```bash
git add migrations/0016_connectes_primary_key.sql
git commit -m "fix(H-044): add PRIMARY KEY to connectes table"
```

### Task I.9: Remove supprimerAlliance missing attack_cooldowns cleanup (H-020)

**Files:**
- Modify: `includes/player.php` (supprimerAlliance function)

**Why:** When an alliance is deleted, attack_cooldowns for its members are not cleaned up — stale cooldown entries remain.

**Step 1: Read player.php supprimerAlliance function**

Find the function and locate where other tables are cleaned up.

**Step 2: Add DELETE for attack_cooldowns**

Inside the existing transaction, after the other cleanup DELETEs, add:

```php
// Clean up attack cooldowns for former alliance members
$members = dbFetchAll($base, 'SELECT login FROM autre WHERE idalliance = ?', 'i', $idAlliance);
foreach ($members as $member) {
    dbExecute($base, 'DELETE FROM attack_cooldowns WHERE login = ?', 's', $member['login']);
}
```

**Step 3: Run tests, commit**

```bash
git add includes/player.php
git commit -m "fix(H-020): clean up attack_cooldowns when alliance is deleted"
```

---

## Sub-batch I.4: UX Polish (3 tasks)

### Task I.10: Link PP column in classement to prestige.php (M-027)

**Files:**
- Modify: `classement.php:163, 199`

**Why:** The PP column in the ranking table is not clickable or linked — players don't know what it means or where to learn more.

**Step 1: Read classement.php lines 163 and 199**

**Step 2: Make the PP column header link to prestige.php**

Line 163 — change:
```php
<th><img src="images/classement/shield.png" alt="prestige" title="Prestige" class="imageSousMenu"/><br/><span class="labelClassement">PP</span></th>
```
to:
```php
<th><a href="prestige.php"><img src="images/classement/shield.png" alt="prestige" title="Prestige" class="imageSousMenu"/><br/><span class="labelClassement">PP</span></a></th>
```

Line 199 — make PP values clickable:
```php
<td><a href="prestige.php" style="text-decoration:none;color:inherit;"><?php echo isset($prestigeCache[$donnees['login']]) ? $prestigeCache[$donnees['login']] : 0; ?></a></td>
```

**Step 3: Run tests, commit**

```bash
git add classement.php
git commit -m "fix(M-027): link PP column header and values to prestige.php"
```

### Task I.11: Add timestamps to market chart X-axis (M-022)

**Files:**
- Modify: `marche.php` (chart generation section, around line 577-595)

**Why:** Market price chart X-axis labels are empty strings — players can't see when prices changed.

**Step 1: Read marche.php chart section to understand the label generation**

The `cours` table stores price history with timestamps. The chart labels array currently has empty strings.

**Step 2: Add date labels from cours timestamps**

When building the labels array for the chart, format each timestamp:

```php
$labels[] = date('d/m', $row['timestamp']);
```

If the labels variable is built differently, adapt accordingly. The goal is to show short date strings like "03/03" on the X-axis.

**Step 3: Run tests, commit**

```bash
git add marche.php
git commit -m "fix(M-022): add date timestamps to market chart X-axis labels"
```

### Task I.12: Escalate tutorial rewards (M-011)

**Files:**
- Modify: `tutoriel.php:28,46,64,86,104,130,148`

**Why:** All 7 tutorial missions give identical 500 energy reward — no increasing motivation.

**Step 1: Read tutoriel.php to see all 7 mission definitions**

**Step 2: Change rewards to escalate**

Replace all `'recompense_energie' => 500` with escalating values:

```
Mission 1: 'recompense_energie' => 200,   // Basic intro
Mission 2: 'recompense_energie' => 300,   // First research
Mission 3: 'recompense_energie' => 400,   // First molecules
Mission 4: 'recompense_energie' => 500,   // First attack/espionage
Mission 5: 'recompense_energie' => 600,   // Profile/exploration
Mission 6: 'recompense_energie' => 800,   // Alliance
Mission 7: 'recompense_energie' => 1000,  // Advanced (all tutorials done)
```

Total: 3800 (was 3500 — slightly higher to reward completion).

**Step 3: Run tests**

Check that any tests referencing tutorial rewards are updated. Search:
```bash
grep -r "recompense_energie\|500" tests/ --include="*.php"
```

**Step 4: Commit**

```bash
git add tutoriel.php
git commit -m "fix(M-011): escalate tutorial rewards from flat 500 to 200-1000 progression"
```

---

## Deployment

### Task I.13: Deploy Batch I

After all tasks pass tests:

```bash
git push origin main
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "cd /var/www/html && git pull origin main"
```

If Task I.8 migration was created, run it on VPS:

```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "mysql -u tvlw -pmLLIoTy2ByGTBNb9RQpTGnqPhXQfUR tvlw < /var/www/html/migrations/0016_connectes_primary_key.sql"
```

---

## Execution Order

```
Sub-batch I.1 (security)    → Tasks I.1-I.3   → 20 min
Sub-batch I.2 (data)        → Tasks I.4-I.6   → 20 min
Sub-batch I.3 (cleanup)     → Tasks I.7-I.9   → 15 min
Sub-batch I.4 (UX)          → Tasks I.10-I.12 → 20 min
Deploy                      → Task I.13       → 5 min
```

**Total: ~80 minutes**

---

## Not Included (deferred or already covered)

Items verified as already fixed: M-002, M-006, M-007, M-009, M-013, M-016, M-019, M-020, M-025, M-026, M-036, M-038, M-046, M-047, M-048, M-050, M-051, M-053, M-056, M-059

Items deferred:
- **C-004/M-055** (CSP unsafe-inline): Batch H, 4-6h effort
- **C-006/C-007** (HSTS/cookies): Batch F, blocked on DNS
- **M-029** (Season countdown): Medium UX effort, deferred to future
- **M-003/M-004/M-008**: Single-statement operations, already atomic at SQL level
- **L-001 through L-016**: Minor polish items, no security impact
- **M-022**: Market chart (included above)
- **QoL-001 through QoL-020**: Feature ideas, not bugs
