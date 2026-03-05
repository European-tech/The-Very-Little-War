# Ultra Audit Pass 1 — Domain 7: Database & Data Integrity

**Date:** 2026-03-04
**Pass:** 1 (Broad Scan)
**Subagents:** 3 (Schema Design, Index & Query Optimization, Data Consistency & Transactions)

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 15 |
| MEDIUM | 36 |
| LOW | 9 |
| **Total** | **60** |

---

## Area 1: Schema Design Review

#### P1-D7-001: Mixed charset collision between connection and tables
- **Severity:** HIGH
- **Category:** Database
- **Location:** `includes/connexion.php:20`, all core tables
- **Description:** `connexion.php` sets `utf8mb4` charset, but core tables use `latin1`. Six tables use `utf8`, newer tables use `utf8mb4`. Three charsets coexist. MariaDB silently truncates/corrupts multi-byte characters when PHP sends utf8mb4 into latin1 columns.
- **Impact:** French accented characters corrupted in latin1 tables. Cross-table JOINs with different charsets cause implicit conversion.
- **Fix:** Migrate all tables to `utf8mb4`. Convert `membre` first (FK reference), then all dependent tables.
- **Effort:** L

#### P1-D7-002: `statistiques` uses `inscrits` as PRIMARY KEY — singleton table antipattern
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `statistiques.inscrits` (PK)
- **Description:** Singleton table uses a mutable counter as PK. Race condition on concurrent registrations produces duplicate `inscrits` values.
- **Impact:** Player count permanently under-counted under concurrent registration.
- **Fix:** Add `id INT NOT NULL DEFAULT 1` as PK. Use `UPDATE statistiques SET inscrits = inscrits + 1 WHERE id=1`.
- **Effort:** S

#### P1-D7-003: `actionsattaques.troupes` and `actionsenvoi` store serialized data in TEXT
- **Severity:** HIGH
- **Category:** Database
- **Location:** `actionsattaques.troupes`, `actionsenvoi.ressourcesEnvoyees/Recues`
- **Description:** Semicolon-delimited strings store troop/resource data. No column-level constraints, no indexing, no atomic partial updates. The `'Espionnage'` sentinel mixes type semantics.
- **Impact:** No atomicity on partial updates. Cannot query specific troop counts via SQL.
- **Fix:** Normalize into junction tables. Add `type ENUM('attaque','espionnage')` column.
- **Effort:** L

#### P1-D7-004: `constructions.pointsProducteur/pointsCondenseur` are VARCHAR(10000) arrays
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `constructions.pointsProducteur`, `constructions.pointsCondenseur`
- **Description:** 8-element semicolon-delimited arrays in VARCHAR(10000). No per-atom constraints. Over-allocated.
- **Impact:** Any corruption affects all 8 values. No DB-level validation.
- **Fix:** Normalize into `constructions_atom_points(login, atom_index, producteur_pts, condenseur_pts)`.
- **Effort:** M

#### P1-D7-005: `parties` season archive stores opaque TEXT blobs
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `parties.joueurs`, `parties.alliances`, `parties.guerres`
- **Description:** Season archive data is serialized TEXT. Not queryable via SQL.
- **Impact:** Cannot query historical season data without PHP deserialization.
- **Fix:** Create normalized `season_rankings` and `season_alliances` tables.
- **Effort:** L

#### P1-D7-006: `prestige.unlocks` VARCHAR(255) will silently truncate
- **Severity:** HIGH
- **Category:** Database
- **Location:** `prestige.unlocks` VARCHAR(255)
- **Description:** Comma-separated unlock keys in VARCHAR(255). Exceeding 255 chars silently truncates, causing players to lose purchased unlocks.
- **Impact:** Players permanently lose purchased prestige unlocks.
- **Fix:** Create `prestige_unlocks(login, unlock_key)` junction table with UNIQUE constraint.
- **Effort:** M

#### P1-D7-007: Role flags as separate INT/TINYINT columns
- **Severity:** LOW
- **Category:** Database
- **Location:** `membre.moderateur` INT(11), `membre.codeur` TINYINT(4)
- **Description:** Boolean flags typed as INT(11). Adding new roles requires schema changes.
- **Impact:** Wasted space, no audit trail.
- **Fix:** Convert to `TINYINT(1) NOT NULL DEFAULT 0` minimum, or normalize to roles table.
- **Effort:** S

