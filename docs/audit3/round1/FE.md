# Frontend Audit Report - Round 1

**Auditor:** Claude Opus 4.6 (Code Review Expert)
**Date:** 2026-03-03
**Scope:** All JavaScript, CSS, HTML, inline scripts, CSP headers, and frontend-related PHP templates.

**Files Audited:**
- `js/notification.js`, `js/loader.js`, `js/lightbox.js`, `js/googleCharts.js`
- `js/sha.js`, `js/sha1.js`, `js/aes.js`, `js/aes-json-format.js`
- `js/jquery.jcryption.3.1.0.js`, `js/PushNotification.js`
- `css/my-app.css`
- `.htaccess`
- `includes/basicprivatehtml.php`, `includes/basicpublichtml.php`
- `includes/style.php`, `includes/tout.php`, `includes/meta.php`, `includes/copyright.php`
- `marche.php`, `attaquer.php`, `armee.php`, `classement.php`, `sujet.php`
- `convertisseur.html`

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 5     |
| HIGH     | 12    |
| MEDIUM   | 18    |
| LOW      | 16    |
| **Total**| **51**|

---

## CRITICAL Findings

### [FE-R1-001] CRITICAL `js/notification.js:56` -- Hardcoded HTTP URL causes mixed content and leaks GCM registration key

The file sends the GCM push notification registration key to `http://www.theverylittlewar.com/tests/inscrireCle.php?cle=` over plain HTTP. Once HTTPS is enabled, this will be blocked by mixed content policy. The endpoint path (`/tests/`) suggests a test/debug URL that should not exist in production. Additionally, the registration key is sent as a GET query parameter, meaning it will appear in server access logs, referrer headers, and any intermediate proxy logs.

```javascript
ajaxGet("http://www.theverylittlewar.com/tests/inscrireCle.php?cle="+e.regid,function(){
})
```

### [FE-R1-002] CRITICAL `includes/copyright.php:20-29` -- Scripts loaded after closing `</html>` tag

All application JavaScript (framework7, jQuery, loader, aes, notification, PushNotification) is loaded after the `</body>` and `</html>` closing tags. This is invalid HTML and produces undefined behavior across browsers. Some browsers may ignore the scripts, others may execute them unpredictably.

```php
</body>
</html>
  <script type="text/javascript" src="cordova.js"></script>
  <script type="text/javascript" src="js/notification.js"></script>
  ...
```

### [FE-R1-003] CRITICAL `.htaccess:7` -- CSP allows `'unsafe-inline'` for both script-src and style-src

The Content-Security-Policy header allows `'unsafe-inline'` for both scripts and styles, which effectively nullifies XSS protection that CSP is designed to provide. Every page in the application uses extensive inline `<script>` blocks with PHP-generated JavaScript, making this practically necessary at present, but it means CSP is not providing its intended protection.

```
Header set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; ..."
```

### [FE-R1-004] CRITICAL `marche.php:560` / `sujet.php:223` -- External scripts loaded without SRI (Subresource Integrity)

Google Charts (`https://www.gstatic.com/charts/loader.js`) in `marche.php:560` and MathJax (`https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.9/MathJax.js`) in `sujet.php:223` are loaded from external CDNs without SRI hashes. A CDN compromise could inject malicious code into every page that loads these scripts. jQuery in `copyright.php:26` does have SRI, so this inconsistency is notable.

```html
<!-- marche.php:560 - NO SRI -->
<script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>

<!-- sujet.php:223 - NO SRI -->
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.9/MathJax.js?config=TeX-AMS-MML_HTMLorMML"></script>
```

### [FE-R1-005] CRITICAL `.htaccess:7` -- CSP missing `https://www.gstatic.com` in script-src

The CSP only allows `https://cdnjs.cloudflare.com` as an external script source, but `marche.php` loads Google Charts from `https://www.gstatic.com`. This means the Google Charts script will be blocked by CSP in browsers that enforce it, breaking the market price chart entirely. Similarly, MathJax is loaded from cdnjs but the `connect-src` directive does not account for any CDN calls MathJax may make.

