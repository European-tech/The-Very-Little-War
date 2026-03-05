# Pass 1 — Domain 9: UI & Visual Design
## Broad Scan Results

**Date:** 2026-03-04
**Subagents:** 5 (Visual Audit, CSS Theme, Page-by-Page, Image Assets, Responsive)
**Total Findings:** 100

### Severity Distribution
| Severity | Count |
|----------|-------|
| CRITICAL | 2 |
| HIGH | 21 |
| MEDIUM | 50 |
| LOW | 27 |

---

## 9.1 Visual Audit (P1-D9-001 — P1-D9-020)

**P1-D9-001** | HIGH | Missing espionage action icon
- File: images/ directory
- No dedicated icon for espionage action; uses generic placeholder
- Impact: Players can't visually identify espionage in action lists

**P1-D9-002** | HIGH | Six diamond-tier medal images missing
- Files: images/medailles/
- Diamond variants for 6 medal categories not created
- Impact: Prestige system shows broken images for top-tier achievements

**P1-D9-003** | MEDIUM | Favicon MIME type incorrect
- File: favicon.ico served without proper image/x-icon Content-Type
- Impact: Some browsers may not display favicon

**P1-D9-004** | MEDIUM | Open Graph image undersized
- File: images/og-image or meta.php reference
- OG image below 1200×630 recommended minimum
- Impact: Poor social media preview cards

**P1-D9-005** | MEDIUM | Building icons inconsistent sizes
- Files: images/batiments/
- Building icons vary between 32px, 48px, 64px with no standard
- Impact: Jagged visual rhythm in building lists

**P1-D9-006** | MEDIUM | Screenshots in wrong directory
- Files: images/ root level
- Game screenshots mixed with UI assets instead of dedicated folder
- Impact: Asset management confusion

