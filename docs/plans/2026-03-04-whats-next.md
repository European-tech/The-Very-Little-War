# What's Next — TVLW Roadmap Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Bring the V4 balance overhaul live, fix VPS connectivity, enable HTTPS, harden CSP, and add player-facing QoL improvements to prepare for launch.

**Architecture:** Six phases ordered by dependency and risk. Phase 1 (ops) unblocks Phase 2 (deploy). Phase 3 (HTTPS) requires DNS. Phases 4-6 are independent and can run in parallel. Each phase is self-contained and deployable.

**Tech Stack:** PHP 8.2, MariaDB 10.11, Apache 2, Framework7 Material CSS, jQuery 3.7.1, Ionos VPS (Debian 12)

---

## Current State (2026-03-04)

| Area | Status |
|------|--------|
| Tests | 371 pass / 2327 assertions |
| Git | 13 commits ahead of origin (V4 balance + Batch I) |
| VPS | **Unreachable** — SSH timeout on 212.227.38.111:22 |
| HTTPS | Not enabled — DNS not pointed to VPS |
| Remediation | Batches A-E + I done. F blocked (DNS). G done (V4). H deferred (CSP). |
| V4 Balance | Fully implemented locally, **not yet live** |
| Remaining TODOs | 3x `TODO: needs FOR UPDATE lock` in player.php (minor) |

---

## Phase 1: VPS Recovery (30 min)

The VPS at 212.227.38.111 is not responding to SSH. Nothing else can proceed until this is fixed.

### Task 1.1: Diagnose VPS connectivity

**Step 1: Test basic network reachability**

```bash
ping -c 3 212.227.38.111
curl -v --connect-timeout 5 http://212.227.38.111/ 2>&1 | head -20
nmap -Pn -p 22,80,443 212.227.38.111
```

**Step 2: If unreachable — use Ionos Cloud Panel**

Log into https://my.ionos.com → Servers & Cloud → select the VPS → check if it's running. If stopped, restart it. If running but unreachable, use the KVM/VNC console to check firewall rules.

**Step 3: If reachable on port 80 but not 22 — firewall issue**

Via Ionos KVM console:
```bash
iptables -L -n | grep 22
ufw status
systemctl status sshd
```

**Step 4: Verify SSH access restored**

```bash
ssh -o ConnectTimeout=10 -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "uptime && git -C /var/www/html log --oneline -3"
```

Expected: uptime output + last 3 commits on VPS

**Step 5: Commit notes (no code change)**

Document any firewall/config changes made via Ionos panel.

---

## Phase 2: Push & Deploy V4 (15 min)

13 local commits need to reach GitHub and VPS. This brings the V4 balance overhaul live.

### Task 2.1: Commit untracked documentation

**Files:**
- Add: `docs/game balance audit/TXT.txt`
- Add: `docs/plans/2026-03-03-batch-I-backlog.md`
- Add: `docs/plans/2026-03-03-gameplay-balance-overhaul.md`
- Add: `docs/plans/2026-03-04-whats-next.md`

**Step 1: Stage and commit docs**

```bash
cd /home/guortates/TVLW/The-Very-Little-War
git add "docs/game balance audit/TXT.txt"
git add docs/plans/2026-03-03-batch-I-backlog.md
git add docs/plans/2026-03-03-gameplay-balance-overhaul.md
git add docs/plans/2026-03-04-whats-next.md
git commit -m "docs: add V4 balance audit source, batch I plan, gameplay overhaul plan, roadmap"
```

### Task 2.2: Push all commits to GitHub

**Step 1: Push to origin**

```bash
git push origin main
```

Expected: 14 commits pushed (13 existing + 1 docs commit)

**Step 2: Verify on GitHub**

```bash
gh repo view European-tech/The-Very-Little-War --json pushedAt,defaultBranchRef
```

### Task 2.3: Deploy to VPS

**Step 1: Pull on VPS**

```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "cd /var/www/html && git pull origin main"
```

**Step 2: Verify deployment — spot-check key pages**

```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'curl -s -o /dev/null -w "%{http_code}" http://localhost/index.php && echo " index" && curl -s -o /dev/null -w "%{http_code}" http://localhost/constructions.php && echo " constructions" && curl -s -o /dev/null -w "%{http_code}" http://localhost/prestige.php && echo " prestige"'
```

