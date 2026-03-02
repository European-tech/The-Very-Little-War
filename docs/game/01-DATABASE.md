# Database Schema Reference

This document describes the complete MySQL database schema for The Very Little War (TVLW).
The database uses **no foreign key constraints**; all relationships are enforced in PHP application code.
All tables use the default InnoDB storage engine.

---

## Table of Contents

1. [Entity-Relationship Diagram](#entity-relationship-diagram)
2. [Player Core Tables](#player-core-tables)
   - [membre](#1-membre--player-accounts)
   - [autre](#2-autre--player-statistics-and-metadata)
   - [ressources](#3-ressources--player-resource-inventory)
   - [constructions](#4-constructions--player-buildings)
3. [Military Tables](#military-tables)
   - [molecules](#5-molecules--army-molecule-classes)
4. [Action Queue Tables](#action-queue-tables)
   - [actionsconstruction](#6-actionsconstruction--building-upgrade-queue)
   - [actionsformation](#7-actionsformation--molecule-training-queue)
   - [actionsattaques](#8-actionsattaques--attack-and-espionage-queue)
   - [actionsenvoi](#9-actionsenvoi--resource-transfer-queue)
5. [Alliance Tables](#alliance-tables)
   - [alliances](#10-alliances--alliance-data)
   - [grades](#11-grades--alliance-membership-grades)
   - [declarations](#12-declarations--wars-and-pacts)
   - [invitations](#13-invitations--alliance-invitations)
6. [Communication Tables](#communication-tables)
   - [messages](#14-messages--private-messages)
   - [rapports](#15-rapports--game-reports)
   - [cours](#16-cours--market-price-history)
7. [Forum Tables](#forum-tables)
   - [forums](#17-forums--forum-categories)
   - [sujets](#18-sujets--forum-threads)
   - [reponses](#19-reponses--forum-replies)
   - [statutforum](#20-statutforum--forum-read-tracking)
8. [System Tables](#system-tables)
   - [statistiques](#21-statistiques--global-game-state)
   - [parties](#22-parties--season-archives)
   - [migrations](#23-migrations--migration-tracking)
   - [connectes](#24-connectes--online-player-tracking)
   - [vacances](#25-vacances--vacation-mode-records)
   - [sanctions](#26-sanctions--forum-bans)
   - [moderation](#27-moderation--moderation-log)
   - [news](#28-news--game-news)

---

## Entity-Relationship Diagram

```
                          +-------------------+
                          |    statistiques    |
                          |-------------------|
                          | inscrits          |
                          | tailleCarte       |
                          | debut             |
                          | maintenance       |
                          +-------------------+

  +-------------------+         +-------------------+         +-------------------+
  |    alliances      |         |      membre       |         |     parties       |
  |-------------------|         |-------------------|         |-------------------|
  | id (PK)           |<--+     | id (PK, AI)       |         | id (PK, AI)       |
  | nom               |   |     | login             |--+      | debut             |
  | tag (UNIQUE)      |   |     | pass_md5          |  |      | joueurs (TEXT)     |
  | description       |   |     | dateInscription   |  |      | alliances (TEXT)   |
  | chef              |---+---->| ip                |  |      | guerres (TEXT)     |
  | energieAlliance   |   |     | derniereConnexion |  |      +-------------------+
  | energieTotaleRecue|   |     | moderateur        |  |
  | duplicateur       |   |     | aleatoire         |  |
  | pointstotaux      |   |     | email             |  |
  | pointsVictoire    |   |     | timestamp         |  |
  | totalConstructions|   |     | x, y              |  |
  | totalAttaque      |   |     | vacance           |  |
  | totalDefense      |   |     +-------------------+  |
  | totalPillage      |   |                            |
  +-------------------+   |    login (text key)        |
        |                 |         |                   |
        |                 |         v                   |
        |     +-----------+---------+----------+--------+----------+
        |     |           |         |          |        |          |
        |     v           v         v          v        v          v
        | +--------+ +--------+ +----------+ +-----+ +--------+ +----------+
        | | autre  | | ress.  | | constru. | | mol.| | msgs   | | rapports |
        | |--------| |--------| |----------| |-----| |--------| |----------|
        | | login  | | login  | | login    | | pro.| | exped. | | destin.  |
        | | idalli.|-| energie| | generat. | | num.| | desti. | | titre    |
        | | points | | carbon.| | product. | | for.| | titre  | | contenu  |
        | | ptAtt. | | azote  | | champdF. | | 8x  | | conten.| | date     |
        | | ptDef. | | hydrog.| | ionisate.| | nom.| | date   | | lu       |
        | | total  | | oxyg.  | | depot    | +-----+ | lu     | | image    |
        | | trade  | | chlore | | condens. |         +--------+ +----------+
        | | neut.  | | soufre | | lieur    |
        | | etc.   | | brome  | | stabilis.|     +--------------------+
        | +--------+ | iode   | | HP cols  |     | actionsattaques    |
        |            | revenu*| | pts cols |     |--------------------|
        |            +--------+ +----------+     | attaquant          |
        |                                        | defenseur          |
        v                                        | troupes            |
  +------------+   +--------------+              | tempsAller         |
  |   grades   |   | declarations |              | tempsAttaque       |
  |------------|   |--------------|              | tempsRetour        |
  | login      |   | type (0/1)   |              | attaqueFaite       |
  | grade      |   | alliance1  --+--+           +--------------------+
  | idalliance-+-->| alliance2  --+--+
  | nom        |   | timestamp    |        +--------------------+
  +------------+   | fin          |        | actionsconstruction|
                   | pertes1      |        |--------------------|
                   | pertes2      |        | login              |
                   | pertesTotales|        | batiment           |
                   | valide       |        | niveau             |
                   +--------------+        | debut              |
                                           | fin                |
  +------------+   +-----------+           | affichage          |
  | actionsenv.|   | actionsfo.|           | points             |
  |------------|   |-----------|           +--------------------+
  | envoyeur   |   | idclasse  |
  | receveur   |   | login     |    +----------+  +---------+  +----------+
  | ressEnv.   |   | debut     |    |  forums  |  | sujets  |  | reponses |
  | ressRec.   |   | fin       |    |----------|  |---------|  |----------|
  | tempsArr.  |   | nombreDeb.|    | id (PK)  |  | id (PK) |  | id (PK)  |
  +------------+   | nombreRes.|    | titre    |  | idforum-+->| idsujet -+->
                   | formule   |    | descript.|  | titre   |  | visibil. |
                   | tempsPour.|    +----------+  | auteur  |  | contenu  |
                   +-----------+                  | contenu |  | auteur   |
                                                  | statut  |  | timestamp|
                    +----------+                  |timestamp |  +----------+
                    |   cours  |                  +---------+
                    |----------|
                    | id (PK)  |     +-------------+
                    | tableau  |     | statutforum  |
                    | timestamp|     |-------------|
                    +----------+     | login       |
                                     | idsujet     |
                                     | idforum     |
                                     +-------------+
```

---

## Player Core Tables

### 1. `membre` -- Player accounts

Primary authentication and identity table. One row per registered player.

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | Unique player ID |
| `login` | VARCHAR(255) NOT NULL | -- | Player username (unique, case-insensitive via `ucfirst`) |
| `pass_md5` | VARCHAR(255) NOT NULL | -- | Password hash (bcrypt for new accounts; legacy MD5 for old) |
| `dateInscription` | INT | -- | Unix timestamp of account creation |
| `ip` | VARCHAR(45) NOT NULL | -- | Last known IP address (supports IPv6 after migration 0002) |
| `derniereConnexion` | INT | -- | Unix timestamp of last login |
| `moderateur` | INT(11) NOT NULL | 0 | Moderator flag (0=normal, 1=moderator) |
| `aleatoire` | INT | -- | Random bonus tier assigned at registration (0-7, weighted) |
| `email` | VARCHAR(255) | -- | Player email address |
| `timestamp` | INT | -- | Registration/reset timestamp; used for beginner protection |
| `x` | INT | -1000 | Map X coordinate (-1000 = not placed) |
| `y` | INT | -1000 | Map Y coordinate (-1000 = not placed) |
| `vacance` | TINYINT(1) NOT NULL | 0 | Vacation mode flag (0=active, 1=on vacation) |

**Indexes** (migration 0002):
- `idx_membre_login` on (`login`)
- `idx_membre_derniereConnexion` on (`derniereConnexion`)

**Key relationships:**
- `login` is the foreign key referenced by `autre.login`, `ressources.login`, `constructions.login`, `molecules.proprietaire`, all action queue tables, `messages`, `rapports`, `grades`, and `statutforum`.

**Example query** -- check if player is active (connected within 31 days):
```sql
-- includes/player.php:10
SELECT count(*) AS nb FROM membre WHERE derniereConnexion >= ? AND x!=-1000 AND login=?
```

---

### 2. `autre` -- Player statistics and metadata

Extended player data: points, combat stats, alliance affiliation, profile info.
One row per player, keyed by `login`.

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `login` | VARCHAR(255) | -- | Player username (FK to `membre.login`) |
| `tempsPrecedent` | INT | -- | Unix timestamp of last resource update |
| `niveaututo` | INT | 1 | Tutorial progress level |
| `description` | TEXT | 'Pas de description' | Player profile description (BBCode) |
| `idalliance` | INT | 0 | Alliance ID (FK to `alliances.id`; 0=none) |
| `points` | DOUBLE | 0 | Construction points |
| `pointsAttaque` | DOUBLE | 0 | Raw attack point accumulator |
| `pointsDefense` | DOUBLE | 0 | Raw defense point accumulator |
| `totalPoints` | DOUBLE | 0 | Composite score (construction + attack + defense + pillage + trade) |
| `moleculesPerdues` | DOUBLE | 0 | Total molecules lost (decay + combat) |
| `ressourcesPillees` | DOUBLE | 0 | Total resources looted from other players |
| `tradeVolume` | DOUBLE NOT NULL | 0 | Cumulative market trade volume (energy spent on buys) |
| `nbattaques` | INT | 0 | Total attacks launched |
| `victoires` | INT | 0 | Victory points earned from season rankings |
| `neutrinos` | INT | 0 | Neutrino count (spy units) |
| `batmax` | INT | 1 | Highest building level (cached) |
| `energieDepensee` | BIGINT NOT NULL | 0 | Total energy spent (military + construction) |
| `energieDonnee` | BIGINT NOT NULL | 0 | Total energy donated to alliance |
| `bombe` | INT | 0 | Bomb count (moderation/admin tool) |
| `missions` | TEXT | '' | Missions/achievements tracking string |
| `image` | VARCHAR(255) | 'defaut.png' | Profile avatar filename |
| `nbMessages` | INT | 0 | Forum post count |

**Indexes** (migration 0001):
- `idx_autre_login` on (`login`)
- `idx_autre_idalliance` on (`idalliance`)

**Column added in migration 0003:**
- `tradeVolume` DOUBLE NOT NULL DEFAULT 0 (placed after `ressourcesPillees`)

**Key relationships:**
- `login` references `membre.login`
- `idalliance` references `alliances.id`

**Example query** -- fetch all player stats:
```sql
-- includes/player.php:150
SELECT * FROM autre WHERE login=?
```

---

### 3. `ressources` -- Player resource inventory

Current resource stockpile, revenue rates, and class-related data.
One row per player, keyed by `login`.

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | Row ID |
| `login` | VARCHAR(255) | -- | Player username (FK to `membre.login`) |
| `energie` | DOUBLE | 0 | Current energy stockpile |
| `carbone` | DOUBLE | 0 | Current carbone stockpile |
| `azote` | DOUBLE | 0 | Current azote (nitrogen) stockpile |
| `hydrogene` | DOUBLE | 0 | Current hydrogene (hydrogen) stockpile |
| `oxygene` | DOUBLE | 0 | Current oxygene (oxygen) stockpile |
| `chlore` | DOUBLE | 0 | Current chlore (chlorine) stockpile |
| `soufre` | DOUBLE | 0 | Current soufre (sulfur) stockpile |
| `brome` | DOUBLE | 0 | Current brome (bromine) stockpile |
| `iode` | DOUBLE | 0 | Current iode (iodine) stockpile |
| `terrain` | INT | 0 | Terrain type or bonus |
| `niveauclasse` | INT | 1 | Number of molecule classes created (used for class cost scaling) |
| `revenuenergie` | BIGINT NOT NULL | 12 | Cached energy revenue per hour |
| `revenuhydrogene` | BIGINT NOT NULL | 9 | Cached hydrogen revenue |
| `revenucarbone` | BIGINT NOT NULL | 9 | Cached carbon revenue |
| `revenuoxygene` | BIGINT NOT NULL | 9 | Cached oxygen revenue |
| `revenuiode` | BIGINT NOT NULL | 9 | Cached iodine revenue |
| `revenubrome` | BIGINT NOT NULL | 9 | Cached bromine revenue |
| `revenuchlore` | BIGINT NOT NULL | 9 | Cached chlorine revenue |
| `revenusoufre` | BIGINT NOT NULL | 9 | Cached sulfur revenue |
| `revenuazote` | BIGINT NOT NULL | 9 | Cached nitrogen revenue |

**Indexes** (migration 0001):
- `idx_ressources_login` on (`login`)

**Key relationships:**
- `login` references `membre.login`

**Example query** -- fetch all resources for a player:
```sql
-- includes/player.php:132
SELECT * FROM ressources WHERE login=?
```

---

### 4. `constructions` -- Player buildings

Building levels, allocated production points, and building HP.
One row per player, keyed by `login`.

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `login` | VARCHAR(255) | -- | Player username (FK to `membre.login`) |
| `generateur` | INT | 1 | Generator level (energy production) |
| `producteur` | INT | 1 | Producer level (atom production) |
| `pointsProducteur` | TEXT | '1;1;1;1;1;1;1;1' | Semicolon-separated production points per atom type |
| `pointsProducteurRestants` | INT | 0 | Unallocated producer points |
| `pointsCondenseur` | TEXT | '0;0;0;0;0;0;0;0' | Semicolon-separated condenseur levels per atom type |
| `pointsCondenseurRestants` | INT | 0 | Unallocated condenser points |
| `champdeforce` | INT | 0 | Force field level (defense bonus) |
| `ionisateur` | INT | 0 | Ionizer level (attack bonus) |
| `depot` | INT | 1 | Storage level (max resource capacity) |
| `condenseur` | INT | 0 | Condenser level (atom strength) |
| `lieur` | INT | 0 | Binder level (molecule formation speed) |
| `stabilisateur` | INT | 0 | Stabilizer level (molecule decay reduction) |
| `vieGenerateur` | BIGINT NOT NULL | 0 | Generator current HP |
| `vieChampdeforce` | BIGINT NOT NULL | 0 | Force field current HP |
| `vieProducteur` | BIGINT NOT NULL | 0 | Producer current HP |
| `vieDepot` | BIGINT NOT NULL | 30 | Storage current HP |

**Indexes** (migration 0001):
- `idx_constructions_login` on (`login`)

**Key relationships:**
- `login` references `membre.login`

**Example query** -- fetch all building data:
```sql
-- includes/player.php:141
SELECT * FROM constructions WHERE login=?
```

---

## Military Tables

### 5. `molecules` -- Army molecule classes

Each player has exactly 4 molecule slots (numeroclasse 1-4).
Molecules are composed of 8 atom types with varying quantities.

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | Unique molecule class ID |
| `carbone` | INT | 0 | Carbon atoms in formula |
| `azote` | INT | 0 | Nitrogen atoms in formula |
| `hydrogene` | INT | 0 | Hydrogen atoms in formula |
| `oxygene` | INT | 0 | Oxygen atoms in formula |
| `chlore` | INT | 0 | Chlorine atoms in formula (affects speed) |
| `soufre` | INT | 0 | Sulfur atoms in formula |
| `brome` | INT | 0 | Bromine atoms in formula |
| `iode` | INT | 0 | Iodine atoms in formula (affects energy production) |
| `numeroclasse` | INT | -- | Slot number (1-4) |
| `proprietaire` | VARCHAR(255) | -- | Owner username (FK to `membre.login`) |
| `formule` | VARCHAR(255) | 'Vide' | HTML formula display string (e.g. "H<sub>2</sub>O") |
| `nombre` | DOUBLE | 0 | Current unit count (can be fractional due to decay) |

**Indexes** (migration 0001):
- `idx_molecules_proprietaire` on (`proprietaire`)
- `idx_molecules_proprietaire_classe` on (`proprietaire`, `numeroclasse`)

**Key relationships:**
- `proprietaire` references `membre.login`
- `id` is referenced by `actionsformation.idclasse`

**Example query** -- fetch all molecule classes for a player:
```sql
-- includes/game_actions.php:85
SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC
```

---

## Action Queue Tables

All action queues are processed on every page load via `updateActions()` in `includes/game_actions.php`.

### 6. `actionsconstruction` -- Building upgrade queue

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | Queue entry ID |
| `login` | VARCHAR(255) | -- | Player username |
| `debut` | BIGINT NOT NULL | -- | Start time (Unix timestamp) |
| `fin` | BIGINT NOT NULL | -- | End time (Unix timestamp) |
| `batiment` | VARCHAR(255) | -- | Building name (e.g. 'generateur', 'producteur') |
| `niveau` | INT | -- | Target level after upgrade |
| `affichage` | VARCHAR(255) | -- | Display name for UI (e.g. 'Generateur') |
| `points` | INT | -- | Points awarded on completion |

**Indexes** (migration 0001):
- `idx_construction_login` on (`login`)
- `idx_construction_fin` on (`fin`)

**Key relationships:**
- `login` references `membre.login`
- `batiment` corresponds to column names in `constructions`

**Example query** -- fetch pending constructions:
```sql
-- includes/game_actions.php:26
SELECT * FROM actionsconstruction WHERE login=? AND fin<?
```

---

### 7. `actionsformation` -- Molecule training queue

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | Queue entry ID |
| `idclasse` | VARCHAR(255) | -- | Molecule class ID (FK to `molecules.id`) or 'neutrino' |
| `login` | VARCHAR(255) | -- | Player username |
| `debut` | INT | -- | Start time (Unix timestamp) |
| `fin` | INT | -- | End time (Unix timestamp) |
| `nombreDebut` | BIGINT NOT NULL | -- | Initial number to train |
| `nombreRestant` | BIGINT NOT NULL | -- | Remaining units to complete |
| `formule` | VARCHAR(255) | -- | Formula display string |
| `tempsPourUn` | INT | -- | Seconds per unit |

**Indexes** (migration 0001):
- `idx_formation_login` on (`login`)
- `idx_formation_fin` on (`fin`)

**Key relationships:**
- `login` references `membre.login`
- `idclasse` references `molecules.id` (or literal string 'neutrino')

**Example query** -- fetch active formations:
```sql
-- includes/game_actions.php:35
SELECT * FROM actionsformation WHERE login=? AND debut<?
```

---

### 8. `actionsattaques` -- Attack and espionage queue

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | Queue entry ID |
| `attaquant` | VARCHAR(255) NOT NULL | -- | Attacker username |
| `defenseur` | VARCHAR(255) NOT NULL | -- | Defender username |
| `tempsAller` | INT | -- | Departure time (Unix timestamp) |
| `tempsAttaque` | INT | -- | Attack arrival time (Unix timestamp) |
| `tempsRetour` | INT | -- | Return time (Unix timestamp) |
| `troupes` | TEXT | -- | Semicolon-separated troop counts per class, or 'Espionnage' |
| `attaqueFaite` | INT | 0 | Whether the attack has been resolved (0=pending, 1=done) |
| `nombreneutrinos` | INT | 0 | Number of neutrinos sent (for espionage only) |

**Indexes** (migration 0001):
- `idx_attaques_attaquant` on (`attaquant`)
- `idx_attaques_defenseur` on (`defenseur`)
- `idx_attaques_temps` on (`tempsAttaque`)

**Key relationships:**
- `attaquant` references `membre.login`
- `defenseur` references `membre.login`

**Example query** -- fetch attacks involving a player:
```sql
-- includes/game_actions.php:65
SELECT * FROM actionsattaques WHERE attaquant=? OR defenseur=? ORDER BY tempsAttaque DESC
```

---

### 9. `actionsenvoi` -- Resource transfer queue

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | Queue entry ID |
| `envoyeur` | VARCHAR(255) NOT NULL | -- | Sender username |
| `receveur` | VARCHAR(255) NOT NULL | -- | Receiver username |
| `ressourcesEnvoyees` | TEXT | -- | Semicolon-separated sent quantities (8 atoms + energy) |
| `ressourcesRecues` | TEXT | -- | Semicolon-separated received quantities (after ratio) |
| `tempsArrivee` | INT | -- | Arrival time (Unix timestamp) |

**Indexes** (migration 0001):
- `idx_envoi_receveur` on (`receveur`)
- `idx_envoi_temps` on (`tempsArrivee`)

**Key relationships:**
- `envoyeur` references `membre.login`
- `receveur` references `membre.login`

**Example query** -- fetch pending transfers:
```sql
-- includes/game_actions.php:427
SELECT * FROM actionsenvoi WHERE (receveur=? OR envoyeur=?) AND tempsArrivee<?
```

---

## Alliance Tables

### 10. `alliances` -- Alliance data

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | Alliance ID |
| `nom` | VARCHAR(255) | -- | Alliance full name |
| `tag` | VARCHAR(255) | -- | Short tag identifier (3-16 chars, unique) |
| `description` | TEXT | '' | Alliance description (BBCode) |
| `chef` | VARCHAR(255) | -- | Leader username (FK to `membre.login`) |
| `energieAlliance` | BIGINT NOT NULL | 0 | Alliance energy treasury |
| `energieTotaleRecue` | BIGINT NOT NULL | 0 | Total energy received from members |
| `duplicateur` | INT | 0 | Duplicator building level (alliance-wide bonus) |
| `pointstotaux` | DOUBLE | 0 | Sum of all members' `totalPoints` (cached) |
| `pointsVictoire` | INT | 0 | Alliance victory points from season rankings |
| `totalConstructions` | DOUBLE | 0 | Sum of all members' construction points (cached) |
| `totalAttaque` | DOUBLE | 0 | Sum of all members' attack points (cached) |
| `totalDefense` | DOUBLE | 0 | Sum of all members' defense points (cached) |
| `totalPillage` | DOUBLE | 0 | Sum of all members' pillage totals (cached) |

**Key relationships:**
- `chef` references `membre.login`
- `id` is referenced by `autre.idalliance`, `grades.idalliance`, `declarations.alliance1`, `declarations.alliance2`

**Example query** -- fetch alliance by ID:
```sql
-- alliance.php:136
SELECT * FROM alliances WHERE id=?
```

---

### 11. `grades` -- Alliance membership grades

Custom ranks within an alliance, with granular permission flags.

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `login` | VARCHAR(255) | -- | Graded member username |
| `grade` | VARCHAR(255) | -- | Permission string: "invite.war.pact.ban.description" (1/0 each) |
| `idalliance` | INT | -- | Alliance ID (FK to `alliances.id`) |
| `nom` | VARCHAR(255) | -- | Grade display name |

**Indexes** (migration 0001):
- `idx_grades_login` on (`login`)
- `idx_grades_alliance` on (`idalliance`)

**Key relationships:**
- `login` references `membre.login`
- `idalliance` references `alliances.id`

**Example query** -- check if player has a grade:
```sql
-- alliance.php:167
SELECT * FROM grades WHERE idalliance=?
```

---

### 12. `declarations` -- Wars and pacts

Tracks inter-alliance diplomatic relations.

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | Declaration ID |
| `type` | INT | -- | 0=war, 1=pact |
| `alliance1` | INT | -- | Declaring alliance ID |
| `alliance2` | INT | -- | Target alliance ID |
| `timestamp` | INT | -- | Declaration start time (Unix timestamp) |
| `fin` | BIGINT | 0 | End time (0=ongoing for wars; Unix timestamp when ended) |
| `pertes1` | BIGINT NOT NULL | 0 | Alliance 1's losses |
| `pertes2` | BIGINT NOT NULL | 0 | Alliance 2's losses |
| `pertesTotales` | BIGINT NOT NULL | 0 | Combined total losses |
| `valide` | INT | 0 | Pact validation flag (0=pending, 1=accepted; only for type=1) |

**Indexes** (migration 0001):
- `idx_declarations_alliance1` on (`alliance1`)
- `idx_declarations_alliance2` on (`alliance2`)

**Key relationships:**
- `alliance1` references `alliances.id`
- `alliance2` references `alliances.id`

**Example query** -- fetch active wars:
```sql
-- alliance.php:179
SELECT * FROM declarations WHERE type=0 AND (alliance1=? OR alliance2=?) AND fin=0
```

---

### 13. `invitations` -- Alliance invitations

Pending invitations for players to join an alliance.

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | Invitation ID |
| `idalliance` | INT | -- | Alliance ID (FK to `alliances.id`) |
| `tag` | VARCHAR(255) | -- | Alliance tag (denormalized for display) |
| `invite` | VARCHAR(255) | -- | Invited player username |

**Key relationships:**
- `idalliance` references `alliances.id`
- `invite` references `membre.login`

**Example query** -- fetch invitations for a player:
```sql
-- alliance.php:369
SELECT * FROM invitations WHERE invite=?
```

---

## Communication Tables

### 14. `messages` -- Private messages

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | Message ID |
| `timestamp` | INT | -- | Send time (Unix timestamp) |
| `titre` | TEXT | -- | Message subject |
| `contenu` | TEXT | -- | Message body (BBCode/HTML escaped) |
| `expeditaire` | VARCHAR(255) | -- | Sender username |
| `destinataire` | VARCHAR(255) | -- | Recipient username |
| `lu` | INT | 0 | Read flag (0=unread, 1=read) |

**Indexes** (migration 0001):
- `idx_messages_destinataire` on (`destinataire`)
- `idx_messages_expeditaire` on (`expeditaire`)

**Key relationships:**
- `expeditaire` references `membre.login`
- `destinataire` references `membre.login`

**Example query** -- insert a private message:
```sql
-- ecriremessage.php:31
INSERT INTO messages VALUES(default, ?, ?, ?, ?, ?, default)
```

---

### 15. `rapports` -- Game reports

System-generated reports for attacks, espionage, transfers, molecule decay, and diplomatic events.

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | Report ID |
| `date` | INT | -- | Report time (Unix timestamp) |
| `titre` | TEXT | -- | Report title |
| `contenu` | TEXT | -- | Report body (rich HTML) |
| `destinataire` | VARCHAR(255) | -- | Recipient username |
| `lu` | INT | 0 | Read flag (0=unread, 1=read) |
| `image` | TEXT | -- | Icon HTML for the report type |

**Indexes** (migration 0001):
- `idx_rapports_destinataire` on (`destinataire`)

**Key relationships:**
- `destinataire` references `membre.login`

**Example query** -- insert an attack report:
```sql
-- includes/game_actions.php:294
INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default, ?)
```

---

### 16. `cours` -- Market price history

Each row is a snapshot of market exchange rates (energy cost per atom type).

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | Price snapshot ID |
| `tableauCours` | TEXT | -- | Comma-separated price array (one float per atom type) |
| `timestamp` | INT | -- | Snapshot time (Unix timestamp) |

**Key relationships:**
- No direct FK references; read by `marche.php` for market UI and charting.

**Example query** -- fetch latest market prices:
```sql
-- marche.php:10
SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1
```

---

## Forum Tables

### 17. `forums` -- Forum categories

Static category definitions for the game forum.

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | Forum category ID |
| `titre` | VARCHAR(255) | -- | Category title |
| `description` | TEXT | -- | Category description |

**Key relationships:**
- `id` is referenced by `sujets.idforum` and `statutforum.idforum`

**Example query** -- list all forum categories:
```sql
-- forum.php:62
SELECT * FROM forums
```

---

### 18. `sujets` -- Forum threads

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | Thread ID |
| `idforum` | INT | -- | Forum category ID (FK to `forums.id`) |
| `titre` | TEXT | -- | Thread title |
| `contenu` | TEXT | -- | Thread opening post content (BBCode) |
| `auteur` | VARCHAR(255) | -- | Author username |
| `statut` | INT | 0 | 0=open, 1=locked |
| `timestamp` | INT | -- | Creation time (Unix timestamp) |

**Indexes** (migration 0001):
- `idx_sujets_forum` on (`idforum`)

**Key relationships:**
- `idforum` references `forums.id`
- `auteur` references `membre.login`
- `id` is referenced by `reponses.idsujet` and `statutforum.idsujet`

**Example query** -- create a new thread:
```sql
-- listesujets.php:31
INSERT INTO sujets VALUES(default, ?, ?, ?, ?, default, ?)
```

---

### 19. `reponses` -- Forum replies

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | Reply ID |
| `idsujet` | INT | -- | Thread ID (FK to `sujets.id`) |
| `visibilite` | INT | 1 | Visibility flag (1=visible, 0=hidden by moderator) |
| `contenu` | TEXT | -- | Reply content (BBCode) |
| `auteur` | VARCHAR(255) | -- | Author username |
| `timestamp` | INT | -- | Post time (Unix timestamp) |

**Indexes** (migration 0001):
- `idx_reponses_sujet` on (`idsujet`)

**Key relationships:**
- `idsujet` references `sujets.id`
- `auteur` references `membre.login`

**Example query** -- post a reply:
```sql
-- sujet.php:21
INSERT INTO reponses VALUES(default, ?, "1", ?, ?, ?)
```

---

### 20. `statutforum` -- Forum read tracking

Tracks which threads a player has read (for new-message indicators).

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `login` | VARCHAR(255) | -- | Player username |
| `idsujet` | INT | -- | Thread ID (FK to `sujets.id`) |
| `idforum` | INT | -- | Forum category ID (FK to `forums.id`) |

**Key relationships:**
- `login` references `membre.login`
- `idsujet` references `sujets.id`
- `idforum` references `forums.id`

**Example query** -- mark thread as read:
```sql
-- sujet.php:89
INSERT INTO statutforum VALUES(?, ?, ?)
```

---

## System Tables

### 21. `statistiques` -- Global game state

Single-row table holding global game configuration and counters.

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `inscrits` | INT | 0 | Total registered player count |
| `tailleCarte` | INT | 1 | Current map grid dimension |
| `nbDerniere` | INT | 0 | Number of players placed on current map edge |
| `debut` | INT | -- | Current season start time (Unix timestamp) |
| `maintenance` | INT | 0 | Maintenance flag (0=normal, 1=24h countdown to reset) |
| `numerovisiteur` | INT | 0 | Visitor counter |

**Key relationships:**
- No FK references; global singleton.

**Example query** -- fetch map dimensions:
```sql
-- includes/player.php:542
SELECT tailleCarte,nbDerniere FROM statistiques
```

---

### 22. `parties` -- Season archives

Each row stores the complete leaderboard snapshot from a finished season.

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | Season archive ID |
| `debut` | INT NOT NULL | -- | Season start time (Unix timestamp) |
| `joueurs` | TEXT | -- | Serialized player leaderboard (bracket-delimited CSV) |
| `alliances` | TEXT | -- | Serialized alliance leaderboard (bracket-delimited CSV) |
| `guerres` | TEXT | -- | Serialized war history (bracket-delimited CSV) |

**Key relationships:**
- No FK references; historical archive.

**Example query** -- fetch a season archive:
```sql
-- historique.php:76
SELECT * FROM parties WHERE id=?
```

---

### 23. `migrations` -- Migration tracking

Tracks which SQL migrations have been applied.

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | Migration entry ID |
| `filename` | VARCHAR(255) NOT NULL UNIQUE | -- | Migration filename (e.g. '0001_add_indexes.sql') |
| `applied_at` | TIMESTAMP | CURRENT_TIMESTAMP | When the migration was applied |

**Key relationships:**
- No FK references; used only by `migrations/migrate.php`.

**Example query** -- check applied migrations:
```sql
-- migrations/migrate.php:25
SELECT filename FROM migrations ORDER BY id
```

---

### 24. `connectes` -- Online player tracking

Ephemeral table tracking currently online users by IP. Entries older than 5 minutes are purged on each page load.

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `ip` | VARCHAR(255) | -- | Visitor IP address |
| `timestamp` | INT | -- | Last activity time (Unix timestamp) |

**Indexes** (migration 0001):
- `idx_connectes_ip` on (`ip`)

**Key relationships:**
- No FK references; IP-based tracking (not tied to `membre`).

**Example query** -- insert or update online tracking:
```sql
-- includes/basicprivatephp.php:63
INSERT INTO connectes VALUES(?, ?)
```

---

### 25. `vacances` -- Vacation mode records

Tracks active vacation periods. Deleted when vacation ends.

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | Record ID |
| `idJoueur` | INT | -- | Player ID (FK to `membre.id`) |
| `dateDebut` | DATE | CURRENT_DATE | Vacation start date |
| `dateFin` | DATE | -- | Vacation end date |

**Key relationships:**
- `idJoueur` references `membre.id`

**Example query** -- fetch vacation dates:
```sql
-- compte.php:166
SELECT dateDebut, dateFin FROM vacances WHERE idJoueur = ?
```

---

### 26. `sanctions` -- Forum bans

Active forum bans issued by moderators.

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `idSanction` | INT AUTO_INCREMENT | PK | Sanction ID |
| `joueur` | VARCHAR(255) | -- | Banned player username |
| `moderateur` | VARCHAR(255) | -- | Moderator who issued the ban |
| `motif` | TEXT | -- | Reason for the ban (BBCode) |
| `dateFin` | DATE | -- | Ban expiration date |

**Key relationships:**
- `joueur` references `membre.login`
- `moderateur` references `membre.login`

**Example query** -- check if player is banned:
```sql
-- forum.php:21
SELECT * FROM sanctions WHERE joueur = ?
```

---

### 27. `moderation` -- Moderation log

Records moderator actions (resource grants/modifications).

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | Log entry ID |
| `energie` | BIGINT NOT NULL | 0 | Energy amount |
| `carbone` | BIGINT NOT NULL | 0 | Carbon amount |
| `azote` | BIGINT NOT NULL | 0 | Nitrogen amount |
| `hydrogene` | BIGINT NOT NULL | 0 | Hydrogen amount |
| `oxygene` | BIGINT NOT NULL | 0 | Oxygen amount |
| `chlore` | BIGINT NOT NULL | 0 | Chlorine amount |
| `soufre` | BIGINT NOT NULL | 0 | Sulfur amount |
| `brome` | BIGINT NOT NULL | 0 | Bromine amount |
| `iode` | BIGINT NOT NULL | 0 | Iodine amount |
| `timestamp` | INT | -- | Action time (Unix timestamp) |

**Key relationships:**
- No direct FK; administrative audit log.

**Example query** -- list all moderation actions:
```sql
-- admin/index.php:148
SELECT * FROM moderation
```

---

### 28. `news` -- Game news

Announcements displayed on the public-facing pages.

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | INT AUTO_INCREMENT | PK | News entry ID |
| `titre` | TEXT | -- | News title |
| `contenu` | TEXT | -- | News body (HTML) |
| `timestamp` | INT | -- | Publication time (Unix timestamp) |

**Key relationships:**
- No FK references; displayed on the landing page.

**Example query** -- insert a news entry:
```sql
-- includes/basicprivatephp.php:207
INSERT INTO news VALUES(default, ?, ?, ?)
```

---

## Migration History

| Migration | Description | Key Changes |
|-----------|-------------|-------------|
| `0001_add_indexes.sql` | Add indexes for performance | 22 indexes across 13 tables |
| `0002_fix_column_types.sql` | Fix column types and widths | BIGINT display widths removed; VARCHAR sizes corrected; `membre.login`/`pass_md5` changed from TEXT to VARCHAR(255); `membre.ip` expanded to VARCHAR(45); indexes added on `membre` |
| `0003_add_trade_volume.sql` | Add market trade tracking | Added `autre.tradeVolume` column (DOUBLE, default 0) |

---

## Design Notes

1. **No foreign keys.** All referential integrity is enforced in PHP. The `supprimerJoueur()` function in `includes/player.php` manually deletes from 13 tables when a player account is removed.

2. **Login as text key.** Most tables use `login` (VARCHAR) as their de facto foreign key rather than numeric IDs. This simplifies PHP code (no JOINs needed) but means username changes would require updating every table.

3. **Serialized data in TEXT columns.** Several columns store structured data as delimited strings:
   - `constructions.pointsProducteur` / `pointsCondenseur`: semicolon-separated integers
   - `actionsattaques.troupes`: semicolon-separated troop counts
   - `actionsenvoi.ressourcesEnvoyees` / `ressourcesRecues`: semicolon-separated quantities
   - `cours.tableauCours`: comma-separated price floats
   - `parties.joueurs` / `alliances` / `guerres`: bracket-delimited CSV archives

4. **Unix timestamps everywhere.** All time values are stored as INT/BIGINT Unix timestamps, not MySQL DATETIME/TIMESTAMP types. The sole exceptions are `vacances.dateDebut`/`dateFin` (DATE) and `migrations.applied_at` (TIMESTAMP).

5. **Cached aggregates.** Several values are denormalized for performance:
   - `alliances.pointstotaux`, `totalConstructions`, `totalAttaque`, `totalDefense`, `totalPillage` are recomputed by `recalculerStatsAlliances()`
   - `autre.batmax` is updated on each `initPlayer()` call
   - `ressources.revenu*` columns store cached revenue values
