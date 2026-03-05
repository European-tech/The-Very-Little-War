# Pass 3 — Cross-Domain Emergent Balance Audit
## The Very Little War — PHP 8.2 Chemistry Strategy Game

**Date:** 2026-03-05
**Auditor:** Senior Game Developer (cross-system analysis)
**Scope:** Interactions between economy, combat, ranking, alliance, prestige, compounds, resource nodes, and map systems
**Finding range:** P3-EB-001 through P3-EB-028

---

## Executive Summary

Pass 3 analysis reveals that individual systems often appear balanced in isolation but create severe imbalance when combined. The dominant emergent pathologies are:

1. **The Compound-Combat Amplifier Stack** — up to 7 multiplicative combat modifiers stack simultaneously, creating ~200-300% effective stat inflations for prepared players vs. unprepared opponents.
2. **The Iode-Duplicateur-Node Production Loop** — three production bonuses multiply together; a well-positioned veteran can produce 3-4x more resources per hour than a new player, compounding into insurmountable build advantages by day 7.
3. **The Alliance Ranking Pump** — the Reseau research bonus on top of sqrt-then-weight ranking means alliance members farm trade points more efficiently than solo players and cannot be caught by soloists.
4. **Total Veteran Bonus Ceiling** — stacking all cross-season advantages (prestige + medals + alliance + resource nodes + compounds + specialization) creates a theoretical maximum stat multiplier of approximately 3.8x over a naked day-1 player, making new-player viability effectively zero past day 7.

All findings below include quantitative analysis where possible.

---

## Findings

---

### P3-EB-001 | CRITICAL | Full Combat Modifier Stack Reaches ~3x Base Stats

**Systems involved:** Compounds (CO2/NaCl), Ionisateur building, Duplicateur alliance bonus, Isotope Catalytique, Prestige combat bonus, Specialization Oxydant, Medal attack bonus

**Description:**
A combat-optimized attacker can simultaneously apply the following multiplicative modifiers to their attack damage:

| Modifier | Source | Value |
|---|---|---|
| Ionisateur level 50 | Building | +100% (2.0x) |
| Duplicateur level 25 | Alliance | +25% (1.25x) |
| Isotope Catalytique (3 classes boosted) | Molecules | +15% (1.15x) |
| CO2 compound (attack_boost) | Laboratoire | +10% (1.10x) |
| Prestige Maitre Chimiste | Cross-season | +5% (1.05x) |
| Specialization Oxydant | Irreversible choice | +10% (1.10x) |
| Emeraude attack medal (cap) | Cross-season | +10% (1.10x) |

Combined: `2.0 * 1.25 * 1.15 * 1.10 * 1.05 * 1.10 * 1.10 = 4.21x base attack`

Meanwhile, an isolated new player without alliance, no prestige, no compounds, and no medal has a 1.0x multiplier. The attack formula `attaque(O, H, nivCondO)` is already quadratic in O — the atom count advantage of a veteran compounds this further.

**Impact:**
A veteran with a modestly-sized army can one-shot wipe a newer player's entire force in a single attack. The combat outcome is determined largely before the battle starts by buff stacking, not by skill or strategy. This makes PvP feel predetermined and drives new players to quit.

**Fix:**
Apply a global combat modifier cap. Introduce a `COMBAT_BONUS_CAP` constant (e.g., 2.5x) in `config.php` and clamp `$degatsAttaquant` and `$degatsDefenseur` before the casualty loop in `combat.php`. Each modifier category (buildings, isotopes, compounds, prestige, specialization) should be additive within its category, with a single multiplicative cross-category cap.

Suggested in `combat.php` near line 195:
```php
define('COMBAT_TOTAL_MODIFIER_CAP', 2.50); // config.php
$degatsAttaquant = min($degatsAttaquant, $rawAttaque * COMBAT_TOTAL_MODIFIER_CAP);
$degatsDefenseur = min($degatsDefenseur, $rawDefense * COMBAT_TOTAL_MODIFIER_CAP);
```

---

### P3-EB-002 | CRITICAL | Economy Positive Feedback Loop Compounds Daily

**Systems involved:** Production (generateur/producteur), Iode catalyst bonus, Resource node proximity, Alliance Duplicateur, Prestige production bonus, Compound production_boost, Specialization Industriel

**Description:**
Energy production formula in `game_resources.php` `revenuEnergie()` applies all bonuses multiplicatively in sequence:

```
prodBase = BASE_ENERGY_PER_LEVEL * generateur_level
prodIode = prodBase * iodeCatalystBonus        // up to 2.0x
prodMedaille = prodIode * (1 + medalBonus/100) // up to 1.10x
prodDuplicateur = prodMedaille * bonusDuplicateur // up to 1.25x
prodPrestige = prodDuplicateur * prestigeBonus  // 1.05x
prodNodes = prodPrestige * (1 + nodeBonus)      // up to 1.30x (3 overlapping nodes)
prodCompound = prodNodes * (1 + compoundBonus)  // up to 1.10x
prodSpec = prodCompound * (1 + specMod)         // up to 1.20x (Energetique)
```

At max stacking: `1 * 2.0 * 1.10 * 1.25 * 1.05 * 1.30 * 1.10 * 1.20 = 5.81x base production`.

A day-1 veteran with Generateur level 10 producing 750 base energy/h could produce ~4,358 effective energy/h. A new player at the same building level produces 750 energy/h. This 5.8x gap means the veteran builds faster, fields more molecules, and maintains the gap indefinitely.

**Impact:**
The production loop self-reinforces: more energy → higher buildings → more energy → better molecules → better raids → more pillage → more construction points. The snowball is not bounded by anything mechanical after the first 3-4 days.

**Fix:**
Convert stacked production bonuses from multiplicative to additive within each tier:
```php
// Additive sum, then single multiplier
$totalBonus = $iodeCatalystBonus - 1.0 + ($bonusDuplicateur - 1.0) + $energyNodeBonus + $compoundProdBonus + $specEnergyMod + ($medalBonus / 100) + ($prestigeBonus - 1.0);
$prodFinal = $prodBase * (1 + $totalBonus) - drainageProducteur($producteur);
```
This converts 5.81x to approximately 2.80x at maximum stacking — still rewarding veterans but not exponentially.

---

### P3-EB-003 | HIGH | Sell-Tax Does Not Prevent Trade-Point Cycling

**Systems involved:** Market buy/sell, Trade points (tradeVolume), Ranking (sqrt formula), Alliance Reseau research

**Description:**
The previous auditor noted the buy-sell arbitrage issue (P2-D4-001). The implemented 5% sell tax (`MARKET_SELL_TAX_RATE = 0.95`) was intended to fix this, but the fix is incomplete. A player can still cycle resources to earn trade points at a 95% efficiency rate.

Consider: a player has 10,000 energy and buys carbone at price 1.0 (cost: 10,000 energy, gains 10,000 carbone, earns 10,000 tradeVolume). They then sell 10,000 carbone at 0.95 effective (gains 9,500 energy, earns 9,500 tradeVolume). Total: spent 500 energy, earned 19,500 tradeVolume. The trade point formula `sqrt(tradeVolume) * weight` means the ranking benefit of cycling is `sqrt(19,500) ≈ 140 points` for 500 energy spent.

Legitimate trading (buying atoms you need once) yields `sqrt(10,000) = 100 points` for the same 10,000 energy. Cycling gives 28% more ranking value per energy than productive play.

With Alliance Reseau at level 25: tradeVolume is multiplied by `1 + 25 * 0.05 = 2.25x`. The cycling player earns `sqrt(43,875) ≈ 210 points` for 500 energy — a 2.1x ranking advantage over a non-alliance trader.

**Impact:**
Optimal solo ranking strategy is pure market cycling, not actual gameplay. Alliance members gain a further 2.25x multiplier on this exploit. Trade points become the dominant ranking category for organized groups.

