# Pass 6 UX/Frontend Audit

## Findings

---

### UX-P6-001 [HIGH] Invalid CSS `rgba()` in classement.php daily-view rows

**File:** `classement.php:147` and `classement.php:689`

**Description:**
In the daily-view player ranking (`$mode === 'daily'`) and the forum ranking sub-tab, the `$enGuerre` variable is initialized to `""` and may remain empty for every non-self row. The template then emits:

```html
<tr style="background-color: rgba(,0.6);">
```

This is invalid CSS. All major browsers silently drop the entire `style` attribute, so the highlighting that's supposed to visually identify "self" rows just fails silently. Contrast this with the total-mode loop at line 289 and the alliance-tab loop at line 478, which use a safe `if(isset($enGuerre)) { echo $enGuerre.",0.6)"; }` guard — those are correct.

**Code (daily view, line 147):**
```php
<tr style="background-color: rgba(<?php echo $enGuerre; ?>,0.6);">
```
**Code (forum tab, line 689):**
```php
<tr style="background-color:rgba(<?php echo $enGuerre; ?>,0.6)">
```

**Fix:** Mirror the correct pattern from lines 289/478:
```php
<tr style="<?php if ($enGuerre !== '') { echo 'background-color:rgba(' . $enGuerre . ',0.6);'; } ?>">
```

---

### UX-P6-002 [HIGH] `joueur.php` renders blank page when accessed without `?id` parameter

**File:** `joueur.php:16`

**Description:**
The entire page body is wrapped in `if (isset($_GET['id']))` with no `else` branch. Navigating to `joueur.php` without an `?id` query parameter outputs an HTML skeleton with no card content between the layout header and the copyright footer — a confusing blank page. There is no redirect, no error message, and no fallback to show the logged-in user's own profile.

**Code:**
```php
if (isset($_GET['id'])) {
    // ... all rendering ...
}
include("includes/copyright.php"); ?>
```

**Fix:** Add an else branch after line 115 that either redirects to the logged-in player's own profile (`header('Location: joueur.php?id='.urlencode($_SESSION['login']));`) or shows an error card:
```php
} else {
    debutCarte("Erreur");
    debutContent();
    echo "Aucun joueur spécifié.";
    finContent();
    finCarte();
}
```

---

### UX-P6-003 [MEDIUM] CSRF token consumed on every GET request to `compte.php`

**File:** `compte.php:6`

**Description:**
`csrfCheck()` is called unconditionally at the top of the file, before any `if (isset($_POST[...]))` guard. Inside `csrfCheck()` (csrf.php:30), it only validates when `$_SERVER['REQUEST_METHOD'] === 'POST'`, so it won't reject GET requests. However, `csrfVerify()` (csrf.php:23) rotates the token on every successful POST verification. The true problem here is that multiple forms on the page (change password, change email, vacation, delete account) each submit to `compte.php`, but only one token is generated per session. A user who opens the page, submits one form (rotating the token server-side), then tries to submit a second form in the same tab using the now-stale token embedded in the page HTML will receive a CSRF error and a confusing redirect.

**Code (csrf.php:23):**
```php
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
```
This rotation happens after every successful POST, invalidating all other forms still visible in the browser.

**Fix:** Either (a) remove token rotation on success (accept replay risk for the short window), or (b) use per-form tokens keyed by form name, or (c) add a JS snippet that fetches a fresh token before each form submission. The simplest safe option matching the existing framework is to not rotate within a page that has multiple independent forms.

---

### UX-P6-004 [MEDIUM] Prestige streak progress bar uses Framework7 `data-progress` attribute but Framework7 is initialized after the HTML

**File:** `prestige.php:71`

**Description:**
The streak progress bar is rendered as:
```html
<div class="progressbar" data-progress="<?= min(100, round($currentStreak / $nextMilestone * 100)) ?>">
    <span></span>
</div>
```
Framework7's progressbar component reads `data-progress` at initialization time (inside the `new Framework7({...})` call in `includes/copyright.php`). This card is rendered inside `debutCarte()` which is server-side PHP output, so the DOM is present when F7 initializes. However, this progressbar does NOT appear inside a Framework7 Page — it is directly inside a raw `<div class="card">` (lines 65-84), not inside the standard `debutCarte()` wrapper. Framework7's progressbar auto-initialization scans pages; raw divs outside the page structure may not be scanned. The bar will visually appear as a flat empty bar with no fill.

**Code (prestige.php:71):**
```php
<div class="progressbar" data-progress="<?= min(100, round($currentStreak / $nextMilestone * 100)) ?>">
```

**Fix:** Use inline CSS `width` on the inner `<span>` to set the fill directly, which does not depend on F7 JS initialization:
```php
<div class="progressbar" style="height:8px;background:#eee;border-radius:4px;">
    <span style="display:block;height:100%;width:<?= min(100, round($currentStreak / $nextMilestone * 100)) ?>%;background:#4caf50;border-radius:4px;"></span>
</div>
```

---

