# TVLW Complete Game Documentation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create comprehensive, multi-part documentation of the entire TVLW game — files, mechanics, database, formulas, variables — readable by both humans and AI, with cross-references between sections.

**Architecture:** 8 standalone Markdown files in `docs/game/`, each covering one domain. A master index links them all. Every formula, constant, and mechanic references exact file paths and line numbers. AI-friendly structure with consistent headings, tables, and code blocks.

**Tech Stack:** Markdown files, no build tools needed. Referenced from any AI context window.

---

### Task 1: Master Index & Game Overview (`docs/game/00-INDEX.md`)

**Files:**
- Create: `docs/game/00-INDEX.md`

**Step 1: Write the master index**

Create the documentation hub that links all parts together. Include:

- Game concept (chemistry-themed strategy game, ~15 years old, PHP 8.2 + MySQL)
- Quick reference table of all 8 documentation parts with descriptions
- Architecture diagram (text-based): page request flow from browser → PHP → DB → response
- File structure overview: root pages, includes/, admin/, migrations/, tests/
- Technology stack summary
- How to read these docs (conventions: `file.php:42` means line 42, `$BUILDING_CONFIG['generateur']` means config key, etc.)

**File include chain diagram:**
```
Browser Request → index.php/constructions.php/etc
  → includes/basicprivatephp.php (auth + session)
    → includes/connexion.php (DB connect)
    → includes/constantes.php
      → includes/config.php (all constants)
      → includes/constantesBase.php (legacy arrays)
    → includes/fonctions.php (modular loader)
      → includes/database.php
      → includes/formulas.php
      → includes/game_resources.php
      → includes/game_actions.php
        → includes/combat.php
      → includes/player.php
      → includes/display.php
      → includes/ui_components.php
      → includes/validation.php
      → includes/csrf.php
      → includes/logger.php
      → includes/rate_limiter.php
  → includes/basicprivatehtml.php (HTML shell)
    → includes/menus.php
    → includes/style.php
  → Page-specific logic
  → includes/copyright.php (footer)
```

**Complete file listing with descriptions (all PHP files):**

Root pages (38):
- `index.php` — Landing/dashboard
- `inscription.php` — Player registration
- `connexion.php` — Login credentials
- `deconnexion.php` — Logout
- `compte.php` — Account settings
- `tutoriel.php` — Tutorial
- `credits.php` — Credits
- `version.php` — Version info
- `regles.php` — Game rules
- `maintenance.php` — Maintenance page
- `constructions.php` — Building management
- `atomes.php` — Atom distribution UI
- `molecule.php` — Molecule editor
- `armee.php` — Army/troop management
- `sinstruire.php` — Tech tree / training
- `attaque.php` — Attack target selection
- `attaquer.php` — Launch attack
- `marche.php` — Market (buy/sell/transfer)
- `don.php` — Alliance energy donation
- `medailles.php` — Medal/achievement display
- `vacance.php` — Vacation mode
- `joueur.php` — Player profile view
- `classement.php` — Rankings/leaderboard
- `connectes.php` — Online players list
- `historique.php` — History
- `rapports.php` — Battle/spy reports
- `messages.php` — Inbox
- `messagesenvoyes.php` — Sent messages
- `ecriremessage.php` — Compose message
- `messageCommun.php` — Global chat
- `alliance.php` — Alliance management
- `allianceadmin.php` — Alliance admin panel
- `validerpacte.php` — Alliance pact validation
- `guerre.php` — War declarations
- `forum.php` — Forum categories
- `listesujets.php` — Forum thread list
- `sujet.php` — Forum thread view
- `editer.php` — Edit forum post
- `moderationForum.php` — Forum moderation
- `voter.php` — Voting
- `api.php` — JSON API endpoint
- `annonce.php` — Announcements
- `video.php` — Video section
- `espionnage.php` — Espionage (if separate)

Include files (26):
- `includes/config.php` — All game constants & balance parameters
- `includes/constantesBase.php` — Legacy constant arrays (medals, thresholds)
- `includes/constantes.php` — Constants loader + session init
- `includes/connexion.php` — MySQLi database connection
- `includes/database.php` — Prepared statement helpers (dbQuery, dbFetchOne, dbExecute)
- `includes/db_helpers.php` — Legacy DB wrappers
- `includes/fonctions.php` — Modular include shim
- `includes/formulas.php` — Pure game formulas (attack, defense, decay, etc.)
- `includes/game_resources.php` — Resource production & update
- `includes/game_actions.php` — Action queue processor (construction, formation, combat, transfers)
- `includes/combat.php` — Combat resolution engine
- `includes/player.php` — Player CRUD, initialization, points, buildings
- `includes/update.php` — Legacy update wrapper
- `includes/validation.php` — Input validation functions
- `includes/csrf.php` — CSRF token generation/verification
- `includes/logger.php` — Structured game logging
- `includes/rate_limiter.php` — Rate limiting
- `includes/display.php` — Number/time formatting, XSS protection
- `includes/ui_components.php` — Card, list, accordion, button builders
- `includes/bbcode.php` — BBCode parser
- `includes/style.php` — CSS output
- `includes/menus.php` — Navigation menu builder
- `includes/tout.php` — Utility glue (loads everything for private pages)
- `includes/basicprivatephp.php` — Auth check + resource/action update on every page
- `includes/basicprivatehtml.php` — HTML head/body wrapper for private pages
- `includes/basicpublichtml.php` / `basicpublicphp.php` — Public page wrappers
- `includes/redirectionVacance.php` — Vacation mode redirect
- `includes/atomes.php` — Atom-specific utilities
- `includes/meta.php` — HTML meta tags
- `includes/copyright.php` — Footer