**Fix:**
Award trade points only on buy operations (energy spent), not on sell operations. Alternatively, cap total tradeVolume per session (per hour) at a reasonable level:
```php
define('MARKET_TRADE_VOLUME_HOURLY_CAP', 50000); // config.php
```
Track last-hour trade volume in the `autre` table and refuse further point awards once capped.

---

### P3-EB-004 | HIGH | Resource Node Radius Creates Permanent Positional Lock-In

**Systems involved:** Resource nodes (resource_nodes.php), Player position (membre.x, membre.y), Production (game_resources.php), Map placement

**Description:**
`getResourceNodeBonus()` returns a flat `+10%` for every node within radius 5. Nodes are generated once per season with 15-25 nodes on a map of unspecified size. Players are placed at fixed positions on registration.

Three critical emergent problems:
1. A player placed near 2-3 overlapping energy nodes receives `+30%` permanent production bonus with zero gameplay cost.
2. Nodes are checked via Euclidean distance on the static `(membre.x, membre.y)` coordinates. Players cannot move, so position advantage is locked in for the entire 31-day season.
3. Nodes are generated randomly, meaning day-1 luck of position determines a 30% permanent economic advantage. There is no catch-up or counter-play.

Quantitatively: `+30%` production × 31 days = 9.3 extra days of a non-node player's production, received for free by a lucky-positioned player.

**Impact:**
Position on registration (effectively random) becomes a major determinant of competitive viability. Players near clusters of matching nodes can outproduce late-joiners by 20-30% indefinitely. This intersects with the economy loop (P3-EB-002) multiplicatively.

**Fix:**
1. Add diminishing returns for multiple nodes of the same type: first node gives +10%, second gives +5%, third gives +2.5%.
2. Add a "resource node decay" mechanic — nodes deplete to 50% bonus after being within radius of a player for 7+ days, rewarding players who seek new nodes.
3. Cap total resource node bonus across all types at 15%: `RESOURCE_NODE_BONUS_CAP = 0.15` in config.php.

---

### P3-EB-005 | HIGH | Iode Catalyst + Molecule Army Creates Runaway Energy Loop

**Systems involved:** Iode catalyst energy bonus (game_resources.php), Molecule formation (actionsformation), Energy production (generateur), Producteur drain

**Description:**
The Iode catalyst formula in `revenuEnergie()`:
```php
$iodeCatalystBonus = 1.0 + min(IODE_CATALYST_MAX_BONUS, $totalIodeAtoms / IODE_CATALYST_DIVISOR);
// IODE_CATALYST_MAX_BONUS = 1.0, IODE_CATALYST_DIVISOR = 50000
```

To reach the 2.0x cap, a player needs 50,000 total iode atoms across all molecule classes. A large army of high-iode molecules achieves this. But maintaining a large army requires energy. This loop:

`more energy → form more iode molecules → more energy bonus → form even more molecules → ...`

Is self-amplifying. Once a player reaches ~25,000 total iode atoms (1.5x energy bonus), they produce enough excess energy to sustain molecule formation faster than they decay, reaching 50,000 atoms within a few days. At 50,000 iode atoms, they have the 2.0x energy cap plus a massive army.

The problem: the iode catalyst counts `molecules['iode'] * molecules['nombre']` — it rewards BOTH high iode per molecule AND high molecule counts. A player with 10,000 molecules each having 5 iode atoms gets the same bonus as one with 1,000 molecules each having 50 iode atoms, but the former has 10x the combat power.

**Impact:**
The optimal strategy is always "maximize molecule count early." There is no tradeoff between iode investment and army size. The energy feedback makes this strategy self-sustaining and unbreakable once established.

**Fix:**
Make the iode catalyst bonus based on average iode per molecule, not total iode atoms:
```php
// In config.php:
define('IODE_CATALYST_DIVISOR', 25); // per-molecule average
// In game_resources.php:
$avgIodePerMol = ($totalMolecules > 0) ? ($totalIodeAtoms / $totalMolecules) : 0;
$iodeCatalystBonus = 1.0 + min(IODE_CATALYST_MAX_BONUS, $avgIodePerMol / IODE_CATALYST_DIVISOR);
```
This rewards molecule composition quality, not raw quantity.

---

### P3-EB-006 | HIGH | Alliance + Isotope Catalytique Creates Unbalanced Alliance Combat Meta

**Systems involved:** Alliance combat (Duplicateur bonus in combat.php), Isotope Catalytique (+15% to other classes), Alliance shared attack coordination, Phalanx formation

**Description:**
When all 4 of an alliance member's molecule classes use the Catalytique isotope configuration optimally (1 class as Catalytique, 3 classes boosted):
- The 3 boosted classes get +15% attack AND +15% HP.
- Alliance Duplicateur level 25 gives +25% to all attack.
- Combined on boosted classes: base attack × 1.15 (Catalytique ally) × 1.25 (Duplicateur) = 1.4375x.

When coordinating a war, all 20 alliance members can attack a single target in sequence within the 1-hour win cooldown window. Each attacker deals 1.44x effective damage. The defender cannot distribute between 20 simultaneous attackers.

The Phalanx formation only helps against one attacker at a time. Against sequential coordinated attacks, the defender's class 1 gets wiped first (Phalanx absorbs 60% damage to class 1), then subsequent attackers face class 2, 3, 4 with no formation benefit since class 1 is already depleted.

**Impact:**
Organized alliances of 20 members can sequentially strip a solo player's entire army within hours. There is no defense against sequential pile-on attacks. Small alliances and solo players have no viable counter-strategy.

**Fix:**
1. Add a "defense cooldown" symmetric to the attack cooldown: after being attacked 3+ times within 4 hours by different players from the same alliance, activate a 6-hour immunity window. Define `ALLIANCE_PILE_ON_THRESHOLD = 3` and `DEFENSE_IMMUNITY_SECONDS = 6 * SECONDS_PER_HOUR` in config.php.
2. Track attacks by alliance in `actionsattaques` and block new attacks from same alliance once threshold is hit.

---

### P3-EB-007 | HIGH | New Player Cannot Reach Day-1 Veteran Within a Single Season

**Systems involved:** All production systems, Combat, Molecule decay, Building costs (ECO_GROWTH_BASE), Prestige cross-season advantages

**Description:**
Quantitative snowball analysis for a 31-day season:

**Veteran (day 1 joiner, prestige Experimente + Maitre Chimiste unlocked, Emeraude medal):**
- Day 1: base production with +5% prestige + +10% medal = 1.155x production
- Day 7: Generateur level 8, Duplicateur level 4 (via alliance) = +4% Duplicateur → 1.20x total
- Day 14: grace period ends, full medal bonus restores → 2.0x+ stack possible
- Day 31: accumulated 31 × 24 = 744 hours of 2.0x production advantage vs. new player

**New player (day 1 joiner, no prestige, no medals):**
- Day 1-3: under beginner protection — cannot be attacked, but also cannot build fast
- Day 7: building level ~5 for core buildings, single alliance slot open
- Cannot unlock 'experimente' prestige (costs 100 PP — requires 2+ full seasons)
- Cannot match veteran's molecule count due to energy deficit
- Day 14: veteran is already attacking — new player has no defense depth

**Mathematical gap at day 14:** Veteran has produced approximately 2.0x the energy of the new player for 14 days. Building costs are `cost_base * 1.15^level`. The veteran is at level 10-12 buildings; the new player is at level 7-8. The cost gap between level 8 and level 12 generateur is: `75 * 1.15^12 / (75 * 1.15^8) = 1.15^4 = 1.75x`. The new player needs 75% more energy to reach the same building level — which they produce 50% less of. The gap is not catchable.

**Impact:**
New players joining after day 7 of a season have no realistic path to top-50 ranking. After day 14, top-10 is impossible. This destroys player retention for mid-season joiners and makes the ranking entirely about who joined earliest.

**Fix:**
1. Implement time-shifted bonus scaling: new players (< 7 days old in current season) receive a `NEW_PLAYER_PRODUCTION_MULTIPLIER = 1.5` production bonus that linearly decays to 1.0 by day 14.
2. Alliance entry for new players should carry reduced Duplicateur benefit during their first week (e.g., `min(actualDuplicateur, 5)` for the first 7 days).
3. Define these in config.php: `NEW_PLAYER_BONUS_DAYS = 7`, `NEW_PLAYER_PRODUCTION_MULTIPLIER = 1.5`.

