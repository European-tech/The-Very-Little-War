# SCHEMA-CROSS — Cross-Domain Database Schema Analysis

**Date:** 2026-03-03
**Scope:** Full schema audit — SQL dump cross-referenced against PHP codebase (28 original tables + 14 migration files)
**Database:** MariaDB 10.11, engine mix InnoDB/MyISAM (partially resolved by migration 0013)
**Methodology:** SQL dump analysis + migration trace + PHP code cross-reference

---

## Executive Summary

The schema has improved substantially through 14 migration files applied post-dump. However, several structural weaknesses remain: zero foreign key enforcement despite dense inter-table relationships, two critical missing tables that PHP code directly references (`prestige`, `attack_cooldowns`) not present in the baseline dump, a significant number of serialised-data columns that resist querying, and a charset inconsistency across tables that will cause UTF-8 collation mismatches. No migration drift protection exists.

---

## 1. Schema Completeness — Missing Foreign Keys, Indexes, and Constraints

### CRITICAL — No Foreign Key Constraints Anywhere

**Tables affected:** All inter-table relationships
**Evidence:** The SQL dump defines zero `FOREIGN KEY` constraints. The ALTER TABLE blocks after each CREATE contain only `ADD PRIMARY KEY` entries (and two indexes on `ressources.azote` and `ressources.login`).

The PHP code enforces referential integrity in application code only. This means:
- A player deletion may leave orphan rows if any of the 11 deletion calls in `supprimerJoueur()` fail mid-transaction (no cascade).
- An `alliances` row deletion requires manual cleanup of `autre.idalliance`, `grades.idalliance`, `invitations.idalliance`, `declarations.alliance1/alliance2`.
- An `autre` login mismatch with `membre` login silently corrupts data.

**Current state:** `supprimerJoueur()` in `player.php` lines 757–776 manually deletes from 11 tables but does so outside a transaction wrapper (each `dbExecute` is its own implicit transaction), so a mid-sequence failure leaves partial orphans.

**Recommendation:**
```sql
ALTER TABLE autre ADD CONSTRAINT fk_autre_membre
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE;
ALTER TABLE ressources ADD CONSTRAINT fk_ressources_membre
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE;
ALTER TABLE constructions ADD CONSTRAINT fk_constructions_membre
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE;
ALTER TABLE molecules ADD CONSTRAINT fk_molecules_membre
    FOREIGN KEY (proprietaire) REFERENCES membre(login) ON DELETE CASCADE;
ALTER TABLE grades ADD CONSTRAINT fk_grades_alliance
    FOREIGN KEY (idalliance) REFERENCES alliances(id) ON DELETE CASCADE;
ALTER TABLE invitations ADD CONSTRAINT fk_invitations_alliance
    FOREIGN KEY (idalliance) REFERENCES alliances(id) ON DELETE CASCADE;
ALTER TABLE prestige ADD CONSTRAINT fk_prestige_membre
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE;
```
Note: `declarations.alliance1/alliance2` were VARCHAR in the dump; migration 0013 changed them to INT but skipped adding a foreign key. These should reference `alliances.id`.

---

### HIGH — Missing Indexes on High-Frequency Query Columns

Migration 0001 and 0014 added many indexes but several important ones remain absent:

| Table | Column(s) | Missing Index | Query Pattern |
|---|---|---|---|
| `actionsattaques` | `(attaquant, tempsAttaque)` | Added by migration 0014 — verify live | `WHERE attaquant=? OR defenseur=? ORDER BY tempsAttaque` |
| `actionsattaques` | `attaqueFaite` | Added by migration 0014 | `WHERE attaqueFaite=0` |
| `attack_cooldowns` | `expires` | Present in 0004 | `WHERE expires > ?` (TTL cleanup) |
| `cours` | `timestamp` | Added in 0013 | `ORDER BY id DESC LIMIT 1` but timestamp filtering also used |
| `membre` | `(x, y)` | Added in 0013 | Map proximity queries |
| `autre` | `totalPoints` | NOT added anywhere | `ORDER BY totalPoints DESC` in `classement.php` and `prestige.php` |
| `autre` | `idalliance` | Added in 0001 | Multiple JOIN/lookup patterns |
| `rapports` | `(destinataire, timestamp)` | Only `destinataire` index | `WHERE destinataire=? ORDER BY timestamp DESC` |
| `reponses` | `(idsujet, timestamp)` | Only `idsujet` index | Thread pagination |
| `prestige` | `total_pp` | None | `ORDER BY total_pp DESC` (leaderboard) |
| `actionsformation` | `(login, fin)` | Separate indexes only | `WHERE login=? AND debut<?` |

**`autre.totalPoints` index is the most impactful gap.** Every page load that renders rankings (classement.php, prestige.php `awardPrestigePoints()`) scans the full `autre` table sorted by `totalPoints`. With 930+ registered players this becomes a full table scan on every visit.

