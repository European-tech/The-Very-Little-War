# Game Balance Audit Report -- Round 1

**Auditor:** Game Balance Analysis Agent
**Date:** 2026-03-03
**Scope:** All formulas, constants, and game economy mechanics
**Files audited:** config.php, formulas.php, combat.php, prestige.php, catalyst.php, game_resources.php, game_actions.php, marche.php, armee.php, constructions.php, sinstruire.php, medailles.php, player.php, attaquer.php

---

## Executive Summary

The game has a well-structured constant system centralized in `config.php`. However, deep formula analysis reveals **7 CRITICAL**, **14 HIGH**, **11 MEDIUM**, and **10 LOW** balance issues across atom scaling, formation viability, economy, prestige, snowball effects, and atom type parity. The dominant strategy is pure-oxygen attack stacking with chlorine speed, while multiple systems (iode energy, azote formation, Embuscade formation, Catalytique isotope) are either underpowered or have degenerate edge cases.

---

## CRITICAL Findings

### [BAL-R1-001] CRITICAL config.php:63-81 -- Quadratic stat formulas create exponential snowball with condenseur levels

The core stat formulas use the pattern `(1 + (coef * atoms)^2 + atoms) * (1 + niveau / 50)`.

For attack: `(1 + (0.1 * O)^2 + O) * (1 + niveau / 50)`

At max atoms (200 O) with condenseur level 50 for oxygen:
- Base: `1 + (0.1 * 200)^2 + 200 = 1 + 400 + 200 = 601`
- Level multiplier: `(1 + 50/50) = 2.0`
- Result: `1202 attack per molecule`

At 50 O with condenseur level 10:
- Base: `1 + (0.1 * 50)^2 + 50 = 1 + 25 + 50 = 76`
- Level multiplier: `(1 + 10/50) = 1.2`
- Result: `91 attack per molecule`

**The power ratio is 13.2:1.** A player at max condenseur with 200 O molecules has 13x the attack of a player with 50 O and condenseur 10. This is compounded by the ionisateur building bonus (+2% per level), duplicateur, medals, prestige, and isotope bonuses, creating a multiplicative snowball where a late-game player can be 50-100x stronger than a mid-game player. The quadratic term `(0.1 * atoms)^2` dominates once atoms > 10 and makes the first 50 atoms nearly meaningless compared to the last 50 (going from 150 to 200 O adds `(20^2 - 15^2) = 175` attack, while going from 0 to 50 adds only 25).

**Impact:** No meaningful catch-up is possible once a player falls behind in condenseur levels. Late joiners are permanently non-competitive within a season.

---

### [BAL-R1-002] CRITICAL config.php:78-80 -- Pillage formula has divided linear term making soufre extremely weak per atom

Pillage formula: `((0.1 * S)^2 + S/3) * (1 + niveau / 50)`

The linear term is `S/3` instead of `S` (used in attack/defense/HP). At 200 soufre, condenseur 50:
- `(20^2 + 200/3) * 2.0 = (400 + 66.7) * 2 = 933`

Compare to attack at 200 oxygene:
- `(1 + 20^2 + 200) * 2.0 = 601 * 2 = 1202`

More critically, the vault (coffrefort) at level 10 protects 1000 of each resource. If a player has 8 resource types, that is 8000 atoms totally immune from pillage. A single molecule with 200 soufre at condenseur 50 pillages only 933 atoms. With 100 molecules surviving, that is 93,300 pillage capacity -- which sounds large, but a well-defended player with coffrefort level 10 and modest resources can lose almost nothing. Meanwhile the attacker spent enormous resources building soufre-focused molecules that are weak in combat.

**Impact:** Soufre is a trap stat. The S/3 divisor makes the linear scaling 3x worse than other atoms. Players who invest in pillage molecules sacrifice combat effectiveness for a reward that the coffrefort building trivially negates.

---

### [BAL-R1-003] CRITICAL formulas.php:155-158 -- Speed formula gives diminishing returns making chlore a dominant must-have atom

Speed formula: `floor((1 + 0.5 * Cl) * (1 + niveau / 50) * 100) / 100`

This is LINEAR, unlike the quadratic attack/defense/HP formulas. At 200 Cl, condenseur 50:
- `(1 + 0.5 * 200) * 2.0 = 101 * 2.0 = 202 cases/hour`

At 50 Cl, condenseur 10:
- `(1 + 0.5 * 50) * 1.2 = 26 * 1.2 = 31.2 cases/hour`

The travel time difference is extreme: a 202 speed army arrives in `distance/202 hours`, while a 31.2 speed army takes `distance/31.2 hours` -- 6.5x longer. Since molecules decay during travel (via `coefDisparition` applied per-second), slow armies lose significantly more molecules in transit than fast ones.

But the real problem is that **every molecule class uses the slowest class's speed for the entire army** (line 131 of attaquer.php: `$tempsTrajet = max($tempsTrajet, ...)`). This means one slow class with low chlore drags the entire army. The optimal strategy becomes putting maximum chlore in every class, which competes directly with combat stats.

**Impact:** Chlore is a tax -- every molecule needs significant chlore investment or it becomes a liability. This narrows viable molecule designs significantly.

---

### [BAL-R1-004] CRITICAL combat.php:206 -- Attacker damage uses defender's isotope attack modifier for defense calculation

In combat.php line 207:
```php
$defBonusForClass = $defReactionDefenseBonus * $defIsotopeAttackMod[$c];
```

