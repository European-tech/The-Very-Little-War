# Ultra Audit Pass 1 — Domain 6: UX, Performance & Accessibility

**Date:** 2026-03-04
**Pass:** 1 (Broad Scan)
**Subagents:** 4 (UI/UX Design, Mobile/Responsive, Performance, Accessibility)

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 4 |
| HIGH | 20 |
| MEDIUM | 33 |
| LOW | 16 |
| **Total** | **73** |

---

## Area 1: UI/UX Design Review

#### P1-D6-001: Navigation buried behind hamburger menu only
- **Severity:** CRITICAL
- **Category:** UX
- **Location:** `includes/layout.php` (sidebar panel), all pages
- **Description:** All primary navigation is inside a hamburger-triggered side panel. No persistent nav bar, no tab bar, no breadcrumbs. New players cannot discover game sections.
- **Impact:** Critical discoverability problem — players miss entire game features.
- **Fix:** Add a persistent bottom tab bar (Framework7 toolbar) for top-5 pages (Base, Army, Map, Market, Alliance). Keep hamburger for secondary nav.
- **Effort:** M

#### P1-D6-002: Resources hidden in popover, not persistent header
- **Severity:** HIGH
- **Category:** UX
- **Location:** `includes/layout.php`, navbar section
- **Description:** Player's resource counts (atoms, energy, molecules) are behind a popover tap. Players must tap to see their current resources before any action.
- **Impact:** Constant friction — players can't make informed decisions at a glance.
- **Fix:** Show key resources (energy + atom count) in a persistent sub-header bar below navbar.
- **Effort:** S

#### P1-D6-003: Tutorial purely reactive, no proactive guidance
- **Severity:** HIGH
- **Category:** UX
- **Location:** `tutoriel.php`, `includes/tutorial.php`
- **Description:** Tutorial missions only appear in the tutorial page. No contextual hints, no pointing arrows, no "next step" prompts on game pages.
- **Impact:** New players don't know what to do next after completing each step.
- **Fix:** Add contextual tutorial banners on relevant pages when mission is active (e.g., "Build a Producteur" banner on constructions.php).
- **Effort:** L

#### P1-D6-004: Market price preview stale on resource type change
- **Severity:** HIGH
- **Category:** UX
- **Location:** `marche.php`, JS section
- **Description:** When changing the selected atom type in market forms, the price preview doesn't update until quantity is changed. Stale price from previous atom shown.
- **Impact:** Players may trade at unexpected prices.
- **Fix:** Trigger price recalculation on resource type `change` event, not just quantity `input`.
- **Effort:** XS

#### P1-D6-005: 11-column leaderboard with invisible sort state
- **Severity:** HIGH
- **Category:** UX
- **Location:** `classement.php`
- **Description:** Leaderboard table has 11 columns. No visual indication of which column is currently sorted. Sort direction ambiguous.
- **Impact:** Players can't understand ranking criteria at a glance.
- **Fix:** Add sort indicator arrows (▲/▼) on active column header. Reduce visible columns on mobile.
- **Effort:** S

#### P1-D6-006: 3-tap attack flow with unclear error string
- **Severity:** HIGH
- **Category:** UX
- **Location:** `attaquer.php`
- **Description:** Attack requires: select target → select army → confirm. Error message "T'y as cru ?" (colloquial French) gives no actionable feedback on why attack failed.
- **Impact:** Players don't understand attack failures.
- **Fix:** Replace with descriptive error messages: "Pas assez d'énergie (besoin: X, disponible: Y)".
- **Effort:** S

#### P1-D6-007: Empty states have no action path
- **Severity:** HIGH
- **Category:** UX
- **Location:** `rapports.php`, `armee.php`, `alliance.php`, `marche.php`
- **Description:** When a list is empty (no reports, no armies, no alliance), the page shows either nothing or "Aucun résultat" with no guidance.
- **Impact:** New players see blank pages and don't know how to proceed.
- **Fix:** Add contextual empty states with CTA: "Pas encore de rapports. Lancez votre première attaque →".
- **Effort:** S

