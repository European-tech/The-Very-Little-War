# TVLW Master Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Complete all remaining remediation, implement anti-multiaccount detection, add sqrt ranking, clean up dead code, and build 4 new gameplay features — organized as deployable sprints with full specs traceability.

**Architecture:** 8 sprints ordered by dependency and risk. Sprint 0 (cleanup) and Sprint 1 (infra) unblock everything else. Sprint 2 (anti-multiaccount) must land before Sprint 3 (sqrt ranking) to prevent exploitation. Sprints 4-7 are independent feature tracks. Each sprint is self-contained and deployable.

**Tech Stack:** PHP 8.2, MariaDB 10.11, Apache 2, Framework7 Material CSS, jQuery 3.7.1, Ionos VPS (Debian 12)

---

## Specs Traceability Index

Every task references its origin:

| Prefix | Source Document |
|--------|----------------|
| C-xxx | `docs/audit3/mega-findings-tracker.md` — CRITICAL finding |
| H-xxx | `docs/audit3/mega-findings-tracker.md` — HIGH finding |
| M-xxx | `docs/audit3/mega-findings-tracker.md` — MEDIUM finding |
| L-xxx | `docs/audit3/mega-findings-tracker.md` — LOW finding |
| V4-xx | V4 gameplay audit item (from `2026-03-03-gameplay-balance-overhaul.md`) |
| V3-xx | V3 plan task (from `2026-03-02-gameplay-balance-overhaul.md`) |
| NEW-x | New requirement (user request 2026-03-04) |
| QOL-x | Quality of life item from mega audit |

---

## Sprint Overview

| Sprint | Name | Est. Time | Dependencies | Deployable |
|--------|------|-----------|--------------|------------|
| 0 | Cleanup & Security Fixes | 3-4h | None | Yes |
| 1 | Infrastructure: VPS + DNS + HTTPS | 1-2h | DNS (manual) | Yes |
| 2 | Anti-Multiaccount Detection System | 6-8h | Sprint 0 | Yes |
| 3 | Sqrt Ranking & Points Rebalance | 3-4h | Sprint 2 | Yes |
| 4 | Espionage, Display Fix & UX Polish | 3-4h | Sprint 0 | Yes |
| 5 | Resource Nodes on Map | 4-6h | Sprint 0 | Yes |
| 6 | Compound Synthesis Lab | 6-8h | Sprint 5 | Yes |
| 7 | Balance Tuning (Batch G) | 2-3h | Playtesting data | Yes |

```
Sprint 0 ──┬── Sprint 1 (blocked on DNS)
            ├── Sprint 2 ── Sprint 3
            ├── Sprint 4
            ├── Sprint 5 ── Sprint 6
            └── Sprint 7 (needs live player data)
```

---

## Prior Work Already Completed

The following remediation batches are DONE (101+ items fixed) and NOT repeated in this plan:
- **Batches A-E**: Transaction safety (H-001/002/013-016), BBCode XSS (H-025/026), column whitelists (H-037-042), season reset hardening (H-048-052), prestige.php UI (C-030)
- **Batch I**: Security fixes (L-023, H-033, H-027), data integrity (M-001, M-005, L-019), dead files (L-013, L-018), UX polish (M-027, M-022, M-011)
- **Batches J-R**: CSP nonce implementation (C-004 substantially fixed), code hardening (stripslashes, die(), mysqli_fetch_array), bilan.php bonus page, CHECK constraints (migration 0017), CI pipeline
- **V4 Balance**: 18/20 V4 gameplay items implemented (covalent synergy, exponential economy, iode catalyst, decay V4, overkill cascade, combat points)
- **CSP Status**: Nonce-based CSP via includes/csp.php + layout.php header. script-src uses nonce, no unsafe-inline. ~21 inline scripts remain but are nonce-gated.

---

## Sprint 0: Cleanup & Security Fixes — COMPLETED

**Goal:** Fix all remaining CRITICAL security items, clean dead constants, fix display bug.

---

### Task 0.1: Fix admin stored XSS in tableau.php [C-008]

**Source:** C-008 — Admin stored XSS via strip_tags
**Files:**
- Modify: `admin/tableau.php:76,285-296,303,635`

**Step 1: Read admin/tableau.php and identify all unescaped outputs**

Look for: `echo $data['nom']`, `echo $data1['unite']`, `echo $data1['compagnie']`, and any raw DB values in JS string contexts.

**Step 2: Apply htmlspecialchars to all DB value outputs**

For HTML context:
```php
echo htmlspecialchars($data['nom'], ENT_QUOTES, 'UTF-8');
```

For JS context (inside `<script>` blocks):
```php
echo json_encode($data1['unite']);
```

**Step 3: Run tests**

```bash
cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --no-coverage
```
Expected: 371 tests pass

**Step 4: Commit**

```bash
git add admin/tableau.php
git commit -m "fix(C-008): escape all DB values in admin/tableau.php with htmlspecialchars/json_encode"
```

---

### Task 0.2: Move admin password hash to .env [C-013]

**Source:** C-013 — Admin password hash hardcoded in source-controlled file
**Files:**
- Modify: `includes/constantesBase.php:54`
- Modify: `.env.example`
- Create or modify: `.env` (on VPS only, never committed)

**Step 1: Read constantesBase.php and find ADMIN_PASSWORD_HASH**

**Step 2: Replace hardcoded hash with getenv()**

```php
// Before:
define('ADMIN_PASSWORD_HASH', '$2y$10$...');

// After:
define('ADMIN_PASSWORD_HASH', getenv('ADMIN_PASSWORD_HASH') ?: '$2y$10$PLACEHOLDER_CHANGE_ME');
```

**Step 3: Add to .env.example**

```
ADMIN_PASSWORD_HASH="$2y$10$..."
```

**Step 4: Run tests, commit**

```bash
git add includes/constantesBase.php .env.example
git commit -m "fix(C-013): move admin password hash to .env, remove from source control"
```

**Step 5: On VPS deployment, set the real hash in /var/www/html/.env**

---

### Task 0.3: Add SRI to Google Charts loader.js [C-020]

**Source:** C-020 — External scripts without SRI
**Files:**
- Modify: `marche.php` (the `<script src="https://www.gstatic.com/charts/loader.js">` tag)

**Step 1: Fetch the current SRI hash for loader.js**

```bash
curl -s https://www.gstatic.com/charts/loader.js | openssl dgst -sha384 -binary | openssl base64 -A
```

**Step 2: Add integrity and crossorigin attributes**

```html
<script src="https://www.gstatic.com/charts/loader.js"
        integrity="sha384-HASH_HERE"
        crossorigin="anonymous"
        nonce="<?php echo htmlspecialchars(cspNonce(), ENT_QUOTES, 'UTF-8'); ?>"></script>
```

