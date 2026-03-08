# Ultra Audit Pass 6 — Clean Bill of Health

**Date:** 2026-03-08
**Auditor:** Consolidation Agent (Claude Sonnet 4.6)
**Scope:** Full codebase — 16 domains
**Verdict:** NO CRITICAL / NO HIGH findings. Codebase is clean.

---

## Finding Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH     | 0 |
| MEDIUM   | 2 |
| LOW      | 2 |
| INFO     | 2 |
| **Total**| **6** |

---

## Domains Audited

1. Auth / Session (`basicpublicphp.php`, `basicprivatephp.php`, `session_init.php`, `csrf.php`)
2. Combat Logic (`combat.php`, `attaque.php`)
3. Combat Infrastructure (`game_actions.php`, `updateActions`)
4. Economy / Molecules (`game_resources.php`, `updateRessources`, `marche.php`)
5. Alliance (`alliance.php`, `allianceadmin.php`, `player.php` alliance functions)
6. Market (`marche.php`)
7. Prestige / Ranking (`prestige.php`, `classement.php`, `includes/prestige.php`)
8. Game Resources / Season (`basicprivatephp.php` season reset, `game_resources.php`)
9. Forum / Social (`sujet.php`, `listesujets.php`, `rapports.php`)
10. Map / Tutorial (`carte.php`, `tutoriel.php`)
11. DB Schema A (`includes/database.php`, `withTransaction`, savepoint nesting)
12. DB Schema B (`migrations/`, key constraints, CHECK constraints)
13. Security / Headers (`includes/csp.php`, `includes/layout.php`, `.htaccess`)
14. Specialization / Compounds (`includes/compounds.php`, `laboratoire.php`)
15. Specialization / Balance (`includes/formulas.php`, `includes/config.php`)
16. Admin / Moderation (`admin/redirectionmotdepasse.php`, `admin/multiaccount.php`, `voter.php`)

---

## Findings Detail

### MEDIUM-001 — `recalculerStatsAlliances()` called on every `classement.php?sub=1` GET

**File:** `classement.php` line ~370
**Description:** Every page load of `classement.php?sub=1` calls `recalculerStatsAlliances()` unconditionally with no caching, debounce, or rate-limiting guard. Under load this triggers a full alliance stats recalculation (SELECT with FOR UPDATE across all membre rows in each alliance) on every request.
**Risk:** CPU/DB pressure under load; not a correctness issue.
**Recommendation:** Cache the last recalculation timestamp in a lightweight DB or file-based flag, recalculate at most once per N minutes (e.g., 5 min), or move to a cron job.
**Blocker:** No — game works correctly today.

---

### MEDIUM-002 — `session.cookie_secure` conditional on `$_SERVER['HTTPS']`

**File:** `includes/session_init.php`
**Description:** `session.cookie_secure` is set to `0` when the request is over plain HTTP. This means session cookies are transmitted without the `Secure` flag until HTTPS is active.
**Risk:** Session hijacking over HTTP (man-in-the-middle). Known blocker: DNS for `theverylittlewar.com` has not yet been pointed to the VPS (currently `85.236.153.17`, needs `212.227.38.111`).
**Recommendation:** Complete DNS cutover → run certbot → set `session.cookie_secure = 1` unconditionally (or enforce HTTPS redirect at Apache level before session init).
**Blocker:** DNS cutover required first. This is tracked in MEMORY.md under Remaining TODO.

---

### LOW-001 — `SECRET_SALT` hardcoded in `config.php`

**File:** `includes/config.php`
**Description:** `SECRET_SALT` is a hardcoded string literal in config.php with a `// TODO: load from .env` comment. The salt is used for IP hashing in logger.php (non-security-critical) and rate limiter filenames (non-security-critical), but it is still good hygiene to keep it out of version-controlled PHP.
**Risk:** If the repo is ever made public, the salt is exposed. Low impact because the salt is not used for password hashing (bcrypt handles that).
**Recommendation:** Move `SECRET_SALT` to the `.env` file on the VPS and load via `$_ENV` in `connexion.php` (which already loads `.env`).
**Blocker:** No.

---

### LOW-002 — Duplicate DB query in `sujet.php`