The defender's damage output (defense score) is multiplied by `$defIsotopeAttackMod` -- the defender's **attack** isotope modifier, not their defense modifier. For Reactif isotope (glass cannon), `$defIsotopeAttackMod = 1.20` (+20% attack), which means Reactif defenders deal 20% more damage to attackers. For Stable isotope (tank), `$defIsotopeAttackMod = 0.90` (-10% attack), meaning Stable defenders deal 10% LESS damage to attackers.

This is counterintuitive but actually correct from a game theory perspective -- it means Reactif molecules deal more damage when defending, which fits their glass cannon identity. However, there is no corresponding `$defIsotopeDefenseMod` being applied. The defense() formula from formulas.php already computes a defense score, and the isotope's defense identity (Stable gets +20% HP, not +defense stat) is applied only via `$defIsotopeHpMod` on the HP side.

**The actual bug:** There is no defense-specific isotope stat modifier at all. The isotope system modifies attack and HP but never the base defense stat. Stable isotope gives -10% attack and +20% HP, but zero bonus to the carbone-based defense formula. This means Stable isotope is strictly about surviving longer, not about defending better in terms of damage output, which undermines its role as a "tank/defender."

**Impact:** Stable isotope is weaker than it appears. Its +20% HP helps it survive, but it deals 10% less damage to attackers. Reactif isotope paradoxically makes a better defender in terms of damage output.

---

### [BAL-R1-005] CRITICAL config.php:83-86 -- Iode energy production is negligibly weak, making iode atoms a dead stat

Iode energy formula: `round(0.10 * iode * (1 + niveau / 50))`

At 200 iode, condenseur 50: `0.10 * 200 * 2.0 = 40 energy per molecule per... tick?`

The comment says "buffed from 0.01 to 0.05 to 0.10" but let us compare this to the generateur building. At generateur level 20:
- `75 * 20 = 1500 energy/hour`

For iode to match generateur level 20, you would need:
- If iode produces per second: `40/3600 * count` molecules... even 1000 molecules of 200 iode gives `40 * 1000 = 40000` per tick. But the actual application in game_resources.php line 34 shows `productionEnergieMolecule($molecules['iode'], $niveauiode) * $molecules['nombre']` is added directly to the hourly energy.

So 1000 molecules of 200 iode at condenseur 50 gives: `40 * 1000 = 40,000 energy/hour`. That is substantial. But **getting 1000 molecules requires enormous investment** -- each molecule of 200 iode costs 200 iode atoms per formation, you need atom production infrastructure, and those 200 iode atoms could have been 200 oxygene (attack) or 200 carbone (defense).

At a more realistic 100 molecules of 100 iode, condenseur 20:
- `0.10 * 100 * (1 + 20/50) = 0.10 * 100 * 1.4 = 14 energy per molecule`
- `14 * 100 = 1400 energy/hour` -- equivalent to generateur level ~18

This is competitive for late game but the opportunity cost is devastating: those 100 molecules have zero combat value. A player with 100 combat molecules instead would dominate militarily.

**Impact:** Iode is a noob trap. It appears to provide "free energy" but requires an army slot for molecules with no combat stats. Only viable if a player completely abandons military, which makes them vulnerable to attack.

---

### [BAL-R1-006] CRITICAL config.php:101-104 -- Decay formula is exponential-on-exponential, making large molecules vanish impossibly fast

Decay coefficient: `pow(pow(0.99, pow(1 + nbAtomes/150, 2) / 25000), seconds)`

Let us evaluate for a 200-atom molecule (moderate size -- 25 per type):
- Inner exponent: `pow(1 + 200/150, 2) / 25000 = pow(2.33, 2) / 25000 = 5.44 / 25000 = 0.000218`
- Base decay: `pow(0.99, 0.000218) = 0.999997812` per second
- After 1 hour (3600s): `pow(0.999997812, 3600) = 0.9921` -- loses 0.79% per hour
- Half-life: ~87.7 hours (3.65 days)

For a max-atom molecule (1600 atoms total -- 200 per type):
- Inner exponent: `pow(1 + 1600/150, 2) / 25000 = pow(11.67, 2) / 25000 = 136.1 / 25000 = 0.005444`
- Base decay: `pow(0.99, 0.005444) = 0.999945` per second
- After 1 hour: `pow(0.999945, 3600) = 0.8207` -- loses **17.9% per hour**
- Half-life: ~3.5 hours

**A max-atom molecule has a half-life of 3.5 hours.** After 24 hours, only `pow(0.8207, 24) = 0.75%` remain. The stabilisateur at level 20 provides `20 * 1.5% = 30%` reduction to the decay exponent, extending this to maybe 5-6 hours half-life. Even with Stable isotope (-30% further), the half-life is perhaps 8-10 hours.

This means **large molecules are unviable for offense** because they decay during the travel time. A player sending a 1600-atom army across a large map (say 10 hours travel) will arrive with almost nothing.

The practical result: optimal molecules use far fewer total atoms and focus atoms in 2-3 useful stats (oxygene/carbone + chlore + brome), leaving other atom slots empty. This makes half the atom types irrelevant.

**Impact:** The decay formula hard-punishes molecule diversity. The competitive meta is small, focused molecules (200 O, 200 Cl, 100 Br, rest empty = 500 total) rather than well-rounded molecules using all 8 atoms.

---

