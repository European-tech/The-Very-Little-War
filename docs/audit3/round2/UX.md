# UX Deep-Dive -- Round 2

**Audit scope**: User flows, interaction patterns, error recovery, feedback, mobile UX, information architecture
**Files analyzed**: ui_components.php, menus.php (basicprivatehtml.php sidebar), basicpublichtml.php, tutoriel.php, constructions.php, armee.php, attaquer.php, marche.php, compte.php, display.php, cardsprivate.php, tout.php, joueur.php, ressources.php, atomes.php
**Date**: 2026-03-03
**Round 1 reference**: 42 issues found (no confirmation dialogs, non-semantic buttons, fixed 600x300 map)

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 6     |
| HIGH     | 18    |
| MEDIUM   | 24    |
| LOW      | 11    |
| **Total**| **59**|

---

## CRITICAL -- Blocks core functionality or causes data loss

### UX-R2-001 -- submit() renders `<a>` tags instead of `<button>` for all form submissions
- **Severity**: CRITICAL
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/ui_components.php` line 574
- **User impact**: Every single form action in the game (construct buildings, form molecules, attack, buy/sell on market, change password, delete account) uses `submit()` which renders `<a class="button" href="javascript:document.FORMNAME.submit()">`. This is a non-semantic anchor pretending to be a button. Consequences: (1) Screen readers announce these as links, not buttons. (2) Keyboard users pressing Enter on a focused `<a>` will navigate, not submit. (3) Form submission bypasses HTML5 validation entirely because `form.submit()` via JS skips `required`, `min`, `max`, `pattern` constraints. (4) If JavaScript fails or loads slowly, every submit button is a dead link.
- **Fix proposal**: Replace the `submit()` function body to emit `<button type="submit">` inside the form. For link-style submits, use `formaction` attribute. Fallback: at minimum add `role="button"` and `tabindex="0"` with `onkeydown` handler, but true `<button>` is the correct approach.

```php
// Current (broken):
return $nom . '<a class="button ..." href="javascript:document.' . $options['form'] . '.submit()">' ...

// Proposed:
return '<button type="submit" class="button ..." style="...">' . $image1 . $titre . $image2 . '</button>';
```

### UX-R2-002 -- No confirmation dialog on destructive actions
- **Severity**: CRITICAL
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/armee.php` line 365, `/home/guortates/TVLW/The-Very-Little-War/compte.php` line 202
- **User impact**: Deleting a molecule class (which destroys all molecules of that class, removes active formations, and modifies pending attacks) is done by clicking a tiny 32x32 image button with no confirmation. Account deletion from the settings page shows a confirmation step, but the initial "Supprimer le compte" button also has no "are you sure?" before proceeding to the confirmation page. A single accidental tap on mobile deletes an entire army class with no undo.
- **Affected actions**:
  - Delete molecule class: single tap on `<input type="image" src="images/croix.png">` (32x32px touch target)
  - Launch attack: single tap on `<a>` styled button, no "You are about to attack X with Y troops" summary
  - Cancel vacation: no confirmation on date change
- **Fix proposal**: Add `onclick="return confirm('Supprimer cette classe de molecules et toutes les molecules associees ?')"` as immediate mitigation. For proper implementation: add a Framework7 modal confirm dialog that shows what will be lost.

### UX-R2-003 -- Map is hardcoded 600x300px, completely broken on mobile
- **Severity**: CRITICAL
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/attaquer.php` line 329
- **User impact**: The map container is `<div style="width:600px;height:300px;">` with absolute-positioned tiles. On a 375px wide phone screen, over 37% of the map is clipped. The scroll overflow comes from the card's `overflow-x:scroll;overflow-y:scroll` but this creates a scroll-within-scroll situation that is extremely difficult to use on touch devices (the outer page scrolls instead of the inner map). Players cannot find or select targets on the map from mobile, which is the primary platform.
- **Fix proposal**: Replace with a responsive container (`width:100%; max-width:600px`) and either use CSS transform/zoom or a proper tile-based map library. Minimum: set `width:100%; aspect-ratio:2/1;` and scale tiles with `transform: scale(calc(var(--container-width) / 600))`.

### UX-R2-004 -- Molecule class deletion via type="image" is a 32x32 touch target
- **Severity**: CRITICAL
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/armee.php` line 365
- **User impact**: The destructive "delete molecule class" action is an `<input type="image" src="images/croix.png" class="w32">` floated right. At 32x32 CSS pixels this is far below the minimum 44x44px touch target recommended by Apple and 48x48dp by Google Material. On mobile, users will frequently mis-tap, accidentally deleting their army classes instead of hitting the adjacent "Former" button.
- **Fix proposal**: Increase touch target to minimum 44x44px. Wrap in a button element with padding. Add confirmation dialog (see UX-R2-002).