Expected: `200 index`, `200 constructions`, `200 prestige`

**Step 3: Check PHP error log is clean**

```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "tail -20 /var/log/apache2/error.log"
```

Expected: No new PHP warnings/errors

---

## Phase 3: DNS + HTTPS (30 min, requires manual DNS step)

### Task 3.1: Point DNS to VPS (manual — Ionos panel)

**Step 1: Log into Ionos domain management**

Go to https://my.ionos.com → Domains → theverylittlewar.com → DNS Settings

**Step 2: Update A record**

| Type | Host | Value | TTL |
|------|------|-------|-----|
| A | @ | 212.227.38.111 | 300 |
| A | www | 212.227.38.111 | 300 |

**Step 3: Wait for propagation and verify**

```bash
dig +short theverylittlewar.com A
dig +short www.theverylittlewar.com A
```

Expected: both return `212.227.38.111`

### Task 3.2: Enable HTTPS via Let's Encrypt

**Step 1: Run certbot on VPS**

```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "certbot --apache -d theverylittlewar.com -d www.theverylittlewar.com --non-interactive --agree-tos --email admin@theverylittlewar.com"
```

**Step 2: Verify HTTPS works**

```bash
curl -I https://theverylittlewar.com 2>&1 | head -5
```

Expected: `HTTP/2 200` with valid certificate

### Task 3.3: Harden cookies and add HSTS

**Files:**
- Modify: `includes/connexion.php` or `includes/session_init.php`
- Modify: `.htaccess`

**Step 1: Set session cookie to secure-only**

Find the session configuration and add:
```php
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
```

**Step 2: Add HSTS header to .htaccess**

```apache
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

**Step 3: Add HTTP→HTTPS redirect**

```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

**Step 4: Run tests locally**

```bash
cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --no-coverage
```

**Step 5: Commit and deploy**

```bash
git add includes/connexion.php .htaccess
git commit -m "fix(C-006, C-007): HSTS header, secure cookies, HTTP→HTTPS redirect"
git push origin main
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "cd /var/www/html && git pull origin main"
```

**Step 6: Verify HTTPS redirect works**

```bash
curl -I http://theverylittlewar.com 2>&1 | head -5
```

Expected: `301 Moved Permanently` → `https://theverylittlewar.com`

---

## Phase 4: CSP Hardening — Remove unsafe-inline (4-6h)

Extract all inline `<script>` blocks into external .js files and switch to nonce-based CSP.

### Task 4.1: Audit all inline scripts

**Step 1: Count inline script blocks across all PHP files**

```bash
cd /home/guortates/TVLW/The-Very-Little-War
grep -rn '<script' --include="*.php" | grep -v 'src=' | grep -v vendor | wc -l
```

**Step 2: Categorize by file and function**

Create a spreadsheet of every inline `<script>` block:
- File path and line number
- What the script does (chart init, form validation, AJAX call, etc.)
- Variables it needs from PHP (for `json_encode` injection)
- Target external .js filename

**Step 3: Commit the audit as a reference doc**

```bash
git commit -m "docs: inline script audit for CSP hardening"
```

### Task 4.2: Create nonce-based CSP infrastructure

**Files:**
- Create: `includes/csp.php`

**Step 1: Write nonce generator**

```php
<?php
function generateCspNonce(): string {
    if (!isset($_SESSION['csp_nonce'])) {
        $_SESSION['csp_nonce'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csp_nonce'];
}
```

**Step 2: Update CSP header in .htaccess**

Replace `'unsafe-inline'` with nonce in script-src directive. This must be done via PHP header (not .htaccess) since nonce is per-request:

```php
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . generateCspNonce() . "' https://cdnjs.cloudflare.com; ...");
```

**Step 3: Run tests, commit**

```bash
git commit -m "feat: add nonce-based CSP infrastructure"
```

### Task 4.3: Extract inline scripts — page by page

For each PHP file with inline scripts:

**Step 1: Create `js/<pagename>.js` with the extracted code**

**Step 2: Replace inline `<script>` with `<script src="js/<pagename>.js" nonce="<?= generateCspNonce() ?>">`**

**Step 3: For PHP variables needed in JS, add a data block:**