### [BAL-R1-007] CRITICAL combat.php:438-468 -- Building destruction targeting is pure RNG with no strategic counterplay

When the attacker wins, building damage is distributed by `rand(1, 4)` per attacking class (line 450). Each class independently rolls to hit one of: generateur, champdeforce, producteur, or depot. The exception is if champdeforce is the highest-level building, in which case ALL damage hits it (lines 438-446).

Problems:
1. **Pure RNG determines which buildings are destroyed.** Two identical attacks can have vastly different outcomes: one might level the generateur, another might only scratch the depot.
2. **The champdeforce exception is binary.** If champdeforce is even 1 level higher than all other buildings, it absorbs 100% of damage. If it is tied, it gets 25% like everything else. This creates a cliff where players must keep champdeforce strictly highest or it provides no soak benefit at all.
3. **No player agency.** The attacker cannot choose which buildings to target. The defender cannot choose which buildings to protect (beyond the champdeforce level trick).
4. **Destruction cascade:** Destroying a generateur or producteur reduces income, which slows rebuilding, which makes the next attack more devastating. This is a pure snowball mechanic with no catch-up.

**Impact:** A single lost battle can cascade into a death spiral of reduced production and subsequent losses. Combined with the lack of catch-up mechanics, this makes the game extremely punishing for players who fall behind.

---

## HIGH Findings

### [BAL-R1-008] HIGH config.php:62-68 -- Attack and defense formulas are identical, making combat purely a numbers game

Attack: `(1 + (0.1 * O)^2 + O) * (1 + niveau / 50)` using oxygene
Defense: `(1 + (0.1 * C)^2 + C) * (1 + niveau / 50)` using carbone

These are the exact same formula with different atom inputs. Since the stat is determined solely by atoms and condenseur level, combat is a pure math comparison: the side with more total (attack_score * count) wins. There is no rock-paper-scissors, no counter-play, no asymmetry between attacking and defending.

The only differentiators are:
- Ionisateur (+2% attack per level) vs. Champdeforce (+2% defense per level) -- symmetric
- Isotopes -- but these are per-class, not per-matchup
- Formations -- only affect damage distribution, not the total

**Impact:** Optimal play is always to maximize oxygene for attack, since attackers choose their targets while defenders cannot. This creates an offense-dominant meta where the best defense is a better offense.

---

### [BAL-R1-009] HIGH config.php:273-278 -- Formation Embuscade (Ambush) has an exploitable condition that favors turtles

Embuscade gives +25% attack bonus to the defender **only if** they have more total molecules than the attacker. This means:
1. A defender with 10,000 weak molecules beats a defender with 100 strong molecules for triggering the bonus.
2. An attacker can scout via espionage and simply send more molecules to negate the formation.
3. The bonus is binary: +25% or +0%. There is no partial bonus for nearly matching counts.

The condition incentivizes mass-producing cheap, weak molecules purely to trigger the formation bonus, which is a degenerate strategy.

**Impact:** Embuscade is either trivially countered (send more molecules) or encourages spam production of low-quality molecules.

---

### [BAL-R1-010] HIGH config.php:288-301 -- Isotope Catalytique is strictly worse than Normal in 1v1 combat scenarios

Catalytique: -10% attack, -10% HP, but +15% to all stats of OTHER classes.

The problem: a player must sacrifice one of their 4 molecule classes to boost the other 3 by 15%. The math:
- Without Catalytique: 4 classes at 100% = 400% total effectiveness
- With Catalytique: 3 classes at 115% + 1 class at 90% attack/90% HP = 345% + 81% = 426% adjusted

This is only a 6.5% net improvement, and it requires dedicating an entire class slot. If that class slot had instead been a fourth Normal class, it would contribute 100% effectiveness vs. the 81% of Catalytique. The net gain from Catalytique is thus only about `(426% - 400%) / 400% = 6.5%`.

But worse: the Catalytique class itself is weak in combat. If the opponent targets it specifically (which proportional damage distribution does not allow, but formation choices could), it dies fast. And the bonus only applies in battles where all 4 classes are deployed -- if you only send 2 classes on a raid, the Catalytique class staying home provides zero benefit.

**Impact:** Catalytique is mathematically marginal (6.5% net gain) for a system that is supposed to be a meaningful strategic choice. Most players will choose Reactif (pure damage) or Stable (survivability) instead.

---

### [BAL-R1-011] HIGH config.php:239 -- Attack energy cost is a flat 0.15 per atom per molecule, scaling linearly with army size

Attack cost: `0.15 * (1 - terreur_medal_bonus/100) * nbAtomes * count_per_class`

For a 500-atom molecule, 1000 molecules per class, 4 classes:
- `0.15 * 500 * 1000 * 4 = 300,000 energy`

With generateur level 40 producing `75 * 40 = 3000 energy/hour`, it takes 100 hours to accumulate this energy. But with a storage cap of `500 * depot_level`, you cannot even store 300,000 energy unless your depot is level 600+.

At depot level 50 (storage 25,000), you can only attack with armies costing up to 25,000 energy. This means `25000 / (0.15 * 500) = 333 molecules max` across all classes. This is a severe throttle on large armies.

However, the Terreur medal at Diamond Rouge tier gives 50% cost reduction, making it `0.075 * nbAtomes`. This halves the cost, but only for players who have already launched 1000+ attacks. Veterans pay half the attack cost of new players.