#### P1-D6-008: Errors shown only via JS alert()
- **Severity:** HIGH
- **Category:** UX
- **Location:** Multiple pages (market, army, construction)
- **Description:** Server-side errors are only communicated via JavaScript `alert()` boxes. No server-side inline error fallback for JS-disabled users.
- **Impact:** Poor UX, no persistent error context, accessibility issues.
- **Fix:** Add inline error banners (Framework7 notification component) alongside removing alert().
- **Effort:** M

#### P1-D6-009: No active state on current page in navigation
- **Severity:** MEDIUM
- **Category:** UX
- **Location:** `includes/layout.php`, sidebar links
- **Description:** Sidebar navigation links have no visual distinction for the current page. All links look identical.
- **Impact:** Players lose orientation within the app.
- **Fix:** Add `active` class to current page link based on `$_SERVER['SCRIPT_NAME']`.
- **Effort:** XS

#### P1-D6-010: Molecule delete has no confirmation and tiny touch target
- **Severity:** MEDIUM
- **Category:** UX
- **Location:** `armee.php`
- **Description:** Delete button on molecules is small and triggers immediately. No confirmation dialog. Accidental taps delete armies.
- **Impact:** Players accidentally lose carefully built armies.
- **Fix:** Add confirmation modal. Increase button touch target to 44px minimum.
- **Effort:** S

#### P1-D6-011: Button hierarchy inconsistent across pages
- **Severity:** MEDIUM
- **Category:** UX
- **Location:** Multiple pages
- **Description:** Primary actions use inconsistent button styles — some are `color-green`, some `color-blue`, some plain. No consistent visual hierarchy.
- **Impact:** Players can't quickly identify the primary action on each page.
- **Fix:** Establish button hierarchy: primary (green), secondary (blue), destructive (red), neutral (gray).
- **Effort:** S

#### P1-D6-012: Time displays use raw seconds/timestamps
- **Severity:** MEDIUM
- **Category:** UX
- **Location:** `constructions.php`, `armee.php`, `includes/ui_components.php`
- **Description:** Some time values display as raw seconds or Unix timestamps. No consistent human-readable time formatting.
- **Impact:** Players can't easily understand durations.
- **Fix:** Create a `formatDuration()` helper that outputs "2h 15m" format consistently.
- **Effort:** S

#### P1-D6-013: No progress bars for building construction
- **Severity:** MEDIUM
- **Category:** UX
- **Location:** `constructions.php`
- **Description:** Building construction shows only "En construction" text with time remaining. No visual progress bar.
- **Impact:** Players lack a visual sense of progress.
- **Fix:** Add Framework7 progressbar component showing % complete.
- **Effort:** S

#### P1-D6-014: No success/failure feedback after actions
- **Severity:** MEDIUM
- **Category:** UX
- **Location:** Form submissions across all pages
- **Description:** After submitting forms (trade, build, attack), the page reloads with no explicit success message. Players must infer success from changed state.
- **Impact:** Uncertainty about whether action completed.
- **Fix:** Add Framework7 toast notifications for successful actions.
- **Effort:** M

#### P1-D6-015: Account deletion has no extra confirmation
- **Severity:** MEDIUM
- **Category:** UX
- **Location:** `compte.php`
- **Description:** Account deletion button triggers with a single click and a basic confirm() dialog. No "type DELETE to confirm" pattern.
- **Impact:** Accidental account deletions possible.
- **Fix:** Add multi-step confirmation: type account name to confirm.
- **Effort:** S

#### P1-D6-016: Color palette overload — 6+ accent colors
- **Severity:** MEDIUM
- **Category:** UX
- **Location:** CSS files, inline styles
- **Description:** The app uses green, blue, red, orange, yellow, purple, and teal without systematic purpose. No consistent color meaning.
- **Impact:** Visual noise, no semantic color system.
- **Fix:** Define 4-color system: primary (green/chemistry), secondary (blue), warning (orange), danger (red).
- **Effort:** M

#### P1-D6-017: Typography lacks hierarchy
- **Severity:** LOW
- **Category:** UX
- **Location:** All pages
- **Description:** Font sizes jump inconsistently between sections. No clear h1→h2→h3 visual hierarchy on most pages.
- **Impact:** Harder to scan and understand page structure.
- **Fix:** Define type scale: h1=24px, h2=20px, h3=16px, body=14px with consistent weights.
- **Effort:** S

