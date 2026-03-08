# Pass 9 Remediation Plan — Completeness Review (Reviewer A)

**Date:** 2026-03-08
**Reviewer:** Reviewer A (Completeness)
**Scope:** All 16 domain audit reports vs. the remediation plan at `docs/plans/2026-03-08-pass-9-remediation.md`
**Method:** Cross-reference every HIGH and MEDIUM finding in every domain report against (a) the Already-Fixed table, (b) a named batch item in the plan.

---

## 1. Methodology

For each of the 16 domain reports I extracted every finding with severity CRITICAL, HIGH, or MEDIUM. I then verified whether each finding is:

- **Already-Fixed** — appears in the plan's "Already Fixed" table with a matching description.
- **In a batch** — has a corresponding `P9-HIGH-*` or `P9-MED-*` item with a concrete file path and fix description.
- **Missing** — absent from both lists (the key concern of this review).

LOW/INFO gaps are explicitly out of scope; I checked them opportunistically but do not report them as blockers.

---

## 2. Finding-by-Finding Cross-Reference

### API domain (2 MEDIUM)

| Finding | Severity | Plan disposition |
|---------|----------|-----------------|
| API-P9-001 | MEDIUM | Already-Fixed table: "api.php params clamped to [0, max]" |
| API-P9-002 | MEDIUM | Already-Fixed table: "api.php json_last_error() guard added" |

Both MEDIUM findings accounted for.

---

### Buildings domain (1 MEDIUM)

| Finding | Severity | Plan disposition |
|---------|----------|-----------------|
| BLDG-P9-011 | MEDIUM | Batch 6, P9-MED-010: "Max-level check falls back to stale outer-scope snapshot" — file `constructions.php:303-307`, fix described concretely. |

MEDIUM finding accounted for.

---

### Email domain (2 HIGH, 4 MEDIUM)

| Finding | Severity | Plan disposition |
|---------|----------|-----------------|
| EMAIL-P9-001 | HIGH | Already-Fixed table: "player.php CRLF sanitization on recipient — now added" |
| EMAIL-P9-002 | HIGH | Already-Fixed table: "Hotmail EOL regex case-insensitive + outlook.com added — now fixed" |
| EMAIL-P9-003 | MEDIUM | Batch 3, P9-HIGH-003 (plan labels it HIGH because it covers header/body injection) — `includes/multiaccount.php:282,294`, fix described. |
| EMAIL-P9-004 | MEDIUM | Batch 3, P9-HIGH-004 — `includes/basicprivatephp.php:247-250`, fix described. |
| EMAIL-P9-005 | MEDIUM | Batch 3, P9-HIGH-005 — migration `0039_email_queue_utf8.sql`, fix described. |
| EMAIL-P9-006 | MEDIUM | Batch 3, P9-MED-004 — `includes/player.php:1200-1261` + migration `0040_email_queue_retry.sql`, fix described. |

All HIGH and MEDIUM email findings accounted for.

**Observation (not a blocking gap):** The plan escalates EMAIL-P9-003, EMAIL-P9-004, and EMAIL-P9-005 from MEDIUM (as labeled in the audit) to HIGH in its quick-reference table. This is conservative and acceptable — it does not drop severity.

---

### Espionage domain (2 MEDIUM)

| Finding | Severity | Plan disposition |
|---------|----------|-----------------|
| SPY-P9-004 | MEDIUM | Batch 5, P9-MED-008: "No CAS guard in espionage resolution" — `includes/game_actions.php:349-476`, concrete fix with CAS guard code. |
| SPY-P9-009 | MEDIUM | **NOT FOUND in plan.** |

**GAP IDENTIFIED: SPY-P9-009 is missing from the remediation plan.**

SPY-P9-009 is a MEDIUM finding: `couleurFormule()` output is unescaped in the espionage report HTML — latent XSS if any code path ever writes unsafe formula content to the DB. The fix is to wrap `couleurFormule()`'s input with `htmlspecialchars()` before passing it. This finding appears nowhere in the Already-Fixed table, and no `P9-MED-*` item covers it.

---

### Forum domain (3 HIGH, 5 MEDIUM)

