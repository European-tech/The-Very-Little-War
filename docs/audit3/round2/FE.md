# Frontend Deep-Dive — Round 2

**Scope:** All JavaScript, CSS, inline scripts, script loading, and CSP across the entire frontend.
**Files examined:** `css/my-app.css`, `includes/style.php`, `includes/basicprivatehtml.php`, `includes/basicpublichtml.php`, `includes/bbcode.php`, `includes/ui_components.php`, `includes/copyright.php`, `includes/tout.php`, `includes/display.php`, `.htaccess`, `attaquer.php`, `constructions.php`, `armee.php`, `marche.php`, `js/notification.js`, `js/loader.js`.

---

## CRITICAL

### FE-R2-001
**Severity:** CRITICAL
**File:** `includes/copyright.php:21-23`
**Issue:** Three dead Cordova scripts are loaded on every page, after `</body>`. `cordova.js` does not exist on the web server (it is a PhoneGap/Cordova runtime artifact), and `js/notification.js` is a full GCM push-notification registration script that calls `window.plugins.pushNotification` which will always be `undefined`. Loading these on a web page throws a runtime error on every visit and calls `alert(error)` on every page load failure because `app.errorHandler` is literally `alert(error)`. `js/PushNotification.js` is 390 KB of minified Google Closure library code that is dead weight.

**Fix:**
```php
// includes/copyright.php — remove entirely:
<script type="text/javascript" src="cordova.js"></script>
<script type="text/javascript" src="js/notification.js"></script>
<script type="text/javascript" src="js/PushNotification.js"></script>
```
Delete `js/notification.js`, `js/PushNotification.js`, `js/PushNotification.js` (the 390 KB loader.js that is `googleCharts.js` renamed), `js/aes.js`, `js/aes-json-format.js` from the repository.

---

### FE-R2-002
**Severity:** CRITICAL
**File:** `includes/copyright.php:28-29`
**Issue:** `js/aes.js` and `js/aes-json-format.js` are loaded on every page. These were used with `jquery.jcryption` to encrypt passwords client-side before sending them (an old, broken pattern — never a substitute for HTTPS). No page in the current codebase calls `jcryption`, `AES`, or `encrypt`. This is 100% dead code that inflates page load by the file sizes and — critically — signals to auditors that the app once sent passwords without TLS, which is a reputational and compliance risk in any future audit.

**Fix:** Remove both `<script>` tags from `includes/copyright.php:28-29`. Delete `js/aes.js`, `js/aes-json-format.js`, `js/jquery.jcryption.3.1.0.js`, `js/sha.js`, `js/sha1.js` from the repository.

---

### FE-R2-003
**Severity:** CRITICAL
**File:** `.htaccess:7`, `includes/copyright.php:27`, `marche.php:560`
**Issue:** The Content-Security-Policy in `.htaccess` allows `script-src 'unsafe-inline'` which negates the XSS protection that CSP is meant to provide. Additionally the CSP does not include `https://www.gstatic.com` (used by Google Charts in `marche.php`) or `https://www.google.com` in `script-src` or `connect-src`, so the Google Charts library either violates the policy silently or would be blocked under a stricter CSP. The CSP also does not include `https://cdnjs.cloudflare.com` for the jQuery CDN in `img-src` context even though the jQuery `<script>` tag uses `crossorigin`. Google Charts loads additional scripts from `gstatic.com` at runtime which are entirely unlisted.

**Current CSP:**
```
script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com
```

**Fix:** The long-term path is to eliminate all inline `<script>` blocks (see FE-R2-009 through FE-R2-016) and use nonces or hashes. Short-term, add the missing origins:
```
script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://www.gstatic.com;
connect-src 'self' https://www.google.com https://www.gstatic.com;
```

---

## HIGH

### FE-R2-004
**Severity:** HIGH
**File:** `includes/bbcode.php:1`
**Issue:** The `<script>` tag uses the obsolete `language="Javascript"` attribute, which was deprecated in HTML 4.01 (1997) and has never been valid in HTML5. All modern browsers ignore it and some validators flag it as an error. This single file contains the BBCode editor JavaScript that is included in `messages.php`, `ecriremessage.php`, `forum.php`, `editer.php`, `allianceadmin.php`, `alliance.php`, `sujet.php`, `compte.php`, `listesujets.php`, `joueur.php`, and `moderationForum.php`.

**Fix:** Change `<script language="Javascript">` to `<script>`.

---

### FE-R2-005
**Severity:** HIGH
**File:** `includes/bbcode.php:5-87`
**Issue:** The first `storeCaret(selec)` function (lines 5-87) is entirely dead code. It is the original one-argument variant that references `document.forms['news'].elements['newst']` — a hard-coded form name that does not exist in any page in the codebase. It is immediately shadowed (overwritten) by the second declaration of `storeCaret(ao_txtfield, as_mf)` at line 89. In JavaScript, the second declaration replaces the first, so the entire 82-line block at lines 5-87 is never executed and cannot be called.

**Fix:** Delete lines 5-87 entirely. The second `storeCaret(ao_txtfield, as_mf)` at line 89 is the active function.

---

