# UX Audit Report - The Very Little War

**Auditor:** Round 1 UX Audit
**Date:** 2026-03-03
**Scope:** 20 files -- UI template layer + top 15 most-visited pages
**Framework:** Framework7 v1 Material Design, mobile-first PHP game (French language)

---

## Summary

78 findings across navigation flow, information hierarchy, visual feedback, form usability, table readability on mobile, empty states, pagination, confirmation dialogs, inconsistent layouts, and accessibility. The game has a solid foundation with Framework7's card/list/accordion patterns, but numerous UX issues degrade the mobile experience: tables that overflow without indication, destructive actions lacking confirmation, inconsistent pagination, forms missing validation feedback, and significant accessibility gaps (no lang attribute, missing ARIA, low contrast, no focus management).

---

## Findings

### Navigation Flow

**[UX-R1-001] HIGH basicprivatehtml.php:231 -- Disconnect is the first menu item, inviting accidental logouts**

The side panel lists "Deconnexion" as the very first item. On a mobile touch interface, users swiping the panel open may accidentally tap it. Destructive/irreversible navigation actions should be placed at the bottom of menus, not at the top.

**[UX-R1-002] MEDIUM basicprivatehtml.php:246 -- Menu label "Carte" does not match page title "Attaquer"**

The side menu labels the attack/map page as "Carte" but `attaquer.php` renders cards titled "Attaquer" and "Espionner". The mental model mismatch between menu label and page content creates confusion. Either rename the menu item or align page headers.

**[UX-R1-003] MEDIUM classement.php:71-73 -- No visible sub-navigation tabs for ranking categories**

The classement page has 4 sub-views (sub=0 players, sub=1 alliances, sub=2 wars, sub=3 forum) but there is no visible tab bar or segmented control rendered in the HTML. Users can only switch via column header icon links, which is non-obvious. A tab bar component at the top of the card would drastically improve discoverability.

**[UX-R1-004] MEDIUM armee.php:340 -- Sub-page toggle via `?sub=0` vs `?sub=1` has no visible navigation**

The army page has two views (molecule management and army overview) toggled by a `sub` query parameter, but there is no visible link or tab to switch between them in the rendered HTML. Users have no way to discover the army overview page.

**[UX-R1-005] MEDIUM marche.php:367-370 -- Market sub-pages (buy/sell vs send) have no visible tab navigation**

Like `classement.php`, the market has `sub=0` (buy/sell) and `sub=1` (send resources), but no visible tab bar or links to switch between them within the page content. Users must rely on side-menu navigation or direct URLs.

**[UX-R1-006] LOW basicpublichtml.php:14 -- "S'inscrire" is bold in the public menu but other items are not**

The registration link uses `<strong>` wrapping while other menu items do not, creating visual inconsistency in the side panel.

**[UX-R1-007] MEDIUM basicprivatehtml.php:239-241 -- Constructions badge shows combined producer+condenser points but user cannot distinguish**

The badge shows `pointsProducteurRestants + pointsCondenseurRestants` as a single green badge. Users see a number but cannot tell what type of points are pending without opening the page.

**[UX-R1-008] LOW alliance.php:68 -- After creating alliance, uses `window.location` JS redirect instead of HTTP redirect**

Line 53 uses `<script>window.location="alliance.php";</script>` after alliance creation. This means back-button behavior is broken and the redirect fails if JavaScript is disabled or blocked.

---

### Information Hierarchy

**[UX-R1-009] HIGH basicprivatehtml.php:296-300 -- Resource bar is hidden behind a "chip" popover requiring a tap to view**

The 8 atom resources with current/max values and production rates are all stuffed into a popover triggered by tapping an "Atomes" chip. On a strategy game where resources drive every decision, this critical information should be persistently visible, not hidden behind a tap.

**[UX-R1-010] HIGH basicprivatehtml.php:360-375 -- Energy production breakdown hidden in a popover**

The detailed energy production breakdown (generator, iode molecules, medals, duplicator) is locked behind a popover that must be manually triggered. Players frequently need to reference this; it should be in an expandable accordion or always-visible section on the constructions page.

**[UX-R1-011] MEDIUM constructions.php:353-358 -- All 9+ buildings listed in a single flat accordion list**

Every building is rendered in one long accordion list with no grouping or categorization. For 9+ buildings, this creates cognitive overload. Grouping by function (production, defense, army, utility) would improve scanability.