| Finding | Severity | Plan disposition |
|---------|----------|-----------------|
| FORUM-P9-001 | HIGH | Already-Fixed table has "LaTeX denylist extended — now done" (FORUM-P9-003) and Batch 4 P9-HIGH-006 covers both FORUM-P9-001 and FORUM-P9-008 (hardcoded DB ID). The plan notes "FORUM-P9-003 remaining — ALREADY-FIXED". Both HIGH aspects addressed. |
| FORUM-P9-002 | HIGH | Already-Fixed table: "bbcode.php [url] length cap — now done (with MSG-P9-001)". |
| FORUM-P9-003 | HIGH | Already-Fixed table: "bbcode.php LaTeX denylist extended — now done". |
| FORUM-P9-004 | MEDIUM | Already-Fixed table: "editer.php form action hardcoded to editer.php — now done". |
| FORUM-P9-005 | MEDIUM | Batch 4, P9-MED-005: "Whitespace-only posts bypass !empty()" — `sujet.php:48-49`, `listesujets.php:77`, fix with `trim()` + `maxlength`. |
| FORUM-P9-006 | MEDIUM | Batch 4, P9-MED-006: "Unbounded SELECT * FROM sanctions" — `moderationForum.php:90`, fix with LIMIT + date filter. |
| FORUM-P9-007 | MEDIUM | Audit report itself WITHDRAWS this finding (redirect already present at lines 45/54). Not needed in plan. |
| FORUM-P9-008 | MEDIUM | Merged into P9-HIGH-006 in Batch 4. Covered. |
| FORUM-P9-009 | MEDIUM | Batch 4, P9-MED-007: "Moderator edit bypasses alliance-private forum access control" — `editer.php:89-121`, fix described. |

All forum HIGH and MEDIUM findings accounted for (FORUM-P9-007 correctly withdrawn).

---

### Infra domain (5 HIGH, 4 MEDIUM)

| Finding | Severity | Plan disposition |
|---------|----------|-----------------|
| INFRA-P9-001 | HIGH | Already-Fixed. |
| INFRA-P9-002 | HIGH | Already-Fixed. |
| INFRA-P9-003 | HIGH | Already-Fixed. |
| INFRA-P9-004 | HIGH | Already-Fixed. |
| INFRA-P9-005 | HIGH | Batch 2, P9-HIGH-002: "composer.phar / phpunit.phar in web root" — concrete fix to move outside web root. |
| INFRA-P9-006 | MEDIUM | Batch 2, P9-MED-002: "mod_php.c block skipped on PHP-FPM" — FPM pool config fix described. |
| INFRA-P9-007 | MEDIUM | Already-Fixed: ".env.example FilesMatch pattern — now updated". |
| INFRA-P9-008 | MEDIUM | Already-Fixed: "migrations/migrate.php CLI guard — now added". |
| INFRA-P9-009 | MEDIUM | Batch 2, P9-MED-003: "No composer audit or static analysis in CI" — `.github/workflows/ci.yml` fix described. |

All infra HIGH and MEDIUM findings accounted for.

---

### Lab domain (4 MEDIUM)

| Finding | Severity | Plan disposition |
|---------|----------|-----------------|
| LAB-P9-001 | MEDIUM | Batch 7, P9-MED-011: "Dynamic column interpolation in UPDATE — whitelist only in pre-check loop" — `includes/compounds.php:100`, fix described. |
| LAB-P9-002 | MEDIUM | Batch 7, P9-MED-012: "countStoredCompounds() has FOR UPDATE outside transaction" — `includes/compounds.php:45`, fix described. |
| LAB-P9-003 | MEDIUM | Batch 7, P9-MED-013: "No rate limiting on synthesis/activation" — `laboratoire.php:7-28`, fix described. |
| LAB-P9-004 | MEDIUM | Batch 7, P9-MED-014: "Hardcoded 86400 in GC cleanup" — `includes/compounds.php:244-245`, fix described. |

All lab MEDIUM findings accounted for.

---

### Map domain (3 MEDIUM)

| Finding | Severity | Plan disposition |
|---------|----------|-----------------|
| MAP-P9-001 | MEDIUM | Batch 8, P9-MED-015: "GET x/y not clamped to map bounds" — `attaquer.php:386-396`, fix described. |
| MAP-P9-002 | MEDIUM | Batch 8, P9-MED-016: "Players with coords >= tailleCarte cause undefined-offset" — `attaquer.php:434`, `includes/player.php:780`, fix described. |
| MAP-P9-009 | MEDIUM | Batch 8, P9-MED-017: "Inactive sentinel players included in map query" — `attaquer.php:402`, fix described. |