### UX-R2-005 -- Attack form submits without any confirmation or summary
- **Severity**: CRITICAL
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/attaquer.php` line 421
- **User impact**: Launching an attack (an irreversible action that spends energy and commits troops) happens with a single click on `submit(['titre' => 'Attaquer'])`. There is no confirmation dialog showing: target name, total troops committed, energy cost, estimated travel time, or the consequence (troops leave your base). A mis-click or accidental tap sends your entire army. The error message for attacking yourself is the unprofessional "T'y as cru?".
- **Fix proposal**: Show a confirmation modal before `formAttaquer.submit()` that summarizes: target, number of troops per class, energy cost, travel time. Replace "T'y as cru?" with a proper message like "Vous ne pouvez pas effectuer cette action."

### UX-R2-006 -- CSRF check at top of compte.php triggers on every GET request
- **Severity**: CRITICAL
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/compte.php` line 6
- **User impact**: Line 6 calls `csrfCheck()` unconditionally, not wrapped in `if($_SERVER['REQUEST_METHOD'] === 'POST')`. This means loading compte.php via GET will trigger the CSRF check, which will fail because there is no CSRF token in a GET request. The only reason this might not crash is if `csrfCheck()` only validates when a token is present, but this is fragile and non-standard. If it does validate unconditionally, users cannot access their account settings page at all.
- **Fix proposal**: Wrap the line 6 `csrfCheck()` inside `if ($_SERVER['REQUEST_METHOD'] === 'POST')`. Each subsequent POST block already calls `csrfCheck()` individually, so the top-level one is redundant or harmful.

---

## HIGH -- Significantly degrades user experience

### UX-R2-007 -- No loading/pending state for construction or formation actions
- **Severity**: HIGH
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/constructions.php`, `/home/guortates/TVLW/The-Very-Little-War/armee.php`
- **User impact**: When a user clicks "Niveau X" to upgrade a building or "Former" to train molecules, the form does a full page POST with no visual feedback. On slow connections, users double-click/double-tap, potentially causing duplicate submissions. The page reloads entirely, losing scroll position. In constructions, the user is redirected to `constructions.php?information=...` which shows a success message, but during the redirect there is a blank white screen.
- **Fix proposal**: Add `onclick="this.disabled=true; this.innerText='En cours...';"` to submit buttons as immediate mitigation. Long-term: use AJAX submissions with loading spinners and optimistic UI updates.

### UX-R2-008 -- Error and success messages passed via URL query string are lost on navigation
- **Severity**: HIGH
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/constructions.php` line 37, `/home/guortates/TVLW/The-Very-Little-War/includes/cardsprivate.php` line 74
- **User impact**: After successful construction, the user is redirected to `constructions.php?information=La+construction+a+bien+ete+lancee`. If the page auto-refreshes (which it does via setInterval when construction completes), or if the user navigates away and back, the message disappears. Worse, in cardsprivate.php lines 74/95/104/112/139/148/160, tutorial completions use `<script>document.location.href="page.php?deployer=true&information=..."</script>` which is a JS redirect after HTML has already started rendering, causing a flash of content before redirect.
- **Fix proposal**: Use `$_SESSION['flash_message']` pattern: store messages in session, display once on next page load, then clear. This survives redirects properly and does not leak information into URLs.

### UX-R2-009 -- Two completely separate tutorial systems running in parallel
- **Severity**: HIGH
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/cardsprivate.php` (old tutorial, levels 1-9), `/home/guortates/TVLW/The-Very-Little-War/tutoriel.php` (new tutorial, 7 missions)
- **User impact**: New players encounter the OLD tutorial system (niveaututo 1-9 in cardsprivate.php) which forces them through a rigid step-by-step flow using JS redirects. Once they complete that (niveaututo=10), they encounter the MISSIONS system (also in cardsprivate.php, 19 missions shown 3 at a time). SEPARATELY, tutoriel.php defines a completely independent 7-mission system with its own claim mechanism. The old tutorial auto-advances by detecting page visits, while the new tutorial requires explicit "Valider la mission" clicks. This creates confusion about which system they are following.
- **Fix proposal**: Deprecate the old cardsprivate.php tutorial system (niveaututo 1-9). Migrate all new players directly to the tutoriel.php mission system which has proper UX (progress bar, explicit claims, descriptions, step-by-step instructions). Set niveaututo=10 for all new accounts on creation.

### UX-R2-010 -- Old tutorial uses JS redirects that cause content flashing
- **Severity**: HIGH
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/cardsprivate.php` lines 74, 95, 104, 112, 139, 148, 160
- **User impact**: When a tutorial condition is met (e.g., user visits classement.php while at niveaututo==2), the system awards resources via DB updates and then emits `<script>document.location.href="classement.php?..."</script>`. Since this code runs during the page body output (after `<body>` has started), the user sees a partial page flash, then a full redirect, then the final page. This happens on every tutorial transition.
- **Fix proposal**: Move tutorial checks before any HTML output. Use `header('Location: ...')` followed by `exit()` instead of JS redirects.

### UX-R2-011 -- Market buy/sell has no "Max" button or balance preview
- **Severity**: HIGH
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/marche.php` lines 449-490
- **User impact**: When buying atoms, the user must manually calculate how many they can afford. There is a bidirectional amount/cost calculator in JS, but no "Max affordable" button. The user sees their energy balance only as part of the field label "Cout en energie (1.5K)" which is easy to miss. After a purchase, a full page reload occurs with no indication of the new balance until the page finishes loading. For selling, the available quantity per resource is shown inside the `<select>` option text, but once a resource is selected, it disappears from view.
- **Fix proposal**: Add a "Max" button next to quantity fields (similar to armee.php molecule formation). Show a live balance preview: "After this trade: X energy remaining". Display current holdings prominently above the form.

### UX-R2-012 -- Navigation menu has no visual indicator of current page
- **Severity**: HIGH
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php` lines 231-286
- **User impact**: The left slide-out navigation panel lists 12+ menu items, all styled identically with `'style' => 'color:black'`. There is no highlight, bold, background color, or any visual indicator showing which page the user is currently on. Users must read the page content to know where they are. Framework7 supports `active` class on navigation items for exactly this purpose.
- **Fix proposal**: Detect current page via `$_SERVER['PHP_SELF']` and add `'classe' => 'item-link active'` or a background highlight to the matching menu item. Example: `$isActive = strpos($_SERVER['PHP_SELF'], 'constructions.php') !== false ? 'background:#e3f2fd;' : '';`