---

## HIGH Findings

### [FE-R1-006] HIGH `js/notification.js:1-77` -- Cordova/PhoneGap push notification code dead in web context

This entire file depends on `window.plugins.pushNotification` (a Cordova plugin) and `document.addEventListener('deviceready', ...)`. In a standard web browser, `deviceready` never fires, so `app.initialize()` at line 77 registers a listener that never triggers. The file uses a hardcoded GCM sender ID (`1086347055283`) which is a credential leak. This file should be removed or conditionally loaded.

### [FE-R1-007] HIGH `js/PushNotification.js:1-78` -- Cordova plugin polyfill dead code with console.log statements

This file creates a `PushNotification` prototype that calls `cordova.exec()`, which does not exist in web browsers. Lines 10, 15, 27, 32, 44, 55, 60 contain `console.log()` calls that will appear in production browser consoles. The file self-registers at line 72-73 by creating a global `window.plugins.pushNotification` instance.

### [FE-R1-008] HIGH `js/lightbox.js:278` -- jQuery `.html()` used with user-controlled data (potential DOM-XSS)

Lightbox v2.51 uses `.html()` to render image titles from `<a>` tag `title` attributes at line 278. If any user-generated content ends up in image link titles without server-side sanitization, this creates a DOM-based XSS vector.

```javascript
$lightbox.find('.lb-caption').html(this.album[this.currentImageIndex].title).fadeIn('fast');
```

### [FE-R1-009] HIGH `js/sha.js` + `js/sha1.js` -- Duplicate SHA libraries loaded

Two separate SHA libraries are included: `sha.js` (jsSHA full implementation supporting SHA-1 through SHA-512, SHA-3, SHAKE) and `sha1.js` (jsSHA SHA-1 only). Both register as `window.jsSHA`, meaning the second one loaded overwrites the first. This wastes bandwidth (sha.js alone is ~46 lines of dense minified code) and creates confusion about which implementation is active.

### [FE-R1-010] HIGH `js/jquery-1.7.2.min.js` + `js/jquery-3.1.1.min.js` -- Obsolete jQuery versions bundled in js/ directory

The `js/` directory contains `jquery-1.7.2.min.js` (2012-era, known CVEs) and `jquery-3.1.1.min.js` (2016, outdated). While `copyright.php:26` loads jQuery 3.7.1 from CDN with SRI (correct approach), these old files remain on disk. If any page loads them (even accidentally), it introduces severe XSS vulnerabilities (jQuery < 3.5 has known prototype pollution and selector-based XSS).

### [FE-R1-011] HIGH `js/jquery-ui-1.8.18.custom.min.js` -- Obsolete jQuery UI 1.8.18 bundled

jQuery UI 1.8.18 (circa 2012) is present in the js/ directory. This version has known XSS vulnerabilities in dialog, tooltip, and autocomplete widgets. It is unclear whether any page loads it, but its presence on disk is a risk.

### [FE-R1-012] HIGH `js/jquery.jcryption.3.1.0.js` -- Client-side encryption library likely unused and obsolete

jCryption 3.1.0 is a client-side form encryption library that encrypts form data using AES+RSA before submission. Once HTTPS is enabled, this library is entirely redundant since TLS provides transport encryption. The library is large (~61K tokens) and appears unused based on the current page templates. It also has a `console.warn()` call at line 4410.

### [FE-R1-013] HIGH `js/aes.js` + `js/aes-json-format.js` -- CryptoJS 3.1.2 (2013) loaded but appears unused

CryptoJS v3.1.2 (from 2013, code.google.com/p/crypto-js) provides AES, MD5, SHA, and EvpKDF. The `aes-json-format.js` formatter is a companion. These are loaded on every page via `copyright.php` but no page-level code appears to call `CryptoJS.AES.encrypt()` or `CryptoJS.AES.decrypt()`. If unused, they add ~36 lines of dense minified library code to every page load for no benefit.

### [FE-R1-014] HIGH `includes/basicprivatehtml.php:427-463` -- PHP-generated JavaScript via string concatenation