### FE-R2-006
**Severity:** HIGH
**File:** `includes/bbcode.php:132`, `bbcode.php:184`, `bbcode.php:241`, `bbcode.php:299`
**Issue:** All four IE-branch code paths in `storeCaret`, `storeCaretNoValueInto`, `storeCaretValue`, and `storeCaretIMG` reference `as_mef.length` which is a typo — the parameter is named `as_mf`, not `as_mef`. This causes a `ReferenceError: as_mef is not defined` in any browser. Since the IE branch (`document.selection.createRange()`) is itself dead in all modern browsers (IE-only, removed in Edge 79+), this bug is never triggered in practice, but the code is still present and causes confusion. The IE branches also contain duplicate variable declarations (`var r = oField.createTextRange(); var r = ao_txtfield.createTextRange();`) at lines 74-75, 133-134, 185-186, 242-243, 300-301 — this is a strict-mode error.

**Fix:** Delete all IE-branch code blocks (`else { // IE ... }`) from all four functions. Modern browsers all support `selectionStart`/`selectionEnd`. Replace the entire bbcode.js with a clean modern implementation using the Selection API.

---

### FE-R2-007
**Severity:** HIGH
**File:** `includes/bbcode.php:90`
**Issue:** IE detection uses `var isIE = (document.all)`. This heuristic is meaningless in 2026: modern Edge and Chrome both support `document.all` (for legacy compat). The detection is used to choose between `document.selection.createRange()` (IE API, dead in all current browsers) and the standard `selectionStart`/`selectionEnd`. Since `document.selection` does not exist in any current browser, the IE branch throws `TypeError: Cannot read properties of undefined` if somehow triggered.

**Fix:** Remove the `isIE` detection entirely. Use only the standard Selection API path.

---

### FE-R2-008
**Severity:** HIGH
**File:** `includes/copyright.php:33`
**Issue:** The Framework7 application is initialized after the `</body>` closing tag. All script loading (`cordova.js`, `notification.js`, `PushNotification.js`, `framework7.min.js`, jQuery, `loader.js`, `aes.js`) happens after `</body>`. While browsers handle this, HTML5 spec mandates scripts after `</body>` are hoisted into `<body>`. The real problem is that Framework7's `myApp` object is created inline in the same block alongside `var $$ = Dom7` and `var mainView = myApp.addView('.view-main')` — all in a non-deferred `<script>` block that runs synchronously. If Framework7 fails to parse (e.g. JS error in a dead Cordova script loaded before it), none of the app's interactive features (panel, autocomplete, popover, smart-select) will work at all.

**Fix:** Move all `<script src="...">` tags into `<head>` with `defer` attribute, or consolidate into a proper body-end block before `</body>`. Fix script order so Framework7 loads before inline initialization code.

---

### FE-R2-009
**Severity:** HIGH
**File:** `includes/basicprivatehtml.php:427-463`
**Issue:** A large inline `<script>` block is emitted inside `<body>` before the `</body>`, containing 8 dynamically-generated `setInterval` timers for real-time resource display (energy and 8 atom types). Each timer generates a unique global variable (`revenuJSEnergie`, `valeurhydrogene`, etc.) and a unique global function (`energieDynamique`, `hydrogèneDynamique`, etc.). This pattern pollutes the global namespace with up to 18 variables and 9 functions on every authenticated page load. The functions use `document.getElementById("affichage" + ressource)` which silently fails when the element is not in the current page's DOM.

**Fix:** Extract to a static `js/resource-timers.js` file that accepts configuration via a single `data-` attribute or a small JSON config object injected once. Example:
```html
<script id="res-config" type="application/json">
  {"revenuEnergie": <?= $val ?>, "energieInit": <?= $ressources['energie'] ?>, ...}
</script>
<script src="js/resource-timers.js" defer></script>
```

---

### FE-R2-010
**Severity:** HIGH
**File:** `includes/tout.php:93-157`
**Issue:** When a player is creating a new molecule class, an inline `<script>` block emitting `actualiserStats()` is output containing 9 separate `$.ajax()` calls — one per molecule stat (attack, defense, HP, speed, destruction, pillage, iode production, formation time, half-life). Every time any atom-count input field changes, all 9 AJAX requests fire simultaneously. This is 9 concurrent XHRs triggered per keystroke. There is no debounce, no batching, and no abort-previous-request logic. On a slow connection this creates a thundering herd of requests and race conditions where out-of-order responses produce stale display values.

**Fix:** Batch all 9 stats into a single `api.php` call. Add 300ms debounce using `setTimeout`/`clearTimeout`. Use `AbortController` to cancel in-flight requests before firing new ones.

---

### FE-R2-011
**Severity:** HIGH
**File:** `attaquer.php:213-227`, `attaquer.php:230-244`, `constructions.php:316-328`, `armee.php:271-304`, `marche.php:391-405`
**Issue:** Per-row countdown timers are emitted as individual `<script>` blocks inside table rows, each defining unique global functions (`tempsDynamique{id}`) and global variables (`valeur{id}`). In `attaquer.php` for example, for each active action there are two `<script>` blocks, each defining a global function. If a player has 10 active attacks, this is 20 inline `<script>` blocks, 20 global functions, and 20 global variables. The countdown functions use `document.location.href = "attaquer.php"` to force-reload when a timer reaches zero, which destroys any unsaved form data on the page.