### UX-R2-013 -- Resource bar is hidden behind a popover, not directly visible
- **Severity**: HIGH
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/ressources.php` lines 1-9
- **User impact**: The resource bar shows only 3 elements: a generic "Atomes" chip (that opens a popover with all 8 atom counts), the energy counter, and a catalyst indicator. Players must tap the "Atomes" chip to see their actual resource counts. In a resource management game, forcing an extra click to see basic resource information is a significant friction point. The popover also obscures the page content when open.
- **Fix proposal**: Show at minimum the top 3-4 most relevant resources directly in the bar. Use a compact horizontal scrollable row of resource chips. Move the detailed breakdown (with production rates) to the popover, but keep the raw counts visible at all times.

### UX-R2-014 -- Construction accordion requires tapping to see costs, making comparison impossible
- **Severity**: HIGH
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/constructions.php` line 182-194 (mepConstructions)
- **User impact**: Each building is rendered as an accordion item. The building name and current level/revenue are visible, but the upgrade cost, time, and button are hidden inside the accordion. A player wanting to decide which building to upgrade next must open each accordion individually -- there is no way to compare costs side by side. Opening one accordion does not close others (Framework7 v1 default), so the page becomes extremely long.
- **Fix proposal**: Show key metrics (cost, time) in the accordion header or subtitle. Add a summary table at the top showing all buildings with their upgrade costs. Consider using a tabbed interface or expandable card with cost chips visible in the collapsed state.

### UX-R2-015 -- Molecule creation form has no client-side validation or total counter
- **Severity**: HIGH
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/armee.php` lines 312-338
- **User impact**: When creating a molecule class, the user enters atom counts for 8 different resources. There is no running total shown, no indication of the 200-per-atom maximum until after submission fails, and no preview of what the molecule will look like. The stat preview in the bottom toolbar updates via 9 separate AJAX calls per keystroke (each input has `oninput="javascript:actualiserStats()"`), creating lag and excessive server load. The isotope selector has no visual explanation of what each isotope does beyond a short text description.
- **Fix proposal**: Add a visible total counter ("Total atoms: 45 / max 200 each"). Show validation errors inline. Debounce AJAX calls (300ms). Calculate basic stats client-side using the formulas (already exposed via api.php) to reduce server round-trips. Add visual stat bars or a radar chart for the molecule preview.

### UX-R2-016 -- "Former" molecule input uses type="text" instead of type="number"
- **Severity**: HIGH
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/armee.php` line 369
- **User impact**: The molecule formation field `<input type="text" name="nombremolecules">` does not show a numeric keyboard on mobile devices. Users must switch keyboard layouts to enter numbers. Similarly, the neutrino purchase field at line 390 uses `type="text"`. The market fields at lines 461-483 also use `type="text"` for numeric amounts. The attack form correctly uses `type="number"`, showing inconsistency.
- **Fix proposal**: Change all numeric input fields to `type="number"` with appropriate `min`, `max`, and `step` attributes. This triggers the numeric keyboard on mobile and provides built-in validation.

### UX-R2-017 -- Vacation date picker uses placeholder "Selectionnez" with readonly but no JS calendar
- **Severity**: HIGH
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/compte.php` line 182
- **User impact**: The vacation end date field is `<input type="text" readonly id="calVacs">` with placeholder "Selectionnez". The `readonly` attribute prevents typing, and the `id="calVacs"` suggests a JS calendar picker should attach, but there is no corresponding JavaScript in the file. If Framework7's calendar component is not initialized for this element, the field is completely non-functional -- the user cannot set a vacation date at all.
- **Fix proposal**: Either initialize a Framework7 calendar picker with `id="calVacs"`, or change to `<input type="date" min="...">` for native browser date picking. Set `min` to 3 days from now to match the server-side validation.

### UX-R2-018 -- Account deletion confirmation uses unlabeled image buttons
- **Severity**: HIGH
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/compte.php` lines 237-242
- **User impact**: The account deletion confirmation step shows two `<input type="image">` elements: `images/yes.png` and `images/croix.png` with no text labels. If images fail to load, there is no indication which button confirms and which cancels. The alt text is "Oui" and no alt for the cross. The buttons are spaced with `margin-right:80px` inline style, which does not adapt to screen width -- on narrow phones, the cross button may overflow off-screen.
- **Fix proposal**: Replace image buttons with proper text buttons: `<button type="submit" name="oui" class="button button-fill bg-red">Oui, supprimer</button>` and a cancel link. Add explicit warning text about the irreversibility of deletion.

