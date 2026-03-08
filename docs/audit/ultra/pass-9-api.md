# Ultra Audit Pass 9 — API Endpoint Security Review

**Date:** 2026-03-08
**Auditor:** Narrow-domain security agent (API focus)
**Target file:** `api.php`
**Supporting files reviewed:** `includes/session_init.php`, `includes/csrf.php`, `includes/rate_limiter.php`, `includes/database.php`, `includes/connexion.php`, `includes/formulas.php`, `includes/catalyst.php`, `includes/db_helpers.php`, `includes/fonctions.php`, `includes/config.php`, `includes/layout.php`

---

## Domain Checklist

| # | Domain | Status |
|---|--------|--------|
| 1 | Authentication — session required for all endpoints | PASS (with note) |
| 2 | Authorization — player can only access their own data | PASS |
| 3 | CSRF — token required or by design irrelevant | FINDING (LOW) |
| 4 | SQL injection in dynamic queries | PASS |
| 5 | Output escaping — json_encode used throughout | FINDING (MEDIUM) |
| 6 | Rate limiting — RATE_LIMIT_MARKET applied to market calls | FINDING (INFO) |
| 7 | Input validation — type + range on all parameters | FINDING (MEDIUM) |
| 8 | Error messages — internal info leakage | PASS |
| 9 | Method checking — GET vs POST for mutating endpoints | N/A (read-only) |
| 10 | Dispatch table — 404 for unknown actions | PASS |

---

## Findings

---

### API-P9-001
**Severity:** MEDIUM
**File:line:** `api.php:50-53`
**Title:** No range validation on numeric parameters — negative `nombre` causes NaN propagation and a silent broken JSON response

**Description:**
All four numeric parameters (`nombre`, `nombre2`, `niveau`, `nbTotalAtomes`) are sanitized with `intval()`, which correctly prevents string injection and ensures an integer type. However, no lower or upper bounds are enforced. The game formulas use `pow($nombre, COVALENT_BASE_EXPONENT)` where `COVALENT_BASE_EXPONENT = 1.2` (a non-integer exponent). In PHP, `pow(negative_int, 1.2)` returns `NAN` (not a number). When any formula handler returns `NAN` or `INF`, `json_encode()` returns `false` (PHP error code `JSON_ERROR_INF_OR_NAN = 7`), and `echo false` emits an empty body. The client receives a 200 OK response with a `Content-Type: application/json` header but an empty body, which causes a JSON parse error in the calling JavaScript (`JSON.parse` throws). This produces silent broken UI without any server-side log entry or error HTTP status.

Affected handlers when `nombre < 0`: `attaque`, `defense`, `pointsDeVieMolecule`, `potentielDestruction`, `pillage`, `vitesse`, `productionEnergieMolecule`, `tempsFormation`.

Verification:
```php
php -r "var_dump(json_encode(['valeur' => pow(-1, 1.2)]));"
// bool(false)  — empty output, JSON_ERROR_INF_OR_NAN
```

Note: `niveau` and `nbTotalAtomes` being negative do not cause NaN because they are used only in linear arithmetic, but absurdly large values (e.g., `niveau = 2147483647`) will produce numerically enormous but technically valid responses, which is a minor concern.

**Recommended fix:** Clamp all four parameters to their valid game ranges immediately after `intval()`: `nombre` and `nombre2` to `[0, MAX_ATOMS_PER_ELEMENT]`, `niveau` to `[0, MAX_BUILDING_LEVEL]`, and `nbTotalAtomes` to `[0, 8 * MAX_ATOMS_PER_ELEMENT]`.

---

### API-P9-002
**Severity:** MEDIUM
**File:line:** `api.php:106`
**Title:** `json_encode` failure produces empty 200 body with no error status or log entry

**Description:**
Line 106 is `echo json_encode(['valeur' => $dispatch[$id]()]);`. If `json_encode` returns `false` for any reason (NaN/INF result from a formula — see API-P9-001, or in future if a handler returns a resource type), the `echo` outputs an empty string. The HTTP status code remains 200 OK, no error is logged, and no `json_last_error()` check is performed. The client-side JavaScript in `layout.php` will silently fail its `JSON.parse(data)` call, resulting in stale/broken stat previews in the molecule editor UI. This is distinct from API-P9-001 in that it is a systemic missing guard that would catch any future `json_encode` failure, not just the NaN case.

**Recommended fix:** After line 106, check `json_last_error() !== JSON_ERROR_NONE` and emit a 500 JSON error response with `error_log()` if encoding failed.

---

### API-P9-003
**Severity:** LOW
**File:line:** `api.php:1-107` (entire file)
**Title:** CSRF token not checked — endpoint is GET-only but performs DB writes indirectly via catalyst rotation

**Description:**
`api.php` does not require or validate a CSRF token. The API is designed as a read-only preview endpoint (GET-only, no explicit state mutation). However, the call chain `api.php` → `fonctions.php` → `catalyst.php` → `getActiveCatalyst()` contains an implicit `UPDATE statistiques SET catalyst=?, catalyst_week=?` write at line 72 of `catalyst.php`. This is a weekly rotation and is idempotent (same result for the same week), so the CSRF risk is negligible in practice. However, any authenticated user's browser can be caused to trigger this write by a cross-site GET request (e.g., `<img src="https://theverylittlewar.com/api.php?id=attaque&nombre=1&nombre2=1&niveau=1">`). Since the write is idempotent and low-value, the severity is LOW, not CRITICAL. The more pressing concern is that if new write-capable endpoints are ever added to the dispatch table without a method check, there is no CSRF defense layer at the API boundary.

