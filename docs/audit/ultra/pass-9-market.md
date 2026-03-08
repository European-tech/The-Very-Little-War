# Pass-9 Market System Audit

**Date:** 2026-03-08
**Scope:** marche.php, includes/game_actions.php (actionsenvoi delivery), includes/game_resources.php (market GC)
**Auditor:** Narrow-domain security audit agent

---

## Audit Methodology

All ten enumerated domains were inspected by reading the full source of `marche.php` (769 lines), the relevant sections of `game_actions.php`, `game_resources.php`, `config.php`, `rate_limiter.php`, and `constantesBase.php`.

---

## Findings

---

### MKT-P9-001

**ID:** MKT-P9-001
**Severity:** MEDIUM
**File:** marche.php:214,346
**Domain:** Rate limiting — combined buy+sell cap

**Description:**
`market_buy` and `market_sell` use two *separate* rate-limit action keys, each with `RATE_LIMIT_MARKET_MAX = 30` per 60-second window. A player can therefore execute 30 buys + 30 sells = **60 market operations per minute**, which is double the intended cap. The config comment reads "Max buy/sell actions per window," implying a combined limit of 30 total. The separate keys make the effective limit 60, allowing sustained automated price manipulation at twice the expected rate.

**Recommended fix:** Use a single shared key (e.g., `'market_action'`) for both buy and sell paths, or halve `RATE_LIMIT_MARKET_MAX` to 15 per path.

---

### MKT-P9-002

**ID:** MKT-P9-002
**Severity:** MEDIUM
**File:** marche.php:25–209
**Domain:** Rate limiting — resource transfer (envoi) path has no rate limit

**Description:**
The resource-transfer-to-player path (triggered when `$_POST['energieEnvoyee']` and `$_POST['destinataire']` are set) includes a CSRF check (line 26) but **no `rateLimitCheck()` call**. An authenticated player can hammer this endpoint in a tight loop, creating many `actionsenvoi` rows and saturating the delivery queue or the `actionsenvoi` table. This also affects multi-account detection latency (the pattern check runs after the insert, not before).

**Recommended fix:** Add a `rateLimitCheck($_SESSION['login'], 'market_transfer', 10, 60)` guard immediately after the `csrfCheck()` call on line 26.

---

### MKT-P9-003

**ID:** MKT-P9-003
**Severity:** LOW
**File:** marche.php:742
**Domain:** Stored XSS — unescaped DB data echoed into JavaScript

**Description:**
Line 742 writes `$cours['tableauCours']` directly into the JavaScript `arrayToDataTable()` call with no sanitization:

```php
$tot = '["' . date('d/m H\hi', $cours['timestamp']) . '",' . $cours['tableauCours'] . ']' . $fin . $tot;
```

The `tableauCours` column is populated exclusively by server-side PHP arithmetic (lines 296 and 446 via `INSERT INTO cours VALUES (default,?,?)` where `$chaine` is built from floats). No user input ever enters this column. However, if the DB were ever compromised or if a future code path allowed non-numeric data to enter `tableauCours`, this would be a stored XSS vector. The current risk is LOW because the trust boundary is narrow (server-only writes), but defence-in-depth requires sanitization.

**Recommended fix:** Validate each entry with `floatval()` after `explode()` before constructing `$tot`, or use `json_encode()` to emit the row safely.

---

### MKT-P9-004

**ID:** MKT-P9-004
**Severity:** LOW
**File:** marche.php:7
**Domain:** Stale volatility snapshot used inside transaction

**Description:**
`$volatilite` is computed once at page load (line 7) from a `COUNT(*)` of active members and is then captured by value into both the buy (line 247 `use` clause) and sell (line 381 `use` clause) transaction closures. Under concurrent load, the active-player count can change between page load and transaction execution. This means the volatility value applied inside the atomic transaction reflects a potentially stale snapshot. For a high-traffic burst, two players loading the page simultaneously get the same `$volatilite` and independently apply full-strength price impact, causing double the intended price movement for a given trade volume. This is a correctness issue rather than a strict security vulnerability, but it means the price impact formula can be gamed by timing requests before other players reduce the count.