All map MEDIUM findings accounted for.

---

### Market domain (2 MEDIUM)

| Finding | Severity | Plan disposition |
|---------|----------|-----------------|
| MKT-P9-001 | MEDIUM | Already-Fixed: "marche.php market_buy/sell unified to market_op key". |
| MKT-P9-002 | MEDIUM | Already-Fixed: "marche.php transfer rate limit (10/60) — now added". |

All market MEDIUM findings accounted for.

**Note:** MKT-P9-003 (LOW, tableauCours raw in JS) is in Batch 13 as P9-MED-029 — the plan escalated it to MEDIUM from the LOW the audit assigned. This is conservative and acceptable.

---

### Messages domain (1 HIGH, 3 MEDIUM)

| Finding | Severity | Plan disposition |
|---------|----------|-----------------|
| MSG-P9-001 | HIGH | Already-Fixed: "[url] domain indicator + length cap + null byte strip — now in bbcode.php". |
| MSG-P9-002 | MEDIUM | Batch 9, P9-MED-018: "Raw DB content passed to creerBBcode()" — `ecriremessage.php:130`, fix described. |
| MSG-P9-003 | MEDIUM | Batch 9, P9-MED-019: "No self-messaging guard" — `ecriremessage.php`, fix described. |
| MSG-P9-004 | MEDIUM | Batch 9, P9-MED-020: "No HTML maxlength on titre/contenu inputs" — `ecriremessage.php:118,140`, fix described. |

All messages HIGH and MEDIUM findings accounted for.

---

### Multiaccount domain (3 HIGH, 6 MEDIUM)

| Finding | Severity | Plan disposition |
|---------|----------|-----------------|
| MULTI-P9-001 | HIGH | Batch 10, P9-HIGH-007 (merged with REG-P9-003): "Plaintext IP in DB (GDPR)" — `includes/multiaccount.php:22`, `includes/player.php:72`, migration `0041_hash_ip_columns.sql`, fix described. |
| MULTI-P9-002 | HIGH | Batch 10, P9-HIGH-008: "Salt hardcoded — rainbow-table reversible" — `includes/config.php:20`, `includes/multiaccount.php:54,69`, fix described. |
| MULTI-P9-003 | HIGH | Batch 10, P9-HIGH-009: "Fragile auth guard in moderation/ip.php" — `moderation/ip.php:1-4`, fix described. |
| MULTI-P9-004 | MEDIUM | **NOT FOUND in plan.** |
| MULTI-P9-005 | MEDIUM | Batch 10, P9-MED-021: "Duplicate flags for same pair" — `includes/multiaccount.php:49`, fix described. |
| MULTI-P9-006 | MEDIUM | Batch 10, P9-MED-022: "login_history grows unbounded" — `includes/multiaccount.php:20-35` + cron, fix described. |
| MULTI-P9-007 | MEDIUM | **NOT FOUND in plan.** |
| MULTI-P9-008 | MEDIUM | Batch 10, P9-MED-023: "Timing-correlation narrow window; dismissed flags re-opened" — `includes/multiaccount.php:210-257`, fix described. |
| MULTI-P9-009 | MEDIUM | Batch 10, P9-MED-024: "resolved_by hardcoded 'admin'" — `admin/multiaccount.php:36`, fix described. |

**GAP IDENTIFIED: MULTI-P9-004 is missing from the remediation plan.**

MULTI-P9-004 (MEDIUM): Raw IP addresses displayed to admin in the account detail view at `admin/multiaccount.php:260`. This is a MEDIUM finding (not the same as MULTI-P9-001 which covers *storage*; this covers *display*). It is not mentioned in the Already-Fixed table and no `P9-MED-*` item covers the admin UI display of raw IPs.

**GAP IDENTIFIED: MULTI-P9-007 is missing from the remediation plan.**

MULTI-P9-007 (MEDIUM): No reverse-proxy / trusted-proxy IP extraction — NAT false positives, and no `TRUSTED_PROXY_IPS` constant. The audit recommendation is to document the current assumption in `config.php` and add a `TRUSTED_PROXY_IPS` constant. This finding is not in the Already-Fixed table and no `P9-MED-*` item covers it.

---

### Prestige domain (0 HIGH, 0 MEDIUM)

The prestige audit produced 0 HIGH, 0 MEDIUM findings — only LOW and INFO. Nothing to check at this severity level.

---

