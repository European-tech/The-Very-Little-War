# Security Audit — Pass 9: Anti-Multiaccount System
**Date:** 2026-03-08
**Auditor:** Narrow-domain security agent
**Scope:** includes/multiaccount.php, admin/multiaccount.php, moderation/index.php (IP tab), moderation/ip.php, admin/ip.php, admin/index.php (IP section), marche.php (areFlaggedAccounts usage), inscription.php, includes/basicpublicphp.php, migrations/0020–0021, cron/cleanup-logs.sh

---

## Findings

---

### MULTI-P9-001
**Severity:** HIGH
**File:** `includes/multiaccount.php:22` / `migrations/0020_create_login_history.sql:7`
**Title:** Raw IP address stored in plaintext in `login_history` table

**Description:**
`logLoginEvent()` captures `$_SERVER['REMOTE_ADDR']` and stores it verbatim in `login_history.ip VARCHAR(45)`. The `membre.ip` column is likewise updated with the raw IP on every login (`includes/basicpublicphp.php:69`). Under GDPR, an IP address is personal data. Storing it in plaintext creates a disclosure risk if the database is exfiltrated and means every SELECT that returns `ip` leaks the address. The evidence JSON stored in `account_flags` contains only a 12-character prefix of a salted SHA-256 hash, but the underlying `login_history` table still holds the original value and can be queried at any time to recover the full IP.

**Recommended fix:** Replace plaintext IP storage with a keyed HMAC (e.g. `hash_hmac('sha256', $ip, SECRET_SALT)`) — store only the hash; detection comparisons remain exact-match and work identically, but the raw address is never persisted.

---

### MULTI-P9-002
**Severity:** HIGH
**File:** `includes/multiaccount.php:54` / `includes/config.php:20`
**Title:** IP pseudonymization salt is a hardcoded constant — reversible by brute-force

**Description:**
The 12-character display hash written into `account_flags.evidence` is derived as `sha256($ip . SECRET_SALT)` truncated to 12 hex chars, where `SECRET_SALT = 'tvlw_audit_2026'` is a public constant in `includes/config.php`. The IPv4 address space is only ~4 billion entries, and the salt is known to any attacker who reads the source. A determined attacker can precompute a full rainbow table over the IPv4 space in minutes and recover the real IP from any 12-character prefix. The pseudonymization therefore provides no practical protection.

Additionally, there are two different fallback strings used when `SECRET_SALT` is not defined — `'tvlw_salt'` (line 54) and `'tvlw'` (line 69) — producing inconsistent hashes for the same IP address in logging vs. evidence.

**Recommended fix:** Generate a random per-deployment secret via `openssl_random_pseudo_bytes(32)`, store it in a `.env` file (never in the codebase), and load it at runtime; also unify the two fallback strings.

---

### MULTI-P9-003
**Severity:** HIGH
**File:** `moderation/ip.php:1-4` / `moderation/mdp.php:1-14`
**Title:** `moderation/ip.php` includes `mdp.php` for auth but `mdp.php` does NOT check `mod_ip` binding before displaying content — IP binding check occurs after the output could have started

**Description:**
`moderation/ip.php` delegates its authentication guard to `mdp.php`. In `mdp.php` the IP-binding check (`hash_equals($_SESSION['mod_ip'], $currentIp)`) calls `session_destroy()` and `header('Location: ...')` on mismatch, but at the point `ip.php` is loaded, `include("../includes/connexion.php")` (line 16) and `$ip = $_GET['ip']` (line 17) have already been evaluated in `ip.php` — these lines run before any output but could emit warnings/notices that break the redirect. More critically, `ip.php` has no `exit` after the `include("mdp.php")` call. If `mdp.php` issues a redirect for an unauthenticated user, PHP will continue executing the rest of `ip.php` unless the response is flushed and the script exits. The guard in `mdp.php` does use `exit()` explicitly, so the immediate risk is low, but the structural pattern is fragile.

**Recommended fix:** Convert `ip.php` to use the same `include("redirectionmotdepasse.php")` / `require_once` pattern used by all other moderation pages, placing the auth guard as the very first statement with an explicit `exit` path.

---

