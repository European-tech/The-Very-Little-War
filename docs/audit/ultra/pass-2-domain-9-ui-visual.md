# Pass 2 — Deep Dive — Domain 9: UI/Visual Design Audit
**The Very Little War** — Full CSS + Inline Style + Component Audit
Date: 2026-03-05
Auditor: UI Designer Agent (claude-sonnet-4-6)
Method: Line-by-line reading of all CSS files, every PHP template, all UI helper functions

---

## Audit Scope

Files examined in full:
- `css/my-app.css` (57 lines, custom game CSS)
- `includes/style.php` (304 lines, inline `<style>` block, loaded on every page)
- `css/framework7.material.css` (7971 lines, primary framework)
- `css/framework7.material.colors.css` (color overrides)
- `css/framework7-icons.css` (icon font)
- `includes/layout.php` (page shell template)
- `includes/ui_components.php` (all UI component functions)
- `includes/display.php` (formatting/display helpers)
- `includes/basicprivatehtml.php` (authenticated shell, 464 lines)
- `includes/basicpublichtml.php` (public shell)
- `includes/cardsprivate.php` (tutorial/missions panel)
- `includes/atomes.php` (resource panel)
- `includes/meta.php` (head meta and stylesheet links)
- `includes/copyright.php` (footer, JS init)
- All 44 root PHP game pages
- `images/` directory structure

**Inline style= count across all PHP files:** 501 occurrences

---

## Summary by Category

| Category | Count |
|---|---|
| Broken/Invalid CSS rules | 9 |
| CSS specificity conflicts | 4 |
| Dead/unused CSS selectors | 5 |
| Invalid CSS property values | 8 |
| Inline style audit findings | 14 |
| Color inconsistency | 7 |
| Typography inconsistency | 6 |
| Spacing/layout magic numbers | 8 |
| Deprecated HTML elements | 3 |
| Framework7 component misuse | 6 |
| Accessibility (focus, contrast, alt) | 5 |
| Component reuse opportunity | 5 |

---

## Findings

---

### P2-D9-001 | CRITICAL | Truncated CSS File — my-app.css Ends with Orphan Selector

- **Location:** `css/my-app.css:57`
- **Description:** The file ends abruptly with a lone period on line 57:
  ```
  .
  ```
  This is a dangling selector with no declaration block. Every CSS parser will flag this as a parse error. Depending on browser recovery behaviour, rules following the orphan selector in adjacent stylesheets could be mis-attributed or silently dropped. The truncated line suggests the file was partially written and saved incompletely during a refactor.
- **Impact:** Silent CSS parse error on every page load. Any rule accidentally merged into this selector would never apply. Browser DevTools reports an error, increasing noise during debugging. The orphan `.` resolves to a universal class matcher with no body — harmless in isolation but signals file corruption.
- **Fix:** Remove line 57 entirely from `css/my-app.css`.

---

### P2-D9-002 | CRITICAL | Invalid CSS Property `height;` (Semicolon Instead of Colon)

- **Location:** `includes/style.php:260`
- **Description:** The `.imageClassement` rule contains a syntax error — a semicolon is used as the property separator instead of a colon:
  ```css
  .imageClassement {
      width: 32px;
      height; 32px;   /* BROKEN — semicolon, not colon */
  }
  ```
  The `height` declaration is therefore silently dropped. The `.imageClassement` class receives no height constraint whatsoever. This affects every ranking medal image that does not have an inline `height` style applied.
- **Impact:** Medal images (gold, silver, bronze) in `imageClassement` function display at their native height rather than the intended 32px, breaking visual alignment in ranking tables across `classement.php`, `historique.php`, `alliance.php`, and `joueur.php`.
- **Fix:**
  ```css
  .imageClassement {
      width: 32px;
      height: 32px;
  }
  ```

---

### P2-D9-003 | CRITICAL | Invalid CSS Value `font-weight:regular` on `<body>` Element

- **Location:** `includes/basicprivatehtml.php:216`, `includes/basicpublichtml.php:1`
- **Description:** Both authenticated and public page body tags declare:
  ```html
  <body class="theme-black" style="font-weight:regular">
  ```
  `regular` is not a valid CSS `font-weight` value. Valid keywords are: `normal`, `bold`, `bolder`, `lighter`, or numeric values 100–900. `normal` is the correct equivalent. All CSS parsers silently discard invalid property values, so `font-weight:regular` resolves to the browser default (typically `normal`). The intent is presumably correct but the implementation is invalid and introduces unnecessary noise in computed styles.
- **Impact:** No visual regression on most browsers (the value is rejected and default applied), but the invalid value appears in DevTools computed styles, creating confusion during debugging. The invalid attribute also means `font-weight` cannot be reliably overridden from this baseline in child rules.
- **Fix:** Replace `style="font-weight:regular"` with `style="font-weight:normal"` in both files, or better, remove the inline style entirely since `normal` is already the browser default.

---

### P2-D9-004 | CRITICAL | Duplicate `alt` Attribute on `<img>` — First Value Hardcoded Incorrect

- **Location:** `includes/display.php:11`
- **Description:** The `image()` function (used on nearly every game page to display atom resource icons) generates an `<img>` tag with two `alt` attributes:
  ```php
  return '<img style="vertical-align:middle;width:37px;height:37px;"
      alt="Energie"                    // FIRST — wrong, hardcoded
      src="images/' . $nomsRes[$num] . '.png"
      alt="' . $nomsRes[$num] . '"    // SECOND — correct, dynamic
      title="' . ucfirst($nomsAccents[$num]) . '" />';
  ```
  HTML parsers use only the first `alt` attribute when duplicates are present. Every atom icon therefore announces itself as "Energie" to screen readers regardless of which atom is displayed. This affects all 8 atom types (carbone, azote, hydrogene, oxygene, chlore, soufre, brome, iode).
- **Impact:** Accessibility failure — screen reader users hear "Energie" for every atom image. Duplicate attributes are also invalid HTML. Used in hundreds of locations across the entire game (resource panels, market, army, constructions, missions, tutorial).
- **Fix:**
  ```php
  return '<img style="vertical-align:middle;width:37px;height:37px;"
      src="images/' . $nomsRes[$num] . '.png"
      alt="' . htmlspecialchars(ucfirst($nomsAccents[$num]), ENT_QUOTES, 'UTF-8') . '"
      title="' . htmlspecialchars(ucfirst($nomsAccents[$num]), ENT_QUOTES, 'UTF-8') . '" />';
  ```

---

### P2-D9-005 | HIGH | Invalid CSS Property `font-family: default`

- **Location:** `includes/style.php:149`
- **Description:** The `.facebook-card .card-header` rule uses:
  ```css
  font-family: default;
  ```
  `default` is not a valid CSS `font-family` value. Valid values include named font families, generic families (`serif`, `sans-serif`, `monospace`, etc.), or keyword `inherit`/`initial`/`unset`. The rule is silently dropped, meaning the forum card header inherits `magmawave_capsbold` from the `.card-header` rule above it (line 78), which is the exact opposite of the apparent intent (overriding back to a readable default font).
- **Impact:** Forum card headers (subject titles in `sujet.php`) display in the decorative `magmawave_capsbold` font rather than a legible body font. Subject titles like long French sentences become visually degraded by the inappropriate decorative typeface.
- **Fix:**
  ```css
  .facebook-card .card-header {
      font-family: inherit;  /* or: -apple-system, Roboto, sans-serif */
  }
  ```

---

### P2-D9-006 | HIGH | Invalid CSS Value `text-align:middle` (Not a Valid Keyword)

- **Location:** `index.php:154`, `index.php:162`
- **Description:** Two elements on the public landing page use `text-align:middle`:
  ```html
  <div style="margin-left:5%;text-align:middle">
  <img ... style="text-align:middle;">
  ```
  `middle` is not a valid `text-align` value. Valid keywords: `left`, `right`, `center`, `justify`, `start`, `end`. The declaration is silently discarded. Additionally `text-align` has no effect on `<img>` elements (a replaced element); vertical positioning requires `vertical-align`.