#### P1-D7-008: `cours` table has no enforced row-count cap
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `cours` table
- **Description:** Market price history grows unbounded. Soft purge only fires on market transactions, not on schedule.
- **Impact:** Table can grow to millions of rows.
- **Fix:** Add scheduled cron or MariaDB event for purging.
- **Effort:** S

#### P1-D7-009: `molecules.nombre` is DOUBLE for discrete integer counts
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `molecules.nombre` DOUBLE
- **Description:** Molecules are discrete units but stored as DOUBLE. Floating-point drift accumulates over many battles.
- **Impact:** Off-by-one display vs combat discrepancies.
- **Fix:** Change to `BIGINT NOT NULL DEFAULT 0`. Use integer arithmetic in PHP.
- **Effort:** S

#### P1-D7-010: `vacances.idJoueur` no FK, uses subquery workaround
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `vacances.idJoueur` INT(11)
- **Description:** Only player table using `membre.id` as FK reference (all others use `login`). No FK constraint. No index on `idJoueur`.
- **Impact:** Orphaned vacation records. Expensive subquery on every page load.
- **Fix:** Add FK and index. Consider migrating to `login` FK for consistency.
- **Effort:** S

#### P1-D7-011: `declarations.alliance1/2` INT with no FK to `alliances`
- **Severity:** HIGH
- **Category:** Database
- **Location:** `declarations.alliance1`, `declarations.alliance2`
- **Description:** War declaration columns reference alliance IDs but have no FK. Alliance deletion orphans war records.
- **Impact:** War records reference deleted alliances, causing PHP errors and missing war bonuses in combat.
- **Fix:** Add FK with `ON DELETE SET NULL`.
- **Effort:** S

#### P1-D7-012: `alliances.tag` and `nom` missing UNIQUE constraint
- **Severity:** HIGH
- **Category:** Database
- **Location:** `alliances.tag`, `alliances.nom`
- **Description:** Uniqueness enforced only in PHP. Race condition allows duplicate tags/names.
- **Impact:** Duplicate tags cause ambiguous URL-based lookups.
- **Fix:** `ADD UNIQUE INDEX uq_alliances_tag (tag), ADD UNIQUE INDEX uq_alliances_nom (nom)`.
- **Effort:** XS

#### P1-D7-013: `membre.email` missing UNIQUE constraint
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `membre.email` VARCHAR(255)
- **Description:** Email uniqueness only in PHP. Race condition allows duplicates. Default `'rien@rien.com'` shared by many accounts.
- **Impact:** Password reset ambiguity, potential account interception.
- **Fix:** Clean up defaults, add UNIQUE INDEX.
- **Effort:** S

#### P1-D7-014: `player_compounds` missing UNIQUE(login, compound_key)
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `player_compounds`
- **Description:** No UNIQUE constraint prevents duplicate compound rows, enabling buff stacking exploits.
- **Impact:** Players can stack compound buffs.
- **Fix:** `ADD UNIQUE INDEX uq_compound_per_player (login, compound_key)`.
- **Effort:** XS

#### P1-D7-015: `actionsformation.idclasse` VARCHAR(50) mixed INT/sentinel abuse
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `actionsformation.idclasse`
- **Description:** Stores integer class IDs and the string `'neutrino'` sentinel in the same column. No FK possible.
- **Impact:** No referential integrity. Implicit type casting in queries.
- **Fix:** Add `type ENUM('normal','neutrino')` column, keep `idclasse` as TINYINT with FK.
- **Effort:** M

#### P1-D7-016: `login_history` and `account_flags` missing FK to `membre`
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `login_history.login`, `account_flags.login`
- **Description:** No FK. Charset mismatch (utf8mb4 vs latin1) prevents FK creation.
- **Impact:** No cleanup on player deletion. JOIN inefficiency.
- **Fix:** Convert `membre` to utf8mb4 first, then add FK with ON DELETE CASCADE.
- **Effort:** S

#### P1-D7-017: `attack_cooldowns.defender` missing FK
- **Severity:** LOW
- **Category:** Database
- **Location:** `attack_cooldowns.defender`
- **Description:** FK exists on `attacker` but not `defender`. Orphaned rows accumulate.
- **Impact:** Stale cooldown entries for deleted defenders.
- **Fix:** Add FK with ON DELETE CASCADE.
- **Effort:** XS

#### P1-D7-018: `ressources` amounts DOUBLE vs revenues BIGINT type mismatch
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `ressources.energie` DOUBLE, `ressources.revenuenergie` BIGINT
- **Description:** Resource amounts stored as DOUBLE but computed from BIGINT revenues. Floating-point drift for large values.
- **Impact:** Display/combat discrepancies for high-resource players.
- **Fix:** Convert amounts to BIGINT. Use integer arithmetic in PHP.
- **Effort:** M