**Note:** Google Charts loader.js is a CDN that may change versions. If SRI breaks, consider self-hosting the file. Add a comment explaining this.

**Step 3: Run tests, commit**

```bash
git commit -m "fix(C-020): add SRI integrity hash to Google Charts loader.js"
```

---

### Task 0.4: Create FK constraints migration [C-028]

**Source:** C-028 — Zero foreign key constraints in entire schema
**Files:**
- Create: `migrations/0018_add_foreign_keys.sql`

**Step 1: Write the migration**

```sql
-- Migration 0018: Add foreign key constraints for referential integrity
-- Run AFTER verifying no orphan rows exist

-- Core player tables → membre
ALTER TABLE autre ADD CONSTRAINT fk_autre_login
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE;

ALTER TABLE ressources ADD CONSTRAINT fk_ressources_login
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE;

ALTER TABLE constructions ADD CONSTRAINT fk_constructions_login
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE;

-- Molecule classes → membre
ALTER TABLE molecules ADD CONSTRAINT fk_molecules_proprietaire
    FOREIGN KEY (proprietaire) REFERENCES membre(login) ON DELETE CASCADE;

-- Prestige → membre
ALTER TABLE prestige ADD CONSTRAINT fk_prestige_login
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE;

-- Attack cooldowns → membre
ALTER TABLE attack_cooldowns ADD CONSTRAINT fk_cooldowns_attacker
    FOREIGN KEY (attacker) REFERENCES membre(login) ON DELETE CASCADE;

-- Forum → membre (soft: no CASCADE, SET NULL on delete)
ALTER TABLE sujets MODIFY auteur VARCHAR(255) NULL;
ALTER TABLE sujets ADD CONSTRAINT fk_sujets_auteur
    FOREIGN KEY (auteur) REFERENCES membre(login) ON DELETE SET NULL;

ALTER TABLE reponses MODIFY auteur VARCHAR(255) NULL;
ALTER TABLE reponses ADD CONSTRAINT fk_reponses_auteur
    FOREIGN KEY (auteur) REFERENCES membre(login) ON DELETE SET NULL;
```

**Step 2: Test locally by importing into a test DB (do NOT run on production yet)**

```bash
mysql -u tvlw -p tvlw_test < migrations/0018_add_foreign_keys.sql
```

**Step 3: Commit**

```bash
git add migrations/0018_add_foreign_keys.sql
git commit -m "db(C-028): add foreign key constraints migration 0018"
```

**Step 4: On VPS — first clean orphan rows, then run migration**

```sql
-- Clean orphans before applying FKs:
DELETE FROM autre WHERE login NOT IN (SELECT login FROM membre);
DELETE FROM ressources WHERE login NOT IN (SELECT login FROM membre);
DELETE FROM constructions WHERE login NOT IN (SELECT login FROM membre);
DELETE FROM molecules WHERE proprietaire NOT IN (SELECT login FROM membre);
DELETE FROM prestige WHERE login NOT IN (SELECT login FROM membre);
DELETE FROM attack_cooldowns WHERE attacker NOT IN (SELECT login FROM membre);
```

---

### Task 0.5: Fix actionsformation.idclasse type [C-029]

**Source:** C-029 — INT stores string 'neutrino', silently becomes 0
**Files:**
- Create: `migrations/0019_fix_idclasse_type.sql`

**Step 1: Write migration**

```sql
-- Migration 0019: Fix actionsformation.idclasse from INT to VARCHAR
-- The column stores 'neutrino' as a string sentinel and molecule IDs as integers
ALTER TABLE actionsformation MODIFY idclasse VARCHAR(50) NOT NULL DEFAULT '0';
```

**Step 2: Commit**

```bash
git add migrations/0019_fix_idclasse_type.sql
git commit -m "db(C-029): fix actionsformation.idclasse INT→VARCHAR(50) to store 'neutrino' sentinel"
```

---

### Task 0.6: Remove dead constants from config.php [NEW-1]

**Source:** V4 audit cross-reference — 24 unused constants identified
**Files:**
- Modify: `includes/config.php`
- Modify: `tests/ConfigConsistencyTest.php` (update tests that reference removed constants)
- Modify: `tests/GameBalanceTest.php` (same)

**Step 1: Read config.php and identify ALL dead constants**

Remove these confirmed-dead constants:
```
ATTACK_ATOM_COEFFICIENT, DEFENSE_ATOM_COEFFICIENT, HP_ATOM_COEFFICIENT
DESTRUCTION_ATOM_COEFFICIENT, PILLAGE_ATOM_COEFFICIENT
ATTACK_LEVEL_DIVISOR, DEFENSE_LEVEL_DIVISOR, HP_LEVEL_DIVISOR
DESTRUCTION_LEVEL_DIVISOR, PILLAGE_LEVEL_DIVISOR, SPEED_LEVEL_DIVISOR
SPEED_ATOM_COEFFICIENT
BUILDING_HP_GROWTH_BASE, BUILDING_HP_LEVEL_EXP
FORCEFIELD_HP_GROWTH_BASE, FORCEFIELD_HP_LEVEL_EXP
FORMATION_AZOTE_COEFFICIENT, FORMATION_AZOTE_EXPONENT, FORMATION_LEVEL_DIVISOR
BASE_STORAGE_PER_LEVEL, VAULT_PROTECTION_PER_LEVEL, PILLAGE_SOUFRE_DIVISOR
```

Also remove from `$BUILDING_CONFIG`:
```
$BUILDING_CONFIG['lieur']['lieur_growth_base']
$BUILDING_CONFIG['coffrefort']['protection_per_level']
```

**Step 2: Update tests that reference these constants**

Remove or update any test assertions that check the values of removed constants.

**Step 3: Run full test suite**

```bash
php vendor/bin/phpunit --no-coverage
```
Expected: All pass (may need test adjustments)

**Step 4: Commit**

```bash
git add includes/config.php tests/
git commit -m "cleanup(NEW-1): remove 24 dead pre-V4 constants from config.php"
```

---

### Task 0.7: Fix productionEnergieMolecule display [V4-4, V3-22]

**Source:** V4 audit — function should show catalyst contribution, not old quadratic formula
**Files:**
- Modify: `includes/formulas.php:115-118` (productionEnergieMolecule function)

**Step 1: Read formulas.php and understand current usage**

The function `productionEnergieMolecule($iode, $niveau)` still uses the old quadratic formula but iode is now a catalyst. This function is only used for display (molecule stat tooltip). It should show the per-molecule iode catalyst contribution.

**Step 2: Simplify to show iode atom count (display only)**

```php
/**
 * Display helper: shows iode atoms contributed by this molecule to the catalyst pool.
 * Actual energy calculation uses the catalyst system in revenuEnergie().
 */
function productionEnergieMolecule($iode, $niveau)
{
    return $iode; // Raw iode atoms — catalyst contribution
}
```

