# Ultra Audit Pass 26 — Remediation Plan
**Date:** 2026-03-09
**Next migration:** `0112`
**Agents:** 11 (Batch A: Auth+InfraSec+InfraDB+AntiCheat+Admin+Season+Forum, partial Batch B: Combat+Actions+Player+Market)

---

## Summary of Findings

| Batch | Theme | CRITICAL | HIGH | MEDIUM |
|-------|-------|----------|------|--------|
| A | CRITICAL: Action formation, Admin transactions, Auth ban race, DB savepoints, Market closure | 7 | 12 | 8 |
| B | MEDIUM: Combat math, return trip, forum nbMessages | 2 | 8 | 6 |

---

## Batch A — CRITICAL fixes

### A-1: ACTIONS-P26-001 — Formation $formed can go negative on clock skew
**File:** includes/game_actions.php ~line 77
```php
// Change:
$formed = (int)floor((time() - $derniereFormation) / $actions['tempsPourUn']);
// To:
$formed = max(0, (int)floor((time() - $derniereFormation) / $actions['tempsPourUn']));
```

### A-2: ADMIN-P26-001/002 — Topic lock not transactional
**Files:** admin/listesujets.php ~line 63-66, moderation/index.php ~line 121-124
Wrap UPDATE sujets + DELETE FROM statutforum in withTransaction() in both files.

### A-3: ADMIN-P26-003 — Undefined $_SESSION['login'] in admin audit log
**File:** admin/supprimercompte.php ~line 36
```php
// Change:
logInfo('ADMIN', '...', ['target' => $loginToDelete, 'admin' => $_SESSION['login']]);
// To:
logInfo('ADMIN', '...', ['target' => $loginToDelete, 'admin_ip' => $_SESSION['admin_ip'] ?? 'unknown']);
```

### A-4: AUTH-P26-001 — Account deletion cooldown bypassed by ban race
**File:** compte.php inside POST handler
Re-check estExclu before processing deletion (inside the isset($_POST['verification']) block).

### A-5: INFRADB-P26-001/002 — RELEASE/ROLLBACK savepoint return not checked
**File:** includes/database.php ~lines 164, 179
Add return value checks after mysqli_query() on RELEASE SAVEPOINT and ROLLBACK TO SAVEPOINT.

### A-6: MARKET-P26-001 — $membre, $revenu undefined in market closure
**File:** marche.php ~line 108
Initialize $membre and $revenu before the closure (fetch player coords + revenue values).

---

## Batch B — HIGH fixes

### B-1: ACTIONS-P26-002/003 — Return trip: no numeric guard, no GREATEST floor
**File:** includes/game_actions.php ~line 690, 692
```php
// Add is_numeric guard before line 690
// Add max(0, ...) around nombre + round($moleculesRestantes)
```

### B-2: ANTICHEAT-P26-007 — areFlaggedAccounts() fails open on DB error
**File:** includes/multiaccount.php ~line 331
Change `return false;` → `return true;` (fail-closed).

### B-3: FORUM-P26-001 — NULL auteur skips nbMessages decrement
**File:** editer.php ~line 72
```php
if ($auteur !== null && !is_null($auteur['auteur'])) {
    dbExecute($base, 'UPDATE autre SET nbMessages = nbMessages - 1 WHERE login = ?', 's', $auteur['auteur']);
}
```

### B-4: AUTH-P26-006/007/008 — logLoginEvent not called in visitor create/convert
**File:** comptetest.php ~lines 63-70 and ~lines 165-172
Add logLoginEvent() calls after session setup in both paths.

### B-5: SEASON-P26-003 — queueSeasonEndEmails() has no idempotency guard
**File:** includes/player.php ~line 1387-1422
Add emails_queued_season check in statistiques (or use email_queue dedup by login+season).
**Migration:** 0112_statistiques_emails_queued_season.sql

### B-6: COMBAT-P26-017 — Tautological whitelist check (always true)
**File:** includes/combat.php ~line 839
```php
// Change:
if (!in_array($ressource, $nomsRes, true)) {
// To:
$allowedResources = ['carbone', 'azote', 'hydrogene', 'oxygene', 'chlore', 'soufre', 'brome', 'iode'];
if (!in_array($ressource, $allowedResources, true)) {
```

### B-7: SEASON-P26-004 — VP award flags reset before awards complete
**File:** includes/player.php ~lines 1258-1264
Remove the pre-reset of vp_awarded/season_vp_awarded; rely on per-transaction guards only.

---

## Batch C — MEDIUM fixes

### C-1: FORUM-P26-002 — URL-encoded path traversal in [img] BBcode
**File:** includes/bbcode.php ~line 47
```php
$url = urldecode($url); // decode before traversal check
if (strpos($url, '..') !== false || strpos($url, '//') !== false || strpos($url, '%') !== false) {
```

### C-2: ADMIN-P26-004/005/006 — Unlock operations missing transaction + statutforum cleanup
**Files:** admin/listesujets.php (unlock), moderation/index.php (unlock)
Wrap unlock in withTransaction() and add DELETE FROM statutforum.

### C-3: SEASON-P26-006 — NULL prestige_awarded_season not guarded
**File:** includes/prestige.php ~line 158
```php
if ((int)($statsRow['prestige_awarded_season'] ?? 0) >= $currentSeason) {
```

### C-4: COMBAT-P26-002 — Dispersée overkill can go slightly negative
**File:** includes/combat.php ~line 322
```php
$disperseeOverkill = max(0.0, $disperseeOverkill - $disperseeOverkill / $spreadDenominator);
```

### C-5: INFRADB-P26-007 — Redundant index on news table PK
**Migration:** 0113_news_drop_redundant_index.sql
```sql
ALTER TABLE news DROP INDEX IF EXISTS idx_id_desc;
```

### C-6: SEASON-P26-008 — alliance_name empty string instead of NULL
**File:** includes/player.php ~line 1128
Pass `$p['alliance_name'] ?? null` to INSERT to preserve NULL semantics.

---

## Migrations Required

| # | File | Task |
|---|------|------|
| 0112 | `0112_statistiques_emails_queued_season.sql` | B-5: emails_queued_season INT DEFAULT 0 |
| 0113 | `0113_news_drop_redundant_index.sql` | C-5: drop redundant idx_id_desc on news |