### MULTI-P9-004
**Severity:** MEDIUM
**File:** `admin/multiaccount.php:260` (login_history detail view)
**Title:** Raw IP addresses displayed to admin in the account detail view

**Description:**
In the account detail sub-panel rendered when `?login=<account>` is set, the "Historique de connexion" table displays `login_history.ip` verbatim (line 260: `htmlspecialchars($lh['ip'])`). The XSS encoding is correct, but the semantic issue is that the raw IP is presented to anyone with admin credentials. If the admin account is compromised (e.g. via session theft), the attacker gains access to the full IP history of all investigated players. The moderation panel at `moderation/index.php?sub=2` and `admin/index.php` (lines 139–151) both also display raw IPs as clickable hyperlinks.

**Recommended fix:** Display only the salted hash (first 12 chars) of each IP in the UI; add a separate "Reveal IP" action gated by a secondary confirmation that logs the reveal event in the moderation audit trail.

---

### MULTI-P9-005
**Severity:** MEDIUM
**File:** `includes/multiaccount.php:40-72`
**Title:** Shared-IP detection fires on every login, generating duplicate flags for the same pair

**Description:**
`checkSameIpAccounts()` deduplicates flags with a SELECT before INSERT, but the deduplication query at line 49 only checks that no non-dismissed flag already exists for `(login, related_login, 'same_ip')`. It does NOT check the reverse pair `(related_login, login)`. This means that when A logs in, a flag (A→B) is inserted. When B logs in, `checkSameIpAccounts()` for B finds A, checks for a flag `(B, A, same_ip)` — which does not yet exist — and inserts a second flag (B→A). The two players end up with two separate flags and two `admin_alerts` messages for the same shared IP. Over a 30-day window with N logins per player, this can produce O(N) duplicate alert records per pair.

**Recommended fix:** In the deduplication query, additionally check the reverse pair with `OR (login = ? AND related_login = ?)`, or canonicalize the pair by always storing the lexicographically smaller login as `login`.

---

### MULTI-P9-006
**Severity:** MEDIUM
**File:** `includes/multiaccount.php:20-35`
**Title:** `logLoginEvent()` is called on every login — no rate cap on `login_history` inserts

**Description:**
There is no maximum row cap or TTL enforcement on the `login_history` table. Each successful login inserts one row unconditionally. A single player who logs in 1000 times per day (e.g. via a bot, or combined with the absence of per-request session-lock enforcement) generates 1000 rows per day. With 200 active players and an average of 5 logins/day, the table grows at ~1000 rows/day — roughly 365,000 rows/year. At higher attack volumes this can grow orders of magnitude faster. The cleanup cron (`cron/cleanup-logs.sh`) deletes only filesystem log files and does NOT prune `login_history` DB rows. There is no scheduled SQL purge of rows older than 30 days.

**Recommended fix:** Add a cron job (or a probabilistic GC call inside `logLoginEvent`) that executes `DELETE FROM login_history WHERE timestamp < UNIX_TIMESTAMP() - 2592000` (30 days), and add a maximum insert rate guard (e.g. skip logging if the same login already has a row within the last 60 seconds with the same IP and fingerprint).

---

### MULTI-P9-007
**Severity:** MEDIUM
**File:** `includes/multiaccount.php:22`
**Title:** No X-Forwarded-For / proxy header handling — NAT bypass possible, but also no spoofing protection documented

**Description:**
`logLoginEvent()` reads only `$_SERVER['REMOTE_ADDR']`. This is correct behaviour on a VPS where Apache terminates connections directly — it prevents X-Forwarded-For spoofing. However, the system is also deployed behind Apache with no reverse proxy, so shared-NAT users (e.g. a family, university network, or corporate environment) will all share a single `REMOTE_ADDR`. Combined with the `same_ip` flag firing at severity=medium for any two accounts sharing an IP, this creates false positives for legitimate players on the same network. The current detection does not distinguish between "same household" and "same device" signals.

On the other side, if a reverse proxy is ever placed in front of Apache (e.g. Cloudflare, nginx), `REMOTE_ADDR` will permanently become the proxy IP, collapsing all players onto one address and generating massive false positives. There is no `trusted_proxies` configuration or safe X-Forwarded-For extraction.