### UX-R2-019 -- Forum badge count can be negative
- **Severity**: HIGH
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php` line 279
- **User impact**: The unread forum topics badge calculates `$nbSujets['nbSujets'] - $statutForum['nbLus']`. If a topic is deleted after a user has read it, the read count could exceed the current topic count, producing a negative badge number. The check at line 279 is `> 0`, which prevents display of negative badges, but the `$messagePlus` variable is reused from the messages section (line 264), so if the forum badge is not set (<=0), it falls through to `$messagePlus = ''` which is correct. However, the variable reuse of `$messagePlus` at line 280 shadows the messages badge variable from line 264, which could display stale data.
- **Fix proposal**: Use separate variables `$forumBadge` and `$messagesBadge` to avoid shadowing. Add `max(0, ...)` to the badge calculation for safety.

### UX-R2-020 -- No back/cancel button on molecule creation form
- **Severity**: HIGH
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/armee.php` lines 311-338
- **User impact**: When the user clicks "+" to create a new molecule class, the entire page changes to show the composition form. There is no back button, cancel button, or breadcrumb. The only way to abort molecule creation is to use browser back or navigate via the menu. If the user started entering values and wants to cancel, there is no way to do so within the page. The bottom toolbar also changes to show stat previews, replacing the normal Formation/Vue d'ensemble tabs.
- **Fix proposal**: Add a visible "Annuler" button that links back to `armee.php`. Show it prominently at the top or bottom of the form. Restore the sub-navigation tabs.

### UX-R2-021 -- Energy cost chip shows "green" even when insufficient for construction
- **Severity**: HIGH
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/display.php` lines 207-216 (coutEnergie function)
- **User impact**: The `coutEnergie()` function compares `$ressources['energie'] >= $cout` to decide green vs red chip color. However, `$ressources` is a global variable that may be stale after multiple actions on the same page load. If a player starts a construction (deducting energy) and the page re-renders the construction list without re-fetching resources, remaining buildings may show green (affordable) when they are actually red (unaffordable). The same issue affects `coutAtome()` and `coutTout()`.
- **Fix proposal**: Re-fetch resources after any action that modifies them, or pass the current resource values explicitly to cost display functions rather than relying on a global.

### UX-R2-022 -- Attack page shows espionage actions in the attacks table with no filter
- **Severity**: HIGH
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/attaquer.php` line 195
- **User impact**: The pending actions table at the top of attaquer.php queries `SELECT * FROM actionsattaques WHERE attaquant=? OR defenseur=?` with no filter. This mixes attacks, espionage, and return trips in one table. The only differentiation is the icon (sword vs binoculars). For active players with many pending actions, this table becomes confusing. Additionally, incoming attacks from other players show as a shield icon with "?" for the time, giving no useful information beyond "someone is coming."
- **Fix proposal**: Split into two sections: "Attacks" and "Espionage". For incoming attacks, consider showing an approximate time range instead of just "?".

### UX-R2-023 -- Send resources form has no autocomplete for recipient name
- **Severity**: HIGH
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/marche.php` line 426
- **User impact**: The "Destinataire" field for sending resources is a plain text input with no autocomplete or player name suggestions. A user must type the exact player name including correct capitalization. A typo results in a full page reload with "Le destinataire n'existe pas" error and all entered amounts are lost. The IP check at line 137 produces the cryptic "Impossible d'envoyer des ressources a ce joueur. Meme adresse IP." without explaining why.
- **Fix proposal**: Add an autocomplete dropdown querying `api.php` for player names. Pre-fill the recipient field when coming from a player profile. Preserve form values on error using `value="<?= htmlspecialchars($_POST['...']) ?>"`.

### UX-R2-024 -- Profile photo upload has cryptic dimension requirements
- **Severity**: HIGH
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/compte.php` lines 96-134
- **User impact**: The photo upload section shows "Photo de profil (150x150)" as title, but the actual validation on line 122 rejects images larger than 150x150. There is no client-side preview, no cropping tool, and no indication of the maximum file size (2MB) until after a failed upload. The error messages are returned as generic text with no guidance on how to fix the issue. Most users taking phone photos will have images thousands of pixels wide.
- **Fix proposal**: Add client-side image preview and validation. Consider server-side resizing (ImageMagick/GD) to automatically scale down photos instead of rejecting them. Show requirements clearly: "Image JPG/PNG/GIF, max 150x150px, max 2 Mo".

---

## MEDIUM -- Noticeable friction or confusion

### UX-R2-025 -- Old tutorial forces rigid linear progression with no skip option
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/cardsprivate.php` lines 64-165
- **User impact**: The old tutorial (niveaututo 1-9) forces strict sequential progression. A player cannot skip ahead even if they already know the game (e.g., returning player after a season reset). The tutorial card appears at the top of every page. There is no "Skip tutorial" or "I already know how to play" option.
- **Fix proposal**: Add a "Passer le tutoriel" button that sets niveaututo=10 immediately. Show it on the first tutorial card (niveaututo==1).

### UX-R2-026 -- Popover help icons are 20x20px, below mobile touch minimum
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/ui_components.php` lines 591-593
- **User impact**: Help icons rendered by `aide()` and `popover()` are 20x20px images. On mobile, these are extremely difficult to tap accurately. The `<a>` wrapper has no padding to increase the touch target.
- **Fix proposal**: Increase icon size to 24px and add `padding: 12px` to the anchor, creating a 48px total touch target. Use `display:inline-block` to ensure padding is respected.

### UX-R2-027 -- progressBar() uses 3 `<br>` tags for spacing instead of CSS
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/ui_components.php` lines 476-484
- **User impact**: The progress bar component starts with `<br/><br/><br/>` to create vertical spacing. This is fragile, non-semantic, and produces unpredictable visual results depending on context. The progress bar itself uses `style="height:6px"` which may be too thin to be visible on some displays. The `<center>` tag inside is deprecated HTML.
- **Fix proposal**: Replace `<br/><br/><br/>` with `margin-top: 24px`. Replace `<center>` with `text-align:center`. Increase bar height to at least 8px. Use a proper container `<div>` with CSS spacing.

### UX-R2-028 -- Construction queue shows raw timestamps with no relative context
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/constructions.php` line 315
- **User impact**: The construction queue table shows "Fin: 14h35" as an absolute time. If the user is in a different timezone than the server, or if the construction finishes the next day, the absolute time is misleading. There is no date shown when the finish time is the next day. The dynamic countdown timer is good, but the "Fin" column should show a full date-time.
- **Fix proposal**: Use `date('d/m H\hi', $actionsconstruction['fin'])` to include the date when the construction finishes after midnight. Consider also showing "dans 2h35" relative format.