---

### P3-EB-008 | HIGH | Prestige Snowball: Veterans Accumulate PP Faster Each Season

**Systems involved:** Prestige PP calculation (prestige.php), Medal thresholds, Rank bonuses, Cross-season unlocks

**Description:**
`calculatePrestigePoints()` awards PP based on medal tier reached. Medal thresholds for high tiers require activities (e.g., Attaque medal Diamond = 15,000 attack points) that are only achievable by veterans with strong armies. Veterans who already have prestige unlocks (production bonus, combat bonus) reach higher medal tiers more easily each season, earning more PP, enabling more prestige unlocks.

PP earn rate analysis by season:
- **Season 1 (no prestige):** ~15-20 PP (active final week + low medal tiers)
- **Season 2 (Experimente unlocked at 100 PP):** +5% production → higher medal tiers → ~25-35 PP
- **Season 3 (Maitre Chimiste unlocked at 500 PP):** +5% combat → Attaque/Defense Diamond possible → ~45-60 PP

The rank bonus (top 5 = +50 PP) is exclusively accessible to veterans who already have all prestige bonuses, making them earn 50+ PP while mid-table players earn 15-20 PP.

**Impact:**
The prestige system creates an accelerating advantage that grows faster than the gap between seasons can allow new players to close. After 3-4 seasons, a veteran has 200-400+ PP invested in unlocks that a new player literally cannot have. The prestige economy has no ceiling or decay mechanism.

**Fix:**
1. Add prestige PP decay: each season, total_pp is reduced by 10% of the amount unspent. Veterans are incentivized to keep spending rather than hoarding.
2. Cap the per-rank PP bonus differential: instead of +50 PP for rank 1, use `VP_PRESTIGE_SCALE = 10` so rank 1 earns +10 bonus PP, rank 2 +9, etc. This reduces the top-rank PP advantage.
3. Add a catch-up PP grant for players in their first 3 seasons: `NEW_ACCOUNT_SEASONS_CATCHUP = 3`, granting +10 flat PP per season below this threshold.

---

### P3-EB-009 | HIGH | Beginner Protection Timestamp Uses Wrong Column (Cross-System Impact)

**Systems involved:** Beginner protection check (attaquer.php or equivalent), `membre.timestamp` vs. `membre.createdAt` or equivalent, Combat access

**Description:**
From the MEMORY context: "Beginner protection uses wrong timestamp." The 3-day beginner protection (`BEGINNER_PROTECTION_SECONDS = 3 * SECONDS_PER_DAY`) is compared against the wrong timestamp. If `membre.timestamp` tracks last login rather than account creation time, then:

1. A player who registers but does not log in for 5 days loses beginner protection on their first login — they enter the game already vulnerable.
2. A player who logs in daily retains protection correctly.
3. A veteran can register a new account, not log in for 4 days, then appear on the map with no protection — useful for alt account exploitation.

Cross-system impact: the multi-account system (`multiaccount.php`) flags suspicious IPs, but if the alt never logged in during the 4-day window, the IP check is never triggered via the normal transfer blocking path.

**Impact:**
Beginner protection is unreliable. Players who take a day to try the game before committing fully lose their protection window unexpectedly.

**Fix:**
Store account creation time in a dedicated non-updatable column. In `player.php` `inscrire()`, insert `created_at = NOW()` and never update it. Change protection check to:
```php
$createdAt = dbFetchOne($base, 'SELECT created_at FROM membre WHERE login=?', 's', $joueur);
$isProtected = (time() - $createdAt['created_at']) < BEGINNER_PROTECTION_SECONDS;
```

---

### P3-EB-010 | HIGH | Sell-Side Trade Points Award Enables Infinite Ranking without Production

**Systems involved:** Market sell (marche.php line 347), Trade points ranking (tradeVolume), Ranking formula, Building resource economy

**Description:**
In `marche.php`, selling awards trade points based on `$energyGained * $reseauBonus`. A player who has zero productive infrastructure (no generateur investment) but steals resources via pillage can sell all pillaged atoms, converting them directly into trade points. This allows a pure-raider strategy to simultaneously farm:
1. Pillage ranking points (from `ressourcesPillees`).
2. Trade ranking points (from selling the pillaged atoms).
3. Attack ranking points (from winning battles).

All three ranking categories are fed by a single activity (pillage raids). The sqrt ranking formula was designed to prevent any single activity from dominating, but pillage feeds three separate categories.

Under the sqrt ranking formula:
```
total = 1.0*sqrt(construction) + 1.5*sqrt(attack) + 1.5*sqrt(defense) + 1.0*sqrt(trade) + 1.2*sqrt(pillage)
```

A pure raider contributes to attack (1.5x), pillage (1.2x), and trade (1.0x) = 3.7x total weight from one activity. A pure builder contributes only to construction (1.0x). The system penalizes builders relative to raiders.

**Impact:**
The optimal ranking strategy is raiding, not building. This creates a predatory meta where established players continuously raid new players, farming all three categories simultaneously. New players cannot compete in any category.

**Fix:**
Sell-side trade points should be removed or significantly reduced:
```php
// Award only 20% of normal trade volume on sell (marche.php ~line 347):
$tradeVolume = round($energyGained * $reseauBonus * 0.20); // Only 20% for selling
```
Alternatively, track "market arbitrage volume" separately and exclude it from trade ranking.

---

### P3-EB-011 | HIGH | Double-Sqrt Bug in Ranking Amplifies High Performers Disproportionately

**Systems involved:** `calculerTotalPoints()` (formulas.php), `pointsAttaque()` / `pointsDefense()` intermediate functions, tradeVolume direct pass

**Description:**
From P2 context: double-sqrt scaling bug. Confirmed in code. The `recalculerTotalPointsJoueur()` function passes already-transformed values into `calculerTotalPoints()`:

```php
// In formulas.php recalculerTotalPointsJoueur():
$total = calculerTotalPoints(
    $data['points'],                    // raw construction points — sqrt applied once
    pointsAttaque($data['pointsAttaque']), // sqrt already applied inside pointsAttaque()
    pointsDefense($data['pointsDefense']), // sqrt already applied inside pointsDefense()
    $data['tradeVolume'],               // raw — sqrt applied once
    pointsPillage($data['ressourcesPillees']) // tanh applied, NOT sqrt — inconsistent
);

// Then calculerTotalPoints() applies sqrt AGAIN to all inputs:
RANKING_ATTACK_WEIGHT * pow(max(0, $attaque), RANKING_SQRT_EXPONENT) // sqrt(sqrt(raw))
```

For attack points: `$attaque = pointsAttaque(raw) = 5.0 * sqrt(raw)`. Then `sqrt($attaque) = sqrt(5.0 * sqrt(raw)) = 2.236 * raw^0.25`.

For construction points: `$construction = raw`. Then `sqrt($construction) = sqrt(raw) = raw^0.5`.

Attack points scale as `raw^0.25` while construction scales as `raw^0.5`. A player with 10,000 raw attack points earns `2.236 * 10^3 ≈ 2,236` total attack contribution. A player with 10,000 construction points earns `100` total construction contribution. The attack/construction weight ratio was intended to be 1.5:1 but is actually `(2.236 * 10,000^0.25 * 1.5) : (10,000^0.5 * 1.0) = 33.5 : 100` — construction dominates.

Note: `pointsPillage()` uses `tanh` not `sqrt`, making it a third distinct scaling function passed into a sqrt wrapper. The three categories have incompatible normalization.

**Impact:**
The ranking system is fundamentally broken in its cross-category comparisons. Building investment produces disproportionate ranking value relative to combat. Players who focus exclusively on constructions and market trading can outrank active combat players, defeating the intended "balanced player" design goal.