**Recommended fix:** Move the `COUNT(*)` query for `$actifs` inside the transaction closure alongside the `cours FOR UPDATE` lock so that the volatility divisor is always consistent with the locked price row.

---

### MKT-P9-005

**ID:** MKT-P9-005
**Severity:** INFO
**File:** marche.php:261,396
**Domain:** `array_slice` truncation of `txTabCours`

**Description:**
Both buy and sell paths slice `$txTabCours` to `$nbRes` elements (lines 261 and 396) before iterating. `$nbRes` is defined as `count($RESOURCE_NAMES) - 1 = 7` (indices 0–7 for 8 atom types). If the stored `tableauCours` string ever has fewer than 8 comma-separated values (e.g., after a failed partial write or schema migration), `array_slice` silently returns fewer elements and the `foreach` loop emits a shortened price row. The next `INSERT INTO cours` stores a malformed string, which corrupts all subsequent market reads. There is no length assertion after the slice.

**Recommended fix:** After `array_slice`, assert `count($txTabCours) === $nbRes` and throw an exception if the count is wrong, to prevent cascading corruption.

---

## Verified Clean

The following ten domains from the audit specification were checked and found **clean**:

| # | Domain | Status |
|---|--------|--------|
| 1 | Price manipulation (0 or negative price) | Clean — `max(1, (int)round(...))` on buy; `max(MARKET_PRICE_FLOOR, ...)` on price floor; quantity validated `> 0` |
| 2 | Transaction atomicity | Clean — both buy and sell use `withTransaction()` wrapping all resource mutations atomically |
| 3 | Storage cap bypass | Clean — depot level re-read with `FOR UPDATE` inside transaction; cap enforced via `$placeDepotTx` |
| 4 | CSRF on all market forms | Clean — `csrfCheck()` present on buy (line 212), sell (line 344), and transfer (line 26) |
| 4b | Order ownership (cancel another player's order) | N/A — this market has no resting limit orders; buy/sell are immediate spot trades |
| 5 | Volatility → negative or overflow price | Clean — sell uses reciprocal formula `1/(1/price + delta)` which converges toward 0 but never reaches it; result clamped to `MARKET_PRICE_FLOOR`; buy uses additive formula clamped to `[FLOOR, CEILING]` |
| 6 | SQL injection | Clean — all queries use `dbExecute()` prepared statements; resource type validated against `$nomsRes` whitelist |
| 7 | Rate limiting enforced | Partially clean — buy and sell both rate-limited (see MKT-P9-001/002); transfer path unprotected |
| 8 | Integer truncation before DB write | Clean — `$_POST['nombreRessourceAAcheter']` reassigned via `intval()` at line 218; superglobal accessible inside closure without re-capture; `$actualSold` computed from `(int)min(...)` |
| 9 | Global market slippage formula | Clean — slippage formula is per-resource, symmetric (buy: additive; sell: reciprocal), mean-reverts toward 1.0, capped at floor/ceiling; no overflow possible |
| 10 | tradeVolume FOR UPDATE fix | Clean — `UPDATE autre SET tradeVolume = LEAST(tradeVolume + ?, ?) WHERE login=?` is fully atomic; no separate SELECT+UPDATE; cap enforced inline |

---

## Summary

| Finding | Severity | Domain |
|---------|----------|--------|
| MKT-P9-001 | MEDIUM | Combined buy+sell rate limit doubles to 60 ops/min |
| MKT-P9-002 | MEDIUM | Transfer (envoi) path has no rate limit |
| MKT-P9-003 | LOW | Unsanitized `tableauCours` in JS chart output |
| MKT-P9-004 | LOW | Stale volatility snapshot used inside transaction |
| MKT-P9-005 | INFO | No length assertion on `array_slice` of `txTabCours` |

FINDINGS: 0 critical, 0 high, 2 medium, 2 low, 1 info