### UX-R2-029 -- Market price chart depends on external Google Charts CDN with no fallback
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/marche.php` line 560
- **User impact**: The market price history chart loads from `https://www.gstatic.com/charts/loader.js`. If this CDN is blocked (corporate firewalls, certain countries), the chart silently fails to render, showing a blank 400px-tall white space. There is no fallback display showing the current prices in tabular form when the chart fails.
- **Fix proposal**: Add an `onerror` fallback on the script tag. Show a simple HTML table of current prices as fallback content that gets replaced when the chart loads successfully.

### UX-R2-030 -- Building upgrade shows "Assez de ressources le DD/MM/YYYY a HHhMM" but this prediction is wrong
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/constructions.php` lines 162-166
- **User impact**: When a player cannot afford an upgrade, the system calculates when they will have enough resources based on current production rates. However, this calculation at line 162-166 does not account for: energy drainage from the producteur, resource storage caps, molecule decay consuming resources, or pending construction/formation costs. The predicted date can be significantly wrong, misleading players.
- **Fix proposal**: Add a disclaimer "(estimation)" next to the date. Account for energy drainage in the calculation. If the prediction exceeds the storage cap scenario, show "Augmentez d'abord votre stockage".

### UX-R2-031 -- Molecule formation "Max" button uses direct DOM index, fragile
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/armee.php` line 369
- **User impact**: The "Max" button uses `document.getElementsByName('nombremolecules')[<?php echo $compteur; ?>].value = ...`. This assumes the DOM order matches the counter. If the HTML structure changes or there are hidden form fields, the index becomes wrong and the "Max" button fills the wrong input field. The current implementation also does not trigger the `input` or `change` event, so any listeners will not fire.
- **Fix proposal**: Use unique IDs per class: `id="nombremolecules-<?= $molecule['numeroclasse'] ?>"` and `document.getElementById('nombremolecules-' + classId)`. Dispatch an `input` event after setting the value.

### UX-R2-032 -- Public landing page sidebar menu has no login link
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublichtml.php`
- **User impact**: The public page sidebar menu shows: Accueil, S'inscrire, S'instruire, CGU, Classement, Forum. There is no explicit "Se connecter" / "Login" link. Users must find the login form somewhere on the page content. For returning players, the most important action (logging in) is not in the navigation.
- **Fix proposal**: Add a "Se connecter" menu item with a prominent style at the top of the public sidebar.

### UX-R2-033 -- Menus.php is actually an ad panel, not a proper menu component
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/menus.php`
- **User impact**: The file named `menus.php` is misleading -- it contains a commented-out Google AdSense block, a news section, statistics panel, and screenshots gallery. This is not a menu at all but a public sidebar content panel. The actual navigation menus are embedded in basicprivatehtml.php and basicpublichtml.php inline.
- **Fix proposal**: Rename to `sidebar_public.php` or similar. Extract actual menu items into a dedicated menu component for clarity and reusability.

### UX-R2-034 -- Resource amounts use K/M/G abbreviations unfamiliar to French players
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/display.php` lines 70-122
- **User impact**: The `chiffrePetit()` function abbreviates large numbers using SI prefixes (K, M, G, T, P, E, Z, Y). While K and M are universal, G/T/P are not intuitive for casual French gamers. The corresponding `transformInt()` function allows user input with these suffixes, but there is no explanation shown to users that they can type "10K" instead of "10000". The title attribute on abbreviated numbers shows the full value, which is good but not discoverable on touch devices (no hover).
- **Fix proposal**: Add a small tooltip or (?) hint near the first input field explaining the K/M shortcuts. Consider showing the full number on long-press for mobile. For display, consider using "10 000" (French number formatting with spaces) for numbers under 1M and only abbreviating above that.

### UX-R2-035 -- Attack troop selection has no "Select all" or percentage buttons
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/attaquer.php` lines 407-422
- **User impact**: When selecting troops for an attack, each molecule class has an individual number input with a "Max" link. There is no way to select all troops across all classes at once, or to select a percentage (50%, 75%). Players launching a full attack must click "Max" on each class individually. The "Max" link uses `javascript:document.getElementById('nbclasse' + id).value = N;actualiseTemps();actualiseCout();` which is functional but not a proper control.
- **Fix proposal**: Add a "Tout envoyer" button that fills all fields to max and updates timers/costs. Consider adding percentage buttons (25%, 50%, 75%, 100%).

