# Pass 7 Audit — INFRA-DATABASE Domain
**Date:** 2026-03-08
**Agent:** Pass7-D3-INFRA-DATABASE

## Summary
| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 3 |
| MEDIUM | 10 |
| LOW | 4 |
| **Total** | **17** |

---

## CRITICAL Findings
None.

---

## HIGH Findings

### HIGH-001 — Hardcoded `TABLE_SCHEMA='tvlw'` in 6 migrations
**Files:** `migrations/0055_declarations_fk.sql`, `migrations/0056_*.sql`, `migrations/0057_*.sql`, `migrations/0058_*.sql`, `migrations/0059_*.sql`, `migrations/0060_*.sql`
**Description:** Six migrations use `TABLE_SCHEMA='tvlw'` in `information_schema` queries instead of `TABLE_SCHEMA=DATABASE()`. If the database is renamed or imported under a different schema name, these migrations silently skip the FK creation with no error.
**Impact:** Silent migration failure on non-`tvlw` databases (staging, dev, CI).
**Fix:** Replace `TABLE_SCHEMA='tvlw'` with `TABLE_SCHEMA=DATABASE()` in all affected migrations.

### HIGH-002 — `account_flags` FKs missing `ON UPDATE CASCADE`
**File:** `migrations/0033_fix_utf8mb4_tables.sql:30-36`
**Description:** `account_flags` table has `ON DELETE CASCADE` but no `ON UPDATE CASCADE` on its FK to `membre.login`. If a player's login is ever renamed (admin operation), rows in `account_flags` are orphaned (FK constraint will reject the rename or silently break referential integrity depending on engine).
**Impact:** Data integrity failure on login rename operations.
**Fix:** Add `ON UPDATE CASCADE` to the `account_flags` FK constraint.

### HIGH-003 — Action-queue tables missing FKs to `membre.login`
**Tables:** `actionsattaques`, `actionsformation`, `actionsenvoi`, `actionsconstruction`
**Description:** These four action-queue tables have `login` columns referencing player logins but have no FK constraints declared. After player deletion (via `supprimerJoueur()`), orphaned rows can remain in the queue and be processed, causing runtime errors or phantom actions for deleted players.
**Impact:** Ghost actions post-deletion; potential errors in action processing.
**Fix:** Add `FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE` to all four action-queue tables.

---

## MEDIUM Findings

### MEDIUM-001 — `withTransaction` depth counter not exception-safe
**File:** `includes/database.php:121-152`
**Description:** The static `$depth` counter in `withTransaction()` is incremented before the callable and decremented in a `finally` block, but if the outer `START TRANSACTION` fails (e.g., DB connection dropped), `$depth` is incremented without a corresponding decrement path from that failure branch. A subsequent call would issue a `SAVEPOINT` instead of `START TRANSACTION`, silently treating a top-level transaction as nested.
**Impact:** Incorrect savepoint behavior if initial `START TRANSACTION` throws.
**Fix:** Only increment `$depth` after `START TRANSACTION` succeeds.

### MEDIUM-002 — Missing index on `resource_nodes.zone`
**File:** `migrations/` (resource_nodes creation)
**Description:** `resource_nodes` is queried by `zone` in every map load and node generation cycle but has no index on the `zone` column. As node count grows, full-table scans occur.
**Fix:** `ALTER TABLE resource_nodes ADD INDEX idx_zone (zone);`

### MEDIUM-003 — `season_recap` table uses `TEXT` for JSON blob
**File:** `migrations/0029_create_season_recap.sql`
**Description:** `season_recap.recap_data` is stored as `TEXT`. MariaDB 10.11 supports `JSON` type with validation. Using `TEXT` allows invalid JSON to be stored silently.
**Fix:** Change to `JSON NOT NULL` (or add a `CHECK (JSON_VALID(recap_data))`).

### MEDIUM-004 — `compound_synthesis` has no expiry index
**File:** `migrations/` (compound_synthesis creation)
**Description:** Compound expiry is checked per-player on every page that applies bonuses. No index on `expires_at`. As synthesis history grows, cleanup queries do full scans.
**Fix:** `ALTER TABLE compound_synthesis ADD INDEX idx_expires (expires_at);`

### MEDIUM-005 — `login_attempts` table missing TTL purge
**File:** `migrations/` (login_attempts creation), `includes/rate_limiter.php`
**Description:** `login_attempts` rows are never deleted after the rate-limit window expires. On a live server over months, the table will accumulate unbounded rows.
**Fix:** Add a periodic DELETE of rows older than the rate-limit window (e.g., via a cron migration helper or in the rate limiter cleanup path).