**Fix:** Consolidate into a single data-driven timer module. Collect timer data as a JSON array in one inline block, then use a single `setInterval` loop to update all timers:
```html
<script id="timer-data" type="application/json">
  [{"id":42,"expires":1800,"page":"attaquer.php"}, ...]
</script>
<script src="js/countdown-timers.js" defer></script>
```

---

### FE-R2-012
**Severity:** HIGH
**File:** `marche.php:492-554`
**Issue:** The `majAchat` and `majVente` JavaScript functions contain PHP code mixed inside the JS body using `<?php foreach ($nomsRes ...) { echo '...'; } ?>`. This generates a chain of `if("hydrogene" == typeRessourceAAcheter){ var numAchat = 0; }` if-else blocks (8 blocks for 8 atom types) inside JS functions. This server-side code generation produces non-minifiable, non-cacheable, and brittle JavaScript. The functions also use `symboleEnNombre()` (defined in `copyright.php`) which has a bug: `chaine.replace(si[j].symbol, si[j].value)` returns a new string but the result is not used — the replacement is discarded, so K/M/G suffixes in market quantity inputs silently fail to convert.

**Fix:** Pass the exchange rates as a JSON object via a `<script id="market-config">` block and compute `numAchat`/`numVente` by `Object.keys(rates).indexOf(resourceName)` in static JS. Fix `symboleEnNombre()` to use the return value of `.replace()`.

---

### FE-R2-013
**Severity:** HIGH
**File:** `marche.php:560`
**Issue:** Google Charts is loaded from `https://www.gstatic.com/charts/loader.js` which is not in the Content-Security-Policy `script-src` directive. This means the Google Charts script either loads successfully only because browsers do not always enforce `.htaccess`-level CSP headers (they do enforce meta-tag CSP), or it silently violates the stated policy. Furthermore, `loader.js` (which is actually the full Google Charts library loader code) dynamically loads additional scripts from `gstatic.com` at runtime, all of which are also not covered by the CSP. The market chart page would fail CSP under a proper strict policy.

**Fix:** Add `https://www.gstatic.com` to `script-src` and `connect-src` in `.htaccess`. Alternatively, self-host a static chart library (Chart.js, ~200 KB) which eliminates the third-party dependency and CSP complexity.

---

## MEDIUM

### FE-R2-014
**Severity:** MEDIUM
**File:** `includes/style.php:36-60`
**Issue:** The `@font-face` declarations for `magmawave_capsbold` and `bpmoleculesregular` are duplicated: they already exist in `css/my-app.css` (lines 1-36), and then duplicated again in `includes/style.php` (lines 36-60) which is output as an inline `<style>` block on every page. This means every page sends redundant font-face declarations. Beyond duplication, the font stacks include `format('embedded-opentype')` (IE6-8 only) and `format('svg')` (removed from all browsers by 2015) — 4 of the 5 source formats per face are dead.

**Fix:** Remove the duplicate `@font-face` declarations from `includes/style.php`. In `css/my-app.css`, strip the EOT and SVG variants, leaving only `woff2` and `woff`:
```css
@font-face {
  font-family: "magmawave_capsbold";
  src: url("fonts/magmawave_caps-webfont.woff2") format("woff2"),
       url("fonts/magmawave_caps-webfont.woff") format("woff");
  font-weight: normal;
  font-style: normal;
}
```

---

### FE-R2-015
**Severity:** MEDIUM
**File:** `includes/style.php:87-90`
**Issue:** The `hr` rule uses four vendor-prefixed gradient properties (`-webkit-linear-gradient`, `-moz-linear-gradient`, `-ms-linear-gradient`, `-o-linear-gradient`) with no unprefixed `linear-gradient` fallback. Since `-moz-`, `-ms-`, and `-o-` have not been needed since Firefox 16 (2012), Opera 12.1 (2012), and IE 10 (2012), these are dead rules. More critically, without a standard `background-image: linear-gradient(...)` line, the rule fails in future browsers that have dropped webkit prefix support.

**Fix:**
```css
hr {
  border: 0;
  height: 1px;
  background-image: linear-gradient(to right, #f0f0f0, #8c8b8b, #f0f0f0);
}
```

---

### FE-R2-016
**Severity:** MEDIUM
**File:** `includes/style.php:259-261`
**Issue:** CSS typo in the `.imageClassement` rule: `height; 32px;` uses a semicolon instead of a colon. This silently drops the `height` property, making `.imageClassement` images have no explicit height constraint.

**Fix:**
```css
.imageClassement {
  width: 32px;
  height: 32px;
}
```

---

### FE-R2-017
**Severity:** MEDIUM
**File:** `css/my-app.css:57`
**Issue:** The file ends with a trailing `.` character on line 57:
```css
.
```
This is an incomplete CSS rule that technically invalidates everything that follows it in the cascade. Since this file has no rules after line 56 (`.item-media` padding), the practical impact is minimal — but it is a parser error that any CSS linter will flag, and would silently invalidate subsequent rules if new ones were added.

**Fix:** Delete line 57 (the lone `.` character).

---