**Recommendation:**
```sql
ALTER TABLE autre ADD INDEX idx_autre_totalPoints (totalPoints);
ALTER TABLE rapports ADD INDEX idx_rapports_dest_time (destinataire, timestamp);
ALTER TABLE reponses ADD INDEX idx_reponses_sujet_time (idsujet, timestamp);
ALTER TABLE prestige ADD INDEX idx_prestige_pp (total_pp);
```

---

### HIGH — `connectes` Table Has No Primary Key

**Table:** `connectes`
**Schema:** `ip VARCHAR(15), timestamp INT` — no PRIMARY KEY declared
**Migration 0013** includes a comment `-- ALTER TABLE connectes ADD PRIMARY KEY (ip); -- Run manually if connectes has no PK` — this was commented out, meaning the migration never ran.

Without a primary key InnoDB creates a hidden 6-byte row ID, making lookups by IP a full table scan. This table is read on every page load for the online-users count.

**Recommendation:**
```sql
ALTER TABLE connectes ADD PRIMARY KEY (ip);
-- or, if duplicate IPs are intentional:
ALTER TABLE connectes ADD INDEX idx_connectes_ip_ts (ip, timestamp);
```

---

## 2. Data Type Appropriateness

### HIGH — `membre.login` Was TEXT (Fixed in Migration 0002)

**Original:** `membre.login TEXT NOT NULL` — TEXT columns cannot be directly indexed in MySQL/MariaDB without a prefix length, making login lookups either unindexed or using prefix indexes (inaccurate for equality).

Migration 0002 corrected this to `VARCHAR(255)` and added the index. **Verify this migration was applied on the live VPS.**

---

### HIGH — `declarations.alliance1` and `alliance2` Were VARCHAR, Now INT (Migration 0013)

**Original:** `alliance1 VARCHAR(255)`, `alliance2 VARCHAR(255)` — these store alliance IDs (integers) as strings. Every JOIN or comparison requires implicit casting.

Migration 0013 applied `MODIFY alliance1 INT DEFAULT NULL; MODIFY alliance2 INT DEFAULT NULL;` — but only after existing data may have been stored as strings. **Verify cast succeeded with no data loss on live DB.**

Additionally, the migration left `NULL` as the default; the original had them NOT NULL. This is a semantic change that may allow malformed war declarations.

---

### MEDIUM — `ressources.*` Resource Columns Are DOUBLE

**Columns:** `ressources.energie`, `hydrogene`, `carbone`, `oxygene`, `iode`, `brome`, `chlore`, `soufre`, `azote`, `terrain`

All resource quantities are `DOUBLE`. Floating-point representation of game quantities creates accumulation errors. After many incremental `UPDATE ressources SET carbone = carbone + ?` operations, values like `63.999999999998` instead of `64` will appear. PHP's `round()` calls in display code mask this but comparisons (e.g., `WHERE carbone >= 100`) can silently fail.

**Recommendation:** Use `DECIMAL(20,4)` for all resource columns. This provides exact arithmetic up to 16 significant digits with 4 decimal places — sufficient for all current formulas.

---

### MEDIUM — `molecules.nombre` Is DOUBLE

**Table:** `molecules`, **Column:** `nombre DOUBLE`

Molecule counts undergo constant `floor()` and `ceil()` operations in PHP but are stored as DOUBLE. The game logic routinely stores and retrieves fractional molecules during decay calculations. This is intentional but the DOUBLE type means after enough combat/decay cycles values drift from their intended precision.

The existing code already calls `ceil($classeDefenseur1['nombre'])` when reading for combat — this compensates. However, storing DECIMAL(20,6) would be more honest about the intent.

---

### MEDIUM — `actionsformation.formule` is VARCHAR(1000)

**Table:** `actionsformation`, **Column:** `formule VARCHAR(1000)`

A molecule formula (e.g., `C10H8O4N2Cl3S1Br5I2`) is at most ~50 characters. VARCHAR(1000) wastes memory in the InnoDB buffer pool.

**Recommendation:** `VARCHAR(100)` is sufficient.

---

### MEDIUM — `constructions.pointsProducteur` and `pointsCondenseur` Are VARCHAR(10000)

**Columns:** `constructions.pointsProducteur VARCHAR(10000)`, `pointsCondenseur VARCHAR(10000)`

These store semicolon-delimited integer arrays, e.g. `1;1;1;1;1;1;1;1` (8 atom type levels). The maximum meaningful content is 8 integers of up to ~5 digits each = ~50 characters. VARCHAR(10000) is 200x oversized.

Worse: the data is a serialised array that cannot be queried by atom type without `LIKE '%val%'` patterns. See Section 3 for normalization recommendations.

**Recommendation:** `VARCHAR(100)` minimum, or normalise to a separate table (see Section 3).

---

### LOW — `autre.timeMolecule` VARCHAR(1000) DEFAULT '0,0,0,0'

**Table:** `autre`, **Column:** `timeMolecule VARCHAR(1000) NOT NULL DEFAULT '0,0,0,0'`

Stores four comma-separated timestamps. Never queried by individual time value. Unlikely to grow beyond 80 characters. VARCHAR(1000) is oversized but low priority.

