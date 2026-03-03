# UX Audit -- Round 1

## Summary
Total findings: 42 (11 HIGH, 19 MEDIUM, 12 LOW)

---

## Findings

### UX-001 [HIGH] No confirmation dialog before destructive molecule class deletion
**File:** /home/guortates/TVLW/The-Very-Little-War/armee.php:364-365
**Issue:** Deleting a molecule class (the X button) submits immediately via `type="image"` with no confirmation prompt. A misclick permanently destroys an entire molecule class, all trained molecules, and resets attack queues.
**Impact:** Players lose hours of resource investment from a single accidental tap. On mobile, where fat-finger taps are common, this is devastating.
**Fix:** Add a JavaScript `confirm()` dialog or a two-step confirmation flow before submitting the delete form. The same pattern applies to "Supprimer tous les messages" in `messages.php:79` and "Quitter l'equipe" in `alliance.php:246-247`.

### UX-002 [HIGH] Error messages use dismissive developer language
**File:** /home/guortates/TVLW/The-Very-Little-War/attaquer.php:50-51, 178
**Issue:** Error messages like `"T'y as cru ?"` (lines 51, 178) are mocking and unhelpful. Users who trigger validation errors through legitimate edge cases (empty fields, slow connections) see dismissive messages instead of guidance.
**Impact:** Users feel insulted and have no idea what they did wrong or how to fix it. This appears in `attaquer.php` and potentially other action files.
**Fix:** Replace all dismissive error messages with specific, helpful messages. For example: `"Veuillez remplir tous les champs obligatoires."` instead of `"T'y as cru ?"`.

### UX-003 [HIGH] Map (carte) is unusable on mobile devices
**File:** /home/guortates/TVLW/The-Very-Little-War/attaquer.php:329
**Issue:** The map container is hardcoded to `width:600px;height:300px` with absolute-positioned tiles of 80px each. On mobile screens (320-414px wide), this creates a tiny viewport into a potentially enormous grid. Scrolling within the overflow container conflicts with page scrolling on touch devices. Player names overlay at 80px width making them unreadable.
**Impact:** The primary combat interaction (selecting targets) is nearly impossible on mobile, which is the primary target platform (Framework7 mobile-first).
**Fix:** Make tile size responsive using viewport units or percentage-based sizing. Add pinch-to-zoom support. Consider a searchable list view as an alternative to the visual map. At minimum, increase the container height on mobile.

### UX-004 [HIGH] No loading or progress indicators for form submissions
**File:** /home/guortates/TVLW/The-Very-Little-War/constructions.php:340-350, attaquer.php:421
**Issue:** Form submissions for attacks, constructions, market transactions, and molecule creation show no loading state. The submit buttons use `javascript:document.formName.submit()` via anchor tags, not actual submit buttons, so there is no native browser loading indicator.
**Impact:** Users double-click or double-tap causing duplicate submissions. They have no idea if their action worked until the page reloads. On slow connections this is particularly frustrating. Double-submitting an attack wastes energy.
**Fix:** Add a loading spinner or disable the button after first click. Use actual `<button type="submit">` elements instead of `<a href="javascript:...">` anchors.

### UX-005 [HIGH] submit() function generates non-semantic, inaccessible buttons
**File:** /home/guortates/TVLW/The-Very-Little-War/includes/ui_components.php:520-575
**Issue:** The `submit()` function creates `<a>` tags with `href="javascript:document.formName.submit()"` instead of actual `<button type="submit">` elements. These are not keyboard-navigable (no Tab focus without explicit tabindex), not announced as buttons by screen readers, and cannot be activated with Enter/Space keys naturally.
**Impact:** Keyboard-only users and assistive technology users cannot interact with any forms. This affects login, registration, all game actions, and account management.
**Fix:** Change `submit()` to render `<button type="submit">` elements with appropriate CSS classes. Keep the visual styling via the existing class system.