### FE-R2-018
**Severity:** MEDIUM
**File:** `includes/copyright.php:32-36`
**Issue:** The inline `<script>` block sets `document.getElementById('titre').style.marginLeft` using `window.innerWidth` to center the banner. This is a JavaScript layout calculation that runs synchronously on parse, before CSS layout has been finalized. It causes layout thrashing (reading `window.innerWidth` forces a layout, then writing `style.marginLeft` forces another). The formula `window.innerWidth * 0.32 - 105` is also brittle — it breaks on narrow mobile screens where `0.32 * 320 - 105 = -2.6px`.

**Fix:** Center the banner with CSS: `margin: 0 auto;` on `#titre`, or use `position: fixed; left: 50%; transform: translateX(-50%);`. Remove the JS margin calculation entirely.

---

### FE-R2-019
**Severity:** MEDIUM
**File:** `includes/copyright.php:115-132`
**Issue:** The `nFormatter` function and `symboleEnNombre` function are defined in a global inline `<script>` block in `copyright.php`. They are used on every authenticated page. However, `nFormatter` duplicates the logic of PHP's `chiffrePetit()` function in display.php — both convert large numbers to K/M/G notation using the same symbols and scale. Two implementations of the same logic, one PHP and one JS, will diverge over time. Additionally, `symboleEnNombre` has a bug: the `String.replace()` return value is discarded (line 149: `chaine.replace(...)` result is thrown away; `chaine = parseFloat(chaine)*si[j].value` only works if `chaine` is already numeric, not a string like `"5K"`).

**Fix:** Fix `symboleEnNombre`:
```js
function symboleEnNombre(chaine) {
  chaine = String(chaine).trim();
  const si = [
    { value: 1e24, symbol: "Y" }, { value: 1e21, symbol: "Z" },
    { value: 1e18, symbol: "E" }, { value: 1e15, symbol: "P" },
    { value: 1e12, symbol: "T" }, { value: 1e9,  symbol: "G" },
    { value: 1e6,  symbol: "M" }, { value: 1e3,  symbol: "K" }
  ];
  for (const { value, symbol } of si) {
    if (chaine.toUpperCase().endsWith(symbol)) {
      return parseFloat(chaine) * value;
    }
  }
  return parseFloat(chaine) || 0;
}
```
Extract `nFormatter` and `symboleEnNombre` to `js/utils.js`.

---

### FE-R2-020
**Severity:** MEDIUM
**File:** `includes/ui_components.php:239`
**Issue:** Every navigation link in the sidebar uses `onclick="javascript:myApp.closePanel()"`. The `javascript:` prefix inside an `onclick` attribute is redundant and invalid (the browser ignores it, but it is a code smell that confuses linters and may cause issues with strict CSP policies). Additionally this is an inline event handler, which will be blocked if CSP is ever tightened to remove `'unsafe-inline'`.

**Fix:**
```php
// Change in ui_components.php:239:
$link = '<a class="item-link item-link-close-panel' . $ajax . $autocomplete
    . '" data-view=".view-main" href="' . $options["link"] . '" ' . $autocompleteId . '>';
// And in Framework7 initialization, use the built-in panel close behavior
// via the class "close-panel" or Framework7's data-panel attribute system.
```
Alternatively, if Framework7 v1 does not support `close-panel` class, attach the click handler via JS in the static init script rather than inline:
```js
document.querySelector('.panel').addEventListener('click', (e) => {
  if (e.target.closest('.item-link')) myApp.closePanel();
});
```

---

### FE-R2-021
**Severity:** MEDIUM
**File:** `includes/ui_components.php:535`
**Issue:** The `submit()` function generates `<a href="javascript:document.formName.submit()">` links to submit forms. Using `href="javascript:..."` is a CSP-blocking pattern and causes the link to be non-functional if JS is disabled. The generated `<a>` elements lack `role="button"` and `tabindex="0"`, making them inaccessible to keyboard navigation. Screen readers cannot identify them as buttons.

**Fix:** Replace the generated anchor tag with a proper `<button type="submit">` element, which submits the associated form natively and is accessible by default:
```php
return $nom . '<button type="submit" class="button ' . $classe . '" style="' . $style . '" ' . $id . '>'
    . $image1 . $titre . $image2 . '</button>';
```

---

### FE-R2-022
**Severity:** MEDIUM
**File:** `includes/basicprivatehtml.php:296-311`
**Issue:** The atom popover resource display uses a `<center>` element (deprecated since HTML 4.01, removed from HTML5). The `<center>` tag in `$listeRessources` (line 296: `$listeRessources = '<center>';`) is inside dynamically-generated HTML that goes into a `<div class="content-block">`. Additionally the bbcode help text at line 400 uses `<nobr>` (also deprecated) and a bare `<center>` tag inside a `<p>` element (invalid nesting).

**Fix:** Replace `<center>` with `<div style="text-align:center">` or a CSS class. Replace `<nobr>` with `<span style="white-space:nowrap">`.

---

### FE-R2-023
**Severity:** MEDIUM
**File:** `includes/basicprivatehtml.php:74`, `basicprivatehtml.php:95`, `basicprivatehtml.php:104`, `basicprivatehtml.php:112`, `basicprivatehtml.php:139`, `basicprivatehtml.php:148`, `basicprivatehtml.php:160`
**Issue:** Tutorial progression redirects use `echo '<script>document.location.href="...";</script>'` — inline JS navigation emitted mid-page-render. This runs during page render before the page layout is complete, potentially interrupting rendering. The `document.location.href` assignment is also a deprecated alias for the standard `location.href`. More importantly, `$information` values (French text like "Félicitations pour votre première construction!") are concatenated into the URL via `urlencode()` and then re-read by the target page as a GET parameter displayed by Framework7's `myApp.addNotification()`. This is a redundant round-trip: the server already knows the information at redirect time.