### Ranking domain (1 MEDIUM)

| Finding | Severity | Plan disposition |
|---------|----------|-----------------|
| RANK-P9-001 | MEDIUM | Already-Fixed: "classement.php CSRF check added on search POST — now done". |

MEDIUM finding accounted for.

---

### Registration domain (4 MEDIUM)

| Finding | Severity | Plan disposition |
|---------|----------|-----------------|
| REG-P9-001 | MEDIUM | Batch 11, P9-MED-025: "Password max-length not enforced in comptetest.php" — `comptetest.php:54`, fix described. |
| REG-P9-002 | MEDIUM | Batch 11, P9-MED-026: "Underscore in login breaks comptetest.php validation" — `comptetest.php:61`, fix described. |
| REG-P9-003 | MEDIUM | Merged into Batch 10, P9-HIGH-007 (plaintext IP). Covered. |
| REG-P9-004 | MEDIUM | **NOT FOUND in plan.** |
| REG-P9-005 | MEDIUM | Batch 11, P9-MED-027: "No duplicate email check in comptetest.php" — `comptetest.php:62-68`, fix described. |

**GAP IDENTIFIED: REG-P9-004 is missing from the remediation plan.**

REG-P9-004 (MEDIUM): No CAPTCHA or bot-protection on registration forms. The audit explicitly labels this MEDIUM. Neither `inscription.php` nor `comptetest.php` has any challenge-response mechanism. The audit recommends a honeypot field or proof-of-work challenge. This finding does not appear in the Already-Fixed table and no `P9-MED-*` item covers it. The plan may have intentionally deferred this (CAPTCHA integration is a larger feature), but it is not acknowledged anywhere in the plan, which is a completeness gap.

---

### Season domain (1 MEDIUM)

| Finding | Severity | Plan disposition |
|---------|----------|-----------------|
| SEASON-P9-001 | MEDIUM | Batch 11, P9-MED-028: "email_queue not purged during season reset" — `includes/player.php:1263`, fix described. |

MEDIUM finding accounted for.

**Note:** SEASON-P9-003 (LOW — recipient not sanitized before mail()) is noted in the plan's Deduplication Notes as "overlaps with EMAIL-P9-001 (already-fixed)". This is correct — the EMAIL fix covers recipient CRLF sanitization.

---

### Voter domain (1 CRITICAL, 2 HIGH, 3 MEDIUM)

| Finding | Severity | Plan disposition |
|---------|----------|-----------------|
| VOTE-P9-001 | CRITICAL | Already-Fixed. |
| VOTE-P9-002 | HIGH | Already-Fixed. |
| VOTE-P9-003 | HIGH | Already-Fixed. |
| VOTE-P9-004 | MEDIUM | Already-Fixed: "INSERT IGNORE for first-vote race — now applied". |
| VOTE-P9-005 | MEDIUM | Batch 1, P9-HIGH-001: "No account status check (voter.php)" — labeled HIGH in plan, originally MEDIUM in audit. Conservative escalation, acceptable. `voter.php:8-17`, fix described. |
| VOTE-P9-006 | MEDIUM | Batch 1, P9-MED-001: "Option count validation fragile (trailing comma)" — `voter.php:38-41`, fix described. |

All voter CRITICAL, HIGH, and MEDIUM findings accounted for.

---

## 3. Summary of Gaps Found

### Missing HIGH/MEDIUM findings (4 gaps)

| Gap | Source Finding | Severity | Description | File |
|-----|---------------|----------|-------------|------|
| GAP-1 | SPY-P9-009 | MEDIUM | `couleurFormule()` output unescaped in espionage report HTML — latent XSS if formula field ever contains unsafe content | `includes/display.php` (function), `includes/game_actions.php:378` (call site) |
| GAP-2 | MULTI-P9-004 | MEDIUM | Raw IP addresses displayed to admin in account detail view at `admin/multiaccount.php:260` — distinct from the storage fix (P9-HIGH-007) | `admin/multiaccount.php:260` |
| GAP-3 | MULTI-P9-007 | MEDIUM | No trusted-proxy / X-Forwarded-For extraction documented; NAT false positives undocumented; no `TRUSTED_PROXY_IPS` config constant | `includes/multiaccount.php:22`, `includes/config.php` |
| GAP-4 | REG-P9-004 | MEDIUM | No CAPTCHA or bot-protection on registration (`inscription.php`, `comptetest.php`) — rate limit is the only bot deterrent | `inscription.php`, `comptetest.php` |