---

### LOW — `actionsattaques.troupes` TEXT with 'Espionnage' Sentinel

**Table:** `actionsattaques`, **Column:** `troupes TEXT`

This column serves dual purpose: stores molecule counts as semicolons-delimited string (`1000;500;250;0;`) OR the string literal `'Espionnage'` to denote a spy mission. This type overloading makes queries harder and prevents type-safe operations.

**Recommendation:** Add a TINYINT `type` column: `0 = attack, 1 = espionage`. Keep `troupes` for attack data only.

---

### LOW — `sanctions.joueur` and `moderateur` Are VARCHAR(30)

**Table:** `sanctions`, **Columns:** `joueur VARCHAR(30)`, `moderateur VARCHAR(30)`

All other tables use VARCHAR(255) for login references. A player name of 31+ characters would silently truncate here. Since registration doesn't validate max length against this column's constraint, this is a silent corruption risk.

**Recommendation:** `VARCHAR(255)` consistent with all other login columns.

---

### LOW — `vacances.idJoueur` is INT Referencing `membre.id`, Not `membre.login`

**Table:** `vacances`, **Column:** `idJoueur INT`

The only table that uses the numeric `membre.id` for a player reference. All other tables use `membre.login` (varchar). This is architecturally inconsistent and breaks the player deletion path in `supprimerJoueur()` (line 759: `DELETE FROM vacances WHERE idJoueur IN (SELECT id FROM membre WHERE login=?)`). The nested subquery would fail silently if the SELECT returns nothing.

**Recommendation:** Store `login VARCHAR(255)` and use the same deletion pattern as other tables.

---

## 3. Normalization Issues

### HIGH — Serialised Arrays in Core Gameplay Columns

The following columns store comma/semicolon-delimited arrays inline:

| Table.Column | Content | Problem |
|---|---|---|
| `constructions.pointsProducteur` | `1;3;2;1;5;1;1;1` (8 atom levels) | Cannot query "players with carbone level > 3" |
| `constructions.pointsCondenseur` | `1;1;2;1;1;1;3;1` (8 atom levels) | Same |
| `autre.timeMolecule` | `0,0,0,0` (4 class timestamps) | Cannot query "players forming class 2 since X" |
| `actionsenvoi.ressourcesEnvoyees` | `0;50;0;100;0;0;0;0;0` (9 values) | Requires PHP parsing; no DB aggregation |
| `actionsenvoi.ressourcesRecues` | Same | Same |
| `actionsattaques.troupes` | `1000;500;0;200` (4 class counts) | Combat queries must parse in PHP |
| `cours.tableauCours` | `1.08,0.99,1.02,...` (8 floats) | Market price history is entirely unqueryable |

The `cours` table is the worst offender: 650+ rows each storing 8 comma-separated floats. Any analytical query (e.g., "average carbone price last 7 days") requires fetching all rows into PHP. The table currently has 650 INSERT rows in the dump with no sign of a cleanup strategy.

**Recommendation for `constructions`:**
```sql
CREATE TABLE constructions_atom_levels (
    login      VARCHAR(255) NOT NULL,
    atom       ENUM('carbone','azote','hydrogene','oxygene',
                    'chlore','soufre','brome','iode') NOT NULL,
    producteur_level INT NOT NULL DEFAULT 1,
    condenseur_level INT NOT NULL DEFAULT 1,
    PRIMARY KEY (login, atom),
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE
);
```
This is a significant schema change that would require a PHP migration. Flag as a future refactor target rather than an immediate fix.

**For `cours`:** Add a periodic cleanup job and consider normalising to:
```sql
CREATE TABLE cours_normalized (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    atom      TINYINT NOT NULL,  -- 0=carbone, 1=azote, ...
    price     DECIMAL(10,6) NOT NULL,
    timestamp INT NOT NULL,
    INDEX (atom, timestamp)
);
```

---

### MEDIUM — `parties` Table Stores Full Game State as TEXT

**Table:** `parties`, **Columns:** `joueurs TEXT`, `alliances TEXT`, `guerres TEXT`

This table archives season snapshots as raw text blobs. There is no way to query "which alliances fought in season 5" without loading the full TEXT column. The data is write-once and read rarely, so this is low operational impact but high analytical cost.

---

### MEDIUM — `membre` Contains x/y Position AND Player State Together

**Columns:** `membre.x INT`, `membre.y INT`

Position (-1000,-1000) is used as a sentinel for "account not yet placed on map". This is a logic flag embedded in a data column. The map query in `player.php` explicitly filters `x != -1000` to exclude inactive accounts. A dedicated `tinyint active` column or a separate `placement` table would be cleaner.

---

### LOW — `statistiques` Is a Single-Row Configuration Table

**Table:** `statistiques` — one row, multiple columns for global game state.

This pattern is fine for a small game but adding `catalyst` and `catalyst_week` columns (migration 0009) to an already multi-purpose table increases coupling. A key-value `game_config` table would be more extensible.

---