#### P1-D7-019: `sanctions.joueur` VARCHAR(30) too short, no FK
- **Severity:** LOW
- **Category:** Database
- **Location:** `sanctions.joueur`, `sanctions.moderateur`
- **Description:** VARCHAR(30) when all other player columns are VARCHAR(255). No FK, no auto-increment on PK.
- **Impact:** Cannot sanction players with logins >30 chars. No cleanup on deletion.
- **Fix:** Widen to VARCHAR(255), add FK, add AUTO_INCREMENT.
- **Effort:** XS

#### P1-D7-020: `grades` table has no PRIMARY KEY
- **Severity:** HIGH
- **Category:** Database
- **Location:** `grades` table
- **Description:** No PK or UNIQUE on `login`. A player can appear multiple times with different alliances. No FK to `membre` or `alliances`.
- **Impact:** Duplicate grades cause incorrect alliance membership lookups. Orphaned records on deletion.
- **Fix:** `ADD PRIMARY KEY (login)`. Add FKs to `membre` and `alliances`.
- **Effort:** S

---

## Area 2: Index & Query Optimization

#### P1-D7-021: Full table scan on `prestige.unlocks` LIKE search during season end
- **Severity:** HIGH
- **Category:** Database
- **Location:** `includes/player.php:964`
- **Description:** `WHERE unlocks LIKE '%debutant_rapide%'` leading-wildcard LIKE forces full table scan. Runs during critical season-end maintenance.
- **Impact:** Blocks season-end transaction as player count grows.
- **Fix:** Normalize unlocks into junction table, or add boolean column with index.
- **Effort:** M

#### P1-D7-022: Missing composite index on `messages(destinataire, statut)` for unread badge
- **Severity:** HIGH
- **Category:** Database
- **Location:** `includes/basicprivatehtml.php:259`
- **Description:** `COUNT(*) WHERE destinataire=? AND statut=0` runs on EVERY private page load. Only single-column index exists.
- **Impact:** Heap lookup for statut check on every page load.
- **Fix:** `ADD INDEX idx_messages_dest_statut (destinataire, statut)`.
- **Effort:** XS

#### P1-D7-023: Missing composite index on `rapports(destinataire, statut)`
- **Severity:** HIGH
- **Category:** Database
- **Location:** `includes/basicprivatehtml.php:266`
- **Description:** Same pattern as P1-D7-022 for reports. `longtext contenu` makes heap reads expensive.
- **Impact:** Expensive heap reads on every page load.
- **Fix:** `ADD INDEX idx_rapports_dest_statut (destinataire, statut)`.
- **Effort:** XS

#### P1-D7-024: Market price query repeated 3 times per page load
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `marche.php:10, :237, :358`
- **Description:** Same `SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1` executed 3 times in one request.
- **Impact:** 3 unnecessary round-trips per market page.
- **Fix:** Fetch once at top, reuse variable.
- **Effort:** XS

#### P1-D7-025: Market chart fetches 1000 rows with SELECT *
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `marche.php:608`
- **Description:** Fetches all columns for 1000 rows when only 2 columns needed.
- **Impact:** Unnecessary data transfer.
- **Fix:** Project only `tableauCours, timestamp`.
- **Effort:** S

#### P1-D7-026: `SELECT * FROM autre` full table scan for message recipient list
- **Severity:** HIGH
- **Category:** Database
- **Location:** `ecriremessage.php:21`
- **Description:** Fetches ALL columns for ALL players just to populate a name dropdown.
- **Impact:** Full table scan including TEXT columns for every message compose page.
- **Fix:** `SELECT login FROM autre ORDER BY login ASC`.
- **Effort:** XS

#### P1-D7-027: Expression-based ORDER BY prevents index usage
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `classement.php:455`
- **Description:** `ORDER BY (pertes1 + pertes2)` forces filesort. `pertesTotales` column already exists and is maintained.
- **Impact:** Full sort of all wars on every war leaderboard page.
- **Fix:** Replace with `ORDER BY pertesTotales DESC`. Add composite index.
- **Effort:** XS

#### P1-D7-028: Visitor cleanup runs on every login page load
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `includes/basicpublicphp.php:37`
- **Description:** `SELECT + DELETE` loop for expired visitors runs unconditionally on every unauthenticated page.
- **Impact:** Unnecessary work on every login page.
- **Fix:** Gate to 1-in-10 requests or use direct DELETE.
- **Effort:** XS

