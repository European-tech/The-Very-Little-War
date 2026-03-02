# TVLW Comprehensive Gameplay Improvement Report

**Date:** 2026-03-02
**Author:** Game Design Analysis
**Version:** 1.0
**Scope:** Complete gameplay review covering what to preserve, missing loops, new features, playstyle archetypes, retention mechanics, and prioritized recommendations.

---

## TABLE OF CONTENTS

1. [What's Great (Preserve These)](#1-whats-great-preserve-these)
2. [Missing Gameplay Loops](#2-missing-gameplay-loops)
3. [New Features That Would Add Strategic Depth](#3-new-features-that-would-add-strategic-depth)
4. [Playstyle Archetypes](#4-playstyle-archetypes)
5. [Retention and Engagement](#5-retention-and-engagement)
6. [Priority Ranking](#6-priority-ranking)

---

## 1. WHAT'S GREAT (Preserve These)

### 1.1 The Chemistry Theme Is Genuinely Unique

TVLW occupies a niche that no other browser strategy game touches. OGame uses spaceships. Travian uses swords and catapults. Tribal Wars uses medieval armies. TVLW uses the periodic table. This is not cosmetic -- it is structural. The 8 atom types (C, N, H, O, Cl, S, Br, I) each map to a distinct gameplay role (defense, formation speed, building destruction, attack, speed, pillage, HP, energy production). A player who understands chemistry gets a thematic shorthand: oxygen is reactive (attack), carbon forms strong bonds (defense), chlorine is fast (speed), bromine is heavy (HP). This thematic coherence is rare in browser games and should be preserved and deepened, never abandoned.

### 1.2 The Molecule Composition System Has Real Depth Potential

The core mechanic -- designing a molecule by choosing how many of each atom to include (up to 200 per type) -- is an outstanding strategic foundation. With 8 dimensions and 200 atoms per dimension, the design space is enormous. The quadratic scaling formula `(1 + (0.1*x)^2 + x)` means every atom matters and specialization is rewarded. Combined with 4 molecule class slots, players must make genuine trade-off decisions about what their army can and cannot do. This is the heart of the game and should be amplified, not simplified.

### 1.3 The Seasonal Reset Model Creates Natural Cadence

Monthly resets with persistent victory points across seasons solve one of the hardest problems in browser strategy games: late-joiner disadvantage. A player who joins mid-month knows the round will end soon, so the barrier to entry is bounded. Victory points accumulating across seasons provide long-term meaning. The 24-hour warning before reset is good. The archiving of top 20 players and alliances provides historical pride. This structure is sound.

### 1.4 The Decay System Is Clever in Principle

Molecule decay -- the idea that larger, more powerful molecules disappear faster -- is a brilliant thematic mechanic (radioactive decay, chemical instability). It creates a natural arms race: you cannot simply stockpile a massive army and sit on it. You must continually produce. This prevents indefinite turtling and keeps the economy relevant throughout the round. The Stabilisateur building and the Pertes medal as counterbalances give players agency over the decay rate. Preserve the concept, but the current formula may need retuning (see Section 3).

### 1.5 The Market System Has Sound Fundamentals

The atom-for-energy market with player-driven prices, buy/sell asymmetry (linear up, harmonic down), and volatility tied to active player count is a legitimately interesting economic system. The 1% mean reversion toward baseline of 1.0 and the [0.1, 10.0] price clamping prevent runaway prices. Trade points as a separate scoring dimension reward economic players. The concept of atoms as individually traded commodities rather than a single "resource" is distinctive and should stay.

### 1.6 The Alliance Duplicateur Is a Great Shared Goal

Having a single shared alliance building that all members fund through energy donations, with benefits that scale for everyone, is exactly the kind of cooperative incentive alliances need. The exponential cost curve (base 2.5) creates natural discussion within alliances about when to invest. This mechanic successfully answers "why should I be in an alliance?" and should be expanded, not replaced.

### 1.7 The Condenseur Point Allocation Is a Meaningful Choice

The Condenseur building granting distributable points across atom types (5 per level, affecting the `(1 + niveau/50)` multiplier) creates a strategic meta-layer above molecule composition. A player who has committed all their condenseur points to Oxygen and Bromine is locked into an attack-focused build. Changing strategy means re-distributing points, which cannot be done retroactively for existing molecules. This persistent allocation decision adds commitment and identity to each player's build. Keep this.

---

## 2. MISSING GAMEPLAY LOOPS

### 2.1 Progression Loop: Present but Flat

**Short-term progression (minutes to hours):** Exists but is passive. Players queue a building upgrade, queue molecule formation, then wait. There is no active short-term loop -- no mini-game, no active combat decision, no exploration. The only "active" moment is launching an attack, which is a single form submission. **Grade: C-**

**Medium-term progression (hours to days):** Building upgrades and army accumulation provide a steady power curve, but because all formulas are linear (75 energy per generator level, 500 storage per depot level, 60 atoms per producteur point), there are no inflection points. No moment where a player thinks "once I hit level X, everything changes." The Constructeur medal thresholds (5, 10, 15, 25...) provide milestones, but the gameplay itself does not change at these thresholds. **Grade: C**

**Long-term progression (weeks to months):** Victory points provide cross-season persistence, but only for the top ~50 players. The bottom half of the player base earns zero VP per season. Medals persist within a season but reset between seasons (since buildings/armies/stats all reset). There is no permanent tech tree, no player-level progression, no cosmetic unlocks, no historical record of individual achievements. A player who has played for 2 years and a new player are mechanically identical at round start except for VP count. **Grade: D**

**What's missing:** Acceleration curves, unlock moments, "aha" thresholds, prestige mechanics, cross-season persistence beyond VP.

### 2.2 Social Loop: Shallow

**Current social features:**
- Alliances (up to 20 members) with shared energy pool and duplicateur
- Alliance wars (declaration + loss tracking, no mechanical reward)
- Alliance pacts (cosmetic label, no mechanical effect)
- Player-to-player resource transfers (with anti-alt penalties)
- Forum (with Pipelette medal for posting)
- Private messaging

**What's missing:**
- No reason to talk to your alliance beyond "please donate energy." The duplicateur is the only shared goal, and it requires no coordination.
- Wars have zero mechanical consequence. There is no "we must work together or lose" scenario.
- No alliance territory. No shared map control. No reason your alliance's composition of players matters.
- No alliance-vs-alliance combat that feels like a *team* activity rather than individual attacks that happen to involve alliance members.
- No diplomacy depth beyond "pact" (which does nothing) and "war" (which tracks losses but grants nothing).
- No trading within an alliance that is different from trading with strangers.
- No shared intelligence (spy reports are private to the spy sender).

**Grade: D+**

### 2.3 Risk/Reward Loop: Attacker-Favored, No Meaningful Defense Decisions

**Current state:** The attacker can spy first (neutrinos), pick their target based on intelligence, and choose when to strike. If they win, they gain pillage + building destruction + combat points. If they lose, they lose molecules (which were decaying anyway) and some energy. The defender gains nothing from successful defense except "not losing." There is no counter-attack trigger, no automatic retaliation, no "trap" mechanic.

**What's missing:**
- No defensive preparation choices (you cannot set your army's defensive formation or prioritize which buildings to protect)
- No risk in *not* attacking -- idle players neither gain nor lose relative to active ones (beyond time-based production, which is automatic)
- No counter-attack or retaliation mechanic
- No territory to lose or defend beyond the base itself
- No meaningful choice about *what* to steal when pillaging (it is proportional to defender's resources)
- No insurance mechanism (bank, vault, hide resources)

**Grade: D**

### 2.4 Discovery Loop: Almost Nonexistent

**Current state:** The game has a static map with random player placement, no fog of war for positions, no hidden resources, no discoverable content, no events, no secrets. Every player has the same 8 buildings, the same 8 atom types, the same 4 molecule slots. The chemistry education page (`sinstruire.php`) is a nice touch but is pure flavor with no gameplay impact.

**What's missing:**
- No map features (resource nodes, strategic positions, geographic advantages)
- No hidden information that rewards exploration (every player's position is visible)
- No technology or recipe discovery
- No world events or environmental changes
- No achievements beyond the existing medal tracks
- No rare resources or seasonal modifiers

**Grade: F**

---

## 3. NEW FEATURES THAT WOULD ADD STRATEGIC DEPTH

### 3a. New Molecule Mechanics

#### 3a-1. Chemical Reactions Between Molecule Classes

**What it adds:** When two specific molecule classes are deployed together in the same attack, their combined atoms trigger a "reaction bonus." For example, a class with high Hydrogen and a class with high Oxygen together produce a "Water Reaction" that grants +15% HP to both classes (thematic: water is stable). A class with high Chlorine and a class with high Sodium (new atom? or Sulfur as substitute) might grant +20% speed. This creates incentives to design complementary molecule pairs rather than four independent classes.

**Chemistry fit:** Chemical reactions are the core of chemistry. Two reagents combining to produce something greater than the sum of their parts is deeply thematic.

**Implementation complexity:** Medium. Requires defining a reaction table (which atom combinations trigger which bonuses), modifying combat resolution to check for reaction conditions before applying stats, and adding UI to show active reactions.

**Impact on existing balance:** Low risk. Reactions are purely additive bonuses that reward smart composition. Players who ignore reactions are not penalized; they simply miss out on the bonus.

**Suggested reactions:**

| Reaction Name | Trigger Condition | Bonus |
|---|---|---|
| Combustion | Class A has O>=100, Class B has C>=100 | +15% attack for both classes |
| Hydrogenation | Class A has H>=100, Class B has Br>=100 | +15% HP for both classes |
| Halogenation | Class A has Cl>=80, Class B has I>=80 | +20% speed for the attack fleet |
| Sulfurization | Class A has S>=100, Class B has N>=50 | +20% pillage capacity |
| Neutralization | Class A has O>=80, Class B has H>=80 and C>=80 | +15% defense for both classes |

#### 3a-2. Isotope Variants (Molecule Specialization)

**What it adds:** When creating a molecule class, players can designate it as one of 3 "isotopes": Stable, Reactive, or Catalytic. Each isotope modifies all stats by a fixed percentage.

- **Stable:** -10% attack, +20% HP, -30% decay rate. Meant for tanks and defenders.
- **Reactive:** +20% attack, -10% HP, +50% decay rate. Meant for glass-cannon raiders.
- **Catalytic:** -10% attack, -10% HP, but grants +15% to the stats of all OTHER classes in the same army. Meant for support roles.

**Chemistry fit:** Isotopes are variants of the same element with different properties. The names (Stable, Reactive, Catalytic) are directly from chemistry vocabulary.

**Implementation complexity:** Simple. One additional column in the molecules table, three multipliers applied during combat resolution.

**Impact on existing balance:** Medium. This directly changes the current "one optimal build" problem by creating three distinct roles. The Catalytic isotope is particularly interesting because it creates a support class that is weak alone but powerful in a coordinated army.

#### 3a-3. Molecular Weight and Inertia

**What it adds:** The total atoms in a molecule (its "molecular weight") affects not just decay but also a new "inertia" stat. Heavy molecules (high total atoms) deal more damage on impact but move slower and cost more energy to deploy. Light molecules are fast and cheap but fragile. This creates a genuine speed-vs-power tradeoff beyond just the Chlorine atom count.

**Chemistry fit:** Molecular weight is fundamental. Heavy molecules are slower to accelerate (inertia) but carry more energy in collisions.

**Implementation complexity:** Simple. Apply a weight-based modifier to existing speed and attack formulas: `attack *= (1 + totalAtoms / 1000)` and `speed /= (1 + totalAtoms / 500)`.

**Impact on existing balance:** Medium. Currently there is no penalty for maxing out all atom counts except faster decay. This adds a second penalty (slower speed, higher cost) that forces players to make leaner, more focused molecules.

---

### 3b. Map and Territory Mechanics

#### 3b-1. Resource Nodes on the Map

**What it adds:** Scatter 15-25 "resource nodes" across the map, each producing a specific atom type at a bonus rate (+50% production of that atom) for the player whose base is closest. Nodes are visible to all players. Players cannot move their base, but they can attack players near a node to reduce competition. Alliance members near the same node share the bonus at reduced rate (+25% each if two allies are closest and second-closest).

**Chemistry fit:** Resource nodes can be themed as "mineral deposits," "atmospheric vents," or "elemental sources." A Carbon node is a "coal seam." An Oxygen node is an "atmospheric vent." An Iodine node is a "seaweed bed."

**Implementation complexity:** Medium. Requires adding a `resource_nodes` table, modifying the map placement algorithm to consider node proximity, and adjusting `revenuAtome` to check for node bonuses.

**Impact on existing balance:** Medium. This gives the map strategic meaning (currently the map is just for distance calculations). Players near desirable nodes become targets, creating natural conflict zones.

#### 3b-2. Controlled Zones (Sphere of Influence)

**What it adds:** Each player exerts a "sphere of influence" on the map proportional to their total points. Overlapping spheres contest territory. An alliance's territory is the union of all member spheres. Territory could provide:
- A small production bonus to all members within the territory
- A visual representation on the map showing alliance control
- A scoring category ("Territory Points") that contributes to rankings

**Chemistry fit:** Molecular orbitals -- each player's influence is like an electron cloud. Where clouds overlap, bonds form (alliances) or repulsion occurs (enemies).

**Implementation complexity:** Complex. Requires a territory calculation engine (Voronoi diagrams or grid-based influence maps), visual map rendering, and a new scoring dimension.

**Impact on existing balance:** High. This would fundamentally change how alliances think about member placement and create a persistent territorial game layer.

---

### 3c. Defensive Mechanics

#### 3c-1. Defensive Formations

**What it adds:** Players can set a "defensive formation" for their army, choosing from 3 options:

- **Dispersed:** Damage is split evenly across all classes (counters the current "kill class 1 first" system). Best against concentrated attacks.
- **Phalanx:** Class 1 absorbs 80% of damage but gets +30% defense. Best against many small attacks.
- **Ambush:** If the defender has more total molecules than the attacker, the defender gets a surprise +25% attack bonus on the first round. Best against overconfident raiders.

**Chemistry fit:** These can be themed as molecular arrangements: Dispersed = gas phase (spread out), Phalanx = crystal lattice (rigid structure), Ambush = supersaturated solution (stable until disturbed, then crystallizes explosively).

**Implementation complexity:** Medium. One new column in `constructions` or `autre` for formation choice, modifications to `combat.php` to apply formation effects before damage calculation.

**Impact on existing balance:** High positive impact. This directly addresses the "combat is solved" problem identified in the balance analysis. Attackers must now scout (via espionage) to learn the defender's formation and adjust their attack composition accordingly. This creates the information game that combat currently lacks.

#### 3c-2. Reactive Countermeasures (Traps)

**What it adds:** Players can spend energy to set one "trap" on their base per day, chosen from:

- **Exothermic Trap:** If attacked, attacker's molecules lose 10% HP before combat (heat damage). Costs 500 energy. Single use.
- **Corrosive Trap:** If attacked, attacker's Oxygen atoms are reduced by 20% effectiveness for that battle (acid corroding weapons). Costs 800 energy. Single use.
- **Endothermic Trap:** If attacked, a portion of pillaged resources (30%) are "frozen" and cannot be taken. Costs 300 energy. Single use.

**Chemistry fit:** Exothermic/endothermic reactions, corrosion, and phase transitions are core chemistry concepts.

**Implementation complexity:** Simple. One column for trap type, one column for trap active/inactive, applied as a pre-combat modifier in `combat.php`.

**Impact on existing balance:** Low-medium. Traps are one-time-use and daily-limited, so they cannot be stacked. They add a risk element to attacking: "does this target have a trap set?" This makes espionage more valuable (spy to see if a trap is active).

#### 3c-3. Automatic Retaliation

**What it adds:** Players can designate one molecule class as a "retaliation force." If they are attacked and the attacker wins, the retaliation force automatically counter-attacks the attacker after a fixed delay (2 hours). The retaliation force is removed from the base (cannot be used for defense) but provides a guaranteed response.

**Chemistry fit:** Le Chatelier's Principle -- "a system disturbed from equilibrium will shift to counteract the disturbance."

**Implementation complexity:** Medium. Requires a new action type in `actionsattaques`, triggered automatically when combat resolves in the attacker's favor.

**Impact on existing balance:** Medium. This gives defenders agency even when offline. Attackers must now consider whether they can withstand a counter-attack when their army is depleted. Creates a genuine risk/reward tension for attackers.

---

### 3d. Economic Depth

#### 3d-1. Resource Scarcity Zones (Seasonal Element Rotation)

**What it adds:** Each round, 2-3 atom types are designated as "scarce" -- their base production rate is halved, but their market value and combat effectiveness are increased by 50%. The scarce elements rotate each season, forcing players to adapt their strategies.

**Chemistry fit:** Chemical scarcity is real -- rare earth elements, noble gases. Some elements are abundant (Carbon, Oxygen), others are scarce (Iodine, Bromine). This mirrors reality.

**Implementation complexity:** Simple. At round start, randomly select 2-3 indices from 0-7, apply a 0.5x multiplier to their production in `revenuAtome` and a 1.5x multiplier to their combat stats.

**Impact on existing balance:** Medium. This prevents a single "solved" molecule design from dominating every round. If Oxygen is scarce one round, attack-heavy strategies are more expensive but also more powerful, creating interesting risk/reward dynamics. Alliances might specialize in scarce-element production and trade with others.

#### 3d-2. Crafting / Compound Synthesis

**What it adds:** A new building -- the "Laboratoire" (Laboratory) -- that lets players combine specific atoms into named compounds with special effects:

| Compound | Recipe | Effect | Duration |
|---|---|---|---|
| H2O (Water) | 200 H + 100 O | +20% energy production for 4 hours | Consumable |
| NaCl (Salt) | 100 Cl + 100 Na/S | +15% resource storage for 4 hours | Consumable |
| CO2 (Carbon Dioxide) | 100 C + 200 O | -10% enemy building HP in next attack | One attack |
| NH3 (Ammonia) | 100 N + 150 H | -20% molecule formation time for 4 hours | Consumable |
| H2SO4 (Sulfuric Acid) | 200 H + 100 S + 400 O | +30% pillage capacity in next attack | One attack |

**Chemistry fit:** This IS chemistry. Combining elements into compounds with distinct properties is the fundamental act of the science.

**Implementation complexity:** Complex. Requires a new building, a compound table, a crafting UI, consumable item tracking, and modifications to combat/production to apply compound effects.

**Impact on existing balance:** High. Compounds create new resource sinks (consuming atoms for temporary effects), new strategic decisions (save atoms for molecules or spend them on compounds?), and new specialization paths. A "chemist" player who focuses on compound production could be a valuable alliance asset.

#### 3d-3. Vault Building (Resource Protection)

**What it adds:** A new building -- the "Coffre-fort" (Vault) -- that protects a portion of stored resources from pillage. At level N, the vault protects `100 * N` of each resource type from being stolen.

**Chemistry fit:** Inert gas encapsulation -- noble gases are unreactive and protect contents in sealed environments.

**Implementation complexity:** Simple. One new building in `$BUILDING_CONFIG`, one check in `combat.php` during pillage calculation to subtract vault-protected amounts.

**Impact on existing balance:** Medium. This directly addresses the "defender gains nothing" problem. Even if attacked and losing, the defender retains a baseline of resources. This makes the game less punishing for casual players and reduces the "log in to find everything stolen" frustration.

---

### 3e. Alliance Mechanics

#### 3e-1. Alliance Research Tree

**What it adds:** Beyond the Duplicateur, alliances can research 4-6 additional shared technologies:

| Technology | Effect | Cost Scale |
|---|---|---|
| Duplicateur (existing) | +1% production and combat per level | Exponential (base 2.5) |
| Catalyseur (Catalyst) | -2% molecule formation time per level for all members | Exponential (base 2.0) |
| Fortification | +1% building HP per level for all members | Exponential (base 2.0) |
| Reseau (Network) | +5% trade point earning rate per level | Exponential (base 1.8) |
| Radar | Espionage success ratio improves by 2% per level (need fewer neutrinos) | Exponential (base 2.5) |
| Bouclier (Shield) | -1% pillage losses per level for all members when defending | Exponential (base 2.0) |

**Chemistry fit:** Research is central to chemistry. Each technology can be named after a chemistry concept.

**Implementation complexity:** Medium. The Duplicateur infrastructure already exists. Adding more alliance-level upgrades follows the same pattern: shared energy cost, per-level bonus, stored in the `alliances` table.

**Impact on existing balance:** Medium-high. This gives alliances much more to do cooperatively and creates differentiation between alliances (one alliance might prioritize Catalyseur for army production speed, another might prioritize Bouclier for defense).

#### 3e-2. Alliance Wars With Objectives

**What it adds:** When two alliances are at war, the system generates 3 "war objectives" -- specific buildings or resource targets:

- "Destroy Alliance X's total Generateur HP below 50%" (rewards: +5 alliance VP)
- "Pillage 50,000 total resources from Alliance X members" (rewards: +3 alliance VP)
- "Maintain more surviving molecules than Alliance X for 72 hours" (rewards: +2 alliance VP)

**Chemistry fit:** War objectives can be themed as "reaction goals" -- achieving a specific chemical transformation of the enemy's assets.

**Implementation complexity:** Complex. Requires objective generation logic, progress tracking, milestone detection, and reward distribution.

**Impact on existing balance:** High positive. This transforms wars from cosmetic labels into meaningful competitive events with tangible rewards. Alliance VP from wars could contribute to a new "War Champion" ranking.

#### 3e-3. Shared Intelligence

**What it adds:** Successful espionage reports can be shared with alliance members via a new "Intelligence Board" on the alliance page. Once shared, the report is visible to all members for 24 hours. This encourages coordinated attacks based on shared intelligence.

**Chemistry fit:** Peer review -- sharing experimental results with your research team.

**Implementation complexity:** Simple. A new `alliance_reports` table linking reports to alliances, a sharing button on the report page, and a display on the alliance page.

**Impact on existing balance:** Low. This is purely quality-of-life for alliances but significantly improves coordination without changing any combat math.

---

### 3f. Player Progression

#### 3f-1. Prestige System (Cross-Season Progression)

**What it adds:** At the end of each season, players earn "Prestige Points" (separate from VP) based on their activity, not just their rank. Prestige Points unlock permanent (cross-season) bonuses:

| Prestige Unlock | Cost | Effect |
|---|---|---|
| Quick Start | 50 PP | Start each round with Generateur level 2 instead of 1 |
| Experienced | 100 PP | +5% resource production permanently |
| Veteran | 200 PP | Start with 1 molecule class pre-unlocked |
| Master Chemist | 500 PP | +1 maximum molecule class slot (5 total) |
| Legend | 1000 PP | Unique cosmetic badge + name color in rankings |

Prestige Points are earned by ALL active players:
- Participated in the season (logged in during final week): 5 PP
- Reached any medal tier: 1 PP per medal tier reached
- Launched at least 10 attacks: 5 PP
- Defended at least 5 attacks: 5 PP
- Traded on market at least 20 times: 3 PP
- Donated to alliance: 2 PP
- Top 50 finish: bonus 10-50 PP based on rank

**Chemistry fit:** Prestige is like research experience -- the more experiments you run, the better you become in your next project.

**Implementation complexity:** Medium. Requires a `prestige` table, end-of-season calculation logic, and application of bonuses during `inscrire()` / season start.

**Impact on existing balance:** Medium. This gives EVERY active player cross-season progression, solving the "bottom 50% earn nothing" problem. The bonuses are modest enough to not create an insurmountable gap, but meaningful enough to reward loyalty.

#### 3f-2. Specialization Tracks

**What it adds:** After reaching certain building levels, players can choose a "specialization" that locks in a permanent bonus for the round but prevents accessing the opposing specialization:

- **Aggressive Chemistry:** After Ionisateur level 15, choose between:
  - **Oxidizer:** +10% attack, -5% defense permanently this round
  - **Reducer:** +10% defense, -5% attack permanently this round

- **Economic Chemistry:** After Producteur level 20, choose between:
  - **Industrial:** +20% atom production, -10% energy production
  - **Energetic:** +20% energy production, -10% atom production

- **Research Chemistry:** After Condenseur level 15, choose between:
  - **Theoretical:** +2 condenseur points per level (7 instead of 5), but -20% molecule formation speed
  - **Applied:** +20% molecule formation speed, but -1 condenseur point per level (4 instead of 5)

**Chemistry fit:** Chemists specialize. An organic chemist and a physical chemist have different strengths. This mirrors that professional divergence.

**Implementation complexity:** Medium. One column per specialization track, applied as multipliers in existing formulas.

**Impact on existing balance:** High positive. This creates the build diversity that is currently missing. Two players with the same building levels will play differently based on their specialization choices, and those choices are irreversible within a round.

---

### 3g. Events and Seasons

#### 3g-1. Weekly Catalysts (Rotating Bonuses)

**What it adds:** Each Monday, a random "Catalyst of the Week" is announced, providing a global bonus to one specific activity:

- **Catalyst: Combustion** -- All attacks deal +10% damage this week
- **Catalyst: Synthesis** -- Molecule formation is 20% faster this week
- **Catalyst: Equilibrium** -- Market prices drift toward 1.0 50% faster this week
- **Catalyst: Fusion** -- Alliance duplicateur costs are 25% cheaper this week
- **Catalyst: Crystallization** -- Building construction time is 15% shorter this week
- **Catalyst: Volatility** -- Molecule decay is 30% faster but pillage capacity is +25% this week

**Chemistry fit:** Catalysts change the rate of reactions. Each weekly catalyst shifts the meta toward a different activity.

**Implementation complexity:** Simple. A `catalyst` column in `statistiques`, checked once per week, with a simple multiplier applied to the relevant formula.

**Impact on existing balance:** Low-medium. Catalysts shift the meta without breaking it. Players adapt their weekly plans around the catalyst, creating variety even within a single season.

#### 3g-2. End-of-Season "Final Reaction" Event

**What it adds:** In the final 72 hours of each season, a special event activates:

- A neutral "Boss Molecule" appears at the center of the map with enormous HP and stats
- All players and alliances can send attacks to damage it
- Damage dealt to the Boss is tracked per player and per alliance
- Top individual contributors earn bonus VP (5/3/1 for top 3)
- Top alliance contributor earns bonus alliance VP (+3)
- The Boss drops "Catalyst Shards" proportional to damage dealt, which convert to Prestige Points

**Chemistry fit:** The Boss Molecule is a "noble gas" -- extremely stable and hard to break apart. Defeating it is a "decomposition reaction" requiring overwhelming energy input.

**Implementation complexity:** Complex. Requires NPC entity logic, damage tracking across multiple attackers, reward distribution, and special UI.

**Impact on existing balance:** Medium positive. This gives endgame meaning (the last 72 hours of each season are currently dead time) and creates an all-server cooperative event that crosses alliance boundaries.

#### 3g-3. Seasonal Modifiers (Round Themes)

**What it adds:** Each round starts with a randomly selected "theme" that modifies one fundamental rule:

- **Accelerated Round:** All production rates +50%, round length 3 weeks instead of 4
- **Scarcity Round:** Storage capacity -30%, market prices start at 2.0 instead of 1.0
- **Warfare Round:** Attack energy cost halved, building damage doubled
- **Diplomatic Round:** Alliance cap raised to 30, pacts grant +5% shared production bonus
- **Classic Round:** No modifiers (the standard experience)

**Chemistry fit:** Different experimental conditions (temperature, pressure, concentration) produce different results. Each round is a new "experiment."

**Implementation complexity:** Simple. A `round_theme` value in `statistiques`, with multipliers applied to the relevant constants at round start.

**Impact on existing balance:** Low. Each modifier is mild enough to not break the game but significant enough to make each round feel different. "Classic Round" ensures purists are satisfied periodically.

---

### 3h. Quality of Life

#### 3h-1. Attack Planner / Calculator

**What it adds:** A client-side tool (JavaScript on `attaquer.php`) that shows the player estimated combat outcomes before committing to an attack:

- "Based on your spy report of [target], your army would deal approximately [X] damage and take approximately [Y] casualties"
- "Estimated travel time: [Z]"
- "Estimated pillage: [W] resources"
- "Warning: your molecules will decay [N]% during the round trip"

**Chemistry fit:** Theoretical chemistry -- predicting reaction outcomes before running the experiment.

**Implementation complexity:** Simple. All formulas are already in `formulas.php`. Replicate them in JavaScript using the API endpoint.

**Impact on existing balance:** None. Purely informational. Reduces the "I attacked and lost everything because I did not understand the math" frustration that drives new players away.

#### 3h-2. Push Notifications for Key Events

**What it adds:**
- "Your building upgrade is complete!" (already partially exists)
- "You are under attack! Estimated arrival: [time]"
- "Your attack has returned with [X] pillaged resources"
- "Your molecule formation is complete: [N] molecules ready"
- "Weekly Catalyst has changed to [X]"

**Implementation complexity:** Medium. Requires service worker integration (already have push notification scripts in `js/`).

**Impact on existing balance:** None. Pure quality of life.

#### 3h-3. Molecule Template Library

**What it adds:** Players can save and name molecule compositions as "templates" for reuse across seasons. When creating a new molecule class, they can load a template instead of manually entering all 8 atom counts.

**Chemistry fit:** Lab notebooks -- chemists record successful formulations for reuse.

**Implementation complexity:** Simple. A `molecule_templates` table linked to login, a save/load UI on `molecule.php`.

**Impact on existing balance:** None. Pure convenience.

#### 3h-4. Interactive Tutorial With Guided First Attack

**What it adds:** The existing tutorial system (`niveaututo` state machine) could be expanded to walk players through their first complete game loop:
1. Build a generateur (already in tutorial)
2. Build a producteur and allocate production points
3. Create a molecule class with a suggested balanced composition
4. Form 10 molecules
5. Spy on a neighboring player (provided neutrinos)
6. Launch a guided first attack

Each step would give bonus resources as rewards, and the tutorial would explain the underlying mechanics.

**Implementation complexity:** Medium. The tutorial framework exists (`cardsprivate.php`). Expanding it with more steps and rewards requires new state machine logic.

**Impact on existing balance:** Low positive. Dramatically improves new player retention.

---

## 4. PLAYSTYLE ARCHETYPES

A well-balanced TVLW should support at least 6 distinct viable playstyles. Each is named after a chemistry concept.

### 4.1 The Oxidizer (Aggressive Raider)

**Chemistry theme:** Oxygen is highly reactive, initiating combustion. The Oxidizer is a player who burns through enemies.

**Core strategy:**
- Maximize Oxygen in molecules (O=200 in attack classes)
- Prioritize Ionisateur building (attack bonus)
- Invest Condenseur points heavily in Oxygen and Chlorine (speed)
- Build lightweight, fast molecules: high O, high Cl, moderate Br, minimal everything else
- Attack frequently to earn Terreur, Attaque, and Pillage medals
- Join an alliance for Duplicateur combat bonus

**Buildings priority:** Generateur > Ionisateur > Lieur > Condenseur > Producteur > Depot

**Alliance contribution:** The alliance's offensive sword. Spearheads attacks during wars, pillages enemy alliance members, and disrupts opponents' economies.

**Countered by:** The Noble Gas (heavy defenses absorb Oxidizer's damage) and The Catalyst (trap mechanics punish reckless attacks).

**Counters:** The Polymer (slow builders who accumulate resources that are easy to steal) and The Solvent (economic players whose stored resources are tempting targets).

### 4.2 The Noble Gas (Defensive Fortress)

**Chemistry theme:** Noble gases are stable and unreactive. The Noble Gas is nearly impossible to crack.

**Core strategy:**
- Maximize Carbon (defense) and Bromine (HP) in molecules
- Prioritize Champ de Force building (defense bonus + building damage sponge)
- Invest Condenseur points in Carbon and Bromine
- Build heavy, durable molecules: high C, high Br, some H for building counter-damage
- Maintain a large standing army that deters attackers through sheer size
- Keep Stabilisateur high to preserve army during downtime

**Buildings priority:** Generateur > Champ de Force > Stabilisateur > Condenseur > Depot > Producteur

**Alliance contribution:** The alliance's shield. Their base is the hardest to crack, so enemies waste armies attacking them. In war, they absorb enemy offensives while allies counter-attack.

**Countered by:** The Solvent (economic superiority means the Solvent can eventually out-produce the Noble Gas's defenses) and attrition from repeated Oxidizer attacks.

**Counters:** The Oxidizer (absorbs their attacks and wastes their molecules) and the Isotope (intelligence-based attacks are wasted against an impenetrable wall).

### 4.3 The Solvent (Economic Engine)

**Chemistry theme:** Solvents dissolve and transform other substances. The Solvent transforms raw resources into alliance-wide advantage.

**Core strategy:**
- Prioritize Generateur, Producteur, and Depot above all combat buildings
- Use the market aggressively: buy low, sell high, earn trade points
- Donate heavily to alliance energy pool for Duplicateur upgrades
- Maintain a minimal defensive army (just enough to deter opportunistic raids)
- Molecules optimized for Iodine (energy production) and Sulfur (pillage for economy)
- Trades resources with allies to fill gaps

**Buildings priority:** Generateur > Producteur > Depot > Condenseur > Stabilisateur > Lieur

**Alliance contribution:** The alliance's bank. Funds the Duplicateur, supplies resources to allies preparing for war, and earns trade points that boost overall ranking.

**Countered by:** The Oxidizer (the Solvent's weak army makes them a tempting target for raiders).

**Counters:** The Polymer (the Solvent can out-produce the Polymer's slow accumulation) and long-term attrition against the Noble Gas (economic superiority wins wars of attrition).

### 4.4 The Catalyst (Strategic Coordinator)

**Chemistry theme:** Catalysts speed up reactions without being consumed. The Catalyst makes everyone around them stronger.

**Core strategy:**
- Balanced building development with emphasis on Condenseur and Lieur
- Invest in neutrinos for espionage -- know everything about enemies
- Share intelligence with alliance (proposed Shared Intelligence feature)
- Design molecules that complement allies' compositions (proposed Reaction Bonus feature)
- Maintain a fast army (high Chlorine) for rapid-response defense or opportunistic strikes
- Set traps (proposed Trap feature) on their base

**Buildings priority:** Generateur > Condenseur > Lieur > Ionisateur = Champ de Force > Producteur

**Alliance contribution:** The alliance's brain. Provides espionage intelligence, coordinates attacks, and identifies enemy weaknesses. Their molecules trigger reaction bonuses when attacking alongside allies.

**Countered by:** The Noble Gas (no amount of intelligence helps if you cannot crack the defense) and isolation (the Catalyst is weak when playing solo).

**Counters:** The Oxidizer (intelligence advantage means the Catalyst can predict and prepare for attacks) and uncoordinated enemies (the Catalyst's team-play advantage shines against disorganized opposition).

### 4.5 The Polymer (Patient Builder)

**Chemistry theme:** Polymers are long chains built through repetitive bonding. The Polymer grows slowly but becomes enormous.

**Core strategy:**
- Focus on construction points: upgrade ALL buildings steadily rather than specializing
- Earn the Constructeur medal early (cost reduction) and Energievore medal (production bonus)
- Build molecules with high total atom counts (big, expensive molecules) once Stabilisateur is high enough to sustain them
- Late-game payoff: by round day 20-25, the Polymer has the highest building levels, the best medals, and the most efficient economy
- Avoid combat in the first 2 weeks; ramp up in the final week

**Buildings priority:** Generateur > Producteur > Depot > Stabilisateur > Condenseur > All others evenly

**Alliance contribution:** The alliance's anchor. In the second half of the round, the Polymer is the strongest player by raw stats. Their high buildings generate the most points and their large, stable army provides the alliance's late-game punch.

**Countered by:** The Oxidizer (early raiding disrupts the Polymer's slow buildup) and the Catalyst (who coordinates attacks against the Polymer before they reach critical mass).

**Counters:** Everyone in the late game. The Polymer's endgame superiority is their win condition. They counter the Noble Gas through sheer economic superiority and the Solvent through higher building levels.

### 4.6 The Isotope (Unpredictable Wildcard)

**Chemistry theme:** Isotopes are unexpected variants -- same element, different mass, sometimes radioactive. The Isotope is the player nobody can predict.

**Core strategy:**
- Highly varied molecule compositions: different classes optimized for different opponents
- Uses espionage extensively to find specific targets' weaknesses
- Switches between offense and defense fluidly
- May intentionally leave a "tempting" base (low defense, visible resources) to bait attackers into traps
- Participates in market manipulation: buys an atom type to drive price up, then uses that atom in molecules while opponents cannot afford it
- Changes strategy week to week based on the meta

**Buildings priority:** Varies by round and week. The Isotope adapts.

**Alliance contribution:** The alliance's wildcard. Hard for enemies to predict. Can fill whatever role the alliance needs at any given moment. Particularly valuable in wars where the enemy has scouted other members.

**Countered by:** The Noble Gas (impervious to tricks) and consistency (a well-executed simple strategy often beats a poorly-executed complex one).

**Counters:** The Polymer (whose predictable buildup pattern is easy to exploit for a player who scouts and adapts) and the Solvent (whose weak army makes them vulnerable to any well-timed attack).

### 4.6 Counter-Play Matrix

```
                Oxidizer    Noble Gas   Solvent     Catalyst    Polymer     Isotope
Oxidizer        --          WEAK vs     STRONG vs   WEAK vs     STRONG vs   EVEN
Noble Gas       STRONG vs   --          WEAK vs     STRONG vs   WEAK vs     STRONG vs
Solvent         WEAK vs     STRONG vs   --          EVEN        WEAK vs     WEAK vs
Catalyst        STRONG vs   WEAK vs     EVEN        --          STRONG vs   EVEN
Polymer         WEAK vs     STRONG vs   STRONG vs   WEAK vs     --          WEAK vs
Isotope         EVEN        WEAK vs     STRONG vs   EVEN        STRONG vs   --
```

This is not a simple rock-paper-scissors triangle but a more complex web where every playstyle has at least 2 favorable and 2 unfavorable matchups, with 1-2 even matchups. No single playstyle dominates all others.

---

## 5. RETENTION AND ENGAGEMENT

### 5.1 Daily Engagement Hooks

**Current state:** Players log in, collect accumulated resources (automatic), check construction queue, and leave. There is nothing that rewards daily activity specifically.

**Proposed daily hooks:**

| Hook | Description | Implementation | Impact |
|---|---|---|---|
| **Daily Compound** | Craft one free compound per day (from Section 3d-2). Expires after 24 hours if unused. | Simple | Low |
| **Daily Trap** | Set one trap per day (from Section 3c-2). Encourages defensive engagement. | Simple | Low |
| **Daily Bounty** | A random player (within +/-30% of your points) is marked as your "daily bounty target." Successfully attacking them grants +2 bonus combat points. | Simple | Medium |
| **Production Checkpoint** | If you log in before your storage fills (within 80% of cap), you get a 10-minute +10% production boost. Miss it and production overflows are wasted. | Simple | Low |
| **Market Special** | One random atom type has -20% market price for the first buyer each day. First come, first served. Creates a reason to log in early. | Simple | Medium |

### 5.2 Weekly Engagement Hooks

| Hook | Description | Implementation | Impact |
|---|---|---|---|
| **Weekly Catalyst** (Section 3g-1) | Rotating global bonus changes the meta each week. Players check what the catalyst is and adjust plans. | Simple | Medium |
| **Alliance Objective** | Each week, each alliance receives a shared objective (e.g., "collectively build 50 building levels this week"). Completion grants shared alliance energy. | Medium | High |
| **Leaderboard Snapshot** | Every Monday, take a ranking snapshot. Players who improved their rank from last week get a bonus point. Creates weekly ranking races. | Simple | Medium |
| **Spy Mission** | A weekly "intelligence target" is announced. First player to successfully spy on that target earns bonus trade points. Creates a mini-game of neutrino management. | Medium | Low |

### 5.3 Seasonal (Round) Engagement Hooks

| Hook | Description | Implementation | Impact |
|---|---|---|---|
| **Round Theme** (Section 3g-3) | Each round has a random modifier that changes fundamental rules. Players must adapt each month. | Simple | High |
| **Final Reaction Event** (Section 3g-2) | Boss molecule in last 72 hours creates all-server cooperative/competitive endgame. | Complex | High |
| **Prestige Rewards** (Section 3f-1) | End-of-season prestige points for ALL active players, not just top 50. | Medium | High |
| **Historical Archive** | Post-season stats page showing personal bests, total molecules formed lifetime, total attacks lifetime, etc. Persistent across seasons. | Medium | Medium |
| **Medal Persistence** | Highest medal tier ever achieved is permanently displayed on profile (even after reset). Creates a long-term collection goal. | Simple | Medium |

### 5.4 Social / Competitive Hooks

| Hook | Description | Implementation | Impact |
|---|---|---|---|
| **Alliance Wars With Objectives** (Section 3e-2) | Wars generate objectives with VP rewards. Creates alliance-level stakes. | Complex | High |
| **Shared Intelligence** (Section 3e-3) | Alliance members share spy reports. Encourages communication and coordination. | Simple | Medium |
| **Alliance Research Tree** (Section 3e-1) | Multiple shared technologies to research. Creates ongoing alliance discussion about priorities. | Medium | High |
| **Rival System** | Players can designate up to 3 "rivals." Attacks against rivals grant +1 bonus combat point. Creates personal feuds. | Simple | Medium |
| **War Stories** | After a battle with > 50 total casualties, the game generates a narrative summary ("The Battle of [coordinates]: [Attacker] charged with 500 molecules against [Defender]'s entrenched force of 800..."). Shareable on forum. | Medium | Medium |

### 5.5 What Keeps Players For 15 Years

The players who have stayed with TVLW for 15 years stay for the **community**, not the mechanics. They know each other. They have rivalries, alliances, and shared history. The game improvements most likely to keep them and bring back lapsed players are:

1. **New strategic depth without invalidating their expertise.** Veterans should feel rewarded for understanding the game deeply, but surprised by new dimensions (reactions, specializations, traps).

2. **Meaningful alliance play.** Veterans are likely in established alliances. Giving those alliances more to do (research tree, war objectives, shared intelligence) validates their social investment.

3. **Recognition of their history.** Prestige system, historical archives, permanent medal displays -- these tell veterans "your years of play mattered."

4. **Fresh rounds.** Seasonal modifiers and weekly catalysts ensure no two rounds feel the same. Veterans who have "solved" the current meta will be re-engaged by a shifting meta.

5. **New player influx.** Nothing kills a community game faster than stagnation. Better tutorials, quality-of-life improvements, and the prestige system's small cross-season bonuses help new players catch up just enough to be competitive, keeping the player base healthy.

---

## 6. PRIORITY RANKING

Each suggestion is scored on four dimensions:
- **Gameplay Depth** (1-10): How much strategic depth does this add?
- **Effort** (Simple/Medium/Complex): Implementation difficulty
- **Risk** (Low/Medium/High): Risk of alienating existing players
- **Fun Factor** (1-10): How much more fun does this make the game?

### Tier 1: Do First (High Impact, Manageable Effort)

| # | Suggestion | Depth | Effort | Risk | Fun | Rationale |
|---|---|:---:|---|---|:---:|---|
| 1 | **Defensive Formations** (3c-1) | 9 | Medium | Low | 9 | Directly solves the "combat is solved" problem. Three formation choices create an information game. Attackers must scout, defenders have agency. |
| 2 | **Prestige System** (3f-1) | 7 | Medium | Low | 8 | Gives EVERY player cross-season progression. Solves the "bottom 50% earn nothing" problem. Veterans get rewarded, new players get hope. |
| 3 | **Weekly Catalysts** (3g-1) | 6 | Simple | Low | 8 | Trivial to implement, enormous impact on round variety. No two weeks feel the same. Prevents meta stagnation. |
| 4 | **Vault Building** (3d-3) | 5 | Simple | Low | 7 | Reduces the "logged in to find everything stolen" frustration. Makes defense meaningful. Simple to implement. |
| 5 | **Reactive Countermeasures / Traps** (3c-2) | 7 | Simple | Low | 8 | Daily engagement hook + defensive depth in one feature. Cheap to implement, creates uncertainty for attackers. |
| 6 | **Isotope Variants** (3a-2) | 8 | Simple | Low | 8 | Three molecule variants (Stable/Reactive/Catalytic) create build diversity with minimal code. Adds a strategic dimension to molecule design. |
| 7 | **Attack Planner** (3h-1) | 3 | Simple | Low | 7 | Pure quality of life. Reduces frustration, improves new player experience, costs almost nothing to build since formulas already exist in code. |

### Tier 2: Do Second (High Impact, More Effort)

| # | Suggestion | Depth | Effort | Risk | Fun | Rationale |
|---|---|:---:|---|---|:---:|---|
| 8 | **Alliance Research Tree** (3e-1) | 8 | Medium | Low | 8 | Gives alliances 5-6 things to work toward instead of just the Duplicateur. Creates alliance identity and differentiation. |
| 9 | **Chemical Reactions Between Classes** (3a-1) | 8 | Medium | Medium | 9 | Deepens molecule design enormously. Incentivizes complementary army composition. Requires careful balancing of reaction bonuses. |
| 10 | **Specialization Tracks** (3f-2) | 9 | Medium | Medium | 8 | Creates permanent-for-round build identity. Two players at the same level play differently. Irreversibility adds weight to decisions. |
| 11 | **Automatic Retaliation** (3c-3) | 6 | Medium | Medium | 7 | Gives defenders agency when offline. Creates attacker risk. But requires careful tuning to avoid making attacks too risky. |
| 12 | **Shared Intelligence** (3e-3) | 4 | Simple | Low | 6 | Simple alliance quality-of-life feature. Encourages coordination and communication. |
| 13 | **Seasonal Modifiers** (3g-3) | 6 | Simple | Low | 8 | Each round feels different. "Classic Round" option keeps purists happy. Very low risk. |
| 14 | **Interactive Tutorial** (3h-4) | 3 | Medium | Low | 6 | Critical for new player retention but does not add depth for veterans. |

### Tier 3: Do Later (High Impact, High Effort or Higher Risk)

| # | Suggestion | Depth | Effort | Risk | Fun | Rationale |
|---|---|:---:|---|---|:---:|---|
| 15 | **Resource Nodes on Map** (3b-1) | 8 | Medium | Medium | 8 | Transforms the map from decorative to strategic. But changes fundamental game geography and could disadvantage players with bad node luck. |
| 16 | **Alliance Wars With Objectives** (3e-2) | 9 | Complex | Medium | 9 | Excellent for endgame depth but requires significant new logic. Best built after alliance research tree is established. |
| 17 | **Compound Synthesis / Laboratoire** (3d-2) | 8 | Complex | Medium | 9 | Adds an entirely new game layer. Beautiful chemistry fit. But requires a new building, crafting UI, consumable system, and extensive balancing. |
| 18 | **End-of-Season Boss Event** (3g-2) | 6 | Complex | Low | 9 | Gives endgame meaning. Cooperative/competitive event is exciting. But requires NPC logic, damage aggregation, and reward distribution. |
| 19 | **Resource Scarcity Zones** (3d-1) | 7 | Simple | Medium | 7 | Forces meta adaptation each round. But could frustrate players who prefer a consistent experience. |
| 20 | **Controlled Zones** (3b-2) | 9 | Complex | High | 7 | Most transformative feature but highest risk. Territory control fundamentally changes the game from base-building to map-control. Should only be attempted after simpler features prove the playerbase wants more map interaction. |

### Tier 4: Nice to Have (Lower Impact)

| # | Suggestion | Depth | Effort | Risk | Fun | Rationale |
|---|---|:---:|---|---|:---:|---|
| 21 | **Push Notifications** (3h-2) | 1 | Medium | Low | 5 | Quality of life. Players already manage without them. |
| 22 | **Molecule Template Library** (3h-3) | 1 | Simple | Low | 4 | Minor convenience. Useful for veterans across seasons. |
| 23 | **Molecular Weight / Inertia** (3a-3) | 6 | Simple | Medium | 5 | Adds a weight-speed tradeoff but overlaps with existing Chlorine speed mechanic. May be redundant if isotope variants are implemented. |
| 24 | **Daily Bounty Target** | 4 | Simple | Low | 6 | Fun engagement hook but low strategic depth. |
| 25 | **Rival System** | 3 | Simple | Low | 6 | Social flavor. Creates personal stakes in combat. |
| 26 | **War Stories** | 1 | Medium | Low | 5 | Flavor/community feature. Fun to share but no gameplay impact. |

---

## IMPLEMENTATION ROADMAP

### Phase 1: "The Stabilization Update" (Weeks 1-3)
*Fix existing issues and add the simplest high-impact features*

- Fix all critical bugs from BALANCE-ANALYSIS.md (Duplicateur combat bug, building damage fall-through, Stabilisateur cost variable)
- Implement **Vault Building** (3d-3) -- simple new building, immediate defensive quality of life
- Implement **Weekly Catalysts** (3g-1) -- one config value per week, massive variety
- Implement **Attack Planner** (3h-1) -- JavaScript-only, no backend changes
- Implement **Seasonal Modifiers** (3g-3) -- one config value per round

### Phase 2: "The Reaction Update" (Weeks 4-8)
*Add the features that transform combat and progression*

- Implement **Defensive Formations** (3c-1) -- the single biggest combat improvement
- Implement **Isotope Variants** (3a-2) -- three molecule variants
- Implement **Reactive Countermeasures / Traps** (3c-2) -- daily defensive engagement
- Implement **Prestige System** (3f-1) -- cross-season progression for everyone
- Implement **Shared Intelligence** (3e-3) -- alliance quality of life

### Phase 3: "The Synthesis Update" (Weeks 9-14)
*Deepen alliance play and molecule design*

- Implement **Alliance Research Tree** (3e-1) -- 5-6 shared technologies
- Implement **Chemical Reactions Between Classes** (3a-1) -- molecule synergy system
- Implement **Specialization Tracks** (3f-2) -- round-permanent build identity
- Implement **Interactive Tutorial** (3h-4) -- new player onboarding
- Implement **Automatic Retaliation** (3c-3) -- defensive counter-attack

### Phase 4: "The Discovery Update" (Weeks 15-20)
*Add map depth and endgame content*

- Implement **Resource Nodes on Map** (3b-1) -- strategic geography
- Implement **Alliance Wars With Objectives** (3e-2) -- war depth
- Implement **Compound Synthesis / Laboratoire** (3d-2) -- crafting system
- Implement **End-of-Season Boss Event** (3g-2) -- endgame climax

### Phase 5: "The Territory Update" (Weeks 21+)
*Only if player engagement data supports it*

- Implement **Controlled Zones** (3b-2) -- map-level territory control
- Implement **Resource Scarcity Zones** (3d-1) -- rotating element scarcity
- Polish and balance all systems based on live data

---

## CLOSING NOTES

TVLW has something rare: a theme that is not just a skin but a structural framework. Chemistry provides an infinite well of naming conventions, thematic mechanics, and educational value. The suggestions in this report are designed to deepen what is already there rather than replace it.

The highest-priority changes (defensive formations, prestige system, weekly catalysts, vault, traps, isotope variants) can all be implemented without touching the core combat math or database schema in fundamental ways. They are additive -- existing players will not lose anything they currently have, but will gain new dimensions of play.

The molecule composition system is the game's crown jewel. Every suggestion that touches molecules (isotope variants, chemical reactions, specializations) is designed to multiply the design space rather than constrain it. A player who has played for 15 years should look at these changes and think: "I need to rethink everything. This is exciting."

That is the goal: make TVLW the best version of itself.