## 4. Orphan Potential — Player Deletion and Alliance Dissolution

### HIGH — `prestige` Table Not Cleaned on Player Deletion

**Code:** `supprimerJoueur()` in `player.php` lines 757–776 deletes from 11 tables but does **not** include a `DELETE FROM prestige WHERE login=?` call.

A deleted player's prestige row persists. On re-registration with the same username (which `inscrire()` does not check against prestige), the player would inherit the previous prestige points — an unintentional benefit.

**Recommendation:** Add to `supprimerJoueur()`:
```php
dbExecute($base, 'DELETE FROM prestige WHERE login=?', 's', $joueur);
```

---

### HIGH — `attack_cooldowns` Not Cleaned on Player Deletion

**Code:** `supprimerJoueur()` does not include `DELETE FROM attack_cooldowns WHERE attacker=? OR defender=?`. Old cooldown rows for deleted players accumulate. The cleanup at season reset (`remiseAZero()` line 837) handles bulk cleanup but individual deletion leaves orphan rows.

**Recommendation:**
```php
dbExecute($base, 'DELETE FROM attack_cooldowns WHERE attacker=? OR defender=?', 'ss', $joueur, $joueur);
```

---

### HIGH — `actionsformation.idclasse` Is a String, Not an Integer FK

**Table:** `actionsformation`, **Column:** `idclasse INT(11)` — but in `game_actions.php` it is compared as `$actions['idclasse'] != 'neutrino'` (a string literal). The column is declared as INT but is used as a string flag.

This means the value `'neutrino'` stored in an INT column is cast to `0` on write and then compared to the string `'neutrino'` on read — which always evaluates to false in PHP's loose comparison (`0 != 'neutrino'` is true, but `0 == 'neutrino'` in older PHP is also true due to type juggling). This is a silent correctness bug in the neutrino formation path.

**Recommendation:** Change the column to `idclasse VARCHAR(50)` and store `'neutrino'` explicitly, or use a separate `actionsformation_neutrino` table with an INT for molecule ID in the main table.

---

### MEDIUM — `grades` Table Has No Cascade on Player Deletion

`supprimerJoueur()` (line 768) calls `DELETE FROM grades WHERE login=?`, which is correct. But if an alliance is dissolved via `supprimerAlliance()`, grades are deleted by `DELETE FROM grades WHERE idalliance=?`. However, if a player is removed from an alliance without deletion (leaving), grade cleanup depends entirely on the leave-alliance PHP code path. No DB-level enforcement exists.

---

### MEDIUM — `statutforum` Has No Cleanup on Player Deletion

**Code:** `supprimerJoueur()` (line 772) calls `DELETE FROM statutforum WHERE login=?` — this is present. However, `statutforum` has no primary key constraint defined in the original schema and was MyISAM (converted by migration 0013). Without a primary key, duplicate rows (same login+idsujet+idforum) can exist silently.

**Recommendation:** Add a composite primary key:
```sql
ALTER TABLE statutforum ADD PRIMARY KEY (login, idsujet);
```

---

### LOW — `rapports` Accumulates Forever Within a Season

**Table:** `rapports` — cleared at season reset (line 818) but grows unbounded during the season. A player who receives thousands of combat reports has no deletion path except the season reset. With `longtext contenu` per row, this table can become the largest in the database.

**Recommendation:** Add a `DELETE FROM rapports WHERE destinataire=? AND timestamp < ?` cleanup for reports older than N days, or cap per-player report count at insert time.

---

## 5. Index Coverage — Common Queries Without Proper Indexes

### After All 14 Migrations

The full set of indexes present on the live system (after all migrations) is:

**`actionsattaques`:** idx_attaques_attaquant, idx_attaques_defenseur, idx_attaques_temps, idx_attaques_attaquant_temps, idx_attaques_defenseur_temps, idx_attaques_fait
**`actionsformation`:** idx_formation_login, idx_formation_fin
**`actionsconstruction`:** idx_construction_login, idx_construction_fin
**`actionsenvoi`:** idx_envoi_receveur, idx_envoi_temps
**`messages`:** idx_messages_destinataire, idx_messages_expeditaire
**`rapports`:** idx_rapports_destinataire
**`membre`:** idx_membre_login, idx_membre_derniereConnexion, idx_membre_xy
**`molecules`:** idx_molecules_proprietaire, idx_molecules_proprietaire_classe
**`autre`:** idx_autre_login, idx_autre_idalliance
**`ressources`:** PRIMARY (id), login, azote
**`constructions`:** PRIMARY (login), idx_constructions_login *(duplicate — login is already PK)*
**`cours`:** idx_cours_timestamp
**`attack_cooldowns`:** idx_attacker_defender, idx_expires
**`grades`:** idx_grades_login, idx_grades_alliance
**`declarations`:** idx_declarations_alliance1, idx_declarations_alliance2
**`sujets`:** idx_sujets_forum
**`reponses`:** idx_reponses_sujet
**`connectes`:** idx_connectes_ip

### Still Missing (Post-Migration)