**Recommended fix:** Document the current assumption (direct-connect only, no reverse proxy) in `config.php`; add a `TRUSTED_PROXY_IPS` constant (empty by default) that, when set, enables safe extraction of the real IP from X-Forwarded-For with strict CIDR validation.

---

### MULTI-P9-008
**Severity:** MEDIUM
**File:** `includes/multiaccount.php:210-257`
**Title:** Timing-correlation check has high false-positive rate for low-activity or new accounts

**Description:**
`checkTimingCorrelation()` flags two accounts as a probable multi-account pair when: (a) they already share another flag, (b) each has more than 10 logins in 30 days, and (c) they have zero simultaneous login windows (within ±5 minutes of each other). Condition (b) provides some noise resistance, but the ±5-minute overlap window is very narrow for players who log in infrequently. Two legitimate players who simply have different play-time schedules (one plays mornings, one plays nights) will never appear simultaneously, generating a false CRITICAL flag. Because `checkTimingCorrelation` is triggered on every login for every related flagged account, and because the deduplication check for `timing_correlation` (line 233) does NOT filter out `dismissed` status, a previously dismissed timing flag can be re-opened if the conditions are re-met after dismissal.

**Recommended fix:** Widen the simultaneous window to at least 15 minutes; raise the minimum login count threshold from 10 to 20; and add `AND status != 'dismissed'` to the deduplication query at line 233.

---

### MULTI-P9-009
**Severity:** MEDIUM
**File:** `admin/multiaccount.php:36` (`resolved_by` field)
**Title:** `resolved_by` is hardcoded to the string `'admin'` — no audit trail of which admin acted

**Description:**
When a flag is confirmed or dismissed, the `resolved_by` column is set to the literal string `'admin'` regardless of which administrator performed the action (line 36). Since there is only a single shared admin account in the current design, this is not an immediate operational problem, but it means the audit trail cannot distinguish actions taken in different sessions or by different people if the admin password is ever shared. In combination with the missing session identity (no admin `login` in `$_SESSION`), it is impossible to trace individual moderation decisions.

**Recommended fix:** Store a session identifier or timestamp-keyed token in `resolved_by` (e.g. `'admin_' . substr(session_id(), 0, 8)`) to provide at least minimal session tracing until proper multi-admin support is added.

---

### MULTI-P9-010
**Severity:** LOW
**File:** `marche.php:41`
**Title:** IP-equality check uses `!=` operator on strings fetched from DB — does not account for IPv6 normalisation

**Description:**
`marche.php` fetches two IP strings from `membre.ip` and blocks transfers when `$ipmm['ip'] != $ipdd['ip']` (i.e. it ALLOWS transfers only when IPs differ, but blocks them when they match — which is the intended behavior). The comparison is a raw PHP string equality check. IPv6 addresses can be represented in multiple canonical forms (e.g. `::1` vs `0:0:0:0:0:0:0:1`, or `2001:db8::1` vs `2001:0db8:0000:0000:0000:0000:0000:0001`). Two accounts connecting from the same IPv6 host but with differently-normalized address strings would bypass the block. PHP's `inet_pton()` / `inet_ntop()` can normalize before comparison.

**Recommended fix:** Normalize both IP strings with `inet_ntop(inet_pton($ip))` before comparison, or perform the comparison in SQL using `INET6_ATON()`.

---

### MULTI-P9-011
**Severity:** LOW
**File:** `includes/multiaccount.php:291-294` (`sendAdminAlertEmail`)
**Title:** Admin alert email sends plain `$body` including player usernames without sanitization

**Description:**
The `$message` passed to `sendAdminAlertEmail()` includes interpolated login names from DB (e.g. `"Comptes sur la même IP: $login et {$other['login']} ($ipDisplay)"`). These logins are validated on registration to match `[a-zA-Z0-9_-]{1,20}` so injection risk is negligible, but the function uses PHP's `mail()` with `@` error suppression, silently swallowing delivery failures. There is no delivery queue or retry mechanism, so critical alerts on high-load periods may be silently lost.