Admin (9): `admin/index.php`, `admin/tableau.php`, `admin/listenews.php`, `admin/redigernews.php`, `admin/ip.php`, `admin/supprimercompte.php`, `admin/supprimerreponse.php`, `admin/redirectionmotdepasse.php`

Moderation (3): `moderation/index.php`, `moderation/ip.php`, `moderation/mdp.php`

Tests (6): `tests/bootstrap.php`, `tests/unit/ConfigConsistencyTest.php`, `tests/unit/GameFormulasTest.php`, `tests/unit/CombatFormulasTest.php`, `tests/unit/MarketFormulasTest.php`, `tests/unit/ResourceFormulasTest.php`, `tests/unit/ValidationTest.php`

Migrations (4): `migrations/migrate.php`, `migrations/0001_add_indexes.sql`, `migrations/0002_fix_column_types.sql`, `migrations/0003_add_trade_volume.sql`

**Step 2: Commit**

```bash
git add docs/game/00-INDEX.md
git commit -m "docs: add master index and game overview"
```

---

### Task 2: Database Schema Reference (`docs/game/01-DATABASE.md`)

**Files:**
- Create: `docs/game/01-DATABASE.md`

**Step 1: Write the database documentation**

Document all 22 active database tables with:

For each table:
- Table name and purpose (one sentence)
- Column list with types, constraints, defaults
- Relationships to other tables (PHP-enforced, no FK constraints)
- Example queries from the codebase (with file:line references)
- Indexes (from migration 0001)

**Tables to document:**

**Player Core (4 tables):**

1. `membre` — Player accounts
   - Columns: login (VARCHAR 255, PK), pass_md5 (VARCHAR), dateInscription (INT), ip (VARCHAR 45), derniereConnexion (INT), moderateur (INT), aleatoire (INT 0-7), email (VARCHAR), x (INT), y (INT), vacance (TINYINT)
   - Indexes: idx_membre_login, idx_membre_derniereConnexion
   - Referenced by: ALL other tables via login field
   - Key queries: `SELECT * FROM membre WHERE login=?` (player.php:initPlayer)

2. `autre` — Extended player stats
   - Columns: login (FK→membre), tempsPrecedent (BIGINT), description (TEXT), idalliance (INT→alliances.id), points (INT), pointsAttaque (INT), pointsDefense (INT), totalPoints (INT), moleculesPerdues (DOUBLE), ressourcesPillees (DOUBLE), tradeVolume (DOUBLE), nbattaques (INT), victoires (INT), defaites (INT), neutrinos (INT), batmax (INT), energieDepensee (BIGINT), energieDonnee (BIGINT), bombe (INT)
   - Indexes: idx_autre_login, idx_autre_idalliance
   - Key queries: `SELECT totalPoints FROM autre ORDER BY totalPoints DESC` (classement.php)

3. `ressources` — Resource inventory
   - Columns: login (FK→membre), energie (DOUBLE), carbone/azote/hydrogene/oxygene/chlore/soufre/brome/iode (DOUBLE each)
   - Indexes: idx_ressources_login
   - Key queries: `UPDATE ressources SET energie=? WHERE login=?` (game_resources.php:127)

4. `constructions` — Building levels & HP
   - Columns: login (FK→membre), generateur/producteur/depot/champdeforce/ionisateur/condenseur/lieur/stabilisateur (INT each), pointsProducteur (VARCHAR — semicolon-separated 8 values), pointsCondenseur (VARCHAR — same), pointsProducteurRestants (INT), pointsCondenseurRestants (INT), vieGenerateur/vieChampdeforce/vieProducteur/vieDepot (BIGINT)
   - Indexes: idx_constructions_login
   - Note: pointsProducteur/pointsCondenseur store allocation arrays as "1;2;3;0;0;0;0;0"

**Military (1 table):**

5. `molecules` — Molecule army classes
   - Columns: id (INT AUTO_INCREMENT), proprietaire (FK→membre), numeroclasse (INT 1-4), formule (VARCHAR — HTML display), carbone/azote/hydrogene/oxygene/chlore/soufre/brome/iode (INT each, atoms in recipe), nombre (DOUBLE — current count)
   - Indexes: idx_molecules_proprietaire, idx_molecules_proprietaire_classe
   - Note: nombre is DOUBLE because decay produces fractional values

**Action Queues (4 tables):**

6. `actionsconstruction` — Building upgrades in progress
   - Columns: id, login (FK), batiment (VARCHAR — building name), niveau (INT), debut (BIGINT — unix timestamp), fin (BIGINT)
   - Max 2 concurrent per player (MAX_CONCURRENT_CONSTRUCTIONS)