**Step 3: Update any tooltip text referencing "energy per molecule" to say "iode atoms (catalyst)"**

**Step 4: Run tests, update any broken test expectations, commit**

```bash
git commit -m "fix(V4-4): simplify productionEnergieMolecule to show catalyst contribution"
```

---

### Task 0.8: Admin session namespace separation [C-005, partial]

**Source:** C-005 — Admin/moderator share session namespace
**Files:**
- Modify: `admin/index.php`
- Modify: `moderation/index.php`

**Step 1: Add separate session name for admin**

In `admin/index.php`, before `session_start()`:
```php
session_name('TVLW_ADMIN');
session_start();
```

In `moderation/index.php`, same:
```php
session_name('TVLW_MOD');
session_start();
```

**Step 2: Verify admin pages still function (login, actions)**

**Step 3: Run tests, commit**

```bash
git commit -m "fix(C-005): separate admin/moderator session namespace from player sessions"
```

---

## Sprint 1: Infrastructure — VPS + DNS + HTTPS (1-2h)

**Goal:** Get the game live on HTTPS with all security headers.
**Blocked on:** DNS pointing theverylittlewar.com to 212.227.38.111 (manual Ionos panel step).

---

### Task 1.1: VPS Recovery [Ops]

**Source:** whats-next.md Phase 1

**Step 1: Test connectivity**

```bash
ping -c 3 212.227.38.111
curl -v --connect-timeout 5 http://212.227.38.111/ 2>&1 | head -20
```

**Step 2: If unreachable, use Ionos Cloud Panel**

Log into https://my.ionos.com → Servers & Cloud → restart VPS. If running, use KVM console to check firewall.

**Step 3: Verify SSH restored**

```bash
ssh -o ConnectTimeout=10 -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "uptime"
```

---

### Task 1.2: Deploy all commits to VPS [Ops]

**Source:** whats-next.md Phase 2

```bash
git push origin main
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "cd /var/www/html && git pull origin main"
```

Run migrations 0017, 0018, 0019 on VPS:
```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "mysql -u tvlw -p tvlw < /var/www/html/migrations/0017_add_check_constraints.sql"
```

---

### Task 1.3: DNS + HTTPS [C-006, C-007]

**Source:** C-006 (HSTS), C-007 (cookie_secure), whats-next.md Phase 3
**Files:**
- Modify: `includes/session_init.php` (hardcode cookie_secure=1)
- Modify: `.htaccess` (add HSTS, HTTP→HTTPS redirect)

**Step 1: Point DNS (manual — Ionos panel)**

| Type | Host | Value | TTL |
|------|------|-------|-----|
| A | @ | 212.227.38.111 | 300 |
| A | www | 212.227.38.111 | 300 |

**Step 2: Run certbot**

```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "certbot --apache -d theverylittlewar.com -d www.theverylittlewar.com --non-interactive --agree-tos --email admin@theverylittlewar.com"
```

**Step 3: Harden session cookies**

```php
// includes/session_init.php
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
```

**Step 4: Add HSTS and redirect to .htaccess**

```apache
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"

RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

**Step 5: Commit, deploy, verify**

```bash
git commit -m "fix(C-006, C-007): HSTS header, secure cookies, HTTP→HTTPS redirect"
git push origin main
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "cd /var/www/html && git pull origin main"
curl -I https://theverylittlewar.com 2>&1 | head -5
```

---

## Sprint 2: Anti-Multiaccount Detection System — COMPLETED

**Goal:** Build comprehensive multi-account detection with IP logging, fingerprinting, coordinated attack detection, transfer pattern analysis, automated alerts, and admin dashboard.

**Source:** NEW-2 (user request), builds on existing admin/index.php IP detection

**Completed:** All 8 tasks (2.1-2.8) implemented in 4 commits:
- Migration 0020: login_history table + membre.ip widened to VARCHAR(45)
- Migration 0021: account_flags + admin_alerts tables
- includes/multiaccount.php: all 5 detection methods + areFlaggedAccounts + email alerts
- Hooks in basicpublicphp.php (login), inscription.php (register), game_actions.php (combat), marche.php (transfers)
- admin/multiaccount.php: full dashboard (alerts, flags, stats, manual flagging, account detail)
- Transfer blocking for flagged account pairs
- 16 unit tests (382 total, 2332 assertions)

### Current State

What exists:
- `membre.ip` stores last-login IP (overwritten each login, VARCHAR(11) — too short for IPv6)
- `connectes` table is ephemeral online presence (5-min TTL, one row per IP)
- `admin/index.php` already has `GROUP BY ip HAVING count(*) > 1` detection
- `marche.php` blocks same-IP transfers
- Logger includes IP in all entries

What's missing:
- No login history table (can't detect IP overlap over time)
- No user-agent/fingerprint storage
- No coordinated attack pattern analysis
- No transfer pattern graphs
- No automated alerting
- No suspicion scoring

---

### Task 2.1: Create login_history table [NEW-2a]

**Files:**
- Create: `migrations/0020_create_login_history.sql`

**Step 1: Write migration**

```sql
-- Migration 0020: Login history for multi-account detection
CREATE TABLE login_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(255) NOT NULL,
    ip VARCHAR(45) NOT NULL,           -- IPv6 ready
    user_agent VARCHAR(512) DEFAULT NULL,
    fingerprint VARCHAR(64) DEFAULT NULL,  -- SHA-256 of UA+accept-language+screen
    timestamp INT NOT NULL,
    event_type ENUM('login', 'register', 'action') NOT NULL DEFAULT 'login',
    INDEX idx_login_history_login (login),
    INDEX idx_login_history_ip (ip),
    INDEX idx_login_history_fingerprint (fingerprint),
    INDEX idx_login_history_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Step 2: Also widen membre.ip**

```sql
ALTER TABLE membre MODIFY ip VARCHAR(45) NOT NULL DEFAULT '';
```

**Step 3: Commit**

```bash
git add migrations/0020_create_login_history.sql
git commit -m "db(NEW-2a): create login_history table for multi-account detection"
```

---

### Task 2.2: Create account_flags table [NEW-2b]

**Files:**
- Create: `migrations/0021_create_account_flags.sql`

**Step 1: Write migration**