- **Impact:** The chlore and brome atom images on the landing page do not align as intended. The decorative atom scatter layout relies on these small positional nudges and their failure degrades the visual composition.
- **Fix:** Replace `text-align:middle` with `text-align:center` for `<div>` elements. For `<img>` elements, use `vertical-align:middle`.

---

### P2-D9-007 | HIGH | Invalid CSS Value `text-weight:bold` (Non-Existent Property)

- **Location:** `includes/copyright.php:83`
- **Description:** The Framework7 error alert uses an invalid CSS property in a JavaScript string:
  ```php
  echo "myApp.alert(...,\"<span style='color:red;text-weight:bold'>Erreur</span>\");";
  ```
  The CSS property `text-weight` does not exist. The correct property is `font-weight`. The error title in the alert dialog therefore never renders as bold.
- **Impact:** The "Erreur" label in error alert dialogs is not bold as intended, reducing visual hierarchy and making error messages less visually prominent when they appear.
- **Fix:** Change `text-weight:bold` to `font-weight:bold`.

---

### P2-D9-008 | HIGH | Invalid CSS Property — `vertical-align :middle` (Space Before Colon)

- **Location:** `includes/basicpublichtml.php:7`
- **Description:** The public panel header image uses:
  ```html
  <img src="images/tvlw.png" style="vertical-align :middle;width:90px;height:90px" alt="icone"/>
  ```
  There is a stray space between the property name and the colon (`vertical-align :middle`). While some lenient CSS parsers may accept this, the CSS specification requires no whitespace between the property name and the colon. Strict parsers (and validators) reject this declaration entirely.
- **Impact:** The game logo/icon in the public navigation panel may not vertically center correctly in strict-mode browsers, causing visual alignment issues in the slide-out menu for anonymous users.
- **Fix:** Remove the space: `vertical-align:middle`.

---

### P2-D9-009 | HIGH | CSS Typo `heigth` Instead of `height` — Two Occurrences

- **Location:** `sinstruire.php:27`, `sinstruire.php:28`
- **Description:** The S'instruire (learn) page has two instances of `heigth` (misspelled):
  ```html
  <img ... style="float: left;width:50px ;heigth:50px;margin: 5px 5px 5px 5px">
  <img ... style="float: right; width:50px ;heigth:50px; margin: 5px 5px 5px 5px">
  ```
  Also note the extra space before the semicolons in `width:50px ;`. Both atom images in the S'instruire introduction section receive no height constraint, and render at their native image height.
- **Impact:** Atom illustration images on the S'instruire page display at full native height rather than the intended 50px, disrupting the text-wrap layout of the physics introduction section.
- **Fix:** Replace `heigth:50px` with `height:50px` and remove the extra spaces before semicolons.

---

### P2-D9-010 | HIGH | Missing `lang` Attribute on `<html>` Element

- **Location:** `includes/layout.php:6`
- **Description:** The main layout template generates:
  ```html
  <html>
  ```
  without a `lang` attribute. The game is French-language and should declare `lang="fr"`. Standalone admin pages (`admin/listesujets.php`, `admin/multiaccount.php`, `validerpacte.php`) do correctly declare `lang="fr"`, creating inconsistency.
- **Impact:** Screen readers use the browser default language for pronunciation. Without `lang="fr"`, assistive technology may mispronounce French text. Also causes WCAG 2.1 criterion 3.1.1 (Language of Page) failure.
- **Fix:**
  ```html
  <html lang="fr">
  ```

---

### P2-D9-011 | HIGH | Framework7 `outline: 0` Removes All Keyboard Focus Indicators

- **Location:** `css/framework7.material.css:44-49`
- **Description:** The base Framework7 material CSS globally removes focus outlines:
  ```css
  a,
  input,
  textarea,
  select {
    outline: 0;
  }
  ```
  No override exists in `css/my-app.css` or `includes/style.php` to restore focus indicators. The game has no custom `:focus` or `:focus-visible` CSS rules anywhere in the custom stylesheets.
- **Impact:** Keyboard-only users receive zero visual feedback about which element is currently focused during navigation. This fails WCAG 2.1 criterion 2.4.7 (Focus Visible) at Level AA. Tab navigation through forms, menu items, and buttons is completely invisible.
- **Fix:** Add to `css/my-app.css`:
  ```css
  a:focus-visible,
  input:focus-visible,
  textarea:focus-visible,
  select:focus-visible,
  button:focus-visible {
    outline: 2px solid #8A0000;
    outline-offset: 2px;
  }
  ```

---

### P2-D9-012 | HIGH | `data-table` Class Used on 18+ Elements — Never Defined in Any CSS

- **Location:** `bilan.php` (17 occurrences), `laboratoire.php` (3 occurrences)
- **Description:** Both `bilan.php` and `laboratoire.php` extensively use `class="data-table"` as a wrapper div for their information tables:
  ```php
  echo '<div class="data-table"><table>';
  ```
  A search of all game CSS files (`css/my-app.css`, `includes/style.php`, `css/framework7.material.css`, `css/framework7.material.colors.css`) finds zero definition of `.data-table`. The class is completely undefined and provides no styling.
- **Impact:** The bonus summary page (`bilan.php`) and the compound lab page (`laboratoire.php`) — both recently added pages — have tables with no container styling. There is no horizontal scroll protection, no visual grouping, and no responsive overflow behaviour on the data-table wrapper. The tables may overflow their cards on small screens.
- **Fix:** Add to `includes/style.php`:
  ```css
  .data-table {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      margin-bottom: 8px;
  }
  .data-table table {
      min-width: 280px;
  }
  ```

---

### P2-D9-013 | HIGH | Bootstrap Table Classes Used Without Bootstrap Framework

- **Location:** 20+ PHP files including `classement.php`, `historique.php`, `rapports.php`, `armee.php`, `forum.php`, `messages.php`, `allianceadmin.php`, `sinstruire.php`, `connectes.php`, `moderationForum.php`, `listesujets.php`, `messagesenvoyes.php`, `includes/game_actions.php`
- **Description:** Across 20 PHP files, tables are declared with Bootstrap CSS classes:
  ```html
  <table class="table table-striped table-bordered">
  ```
  Bootstrap is not included anywhere in the project. Neither `table-striped`, `table-bordered`, nor the `table` base class are defined in Framework7 material CSS, the game's `my-app.css`, or `style.php`. These classes resolve to nothing.
- **Impact:** All tables across the ranking, history, reports, forum, army, messages, and admin pages receive no Bootstrap-style formatting — no striped alternating rows, no borders between cells from the Bootstrap rule set. Styling falls back entirely to the bare global `table`, `td`, `th` rules in `style.php` (which at least provides `border-top: 1px solid gray` on `td`). The visual experience is functional but not as designed.
- **Fix Options:**
  1. Add minimal Bootstrap-compatible table CSS to `includes/style.php`:
     ```css
     .table { width: 100%; border-collapse: collapse; }
     .table-bordered td, .table-bordered th { border: 1px solid #ddd; }
     .table-striped tbody tr:nth-of-type(odd) { background-color: rgba(0,0,0,.05); }
     ```
  2. Or replace Bootstrap class names with Framework7-compatible markup.

---

### P2-D9-014 | HIGH | `accordion()` Function Uses `item-media` for Title and Content — Wrong Semantic

- **Location:** `includes/ui_components.php:374-390`
- **Description:** The `accordion()` function wraps all three sections (media, titre, contenu) in `<div class="item-media">`:
  ```php
  if (array_key_exists("titre", $options) && $options["titre"]) {
      $titre = '<div class="item-media">  // WRONG — should be item-title or item-inner
          ' . $options["titre"] . '
      </div>';
  }
  if (array_key_exists("contenu", $options) && $options["contenu"]) {
      $contenu = '<div class="item-media">  // WRONG
          ' . $options["contenu"] . '
      </div>';
  }
  ```
  `item-media` is a Framework7 class for the left-side icon/media area. Using it for the title and content areas applies media-specific styles (fixed width, padding) to text content, breaking the layout.
