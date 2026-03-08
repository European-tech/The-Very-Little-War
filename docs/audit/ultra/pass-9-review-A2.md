# Pass 9 Remediation Plan — Reviewer A Re-run (Rev 2 Verification)

**Date:** 2026-03-08
**Reviewer:** Reviewer A (Re-run after Rev 2 corrections)
**Scope:** Verify that all 10 concerns raised in pass-9-review-A.md were addressed in the Rev 2 update to `docs/plans/2026-03-08-pass-9-remediation.md`.
**Prior review:** `docs/audit/ultra/pass-9-review-A.md`

---

## Verification of 10 Specified Concerns

### 1. SPY-P9-009 (couleurFormule XSS) now in Batch 15 with a concrete fix

**Status: SATISFIED.**

Batch 15 entry `SPY-P9-009` at line 943 of the plan is present and concrete:
- File: `includes/game_actions.php:378`
- Fix: `htmlspecialchars($formule, ENT_QUOTES, 'UTF-8')` applied to the input before passing to `couleurFormule()`.
- Plan correctly warns to check for double-encoding if `couleurFormule()` already escapes internally, and directs the fix agent to apply `htmlspecialchars()` inside the function instead if that is the case.
- Verify step: Script tag in DB molecule name rendered as `&lt;script&gt;` in espionage report HTML.
- Numbered as P9-MED-030 in the quick-reference table.

Concern fully addressed.

---

### 2. MULTI-P9-004 (raw IP in display) now in Batch 15 with a concrete fix

**Status: SATISFIED.**

Batch 15 entry `MULTI-P9-004` at line 959 of the plan is present and concrete:
- File: `admin/multiaccount.php:260`
- Fix: `substr($account['ip'], 0, 12) . '…'` — display only the first 12 characters of the stored hash, not the full value.
- Explicitly noted as a display-layer fix distinct from the P9-HIGH-007 storage fix.
- Dependency documented: apply after P9-HIGH-007 so the truncation applies to the hash, not a raw IP.
- Numbered as P9-MED-031 in the quick-reference table.

One minor observation: the original review (GAP-2) mentioned "add 'Reveal IP' action with audit logging" as part of the fix. The Rev 2 plan explicitly notes "Do NOT add a 'Reveal IP' link at this stage (out of scope for this batch; the hashed value is irreversible by design post-P9-HIGH-007)." This is a reasonable scope decision — the hashed IP is irreversible, so a reveal action would be meaningless post-migration. The core concern (raw IP visible to admin) is addressed by the truncated display. The scope reduction is acceptable.

Concern fully addressed.

---

### 3. MULTI-P9-007 (trusted-proxy config) now in Batch 15

**Status: SATISFIED.**

Batch 15 entry `MULTI-P9-007` at line 977 of the plan is present:
- Files: `includes/config.php`, `includes/multiaccount.php:22`
- Fix: Add `TRUSTED_PROXY_IPS` constant to `config.php` with a detailed documentation comment explaining the current direct-connection assumption, the X-Forwarded-For risk, and the upgrade path if a reverse proxy is ever added. Add a comment at the `$_SERVER['REMOTE_ADDR']` assignment in `multiaccount.php:22`.
- Plan explicitly states this is documentation and forward-proofing only (no functional code change required now, which is correct since the VPS has no reverse proxy).
- Numbered as P9-MED-032 in the quick-reference table.

Concern fully addressed.

---

### 4. REG-P9-004 (no CAPTCHA) marked as deferred with rationale

**Status: SATISFIED.**

Batch 15 entry `REG-P9-004` at line 1002 of the plan:
- Status explicitly set to `EXPLICITLY DEFERRED`.
- Gap acknowledged in full: neither `inscription.php` nor `comptetest.php` has CAPTCHA, honeypot, or proof-of-work.
- Rationale documented: non-trivial UI/UX change, third-party service configuration required, small active player base, existing rate limiter provides interim mitigation.
- Interim mitigation cited: rate limiting (10 registration attempts / 60 seconds per IP).
- Future fix described (honeypot field) with implementation note.
- Numbered as P9-MED-033 in the quick-reference table with DEFERRED status.

The original concern was that the finding was not acknowledged at all. It is now explicitly acknowledged with documented rationale. Concern fully addressed.

---

### 5. IPv6 normalization (P9-LOW-020) listed as prerequisite before IP hashing (P9-HIGH-007)

**Status: SATISFIED.**

Two separate locations in the plan address this:

(a) In the P9-HIGH-007 fix description (Batch 10, line 597):
> "PREREQUISITE: P9-LOW-020 (IPv6 normalization in marche.php) must be implemented FIRST. IPv6 normalization must be applied to raw IP strings BEFORE hashing them. The canonical order is: raw IP → inet_pton/inet_ntop normalize → hash_hmac. If hashing is applied before normalization, two representations of the same IPv6 address will produce different hashes and fail to match."

(b) In the batch dependency map (line 1027):
> "ORDER WITHIN BATCH MATTERS: P9-LOW-020 (IPv6 normalization) MUST be applied BEFORE P9-HIGH-007 (IP hashing). Normalization must happen on the raw IP string before it is hashed; normalizing a 64-char hex hash would produce nonsense."