7. `actionsformation` — Molecule/neutrino formation
   - Columns: id, login (FK), idclasse (INT→molecules.id or 'neutrino'), nombreDebut (BIGINT), nombreRestant (BIGINT), fin (BIGINT), tempsPourUn (DOUBLE), debut (BIGINT)

8. `actionsattaques` — Attacks & espionage in transit
   - Columns: id, attaquant (FK), defenseur (FK), troupes (VARCHAR — semicolon-separated counts per class), tempsAller (BIGINT), tempsAttaque (BIGINT), tempsRetour (BIGINT), attaqueFaite (TINYINT 0/1)

9. `actionsenvoi` — Resource transfers in transit
   - Columns: id, envoyeur (FK), receveur (FK), ressourcesEnvoyees (VARCHAR — 9 values semicolon-separated), ressourcesRecues (VARCHAR), tempsArrivee (BIGINT)

**Alliance (3 tables):**

10. `alliances` — Alliance data
    - Columns: id (AUTO_INCREMENT), tag (VARCHAR), chef (FK→membre), description (TEXT), energieAlliance (BIGINT), energieTotaleRecue (BIGINT), duplicateur (INT), pointstotaux (INT)

11. `grades` — Alliance membership
    - Columns: id, login (FK), idalliance (FK→alliances.id), grade (VARCHAR)

12. `declarations` — Wars & pacts
    - Columns: id, alliance1 (FK→alliances.id), alliance2 (FK), type (INT: 0=war, 1=pact), fin (INT: 0=active, 1=ended), pertes1/pertes2/pertesTotales (BIGINT)

**Communication (3 tables):**

13. `messages` — Private messages
    - Columns: id, expeditaire (FK), destinataire (FK), date (INT), titre (VARCHAR), contenu (TEXT), lu (TINYINT)

14. `rapports` — Battle/action reports
    - Columns: id, date (INT), titre (VARCHAR), contenu (TEXT), destinataire (FK), lu (TINYINT default 0), image (VARCHAR — report type icon path)

15. `cours` — Market price history
    - Columns: id, tableauCours (VARCHAR — comma-separated 8 prices), timestamp (INT)

**Forum (4 tables):**

16. `forums` — Forum categories — Columns: id, nom, description
17. `sujets` — Forum threads — Columns: id, idforum (FK→forums.id), titre, auteur (FK), date, contenu
18. `reponses` — Forum replies — Columns: id, idsujet (FK→sujets.id), auteur (FK), date, contenu
19. `statutforum` — Read tracking — Columns: login, idsujet, idforum

**System (3 tables):**

20. `statistiques` — Global game stats (single row)
21. `parties` — Archived round results (season history)
22. `migrations` — Applied migration tracking

**Include an Entity-Relationship diagram in ASCII art.**

**Step 2: Commit**

```bash
git add docs/game/01-DATABASE.md
git commit -m "docs: add database schema reference"
```

---

### Task 3: Resource & Production System (`docs/game/02-RESOURCES.md`)

**Files:**
- Create: `docs/game/02-RESOURCES.md`

**Step 1: Write resource documentation**

Cover:

**The 8 Atom Types** — table with index, name, French name, color, letter abbreviation, primary role:

| # | Name | Letter | Color | Role | Used By |
|---|------|--------|-------|------|---------|
| 0 | Carbone | C | black | Defense | `defense()` formula |
| 1 | Azote | N | blue | Formation speed | `tempsFormation()` |
| 2 | Hydrogene | H | gray | Building destruction | `potentielDestruction()` |
| 3 | Oxygene | O | red | Attack | `attaque()` formula |
| 4 | Chlore | Cl | green | Movement speed | `vitesse()` |
| 5 | Soufre | S | orange | Pillage capacity | `pillage()` |
| 6 | Brome | Br | brown | Molecule HP | `pointsDeVieMolecule()` |
| 7 | Iode | I | pink | Energy production | `productionEnergieMolecule()` |

**Energy System:**
- Production: `revenuEnergie = BASE_ENERGY_PER_LEVEL(75) * generateur_level + totalIodeMoleculeProduction * (1 + medalBonus%) * allianceDuplicateurBonus - drainageProducteur(8 * producteur_level)`
- Detail levels in `revenuEnergie($niveau, $joueur, $detail)`:
  - detail=0: Final (after producteur drain) — `formulas.php:52`
  - detail=1: Before producteur drain — `formulas.php:55`
  - detail=2: Before duplicateur — `formulas.php:57`
  - detail=3: Before medal — `formulas.php:59`
  - detail=4: Base only — `formulas.php:61`
- Storage cap: `placeDepot = 500 * depot_level` — `formulas.php:223-226`

**Atom Production:**
- Formula: `revenuAtome = round(allianceDuplicateurBonus * 60 * pointsAllocatedToThisAtom)` — `game_resources.php:81`
- Points come from producteur building (8 per level to distribute among 8 atom types)
- Stored in `constructions.pointsProducteur` as semicolon-separated values