```sql
-- Migration 0021: Account suspicion flags and relationships
CREATE TABLE account_flags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(255) NOT NULL,
    flag_type ENUM('same_ip', 'same_fingerprint', 'coord_attack', 'coord_transfer', 'timing_correlation', 'manual') NOT NULL,
    related_login VARCHAR(255) DEFAULT NULL,  -- the other suspected account
    evidence TEXT NOT NULL,                    -- JSON blob with detection details
    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
    status ENUM('open', 'investigating', 'confirmed', 'dismissed') NOT NULL DEFAULT 'open',
    created_at INT NOT NULL,
    resolved_at INT DEFAULT NULL,
    resolved_by VARCHAR(255) DEFAULT NULL,
    INDEX idx_flags_login (login),
    INDEX idx_flags_related (related_login),
    INDEX idx_flags_status (status),
    INDEX idx_flags_severity (severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Alert queue for admin notifications
CREATE TABLE admin_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alert_type VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    details TEXT DEFAULT NULL,       -- JSON blob
    severity ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'warning',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at INT NOT NULL,
    INDEX idx_alerts_unread (is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Step 2: Commit**

```bash
git add migrations/0021_create_account_flags.sql
git commit -m "db(NEW-2b): create account_flags and admin_alerts tables"
```

---

### Task 2.3: Implement login history logging [NEW-2c]

**Files:**
- Create: `includes/multiaccount.php`
- Modify: `includes/basicpublicphp.php` (login success path)
- Modify: `inscription.php` (registration path)

**Step 1: Create multiaccount.php with logging functions**

```php
<?php
/**
 * Multi-account detection system.
 * Logs login events, analyzes patterns, flags suspicious accounts.
 */

function logLoginEvent($base, $login, $eventType = 'login')
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $fingerprint = hash('sha256', $ua . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    $timestamp = time();

    dbExecute($base,
        'INSERT INTO login_history (login, ip, user_agent, fingerprint, timestamp, event_type) VALUES (?, ?, ?, ?, ?, ?)',
        'ssssis', $login, $ip, substr($ua, 0, 512), $fingerprint, $timestamp, $eventType
    );

    // Run detection checks asynchronously (non-blocking)
    checkSameIpAccounts($base, $login, $ip, $timestamp);
    checkSameFingerprintAccounts($base, $login, $fingerprint, $timestamp);
}

function checkSameIpAccounts($base, $login, $ip, $timestamp)
{
    // Find other accounts that logged in from this IP in the last 30 days
    $cutoff = $timestamp - (30 * 86400);
    $others = dbFetchAll($base,
        'SELECT DISTINCT login FROM login_history WHERE ip = ? AND login != ? AND timestamp > ?',
        'ssi', $ip, $login, $cutoff
    );

    foreach ($others as $other) {
        // Check if flag already exists
        $existing = dbFetchOne($base,
            'SELECT id FROM account_flags WHERE login = ? AND related_login = ? AND flag_type = "same_ip" AND status != "dismissed"',
            'ss', $login, $other['login']
        );
        if (!$existing) {
            $evidence = json_encode([
                'shared_ip' => $ip,
                'detection_time' => $timestamp,
                'login_a' => $login,
                'login_b' => $other['login']
            ]);
            dbExecute($base,
                'INSERT INTO account_flags (login, flag_type, related_login, evidence, severity, created_at) VALUES (?, "same_ip", ?, ?, "medium", ?)',
                'sssi', $login, $other['login'], $evidence, $timestamp
            );
            createAdminAlert($base, 'same_ip',
                "Comptes sur la même IP: $login et {$other['login']} ($ip)",
                $evidence, 'warning'
            );
        }
    }
}

function checkSameFingerprintAccounts($base, $login, $fingerprint, $timestamp)
{
    $cutoff = $timestamp - (30 * 86400);
    $others = dbFetchAll($base,
        'SELECT DISTINCT login FROM login_history WHERE fingerprint = ? AND login != ? AND timestamp > ?',
        'ssi', $fingerprint, $login, $cutoff
    );

    foreach ($others as $other) {
        $existing = dbFetchOne($base,
            'SELECT id FROM account_flags WHERE login = ? AND related_login = ? AND flag_type = "same_fingerprint" AND status != "dismissed"',
            'ss', $login, $other['login']
        );
        if (!$existing) {
            $evidence = json_encode([
                'shared_fingerprint' => $fingerprint,
                'detection_time' => $timestamp,
                'login_a' => $login,
                'login_b' => $other['login']
            ]);
            dbExecute($base,
                'INSERT INTO account_flags (login, flag_type, related_login, evidence, severity, created_at) VALUES (?, "same_fingerprint", ?, ?, "high", ?)',
                'sssi', $login, $other['login'], $evidence, $timestamp
            );
            createAdminAlert($base, 'same_fingerprint',
                "Même empreinte navigateur: $login et {$other['login']}",
                $evidence, 'warning'
            );
        }
    }
}