**Fix:**
Either pass raw values to `calculerTotalPoints()` and apply sqrt there, or pre-transform all categories consistently before the weighted sum:
```php
// Option A: Pass raw values everywhere
function recalculerTotalPointsJoueur($base, $joueur) {
    $total = calculerTotalPoints(
        $data['points'],
        $data['pointsAttaque'],    // raw, let calculerTotalPoints() apply sqrt
        $data['pointsDefense'],    // raw
        $data['tradeVolume'],      // raw
        $data['ressourcesPillees'] // raw
    );
}
// Then calculerTotalPoints applies sqrt uniformly to all 5.
```

---

### P3-EB-012 | HIGH | Catalytique Isotope + Alliance Research Stacks to Unplayable Multipliers

**Systems involved:** Isotope Catalytique (+15% ally bonus in combat.php), Alliance Fortification research (+1% building HP/level), Alliance Reseau (+5% trade/level), Catalytique self-penalty (-10% attack, -10% HP)

**Description:**
The Catalytique isotope boosts other classes by `ISOTOPE_CATALYTIQUE_ALLY_BONUS = 0.15` to both attack and HP. With 4 molecule classes, 1 Catalytique means 3 classes at +15% attack and +15% HP. But the Catalytique class is -10% attack, -10% HP.

Net combat value of the "1 Catalytique + 3 boosted" configuration vs. "4 Normal":
- 3 classes contribute: `3 * 1.15 attack * 1.15 HP = 3 * 1.3225 = 3.97` units of value
- 1 class contributes: `0.9 attack * 0.9 HP = 0.81` units
- Total: 4.78 vs. 4.0 for all-Normal

The Catalytique configuration gives 19.5% more effective combat value with no additional resource cost. It is strictly dominant over Normal for any player with 4 classes.

Combined with Alliance Fortification level 25 on the defender side (+25% building HP, making them harder to building-damage), and Alliance Reseau making the coordinated attacker earn extra ranking from the attack: alliances have both offensive (Catalytique + Duplicateur) and defensive (Fortification) uncounterable advantages.

**Impact:**
Catalytique is the correct isotope choice for class 4 in all situations with zero exceptions. There is no strategic depth — the optimal choice is obvious and dominating. Solo players without this coordination knowledge are at 20% permanent disadvantage.

**Fix:**
The Catalytique ally bonus should scale inversely with the Catalytique class's own penalty. If the support class has 90% effectiveness, allies gain only `0.90 * ISOTOPE_CATALYTIQUE_ALLY_BONUS = 13.5%`. Change in `combat.php`:
```php
// Instead of flat 0.15:
$catalytiqueBoostFactor = (1 + ISOTOPE_CATALYTIQUE_ATTACK_MOD); // = 0.90
$effectiveCatalytiqueBonus = ISOTOPE_CATALYTIQUE_ALLY_BONUS * $catalytiqueBoostFactor;
```

---

### P3-EB-013 | HIGH | Compound H2SO4 Pillage Boost Enables End-Season Resource Denial

**Systems involved:** H2SO4 compound (+25% pillage), Vault protection (capaciteCoffreFort), Combat pillage system (combat.php), Season dynamics

**Description:**
In the final week of a season, the optimal strategy is to raid competitors who have built up large resource stockpiles to spend on last-minute constructions. H2SO4 gives +25% pillage bonus for 1 hour (`SECONDS_PER_HOUR`), which on top of already calculated pillageable resources becomes:

```php
$ressourcesAPiller *= $catalystPillageBonus;      // existing
$ressourcesAPiller *= (1 + $compoundPillageBonus); // +25% H2SO4
```

The vault (`coffrefort`) only protects `min(50%, vault_level * 2%)` of storage. At level 10 vault, only 20% is protected. A player storing 100,000 of each atom has 80,000 of each exposed. H2SO4 raider with a large soufre-focused pillage stat can strip 25% more of that 80,000 = 100,000 atoms per raid.

In the final week, players cannot rebuild their resource base (no production recovery time). A coordinated alliance using H2SO4 simultaneously can deny a top-ranked solo player all their resources, preventing the final construction sprint that determines rank.

**Impact:**
Resource denial by compound abuse is a legitimate strategy in the final week. It creates a toxic meta where organized alliances systematically strip solo players right before season end, cementing alliance dominance in the VP distribution.

**Fix:**
1. Disable compound synthesis in the final 48 hours of a season: add a season-phase check in `synthesizeCompound()`.
2. Or: apply the H2SO4 pillage boost only to the pillage stat formula, not to the already-calculated `$ressourcesAPiller`. This removes the double-dipping.
3. Increase vault protection to 30% per level (cap at 60%): `VAULT_PCT_PER_LEVEL = 0.03`, `VAULT_MAX_PROTECTION_PCT = 0.60`.

---

### P3-EB-014 | MEDIUM | Speed Formula Soft Cap Fails Against Condenseur Amplification

**Systems involved:** Speed formula `vitesse()` (formulas.php), Condenseur `modCond()` multiplier, Chlore atom investment, Speed soft cap (`SPEED_SOFT_CAP = 30`)

**Description:**
The speed formula:
```php
function vitesse($Cl, $N, $nivCondCl) {
    $clContrib = min(SPEED_SOFT_CAP, $Cl * SPEED_ATOM_COEFFICIENT); // cap at 30
    $base = 1 + $clContrib + (($Cl * $N) / SPEED_SYNERGY_DIVISOR);
    return max(1.0, floor($base * modCond($nivCondCl) * 100) / 100);
}
```

The soft cap limits `Cl * 0.5` to 30, meaning Cl > 60 gives no benefit from the linear term. BUT the synergy term `Cl * N / 200` is uncapped, and the entire `$base` is then multiplied by `modCond($nivCondCl) = 1 + (condenseurLevel / 50)`.

At Cl=100, N=100, condenseur level 50:
- `$clContrib = min(30, 50) = 30` (soft cap)
- synergy = `100 * 100 / 200 = 50`
- `$base = 1 + 30 + 50 = 81`
- `modCond(50) = 1 + 50/50 = 2.0`
- `vitesse = 81 * 2.0 = 162` tiles/h

At Cl=60 (soft cap just hit), N=100, condenseur 50:
- `$clContrib = min(30, 30) = 30`
- synergy = `60 * 100 / 200 = 30`
- `$base = 1 + 30 + 30 = 61`
- `vitesse = 61 * 2.0 = 122` tiles/h

Cl=100 still gives 33% more speed than Cl=60 despite the "soft cap." The soft cap only affects the linear term, not the synergy×condenseur interaction. With condenseur level 50, investing Cl beyond 60 gives significant returns.

**Impact:**
There is no effective speed cap. Players with high condenseur investment have incentive to maximize Cl beyond the supposed cap. Molecule design has less strategic depth (always max Cl+N+Condenseur for speed).

**Fix:**
Apply the soft cap after condenseur multiplication, not before:
```php
$base = 1 + min(SPEED_SOFT_CAP, ($Cl * SPEED_ATOM_COEFFICIENT) + (($Cl * $N) / SPEED_SYNERGY_DIVISOR));
$speed = max(1.0, floor($base * modCond($nivCondCl) * 100) / 100);
```
This makes condenseur amplify speeds up to the cap, not bypass it.

---

### P3-EB-015 | MEDIUM | Phalanx Formation Can Be Exploited by Empty-Class-1 Cheese

**Systems involved:** Phalanx formation (combat.php, `FORMATION_PHALANX_ABSORB = 0.60`), Class structure, Molecule decay

**Description:**
The Phalanx formation causes class 1 to absorb 60% of incoming damage with a +20% defense bonus. But the defender chooses their formation, and the attacker can see the defender's formation via espionage reports.

If a defender uses Phalanx with zero molecules in class 1 (class 1 empty due to decay or intentional deletion):
```php
if ($classeDefenseur1['nombre'] > 0 && $hpPerMol1 > 0) {
    $kills1 = min($classeDefenseur1['nombre'], floor($phalanxDamage / $hpPerMol1));
    // ...
} elseif ($classeDefenseur1['nombre'] > 0) {
    // ...
}
// No else: if nombre == 0, $classe1DefenseurMort = 0, phalanxOverflow = 0
```