**Resource Update Cycle** (`updateRessources()` — `game_resources.php:104-180`):
```
Called on every page load
1. Calculate seconds since last update (time() - tempsPrecedent)
2. For energy: current + revenuEnergie * (seconds / 3600)
3. For each atom: current + revenuAtome * (seconds / 3600)
4. Cap all at placeDepot(depot_level)
5. Apply molecule decay: nombre * pow(coefDisparition, seconds)
6. If offline > 6 hours: generate loss report
```

**Condenseur System:**
- Condenseur building gives 5 points per level to distribute among atom "niveaux"
- These niveaux boost molecule stats: `(1 + niveau / 50)` factor in all formulas
- Stored in `constructions.pointsCondenseur`

**Step 2: Commit**

```bash
git add docs/game/02-RESOURCES.md
git commit -m "docs: add resource and production system documentation"
```

---

### Task 4: Building System (`docs/game/03-BUILDINGS.md`)

**Files:**
- Create: `docs/game/03-BUILDINGS.md`

**Step 1: Write building documentation**

For each of the 8 buildings, document:

**Generateur (Energy Generator):**
- Purpose: Produces energy per hour
- Production: `75 * level` energy/hour
- Energy cost: `round((1 - medalBonus/100) * 50 * pow(level, 0.7))`
- Atom cost (each of 8): `round((1 - medalBonus/100) * 75 * pow(level, 0.7))`
- Build time: level 1 = 10s, then `round(60 * pow(level, 1.5))`
- Points awarded: `1 + floor(level * 0.1)` construction points
- HP: `round(20 * (pow(1.2, level) + pow(level, 1.2)))`
- Config key: `$BUILDING_CONFIG['generateur']` — `config.php:127-138`

**Producteur (Atom Producer):**
- Purpose: Produces atoms (drains energy)
- Production: 8 distribution points per level, each point = 60 atoms/hour of that type
- Drain: `8 * level` energy/hour subtracted from energy production
- Energy cost: `round((1 - medalBonus/100) * 75 * pow(level, 0.7))`
- Atom cost (each): `round((1 - medalBonus/100) * 50 * pow(level, 0.7))`
- Build time: level 1 = 10s, then `round(40 * pow(level, 1.5))`
- Points: `1 + floor(level * 0.1)`
- Config: `$BUILDING_CONFIG['producteur']` — `config.php:139-151`

**Depot (Storage):**
- Purpose: Increases max storage for energy + all atoms
- Capacity: `500 * level` per resource
- Energy cost: `round((1 - medalBonus/100) * 100 * pow(level, 0.7))`
- No atom cost
- Build time: `round(80 * pow(level, 1.5))`
- Points: `1 + floor(level * 0.1)`
- Config: `$BUILDING_CONFIG['depot']` — `config.php:152-162`

**Champdeforce (Force Field / Defense Building):**
- Purpose: +2% defense bonus per level in combat; absorbs damage if highest-level building
- Carbone cost: `round((1 - medalBonus/100) * 100 * pow(level, 0.7))`
- Build time: `round(20 * pow(level + 2, 1.7))`
- HP: `round(50 * (pow(1.2, level) + pow(level, 1.2)))` — 2.5x base HP
- Points: `1 + floor(level * 0.075)`
- Config: `$BUILDING_CONFIG['champdeforce']` — `config.php:163-173`

**Ionisateur (Attack Booster):**
- Purpose: +2% attack bonus per level in combat
- Oxygene cost: `round((1 - medalBonus/100) * 100 * pow(level, 0.7))`
- Build time: `round(20 * pow(level + 2, 1.7))`
- Points: `1 + floor(level * 0.075)`
- Config: `$BUILDING_CONFIG['ionisateur']` — `config.php:174-184`

**Condenseur (Molecule Enhancement):**
- Purpose: Gives 5 points per level to allocate to atom "niveaux" that boost molecule stats
- Energy cost: `round((1 - medalBonus/100) * 25 * pow(level, 0.8))`
- Atom cost (each): `round((1 - medalBonus/100) * 100 * pow(level, 0.8))`
- Build time: `round(120 * pow(level + 1, 1.6))`
- Points: `2 + floor(level * 0.1)`
- Config: `$BUILDING_CONFIG['condenseur']` — `config.php:185-197`

**Lieur (Formation Speed):**
- Purpose: Reduces molecule formation time via multiplier
- Bonus: `floor(100 * pow(1.07, level)) / 100` — e.g., level 10 = 1.96x speed
- Azote cost: `round((1 - medalBonus/100) * 100 * pow(level, 0.8))`
- Build time: `round(100 * pow(level + 1, 1.5))`
- Points: `2 + floor(level * 0.1)`
- Config: `$BUILDING_CONFIG['lieur']` — `config.php:198-208`

**Stabilisateur (Decay Reduction):**
- Purpose: Reduces molecule decay rate
- Effect: `1% decay reduction per level` (STABILISATEUR_BONUS_PER_LEVEL = 0.01)
- Atom cost (each): `round((1 - medalBonus/100) * 75 * pow(level, 0.9))`
- Build time: `round(120 * pow(level + 1, 1.5))`
- Points: `3 + floor(level * 0.1)`
- Config: `$BUILDING_CONFIG['stabilisateur']` — `config.php:209-219`