function createAdminAlert($base, $type, $message, $details, $severity = 'warning')
{
    dbExecute($base,
        'INSERT INTO admin_alerts (alert_type, message, details, severity, created_at) VALUES (?, ?, ?, ?, ?)',
        'ssssi', $type, $message, $details, $severity, time()
    );
}
```

**Step 2: Hook into login flow**

In `includes/basicpublicphp.php`, after successful login (after `session_regenerate_id`):
```php
require_once(__DIR__ . '/multiaccount.php');
logLoginEvent($base, $loginInput, 'login');
```

In `inscription.php`, after successful registration:
```php
require_once('includes/multiaccount.php');
logLoginEvent($base, $safePseudo, 'register');
```

**Step 3: Write tests**

```php
// tests/MultiaccountTest.php
class MultiaccountTest extends TestCase
{
    public function testLogLoginEventInserts() { ... }
    public function testSameIpDetection() { ... }
    public function testSameFingerprintDetection() { ... }
    public function testDuplicateFlagNotCreated() { ... }
    public function testDismissedFlagIgnored() { ... }
}
```

**Step 4: Run tests, commit**

```bash
git commit -m "feat(NEW-2c): implement login history logging with IP/fingerprint detection"
```

---

### Task 2.4: Coordinated attack detection [NEW-2d]

**Files:**
- Modify: `includes/multiaccount.php` (add attack pattern analysis)
- Modify: `includes/game_actions.php` (hook after combat resolution)

**Step 1: Add coordinated attack detection function**

```php
function checkCoordinatedAttacks($base, $attacker, $defender, $timestamp)
{
    // Find other accounts that attacked the same defender within 30 minutes
    $window = 1800; // 30 minutes
    $recentAttacks = dbFetchAll($base,
        'SELECT DISTINCT attaquant FROM actionsattaques
         WHERE defenseur = ? AND attaquant != ? AND tempsAttaque BETWEEN ? AND ?',
        'ssii', $defender, $attacker, $timestamp - $window, $timestamp + $window
    );

    foreach ($recentAttacks as $other) {
        // Check if these two accounts share IP or fingerprint
        $ipOverlap = dbFetchOne($base,
            'SELECT COUNT(*) AS cnt FROM login_history a
             INNER JOIN login_history b ON a.ip = b.ip
             WHERE a.login = ? AND b.login = ? AND a.timestamp > ?',
            'ssi', $attacker, $other['attaquant'], time() - (30 * 86400)
        );

        if ($ipOverlap && $ipOverlap['cnt'] > 0) {
            $evidence = json_encode([
                'attacker_a' => $attacker,
                'attacker_b' => $other['attaquant'],
                'defender' => $defender,
                'time_window' => $window,
                'attack_time' => $timestamp,
                'shared_ip' => true
            ]);
            dbExecute($base,
                'INSERT INTO account_flags (login, flag_type, related_login, evidence, severity, created_at) VALUES (?, "coord_attack", ?, ?, "critical", ?)',
                'sssi', $attacker, $other['attaquant'], $evidence, time()
            );
            createAdminAlert($base, 'coord_attack',
                "ALERTE: Attaque coordonnée sur $defender par $attacker et {$other['attaquant']} (même IP)",
                $evidence, 'critical'
            );
        }
    }
}
```

**Step 2: Hook into combat resolution in game_actions.php**

After combat resolution (after rapports INSERT), add:
```php
require_once(__DIR__ . '/multiaccount.php');
checkCoordinatedAttacks($base, $actions['attaquant'], $actions['defenseur'], time());
```

**Step 3: Write tests, commit**

```bash
git commit -m "feat(NEW-2d): detect coordinated attacks from same-IP accounts"
```

---

### Task 2.5: Transfer pattern detection [NEW-2e]

**Files:**
- Modify: `includes/multiaccount.php`
- Modify: `marche.php` (hook after resource send)

**Step 1: Add transfer pattern analysis**

```php
function checkTransferPatterns($base, $sender, $receiver, $timestamp)
{
    // Count transfers from sender→receiver in last 7 days
    $cutoff = $timestamp - (7 * 86400);
    $transferCount = dbFetchOne($base,
        'SELECT COUNT(*) AS cnt FROM actionsenvoi WHERE envoyeur = ? AND receveur = ? AND tempsArrivee > ?',
        'ssi', $sender, $receiver, $cutoff
    );

    // Flag if more than 5 one-way transfers in a week
    if ($transferCount && $transferCount['cnt'] >= 5) {
        // Check for reciprocity (is receiver also sending back?)
        $reverseCount = dbFetchOne($base,
            'SELECT COUNT(*) AS cnt FROM actionsenvoi WHERE envoyeur = ? AND receveur = ? AND tempsArrivee > ?',
            'ssi', $receiver, $sender, $cutoff
        );

        $ratio = $reverseCount ? $reverseCount['cnt'] : 0;
        if ($ratio < 2) { // Very one-sided — suspicious
            $evidence = json_encode([
                'sender' => $sender,
                'receiver' => $receiver,
                'transfers_7d' => $transferCount['cnt'],
                'reverse_transfers_7d' => $ratio,
                'period_start' => $cutoff,
                'period_end' => $timestamp
            ]);
            $existing = dbFetchOne($base,
                'SELECT id FROM account_flags WHERE login = ? AND related_login = ? AND flag_type = "coord_transfer" AND status != "dismissed" AND created_at > ?',
                'ssi', $sender, $receiver, $cutoff
            );
            if (!$existing) {
                dbExecute($base,
                    'INSERT INTO account_flags (login, flag_type, related_login, evidence, severity, created_at) VALUES (?, "coord_transfer", ?, ?, "high", ?)',
                    'sssi', $sender, $receiver, $evidence, $timestamp
                );
                createAdminAlert($base, 'coord_transfer',
                    "Transferts suspects: $sender → $receiver ({$transferCount['cnt']}x en 7j, quasi aucun retour)",
                    $evidence, 'warning'
                );
            }
        }
    }
}
```

**Step 2: Hook into marche.php resource send flow**

After successful resource send, add:
```php
require_once('includes/multiaccount.php');
checkTransferPatterns($base, $_SESSION['login'], $_POST['destinataire'], time());
```

**Step 3: Write tests, commit**

```bash
git commit -m "feat(NEW-2e): detect one-sided resource transfer patterns"
```

---

### Task 2.6: Login timing correlation [NEW-2f]

**Files:**
- Modify: `includes/multiaccount.php`

**Step 1: Add timing analysis (accounts that are never online simultaneously)**

```php
function checkTimingCorrelation($base, $login, $timestamp)
{
    // Get all flagged-related accounts for this login
    $related = dbFetchAll($base,
        'SELECT DISTINCT related_login FROM account_flags WHERE login = ? AND status != "dismissed"',
        's', $login
    );

    foreach ($related as $rel) {
        $other = $rel['related_login'];
        // Check overlap: did both accounts have login events within 5 min of each other?
        $overlap = dbFetchOne($base,
            'SELECT COUNT(*) AS cnt FROM login_history a
             INNER JOIN login_history b ON ABS(a.timestamp - b.timestamp) < 300
             WHERE a.login = ? AND b.login = ? AND a.timestamp > ?',
            'ssi', $login, $other, $timestamp - (30 * 86400)
        );

        // If zero overlaps in 30 days despite both being active — suspicious
        $aLogins = dbFetchOne($base, 'SELECT COUNT(*) AS cnt FROM login_history WHERE login = ? AND timestamp > ?', 'si', $login, $timestamp - (30 * 86400));
        $bLogins = dbFetchOne($base, 'SELECT COUNT(*) AS cnt FROM login_history WHERE login = ? AND timestamp > ?', 'si', $other, $timestamp - (30 * 86400));

        if ($aLogins['cnt'] > 10 && $bLogins['cnt'] > 10 && $overlap['cnt'] == 0) {
            $evidence = json_encode([
                'login_a' => $login,
                'login_b' => $other,
                'a_logins_30d' => $aLogins['cnt'],
                'b_logins_30d' => $bLogins['cnt'],
                'simultaneous_logins' => 0,
                'analysis' => 'Never online at same time despite high activity — strong multi-account indicator'
            ]);
            $existing = dbFetchOne($base,
                'SELECT id FROM account_flags WHERE login = ? AND related_login = ? AND flag_type = "timing_correlation"',
                'ss', $login, $other
            );
            if (!$existing) {
                dbExecute($base,
                    'INSERT INTO account_flags (login, flag_type, related_login, evidence, severity, created_at) VALUES (?, "timing_correlation", ?, ?, "critical", ?)',
                    'sssi', $login, $other, $evidence, $timestamp
                );
                createAdminAlert($base, 'timing_correlation',
                    "ALERTE: $login et $other jamais en ligne en même temps (multi-compte probable)",
                    $evidence, 'critical'
                );
            }
        }
    }
}
```

**Step 2: Call from logLoginEvent() after IP/fingerprint checks**

**Step 3: Write tests, commit**

```bash
git commit -m "feat(NEW-2f): detect never-simultaneous login timing correlation"
```

---

### Task 2.7: Admin anti-multiaccount dashboard [NEW-2g]

**Files:**
- Create: `admin/multiaccount.php`
- Modify: `admin/index.php` (add navigation link)

**Step 1: Create dashboard page**

The page should show:
1. **Unread alerts** — table of admin_alerts WHERE is_read=0, sorted by severity/date
2. **Open flags** — grouped by account pair, showing all flag types between them
3. **Account detail view** — click a login to see: all IPs used, all fingerprints, all transfers, all attacks, timeline visualization
4. **Actions** — buttons to: dismiss flag, confirm multi-account, ban account, merge accounts

**Step 2: Use standard admin auth (redirectionmotdepasse.php)**

**Step 3: Add email notifications for CRITICAL alerts**

```php
function sendAdminAlertEmail($subject, $body)
{
    $adminEmail = getenv('ADMIN_ALERT_EMAIL') ?: 'theverylittewar@gmail.com';
    $headers = "From: noreply@theverylittlewar.com\r\nContent-Type: text/plain; charset=UTF-8";
    mail($adminEmail, "[TVLW Alert] $subject", $body, $headers);
}
```

Call from `createAdminAlert()` when severity is 'critical'.

**Step 4: Write tests, commit**

```bash
git commit -m "feat(NEW-2g): admin multi-account dashboard with alerts and flag management"
```

---

### Task 2.8: Automated soft-responses [NEW-2h]

**Files:**
- Modify: `includes/multiaccount.php`
- Modify: `marche.php` (enhanced IP check)
- Modify: `attaquer.php` (flag check before attack)

**Step 1: Add flagged-account restrictions**

When an account pair has an OPEN or INVESTIGATING flag of severity HIGH or CRITICAL:
- Block resource transfers between them (enhance existing IP check in marche.php)
- Add warning to attack screen when attacking a player that a flagged-related account also recently attacked
- Do NOT auto-ban — leave that to admin

```php
function areFlaggedAccounts($base, $loginA, $loginB)
{
    $flag = dbFetchOne($base,
        'SELECT id FROM account_flags
         WHERE ((login = ? AND related_login = ?) OR (login = ? AND related_login = ?))
         AND status IN ("open", "investigating")
         AND severity IN ("high", "critical")',
        'ssss', $loginA, $loginB, $loginB, $loginA
    );
    return !empty($flag);
}
```

**Step 2: Hook into marche.php transfer validation**

```php
require_once('includes/multiaccount.php');
if (areFlaggedAccounts($base, $_SESSION['login'], $_POST['destinataire'])) {
    $erreur = "Transfert bloqué : les comptes sont sous surveillance pour suspicion de multi-compte.";
}
```

**Step 3: Write tests, commit**

```bash
git commit -m "feat(NEW-2h): auto-block transfers between flagged account pairs"
```

---

## Sprint 3: Sqrt Ranking & Points Rebalance — COMPLETED

**Goal:** Apply sqrt scaling to all point categories so no single activity dominates rankings.
**Source:** V4-19 (deferred), C-021 (quadratic snowball)
**Depends on:** Sprint 2 (anti-multiaccount prevents group-attack exploitation of new system)

**Completed:** All 3 tasks (3.1-3.3) implemented in 3 commits:
- config.php: 6 RANKING_* constants (weights per category + sqrt exponent)
- formulas.php: calculerTotalPoints() + recalculerTotalPointsJoueur()
- player.php: ajouterPoints() refactored to use sqrt recomputation
- marche.php: trade volume simplified to recalculate after update
- classement.php: Commerce column added, trade sortable (clas=6)
- Migration 0022: one-time recalculation + prestige.login width fix (H-045)
- regles.php: ranking explanation with category table and diversification advice
- 12 unit tests, 394 total / 2353 assertions

---

### Task 3.1: Design sqrt ranking formula [V4-19]

**Files:**
- Modify: `includes/config.php` (add ranking constants)
- Modify: `includes/formulas.php` (add calculerTotalPoints function)

**Step 1: Add ranking constants to config.php**

```php
// Sqrt ranking system (V4-19)
define('RANKING_CONSTRUCTION_WEIGHT', 1.0);
define('RANKING_ATTACK_WEIGHT', 1.5);
define('RANKING_DEFENSE_WEIGHT', 1.5);
define('RANKING_TRADE_WEIGHT', 1.0);
define('RANKING_PILLAGE_WEIGHT', 1.2);
define('RANKING_SQRT_EXPONENT', 0.5);  // sqrt = pow(x, 0.5)
```

**Step 2: Write calculerTotalPoints() in formulas.php**

```php
/**
 * Unified sqrt ranking: prevents any single activity from dominating.
 * Total = sum of (weight * sqrt(category_points)) for each category.
 */