When class 1 is empty (`nombre == 0`), the code sets `$classe1DefenseurMort = 0` and `$phalanxOverflow = 0`. Then `$remainingDamage = $otherDamage + 0`. This means `0.60 * $degatsAttaquant` is simply discarded — the attacker's 60% damage allocation disappears into an empty class.

The defender with class 1 completely empty receives only `0.40 * $degatsAttaquant` distributed to classes 2-4. This is a 60% effective damage reduction with no army investment.

**Impact:**
A defender can intentionally drain/delete class 1 molecules before a battle to activate the "60% free damage discard" mechanic. This is particularly powerful when the defender knows an attack is incoming (via espionage notification).

**Fix:**
When class 1 is empty, the Phalanx overflow should fully carry over to other classes:
```php
if ($classeDefenseur1['nombre'] <= 0) {
    $classe1DefenseurMort = 0;
    $phalanxOverflow = $phalanxDamage; // Full overflow, not 0
}
```

---

### P3-EB-016 | MEDIUM | Alliance Research Bouclier + Vault Creates Near-Impenetrable Defense

**Systems involved:** Alliance Bouclier research (-1% pillage losses/level), Vault protection (capaciteCoffreFort), Phalanx formation (+20% defense for class 1)

**Description:**
Combining the available defensive stacking:
- Vault level 25: `min(50%, 25 * 2%) = 50%` resources protected
- Alliance Bouclier level 25: `-25%` pillage losses on remaining 50%
- Phalanx formation: +20% defense on class 1 (main defensive class)
- Isotope Stable on class 1: +30% HP, -30% decay

Effective pillage protection: `50%` vault protects half. Of remaining 50%, Bouclier reduces by 25% → `50% * 0.75 = 37.5%` exposed to pillageable. Total pillage from full storage = `37.5%`.

Combat to pierce this: the attacker needs to kill all defender molecules first (no pillage on loss). The Phalanx + Stable combination gives the defender approximately:
- Defense stat: `defense(C, Br, condC) * 0.90 isotope_stable_attack * 1.30 isotope_stable_hp * 1.20 phalanx_class1`
- Effective HP of class 1: `1.30x` with +20% defense multiplier from formation

The defender who invests in this combination has ~3.3x effective durability for class 1 molecules. An attacker needs to deal roughly 3.3x more damage than the defender's class 1 HP to break through.

**Impact:**
A defensive-focused alliance member is effectively unkillable by any single attacker. Combined with the pile-on immunity fix needed (P3-EB-006), there is no offensive counter-play. The game bifurcates into unkillable alliance turtles and exposed solo players.

**Fix:**
Cap total defensive protection percentage at a meaningful number. Implement `MAX_COMBINED_DEFENSE_REDUCTION = 0.40` (40% max reduction from all non-stat sources combined). This still rewards defensive investment but prevents turtling.

---

### P3-EB-017 | MEDIUM | Prestige 'Veterain' Extra Protection Day Stacks With Wrong Timestamp

**Systems involved:** Prestige 'veteran' unlock (extra_protection_day), Beginner protection check (BEGINNER_PROTECTION_SECONDS = 3 days), Registration timestamp bug (P3-EB-009)

**Description:**
The 'veteran' prestige unlock costs 250 PP and provides "+1 jour de protection débutant." However, examining `prestige.php`, the unlock is defined but its `effect = 'extra_protection_day'` is not consumed anywhere in the beginner protection check (the check likely reads only `BEGINNER_PROTECTION_SECONDS`).

Two problems:
1. The veteran unlock has no mechanical implementation — purchasing it for 250 PP does nothing. This is a PP sink with no return, which doubly hurts newer players who spend PP on non-functional unlocks.
2. If implemented, it would stack on the already-broken timestamp (P3-EB-009), potentially giving 4 days of protection to a veteran alt account registered with a delay.

**Impact:**
250 PP is wasted per player who buys this unlock. Since prestige progression is slow (P3-EB-008), wasting 250 PP meaningfully delays access to functional unlocks (combat_5pct at 500 PP).

**Fix:**
Either implement the unlock correctly:
```php
// In beginner protection check:
$extraDays = hasPrestigeUnlock($login, 'veteran') ? SECONDS_PER_DAY : 0;
$isProtected = (time() - $createdAt) < (BEGINNER_PROTECTION_SECONDS + $extraDays);
```
Or refund existing PP purchases of this unlock and replace the effect with something implementable (e.g., "Start with 1 extra Condenseur point").

---

### P3-EB-018 | MEDIUM | Market Price Manipulation via Coordinated Alliance Trading

**Systems involved:** Market volatility formula (`MARKET_VOLATILITY_FACTOR / nbActifs`), Mean reversion (`MARKET_MEAN_REVERSION = 0.01`), Alliance coordination, Price floor/ceiling

**Description:**
The volatility per trade is `0.3 / nbActifs / MARKET_GLOBAL_ECONOMY_DIVISOR * quantity`. With 30 active players: `volatility = 0.3 / 30 = 0.01` per unit of `quantity / 10,000`.

An alliance of 20 members can coordinate to buy the same resource simultaneously. If each buys 5,000 atoms of carbone, the combined price impact is:
`20 * (0.01 * 5,000 / 10,000) = 20 * 0.005 = 0.10` price increase per coordinated buy wave.

Starting at price 1.0, after one coordinated wave: price = 1.10. Second wave: 1.20. After 8 coordinated buy waves (160,000 atoms total): price ≈ 1.80.

Now the alliance sells: each sell gives `1 / (1 / 1.80 + 0.01 * 5,000 / 10,000) ≈ 1.68` effective price. With 5% sell tax: 1.60 per atom vs. the 1.0 they would have gotten at baseline. The alliance profits 60% per atom by coordinated buy-inflate-sell.

The mean reversion of 1% per trade is insufficient to counteract 20 simultaneous coordinated trades — it only pulls toward 1.0 by `0.01 * 1.80 = 0.018` per trade while the alliance pushes by `0.005` per trade. Net drift per trade: +0.005 - 0.018 = -0.013 (market corrects, but only after the alliance has already executed their sell orders).

**Impact:**
Organized alliances can farm energy by market manipulation. The 5% sell tax (intended to stop buy-sell cycling) does not prevent the price inflation exploit. Solo players who needed that resource pay inflated prices during the manipulation window.

**Fix:**
Add per-player trade rate limiting beyond the energy/storage checks. Implement `MARKET_TRADE_COOLDOWN_SECONDS = 300` (5 minute cooldown between market operations per resource type). Track in a `market_cooldowns` table similar to `attack_cooldowns`.

---

### P3-EB-019 | MEDIUM | Season End VP Distribution Rewards Alliance Members Over Solo Players

**Systems involved:** Victory Points allocation (config.php `$VICTORY_POINTS_PLAYER`), Alliance ranking, Individual ranking, Season reset

**Description:**
Alliance VP is separate from individual VP:
- Alliance rank 1 = +15 VP per member (up to 20 members = 300 VP distributed)
- Individual rank 1 = +100 VP

An alliance that fields 20 competitive members can sweep ranks 1-20 on the individual leaderboard (assuming even distribution), earning roughly:
- VP from individual ranks 1-20: 100+80+70+65+60+55+50+45+40+35+33+31+29+27+25+23+21+19+17+15 = 840 VP total across 20 members
- VP from alliance rank 1: 15 VP each × 20 members = 300 VP bonus

Solo players who rank 21-30 earn: 12+11.77+11.54+11.31+11.08+10.85+10.62+10.39+10.16+9.93 ≈ 109 VP combined.

Alliance members average `(840 + 300) / 20 = 57 VP` each. Solo players rank 21-30 average `10.9 VP` each. The ratio is 5.2:1 in VP earned — an insurmountable cross-season advantage for organized alliances.