**Construction Queue Rules:**
- Max 2 concurrent constructions (MAX_CONCURRENT_CONSTRUCTIONS)
- Medal bonus (Energievore + Constructeur) reduces costs
- Processed by `updateActions()` in `game_actions.php`
- On completion: level++, HP reset to max, points awarded

**Include a cost comparison table at levels 1, 5, 10, 20, 50.**

**Step 2: Commit**

```bash
git add docs/game/03-BUILDINGS.md
git commit -m "docs: add building system documentation"
```

---

### Task 5: Molecule & Combat System (`docs/game/04-COMBAT.md`)

**Files:**
- Create: `docs/game/04-COMBAT.md`

**Step 1: Write combat documentation**

**Molecule System:**
- 4 classes per player (MAX_MOLECULE_CLASSES)
- Each class has a fixed recipe of 8 atom types (max 200 per element)
- Class unlock cost: `pow(classNumber + 1, 4)` energy — Class 1: 16, Class 2: 81, Class 3: 256, Class 4: 625
- Molecules have quantity (`nombre` in DB, DOUBLE for decay)
- Formation time: `ceil(totalAtoms / (1 + pow(0.09 * azote, 1.09)) / (1 + niveau / 20) / bonusLieur * 100) / 100` hours per molecule

**Molecule Stats (per molecule):**

| Stat | Atom | Formula | File:Line |
|------|------|---------|-----------|
| Attack | Oxygene | `round((1 + (0.1*O)² + O) * (1 + niveauO/50) * (1 + medalBonus%))` | formulas.php:80-96 |
| Defense | Carbone | `round((1 + (0.1*C)² + C) * (1 + niveauC/50) * (1 + medalBonus%))` | formulas.php:98-114 |
| HP | Brome | `round((1 + (0.1*Br)² + Br) * (1 + niveauBr/50))` | formulas.php:116-119 |
| Destruction | Hydrogene | `round(((0.075*H)² + H) * (1 + niveauH/50))` | formulas.php:121-124 |
| Pillage | Soufre | `round(((0.1*S)² + S/3) * (1 + niveauS/50) * (1 + medalBonus%))` | formulas.php:126-142 |
| Speed | Chlore | `floor((1 + 0.5*Cl) * (1 + niveauCl/50) * 100) / 100` | formulas.php:149-152 |
| Energy Prod | Iode | `round(0.05 * I * (1 + niveauI/50))` | formulas.php:144-147 |
| Formation | Azote | `ceil(N / (1 + (0.09*Az)^1.09) / (1+niv/20) / lieur * 100) / 100` | formulas.php:159-164 |

Note: `niveauX` = condenseur points allocated to atom X.

**Molecule Decay:**
- Per-second coefficient: `pow(pow(0.99, pow(1 + totalAtoms/100, 2) / 25000), (1 - medalBonus/100) * (1 - stabilisateur * 0.01))`
- Applied continuously: `remainingCount = originalCount * pow(coefficient, elapsedSeconds)`
- Half-life: `log(0.5) / log(coefficient)` seconds — `formulas.php:200-203`
- More atoms in recipe = faster decay (complexity penalty)
- Stabilisateur building reduces decay
- Medal "Pertes" also reduces decay

**Combat Resolution Step-by-Step** (`combat.php`):

1. **Load combatants**: Fetch all 4 molecule classes + quantities for attacker & defender
2. **Load bonuses**: ionisateur level (attack), champdeforce level (defense), alliance duplicateur
3. **Calculate total attack damage**:
   ```
   totalAttack = SUM(classes) of:
     attaque(oxygene, niveauOxygene, attacker)
     * (1 + ionisateur * 2 / 100)
     * (1 + duplicateur / 100)
     * moleculeCount
   ```
4. **Calculate total defense damage**: Same pattern with carbone + champdeforce bonus
5. **Resolve attacker casualties**: Defense damage kills attacker molecules (HP-based, class by class)
6. **Resolve defender casualties**: Attack damage kills defender molecules
7. **Determine winner**:
   - Winner = 1 (Defender) if attacker has 0 molecules remaining
   - Winner = 2 (Attacker) if defender has 0 molecules remaining
   - Winner = 0 (Draw) otherwise
8. **Pillage** (attacker wins only): Each remaining attacker molecule pillages using soufre formula. Resources deducted from defender, added to attacker.
9. **Building damage** (attacker wins + has hydrogene):
   - Total destruction = SUM(remainingTroops * potentielDestruction(H, niveauH))
   - If champdeforce is highest level building: all damage → champdeforce
   - Otherwise: randomly targets generateur/champdeforce/producteur/depot
   - Buildings can't go below level 1
10. **Award combat points**:
    ```
    totalCasualties = attackerLosses + defenderLosses
    battlePoints = min(20, floor(1 + 0.5 * sqrt(totalCasualties)))
    Winner: +battlePoints, Loser: -battlePoints
    ```