### UX-R2-036 -- Card component `debutCarte()`/`finCarte()` uses echo, preventing composition
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/ui_components.php` lines 7-53
- **User impact**: The card component functions directly `echo` their HTML. This makes it impossible to compose cards inside other UI structures, capture output for conditionals, or build complex layouts. The `item()` function has a `retour` option to return instead of echo, but `debutCarte`/`finCarte` do not. This leads to fragile code where the order of echo calls must be precisely maintained.
- **Fix proposal**: Add a `$return = false` parameter to `debutCarte()` and `finCarte()`, similar to `debutContent()`. When true, return the HTML string instead of echoing it.

### UX-R2-037 -- Espionage form shows neutrino count but no guidance on how many to send
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/attaquer.php` lines 496-518
- **User impact**: The espionage form shows the player's neutrino count and a number input with min=0 max=neutrinos, but gives no guidance on how many neutrinos are needed for successful espionage. The game mechanics presumably use neutrino count to determine success probability, but this is not communicated. A new player might send 1 neutrino and waste the energy/time on a failed mission.
- **Fix proposal**: Add a brief explanation: "Plus vous envoyez de neutrinos, plus les informations recoltees seront detaillees." Show recommended amounts or success probability ranges.

### UX-R2-038 -- Market "Envoyer" form is on a separate sub-tab with no visual connection to trading
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/marche.php` line 411, `/home/guortates/TVLW/The-Very-Little-War/includes/tout.php` lines 53-62
- **User impact**: The market has two bottom toolbar tabs: "Echanger" (buy/sell) and "Envoyer" (send to player). These are entirely separate pages (sub=0 vs sub=1). The "Envoyer" tab has no visible exchange rate table, no explanation of delivery time mechanics, and no indication of what percentage of resources will actually arrive. The tooltip for "Cours d'envoi" only appears when clicking a small (?) icon.
- **Fix proposal**: Show the delivery ratio/formula prominently on the send page. Add the recipient's current production level to help users understand what percentage will arrive. Consider merging "Envoyer" as a section below the buy/sell forms since they share the same context.

### UX-R2-039 -- Player profile attack/spy/message buttons have no disabled state
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/joueur.php` lines 67-74
- **User impact**: On a player's profile, the "Attaquer", "Espionner", "Message", and "Medailles" buttons are always shown. If the player is on vacation, under beginner protection, or if the viewing player has no army, the buttons still appear. Clicking "Attaquer" redirects to the attack form which then shows an error. This creates a frustrating click-error-backtrack loop.
- **Fix proposal**: Check conditions before showing action buttons. Dim or disable "Attaquer" with a tooltip "(Protection debutant)" or "(En vacances)". Hide "Espionner" if the player has 0 neutrinos, or show it grayed with "(Pas de neutrinos)".

### UX-R2-040 -- No page title differentiation -- all pages show the same banner
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/tout.php` line 31
- **User impact**: The navbar always shows `images/banniere.png` fixed at top center. There is no text page title (e.g., "Constructions", "Armee", "Marche") in the header. Users must read the page content or remember what they clicked to know which page they are on. Combined with UX-R2-012 (no active menu item), orientation within the game is poor.
- **Fix proposal**: Add a page title variable set by each page (`$pageTitle = "Constructions"`) and display it in the navbar center alongside or instead of the banner image. On mobile, the page title is more important than the game logo.

### UX-R2-041 -- Vacation mode deletes formation queue with no warning
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/compte.php` line 18
- **User impact**: Activating vacation mode runs `DELETE FROM actionsformation WHERE login = ?` which destroys all pending molecule formations. The warning text says "La mise en vacance supprimera tout ordre de production de molecule en cours" but this is a `<div class="content-block">` that can be easily overlooked inside the form. For a player with hours of pending formations, this is a significant loss.
- **Fix proposal**: Show the warning more prominently (red text, bold, with the specific count of pending formations). Add a confirmation dialog listing what will be lost.

### UX-R2-042 -- Password change form has no strength indicator or minimum requirements
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/compte.php` lines 33-71
- **User impact**: The password change form accepts any password with no minimum length, complexity, or strength indication. The server-side code does not validate password length -- a single-character password would be accepted. There is no visual feedback while typing (strength meter, requirements checklist). The confirmation field mismatch error is only shown after full page reload.
- **Fix proposal**: Add minimum password length (8 characters). Show a real-time password strength indicator. Validate confirmation match client-side before submission.

### UX-R2-043 -- Construction points allocation UI not described in code
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/constructions.php` lines 6-80
- **User impact**: The production point allocation form (pointsProducteur and pointsCondenseur) accepts POST data for each resource type but there is no visible UI rendering code in constructions.php for displaying the allocation interface. The form expects `nbPointshydrogene`, `nbPointscarbone`, etc. but the accordion content in `mepConstructions()` does not include these inputs. This suggests the allocation UI is rendered somewhere else (possibly inside the producteur accordion content via dynamic HTML), making it hard to find and potentially inconsistent.
- **Fix proposal**: Centralize the point allocation UI in a clearly named function. Show it as a dedicated section under the Producteur/Condenseur accordions with clear +/- buttons and a "points remaining" counter.

### UX-R2-044 -- Chat/Forum BBcode toolbar shows only "BBcode active" text with no buttons
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/display.php` lines 334-339
- **User impact**: The `creerBBcode()` function was simplified to just display "BBcode active" with a help icon. There are no formatting buttons (bold, italic, color) above the textarea. Users must memorize BBcode syntax or click the (?) help icon to see the reference. The help popover shows the full BBcode reference, which is helpful but requires back-and-forth reading while typing.
- **Fix proposal**: Add basic formatting buttons: [B], [I], [U], [color] that insert BBcode tags at cursor position in the textarea. This is standard for any BBcode-enabled text area.

### UX-R2-045 -- Real-time resource counters can show stale/incorrect values after actions
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php` lines 427-463
- **User impact**: The JavaScript resource tickers update every second based on production rates calculated at page load. After an action that changes resources (buying on market, launching construction, forming molecules), the tickers continue from the original values. Only a full page reload shows correct values. Players see misleading resource counts that do not reflect their actual holdings until the next navigation.
- **Fix proposal**: After any form submission, either: (a) do a full page reload (current behavior), which is OK but slow, or (b) update the JS variables via an inline script in the response that sets the correct post-action values. Alternatively, expose a lightweight `/api.php?id=resources` endpoint and periodically re-sync.