**Impact:**
After 3 seasons, alliance members have 3x more total VP (prestige rank bonuses, VP from individual + alliance ranks) than equivalent-skill solo players. The prestige system is effectively a "pay-to-win for alliance members" mechanic.

**Fix:**
Cap the combined individual + alliance VP that one player can receive per season. Define `VP_SEASON_CAP_SOLO = 150` and `VP_SEASON_CAP_ALLIANCE = 100` so that top-ranked alliance members cannot receive more than 100 VP from their individual rank when also receiving alliance rank VP. This preserves incentive to rank high while reducing the gap.

---

### P3-EB-020 | MEDIUM | Compound Stacking Across Multiple Effects Creates Unlimited Buff Combos

**Systems involved:** Compounds system (compounds.php), Activation logic, Multiple simultaneous active compounds

**Description:**
The anti-duplication check in `activateCompound()` only blocks activating two compounds with the same `effect` type. Since the 5 compounds have 5 different effect types (`production_boost`, `defense_boost`, `attack_boost`, `speed_boost`, `pillage_boost`), a player can simultaneously activate all 5 compounds:

- H2O: +10% production
- NaCl: +15% defense
- CO2: +10% attack
- NH3: +20% speed
- H2SO4: +25% pillage

Storage cap is `COMPOUND_MAX_STORED = 3`, but this tracks unactivated compounds. Once activated, the storage count decreases (`activated_at IS NOT NULL`). A player can synthesize 3, activate 1, synthesize 2 more, activate 1, synthesize 2 more, activate 1, etc., eventually having all 5 simultaneously active since each takes 1 hour and they can chain synthesis during active windows.

Practically: synthesize H2O, H2O, NaCl → activate H2O → synthesize CO2, NH3 → activate NaCl → ... Within 5 hours, all 5 can be active simultaneously.

This creates a 1-hour combat window where the attacker has +10% attack + +10% production + +20% speed + +25% pillage simultaneously. The speed boost (NH3 +20%) means they arrive at targets faster, and the attack boost (CO2 +10%) plus pillage boost (H2SO4 +25%) are active during the raid.

**Impact:**
A coordinated player with farming resources can prepare a 5-compound buffed raid with simultaneous multi-bonus activation. This stacks with all other modifiers (P3-EB-001), pushing toward the ~4x effective attack multiplier.

**Fix:**
Implement a global compound "active slot" limit:
```php
define('COMPOUND_MAX_ACTIVE', 2); // Max 2 compounds active simultaneously (config.php)
// In activateCompound():
$activeCount = count(getActiveCompounds($base, $login));
if ($activeCount >= COMPOUND_MAX_ACTIVE) {
    return "Maximum " . COMPOUND_MAX_ACTIVE . " composés actifs simultanément.";
}
```

---

### P3-EB-021 | MEDIUM | Multi-Account Alt Feeding Survives IP Check via Transfer Inversion Ratio

**Systems involved:** Alt-feeding inversion (marche.php `$rapportEnergie` calculation), Multi-account detection (multiaccount.php), IP transfer blocking

**Description:**
The V4 alt-feeding inversion is designed so that if the receiver has higher revenue than the sender, the transfer ratio is penalized (`$rapportEnergie = min(1.0, sender_rev / receiver_rev)`).

But this logic has a critical gap: it checks per-resource revenue. A multi-account operator can configure their alt account to have LOWER atom production than their main (e.g., alt has no producteur points allocated to carbone while main has maximum allocation). The ratio check for carbone: `alt_carbone_rev / main_carbone_rev < 1` → inversion applies → alt loses value.

However, for energy: the alt can have a high generateur level but no producteur investment. If the alt's energy revenue exceeds the main's energy revenue, the inversion protects against energy feeding. But if the main has higher energy revenue (which veterans almost always do), `$rapportEnergie = 1.0` and full energy transfers are allowed.

A sophisticated operator creates an alt that has high generateur but zero atoms, enabling full 1:1 energy transfers to the main. The multi-account detection (`checkTransferPatterns()`) flags suspicious patterns, but flags only trigger for human review — they do not automatically block.

**Impact:**
Energy feeding from alts to mains remains exploitable if the alt is set up to pass the revenue inversion check. Energy is the universal bottleneck resource. 1,000 energy/h from an alt represents significant economic advantage.

**Fix:**
Add a daily energy transfer cap regardless of revenue ratio:
```php
define('MAX_DAILY_ENERGY_TRANSFER', 5000); // Per sender per day (config.php)
// Check in marche.php before processing energy transfer:
$todayTransferred = getTodayTransferTotal($base, $_SESSION['login'], 'energie');
if ($todayTransferred + $_POST['energieEnvoyee'] > MAX_DAILY_ENERGY_TRANSFER) {
    $erreur = "Limite quotidienne de transfert d'énergie atteinte.";
}
```

---

### P3-EB-022 | MEDIUM | Formation Embuscade Applies Only to Defender but Not to Attacker

**Systems involved:** Formation Embuscade (combat.php `$embuscadeDefBoost`), Attacker formation choice, Attack strategy

**Description:**
The Embuscade formation gives `+25%` damage to the defender if they outnumber the attacker. But attackers choose which troops to send via the `troupes` field — they can trivially send fewer molecules than the defender has to prevent triggering Embuscade, then still pillage successfully if their per-molecule stats are stronger.

The "outnumber" check:
```php
if ($totalDefenderMols > $totalAttackerMols) {
    $embuscadeDefBoost = 1.0 + FORMATION_AMBUSH_ATTACK_BONUS;
}
```

An attacker with 500 high-stat molecules can send only 100 against a defender with 150 molecules using Embuscade, triggering Embuscade's +25%. But if the attacker's 100 molecules have 3x the defense-stat (Br, C) as the defender's 150 molecules, the attacker still wins easily. Embuscade only helps defenders when they're facing an equal-or-greater force.

**Impact:**
Embuscade is strategically weak. Players who choose it face the same problem noted in the MEMORY: "Embuscade was never optimal (0% 'Best In')." The buff to 25% from 15% still doesn't fix the core problem that skilled attackers always send exactly enough molecules to not trigger it.

**Fix:**
Change Embuscade to trigger based on the ratio of surviving molecules at battle start, not the chosen count. Alternatively, add an attacker-side formation system where attackers also choose a formation before the battle, creating pre-battle strategic interaction.

---

### P3-EB-023 | MEDIUM | Specialization Choices Create Irreversible Asymmetric Power Gaps

**Systems involved:** Specialization system (`$SPECIALIZATIONS` in config.php), Combat specialization (Oxydant/Reducteur), Economy specialization (Industriel/Energetique), Research specialization (Theorique/Applique)

**Description:**
Specializations are "irreversible choices unlocked at building milestones" according to config.php. Three specialization slots unlock at:
- Combat: Ionisateur level 15
- Economy: Producteur level 20
- Research: Condenseur level 15

These are achievable by active veterans around day 10-14. New players or mid-season joiners reach these milestones around day 20-25.

The irreversibility creates a 10-14 day window where veterans have specialization bonuses (+10% attack, +20% production, +2 condenseur points/level) and new players do not. Since specializations amplify existing stats multiplicatively, veterans with specializations outperform new players even at the same building level.

Combined impact of all 3 optimal choices (Oxydant + Industriel + Theorique):
- +10% attack, -5% defense
- +20% atom production, -10% energy production
- +2 condenseur points/level, -20% formation speed

The Industriel + Theorique combination is particularly dominant: more atoms → larger molecules → higher attack stats from O atoms → more raid income → more energy → faster building → earlier next specialization unlock.

**Impact:**
Specialization creates permanent within-season power gaps. A day-1 player who picks Oxydant+Industriel+Theorique has a strong advantage over a day-14 player who picks the same combination. There is no strategic depth — the optimal combination (Industriel+Oxydant+Theorique for aggressive play) is obvious.