11. **Update stats**: pointsAttaque/pointsDefense, ressourcesPillees, moleculesPerdues, nbattaques
12. **Generate reports**: Detailed HTML reports for both players
13. **Alliance war**: If war active, update declarations.pertes

**Attack Energy Cost:**
- `0.15 * (1 + terreurMedalBonus/100) * totalAtomsInAllSentMolecules` energy
- Terreur medal makes attacks cheaper (counterintuitive but rewards aggression)

**Espionage:**
- Cost: 50 energy per neutrino
- Travel: `distance / 20` hours (ESPIONAGE_SPEED = 20)
- Success: Your neutrinos / 2 > defender's neutrinos
- Reveals: Army composition, resources, buildings, HP

**Step 2: Commit**

```bash
git add docs/game/04-COMBAT.md
git commit -m "docs: add molecule and combat system documentation"
```

---

### Task 6: Market & Economy (`docs/game/05-ECONOMY.md`)

**Files:**
- Create: `docs/game/05-ECONOMY.md`

**Step 1: Write economy documentation**

**Market Trading** (`marche.php`):

**Buying atoms:**
- Cost: `round(currentPrice * quantity)` energy
- Price impact: `newPrice = oldPrice + (0.3 / nbActifs) * quantity / placeDepot`
- Mean reversion: `newPrice = newPrice * 0.99 + 1.0 * 0.01`
- Clamp: `max(0.1, min(10.0, newPrice))`
- Awarding trade points: tracks `$coutAchat` (energy spent) in `autre.tradeVolume`
- Points: `min(40, floor(0.05 * sqrt(tradeVolume)))`

**Selling atoms:**
- Gain: `round(currentPrice * quantity)` energy (capped at storage)
- Price impact: `newPrice = 1 / (1/oldPrice + (0.3/nbActifs) * quantity / placeDepot)`
- Same mean reversion + clamping
- Does NOT award trade points (anti-exploit: only buying counts)

**Price Dynamics:**
- Starting price: ~1.0 energy per atom (baseline)
- Floor: 0.1 (MARKET_PRICE_FLOOR)
- Ceiling: 10.0 (MARKET_PRICE_CEILING)
- Mean reversion: 1% pull toward 1.0 per trade
- Volatility inversely proportional to active player count
- Price history stored in `cours` table (last 1000 entries shown in chart)

**Resource Transfers** (`marche.php` sub=1):
- Player-to-player resource sending
- Anti-alt: Same IP addresses cannot trade
- Travel time: `round(3600 * distance / 20)` seconds
- Receiving rate: `min(1.0, receiver_production / sender_production)` — weaker players receive less
- All 8 atoms + energy can be sent simultaneously

**Alliance Energy Donations** (`don.php`):
- Send energy to alliance pool (`alliances.energieAlliance`)
- Tracked in `autre.energieDonnee`
- Used to upgrade alliance duplicateur

**Duplicateur (Alliance Tech):**
- Cost: `round(10 * pow(2.5, level + 1))`
  - Level 1: 62, Level 2: 156, Level 3: 390, Level 4: 976, Level 5: 2441
- Effect: `+level%` to all resource production for all members
- Also applies in combat (attack + defense bonus)

**Economy Flow Diagram:**
```
Energy (Generateur) ──→ Buy Atoms (Market)
       │                       │
       ├──→ Build Buildings    ├──→ Create Molecules ──→ Attack/Defend
       │                       │                              │
       ├──→ Buy Neutrinos      ├──→ Molecule Decay           │
       │                       │                              ↓
       ├──→ Send to Alliance   └──→ Sell Atoms ──→ Energy    Pillage Resources
       │         │                                            │
       │         ↓                                            ↓
       │    Duplicateur ──→ +% Production                Trade Points
       │                                                      │
       └──→ Trade Points (buy energy spent)                   ↓
                    │                                   totalPoints
                    ↓
              totalPoints
```

**Step 2: Commit**

```bash
git add docs/game/05-ECONOMY.md
git commit -m "docs: add market and economy documentation"
```

---

### Task 7: Points, Rankings & Medals (`docs/game/06-POINTS.md`)

**Files:**
- Create: `docs/game/06-POINTS.md`

**Step 1: Write points documentation**

**Total Points Formula** (the ranking metric):
```
totalPoints = constructionPoints
            + pointsAttaque(rawAttackPoints)      // sqrt scaling
            + pointsDefense(rawDefensePoints)     // sqrt scaling
            + pointsPillage(ressourcesPillees)     // tanh scaling
            + tradePoints                          // sqrt scaling, capped at 40
```

**Construction Points:**
- Awarded when building completes upgrade
- Per building: `points_base + floor(level * points_level_factor)` from $BUILDING_CONFIG
- Tracked in `autre.points`
- Updated by `ajouterPoints($nb, $joueur, 0)` — `player.php`

**Attack Points:**
- Raw: accumulated from combat (winner gets +battlePoints, loser gets -battlePoints)
- Display: `pointsAttaque(raw) = round(3.0 * sqrt(raw))` — `formulas.php:53-57`
- Can go negative (losing battles)