### UX-006 [HIGH] Ranking tables overflow horizontally on mobile with no visual affordance
**File:** /home/guortates/TVLW/The-Very-Little-War/classement.php:152-206
**Issue:** The player ranking table has 10 columns (Rang, Joueur, Points, Equipe, Constructions, Attaque, Defense, Pillage, Victoire, PP) with 32x32px images in headers. On a 375px mobile screen, this is at least 800px wide. While `table-responsive` adds horizontal scroll, there is no visual indicator that the table is scrollable, and the player name column scrolls off-screen.
**Impact:** Players cannot see their own stats and the player name simultaneously. This is the most-visited page in competitive play.
**Fix:** Make the first two columns (Rang, Joueur) sticky with `position: sticky; left: 0`. Alternatively, use a card-based layout on narrow screens. Consider collapsing less-important columns behind a details expansion on mobile.

### UX-007 [HIGH] Tutorial uses JavaScript redirects that break browser navigation
**File:** /home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php:74,95,104,112,139,148,160
**Issue:** Tutorial progression uses `echo '<script>document.location.href="...";</script>'` mid-page (before HTML is rendered). This causes: (1) a flash of partial content before redirect, (2) the redirect page is added to browser history creating back-button loops, (3) if JavaScript is blocked, the redirect fails silently.
**Impact:** New players (the most critical cohort for retention) experience confusing page flashes and cannot use the back button during onboarding. They may get stuck in redirect loops.
**Fix:** Use `header('Location: ...')` followed by `exit()` for server-side redirects. This pattern is already used correctly in `constructions.php:37-38` -- apply the same approach to tutorial progression.

### UX-008 [HIGH] No visual distinction between success and error feedback
**File:** /home/guortates/TVLW/The-Very-Little-War/includes/cardsprivate.php (implied), all action pages
**Issue:** Both `$information` (success) and `$erreur` (error) variables are set throughout the codebase and presumably rendered via `tout.php`. From the files reviewed, there is no distinct visual treatment visible in the template layer -- no green for success, no red for errors, no icons. Both message types appear to be rendered identically.
**Impact:** Users cannot quickly distinguish "your attack was launched" (success) from "you don't have enough energy" (error). Both look the same, requiring reading the full message text.
**Fix:** Render `$information` with a green background/left-border and a checkmark icon. Render `$erreur` with a red background/left-border and a warning icon. Use Framework7's notification component for toast-style feedback.

### UX-009 [HIGH] Market sell tax (5%) is not disclosed before transaction
**File:** /home/guortates/TVLW/The-Very-Little-War/marche.php:470-490
**Issue:** The sell form shows "Apport en energie" but the 5% sell tax is only mentioned in the success message after the transaction (`marche.php:342` -- "5% de frais"). The JavaScript `majVente()` does apply the tax rate (`sellTaxRate = 0.95`) to the preview, but there is no visible label explaining the fee exists.
**Impact:** Players expecting full value for their resources are surprised by the 5% deduction. This feels like a hidden fee and erodes trust in the market system.
**Fix:** Add a visible label near the sell form: "Frais de vente : 5%" and show the fee amount dynamically alongside the energy return. Display it prominently in the sell card header.

### UX-010 [HIGH] Molecule formation uses text input instead of number input on mobile
**File:** /home/guortates/TVLW/The-Very-Little-War/armee.php:369, 390
**Issue:** The "Former" input for molecule count uses `<input type="text">` instead of `<input type="number">`. While the system handles `transformInt()` for K/M suffixes, on mobile this opens a full alphabetic keyboard instead of a numeric keypad.
**Impact:** Mobile users must switch keyboard modes to enter numbers, adding friction to the most common action in the game (training molecules). Same issue for neutrino formation (`armee.php:390`) and all market inputs (`marche.php:461,482`).
**Fix:** Use `<input type="number" inputmode="numeric">` for pure number inputs. If K/M suffix support is desired, use `inputmode="text"` but add a visual hint that suffixes are accepted (e.g., placeholder="1000 ou 1K").