**Fix:** Replace JS redirects with PHP `header('Location: ...')` + `exit()` in all tutorial progression branches. The `$information` message can be passed as a flash session variable instead of a URL parameter:
```php
$_SESSION['flash_info'] = "Félicitations pour votre première construction!";
header('Location: constructions.php?deployer=true');
exit();
```

---

### FE-R2-024
**Severity:** MEDIUM
**File:** `includes/copyright.php:50-57`, `copyright.php:59-82`
**Issue:** The player autocomplete list `var joueurs = [...]` is populated by serializing all player login names into a JavaScript array via PHP. For a game with many players this could output several kilobytes of JavaScript inline. This list is also re-generated on every page load for every authenticated user, even pages where the autocomplete (invitation feature) is not present. The autocomplete logic itself uses a linear `for` loop over the entire array on every keystroke (`for (var i = 0; i < joueurs.length; i++)`), which does not scale.

**Fix:** Replace the inline player list with an AJAX-backed autocomplete that queries `api.php?id=players&q=<query>` with a minimum query length of 2 characters. This reduces initial page payload and scales to large player counts.

---

### FE-R2-025
**Severity:** MEDIUM
**File:** `armee.php:369`
**Issue:** The "Max" button for molecule formation uses `onclick="javascript:document.getElementsByName('nombremolecules')[index].value = maxValue;"`. Using `getElementsByName` with a dynamic index is fragile — if the order of elements changes or if there are multiple forms on the page (which there are), `[compteur]` may target the wrong input. Additionally, `onclick="javascript:..."` is an inline event handler.

**Fix:** Use `id` attributes instead of `name` for targeting:
```php
// Generate unique IDs:
item([..., 'after' => '<a id="maxBtn' . $molecule['numeroclasse'] . '"
    class="button button-raised button-fill" style="margin-right:5px"
    data-target="maxInput' . $molecule['numeroclasse'] . '"
    data-value="' . $nbmoleculesMax . '">Max: ' . chiffrePetit($nbmoleculesMax, 0) . '</a>']);
```
Then in static JS:
```js
document.querySelectorAll('[data-target]').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById(btn.dataset.target).value = btn.dataset.value;
  });
});
```

---