| Gap | Impact | Recommendation |
|---|---|---|
| `autre.totalPoints` | HIGH — full scan on every ranking page | `ADD INDEX idx_autre_totalPoints (totalPoints)` |
| `rapports.(destinataire, timestamp)` | HIGH — inbox loads require sort after filter | `ADD INDEX idx_rapports_dest_time (destinataire, timestamp)` |
| `prestige.total_pp` | MEDIUM — season-end leaderboard scan | `ADD INDEX idx_prestige_pp (total_pp)` |
| `actionsformation.(login, debut)` | MEDIUM — query is `WHERE login=? AND debut<?` | `ADD INDEX idx_formation_login_debut (login, debut)` |
| `reponses.(idsujet, timestamp)` | MEDIUM — forum thread sorting | `ADD INDEX idx_reponses_sujet_time (idsujet, timestamp)` |
| `membre.email` | LOW — password reset / duplicate check | `ADD UNIQUE INDEX idx_membre_email (email)` |
| `statutforum.(login, idsujet)` | LOW — forum read-status check | Becomes PK in the recommended fix above |
| `constructions.(login)` | LOW — duplicate of PK | Remove redundant `idx_constructions_login` |

### Redundant Index (Waste)

`constructions` has a PRIMARY KEY on `login` AND an additional `idx_constructions_login` index on `login` added by migration 0001. These are identical; the secondary index wastes buffer pool space and slows writes.

```sql
ALTER TABLE constructions DROP INDEX idx_constructions_login;
```

---

## 6. Table Relationships — Complete Entity-Relationship Map

```
membre (id PK, login UNIQUE)
├── autre          [login FK] — game stats, alliance membership, medal counters
├── ressources     [login FK] — current resources + income rates
├── constructions  [login PK] — building levels + HP + serialised atom configs
├── molecules      [proprietaire FK] — 4 classes per player (numeroclasse 1-4)
├── prestige       [login PK] — cross-season PP and unlocks
├── vacances       [idJoueur → membre.id] — INCONSISTENT (uses id not login)
├── messages       [expeditaire FK, destinataire FK]
├── rapports       [destinataire FK]
├── actionsattaques [attaquant FK, defenseur FK]
├── actionsformation [login FK, idclasse → molecules.id or 'neutrino']
├── actionsconstruction [login FK]
├── actionsenvoi   [envoyeur FK, receveur FK]
├── statutforum    [login FK]
├── grades         [login FK, idalliance FK]
├── invitations    [invite → login, idalliance FK]
├── sanctions      [joueur → login (narrow column)]
└── attack_cooldowns [attacker FK, defender FK]

alliances (id PK)
├── autre          [idalliance FK] — membership
├── grades         [idalliance FK] — grades/roles
├── invitations    [idalliance FK]
└── declarations   [alliance1 FK, alliance2 FK] — wars and pacts

forums (id PK)
├── sujets         [idforum FK]
│   ├── reponses   [idsujet FK]
│   └── statutforum [idforum, idsujet]

cours (id PK)          — market price history (no FK, standalone)
statistiques           — singleton global state
news                   — admin news posts (no FK)
moderation             — admin resource grants (destinataire → login, no FK)
parties                — season archive snapshots (no FK to live data)
connectes              — online status tracking (ip, no FK)
tutoriel               — tutorial mission definitions (static data)
```

**Key observations:**
- `vacances.idJoueur` references `membre.id` while all peers reference `membre.login` — architectural inconsistency
- `moderation.destinataire` references a player login with no FK — moderation grants could be issued to non-existent players
- `cours` is completely disconnected from the rest of the schema — acts as a time-series log

---

## 7. Storage Efficiency

### MEDIUM — `cours` Table Is Unbounded and Unpartitioned

**Evidence:** The SQL dump contains 650+ rows from a 5-day period (April 2019). At current insert rate (multiple per minute during active play), a full season generates tens of thousands of rows. The `tableauCours TEXT` column stores 8 comma-separated floats per row.

**Estimate:** 650 rows/5 days × 30 days = ~3,900 rows/season. Each row is approximately 150 bytes of TEXT data + overhead. Low absolute size but the table lacks a cleanup strategy. After several seasons this grows to tens of thousands of rows with no deletion.

**Recommendation:** Keep only the last N price points per resource (e.g., 500 rows). Add a cron-accessible cleanup or use the session reset to truncate old rows.

---

### MEDIUM — `rapports.contenu` Is `LONGTEXT`

**Table:** `rapports`, **Column:** `contenu LONGTEXT NOT NULL`

Combat reports contain full HTML-formatted battle summaries including molecule formulas, troop counts, and building damage — these can legitimately be large (1–5KB per report). LONGTEXT allows up to 4GB. This is safe but wastes overhead for what are typically small values. `TEXT` (65KB max) would be sufficient for all current report formats.

---

### LOW — `ressources` Has Redundant `id` Auto-Increment Column

**Table:** `ressources`, **Columns:** `id INT AUTO_INCREMENT PRIMARY KEY`, `login VARCHAR(255)` with a separate index