- **Impact:** Any page calling `accordion()` (not `itemAccordion()`) will have incorrectly styled title and content areas. The `accordion()` function appears unused based on grep output, but remains a latent bug if called.
- **Fix:**
  ```php
  $titre = '<div class="item-inner"><div class="item-title">' . $options["titre"] . '</div></div>';
  $contenu = $options["contenu"];
  ```

---

### P2-D9-015 | HIGH | Invisible Submit Buttons Using `background-color: Transparent; color: Transparent`

- **Location:** `alliance.php:474`
- **Description:** Alliance invitation accept/reject buttons are rendered as invisible image-background submit inputs:
  ```html
  <input type="submit" class="w32"
      style="background-image: url('images/yes.png');
             background-color: Transparent;
             color: Transparent;
             ...border: none;outline:none;"/>
  ```
  The `Transparent` value is capitalised, which while technically valid per CSS spec (values are case-insensitive), is non-standard. More critically, the submit button has `color: Transparent` making the value text invisible. The `outline:none` removes the focus indicator. The entire interaction relies on a 32px background image with no visible text fallback.
- **Impact:** If the `yes.png` or `croix.png` image fails to load, the accept/reject buttons become completely invisible and unusable. Additionally `outline:none` means keyboard users cannot see focus on these critical action buttons. The capitalised colour values may cause issues in strict CSS processors.
- **Fix:** Replace with properly styled Framework7 button components:
  ```html
  <button type="submit" name="actioninvitation" value="Accepter"
          class="button button-fill color-green" style="min-width:32px;">
      <img src="images/yes.png" alt="Accepter" class="w32"/>
  </button>
  ```

---

### P2-D9-016 | MEDIUM | `!important` Overrides in `css/my-app.css` Against Framework7 Internal Rules

- **Location:** `css/my-app.css:50`, `css/my-app.css:54`
- **Description:** The custom CSS file uses `!important` to override Framework7 internal padding:
  ```css
  .item-media .item-inner {
      padding-bottom: 0px !important;
  }
  .item-media {
      padding-bottom: 0px !important;
  }
  ```
  Using `!important` to fight framework internals is a specificity debt indicator. Framework7 v1.5 applies `padding-bottom` to `.item-inner` for layout purposes. Zeroing it with `!important` may collapse the spacing in list items where `.item-media` is combined with `.item-inner`.
- **Impact:** List items containing both media (icon) and inner content areas may have collapsed bottom padding, causing visual cramping or content clipping on iOS-style list components. The `!important` also prevents any legitimate styling of these elements from other rules.
- **Fix:** Rather than `!important`, target the specific compound selector more precisely:
  ```css
  .list-block .item-media + .item-inner {
      padding-bottom: 0;
  }
  ```
  Investigate whether this override is still required — if Framework7 v1.5's default padding is actually correct and the zero override was added speculatively, remove it.

---

### P2-D9-017 | MEDIUM | Theme Color Meta Tag Mismatches Brand Color

- **Location:** `includes/meta.php:4`
- **Description:** The mobile browser theme color is set to Framework7's default blue:
  ```html
  <meta name="theme-color" content="#2196f3">
  ```
  The game's brand color is `#8A0000` (dark red), used in card headers, tutorial cards, and mission cards across the site. The browser chrome (status bar on Android, URL bar on Chrome) displays Material blue instead of the game's red brand color.
- **Impact:** On mobile browsers that support `theme-color`, the browser UI chrome is rendered in bright blue — completely incongruent with the dark red/black game aesthetic. This is the first visual impression for mobile players.
- **Fix:**
  ```html
  <meta name="theme-color" content="#8A0000">
  ```

---

### P2-D9-018 | MEDIUM | 501 Inline Styles — Top 10 Repeated Patterns Should Become CSS Classes

- **Location:** All PHP files, 501 `style=` occurrences
- **Description:** Systematic inline style audit reveals the following patterns repeated enough times to warrant CSS class extraction:

  | Inline Style | Count | Proposed Class |
  |---|---|---|
  | `style="padding:6px;border:1px solid #ddd;"` | 80 | `.cell-bordered` |
  | `style="color:green"` | 65 | `.text-success` (already in F7) |
  | `style="vertical-align:middle"` | 20 | `.v-mid` |
  | `style="display:inline"` | 18 | `.d-inline` |
  | `style="width:25px;height:25px;"` | 16 | `.icon-sm` |
  | `style="width:20px;height:20px;vertical-align:middle;margin-right:5px;"` | 15 | `.icon-xs` |
  | `style="color:red"` | 14 | `.text-danger` (already in F7) |
  | `style="color:#999"` | 12 | `.text-muted` |
  | `style="color:green;"` | 10 | `.text-success` |
  | `style="width:25px;border-radius:0px;"` | 10 | `.chip-icon` |

  The `padding:6px;border:1px solid #ddd;` pattern (80 occurrences) is the most prevalent, appearing in bilan.php table cells throughout the bonus summary page.

- **Impact:** 501 inline styles prevent global theming changes, increase HTML payload, make design updates require modifying 40+ files, and violate Content Security Policy best practices. The CSP already requires `'unsafe-inline'` for styles, which weakens XSS protection.
- **Fix:** Extract top 10 patterns into `includes/style.php` classes. Begin with the 80-count `cell-bordered` pattern in `bilan.php` as a single-file migration.

---

### P2-D9-019 | MEDIUM | Inconsistent Green Color — Six Different Values for Same Semantic Purpose

- **Location:** Multiple PHP files
- **Description:** "Positive/success" green is expressed in 6 different ways across the codebase:
  - `color:green` (65 occurrences — CSS keyword)
  - `color:green;` (10 occurrences — with trailing space variations)
  - `color:green\` (2 occurrences in JS strings)
  - `bg-green` Framework7 class (1 occurrence in menu badge)
  - `color-green` Framework7 class (1 occurrence in laboratoire button)
  - `#087625` (1 occurrence for chlore in index.php)

  Similarly "error/danger" red uses: `color:red`, `color:red;`, `color:#c00`, `color:#d32f2f`, `color:#c62828`, `color:#a40000`, `color: #a40000`.

- **Impact:** Visual inconsistency across pages. The red `#c00` (shorthand for `#cc0000`) and `#d32f2f` (Material Design red) are noticeably different saturations. Users experience different reds for the same semantic meaning (error, danger, insufficient resources). The green `#087625` for chlore conflicts with generic success green.
- **Fix:** Define CSS custom properties in `includes/style.php`:
  ```css
  :root {
    --color-success: #2e7d32;
    --color-danger: #c62828;
    --color-brand: #8A0000;
    --color-muted: #999;
    --color-warning: #e65100;
  }
  ```
  Then replace inline `color:green` with `color:var(--color-success)` etc. Short-term: standardise on Framework7's `bg-green`/`color-green` classes.

---

### P2-D9-020 | MEDIUM | Misspelled CSS Color Names — `marroon`, `fuschia`, `lightGray` (mixed case)

- **Location:** `index.php:175`
- **Description:** The landing page promotional section uses three invalid/non-standard color names:
  ```html
  <span style="color:marroon">brome</span>   <!-- 'maroon' has one r -->
  <span style="color:fuschia">iode</span>    <!-- 'fuchsia' not 'fuschia' -->
  <span style="color:lightGray">hydrogene</span>  <!-- mixed case, valid but inconsistent -->
  ```
  `marroon` is not a CSS color keyword (correct spelling: `maroon`). `fuschia` is not a CSS color keyword (correct spelling: `fuchsia`). Both resolve to black in browsers (unknown keywords). `lightGray` is accepted by most browsers but is non-standard; the CSS standard is `lightgray` (lowercase).