**[UX-R1-012] MEDIUM attaquer.php:329 -- Map has a fixed 600x300px container but game map can be much larger**

The map container is hardcoded at `width:600px;height:300px` with scrollable overflow. On small mobile screens the scroll area is tiny, and on larger screens the fixed width wastes space. This should use responsive sizing (percentage or viewport units).

**[UX-R1-013] MEDIUM attaquer.php:358-359 -- Player names on the map use opacity:0.7 black background labels at tiny size**

Map player labels use `opacity:0.7;background-color:black;color:white` overlaid on tile images. On small screens with 80px tiles, names are truncated and hard to read. No text-overflow handling exists.

**[UX-R1-014] LOW index.php:64-85 -- Landing page atom showcase uses absolute positioning that breaks on narrow screens**

The atom names (azote, carbone, etc.) are positioned with floats and margins that assume a certain width. On very narrow viewports, the layout can collapse with overlapping elements.

**[UX-R1-015] MEDIUM cardsprivate.php:8-146 -- Tutorial card dominates the entire page regardless of current context**

The tutorial/missions card is injected at the top of every private page via `cardsprivate.php`. During tutorial steps, it can push actual page content far below the fold, requiring scrolling on mobile before the user can interact with the page they navigated to.

---

### Visual Feedback

**[UX-R1-016] CRITICAL basicprivatehtml.php (no line) -- No global success/error notification system visible in the template**

Success (`$information`) and error (`$erreur`) messages are set as PHP variables but no toast, snackbar, or alert banner rendering is visible in the base template files read. If the notification display depends on `includes/tout.php` (not in scope), there is still no visual feedback for AJAX operations or client-side validation failures.

**[UX-R1-017] HIGH attaquer.php:159 -- Attack launched with no confirmation dialog**

The attack form (`formAttaquer`) submits directly when the "Attaquer" button is tapped. There is no confirmation dialog asking "Are you sure you want to attack [player]?". Attacks consume energy and troops -- this is an irreversible, high-stakes action.

**[UX-R1-018] HIGH armee.php:364-365 -- Molecule class deletion uses a bare image-type submit with no confirmation**

Clicking the red X image (`images/croix.png`) immediately submits a form to delete an entire molecule class. There is no confirmation dialog. This destroys all molecules of that class and is irreversible.

**[UX-R1-019] HIGH compte.php:198-202 -- Account deletion button lacks inline warning before proceeding**

The "Supprimer le compte" button is a red-styled button that navigates to a confirmation page, but the initial page gives no warning text about consequences. The confirmation page (lines 234-244) shows yes/no image buttons, which are tiny and hard to distinguish on mobile.

**[UX-R1-020] MEDIUM constructions.php:166-167 -- "Resources available on [date]" shows raw date format without countdown**

When a player cannot afford a building upgrade, the message shows "Assez de ressources le 15/03/2026 a 14h30" as plain text. A dynamic countdown or "in X hours" format would be more useful.

**[UX-R1-021] MEDIUM attaquer.php:403-404 -- Attack time and energy cost start at 0, only update on input change**

The `chipInfo('0:00:00', ...)` and `nombreEnergie(0, ...)` chips initially show zero values. They only update via JavaScript event handlers on input change. If JavaScript fails or loads slowly, users see misleading "0 energy cost" and "0:00:00 time".

**[UX-R1-022] MEDIUM marche.php:446-448 -- Market price chart has no loading indicator**

The Google Charts line chart at `#curve_chart` loads asynchronously from gstatic.com. During loading, users see an empty 400px tall white box with no spinner or "Loading..." text.

**[UX-R1-023] LOW armee.php:369 -- "Max" button for molecule formation uses inline JavaScript without visual affordance**

The max-fill link is rendered as `<a class="button button-raised button-fill">Max : 1.5K</a>` which looks like a button but actually executes inline JavaScript to fill the form. No visual distinction between "fill max" and "submit form".

**[UX-R1-024] MEDIUM alliance.php:246-247 -- "Quitter" (leave alliance) has no confirmation dialog**

The leave-alliance button submits directly via form. Leaving an alliance is a significant action (loss of team bonuses, duplicator access) but there is no "Are you sure?" confirmation.

**[UX-R1-025] LOW basicprivatehtml.php:74 -- Tutorial redirects use inline JavaScript `document.location.href` which breaks browser history**

