# Pass 7 UI Rendering Audit

**Date:** 2026-03-08
**Scope:** UI rendering correctness, navigation, page state, JS errors, mobile layout, meta/head

---

## Findings

### UI-P7-001 [MEDIUM]

**File:** `classement.php` lines 289, 478
**Title:** Invalid CSS emitted for non-highlighted rows (`isset` vs `!== ''` guard)

**Proof:**
`$enGuerre` is initialised to `""` for every row. When no highlight applies the render is:

```php
<tr style="background-color: rgba(<?php if(isset($enGuerre)) { echo $enGuerre.",0.6)"; }?>;">
```

Because `isset("")` is `true`, this always enters the branch and outputs:

```html
<tr style="background-color: rgba(,0.6);">
```

That is invalid CSS. Browsers silently discard the rule, so visually there is no impact, but the HTML source contains junk markup on every non-highlighted row. The daily-leaderboard table at line 147 already uses the correct pattern `if ($enGuerre !== '') { … }`. The main player table (line 289) and the alliance table (line 478) have not been updated to match.

**Fix:** Replace `isset($enGuerre)` with `$enGuerre !== ''` on lines 289 and 478, matching the existing correct pattern on line 147.

---

### UI-P7-002 [MEDIUM]

**File:** `classement.php` lines 41-47, 168-182, 195-198
**Title:** `$pageParDefaut` undefined when searched player exists but has x = -1000 (off-map)

**Proof:**
The search path sets `$pasTrouve = 1` (line 48) and may set `$pageParDefaut` (line 46) — but only when `$playerScore` is truthy. `$playerScore` queries on `m.x != -1000`, so for a newly-registered player still at position x=-1000 the query returns no row, `$playerScore` is `false`, line 46 is skipped, and `$pageParDefaut` is never assigned.

Later at line 168-170 the `if ($pasTrouve == 1)` branch is intentionally empty (no assignment). Then at line 195:

```php
$page = isset($_GET['page']) ? intval($_GET['page']) : $pageParDefaut;
```

`$pageParDefaut` is undefined. PHP 8 emits a warning and treats the value as `null` (→ 0). The guard on line 196 `if ($page < 1 || $page > $nombreDePages)` becomes `0 < 1` = true, and resets `$page = $pageParDefaut` which is still undefined (still 0). `$premierJoueurAafficher = (0 - 1) * $nombreDeJoueursParPage = negative`. The LIMIT with a negative offset causes a DB error or returns 0 rows with a warning.

**Fix:** After the outer search `if/else` block (line 54), or inside the `if ($pasTrouve == 1)` empty branch, add `$pageParDefaut = $pageParDefaut ?? 1;` as a fallback. Alternatively change line 195 to `$page = isset($_GET['page']) ? intval($_GET['page']) : ($pageParDefaut ?? 1);`.

---

### UI-P7-003 [LOW]

**File:** `classement.php` lines 192-198, `rapports.php` line 88, and four more pagination sites
**Title:** Pagination shows "**1**" when there are zero records (`$nombreDePages = 0`)

**Proof:**
When a table is empty `ceil(0 / N) = 0` so `$nombreDePages = 0`. The guard:

```php
if ($page < 1 || $page > $nombreDePages) { $page = $pageParDefaut; }
```

evaluates as `1 > 0 = true`, falls back to `$pageParDefaut = 1`. The pagination template then renders:

```html
   <strong>1</strong>
```

There is no "0 pages" or empty-state guard, so users see a page indicator for a page that does not exist. This is cosmetic for now but confusing for beta testers.

Affected files:
- `classement.php` sub=0 (players), sub=1 (alliances), sub=2 (wars), sub=3 (forum)
- `rapports.php` list view

**Fix:** Either skip rendering the pagination line entirely when `$nombreDePages <= 1`, or add an explicit empty-state message upstream (already handled in most spots, just not the pagination counter itself).

---

### UI-P7-004 [LOW]

**File:** `includes/meta.php` line 1 and line 5
**Title:** Duplicate charset declaration

**Proof:**
```html
<meta charset="utf-8">                                       <!-- line 1 -->
<meta http-equiv="content-type" content="text/html; charset=utf-8" />   <!-- line 5 -->
```

Both declare UTF-8. The `http-equiv content-type` form is the HTML 4 legacy form; the `<meta charset>` is the HTML5 form. Having both is harmless but redundant and slightly inflates every page's `<head>`. The `http-equiv` form is also less efficiently parsed by modern browsers.

**Fix:** Remove the `http-equiv` line (line 5); the `<meta charset="utf-8">` on line 1 is sufficient for HTML5.

---

### UI-P7-005 [LOW]

**File:** `includes/basicprivatehtml.php` line 514
**Title:** Inline `<script nonce=...>` tag uses a comment inside the open tag

**Proof:**
```php
<script nonce="..."> // affichage des variables en temps reel
```

The JavaScript comment `// affichage des variables en temps reel` is placed on the same line as the opening `<script>` tag but after the `>`. This is valid HTML/JS and has no functional impact. However it differs from every other `<script nonce=...>` block in the codebase (which open cleanly) and could cause confusion for automated linters.

**Fix:** Move the comment to its own line inside the `<script>` block.

---

### UI-P7-006 [LOW]