```php
<script nonce="<?= generateCspNonce() ?>">
    window.TVLW = <?= json_encode(['key' => $value]) ?>;
</script>
<script src="js/pagename.js" nonce="<?= generateCspNonce() ?>"></script>
```

**Step 4: Test the page manually in browser**

**Step 5: Commit after each file group (2-3 files per commit)**

```bash
git commit -m "refactor(CSP): extract inline scripts from <filename>.php"
```

### Task 4.4: Deploy and verify CSP

**Step 1: Push and deploy**

```bash
git push origin main
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "cd /var/www/html && git pull origin main"
```

**Step 2: Test every page — check browser console for CSP violations**

Open each page in Chrome DevTools → Console. Zero CSP violation errors expected.

**Step 3: Run full test suite**

```bash
php vendor/bin/phpunit --no-coverage
```

---

## Phase 5: Player Experience Polish (3-4h)

Quality-of-life improvements that make the game more welcoming for new and returning players.

### Task 5.1: Add season countdown timer to homepage

**Files:**
- Modify: `index.php`
- Create: `js/countdown.js`

**Step 1: Write failing test** — verify index.php outputs a countdown element

**Step 2: Add countdown element to index.php**

```php
<?php
$finManche = dbFetchOne($base, "SELECT valeur FROM parametres WHERE nom = 'finManche'");
$secondsLeft = max(0, strtotime($finManche['valeur']) - time());
?>
<div id="countdown" data-end="<?= htmlspecialchars($finManche['valeur']) ?>">
    Fin de la manche dans: <span id="countdown-timer"><?= gmdate('j\j H\h i\m', $secondsLeft) ?></span>
</div>
```

**Step 3: Write js/countdown.js**

```javascript
(function() {
    var el = document.getElementById('countdown-timer');
    var end = new Date(document.getElementById('countdown').dataset.end).getTime();
    setInterval(function() {
        var now = Date.now();
        var diff = Math.max(0, Math.floor((end - now) / 1000));
        var d = Math.floor(diff / 86400);
        var h = Math.floor((diff % 86400) / 3600);
        var m = Math.floor((diff % 3600) / 60);
        el.textContent = d + 'j ' + h + 'h ' + m + 'm';
    }, 60000);
})();
```

**Step 4: Run tests, commit**

```bash
git commit -m "feat: add season countdown timer to homepage"
```

### Task 5.2: Add tooltips to building/formula displays

**Files:**
- Modify: `constructions.php`
- Modify: `armee.php`
- Modify: `atomes.php`

**Step 1: Add `title` attributes with formula explanations to key displays**

For each building stat display, add a tooltip explaining the formula:
```php
<span title="<?= htmlspecialchars('Coût: ' . $config['cost_energy_base'] . ' × ' . $config['cost_growth_base'] . '^niveau') ?>">
    <?= number_format($cout) ?> E
</span>
```

**Step 2: Add tooltips to molecule stat displays**

```php
<span title="Attaque = (O^1.2 + O) × (1 + niv/50) × modCond">
    <?= number_format($attaque) ?>
</span>
```

**Step 3: Run tests, commit**

```bash
git commit -m "feat: add formula tooltips to building costs and molecule stats"
```

### Task 5.3: Add attack history log page

**Files:**
- Create: `historique_combats.php`
- Modify: `includes/basicprivatehtml.php` (menu link)

**Step 1: Write failing test** — historique_combats.php returns 200 for authenticated user

**Step 2: Create page showing last 50 combat reports**

```php
<?php
require_once 'includes/basicprivatephp.php';
$rapports = dbFetchAll($base,
    "SELECT r.*, j.login AS adversaire
     FROM rapports r
     LEFT JOIN joueurs j ON (r.attaquant = ? AND j.login = r.defenseur)
                         OR (r.defenseur = ? AND j.login = r.attaquant)
     WHERE r.attaquant = ? OR r.defenseur = ?
     ORDER BY r.date DESC LIMIT 50",
    'ssss', $_SESSION['login'], $_SESSION['login'], $_SESSION['login'], $_SESSION['login']
);
```

With filter buttons: All / Attacks / Defenses / Wins / Losses

**Step 3: Add navigation link in sidebar**

**Step 4: Run tests, commit**

```bash
git commit -m "feat: add combat history page with filters"
```

### Task 5.4: Add inline V4 help to key pages

**Files:**
- Modify: `regles.php`

