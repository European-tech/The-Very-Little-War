# Pass 7 Audit — ALLIANCE_MGMT Domain
**Date:** 2026-03-08
**Agent:** Pass7-C2-ALLIANCE_MGMT

## Summary
| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 0 |
| MEDIUM | 0 |
| LOW | 1 |
| INFO | 2 |
| **Total** | **3** |

**Overall Assessment:** CLEAN. All critical security controls verified correct.

---

## LOW Findings

### LOW-001 — Grade insert error message misleading
**File:** `allianceadmin.php:120-125`
**Description:** When `INSERT INTO grades` fails due to a duplicate key (PRIMARY KEY on `(login, idalliance)`), the error message says "conflit de concurrent" (concurrent conflict) but the failure could also be a legitimate duplicate. The logic is correct (atomic DB constraint handles it), but the user message is misleading.
**Fix:** Change error message to "Ce joueur est déjà gradé" without implying concurrency.

---

## INFO Findings

### INFO-001 — Alliance description uses BBCode without explicit htmlspecialchars pre-escape
**File:** `alliance.php:383`
**Description:** Alliance description is output via `BBcode($allianceJoueurPage['description'])`. Safe as long as the BBCode parser applies `htmlspecialchars` before parsing (confirmed in bbcode.php). No vulnerability.

### INFO-002 — Alliance join cooldown check uses graceful degradation
**File:** `alliance.php:208-220`
**Description:** The `alliance_left_at` cooldown check is wrapped in a try-catch that gracefully skips if the column doesn't exist. Not a vulnerability — intentional migration compatibility.

---

## Verified Clean

- **CSRF:** `csrfCheck()` on all POST paths — alliance.php (6 calls), allianceadmin.php (12 calls), validerpacte.php (1), don.php (1) — clean.
- **Race conditions on join:** FOR UPDATE on `autre` and `alliances` rows; count inside locked transaction — clean.
- **Grade permissions:** Bit-extraction from grade string (inviter/guerre/pacte/bannir/description); verified against appropriate POST handlers — clean.
- **Self-war prevention:** `id != ?` check in war declaration — clean.
- **Pact/war mutual exclusion:** Pending pacts deleted before war; FOR UPDATE on both alliances — clean.
- **XSS:** Alliance tag/name/grade names/war tags all wrapped in `htmlspecialchars()` — clean.
- **Input validation:** Tag regex `[a-zA-Z0-9_]{3,16}`, name length 3-50, donation cast to int + capped at `ALLIANCE_DONATION_MAX` — clean.
- **Transaction safety:** Alliance creation, leave, kick, pact, war declaration, war end, donation all wrapped in `withTransaction()` — clean.
- **Donation safety:** Rate limited (10/hour), minimum reserve `DONATION_MIN_ENERGY_RESERVE`, FOR UPDATE locks, atomic deduction+receipt — clean.
- **Invitation cooldown:** Enforced AFTER FOR UPDATE lock (no TOCTOU) — clean.
- **Alliance deletion cleanup:** `supprimerAlliance()` wraps all cleanup (grades, invitations, declarations, energy reset) in transaction — clean.
- **Alliance admin guard:** Chef membership verified before admin access; grade-only operations gated by bit flags — clean.
- **validerpacte.php:** FOR UPDATE on declaration + alliance rows; chef auth check; grade permission (pact bit) verified — clean.
- **alliance_discovery.php:** Public page; safe JOIN+GROUP BY; member count checked; all output escaped — clean.