#### P1-D6-018: Market chart lacks context
- **Severity:** LOW
- **Category:** UX
- **Location:** `marche.php`, Chart.js integration
- **Description:** Market price chart shows line but no tooltips, no axis labels, no volume bars.
- **Impact:** Limited analytical value for traders.
- **Fix:** Add tooltips, axis labels, volume overlay.
- **Effort:** S

#### P1-D6-019: Registration form maxlength inconsistency
- **Severity:** LOW
- **Category:** UX
- **Location:** `inscription.php`
- **Description:** HTML maxlength attributes may not match server-side validation constants (LOGIN_MAX_LENGTH etc).
- **Impact:** Minor validation confusion.
- **Fix:** Ensure HTML maxlength matches PHP constants dynamically.
- **Effort:** XS

---

## Area 2: Mobile & Responsive Design

#### P1-D6-020: Map canvas completely unusable on mobile
- **Severity:** CRITICAL
- **Category:** Responsive
- **Location:** `carte.php`, `js/carte.js`
- **Description:** The HTML5 canvas map renders at a fixed size. No pinch-to-zoom, no pan gestures, no mobile viewport adaptation. Touch events not handled.
- **Impact:** Core game feature (map/territory) completely broken on mobile.
- **Fix:** Add touch event handlers (pinch zoom, pan), make canvas responsive to viewport, add zoom controls.
- **Effort:** L

#### P1-D6-021: Banner/header hardcoded positioning breaks on mobile
- **Severity:** HIGH
- **Category:** Responsive
- **Location:** CSS, `includes/layout.php`
- **Description:** Site banner uses `position:fixed; left:15%` hardcoded. On narrow screens, this overlaps content or goes off-screen.
- **Impact:** Header broken on screens <768px.
- **Fix:** Use responsive positioning with media queries or flexbox centering.
- **Effort:** S

#### P1-D6-022: 11-column leaderboard table overflows on mobile
- **Severity:** HIGH
- **Category:** Responsive
- **Location:** `classement.php`
- **Description:** Full leaderboard table (11 columns) has no horizontal scroll wrapper and no responsive column hiding. Content overflows viewport.
- **Impact:** Leaderboard unusable on mobile.
- **Fix:** Add horizontal scroll wrapper, hide non-essential columns on mobile (show rank, name, score, alliance only).
- **Effort:** S

#### P1-D6-023: Rapports list has malformed HTML
- **Severity:** HIGH
- **Category:** Code Quality / Responsive
- **Location:** `rapports.php`
- **Description:** Report list table may have missing `<tr>` tags, causing inconsistent rendering across browsers and broken layout on mobile.
- **Impact:** Broken display on some browsers/devices.
- **Fix:** Audit and fix HTML table structure in rapports.php.
- **Effort:** S

#### P1-D6-024: Market inputs use type="text" instead of type="number"
- **Severity:** HIGH
- **Category:** Responsive / UX
- **Location:** `marche.php`
- **Description:** Quantity and price inputs use `type="text"`. On mobile, this shows a full keyboard instead of the numeric keypad.
- **Impact:** Extra friction on every market transaction on mobile.
- **Fix:** Change to `type="number" inputmode="numeric" pattern="[0-9]*"`.
- **Effort:** XS

#### P1-D6-025: Email input uses type="text" instead of type="email"
- **Severity:** HIGH
- **Category:** Responsive / UX
- **Location:** `inscription.php`, `compte.php`
- **Description:** Email fields use `type="text"` missing mobile keyboard optimization and browser validation.
- **Impact:** No @ keyboard shortcut on mobile, no browser validation.
- **Fix:** Change to `type="email"`.
- **Effort:** XS

#### P1-D6-026: No CSS media queries for custom styles
- **Severity:** MEDIUM
- **Category:** Responsive
- **Location:** Custom CSS files
- **Description:** All custom CSS is written for a single viewport size. No `@media` breakpoints for responsive adaptation beyond Framework7's built-in.
- **Impact:** Custom elements don't adapt to different screen sizes.
- **Fix:** Add breakpoints at 480px, 768px, 1024px for custom layouts.
- **Effort:** M