---

## 4. Review of Fix Descriptions for Technical Correctness

The following batch items raised concerns during technical review:

### P9-MED-010 (BLDG-P9-011): Stale snapshot fallback fix

The proposed fix interpolates `$liste['bdd']` (the building column name) directly into a SQL string inside the `withTransaction` closure:
```php
$niveauActuelRow = dbFetchOne($base, 'SELECT ' . $liste['bdd'] . ' AS niveau FROM constructions WHERE login=? FOR UPDATE', ...);
```
The plan notes "it is already validated at this point in the flow via `$liste` from `listeConstructions()`", which is accurate — `listeConstructions()` returns a fixed array with known column names. This is acceptable but the fix description should explicitly state that no additional whitelist check is needed here *because* the value comes from a server-controlled array, not from any user input. As written, a future developer reading only the fix code might add user-controlled input to `$liste['bdd']` without recognizing the injection risk. **Recommendation:** Add an `in_array` assertion in the fix code as belt-and-suspenders, consistent with the project's whitelist pattern.

### P9-HIGH-007 (MULTI-P9-001): IP hashing migration

The fix stores SHA-256 HMAC of IPs in columns currently declared `VARCHAR(45)`. SHA-256 hex output is 64 characters. The plan correctly states to create migration `0041_hash_ip_columns.sql` to alter to `VARCHAR(64)`. The plan also correctly warns "this will invalidate existing IP-match detections."

One additional concern: `marche.php:41` compares `$ipmm['ip'] != $ipdd['ip']` for same-IP transfer blocking. After hashing, the `membre.ip` column will store HMAC-hashed IPs, not raw IPs. The comparison will still work correctly (hash of same IP = same hash), *but* the IPv6 normalization fix (P9-LOW-020, `marche.php:41`) proposed in Batch 10 normalizes the IP string before comparison — after hashing, normalization must happen *before* hashing, not before the string comparison of already-hashed values. The plan treats P9-LOW-020 and P9-HIGH-007 as independent but they must be applied in the correct order (normalize then hash, not hash then normalize). **Recommendation:** Add a sequencing note in the batch dependencies that P9-LOW-020 must be implemented as a pre-hash normalization step when P9-HIGH-007 is applied.

### P9-HIGH-009 (MULTI-P9-003): moderation/ip.php auth guard

The fix says to "replace the `include("mdp.php")` auth guard with the standard `include("redirectionmotdepasse.php")` pattern." This is correct. However, the audit finding notes that the structural issue is that PHP continues executing after an `include` unless the included file calls `exit`. The plan's fix is adequate because `redirectionmotdepasse.php` does call `exit`. No technical problem here.

### P9-MED-007 (FORUM-P9-009): Moderator edit bypasses alliance-private forum

The proposed fix adds a join on `forums.alliance_tag` to check access. The plan notes "Adjust column names to match actual schema." This is an incomplete fix description — the actual schema column name for alliance forum restriction is not verified in the plan. The fix agent will need to inspect the `forums` table schema. **Recommendation:** Verify the column name (likely `allianceTag` or `alliance_tag`) and confirm the fix handles the case where `alliance_tag` is NULL (public forum — no restriction needed).

### P9-MED-022 (MULTI-P9-006): login_history probabilistic GC

The plan proposes a 1-in-200 probabilistic purge at end of `logLoginEvent()`. This is called on every login, so at 200 logins/day the expected purge frequency is once per day — reasonable. However, the plan does not address the observation that a bot hammering logins (no rate limit on `logLoginEvent()`) can insert many thousands of rows/day. The probabilistic GC will not keep up with adversarial insertion rates. **Recommendation:** Add a note that the purge frequency should be inversely proportional to typical login volume, or alternatively add a maximum-row cap check.

### P9-LOW-017 (MSG-P9-009): GET information/erreur params

The proposed fix adds a 100-char length cap via `mb_substr`. This is a minimal mitigation. The underlying issue (arbitrary text injection) remains exploitable within 100 chars. The audit recommendation was to use session-based flash messages instead. The plan's minimal fix is technically non-wrong but does not fully resolve the finding. For a MEDIUM-level social-engineering vector, a proper flash-message migration would be more appropriate. **Note:** MSG-P9-009 was originally labeled LOW in the audit summary table, so the plan's lightweight fix is arguably sufficient for a LOW finding.

