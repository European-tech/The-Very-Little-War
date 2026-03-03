# TVLW Feature Proposals -- Round 1

**Date:** 2026-03-03
**Analyst:** Game Design Consultant (Architecture Review)
**Codebase:** /home/guortates/TVLW/The-Very-Little-War/
**Context:** 15-year-old PHP chemistry strategy game, monthly seasons, 8 atoms, 4 molecule classes, alliances, market, combat, prestige, isotopes, formations, chemical reactions, tutorial missions, medals.

---

## Table of Contents

1. [Engagement & Retention Features](#1-engagement--retention-features)
2. [Social & Community Features](#2-social--community-features)
3. [New Game Mechanics](#3-new-game-mechanics)
4. [Visual & UX Improvements](#4-visual--ux-improvements)
5. [Technical Modernization](#5-technical-modernization)
6. [Monetization (Cosmetic Only)](#6-monetization-cosmetic-only)
7. [Implementation Priority Matrix](#7-implementation-priority-matrix)

---

## 1. Engagement & Retention Features

### [IDEA-R1-001] HIGH -- Daily Login Reward Chain: "Tableau Periodique Quotidien"

**Description:** A daily login reward system themed as "filling in your personal periodic table." Each day the player logs in, they earn an increasing reward (energy, atoms, prestige points). Consecutive days fill element slots on a visual periodic table grid. Missing a day resets the chain but keeps permanent unlocks.

**Why it fits:** The game already tracks `derniereConnexion` in the `membre` table and has energy rewards in the tutorial system (`tutoriel.php` lines 28, 47, 65). Players currently have no incentive to log in daily beyond checking resources. The chemistry theme makes a periodic table fill-in naturally thematic.

**Mechanic details:**
- Day 1-7: Escalating energy (100, 200, 400, 800, 1600, 3200, 6400)
- Day 7 bonus: Random rare atom bundle (50 of a random atom)
- Day 14 bonus: 10 Prestige Points
- Day 28 bonus: Exclusive cosmetic frame for profile
- Day 30 (full month): "Element Collector" badge visible on classement.php
- Each element on the periodic table grid represents one day; fill the grid to unlock permanent +1% production

**Schema impact:** Add `daily_streak INT DEFAULT 0, last_daily_claim INT DEFAULT 0` to `autre` table.

**Files affected:** New `daily_reward.php`, modifications to `cardsprivate.php` (dashboard notification), `includes/game_resources.php` (reward distribution).

---

### [IDEA-R1-002] HIGH -- Achievement System: "Prix Nobel de Chimie"

**Description:** A permanent achievement system separate from medals. Medals track cumulative stats (attack points, losses, etc.), but achievements reward specific one-time accomplishments: "Win a battle without losses," "Have all 4 molecule classes active simultaneously," "Trade 10 different atoms in one session," "Reach #1 on the leaderboard for any stat."

**Why it fits:** The medal system (`medailles.php`) is purely threshold-based on cumulative counters (`$MEDAL_THRESHOLDS_TERREUR`, `$MEDAL_THRESHOLDS_ATTAQUE`, etc.). There is no system for recognizing strategic milestones or creative play. Achievements encourage diverse play patterns rather than grinding a single stat.

**Mechanic details:**
- ~50 achievements across categories: Combat, Economy, Social, Exploration, Chemistry
- Chemistry-specific: "Trigger all 5 chemical reactions in a single battle," "Have a molecule with exactly 200 atoms of one type," "Maintain a Catalytique isotope class for 7 days"
- Each achievement awards a one-time PP bonus (5-50 PP) and a profile badge
- Achievement display page with locked/unlocked states, progress bars for progressive ones
- Alliance achievements: "Alliance wins 100 wars," "All 6 research technologies at level 10+"

**Schema impact:** New `achievements` table: `(id, login, achievement_key, unlocked_at)`. Achievement definitions in `includes/achievements.php`.

**Files affected:** New `achievements.php` page, hooks in `includes/combat.php`, `marche.php`, `alliance.php`, `includes/game_actions.php`.

---

### [IDEA-R1-003] HIGH -- Season Pass / Monthly Objectives: "Protocole Experimental"

**Description:** Each monthly season introduces a set of 20-30 objectives with escalating rewards, forming a "season pass" progression track. Free track for all players; premium cosmetic track for paying players (see monetization section). Objectives rotate each season to keep gameplay fresh.

**Why it fits:** The game already has monthly resets (season system with `remiseAZero()`), prestige points (`prestige.php`), and victory points. But mid-season engagement drops once players feel they cannot catch up. A season pass gives incremental goals throughout the month regardless of competitive ranking.

**Example objectives per season:**
- Tier 1 (easy): "Upgrade any building 5 times" / "Buy 500 atoms on the market"
- Tier 2 (medium): "Win 3 battles" / "Reach Condenseur level 10" / "Trigger a Chemical Reaction"
- Tier 3 (hard): "Pillage 50,000 resources" / "Build all 4 molecule classes" / "Reach top 20 ranking"
- Tier 4 (elite): "Win 10 battles without losing any" / "Hold #1 rank for 24 consecutive hours"
- Rewards: Energy, atoms, prestige points, exclusive cosmetic borders, catalyst tokens

**Schema impact:** New `season_pass` table: `(id, login, season_id, objective_key, completed_at)`. Objective definitions rotated per season in config.

---

### [IDEA-R1-004] MEDIUM -- Comeback Mechanic: "Demi-Vie de Retour"

**Description:** Players who have been inactive for 7+ days receive a "returning chemist" boost when they log back in: 48 hours of +50% production, a resource care package scaled to current server average, and extended beginner protection (72 hours). Named after the half-life decay mechanic already in the game.

**Why it fits:** The game tracks `derniereConnexion` and already has `BEGINNER_PROTECTION_SECONDS` (5 days). Inactive players return to find themselves far behind -- their molecules have decayed (via `coefDisparition()`), resources are low, and buildings may have been damaged. Without a catch-up mechanism, returning players immediately quit again.

**Mechanic details:**
- 7-14 day absence: +25% production for 48h, energy grant = server median energy
- 14-30 day absence: +50% production for 72h, full resource grant, 48h attack immunity
- 30+ day absence: Full "new player" treatment with tutorial re-shown, beginner protection reset
- Display: Welcome-back modal with summary of what happened ("Your molecules decayed to X, alliance Y won the last season")

**Schema impact:** `comeback_bonus_until INT DEFAULT 0, comeback_tier INT DEFAULT 0` added to `autre` table.

---

### [IDEA-R1-005] MEDIUM -- Progressive Tutorial Expansion: "Cours Avances"

**Description:** Extend the existing 7-mission tutorial (`tutoriel.php`) with an "advanced course" track that unlocks after completing the basics. 10 new missions covering: isotope selection, chemical reactions, formation strategy, market arbitrage, alliance diplomacy, prestige spending. Each teaches a mechanic that most new players never discover.

**Why it fits:** The tutorial currently covers only the basics (build generator, produce atoms, create molecule, spy, join alliance). Critical mechanics like isotopes (`ISOTOPE_STABLE/REACTIF/CATALYTIQUE`), chemical reactions (`$CHEMICAL_REACTIONS`), formations (`FORMATION_PHALANGE/EMBUSCADE`), specializations (`$SPECIALIZATIONS`), and the weekly catalyst system are never explained in-game. The `sinstruire.php` page teaches real chemistry, not game mechanics.

**Advanced missions:**
1. "Choose an isotope variant for a molecule class" -- reward: 1000 energy
2. "Trigger a chemical reaction in battle" -- reward: 1500 energy
3. "Set a defensive formation" -- reward: 500 energy + 5 PP
4. "Buy and sell on the market in the same session" -- reward: 800 energy
5. "Donate energy to your alliance" -- reward: 1000 energy
6. "Choose a specialization" -- reward: 2000 energy + 10 PP
7. "Upgrade the Stabilisateur to reduce decay below 99%" -- reward: 1000 energy
8. "Build a Coffre-Fort to protect resources" -- reward: 1500 energy
9. "Reach 100 neutrinos and spy on 3 different players" -- reward: 2000 energy
10. "Earn a Bronze medal in any category" -- reward: 5 PP

**Files affected:** Extend `tutoriel.php` with phase 2 missions, update `$tutoOffset` calculations.

---

### [IDEA-R1-006] MEDIUM -- Weekly Challenges: "Defi Hebdomadaire"

**Description:** Each week (synced with the catalyst rotation from `catalyst.php`), a specific global challenge is announced that all players can participate in. The catalyst already sets the gameplay tone for the week; the challenge amplifies it. Example: During "Combustion" catalyst (+10% attack), the weekly challenge is "Deal the most total combat damage."

**Why it fits:** The weekly catalyst system (`$CATALYSTS`, 6 catalysts rotating via `getActiveCatalyst()`) already creates variety but is passive -- players do not actively engage with it. A challenge layer makes the catalyst week feel like an event. The `statistiques` table already tracks `catalyst` and `catalyst_week`.

**Challenge per catalyst:**
- Combustion (attack +10%): "Deal the most total damage this week" -- top 10 get bonus PP
- Synthese (formation +20%): "Form the most molecules this week" -- top 10 get energy
- Equilibre (market convergence): "Complete the most market trades" -- top 10 get atoms
- Fusion (duplicateur discount): "Donate the most energy to alliance" -- top 10 get alliance research
- Cristallisation (construction speed): "Upgrade the most building levels" -- top 10 get PP
- Volatilite (decay + pillage): "Pillage the most resources" -- top 10 get vault levels

**Schema impact:** New `weekly_challenge_scores` table: `(id, login, week_id, score)`.

---

## 2. Social & Community Features

### [IDEA-R1-007] HIGH -- In-Game Chat System: "Messagerie Instantanee"

**Description:** Replace the forum-only communication model with a lightweight real-time chat system. Three channels: Global (all players), Alliance (team only), Direct Messages. Uses AJAX long-polling initially (no WebSocket dependency), upgradeable to WebSocket later.

**Why it fits:** The current communication is limited to the forum (`forum.php` with `reponses` table) and individual messages (`messages.php` with `rapports` table). There is no real-time communication. Alliance coordination requires external tools (Discord, etc.), which fractures the community.

**Implementation approach:**
- Phase 1: AJAX polling every 5 seconds on chat endpoint, stored in `chat_messages` table
- Phase 2: WebSocket upgrade via Ratchet PHP or Swoole for true real-time
- Chat shows online status (already tracked via `ONLINE_TIMEOUT_SECONDS = 300`)
- Alliance chat has pinned messages capability for leaders
- Rate-limited: max 1 message per 2 seconds per player (reuse `rate_limiter.php` pattern)
- BBCode support (reuse existing `includes/bbcode.php`)

**Schema impact:** New `chat_messages` table: `(id, channel, sender, message, created_at)`. Index on `(channel, created_at)`.

---

### [IDEA-R1-008] HIGH -- Alliance Wars Dashboard: "Tableau de Guerre"

**Description:** A dedicated war dashboard that replaces the current minimal war tracking. Currently, wars are stored in `declarations` table with just `pertes1/pertes2` counters. The dashboard would show: real-time war score, individual member contributions, battle history, resource pillage totals, and war objectives.

**Why it fits:** The alliance war system exists (`declarations` table, `combat.php` lines 618-633 updating war casualties) but is barely visible to players. Wars are declared but there is no way to track progress, see who is contributing, or know how close you are to winning. This is a major missed opportunity for alliance engagement.

**Features:**
- War score: Weighted combination of casualties inflicted, resources pillaged, buildings destroyed
- Member contribution leaderboard within the war
- Battle log: Every combat between the two alliances during the war period
- War objectives: Optional goals like "Destroy 5 buildings" or "Pillage 100k resources"
- War chat: Dedicated chat channel for wartime communication
- War end conditions: Surrender vote, time limit (7 days), or objective completion
- Post-war summary with MVP awards and alliance PP distribution

**Schema impact:** Extend `declarations` table with objective columns. New `war_battles` table linking combats to wars.

---

### [IDEA-R1-009] MEDIUM -- Alliance Trading: "Marche d'Equipe"

**Description:** An internal alliance marketplace where members can post buy/sell orders for atoms at custom prices, visible only to alliance members. Trades are instant (no travel time like `actionsenvoi`) and have no tax (vs. the 5% sell tax on public market).

**Why it fits:** The current resource transfer system (`marche.php?sub=1`, lines 21-143) is point-to-point (one sender, one receiver) with travel time based on distance and production-ratio penalties. Alliance members cannot efficiently distribute resources. The public market (`marche.php?sub=0`) has a 5% sell tax. An internal market would incentivize alliance membership and coordination.

**Mechanic details:**
- Members post offers: "Selling 500 Carbone for 200 energy" or "Buying 300 Oxygene, paying 150 energy"
- Orders are matched automatically or manually accepted
- No travel time (alliance warehouse concept)
- Alliance leader can set market policies (e.g., max trade size, restricted atoms)
- Trade history visible to all members for transparency

**Schema impact:** New `alliance_market` table: `(id, alliance_id, seller, atom_type, quantity, price_energy, status, created_at)`.

---

### [IDEA-R1-010] MEDIUM -- Diplomacy Overhaul: "Conseil de Securite"

**Description:** Expand the pact/war system with a full diplomacy layer: Non-Aggression Pacts (NAP), Trade Agreements (+10% cross-alliance trade efficiency), Research Partnerships (share 25% of alliance research bonuses), Mutual Defense Pacts (auto-notify when ally is attacked), and Tribute demands (demand resources to avoid war).

**Why it fits:** The current diplomacy is binary: pact (friendly) or war (hostile). The `declarations` table has `type` column (0=war, presumably 1=pact). There is no middle ground. Real strategy games thrive on diplomatic nuance. The alliance research system (`$ALLIANCE_RESEARCH`) already has 5 technologies that could interact with diplomatic bonuses.

**Diplomacy types:**
- NAP: Cannot attack each other, 48h notice to cancel
- Trade Agreement: -50% travel time for resource transfers between alliances, -2% market tax
- Research Partnership: Both alliances share 25% of each other's research levels (read-only)
- Mutual Defense: Auto-send alert when member is attacked; +10% defense when fighting mutual enemy
- Tribute: Demand periodic resource payment to maintain peace; refusal = automatic war declaration

**Schema impact:** Extend `declarations` table with `type` expanded (0-5), add `terms` JSON column.

---

### [IDEA-R1-011] LOW -- Player Profile Enhancements: "Carte d'Identite Chimique"

**Description:** Rich player profiles showing: combat record (W/L/D), favorite molecule compositions, market activity graph, medal collection display, prestige unlocks, achievement badges, alliance history, and a customizable "lab coat" avatar built from earned cosmetics.

**Why it fits:** The current player profile (`joueur.php`) shows basic stats. The game already has rich data: medals (`medailles.php`), prestige (`prestige.php`), combat history (in `rapports` table), market trades, alliance membership. This data is scattered and never aggregated into a compelling profile.

**Profile sections:**
- Header: Username, alliance tag, prestige rank, legend badge if earned
- Combat stats: Battles fought, win rate, favorite class composition, active chemical reactions
- Economy stats: Total market volume, resource production rates, building levels radar chart
- Social stats: Forum posts (already tracked for Pipelette medal), alliance contributions
- Achievements showcase: Player selects 5 achievements to display prominently
- Activity graph: Login frequency, combat activity, market trades over last 30 days

---

## 3. New Game Mechanics

### [IDEA-R1-012] HIGH -- Tournament System: "Tournoi des Elements"

**Description:** Weekly or bi-weekly automated tournaments where players opt in and are matched in bracket-style elimination. Battles use the existing combat engine but with standardized rules: all participants fight with their current armies, bracket seeded by ranking. Winner earns major PP rewards, exclusive cosmetics, and a "Champion" title visible server-wide.

**Why it fits:** The game has a full combat engine (`combat.php`, 634 lines) with isotopes, formations, chemical reactions, building bonuses, alliance duplicateur effects, and prestige bonuses. But combat is currently ad-hoc -- players attack whoever they find on the map. A structured tournament gives combat a focal point and creates community events.

**Tournament format:**
- Entry: Open for 48 hours, requires minimum army size (e.g., 100 total molecules)
- Bracket: Single elimination, 16/32/64 player brackets depending on signups
- Scheduling: Battles resolve automatically at set times (every 6 hours), using existing combat resolution
- Seeding: Higher-ranked players face lower-ranked players first
- Rewards: Winner gets 100 PP + exclusive cosmetic + "Champion de la Saison X" title; Top 4 get scaled PP
- Spectator mode: All tournament battles produce public combat reports visible to everyone

**Schema impact:** New `tournaments` table, `tournament_brackets` table, `tournament_matches` table.

---

### [IDEA-R1-013] HIGH -- World Events: "Anomalie Chimique"

**Description:** Random server-wide events that change the game rules temporarily (2-3 days). These create memorable moments and force adaptive strategy. Events are announced 12 hours before activation.

**Why it fits:** The catalyst system (`catalyst.php`) provides weekly modifiers but they are predictable (deterministic rotation via `$currentWeek % count($CATALYSTS)`). Random events add surprise and urgency. The existing formula infrastructure in `includes/formulas.php` and `config.php` already supports modifier-based calculations.

**Event examples:**
- "Reaction en Chaine": All chemical reaction thresholds reduced by 50% -- easier to trigger combos
- "Penurie d'Energie": Energy production halved for 48h -- forces market trading and conservation
- "Supernova": All production doubled for 24h -- land rush for resources
- "Stabilite Quantique": Molecule decay stops completely for 48h -- armies grow unchecked
- "Marche Noir": Market prices frozen, but private trades (player-to-player) have 0% tax
- "Course aux Armements": Formation speed tripled, but all combat damage doubled
- "Gel Atomique": No new molecule classes can be created for 48h -- fight with what you have
- "Trou de Ver": All travel distances halved -- faster attacks, espionage, and trade routes

**Schema impact:** `world_events` table: `(id, event_type, starts_at, ends_at, active)`. Event effects read like catalyst effects in formulas.

---

### [IDEA-R1-014] MEDIUM -- Molecule Fusion: "Fusion Moleculaire"

**Description:** A new building ("Fusionneur") that allows merging two molecule classes into a hybrid class. The fused class inherits the dominant atoms from each parent but gains a unique "Fusion" isotope type with +10% to all stats and +30% decay rate. This consumes both parent classes permanently.

**Why it fits:** Players currently have 4 molecule classes with fixed compositions. The isotope system (`ISOTOPE_NORMAL/STABLE/REACTIF/CATALYTIQUE`) already modifies stats at the class level. Fusion adds a strategic decision: sacrifice two classes for one powerful hybrid, reducing army diversity for raw power.

**Mechanic details:**
- Requires both classes to have at least 100 molecules each
- Fused class formula = weighted average of parent atom distributions (rounded)
- Fused class count = sqrt(parent1_count * parent2_count) -- geometric mean, so not a free army doubler
- Fusion isotope: +10% attack, +10% defense, +10% HP, +30% decay rate
- Fusion is irreversible -- the two parent classes are destroyed
- Fused class occupies one slot, freeing the other for a new class
- Maximum 1 fusion per season (prevent abuse)

**Building:** Fusionneur, unlocked at Condenseur level 20, cost similar to Stabilisateur.

---

### [IDEA-R1-015] MEDIUM -- Espionage Counter-Intelligence: "Contre-Espionnage"

**Description:** A new building ("Brouilleur") that provides counter-espionage capabilities. At higher levels, it can: detect incoming spies earlier, scramble spy reports (show false data), and even trap spies (destroy attacker's neutrinos).

**Why it fits:** Espionage is currently one-directional. The defender is notified of a successful spy ("Tentative d'espionnage detectee" in `game_actions.php` line 437) but has no way to prevent or counter it. The success check is simple: attacker neutrinos > defender neutrinos / 2 (line 328-330). A counter-espionage building adds strategic depth.

**Mechanic details:**
- Level 1-5: Increases the neutrino threshold for successful espionage by 10% per level
- Level 6-10: 20% chance per level to scramble spy report (random false data)
- Level 11-15: 10% chance per level to trap spy (attacker loses their neutrinos, gets failure report)
- Level 16+: Reveals the spy's identity in the defender's report
- Building cost: Azote-based, similar to Lieur cost curve

---

### [IDEA-R1-016] MEDIUM -- Resource Events on Map: "Gisements Rares"

**Description:** Random resource deposits ("Gisements") appear on the game map at periodic intervals. Players can send molecules to harvest them (like an attack on a neutral target). First to arrive claims the deposit. Deposits contain rare atom bundles, energy caches, or special catalysts.

**Why it fits:** The game map (`attaquer.php`) currently shows only player positions with colored dots (orange=self, blue=team, green=ally, red=enemy). The map is static between players. Adding resource nodes creates points of contention, encourages exploration, and provides PvE content for players who prefer not to attack others.

**Mechanic details:**
- 3-5 deposits spawn per day at random coordinates
- Deposit types: Common (energy), Uncommon (rare atoms), Rare (catalyst tokens), Legendary (PP)
- Harvesting requires sending at least 10 molecules; travel time based on distance
- If two players arrive at the same deposit, a mini-battle determines the winner
- Deposits visible on map with chemistry-themed icons (crystal, flask, atom symbol)
- Alliance coordination: Multiple members can send molecules to the same deposit (combined force)

**Schema impact:** New `map_deposits` table: `(id, x, y, type, reward, spawned_at, claimed_by, claimed_at)`.

---

### [IDEA-R1-017] LOW -- Prestige Skill Tree Expansion

**Description:** Expand the current 5-node prestige unlock system (`$PRESTIGE_UNLOCKS` in `prestige.php`) into a branching skill tree with 3 paths: Combat, Economy, Research. Each path has 5-7 nodes with increasing PP costs and meaningful permanent bonuses.

**Why it fits:** The current prestige system is flat: 5 unlocks at fixed PP thresholds (50, 100, 250, 500, 1000). Once a player has 1000+ PP, they have everything and PP becomes meaningless. A skill tree creates meaningful long-term choices and makes PP valuable indefinitely.

**Skill tree paths:**
- **Combat Path:** Warlord
  - 50 PP: +2% attack
  - 100 PP: +5% molecule HP
  - 200 PP: -10% attack energy cost
  - 400 PP: +1 max chemical reaction slot
  - 800 PP: "Berserker" -- +15% damage when outnumbered 2:1
  - 1500 PP: Start season with Ionisateur level 3

- **Economy Path:** Industrialist
  - 50 PP: +3% resource production
  - 100 PP: -5% market buy tax
  - 200 PP: +10% storage capacity
  - 400 PP: Start season with Producteur level 3
  - 800 PP: -10% building construction time
  - 1500 PP: "Tycoon" -- market trades affect prices 50% less

- **Research Path:** Scientist
  - 50 PP: +5% formation speed
  - 100 PP: -10% molecule decay
  - 200 PP: +2 condenseur points per level
  - 400 PP: Start season with all 4 class slots unlocked
  - 800 PP: Chemical reactions trigger at 75% threshold (instead of 100%)
  - 1500 PP: "Nobel" -- exclusive 6th chemical reaction: "Catalyse" (+10% all stats when all 4 classes active)

**Schema impact:** Replace `unlocks` varchar in `prestige` table with `skill_tree` JSON column.

---

### [IDEA-R1-018] LOW -- Atom Specialization Missions: "Recherche Fondamentale"

**Description:** Once a player chooses a specialization (`$SPECIALIZATIONS` in `config.php`), unlock a chain of 5 missions specific to that path. Completing all 5 missions in a specialization path grants an additional +5% bonus to the chosen spec and a unique cosmetic.

**Why it fits:** Specializations exist (`spec_combat`, `spec_economy`, `spec_research` in config.php) but are a one-click irreversible choice with no follow-up engagement. Players choose and forget. Missions tied to specializations create investment in the choice and reward players for playing into their specialization.

---

## 4. Visual & UX Improvements

### [IDEA-R1-019] HIGH -- Combat Replay Viewer: "Rapport de Bataille Anime"

**Description:** Replace the static HTML combat report with an animated replay. Show molecules clashing class-by-class, damage numbers floating, buildings crumbling, resources flowing. Use CSS animations and lightweight JavaScript -- no canvas or WebGL required.

**Why it fits:** Combat reports (`game_actions.php`, lines 150-310) are currently plain HTML tables with numbers. Players see "Pertes: 1,234" but do not experience the battle. The report already contains all the data needed for a replay: troop counts, casualties per class, damage to buildings, resources pillaged, formation effects, and chemical reactions triggered.

**Implementation approach:**
- Phase 1: CSS bar-chart animation showing HP bars depleting per class (both sides)
- Phase 2: Step-by-step narration ("Class 1 attacks for 45,000 damage. Phalange formation absorbs 70%...")
- Phase 3: Particle effects for chemical reactions triggering, isotope glow effects
- Uses existing Framework7 card components for layout
- Data already in report HTML; extract into JSON structure for animation engine

---

### [IDEA-R1-020] HIGH -- Interactive Map Overhaul: "Carte Strategique"

**Description:** Replace the current static dot-map with an interactive, zoomable map. Show territory influence, alliance borders, trade routes (active `actionsenvoi`), attack paths (active `actionsattaques`), and resource deposit locations. Players can filter by alliance, online status, or threat level.

**Why it fits:** The map (`attaquer.php`) currently renders all players as colored dots based on diplomacy status. There is no concept of territory, no visual feedback for ongoing actions, and no strategic information layer. Players must manually calculate distances. The game stores x,y coordinates in the `membre` table.

**Features:**
- Zoomable/pannable map using Leaflet.js or similar (no Google Maps dependency)
- Alliance territory visualization (convex hull of member positions, colored by alliance)
- Real-time movement arrows for ongoing attacks, spy missions, and trade convoys
- Distance calculator on hover between any two players
- Filter: Show only alliance members, only enemies, only online players, only within range
- Resource heatmap: Density of uncollected map deposits

---

### [IDEA-R1-021] MEDIUM -- Market Price History Graph Improvements

**Description:** Enhance the existing Google Charts market graph (`marche.php`, lines 560-611) with: candlestick charts showing open/high/low/close per hour, volume indicators (total trades per resource per hour), moving averages, and price alerts. Allow zooming into specific time ranges.

**Why it fits:** The market already stores full price history in the `cours` table with timestamps. The current chart renders up to 1000 data points as a simple line chart. The data is there but the visualization is basic. Traders need better tools to make informed decisions, which increases market engagement and trade volume.

**Implementation:**
- Replace Google Charts with Chart.js (smaller, more interactive, no external dependency)
- Add 1h/6h/24h/7d time range buttons
- Show trade volume overlay (number of trades per time period)
- Price alert: "Notify me when Carbone drops below 0.5 energy" (stored in `localStorage`, checked client-side)
- Responsive: Works on mobile (Framework7 already handles this)

---

### [IDEA-R1-022] MEDIUM -- Dashboard Notifications: "Centre de Notifications"

**Description:** A unified notification center accessible from the main navigation that aggregates all game events: combat reports, espionage results, construction completions, formation completions, market trades, alliance events, tournament updates, and world events. Currently players must check multiple pages.

**Why it fits:** Game events are scattered across: `rapports` table (combat/spy reports), `actionsformation` (formations), `actionsconstruction` (buildings), `actionsenvoi` (trades). Players have no single place to see "what happened while I was away." The mobile-first Framework7 UI already supports notification badge components.

**Implementation:**
- Notification bell icon in top nav bar with unread count badge
- Dropdown panel showing last 20 notifications with timestamps
- Click notification to navigate to relevant page
- Categories: Combat (red), Economy (green), Alliance (blue), System (gray)
- Mark as read, mark all as read
- Stored in `notifications` table, auto-pruned after 7 days

---

### [IDEA-R1-023] MEDIUM -- Molecule Composition Visualizer: "Visualiseur Moleculaire"

**Description:** When creating or viewing a molecule class, display a visual representation of the atom distribution as an interactive radar/spider chart showing all 8 atom stats (Attack, Defense, HP, Speed, Destruction, Pillage, Formation Time, Energy Production). Show how the molecule compares to average compositions on the server.

**Why it fits:** The molecule creation form in `armee.php` shows raw atom counts and lets players distribute points. But understanding the resulting combat stats requires mental math with the formulas in `formulas.php` (attack, defense, HP, speed, formation time, etc.). A radar chart instantly communicates the molecule's profile.

**Implementation:**
- Radar chart using Chart.js with 8 axes (one per atom's primary stat)
- Real-time update as player adjusts atom sliders during creation
- "Compare to server average" toggle showing the median composition
- "Compare to last attacker" using data from most recent combat report
- Show derived stats below: DPS, EHP (effective HP), travel speed in cases/hour

---

### [IDEA-R1-024] LOW -- Dark Mode: "Mode Nuit"

**Description:** Add a dark mode toggle. The game uses Framework7 Material CSS which supports theming. Store preference in `localStorage` and apply via CSS class toggle.

**Why it fits:** The game is mobile-first and many players check it at night. Framework7 has built-in dark mode support. This is a quality-of-life improvement that costs almost nothing to implement.

---

## 5. Technical Modernization

### [IDEA-R1-025] HIGH -- Progressive Web App (PWA) with Push Notifications

**Description:** Convert the web application into a PWA with a service worker for offline caching, an app manifest for home screen installation, and push notifications for critical events (attack incoming, construction complete, tournament starting).

**Why it fits:** The game is already mobile-first (Framework7 Material CSS). Players check the game on their phones but have no way to receive alerts. The existing architecture (PHP rendering HTML pages, jQuery for interactivity) is fully compatible with a PWA wrapper. Push notifications would dramatically improve engagement.

**Implementation:**
- `manifest.json`: App name, icons, theme color, display: standalone
- Service worker: Cache static assets (CSS, JS, images), serve from cache when offline
- Push notifications via Web Push API + VAPID keys
- Notification triggers: "Vous etes attaque!", "Construction terminee", "Tournoi commence dans 1h"
- Background sync: Queue market trades and construction orders when offline, execute when online
- PHP backend: `web-push` library for server-side notification dispatch

**Files affected:** New `manifest.json`, `sw.js`, `includes/push_notifications.php`, `api.php` push endpoints.

---

### [IDEA-R1-026] HIGH -- REST API Layer: "API Publique"

**Description:** Extract the existing `api.php` into a proper REST API with versioned endpoints, JSON responses, token authentication, and Swagger/OpenAPI documentation. This enables: mobile app development, third-party tools, Discord bots, and stat tracking websites.

**Why it fits:** The game already has `api.php` with a dispatch table pattern. Expanding this into a full API unlocks the ecosystem. Alliance leaders could build Discord bots that post war updates. Stat-tracking sites could show historical rankings. Mobile app wrappers could consume the API directly.

**Endpoints:**
- `GET /api/v1/player/{login}` -- Public profile data
- `GET /api/v1/rankings?sort=totalPoints&page=1` -- Leaderboard
- `GET /api/v1/market/prices` -- Current atom prices
- `GET /api/v1/market/history?resource=carbone&period=24h` -- Price history
- `GET /api/v1/alliance/{tag}` -- Alliance info and members
- `GET /api/v1/catalyst/current` -- Current active catalyst
- `POST /api/v1/market/buy` -- Execute market trade (authenticated)
- `GET /api/v1/player/notifications` -- Player notifications (authenticated)

---

### [IDEA-R1-027] MEDIUM -- Server-Sent Events for Real-Time Updates

**Description:** Implement Server-Sent Events (SSE) for push-based updates to the client: resource production ticking in real time, construction/formation progress bars, incoming attack warnings, chat messages, and market price changes. SSE is simpler than WebSocket and works over standard HTTP.

**Why it fits:** The game currently uses JavaScript `setInterval` timers to count down construction/formation times (e.g., `marche.php` lines 390-405). Resource counts are static until page reload. SSE would enable live-updating resource counters, real-time attack notifications, and instant chat without polling.

**Implementation:**
- `sse.php` endpoint: Authenticated, sends events based on player state
- Events: `resource_update`, `construction_complete`, `formation_progress`, `attack_incoming`, `chat_message`
- Client: `EventSource` API with fallback to AJAX polling for older browsers
- Server: PHP with `flush()` and `ob_flush()` for streaming, or Redis pub/sub for multi-process

---

### [IDEA-R1-028] LOW -- Automated Game Balancing Telemetry

**Description:** Instrument key game events to collect anonymized telemetry: which atom distributions win most battles, which buildings are upgraded first, market trade patterns, average session length, drop-off points in the tutorial. Store in a `telemetry` table and build an admin dashboard.

**Why it fits:** The game has been balanced through code inspection and manual testing. `config.php` contains dozens of tunable constants (`ATTACK_ATOM_COEFFICIENT`, `DECAY_BASE`, `MARKET_VOLATILITY_FACTOR`, etc.) but there is no data-driven feedback loop. Knowing that "90% of winning molecules have >100 Oxygene" or "only 5% of players complete tutorial mission 6" informs balance changes and feature development.

---

## 6. Monetization (Cosmetic Only)

### [IDEA-R1-029] MEDIUM -- Cosmetic Shop: "Laboratoire de Style"

**Description:** A premium cosmetic shop where players can purchase purely visual customizations with a premium currency ("Neutrons d'Or"). Cosmetics provide NO gameplay advantage. Categories: molecule trail effects, profile frames, name colors, chat badges, alliance banners, map icons, and combat report themes.

**Cosmetic categories:**
- **Molecule Skins:** Visual effects on the molecule composition display (flame aura, crystal glow, toxic mist)
- **Profile Frames:** Decorative borders around the player profile card (periodic table border, radiation hazard, aurora borealis)
- **Name Colors:** Colored username in chat, forum, and leaderboard (separate from Legende prestige color)
- **Alliance Banners:** Custom alliance icon displayed on map and in alliance page
- **Combat Themes:** Alternative visual style for combat reports (dark mode, neon, retro terminal)
- **Map Trails:** Visual trail behind molecules on the map during attacks/espionage

**Pricing model:**
- Neutrons d'Or purchased with real money (1 EUR = 100 Neutrons d'Or)
- Individual cosmetics: 50-500 Neutrons d'Or
- Season Pass Premium Track: 1000 Neutrons d'Or per season (unlocks exclusive cosmetics alongside free objectives)
- No loot boxes, no gacha -- direct purchase only

**Implementation:** New `cosmetics` table, `player_cosmetics` table, payment via Stripe or PayPal.

---

### [IDEA-R1-030] LOW -- Cosmetic Gifting and Trading

**Description:** Allow players to gift purchased cosmetics to other players or trade cosmetics between accounts. Creates a secondary social economy around cosmetics.

---

## 7. Implementation Priority Matrix

| ID | Priority | Effort | Impact | Category |
|----|----------|--------|--------|----------|
| IDEA-R1-001 | HIGH | Low | High | Engagement |
| IDEA-R1-002 | HIGH | Medium | High | Engagement |
| IDEA-R1-003 | HIGH | Medium | High | Engagement |
| IDEA-R1-007 | HIGH | Medium | High | Social |
| IDEA-R1-008 | HIGH | Medium | High | Social |
| IDEA-R1-012 | HIGH | High | High | Mechanics |
| IDEA-R1-013 | HIGH | Medium | High | Mechanics |
| IDEA-R1-019 | HIGH | Medium | High | Visual |
| IDEA-R1-020 | HIGH | High | High | Visual |
| IDEA-R1-025 | HIGH | Medium | High | Technical |
| IDEA-R1-026 | HIGH | Medium | Medium | Technical |
| IDEA-R1-004 | MEDIUM | Low | Medium | Retention |
| IDEA-R1-005 | MEDIUM | Low | Medium | Engagement |
| IDEA-R1-006 | MEDIUM | Low | Medium | Engagement |
| IDEA-R1-009 | MEDIUM | Medium | Medium | Social |
| IDEA-R1-010 | MEDIUM | Medium | Medium | Social |
| IDEA-R1-014 | MEDIUM | High | Medium | Mechanics |
| IDEA-R1-015 | MEDIUM | Medium | Medium | Mechanics |
| IDEA-R1-016 | MEDIUM | Medium | High | Mechanics |
| IDEA-R1-021 | MEDIUM | Low | Medium | Visual |
| IDEA-R1-022 | MEDIUM | Medium | High | Visual |
| IDEA-R1-023 | MEDIUM | Low | Medium | Visual |
| IDEA-R1-027 | MEDIUM | Medium | Medium | Technical |
| IDEA-R1-029 | MEDIUM | High | Medium | Monetization |
| IDEA-R1-011 | LOW | Medium | Low | Social |
| IDEA-R1-017 | LOW | Medium | Medium | Mechanics |
| IDEA-R1-018 | LOW | Low | Low | Mechanics |
| IDEA-R1-024 | LOW | Low | Low | Visual |
| IDEA-R1-028 | LOW | Medium | Medium | Technical |
| IDEA-R1-030 | LOW | Low | Low | Monetization |

---

## Recommended Implementation Order

### Phase A: Quick Wins (1-2 weeks each, high engagement impact)

1. **IDEA-R1-001** Daily Login Rewards -- simple DB + UI, immediate engagement boost
2. **IDEA-R1-005** Advanced Tutorial -- extends existing tutorial.php pattern
3. **IDEA-R1-004** Comeback Mechanic -- builds on existing derniereConnexion tracking
4. **IDEA-R1-024** Dark Mode -- Framework7 supports natively, minimal effort
5. **IDEA-R1-022** Notification Center -- aggregates existing data sources

### Phase B: Core Feature Additions (2-4 weeks each)

6. **IDEA-R1-025** PWA + Push Notifications -- multiplier for all future features
7. **IDEA-R1-007** Chat System (AJAX first) -- enables social engagement
8. **IDEA-R1-013** World Events -- reuses catalyst system patterns
9. **IDEA-R1-006** Weekly Challenges -- syncs with existing catalyst rotation
10. **IDEA-R1-002** Achievement System -- hooks into existing stat tracking

### Phase C: Strategic Depth (4-8 weeks each)

11. **IDEA-R1-008** Alliance Wars Dashboard -- enriches existing war system
12. **IDEA-R1-012** Tournament System -- structured PvP using existing combat engine
13. **IDEA-R1-020** Interactive Map -- replaces static map with strategic tool
14. **IDEA-R1-010** Diplomacy Overhaul -- extends existing pact/war mechanics
15. **IDEA-R1-016** Map Resource Events -- adds PvE dimension to the map

### Phase D: Long-Term Investment

16. **IDEA-R1-019** Combat Replay Viewer -- rich combat experience
17. **IDEA-R1-026** REST API -- enables ecosystem development
18. **IDEA-R1-017** Prestige Skill Tree -- long-term progression depth
19. **IDEA-R1-029** Cosmetic Shop -- monetization infrastructure
20. **IDEA-R1-003** Season Pass -- combines with cosmetic shop for revenue

---

## Architecture Notes

All proposed features are designed to work within the existing PHP 8.2 / MariaDB / Framework7 stack. No feature requires a frontend framework migration. The recommended approach is:

1. **Backend:** New PHP include files following the modular pattern established in `includes/fonctions.php` (7 focused modules). New features get their own include files (e.g., `includes/achievements.php`, `includes/tournaments.php`).

2. **Database:** All schema changes are additive (new tables, new columns with defaults). No existing table modifications except where noted. Use the migration pattern from the existing `migrations/` directory.

3. **Frontend:** Continue using Framework7 cards, lists, and modals. Add Chart.js for data visualization (replace Google Charts dependency). Use jQuery for AJAX. No React/Vue/Angular needed.

4. **API:** Extend existing `api.php` dispatch table pattern for new endpoints.

5. **Caching:** Extend the static cache pattern used in `getActiveCatalyst()` and `catalystEffect()` to new systems (achievements, daily rewards, tournament state).

6. **Testing:** Each feature should include PHPUnit tests following the existing 370-test suite pattern. Particularly important for balance-affecting features (tournaments, world events, new mechanics).

---

*End of Round 1 Feature Proposals -- 30 ideas across 6 categories*