#### P1-D6-027: Forum post content overflows on narrow screens
- **Severity:** MEDIUM
- **Category:** Responsive
- **Location:** `forum/afficher.php`
- **Description:** Long forum posts (especially with code or links) overflow their containers on mobile. No `word-break` or `overflow-wrap`.
- **Impact:** Horizontal scroll, broken layout.
- **Fix:** Add `word-break: break-word; overflow-wrap: break-word` to post content.
- **Effort:** XS

#### P1-D6-028: Alliance page layout assumes wide viewport
- **Severity:** MEDIUM
- **Category:** Responsive
- **Location:** `alliance.php`
- **Description:** Alliance member table and research grid assume desktop width. No stacking or card layout for mobile.
- **Impact:** Cramped, unreadable layout on mobile.
- **Fix:** Switch to card-based member display on mobile, stack research items.
- **Effort:** M

#### P1-D6-029: Construction cards don't stack properly on mobile
- **Severity:** MEDIUM
- **Category:** Responsive
- **Location:** `constructions.php`
- **Description:** Building cards use fixed-width layouts that don't flow properly on narrow viewports.
- **Impact:** Horizontal overflow or cramped cards on mobile.
- **Fix:** Use CSS grid with `auto-fill` and `minmax()` for responsive card grid.
- **Effort:** S

#### P1-D6-030: Modal dialogs don't adapt to mobile viewport
- **Severity:** MEDIUM
- **Category:** Responsive
- **Location:** Various pages using Framework7 modals
- **Description:** Some custom modals use fixed widths or heights that don't account for mobile viewports.
- **Impact:** Modals overflow or are partially hidden on small screens.
- **Fix:** Use Framework7's built-in modal responsive options, max-width: 90vw.
- **Effort:** S

#### P1-D6-031: BBCode rendered content not responsive
- **Severity:** MEDIUM
- **Category:** Responsive
- **Location:** `includes/bb2html()` function output
- **Description:** BBCode-rendered images and tables don't have max-width constraints.
- **Impact:** User-posted images/tables break forum layout on mobile.
- **Fix:** Add `max-width: 100%; height: auto` to BBCode image output. Wrap tables in scrollable container.
- **Effort:** S

#### P1-D6-032: Army designer form cramped on mobile
- **Severity:** MEDIUM
- **Category:** Responsive
- **Location:** `armee.php`
- **Description:** Atom selection grid and quantity inputs don't reflow on narrow screens.
- **Impact:** Difficult to build armies on mobile.
- **Fix:** Stack atom selectors vertically on mobile, full-width quantity inputs.
- **Effort:** S

#### P1-D6-033: Prestige shop items overflow on mobile
- **Severity:** MEDIUM
- **Category:** Responsive
- **Location:** `prestige.php`
- **Description:** Prestige unlock cards use side-by-side layout that doesn't stack on mobile.
- **Impact:** Cards overflow or are too narrow to read.
- **Fix:** Single-column stack on mobile using media query.
- **Effort:** S

#### P1-D6-034: No viewport meta tag verification
- **Severity:** LOW
- **Category:** Responsive
- **Location:** `includes/layout.php`
- **Description:** Verify `<meta name="viewport" content="width=device-width, initial-scale=1">` is present and correct on all page templates.
- **Impact:** Pages may not scale correctly on mobile without proper viewport meta.
- **Fix:** Verify and add if missing.
- **Effort:** XS

#### P1-D6-035: Touch targets below 44px minimum
- **Severity:** LOW
- **Category:** Responsive
- **Location:** Multiple pages — small links, buttons, icons
- **Description:** Many interactive elements (delete buttons, sort toggles, pagination links) are below the 44px × 44px WCAG touch target guideline.
- **Impact:** Difficult to tap accurately on mobile.
- **Fix:** Increase tap targets via padding or min-height/min-width.
- **Effort:** M

#### P1-D6-036: Landscape orientation not optimized
- **Severity:** LOW
- **Category:** Responsive
- **Location:** All pages
- **Description:** No specific landscape optimization. Pages look the same in portrait and landscape on mobile.
- **Impact:** Wasted horizontal space in landscape mode.
- **Fix:** Low priority — add landscape-specific layouts for map and leaderboard.
- **Effort:** L