Multiple tutorial step completions (lines 74, 95, 104, 112, 139, 148, 160) redirect via `<script>document.location.href=...</script>` instead of PHP `header('Location: ...')`. This means back-button behavior is unpredictable during the tutorial.

---

### Form Usability

**[UX-R1-026] HIGH armee.php:316-317 -- Molecule creation form has 8 number inputs with no real-time total or atom limit indicator**

When creating a molecule class, users must enter quantities for 8 atom types (max 200 each). There is no visible running total, no indicator showing how close they are to the 200 cap per atom, and no warning if they exceed limits until after submission.

**[UX-R1-027] HIGH marche.php:416-427 -- Send resources form has 9+ input fields on one screen**

The send-resources form requires energy amount, 8 atom amounts, and a recipient name -- all as individual text inputs stacked vertically. On mobile this is an extremely long form requiring extensive scrolling with no section breaks or progress indicator.

**[UX-R1-028] MEDIUM marche.php:461 -- Market buy quantity input uses `type="text"` instead of `type="number"`**

The "nombreRessourceAAcheter" input is `type="text"`, which means mobile users get a full QWERTY keyboard instead of a numeric keypad. Same issue for the sell quantity (line 482) and send amounts (lines 420-424).

**[UX-R1-029] MEDIUM armee.php:369 -- Molecule formation count input uses `type="text"` instead of `type="number"`**

`<input type="text" name="nombremolecules" ...>` should be `type="number"` to trigger numeric keypad on mobile and provide built-in validation.

**[UX-R1-030] MEDIUM armee.php:390 -- Neutrino formation input uses `type="text"` with no min/max constraints**

The neutrino count field has no HTML5 constraints. Using `type="number" min="1"` would provide basic client-side validation.

**[UX-R1-031] MEDIUM compte.php:182 -- Vacation date picker uses `readonly` input with id "calVacs" but no visible calendar widget**

The vacation end-date field is `<input type="text" readonly id="calVacs">` with placeholder "Selectionnez". The calendar initialization presumably happens elsewhere, but if Framework7's calendar picker is not properly initialized, users see an unclickable empty field with no fallback.

**[UX-R1-032] LOW compte.php:192 -- Vacation start date shows as non-editable text input but looks like a disabled field**

The start date input has `'disabled' => true` making it grayed out. While correct (start date is "now"), the visual treatment does not clearly communicate "this is today's date" vs "this field is broken".

**[UX-R1-033] MEDIUM index.php:36-37 -- Login form has no "Forgot password?" link**

The login form on the home page shows Login + Password fields and two buttons (Connexion, Tester) but no password recovery option. Players who forget their password have no visible path to recover their account.

**[UX-R1-034] MEDIUM attaquer.php:508 -- Spy form neutrino input has min/max constraints but no visual slider**

The spy form uses `<input type="number" min="0" max="[neutrinos]">`. While functional, a slider or stepper would be more touch-friendly on mobile for selecting a quantity from a bounded range.

**[UX-R1-035] LOW constructions.php:340-350 -- Defensive formation radio buttons use plain `<label>` elements with small hit targets**

The formation selector uses `<label style="display:block;padding:8px;...">` with inline styles. On mobile, the 8px padding creates small touch targets that may be hard to hit accurately.

**[UX-R1-036] MEDIUM compte.php:228 -- Profile photo upload input has no preview of current avatar**

The photo upload section does not show the current profile picture. Users cannot see what their current avatar is before deciding to change it.

**[UX-R1-037] LOW marche.php:462 -- Market energy cost field is editable but also auto-calculated, creating ambiguity**

The "Cout en energie" field can be both manually edited and auto-filled by JavaScript. Users may not understand that editing one field recalculates the other, and there is no visual indication of the bidirectional relationship.

---

### Table Readability on Mobile

**[UX-R1-038] CRITICAL classement.php:152-165 -- Player ranking table has 10 columns, impossible to read on mobile**

The main ranking table renders 10 columns (Rang, Joueur, Points, Equipe, Constructions, Attaque, Defense, Pillage, Victoire, PP). On a 320px-375px mobile screen, each column gets roughly 32-37px. Column headers are images with text labels below them, making the header row alone take significant vertical space. The `.table-responsive` wrapper enables horizontal scroll, but there is no visual indicator that the table scrolls.

**[UX-R1-039] CRITICAL classement.php:332-345 -- Alliance ranking table has 10 columns with same mobile problem**