#### P1-D7-029: N+1 double-lookup in allianceResearchBonus/Level
- **Severity:** HIGH
- **Category:** Database
- **Location:** `includes/db_helpers.php:52-88`
- **Description:** Two sequential queries (autre→alliances) called 6+ times per request. Up to 20 DB round-trips.
- **Impact:** Measurable latency on combat/market pages.
- **Fix:** Single JOIN query with request-level caching.
- **Effort:** S

#### P1-D7-030: Missing covering index for prestige ranking
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `includes/player.php:868`
- **Description:** `SELECT login, totalPoints FROM autre ORDER BY totalPoints DESC` without covering index requires heap lookups.
- **Impact:** Suboptimal season-end prestige distribution.
- **Fix:** `ADD INDEX idx_autre_totalpoints_login (totalPoints DESC, login)`.
- **Effort:** XS

#### P1-D7-031: Missing covering index for online player list
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `connectes.php:22`
- **Description:** Single-column index on `derniereConnexion` requires heap lookup for `login`.
- **Impact:** Full ordered scan for online list.
- **Fix:** Composite index `(derniereConnexion DESC, login)`. Add LIMIT clause.
- **Effort:** XS

#### P1-D7-032: Missing index on `sanctions.joueur`
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `forum.php:21`, `sujet.php:88`
- **Description:** No index on `joueur`. Full table scan on every forum page load.
- **Impact:** Degrades with active moderation.
- **Fix:** `ADD INDEX idx_sanctions_joueur (joueur)`.
- **Effort:** XS

#### P1-D7-033: Missing index on `sujets.auteur` for GROUP BY
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `classement.php:569`
- **Description:** `GROUP BY auteur` requires full scan + temporary table sort.
- **Impact:** Full scan on every forum leaderboard page.
- **Fix:** `ADD INDEX idx_sujets_auteur (auteur)`.
- **Effort:** XS

#### P1-D7-034: Missing composite index on `sujets(idforum, statut, timestamp)`
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `listesujets.php:69`
- **Description:** Single-column index on `idforum` requires filesort for `ORDER BY statut, timestamp DESC`.
- **Impact:** Filesort on every forum topic list page.
- **Fix:** Replace with composite `(idforum, statut, timestamp DESC)`.
- **Effort:** XS

#### P1-D7-035: Full prestige table scan for leaderboard decoration
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `classement.php:132`
- **Description:** `SELECT login, total_pp FROM prestige` fetches all rows for 20-player page display.
- **Impact:** Linear growth with player count.
- **Fix:** JOIN prestige into leaderboard query or fetch only visible page logins.
- **Effort:** S

#### P1-D7-036: Missing index on `reponses.auteur` — COUNT on every profile
- **Severity:** LOW
- **Category:** Database
- **Location:** `includes/display.php:269`
- **Description:** `COUNT(*) FROM reponses WHERE auteur=?` runs per player name render. No index on `auteur`.
- **Impact:** 20 full scans on leaderboard page.
- **Fix:** `ADD INDEX idx_reponses_auteur (auteur)`.
- **Effort:** XS

#### P1-D7-037: FIELD() function in ORDER BY prevents index usage
- **Severity:** LOW
- **Category:** Database
- **Location:** `admin/multiaccount.php:122`
- **Description:** `ORDER BY FIELD(severity, ...)` forces filesort on admin_alerts.
- **Impact:** Full scan on admin dashboard.
- **Fix:** Add stored generated column with numeric priority, index it.
- **Effort:** S

#### P1-D7-038: Missing composite index on `login_history(login, timestamp)`
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `includes/multiaccount.php:225`
- **Description:** Two single-column indexes; only one used per query. Post-filter on heap rows.
- **Impact:** Suboptimal multi-account correlation queries.
- **Fix:** `ADD INDEX idx_lh_login_ts (login, timestamp)`.
- **Effort:** XS

#### P1-D7-039: Leaderboard sort columns lack indexes
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `classement.php:114`
- **Description:** Dynamic sort on `points`, `victoires`, `ressourcesPillees`, etc. — only `totalPoints` has index.
- **Impact:** Filesort on all leaderboard tabs except default.
- **Fix:** Add indexes for top-3 sort columns.
- **Effort:** S