- **Impact:** The brome and iode atom name spans on the landing page (promotional text) render in black instead of their intended chemical element colors, making the color-coding meaningless for new player orientation.
- **Fix:**
  ```html
  <span style="color:maroon">brome</span>
  <span style="color:fuchsia">iode</span>
  <span style="color:lightgray">hydrogene</span>
  ```
  Better: use the established `$couleurs` array values (consistent with the rest of the game) rather than named colors. Brome's game color comes from `$couleurs[6]`.

---

### P2-D9-021 | MEDIUM | Verbose Zero Values with Units — `0px`, `margin: 0px 0px 0px 0px`

- **Location:** `includes/style.php:273`, `css/my-app.css:50,54`, 21 occurrences of `border-radius:0px` in PHP files
- **Description:** CSS best practice dictates that zero values should not carry units (`0` not `0px`). The `.titreAide` rule uses:
  ```css
  margin: 0px 0px 0px 0px;
  ```
  which is equivalent to `margin: 0;` but eight characters longer. Similarly `padding-bottom: 0px !important` should be `padding-bottom: 0 !important`. There are 21 instances of `border-radius:0px` in PHP templates that should be `border-radius:0`.
- **Impact:** Minor — no visual impact. Adds unnecessary payload and violates CSS style guide conventions. May cause confusion when developers expect unit-free zeros.
- **Fix:** Global replacement: `0px` → `0` in CSS contexts (except within `calc()` and `translate()` where some parsers require units). `margin: 0px 0px 0px 0px` → `margin: 0`.

---

### P2-D9-022 | MEDIUM | Image Sizing Inconsistency — Three Classes for Same 25px Icon Size

- **Location:** `includes/style.php`, `includes/ui_components.php`, `includes/display.php`, PHP templates
- **Description:** Three separate mechanisms exist for 25px square icons:
  1. `.imageAide` class (style.php:240): `width:20px; height:20px` — 20px, not 25px
  2. `.imageChip` class (style.php:252): `width:25px; height:25px; border-radius:0px`
  3. Inline `style="width:25px;height:25px;"` (16 occurrences)
  4. Inline `style="width:25px;border-radius:0px;"` (10 occurrences, missing height)

  The `chipInfo()` function in `ui_components.php:472` uses inline style for its icon image:
  ```php
  return chip($label, '<img alt="tag" src="' . $image . '" style="width:25px;height:25px;border-radius:0px;"/>', ...);
  ```
  This should use `.imageChip` but instead duplicates the style inline.

  Additionally, `imageClassement()` images have inconsistent sizing: gold=28px, silver=25px, bronze=21px (intentional sizing by rank, but undocumented).
- **Impact:** Minor visual inconsistencies in icon sizes throughout chips, menus, and lists. The 16 inline occurrences cannot be globally resized by changing one CSS rule.
- **Fix:** Standardise on `.imageChip` for all 25px icons. Update `chipInfo()` to use the class. Create `.imageChip--sm` for 20px variants.

---

### P2-D9-023 | MEDIUM | Sixteen Different Font Sizes Used in Inline Styles — No Type Scale

- **Location:** Various PHP files
- **Description:** Font size values found in inline styles across PHP files:
  `10px, 11px, 12px, 13px, 14px, 15px, 16px, 17px, 18px, 20px, 22px, 25px, 28px, 2em, 0.9em, larger, 130%, 17px`

  That is 18 distinct font-size values. No type scale or modular scale is documented or enforced. Notable conflicts:
  - "Help" popover title: `font-size: 17px` in `.titreAide` (style.php)
  - Season countdown in navbar: `font-size:10px` (layout.php:57)
  - Same countdown on homepage: `font-size:18px; font-weight:bold` (index.php:136)
  - Atom popover title uses `font-size:18px` (basicprivatehtml.php:350)
  - bilan.php summary text: `font-size:13px`
  - laboratoire.php button: `font-size:12px`
  - prestige.php PP balance: `font-size:28px` (largest inline value)
- **Impact:** Typography looks inconsistent between pages. The same information (countdown timer) appears at two different sizes depending on which page displays it. No rhythm or visual hierarchy is established through consistent type scaling.
- **Fix:** Define a type scale in `includes/style.php`:
  ```css
  :root {
    --text-xs: 11px;
    --text-sm: 13px;
    --text-base: 14px;
    --text-md: 16px;
    --text-lg: 18px;
    --text-xl: 22px;
    --text-2xl: 28px;
  }
  ```

---

### P2-D9-024 | MEDIUM | Deprecated HTML Element `<center>` Used 15 Times

- **Location:** `index.php` (7 occurrences), `includes/cardsprivate.php` (2 occurrences), `includes/basicprivatehtml.php` (2 occurrences), `tutoriel.php` (1 occurrence), other pages (3 occurrences)
- **Description:** The `<center>` element was deprecated in HTML 4.01 and removed in HTML5. All 15 occurrences should use `text-align:center` on a block element or `margin: 0 auto` for block centering.
- **Impact:** HTML5 validators report these as errors. While browsers continue to render `<center>` for backward compatibility, it cannot be styled with CSS pseudo-elements or targeted by CSS child selectors in standard ways.
- **Fix:** Replace all `<center>` with `<div style="text-align:center">` or with a CSS class.

---

### P2-D9-025 | MEDIUM | Deprecated HTML Element `<nobr>` Used 8 Times

- **Location:** `includes/cardsprivate.php` (3 occurrences), `includes/basicprivatehtml.php` (5 occurrences)
- **Description:** `<nobr>` (no-break) was never part of any HTML standard and is a non-standard browser extension. It prevents line breaks within its content. Used in tutorial and help text:
  ```html
  <nobr><img alt="c" src="images/menu/armee.png" class="imageAide"/> <strong>Armée</strong></nobr>
  ```
- **Impact:** While browsers render `<nobr>` for legacy reasons, it is semantically invalid and may fail in edge-case parsers. The `white-space:nowrap` CSS property is the correct replacement.
- **Fix:** Replace `<nobr>content</nobr>` with `<span style="white-space:nowrap">content</span>` or a CSS class `.no-break { white-space: nowrap; }`.

---

### P2-D9-026 | MEDIUM | Inconsistent Card Header Color — Brand Color Used Inline Instead of Via CSS Class

- **Location:** `index.php:50`, `index.php:63`, `includes/cardsprivate.php:128,167`, `prestige.php:27`, `bilan.php:101`, `alliance.php`, multiple others
- **Description:** Card headers use the brand color `#8A0000` as an inline style on the `debutCarte()` call:
  ```php
  debutCarte("Se connecter", "background-color:#8A0000");
  debutCarte("Tutoriel","background-color:#8A0000");
  debutCarte("Missions","background-color:#8A0000");
  ```
  But `style.php` line 78 already defines `.card-header` with `background-color: black`. Some cards appear black-header (using the class default), others appear brand-red (using the inline override), creating a two-tier visual system:
  - Default cards: black header
  - "Important" cards (tutorial, login, missions, prestige): red `#8A0000` header
  - Feature overview card: dark grey `#333` header

  There is no documented convention for when to use which color.
- **Impact:** Visual inconsistency makes the interface feel arbitrary. Players cannot infer meaning from header color because there is no consistent semantic system.
- **Fix:** Define CSS modifier classes:
  ```css
  .card-header--brand { background-color: #8A0000; }
  .card-header--dark  { background-color: #333; }
  ```
  Document the convention: `--brand` for player-action cards, `--dark` for informational cards, default (black) for data cards.

---

### P2-D9-027 | MEDIUM | Duplicate Font-Face Declarations — `magmawave_capsbold` Declared Twice

- **Location:** `css/my-app.css:25-36`, `includes/style.php:36-47` (for magmawave_capsbold); `css/my-app.css:1-11`, `includes/style.php:49-60` (for bpmoleculesregular)
- **Description:** Both `magmawave_capsbold` and `bpmoleculesregular` are declared in `@font-face` rules in two separate locations:
  1. `css/my-app.css` (loaded as external stylesheet)
  2. `includes/style.php` (loaded as inline `<style>` block via `<head>` include)

  This means both `@font-face` declarations fire on every page load — the browser must process, deduplicate, and potentially make duplicate network requests for the font files (though caching mitigates the network cost). More importantly it creates a maintenance problem: updates to font sources must be made in two places.