**Defense Points:**
- Same as attack: `pointsDefense(raw) = round(3.0 * sqrt(raw))` — `formulas.php:59-63`
- Defending successfully earns positive points

**Pillage Points:**
- Based on total resources pillaged (cumulative, defender loses points too)
- Display: `pointsPillage(total) = round(tanh(total / 100000) * 50)` — `formulas.php:65-68`
- Tanh caps effectively at ~50 points (approaches asymptotically)
- Self-correcting: defender's ressourcesPillees decreases, so their pillage points drop

**Trade Points:**
- Based on energy spent on market buys
- `min(40, floor(0.05 * sqrt(tradeVolume)))` — `marche.php:203-204`
- Only buy-side counts (selling does not award points)
- To reach max 40: need 640,000 energy spent on buys

**Victory Points (End-of-Season):**

Player rankings:
| Rank | Points |
|------|--------|
| 1st | 100 |
| 2nd | 80 |
| 3rd | 70 |
| 4-10 | 65, 60, 55, 50, 45, 40, 35 |
| 11-20 | 33, 31, 29, 27, 25, 23, 21, 19, 17, 15 |
| 21-50 | floor(15 - (rank-20)*0.5) |
| 51-100 | max(1, floor(15 - (rank-20)*0.15)) |
| 101+ | 0 |

Alliance rankings:
| Rank | Points |
|------|--------|
| 1st | 15 |
| 2nd | 10 |
| 3rd | 7 |
| 4-9 | 10 - rank |
| 10+ | 0 |

**Ranking Views** (`classement.php`):
- Default: totalPoints
- Alternative sorts: batmax, victoires, pointsAttaque, pointsDefense, ressourcesPillees, points

**Medal System:**

10 medal categories, 8 tiers each (Bronze → Diamant Rouge):

| Tier | Name | Bonus % |
|------|------|---------|
| 1 | Bronze | 1% |
| 2 | Argent | 3% |
| 3 | Or | 6% |
| 4 | Emeraude | 10% |
| 5 | Saphir | 15% |
| 6 | Rubis | 20% |
| 7 | Diamant | 30% |
| 8 | Diamant Rouge | 50% |

Medal categories with thresholds and effects:

| Medal | Metric | Thresholds | Effect |
|-------|--------|------------|--------|
| Terreur | Attacks launched | 5→1000 | Reduces attack energy cost |
| Attaque | Raw attack points | 100→10M | Boosts molecule attack stat |
| Defense | Raw defense points | 100→10M | Boosts molecule defense stat |
| Pillage | Resources pillaged | 1k→100M | Boosts molecule pillage stat |
| Pipelette | Forum messages | 10→5k | Forum badge only |
| Pertes | Molecules lost | 10→1M | Reduces molecule decay rate |
| Energievore | Energy spent on buildings | 100→1B | Reduces building costs |
| Constructeur | Highest building level | 5→100 | Reduces building costs |
| Bombe | Buildings destroyed | 1→12 | Badge only |
| Troll | Unknown metric | 0→7 | Badge only |

**Step 2: Commit**

```bash
git add docs/game/06-POINTS.md
git commit -m "docs: add points, rankings, and medal documentation"
```

---

### Task 8: Alliance & Player Lifecycle (`docs/game/07-ALLIANCES.md`)

**Files:**
- Create: `docs/game/07-ALLIANCES.md`

**Step 1: Write alliance and player lifecycle documentation**

**Alliance System:**

Creation:
- Tag: 3-16 alphanumeric characters (`ALLIANCE_TAG_MIN/MAX_LENGTH`)
- Creator becomes chef (leader)
- Max 20 members (`MAX_ALLIANCE_MEMBERS`)

Membership:
- Grades stored in `grades` table (login, alliance, grade name)
- Chef can invite players, kick members, promote ranks
- Members can donate energy to alliance pool

Duplicateur:
- Cost: `round(10 * pow(2.5, level + 1))` energy from alliance pool
- Level 1: 62, Level 2: 156, Level 3: 390...
- Effect: +1% per level to all member resource production AND combat stats

War System:
- Alliance can declare war on another alliance
- Tracked in `declarations` table (type=0 for war)
- War casualties tracked per side (pertes1, pertes2)
- Molecules lost in combat between warring alliances count toward war stats

Pacts:
- Type=1 in declarations table
- Requires validation from both sides (`validerpacte.php`)

**Player Lifecycle:**

1. **Registration** (`inscription.php` → `player.php:inscrire()`):
   - Rate limit: 3 per hour per IP
   - Random starting element (weighted: 50% chance of element 0, 0.5% chance of element 7)
   - Creates rows in: membre, autre, ressources, constructions, 4x molecules
   - Starting position: x=-1000, y=-1000 (off-map, placed on first real login)
   - All buildings level 0 except generateur/producteur/depot at level 1