function calculerTotalPoints($construction, $attaque, $defense, $commerce, $pillage)
{
    return round(
        RANKING_CONSTRUCTION_WEIGHT * pow(max(0, $construction), RANKING_SQRT_EXPONENT)
        + RANKING_ATTACK_WEIGHT * pow(max(0, $attaque), RANKING_SQRT_EXPONENT)
        + RANKING_DEFENSE_WEIGHT * pow(max(0, $defense), RANKING_SQRT_EXPONENT)
        + RANKING_TRADE_WEIGHT * pow(max(0, $commerce), RANKING_SQRT_EXPONENT)
        + RANKING_PILLAGE_WEIGHT * pow(max(0, $pillage), RANKING_SQRT_EXPONENT)
    );
}
```

**Step 3: Write tests**

```php
class SqrtRankingTest extends TestCase
{
    public function testPureBuilderVsBalancedPlayer()
    {
        // Pure builder: 10000 construction, 0 combat
        $builder = calculerTotalPoints(10000, 0, 0, 0, 0);
        // Balanced: 2000 each category
        $balanced = calculerTotalPoints(2000, 2000, 2000, 2000, 2000);
        // Balanced should win (sqrt rewards diversity)
        $this->assertGreaterThan($builder, $balanced);
    }

    public function testSqrtPreventsLinearSnowball()
    {
        $small = calculerTotalPoints(100, 100, 100, 100, 100);
        $big = calculerTotalPoints(10000, 10000, 10000, 10000, 10000);
        // 100x more raw points should NOT give 100x more ranking
        $ratio = $big / $small;
        $this->assertLessThan(20, $ratio); // sqrt(100x) ≈ 10x
    }
}
```

**Step 4: Run tests, commit**

```bash
git commit -m "feat(V4-19): implement sqrt ranking formula with category weights"
```

---

### Task 3.2: Integrate sqrt ranking into classement.php [V4-19]

**Files:**
- Modify: `classement.php`
- Modify: `includes/db_helpers.php` (if totalPoints is computed there)

**Step 1: Read classement.php to understand current ranking computation**

**Step 2: Replace raw additive totalPoints with calculerTotalPoints()**

The ranking page should compute scores using the new formula and display category breakdowns.

**Step 3: Update VP awarding in basicprivatephp.php to use new scores**

Season-end VP should be based on the sqrt-ranked leaderboard.

**Step 4: Run tests, commit**

```bash
git commit -m "feat(V4-19): integrate sqrt ranking into classement.php and VP awards"
```

---

### Task 3.3: Add ranking explanation to regles.php [V4-19]

**Files:**
- Modify: `regles.php`

Add a section explaining:
- Points come from 5 categories (construction, attack, defense, trade, pillage)
- Each category is sqrt-scaled — diminishing returns on specialization
- Balanced players rank higher than one-dimensional specialists
- Category weights visible

**Step 1: Write French text, commit**

```bash
git commit -m "docs(V4-19): explain sqrt ranking system in regles.php"
```

---

## Sprint 4: Espionage Notification, Display Fix & UX Polish (3-4h)

**Goal:** Add espionage alerts, fix display inconsistencies, deliver key QoL items.

---

### Task 4.1: Espionage notification to defender [V3-10]

**Source:** V3 Task 10 — Defenders have no way to know they were spied on
**Files:**
- Modify: `includes/game_actions.php` (spy resolution section)

**Step 1: Read game_actions.php spy mission section**

Find where espionage succeeds and generates the attacker's spy report.

**Step 2: Add anonymous notification to defender**

After the attacker's spy report is created, insert a defender report:
```php
$defenderReport = "Un agent inconnu a tenté d'espionner votre base. " .
    "Renforcez votre défense et restez vigilant.";