### UX-011 [HIGH] Vacation mode date picker appears non-functional
**File:** /home/guortates/TVLW/The-Very-Little-War/compte.php:182
**Issue:** The vacation end date field uses `<input type="text" placeholder="Selectionnez" readonly id="calVacs">` which appears to expect a JavaScript calendar picker (`calVacs` ID suggests a calendar binding), but no calendar initialization code is visible in this file. The `readonly` attribute prevents manual typing, and without a bound calendar, the field is completely inert.
**Impact:** Players cannot set vacation mode at all, which is a critical feature for player retention in a game with army decay mechanics.
**Fix:** Either bind a Framework7 calendar picker to `#calVacs` in the page's script section, or change to `<input type="date">` which provides a native date picker on all modern mobile browsers. Remove the `readonly` attribute if using a native date input.

### UX-012 [MEDIUM] Pagination lacks context and has tiny touch targets
**File:** /home/guortates/TVLW/The-Very-Little-War/classement.php:209-234, messages.php:80-105
**Issue:** Pagination shows only `1 ... 3 [4] 5 ... 10` as plain text links with no button styling, no indication of total items or current range (e.g., "Showing 61-80 of 200 players"), and no visual button treatment. The links are rendered as bare `<a href>` tags with minimal tap area.
**Impact:** On mobile, page numbers are nearly impossible to tap accurately. Users have no context for how many pages exist or where they are in the list.
**Fix:** Style pagination links as Framework7 buttons with adequate padding (minimum 44x44px touch target). Add "X joueurs au total" or "61-80 sur 200" to the pagination footer. Place pagination both above and below the table.

### UX-013 [MEDIUM] Side menu has no visual indicator of current page
**File:** /home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php:230-286
**Issue:** The left panel menu lists all navigation items with identical `style="color:black"`. There is no highlighting, background color, or active indicator for the currently visited page.
**Impact:** Users cannot tell which page they are on from the menu, reducing spatial orientation within the app.
**Fix:** Pass the current page filename to the menu and apply an active class (e.g., `background-color:#f0f0f0` or `font-weight:bold;color:red`) to the matching menu item.

### UX-014 [MEDIUM] Resource information hidden behind a popover on small screens
**File:** /home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php:296-310
**Issue:** The resource popover crams 8 atom types into a `<center>` block with format `amount/max +revenue/h utility_text` per line, using 12px and 10px font sizes. On a 375px screen, this is extremely cramped. The popover itself may not fit within the viewport.
**Impact:** The most frequently accessed information (resource counts) is hard to read. Players must tap to open and then squint to check if they can afford an action.
**Fix:** Use a dedicated resource page or a bottom sheet instead of a popover. Increase font sizes to at least 14px. Consider a horizontal scrollable chip bar showing resources relevant to the current page context.

### UX-015 [MEDIUM] Classement sub-navigation has no visible tabs or segmented control
**File:** /home/guortates/TVLW/The-Very-Little-War/classement.php:71-73
**Issue:** The classement page has 4 sub-sections (players sub=0, alliances sub=1, wars sub=2, forum sub=3) navigated via URL parameters, but there are no visible tab buttons or navigation elements rendered in the page itself. Users can only switch between them by clicking column header icons or modifying the URL directly.
**Impact:** Players do not know that alliance rankings, war history, and forum rankings exist. The sub-pages are effectively hidden features.
**Fix:** Add a Framework7 tab bar or segmented control at the top of the classement card with labels: "Joueurs", "Equipes", "Guerres", "Forum". Same issue exists on armee.php (`?sub=0` vs `?sub=1`) and marche.php (`?sub=0` vs `?sub=1`).

### UX-016 [MEDIUM] Disconnect is the first item in the side menu
**File:** /home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php:231
**Issue:** The side panel lists "Deconnexion" as the very first item. On a mobile touch interface, users swiping the panel open may accidentally tap it. Destructive and irreversible navigation actions should be placed at the bottom of menus.
**Impact:** Accidental logouts during gameplay. Players lose unsaved state and must log in again.
**Fix:** Move "Deconnexion" to the bottom of the menu list, below "Medailles" and "Forum". Optionally add a confirmation prompt.