The `ressources` table has a surrogate `id` primary key but is always accessed by `login` (every SELECT, UPDATE, INSERT uses `WHERE login=?`). The `id` column is never referenced in any PHP code. This wastes 4 bytes per row and one index tree.

**Recommendation:** Drop `id`, make `login` the primary key:
```sql
ALTER TABLE ressources DROP PRIMARY KEY, DROP COLUMN id, ADD PRIMARY KEY (login);
```

---

### LOW — `autre.missions` TEXT DEFAULT ''

**Column:** `autre.missions TEXT NOT NULL`

Stores serialised mission completion state as comma-separated values. Never queried by individual mission. Given there are 8 tutorial missions, the maximum content is small. TEXT is fine but VARCHAR(50) would be more honest about intent.

---

## 8. Missing Tables

### CRITICAL — `prestige` Table Not in Original Dump (Exists via Migration 0007)

**Migration:** `0007_add_prestige_table.sql`
**Created:**
```sql
CREATE TABLE IF NOT EXISTS prestige (
    login VARCHAR(50) PRIMARY KEY,
    total_pp INT DEFAULT 0,
    unlocks VARCHAR(255) DEFAULT ''
);
```

**Issues with this table:**
1. `login VARCHAR(50)` — should be `VARCHAR(255)` to match `membre.login`. A player with a 51-character name would fail to insert silently (MariaDB truncates on insertion in certain SQL modes).
2. `unlocks VARCHAR(255)` — stores a comma-separated list of unlock keys. A player with all 5 unlocks uses ~60 characters, but this serialisation prevents individual unlock queries.
3. No `FOREIGN KEY` to `membre`.
4. Not included in `supprimerJoueur()` cleanup (see Section 4).

---

### CRITICAL — `attack_cooldowns` Not in Original Dump (Exists via Migration 0004)

**Migration:** `0004_add_attack_cooldowns.sql`

**Issues with this table:**
1. Originally created with `attacker VARCHAR(50)` — widened to `VARCHAR(255)` in migration 0013, but only if 0013 ran **after** 0004. Deployment ordering matters.
2. No `ON DELETE CASCADE` from `membre`.
3. Expired rows accumulate between cleanups. The `DELETE FROM attack_cooldowns WHERE expires < UNIX_TIMESTAMP()` cleanup is only run in migration 0013 (one-time) and at `remiseAZero()`. If a player is very active, hundreds of expired rows accumulate mid-season.

**Recommendation:** Add a periodic scheduled cleanup or use MariaDB events:
```sql
CREATE EVENT IF NOT EXISTS cleanup_cooldowns
    ON SCHEDULE EVERY 1 HOUR
    DO DELETE FROM attack_cooldowns WHERE expires < UNIX_TIMESTAMP();
```

---

### HIGH — No `medailles` Table (Computed On-the-Fly from Raw Stats)

The codebase references medals extensively (config.php defines 11 medal thresholds, display code renders medal tiers). Medals are computed from raw stats in `autre` (nbattaques, pointsAttaque, pointsDefense, etc.) against threshold arrays in `config.php`.

There is no `medailles` table storing a player's current medal tier. Every medal display requires recalculating from raw stats. `prestige.php` (line 59) explicitly comments `-- FIX: was querying non-existent medailles table`.

**Impact:** Medal display on every profile page requires reading `autre.*` and looping through threshold arrays. With caching in `initPlayer()` this is partially mitigated, but a `medailles` table would enable:
- `ORDER BY terreur_tier DESC` queries
- Alliance medal comparisons
- Direct medal tier storage avoids recalculation bugs if thresholds change mid-season

**Recommendation:** Create:
```sql
CREATE TABLE medailles (
    login         VARCHAR(255) PRIMARY KEY,
    terreur       TINYINT DEFAULT 0,
    attaque       TINYINT DEFAULT 0,
    defense       TINYINT DEFAULT 0,
    pillage       TINYINT DEFAULT 0,
    pipelette     TINYINT DEFAULT 0,
    pertes        TINYINT DEFAULT 0,
    energievore   TINYINT DEFAULT 0,
    constructeur  TINYINT DEFAULT 0,
    bombe         TINYINT DEFAULT 0,
    troll         TINYINT DEFAULT 0,
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE
);
```
Update medal tiers whenever the underlying stat changes. This would also fix the missing `troll` and `pipelette` / `nbMessages` inconsistency (see Section 9).

---

### MEDIUM — No `session_tokens` Table Visible in Dump

Migration 0012 is `0012_add_session_token.sql`. Its content was not read, but `includes/session_init.php` exists and the memory file mentions "session token auth." If session tokens are stored in a table, that table is absent from the dump. Verify migration 0012 ran on the VPS.

---

### MEDIUM — No Audit / Event Log Table

The codebase has `includes/logger.php` for PHP-level error logging to files. There is no database-side audit table tracking game events: player bans, admin moderation grants, or season resets. The `moderation` table exists for resource grants but has no timestamp for individual actions.

---