### UX-R2-046 -- Error messages use inconsistent tone and language
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/attaquer.php` line 51, line 178
- **User impact**: Error messages range from professional ("Vous n'avez pas assez d'energie.") to colloquial/mocking ("T'y as cru?"). The latter appears when required fields are empty, which is a likely result of a UI bug, not user malice. This unprofessional tone damages trust. Other examples of inconsistent messages: "Seul des nombres positifs et entiers doivent etre entres" (grammatically incorrect -- should be "Seuls des nombres").
- **Fix proposal**: Audit all error messages for consistent, professional tone. Replace "T'y as cru?" with "Veuillez remplir tous les champs requis." Fix grammar: "Seuls des nombres entiers et positifs sont acceptes."

### UX-R2-047 -- Chip component has hardcoded 3px margins, no responsive scaling
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/ui_components.php` line 463
- **User impact**: The `chip()` function uses `style="margin-right:3px;margin-left:3px;"` which creates very tight spacing between chips. On resource displays with many chips, they can visually merge. The chip media section uses fixed pixel sizes (25x25px) that do not scale with text size or screen density.
- **Fix proposal**: Use CSS classes instead of inline styles. Increase chip margins to 4-6px. Use `rem` units for sizing to scale with user font preferences.

### UX-R2-048 -- Number formatter JS function `nFormatter` referenced but not defined in visible code
- **Severity**: MEDIUM
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php` line 436
- **User impact**: The dynamic resource counter calls `nFormatter(Math.floor(valeur))` but this function is not defined in basicprivatehtml.php. It must be loaded from an external script file. If that file fails to load, all resource counters will show "undefined" or cause JS errors that break other functionality on the page.
- **Fix proposal**: Define `nFormatter` inline as a fallback, or add an error handler: `typeof nFormatter === 'function' ? nFormatter(val) : Math.floor(val).toLocaleString()`.

---

## LOW -- Minor polish or edge cases

### UX-R2-049 -- Checkbox component returns HTML but is never used
- **Severity**: LOW
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/ui_components.php` lines 404-448
- **User impact**: The `checkbox()` function is defined but a search shows it is not called anywhere in the codebase. Dead code that adds maintenance burden and confusion.
- **Fix proposal**: Remove the function or mark it as `@deprecated` with a comment explaining why it exists.

### UX-R2-050 -- Slider component exists but is not used anywhere
- **Severity**: LOW
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/ui_components.php` lines 486-518
- **User impact**: The `slider()` function generates a range input but is not called anywhere in the game. It could be useful for molecule composition or point allocation, but is unused.
- **Fix proposal**: Either integrate sliders into point allocation UI or remove the function.

### UX-R2-051 -- Banner image is fixed position with hardcoded pixel offsets
- **Severity**: LOW
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/tout.php` line 31
- **User impact**: The game banner uses `style="position:fixed;top:10px;left:15%;width:260px;height:27px;"`. The `left:15%` positioning means on very wide screens the banner is too far right, and on narrow screens it overlaps with the hamburger menu. The 260x27px size is hardcoded and does not scale.
- **Fix proposal**: Use flexbox centering: remove `position:fixed`, use `margin:0 auto` or `text-align:center` within the navbar-inner. Add `max-width:100%` for responsive scaling.

### UX-R2-052 -- Screenshot gallery on public page uses lightbox with no JS library loaded
- **Severity**: LOW
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/menus.php` lines 66-69
- **User impact**: Screenshots use `rel="lightbox[screenshoots]"` but the lightbox JS library is likely not loaded on public pages. Clicking a screenshot navigates to the raw image file instead of showing a gallery overlay. Also, "screenshoots" is misspelled (should be "screenshots").
- **Fix proposal**: Either load a lightbox library or use a simple CSS modal. Fix the typo.

### UX-R2-053 -- News section uses hardcoded "A" character instead of accent
- **Severity**: LOW
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/menus.php` line 30
- **User impact**: News dates display as `date('d/m/Y A H\hi', ...)` which outputs "A" literally instead of the French "a" (meaning "at"). This appears as "01/01/2026 A 14h30" instead of "01/01/2026 a 14h30". This is a known encoding issue flagged in previous audits.
- **Fix proposal**: Use `date('d/m/Y', ...) . ' a ' . date('H\hi', ...)` or fix the `A` encoding.

### UX-R2-054 -- Image alt texts are generic or misleading
- **Severity**: LOW
- **Location**: Multiple files, e.g., `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublichtml.php` lines 13-18
- **User impact**: Many images use generic alt text. In basicpublichtml.php, all menu icons use `alt="armee"` regardless of what they represent (home, register, learn, rules, ranking, forum). The display.php `image()` function has a duplicate `alt` attribute: `alt="Energie" src="..." alt="resourcename"`. Screen reader users receive confusing information.
- **Fix proposal**: Set accurate alt text per image: `alt="accueil"`, `alt="inscription"`, `alt="regles"`, etc. Fix the duplicate alt in `image()`.