### UX-017 [MEDIUM] Menu label "Carte" does not match page title "Attaquer"
**File:** /home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php:246, attaquer.php:262,384
**Issue:** The side menu labels the attack/map page as "Carte" but `attaquer.php` renders cards titled "Attaquer" and "Espionner". The mental model mismatch between menu label and page content creates confusion.
**Impact:** New players navigating to "Carte" expect a map, not attack forms. The attack context appears only after clicking a player on the map.
**Fix:** Either rename the menu item to "Carte & Combat" or rename the page headers to match.

### UX-018 [MEDIUM] Attack form provides no army composition summary
**File:** /home/guortates/TVLW/The-Very-Little-War/attaquer.php:406-421
**Issue:** When setting up an attack, the form shows molecule classes with individual number inputs but no summary of total attacking force (total molecules, combined attack power, combined defense, combined speed). Only travel time and energy cost are calculated dynamically.
**Impact:** Players cannot evaluate if their attacking force is sufficient to win. They must mentally calculate from individual class stats shown on other pages.
**Fix:** Add a dynamic summary section showing "Total molecules: X, Puissance d'attaque: Y, Defense: Z" that updates as the user changes troop counts.

### UX-019 [MEDIUM] Market chart title contains a typo
**File:** /home/guortates/TVLW/The-Very-Little-War/marche.php:596
**Issue:** The chart title reads `'Evolution du cout en energie des ressoures'` -- "ressoures" should be "ressources".
**Impact:** Minor polish issue but visible on a prominent page. Undermines perceived quality.
**Fix:** Change to `'Evolution du cout en energie des ressources'`.

### UX-020 [MEDIUM] Market chart loads from external CDN with no loading indicator or fallback
**File:** /home/guortates/TVLW/The-Very-Little-War/marche.php:560, 447
**Issue:** The price chart depends on `https://www.gstatic.com/charts/loader.js`. If the CDN is slow or blocked, the chart area is a blank white 400px box with no loading indicator or error message.
**Impact:** Players see a broken-looking empty area and may think the page is malfunctioning.
**Fix:** Add a loading indicator inside `#curve_chart` that shows until the chart renders. Add an error handler for chart loading failure with a text-based fallback showing current prices in a table.

### UX-021 [MEDIUM] Forum page has no "new topic" button visible
**File:** /home/guortates/TVLW/The-Very-Little-War/forum.php:48-104
**Issue:** The forum landing page shows a table of forum categories with topic/message counts, but there is no visible button to create a new topic. Users must navigate into a specific forum category first.
**Impact:** Users who want to post may not realize they need to click into a category first. The most common action (posting) has no visible call-to-action on the forum landing page.
**Fix:** Add a prominent "Nouveau sujet" button in the card footer, or at minimum add instructional text explaining the workflow.

### UX-022 [MEDIUM] Messages footer links are unstyled and concatenated
**File:** /home/guortates/TVLW/The-Very-Little-War/messages.php:115
**Issue:** The footer of the messages card contains `<a href="ecriremessage.php">Ecrire</a><a href="messagesenvoyes.php">Envoyes</a>` as plain unstyled links directly adjacent to each other with no spacing or separator.
**Impact:** These critical actions (compose new message, view sent messages) are nearly invisible and run together visually. On mobile the tap targets overlap.
**Fix:** Style as Framework7 buttons with proper margin spacing. Add icons (envelope for compose, paper plane for sent).

### UX-023 [MEDIUM] Alliance page shows dead-end "Inconnue" for invalid alliance tags
**File:** /home/guortates/TVLW/The-Very-Little-War/alliance.php:401-405
**Issue:** When viewing a non-existent alliance tag, the page shows a card titled "Inconnue" with one line "Cette alliance n'existe pas." and no navigation links.
**Impact:** Dead-end UX. Users navigating from stale links or shared URLs have no path forward.
**Fix:** Add a link to `classement.php?sub=1` (alliance rankings) and to create a new alliance.