Identical issue to the player ranking table. Alliance ranking has Rang, TAG, Membres, Points, Moyenne, Constructions, Attaque, Defense, Pillage, Victoire.

**[UX-R1-040] HIGH classement.php:432-439 -- War history table has 5 columns but "Adversaires" cell contains two alliance names**

The adversaries cell renders "[Alliance1] contre [Alliance2]" which can be very wide text in a single cell, pushing other columns off screen.

**[UX-R1-041] HIGH messages.php:57-65 -- Message list table has 5 columns (Statut, Titre, Auteur, Date, Action) on mobile**

The messages table renders icon status, title, author name, full date string, and a delete button in 5 columns. The date column ("15/03/2026 a 14h30") alone is quite wide. On mobile, this table is barely usable.

**[UX-R1-042] MEDIUM forum.php:52-63 -- Forum table has "Statut" column with 32x32px images that waste horizontal space on mobile**

The Statut column shows a 32x32px read/unread icon. On mobile where every pixel matters, this takes disproportionate space. A smaller indicator (dot, colored border) would be more efficient.

**[UX-R1-043] MEDIUM alliance.php:323-336 -- Alliance member table has 9 columns with full-width images as headers**

The member table replicates the ranking table pattern with 9 columns and image+label headers. Same mobile readability issues.

**[UX-R1-044] MEDIUM attaquer.php:199-200 -- Active attacks table has 3 columns but no sort order indicator**

The active attacks/espionage table shows Type, Joueur, Temps but has no visual indication of the sort order (by tempsAttaque ASC).

**[UX-R1-045] LOW style.php:116-131 -- Global table styles use 94% width with 3% margins but no max-width**

The table CSS sets `width:94%; margin:3%` but does not set `max-width`. On very wide screens tables stretch the full 94% which degrades readability for data tables.

**[UX-R1-046] MEDIUM armee.php:404-440 -- Army overview table uses `<div class="reponsive-table">` (typo) instead of `table-responsive`**

Line 404 has `<div class="reponsive-table">` (missing 's' in responsive). This means the responsive overflow wrapper CSS class is not applied, so the table has no horizontal scroll on mobile.

---

### Empty States

**[UX-R1-047] HIGH armee.php:340-384 -- Empty molecule slots show "Vide" with just a plus icon, no explanation**

When a player has not yet created molecule classes, each slot shows the formula "Vide" with a small plus icon on the right. There is no explanatory text like "Create your first molecule class to build an army" or guidance on what to do.

**[UX-R1-048] MEDIUM attaquer.php:196 -- Active attacks section is completely absent when no attacks are in progress**

When `$nb['nb'] == 0`, the active attacks card is not rendered at all. This is fine functionally, but there is no indication that this is where active attacks would appear, which may confuse new players who just launched their first attack.

**[UX-R1-049] MEDIUM alliance.php:401-405 -- "Cette alliance n'existe pas" shown in a card titled "Inconnue"**

When viewing a non-existent alliance, users see a card titled "Inconnue" with one line of text. No link back to alliance creation or search functionality is provided.

**[UX-R1-050] MEDIUM alliance.php:435 -- "Vous n'avez aucune invitation d'equipe" with no call to action**

The empty invitations state shows plain text but does not suggest browsing the alliance ranking to find a team to join, or explain how invitations work.

**[UX-R1-051] LOW messages.php:112-113 -- "Vous n'avez aucun messages ou cette page n'existe pas" conflates two states**

The empty state message combines "no messages" with "invalid page" into one string. These are different situations requiring different guidance.

**[UX-R1-052] LOW forum.php (no explicit empty state) -- Forum category list has no empty state if no forums exist**

If the `forums` table is empty, the while loop simply renders nothing, leaving an empty table structure with headers but no body rows and no explanatory message.

**[UX-R1-053] MEDIUM marche.php:375-408 -- Active resource transfers card absent when no transfers in progress**

Same pattern as attacks: when no transfers exist, the entire section is absent. A placeholder message like "Aucun envoi en cours" would provide context.

---

### Pagination UX

**[UX-R1-054] HIGH classement.php:208-234 -- Pagination is plain text links with no visual button treatment**

Pagination links are rendered as plain `<a href>` tags separated by spaces, looking like "1 ... 3 **4** 5 ... 20". There are no button styles, no border, no padding -- they are extremely hard to tap on mobile due to small touch targets.