dbExecute($base,
    'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default, ?)',
    'issss', time(), "Tentative d'espionnage", $defenderReport,
    $actions['defenseur'], 'images/espion.png'
);
```

**Step 3: Write test, commit**

```bash
git commit -m "feat(V3-10): notify defender when espionage mission is performed"
```

---

### Task 4.2: Season countdown in header [QOL-004, M-029]

**Source:** QOL-004, M-029 — No season end countdown
**Files:**
- Modify: `includes/basicprivatehtml.php` (header area)
- Create: `js/countdown.js`

**Step 1: Add countdown element to the authenticated layout header**

```php
<?php
$finManche = dbFetchOne($base, "SELECT valeur FROM parametres WHERE nom = 'finManche'");
if ($finManche) {
    $secondsLeft = max(0, strtotime($finManche['valeur']) - time());
    echo '<div id="countdown" data-end="' . htmlspecialchars($finManche['valeur'], ENT_QUOTES, 'UTF-8') . '">';
    echo 'Fin de manche : <span id="countdown-timer">' . gmdate('j\j H\h', $secondsLeft) . '</span>';
    echo '</div>';
}
?>
```

**Step 2: Create js/countdown.js with live updating**

**Step 3: Commit**

```bash
git commit -m "feat(QOL-004): add season countdown timer in header"
```

---

### Task 4.3: Remaining HIGH security fixes [H-046, H-045]

**Source:** H-046 (migrations web-accessible), H-045 (prestige varchar)
**Files:**
- Create: `migrations/.htaccess`
- Create: `migrations/0022_fix_prestige_login_width.sql`

**Step 1: Block web access to migrations**

```apache
# migrations/.htaccess
Deny from all
```

**Step 2: Fix prestige.login width**

```sql
ALTER TABLE prestige MODIFY login VARCHAR(255) NOT NULL;
```

**Step 3: Commit**

```bash
git commit -m "fix(H-046, H-045): block migrations web access, fix prestige.login varchar width"
```

---

## Sprint 5: Resource Nodes on Map (4-6h)

**Goal:** Add 15-25 resource nodes to the game map that boost nearby player production.
**Source:** V3-19

---

### Task 5.1: Create resource_nodes table [V3-19a]

**Files:**
- Create: `migrations/0023_create_resource_nodes.sql`

```sql
CREATE TABLE resource_nodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    x INT NOT NULL,
    y INT NOT NULL,
    resource_type ENUM('carbone','azote','hydrogene','oxygene','chlore','soufre','brome','iode','energie') NOT NULL,
    bonus_pct DECIMAL(5,2) NOT NULL DEFAULT 10.00,  -- % production bonus
    radius INT NOT NULL DEFAULT 5,                    -- effect radius in map tiles
    active TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_nodes_coords (x, y),
    INDEX idx_nodes_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### Task 5.2: Generate nodes on season reset [V3-19b]

**Files:**
- Modify: `includes/player.php` (inside remiseAZero — regenerate nodes)
- Create: `includes/resource_nodes.php`

Generate 15-25 nodes at random positions, ensuring minimum distance between nodes and from map edges.

---

### Task 5.3: Apply proximity bonuses to production [V3-19c]

**Files:**
- Modify: `includes/game_resources.php` (revenuAtome — check nearby nodes)

For each resource, check if the player is within radius of a matching node and apply the bonus percentage.

---

### Task 5.4: Display nodes on map [V3-19d]

**Files:**
- Modify: `carte.php` or map display file
- Add node markers with tooltips showing resource type and bonus

---

### Task 5.5: Add to bilan.php [V3-19e]

**Files:**
- Modify: `bilan.php` (add "Nœuds de Ressources" section showing nearby node bonuses)

---

## Sprint 6: Compound Synthesis Lab (6-8h)

**Goal:** Add a laboratory building where players craft consumable compounds from atoms.
**Source:** V3-20
**Depends on:** Sprint 5 (resource nodes provide atoms needed for synthesis)

---

### Task 6.1: Design compound system [V3-20a]

**Files:**
- Modify: `includes/config.php` (add $COMPOUNDS array)

```php
$COMPOUNDS = [
    'H2O' => [
        'name' => 'Eau',
        'recipe' => ['hydrogene' => 2, 'oxygene' => 1],
        'effect' => 'production_boost',
        'effect_value' => 0.10,  // +10% all production for 1h
        'duration' => 3600,
        'description' => "+10% production pendant 1h"
    ],
    'NaCl' => [
        'name' => 'Sel',
        'recipe' => ['chlore' => 1, 'soufre' => 1],  // simplified
        'effect' => 'defense_boost',
        'effect_value' => 0.15,
        'duration' => 3600,
        'description' => "+15% défense pendant 1h"
    ],
    'CO2' => [
        'name' => 'Dioxyde de Carbone',
        'recipe' => ['carbone' => 1, 'oxygene' => 2],
        'effect' => 'attack_boost',
        'effect_value' => 0.10,
        'duration' => 3600,
        'description' => "+10% attaque pendant 1h"
    ],
    'NH3' => [
        'name' => 'Ammoniac',
        'recipe' => ['azote' => 1, 'hydrogene' => 3],
        'effect' => 'speed_boost',
        'effect_value' => 0.20,
        'duration' => 3600,
        'description' => "+20% vitesse pendant 1h"
    ],
    'H2SO4' => [
        'name' => 'Acide Sulfurique',
        'recipe' => ['hydrogene' => 2, 'soufre' => 1, 'oxygene' => 4],
        'effect' => 'pillage_boost',
        'effect_value' => 0.25,
        'duration' => 3600,
        'description' => "+25% pillage pendant 1h"
    ],
];
```