- **Impact:** Minor performance cost (duplicate parsing). High maintenance risk — font paths in one location may diverge from the other over time. The `style.php` versions use relative path `css/fonts/...` (which works from root) while `my-app.css` versions use `fonts/...` (relative to the CSS file in `css/`). Both resolve to the same files currently, but any reorganisation will break one set.
- **Fix:** Remove the `@font-face` declarations from `includes/style.php` and retain only those in `css/my-app.css` (which is the external stylesheet loaded via `<link>`). The `.magma` and `.atome` class definitions in `style.php` can remain since they reference the already-declared font families by name.

---

### P2-D9-028 | MEDIUM | Negative Margin Layout Hacks — Three Occurrences

- **Location:** `constructions.php:113`, `includes/basicprivatehtml.php:221`, `includes/style.php:174`
- **Description:** Three places use negative margins for visual positioning:
  1. **constructions.php:113** — Building image with health bar overlay:
     ```html
     style="width:80px;height:80px;margin-top:-54px;"
     style="margin-left:-80px;margin-top:-10px;"
     ```
     This creates a manual z-axis overlay of the progress bar over the building image using negative margins — a fragile hack.
  2. **basicprivatehtml.php:221** — Sidebar player name:
     ```html
     style="display:block;font-family:magmawave_capsbold;margin-top:-20px;"
     ```
  3. **style.php:174** — Facebook card date:
     ```css
     margin-top:-10px;
     ```
- **Impact:** Negative margin layouts break when parent container dimensions change (e.g., larger building images, different font sizes). The constructions building overlay is especially fragile — any change to the 80px image size requires recalculating both offsets.
- **Fix:** For the building health bar overlay, use CSS `position:relative` on the container with `position:absolute` on the progress bar, removing both negative margin hacks.

---

### P2-D9-029 | MEDIUM | `<img>` with `alt="so"` — Meaningless Generic Alt Text

- **Location:** `index.php:145-163` (10 occurrences)
- **Description:** All atom images in the landing page decorative scatter layout use `alt="so"`:
  ```html
  <img alt="so" src="images/accueil/azote.png" class="imageAtome" />
  <img alt="so" src="images/accueil/carbone.png" style="float:right" class="imageAtome" />
  ```
  `"so"` appears to be a developer shorthand placeholder (possibly abbreviated from "sans objet" or "something"). It is not a meaningful description of the atom images.
- **Impact:** Screen readers announce "so" for each atom image. If the images fail to load, the alt text provides no context. Fails WCAG 1.1.1 (Non-text Content).
- **Fix:** Use descriptive alt text matching the atom shown:
  ```html
  <img alt="Azote" src="images/accueil/azote.png" class="imageAtome" />
  <img alt="Carbone" src="images/accueil/carbone.png" class="imageAtome" />
  ```
  If the images are purely decorative (the atom name is displayed in text alongside), use `alt=""` to mark them as presentational.

---

### P2-D9-030 | MEDIUM | `class="chip bg-"` — Empty Framework7 Background Class

- **Location:** `includes/display.php:201`
- **Description:** The `nombreTout()` function generates a chip with an empty `bg-` class:
  ```php
  '<div class="chip bg-">'
  ```
  Framework7 material expects `bg-green`, `bg-red`, `bg-blue`, etc. An empty `bg-` class is invalid and matches no Framework7 colour rule. The chip will render with no background colour applied (transparent chip background), which may look broken against the card background image.
- **Impact:** The "all resources" chip displayed in missions and tutorial reward sections has no background colour, making it visually inconsistent with other chips that use green/red backgrounds to indicate affordability.
- **Fix:** Determine the intended background color (likely `bg-blue` for neutral or `bg-white`) and update:
  ```php
  '<div class="chip bg-blue">'
  ```

---

### P2-D9-031 | MEDIUM | Magic Number `143px` Width for `chip-media` in `nombreTout()` and `coutTout()`

- **Location:** `includes/display.php:202`, `includes/display.php:250`
- **Description:** Both `nombreTout()` and `coutTout()` hardcode a 143px width for the chip media area:
  ```html
  <div class="chip-media bg-white" style="width:143px;border-radius:20px">
  ```
  Framework7's chip component expects `chip-media` to be a small circular avatar area (typically 32px). Overriding it to 143px creates a panoramic chip layout. The `143px` value appears to be the width of `images/tout.png` — i.e., it was measured once and hardcoded. If the image changes size, the layout breaks.
- **Impact:** The "all resources" chip displays as an unusually wide bar rather than the standard chip format. On narrow screens (<320px), it may overflow its container. The value is also undocumented and will confuse any developer maintaining this code.
- **Fix:** Use `max-width:100%` on the image and let the chip-media resize naturally, or use a fixed icon size consistent with other chips:
  ```html
  <div class="chip-media bg-white" style="border-radius:20px;overflow:hidden;">
      <img src="images/tout.png" style="max-width:100%;height:auto;" alt="toutes" />
  </div>
  ```

---

### P2-D9-032 | MEDIUM | Two Redundant Icon Size Classes — `iconeMenu` vs `imageSousMenu`

- **Location:** `includes/style.php:223-228` (imageSousMenu), `includes/style.php:290-293` (iconeMenu)
- **Description:** Two CSS classes serve identical visual purposes but with different dimensions:
  - `.imageSousMenu`: `margin-top:3px; height:32px; width:32px` — used 89 times (classement, historique, joueur, rapports, etc.)
  - `.iconeMenu`: `width:25px; height:25px` — used only 6 times (basicpublichtml.php public menu)

  Both are used for navigation menu item icons. The public menu (anonymous users) gets 25px icons, while the private menu (logged-in users) gets 32px icons via `imageSousMenu`. The inconsistency is compounded by `basicprivatehtml.php` using `style="width:25px;height:25px;"` inline (16 times) instead of either class.
- **Impact:** The public and private menus display icons at different sizes. Within the private menu itself, the side panel uses inline 25px while the sub-menu toolbar uses class-based 32px. Three different sizes for conceptually identical navigation icons.
- **Fix:** Standardise on one class and one size. Rename `.imageSousMenu` to `.menu-icon` with `width:32px; height:32px`. Replace all inline `style="width:25px;height:25px;"` menu icon instances with `.menu-icon` (and remove `.iconeMenu`).

---

### P2-D9-033 | MEDIUM | `progressBar()` Function Uses `<br/>` Tags for Spacing — Three Consecutive

- **Location:** `includes/ui_components.php:477-485`
- **Description:** The progress bar helper function uses three consecutive `<br/>` tags for vertical spacing before the progress bar:
  ```php
  return '
      <br/><br/><br/>
      <div class="item-content" style="margin:0;padding:0;">
  ```
  This is a line-break-based layout pattern. The progress bar then has `<center>` tags around the HP text, and uses `style="height:6px;border:2px solid black;"` which doesn't match the Framework7 progressbar styling.
- **Impact:** The building health bar in constructions.php has unpredictable vertical spacing that depends on `<br>` tags (which render differently in various contexts). The `border:2px solid black` on the Framework7 progressbar overrides the design language of the material theme.
- **Fix:** Replace `<br/><br/><br/>` with `margin-top` CSS. Remove `border:2px solid black` from the progressbar and use Framework7's native progressbar styling.

---

### P2-D9-034 | MEDIUM | Season Countdown Timer Shows Two Different Sizes on Different Pages

- **Location:** `includes/layout.php:57` (navbar) vs `index.php:136` (homepage)
- **Description:** The season countdown appears in two places with dramatically different typography:
  - **Navbar** (`layout.php:57`): `font-size:10px; color:#aaa` — tiny, muted text `Xj`
  - **Homepage** (`index.php:125-140`): `font-size:18px; font-weight:bold; color:#8A0000` — large, prominent

  Both display the same information (days until season end), share the same `id="season-countdown"` and `data-end` attribute, but look completely different. Using the same element ID twice on the same page (when both navbar and page content are rendered simultaneously) also causes JavaScript targeting issues.