The resource counter script block generates JS function names and variable names by concatenating PHP `$ressource` variables directly into JavaScript source code. While these values come from a server-side array (not user input), the pattern of building JS via PHP string concatenation is fragile and error-prone.

```php
echo 'function '.$ressource.'Dynamique(){
    document.getElementById("affichage'.$ressource.'").innerHTML = nFormatter(Math.floor(valeur'.$ressource.'))+\'/'.$ressourcesMax.'\'
```

### [FE-R1-015] HIGH `includes/tout.php:93-157` -- Nine AJAX calls on every molecule composition input event

The `actualiserStats()` function in `tout.php` fires 9 separate AJAX requests to `api.php` every time any atom input field changes (via `oninput`). Rapid typing in any of the 8 atom input fields will flood the server with requests. There is no debouncing, throttling, or request cancellation.

### [FE-R1-016] HIGH `includes/copyright.php:21` -- Loading non-existent `cordova.js` on every page

Every page loads `<script type="text/javascript" src="cordova.js"></script>`. This file does not exist in the web root (it is a Cordova build artifact). Every page load generates a 404 error for this file, wasting a network request and potentially logging errors.

### [FE-R1-017] HIGH `js/loader.js:1-100+` -- Google Charts loader.js bundled locally AND loaded from CDN

The file `js/loader.js` is a local copy of Google Charts loader (~38K+ tokens, massive). Meanwhile, `marche.php:560` loads `https://www.gstatic.com/charts/loader.js` from Google's CDN. This means the loader may be loaded twice. The local copy is also likely outdated compared to Google's current version.

---

## MEDIUM Findings

### [FE-R1-018] MEDIUM `css/my-app.css:57` -- Truncated CSS file (ends with a lone period)

The `my-app.css` file ends abruptly at line 57 with just a `.` character, suggesting the file was accidentally truncated. The file currently only contains 3 `@font-face` declarations and a few utility rules.

```css
.item-media {
    padding-bottom: 0px !important;
}

.
```

### [FE-R1-019] MEDIUM `css/my-app.css:1-11` + `includes/style.php:36-60` -- Duplicate @font-face declarations

The fonts `bpmoleculesregular`, `quenya`, and `magmawave_capsbold` are declared in both `css/my-app.css` (paths relative to `css/` directory: `fonts/...`) and `includes/style.php` (paths relative to root: `css/fonts/...`). This means each font is declared twice with potentially conflicting paths, causing redundant font downloads and possible resolution failures depending on which path resolves correctly.

### [FE-R1-020] MEDIUM `css/my-app.css:1-11` + `includes/style.php:36-60` -- Missing `font-display` property on all @font-face declarations

All 5 `@font-face` declarations across both files lack `font-display: swap` (or any `font-display` value). This means text using these custom fonts will be invisible during font loading (FOIT - Flash of Invisible Text) on slow connections, degrading the mobile-first experience.

### [FE-R1-021] MEDIUM `includes/style.php:260` -- CSS syntax error: semicolon instead of colon

Line 260 contains `height; 32px` (semicolon instead of colon), which silently invalidates this CSS property. The `.imageClassement` class will not have its height set.

```css
.imageClassement{
    width: 32px;
    height; 32px;  /* BUG: semicolon should be colon */
}
```

### [FE-R1-022] MEDIUM `includes/style.php:87-90` -- Vendor-prefixed gradients without standard syntax

The `hr` rule uses `-webkit-linear-gradient`, `-moz-linear-gradient`, `-ms-linear-gradient`, and `-o-linear-gradient` but does not include the unprefixed `linear-gradient()` standard syntax as a fallback. Modern browsers require the unprefixed version.

```css
hr {
    background-image: -webkit-linear-gradient(left, #f0f0f0, #8c8b8b, #f0f0f0);
    background-image: -moz-linear-gradient(left, #f0f0f0, #8c8b8b, #f0f0f0);
    /* Missing: background-image: linear-gradient(to right, ...); */
}
```

### [FE-R1-023] MEDIUM `convertisseur.html:1-12` -- Debug/test page with console.log in production