---

## 5. Batch Ordering Review

The batch dependency map at the bottom of the plan is reviewed for circular dependencies and logical ordering:

- **Batch 3 dependency** (P9-HIGH-005 before P9-MED-004): migration 0039 (UTF-8 columns) must run before 0040 (retry cap). Correct — adding retry columns to a latin1 table before fixing the charset would leave new columns also latin1. Ordering is sound.
- **Batch 4 dependency** (P9-LOW-003/004 before P9-MED-007): session_init.php must be included in editer.php before the alliance access check is added. Correct — the auth guard depends on session being initialized.
- **Batch 5** (P9-MED-008 before P9-MED-009): CAS guard before null-dereference check. The CAS guard reduces the race window but the null-dereference fix is independent. Ordering is stated as "CAS guard reduces race window" — both can be applied in either order with no circular dependency. No issue.
- **Batch 10** (migration 0041 before LOW-018/019): IP hashing migration must deploy before detection functions are wired up, since the detection functions read `login_history.ip` for comparisons. Correct.
- **No circular dependencies detected.**

One ordering gap: The plan does not explicitly sequence P9-LOW-020 (IPv6 normalization in marche.php) relative to P9-HIGH-007 (IP hashing). As noted in Section 4, normalization must happen before hashing for correct comparison. This is a dependency that should be added to the batch dependency list.

---

## 6. Findings with Severity Discrepancies

The plan made the following severity escalations relative to audit labels:

| Plan Item | Audit Severity | Plan Severity | Assessment |
|-----------|---------------|---------------|------------|
| P9-HIGH-003 (EMAIL-P9-003) | MEDIUM | HIGH (in quick-ref table) | Acceptable — body injection risk warranted escalation |
| P9-HIGH-004 (EMAIL-P9-004) | MEDIUM | HIGH | Acceptable |
| P9-HIGH-005 (EMAIL-P9-005) | MEDIUM | HIGH | Acceptable |
| P9-HIGH-001 (VOTE-P9-005) | MEDIUM | HIGH | Acceptable — voting integrity warranted escalation |
| P9-MED-029 (MKT-P9-003) | LOW | MEDIUM | Acceptable — latent stored XSS warrants higher priority |

No findings were **downgraded** from HIGH/CRITICAL to a lower severity. All escalations are conservative and appropriate.

---

## 7. Verdict

**4 HIGH/MEDIUM findings are missing from the remediation plan:**

1. **SPY-P9-009** (MEDIUM) — `couleurFormule()` unescaped output in espionage reports. File: `includes/display.php` + `includes/game_actions.php:378`. Fix: `htmlspecialchars($formula, ENT_QUOTES, 'UTF-8')` before passing to `couleurFormule()`.

2. **MULTI-P9-004** (MEDIUM) — Raw IP addresses displayed in admin account detail view. File: `admin/multiaccount.php:260`. Fix: Display only 12-char hash prefix; add "Reveal IP" action with audit logging.

3. **MULTI-P9-007** (MEDIUM) — No trusted-proxy documentation or `TRUSTED_PROXY_IPS` config constant. File: `includes/multiaccount.php:22`, `includes/config.php`. Fix: Add config constant and deployment documentation.

4. **REG-P9-004** (MEDIUM) — No CAPTCHA/bot-protection on registration. Files: `inscription.php`, `comptetest.php`. Fix: Honeypot field or proof-of-work challenge. (May be intentionally deferred as a larger feature, but must be explicitly acknowledged in the plan.)

**3 technical correctness concerns in existing fix descriptions** (not blocking, but should be addressed before implementation):

- P9-MED-010: Add explicit `in_array` whitelist assertion in the fallback query code.
- P9-HIGH-007 + P9-LOW-020: Add sequencing dependency — IPv6 normalization must occur before IP hashing, not after.
- P9-MED-007: Verify actual `forums` table column name for alliance restriction before implementing the moderator edit fix.

**Plan is NOT approved in current state.**

The plan must be updated to include SPY-P9-009, MULTI-P9-004, MULTI-P9-007, and REG-P9-004 (or explicitly document deferred status with rationale). Once those 4 gaps are addressed, and the 3 technical notes are incorporated, the plan will be ready for implementation.

---

*Reviewer A sign-off: completeness review complete. Reviewer B (Technical Correctness) may proceed in parallel.*