#### P1-D7-040: OFFSET-based pagination on all list queries
- **Severity:** LOW
- **Category:** Database
- **Location:** `classement.php`, `rapports.php`, `listesujets.php`
- **Description:** `LIMIT offset, count` requires scanning and discarding offset rows. Combined with `longtext` columns, expensive at high offsets.
- **Impact:** Degraded pagination for active players with many reports.
- **Fix:** Cursor-based pagination for `rapports`. OFFSET acceptable for small tables.
- **Effort:** M

---

## Area 3: Data Consistency & Transactions

#### P1-D7-041: Resource transfer (actionsenvoi) not in transaction
- **Severity:** HIGH
- **Category:** Database
- **Location:** `marche.php:101-127`
- **Description:** `INSERT INTO actionsenvoi` and `UPDATE ressources` are separate auto-commit operations. Crash between them creates free resource duplication.
- **Impact:** Resource duplication exploit: sender keeps atoms AND convoy delivers them.
- **Fix:** Wrap in `withTransaction()` with `SELECT ... FOR UPDATE` on resources.
- **Effort:** S

#### P1-D7-042: Resource convoy arrival not transactional
- **Severity:** HIGH
- **Category:** Database
- **Location:** `includes/game_actions.php:526-599`
- **Description:** DELETE from actionsenvoi and UPDATE ressources are not transactional. Crash loses resources in transit. Concurrent access can double-credit.
- **Impact:** Resources vanish in transit or double-credit on concurrent access.
- **Fix:** Wrap in `withTransaction()` with `SELECT ... FOR UPDATE` on actionsenvoi.
- **Effort:** S

#### P1-D7-043: Combat transaction may have nested transaction boundary splits
- **Severity:** HIGH
- **Category:** Database
- **Location:** `includes/game_actions.php:109-351`, `includes/combat.php`
- **Description:** Outer combat transaction includes combat.php which calls functions that may use `withTransaction()`. MariaDB silently commits on inner `BEGIN`.
- **Impact:** Partial combat state: attacker loses molecules but defender keeps resources.
- **Fix:** Audit all functions called within combat for nested transaction calls.
- **Effort:** M

#### P1-D7-044: Alliance `pointstotaux` cache staleness-prone
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `includes/player.php:713-733`
- **Description:** `alliances.pointstotaux` is only updated by periodic recalculation, not on each player score change.
- **Impact:** Season VP awarded based on stale alliance totals.
- **Fix:** Call `recalculerStatsAlliances()` in season-end transaction, or use delta-update on score change.
- **Effort:** M

#### P1-D7-045: updateRessources CAS guard has read-modify window
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `includes/game_resources.php:161-211`
- **Description:** CAS on tempsPrecedent succeeds, then reads resources without FOR UPDATE. Concurrent market/combat can modify between read and write.
- **Impact:** Market purchases silently overwritten by concurrent resource update.
- **Fix:** Move resource SELECT inside transaction with FOR UPDATE lock.
- **Effort:** M

#### P1-D7-046: Alliance invitation TOCTOU on member count
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `alliance.php:156-172`
- **Description:** Member count check outside transaction without FOR UPDATE. Two concurrent acceptances bypass cap.
- **Impact:** Alliance exceeds maximum member cap.
- **Fix:** Move count check inside transaction with FOR UPDATE.
- **Effort:** S

#### P1-D7-047: Season end Phase 2 not in same transaction as Phase 1
- **Severity:** HIGH
- **Category:** Database
- **Location:** `includes/player.php:816-971`
- **Description:** VP award (Phase 1) and data reset (Phase 2) are separate transactions. Crash between them awards VP without resetting.
- **Impact:** Permanent inconsistency: VP awarded but season never resets.
- **Fix:** Combine phases or add `season_reset_phase` flag for crash recovery.
- **Effort:** M

#### P1-D7-048: inscrire() reads inscrits outside transaction before increment
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `includes/player.php:31-32`
- **Description:** Reads `inscrits` before `withTransaction()`. Concurrent registrations both compute same value.
- **Impact:** Player counter permanently under-counts.
- **Fix:** Use `UPDATE statistiques SET inscrits = inscrits + 1`.
- **Effort:** XS

#### P1-D7-049: Espionage launch not atomic
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `attaquer.php:36-40`
- **Description:** INSERT actionsattaques and UPDATE neutrinos are separate auto-commits. Crash gives free espionage.
- **Impact:** Free espionage by crash exploitation or two-tab race.
- **Fix:** Wrap in `withTransaction()` with FOR UPDATE on neutrinos.
- **Effort:** XS