### UX-024 [MEDIUM] Alliance invitation accept/reject uses transparent-text image buttons
**File:** /home/guortates/TVLW/The-Very-Little-War/alliance.php:432
**Issue:** The accept/reject invitation buttons use `<input type="submit" style="...color: Transparent;...">` with background images. The button text ("Accepter"/"Refuser") is transparent so only the image shows. If images fail to load, buttons are invisible.
**Impact:** Fragile UI that breaks if images fail. No hover/active visual states. Poor accessibility for screen readers due to transparent text approach.
**Fix:** Use `<button type="submit">` with visible text labels alongside icons. Or use proper icon buttons with `aria-label` attributes.

### UX-025 [MEDIUM] No back navigation or breadcrumbs on detail pages
**File:** /home/guortates/TVLW/The-Very-Little-War/messages.php:28-33, attaquer.php:384-425
**Issue:** When viewing a single message, attack form, or spy form, there is no back button or breadcrumb to return to the parent list. Users must open the side menu and re-navigate.
**Impact:** Users feel lost after drilling into detail views. They lose their scroll position in lists when navigating back via the menu.
**Fix:** Add a "Retour" link or breadcrumb above each detail card. Framework7 supports a navbar back button natively.

### UX-026 [MEDIUM] "Supprimer tous les messages" has no confirmation
**File:** /home/guortates/TVLW/The-Very-Little-War/messages.php:79
**Issue:** The "Supprimer tous les messages" link is a plain underlined text that submits a form deleting all messages permanently with no confirmation dialog.
**Impact:** One accidental click destroys the entire message history. Important diplomatic messages between players are lost permanently.
**Fix:** Add `onclick="return confirm('Supprimer tous les messages ?')"` or a two-step confirmation flow.

### UX-027 [MEDIUM] News date format shows encoding artifact
**File:** /home/guortates/TVLW/The-Very-Little-War/includes/menus.php:30, /home/guortates/TVLW/The-Very-Little-War/includes/cardspublic.php:30
**Issue:** The date format string `date('d/m/Y A H\hi', ...)` uses uppercase `A` which outputs the AM/PM indicator (e.g., "AM" or "PM") in the middle of French text, appearing as `"01/01/2024 AM 14h30"`. The intent is likely the French preposition "a" (meaning "at").
**Impact:** Dates display with a stray "AM" or "PM" fragment mixed into French text, looking broken.
**Fix:** Change to `date('d/m/Y \a H\hi', ...)` using an escaped lowercase `\a` for the literal "a" character.

### UX-028 [MEDIUM] Login form has no "forgot password" link
**File:** /home/guortates/TVLW/The-Very-Little-War/index.php:32-42
**Issue:** The login card has only "Connexion" and "Tester" buttons. There is no "Mot de passe oublie ?" link or recovery mechanism.
**Impact:** Players who forget their password have no self-service recovery path and must contact an admin or abandon their account.
**Fix:** Add a "Mot de passe oublie ?" link below the login form that initiates a password reset via the registered email address.

### UX-029 [MEDIUM] Global CSS forces all buttons to red color
**File:** /home/guortates/TVLW/The-Very-Little-War/includes/style.php:8-12
**Issue:** The CSS rule `.button { max-width:200px; color:red; }` applies red text to every button across the entire application. This means constructive actions like "Former" (train), "Acheter" (buy), and "Creer" (create) all appear in the same color as destructive actions.
**Impact:** Red conventionally signals danger or destruction. Using it for all buttons removes any color-based distinction between safe and destructive actions, desensitizing users to actual warnings.
**Fix:** Use a neutral or brand color as the default button color. Reserve red for destructive actions only (delete, leave, disconnect).

### UX-030 [MEDIUM] Molecule composition form has broken stats preview
**File:** /home/guortates/TVLW/The-Very-Little-War/armee.php:317
**Issue:** When creating a new molecule class, the atom quantity inputs include `oninput="javascript:actualiserStats()"`, but the `actualiserStats()` function is not defined anywhere in the file. Whatever real-time preview was intended is completely non-functional.
**Impact:** Players create molecules blindly, not knowing the effect of their atom choices until after spending energy on creation. This is a core game mechanic with zero user feedback.
**Fix:** Implement `actualiserStats()` to show a real-time preview of the molecule's combat stats (attack, defense, speed, stability, half-life) as the user adjusts atom quantities.