`convertisseur.html` is a bare HTML page with a textarea and a button that logs its value to `console.log()`. It has no doctype, no `<head>`, no meta tags, and appears to be a debug tool left in production. It should be removed or access-restricted.

### [FE-R1-024] MEDIUM `marche.php:390-405` + `attaquer.php:212-244` + `armee.php:270-304` -- Inline scripts with `setInterval` never cleared

Multiple pages generate `setInterval()` timers per database row (one per active action/formation/attack). These intervals are never cleared with `clearInterval()`. If the page state changes without a full reload (e.g., Framework7's pushState routing), timers accumulate and attempt to update non-existent DOM elements, causing JS errors.

### [FE-R1-025] MEDIUM `marche.php:392-404` + `attaquer.php:214-227` -- Inline script variable names derived from database IDs

Timer variables are named `valeur{id}` and functions `tempsDynamique{id}()` where `{id}` comes from database auto-increment IDs. While these are integers (safe), the pattern of injecting database values directly into JavaScript identifier names is fragile. A non-numeric ID would break JS syntax.

### [FE-R1-026] MEDIUM `marche.php:400-401` + `attaquer.php:222-223` -- `document.location.href` redirect on timer expiry

When countdown timers reach zero, the page auto-redirects via `document.location.href="marche.php"` (or `attaquer.php`). Multiple simultaneous timers could trigger near-simultaneous redirects. There is no guard to prevent redirect storms.

### [FE-R1-027] MEDIUM `marche.php:460` + `marche.php:481` -- Inline `onChange` event handlers in HTML attributes

Form select elements use `onChange="majAchat(true)"` and `onChange="majVente(true)"` as inline event handlers. This violates CSP best practices (only works because of `'unsafe-inline'`) and mixes behavior with markup.

### [FE-R1-028] MEDIUM `includes/basicpublichtml.php:13-16` -- Non-specific alt text on menu images

All menu item images in the public sidebar use `alt="armee"` regardless of what the image actually depicts (accueil, sinscrire, sinstruire, regles, classement, forum). This is an accessibility violation as screen readers will announce "armee" for every menu icon.

### [FE-R1-029] MEDIUM `includes/basicprivatehtml.php:231-286` -- Generic alt text "checklist" on all private menu icons

All sidebar menu icons in the authenticated view use `alt="checklist"` for every image, regardless of whether the icon represents disconnection, moderation, constructions, army, market, etc. Screen readers will announce "checklist" for every menu item.

### [FE-R1-030] MEDIUM `attaquer.php:329-365` -- Map images missing proper alt text

Map grid images (players and empty tiles) use either no alt attribute or non-descriptive positioning information. Player tiles on the map use `<img src="images/carte/..." />` (inline styles for positioning) without meaningful alt text. Empty tiles have no alt text at all.

### [FE-R1-031] MEDIUM `includes/tout.php:31-32` -- Fixed-width banner image positioning via JS

Line 32 of `copyright.php` uses `document.getElementById('titre').style.marginLeft = window.innerWidth*0.32-105+"px"` to position the banner. This runs once on load and does not update on resize, meaning the banner will be mispositioned after any window resize or device rotation.

### [FE-R1-032] MEDIUM `includes/meta.php:1+5` -- Duplicate charset declarations

Both `<meta charset="utf-8">` (line 1) and `<meta http-equiv="content-type" content="text/html; charset=utf-8" />` (line 5) declare UTF-8 encoding. The `<meta charset>` tag alone is sufficient and recommended by HTML5. Having both is redundant.

### [FE-R1-033] MEDIUM `js/googleCharts.js` -- Empty file (0 bytes)

The file `js/googleCharts.js` exists but is empty (0 bytes). It appears to be a placeholder that was never populated, or its functionality was moved to inline scripts in `marche.php`.

### [FE-R1-034] MEDIUM `js/jquery.smooth-scroll.min.js` -- Unused jQuery plugin on disk

The file `js/jquery.smooth-scroll.min.js` exists in the js/ directory but does not appear to be loaded by any page template. Dead file on the web server.

### [FE-R1-035] MEDIUM `includes/copyright.php:49-57` -- Player list for autocomplete dumped into inline JS

All player login names are dumped into a JavaScript array on every authenticated page load (`var joueurs = [...]`). This leaks the full list of all registered player names to every logged-in user and increases page size proportionally to player count.

---

## LOW Findings

### [FE-R1-036] LOW `includes/style.php:98-100` -- Vendor-prefixed `box-shadow` without need

The `.lienFormule` class uses `-webkit-box-shadow` and `-moz-box-shadow` prefixes alongside the standard `box-shadow`. These prefixes have been unnecessary since Chrome 10+ and Firefox 4+ (circa 2011).

### [FE-R1-037] LOW `includes/style.php:50-53` -- Using `0px` instead of `0`

Multiple rules use `padding-bottom: 0px !important` where `0` alone suffices and is more concise. Minor code cleanliness issue.

### [FE-R1-038] LOW `includes/copyright.php:114-132` -- `nFormatter` and `symboleEnNombre` utility functions defined in inline script

These general-purpose number formatting utilities are defined in an inline `<script>` block within `copyright.php` (which loads on every page). They should be extracted to an external JS file for cacheability and CSP compliance.

### [FE-R1-039] LOW `includes/copyright.php:158-260` -- Name generator code on every page

A procedural name generator (`generate()`, `genererLettre()`, `genererConsonne()` functions, plus large `consonnes`/`voyelles`/`lettres` arrays) is loaded on every single page. This is only used on the registration page (`inscription.php`). It wastes parse time and memory on all other pages.

### [FE-R1-040] LOW `includes/copyright.php:33` -- Framework7 initialized with deprecated `swipePanel` option

`new Framework7({swipePanel: 'left', ...})` uses the Framework7 v1 API. The `swipePanelActiveArea: 40` at line 33 is also a v1 option. If the Framework7 version is ever updated, these will break silently.

### [FE-R1-041] LOW `includes/copyright.php:34` -- `Dom7` aliased as `$$` pollutes global scope

`var $$ = Dom7;` creates a global variable that could conflict with other libraries or developer tools that use `$$` (e.g., Chrome DevTools uses `$$` as a query selector shortcut).

### [FE-R1-042] LOW `includes/style.php:149` -- CSS `font-family: default` is not a valid value

Line 149 uses `font-family: default;` in `.facebook-card .card-header`. There is no CSS font family named "default". The browser will ignore this declaration. The intent was likely `font-family: inherit` or `font-family: sans-serif`.

### [FE-R1-043] LOW `includes/style.php:400` -- Deprecated HTML elements used in help text

The help text strings in `basicprivatehtml.php` reference `<center>` and `<nobr>` elements (lines 400-401 of style reference), which are deprecated in HTML5. These should use CSS (`text-align: center`, `white-space: nowrap`) instead.

### [FE-R1-044] LOW `includes/meta.php:9` -- Favicon uses non-standard `image/x-icon` type for PNG file

The favicon is declared as `type="image/x-icon"` but the file is `images/icone.png` (a PNG image). The correct MIME type for PNG is `image/png`. Using `image/x-icon` for a PNG may cause browsers to reject or misinterpret the favicon.

### [FE-R1-045] LOW `includes/basicprivatehtml.php:74` + multiple locations -- JS redirect via `echo '<script>document.location.href=...'`

Tutorial progression uses `echo '<script>document.location.href="...";</script>'` for redirects (lines 74, 95, 104, 112, 139, 148, 160). PHP `header('Location: ...')` redirects would be more reliable, faster, and work without JavaScript. The current approach also means the redirect happens mid-page-render, causing a flash of partial content.

### [FE-R1-046] LOW `css/my-app.css:20` -- SVG fragment identifier mismatch in quenya font

The quenya font `@font-face` SVG source uses `#bpmoleculesregular` as the fragment identifier, which is the fragment from the bpmolecules font, not quenya. This would prevent the SVG font from loading correctly in browsers that use the SVG font format.

```css
url("fonts/quenya-webfont.svg#bpmoleculesregular") format("svg");
/* Should likely be: #quenyaregular or similar */
```

### [FE-R1-047] LOW `js/notification.js:28` + `js/notification.js:67-71` -- Alert dialogs in production push notification code

The notification handler uses `alert()` for error conditions (line 28: `alert(error)`, line 67: `alert('GCM error = '+e.msg)`, line 71: `alert('An unknown GCM event has occurred')`). These should be replaced with proper error logging.

### [FE-R1-048] LOW `js/lightbox.js:1-351` -- Lightbox v2.51 is outdated

Lightbox v2.51 by Lokesh Dhakar is from circa 2013. The current version is 2.11.4+. The old version lacks responsive image support, touch/swipe navigation, and accessibility features (ARIA labels, focus trapping).

### [FE-R1-049] LOW `includes/copyright.php:5` -- Copyright footer links to Facebook page via HTTP-upgradable URL

The "Contact" link points to `https://www.facebook.com/The-Very-Little-War-463377203736000/` which is fine (HTTPS), but this Facebook page URL format is the legacy numeric-ID format and may not resolve correctly if Facebook has updated their URL scheme.

### [FE-R1-050] LOW `css/my-app.css:42-43` + `css/my-app.css:50-51` -- Excessive use of `!important`

Multiple rules use `!important` to override Framework7 styles (e.g., `padding-bottom: 0px !important`). This creates specificity wars and makes future maintenance difficult.

### [FE-R1-051] LOW `includes/tout.php:17-19` -- Comment-HTML syntax error in template

Line 17-19 of `tout.php` contains `<!-- commun au publique et au priv` followed by another `<!-- Your main view...` comment. The first comment is never closed properly, which could cause the opening `<div class="views tabs">` to be swallowed by the unclosed comment in certain edge cases.

---

## Recommendations (Priority Order)

1. **Remove dead Cordova code** (FE-R1-006, FE-R1-007, FE-R1-016): Delete `js/notification.js`, `js/PushNotification.js`, and the `cordova.js` script tag. These are artifacts from a defunct mobile app wrapper.

2. **Remove obsolete JS libraries** (FE-R1-009, FE-R1-010, FE-R1-011, FE-R1-012, FE-R1-013, FE-R1-034): Delete `js/sha.js`, `js/sha1.js`, `js/jquery-1.7.2.min.js`, `js/jquery-3.1.1.min.js`, `js/jquery-ui-1.8.18.custom.min.js`, `js/jquery.jcryption.3.1.0.js`, `js/jquery.smooth-scroll.min.js`, `js/aes.js`, `js/aes-json-format.js`, and `js/googleCharts.js` (empty). Remove corresponding `<script>` tags from `copyright.php`.

3. **Fix script loading position** (FE-R1-002): Move all `<script>` tags from after `</html>` to just before `</body>` inside the body tag.

4. **Add SRI to all external scripts** (FE-R1-004): Add `integrity` and `crossorigin` attributes to Google Charts and MathJax script tags.

5. **Fix CSP header** (FE-R1-003, FE-R1-005): Add `https://www.gstatic.com` to `script-src`. Plan a roadmap to extract inline scripts to external files and replace `'unsafe-inline'` with nonce-based CSP.

6. **Fix CSS issues** (FE-R1-018, FE-R1-019, FE-R1-020, FE-R1-021, FE-R1-046): Fix the truncated `my-app.css`, deduplicate font declarations, add `font-display: swap`, fix the `height;` typo, fix the SVG fragment ID.

7. **Add debouncing to molecule stats AJAX** (FE-R1-015): Wrap `actualiserStats()` in a debounce (200-300ms) to prevent flooding `api.php`.

8. **Fix accessibility** (FE-R1-028, FE-R1-029, FE-R1-030): Replace generic alt text with descriptive alternatives for all menu icons and map elements.

9. **Extract inline JS to external files** (FE-R1-038, FE-R1-039): Move `nFormatter`, `symboleEnNombre`, name generator, and other utility code to a cached external JS file.

10. **Remove debug artifacts** (FE-R1-023): Delete `convertisseur.html` or restrict access.
