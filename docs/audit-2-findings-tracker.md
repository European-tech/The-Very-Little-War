# Audit #2 — Findings Tracker

## CRITICAL Issues (10 total)

| ID | Domain | Description | File | Status |
|----|--------|-------------|------|--------|
| AUTH-001 | Auth | Session fixation on login (no session_regenerate_id) | basicpublicphp.php | FIXED |
| AUTH-002 | Auth | Session fixation on visitor account creation | comptetest.php | FIXED |
| GAME-101 | Game Logic | Prestige unlock doesn't deduct PP cost | includes/prestige.php | FIXED |
| GAME-101b | Game Logic | Prestige unlock TOCTOU double-spend race condition | includes/prestige.php | FIXED (transaction + FOR UPDATE) |
| GAME-102 | Game Logic | Vacation players still get resources (updateRessources before vacation check) | includes/basicprivatephp.php | FIXED |
| DB-001 | Database | don.php alliance energy donation lacks transaction | don.php | FIXED |
| DB-002 | Database | inscrire() has no transaction (6 INSERTs) | includes/player.php | FIXED |
| DB-003 | Database | supprimerJoueur() has no transaction (14 DELETEs) | includes/player.php | FIXED |
| DB-004 | Database | supprimerAlliance() has no transaction (6 operations) | includes/player.php | FIXED |
| DB-013 | Database | don.php TOCTOU race condition | don.php | FIXED |

## HIGH Issues (43+ total)