#### P1-D6-037: No "back to top" button on long pages
- **Severity:** LOW
- **Category:** Responsive
- **Location:** Forum, classement.php, rapports.php
- **Description:** Long scrollable pages have no quick scroll-to-top button.
- **Impact:** Minor inconvenience on mobile.
- **Fix:** Add floating "back to top" button appearing after scroll threshold.
- **Effort:** XS

#### P1-D6-038: Favicon/icons not optimized for mobile home screen
- **Severity:** LOW
- **Category:** Responsive
- **Location:** `includes/meta.php`
- **Description:** Missing apple-touch-icon, manifest.json for PWA, and various mobile icon sizes.
- **Impact:** Ugly or missing icon when users add to home screen.
- **Fix:** Generate icon set (192px, 512px) and add manifest.json.
- **Effort:** S

#### P1-D6-039: Print stylesheet missing
- **Severity:** LOW
- **Category:** Responsive
- **Location:** N/A
- **Description:** No print-specific CSS. Printing pages includes navigation, sidebars, and broken layouts.
- **Impact:** Cannot print reports or rankings cleanly.
- **Fix:** Add `@media print` stylesheet hiding nav, fixing widths.
- **Effort:** S

---

## Area 3: Performance Optimization

#### P1-D6-040: revenuEnergie() runs redundant queries per building level
- **Severity:** MEDIUM
- **Category:** Performance
- **Location:** `includes/formulas.php`
- **Description:** `revenuEnergie()` queries database for each detail level when called in loops. Same data fetched multiple times per page load.
- **Impact:** Extra DB roundtrips on pages showing energy breakdowns.
- **Fix:** Cache building data at start of request, pass to formula functions.
- **Effort:** S

#### P1-D6-041: revenuAtome() has similar redundant query pattern
- **Severity:** MEDIUM
- **Category:** Performance
- **Location:** `includes/formulas.php`
- **Description:** Same issue as P1-D6-040 for atom revenue calculation.
- **Impact:** Extra DB roundtrips.
- **Fix:** Same cache pattern as P1-D6-040.
- **Effort:** S

#### P1-D6-042: initPlayer() writes batmax UPDATE every page load
- **Severity:** HIGH
- **Category:** Performance
- **Location:** `includes/player.php`, `initPlayer()`
- **Description:** `initPlayer()` recalculates and writes `batmax` (max buildings) to DB on EVERY page load, even when value hasn't changed. Unconditional UPDATE.
- **Impact:** One unnecessary write query per page view per player.
- **Fix:** Only UPDATE if calculated value differs from cached value.
- **Effort:** S

#### P1-D6-043: updateRessources() uses per-row UPDATE loop
- **Severity:** HIGH
- **Category:** Performance
- **Location:** `includes/game_resources.php`
- **Description:** Resource updates are done with individual UPDATE statements per resource type in a loop instead of a single multi-column UPDATE.
- **Impact:** 8 UPDATE queries where 1 would suffice.
- **Fix:** Combine into single UPDATE statement with all resource columns.
- **Effort:** S

#### P1-D6-044: prestige.php getPrestige() not cached
- **Severity:** HIGH
- **Category:** Performance
- **Location:** `includes/formulas.php` or `prestige.php`
- **Description:** `getPrestige()` (PP calculation) involves multiple DB queries and is called multiple times on prestige.php without caching.
- **Impact:** Redundant heavy queries on prestige page.
- **Fix:** Cache PP result in `$_SESSION` or request-scoped static variable.
- **Effort:** S

#### P1-D6-045: N+1 query in attaquer.php JS generation
- **Severity:** HIGH
- **Category:** Performance
- **Location:** `attaquer.php`
- **Description:** Attack target list generates JS data by querying player details one by one in a loop (N+1 pattern).
- **Impact:** O(N) queries where N = number of attackable players.
- **Fix:** Single query with JOIN to get all target data at once.
- **Effort:** S