### UX-P6-005 [MEDIUM] Daily leaderboard missing sortable column headers and Commerce/Victory columns

**File:** `classement.php:121–133`

**Description:**
The daily-mode leaderboard table has only 8 columns (Rang, Joueur, Points, Equipe, Attaque, Défense, Pillage, PP) while the total-mode table has 11 columns (adds Constructions, Commerce, Victoire). The daily mode does not include Commerce (`tradeVolume`) or Victoire (`victoires`) columns even though those statistics are fetched in the query (line 98-105). This inconsistency means players cannot compare the same statistics across both views, leading to confusion. Additionally, the daily view column headers have no sort links unlike the total view.

**Code (classement.php:94–105 query does fetch tradeVolume and victoires, but line 121-131 headers omit them):**
```sql
SELECT a.login, a.totalPoints, a.pointsAttaque, a.pointsDefense, a.ressourcesPillees, a.tradeVolume, a.victoires ...
```
But the table only renders: Rang, Joueur, Points, Equipe, Attaque, Défense, Pillage, PP.

**Fix:** Add Commerce and Victoire columns to the daily view table header and each `<tr>` in the daily rendering loop (lines 147-157), matching the total-view structure.

---

### UX-P6-006 [MEDIUM] `attaquer.php` cost display uses `innerHTML` instead of `textContent` for energy cost

**File:** `attaquer.php:594`

**Description:**
The attack cost live-updater writes numeric output via `innerHTML`:
```javascript
document.getElementById("coutEnergie").innerHTML = nFormatter(cout);
```
The other stat updaters on the same page (tempsAttaque at line 575, etc.) correctly use `textContent`. `nFormatter` returns only numeric strings with SI suffixes (e.g. `"1.5K"`) so XSS is not possible in this specific case, but it is an inconsistency and a bad pattern — if `nFormatter` were ever modified to include HTML, this would become a vector.

**Fix:** Change to `textContent`:
```javascript
document.getElementById("coutEnergie").textContent = nFormatter(cout);
```

---

### UX-P6-007 [MEDIUM] Countdown timer in navbar (layout.php) is loaded on every page but the JS `countdown.js` requires a DOM element that only exists on pages with `$debut` set

**File:** `includes/layout.php:55–76` and `includes/copyright.php:20`

**Description:**
`countdown.js` is included unconditionally in every page footer (copyright.php line 20). The script looks for `#season-countdown` (homepage only) and `#season-countdown-navbar`. The navbar countdown span is only rendered when `isset($_SESSION['login']) && isset($debut) && !empty($debut['debut'])` is true (layout.php:55). For public pages (index.php without login, inscription.php, classement.php public view), neither element exists. The JS handles this gracefully (`if (elements.length === 0) return;`) so it does not crash.

However, the navbar countdown span shows `$daysLeft . 'j'` (e.g. `"28j"`) on initial PHP render, then the JS updates it to `"28j 5h 30m"` format (more specific). There's a mismatch: the server-side initial value only shows days, while the JS shows days + hours + minutes. On a fast connection this flicker is imperceptible, but on a slow connection the user briefly sees the incomplete "28j" before it updates.

**Code (layout.php:74):**
```php
title="Fin de manche"><?php echo $daysLeft; ?>j</span>
```

**Fix:** Pre-render the full countdown server-side, matching the JS format:
```php
<?php
$daysLeft = floor($secondsLeft / SECONDS_PER_DAY);
$hoursLeft = floor(($secondsLeft % SECONDS_PER_DAY) / SECONDS_PER_HOUR);
$minutesLeft = floor(($secondsLeft % 3600) / 60);
echo $daysLeft . 'j ' . $hoursLeft . 'h ' . $minutesLeft . 'm';
?>
```

---

### UX-P6-008 [LOW] `season_recap.php` renders unaccented French text (ASCII substitutions remain)

**File:** `season_recap.php:15, 45-50`

**Description:**
Several user-visible strings in the season recap page use ASCII substitutions that were likely copy-paste from during the no-accent phase:

- Line 15: `"Aucun historique disponible. Les donnees seront archivees a la fin de chaque saison."` — "donnees", "archivees" should be "données", "archivées"
- Line 45: `"Points de defense"` should be "défense"
- Line 47: `"Volume d'echange"` should be "d'échange"
- Line 49: `"Ressources pillees"` should be "pillées"
- Line 50: `"Combats menes"` should be "menés"

**Code (line 45):**
```php
echo '<tr><td><strong>Points de defense</strong></td><td>' ...
```

**Fix:** Use proper UTF-8 accented characters throughout (the file is already UTF-8 as declared by the CSP/meta charset).

---

### UX-P6-009 [LOW] `symboleEnNombre` JS function has a dead-code bug (`.replace()` result is discarded)

**File:** `includes/copyright.php:160`