#### P1-D7-050: Compound synthesis resource check without FOR UPDATE
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `includes/compounds.php:65-88`
- **Description:** Plain SELECT before transaction. Two-tab exploit can double-spend or over-draft.
- **Impact:** Negative atom balances or double compound creation.
- **Fix:** Move availability check inside transaction with FOR UPDATE.
- **Effort:** S

#### P1-D7-051: Molecule formation queue TOCTOU without FOR UPDATE
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `armee.php:128-140`
- **Description:** Last formation's `fin` timestamp read inside transaction but without FOR UPDATE. Concurrent requests create overlapping formations.
- **Impact:** Formation queue corruption: both formations run simultaneously.
- **Fix:** Add `FOR UPDATE LIMIT 1` to formation queue lookup.
- **Effort:** XS

#### P1-D7-052: Combat defender resource read not locked
- **Severity:** HIGH
- **Category:** Database
- **Location:** `includes/combat.php:362-368`
- **Description:** Defender resources read with plain SELECT within combat transaction. Concurrent market/combat can modify between read and pillage UPDATE.
- **Impact:** Defender's market purchase silently deleted by combat overwrite.
- **Fix:** Add `FOR UPDATE` to defender resource SELECT.
- **Effort:** S

#### P1-D7-053: recalculerStatsAlliances() has no transaction
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `includes/player.php:713-733`
- **Description:** Iterates all alliances reading/writing without transaction. Concurrent changes corrupt totals. Long iteration holds outer transaction.
- **Impact:** Race-condition-corrupted alliance totals at season end.
- **Fix:** Use single aggregating SQL: `UPDATE alliances SET pointstotaux = (SELECT SUM(totalPoints) FROM autre WHERE idalliance = alliances.id)`.
- **Effort:** M

#### P1-D7-054: Resource transfer receiver credit has no concurrent arrival guard
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `includes/game_actions.php:567-599`
- **Description:** No `mysqli_affected_rows()` check after convoy DELETE. Two concurrent requests can double-credit receiver.
- **Impact:** Double resource credit from single convoy.
- **Fix:** Add `if (mysqli_affected_rows($base) === 0) continue;` after DELETE.
- **Effort:** XS

#### P1-D7-055: Construction queue count check outside transaction
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `constructions.php:236-338`
- **Description:** Queue count check at line 236 not repeated inside transaction. Two concurrent requests bypass 2-item queue cap.
- **Impact:** Player can have more than 2 buildings in construction queue.
- **Fix:** Move count check inside transaction with FOR UPDATE.
- **Effort:** S

#### P1-D7-056: Season reset doesn't archive prestige state before cleanup
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `includes/player.php:923-971`
- **Description:** No phase flag to prevent double-awarding on crash recovery re-trigger.
- **Impact:** VP and PP awarded twice if admin re-triggers after crash.
- **Fix:** Add `season_reset_phase` flag to `statistiques`.
- **Effort:** M

#### P1-D7-057: ajouterPoints reads/writes outside transaction context
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `includes/player.php:77-96`
- **Description:** FOR UPDATE inside function works within combat transaction, but construction points calls (type 0) have no surrounding transaction.
- **Impact:** Brief leaderboard ranking inconsistency.
- **Fix:** Ensure all callers wrap in transaction, or merge `recalculerTotalPointsJoueur` into same UPDATE.
- **Effort:** M

#### P1-D7-058: Market price history diverges from trades under concurrency
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `marche.php:180-353`
- **Description:** Price computed from page-load snapshot, not transaction-time value. Two concurrent buys apply only one price impact.
- **Impact:** Price manipulation via simultaneous orders.
- **Fix:** Re-read latest price with FOR UPDATE inside transaction.
- **Effort:** S

#### P1-D7-059: Alliance `energieAlliance` concurrent update gap
- **Severity:** MEDIUM
- **Category:** Database
- **Location:** `don.php:28`, `alliance.php:87-134`
- **Description:** Donation and research upgrade both lock alliance row (correctly), but `energieDonnee` in `autre` is read stale.
- **Impact:** Displayed donation percentages inconsistent.
- **Fix:** Ensure fresh query for `energieTotaleRecue` display.
- **Effort:** XS

#### P1-D7-060: supprimerJoueur doesn't recalculate alliance pointstotaux
- **Severity:** LOW
- **Category:** Database
- **Location:** `includes/player.php:754-785`
- **Description:** After deleting a player, their alliance retains the player's points in cached total.
- **Impact:** Alliance ranked higher than it should be until next recalculation.
- **Fix:** Subtract deleted player's points from alliance total in same transaction.
- **Effort:** XS