**Impact:** Attack energy cost creates a hard ceiling on army size that disproportionately punishes new/small players while veterans with Terreur medals attack at half cost.

---

### [BAL-R1-012] HIGH config.php:504-505 -- Medal bonus progression is exponential: 1%, 3%, 6%, 10%, 15%, 20%, 30%, 50%

The medal bonuses are: `[1, 3, 6, 10, 15, 20, 30, 50]`

At Diamond Rouge (tier 8), a player gets +50% to the relevant stat. For attack medals, that means:
- Attack formula includes `* (1 + bonus/100)` where bonus = 50
- Result: `* 1.50` -- a 50% multiplicative bonus to ALL attack

Combined with ionisateur level 30 (+60%), duplicateur level 25 (+25%), prestige (+5%), and Reactif isotope (+20%), a veteran player's attack multiplier is:
- `1.50 * 1.60 * 1.25 * 1.05 * 1.20 = 3.78x`

A new player with zero medals, no alliance, no prestige:
- `1.00 * 1.00 * 1.00 * 1.00 * 1.00 = 1.00x`

**The multiplicative gap is 3.78:1 before even counting atom counts and condenseur levels.** With the quadratic atom formula creating an additional 10-13x gap, the total combat power difference between a veteran and a new player can easily exceed 40:1.

**Impact:** Medal bonuses compound the snowball effect to create an insurmountable power gap between veterans and new players within a single season.

---

### [BAL-R1-013] HIGH config.php:516-544 -- Medal thresholds span 3+ orders of magnitude, with Diamond Rouge unreachable for most

Attack medal thresholds: `[100, 1000, 5000, 20000, 100000, 500000, 2000000, 10000000]`

Going from Gold (5000 points) to Emeraude (20000) requires 4x more points. Going from Rubis (500000) to Diamant (2000000) requires 4x more. The total span is 100,000x from Bronze to Diamond Rouge.

Since combat points per battle are capped at 20 (`COMBAT_POINTS_MAX_PER_BATTLE`), and `pointsAttaque = round(5.0 * sqrt(rawPoints))`, the maximum attack points per battle is `round(5.0 * sqrt(20)) = 22`. To reach Diamond Rouge (10,000,000 attack points) at 22 points per battle, a player needs **454,545 battles**. Even at one battle per hour, that is 51 years.

The practical maximum within a monthly season is perhaps 720 battles (one per hour for 30 days), yielding `720 * 22 = 15,840 points` -- barely Gold tier. The upper tiers (Saphir through Diamond Rouge) are only reachable by accumulating across many seasons, creating a permanent veteran advantage.

**Impact:** Medal tiers above Gold are inaccessible within a single season, making them pure veteran rewards with no catch-up possibility.

---

### [BAL-R1-014] HIGH config.php:375-380 -- Duplicateur research compounds multiplicatively with other bonuses with no cap

Duplicateur gives `level / 100` bonus (1% per level). At max level 25, that is +25% to both resource production AND combat stats. The alliance research has no individual per-session contribution cap -- a large, active alliance can rush duplicateur to 25 quickly while solo players or small alliances get nothing.

The duplicateur also directly multiplies combat damage in combat.php lines 46-57:
```php
$bonusDuplicateurAttaque = 1 + ($duplicateurAttaque['duplicateur'] / 100);
```

This bonus applies multiplicatively on top of medals, ionisateur, prestige, isotopes, and reactions. A large alliance with duplicateur 25 gives every member +25% to everything, creating a snowball where the strongest alliance gets stronger while weaker alliances cannot catch up.

**Impact:** Alliance duplicateur creates alliance-level snowball with no catch-up mechanism.

---

### [BAL-R1-015] HIGH config.php:391-437 -- Alliance research "Reseau" boosts trade points by 5% per level, feeding into a self-amplifying economy loop

The Reseau research gives +5% trade points per level (up to 25 levels = +125% trade points). Trade points contribute to totalPoints which determines rankings and victory points. A large alliance maxing Reseau means every member earns 2.25x more trade points from the same market activity.

Since market activity also generates points that fuel alliance research funding (through better rankings), this creates a positive feedback loop: more Reseau -> more trade points -> higher rankings -> more alliance income -> more research -> more Reseau.

**Impact:** Self-amplifying economy loop that advantages established alliances.

---

### [BAL-R1-016] HIGH formulas.php:165-172 -- Formation time formula creates extreme time costs for large/diverse molecules

Formation time: `ceil(ntotal / (1 + pow(0.09 * azote, 1.09)) / (1 + niveau / 20) / bonusLieur / catalystBonus / allianceBonus * 100) / 100`

For a 1000-atom molecule (125 per type), 50 azote, condenseur level 20, lieur level 15:
- Azote reduction: `1 + pow(0.09 * 50, 1.09) = 1 + pow(4.5, 1.09) = 1 + 5.24 = 6.24`
- Level reduction: `1 + 20/20 = 2.0`
- Lieur reduction: `pow(1.07, 15) = 2.76`
- Base time: `1000 / 6.24 / 2.0 / 2.76 = 29.0 seconds per molecule`
- For 500 molecules: `29.0 * 500 = 14,500 seconds = 4.03 hours`

For a 200-atom focused molecule (200 O, rest 0), same player:
- `200 / 6.24 / 2.0 / 2.76 = 5.8 seconds per molecule`
- For 500 molecules: `2,900 seconds = 48 minutes`

