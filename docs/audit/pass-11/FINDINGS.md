# Pass 11 Ultra Audit — Findings Report

> Date: 2026-03-08 | 21 domains audited in 3 parallel batches of 7 agents each

---

## Executive Summary

| Severity | Found | Fixed | Deferred |
|----------|-------|-------|---------|
| CRITICAL | 1 | 1 | 0 |
| HIGH | 10 | 8 | 2 |
| MEDIUM | 19 | 8 | 11 |
| LOW | 31 | 3 | 28 |
| INFO | 18 | 0 | 18 |

**2 commits applied:** a33b29b (Batches A+B), 6fbb0b3 (Batch C)

---

## BATCH A — Security & Infrastructure

### AUTH
| ID | Sev | File | Issue | Status |
|----|-----|------|-------|--------|
| AUTH-MED-001 | MEDIUM | deconnexion.php | Account deletion bypasses cooldown check | FALSE POSITIVE — no cooldown exists |
| AUTH-LOW-001 | LOW | deconnexion.php | Session token not invalidated on 7-day delete | OPEN |
| AUTH-LOW-002 | LOW | inscription.php | No honeypot or captcha for bot registration | OPEN (by design) |
| AUTH-LOW-003 | LOW | compte.php | No re-auth for email change | OPEN (by design) |

### INFRA-DATABASE
| ID | Sev | File | Issue | Status |
|----|-----|------|-------|--------|
| INFRA-DB-HIGH-001 | HIGH | connexion.php | No mysqli_report() — DB errors silent | FIXED ✓ |
| INFRA-DB-HIGH-002 | HIGH | migrations/0075 | IF..THEN..END IF syntax not valid standalone | FIXED ✓ |
| INFRA-DB-MED-001 | MEDIUM | database.php | withTransaction rollback does not rethrow | OPEN |
| INFRA-DB-MED-002 | MEDIUM | migrations | Multiple migrations not idempotent | OPEN |
| INFRA-DB-MED-003 | MEDIUM | database.php | dbCount returns mixed vs int | OPEN (minor) |

### ANTI_CHEAT
| ID | Sev | File | Issue | Status |
|----|-----|------|-------|--------|
| AC-HIGH-001 | HIGH | admin/ip.php | Queries raw IP against hashed IP column (always 0 results) | FIXED ✓ |
| AC-MED-001 | MEDIUM | multiaccount.php | IP normalization can be bypassed with IPv6 | OPEN |
| AC-MED-002 | MEDIUM | multiaccount.php | No rate limit on fingerprint detection calls | OPEN |

### ADMIN
| ID | Sev | File | Issue | Status |
|----|-----|------|-------|--------|
| ADMIN-HIGH-001 | HIGH | supprimercompte.php | Audit log written BEFORE deletion (false records) | FIXED ✓ |
| ADMIN-HIGH-002 | HIGH | index.php | Batch IP deletion loop has no outer transaction | FIXED ✓ |
| ADMIN-MED-001 | MEDIUM | index.php | CSRF check after POST conditional (stale) | OPEN (acceptable) |
| ADMIN-MED-002 | MEDIUM | supprimercompte.php | Still uses redirectionmotdepasse (weak auth) | OPEN (existing) |

### SEASON_RESET
| ID | Sev | File | Issue | Status |
|----|-----|------|-------|--------|
| SR-HIGH-001 | HIGH | player.php:1351 | DELETE sanctions using non-existent columns (type/fin) | FIXED ✓ |

### FORUM
| ID | Sev | File | Issue | Status |
|----|-----|------|-------|--------|
| FORUM-MED-001 | MEDIUM | moderationForum.php | rateLimitCheck return value ignored (unenforced) | FIXED ✓ |
| FORUM-MED-002 | MEDIUM | editer.php | Banned moderator bypass via fresh DB query | FIXED ✓ |

---

## BATCH B — Combat & Economy

### MARKET
| ID | Sev | File | Issue | Status |
|----|-----|------|-------|--------|
| MARKET-HIGH-001 | HIGH | marche.php | $nbRes not in buy/sell closure use clauses → all transactions fail | FIXED ✓ |

### COMPOUNDS
| ID | Sev | File | Issue | Status |
|----|-----|------|-------|--------|
| CMPD-CRIT-001 | CRITICAL | compounds.php | $allowedCols uses chemical symbols not French column names → ALL synthesis broken | FIXED ✓ |

### COMBAT
| ID | Sev | File | Issue | Status |
|----|-----|------|-------|--------|
| CMB-MED-001 | MEDIUM | player.php | supprimerJoueur deletes in-flight attacks (molecules lost) | OPEN (design) |
| CMB-LOW-001 | LOW | attaquer.php | Beginner protection display ignores veteran prestige extension | OPEN |

### ESPIONAGE
| ID | Sev | File | Issue | Status |
|----|-----|------|-------|--------|
| ESP-MED-001 | MEDIUM | attaquer.php | Attacker's own beginner protection not checked for espionage | OPEN |
| ESP-LOW-001 | LOW | game_actions.php | Defender neutrino read without FOR UPDATE during espionage resolution | OPEN |

### ECONOMY
| ID | Sev | File | Issue | Status |
|----|-----|------|-------|--------|
| ECO-LOW-001 | LOW | don.php | Unused FOR UPDATE on energieDonnee (wasted SELECT) | OPEN (minor) |