**Step 1: Update the rules page with V4 mechanics**

Add sections explaining:
- Covalent synergies (why hybrid builds matter)
- Isotope variants (Stable / Reactif / Catalytique)
- Specializations (Oxydant/Reducteur, Industriel/Energetique, Theorique/Applique)
- Vault protection mechanics
- Market slippage

**Step 2: Ensure all text is in French, matching game language**

**Step 3: Run tests, commit**

```bash
git commit -m "docs: update regles.php with V4 mechanics explanation"
```

### Task 5.5: Fix 3 remaining TODO comments in player.php

**Files:**
- Modify: `includes/player.php:77,83,89`

**Step 1: Read the context around lines 77-89**

**Step 2: Add `FOR UPDATE` to the SELECT queries or wrap in existing transactions**

**Step 3: Run tests, commit**

```bash
git commit -m "fix: add FOR UPDATE locks to player.php resource queries"
```

---

## Phase 6: Launch Preparation (2h)

### Task 6.1: Create a landing page for new players

**Files:**
- Modify: `index.php` (public view)

**Step 1: Enhance the public-facing index.php**

For non-logged-in visitors, show:
- Game tagline and brief description (in French)
- Key features (8 atom types, alliances, combat, market, prestige)
- Screenshot/mockup of gameplay
- Prominent "S'inscrire" (Register) button
- "Se connecter" (Login) link

**Step 2: Run tests, commit**

```bash
git commit -m "feat: enhance public landing page for player acquisition"
```

### Task 6.2: Add meta tags for SEO and social sharing

**Files:**
- Modify: `includes/basicpublihtml.php` or equivalent header

**Step 1: Add Open Graph and basic meta tags**

```html
<meta name="description" content="The Very Little War - Jeu de stratégie chimique multijoueur. Créez des molécules, attaquez vos ennemis, dominez le marché.">
<meta property="og:title" content="The Very Little War">
<meta property="og:description" content="Jeu de stratégie chimique multijoueur gratuit">
<meta property="og:type" content="website">
<meta property="og:url" content="https://theverylittlewar.com">
```

**Step 2: Run tests, commit**

```bash
git commit -m "feat: add SEO meta tags and Open Graph for social sharing"
```

### Task 6.3: Final full deployment and smoke test

**Step 1: Push everything**

```bash
git push origin main
```

**Step 2: Deploy to VPS**

```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "cd /var/www/html && git pull origin main"
```

**Step 3: Full smoke test — all 34+ pages**

```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'for page in index inscription regles credits version connectes classement medailles forum listesujets sinstruire historique voter compte constructions armee atomes molecule attaque attaquer marche don messages ecriremessage messagesenvoyes rapports joueur tutoriel prestige alliance allianceadmin guerre vacance prestige api; do code=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/${page}.php); echo "${code} ${page}"; done'
```

Expected: All return 200 (except pages requiring auth which return 302)

**Step 4: Verify zero PHP errors**

```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "tail -50 /var/log/apache2/error.log | grep -c 'PHP'"
```

Expected: 0

---

## Execution Order & Dependencies

```
Phase 1: VPS Recovery         → IMMEDIATE (blocks Phase 2, 3)
Phase 2: Push & Deploy V4     → After Phase 1 (15 min)
Phase 3: DNS + HTTPS          → After Phase 1 + manual DNS step (30 min)
Phase 4: CSP Hardening        → After Phase 3 (4-6h, independent)
Phase 5: Player Experience    → After Phase 2 (3-4h, independent of Phase 3/4)
Phase 6: Launch Preparation   → After Phase 3 + 5 (2h)
```

```
Total estimated: 10-14 hours across multiple sessions
Critical path: Phase 1 → Phase 2 → Phase 5 → Phase 6
Blocker: Phase 3 requires manual DNS configuration in Ionos panel
```

---

## Beyond This Plan

Once all 6 phases are complete, the game is **launch-ready**. Future work depends on player feedback:

- **Balance tuning** — monitor actual player behavior, adjust config.php constants
- **New features** — alliance bulletin board, achievement badges, mobile push notifications
- **Schema hardening** — FK constraints, column type fixes (from audit M-036 to M-045)
- **CSS/UI refresh** — modernize Framework7 Material theme
- **Analytics** — add lightweight page view / action tracking for balance insights