**File:** `sujet.php` lines ~150 and ~207
**Description:** `SELECT titre FROM forums WHERE id = ?` is executed twice in the same request — once to display the forum breadcrumb and again later for the reply form label.
**Risk:** Zero correctness impact; minor inefficiency (one extra indexed primary-key lookup per page load).
**Recommendation:** Cache the result in a local variable after the first query and reuse it for the second display point.
**Blocker:** No.

---

### INFO-001 — `ADMIN_LOGIN` hardcoded in `config.php`

**File:** `includes/config.php`
**Description:** `ADMIN_LOGIN` is defined as `'Guortates'` directly in the config file.
**Risk:** No security risk — the admin username is not a secret. This is purely a configuration-as-code observation.
**Recommendation:** Acceptable as-is for a single-admin game. Could move to `.env` for consistency with other environment-specific values, but not urgent.

---

### INFO-002 — `transfert.php` does not exist as a standalone file

**Description:** Previous audit prompts referenced `transfert.php` as a separate file to audit. It does not exist. Inter-player transfer logic is handled entirely within `marche.php`.
**Risk:** None. This is a documentation inconsistency in older audit prompts.
**Recommendation:** Remove `transfert.php` references from future audit checklists.

---

## What Was Verified Clean

The following areas were fully audited and found to have no actionable issues:

- **CSRF protection**: `csrfCheck()` on all POST mutations, token rotation on verify, same-origin redirect guard — clean.
- **XSS**: `htmlspecialchars` applied on all user-controlled output, BBCode parser applies `htmlspecialchars` first — clean.
- **SQL injection**: All queries use `dbQuery`/`dbFetchOne`/`dbFetchAll`/`dbExecute` prepared statement helpers — clean.
- **Authentication**: bcrypt passwords, MD5 auto-upgrade, session regeneration on login, session token double-check (DB + `$_SESSION`), idle timeout, session regen every 30min — clean.
- **Race conditions**: FOR UPDATE locks on combat, compounds, voter, alliance joins, research upgrades; withTransaction + savepoint nesting; CAS guards in updateRessources/updateActions — clean.
- **Transactions**: `withTransaction()` used in all multi-step mutations (game_actions, alliance, player, combat, compounds) — clean.
- **Input validation**: `validateLogin`, `validatePositiveInt`, `validateRange`, column whitelists in db_helpers — clean.
- **CSP**: Per-request nonce, all inline scripts use nonce, no `unsafe-inline` scripts (only `unsafe-inline` for styles, documented TODO) — clean.
- **Admin security**: Separate `TVLW_ADMIN` session name, IP binding with `hash_equals`, idle timeout, CSRF on all admin POST — clean.
- **Rate limiting**: Login (10/5min), registration, reply (10/5min), file-based in `data/rates/`, fails closed — clean.
- **Combat logic**: V4 overkill cascade, formation modifiers, vault protection, pillage tax, defense reward, building damage weighted targeting, compound snapshots — no logic errors found.
- **Season reset**: Two-phase (maintenance flag → 24h wait → performSeasonEnd), admin-gated, archiveSeasonData — clean.
- **Prestige/PP**: Unlock shop CSRF-protected, streak tracking, comeback bonus — clean.
- **Alliance**: Transaction-wrapped creation/join, FOR UPDATE on duplicateur and research upgrades, invitation cooldown — clean.
- **Forum**: Rate limit on replies, ban check, locked topic check, atomic message counter, alliance-only access guard — clean.
- **Compound synthesis**: FOR UPDATE serialization, cache invalidation using global `$_compoundBonusCache` — clean.
- **Resource nodes**: Proximity bonus system — no issues found.
- **Logging**: IP hashing, CR/LF stripping (log injection prevention), daily rotation — clean.
- **Health endpoint**: DB details only for localhost (127.0.0.1), returns 503 on DB failure — clean.

---

## Conclusion

After 5 prior remediation passes totaling ~130 commits, the TVLW codebase has no remaining CRITICAL or HIGH security, logic, or data-integrity issues. The 2 MEDIUM findings are performance/infrastructure items (classement caching, HTTPS), both with clear resolution paths already tracked. The 2 LOW findings are minor code hygiene items that can be addressed opportunistically.

**The codebase is production-ready** pending DNS cutover and HTTPS activation.