The 5x difference in formation time between diverse and focused molecules further incentivizes the "max one stat" strategy. Players should make 200 O molecules and forget other atoms.

**Impact:** Formation time heavily penalizes molecule diversity, reinforcing the dominant strategy of single-stat-focused molecules.

---

### [BAL-R1-017] HIGH combat.php:263-289 -- Formation Dispersee splits damage equally among active classes, punishing unequal armies

Dispersee divides damage equally: if you have 3 classes with molecules, each takes 33.3% of incoming damage regardless of their HP. If class 1 has 10,000 HP and class 3 has 100 HP, class 3 still takes 33.3% of damage and is instantly wiped out.

The proportional HP-based distribution (Embuscade/default else branch, lines 286-289) is only used as the fallback for non-Dispersee/non-Phalange formations. Dispersee is the default, meaning new players get the worst damage distribution.

**Impact:** Default formation punishes army diversity. Players learn to make all classes roughly equal or switch to Embuscade/Phalange.

---

### [BAL-R1-018] HIGH config.php:450-470 -- Victory point allocation at ranks 51-100 is near-zero, giving almost no incentive to mid-tier players

Ranks 51-100 formula: `max(1, floor(3 - (rank - 50) * 0.04))`

At rank 51: `floor(3 - 1 * 0.04) = floor(2.96) = 2 VP`
At rank 75: `floor(3 - 25 * 0.04) = floor(2.0) = 2 VP`
At rank 100: `floor(3 - 50 * 0.04) = floor(1.0) = 1 VP`

The difference between rank 51 and rank 100 is only 1 VP. Meanwhile rank 1 gets 100 VP. The top 3 players receive `100 + 80 + 70 = 250 VP` out of a much smaller total pie, creating extreme top-heaviness.

**Impact:** Players outside the top 20 have virtually no VP incentive to compete, leading to player disengagement.

---

### [BAL-R1-019] HIGH marche.php:197 -- Market buy price impact uses additive formula while sell uses harmonic, creating asymmetric volatility

Buy price update (line 197):
```php
$ajout = $tabCours[$num] + $volatilite * amount / $placeDepot;
```

Sell price update (line 310):
```php
$ajout = 1 / (1 / $tabCours[$num] + $volatilite * amount / $placeDepot);
```

The buy formula is additive (price goes up linearly with volume), while the sell formula is harmonic (price goes down hyperbolically). This means:
- Buying N atoms raises the price by `volatility * N / storage`
- Selling N atoms lowers the price less than buying raises it

With mean-reversion at only 1%, large purchases create price spikes that persist for many transactions. This allows market manipulation: buy a resource to spike its price, then sell at the inflated price (minus 5% tax). The profit is `(spiked_price - base_price) * amount * 0.95 - base_price * amount`.

**Impact:** Asymmetric price movement enables market manipulation by wealthy players who can move prices with large orders.

---

### [BAL-R1-020] HIGH config.php:267 -- Attack cooldown (4h) only triggers on loss/draw, not on win, enabling attack-chain bullying

`ATTACK_COOLDOWN_SECONDS = 4 * 3600` (4 hours), but it only sets on `$gagnant != 2` (combat.php line 336). A winning attacker can immediately attack the same target again. Combined with building destruction, this means a dominant player can repeatedly attack a weaker player, destroying buildings each time, until the defender has nothing left.

The 5-day beginner protection helps new accounts but does nothing for mid-game players who lose a key battle.

**Impact:** Winning attackers can chain attacks to completely destroy a weaker player with no cooldown.

---

### [BAL-R1-021] HIGH config.php:228-230 -- Coffrefort (vault) has no HP and cannot be targeted by destruction, making it OP once built

The vault building is not in the list of damageable buildings (combat.php uses `rand(1,4)` targeting generateur, champdeforce, producteur, depot). The vault has no HP bar and cannot be destroyed.

Once a player builds coffrefort to high levels, their resources are permanently protected. At coffrefort level 50, `50 * 100 = 5000` of each resource is immune to pillage. Since there are 8 resource types, that is 40,000 atoms permanently safe. This makes the pillage system largely irrelevant against established players.

**Impact:** Indestructible vault makes pillage a newbie-only mechanic; established players are immune.

---

## MEDIUM Findings

### [BAL-R1-022] MEDIUM config.php:88-90 -- Speed (chlore) formula is linear while all combat stats are quadratic, creating a stat hierarchy

Chlore uses `(1 + 0.5 * Cl) * level_mult` -- no quadratic term. This means chlore scales linearly while O/C/Br/H/S scale quadratically. At high atom counts, 1 atom of oxygene contributes far more combat value than 1 atom of chlore contributes speed value.

However, because speed is essential for arrival time (and thus decay during travel), chlore cannot be ignored. This creates an awkward balance where chlore is both underpowered per-atom AND mandatory.

**Impact:** Chlore is a tax with no quadratic payoff, creating a feel-bad investment.

---

### [BAL-R1-023] MEDIUM config.php:92-95 -- Azote (formation time) formula has a power exponent of 1.09, making investment barely super-linear

Formation azote factor: `pow(0.09 * azote, 1.09)`

The 1.09 exponent means azote scaling is barely above linear. At 200 azote:
- `pow(0.09 * 200, 1.09) = pow(18, 1.09) = 22.8`
- Compare to linear: `18`
- Only 27% better than linear