**[UX-R1-055] MEDIUM classement.php:208-234 -- Pagination is placed in card footer which may not be visible**

The pagination `$pages` string is passed to `finCarte($pages)` which renders it inside `.card-footer`. If the user has not scrolled to the bottom of the table, they may not know pagination exists at all. Consider placing pagination both above and below the table.

**[UX-R1-056] MEDIUM messages.php:80-105 -- Pagination uses identical copy-pasted pattern with no "first/last" labels**

The pagination pattern is duplicated verbatim across classement.php and messages.php. Page numbers are bare numbers with no "Premiere"/"Derniere" labels. The ellipsis "..." is not a clickable element.

**[UX-R1-057] LOW classement.php:234 -- Current page number has no distinct visual treatment beyond bold**

The current page is shown as `<strong>4</strong>`. On mobile, the visual difference between bold and normal text is subtle and may not clearly communicate "you are on page 4".

**[UX-R1-058] LOW messages.php:105 -- Messages pagination prefixed with "Pages :" label but ranking pages are not**

Inconsistent labeling: messages.php prepends "Pages : " to the pagination string while classement.php does not.

---

### Confirmation Dialogs

**[UX-R1-059] CRITICAL armee.php:364-365 -- Delete molecule class has zero confirmation (restated for severity)**

This is the single most dangerous one-click action in the game. Deleting a molecule class destroys all molecules of that type, removes them from active attacks, and decreases the class counter. The only UI element is a 32x32 red X image used as a submit button. No confirmation dialog, no undo.

**[UX-R1-060] HIGH messages.php:79 -- "Supprimer tous les messages" has no confirmation dialog**

A single text link "Supprimer tous les messages" deletes the entire inbox. No confirmation, no undo.

**[UX-R1-061] HIGH alliance.php:246-247 -- Leave alliance has no confirmation (restated for severity)**

Leaving an alliance has significant gameplay consequences. The form has a hidden field `name="quitter"` and the submit is an image button. No modal or confirmation step.

**[UX-R1-062] MEDIUM compte.php:233-244 -- Account deletion confirmation uses image buttons (yes/no) with no text labels**

The deletion confirmation page shows `images/yes.png` and `images/croix.png` as the only interactive elements. These are 32x32 images with no visible text labels, requiring users to correctly interpret checkmark = confirm, X = cancel. If images fail to load, buttons are invisible.

**[UX-R1-063] MEDIUM attaquer.php:421 -- Attack submission button says "Attaquer" with sword icons but no final confirmation**

The attack button uses `submit(['titre' => 'Attaquer', 'image' => 'images/attaquer/attaquer.png', 'form' => 'formAttaquer'])` which renders as a styled link that triggers form submission. No intermediate confirmation.

---

### Inconsistent Layouts

**[UX-R1-064] MEDIUM style.php:8-12 -- Global button style forces max-width:200px and red color**

The CSS rule `.button { max-width:200px; color:red; }` applies to all buttons globally. This means all Framework7 buttons are red by default, even for non-destructive actions like "Former" (train molecules) or "Rechercher" (search). Destructive actions should be red; constructive actions should use a different color.

**[UX-R1-065] MEDIUM ui_components.php:574 -- Submit button renders as `<a>` tag, not `<button>`, breaking form semantics**

The `submit()` function returns an `<a class="button" href="javascript:document.form.submit()">` element. This means: (a) the button does not submit the form on Enter key press, (b) assistive technology does not recognize it as a submit control, (c) it depends entirely on JavaScript.

**[UX-R1-066] MEDIUM ui_components.php:569 -- Hidden input for `name` parameter is placed outside the form in some usages**

The `submit()` function prepends `<input type="hidden" name="..."/>` to the anchor tag. If this hidden input is rendered outside a `<form>` element (as happens when submit() is used in accordion content), the hidden value is never sent.

**[UX-R1-067] LOW cardspublic.php:1 (empty file) -- Public pages have no card content, suggesting dead include**

`cardspublic.php` appears to be empty (only whitespace/newline). The public page template includes it but it contributes nothing, adding a pointless server-side include.

**[UX-R1-068] LOW style.php:260-261 -- CSS typo: `height; 32px` uses semicolon instead of colon**

Line 261 in `.imageClassement` has `height; 32px` which is a CSS syntax error. The height property is not applied, potentially causing ranking medal images to render at their natural size.