### UX-031 [LOW] Font declarations are duplicated across style.php and my-app.css
**File:** /home/guortates/TVLW/The-Very-Little-War/includes/style.php:36-60, /home/guortates/TVLW/The-Very-Little-War/css/my-app.css:1-36
**Issue:** `@font-face` declarations for `magmawave_capsbold` and `bpmoleculesregular` exist in both files with slightly different relative paths. This causes duplicate font downloads.
**Impact:** Increased page load time from downloading the same fonts twice. On slow mobile connections this is noticeable.
**Fix:** Consolidate all font declarations into one file. Remove duplicates.

### UX-032 [LOW] body element has invalid CSS font-weight value
**File:** /home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php:218, /home/guortates/TVLW/The-Very-Little-War/includes/basicpublichtml.php:1
**Issue:** `<body style="font-weight:regular">` -- "regular" is not a valid CSS `font-weight` value. Valid values are `normal`, `bold`, or numeric (100-900).
**Impact:** The font-weight declaration is silently ignored by all browsers. No visual effect but indicates untested CSS.
**Fix:** Change `font-weight:regular` to `font-weight:normal`.

### UX-033 [LOW] Screenshots use lightbox attribute without loading a lightbox library
**File:** /home/guortates/TVLW/The-Very-Little-War/includes/cardspublic.php:66-69, /home/guortates/TVLW/The-Very-Little-War/includes/menus.php:66-69
**Issue:** Screenshot links use `rel="lightbox[screenshoots]"` which requires a Lightbox JavaScript library that is not included. Also "screenshoots" is misspelled (should be "screenshots").
**Impact:** Clicking screenshots opens the full-size image in a new page navigation instead of an overlay. Users lose their place on the homepage.
**Fix:** Either include a lightbox library compatible with Framework7 or use Framework7's built-in photo browser component.

### UX-034 [LOW] Screenshot image dimensions missing "px" units
**File:** /home/guortates/TVLW/The-Very-Little-War/includes/cardspublic.php:66-69
**Issue:** `style="width:200;height:113;"` is missing the `px` unit suffix. While some browsers infer pixels, this is technically invalid CSS.
**Impact:** Inconsistent rendering across browsers.
**Fix:** Change to `style="width:200px;height:113px;"`.

### UX-035 [LOW] display.php image() function outputs duplicate alt attributes
**File:** /home/guortates/TVLW/The-Very-Little-War/includes/display.php:11
**Issue:** The `image()` function outputs `<img alt="Energie" src="..." alt="resourceName" .../>` with two `alt` attributes. Only the first is used by browsers.
**Impact:** All atom images have alt text "Energie" regardless of which atom they represent, confusing screen reader users and violating HTML spec.
**Fix:** Remove the first `alt="Energie"` and keep only the dynamic `alt="resourceName"`.

### UX-036 [LOW] Public menu uses same alt text "armee" for all icons
**File:** /home/guortates/TVLW/The-Very-Little-War/includes/basicpublichtml.php:13-18
**Issue:** All public menu items use `alt="armee"` regardless of the actual icon (accueil, sinscrire, sinstruire, regles, classement, forum).
**Impact:** Screen reader users hear "armee" for every menu icon, making navigation impossible for visually impaired users.
**Fix:** Set meaningful alt text matching each menu item: `alt="accueil"`, `alt="inscription"`, etc.

### UX-037 [LOW] Private menu icons use generic alt text "checklist"
**File:** /home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php:231-286
**Issue:** All private menu item icons use `alt="checklist"` regardless of the actual menu item (Constructions, Armee, Carte, etc.).
**Impact:** Same as UX-036: screen reader users cannot distinguish menu items by their icons.
**Fix:** Use descriptive alt text for each icon matching the menu label.