Meanwhile, the `ntotal` in the numerator means molecule size has a 1:1 effect on formation time. Adding more atoms to speed up formation barely helps compared to simply reducing total atom count.

**Impact:** Azote investment provides diminishing returns; better to reduce molecule size than to add azote atoms.

---

### [BAL-R1-024] MEDIUM config.php:74-76 -- Destruction (hydrogene) formula lacks the +1 base term, making zero hydrogen literally zero destruction

Destruction: `((0.075 * H)^2 + H) * level_mult`

Note the formula starts at 0 when H=0, unlike attack/defense/HP which have `1 + (coef * atom)^2 + atom` (the +1 means even 0 atoms gives 1 base stat). This means molecules with 0 hydrogene do literally zero building damage, even if they win the battle.

This is thematically correct (no hydrogen = no building damage) but creates a strategic asymmetry where a pure-attack army can win battles without ever threatening buildings. Since building destruction is one of the main ways to set back an opponent, this means pure O+C+Cl armies avoid the destruction mechanic entirely... which is actually the optimal play, since building damage does not contribute to winning the combat itself.

**Impact:** Destruction (H) is a luxury stat -- you have to sacrifice combat effectiveness to threaten buildings.

---

### [BAL-R1-025] MEDIUM prestige.php:10-41 -- Prestige unlock costs are linear but benefits are multiplicative, with a massive jump at 500 PP

Prestige unlocks: 50 PP (gen level 2), 100 PP (+5% production), 250 PP (+1 day protection), 500 PP (+5% combat), 1000 PP (cosmetic).

The practical unlocks are at 50, 100, and 500. The 500 PP "Maitre Chimiste" giving +5% combat is applied multiplicatively in combat.php line 216:
```php
$degatsAttaquant *= prestigeCombatBonus($actions['attaquant']);
```

This 5% permanently advantages veteran players who have accumulated PP over seasons. Since PP is only earned at end of season and top-5 players get +50 PP, the top players accumulate PP fastest, creating cross-season snowball.

**Impact:** Cross-season prestige creates a permanent advantage for veterans that compounds over time.

---

### [BAL-R1-026] MEDIUM config.php:313-344 -- Chemical reaction conditions require 80-100 atoms across TWO classes, severely limiting viable compositions

Combustion requires O>=100 in one class AND C>=100 in another. This means two of your four molecule classes must have extremely specialized compositions (100+ atoms of a single type), leaving little room for speed (Cl), HP (Br), or other stats.

The reactions are powerful (+15-20% bonuses) but the opportunity cost is enormous. A player cannot achieve Combustion AND Hydrogenation because:
- Combustion: needs O>=100 class + C>=100 class (2 classes used)
- Hydrogenation: needs H>=100 class + Br>=100 class (2 classes used)
- Total: 4 classes, all extremely specialized with few other stats

This makes reactions a "all or nothing" choice rather than a gradient optimization.

**Impact:** Reaction system forces extreme specialization, reducing strategic diversity.

---

### [BAL-R1-027] MEDIUM catalyst.php:10-47 -- Catalyst "Volatilite" simultaneously increases decay by 30% AND pillage by 25%, creating a toxic week for defenders

Catalyst 5 "Volatilite": `['decay_increase' => 0.30, 'pillage_bonus' => 0.25]`

During Volatilite weeks:
- All molecules decay 30% faster (applied as exponent in formulas.php:210)
- Pillage capacity increases by 25%

This double-whammy punishes passive/defensive players while rewarding aggressive players. A defender's army melts 30% faster AND they lose 25% more resources when attacked. Meanwhile, the attacker benefits from the pillage bonus.

The catalyst rotation is deterministic (`$currentWeek % count($CATALYSTS)`) so experienced players can predict when Volatilite week occurs and plan attacks accordingly.

**Impact:** Predictable catalyst rotation creates scheduled "grief weeks" that disproportionately hurt defenders and casual players.

---

### [BAL-R1-028] MEDIUM config.php:442-444 -- Class unlock cost `pow(n+1, 4)` makes 4th class cost 625x the 1st class

Class cost: `pow(numero + 1, 4)` where numero is 0-indexed class count.

- Class 1: `pow(1, 4) = 1 energy`
- Class 2: `pow(2, 4) = 16 energy`
- Class 3: `pow(3, 4) = 81 energy`
- Class 4: `pow(4, 4) = 256 energy`

Wait -- this is using `niveauclasse` (number of classes already created). So the first class costs `pow(0+1, 4) = 1`, second costs `pow(1+1, 4) = 16`, third costs `pow(2+1, 4) = 81`, fourth costs `pow(3+1, 4) = 256`.

These costs are trivial. Even the 4th class at 256 energy is almost free. This means the unlock cost is not a meaningful progression gate -- every player can unlock all 4 classes in the first hour.

**Impact:** Class unlock costs are negligible and provide no meaningful progression gate. The system adds a pointless early-game friction without strategic depth.

---

### [BAL-R1-029] MEDIUM combat.php:326-333 -- Defensive reward energy (20% of attacker's pillage capacity) is capped at storage limit, often wasting the bonus

When a defender wins, they receive `floor(totalAttackerPillage * 0.20)` in energy. But this is capped by the defender's storage capacity (combat.php lines 606-607). If the defender's energy is near max, they receive almost nothing.

Furthermore, the reward is based on the ATTACKER's pillage capacity, not their combat power. An attacker with zero soufre (0 pillage) triggers zero defensive reward. This means defensive rewards only exist against raiders, not against pure attackers.