Both the fix description and the dependency map are updated. The sequencing is unambiguous.

Concern fully addressed.

---

### 6. P9-MED-007 now includes a separate fetch for moderator's alliance from the `autre` table

**Status: SATISFIED.**

P9-MED-007 fix description (Batch 4, line 252) now explicitly states:
- The `$moderateur` array fetched at line 12 contains only the `moderateur` key — NOT an `alliance` key.
- A separate `dbFetchOne` query on the `autre` table is required:
  ```php
  $modAllianceRow = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
  $modAlliance = $modAllianceRow['idalliance'] ?? null;
  ```
- The fix agent is directed to verify actual column names (`DESCRIBE autre`, `DESCRIBE forums`) before applying.
- The guard is explicitly specified to be a no-op (allow through) when `$topicForum['alliance_tag']` is NULL (public forum).

The original concern was that `$moderateur['alliance']` would always be null because that key does not exist in the fetched array. The Rev 2 plan explicitly identifies this and prescribes the correct separate fetch. Concern fully addressed.

---

### 7. P9-MED-008 now specifies an outer withTransaction() wrapping lines 350-476

**Status: SATISFIED.**

P9-MED-008 fix description (Batch 5, line 318) now explicitly states:
> "The correct fix is to wrap the entire espionage resolution block in a NEW outer withTransaction() and place the CAS guard as the FIRST statement inside that new wrapper."

The plan provides the exact code structure showing the new outer `withTransaction($base, function() use (...) { ... })` wrapping lines 350-476, with the CAS guard (`UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0`) as the first statement inside that wrapper. The plan explicitly warns:
> "Do NOT merely add the CAS guard to the existing line-465 inner transaction alone — that does not protect the data-fetch phase."

The plan also addresses merging or removing the existing narrow transaction at line 465 (it becomes redundant inside the outer transaction).

The original concern was that a CAS guard only in the line-465 inner transaction does not protect the data-fetch phase starting at line 350. This concern is now directly and correctly addressed. Concern fully addressed.

---

### 8. P9-HIGH-007 now includes updating lookup queries in moderation/ip.php and checkSameIpAccounts()

**Status: SATISFIED.**

P9-HIGH-007 fix description (Batch 10, line 594) now includes a mandatory "SIDE-EFFECT FIX" section:
> "After this migration, any query that compares a raw IP string against the now-hashed column will silently fail to match. The following locations MUST also be updated to hash the lookup value before comparison:
> 1. moderation/ip.php:20 — currently `SELECT * FROM membre WHERE ip = ?` with a raw IP input. Change to hash the `$_GET['ip']` (or equivalent) value using the same hash_hmac call before binding it to the query.
> 2. checkSameIpAccounts() in includes/multiaccount.php — any query that reads from login_history by IP value must hash the comparison IP first."

The plan further directs the fix agent to "audit ALL query sites that read/compare membre.ip or login_history.ip and update every one."

The verify step also confirms: "Admin IP lookup in moderation/ip.php still returns results when queried with a raw IP (hashed internally before lookup)."

Concern fully addressed.

---

### 9. P9-MED-019 now uses the actual recipient variable (not $canonicalLogin)

**Status: SATISFIED.**

P9-MED-019 fix description (Batch 9, line 541) now states:
> "CORRECTED from Rev 1: The variable $canonicalLogin does not exist in the current ecriremessage.php code."

The corrected fix:
1. Directs the fix agent to locate where the recipient is resolved from the DB (the `SELECT ... FROM membre WHERE login = ?` lookup).
2. Instructs adding the self-send guard immediately after that DB lookup, comparing the DB-returned login against `$_SESSION['login']`.
3. Provides a fallback path if no DB canonicalization exists: compare `$_POST['destinataire']` directly.
4. Specifies case-insensitive comparison (`strtolower()`).
5. Instructs the fix agent to read `ecriremessage.php:7-60` to locate the actual recipient variable before applying.

The original concern was that the Rev 1 fix referenced a non-existent `$canonicalLogin` variable, making it unapplicable. The Rev 2 fix is written to be implementation-agnostic and correctly guides the fix agent to find the actual variable. Concern fully addressed.

---

### 10. No other HIGH/MEDIUM findings from the 16 domain reports are missing

**Status: SATISFIED — no additional gaps found.**

Cross-checking the quick-reference tables in the Rev 2 plan against the domain-by-domain breakdown in pass-9-review-A.md:

**HIGH findings (11 remaining after already-fixed):**
- P9-HIGH-001 (VOTE-P9-005): Batch 1. Present.
- P9-HIGH-002 (INFRA-P9-005): Batch 2. Present.
- P9-HIGH-003 (EMAIL-P9-003): Batch 3. Present.
- P9-HIGH-004 (EMAIL-P9-004): Batch 3. Present.
- P9-HIGH-005 (EMAIL-P9-005): Batch 3. Present.
- P9-HIGH-006 (FORUM-P9-001+008): Batch 4. Present.
- P9-HIGH-007 (MULTI-P9-001+REG-P9-003): Batch 10. Present.
- P9-HIGH-008 (MULTI-P9-002): Batch 10. Present.
- P9-HIGH-009 (MULTI-P9-003): Batch 10. Present.
- All INFRA-P9-001/002/003/004, EMAIL-P9-001/002, MSG-P9-001, FORUM-P9-002/003, VOTE-P9-002/003, MKT-P9-001/002, RANK-P9-001, FORUM-P9-004: Already-Fixed table. All accounted for.