### UX-038 [LOW] CSS has syntax error in imageClassement and nombreMolecules
**File:** /home/guortates/TVLW/The-Very-Little-War/includes/style.php:261, /home/guortates/TVLW/The-Very-Little-War/includes/display.php:170
**Issue:** `.imageClassement` has `height; 32px;` (semicolon instead of colon, style.php:261). `nombreMolecules()` has `height;20px` (display.php:170). Both are invalid CSS causing the height property to be ignored.
**Impact:** Ranking medal images and molecule count images may render at wrong sizes depending on intrinsic image dimensions.
**Fix:** Change `height;` to `height:` in both locations.

### UX-039 [LOW] my-app.css has truncated/invalid CSS at end of file
**File:** /home/guortates/TVLW/The-Very-Little-War/css/my-app.css:57
**Issue:** The file ends with a lone period (`.`) on line 57, which is an incomplete CSS rule selector. This is a CSS syntax error that could invalidate surrounding rules.
**Impact:** Potential rendering issues depending on CSS parser error recovery behavior.
**Fix:** Remove the trailing `.` or complete the intended CSS rule.

### UX-040 [LOW] Color-only indicators for resource affordability (color-blind inaccessible)
**File:** /home/guortates/TVLW/The-Very-Little-War/includes/display.php:210-227
**Issue:** `coutEnergie()` and `coutAtome()` indicate whether the player can afford a cost solely by chip background color: green for affordable, red for unaffordable. No icon, text, or pattern differentiates them.
**Impact:** Red-green color-blind users (approximately 8% of males) cannot distinguish affordable from unaffordable costs. This is exactly the most common form of color blindness.
**Fix:** Add a checkmark icon for affordable and an X icon for unaffordable in addition to the color. Alternatively, use text labels like "OK" and "Insuffisant".

### UX-041 [LOW] Army overview has typo in responsive wrapper class
**File:** /home/guortates/TVLW/The-Very-Little-War/armee.php:404
**Issue:** `<div class="reponsive-table">` is misspelled (missing 's' -- should be `responsive-table` or `table-responsive`). The CSS class is not applied.
**Impact:** The army overview table has no horizontal scroll wrapper on mobile, causing content to overflow and be clipped or trigger full-page horizontal scroll.
**Fix:** Change `reponsive-table` to `table-responsive`.

### UX-042 [LOW] Account page applies CSRF check unconditionally before checking POST fields
**File:** /home/guortates/TVLW/The-Very-Little-War/compte.php:6
**Issue:** `csrfCheck()` is called at the top of the file before any POST variable checking. If a user navigates to `compte.php` via an unexpected POST (browser extensions, form replays), they get an opaque CSRF error even for innocent navigation.
**Impact:** Users may see an unexplained error when navigating to the account page under certain conditions. Every other action file in the codebase correctly places `csrfCheck()` inside specific `if(isset($_POST[...]))` blocks.
**Fix:** Move `csrfCheck()` inside each specific `if(isset($_POST[...]))` block, matching the pattern used in `attaquer.php`, `armee.php`, `constructions.php`, `alliance.php`, etc.

---

## Severity Distribution

| Severity | Count |
|----------|-------|
| HIGH     | 11    |
| MEDIUM   | 19    |
| LOW      | 12    |
| **Total**| **42**|

---

## Top 10 Priority Fixes

1. **UX-001 HIGH** -- Add confirmation dialog before deleting molecule classes (and other destructive actions)
2. **UX-005 HIGH** -- Replace `<a href="javascript:...">` submit buttons with proper `<button type="submit">`
3. **UX-003 HIGH** -- Make the battle map responsive for mobile screens
4. **UX-007 HIGH** -- Replace tutorial JS redirects with PHP `header('Location:')` + `exit()`
5. **UX-006 HIGH** -- Redesign ranking tables for mobile (sticky columns or card layout)
6. **UX-008 HIGH** -- Add visual distinction (color, icon) between success and error messages
7. **UX-010 HIGH** -- Change text inputs to number inputs for molecule/neutrino/market quantity fields
8. **UX-011 HIGH** -- Fix vacation date picker to actually work (bind calendar or use native date input)
9. **UX-015 MEDIUM** -- Add visible sub-navigation tabs for classement, armee, and marche sub-pages
10. **UX-030 MEDIUM** -- Implement the missing `actualiserStats()` function for molecule composition preview