**Impact:** Defensive rewards are unreliable and often wasted due to storage caps and dependency on attacker build.

---

### [BAL-R1-030] MEDIUM config.php:541-544 -- Troll medal thresholds start at 0, giving free Bronze tier

```php
$MEDAL_THRESHOLDS_TROLL = [0, 1, 2, 3, 4, 5, 6, 7];
```

Bronze tier requires a value of 0, meaning every player gets Bronze Troll medal by default. Silver requires just 1, Gold just 2, etc. This is clearly a joke/meta medal but its bonuses should be verified -- if Troll medals provide any gameplay advantage, they are essentially free stats.

Examining medailles.php line 43: Troll uses `$bonusTroll` as its bonus array. If `$bonusTroll` provides combat or economic bonuses, this is free value.

**Impact:** Potential free medal bonuses depending on bonusTroll definition.

---

### [BAL-R1-031] MEDIUM game_resources.php:47-52 -- Energy revenue calculation applies bonuses in a specific order that matters due to integer rounding

Energy production chain:
1. `prodBase = BASE_ENERGY_PER_LEVEL * niveau` (integer)
2. `prodIode = prodBase + totalIode`
3. `prodMedaille = (1 + bonus/100) * prodIode`
4. `prodDuplicateur = bonusDuplicateur * prodMedaille`
5. `prodPrestige = prodDuplicateur * prestigeProductionBonus()`
6. `prodProducteur = prodPrestige - drainageProducteur()`

The `round()` is only applied at the return statement. Since intermediate values are floats, the ordering of multiplications does not lose precision. However, the subtraction of `drainageProducteur()` at the END means it is applied after all bonuses. This means the drain is a flat cost while production scales multiplicatively with bonuses.

At high levels with all bonuses, production might be `75 * 40 * 1.5 * 1.25 * 1.05 = 5906 energy/hour` while drain at producteur 40 is `8 * 40 = 320 energy/hour`. The drain is only 5.4% of production. But at low levels with no bonuses: `75 * 5 = 375` production, drain `8 * 5 = 40` = 10.7% drain. The drain is relatively heavier for new players.

**Impact:** Flat drain cost is regressive -- it hurts low-level players proportionally more.

---

### [BAL-R1-032] MEDIUM config.php:124-231 -- Building cost exponents are uniformly low (0.7-0.9), making high-level buildings cheap relative to production

All building cost formulas use exponents of 0.7-0.9: `round(base * pow(level, 0.7))`. This is sub-linear -- each level costs LESS relative to the previous level increase. At level 50:
- Generateur energy cost: `50 * pow(50, 0.7) = 50 * 17.7 = 885 energy`
- Generateur atom cost: `75 * pow(50, 0.7) = 75 * 17.7 = 1328 per type`

With production rates in the thousands per hour at high levels, these costs are trivial. The real gate is construction TIME, which uses exponents of 1.5-1.7.

This means building progression is gated by time, not resources, for established players. New players are resource-gated, creating asymmetric progression curves.

**Impact:** Sub-linear cost scaling makes resource costs irrelevant for established players; time is the only real gate.

---

## LOW Findings

### [BAL-R1-033] LOW config.php:349 -- Espionage speed (20 cases/hour) is identical to merchant speed, with no upgrade path

Both espionage and merchant caravans move at 20 cases/hour with no way to increase speed. Since attack armies can exceed 200 cases/hour with maxed chlore, espionage is dramatically slower than the army it is supposed to scout.

If a player sends espionage first, then attacks, the espionage report arrives AFTER the attack has already landed, making it useless for real-time intelligence.

**Impact:** Fixed espionage speed makes pre-attack scouting impractical against distant targets.

---

### [BAL-R1-034] LOW config.php:355-356 -- Neutrino cost is flat 50 energy regardless of game stage

`NEUTRINO_COST = 50` energy per neutrino. In early game when production is `75 * 1 = 75 energy/hour`, buying 10 neutrinos costs 500 energy (6.7 hours of production). In late game at 5000+ energy/hour, 10 neutrinos cost 0.1 hours of production.

The static cost makes espionage prohibitively expensive early and trivially cheap late, providing no consistent cost pressure.

**Impact:** Flat neutrino cost is irrelevant at high levels, removing espionage as a meaningful resource trade-off.

---

### [BAL-R1-035] LOW config.php:568-629 -- Specialization unlock levels (ionisateur 15, producteur 20, condenseur 15) are reached at very different game stages

Combat specialization unlocks at ionisateur level 15. Economy at producteur level 20. Research at condenseur level 15.

Ionisateur is a military building that offensive players rush, so they get combat spec early. Producteur level 20 requires significant economic investment that is reached mid-to-late game. This means aggressive players get their specialization faster than economic players, reinforcing the offense-dominant meta.

**Impact:** Asymmetric unlock timing favors aggressive playstyles.

---

### [BAL-R1-036] LOW formulas.php:175-226 -- Decay coefficient calculation queries the database 3 times per call (stabilisateur, medal data, molecule data)

While not strictly a balance issue, the decay calculation is called per-class per-update in `updateRessources()`, resulting in 12+ DB queries per player update just for decay. This performance cost may discourage frequent logins if updates are slow, which would ironically increase actual decay losses (more time between updates = more molecules lost per report).