**MEDIUM findings (30 total: 1 already-applied, 28 remaining, 1 deferred):**
- P9-MED-001 (VOTE-P9-006): ALREADY APPLIED. Confirmed in codebase by Reviewer B. Skip.
- P9-MED-002 through P9-MED-029: All present in Batches 2-13 as reviewed in pass-9-review-A.md (all domains verified clean except the 4 gaps that are now fixed in Batch 15).
- P9-MED-030 (SPY-P9-009): Batch 15. Now present.
- P9-MED-031 (MULTI-P9-004): Batch 15. Now present.
- P9-MED-032 (MULTI-P9-007): Batch 15. Now present.
- P9-MED-033 (REG-P9-004): Batch 15. Explicitly deferred with rationale.
- FORUM-P9-007: Withdrawn by the audit report itself (redirect already present). Correctly omitted from plan.

No domain reports contain unaccounted HIGH or MEDIUM findings after these additions.

**Supplementary check — Rev 2 header notes alignment:**

The "What changed in Rev 2" section at the top of the plan (lines 7-24) accurately summarizes all changes:
- Batch 15 additions (4 items).
- P9-HIGH-001 alias correction.
- P9-MED-001 already-applied status.
- P9-MED-007 separate alliance fetch.
- P9-MED-008 outer transaction clarification.
- P9-HIGH-007 lookup query side-effect fix.
- P9-MED-019 $canonicalLogin correction.
- P9-LOW-020 prerequisite ordering before P9-HIGH-007.

All noted changes are present in the plan body. No discrepancy between the changelog and the actual plan content.

---

## Residual Technical Notes (Non-Blocking, Carried Forward from Prior Review)

These were noted as non-blocking recommendations in pass-9-review-A.md. They remain valid observations for the fix agent but do not constitute plan completeness gaps:

1. **P9-MED-010 (BLDG-P9-011):** The Rev 2 plan added an explicit note that `$liste['bdd']` is already validated via `listeConstructions()`. However, the plan does not add an `in_array` assertion in the fix code itself. This remains a belt-and-suspenders recommendation. Fix agents should add the assertion consistent with the project's column-whitelist pattern used elsewhere.

2. **P9-MED-022 (MULTI-P9-006):** The probabilistic GC (1-in-200) note about adversarial login rates has not been updated in the plan. Under bot hammering, the 1-in-200 GC will not keep pace. This is a known limitation documented in the prior review. Fix agents should consider a row-count cap in addition to the probabilistic purge.

3. **P9-LOW-017 (MSG-P9-009):** The plan's 100-character truncation is a minimal fix for what is formally a LOW-severity finding (social-engineering text injection). A full session-flash migration would be more robust but is out of scope for this audit cycle. The fix as specified is acceptable for LOW severity.

These three points are informational only and do not prevent plan approval.

---

## Summary

| Concern | Status |
|---------|--------|
| 1. SPY-P9-009 in Batch 15 with concrete fix | SATISFIED |
| 2. MULTI-P9-004 in Batch 15 with concrete fix | SATISFIED |
| 3. MULTI-P9-007 in Batch 15 | SATISFIED |
| 4. REG-P9-004 deferred with rationale | SATISFIED |
| 5. P9-LOW-020 listed as prerequisite before P9-HIGH-007 | SATISFIED |
| 6. P9-MED-007 separate fetch for moderator's alliance from `autre` | SATISFIED |
| 7. P9-MED-008 outer withTransaction() wrapping lines 350-476 | SATISFIED |
| 8. P9-HIGH-007 updating lookup queries in moderation/ip.php and checkSameIpAccounts() | SATISFIED |
| 9. P9-MED-019 uses actual recipient variable (not $canonicalLogin) | SATISFIED |
| 10. No other HIGH/MEDIUM findings missing | SATISFIED |

All 10 concerns from the prior review have been correctly addressed in the Rev 2 plan. The plan now accounts for all HIGH and MEDIUM findings across all 16 domain reports, with one finding explicitly deferred with documented rationale (REG-P9-004). No HIGH or MEDIUM findings remain unaddressed or unacknowledged.

---

**PLAN APPROVED**

*Reviewer A sign-off: all prior concerns resolved. Plan Rev 2 is ready for fix-agent implementation. Process Batches 1-13 in order, then Batch 15 (new MEDIUM items), deferring Batch 14 (INFO cleanups) to a final polish pass. Within Batch 10, apply P9-LOW-020 before P9-HIGH-007. P9-MED-001 is already applied — skip. REG-P9-004 is deferred — skip for this cycle.*