---

### Task 6.2: Create synthesis tables [V3-20b]

**Files:**
- Create: `migrations/0024_create_compounds.sql`

```sql
CREATE TABLE player_compounds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(255) NOT NULL,
    compound_key VARCHAR(20) NOT NULL,
    activated_at INT DEFAULT NULL,      -- NULL = stored, non-NULL = active
    expires_at INT DEFAULT NULL,
    INDEX idx_compounds_login (login),
    INDEX idx_compounds_active (login, expires_at),
    CONSTRAINT fk_compounds_login FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### Task 6.3: Create laboratoire.php page [V3-20c]

**Files:**
- Create: `laboratoire.php`
- Modify: `includes/basicprivatehtml.php` (add menu link)

Page shows available compounds, recipes, current stock, and "Synthétiser" buttons.

---

### Task 6.4: Apply active compound effects [V3-20d]

**Files:**
- Modify: `includes/game_resources.php` (check active compounds for production boosts)
- Modify: `includes/combat.php` (check active compounds for combat boosts)
- Modify: `bilan.php` (show active compounds section)

---

## Sprint 7: Balance Tuning — Batch G (2-3h)

**Goal:** Adjust game balance based on playtesting data.
**Source:** C-021, C-024, H-008, H-009, H-010, H-053
**Depends on:** Live playtesting data after Sprint 0-4 deploy

---

### Task 7.1: Soufre pillage divisor [H-008]

**Files:**
- Modify: `includes/formulas.php` (pillage function)

The `PILLAGE_SOUFRE_DIVISOR` constant exists (=2) but is NOT used in the V4 `pillage()` function. Decision needed: apply it or remove it. If soufre pillage is confirmed weak via playtesting, apply the divisor.

---

### Task 7.2: Chlore speed curve [H-009]

**Files:**
- Modify: `includes/formulas.php` (vitesse function)

Currently hardcoded `$Cl * 0.5`. If chlore is confirmed too dominant, add a soft cap:
```php
$clContrib = min(SPEED_SOFT_CAP, $Cl * SPEED_ATOM_COEFFICIENT);
```

---

### Task 7.3: Formation rebalance [H-010]

**Files:**
- Modify: `includes/config.php` (FORMATION_* constants)

Adjust Phalanx absorb (currently 0.60), Ambush attack bonus (0.15), Dispersée dodge (0.10) based on combat logs showing which formation wins most.

---

### Task 7.4: Building destruction targeting [C-024]

**Files:**
- Modify: `includes/combat.php:448-478`

Replace `rand(1,4)` with weighted targeting based on building levels:
```php
$weights = [];
foreach ($damageable_buildings as $b) {
    $weights[$b] = max(1, $buildingLevels[$b]); // Higher level = higher target priority
}
$target = weightedRandom($weights);
```

---

### Task 7.5: Ionisateur damageable [H-053]

**Files:**
- Modify: `includes/combat.php` (add ionisateur to damageable buildings list)

Currently only generateur/champdeforce/producteur/depot can be damaged. Add ionisateur with same HP as other offensive buildings.

---

## Appendix A: Remaining Remediation Items NOT in Sprints

These items are tracked but deferred to future work. They are all LOW/MEDIUM priority and do not block launch.

### MEDIUM — Schema & Data Quality (deferred to post-launch)
- M-003: Alliance creation not atomic
- M-004: Alliance join not atomic
- M-008: Pact accept check-then-act
- M-039: declarations alliance1/2 VARCHAR→INT
- M-040: totalPoints has no index
- M-041: DOUBLE columns for resources (documented limitation)
- M-042: Serialized VARCHAR arrays unqueryable (documented limitation)
- M-043: cours table no TTL cleanup
- M-044: vacances idJoueur inconsistent
- M-045: sanctions VARCHAR(30) width

### MEDIUM — UX Polish (deferred to post-launch)
- M-010: Espionage mission fragile LIKE pattern
- M-012: No bottleneck identification in build cost
- M-014: Attack cost calculator doesn't update on clear
- M-015: Incoming attack no time estimate
- M-017: No alliance donation goal mechanism
- M-018: Research effects hidden in accordion
- M-021: No war/pact buttons on alliance view
- M-023: No post-sale price preview
- M-024: In-transit no resource breakdown
- M-030: Password change no current email shown
- M-031: Vacation mode no benefit explanation
- M-032: Isotope system never displayed
- M-033: Chemical reactions defined but never activate (removed in V4)
- M-034: Specialization system has no UI
- M-035: Map renders all N×N tiles
- M-054: 45+ hardcoded French strings
- M-055: 76 inline script blocks need extraction (CSP nonce handles this)
- M-057: Army overview requires ?sub=1 with no button
- M-058: Buy form field no label

### LOW (all deferred)
- L-001 through L-025 minus 7 already fixed: tutorial duplicates, UI placement, missing labels, mobile layout, jQuery fallback, non-breaking spaces, schema polish

### QoL (selected items addressable in future sprints)
- QOL-003: Specialization system UI
- QOL-005: Navigation badges
- QOL-008: Alliance message system
- QOL-009: Isotope display system
- QOL-010: Attack cost preview
- QOL-011: Army tab switcher
- QOL-012: Vacation mode explanation
- QOL-013: Alliance research bonuses visible
- QOL-014: Password change shows email
- QOL-016-020: Build bottleneck, in-transit, post-sale preview, map optimization, new features

---

## Appendix B: Seasonal Round Themes [V3-21] — EXCLUDED

Per user request, seasonal round themes (Accéléré, Pénurie, Guerre, Diplomatique, Classique) are excluded from this plan. They can be designed separately after the core systems are stable.

---

## Execution Order Summary

```
Week 1:  Sprint 0 (cleanup/security) + Sprint 1 (VPS/DNS/HTTPS)
Week 2:  Sprint 2 (anti-multiaccount) + Sprint 4 (espionage/UX)
Week 3:  Sprint 3 (sqrt ranking)
Week 4:  Sprint 5 (resource nodes)
Week 5:  Sprint 6 (compound synthesis)
Ongoing: Sprint 7 (balance tuning based on live data)
```

**Total estimated: 30-40 hours across 5-6 sessions**