**P1-D9-007** | MEDIUM | PSD source files exposed in production
- Files: images/*.psd
- Photoshop source files accessible via web server
- Impact: Unnecessary file exposure, wasted bandwidth if crawled

**P1-D9-008** | MEDIUM | Duplicate icon files
- Files: Multiple identical icons with different names
- Impact: Increased repository size, maintenance confusion

**P1-D9-009** | MEDIUM | Planet images unoptimized
- Files: images/planetes/
- Large PNG sprites without compression optimization
- Impact: Slow page loads on map view

**P1-D9-010** | LOW | No SVG icons used anywhere
- All icons are raster PNG/GIF, no vector alternatives
- Impact: Icons blur on high-DPI screens

**P1-D9-011** | LOW | Inconsistent icon naming convention
- Files: images/ — mix of French/English, camelCase/snake_case
- Impact: Developer confusion when referencing assets

**P1-D9-012** | LOW | No dark mode icon variants
- All icons designed for light backgrounds only
- Impact: Poor contrast if dark theme ever implemented

**P1-D9-013** | LOW | Missing alt text patterns for decorative images
- Multiple img tags lack alt="" for decorative images
- Impact: Screen readers announce file names

**P1-D9-014** | LOW | Avatar images oversized for display size
- Files: images/avatars/ — 170 legacy avatars at full resolution
- Impact: Bandwidth waste, avatars displayed at 40-60px but served at 200px+

**P1-D9-015** | LOW | No image lazy loading
- All images loaded eagerly regardless of viewport position
- Impact: Slower initial page render

**P1-D9-016** | LOW | Missing loading states for images
- No skeleton/placeholder while images load
- Impact: Layout shifts as images appear

**P1-D9-017** | LOW | Color palette lacks formal definition
- No design tokens or documented color system
- Impact: Inconsistent color usage across pages

**P1-D9-018** | LOW | No visual hierarchy in navigation
- All nav items same visual weight
- Impact: Users can't quickly identify primary actions

**P1-D9-019** | MEDIUM | Action buttons lack visual feedback
- Click/tap states not clearly differentiated from hover
- Impact: Users unsure if action registered

**P1-D9-020** | MEDIUM | No empty state illustrations
- Empty lists show only text, no visual guidance
- Impact: Cold, uninviting experience for new features

---

## 9.2 CSS Theme Enhancement (P1-D9-021 — P1-D9-040)

**P1-D9-021** | CRITICAL | CSS typo breaks .imageClassement rule
- File: CSS containing `height; 32px;` (semicolon instead of colon)
- Impact: Entire rule block ignored by parser, leaderboard images unstyled

**P1-D9-022** | HIGH | Zero CSS custom properties used
- All colors, sizes, spacing hardcoded throughout CSS
- Impact: Theme changes require editing hundreds of values

**P1-D9-023** | HIGH | Invalid color names in CSS
- Values like `marroon` and `fuschia` — misspelled CSS color keywords
- Impact: Browser falls back to default, unexpected rendering

**P1-D9-024** | HIGH | Invalid font-weight: regular
- `font-weight: regular` is not a valid CSS value (should be `normal` or `400`)
- Impact: Font weight rule ignored, inconsistent typography

**P1-D9-025** | HIGH | 501 inline styles across HTML/PHP files
- Massive use of style="" attributes instead of CSS classes
- Impact: Unmaintainable, overrides CSS cascade, impossible to theme

**P1-D9-026** | MEDIUM | `font-family: default` is not valid CSS
- Impact: Font-family rule ignored, falls back to browser default unpredictably

**P1-D9-027** | MEDIUM | Duplicate @font-face declarations
- Same font loaded multiple times with slightly different paths
- Impact: Wasted bandwidth, potential FOUT issues

**P1-D9-028** | MEDIUM | No zebra striping on data tables
- Large tables (leaderboard, market, reports) have no alternating row colors
- Impact: Hard to track data across wide rows

**P1-D9-029** | MEDIUM | Global `.button { color: red }` override
- Overly broad selector turns all buttons red
- Impact: Unintended red text on Framework7 buttons

**P1-D9-030** | MEDIUM | No CSS transitions on interactive elements
- Buttons, links, panels have no transition effects
- Impact: UI feels static and unpolished

**P1-D9-031** | MEDIUM | Inconsistent spacing units
- Mix of px, em, rem, and bare numbers throughout CSS
- Impact: Unpredictable spacing across screen sizes

**P1-D9-032** | MEDIUM | No focus-visible styles
- Keyboard focus indicators rely on browser defaults
- Impact: Poor accessibility for keyboard navigation

**P1-D9-033** | MEDIUM | CSS specificity wars
- Multiple !important declarations fighting each other
- Impact: Styling difficult to override or maintain

**P1-D9-034** | MEDIUM | No print stylesheet
- Game pages print with navigation, backgrounds, and broken layouts
- Impact: Players can't print reports or guides usefully

**P1-D9-035** | MEDIUM | Color contrast failures
- Several text/background combinations below WCAG AA 4.5:1 ratio
- Impact: Accessibility barrier for low-vision users

**P1-D9-036** | LOW | No CSS minification in production
- Full readable CSS served to clients
- Impact: Larger file sizes than necessary

**P1-D9-037** | LOW | Unused CSS selectors
- Multiple selectors target elements that no longer exist in HTML
- Impact: Dead code bloat in stylesheets

**P1-D9-038** | LOW | No CSS methodology (BEM, OOCSS)
- Class names are ad-hoc with no naming convention
- Impact: Hard to understand scope and intent of styles

**P1-D9-039** | LOW | No dark mode support
- No prefers-color-scheme media query or toggle
- Impact: Bright UI in dark environments

**P1-D9-040** | LOW | No CSS custom scrollbar styling
- Default browser scrollbars in overflow containers
- Impact: Visual inconsistency with game theme

---

## 9.3 Page-by-Page UI Review (P1-D9-041 — P1-D9-060)

**P1-D9-041** | MEDIUM | Homepage has duplicate feature blocks
- Same feature descriptions repeated in hero section
- Impact: Confusing layout, wastes vertical space

**P1-D9-042** | MEDIUM | Army tab state lost on form submit
- Selecting a tab then submitting resets to first tab
- Impact: User loses context, must re-navigate

**P1-D9-043** | HIGH | Map has no standalone page
- Map embedded in carte.php but not accessible as full-page view
- Impact: Mobile users can't zoom or explore map properly

**P1-D9-044** | HIGH | Reports image column shows raw filename
- File: rapports.php — image column renders filename text instead of img tag
- Impact: Broken visual in battle reports

**P1-D9-045** | HIGH | Alliance TAG maxlength uses PHP literal
- Hardcoded `maxlength="4"` instead of config constant
- Impact: If TAG length changes in config, form doesn't match

**P1-D9-046** | MEDIUM | Market cost fields update on blur not input
- Price calculation only updates when field loses focus
- Impact: Users don't see real-time cost while typing

**P1-D9-047** | MEDIUM | Construction timer missing completion date
- Timer shows remaining time but not target completion datetime
- Impact: Players can't plan around specific times

**P1-D9-048** | MEDIUM | Leaderboard has no sort indicator
- Active sort column not visually distinguished
- Impact: Users unsure which column is currently sorted

**P1-D9-049** | MEDIUM | Laboratoire shows raw key codes
- Compound names displayed as internal keys instead of formatted names
- Impact: Poor readability for players

**P1-D9-050** | MEDIUM | Vacation mode date picker broken
- Date input for vacation end may not render properly on all browsers
- Impact: Players can't set precise vacation end dates

**P1-D9-051** | MEDIUM | Prestige page "no bonus" shown falsely
- When bonuses exist but PP is zero, shows "no bonus" message
- Impact: Confusing — bonuses exist but page says none

**P1-D9-052** | MEDIUM | Bilan/tutoriel accent characters stripped
- French accents (é, è, ê, à) display as garbled text in some contexts
- Impact: Poor readability for French-speaking players

**P1-D9-053** | HIGH | Invisible alliance invitation buttons
- Accept/decline buttons for alliance invites have no visible styling
- Impact: Players can't interact with invitations

**P1-D9-054** | MEDIUM | Forum post form too narrow
- BBCode editor textarea constrained to small width
- Impact: Hard to compose longer messages

**P1-D9-055** | MEDIUM | No confirmation feedback on successful actions
- Form submissions silently succeed with no toast/banner
- Impact: Users unsure if action completed

**P1-D9-056** | LOW | Login form lacks password visibility toggle
- No eye icon to show/hide password
- Impact: Minor UX inconvenience

**P1-D9-057** | LOW | No breadcrumb navigation
- Deep pages (alliance > members > profile) have no path indicator
- Impact: Users can feel lost in navigation hierarchy

**P1-D9-058** | LOW | Registration form field order suboptimal
- Login/password fields not in expected order for autofill
- Impact: Password managers may not auto-populate correctly

**P1-D9-059** | LOW | No loading spinner on AJAX actions
- API calls show no visual feedback during processing
- Impact: Users may double-click thinking nothing happened

**P1-D9-060** | MEDIUM | Tutoriel mission list not visually differentiated
- Completed vs pending missions look similar
- Impact: Players can't quickly scan progress

---

## 9.4 Image Assets Audit (P1-D9-061 — P1-D9-080)

**P1-D9-061** | HIGH | Missing espionage action icon
- No dedicated icon file for espionage action type
- Impact: Falls back to generic/missing image in action lists
- Note: Overlaps with P1-D9-001

**P1-D9-062** | HIGH | Missing duplicateur building icon
- No icon for duplicateur building in images/batiments/
- Impact: Building list shows placeholder or broken image

**P1-D9-063** | HIGH | Six diamond medal images missing
- Files: images/medailles/ — diamond tier variants not created for 6 categories
- Impact: Top prestige rewards show broken images
- Note: Overlaps with P1-D9-002

**P1-D9-064** | HIGH | Planet sprites grossly oversized (20MB+)
- Files: images/planetes/ — sprite sheets totaling 20MB+
- Impact: Map page extremely slow to load, mobile unusable

**P1-D9-065** | HIGH | Background wallpaper 596KB
- File: images/fond or background CSS reference — single background image 596KB
- Impact: Slow initial page load, especially on mobile connections

**P1-D9-066** | MEDIUM | ~80 duplicate image files
- Multiple identical files with different names across image directories
- Impact: Repository bloat, confusion about canonical version

**P1-D9-067** | MEDIUM | Unreferenced arbre/ directory
- Files: images/arbre/ — directory of tree images not referenced anywhere in code
- Impact: Dead assets wasting space

**P1-D9-068** | MEDIUM | Non-image files in partenariat/ directory
- Files: images/partenariat/ contains non-image files
- Impact: Directory pollution, potential security concern

**P1-D9-069** | MEDIUM | Favicon 83KB oversized
- Standard favicon should be under 10KB
- Impact: Wasted bandwidth on every page load

**P1-D9-070** | MEDIUM | Heavy atom generator icons are identical placeholders
- Multiple atom type icons using same placeholder image
- Impact: Players can't visually distinguish atom types

**P1-D9-071** | MEDIUM | PSD files accessible in production
- Source Photoshop files served by web server
- Impact: Unnecessary exposure of design source files

**P1-D9-072** | MEDIUM | Inconsistent building icon dimensions
- Building icons range from 24px to 128px with no standard
- Impact: Jagged visual grid in building displays

**P1-D9-073** | MEDIUM | 170 legacy avatar images in git
- Large number of avatar files bloating repository
- Impact: Slow git clone, unnecessary storage

**P1-D9-074** | MEDIUM | Zero WebP images used
- All images in PNG/GIF/JPEG format, no modern WebP
- Impact: 25-35% larger file sizes than necessary

**P1-D9-075** | MEDIUM | No responsive image srcset
- Images served at single resolution regardless of screen
- Impact: Mobile devices download full-size images

**P1-D9-076** | LOW | No image CDN or optimization pipeline
- Images served directly from Apache with no processing
- Impact: No automatic resizing, format conversion, or caching

**P1-D9-077** | LOW | Image filenames contain spaces
- Some image files have spaces in names requiring URL encoding
- Impact: Potential broken references, URL encoding issues

**P1-D9-078** | LOW | No sprite sheet for UI icons
- Each small icon is a separate HTTP request
- Impact: Multiple round trips for icon-heavy pages

**P1-D9-079** | LOW | No image dimension attributes in HTML
- img tags missing width/height attributes
- Impact: Layout shifts during image loading (CLS)

**P1-D9-080** | LOW | Partenariat images unreferenced
- Partner logo images exist but no partnership page references them
- Impact: Dead assets

---

## 9.5 Responsive & Mobile (P1-D9-081 — P1-D9-100)

**P1-D9-081** | CRITICAL | Map pixel-sized with no touch scroll
- File: carte.php — map renders at fixed pixel dimensions, no touch pan/zoom
- Impact: Map completely unusable on mobile devices

**P1-D9-082** | HIGH | Banner fixed position breaks on mobile
- Fixed-position banner overlaps content on small screens
- Impact: Content hidden behind banner, no scroll access

**P1-D9-083** | HIGH | 11-column leaderboard table overflow
- File: classement.php — table exceeds viewport width on mobile
- Impact: Data cut off, horizontal scroll awkward on touch

**P1-D9-084** | HIGH | iOS scroll trap in overflow containers
- Nested scrollable areas trap touch scrolling on iOS Safari
- Impact: Users get stuck, can't scroll parent page

**P1-D9-085** | HIGH | Dangling CSS period causes parse error
- Stray `.` in CSS file causes parser to skip subsequent rules
- Impact: Multiple styles silently broken

**P1-D9-086** | HIGH | Only one @media query — no breakpoints
- Entire CSS has single breakpoint, no tablet/phone/desktop tiers
- Impact: Layout doesn't adapt to different screen sizes

**P1-D9-087** | HIGH | Un-interpolated PHP maxlength in HTML
- PHP variable not properly echoed in maxlength attribute
- Impact: Literal PHP code visible in HTML, no validation

**P1-D9-088** | HIGH | No map zoom/scale controls
- Map has no pinch-to-zoom or zoom buttons
- Impact: Players can't navigate large maps on any device

**P1-D9-089** | MEDIUM | Sub-44px touch targets
- Multiple interactive elements smaller than 44×44px minimum
- Impact: Difficult to tap on mobile, accessibility failure

**P1-D9-090** | MEDIUM | Fixed 65px toolbar height
- Toolbar height hardcoded, doesn't adapt to content or device
- Impact: Wasted space on large screens, cramped on small

**P1-D9-091** | MEDIUM | Fixed 400px chart height
- Market/resource charts at fixed pixel height
- Impact: Charts too small on desktop, too large on mobile

**P1-D9-092** | MEDIUM | data-table class doesn't exist in Framework7 v1.5
- References to F7 data-table component that isn't in v1.5
- Impact: Tables unstyled, relying on custom CSS only

**P1-D9-093** | MEDIUM | Chip row has no scroll indicator
- Horizontal chip rows scrollable but no visual affordance
- Impact: Users don't know they can scroll for more options

**P1-D9-094** | MEDIUM | Popovers overflow viewport on mobile
- Popover menus extend beyond screen edges
- Impact: Content clipped, options inaccessible

**P1-D9-095** | MEDIUM | Formation radio buttons undersized on mobile
- Radio inputs for army formation too small to tap
- Impact: Players mis-tap formations in combat setup

**P1-D9-096** | MEDIUM | Deprecated `<center>` tag used
- HTML `<center>` tag used instead of CSS text-align
- Impact: Deprecated HTML, inconsistent rendering

**P1-D9-097** | MEDIUM | Global 94% table width
- All tables set to width:94%, no responsive adjustment
- Impact: Tables too wide on mobile, too narrow on desktop

**P1-D9-098** | MEDIUM | 10px font on atom income labels
- Tiny font size on resource income display
- Impact: Unreadable on mobile and high-DPI screens

**P1-D9-099** | LOW | Float-based atom layout breaks on resize
- Atom display uses CSS floats that wrap unpredictably
- Impact: Layout breaks at certain viewport widths

**P1-D9-100** | LOW | No viewport meta tag optimizations
- Basic viewport tag without optimal mobile settings
- Impact: Suboptimal mobile rendering

---

## Cross-References (Duplicates Between Subagents)
- P1-D9-061 duplicates P1-D9-001 (missing espionage icon)
- P1-D9-063 duplicates P1-D9-002 (missing diamond medals)
- P1-D9-071 overlaps P1-D9-007 (PSD files in production)

**Unique findings after dedup: ~97**

---

## Summary by Area

| Area | Findings | CRIT | HIGH | MED | LOW |
|------|----------|------|------|-----|-----|
| 9.1 Visual Audit | 20 | 0 | 2 | 8 | 10 |
| 9.2 CSS Theme | 20 | 1 | 4 | 10 | 5 |
| 9.3 Page-by-Page | 20 | 0 | 4 | 12 | 4 |
| 9.4 Image Assets | 20 | 0 | 5 | 10 | 5 |
| 9.5 Responsive | 20 | 1 | 6 | 10 | 3 |
| **Total** | **100** | **2** | **21** | **50** | **27** |