| ID | Domain | Description | File | Status |
|----|--------|-------------|------|--------|
| AUTH-003 | Auth | api.php/voter.php skip session token validation | api.php | FIXED (session token DB validation added) |
| AUTH-004 | Auth | Login CSRF missing | basicpublicphp.php | FIXED (csrfField + csrfCheck) |
| AUTH-005 | Auth | Logout doesn't clear session cookie | deconnexion.php | FIXED |
| AUTH-006 | Auth | Admin/moderator share same password mechanism | moderation/ | DEFERRED (architectural) |
| AUTH-007 | Auth | No idle session timeout | session_init.php | FIXED (1h idle timeout + gc_maxlifetime) |
| AUTH-008 | Auth | 14 hybrid pages start sessions without security flags | 14 files | FIXED |
| AUTH-009 | Auth | Timing-unsafe legacy MD5 comparison | basicpublicphp.php | FIXED (hash_equals) |
| SEC-001 | Security | XSS in attaquer.php map + espionage | attaquer.php | FIXED (htmlspecialchars) |
| SEC-002 | Security | XSS in historique.php archives | historique.php | FIXED |
| SEC-003 | Security | moderationForum.php actions before auth check | moderationForum.php | FIXED |
| GAME-103 | Game Logic | Energy can go negative | multiple | FIXED (max(0) guards + market FOR UPDATE) |
| GAME-104 | Game Logic | Decay stats not tracked | update.php | FIXED (moleculesPerdues tracked) |
| GAME-105 | Game Logic | Defender isotope mods ignored in combat | combat.php | FIXED (defIsotopeAttackMod applied) |
| GAME-106 | Game Logic | Hardcoded 4 molecule classes | multiple | DEFERRED (architectural) |
| GAME-107 | Game Logic | Selling doesn't award trade points | marche.php | FIXED (sell trade points mirroring buy) |
| GAME-108 | Game Logic | NEW_PLAYER_BOOST dead code | config.php | FIXED (removed) |
| INP-001 | Input | XSS in marche.php | marche.php | FIXED (htmlspecialchars on player names) |
| INP-002 | Input | XSS in attaquer.php espionage | attaquer.php | FIXED (htmlspecialchars) |
| INP-003 | Input | voter.php stored XSS | voter.php | FIXED (intval cast) |
| INP-004 | Input | Weak pagination validation (#\d# regex) | multiple | FIXED (intval across 8 locations) |
| INP-005 | Input | XSS in historique.php (all 3 archive tables) | historique.php | FIXED |
| INP-006 | Input | Date validation in moderationForum | moderationForum.php | FIXED (checkdate validation) |
| INP-007 | Input | Donation energy validation | don.php | FIXED (transaction + lock) |
| INP-008 | Input | Attack troop count validation | attaquer.php | DEFERRED |
| INP-009 | Input | Admin news HTML injection | moderation/ | DEFERRED |
| DB-005 | Database | Market buy race condition | marche.php | FIXED (SELECT FOR UPDATE in transaction) |
| DB-006 | Database | N+1 in combat formulas | formulas.php | FIXED (optional pre-fetched medal data param) |
| DB-007 | Database | Missing index on actionsattaques | schema | FIXED (migration 0014 composite indexes) |
| CODE-001 | Code Quality | joueur.php full table scan for rank | joueur.php | FIXED |
| CODE-002 | Code Quality | Variable variables in armee.php | armee.php | DEFERRED (cosmetic) |
| CODE-003 | Code Quality | comptetest.php GET registration no rate limiting | comptetest.php | FIXED |
| CODE-004 | Code Quality | Forum ban check garbage query | forum.php, sujet.php | FIXED |
| CODE-006 | Code Quality | deconnexion.php conflicting charset | deconnexion.php | FIXED |
| CODE-008 | Code Quality | messageCommun.php no admin check | messageCommun.php | FIXED |
| CODE-015 | Code Quality | guerre.php XSS unescaped alliance tags | guerre.php | FIXED |
| CODE-027 | Code Quality | sinstruire.php off-by-one in course ID | sinstruire.php | FIXED |
| INP-010 | Input | don.php zero-amount donation bypasses regex | don.php | FIXED (regex + >0 check) |
| DB-014 | Database | comptetest.php 15-table login rename without transaction | comptetest.php | FIXED (withTransaction) |
| DB-015 | Database | comptetest.php TOCTOU race on numerovisiteur | comptetest.php | FIXED (LAST_INSERT_ID atomic) |
| CODE-028 | Code Quality | guerre.php null dereference when war/alliance not found | guerre.php | FIXED (null guards) |
| CODE-029 | Code Quality | moderationForum.php dead code (if(true) + unreachable else) | moderationForum.php | FIXED (removed) |
| CODE-030 | Code Quality | historique.php null dereference on alliances/guerres data | historique.php | FIXED (null guards) |

## MEDIUM Issues (47+ total) - Selected Fixes

| ID | Domain | Description | File | Status |
|----|--------|-------------|------|--------|
| UX-M3 | UX | marche.php send form shows wrong balance (energie instead of atom) | marche.php | FIXED |
| UX-L7 | UX | alliance.php CSS typo "table-reponsive" | alliance.php | FIXED |
| CODE-005 | Code Quality | Pagination regex allows partial match | multiple | FIXED (intval replaces regex) |
| CODE-007 | Code Quality | Duplicated pagination logic | multiple | DEFERRED (cosmetic) |
| CODE-009 | Code Quality | moderation/ip.php fragile auth flow | moderation/ip.php | FIXED (session_init.php) |
| CODE-010 | Code Quality | editer.php delete/hide GET links (dead UI) | sujet.php | DEFERRED |
| CODE-011 | Code Quality | Stale revenue columns in update.php | update.php | DEFERRED |
| CODE-012 | Code Quality | formulas.php performs DB queries | formulas.php | FIXED (optional medalData param) |
| CODE-013 | Code Quality | Race condition in armee.php formation | armee.php | DEFERRED |
| CODE-014 | Code Quality | classement.php deletes alliances during display | classement.php | FIXED (continue instead of delete) |

## Summary

| Severity | Total Found | Fixed | Deferred | Fix Rate |
|----------|-------------|-------|----------|----------|
| CRITICAL | 10 | 10 | 0 | 100% |
| HIGH | 49+ | 40 | 9+ | ~82% |
| MEDIUM | 47+ | 8 | 39+ | ~17% |
| LOW | 32+ | 0 | 32+ | 0% |

### Files Modified in This Session
1. includes/session_init.php (NEW)
2. includes/basicpublicphp.php (session_init + session_regenerate_id)
3. includes/basicprivatephp.php (vacation resource fix)
4. includes/prestige.php (PP deduction fix + TOCTOU race fix with transaction)
5. includes/player.php (3 transactions: inscrire, supprimerJoueur, supprimerAlliance)
6. comptetest.php (session_init + session_regenerate_id + rate limiting + atomic visitor# + rename transaction)
7. deconnexion.php (session_init + cookie clearing + charset fix)
8. don.php (transaction + SELECT FOR UPDATE + zero-amount validation)
9. moderationForum.php (auth check before actions + dead code cleanup)
10. messageCommun.php (admin-only + CSRF + form)
11. historique.php (XSS escaping on 3 archive tables + null guards)
12. guerre.php (XSS escaping + null dereference guards)
13. joueur.php (rank query optimization)
14. forum.php (remove garbage ban query)
15. sujet.php (remove garbage ban query)
16. sinstruire.php (off-by-one fix)
17. marche.php (wrong balance bug fix)
18. alliance.php (CSS typo fix + session_init)
19. api.php (session_init)
20. 13 hybrid pages (session_init): index.php, classement.php, connectes.php, credits.php, guerre.php, historique.php, joueur.php, listesujets.php, regles.php, sinstruire.php, sujet.php, version.php, forum.php
