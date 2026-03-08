# Pass 9 — Espionage System Security Audit

**Date:** 2026-03-08
**Auditor:** Narrow-domain security agent
**Scope:** attaquer.php (espionage POST branch), includes/game_actions.php (espionage resolution), rapports.php (report display), includes/csrf.php, includes/rate_limiter.php

---

## Findings

---

### SPY-P9-001

**ID:** SPY-P9-001
**Severity:** INFO
**File:** attaquer.php:21
**Description:** CSRF protection is present and correct. The espionage POST block calls `csrfCheck()` at line 21 as the very first action, before any input is read or state mutated. The CSRF token is a 64-hex `random_bytes(32)` value stored in `$_SESSION['csrf_token']` and compared with `hash_equals()`. The form includes `csrfField()` (line 638 in the espionage form). No bypass is possible.
**Status:** PASS — no action required.

---

### SPY-P9-002

**ID:** SPY-P9-002
**Severity:** INFO
**File:** attaquer.php:28, basicprivatephp.php:19-25
**Description:** Session validation is thorough. `basicprivatephp.php` requires both `$_SESSION['login']` and `$_SESSION['session_token']` to be set, then re-fetches `session_token` from the `membre` table and compares with `hash_equals()`. The session is destroyed on any mismatch. An idle-timeout check (line 13) additionally invalidates sessions after `SESSION_IDLE_TIMEOUT` seconds. Self-spy is explicitly blocked at attaquer.php:28 (`$_POST['joueurAEspionner'] != $_SESSION['login']`).
**Status:** PASS — no action required.

---

### SPY-P9-003

**ID:** SPY-P9-003
**Severity:** INFO
**File:** attaquer.php:30,43, game_actions.php:350,363,365,367
**Description:** All espionage queries use prepared statements with `dbFetchOne`/`dbFetchAll`/`dbExecute` wrappers that bind parameters via mysqli. The target login (`$_POST['joueurAEspionner']`) and neutrino count (`intval($_POST['nombreneutrinos'])`) are bound as `'s'` and `'i'` types respectively. No SQL injection vector exists in the espionage path.
**Status:** PASS — no action required.

---

### SPY-P9-004

**ID:** SPY-P9-004
**Severity:** MEDIUM
**File:** game_actions.php:349-476
**Description:** **Race condition — duplicate espionage resolution (no CAS guard).** The combat resolution path for regular attacks uses a Compare-And-Swap guard at line 119: `UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0`. If `affected == 0`, the concurrent request skips processing. The espionage resolution branch (lines 349-476) has NO equivalent CAS guard. The transaction wrapping the `INSERT INTO rapports ... DELETE FROM actionsattaques` block (line 465) prevents a partial write, but two concurrent calls to `updateActions()` — each triggered when both the attacker and defender load a page simultaneously — can both pass the `$actions['attaqueFaite'] == 0` check (line 94) before either transaction commits, read the defender's data, and both attempt the `INSERT/DELETE` inside `withTransaction`. The `DELETE` in the second transaction will affect 0 rows (the row is already gone) but will not throw, so that transaction commits silently — resulting in two espionage reports delivered to the attacker for a single mission.
**Recommended fix:** Add the same CAS guard used for combat: open the `withTransaction` with `UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0`, check `affected_rows`, and throw `cas_skip` if 0.

---

### SPY-P9-005

**ID:** SPY-P9-005
**Severity:** INFO
**File:** attaquer.php:53-63
**Description:** Neutrino cost is deducted atomically. The espionage launch is wrapped in `withTransaction` (line 54). Inside the closure, the attacker's neutrino row is locked with `SELECT ... FOR UPDATE` (line 55), the balance is re-validated against `$_POST['nombreneutrinos']` (line 56), and the `INSERT` into `actionsattaques` plus the `UPDATE autre SET neutrinos` are performed in the same transaction (lines 59-62). A `RuntimeException('NOT_ENOUGH_NEUTRINOS')` is thrown and caught if the balance changed between page load and submit.
**Status:** PASS — no action required.

---

### SPY-P9-006

**ID:** SPY-P9-006
**Severity:** LOW
**File:** game_actions.php:350
**Description:** **Defender neutrino fetch uses no lock during resolution.** When an espionage action is resolved, the defender's current neutrino count is fetched at line 350 with a plain `SELECT neutrinos FROM autre WHERE login=?` (no `FOR UPDATE`). Between this read and the threshold comparison at line 360, the defender's neutrino count could increase (e.g., a neutrino formation completing). This means the threshold used for the success/fail decision is a snapshot that may already be stale by microseconds. In practice, the window is negligible and the impact is bounded (a spy mission that should barely fail might succeed, or vice versa), but it is a logical inconsistency.
**Recommended fix:** Add `FOR UPDATE` to the neutrino fetch at line 350, or perform the threshold read inside the `withTransaction` closure that creates the report.