#### P1-D6-046: recalculerStatsAlliances() fires on every page view
- **Severity:** HIGH
- **Category:** Performance
- **Location:** `includes/game_actions.php` or `includes/player.php`
- **Description:** Alliance statistics (member count, total resources, ranking) are recalculated on every page load via `updateActions()`.
- **Impact:** Heavy write storm — multiple UPDATEs to alliance table on every page view.
- **Fix:** Recalculate only when alliance data changes (member join/leave, resource update), or cache with TTL.
- **Effort:** M

#### P1-D6-047: No HTTP cache headers on static assets
- **Severity:** HIGH
- **Category:** Performance
- **Location:** `.htaccess` or Apache config
- **Description:** Images, CSS, and JS files don't have Cache-Control or Expires headers. Every page load re-fetches all assets.
- **Impact:** Significantly slower page loads, wasted bandwidth.
- **Fix:** Add `ExpiresByType` directives in .htaccess for images (30d), CSS/JS (7d).
- **Effort:** XS

#### P1-D6-048: No gzip/deflate compression enabled
- **Severity:** HIGH
- **Category:** Performance
- **Location:** Apache config / `.htaccess`
- **Description:** HTML, CSS, and JS responses are not compressed. Full payload sent on every request.
- **Impact:** 60-80% larger payloads than necessary.
- **Fix:** Enable `mod_deflate` in Apache for text/html, text/css, application/javascript.
- **Effort:** XS

#### P1-D6-049: 34MB+ unoptimized images in images/ directory
- **Severity:** MEDIUM
- **Category:** Performance
- **Location:** `images/` directory
- **Description:** Many game images are unoptimized PNGs and BMPs. Total image directory may exceed 34MB.
- **Impact:** Slow page loads, especially on mobile networks.
- **Fix:** Convert BMPs to PNG, optimize PNGs with `optipng`, consider WebP with PNG fallback.
- **Effort:** M

#### P1-D6-050: Framework7 CSS loaded in full (200KB+)
- **Severity:** MEDIUM
- **Category:** Performance
- **Location:** `includes/layout.php`
- **Description:** Full Framework7 CSS bundle loaded even though only ~30% of components are used.
- **Impact:** 140KB+ of unused CSS on every page load.
- **Fix:** Create custom Framework7 build with only used components, or use PurgeCSS.
- **Effort:** M

#### P1-D6-051: Multiple synchronous JS files in <head>
- **Severity:** MEDIUM
- **Category:** Performance
- **Location:** `includes/layout.php`
- **Description:** JS files loaded synchronously in `<head>` without `defer` or `async` attributes, blocking page render.
- **Impact:** Slower first contentful paint.
- **Fix:** Add `defer` to non-critical JS, move scripts to end of body.
- **Effort:** S

#### P1-D6-052: No lazy loading on images
- **Severity:** MEDIUM
- **Category:** Performance
- **Location:** All pages with images
- **Description:** All images load eagerly on page load. No `loading="lazy"` attribute.
- **Impact:** Longer initial page load, especially on image-heavy pages (map, army).
- **Fix:** Add `loading="lazy"` to below-fold images.
- **Effort:** S

#### P1-D6-053: Chart.js loaded on all pages, used on 2
- **Severity:** MEDIUM
- **Category:** Performance
- **Location:** `includes/layout.php`
- **Description:** Chart.js library (60KB+) is loaded globally but only used on market and potentially bilan pages.
- **Impact:** Unnecessary payload on ~95% of page loads.
- **Fix:** Load Chart.js only on pages that use it (conditional include).
- **Effort:** S

#### P1-D6-054: basicprivatehtml.php sidebar renders 15-19 queries
- **Severity:** MEDIUM
- **Category:** Performance
- **Location:** `includes/basicprivatehtml.php`
- **Description:** Sidebar navigation renders unread counts, resource summaries, and notifications — each requiring separate DB queries.
- **Impact:** 15-19 queries per page load just for sidebar.
- **Fix:** Single aggregated query for sidebar data, cache in session with short TTL (60s).
- **Effort:** M

#### P1-D6-055: Session writes on every request
- **Severity:** MEDIUM
- **Category:** Performance
- **Location:** PHP session handling
- **Description:** PHP default file-based sessions lock the session file for the entire request duration, preventing concurrent requests from same user.
- **Impact:** Tab switching causes request queuing.
- **Fix:** Use `session_write_close()` early after reading session data, before heavy processing.
- **Effort:** S