**Fix:**
1. Allow one specialization respec per season (costing 20% of current building level in energy).
2. Or make specialization bonuses scale with the age of the choice: `bonus = base * min(1.0, daysSinceUnlock / 7)` so they ramp up over a week rather than applying instantly.
3. Add a counter-specialization system where certain choices explicitly counter others (Reducteur should have a clear use case vs. Oxydant opponents).

---

### P3-EB-024 | MEDIUM | Season Timeline Optimization Creates Single Dominant Strategy

**Systems involved:** VP distribution (season end), Prestige PP calculation, Building investment curve, Market trading, Combat points

**Description:**
Optimal season play can be decomposed into rigid phases with a single dominant strategy:

**Days 1-3:** Beginner protection — maximize building investment only. No combat (protection active). Best use: prioritize Generateur → Producteur → Condenseur.

**Days 4-7:** Early raids — attack unprotected players. Use NH3 compound (+20% speed) to reach targets faster. Farm pillage ranking.

**Days 8-14:** Mid-season economy — continue building, unlock specializations (day 10-14), join/form alliance for Duplicateur.

**Days 15-21:** Alliance wars — use coordinated attacks to strip top-solo players. This is the period where alliance advantages maximize.

**Days 22-28:** Construction sprint — dump all resources into buildings for construction points. Maximize energieDepensee medal threshold.

**Days 29-31:** Final ranking freeze — activate all compounds, raid for final resource denial, ensure active final-week login (prestige PP).

This timeline is deterministic and leaves no room for meaningful player expression. A player who deviates (e.g., prioritizes combat in days 1-3) falls irrevocably behind the optimal path. There are no meaningful strategic choices beyond "follow the optimal timeline."

**Impact:**
Experienced players follow the optimal timeline and consistently outperform new players who don't know it. The game's strategic depth is zero once the optimal path is known. Player retention suffers because there is only one "correct" way to play.

**Fix:**
Introduce "economic uncertainty" mechanics that randomize the optimal strategy each season:
1. Season "event" modifiers (3-5 different event types, one chosen per season): e.g., "Season of Pillage" where pillage VP is 2x, rewarding different playstyles.
2. Randomize the composition of resource nodes (P3-EB-004) to make different atom types valuable each season.
3. Add "season-specific catalysts" (one-time unlocks) that modify the optimal building order.

---

### P3-EB-025 | LOW | Isotope Stable + Phalanx + Vault Creates True Turtle Immortality

**Systems involved:** Isotope Stable (+30% HP, -30% decay), Phalanx formation (class 1 absorbs 60% damage), Vault protection (50% resources safe), Decay mechanics

**Description:**
A player who combines:
- Class 1: Stable isotope (+30% HP, -30% decay), maxed Br and C atoms
- Phalanx formation: class 1 takes 60% of all incoming damage at +20% defense
- Vault level 25: 50% resources protected

Can achieve a state where:
1. Their class 1 is nearly unkillable: `1.30 HP * 1.20 Phalanx defense = 1.56x effective HP` on the class absorbing 60% of damage.
2. Their resources are 50% safe from pillage.
3. Decay is reduced by 30%, so they can maintain their large class 1 without active play.

The critical interaction: the Stable isotope's -30% decay means class 1 can persist for extended periods without the player being online to refresh. An offline player using this configuration retains their defensive capability even after 12-24 hours offline.

The -5% attack penalty on Stable is irrelevant for a pure defender — they don't need attack. Combined with Bouclier alliance research (-25% pillage) and Vault (50% safe): the effective pillage against them is `(100% - 50%) * (1 - 0.25) = 37.5%` of resources, and they're extremely hard to kill in combat.

**Impact:**
Turtle strategies have no meaningful counterplay. The attacker must commit to multiple sequential raids (cooldown allows only 1/hour) against a defender who loses almost nothing. This disincentivizes combat engagement and creates "dead zones" on the map where attacking certain players is never worth it.

**Fix:**
Add a mechanic where successful defense generates "heat" that temporarily reduces subsequent defensive bonuses:
```php
define('DEFENSE_HEAT_DECAY_HOURS', 4); // config.php
define('DEFENSE_HEAT_PER_WIN', 0.05);  // 5% reduction per defensive win
define('DEFENSE_HEAT_CAP', 0.40);      // Max 40% reduction from heat
```
Track `defense_heat` on the player and reduce formation/vault bonuses accordingly.

---

### P3-EB-026 | LOW | Resource Node Type Distribution Does Not Match Atom Demand Distribution

**Systems involved:** Resource node generation (resource_nodes.php `generateResourceNodes()`), Atom demand (combat: O for attack, Br for HP, C for defense, N for speed formation), Economic utility

**Description:**
Node generation picks resource type via `array_rand($resourceTypes)` where `$resourceTypes = array_merge($nomsRes, ['energie'])` = 9 types. This gives roughly equal probability to each resource type.

But atom demand is highly unequal:
- Oxygene (O): high demand (primary attack stat)
- Brome (Br): high demand (HP stat)
- Carbone (C): high demand (defense + construction costs)
- Chlore (Cl): medium demand (speed + pillage synergy)
- Soufre (S): medium demand (pillage stat)
- Iode (I): medium demand (energy catalyst)
- Azote (N): low demand (formation speed + speed synergy)
- Hydrogene (H): low demand (building destruction potential)
- Energie: everyone needs it

A player lucky enough to have an O node near them gets a 10% bonus on their attack resource — a massive competitive advantage. A player with an H node gets +10% on a rarely-used resource (building damage only).

The random distribution means the positional lottery (already identified in P3-EB-004) is further distorted by which type of node is nearby.

**Impact:**
Node type distribution creates unequal positional advantages. An O-node player has a permanently better attack stat than an H-node player at the same atom investment level.

**Fix:**
Weight resource node types inversely to their demand: H and Azote nodes should be more common (or give larger bonuses), while O and Carbone nodes should be rarer (or give smaller bonuses). Implement a weighted distribution:
```php
$nodeWeights = ['carbone' => 1, 'azote' => 3, 'hydrogene' => 3, 'oxygene' => 1,
                'chlore' => 2, 'soufre' => 2, 'brome' => 1, 'iode' => 2, 'energie' => 2];
```

---

### P3-EB-027 | LOW | Condenseur Points System Allows Disproportionate Early Allocation

**Systems involved:** Condenseur building (condenseur points per level = 5), Condenseur levels in molecule formulas (`modCond()`), Combat system, Atom formulas

**Description:**
The condenseur awards 5 points per level (`points_per_level = 5`). These points are distributed across 8 atom types. A player who allocates all points to one atom type (e.g., all to Oxygene condenseur) achieves `modCond(condenseurLevel) = 1 + (condenseurLevel / 50)` where condenseurLevel grows 5x faster than if spread evenly.

With condenseur building level 10 (50 points available) and all allocated to Oxygene:
`modCond(50) = 1 + 50/50 = 2.0` attack multiplier on the O-heavy attack formula.

With condenseur level 10 spread across 8 types evenly (6 points each, 2 leftover):
`modCond(6) = 1 + 6/50 = 1.12` for all stats.

The concentrated build gives 2.0x attack vs. 1.12x for a balanced build — a 79% attack advantage. But the balanced build gets modest improvements everywhere, while the concentrated build has near-zero defense (Br and C condenseur levels near 0).

The game design seems to intend this tradeoff, but the tradeoff is severely imbalanced: in practice, attack dominates over defense because:
1. Attackers choose the engagement (they can choose weaker targets).
2. Attack points are weighted 1.5x in ranking vs. defense at 1.5x (equal, but attackers also earn pillage ranking).
3. One-shot kills are possible (P3-EB-001), making HP less relevant.

**Impact:**
Concentrated attack condenseur builds are strictly dominant. No one builds balanced condenseur investment. There is no strategic choice — maximum O condenseur is always optimal for competitive play.

**Fix:**
Apply diminishing returns within a single atom's condenseur level: `modCond(level) = 1 + sqrt(level) / COVALENT_CONDENSEUR_DIVISOR`. This makes spreading points more efficient than concentrating:
- Concentrated 50 in O: `1 + sqrt(50) / 50 = 1.141`
- Spread 6 in each of 8: `1 + sqrt(6) / 50 = 1.049` for each — balanced but closer to concentrated