**Description:**
The `symboleEnNombre` function used for the market trade conversion has a silent bug:
```javascript
chaine.replace(si[j].symbol, si[j].value);  // result discarded
chaine = parseFloat(chaine) * si[j].value;   // uses unmodified chaine
```
`String.replace()` is not in-place — it returns the new string, which is discarded. The subsequent `parseFloat(chaine)` then operates on the original string. For input like `"1.5K"`, `parseFloat("1.5K")` returns `1.5` (JavaScript stops at the non-numeric character), then `1.5 * 1000 = 1500` — which accidentally gives the correct result. But for inputs like `"1K5"` or other edge cases, it would silently give wrong values.

**Code (copyright.php:160):**
```javascript
chaine.replace(si[j].symbol,si[j].value);
chaine = parseFloat(chaine)*si[j].value;
```

**Fix:**
```javascript
chaine = parseFloat(chaine) * si[j].value;
break; // exit inner loop after match
```
The `replace` line can be removed entirely since `parseFloat` already handles the numeric prefix extraction correctly.

---

### UX-P6-010 [LOW] `cardsprivate.php` tutorial step 7 hardcodes "deux jours" but beginner protection is config-driven

**File:** `includes/cardsprivate.php:83`

**Description:**
The tutorial card for step 7 (Carte) displays:
```
"vous êtes encore sous protection débutant pour deux jours"
```
The actual beginner protection duration is `BEGINNER_PROTECTION_SECONDS` (from config.php), which may not equal 2 days. If the constant is ever changed (e.g. to 5 days for a rebalance), the tutorial message will be incorrect. The actual protection duration is already shown dynamically in `attaquer.php:282` using the constant.

**Code (cardsprivate.php:83):**
```php
'Malheureusement, vous êtes encore sous <strong>protection débutant</strong> pour <strong>deux jours</strong>'
```

**Fix:**
```php
'Malheureusement, vous êtes encore sous <strong>protection débutant</strong> pendant <strong>' . round(BEGINNER_PROTECTION_SECONDS / SECONDS_PER_DAY) . ' jours</strong>'
```

---

### UX-P6-011 [INFO] `affichageTemps()` returns negative values when called with elapsed timestamps

**File:** `includes/display.php:124`

**Description:**
The PHP `affichageTemps()` function does not guard against negative input. When called with `$actionsconstruction['fin'] - time()` (constructions.php line 369), if `fin` is in the past (construction overdue, queued behind another), it can return a string like `-1:-05:30`. The JS countdown function on the same page does guard against negative countdown values (it redirects at 0), but the initial server-side render can show a negative time for a brief moment, confusing users.

**Code (constructions.php:369):**
```php
'<span id="affichage' . $actionsconstruction['id'] . '">' . affichageTemps($actionsconstruction['fin'] - time()) . '</span>'
```

**Fix:** Wrap in `max(0, ...)`:
```php
affichageTemps(max(0, $actionsconstruction['fin'] - time()))
```
And apply the same guard in `attaquer.php` for travel time display (line 303, 305, 325).

---

### UX-P6-012 [INFO] Forum ranking tab (sub=3) uses `$compteur` for rank instead of DENSE_RANK

**File:** `classement.php:690`

**Description:**
Player ranking sub-tabs 0, 1, and 2 all correctly use `DENSE_RANK() OVER (ORDER BY ...)` from SQL so tied players share the same position. The forum ranking sub-tab (sub=3) uses a PHP `$compteur` variable that simply increments by 1 per row (line 699: `$compteur++`), meaning tied players (e.g. two players with exactly 0 messages) get consecutive ranks instead of sharing rank. This is minor because forum rankings are rarely tied at high message counts, but it violates consistency.

**Code (classement.php:699):**
```php
$compteur++;
```

**Fix:** Either use `DENSE_RANK()` in the SQL query for the forum tab as well, or compute rank from the score data after fetching.

---

## Summary

Total: **12 findings**

| Severity | Count | IDs |
|----------|-------|-----|
| HIGH     | 2     | UX-P6-001, UX-P6-002 |
| MEDIUM   | 4     | UX-P6-003, UX-P6-004, UX-P6-005, UX-P6-006 |
| LOW      | 3     | UX-P6-008, UX-P6-009, UX-P6-010 |
| INFO     | 2     | UX-P6-011, UX-P6-012 |
| (renumbered) | 1 | UX-P6-007 → MEDIUM |

**Revised breakdown (including UX-P6-007):**

| Severity | Count |
|----------|-------|
| HIGH     | 2     |
| MEDIUM   | 5     |
| LOW      | 3     |
| INFO     | 2     |
| **Total** | **12** |

### Priority fix order:
1. **UX-P6-001** — invalid CSS `rgba(,0.6)` breaks row highlighting in daily leaderboard and forum tab
2. **UX-P6-002** — blank page when navigating to `joueur.php` without `?id`
3. **UX-P6-003** — CSRF token rotation breaks multi-form page (compte.php)
4. **UX-P6-004** — Prestige streak progress bar may not render correctly (outside F7 page context)
5. **UX-P6-005** — Daily leaderboard missing Commerce and Victory columns vs. total view
6. **UX-P6-007** — Navbar countdown shows only "Xj" on initial load, then flickers to "Xj Yh Zm"