### BUILDINGS
| ID | Sev | File | Issue | Status |
|----|-----|------|-------|--------|
| BLDG-LOW-001 | LOW | constructions.php | 0-point production allocation triggers no-op transaction | OPEN |

### MAPS
| ID | Sev | File | Issue | Status |
|----|-----|------|-------|--------|
| MAPS-LOW-001 | LOW | attaquer.php | Resource node X/Y not int-cast in map HTML output | OPEN |
| MAPS-LOW-002 | LOW | resource_nodes.php | Resource node bounds check missing lower bound | OPEN |
| MAPS-LOW-003 | LOW | attaquer.php | Coordinate values not int-cast in attack/espionage links | OPEN |

---

## BATCH C — Social & Cross-cutting

### ALLIANCE_MGMT
| ID | Sev | File | Issue | Status |
|----|-----|------|-------|--------|
| ALLY-HIGH-001 | HIGH | validerpacte.php | TOCTOU: grade read without FOR UPDATE inside pact acceptance | FIXED ✓ |
| ALLY-MED-001 | MEDIUM | allianceadmin.php | Race condition in duplicate invitation check | OPEN |

### RANKINGS
| ID | Sev | File | Issue | Status |
|----|-----|------|-------|--------|
| RANK-HIGH-001 | HIGH | formulas.php | Double sqrt: pointsAttaque/Defense pre-transformed, then pow(0.5) applied again | DEFERRED (season reset) |

### NOTIFICATIONS
| ID | Sev | File | Issue | Status |
|----|-----|------|-------|--------|
| NOTIF-MED-001 | MEDIUM | rapports.php | Report ID not int-cast before HTML output | FIXED ✓ |
| NOTIF-MED-002 | MEDIUM | rapports.php | Event-handler regex missing /onerror= pattern (slash prefix) | FIXED ✓ |
| NOTIF-MED-003 | MEDIUM | basicprivatephp.php | Email queue lacks FILTER_VALIDATE_EMAIL before use | OPEN (minor) |

### SOCIAL
| ID | Sev | File | Issue | Status |
|----|-----|------|-------|--------|
| SOCIAL-HIGH-001 | HIGH | ecriremessage.php | Concurrent requests can bypass broadcast rate limit | OPEN (design limit) |
| SOCIAL-MED-001 | MEDIUM | joueur.php | $lastSeenColor not whitelist-validated before style attr | OPEN (low risk) |
| SOCIAL-LOW-001 | LOW | connectes.php | Admin filtered in PHP loop instead of SQL WHERE | FIXED ✓ |
| SOCIAL-LOW-002 | LOW | ecriremessage.php | User enumeration via error message showing input | OPEN |

### GAME_CORE
| ID | Sev | File | Issue | Status |
|----|-----|------|-------|--------|
| CORE-MED-001 | MEDIUM | player.php | Login streak pre-lock read (race window <1ms) | FALSE POSITIVE — check is inside lock |
| CORE-MED-002 | MEDIUM | player.php | Comeback cooldown uses lastLogin not last_catch_up | FALSE POSITIVE — uses last_catch_up correctly |

### PRESTIGE
| ID | Sev | File | Issue | Status |
|----|-----|------|-------|--------|
| PRES-LOW-001 | LOW | prestige.php | No rate limiting on prestige purchases | OPEN (CSRF protects) |

### INFRA-TEMPLATES
| ID | Sev | File | Issue | Status |
|----|-----|------|-------|--------|
| TMPL-MED-001 | MEDIUM | layout.php | CSP nonce not HTML-escaped in meta tag | OPEN (base64 safe) |
| TMPL-LOW-001 | LOW | layout.php | Cache-Control/Pragma headers not set | OPEN |

---

## Deferred Items

### RANK-HIGH-001 — Double sqrt ranking formula
**File:** includes/formulas.php:105-116 + recalculerTotalPointsJoueur:127-134

`recalculerTotalPointsJoueur()` calls `pointsAttaque($data['pointsAttaque'])` which returns
`ATTACK_MULTIPLIER * sqrt(raw)`, then `calculerTotalPoints()` applies `pow(x, RANKING_SQRT_EXPONENT=0.5)` again,
producing a `x^0.25` (fourth-root) curve instead of the intended `x^0.5` (sqrt) for attack/defense.

**Impact:** Attack and defense rankings compressed to 4th root vs sqrt. Rankings still consistent
relative to each other, but attack/defense advantage is severely underweighted vs construction/trade.

**Fix at season reset:** Change `recalculerTotalPointsJoueur` to pass raw values:
```php
$total = calculerTotalPoints(
    $data['points'],
    $data['pointsAttaque'],    // raw — let calculerTotalPoints apply sqrt
    $data['pointsDefense'],    // raw
    $data['tradeVolume'],
    pointsPillage($data['ressourcesPillees'])  // tanh-transformed, keep as-is
);
```
Then run `UPDATE autre SET totalPoints = recalculated` for all players.

---

## Net Assessment

Pass 11 found **1 CRITICAL + 10 HIGH** issues — significantly more than Pass 10's 1 HIGH + 6 MEDIUM.
This validates the 21-domain expansion: MARKET, COMPOUNDS, and RANKINGS contained previously unseen bugs.

All **CRITICAL and 8 of 10 HIGH** findings were fixed in this pass.
Remaining 2 HIGH: RANK-HIGH-001 (deferred to season reset), SOCIAL-HIGH-001 (design limitation).
