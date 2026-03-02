# DATABASE AUDIT REPORT: The Very Little War

## Summary

| ID | Severity | Category | Title |
|----|----------|----------|-------|
| DB-001 | CRITICAL | Security | Root credentials in repo |
| DB-002 | CRITICAL | Transactions | No transactions in combat |
| DB-003 | CRITICAL | Performance | N+1 loop on map (2,791 queries) |
| DB-004 | HIGH | Engine | 3 MyISAM tables (no ACID) |
| DB-005 | HIGH | Lifecycle | cours table unbounded (102k+ rows) |
| DB-006 | HIGH | Schema | declarations alliance cols VARCHAR not INT |
| DB-007 | HIGH | Performance | revenuEnergie() = 8 queries per call |
| DB-008 | HIGH | Performance | initPlayer() = 8 queries for building queue |
| DB-009 | HIGH | Performance | N+1 in molecule decay loop |
| DB-010 | HIGH | Correctness | prestige queries non-existent medailles table |
| DB-011 | HIGH | Performance | awardPrestigePoints() O(N) per-player loop |
| DB-012 | MEDIUM | Encoding | Mixed latin1/utf8/utf8mb4 charsets |
| DB-013 | MEDIUM | Schema | CSV atoms in VARCHAR(10000) column |
| DB-014 | MEDIUM | Schema | timeMolecule CSV in VARCHAR(1000) |
| DB-015 | MEDIUM | Normalization | totalPoints denormalized cached column |
| DB-016 | MEDIUM | Schema | attack_cooldowns login VARCHAR(50) vs 255 |
| DB-017 | MEDIUM | Schema | prestige login/unlocks too narrow |
| DB-018 | MEDIUM | Schema | connectes has no primary key |
| DB-019 | MEDIUM | Schema | statutforum no PK or unique constraint |
| DB-020 | MEDIUM | Index | No index on membre.x, membre.y |
| DB-021 | MEDIUM | Performance | DELETE vs TRUNCATE in season reset |
| DB-022 | MEDIUM | Safety | Dynamic column name injection risk |
| DB-023 | LOW | Correctness | Stale stored revenue vs live computation |
| DB-024 | LOW | Performance | cours ORDER BY timestamp needs index |
| DB-025 | LOW | Migration | No idempotency guards on ADD COLUMN |
| DB-026 | LOW | Schema | statistiques uses inscrits as PK |
| DB-027 | LOW | Lifecycle | No periodic cleanup of expired cooldowns |

---

## CRITICAL

### DB-001: Root Credentials in Repo
- **File:** includes/connexion.php
- Dev uses root/empty password. VPS has correct creds but repo retains insecure version
- **Fix:** Move to .env file, add to .gitignore

### DB-002: No Transactions in Combat
- **Files:** includes/combat.php, includes/game_actions.php
- 20-30 sequential writes across 8 tables with no BEGIN/COMMIT
- Failure mid-sequence = permanently inconsistent state
- **Fix:** Wrap in mysqli_begin_transaction()/commit() with rollback

### DB-003: N+1 Loop on Map (2,791 queries for 930 players)
- **File:** attaquer.php lines 295-315
- SELECT * FROM membre, then 3 queries per player in loop
- **Fix:** Single JOIN query + PHP-side alliance lookups

---

## HIGH

### DB-004: 3 MyISAM Tables (no ACID)
- declarations, moderation, statutforum
- **Fix:** ALTER TABLE ENGINE=InnoDB

### DB-005: cours Table Unbounded (102k+ rows, no cleanup)
- **Fix:** Add cleanup to remiseAZero(), periodic trim

### DB-006: declarations.alliance1/2 VARCHAR Instead of INT
- Compared to integer idalliance but stored as VARCHAR(255)
- **Fix:** ALTER TABLE MODIFY to INT

### DB-007: revenuEnergie() = 8+ Queries Per Call
- Called from initPlayer(), updateRessources(), repeatedly
- **Fix:** Cache/batch-fetch player data, pass as parameters

### DB-008: initPlayer() = 8 Separate Building Queue Queries
- One SELECT per building type, could be single GROUP BY query
- **Fix:** Single query with GROUP BY batiment

### DB-009: N+1 in Molecule Decay Loop
- Re-reads moleculesPerdues inside loop
- **Fix:** Read once, accumulate in PHP, write once after loop

### DB-010: prestige Queries Non-Existent medailles Table
- calculatePrestigePoints() queries table that doesn't exist
- **Fix:** Use existing medal data from autre table stats

### DB-011: awardPrestigePoints() O(N) Per-Player Loop
- 930 players x 3 queries each during season reset
- **Fix:** Bulk JOIN + batch INSERT

---

## MEDIUM

### DB-012: Mixed Character Sets (latin1/utf8/utf8mb4)
- French accents corrupted in latin1 tables read via utf8 connection
- **Fix:** Convert all to utf8mb4

### DB-013: CSV Atoms in VARCHAR(10000) Column
- pointsProducteur/pointsCondenseur as "1;1;1;1;1;1;1;1"
- **Fix:** Normalize to separate table or add PHP validation

### DB-014: timeMolecule CSV in VARCHAR(1000)

### DB-015: totalPoints Denormalized Cached Column
- Drift between components and cached total
- **Fix:** Generated column or compute at read time

### DB-016: attack_cooldowns Login Columns VARCHAR(50) vs 255
- Truncation risk for long logins
- **Fix:** Widen to VARCHAR(255)

### DB-017: prestige Login/Unlocks Too Narrow

### DB-018: connectes Has No Primary Key
- **Fix:** ADD PRIMARY KEY (ip), use ON DUPLICATE KEY UPDATE

### DB-019: statutforum No PK or Unique Constraint
- **Fix:** Deduplicate, ADD PRIMARY KEY (login, idsujet)

### DB-020: No Index on membre.x, membre.y
- **Fix:** ADD INDEX idx_membre_xy (x, y)

### DB-021: DELETE vs TRUNCATE in Season Reset
- DELETE FROM without WHERE on 8 tables (slow for 100k+ rows)
- **Fix:** Use TRUNCATE TABLE

### DB-022: Dynamic Column Name Injection Risk
- $nomsRes[$numRes] interpolated into SQL (server-side but fragile)
- **Fix:** Add whitelist validation

---

## LOW

### DB-023: Stale Stored Revenue vs Live Computation
### DB-024: cours ORDER BY timestamp Needs Index
### DB-025: No Idempotency Guards on ADD COLUMN in Migrations
### DB-026: statistiques Uses inscrits as PK
### DB-027: No Periodic Cleanup of Expired Cooldowns

---

## Priority Remediation Order
1. DB-002: Wrap combat in transaction (prevents data corruption)
2. DB-004: Convert MyISAM to InnoDB (prerequisite for DB-002)
3. DB-010: Fix missing medailles table (prestige is silently broken)
4. DB-003: Eliminate map N+1 loop (biggest query offender)
5. DB-005: Add cours table pruning
6. DB-007/008/009: Consolidate repeated queries
7. DB-012: Charset migration to utf8mb4