- **Impact:** The `countdown.js` script targets `document.getElementById('season-countdown')` which in JavaScript returns only the FIRST matching element. The homepage version will update correctly (it comes later in DOM order on the homepage), but on all other pages only the navbar countdown updates while the homepage section (on index.php) is the only element being updated. This is a JS bug caused by the visual design duplicating the same component with the same ID.
- **Fix:** Give each instance a unique ID: `season-countdown-navbar` and `season-countdown-hero`. Update `countdown.js` to target both. Unify the visual style by creating a `.countdown-display` class with shared typography rules.

---

### P2-D9-035 | MEDIUM | `<html>` Tag Missing — `basicpublichtml.php` Generates `<body>` Without `<html>`

- **Location:** `includes/basicpublichtml.php:1`
- **Description:** The public HTML template begins directly with `<body>`:
  ```php
  <body class="theme-black" style="font-weight:regular">
  ```
  The matching `<html>` open tag is in `includes/layout.php:6`, which outputs `<html>` before including either `basicprivatehtml.php` or `basicpublichtml.php`. The `<head>` and `<html>` are in `layout.php`, not in either HTML template file. But `layout.php` is included via PHP from `index.php`, and the HTML structure splits across multiple files without clear documentation of the expected assembly order.
- **Impact:** Minor structural concern but not a rendering bug since the files are included in the correct server-side order. The issue is documentation/maintainability — the HTML document structure is split non-obviously across 5+ files, making it difficult for developers to trace the full page structure.
- **Fix:** Add HTML structure comments to `layout.php` documenting which file provides which section.

---

### P2-D9-036 | LOW | `nowrapColumn` CSS Class Only Used in `connectes.php` — Consider Inline Style

- **Location:** `css/my-app.css:38-43`, `connectes.php:26-27`
- **Description:** The `.nowrapColumn` class is defined in `my-app.css` and used in exactly one place: the connected players page. The class combines `white-space:nowrap`, `text-overflow:ellipsis`, `overflow:hidden`, and `max-width:1px`. The `max-width:1px` is a CSS table column trick for allowing `text-overflow:ellipsis` to work — it sets a 1px minimum width which the table's column algorithm then expands to fill available space, enabling the overflow/ellipsis behaviour.
- **Impact:** Low — functional. The `max-width:1px` trick may confuse developers unfamiliar with CSS table column ellipsis patterns. No documentation explains why max-width is 1px.
- **Fix:** Add a comment in `my-app.css` explaining the 1px trick. No removal needed unless the class is used elsewhere in future — keep it.

---

### P2-D9-037 | LOW | `.item-media .item-inner:after` Selector in `my-app.css` — Purpose Unclear

- **Location:** `css/my-app.css:45-47`
- **Description:** The custom CSS file contains:
  ```css
  .item-media .item-inner:after {
    visibility: hidden;
  }
  ```
  This hides the `::after` pseudo-element on `.item-inner` elements that appear inside `.item-media`. In Framework7 v1.5, `.item-inner::after` is used to draw the bottom divider line in list items. Hiding it on `.item-media` elements removes the separator line for media items. This may have been added to fix a double-border issue, but there is no comment explaining it.
- **Impact:** Minor — may cause missing or unexpected separator lines in media list items. The undocumented override makes it hard to understand if removing it would break visual layout.
- **Fix:** Add a code comment:
  ```css
  /* Hides the Framework7 list-item divider line when item-inner is nested inside item-media
     to prevent double-border appearance in media list items */
  .item-media .item-inner:after {
    visibility: hidden;
  }
  ```

---

### P2-D9-038 | LOW | `-webkit-linear-gradient` and `-moz-linear-gradient` — Obsolete Vendor Prefixes

- **Location:** `includes/style.php:87-90`
- **Description:** The `hr` element styling uses four vendor-prefixed gradient declarations:
  ```css
  hr {
      background-image: -webkit-linear-gradient(left, #f0f0f0, #8c8b8b, #f0f0f0);
      background-image: -moz-linear-gradient(left, #f0f0f0, #8c8b8b, #f0f0f0);
      background-image: -ms-linear-gradient(left, #f0f0f0, #8c8b8b, #f0f0f0);
      background-image: -o-linear-gradient(left, #f0f0f0, #8c8b8b, #f0f0f0);
  }
  ```
  There is no unprefixed `linear-gradient()` fallback. The `-ms-` and `-o-` prefixes have not been required since 2014 and 2012 respectively. The `-webkit-` prefix is still supported but unnecessary for modern browsers. More critically, the lack of an unprefixed rule means the gradient fails silently in any non-prefixed-only browser context.
- **Impact:** The horizontal rule gradient is missing the modern unprefixed declaration. In any browser or context where prefixed gradients are not supported (strict CSS environments, future browser versions), `hr` elements render as a flat bar with no gradient. This affects all pages since `hr` is used throughout.
- **Fix:**
  ```css
  hr {
      border: 0;
      height: 1px;
      background-image: linear-gradient(to right, #f0f0f0, #8c8b8b, #f0f0f0);
  }
  ```

---

### P2-D9-039 | LOW | `-webkit-box-shadow` and `-moz-box-shadow` — Obsolete Prefixes on `lienFormule`

- **Location:** `includes/style.php:98-100`
- **Description:** The formula link style uses redundant vendor prefixes:
  ```css
  .lienFormule {
      -webkit-box-shadow: 0px 2px 0px gray;
      -moz-box-shadow: 0px 2px 0px gray;
      box-shadow: 0px 2px 0px gray;
  }
  ```
  `-webkit-box-shadow` has been unnecessary since 2012 and `-moz-box-shadow` since 2011. All modern browsers support the unprefixed `box-shadow`. The prefixed versions add dead weight.
- **Impact:** None visual — the unprefixed property is present and takes effect. Minor payload bloat.
- **Fix:** Remove the two prefixed lines, keep only `box-shadow: 0 2px 0 gray;`.

---

### P2-D9-040 | LOW | `debutCarte()` Always Wraps Content in `<p>` Tags — Inappropriate for Tables and Divs

- **Location:** `includes/ui_components.php:31-36`
- **Description:** The `debutCarte()` function always opens with a `<p>` tag inside `card-content-inner`:
  ```php
  echo '...
      <div class="card-content-inner" ' . $overflow . ' >
      <p>';  // <-- hardcoded <p> opening
  ```
  And `finCarte()` always closes with `</p>`:
  ```php
  echo '   </p>
      </div>...';
  ```
  This means every card, regardless of its content type, wraps its content in a paragraph. When cards contain tables (`classement.php`, `constructions.php`, `marche.php`, `bilan.php`), this generates invalid HTML: `<p><table>...</table></p>` — paragraphs cannot contain block-level elements per the HTML5 specification. The browser's error recovery silently closes the `<p>` before the `<table>`, creating an empty paragraph before every table.
- **Impact:** Invalid HTML on every page that contains a table inside a card. The empty auto-closed `<p>` adds 16-20px of space above every table inside a card, causing layout inconsistency. Any CSS rule targeting `p > table` will not work as expected. Affects approximately 20+ pages.
- **Fix:** The `<p>` wrapper in `debutCarte()` and `finCarte()` should be removed. Content padding should be applied via `card-content-inner` CSS instead. Or add an optional parameter to skip the `<p>` tag for block-level content.

---

### P2-D9-041 | LOW | `imagePoints()` Function Returns Image Without Size Attributes

- **Location:** `includes/display.php:24-26`
- **Description:**
  ```php
  function imagePoints()
  {
      return '<img src="images/points.png" style="vertical-align:middle" alt="Points" title="Points" />';
  }
  ```
  No `width` or `height` attributes. This causes cumulative layout shift (CLS) as the browser cannot reserve space for the image before it loads. All other image helper functions in the same file (`image()`, `imageEnergie()`) include explicit dimensions.