**Impact:** Performance-induced gameplay friction around molecule decay.

---

### [BAL-R1-037] LOW config.php:498-505 -- Medal tier names go up to "Diamant Rouge" (8 tiers) but bonus array has exactly 8 entries

`$MEDAL_BONUSES = [1, 3, 6, 10, 15, 20, 30, 50]`

The jump from Diamant (30%) to Diamant Rouge (50%) is +20 percentage points, the largest gap. But reaching Diamant Rouge requires orders of magnitude more progress than Diamant. For attack medals: Diamant requires 2,000,000 points while Diamant Rouge requires 10,000,000 (5x more for 20% more bonus). This is poor ROI.

**Impact:** Diamond Rouge tier has diminishing returns on investment, though the bonus is still the strongest.

---

### [BAL-R1-038] LOW config.php:253-256 -- Combat point formula caps at 20 per battle, making small skirmishes worth the same as large battles

`COMBAT_POINTS_MAX_PER_BATTLE = 20`

Formula: `min(20, floor(1 + 0.5 * sqrt(total_casualties)))`

To hit the cap of 20: `0.5 * sqrt(casualties) = 19`, so `casualties = 1444`.

Any battle with 1444+ total casualties gives the same 20 points as a battle with 100,000 casualties. This flattens the reward curve for large battles, discouraging massive engagements and instead incentivizing many small raids for maximum point farming.

**Impact:** Point cap incentivizes small raid spam over meaningful large-scale battles.

---

### [BAL-R1-039] LOW prestige.php:46-84 -- Prestige PP calculation rewards attack count (10+ attacks = 5 PP) but not defense count

Line 79: `if ($autre['nbattaques'] >= 10) $pp += 5;`

There is no equivalent bonus for number of successful defenses. Defensive players who hold territory without attacking earn fewer prestige points. Combined with the rank-based PP bonus (top 5 get +50 PP), the prestige system favors aggressive, high-ranking players.

**Impact:** Prestige system undervalues defensive playstyles.

---

### [BAL-R1-040] LOW marche.php:7 -- Market volatility divisor uses ALL registered players' activity, not actual traders

`$volatilite = 0.3 / max(1, $actifs['nbActifs'])` where `nbActifs` counts all players active in the last 31 days.

With many active players, volatility approaches zero, meaning market prices barely move. With few players, volatility is high, causing extreme price swings. This makes the market useless in large games (prices never change) and exploitable in small games (one player can manipulate prices).

**Impact:** Market volatility scaling is inversely proportional to player count, creating opposite problems at different scales.

---

### [BAL-R1-041] LOW config.php:161-166 -- Champdeforce costs only carbone while ionisateur costs only oxygene, creating a resource asymmetry

Champdeforce (defense building) costs carbone. Ionisateur (attack building) costs oxygene. Since these are the same atoms used for defense and attack stats respectively, building these buildings competes directly with molecule production for the same resources.

A player investing in champdeforce must produce extra carbone, which also goes into defensive molecules. An attacker investing in ionisateur must produce extra oxygene, which also fuels attack molecules. This creates a natural synergy where economic specialization aligns with military specialization, further reinforcing that players cannot viably play both offense and defense.

**Impact:** Building resource requirements reinforce mandatory specialization rather than encouraging versatility.

---

### [BAL-R1-042] LOW sinstruire.php -- Training courses are purely educational content with zero gameplay integration

The entire sinstruire.php is a chemistry education module with no in-game rewards, bonuses, or progression tied to completing courses. While thematically appropriate, this represents an unused engagement mechanism. A "tutorial bonus" system (e.g., completing a course grants a one-time resource bonus) could improve new player onboarding.

**Impact:** Missed opportunity for new player engagement and onboarding rewards.

---

## Summary Table

| Severity | Count | Key Themes |
|----------|-------|------------|
| CRITICAL | 7 | Quadratic snowball, decay kills diversity, dead atoms, RNG building destruction |
| HIGH | 14 | Offense dominance, medal gap, alliance snowball, formation exploits, market manipulation |
| MEDIUM | 11 | Stat hierarchy, prestige compounding, reaction rigidity, catalyst griefing |
| LOW | 10 | Speed mismatches, flat costs, specialization timing, missed opportunities |
| **TOTAL** | **42** | |

---

## Top 5 Recommended Priority Fixes

1. **[BAL-R1-006] Decay formula overhaul** -- Flatten the exponential-on-exponential decay to allow viable diverse molecules. Consider `pow(0.99, atoms/500)` per hour instead of the current nested power formula.

2. **[BAL-R1-001] Add diminishing returns to condenseur** -- Cap the level multiplier or use `sqrt(niveau)` instead of `niveau/50` to prevent 13:1 power ratios from condenseur alone.

3. **[BAL-R1-007] Replace random building targeting with player choice** -- Let the attacker allocate destruction points across buildings, or let the defender set a protection priority.

4. **[BAL-R1-012] Compress medal bonuses** -- Change from `[1,3,6,10,15,20,30,50]` to something like `[2,4,7,10,13,16,20,25]` to reduce the veteran gap while still rewarding progression.

5. **[BAL-R1-020] Add win cooldown** -- Apply `ATTACK_COOLDOWN_SECONDS` on ALL combat outcomes (win/loss/draw) against the same target to prevent chain-attack bullying. Consider a shorter cooldown for wins (e.g., 1 hour) vs. longer for losses (4 hours).