**Recommended fix:** Either add `X-Requested-With: XMLHttpRequest` header verification (already used in `csrf.php` for AJAX detection) as a CSRF substitute for this read-oriented API, or add a defense-in-depth comment to the dispatch table warning future developers that any mutating handler must be protected before deployment.

---

### API-P9-004
**Severity:** LOW
**File:line:** `api.php:36`
**Title:** Rate limiting uses raw `REMOTE_ADDR` — bypassable behind a proxy without `X-Forwarded-For` normalization

**Description:**
```php
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
```
`REMOTE_ADDR` is the correct choice when the server is not behind a reverse proxy, and it is not spoofable. However, the MEMORY.md indicates the VPS may be accessed via a load balancer or CDN in the future. If a reverse proxy is ever added in front of Apache without configuring `mod_remoteip` (to rewrite `REMOTE_ADDR` from `X-Forwarded-For`), all requests will present the same IP (the proxy's IP), and all users will share a single rate limit bucket, causing legitimate users to be locked out while attackers can also bypass the limit by sharing quota with others. This is an infrastructure-deployment risk rather than a current exploit.

**Recommended fix:** Document in `rate_limiter.php` that `REMOTE_ADDR` must be the real client IP; if a reverse proxy is added, configure Apache `mod_remoteip` with a trusted proxy list rather than trusting `HTTP_X_FORWARDED_FOR` from the application layer.

---

### API-P9-005
**Severity:** INFO
**File:line:** `api.php:37`, `includes/config.php:646`
**Title:** API rate limit (60 req/60s) is a separate bucket from `RATE_LIMIT_MARKET_*` — market-related API calls are not subject to the market rate limit

**Description:**
`config.php` defines `RATE_LIMIT_MARKET_MAX = 30` and `RATE_LIMIT_MARKET_WINDOW = 60`. The API uses its own bucket: `rateLimitCheck($ip, 'api', 60, 60)`. This is intentional and correct — the API serves formula previews, not market transactions, so the 60 req/60s limit is more permissive. Market buy/sell actions happen through separate PHP pages (`marche.php`, etc.), not through `api.php`. No market endpoint exists in the dispatch table. This is noted for completeness and to confirm the audit checklist item 6: the market rate limit is correctly not applied to this endpoint because the endpoint does not perform market operations.

**Recommended fix:** None required. Add a code comment on line 37 of `api.php` noting that market operations are handled separately and are rate-limited in their own pages.

---

### API-P9-006
**Severity:** INFO
**File:line:** `api.php:97`
**Title:** `$_GET['id']` assignment uses no type normalization — dispatch lookup is correct but could be documented

**Description:**
```php
$id = isset($_GET['id']) ? $_GET['id'] : '';
```
The value is immediately checked against `isset($dispatch[$id])`. Since `$dispatch` is a fixed PHP array with string keys, any non-matching string (including numeric strings, unicode, or empty string) will fall through to the 400 error path. This is correct and safe. The null coalescing operator (`$id = $_GET['id'] ?? ''`) would be marginally cleaner. No security issue.

**Recommended fix:** No action required; optionally replace with `??` for readability.

---

## Summary Table

| ID | Severity | Title |
|----|----------|-------|
| API-P9-001 | MEDIUM | Negative `nombre` → `pow(neg, 1.2)` = NAN → silent empty JSON response |
| API-P9-002 | MEDIUM | Missing `json_encode` failure guard — empty 200 on encoding error |
| API-P9-003 | LOW | No CSRF token check; catalyst rotation is an implicit side-effect write |
| API-P9-004 | LOW | Rate limiting on `REMOTE_ADDR` — bypassable if reverse proxy added without `mod_remoteip` |
| API-P9-005 | INFO | Market rate limit bucket not applied here — by design, confirmed correct |
| API-P9-006 | INFO | Minor code style note on `$id` assignment |

---

## Positive Security Properties Confirmed

- **Authentication:** Session validation on every request; `$_SESSION['login']` verified against `session_token` column in DB via `hash_equals()` — replay-resistant.
- **Authorization:** `$joueur` is forced to `$_SESSION['login']` (line 47); no client-supplied player identity is honored; all DB queries use this session-bound value.
- **SQL injection:** All DB calls use `dbFetchOne` / `dbExecute` with prepared statements and `?` placeholders. The only column interpolations (`allianceResearchLevel`, `allianceResearchBonus`) are guarded by a hard-coded column whitelist (`ALLIANCE_RESEARCH_COLUMNS`).
- **Output encoding:** `json_encode` is used for all output; no raw string interpolation into the JSON response body.
- **Dispatch table:** Unknown `id` values return HTTP 400 with a generic error message — no fallthrough, no default execution, no information leakage.
- **Error messages:** All error responses use generic strings (`Authentication required`, `Session invalid`, `Rate limit exceeded`, `Invalid action`) — no stack traces, no SQL details, no file paths.
- **Method checking:** All endpoints are read-only (compute formulas, return a number); no state mutation in handlers; no need for POST enforcement.
- **Rate limiting:** 60 requests per 60 seconds per IP using a file-based sliding window; fail-safe: if the rate-limit directory cannot be created, access is denied (not granted).
- **Session security:** `session_init.php` sets `httponly`, `samesite=Lax`, `use_strict_mode`, `use_only_cookies`, `use_trans_sid=0` — comprehensive session hardening.

---

FINDINGS: 0 critical, 0 high, 2 medium, 2 low, 2 info