#### P1-D6-056: No database query logging/profiling
- **Severity:** LOW
- **Category:** Performance
- **Location:** `includes/database.php`
- **Description:** No mechanism to log slow queries or count total queries per page load in development.
- **Impact:** Performance issues hard to diagnose.
- **Fix:** Add optional query counter and slow query log (>100ms) to dbQuery wrapper.
- **Effort:** S

#### P1-D6-057: No CDN for static assets
- **Severity:** LOW
- **Category:** Performance
- **Location:** Infrastructure
- **Description:** All assets served from single VPS. No CDN edge caching.
- **Impact:** Higher latency for geographically distant players.
- **Fix:** Consider Cloudflare free tier as CDN proxy.
- **Effort:** M

#### P1-D6-058: PHP output buffering not explicit
- **Severity:** LOW
- **Category:** Performance
- **Location:** All PHP files
- **Description:** No explicit `ob_start()` for output buffering. Default PHP config may or may not buffer.
- **Impact:** Potential for partial page renders on slow queries.
- **Fix:** Add `ob_start()` at top of layout to ensure complete page delivery.
- **Effort:** XS

#### P1-D6-059: No ETag headers for dynamic content
- **Severity:** LOW
- **Category:** Performance
- **Location:** Apache config
- **Description:** Dynamic PHP pages don't send ETag or Last-Modified headers. Browsers can't cache-validate.
- **Impact:** Minor — dynamic content changes frequently.
- **Fix:** Low priority — add ETags only for semi-static pages (rules, player guide).
- **Effort:** S

---

## Area 4: Accessibility

#### P1-D6-060: Missing lang="fr" on html element
- **Severity:** CRITICAL
- **Category:** Accessibility
- **Location:** `includes/layout.php`, `<html>` tag
- **Description:** The `<html>` element has no `lang` attribute. Screen readers default to English pronunciation for French content.
- **Impact:** Screen reader users hear French words with English pronunciation — unintelligible.
- **Fix:** Add `lang="fr"` to `<html>` tag in layout.php.
- **Effort:** XS

#### P1-D6-061: UTF-8 declared but database uses Latin1
- **Severity:** CRITICAL
- **Category:** Accessibility / i18n
- **Location:** `includes/layout.php` (meta charset), `includes/connexion.php`
- **Description:** HTML declares `charset=UTF-8` but database and PHP use Latin1. French characters (é, è, à) may double-encode or corrupt.
- **Impact:** Broken accented characters, screen reader confusion.
- **Fix:** Ensure consistent charset handling: either migrate DB to UTF-8 or ensure proper Latin1→UTF-8 conversion at output boundary.
- **Effort:** L

#### P1-D6-062: Images missing alt attributes
- **Severity:** HIGH
- **Category:** Accessibility
- **Location:** Multiple pages (atom icons, building icons, medals)
- **Description:** Many `<img>` tags lack `alt` attributes or have empty `alt=""` even when conveying meaningful information.
- **Impact:** Screen reader users miss visual game information.
- **Fix:** Add descriptive alt text: `alt="Atome Carbone (C)"`, `alt="Producteur niveau 3"`.
- **Effort:** M

#### P1-D6-063: No CSS focus indicators
- **Severity:** HIGH
- **Category:** Accessibility
- **Location:** CSS (global styles)
- **Description:** Focus outlines are removed or invisible (`outline: none` or browser default overridden). Keyboard users can't see which element is focused.
- **Impact:** Keyboard-only navigation impossible.
- **Fix:** Add visible `:focus-visible` styles on all interactive elements.
- **Effort:** S

#### P1-D6-064: Form labels not associated with inputs
- **Severity:** HIGH
- **Category:** Accessibility
- **Location:** Multiple forms (inscription.php, compte.php, marche.php)
- **Description:** Form inputs use placeholder text or adjacent text but no `<label for="...">` association.
- **Impact:** Screen readers can't announce field purpose, auto-fill broken.
- **Fix:** Add `<label for="fieldId">` to all form inputs.
- **Effort:** M