**File:** Multiple JS timer scripts — `attaquer.php` lines 315, 332, 351, 575, 606; `marche.php` line 527; `armee.php` lines 330, 335, 339; `attaque.php` lines 32, 51; `constructions.php` line 373
**Title:** `innerHTML` used to write countdown text (instead of `textContent`)

**Proof:**
All countdown update functions set time-remaining text via `innerHTML`:

```js
document.getElementById("affichage" + id).innerHTML = affichageTemps(valeur);
```

The JS `affichageTemps()` function returns only digit/colon strings (e.g., `"2:30:00"`), so there is no XSS risk. However:
1. Using `innerHTML` for plain text is inconsistent with the refactoring already done in `layout.php` (which uses `textContent` after the Pass 5 audit).
2. Any future change that makes `affichageTemps` return HTML (e.g., bold formatting) would open an XSS vector.

**Fix:** Replace `innerHTML = affichageTemps(...)` with `textContent = affichageTemps(...)` in all timer-update callbacks. The `affichageTemps` JS function returns no HTML, so `textContent` is a safe drop-in.

---

### UI-P7-007 [INFO]

**File:** Most pages (`joueur.php`, `forum.php`, `guerre.php`, `historique.php`, `connectes.php`, `regles.php`, etc.)
**Title:** No `$pageTitle` set — browser tab shows generic "The Very Little War"

**Proof:**
Only five files explicitly set `$pageTitle` before including `layout.php`:
- `classement.php`, `marche.php`, `prestige.php`, `allianceadmin.php`, `bilan.php`

All other pages fall through to the default `'The Very Little War'` in `meta.php` line 19:
```php
<title><?= htmlspecialchars($pageTitle ?? 'The Very Little War', ENT_QUOTES, 'UTF-8') ?></title>
```

Browser tabs, bookmarks, and screen-reader announcements all show the same generic title for every page. This is not a crash or security issue, but degrades SEO and usability.

**Fix:** Add a short `$pageTitle = 'Page Name — The Very Little War';` before each `include("includes/layout.php")` in the remaining ~35 pages. Priority: joueur.php, forum.php, classement.php (already done), armee.php, attaquer.php, alliance.php.

---

### UI-P7-008 [INFO]

**File:** `includes/style.php` lines 87-90
**Title:** Vendor-prefixed gradient fallbacks for obsolete browsers still in stylesheet

**Proof:**
```css
background-image: -webkit-linear-gradient(left, #f0f0f0, #8c8b8b, #f0f0f0);
background-image: -moz-linear-gradient(left, #f0f0f0, #8c8b8b, #f0f0f0);
background-image: -ms-linear-gradient(left, #f0f0f0, #8c8b8b, #f0f0f0);
background-image: -o-linear-gradient(left, #f0f0f0, #8c8b8b, #f0f0f0);
```

The `-ms-`, `-moz-`, and `-o-` prefixes have been unsupported in any actively-used browser for 6+ years. The `-webkit-` prefix is needed for older iOS Safari, but even there only versions before iOS 9 (2015). Mobile-first game, modern deployment — these add dead bytes to every page.

**Fix:** Replace all four vendor-prefixed lines with the single standard `background-image: linear-gradient(to right, #f0f0f0, #8c8b8b, #f0f0f0);`.

---

## Summary

| ID | Severity | File | Issue |
|----|----------|------|-------|
| UI-P7-001 | MEDIUM | classement.php:289,478 | `isset($enGuerre)` emits invalid CSS `rgba(,0.6)` on every unhighlighted row |
| UI-P7-002 | MEDIUM | classement.php:46,195 | `$pageParDefaut` undefined when searched player is off-map (x=-1000), causing PHP warning + negative LIMIT offset |
| UI-P7-003 | LOW | classement.php (4 tabs), rapports.php | Pagination counter shows "1" when table is empty (0 pages) |
| UI-P7-004 | LOW | includes/meta.php:5 | Duplicate charset declaration (legacy `http-equiv` redundant alongside `<meta charset>`) |
| UI-P7-005 | LOW | includes/basicprivatehtml.php:514 | Comment placed on `<script nonce=...>` opening line — inconsistent style |
| UI-P7-006 | LOW | attaquer.php, marche.php, armee.php, attaque.php, constructions.php | `innerHTML` used for countdown text; should use `textContent` |
| UI-P7-007 | INFO | ~35 pages | No `$pageTitle` set; all pages show same generic browser-tab title |
| UI-P7-008 | INFO | includes/style.php:87-90 | Dead vendor-prefixed CSS gradient fallbacks (`-ms-`, `-moz-`, `-o-`) |

**8 findings total: 2 MEDIUM, 4 LOW, 2 INFO. No HIGH or CRITICAL.**

Key structural observations:
- No page can crash (500) on normal user action from the issues found above; UI-P7-002 produces a PHP warning and a likely empty table, not a fatal error.
- All navigation links point to existing pages — no 404 dead links found.
- `includes/layout.php` has correct charset, viewport, CSP, and meta structure.
- Mobile layout uses Framework7 which is inherently responsive; no fixed-width elements found in custom CSS outside of images with explicit pixel sizes (all <= 80px, acceptable).
- JS interactions (countdown, timers, autocomplete) load correctly; `js/countdown.js` is clean and uses `textContent` correctly.