---

### SPY-P9-007

**ID:** SPY-P9-007
**Severity:** LOW
**File:** game_actions.php:458-459
**Description:** **Failed spy report leaks threshold logic.** When espionage fails, the report body (line 459) states: "Votre espionnage a raté, vous avez envoyé moins de la moitié des neutrinos de votre adversaire." This confirms to the attacker both that a ratio-based threshold exists and implicitly the direction (they sent less than half). The defender's absolute neutrino count is not revealed. The severity is LOW because the threshold ratio is documented in the game rules, so this is not truly hidden information. However, a subtle timing variant exists: the response time for a successful spy mission is measurably longer (it fetches `molecules`, `ressources`, and `constructions` tables) versus a failed one (immediate). An attacker timing many missions could use response-time differences to probe whether the defender has more or fewer neutrinos relative to their sent count.
**Recommended fix:** Normalize response time for success/failure branches by always performing the same set of DB reads regardless of outcome (discard results on failure), or add a constant-time sleep.

---

### SPY-P9-008

**ID:** SPY-P9-008
**Severity:** INFO
**File:** attaquer.php:23
**Description:** Rate limiting is present. The espionage POST branch calls `rateLimitCheck('espionage_' . $_SESSION['login'], 'espionage', ESPIONAGE_RATE_LIMIT, ESPIONAGE_RATE_WINDOW)` before any other logic. Constants are defined in config.php: `ESPIONAGE_RATE_LIMIT = 5` and `ESPIONAGE_RATE_WINDOW = 60` (5 per minute per player). The rate limiter uses file-based sliding-window counting with `LOCK_EX` file writes.
**Status:** PASS — no action required.

---

### SPY-P9-009

**ID:** SPY-P9-009
**Severity:** MEDIUM
**File:** game_actions.php:378, display.php:57-67
**Description:** **`couleurFormule()` outputs unescaped DB content into the espionage report HTML.** The function at display.php:57 takes the molecule `formule` field (e.g., "C<sub>2</sub>H<sub>4</sub>") and applies `preg_replace` to add `<span>` color tags. It returns the result without `htmlspecialchars`. The `formule` field is written by armee.php using server-constructed strings built from `$lettre[$num]` (static atom letter constants: C, N, H, O, Cl, S, Br, I) and `intval($_POST[$ressource])` subscript values — so the stored value cannot contain arbitrary HTML injected by a user. However, the function does not escape the input before running regex on it, and no `htmlspecialchars` call wraps the output in the espionage report at line 378. If any future code path writes a formula containing `<script>` or `<img onerror=...>` to the DB (e.g., a migration bug or admin tool), it would render unescaped in the spy report. The existing strip_tags/preg_replace sanitization in rapports.php (lines 33-37) provides a partial backstop: `strip_tags` with an allowlist removes unknown tags and event handlers are stripped by regex — but the allowlist includes `<span>` and `<img>`, and the `style` attribute stripping regex at line 36 might miss some edge cases (e.g., `style` on `<span>` elements generated by `couleurFormule`).
**Recommended fix:** Wrap `couleurFormule()`'s input with `htmlspecialchars($espClass['formule'], ENT_QUOTES, 'UTF-8')` before passing to the function, since the regex patterns only match known atom letter sequences and won't be disrupted by HTML-encoding of the formula string.

---

### SPY-P9-010

**ID:** SPY-P9-010
**Severity:** INFO
**File:** rapports.php:20,32-37
**Description:** Report content sanitization in rapports.php is layered and adequate. The espionage report body is stored as server-generated HTML. On display (line 33), `strip_tags` with an allowlist is applied, followed by regex stripping of `on*` event handlers (lines 35-36) and `javascript:` hrefs (line 37). The report title is passed through `htmlspecialchars()` in the list view (line 119) and in `debutCarte()` (line 28). Defender login in the title is also `htmlspecialchars`-encoded at generation time (game_actions.php:369). The report can only be fetched by its owner (destinataire = $_SESSION['login'] verified on line 20).
**Status:** PASS — defense-in-depth is present, though SPY-P9-009 notes the formula field warrants additional escaping at generation time.

---

### SPY-P9-011

**ID:** SPY-P9-011
**Severity:** INFO
**File:** attaquer.php:31-32
**Description:** Invalid target login is properly handled. The espionage path fetches `SELECT vacance,timestamp FROM membre WHERE login=?` (line 30). If the target does not exist, `$espTarget` is `false`/null and the `if (!$espTarget)` branch at line 31 sets `$erreur = "Ce joueur n'existe pas."` without leaking any internal state. No SQL injection is possible (prepared statement) and no difference in error message between "invalid login format" and "valid but non-existent login" is present.
**Status:** PASS — no action required.