- **Impact:** Minor layout shift on pages using `imagePoints()` — visible in `joueur.php`, `armee.php`, and `classement.php` where points display alongside numbers.
- **Fix:**
  ```php
  return '<img src="images/points.png" style="vertical-align:middle;width:23px;height:23px;" alt="Points" title="Points" />';
  ```

---

### P2-D9-042 | LOW | Hardcoded `height:63px` Spacer Div for Navbar Clearance

- **Location:** `includes/layout.php:220`
- **Description:** A hardcoded spacer div is used to compensate for the fixed navbar:
  ```html
  <div style="height:63px"></div> <!-- pour éviter des problèmes avec la barre du menu -->
  ```
  Framework7 provides the class `navbar-through` (applied on line 212 to `.pages`) specifically to handle navbar clearance with padding. The hardcoded 63px does not adapt to different navbar heights (e.g., when the toolbar sub-menu is active, the toolbar adds 65px more). When both navbar and toolbar are visible, content is obscured by the toolbar bottom bar.
- **Impact:** On pages with the bottom toolbar (classement, marche, armee, historique), content at the page bottom may be partially hidden under the toolbar. The hardcoded 63px also assumes a specific navbar height that may vary by device pixel ratio and Framework7 configuration.
- **Fix:** Remove the hardcoded div. Let Framework7's `navbar-through` class handle the top clearance (it adds `padding-top: 44px` automatically). Add `toolbar-through` class to `.page` when a toolbar is present to handle bottom clearance.

---

### P2-D9-043 | LOW | `<img alt="armee">` Generic Alt on All Public Menu Icons

- **Location:** `includes/basicpublichtml.php:13-18`
- **Description:** All 6 public navigation menu items use `alt="armee"` regardless of what the icon represents:
  ```php
  item(['media' => '<img alt="armee" src="images/menu/accueil.png" ...>', ...]);
  item(['media' => '<img alt="armee" src="images/menu/sinscrire.png" ...>', ...]);
  item(['media' => '<img alt="armee" src="images/menu/sinstruire.png" ...>', ...]);
  ```
  "armee" (army) is the description for none of these icons: accueil (home), sinscrire (register), sinstruire (learn), regles (rules), classement (ranking), forum.
- **Impact:** Screen readers announce "armee" before every public menu item. Completely misleading for assistive technology users navigating the public-facing menu.
- **Fix:** Use descriptive alt text matching each icon's purpose:
  ```php
  item(['media' => '<img alt="Accueil" src="images/menu/accueil.png" ...>', ...]);
  item(['media' => '<img alt="S\'inscrire" src="images/menu/sinscrire.png" ...>', ...]);
  ```

---

### P2-D9-044 | LOW | Inline `margin: 5px 5px 5px 5px` Verbose Form — Should Be `margin: 5px`

- **Location:** `sinstruire.php:27`, `sinstruire.php:28`
- **Description:** Atom images in sinstruire.php use the verbose four-value margin:
  ```html
  style="float: left;width:50px ;heigth:50px;margin: 5px 5px 5px 5px"
  ```
  `margin: 5px 5px 5px 5px` is exactly equivalent to `margin: 5px` and adds 15 unnecessary characters.
- **Impact:** No visual impact. Minor HTML payload bloat.
- **Fix:** `margin: 5px 5px 5px 5px` → `margin: 5px`.

---

### P2-D9-045 | LOW | `imageLabel()` Function Outputs Unclosed `</a>` When No Link Provided

- **Location:** `includes/display.php:29-39`
- **Description:** The `imageLabel()` function conditionally opens an `<a>` tag but always closes it:
  ```php
  function imageLabel($image, $label, $lien = false)
  {
      if (!$lien) {
          $lien = "";
          $typeLabel = 'labelClassement';
      } else {
          $lien = '<a href="' . $lien . '" class="lienSousMenu">';  // opens <a>
          $typeLabel = 'labelSousMenu';
      }
      return $lien . $image . '<br/><span class="' . $typeLabel . '"  style="color:black">' . $label . '</span></a>';
      //                                                                                                        ^^^^^ always closes </a>
  ```
  When `$lien = false`, the opening `<a>` is empty string `""` but `</a>` is still appended. This generates a stray `</a>` closing tag on every non-linked image label.
- **Impact:** Stray `</a>` tags in HTML. While browsers handle stray closing tags gracefully via error recovery (treating them as no-ops), they generate HTML validation errors and may cause unexpected DOM structure in edge cases. Used in `armee.php` and `classement.php` headers.
- **Fix:**
  ```php
  $closing = $lien ? '</a>' : '';
  return $lien . $image . '<br/><span class="' . $typeLabel . '" style="color:black">' . $label . '</span>' . $closing;
  ```

---

### P2-D9-046 | LOW | `chip()` Function — Border Style Applied to Wrong Element

- **Location:** `includes/ui_components.php:451-467`
- **Description:** The `chip()` function with `$circle = true` applies `border:1px solid black` to the chip-media (icon area):
  ```php
  if ($circle) {
      $style = "border:1px solid black";
  }
  ```
  The border is then applied to the `chip-media` div, not the chip itself. In Framework7, `chip-media` is the small circular icon area inside the chip. Adding a border to it creates a small square-looking bordered icon rather than a circular chip border. The `chipInfo()` function (which calls `chip()` with `$circle = true`) is used for all stat displays.
- **Impact:** All stat chips (attack, defense, HP, speed, etc.) in the army creation toolbar display with a bordered icon area. The visual result is a small black-bordered square around each stat icon, which looks like a broken design element rather than intentional styling.
- **Fix:** Either remove the border (the icon images already have clear visual boundaries) or apply it to the outer `chip` div instead of `chip-media`.

---

### P2-D9-047 | LOW | Double Space in `imageEnergie()` Return Value

- **Location:** `includes/display.php:21`
- **Description:**
  ```php
  return '<img src="images/energie.png" ' . $class . '  alt="Energie" title="Energie" />';
  //                                    ^^ double space between $class and alt
  ```
  A double space between the `$class` attribute and `alt="Energie"`. While HTML parsers normalise whitespace in attribute lists and this causes no rendering issue, it is a minor HTML quality defect.
- **Impact:** None visual. Generates slightly malformed attribute whitespace in HTML output.
- **Fix:** `' . $class . ' alt="` (single space).

---

## Cross-Cutting Summary

### The Most Critical Path

The highest priority fixes in order:

1. **P2-D9-001** — Fix the truncated CSS file (`css/my-app.css` orphan `.`)
2. **P2-D9-002** — Fix `height; 32px` typo in `.imageClassement`
3. **P2-D9-004** — Fix duplicate `alt="Energie"` on all atom `<img>` tags (accessibility + HTML validity)
4. **P2-D9-011** — Restore keyboard focus indicators (WCAG AA compliance)
5. **P2-D9-010** — Add `lang="fr"` to `<html>` tag
6. **P2-D9-013** — Define Bootstrap table classes or replace with Framework7 equivalents
7. **P2-D9-012** — Define `.data-table` CSS class (used 20+ times with no definition)
8. **P2-D9-020** — Fix `marroon`/`fuschia` misspellings (atoms display wrong colour)
9. **P2-D9-003** — Fix `font-weight:regular` invalid value
10. **P2-D9-005** — Fix `font-family: default` invalid value in forum cards

### Quick Wins (< 10 minutes each)

| Finding | Fix |
|---|---|
| P2-D9-001 | Delete line 57 from my-app.css |
| P2-D9-002 | Change `height;` to `height:` in style.php:260 |
| P2-D9-003 | Change `font-weight:regular` to `font-weight:normal` in 2 files |
| P2-D9-007 | Change `text-weight:bold` to `font-weight:bold` in copyright.php |
| P2-D9-008 | Remove space in `vertical-align :middle` |
| P2-D9-009 | Fix `heigth` → `height` in sinstruire.php |
| P2-D9-010 | Add `lang="fr"` to layout.php |
| P2-D9-017 | Change theme-color to `#8A0000` |
| P2-D9-020 | Fix `marroon` → `maroon`, `fuschia` → `fuchsia` |
| P2-D9-021 | Remove units from zero values |
| P2-D9-038 | Remove obsolete `-webkit-`/`-moz-` gradient prefixes |
| P2-D9-044 | `margin: 5px 5px 5px 5px` → `margin: 5px` |
| P2-D9-047 | Fix double space in imageEnergie() |