2. **New Player Boost**:
   - Beginner protection: 5 days (can't attack or be attacked)
   - Production boost: 2x for 3 days

3. **Active Game Loop** (every page load):
   - Auth check (`basicprivatephp.php`)
   - `updateRessources()` — accumulate resources since last visit
   - `updateActions()` — process completed constructions, formations, attacks, transfers
   - `initPlayer()` — load all player data into global variables
   - Render page

4. **Vacation Mode** (`vacance.php`):
   - Player sets vacation start/end
   - During vacation: no resource production, no attacks, no actions processed
   - Redirected to vacation page on any game page access
   - Time paused: tempsPrecedent not updated

5. **Season Reset** (`player.php:remiseAZero()`):
   - Archives top 20 players + top 20 alliances into `parties` table
   - Resets all: points=0, resources=0, buildings to level 1, molecules to empty
   - Deletes all action queues, messages, reports
   - Resets alliance duplicateur and energy
   - Resets tradeVolume, all combat stats, all medals
   - Players placed off-map (x=-1000, y=-1000)

6. **Account Deletion** (`player.php:supprimerJoueur()` / `admin/supprimercompte.php`):
   - Removes from all tables: membre, autre, ressources, constructions, molecules
   - Removes from alliance (grades, updates alliance stats)
   - Deletes all actions, messages, reports

**Map System:**
- 2D coordinate grid (x, y integers)
- New players placed at random coordinates on first login (`coordonneesAleatoires()`)
- Distance: `sqrt((x1-x2)² + (y1-y2)²)` (Euclidean)
- Affects: attack travel time, espionage travel time, resource transfer time
- All travel: `distance / speed * 3600` seconds (speed = 20 cases/hour)

**Step 2: Commit**

```bash
git add docs/game/07-ALLIANCES.md
git commit -m "docs: add alliance system and player lifecycle documentation"
```

---

### Task 9: API & Technical Reference (`docs/game/08-TECHNICAL.md`)

**Files:**
- Create: `docs/game/08-TECHNICAL.md`

**Step 1: Write technical reference**

**API Endpoints** (`api.php`):
All return JSON. Require authenticated session.

| Endpoint | Parameters | Returns |
|----------|-----------|---------|
| `?id=attaque` | atom counts + level | Attack power value |
| `?id=defense` | atom counts + level | Defense power value |
| `?id=pointsDeVieMolecule` | atom counts + level | HP value |
| `?id=potentielDestruction` | atom counts + level | Destruction value |
| `?id=pillage` | atom counts + level | Pillage capacity |
| `?id=productionEnergieMolecule` | atom counts + level | Energy production |
| `?id=vitesse` | atom counts + level | Movement speed |
| `?id=tempsFormation` | atom counts + level + total | Formation time |
| `?id=demiVie` | total atoms or class | Half-life in seconds |

**Database Helper Functions** (`database.php`):

| Function | Purpose | Returns |
|----------|---------|---------|
| `dbQuery($base, $sql, $types, ...$params)` | SELECT with prepared statement | mysqli_result |
| `dbFetchOne($base, $sql, $types, ...$params)` | Fetch single row | Associative array |
| `dbFetchAll($base, $sql, $types, ...$params)` | Fetch all rows | Array of arrays |
| `dbExecute($base, $sql, $types, ...$params)` | INSERT/UPDATE/DELETE | void |
| `dbLastId($base)` | Last auto-increment ID | int |
| `dbCount($base, $sql, $types, ...$params)` | Count rows | int |
| `dbEscapeLike($str)` | Escape LIKE wildcards | string |

**Security Layer:**
- CSRF: `csrfToken()`, `csrfField()`, `csrfCheck()` — `csrf.php`
- XSS: `antiXSS($phrase)` — `display.php`
- Input validation: `validateLogin()`, `validateEmail()`, `validatePositiveInt()`, `validateRange()` — `validation.php`
- Rate limiting: `rateLimitCheck($id, $action, $max, $window)` — `rate_limiter.php`
- All DB queries use prepared statements (dbQuery/dbFetchOne/dbExecute)

**Logging** (`logger.php`):
- `logInfo($category, $message, $context)` — Info level
- `logWarn($category, $message, $context)` — Warning
- `logError($category, $message, $context)` — Error
- Categories: MARKET, COMBAT, AUTH, CONSTRUCTION, etc.
- Output: file-based logs

**Session Management:**
- PHP native sessions with secure flags
- Password hash stored in session for verification
- Session ID regenerated once per session
- Login rate limit: 10 attempts per 5 minutes per IP

**Configuration Constants Reference** — Full table of all `define()` constants from `config.php` with value, description, and which formula uses them.

**Step 2: Commit**

```bash
git add docs/game/08-TECHNICAL.md
git commit -m "docs: add API and technical reference documentation"
```

---

## Execution Notes

- All documentation goes in `docs/game/` directory (create it)
- Use consistent heading hierarchy: `#` for title, `##` for sections, `###` for subsections
- Every formula must reference `file.php:lineNumber`
- Every constant must reference its `config.php` define() or $BUILDING_CONFIG key
- Cross-reference other doc parts with relative links: `[see Combat](04-COMBAT.md)`
- Tables for comparison data (costs at different levels, medal thresholds)
- Code blocks for formulas (PHP syntax)
- ASCII diagrams for flows and relationships