---

### SPY-P9-012

**ID:** SPY-P9-012
**Severity:** LOW
**File:** attaquer.php:41
**Description:** **Neutrino count upper bound validated only against cached session data.** The form field `nombreneutrinos` is validated at line 41 as `<= $autre['neutrinos']`. The `$autre` variable is populated from `initPlayer()` called in `basicprivatephp.php`, which caches results for the current request. However, a player could open two browser tabs, submit espionage from both nearly simultaneously, and both passes line 41 before either `FOR UPDATE` transaction commits. The transaction at lines 53-63 re-validates with `SELECT neutrinos FROM autre WHERE login=? FOR UPDATE` (line 55), which is the correct guard. The pre-transaction check at line 41 is therefore a UX convenience (early rejection) not a security boundary — the actual integrity enforcement is in the transaction.
**Status:** INFO — the TOCTOU is mitigated correctly by the transaction. Documented for clarity.

---

### SPY-P9-013

**ID:** SPY-P9-013
**Severity:** LOW
**File:** attaquer.php:290
**Description:** **Espionage missions visible to the defender in the action list.** The query at line 290 fetches all `actionsattaques` rows where `attaquant=? OR defenseur=?` and renders them in the UI. The espionage rows (troupes='Espionnage') are filtered out from the *defender* view in the display loop (line 343: `if ($actionsattaques['troupes'] != 'Espionnage' && ...)`), so the defender does not see incoming spy missions listed. However, the *count* query at line 287 uses `troupes!=?` with 'Espionnage' to exclude spy missions from the count shown in the card header. This is correct. No information leakage to the defender occurs.
**Status:** PASS — no action required.

---

### SPY-P9-014

**ID:** SPY-P9-014
**Severity:** LOW
**File:** game_actions.php:465-476
**Description:** **Espionage resolution transaction lacks a CAS pre-check, allowing double-report on very unlikely rollback.** This is the resolution-side complement to SPY-P9-004. Even if the CAS guard is added (as recommended), the current transaction structure builds the full HTML report *outside* the transaction (lines 363-456) and only the `INSERT/DELETE` is inside `withTransaction`. If the outer `withTransaction` itself is retried due to a deadlock (MariaDB can retry on deadlock with auto-retry enabled), the report content is already computed; only the commit is retried, which is safe. However, if the attacker or defender is deleted between lines 363-465, the report could contain NULL values from the `ressources` or `constructions` fetch (e.g., `$ressourcesJoueur` could be false, causing `$ressourcesJoueur[$ressource]` to generate a PHP warning). The defender deletion case at line 351 catches deletion of the `autre` row, but does not catch deletion of the `ressources` or `constructions` rows.
**Recommended fix:** Add null-checks on `$ressourcesJoueur` and `$constructionsJoueur` before accessing their fields, and set a safe fallback message if either is missing.

---

### SPY-P9-015

**ID:** SPY-P9-015
**Severity:** INFO
**File:** attaquer.php:35
**Description:** Beginner protection and comeback shield are consistently applied to espionage targets. Line 35 applies the same `BEGINNER_PROTECTION_SECONDS + veteran prestige extension` formula used for combat. Line 38 checks `hasActiveShield()` for the comeback shield. Both checks use fresh DB fetches (line 30) rather than stale cached data.
**Status:** PASS — no action required.

---

## Summary

| ID | Severity | Subject |
|---|---|---|
| SPY-P9-001 | INFO | CSRF — PASS |
| SPY-P9-002 | INFO | Session auth / self-spy — PASS |
| SPY-P9-003 | INFO | SQL injection — PASS |
| SPY-P9-004 | MEDIUM | Race: no CAS guard in espionage resolution (double-report possible) |
| SPY-P9-005 | INFO | Atomic cost deduction — PASS |
| SPY-P9-006 | LOW | Defender neutrino fetch not locked during resolution |
| SPY-P9-007 | LOW | Timing side-channel leaks success/fail branch |
| SPY-P9-008 | INFO | Rate limiting — PASS |
| SPY-P9-009 | MEDIUM | couleurFormule output unescaped in report HTML (formula XSS latent risk) |
| SPY-P9-010 | INFO | Report sanitization in rapports.php — PASS |
| SPY-P9-011 | INFO | Invalid target login — PASS |
| SPY-P9-012 | LOW | Pre-tx neutrino bound check uses cached data (TOCTOU mitigated by tx) |
| SPY-P9-013 | INFO | Espionage actions not visible to defender — PASS |
| SPY-P9-014 | LOW | NULL-dereference if target deleted between data-read and report-write |
| SPY-P9-015 | INFO | Beginner/shield protection applied to espionage — PASS |

FINDINGS: 0 critical, 0 high, 2 medium, 4 low