#### P1-D6-065: No i18n infrastructure
- **Severity:** MEDIUM
- **Category:** Accessibility / i18n
- **Location:** All PHP files
- **Description:** All user-facing strings are hardcoded in French. No translation system, no gettext, no language files.
- **Impact:** Game inaccessible to non-French speakers, can't add language support.
- **Fix:** Long-term: Extract strings to language files. Short-term: Low priority for single-language game.
- **Effort:** XL

#### P1-D6-066: Tables lack proper header markup
- **Severity:** MEDIUM
- **Category:** Accessibility
- **Location:** `classement.php`, `rapports.php`, `alliance.php`
- **Description:** Data tables use `<td>` for header cells instead of `<th>` with `scope` attributes.
- **Impact:** Screen readers can't associate data cells with their headers.
- **Fix:** Use `<th scope="col">` for column headers, `<th scope="row">` for row headers.
- **Effort:** S

#### P1-D6-067: Color alone conveys meaning
- **Severity:** MEDIUM
- **Category:** Accessibility
- **Location:** Multiple pages (resource states, combat results)
- **Description:** Red/green/orange colors are used as sole indicator of status (win/loss, resource level, online/offline). No text or icon backup.
- **Impact:** Color-blind users miss critical information.
- **Fix:** Add text labels or icons alongside color indicators: "Victoire ✓" (green), "Défaite ✗" (red).
- **Effort:** S

#### P1-D6-068: ARIA landmarks missing
- **Severity:** MEDIUM
- **Category:** Accessibility
- **Location:** `includes/layout.php`
- **Description:** Page lacks ARIA landmark roles (`role="navigation"`, `role="main"`, `role="banner"`).
- **Impact:** Screen reader users can't quickly navigate between page sections.
- **Fix:** Add ARIA roles to layout sections, or use semantic HTML5 elements (`<nav>`, `<main>`, `<header>`).
- **Effort:** S

#### P1-D6-069: No skip navigation link
- **Severity:** MEDIUM
- **Category:** Accessibility
- **Location:** `includes/layout.php`
- **Description:** No "Skip to main content" link for keyboard users. Must tab through entire navigation on every page.
- **Impact:** Keyboard users waste time tabbing through nav on every page load.
- **Fix:** Add hidden skip link as first focusable element: `<a href="#main" class="sr-only">Aller au contenu</a>`.
- **Effort:** XS

#### P1-D6-070: Interactive elements not keyboard accessible
- **Severity:** MEDIUM
- **Category:** Accessibility
- **Location:** Multiple pages (custom buttons, map clicks, tab switchers)
- **Description:** Some interactive elements use `<div onclick="...">` or `<span>` instead of `<button>` or `<a>`. Not keyboard-focusable.
- **Impact:** Keyboard users can't interact with these elements.
- **Fix:** Use semantic `<button>` elements or add `tabindex="0" role="button"` with `keydown` handler.
- **Effort:** M

#### P1-D6-071: Insufficient color contrast
- **Severity:** MEDIUM
- **Category:** Accessibility
- **Location:** Various pages — light text on colored backgrounds
- **Description:** Some text/background combinations don't meet WCAG AA contrast ratio (4.5:1 for normal text, 3:1 for large text).
- **Impact:** Text hard to read for low-vision users.
- **Fix:** Audit with contrast checker, adjust colors to meet 4.5:1 minimum.
- **Effort:** M

#### P1-D6-072: Dynamic content updates not announced
- **Severity:** MEDIUM
- **Category:** Accessibility
- **Location:** AJAX updates, countdown timer, market prices
- **Description:** Content updated via JavaScript (countdown, market refresh, resource ticks) has no `aria-live` regions.
- **Impact:** Screen reader users miss dynamic updates.
- **Fix:** Add `aria-live="polite"` to dynamically updated regions.
- **Effort:** S

#### P1-D6-073: Page titles not descriptive
- **Severity:** LOW
- **Category:** Accessibility
- **Location:** `includes/layout.php`, `<title>` tag
- **Description:** Page titles may be generic or same across pages ("TVLW" on every page) instead of descriptive.
- **Impact:** Tab navigation and screen reader page identification harder.
- **Fix:** Set unique `<title>` per page: "Constructions | TVLW", "Marché | TVLW".
- **Effort:** S