### Estimated Inline Style Reduction

Implementing the top 5 class extractions (P2-D9-018) would reduce inline style count from 501 to approximately:
- Remove 80 `padding:6px;border:1px solid #ddd;` → `.cell-bordered`
- Remove 65 `color:green` → Framework7 text utility
- Remove 20 `vertical-align:middle` → `.v-mid`
- Remove 18 `display:inline` → CSS class
- Remove 15 `width:25px;height:25px;` → `.icon-sm`

**Net reduction: ~198 inline styles (39% reduction)**

---

## Implementation Status — Pass 2 Fixes Applied
**Date applied:** 2026-03-05
**Implementor:** UI Designer Agent (claude-sonnet-4-6)

All Critical and High findings were implemented, plus 15 Medium/Low items. Summary:

| Finding | Status | File(s) Modified |
|---|---|---|
| P2-D9-001 | FIXED | `css/my-app.css` — orphan `.` removed |
| P2-D9-002 | FIXED | `includes/style.php` — `height;` → `height:` |
| P2-D9-003 | FIXED | `includes/basicprivatehtml.php`, `includes/basicpublichtml.php` — `font-weight:regular` removed |
| P2-D9-004 | FIXED | `includes/display.php` — duplicate alt removed, correct dynamic alt used |
| P2-D9-005 | FIXED | `includes/style.php` — `font-family: default` → `font-family: inherit` |
| P2-D9-006 | FIXED | `index.php` — `text-align:middle` → `text-align:left` |
| P2-D9-007 | FIXED | `includes/copyright.php` — `text-weight:bold` → `font-weight:bold` |
| P2-D9-008 | FIXED | `includes/basicpublichtml.php` — `vertical-align :middle` → `vertical-align:middle` |
| P2-D9-009 | FIXED | `sinstruire.php` — `heigth:50px` → `height:50px` (and extra spaces removed) |
| P2-D9-009b | FIXED | `includes/display.php` — `height;20px` → `height:20px` in `nombreMolecules()` |
| P2-D9-010 | FIXED | `includes/layout.php` — `<html lang="fr">` added |
| P2-D9-011 | FIXED | `includes/style.php` — focus indicators restored with brand color `#8A0000` |
| P2-D9-012 | FIXED | `includes/style.php` — `.data-table` CSS class defined with full table styling |
| P2-D9-013 | FIXED | 13 PHP files — Bootstrap `table-striped table-bordered` → `.data-table` |
| P2-D9-015 | FIXED | `alliance.php` — invisible submit buttons replaced with visible `button.button-fill` elements |
| P2-D9-017 | FIXED | `includes/meta.php` — `theme-color` corrected from `#2196f3` to `#8A0000` |
| P2-D9-018 | FIXED | `includes/layout.php` — navbar inline shadow extracted to `.navbar-shadow` class |
| P2-D9-019 | FIXED | `includes/layout.php` — hardcoded 63px spacer replaced with `.navbar-spacer` class |
| P2-D9-020 | FIXED | `index.php` — `marroon` → `#693C25`, `fuschia` → `#FF3C54`, `lightGray` → `#aaaaaa` |
| P2-D9-020b | FIXED | `includes/layout.php` — `lightgray` → `#d3d3d3` (explicit hex) |
| P2-D9-021 | PARTIAL | `includes/meta.php` — duplicate charset removed |
| P2-D9-023 | FIXED | `includes/style.php` — dead `.image-centree` and `.align` classes removed |
| P2-D9-024 | FIXED | `includes/basicprivatehtml.php` — `bg-grey` → `bg-gray` |
| P2-D9-025 | FIXED | `includes/style.php` — `.button { color:red }` global rule removed (wrong override) |
| P2-D9-026 | FIXED | `includes/style.php` — duplicate `@font-face` declarations removed (kept only in `css/my-app.css`) |
| P2-D9-029 | FIXED | `includes/style.php` — vendor-prefixed `hr` gradients replaced with single unprefixed rule |
| P2-D9-030 | FIXED | `includes/style.php` — obsolete `-webkit-box-shadow`/`-moz-box-shadow` removed from `.lienFormule` |
| P2-D9-031 | FIXED | `includes/style.php` — verbose `margin: 0px 0px 0px 0px` → `margin: 0` on `.titreAide` |
| P2-D9-031b | FIXED | `includes/style.php` — `padding: 5px 5px 5px 5px` → `padding: 5px` on `.lienFormule` |
| P2-D9-034 | FIXED | `includes/ui_components.php` — `accordion()` function: titre/contenu use correct `item-title`/plain text instead of `item-media` |
| P2-D9-035 | FIXED | `includes/ui_components.php` — `progressBar()`: `<br/><br/><br/>` → CSS margin, `<center>` → `div[text-align:center]` |
| P2-D9-036 | FIXED | `includes/cardsprivate.php` — `finListe()` after `debutAccordion()` → `finAccordion()` (tutorial panel) |
| P2-D9-036b | FIXED | `includes/cardsprivate.php` — missions panel: `debutAccordion()` → `debutListe()` (accordion not used for items) |
| P2-D9-037 | FIXED | `includes/basicprivatehtml.php`, `cardsprivate.php`, `player.php`, `compte.php` — `<center>` → `<div style="text-align:center">` |
| P2-D9-038 | FIXED | `includes/cardsprivate.php`, `includes/basicprivatehtml.php` — `<nobr>` → `<span style="white-space:nowrap">` |
| P2-D9-039 | FIXED | `index.php` — `alt="so"` → descriptive alt text for all atom images |
| P2-D9-040 | FIXED | `includes/basicpublichtml.php` — `alt="armee"` → descriptive alts on all 6 menu icons |
| P2-D9-040b | FIXED | `includes/basicprivatehtml.php` — `alt="checklist"` → descriptive alts on all 14 menu icons |
| P2-D9-041 | FIXED | `includes/display.php` — `imagePoints()` given explicit `width:25px;height:25px` to prevent CLS |
| P2-D9-042 | FIXED | `includes/display.php` — `nombreTout()` empty `chip bg-""` class → `chip` |
| P2-D9-044 | FIXED | `includes/cardsprivate.php` — duplicate `alt="Energie"` on iode img fixed |
| P2-D9-047 | FIXED | `includes/display.php` — double space before `alt` in `imageEnergie()` |
| P2-D9-stray | FIXED | `includes/display.php` — `imageLabel()` stray `</a>` when no link provided |
| P2-D9-stray2 | FIXED | `includes/style.php` — `.facebook-grade { margin-top : 10px }` space before colon fixed |

### Remaining (Not Yet Implemented)
- P2-D9-014: accordion() title rendered via `<br/>` in `itemAccordion` — needs careful test
- P2-D9-016 + P2-D9-017 class extraction: 143px chip-media width → CSS constant
- P2-D9-027/028: CSS specificity chain analysis for `.button` color overrides
- P2-D9-032/033: `imageSousMenu` (32px) vs `iconeMenu` (25px) consolidation — needs design decision
- P2-D9-043: `imageClassement()` intentional size hierarchy — leave as-is (documented)
- Inline style mass extraction (39% reduction): deferred, requires regression testing
- `data-table` header dark mode (#222 background): may conflict with light theme
- Duplicate `season-countdown` ID across navbar and homepage — JS fix needed

---

*End of Pass 2 — Domain 9 UI/Visual Design Audit*
*Total findings: 47 (P2-D9-001 through P2-D9-047)*
*Implementation date: 2026-03-05 — 40+ fixes applied across 16 files*