### LOW — No `rate_limits` Table (File-Based or In-Memory Only)

`includes/rate_limiter.php` exists (referenced in memory). If it uses database-backed rate limiting, the table may be missing. File-based rate limiting would not survive process restarts. Verify the implementation.

---

## 9. Migration Risks — Overflow Potential with Growth

### HIGH — `autre.totalPoints` INT(11) — Theoretical Max ~2.1 Billion

**Column:** `autre.totalPoints INT(11) NOT NULL DEFAULT 0`

`totalPoints` accumulates contributions from:
- Player ranking points (up to 100 VP per season)
- Attack points (scaled by `sqrt(casualties) * 5.0`, capped at 100 per battle)
- Defense points
- Pillage points (`tanh(pillage/50000) * 80`, max ~80)
- Market points (scale 0.08, cap 80)
- Building/molecule points

Signed INT max is 2,147,483,647. In a hyper-active single season scenario with thousands of battles, individual players could exceed this. However, with current player counts (~930) and game balance, this is unlikely in the near term. **Monitor and switch to BIGINT proactively.**

---

### HIGH — `autre.ressourcesPillees` BIGINT — Growth Risk

**Column:** `autre.ressourcesPillees BIGINT DEFAULT 0`

At PILLAGE_POINTS_MULTIPLIER=80 and PILLAGE_POINTS_DIVISOR=50000, the max meaningful pillage value is in the millions. BIGINT (max ~9.2×10^18) is safe.

---

### HIGH — `alliances.energieAlliance` and `energieTotaleRecue` BIGINT

These accumulate all energy ever donated to an alliance across seasons. They are reset at `remiseAZero()` (line 801 sets `energieAlliance=0`). Risk is low but should be verified that `energieTotaleRecue` is also reset (it is not reset in the current reset query — potential drift).

**Evidence from player.php line 801:**
```sql
UPDATE alliances SET energieAlliance=0, duplicateur=0, catalyseur=0, fortification=0, reseau=0, radar=0, bouclier=0
```
`energieTotaleRecue` is **not** reset. This is intentional (it tracks lifetime donations) but should be documented. Over many seasons, even BIGINT could theoretically overflow with extremely active alliances, but this is academically distant.

---

### MEDIUM — `cours.id` INT(11) Auto-Increment

**Table:** `cours`, **Column:** `id INT(11) AUTO_INCREMENT`

The SQL dump shows IDs already at 102,744 from what appears to be only 5 days of game data. If the game runs for years without ID rollover or cleanup, INT(11) (~2.1 billion) would be reached after approximately: `102744 / 5 * 365 * ~57 years`. Low urgency but the table should be periodically truncated (keeping only recent rows) or migrated to BIGINT.

---

### MEDIUM — `membre.id` INT(11) — Player ID Overflow

**Table:** `membre`, **Column:** `id INT(11) AUTO_INCREMENT`

The dump shows `inscrits = 930` after what appears to be the game's full history. With normal growth, INT(11) will never overflow. Not a practical risk.

---

### LOW — `prestige.total_pp` INT

PP is bounded by game mechanics (max ~50 per rank + medal tiers per season × seasons played). INT is sufficient for decades of play.

---

## 10. Charset and Collation Inconsistencies

### HIGH — Mixed Charset Across Tables

The schema uses three different character sets:

| Charset | Tables |
|---|---|
| `latin1` | actionsattaques, actionsconstruction, actionsenvoi, actionsformation, alliances, autre, connectes, constructions, declarations, forums, grades, invitations, membre, molecules, rapports, ressources, sanctions, statistiques |
| `utf8` | cours, messages, parties, reponses, sujets, vacances |
| `utf8mb4` | attack_cooldowns (added in migration 0004) |

This creates **collation mismatch errors** on JOINs between tables. For example, a JOIN between `membre` (latin1) and `messages` (utf8) on the login column requires MariaDB to convert charsets, which:
1. Prevents index usage on the joined column
2. Can corrupt data containing non-latin1 characters (French accented characters are handled differently)
3. `utf8mb4` (attack_cooldowns) cannot be joined against `latin1` columns without conversion

**Migration 0013 partially addressed this** by converting MyISAM tables to InnoDB but did not standardise charset.

**Recommendation:** Standardise all tables to `utf8mb4 COLLATE utf8mb4_unicode_ci`:
```sql
ALTER TABLE membre CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE autre CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE constructions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- ... (all tables)
```
Do this during a maintenance window. Verify no latin1-only data (e.g., byte values 0x80–0xFF used as non-UTF-8) exists first.

---

## 11. Summary Table