**Recommended fix:** Remove `@` suppression, log `mail()` failures via `logWarn()`, and consider queuing critical emails in `email_queue` (migration 0038) for retry.

---

### MULTI-P9-012
**Severity:** LOW
**File:** `includes/multiaccount.php:113-156`
**Title:** `checkCoordinatedAttacks()` is never called from game code

**Description:**
`checkCoordinatedAttacks()` is defined in `multiaccount.php` but a search of all PHP files in the project reveals no call site outside of documentation and tests. The function is intended to be called from attack processing code (e.g. `attaquer.php` or `game_actions.php`), but is not wired up. Coordinated attack detection — arguably the highest-value heuristic — is therefore dead code in production.

**Recommended fix:** Call `checkCoordinatedAttacks($base, $attacker, $defender, time())` at the point where a successful attack is recorded in `actionsattaques`.

---

### MULTI-P9-013
**Severity:** LOW
**File:** `includes/multiaccount.php:161-204`
**Title:** `checkTransferPatterns()` is never called from game code

**Description:**
Same problem as MULTI-P9-012. `checkTransferPatterns()` is defined but has no call site in production PHP files. One-sided resource flow detection is inert.

**Recommended fix:** Call `checkTransferPatterns($base, $sender, $receiver, time())` at the end of a successful `actionsenvoi` INSERT in `envoi.php` or `game_actions.php`.

---

### MULTI-P9-014
**Severity:** INFO
**File:** `admin/multiaccount.php` (entire file)
**Title:** Admin authorization is correctly enforced

**Description:**
`admin/multiaccount.php` begins with `include("redirectionmotdepasse.php")` which checks `$_SESSION['motdepasseadmin'] === true`, enforces an idle timeout via `SESSION_IDLE_TIMEOUT`, and validates IP binding via `hash_equals($_SESSION['admin_ip'], REMOTE_ADDR)`. All POST actions are protected by `csrfCheck()`. No SQL injection vectors were found — all queries use prepared statements via `dbExecute`/`dbFetchAll`. Output is consistently escaped with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`. No issues found in this area.

---

### MULTI-P9-015
**Severity:** INFO
**File:** `includes/multiaccount.php:43-46`
**Title:** All detection queries use prepared statements — no SQL injection

**Description:**
Every query in `multiaccount.php` uses the `dbFetchAll`/`dbFetchOne`/`dbExecute` helpers with parameterized placeholders. No string concatenation is used in query construction. No SQL injection vectors identified.

---

## Summary Table

| ID | Severity | Title |
|----|----------|-------|
| MULTI-P9-001 | HIGH | Plaintext IP stored in login_history (GDPR) |
| MULTI-P9-002 | HIGH | Pseudonymization salt hardcoded in source — rainbow-table reversible |
| MULTI-P9-003 | HIGH | Fragile auth guard structure in moderation/ip.php |
| MULTI-P9-004 | MEDIUM | Raw IP displayed to admin in detail view |
| MULTI-P9-005 | MEDIUM | Duplicate flags generated for same pair (reverse-pair dedup missing) |
| MULTI-P9-006 | MEDIUM | No login_history row cap or scheduled purge — unbounded table growth |
| MULTI-P9-007 | MEDIUM | No reverse-proxy / trusted-proxy IP extraction — NAT false positives |
| MULTI-P9-008 | MEDIUM | Timing-correlation check: narrow window and dismissed flags re-opened |
| MULTI-P9-009 | MEDIUM | resolved_by hardcoded 'admin' — no per-session audit trail |
| MULTI-P9-010 | LOW | IPv6 normalisation not applied in marche.php IP equality check |
| MULTI-P9-011 | LOW | mail() failures silently suppressed — critical alerts may be lost |
| MULTI-P9-012 | LOW | checkCoordinatedAttacks() defined but never called in production |
| MULTI-P9-013 | LOW | checkTransferPatterns() defined but never called in production |
| MULTI-P9-014 | INFO | Admin authorization correctly enforced (no issues) |
| MULTI-P9-015 | INFO | All queries use prepared statements (no SQL injection) |

---

FINDINGS: 0 critical, 3 high, 6 medium, 4 low