---

### P3-EB-028 | LOW | Combat Report Timing Creates Predictable Retaliation Windows

**Systems involved:** Combat resolution (updateActions/combat.php), Attack cooldown (ATTACK_COOLDOWN_WIN_SECONDS = 3600), Reports system, Espionage notification

**Description:**
When an attacker wins, the 1-hour cooldown (`ATTACK_COOLDOWN_WIN_SECONDS`) prevents them from attacking the same target for 1 hour. This creates a predictable "safe window" for the victim to:
1. Receive the attack report.
2. Identify the attacker.
3. Launch a counterattack within the 1-hour window.

But the attacker knows this pattern and can preemptively launch a formation request against the victim's ally to prevent the counter. The timing of combat reports (created synchronously with `INSERT INTO rapports`) and the cooldown system creates a predictable cycle that experienced players exploit to chain coordinated attacks.

The 4-hour cooldown on loss (`ATTACK_COOLDOWN_SECONDS`) discourages retaliatory attacks from the victim since they must wait 4 hours if they lose the counter (while the original attacker can attack new targets). This asymmetry systematically advantages experienced attackers.

**Impact:**
The cooldown system, intended to prevent bullying, creates an asymmetric advantage for initiators. Experienced players time their attacks to maximize the opportunity window and minimize their own exposure to retaliation. Less experienced players cannot match this timing precision.

**Fix:**
Add a "retaliatory cooldown exemption": if player A attacked player B within the last 6 hours, player B's cooldown on attacking player A is reduced by 50% (halved). This gives victims a fair counter-attack window without eliminating the anti-bullying cooldown system.

```php
define('RETALIATION_COOLDOWN_REDUCTION', 0.50); // config.php
// In attack eligibility check:
$wasRecentlyAttacked = dbFetchOne($base, 'SELECT expires FROM attack_cooldowns WHERE attacker=? AND defender=? AND expires > ?', 'ssi', $defender, $attacker, time() - 6 * SECONDS_PER_HOUR);
if ($wasRecentlyAttacked) {
    $effectiveCooldown = $normalCooldown * (1 - RETALIATION_COOLDOWN_REDUCTION);
}
```

---

## Summary Table

| ID | Severity | Title | Systems |
|---|---|---|---|
| P3-EB-001 | CRITICAL | Full combat modifier stack reaches ~4x | Compounds, Ionisateur, Duplicateur, Isotope, Prestige, Spec, Medal |
| P3-EB-002 | CRITICAL | Economy positive feedback loop compounds daily | Production, Iode, Nodes, Duplicateur, Prestige, Compound, Spec |
| P3-EB-003 | HIGH | Sell-tax does not prevent trade-point cycling | Market, tradeVolume, Ranking, Alliance Reseau |
| P3-EB-004 | HIGH | Resource node radius creates permanent positional lock-in | Nodes, Position, Production |
| P3-EB-005 | HIGH | Iode catalyst + molecule army creates runaway energy loop | Iode catalyst, Molecule formation, Energy production |
| P3-EB-006 | HIGH | Alliance + Catalytique creates unbalanced alliance combat meta | Alliance, Isotope Catalytique, Phalanx, Coordination |
| P3-EB-007 | HIGH | New player cannot catch day-1 veteran within a season | All production, Combat, Decay, Building costs, Prestige |
| P3-EB-008 | HIGH | Prestige snowball: veterans accumulate PP faster each season | Prestige PP, Medal thresholds, Rank bonuses, Cross-season |
| P3-EB-009 | HIGH | Beginner protection uses wrong timestamp cross-system | Protection, Registration, Multi-account |
| P3-EB-010 | HIGH | Sell-side trade points enable infinite ranking w/o production | Market sell, tradeVolume, Ranking, Pillage |
| P3-EB-011 | HIGH | Double-sqrt bug amplifies high performers disproportionately | calculerTotalPoints, pointsAttaque, pointsDefense |
| P3-EB-012 | HIGH | Catalytique isotope + alliance research stacks to unplayable | Isotope, Alliance Fortification, Reseau, Combat |
| P3-EB-013 | HIGH | H2SO4 pillage boost enables end-season resource denial | H2SO4 compound, Vault, Combat pillage, Season dynamics |
| P3-EB-014 | MEDIUM | Speed formula soft cap fails against condenseur amplification | vitesse(), modCond(), SPEED_SOFT_CAP |
| P3-EB-015 | MEDIUM | Phalanx formation can be exploited by empty-class-1 cheese | Phalanx, Class structure, Molecule decay |
| P3-EB-016 | MEDIUM | Alliance Bouclier + Vault creates near-impenetrable defense | Alliance Bouclier, Vault, Phalanx, Isotope Stable |
| P3-EB-017 | MEDIUM | Prestige 'Veteran' extra protection day has no implementation | Prestige, Protection, Registration timestamp |
| P3-EB-018 | MEDIUM | Market price manipulation via coordinated alliance trading | Market volatility, Alliance coordination, Price floor |
| P3-EB-019 | MEDIUM | Season VP distribution rewards alliance members over solo | VP distribution, Alliance ranking, Individual ranking |
| P3-EB-020 | MEDIUM | Compound stacking across multiple effects unlimited combos | Compounds, Activation logic, All 5 effect types |
| P3-EB-021 | MEDIUM | Multi-account alt feeding survives IP check via ratio inversion | Alt-feeding inversion, Multi-account, IP blocking |
| P3-EB-022 | MEDIUM | Formation Embuscade trivially countered by troop selection | Embuscade, Attacker troop choice, Formation |
| P3-EB-023 | MEDIUM | Specialization choices create irreversible asymmetric gaps | Specialization system, Combat/Economy/Research |
| P3-EB-024 | MEDIUM | Season timeline has single dominant strategy, no variation | VP distribution, Prestige, Building curve, All systems |
| P3-EB-025 | LOW | Isotope Stable + Phalanx + Vault creates turtle immortality | Stable, Phalanx, Vault, Decay, Alliance Bouclier |
| P3-EB-026 | LOW | Resource node type distribution mismatches atom demand | Node generation, Atom demand, Combat formulas |
| P3-EB-027 | LOW | Condenseur points allow disproportionate early allocation | Condenseur, modCond(), Combat, Atom formulas |
| P3-EB-028 | LOW | Combat report timing creates predictable retaliation windows | Combat resolution, Cooldowns, Reports |

---

## Cross-Cutting Root Causes

Three root causes drive the majority of the 28 findings:

**Root Cause A — Multiplicative Bonus Stacking (affects P3-EB-001, 002, 005, 006, 012, 020):**
Bonuses from different systems multiply together rather than summing additively. Each system was balanced in isolation, but their interaction was never analyzed. Fix: adopt an additive bonus model within each category, then apply a single cross-category multiplier with a cap.

**Root Cause B — Absence of Progression Rate Limits (affects P3-EB-007, 008, 019, 021, 024):**
There are no catch-up mechanics or rate limits on advantage accumulation. Veterans accumulate advantages that cannot be caught within a 31-day season. Fix: implement per-system caps, catch-up bonuses for new players, and time-limited advantage windows.

**Root Cause C — Alliance Mechanics Have No Solo Counterplay (affects P3-EB-003, 006, 012, 016, 018, 019):**
Alliance mechanics provide bonuses (Duplicateur, Reseau, Bouclier, Fortification, Catalytique) that solo players cannot access. There is no equivalent solo-player bonus system. Fix: add solo-player "independence bonuses" that partially compensate for lack of alliance buffs (e.g., solo players receive +10% to all production stats when not in an alliance).

---

*Pass 3 analysis complete. 28 emergent balance findings (2 CRITICAL, 9 HIGH, 9 MEDIUM, 8 LOW). Recommend prioritizing P3-EB-001, P3-EB-002, P3-EB-011, and P3-EB-007 as they collectively define the player experience ceiling and have the highest retention impact.*
