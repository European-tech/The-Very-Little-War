# Pass 7 Input Validation Audit

**Date:** 2026-03-08
**Scope:** Input validation completeness and file upload security across all POST handlers.
**Already-known clean:** validation.php, avatar upload (bin2hex random filename, finfo MIME, extension whitelist, getimagesize, size cap).

---

## Findings

### INP-P7-001 [LOW] — Admin news title/content have no length cap
**File:** `admin/listenews.php` lines 47–59

**Proof:**
```php
if (isset($_POST['titre']) and isset($_POST['contenu'])) {
    $titre = $_POST['titre'];
    $contenu = $_POST['contenu'];
    $id_news = (int)$_POST['id_news'];
    // INSERT/UPDATE with raw $titre and $contenu — no mb_strlen() check
```
The `$titre` and `$contenu` are inserted into `news.titre` and `news.contenu` without any length validation. A moderator (or anyone with the admin password) could store a multi-megabyte news entry, potentially causing display/performance issues.

**Severity rationale:** Admin-only page, so exploitability requires the admin password. No SQL injection risk (prepared statements used). DB column type likely TEXT, so no truncation crash. Risk is storage abuse and display breakage, not code execution.

**Fix:** Add before the INSERT/UPDATE:
```php
if (mb_strlen($titre) > 200) { $erreur = "Titre trop long (200 max)."; }
elseif (mb_strlen($contenu) > 10000) { $erreur = "Contenu trop long (10 000 max)."; }
```

---

### INP-P7-002 [LOW] — Moderation ban motif has no length cap
**File:** `moderationForum.php` lines 28–48

**Proof:**
```php
if (isset($_POST['pseudo'], $_POST['dateFin'], $_POST['motif']) && !isset($_POST['supprimer'])) {
    csrfCheck();
    // ... validates pseudo and date ...
    dbExecute($base, 'INSERT INTO sanctions VALUES (default, ?, CURRENT_DATE, ?, ?, ?)',
              'ssss', $_POST['pseudo'], $date, $_POST['motif'], $_SESSION['login']);
```
`$_POST['motif']` is inserted into `sanctions.motif` (a BBCode text field) without any length check. A moderator can store an arbitrarily large motif, causing database storage bloat and potential rendering issues when displayed in the sanctions table.

**Severity rationale:** Moderator-only page (checked at line 7). No injection risk. Risk is storage abuse.

**Fix:** Add before INSERT:
```php
if (mb_strlen($_POST['motif']) > FORUM_POST_MAX_LENGTH) {
    $erreur = "Le motif est trop long (" . FORUM_POST_MAX_LENGTH . " caractères max).";
}
```

---

### INP-P7-003 [LOW] — moderation/index.php `joueurBombe` login not format-validated
**File:** `moderation/index.php` lines 59–68

**Proof:**
```php
if (isset($_POST['joueurBombe'])) {
    $nb = dbCount($base, 'SELECT count(login) AS nb FROM membre WHERE login = ?', 's', $_POST['joueurBombe']);
    if ($nb > 0) {
        $joueur = dbFetchOne($base, 'SELECT bombe FROM autre WHERE login = ?', 's', $_POST['joueurBombe']);
        dbExecute($base, 'UPDATE autre SET bombe = ? WHERE login = ?', 'is', ($joueur['bombe'] + 1), $_POST['joueurBombe']);
```
The `joueurBombe` field is used in prepared statements (no SQL injection risk), but there is no `validateLogin()` call or length cap. An admin could submit a 65 535-byte string as the login, forcing an unnecessary full-table scan. The existence check (`$nb > 0`) prevents applying the bombe to a non-existent player, but the unvalidated string is still passed to the query.

**Severity rationale:** Admin-only page. No real exploit vector. Minor defensive hardening gap.

**Fix:** Add before the `dbCount`:
```php
$joueurBombeInput = trim($_POST['joueurBombe'] ?? '');
if (!validateLogin($joueurBombeInput)) {
    $erreur = "Login invalide.";
} else {
    // existing logic using $joueurBombeInput
}
```

---

### INP-P7-004 [INFO] — `constructions.php` producteur/condenseur point allocation: unbounded positive integer sum
**File:** `constructions.php` lines 11–46

**Proof:**
```php
foreach ($nomsRes as $num => $ressource) {
    $_POST['nbPoints' . $ressource] = intval($_POST['nbPoints' . $ressource]);
    if ($_POST['nbPoints' . $ressource] < 0) { $bool = false; }
    else { $somme = $somme + $_POST['nbPoints' . $ressource]; }
}
// Then: if ($somme > $locked['pointsProducteurRestants']) throw ...
```
Each per-resource point value is `intval()` cast and checked for negativity. The sum is authoritatively checked against `pointsProducteurRestants` inside a `FOR UPDATE` transaction. Correct — no bypass possible.

**Result:** No issue. Recorded for completeness.

---

### INP-P7-005 [INFO] — File upload: old avatar not deleted on replacement
**File:** `compte.php` lines 161–168

**Proof:**
```php
$fichier = 'avatar_' . bin2hex(random_bytes(16)) . '.' . $extension;
move_uploaded_file($_FILES['photo']['tmp_name'], $dossier . $fichier);
dbExecute($base, 'UPDATE autre SET image = ? WHERE login = ?', 'ss', $fichier, $_SESSION['login']);
```
When a player uploads a new avatar, the old file in `images/profil/` is not deleted. Over time, orphaned avatar files accumulate on disk.

**Severity:** INFO — no security impact. Disk accumulation only.

**Fix:** Before `move_uploaded_file`, fetch and delete the previous image:
```php
$oldImg = dbFetchOne($base, 'SELECT image FROM autre WHERE login = ?', 's', $_SESSION['login']);
if ($oldImg && $oldImg['image'] && $oldImg['image'] !== 'defaut.png') {
    @unlink($dossier . $oldImg['image']);
}
```

---

## Summary

| ID           | Severity | File                         | Issue                                   |
|--------------|----------|------------------------------|-----------------------------------------|
| INP-P7-001   | LOW      | admin/listenews.php:47-59    | News title/content no length limit      |
| INP-P7-002   | LOW      | moderationForum.php:28-48    | Ban motif no length limit               |
| INP-P7-003   | LOW      | moderation/index.php:59-68   | joueurBombe not format-validated        |
| INP-P7-004   | INFO     | constructions.php:11-46      | (CLEAN) Point sum guarded by FOR UPDATE |
| INP-P7-005   | INFO     | compte.php:161-168            | Old avatar not deleted on replacement   |

**Critical / High / Medium findings: 0**

All numeric inputs across the game (market, army, attack, donation, constructions, marche.php transfers) use `intval()`/`(int)` casts, bounds checks, and server-side `FOR UPDATE` transaction re-validation. No integer overflow paths, negative injection, or array injection via POST were found. No path traversal in file operations — avatar filename is always a randomized hex string. Admin resource grants in `moderation/index.php` are capped at hardcoded `$maxGrant = 1000000` and `$maxAtomGrant = 50000`, consistent with `ADMIN_RESOURCE_GRANT_MAX` in config.php.

The three LOW findings are all in admin/moderator-only pages and require privileged credentials to exploit; none allow privilege escalation or game state corruption.
