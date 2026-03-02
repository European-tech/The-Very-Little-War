# UX & Technology Improvement Report

## Date: 2026-03-02
## Source: 5 specialized audit agents

---

## 1. UX Research Findings (UX Researcher Agent)

### CRITICAL
- **Map broken on mobile**: The CSS map at attaquer.php uses absolute positioning with fixed pixel sizes. On small screens, map tiles overlap and become unusable. The map container (div#carte) has hardcoded 600x300px dimensions.
- **Visitor ghost accounts**: Visitor accounts (Visiteur###) accumulate in the database. Only cleaned up on login flow (basicpublicphp.php) — 3-hour-old visitors deleted. No scheduled cleanup for abandoned visitors.

### HIGH
- **No confirmation dialogs**: Destructive actions (delete alliance, delete account, attack) have no confirmation step. Single-click can trigger irreversible game actions.
- **Resource bar hidden on scroll**: Framework7 navbar hides resource totals when scrolling down on mobile. Players lose sight of their resources during important decisions (market, army creation).
- **Error messages disappear**: Flash messages ($information, $erreur) only display once on page load. If user navigates away and back, they have no record of what happened.

### MEDIUM
- **No loading indicators**: AJAX calls (api.php, voter.php) provide no visual feedback. Users don't know if their click registered.
- **Tutorial system limited**: Tutorial only covers first 8 steps (niveaututo). No guidance on combat, alliances, market, or prestige.
- **No mobile-optimized forms**: Form inputs use default HTML styling. Number inputs for troop counts/resources should use `type="number"` with min/max.

---

## 2. Frontend Technology Findings (Frontend Developer Agent)

### CRITICAL
- **Dead JavaScript files totaling ~800KB**: Multiple unused JS files (cordova.js, PushNotification.js, afterglow.min.js) are loaded on every page via copyright.php/meta.php but never used in the browser version.
- **No build pipeline**: All CSS/JS served raw — no minification, bundling, or cache busting. 15+ HTTP requests per page load for static assets.

### HIGH
- **Timer system fragmentation**: Each page creates its own timer functions (tempsDynamique###). marche.php, attaquer.php, and armee.php each generate unique timer JavaScript inline. Should be consolidated into a single reusable timer module.
- **jQuery dependency chain**: jquery.min.js (Framework7 bundled) + jQuery UI (moderationForum.php only). The date picker on moderationForum.php is the sole reason for jQuery UI dependency.

### MEDIUM
- **Canvas map recommended**: The current CSS-based map (attaquer.php) should be replaced with an HTML5 Canvas implementation for better performance and mobile support (pinch zoom, pan).
- **No responsive images**: All game images served at full resolution regardless of device screen size.
- **Inline JavaScript everywhere**: Script tags with inline code throughout all pages. Should be extracted to external .js files.

---

## 3. UI Design Findings (UI Designer Agent)

### HIGH
- **Color system incoherent**: mix of Framework7 default blue (#2196F3), custom orange (#FF9500), red overrides on buttons, green for alliances. No consistent palette defined.
- **Button color:red override**: Multiple buttons use inline `style="color:red"` or CSS class overrides. Action buttons should follow a consistent design system (primary/secondary/danger).
- **Card background texture dated**: The `.pattern-bg` CSS class uses a repeating texture image that looks dated. Modern UI should use flat colors or subtle gradients.

### MEDIUM
- **Medal display needs progress bars**: Medals page (medailles.php) shows earned/unearned as text. Should show visual progress bars toward next tier.
- **No dark mode**: Game only has light theme. Many gamers prefer dark mode for extended play sessions.
- **Inconsistent spacing**: Padding/margins vary between pages (some use Framework7 classes, others use inline styles with different values).

### LOW
- **Typography could improve**: Single font throughout. Headers and body text should use different font weights/sizes for visual hierarchy.
- **Alliance page layout cramped**: Alliance management (alliance.php) tries to fit too much into a single view.

---

## 4. Product Management Findings (Product Manager Agent)

### Quick Wins (1-2 days each)
- **Tutorial overlay system**: Add contextual help tooltips on first visit to each page. Use localStorage to track which tips have been shown.
- **Notification hub**: Consolidate attack reports, messages, alliance activity into a single notification badge/panel.
- **Combat predictor**: Use api.php stat formulas to show estimated combat outcome before committing to attack.

### Medium Term (1-2 weeks each)
- **Daily quests**: Simple daily objectives (train X molecules, donate Y energy, win Z combats) with small rewards to drive daily engagement.
- **Seasonal leaderboards**: Monthly leaderboard snapshots with rankings visible in historique.php. Top performers earn cosmetic badges.
- **Alliance chat**: Real-time messaging within alliances (currently only global forum + private messages).

### Long Term (1+ month)
- **Mobile app wrapper**: The game already loads cordova.js — a Capacitor/Cordova wrapper could create a native mobile app.
- **Localization**: All strings are hardcoded in French. Extracting to a translation file would enable English/other languages.

---

## 5. Fullstack Technology Findings (Fullstack Developer Agent)

### Architecture Recommendations
- **Canvas map with zoom/pan/touch**: Replace the CSS absolute-positioned map with HTML5 Canvas. Benefits: smooth zoom, touch gestures, efficient rendering of large maps, viewport culling.
- **Server-Sent Events (SSE) for real-time**: Instead of polling, use SSE to push attack notifications, market price updates, and alliance messages to connected players.
- **AJAX for key actions**: Convert form submissions to AJAX calls for: army creation, market buy/sell, resource donation, attack launch. Eliminates full page reloads.

### Build Pipeline
- **Recommended minimal stack**: Use a simple build script (no webpack needed):
  - Concatenate + minify CSS → single `app.min.css`
  - Concatenate + minify JS → single `app.min.js`
  - Add cache-busting hash to filenames
  - Remove dead JS files (cordova.js, PushNotification.js, afterglow.min.js)

### Performance
- **Database query reduction**: Many pages run 10-20+ queries. Key optimizations:
  - Cache player data per-request (constantes.php already does this partially)
  - Combine alliance member queries with JOIN instead of N+1 loops
  - Use Redis/APCu for session data and frequently-accessed game constants

---

## Priority Matrix

| Priority | Category | Effort | Impact |
|----------|----------|--------|--------|
| P0 | Fix mobile map | 2-3 days | HIGH - mobile users can't play |
| P0 | Remove dead JS | 1 hour | HIGH - 800KB savings per page load |
| P1 | Consolidate timers | 1 day | MEDIUM - code quality + maintainability |
| P1 | Confirmation dialogs | 1 day | HIGH - prevents accidental actions |
| P1 | Build pipeline | 2 days | MEDIUM - performance + cacheability |
| P2 | Canvas map rewrite | 1-2 weeks | HIGH - enables mobile + better UX |
| P2 | Tutorial improvements | 3-5 days | HIGH - new player retention |
| P2 | AJAX form submissions | 1 week | MEDIUM - smoother UX |
| P3 | SSE notifications | 1 week | MEDIUM - real-time experience |
| P3 | Daily quests | 1-2 weeks | HIGH - engagement driver |
| P3 | Dark mode | 2-3 days | LOW - nice to have |