**[UX-R1-069] LOW my-app.css:57 -- Stray period at end of CSS file**

Line 57-58 of `my-app.css` contains a stray `.` character which is a CSS syntax error. While browsers typically recover, it can cause the preceding rule to be invalidated.

**[UX-R1-070] MEDIUM cardsprivate.php:128-145 -- Tutorial accordion is rendered before all page content on every page**

The tutorial card is unconditionally injected before the main page content. During tutorial levels 1-9, this pushes the actual page below the fold. On the constructions page, a player must scroll past the full tutorial text to see their buildings.

---

### Accessibility

**[UX-R1-071] CRITICAL basicprivatehtml.php:218 / basicpublichtml.php:1 -- No `lang` attribute on `<body>` or `<html>` elements**

Neither template sets `lang="fr"` on the HTML element. Screen readers and translation tools cannot detect the page language, which is French. This is a WCAG 2.1 Level A violation (3.1.1).

**[UX-R1-072] HIGH display.php:11 -- Image function outputs duplicate `alt` attributes**

The `image()` function produces `alt="Energie"` followed by `alt="[resource]"`. Duplicate `alt` attributes are invalid HTML; only the first is used, meaning all atom images report as "Energie" to screen readers.

**[UX-R1-073] HIGH ui_components.php:349 -- Accordion items use `<a href="#">` for toggle, losing keyboard focus context**

Accordion links use `href="#"` which causes the page to scroll to top on click in some browsers. ARIA accordion patterns (`role="button"`, `aria-expanded`, `aria-controls`) are absent entirely.

**[UX-R1-074] HIGH style.php:2-7 -- Page content has only 5px left/right padding, touching screen edges**

`.page-content { padding-right:5px; padding-left:5px; }` means content is only 5px from screen edges. On mobile, this makes touch targets near edges very hard to tap and text hard to read.

**[UX-R1-075] MEDIUM basicprivatehtml.php:221-226 -- Side panel header uses inline styles with no semantic heading**

The player name in the side panel is wrapped in a `<p>` tag with inline font styling. It should use an appropriate heading level (`<h2>` or `<h3>`) for screen reader navigation.

**[UX-R1-076] MEDIUM display.php:170 -- `nombreMolecules` image has `height;20px` CSS typo (semicolon instead of colon)**

`style="width:20px;height;20px"` is invalid CSS. The height is not applied, potentially causing molecule count images to render at their natural size.

**[UX-R1-077] MEDIUM alliance.php:432 -- Invitation accept/reject buttons use `color: Transparent` text on image backgrounds**

The accept/reject buttons for alliance invitations use `color: Transparent` to hide the text "Accepter"/"Refuser" while showing background images. If CSS fails or images do not load, the buttons become invisible and unusable.

**[UX-R1-078] LOW basicprivatehtml.php:427-463 -- Inline JavaScript for real-time resource updates has no fallback**

The dynamic resource counters rely entirely on `setInterval` JavaScript. If JavaScript fails, users see stale resource numbers with no indication they are not updating. A `<noscript>` fallback or server-rendered timestamp would help.

---

## Severity Distribution

| Severity | Count |
|----------|-------|
| CRITICAL | 5     |
| HIGH     | 18    |
| MEDIUM   | 39    |
| LOW      | 16    |
| **Total**| **78**|

---

## Top 10 Priority Fixes

1. **[UX-R1-059] CRITICAL** -- Add confirmation dialog before deleting molecule classes
2. **[UX-R1-038] CRITICAL** -- Redesign ranking tables for mobile (card layout or collapsible rows)
3. **[UX-R1-071] CRITICAL** -- Add `lang="fr"` to the `<html>` element
4. **[UX-R1-016] CRITICAL** -- Ensure success/error notifications are visibly rendered (toast/snackbar)
5. **[UX-R1-017] HIGH** -- Add confirmation dialog before attacking another player
6. **[UX-R1-060] HIGH** -- Add confirmation before "delete all messages"
7. **[UX-R1-009] HIGH** -- Make resource bar persistently visible instead of hidden in a popover
8. **[UX-R1-054] HIGH** -- Style pagination links as tappable buttons with adequate touch targets
9. **[UX-R1-001] HIGH** -- Move disconnect button to bottom of side menu
10. **[UX-R1-065] MEDIUM** -- Replace `<a>` submit buttons with proper `<button type="submit">` elements