### FE-R2-026
**Severity:** MEDIUM
**File:** `attaquer.php:475-486`
**Issue:** The attack form uses `addEventListener("input", function(){...})` correctly, but the listener closure captures `tempsAttaque[c-1]` and `tempsEnCours` from outer scope via PHP string interpolation — this works but the function references the global `tempsEnCours` variable, which creates a closure over a mutable global. If two classes update the value simultaneously (impossible in JS's single-threaded model, but confusing for future maintainers), the displayed time would be incorrect. Additionally, `actualiseCout()` inside the listener calls `document.getElementById("nbclasse1").value * 3.5` etc., where the cost-per-atom values are baked into the emitted JS — this means molecule stats changes require a page reload to reflect in the attack cost display.

**Fix:** Extract into a data-driven approach. Emit `window.ATTACK_DATA = {classCount: N, templates: [...], costs: [...]}` in one block, and reference it in a static `js/attack-form.js`.

---

### FE-R2-027
**Severity:** MEDIUM
**File:** `includes/copyright.php:100-102`
**Issue:** The `deconnexion()` function is defined globally in `copyright.php` and redirects via `document.location.href="deconnexion.php"`. It is never called from any `onclick` handler in the current codebase (the logout menu item is a standard `<a href="deconnexion.php">` link generated by `item()` in `basicprivatehtml.php`). This is dead JavaScript.

**Fix:** Remove the `function deconnexion()` definition.

---

### FE-R2-028
**Severity:** MEDIUM
**File:** `includes/copyright.php:38-46`
**Issue:** The Framework7 Calendar is initialized unconditionally on every page (`var calVacs = myApp.calendar({input: '#calVacs', ...})`), but `#calVacs` only exists on `vacance.php`. Framework7 v1 silently ignores a missing input element here, but this initializes a calendar object, attaches event listeners, and allocates memory on every page in the app for a feature that is only used on one page.

**Fix:** Initialize the calendar only on `vacance.php`:
```php
// In vacance.php, add a page-specific script:
// <script>var calVacs = myApp.calendar({input:'#calVacs',...});</script>
```
Remove the calendar initialization from `copyright.php`.

---

## LOW

### FE-R2-029
**Severity:** LOW
**File:** `includes/basicpublichtml.php:1`, `includes/basicprivatehtml.php:218`
**Issue:** Both body opening tags use `style="font-weight:regular"`. `regular` is not a valid CSS `font-weight` value. Valid values are `normal` (equivalent to `400`), `bold` (equivalent to `700`), or numeric 100-900. Browsers interpret the invalid value as `font-weight: normal` but this was already flagged in Round 1 audit (UX.md) and has not been fixed.

**Fix:**
```html
<body class="theme-black">
```
Remove the `style` attribute entirely since the body's `font-weight` should be set in `css/my-app.css` or `includes/style.php`.

---

### FE-R2-030
**Severity:** LOW
**File:** `includes/display.php:146-166` (function `scriptAffichageTemps`)
**Issue:** The `scriptAffichageTemps()` PHP function emits an inline `<script>` block containing the JavaScript `affichageTemps()` function. This function is called from multiple pages (`attaquer.php`, `constructions.php`, `armee.php`, `marche.php`), which means the same JS function is re-emitted each time `scriptAffichageTemps()` is called. If a page has two active sections that each call `scriptAffichageTemps()`, the function is defined twice in the same document. While harmless (the second definition simply overwrites the first), it is wasteful and incorrect.

**Fix:** Move `affichageTemps()` to `js/utils.js` as a permanent static function. Remove `scriptAffichageTemps()` from `display.php`. Add `<script src="js/utils.js" defer></script>` once in `includes/meta.php`.

---

### FE-R2-031
**Severity:** LOW
**File:** `includes/copyright.php:159-259`
**Issue:** The login name generator (`generate()`, `genererLettre()`, `genererConsonne()`) with its `consonnes`, `voyelles`, and `lettres` arrays is defined globally in `copyright.php` and loaded on every page — authenticated and public. The generator is only used on `inscription.php` (registration page) to suggest a username. All pages other than `inscription.php` load this ~100-line script and three large arrays for no reason.

**Fix:** Move the generator to a page-specific inline `<script>` in `inscription.php` only, or to `js/name-generator.js` loaded with a conditional:
```php
// In inscription.php only:
// <script src="js/name-generator.js" defer></script>
```

---

### FE-R2-032
**Severity:** LOW
**File:** `js/loader.js:1` (first 100 lines examined)
**Issue:** `js/loader.js` is a minified copy of the Google Charts loader library (the same code loaded from `https://www.gstatic.com/charts/loader.js`). This appears to be a stale local copy used for offline/Cordova purposes. On the live web version, `marche.php` loads the CDN version (`https://www.gstatic.com/charts/loader.js`) at line 560. Both are loaded: `copyright.php` loads `js/loader.js` on every page, and `marche.php` additionally loads the CDN version. This means the charts library is loaded twice on `marche.php` and loaded unnecessarily on all other pages.

**Fix:** Remove `<script src="js/loader.js">` from `copyright.php`. Keep only the CDN version in `marche.php`, loaded conditionally. Add `https://www.gstatic.com` to the CSP.

---

### FE-R2-033
**Severity:** LOW
**File:** `includes/copyright.php:107-111`
**Issue:** The tutorial accordion deployment uses `echo '<script>myApp.accordionOpen(document.getElementById("tutorielAccordion"));</script>'` when `$_GET['deployer']` is set. This is an inline script block that fires only when the `deployer` GET parameter is present. Since `myApp` is defined synchronously above this point in the same `<script>` block, execution order is correct — but the `deployer` parameter is also used to pass `information` via `myApp.addNotification()`. Both the GET-parameter information display and the accordion opening are triggered by the same redirect pattern from tutorial progression, so the entire flow is fragile: if the notification fires before `accordionOpen`, the accordion may not be visible to the user.

**Fix:** Use Framework7's `pageInit` callback or a `DOMContentLoaded` event to ensure the accordion open fires after full page initialization.

---

### FE-R2-034
**Severity:** LOW
**File:** `includes/bbcode.php:319`
**Issue:** The `BBCode()` PHP function contains a security-motivated regex strip: `preg_replace('!localStorage.getItem\(("|\')mdp!isU', '', $text)`. This sanitizes old Cordova/localStorage-stored password references from BBCode content. This sanitation is a leftover from the era when the app used `localStorage` to store the MD5 password (pre-bcrypt migration). Since the `mdp` session key was `unset()` after migration (see `basicprivatephp.php:35` and `basicpublicphp.php:14`), no valid user data uses this localStorage pattern anymore. The strip is dead sanitization logic adding regex overhead to every BBCode render.

**Fix:** Remove the `preg_replace('!localStorage.getItem\(("|\')mdp!isU', ...)` line from `BBCode()`. The XSS risk it was guarding against is fully resolved by `htmlentities()` on line 317 which runs before it.

---

### FE-R2-035
**Severity:** LOW
**File:** `attaquer.php:367-370`
**Issue:** The map scroll-centering script:
```js
document.getElementById('conteneurCarte').scrollTop = Math.max(0, parseInt(<?= $tailleTile * ($x + 0.5) ?> - ...));
document.getElementById('conteneurCarte').scrollLeft = Math.max(0, parseInt(<?= $tailleTile * ($y + 0.5) ?> - ...));
```
runs before the DOM is painted. Since it is emitted inline after the PHP map generation loop (still inside the card div), the `conteneurCarte` div exists but the browser has not yet laid it out, so `offsetHeight` and `offsetWidth` may return 0. The `parseInt(value - 0)` call is also redundant since the subtracted values are already numeric.

**Fix:** Wrap in `window.addEventListener('load', ...)` or `document.addEventListener('DOMContentLoaded', ...)`, and remove the redundant `parseInt()` wrapper around arithmetic expressions.

---

### FE-R2-036
**Severity:** LOW
**File:** `includes/display.php:170`
**Issue:** The `nombreMolecules()` function contains a CSS typo: `height;20px` (semicolon instead of colon) in the inline style of the molecule icon chip image:
```php
return chip($nombre, '<img src="images/molecule.png" ... style="width:20px;height;20px;border-radius:0px"/>',...)
```
The `height` property is silently dropped, leaving the image with no explicit height.

**Fix:**
```php
return chip($nombre, '<img src="images/molecule.png" alt="molecule" title="Population" style="width:20px;height:20px;border-radius:0px"/>',...)
```

---

### FE-R2-037
**Severity:** LOW
**File:** `includes/display.php:11`
**Issue:** The `image()` function has a duplicate `alt` attribute:
```php
return '<img style="vertical-align:middle;width:37px;height:37px;" alt="Energie" src="images/' . $nomsRes[$num] . '.png" alt="' . $nomsRes[$num] . '" title="...">';
```
`alt="Energie"` appears first, then `alt="..."` with the actual resource name. Browsers use the last `alt` value, but the first hardcoded `alt="Energie"` is misleading for all atom images (hydrogen, carbon, etc. would all show `alt="Energie"` in some parsers).

**Fix:** Remove the first hardcoded `alt="Energie"`:
```php
return '<img style="vertical-align:middle;width:37px;height:37px;" src="images/'
    . $nomsRes[$num] . '.png" alt="' . $nomsRes[$num] . '" title="'
    . ucfirst($nomsAccents[$num]) . '" />';
```

---

### FE-R2-038
**Severity:** LOW
**File:** `includes/basicprivatehtml.php:329-330`
**Issue:** The Duplicateur bonus calculation in the energy details popover uses:
```php
$bonusDuplicateur = 1+((0.1*$duplicateur['duplicateur'])/100);
```
This gives 0.001x per duplicateur level (0.1%). But the displayed string shows `bonusDuplicateur($duplicateur['duplicateur'])*100`. The `bonusDuplicateur()` function (from formulas.php) and this local calculation may or may not produce identical results depending on rounding. The discrepancy means the popover may display one percentage while the game applies a different one. This is a UX issue rather than a security issue but confuses players.

**Fix:** Remove the local `$bonusDuplicateur` calculation from `basicprivatehtml.php`. Use `bonusDuplicateur($duplicateur['duplicateur'])` from `formulas.php` as the single source of truth for both display and game logic.

---

### FE-R2-039
**Severity:** LOW
**File:** `includes/meta.php:4-5`
**Issue:** Two charset declarations exist: `<meta charset="utf-8">` (HTML5 standard, line 1) and `<meta http-equiv="content-type" content="text/html; charset=utf-8" />` (XHTML/HTML4, line 5). The second is redundant and can cause parser confusion in some older browsers. Only one is needed.

**Fix:** Remove line 5 (`<meta http-equiv="content-type"...>`). The `<meta charset="utf-8">` on line 1 is sufficient and correct.

---

### FE-R2-040
**Severity:** LOW
**File:** `includes/copyright.php:86-97`
**Issue:** Error and notification display uses Framework7's `myApp.alert()` and `myApp.addNotification()` API, which are Framework7 v1 methods. The notification has no `hold` value, so it auto-closes after Framework7's default timeout. Success notifications (for market trades, construction launches, attacks) disappear before players can read them if they are long messages. Additionally, `myApp.alert()` for errors creates a modal dialog that blocks interaction — acceptable for errors but poor UX for informational messages.

**Fix:** Separate error alerts from informational notifications. Use a longer `hold` value (e.g. 5000ms) for success notifications. Consider a toast-style display for non-critical information.

---

## SUMMARY TABLE

| ID | Severity | File | Issue |
|----|----------|------|-------|
| FE-R2-001 | CRITICAL | copyright.php:21-23 | Dead Cordova scripts loaded on every page, alert() on every visit |
| FE-R2-002 | CRITICAL | copyright.php:28-29 | Dead AES/jcryption scripts, legacy password encryption artifacts |
| FE-R2-003 | CRITICAL | .htaccess:7, marche.php:560 | CSP allows unsafe-inline, missing gstatic.com origin for Google Charts |
| FE-R2-004 | HIGH | bbcode.php:1 | Obsolete `language="Javascript"` attribute on script tag |
| FE-R2-005 | HIGH | bbcode.php:5-87 | First `storeCaret(selec)` function is dead code, shadowed by line 89 |
| FE-R2-006 | HIGH | bbcode.php:132,184,241,299 | `as_mef` typo (should be `as_mf`) causes ReferenceError; duplicate var declarations |
| FE-R2-007 | HIGH | bbcode.php:90 | IE detection via `document.all` is meaningless; IE API branches are dead |
| FE-R2-008 | HIGH | copyright.php:33 | Script loading after `</body>`, Framework7 init fragile if prior script errors |
| FE-R2-009 | HIGH | basicprivatehtml.php:427-463 | 9 setInterval timers as inline script, global namespace pollution per page |
| FE-R2-010 | HIGH | tout.php:93-157 | 9 concurrent AJAX calls per keystroke with no debounce or abort |
| FE-R2-011 | HIGH | attaquer.php, constructions.php, armee.php, marche.php | Per-row inline script blocks emitting global functions for countdowns |
| FE-R2-012 | HIGH | marche.php:492-554 | PHP/JS mixing for market calc; symboleEnNombre() discards replace() result |
| FE-R2-013 | HIGH | marche.php:560 | Google Charts from gstatic.com not in CSP; dynamically loads uncovered scripts |
| FE-R2-014 | MEDIUM | style.php:36-60, my-app.css:1-36 | Duplicate @font-face declarations; dead EOT/SVG font formats |
| FE-R2-015 | MEDIUM | style.php:87-90 | Four vendor-prefixed gradients with no standard unprefixed fallback |
| FE-R2-016 | MEDIUM | style.php:259-261 | CSS typo `height; 32px` drops height property on `.imageClassement` |
| FE-R2-017 | MEDIUM | my-app.css:57 | Trailing `.` character — incomplete CSS rule invalidates file end |
| FE-R2-018 | MEDIUM | copyright.php:32 | JS banner centering causes layout thrash, breaks on narrow screens |
| FE-R2-019 | MEDIUM | copyright.php:115-157 | nFormatter duplicates PHP logic; symboleEnNombre() bug discards replace() |
| FE-R2-020 | MEDIUM | ui_components.php:239 | `onclick="javascript:..."` redundant prefix; blocks CSP hardening |
| FE-R2-021 | MEDIUM | ui_components.php:535 | `href="javascript:form.submit()"` inaccessible; should be `<button type="submit">` |
| FE-R2-022 | MEDIUM | basicprivatehtml.php:296,400 | Deprecated `<center>` and `<nobr>` tags in generated HTML |
| FE-R2-023 | MEDIUM | basicprivatehtml.php:74,95,104,112,139,148,160 | Tutorial redirects via inline JS; should use PHP header() |
| FE-R2-024 | MEDIUM | copyright.php:50-57 | Entire player list serialized to JS on every page; linear-scan autocomplete |
| FE-R2-025 | MEDIUM | armee.php:369 | `getElementsByName()[index]` fragile targeting; inline onclick handler |
| FE-R2-026 | MEDIUM | attaquer.php:475-486 | Attack cost baked into emitted JS; mutable global state in listeners |
| FE-R2-027 | MEDIUM | copyright.php:100-102 | Dead `deconnexion()` function never called |
| FE-R2-028 | MEDIUM | copyright.php:38-46 | Calendar initialized on every page; `#calVacs` only exists on vacance.php |
| FE-R2-029 | LOW | basicpublichtml.php:1, basicprivatehtml.php:218 | `font-weight:regular` invalid CSS value (also in Round 1 UX audit) |
| FE-R2-030 | LOW | display.php:146-166 | `scriptAffichageTemps()` re-emits same function multiple times per page |
| FE-R2-031 | LOW | copyright.php:159-259 | Name generator arrays loaded on all pages, only used on inscription.php |
| FE-R2-032 | LOW | js/loader.js, copyright.php:27 | Google Charts library loaded twice on marche.php; loaded unused on all other pages |
| FE-R2-033 | LOW | copyright.php:107-111 | Tutorial accordion open may fire before Framework7 finishes init |
| FE-R2-034 | LOW | bbcode.php:319 | Dead localStorage/mdp sanitization regex; superseded by htmlentities() |
| FE-R2-035 | LOW | attaquer.php:367-370 | Map scroll fires before DOM layout is complete; redundant parseInt() |
| FE-R2-036 | LOW | display.php:170 | CSS typo `height;20px` drops molecule chip image height |
| FE-R2-037 | LOW | display.php:11 | Duplicate `alt` attribute on image(); first one hardcoded "Energie" for all atoms |
| FE-R2-038 | LOW | basicprivatehtml.php:329-330 | Local duplicateur bonus calc may diverge from formulas.php source of truth |
| FE-R2-039 | LOW | meta.php:4-5 | Duplicate charset declarations (charset= and http-equiv content-type) |
| FE-R2-040 | LOW | copyright.php:86-97 | Framework7 notifications have no hold time; success messages disappear instantly |

**Total: 40 issues — 3 CRITICAL, 10 HIGH, 16 MEDIUM, 11 LOW**

---

## Priority Fix Order

1. **FE-R2-001** — Remove dead Cordova scripts (eliminates alert() on every page load)
2. **FE-R2-002** — Remove dead AES/jcryption scripts (removes legacy security artifacts)
3. **FE-R2-003** — Fix CSP to add gstatic.com; begin planning nonce migration
4. **FE-R2-005** + **FE-R2-006** + **FE-R2-007** — Rewrite bbcode.js (one clean modern file)
5. **FE-R2-009** + **FE-R2-011** + **FE-R2-030** — Consolidate all timer/counter scripts into `js/timers.js`
6. **FE-R2-010** — Add debounce + single batched AJAX call for molecule stat preview
7. **FE-R2-012** + **FE-R2-019** — Fix `symboleEnNombre()` bug; extract JS utils to static file
8. **FE-R2-016** + **FE-R2-036** + **FE-R2-037** — Fix CSS/HTML typos (5 min each)
9. **FE-R2-021** — Replace `<a href="javascript:">` submit buttons with `<button type="submit">`
10. **FE-R2-023** — Replace tutorial JS redirects with PHP `header()` + session flash messages
