# UX-CROSS: Unified UI/UX Improvement Plan

**Round 3 Cross-Cutting Analysis -- "The Very Little War"**
**Date:** 2026-03-03
**Stack:** PHP 8.2, Framework7 v1 (Material), jQuery 3.7.1, mobile-first

---

## Executive Summary

This document consolidates all UI/UX findings from Rounds 1 and 2 into a four-phase
implementation plan. The codebase has fundamental architectural patterns that undermine
usability and security simultaneously: the `submit()` function uses `<a href="javascript:...">`
instead of native form buttons, every page loads dead Cordova/AES scripts, three
competing tutorial systems exist in parallel, the prestige system has backend functions
but no user-facing page, timer countdowns force full-page reloads destroying player
context, the map is hardcoded to 600x300px breaking on all modern mobile devices,
navigation badges exist only for some actions, and the CSP header requires `unsafe-inline`
because there are 48 inline `<script>` blocks across 22 PHP files.

The plan is ordered by risk: Phase 1 fixes things that are actively broken or dangerous,
Phase 2 addresses mobile usability, Phase 3 adds missing features, and Phase 4 is
polish and security hardening.

---

## Table of Contents

1. [Root Cause Analysis](#1-root-cause-analysis)
2. [Phase 1 -- Safety and Quick Wins](#2-phase-1----safety-and-quick-wins)
3. [Phase 2 -- Mobile Usability](#3-phase-2----mobile-usability)
4. [Phase 3 -- Missing Features](#4-phase-3----missing-features)
5. [Phase 4 -- Polish and CSP Hardening](#5-phase-4----polish-and-csp-hardening)
6. [File Impact Matrix](#6-file-impact-matrix)
7. [Effort Estimates](#7-effort-estimates)
8. [Risk Assessment](#8-risk-assessment)

---

## 1. Root Cause Analysis

### 1.1 The `submit()` Function (CRITICAL)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/ui_components.php` lines 520-575

The central form submission helper generates this HTML:

```php
$form = 'javascript:document.' . $options['form'] . '.submit()';
// ...
return $nom . '<a class="button ' . $classe . '" style="' . $style
     . '" href="' . $form . '" ' . $id . '>'
     . $image1 . $titre . $image2 . '</a>';
```

This produces: `<a href="javascript:document.formAttaquer.submit()">Attaquer</a>`

**Problems:**

| Problem | Severity | Impact |
|---------|----------|--------|
| `href="javascript:"` requires `unsafe-inline` in CSP | CRITICAL | Cannot remove `unsafe-inline` from `script-src` while this pattern exists |
| `<a>` instead of `<button type="submit">` | HIGH | Not keyboard-navigable (no Tab focus), screen readers announce as "link" not "button" |
| No native form submission fallback | HIGH | JS-disabled browsers (accessibility tools, corporate proxies) get no functionality |
| `document.formName.submit()` bypasses HTML5 validation | MEDIUM | `required`, `min`, `max`, `pattern` attributes on inputs are all ignored |
| No ARIA attributes | MEDIUM | No `role="button"`, no `aria-label` |
| No loading/disabled state | LOW | Users can double-click and submit twice |

**All call sites (grep `submit\(` in page files):**

| File | Form Name | Action |
|------|-----------|--------|
| `attaquer.php:421` | `formAttaquer` | Launch attack |
| `attaquer.php:516` | `formEspionner` | Launch espionage |
| `armee.php:369+` | molecule forms | Create/modify molecules |
| `constructions.php` | building forms | Upgrade buildings |
| `marche.php` | market forms | Buy/sell resources |
| `alliance.php` | alliance forms | Alliance actions |
| `don.php` | donation forms | Send resources |
| `compte.php` | account forms | Change password/description |
| `ecriremessage.php` | message form | Send message |
| `forum.php` | forum forms | Post to forum |

### 1.2 Dead Script Loading

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php` lines 20-29

Every authenticated page loads these scripts after `</html>`:

```html
<script type="text/javascript" src="cordova.js"></script>
<script type="text/javascript" src="js/notification.js"></script>
<script type="text/javascript" src="js/PushNotification.js"></script>
<script type="text/javascript" src="js/framework7.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" ...></script>
<script type="text/javascript" src="js/loader.js"></script>
<script type="text/javascript" src="js/aes.js"></script>
<script type="text/javascript" src="js/aes-json-format.js"></script>
```

**Problems:**

| Script | Issue |
|--------|-------|
| `cordova.js` | Cordova/PhoneGap wrapper -- the game is a web app, not a hybrid mobile app. This file does not exist on the server, producing a 404 on every page load |
| `js/notification.js` | Cordova push notification helper -- dead code, no Cordova runtime |
| `js/PushNotification.js` | Cordova push notification plugin -- dead code |
| `js/aes.js` | CryptoJS AES encryption -- never referenced anywhere in game code. Was likely for a planned feature that never shipped |
| `js/aes-json-format.js` | CryptoJS AES JSON formatter -- same, dead code |
| Scripts after `</html>` | All script tags are placed AFTER the closing `</html>` tag, which is invalid HTML. Browsers tolerate it but it means the DOM is already "complete" |

**Cost:** 5 HTTP requests (3 are 404s) per page load, ~150ms added latency on mobile.

### 1.3 Three Competing Tutorial Systems

The game has three separate tutorial/onboarding mechanisms that run simultaneously:

#### System A: "niveaututo" State Machine (Legacy)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php` lines 64-166 (aka `cardsprivate.php` content)

A 10-step sequential tutorial stored in `autre.niveaututo`. Each step checks a condition
on EVERY page load and does a JavaScript redirect:

```php
if($autre['niveaututo'] == 2 and in_array("classement.php",...)){
    dbExecute($base, 'UPDATE autre SET niveaututo = 3 WHERE login = ?', ...);
    echo '<script>document.location.href="classement.php?...";</script>';
}
```

**Problems:**
- Runs on EVERY page load via `cardsprivate.php` (included by `basicprivatehtml.php`)
- Uses `echo '<script>document.location.href=...'` (7 locations) -- forces CSP `unsafe-inline`
- Redirects cause partial page renders (user sees flash of content)
- Cannot be dismissed or skipped
- Steps 1-9 chain automatically; step 9 requires POST `finir` to complete

#### System B: Mission Checklist (in same file)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php` lines 9-213

A 20-mission reward system stored in `autre.missions` as semicolon-separated "0"/"1" values.
Runs on every page load. First 3 incomplete missions are checked each load.

**Problems:**
- Shares the `autre.missions` field with System C (offset collision risk)
- Validation logic runs on every page load (performance)
- No dedicated UI page -- rewards appear as notifications
- The `$listeMissions` array (19 entries, indices 0-18) is defined inline in the template file

#### System C: Tutorial Page (New)

**File:** `/home/guortates/TVLW/The-Very-Little-War/tutoriel.php` lines 1-492

A dedicated tutorial page with 7 structured missions, step-by-step instructions,
claim buttons, and progress tracking. Uses proper `<button type="submit">` and CSRF tokens.

**Problems:**
- Stores claimed status at offset 19+ in the SAME `autre.missions` string as System B
- Not linked from the main navigation menu (no menu item for "Tutoriel")
- Completely independent from Systems A and B -- a new player experiences all three simultaneously
- System A may redirect the player AWAY from `tutoriel.php` mid-mission

#### Conflict Matrix

| Scenario | System A | System B | System C |
|----------|----------|----------|----------|
| New player first login | Starts step 1, forces redirect chain | Initializes 19 missions as "0;0;...0;" | Not shown (no menu link) |
| Player visits classement.php | May trigger redirect if niveaututo==2 | Checks 3 missions, may award rewards | No interaction |
| Player upgrades producteur to 3 | Triggers step 3->4 transition if niveaututo==3 | Mission "Producteur niveau 3" completes | Mission 2 "Produire des atomes" may overlap |
| Same achievement | Awards +20 of each atom | Awards +50 energy | Awards +500 energy (on claim) |

### 1.4 Unreachable Prestige System

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/prestige.php` (183 lines)

A complete backend system exists:
- 5 prestige unlocks (Debutant Rapide, Experimente, Veteran, Maitre Chimiste, Legende)
- `calculatePrestigePoints()` -- calculates PP from medals, activity, rank
- `awardPrestigePoints()` -- end-of-season PP distribution
- `purchasePrestigeUnlock()` -- transactional purchase with row locking
- `prestigeProductionBonus()` / `prestigeCombatBonus()` -- modifiers used in formulas

**What is missing:**
- No `prestige.php` page exists for players to VIEW their PP or purchase unlocks
- No link in the navigation menu
- No `prestige` table migration has been verified (the code references `prestige` table with `total_pp` and `unlocks` columns)
- `awardPrestigePoints()` is never called in the season-reset flow
- The production/combat bonus functions exist but may not be called in the formula chain

### 1.5 Timer Reloads Destroy Context

**Files:** `attaquer.php` lines 214-244, `attaque.php` lines 34/53, `marche.php` line 400, `constructions.php` line 324

When countdown timers reach zero, they trigger `document.location.href=":

```javascript
function tempsDynamique123(){
    if(valeur123 > 0){
        valeur123 -= 1;
        document.getElementById("affichage123").innerHTML = affichageTemps(valeur123);
    }
    else {
        document.location.href="attaquer.php";
    }
}
setInterval(tempsDynamique123, 1000);
```

**Problems:**
- Full page reload loses scroll position, open accordions, form inputs
- Multiple timers on the same page can trigger near-simultaneous redirects
- No debounce or guard -- timer drift means the "zero" check can fire multiple times
- The `setInterval` is never cleared -- it continues running after redirect begins
- Forces inline `<script>` (CSP `unsafe-inline` required)
- Each timer creates a separate `setInterval` with a unique function name generated via PHP

### 1.6 Hardcoded Map Dimensions

**File:** `/home/guortates/TVLW/The-Very-Little-War/attaquer.php` line 329

```html
<div style="width:600px;height:300px;" id="carte">
```

The map container is fixed at 600x300px. Each tile is 80x80px (`$tailleTile = 80`).
The actual map size depends on `$tailleCarte['tailleCarte']` from the database, which
determines the grid dimensions.

**Problems:**
- On a 375px-wide mobile screen (iPhone SE), the map overflows by 225px
- The overflow container (`conteneurCarte`) has `overflow-x:scroll;overflow-y:scroll` but the fixed dimensions mean the user must scroll both axes
- Tile labels (player names) at 80px wide truncate any name longer than ~8 characters
- No pinch-to-zoom support
- No responsive tile sizing
- Player position names overlap tile boundaries
- The scroll-to-center script assumes container dimensions that may not match viewport

### 1.7 Incomplete Navigation Badges

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php` lines 238-286

Current badges in the slide-out navigation menu:

| Menu Item | Badge | Color | Condition |
|-----------|-------|-------|-----------|
| Constructions | Points remaining | Green | `pointsProducteurRestants + pointsCondenseurRestants > 0` |
| Equipe | Invitations | Red | Has pending alliance invitations AND not in alliance |
| Messages | Unread count | Red | `messages.statut = 0` |
| Rapports | Unread count | Red | `rapports.statut = 0` |
| Forum | Unread topics | Grey | `nbSujets - nbLus > 0` |

**Missing badges:**

| Menu Item | Missing Badge | Impact |
|-----------|---------------|--------|
| Carte (attaquer.php) | Active attacks/returns | Player cannot see at a glance if troops are moving |
| Armee | Molecules in formation | Player does not know formation is complete without visiting page |
| Tutoriel | Not in menu at all | New players have no way to discover the tutorial page |
| Constructions | Active build timers | Player cannot see if a build slot is free |
| Prestige | Not in menu at all | Entire feature unreachable |

### 1.8 CSP `unsafe-inline` Dependency

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess` line 7

```
Header set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'
https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com;
img-src 'self' data: https:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'"
```

**48 inline `<script>` blocks across 22 files** prevent removal of `unsafe-inline`.
Categories:

| Category | Count | Files | Example |
|----------|-------|-------|---------|
| Timer countdowns | 8+ | `attaquer.php`, `attaque.php`, `marche.php`, `constructions.php` | `setInterval(tempsDynamique..., 1000)` |
| Tutorial JS redirects | 7 | `includes/basicprivatehtml.php` | `echo '<script>document.location.href=...'` |
| Resource live counters | 9 | `includes/copyright.php` (bottom) | `setInterval(carboneynamique, 1000)` |
| API stat preview calls | 1 block | `includes/tout.php` lines 93-157 | `$.ajax({url: "api.php?id=attaque..."})` |
| Form cost calculators | 2 | `attaquer.php` lines 432-491 | `actualiseTemps()`, `actualiseCout()` |
| Framework7 init + config | 1 block | `includes/copyright.php` lines 31-103 | `new Framework7(...)`, autocomplete |
| Accordion deployer | 1 | `includes/copyright.php` lines 107-111 | `myApp.accordionOpen(...)` |
| Number formatter | 1 block | `includes/copyright.php` lines 114-260 | `nFormatter()`, name generator |
| Error/info display | 1 block | `includes/copyright.php` lines 84-98 | `myApp.alert()`, `myApp.addNotification()` |
| Molecule stat preview | 1 block | `armee.php` (via tout.php) | `actualiserStats()` with 9 AJAX calls |
| Google Charts loader | 1 | `marche.php` | `google.charts.load(...)` |

**Missing CSP domains (silent violations):**
- `https://www.gstatic.com` -- required by Google Charts in `marche.php`
- `https://www.google.com` -- required by Google Charts loader

### 1.9 No Confirmation Dialogs on Destructive Actions

Zero instances of `confirm()`, `myApp.confirm()`, or any confirmation dialog anywhere
in the codebase. Destructive actions that submit immediately on click:

| Action | File | Consequence |
|--------|------|-------------|
| Launch attack | `attaquer.php:421` | Troops committed, energy spent, irreversible |
| Espionage | `attaquer.php:516` | Neutrinos consumed |
| Delete molecule class | `armee.php` | Entire molecule class destroyed |
| Leave alliance | `alliance.php` | Leaves team permanently |
| Dissolve alliance | `allianceadmin.php` | Entire alliance destroyed |
| Send resources (don) | `don.php` | Resources transferred, losses in transit |
| Delete account | `compte.php` | Account permanently deleted |
| Declare war | `allianceadmin.php` | War started, affects all team members |

---

## 2. Phase 1 -- Safety and Quick Wins

**Goal:** Fix things that are actively broken or cause data loss.
**Estimated effort:** 6-8 hours total.
**Risk:** Low -- all changes are backward-compatible.

### P1-001: Replace `submit()` with `<button type="submit">` [CRITICAL]

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/ui_components.php` lines 520-575

**Current implementation:**
```php
function submit($options) {
    // ...
    $form = 'javascript:document.' . $options['form'] . '.submit()';
    // ...
    return $nom . '<a class="button ' . $classe . '" ... href="' . $form . '">'
         . $image1 . $titre . $image2 . '</a>';
}
```

**Proposed replacement:**
```php
function submit($options) {
    $style = $options['style'] ?? '';
    $titre = $options['titre'] ?? '';
    $classe = $options['classe'] ?? 'button-raised button-fill';
    $id = isset($options['id']) ? 'id="' . htmlspecialchars($options['id'], ENT_QUOTES, 'UTF-8') . '"' : '';

    // Image decorations
    $image1 = '';
    $image2 = '';
    if (!empty($options['image'])) {
        $imgSrc = htmlspecialchars($options['image'], ENT_QUOTES, 'UTF-8');
        $image1 = '<img alt="" src="' . $imgSrc . '" style="float:left;vertical-align:middle;width:25px;height:25px;margin-top:5px;margin-left:-3px"/>';
        if (empty($options['simple'])) {
            $image2 = '<img alt="" src="' . $imgSrc . '" style="float:right;vertical-align:middle;width:25px;height:25px;margin-top:5px;margin-right:-3px"/>';
        }
    }

    $nom = '';
    if (!empty($options['nom'])) {
        $nom = '<input type="hidden" name="' . htmlspecialchars($options['nom'], ENT_QUOTES, 'UTF-8') . '"/>';
    }

    // If 'link' is provided, render as <a> (navigation, not form submit)
    if (!empty($options['link'])) {
        return $nom . '<a class="button ' . $classe . '" style="' . $style
             . '" href="' . htmlspecialchars($options['link'], ENT_QUOTES, 'UTF-8')
             . '" ' . $id . '>' . $image1 . $titre . $image2 . '</a>';
    }

    // Default: render as <button type="submit"> (form submission)
    return $nom . '<button type="submit" class="button ' . $classe
         . '" style="' . $style . '" ' . $id . '>'
         . $image1 . $titre . $image2 . '</button>';
}
```

**Key behavioral changes:**
- Forms with `'form' => 'formName'` now render `<button type="submit">` instead of `<a href="javascript:...">`
- The `'form'` option is no longer needed when the button is inside the `<form>` element (which it always is in this codebase)
- Links with `'link'` option continue to render `<a>` tags (no change)
- HTML5 form validation (`required`, `min`, `max`, `pattern`) now fires automatically
- Keyboard accessible (Tab + Enter), screen-reader announced as "button"
- Eliminates `javascript:` URLs -- one step toward removing CSP `unsafe-inline`

**Call-site audit required:** Every page that uses `submit(['form' => 'formName'])` must be checked
to ensure the button is inside the corresponding `<form>` element. In the current codebase,
this is already the case for all instances.

**Estimated effort:** 1.5 hours (change function + test all 10+ pages with forms).

### P1-002: Add Confirmation Dialogs for Destructive Actions [HIGH]

**Approach:** Use Framework7's built-in `myApp.confirm()` modal instead of browser `confirm()`.

Create a reusable helper in a new external JS file:

**New file:** `/home/guortates/TVLW/The-Very-Little-War/js/tvlw-confirm.js`

```javascript
/**
 * Attach confirmation dialogs to destructive form submit buttons.
 * Usage: <button type="submit" data-confirm="Confirmer l'attaque ?">Attaquer</button>
 */
document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-confirm]');
        if (!btn) return;
        var msg = btn.getAttribute('data-confirm');
        if (!msg) return;

        e.preventDefault();
        e.stopPropagation();

        if (typeof myApp !== 'undefined' && myApp.confirm) {
            myApp.confirm(msg, 'Confirmation', function() {
                // Remove the attribute temporarily to avoid re-triggering
                btn.removeAttribute('data-confirm');
                btn.click();
            });
        } else if (confirm(msg)) {
            btn.removeAttribute('data-confirm');
            btn.click();
        }
    }, true);
});
```

**Integration points -- add `data-confirm` attribute to these buttons:**

| File | Button | Confirmation Message |
|------|--------|---------------------|
| `attaquer.php:421` | Attack | `"Lancer l'attaque ?"` |
| `attaquer.php:516` | Espionage | `"Lancer l'espionnage ?"` |
| `armee.php` | Delete class | `"Supprimer cette classe de molecules ?"` |
| `alliance.php` | Leave | `"Quitter l'equipe ?"` |
| `allianceadmin.php` | Dissolve | `"Dissoudre l'equipe ? Cette action est irreversible."` |
| `allianceadmin.php` | Declare war | `"Declarer la guerre ?"` |
| `don.php` | Send | `"Envoyer ces ressources ?"` |
| `compte.php` | Delete account | `"Supprimer definitivement votre compte ?"` |

**Estimated effort:** 1.5 hours.

### P1-003: Remove Dead Scripts from `copyright.php` [HIGH]

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php` lines 20-29

**Remove these lines entirely:**

```html
<!-- REMOVE: Cordova/PhoneGap (game is not a hybrid app) -->
<script type="text/javascript" src="cordova.js"></script>
<script type="text/javascript" src="js/notification.js"></script>
<script type="text/javascript" src="js/PushNotification.js"></script>

<!-- REMOVE: CryptoJS AES (never used) -->
<script type="text/javascript" src="js/aes.js"></script>
<script type="text/javascript" src="js/aes-json-format.js"></script>
```

**Also fix:** Move remaining scripts BEFORE `</html>` (currently they are after it).

**Keep:**
- `js/framework7.min.js` -- core UI framework
- jQuery 3.7.1 CDN (with SRI) -- used by molecule stats AJAX
- `js/loader.js` -- used for loading indicators

**Add:** `js/tvlw-confirm.js` (from P1-002).

**Estimated effort:** 30 minutes.

### P1-004: Fix Scripts-After-HTML Placement [MEDIUM]

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php`

The copyright.php file closes `</body></html>` on lines 12-18, then loads scripts on
lines 20-29. This is invalid HTML structure.

**Fix:** Move the script block BEFORE `</body>`, or restructure copyright.php to output
scripts first, then close the HTML. The `</div>` cascade on lines 12-16 also needs to
be verified against the opening tags in `tout.php`.

**Estimated effort:** 30 minutes.

### P1-005: Guard Timer Redirects Against Storms [MEDIUM]

**Files:** `attaquer.php`, `attaque.php`, `marche.php`, `constructions.php`

**Current pattern (per timer):**
```javascript
setInterval(function(){
    if(valeur > 0) { valeur -= 1; /* update display */ }
    else { document.location.href="attaquer.php"; }
}, 1000);
```

**Replace with guarded pattern:**
```javascript
var _tvlwRedirecting = false;
function safeRedirect(url) {
    if (_tvlwRedirecting) return;
    _tvlwRedirecting = true;
    window.location.replace(url);
}
// Then in each timer:
setInterval(function(){
    if(valeur > 0) { valeur -= 1; /* update display */ }
    else { safeRedirect("attaquer.php"); }
}, 1000);
```

This prevents multiple timers from triggering simultaneous redirects.

**Estimated effort:** 1 hour.

### P1-006: Replace Tutorial JS Redirects with PHP `header()` [MEDIUM]

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php` lines 64-166

The 7 tutorial redirect locations all follow this pattern:
```php
echo '<script>document.location.href="classement.php?sub=0&deployer=true&information=...";</script>';
```

Since this code runs BEFORE any HTML output (it is in the PHP processing section of
`cardsprivate.php`, before the `<body>` tag), it can be replaced with:
```php
header('Location: classement.php?sub=0&deployer=true&information=' . urlencode($information));
exit;
```

**Caveat:** The code currently runs AFTER `<body>` is output (line 218). The DB updates
must be moved earlier, or the redirect must use `header()` before any output. Since
`basicprivatehtml.php` is included by `tout.php` which outputs `<!DOCTYPE html>` first,
the tutorial logic must be extracted into a pre-output check.

**Recommended approach:**
1. Extract tutorial transition checks into a function `checkTutorialTransitions()`
2. Call it in `basicprivatephp.php` (the auth guard, which runs before output)
3. Use `header('Location: ...')` + `exit` for redirects
4. This eliminates 7 inline `<script>` blocks

**Estimated effort:** 2 hours.

---

## 3. Phase 2 -- Mobile Usability

**Goal:** Make the game playable on mobile screens (375px-414px width).
**Estimated effort:** 8-10 hours total.
**Risk:** Medium -- visual changes, requires testing on multiple screen sizes.

### P2-001: Make Map Responsive [HIGH]

**File:** `/home/guortates/TVLW/The-Very-Little-War/attaquer.php` lines 263-370

**Current:** Fixed 600x300px container, 80px tiles, absolute positioning.

**Proposed approach:**

```php
// Calculate responsive tile size based on map dimensions
$tailleTile = 80; // Keep as default
// The container should use CSS to fill available width
```

Replace the hardcoded container:
```html
<!-- BEFORE -->
<div style="width:600px;height:300px;" id="carte">

<!-- AFTER -->
<div style="width:100%;height:70vh;position:relative;overflow:auto;-webkit-overflow-scrolling:touch;" id="carte">
```

The tiles use absolute positioning with pixel values calculated from `$tailleTile`.
The map grid total size is `$tailleCarte * $tailleTile` pixels in each dimension.
The container becomes a scrollable viewport that fills the available width.

**Additional improvements:**
- Add `touch-action: pan-x pan-y;` for smooth touch scrolling
- Add a "Center on me" button to re-center the map on the player's position
- Consider reducing `$tailleTile` to 60px on mobile (detectable via PHP `$_SERVER['HTTP_USER_AGENT']` or CSS media queries)
- Player name labels: use `font-size: clamp(8px, 1.5vw, 12px)` for responsive text

**Estimated effort:** 2.5 hours.

### P2-002: Improve Touch Targets [MEDIUM]

**Framework7 v1 Material theme minimum touch target:** 48x48px (Google Material guidelines).

**Problem areas:**

| Element | Current Size | Location |
|---------|-------------|----------|
| Map player tiles | 80x80px | OK |
| Resource chips in toolbar | ~25x25px image + text | `tout.php` molecule stat bar |
| Accordion expand chevrons | Framework7 default | Multiple pages |
| Popover help icons (`aide()`) | 20x20px | `ui_components.php:583` |
| Menu item tap area | Full width but 44px height | `basicprivatehtml.php` menu items |
| Number inputs for troops | Default browser | `attaquer.php` attack form |
| Slider range inputs | Default browser | `ui_components.php:486` |

**Fixes:**
- Help icons (`aide()`): increase tap target to 44x44px with padding:
  ```css
  .open-popover img { min-width: 44px; min-height: 44px; padding: 12px; }
  ```
- Number inputs: add `inputmode="numeric"` and increase font size to 16px (prevents iOS zoom)
- Toolbar stat chips: increase to minimum 44px height

**Estimated effort:** 2 hours.

### P2-003: Improve Resource Visibility [MEDIUM]

**Current:** Resources are shown in a popover triggered by clicking a small icon.
The slide-out panel shows `atomes.php` but only when the panel is open.

**Proposed:** Add a compact resource bar below the navbar that is always visible:

```html
<div class="resource-bar" style="position:fixed;top:44px;left:0;right:0;z-index:500;
    background:#1a1a1a;padding:4px 8px;display:flex;flex-wrap:wrap;
    justify-content:space-around;font-size:11px;color:white;box-shadow:0 1px 3px rgba(0,0,0,0.3);">
    <span>E: <span id="res-energie">0</span></span>
    <span style="color:black">C: <span id="res-carbone">0</span></span>
    <!-- ... other resources ... -->
</div>
```

This requires adjusting the page content top padding from 63px to ~85px.

**Estimated effort:** 2 hours.

### P2-004: Fix Viewport and Font Sizing [LOW]

**Check `includes/meta.php`** for proper viewport meta tag:
```html
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, user-scalable=yes">
```

Ensure:
- No `user-scalable=no` (accessibility violation)
- No `maximum-scale=1` (prevents zoom)
- Input font size >= 16px to prevent iOS auto-zoom on focus

**Estimated effort:** 30 minutes.

### P2-005: Add "Select All Troops" Button [LOW]

**File:** `/home/guortates/TVLW/The-Very-Little-War/attaquer.php` attack form

Currently each molecule class has an individual "Max" link. Add a single "All troops"
button above the troop selection that fills all inputs to their maximum values.

**Estimated effort:** 1 hour.

---

## 4. Phase 3 -- Missing Features

**Goal:** Complete unreachable functionality and unify competing systems.
**Estimated effort:** 12-16 hours total.
**Risk:** Medium-High -- new page creation, tutorial system refactor.

### P3-001: Create `prestige.php` Page [HIGH]

**Backend:** `/home/guortates/TVLW/The-Very-Little-War/includes/prestige.php` is complete.

**Required new file:** `/home/guortates/TVLW/The-Very-Little-War/prestige.php`

**Page structure:**
```
+--------------------------------------------------+
| Prestige                                     [?] |
+--------------------------------------------------+
| Points de Prestige: 150 PP                       |
| Saison actuelle: +0 PP (estimé)                  |
+--------------------------------------------------+

+--------------------------------------------------+
| Ameliorations                                    |
+--------------------------------------------------+
| [x] Debutant Rapide (50 PP)                     |
|     Commence avec le Generateur niveau 2         |
|     [Deja achete]                                |
+--------------------------------------------------+
| [ ] Experimente (100 PP)                         |
|     +5% de production de ressources              |
|     [Acheter - 100 PP]                           |
+--------------------------------------------------+
| [ ] Veteran (250 PP)                  [verrouille]|
|     +1 jour de protection debutant               |
|     Pas assez de PP (150/250)                    |
+--------------------------------------------------+
| ... (Maitre Chimiste, Legende) ...               |
+--------------------------------------------------+

+--------------------------------------------------+
| Comment gagner des PP                            |
+--------------------------------------------------+
| - Etre actif la derniere semaine: +5 PP          |
| - Medailles: +1 PP par palier atteint            |
| - 10+ attaques: +5 PP                            |
| - 20+ echanges: +3 PP                            |
| - Dons: +2 PP                                    |
| - Top 5: +50 PP | Top 10: +30 PP | ...          |
+--------------------------------------------------+
```

**Implementation tasks:**
1. Create `prestige.php` with `include("includes/basicprivatephp.php")`
2. Handle POST for `purchasePrestigeUnlock()` with CSRF
3. Display current PP, estimated season PP, unlock status
4. Add prestige menu item to `basicprivatehtml.php` navigation
5. Verify `prestige` database table exists (run migration if needed)
6. Wire `awardPrestigePoints()` into season-reset flow
7. Verify `prestigeProductionBonus()` and `prestigeCombatBonus()` are called in formula chain

**Estimated effort:** 4 hours.

### P3-002: Unify Tutorial Systems [HIGH]

**Goal:** Merge Systems A, B, and C into a single coherent onboarding flow.

**Recommended architecture:**

1. **Keep System C** (`tutoriel.php`) as the single tutorial interface
2. **Retire System A** (niveaututo state machine) -- convert remaining unfinished steps to System C missions
3. **Retire System B** (mission checklist) -- migrate missions that are not duplicated in System C

**Migration plan:**

| System A Step | System C Equivalent | Action |
|---------------|---------------------|--------|
| Step 1: Follow tutorial | N/A (entry point) | Remove -- just link to tutoriel.php |
| Step 2: Visit classement | Add mission 8 "Decouvrir le classement" | Migrate |
| Step 3: Producteur >= 2 | Mission 2 "Produire des atomes" | Already covered |
| Step 4: Change production points | Add to Mission 2 instructions | Merge |
| Step 5: Visit armee | Mission 4 "Creer sa premiere molecule" | Already covered |
| Step 6: Create molecule | Mission 4 | Already covered |
| Step 7: Visit joueur.php | Add mission 9 "Consulter un profil" | Migrate |
| Step 8: Join alliance | Mission 7 | Already covered |
| Step 9: Finish tutorial | Remove -- use System C progress bar completion | Remove |

| System B Mission | System C Equivalent | Action |
|------------------|---------------------|--------|
| Changer description | Mission 5 "Personnaliser son profil" | Already covered |
| Producteur niveau 3 | Extend Mission 2 as "advanced" | Merge into advanced missions |
| Envoyer un message | Add as Mission 10 | Migrate |
| Generateur niveau 3 | Extend Mission 1 | Merge |
| Decouvrir medailles | Add as Mission 11 | Migrate |
| ... (15 more) | Add as "Advanced Missions" section | Migrate |

**Data migration:**
- Players with `niveaututo >= 10` (tutorial complete): mark all System C basic missions as claimed
- Players with `niveaututo < 10`: map their current step to System C progress
- System B missions string: parse existing completions and map to System C offsets
- After migration: `niveaututo` column becomes unused (keep for rollback safety)

**Code changes:**
1. Remove tutorial checks from `basicprivatehtml.php` lines 64-166 (eliminates 7 inline `<script>` blocks)
2. Remove `$listeMissions` array from `basicprivatehtml.php` lines 9-49
3. Remove mission verification loop from `basicprivatehtml.php` lines 170-213
4. Add "Tutoriel" to navigation menu in `basicprivatehtml.php`
5. Add advanced missions to `tutoriel.php`
6. All mission reward logic stays in `tutoriel.php` POST handler

**Estimated effort:** 6 hours.

### P3-003: Add Navigation Badges for Missing Items [MEDIUM]

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php`

Add these badge queries:

```php
// Active attacks/returns (for Carte menu item)
$activeAttacks = dbFetchOne($base, 'SELECT COUNT(*) AS nb FROM actionsattaques WHERE attaquant=? OR defenseur=?', 'ss', $_SESSION['login'], $_SESSION['login']);
$cartePlus = '';
if ($activeAttacks['nb'] > 0) {
    $cartePlus = '<span class="badge bg-orange">' . $activeAttacks['nb'] . '</span>';
}

// Tutorial progress (new menu item)
// Only show badge if tutorial is not 100% complete
$tutoPlus = '';
// Calculate from tutoriel.php mission data or a simpler check

// Prestige (new menu item)
$prestigePlus = '';
// Show PP count or "NEW" badge
```

**Add menu items:**
```php
// After Medailles item:
item(['media' => '<img src="images/menu/tutoriel.png" ...>', 'titre' => 'Tutoriel ' . $tutoPlus, 'link' => 'tutoriel.php']);
item(['media' => '<img src="images/menu/prestige.png" ...>', 'titre' => 'Prestige ' . $prestigePlus, 'link' => 'prestige.php']);
```

**Performance note:** These additional queries add to the per-page-load cost. Consider
caching badge counts in the session with a 60-second TTL.

**Estimated effort:** 2 hours.

### P3-004: Add Tutorial Menu Icon [LOW]

Create or source an appropriate icon for the tutorial menu entry. The game uses 25x25px
PNG icons in the sidebar. A "graduation cap" or "book" icon would be appropriate.

If no custom icon is available, reuse `images/missions/description.png` or create a
simple SVG.

**Estimated effort:** 30 minutes.

---

## 5. Phase 4 -- Polish and CSP Hardening

**Goal:** Extract inline scripts, implement CSP nonces, add chart timestamps.
**Estimated effort:** 10-14 hours total.
**Risk:** High -- touches every page, requires comprehensive regression testing.

### P4-001: Extract Inline Scripts to External Files [CRITICAL for CSP]

**Target:** Eliminate all 48 inline `<script>` blocks across 22 files.

**Extraction plan by category:**

#### Category 1: Resource Live Counters (9 blocks)

**Source:** `includes/copyright.php` lines 427-463

These generate per-resource `setInterval` functions with PHP-injected initial values.

**Extract to:** `js/tvlw-resources.js`

**Approach:** Output PHP data as a JSON object in a `<script type="application/json">` block
(which is CSP-safe), then read it from the external JS file:

```php
// In copyright.php, replace inline script with data block:
echo '<script type="application/json" id="tvlw-resource-data">' . json_encode([
    'energie' => ['value' => $ressources['energie'], 'revenue' => revenuEnergie(...)/3600, 'max' => placeDepot(...)],
    'carbone' => ['value' => $ressources['carbone'], 'revenue' => revenuAtome(0,...)/3600, 'max' => placeDepot(...)],
    // ... other resources
]) . '</script>';
```

```javascript
// js/tvlw-resources.js
(function() {
    var data = JSON.parse(document.getElementById('tvlw-resource-data').textContent);
    Object.keys(data).forEach(function(res) {
        var d = data[res];
        setInterval(function() {
            if (d.value + d.revenue < d.max) d.value += d.revenue;
            else d.value = d.max;
            var el = document.getElementById('affichage' + res);
            if (el) el.innerHTML = nFormatter(Math.floor(d.value)) + '/' + nFormatter(d.max);
        }, 1000);
    });
})();
```

#### Category 2: Framework7 Init + Config (1 large block)

**Source:** `includes/copyright.php` lines 31-103

**Extract to:** `js/tvlw-init.js`

**Challenge:** The autocomplete player list is injected via PHP. Use the same
`<script type="application/json">` pattern:

```php
echo '<script type="application/json" id="tvlw-init-data">' . json_encode([
    'joueurs' => $joueursList, // pre-built array
    'innerWidth' => null, // handled in JS
]) . '</script>';
```

#### Category 3: Timer Countdowns (8+ blocks)

**Source:** `attaquer.php`, `attaque.php`, `marche.php`, `constructions.php`

**Extract to:** `js/tvlw-timers.js`

**Approach:** Use `data-*` attributes on timer elements:

```html
<td data-timer="<?= $actionsattaques['tempsAttaque'] - time() ?>"
    data-redirect="attaquer.php"
    id="affichage<?= $actionsattaques['id'] ?>">
    <?= affichageTemps($actionsattaques['tempsAttaque'] - time()) ?>
</td>
```

```javascript
// js/tvlw-timers.js
(function() {
    var redirecting = false;
    document.querySelectorAll('[data-timer]').forEach(function(el) {
        var remaining = parseInt(el.getAttribute('data-timer'), 10);
        var redirect = el.getAttribute('data-redirect');
        setInterval(function() {
            if (remaining > 0) {
                remaining--;
                el.textContent = affichageTemps(remaining);
            } else if (redirect && !redirecting) {
                redirecting = true;
                window.location.replace(redirect);
            }
        }, 1000);
    });
})();
```

#### Category 4: Number Formatter + Name Generator (1 block)

**Source:** `includes/copyright.php` lines 114-260

**Extract to:** `js/tvlw-utils.js`

These are pure JavaScript functions with no PHP dependencies. Direct extraction.

#### Category 5: Form Calculators (2 blocks)

**Source:** `attaquer.php` lines 432-491

**Extract to:** `js/tvlw-attack.js`

Use `data-*` attributes on form elements to pass PHP values:

```html
<form data-attack-times='<?= json_encode($tempsAttaque) ?>'
      data-cost-factors='<?= json_encode($coutFactors) ?>'>
```

#### Category 6: Molecule Stat Preview (1 block)

**Source:** `includes/tout.php` lines 93-157

**Extract to:** `js/tvlw-molecule-stats.js`

The 9 AJAX calls use PHP-injected player name and level values. Pass via data attributes.

#### Category 7: Error/Info Display (1 block)

**Source:** `includes/copyright.php` lines 84-98

**Extract to:** `js/tvlw-notifications.js`

Use `data-*` attributes on a hidden element:

```html
<div id="tvlw-flash" style="display:none"
     data-error="<?= htmlspecialchars($erreur ?? '', ENT_QUOTES, 'UTF-8') ?>"
     data-info="<?= htmlspecialchars($information ?? '', ENT_QUOTES, 'UTF-8') ?>">
</div>
```

#### Category 8: Accordion Deployer (1 block)

**Source:** `includes/copyright.php` lines 107-111

**Merge into:** `js/tvlw-init.js`

Check for `?deployer=true` URL parameter in JS and open accordion.

**Estimated effort for full extraction:** 8-10 hours.

### P4-002: Implement CSP Nonces [HIGH]

**Prerequisite:** P4-001 must be substantially complete. Some inline scripts may remain
(Google Charts callback, Framework7 init). These need nonces.

**Implementation:**

1. Generate nonce in `includes/session_init.php` (or `basicprivatephp.php`):
```php
$csp_nonce = base64_encode(random_bytes(16));
```

2. Set CSP header via PHP (replace .htaccess CSP):
```php
header("Content-Security-Policy: default-src 'self'; "
     . "script-src 'self' 'nonce-{$csp_nonce}' https://cdnjs.cloudflare.com https://www.gstatic.com https://www.google.com; "
     . "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
     . "img-src 'self' data: https:; "
     . "font-src 'self'; "
     . "connect-src 'self'; "
     . "frame-ancestors 'self'; "
     . "object-src 'none'; "
     . "base-uri 'self'");
```

3. Add nonce to any remaining inline scripts:
```php
echo '<script nonce="' . $csp_nonce . '">';
```

4. Add nonce to `<script type="application/json">` data blocks (they technically do
   not need it since they are not executable, but strict CSP may flag them).

5. Remove `unsafe-inline` from `script-src`.

6. Keep `unsafe-inline` in `style-src` temporarily (Framework7 v1 uses many inline styles).

**Estimated effort:** 2-3 hours (after P4-001).

### P4-003: Add Google Charts Timestamps [LOW]

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php`

The market exchange rate chart uses Google Charts but lacks timestamps on the X-axis.
Add datetime labels to the chart data points showing when each rate was recorded.

**Estimated effort:** 1 hour.

### P4-004: Add Missing CSP Domains (Immediate) [MEDIUM]

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess` line 7

Even before the nonce migration, fix the missing domains that cause silent CSP violations:

```
Header set Content-Security-Policy "default-src 'self';
  script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://www.gstatic.com https://www.google.com;
  style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com;
  img-src 'self' data: https:;
  font-src 'self' https://cdnjs.cloudflare.com;
  connect-src 'self';
  frame-ancestors 'self';
  object-src 'none';
  base-uri 'self'"
```

**Note:** This can be done in Phase 1 as a quick win. Listed here for completeness.

**Estimated effort:** 15 minutes.

---

## 6. File Impact Matrix

| File | P1-001 | P1-002 | P1-003 | P1-004 | P1-005 | P1-006 | P2-001 | P2-002 | P2-003 | P3-001 | P3-002 | P3-003 | P4-001 | P4-002 |
|------|--------|--------|--------|--------|--------|--------|--------|--------|--------|--------|--------|--------|--------|--------|
| `includes/ui_components.php` | MODIFY | | | | | | | | | | | | | |
| `includes/copyright.php` | | | MODIFY | MODIFY | | | | | | | | | MODIFY | MODIFY |
| `includes/basicprivatehtml.php` | | | | | | MODIFY | | | MODIFY | | MODIFY | MODIFY | | MODIFY |
| `includes/basicprivatephp.php` | | | | | | MODIFY | | | | | | | | MODIFY |
| `includes/tout.php` | | | | | | | | MODIFY | MODIFY | | | | MODIFY | |
| `attaquer.php` | TEST | MODIFY | | | MODIFY | | MODIFY | | | | | | MODIFY | |
| `attaque.php` | | | | | MODIFY | | | | | | | | MODIFY | |
| `marche.php` | TEST | | | | MODIFY | | | | | | | | MODIFY | |
| `constructions.php` | TEST | | | | MODIFY | | | | | | | | MODIFY | |
| `armee.php` | TEST | MODIFY | | | | | | | | | | | MODIFY | |
| `alliance.php` | TEST | MODIFY | | | | | | | | | | | | |
| `allianceadmin.php` | TEST | MODIFY | | | | | | | | | | | | |
| `don.php` | TEST | MODIFY | | | | | | | | | | | | |
| `compte.php` | TEST | MODIFY | | | | | | | | | | | | |
| `tutoriel.php` | | | | | | | | | | | MODIFY | | | |
| `prestige.php` | | | | | | | | | | CREATE | | | | |
| `includes/prestige.php` | | | | | | | | | | | | | | |
| `.htaccess` | | | | | | | | | | | | | | MODIFY |
| `js/tvlw-confirm.js` | | CREATE | | | | | | | | | | | | |
| `js/tvlw-resources.js` | | | | | | | | | | | | | CREATE | |
| `js/tvlw-timers.js` | | | | | | | | | | | | | CREATE | |
| `js/tvlw-utils.js` | | | | | | | | | | | | | CREATE | |
| `js/tvlw-init.js` | | | | | | | | | | | | | CREATE | |
| `js/tvlw-attack.js` | | | | | | | | | | | | | CREATE | |
| `js/tvlw-molecule-stats.js` | | | | | | | | | | | | | CREATE | |
| `js/tvlw-notifications.js` | | | | | | | | | | | | | CREATE | |

---

## 7. Effort Estimates

| Phase | Task | Hours | Cumulative |
|-------|------|-------|------------|
| **Phase 1** | P1-001: Fix `submit()` | 1.5 | 1.5 |
| | P1-002: Confirmation dialogs | 1.5 | 3.0 |
| | P1-003: Remove dead scripts | 0.5 | 3.5 |
| | P1-004: Fix scripts-after-HTML | 0.5 | 4.0 |
| | P1-005: Guard timer redirects | 1.0 | 5.0 |
| | P1-006: Tutorial PHP redirects | 2.0 | 7.0 |
| | P4-004: Fix CSP domains (quick) | 0.25 | 7.25 |
| **Phase 1 Total** | | **7.25** | |
| **Phase 2** | P2-001: Responsive map | 2.5 | 9.75 |
| | P2-002: Touch targets | 2.0 | 11.75 |
| | P2-003: Resource visibility | 2.0 | 13.75 |
| | P2-004: Viewport/font sizing | 0.5 | 14.25 |
| | P2-005: Select all troops | 1.0 | 15.25 |
| **Phase 2 Total** | | **8.0** | |
| **Phase 3** | P3-001: Create prestige.php | 4.0 | 19.25 |
| | P3-002: Unify tutorials | 6.0 | 25.25 |
| | P3-003: Navigation badges | 2.0 | 27.25 |
| | P3-004: Tutorial menu icon | 0.5 | 27.75 |
| **Phase 3 Total** | | **12.5** | |
| **Phase 4** | P4-001: Extract inline scripts | 9.0 | 36.75 |
| | P4-002: Implement CSP nonces | 2.5 | 39.25 |
| | P4-003: Chart timestamps | 1.0 | 40.25 |
| **Phase 4 Total** | | **12.5** | |
| **Grand Total** | | **40.25 hours** | |

---

## 8. Risk Assessment

### Phase 1 Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| `submit()` change breaks a form | Medium | HIGH | Test every form on every page before deploying. The `<button type="submit">` must be inside the `<form>` element. |
| Confirmation dialogs block legitimate rapid actions | Low | LOW | Use `data-confirm` only on truly destructive actions, not on routine ones like "Ameliorer" |
| Removing dead scripts breaks something unexpected | Very Low | MEDIUM | The Cordova/AES scripts produce 404s -- they are provably not loading. But search codebase for any references to their functions before removing. |
| Tutorial PHP redirects fail due to output buffering | Medium | MEDIUM | Test that `header('Location:')` works before any HTML output. May require `ob_start()` at the top of `basicprivatephp.php`. |

### Phase 2 Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Responsive map breaks tile alignment | Medium | MEDIUM | Test with map sizes 5x5 through 20x20. Absolute positioning math must still be correct. |
| Resource bar overlaps content | Medium | LOW | Use `position: sticky` instead of `fixed` as fallback. Test on iOS Safari (known sticky issues). |
| Touch target changes break Framework7 layout | Low | MEDIUM | Framework7 v1 has specific CSS expectations. Test accordion, list, and chip components. |

### Phase 3 Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Prestige table does not exist in production DB | High | HIGH | Run `SHOW TABLES LIKE 'prestige'` before deploying. Create migration if needed. |
| Tutorial unification loses player progress | Medium | HIGH | Write a one-time migration script that maps `niveaututo` and `missions` string to new System C state. Back up `autre` table first. |
| Extra badge queries slow page load | Medium | MEDIUM | Cache badge counts in `$_SESSION` with 60-second TTL. Measure before/after with `EXPLAIN`. |

### Phase 4 Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Extracted JS breaks due to timing/load order | High | HIGH | Use `DOMContentLoaded` in all external files. Test that `myApp`, `$$`, `nFormatter` are available when needed. Framework7 and jQuery must load before game scripts. |
| CSP nonce breaks cached pages | Medium | MEDIUM | Nonces are per-request so HTTP caching of HTML pages must be disabled (already the case for PHP pages with `session_start()`). |
| Missing a single inline script causes page breakage | High | HIGH | Use `Content-Security-Policy-Report-Only` during transition to catch violations without blocking. Only switch to enforcing mode after zero reports for 1 week. |

---

## Appendix A: Complete Inline Script Inventory

| File | Line(s) | Category | PHP Deps | Extract To |
|------|---------|----------|----------|------------|
| `includes/copyright.php` | 31-36 | F7 init + calendar | `$_SESSION['login']` | `js/tvlw-init.js` |
| `includes/copyright.php` | 49-57 | Autocomplete player list | `dbQuery` player names | `js/tvlw-init.js` (via JSON data) |
| `includes/copyright.php` | 59-82 | Autocomplete config | None (pure JS) | `js/tvlw-init.js` |
| `includes/copyright.php` | 84-98 | Error/info display | `$erreur`, `$information` | `js/tvlw-notifications.js` (via data attr) |
| `includes/copyright.php` | 100-103 | Deconnexion function | None (dead code) | Remove entirely |
| `includes/copyright.php` | 107-111 | Accordion deployer | `$_GET['deployer']` | `js/tvlw-init.js` (check URL param) |
| `includes/copyright.php` | 114-157 | nFormatter + symboleEnNombre | None (pure JS) | `js/tvlw-utils.js` |
| `includes/copyright.php` | 159-260 | Name generator | None (pure JS) | `js/tvlw-utils.js` |
| `includes/copyright.php` | 427-463 | Resource live counters | Resource values + revenues | `js/tvlw-resources.js` (via JSON data) |
| `includes/basicprivatehtml.php` | 74 | Tutorial redirect | DB update | PHP `header()` (P1-006) |
| `includes/basicprivatehtml.php` | 95 | Tutorial redirect | DB update | PHP `header()` (P1-006) |
| `includes/basicprivatehtml.php` | 104 | Tutorial redirect | DB update | PHP `header()` (P1-006) |
| `includes/basicprivatehtml.php` | 112 | Tutorial redirect | DB update | PHP `header()` (P1-006) |
| `includes/basicprivatehtml.php` | 139 | Tutorial redirect | DB update | PHP `header()` (P1-006) |
| `includes/basicprivatehtml.php` | 148 | Tutorial redirect | DB update | PHP `header()` (P1-006) |
| `includes/basicprivatehtml.php` | 160 | Tutorial redirect | DB update | PHP `header()` (P1-006) |
| `attaquer.php` | 213-227 | Timer countdown (attack outbound) | Timer values | `js/tvlw-timers.js` (via data attr) |
| `attaquer.php` | 230-244 | Timer countdown (attack return) | Timer values | `js/tvlw-timers.js` (via data attr) |
| `attaquer.php` | 367-370 | Map scroll centering | Tile positions | `js/tvlw-map.js` (via data attr) |
| `attaquer.php` | 432-491 | Attack cost/time calculator | Molecule data | `js/tvlw-attack.js` (via data attr) |
| `attaque.php` | 30-37 | Timer countdown | Timer values | `js/tvlw-timers.js` (via data attr) |
| `attaque.php` | 49-56 | Timer countdown | Timer values | `js/tvlw-timers.js` (via data attr) |
| `marche.php` | 396-403 | Timer countdown | Timer values | `js/tvlw-timers.js` (via data attr) |
| `marche.php` | ~560 | Google Charts loader | Chart data | `js/tvlw-charts.js` (partially) |
| `constructions.php` | 320-327 | Timer countdown | Timer values | `js/tvlw-timers.js` (via data attr) |
| `armee.php` | 295-302 | Timer countdown | Timer values | `js/tvlw-timers.js` (via data attr) |
| `includes/tout.php` | 93-157 | Molecule stat AJAX preview | Player name, levels | `js/tvlw-molecule-stats.js` (via data attr) |
| `editer.php` | multiple | Forum editor helpers | None (pure JS) | `js/tvlw-forum-editor.js` |
| `alliance.php` | 2 blocks | Alliance management | Timer values | `js/tvlw-timers.js` |
| `compte.php` | 2 blocks | Account page helpers | None (pure JS) | `js/tvlw-account.js` |
| `forum.php` | 1 block | Forum helpers | None (pure JS) | `js/tvlw-forum.js` |
| `includes/display.php` | 1 block | Display helper | None | `js/tvlw-utils.js` |
| `includes/player.php` | 2 blocks | Player data helpers | Session data | `js/tvlw-init.js` |

---

## Appendix B: Dependency Order for External JS Files

Scripts must load in this order (after Framework7 and jQuery):

```
1. js/framework7.min.js          (Framework7 core -- provides myApp, Dom7, $$)
2. jquery.min.js (CDN + SRI)     (jQuery -- provides $, $.ajax)
3. js/tvlw-utils.js              (nFormatter, affichageTemps, symboleEnNombre, name generator)
4. js/tvlw-init.js               (Framework7 config, calendar, autocomplete, accordion deployer)
5. js/tvlw-notifications.js      (Error/info display -- needs myApp)
6. js/tvlw-confirm.js            (Confirmation dialogs -- needs myApp)
7. js/tvlw-resources.js          (Resource live counters -- needs nFormatter)
8. js/tvlw-timers.js             (Countdown timers -- needs affichageTemps)
9. js/tvlw-attack.js             (Attack form calculator -- page-specific, only on attaquer.php)
10. js/tvlw-molecule-stats.js    (Molecule preview AJAX -- page-specific, only on armee.php)
11. js/tvlw-map.js               (Map scroll centering -- page-specific, only on attaquer.php)
12. js/tvlw-charts.js            (Google Charts -- page-specific, only on marche.php)
```

Page-specific scripts (9-12) should only be loaded on the pages that need them.
Global scripts (3-8) load on every page.

---

## Appendix C: Quick Reference -- What to Do First

If time is limited, this is the minimum viable subset:

1. **P1-001**: Fix `submit()` -- eliminates the most widespread broken pattern (1.5h)
2. **P1-003**: Remove dead scripts -- instant performance win (0.5h)
3. **P4-004**: Fix CSP domains -- stops silent Google Charts violations (0.25h)
4. **P1-002**: Confirmation dialogs -- prevents accidental data loss (1.5h)

**Total for minimum viable improvement: 3.75 hours.**

These four changes fix the most visible user-facing issues without requiring
architectural refactoring. Everything else can be scheduled incrementally.