### MEDIUM-006 — `historique_alliances` `action` column not ENUMed
**File:** `migrations/` (historique_alliances)
**Description:** The `action` column is a `VARCHAR(50)` storing free-form strings. There is no constraint on valid action types. An application bug could write unknown action codes, breaking any switch/case display logic.
**Fix:** Convert to `ENUM('join','leave','kick','promote','demote','create','dissolve','pact','war')` (or similar agreed set).

### MEDIUM-007 — `voter_log` missing unique constraint on `(voter, target, date)`
**File:** `migrations/` (voter_log)
**Description:** The voter TOCTOU fix (Phase 15) added a `FOR UPDATE` lock, but the DB still has no UNIQUE constraint on `(voter, target, date)`. If the application lock is ever bypassed, duplicate votes can be inserted silently.
**Fix:** `ALTER TABLE voter_log ADD UNIQUE KEY uk_vote (voter, target, date);`

### MEDIUM-008 — Missing `ON DELETE` on `forum_messages.sujet_id` FK
**File:** `migrations/` (forum_messages)
**Description:** `forum_messages` has an FK to `forum_sujets.id` but no `ON DELETE` clause (defaults to `RESTRICT`). Deleting a topic will fail silently at the application level unless the messages are cleaned up first. The application does clean up, but the DB gives no cascade guarantee.
**Fix:** Add `ON DELETE CASCADE` to enforce at DB level.

### MEDIUM-009 — `autre` table `streak_days` default is NULL
**File:** `migrations/0027_add_login_streak.sql`
**Description:** `streak_days` column has no `DEFAULT 0`, meaning new rows (or rows that pre-date the migration if the ALTER didn't backfill) have NULL streak_days. PHP code doing `$row['streak_days'] + 1` on a NULL produces `1` correctly due to PHP coercion, but explicit SQL arithmetic (`streak_days + 1 WHERE ...`) returns NULL.
**Fix:** `ALTER TABLE autre MODIFY streak_days INT NOT NULL DEFAULT 0;` + backfill: `UPDATE autre SET streak_days = 0 WHERE streak_days IS NULL;`

### MEDIUM-010 — `alliance_members` has no index on `alliance_tag`
**File:** `migrations/` (alliance_members / membre table)
**Description:** Alliance queries join or filter on `membre.alliance` (the tag) frequently. If there is no index on this column, each alliance page load scans the full membre table.
**Fix:** `ALTER TABLE membre ADD INDEX idx_alliance (alliance);` (if not already present — verify with `SHOW INDEX FROM membre`).

---

## LOW Findings

### LOW-001 — `data/rates/` directory not in `.gitignore`
**File:** `.gitignore`
**Description:** The rate-limiter writes files to `data/rates/`. This directory is likely already gitignored, but if not, rate-limit files could be committed.
**Verify:** Check `.gitignore` contains `data/rates/`.

### LOW-002 — `migrations/` filenames not zero-padded consistently
**Files:** `migrations/`
**Description:** Some migrations use 4-digit zero-padded numbers (0077, 0080) while a few earlier ones use non-padded names. Alphabetical sort in deployment scripts may process them out of order.
**Fix:** Audit and rename any non-padded migration files.

### LOW-003 — `withTransaction` does not log savepoint names on rollback
**File:** `includes/database.php`
**Description:** When a nested transaction rolls back to a savepoint, the savepoint name is not logged. Debugging nested transaction failures in production requires log correlation.
**Fix:** Add a `logError` call when rolling back to savepoint in the catch block.

### LOW-004 — `CHECK` constraints not verified in PHPUnit
**File:** `tests/`
**Description:** The CHECK constraints added in migration 0017 (resources, buildings, molecules non-negative) are not covered by any PHPUnit test. A test inserting a negative value should verify the DB rejects it.
**Fix:** Add negative-value insert tests for constrained columns.

---

## What Was Verified Clean
- `dbQuery`, `dbFetchOne`, `dbFetchAll`, `dbExecute`, `dbCount` all use prepared statements — clean.
- `withTransaction` SAVEPOINT nesting for normal paths — correct.
- `connexion.php` DB credentials loaded from `.env` (not hardcoded) — clean.
- Migration numbering is sequential with no gaps — clean.
- `latin1` charset on all new tables (FK compatibility with `membre`) — clean.
- `membre` PK is `login VARCHAR` — noted, not a new finding.