### UX-R2-055 -- Mobile sidebar covers entire screen with no visible close button
- **Severity**: LOW
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php` line 219-220
- **User impact**: The left panel is a Framework7 "panel-cover" type, which covers the page content entirely on mobile. The only way to close it is to tap the panel-overlay area (which is transparent/semi-transparent) or swipe. There is no explicit close button or "X" icon. New users may not discover how to close the menu.
- **Fix proposal**: Add a close button icon in the top-right of the panel, or switch to `panel-reveal` type which pushes content to the side (more discoverable as a panel).

### UX-R2-056 -- `item()` function link forces panel close on every click
- **Severity**: LOW
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/ui_components.php` line 239
- **User impact**: Every link generated by `item()` includes `onclick="javascript:myApp.closePanel()"`. This means clicking any link in any list (not just navigation menu items) will attempt to close the panel, even if the item is not in a panel. If `myApp` is not yet initialized (script load order), this triggers a JS error.
- **Fix proposal**: Only add the panel-close onclick when the item is explicitly inside a panel. Add a `'closePanel' => true` option and default to false.

### UX-R2-057 -- Page load makes 10+ DB queries just for navigation badges
- **Severity**: LOW
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php` lines 3-7, 249-285
- **User impact**: Every page load runs separate queries for: messages sent, molecule classes, unread messages, unread reports, alliance invitations, forum read status, and moderator status -- just to render badge counts in the navigation. While not a direct UX issue, on slow connections this delays the initial page render. Users see a brief flash of content before badges populate.
- **Fix proposal**: Consolidate badge queries into a single query joining the relevant tables, or cache badge counts with a short TTL (30 seconds). This is more of a performance issue but affects perceived UX.

### UX-R2-058 -- The cardsprivate.php missions show only 3 at a time with no progress indicator
- **Severity**: LOW
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/includes/cardsprivate.php` lines 166-193
- **User impact**: After the tutorial (niveaututo >= 10), the missions panel shows the next 3 uncompleted missions as accordion items. There is no indicator of total missions (19), how many are completed, or a progress bar. Players have no sense of overall progression. The tutoriel.php page has a progress bar, but these are different systems (see UX-R2-009).
- **Fix proposal**: Add a progress indicator: "Missions: X/19 completees" above the mission list. Show the 3 current missions with their position (e.g., "Mission 7/19").

### UX-R2-059 -- Description textarea in compte.php starts with raw HTML entity encoding
- **Severity**: LOW
- **Location**: `/home/guortates/TVLW/The-Very-Little-War/compte.php` line 220
- **User impact**: The description textarea uses `htmlspecialchars($description['description'])` which correctly escapes HTML for display in the textarea. However, if a description was previously saved with BBcode like `[b]Hello[/b]`, it appears as plain BBcode text in the textarea (which is correct), but there is no live preview of what the formatted description will look like. Users must save, visit their profile, then come back to edit if it does not look right.
- **Fix proposal**: Add a "Preview" section below the textarea that renders the BBcode in real-time or on button click, showing what other players will see.

---

## Architecture Observations

### Pattern: Echo-based rendering prevents testability
All UI components (`debutCarte`, `finCarte`, `item`, `debutListe`, `finListe`, etc.) directly `echo` HTML. This makes it impossible to unit test the UI layer, capture output for manipulation, or compose components programmatically. The `$retour`/`$return` parameter exists on some functions (`debutContent`, `finContent`, `item`, `debutListe`, `finListe`) but not on the most commonly used `debutCarte`/`finCarte`.

### Pattern: Global variable dependency in display functions
Functions like `coutEnergie()`, `coutAtome()`, and `coutTout()` rely on the global `$ressources` variable. If this variable is stale (modified by a preceding action on the same request), the cost color coding will be wrong. This is a systemic issue across the display layer.

### Pattern: Forms use `name` attribute for JS access instead of `id`
The `submit()` function references forms via `document.FORMNAME.submit()`. This relies on named form access, which is legacy DOM behavior. Modern practice uses `document.getElementById()` or `document.querySelector()`.

### Pattern: No ARIA landmarks or roles anywhere
The entire UI has zero ARIA attributes. No `role="navigation"`, `role="main"`, `aria-label`, `aria-live` for dynamic content, or `aria-expanded` for accordions. Screen reader accessibility is non-existent.

---

## Priority Remediation Order

**Phase 1 -- Safety and functionality (Week 1)**
1. UX-R2-001: Replace `<a>` submit buttons with `<button type="submit">`
2. UX-R2-002: Add confirmation dialogs on destructive actions
3. UX-R2-005: Add attack confirmation summary
4. UX-R2-006: Fix unconditional CSRF check in compte.php

**Phase 2 -- Mobile usability (Week 2)**
5. UX-R2-003: Make map container responsive
6. UX-R2-004: Increase touch targets to 44x44px minimum
7. UX-R2-016: Change text inputs to number inputs
8. UX-R2-026: Increase help icon touch targets

**Phase 3 -- User flow improvements (Week 3)**
9. UX-R2-009: Unify tutorial systems
10. UX-R2-010: Fix JS redirect flashing with proper header redirects
11. UX-R2-008: Implement session flash messages
12. UX-R2-012: Add active page indicator in navigation
13. UX-R2-013: Show resources directly in resource bar

**Phase 4 -- Polish (Week 4)**
14. UX-R2-011: Add Max buttons to market
15. UX-R2-014: Show cost summary in construction list
16. UX-R2-015: Add client-side validation for molecule creation
17. UX-R2-020: Add cancel button to molecule creation form
18. UX-R2-040: Add page titles to navbar