| ID | Severity | Table.Column | Issue | Fix Required |
|---|---|---|---|---|
| SC-001 | CRITICAL | All tables | Zero foreign key constraints | Add FK constraints + ON DELETE CASCADE |
| SC-002 | CRITICAL | `prestige.login` | VARCHAR(50) vs VARCHAR(255) — truncation risk | `MODIFY login VARCHAR(255)` |
| SC-003 | CRITICAL | `actionsformation.idclasse` | INT storing string 'neutrino' — type mismatch | Change to VARCHAR(50) |
| SC-004 | HIGH | `connectes` | No primary key — full scan on every online-check | `ADD PRIMARY KEY (ip)` |
| SC-005 | HIGH | `prestige` | Not deleted on player deletion | Add to `supprimerJoueur()` |
| SC-006 | HIGH | `attack_cooldowns` | Not deleted on player deletion | Add to `supprimerJoueur()` |
| SC-007 | HIGH | `declarations.alliance1/2` | Was VARCHAR, now INT — verify migration ran | Check live VPS schema |
| SC-008 | HIGH | `autre.totalPoints` | INT — potential overflow with growth | Monitor; plan BIGINT migration |
| SC-009 | HIGH | All tables | Mixed charset (latin1/utf8/utf8mb4) | Standardise to utf8mb4 |
| SC-010 | HIGH | `autre.totalPoints` | Missing index — full scan on rankings | `ADD INDEX (totalPoints)` |
| SC-011 | HIGH | `prestige` | No FOREIGN KEY, no cleanup on alliance dissolve | Add FK + ON DELETE CASCADE |
| SC-012 | MEDIUM | `ressources.*` | DOUBLE resource columns — accumulation errors | Change to DECIMAL(20,4) |
| SC-013 | MEDIUM | `molecules.nombre` | DOUBLE — precision drift over many operations | Change to DECIMAL(20,6) |
| SC-014 | MEDIUM | `constructions.pointsProducteur/Condenseur` | VARCHAR(10000) serialised array | Normalise or reduce to VARCHAR(100) |
| SC-015 | MEDIUM | `cours.tableauCours` | Serialised market prices, unbounded growth | Normalise + add cleanup |
| SC-016 | MEDIUM | `rapports` | Unbounded growth, no per-player cleanup | Add TTL cleanup query |
| SC-017 | MEDIUM | `statutforum` | No primary key — allows duplicate rows | `ADD PRIMARY KEY (login, idsujet)` |
| SC-018 | MEDIUM | `vacances.idJoueur` | References `membre.id` not `login` — inconsistent | Change to login VARCHAR(255) |
| SC-019 | MEDIUM | `sanctions.joueur/moderateur` | VARCHAR(30) — truncation vs VARCHAR(255) peers | Widen to VARCHAR(255) |
| SC-020 | MEDIUM | No `medailles` table | Medal tiers computed on every page load | Create dedicated medals table |
| SC-021 | MEDIUM | `actionsattaques.troupes` | Type overloading ('Espionnage' vs CSV) | Add `type` TINYINT column |
| SC-022 | MEDIUM | `alliances.energieTotaleRecue` | Not reset at season reset — documented gap | Add to reset or document |
| SC-023 | LOW | `constructions` | Redundant `idx_constructions_login` (login is PK) | Drop redundant index |
| SC-024 | LOW | `ressources.id` | Surrogate PK never referenced — wasteful | Make login the PK |
| SC-025 | LOW | `cours.id` | Auto-increment at 102,744 — plan table cleanup | Periodic truncation |
| SC-026 | LOW | `actionsformation.formule` | VARCHAR(1000) — oversized | Reduce to VARCHAR(100) |
| SC-027 | LOW | `rapports.contenu` | LONGTEXT — TEXT sufficient | Change to TEXT |

---

## 12. Recommended Migration Priorities

### Immediate (Can run without downtime)
```sql
-- SC-005/006: Player deletion completeness
-- (code fix in player.php, not schema)

-- SC-010: Critical missing index
ALTER TABLE autre ADD INDEX idx_autre_totalPoints (totalPoints);

-- SC-023: Remove redundant index
ALTER TABLE constructions DROP INDEX idx_constructions_login;

-- SC-017: Fix statutforum primary key
ALTER TABLE statutforum ADD PRIMARY KEY (login, idsujet);

-- SC-004: connectes primary key
ALTER TABLE connectes ADD PRIMARY KEY (ip);
```

### Next maintenance window
```sql
-- SC-002: Fix prestige login column width
ALTER TABLE prestige MODIFY login VARCHAR(255) NOT NULL;

-- SC-009: Charset standardisation (per table)
ALTER TABLE membre CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- ... repeat for all latin1 tables

-- SC-018: vacances fix
ALTER TABLE vacances ADD COLUMN login VARCHAR(255) NOT NULL AFTER idJoueur;
-- UPDATE vacances v JOIN membre m ON v.idJoueur = m.id SET v.login = m.login;
-- ALTER TABLE vacances DROP COLUMN idJoueur;
```

### Future refactor
- SC-001: Foreign key constraints (requires FK-safe deletion code first)
- SC-003: actionsformation.idclasse type fix
- SC-012/013: DOUBLE to DECIMAL migration
- SC-014: Normalise serialised atom level columns
- SC-020: Dedicated medailles table

---

*Audit generated by database-optimizer agent. Cross-reference with PERF-CROSS.md for index performance analysis and DATA-CROSS.md for data integrity findings.*
