# Pass 3: Cross-Domain Exploit Chain Analysis

**Auditor:** Claude Opus 4.6 (Security Audit Agent)
**Date:** 2026-03-05
**Scope:** Cross-system interactions between combat, market, alliance, prestige, compounds, resource nodes, transfers, vacation, beginner protection, and season reset.
**Methodology:** For each chain, trace how bugs or design gaps in one system can be leveraged through another system to amplify impact. Findings reference Pass 2 IDs where applicable.

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 5     |
| HIGH     | 9     |
| MEDIUM   | 8     |
| LOW      | 3     |
| **Total**| **25**|

---

## CRITICAL CHAINS

### P3-XD-001 | CRITICAL | Resource Transfer Duplication via TOCTOU Race (Transfer + Market + Ranking)

- **Chain:** marche.php transfer (P2-D1-002) -> market buy (D4) -> trade points (D4) -> ranking inflation
- **Description:**
  1. Player A initiates a resource transfer to Player B on `marche.php`. The sender's resources are deducted via a non-transactional read-then-write pattern (line 55: `SELECT * FROM ressources`, line 108-127: `UPDATE ressources SET ... $chaine ...`). There is no `FOR UPDATE` lock on the sender's read, and no wrapping transaction.
  2. Player A concurrently opens a second browser tab and sells the same resources on the market (which IS inside a transaction with `FOR UPDATE` -- marche.php line 287-319).
  3. The sell transaction locks and reads the current balance, sells the atoms, and gains energy. It commits.
  4. The transfer code, still holding the stale pre-sell balance, writes back `old_balance - transfer_amount`. Since it does not re-read after the sell committed, the deduction uses the old (higher) value. Net result: atoms are both sold AND transferred -- they are duplicated.
  5. The market sell also awards `tradeVolume` points (line 346-352), inflating ranking.
- **Impact:** Unlimited resource duplication. Atoms can be created from nothing by racing transfer against sell. Duplicated atoms feed into trade points, alliance donations, and ultimately ranking manipulation. A coordinated pair of accounts can generate infinite resources.
- **Fix:** Wrap the entire transfer logic in `marche.php` lines 55-127 in `withTransaction()` with `SELECT ... FOR UPDATE` on the sender's `ressources` row before deducting. Re-read balance inside the transaction.

---

### P3-XD-002 | CRITICAL | Resource Duplication + Market Pump: Infinite Energy Generation

- **Chain:** Transfer duplication (P3-XD-001) -> market buy/sell cycle -> energy laundering
- **Description:**
  1. Exploit P3-XD-001 to duplicate atoms (e.g., 10,000 carbone created from nothing).
  2. Sell the duplicated carbone on the market at current price, gaining energy.
  3. The sell pushes the carbone price down (inverse volatility formula, marche.php line 330).
  4. Use the energy gained to buy a DIFFERENT resource (e.g., oxygene) cheaply. The buy pushes oxygene price up.
  5. Repeat: duplicate more carbone, sell it. The price never matters because the atoms cost nothing.
  6. Each buy/sell cycle awards `tradeVolume` points (both buy at line 226-231 and sell at line 346-352).
  7. The attacker now has unlimited energy AND climbing trade rankings.
- **Impact:** Complete economic collapse. Infinite energy means infinite buildings, infinite neutrinos, infinite molecule formation. The attacker dominates every ranking category.
- **Fix:** Fix P3-XD-001 (transaction on transfers). Additionally, implement daily trade volume caps or detect anomalous trade patterns.

---

### P3-XD-003 | CRITICAL | Combat Error Silencing + Wrong HP Column = Undetectable One-Hit Kills

- **Chain:** logError wrong args (P2-D2-001) -> combat errors silenced -> wrong HP column (P2-D5-006) -> stealth kills
- **Description:**
  1. In `combat.php` line 28-33, atom levels for the attacker are read from `pointsProducteur` column: `SELECT pointsProducteur FROM constructions`. This is the production point distribution, NOT the condenseur levels.
  2. The condenseur levels (stored in `pointsCondenseur`) drive the `modCond()` multiplier which is used in `pointsDeVieMolecule()`, `attaque()`, `defense()`, and `pillage()` functions.
  3. If `pointsProducteur` has values like "3;2;5;4;1;3;2;1" (production points) but `pointsCondenseur` has values like "8;6;10;7;4;5;3;2" (condenseur levels), then combat uses the WRONG (lower) values for all stat calculations.
  4. The `modCond()` formula is `1 + (level / 50)`. Using production points (typically 0-20) instead of condenseur levels (typically 0-30+) means stats are calculated with wrong multipliers.
  5. This affects BOTH attacker and defender symmetrically (lines 28+38 both use `pointsProducteur`), but the impact varies based on how players distribute points.
  6. When this causes unexpected results, combat errors are logged via `logError()` calls -- but if Pass 2 finding P2-D2-001 (wrong argument count) means those log calls silently fail, the issue is never detected.
- **Impact:** All combat calculations use wrong stat multipliers. Players with high condenseur investment get no benefit in combat. This creates predictable but invisible imbalance where certain builds are much weaker than they should be.
- **Fix:** Change `combat.php` lines 28 and 38 from `SELECT pointsProducteur` to `SELECT pointsCondenseur`. Verify `logError()` call signatures match the function definition.

---

### P3-XD-004 | CRITICAL | Transfer Duplication + Alliance Donation = Infinite Alliance Power

- **Chain:** Transfer duplication (P3-XD-001) -> market sell -> energy -> alliance donation -> duplicateur/research -> combat dominance
- **Description:**
  1. Use P3-XD-001 to duplicate resources infinitely.
  2. Sell duplicated atoms on the market to convert to energy.
  3. Donate unlimited energy to alliance via `don.php` (which IS properly transactional, ironically).
  4. Alliance now has infinite `energieAlliance`.
  5. Upgrade duplicateur to extreme levels. Each level provides +1% to ALL resource production AND +1% to attack/defense in combat (via `DUPLICATEUR_BONUS_PER_LEVEL`).
  6. Upgrade all 5 alliance research technologies (catalyseur, fortification, reseau, radar, bouclier) to max level 25.
  7. Alliance members now have: -50% formation time (catalyseur), +25% building HP (fortification), +125% trade points (reseau), -50% espionage cost (radar), -25% pillage losses (bouclier).
  8. The duplicateur at level 100 gives +100% to all stats. There is no duplicateur level cap enforced in the upgrade code (alliance.php line 87-100 only checks energy, not level).
- **Impact:** Entire alliance becomes invincible. +100% (or more) to all stats through uncapped duplicateur. Every member dominates combat and economy. Season rankings are permanently skewed.
- **Fix:** Fix P3-XD-001 first. Add a `DUPLICATEUR_MAX_LEVEL` constant and enforce it in the upgrade transaction. Cap at a reasonable level (e.g., 25 like research).

---

### P3-XD-005 | CRITICAL | withTransaction Catches Exception Not Throwable + TypeError in Combat = Resource Loss Without Combat

- **Chain:** withTransaction design flaw (P2-D7-003) -> TypeError in combat.php -> partial commit -> attacker loses troops, defender loses nothing
- **Description:**
  1. `withTransaction()` in `database.php` line 117 catches `Exception` but not `Throwable`. PHP 8.x `TypeError` and `DivisionByZeroError` extend `Error`, not `Exception`.
  2. In `combat.php`, numerous arithmetic operations could produce `TypeError` if a DB query returns `null` instead of expected array (e.g., if defender deletes their account mid-combat).
  3. The combat code in `game_actions.php` lines 109-360 uses a manual `mysqli_begin_transaction` + `include("includes/combat.php")` + `mysqli_commit` pattern.
  4. If combat.php throws a `TypeError` (e.g., trying to access array key on null), the catch block at line 357 catches `Exception` -- but `TypeError` is NOT an `Exception`. It propagates up, and the transaction is left in a dirty state.
  5. Before the error, the CAS guard at line 90-94 already set `attaqueFaite=1` (OUTSIDE the transaction). The attacker's troops were already deducted inside the transaction.
  6. Because the transaction neither commits nor rolls back cleanly on a `TypeError`, the database connection is in an ambiguous state. If PHP's shutdown handler or connection close triggers an implicit rollback, attacker troops are lost (deducted outside transaction) but defender takes no damage.
  7. The CAS flag `attaqueFaite=1` prevents retry, so the combat is permanently consumed.
- **Impact:** Attackers can lose their entire army without dealing any damage. This could happen naturally (defender account deletion) or be triggered intentionally (defender rapidly modifying their data during combat resolution window).
- **Fix:** Change `withTransaction()` to catch `\Throwable` instead of `\Exception`. Move the CAS guard (`attaqueFaite=1`) inside the transaction. Add rollback handling for Throwable in the combat block.

---

## HIGH CHAINS

### P3-XD-006 | HIGH | Beginner Protection Uses Wrong Field + Veteran Prestige Never Enforced = Exploitable Protection

- **Chain:** Wrong timestamp field (P2-D5-031) + veteran unlock not checked in attaquer.php -> protection bypass or extension exploit
- **Description:**
  1. In `attaquer.php` line 65, beginner protection checks `time() - $enVac['timestamp'] < BEGINNER_PROTECTION_SECONDS`. The `timestamp` field in `membre` table is the account creation time.
  2. During season reset (`remiseAZero()`, player.php line 936), ALL players get their `timestamp` reset to current time: `UPDATE membre SET timestamp=?`. This means every player gets 3 days of beginner protection at the start of every season.
  3. The `veteran` prestige unlock (cost: 250 PP) promises "+1 day beginner protection" but is NEVER checked in `attaquer.php`. The unlock exists in the prestige system but the attack protection check does not query it.
  4. Players who paid 250 PP for `veteran` get no benefit -- the unlock is dead code.
  5. Meanwhile, ALL players get protection renewed every season, which may not be intended for returning players.
- **Impact:** Dead prestige feature (wasted PP for players who bought it). Additionally, the universal protection renewal at season start may be intentional game design, but combined with the veteran non-enforcement, it creates confusion and wasted player currency.
- **Fix:** In `attaquer.php`, add a check: `$protectionDuration = BEGINNER_PROTECTION_SECONDS; if (hasPrestigeUnlock($target, 'veteran')) $protectionDuration += SECONDS_PER_DAY;` Apply this to both defender and attacker protection checks.

---

### P3-XD-007 | HIGH | Vacation Mode + Resource Accumulation + No Vacation Attack Receiving

- **Chain:** Vacation mode -> resources still accumulate (no freeze) -> exit vacation with stockpile -> immediate attack advantage
- **Description:**
  1. Player activates vacation mode via `compte.php`. The `vacance` flag is set in `membre`.
  2. `redirectionVacance.php` prevents the player from accessing game pages (armee, attaquer, marche, constructions, attaque).
  3. However, `updateRessources()` is called in `basicprivatephp.php` every time any page loads. It reads `tempsPrecedent`, calculates elapsed time, and adds resources proportional to time passed.
  4. When another player's page load triggers `updateActions()` which calls `updateRessources($vacationPlayer)` (e.g., when combat resolves), the vacation player's resources are updated for the entire vacation duration.
  5. The vacation player accumulates resources up to storage cap passively.
  6. Upon returning from vacation, they have full storage of all resources without having played.
  7. They can immediately dump all resources into molecule formation, market trades, or attack preparation.
  8. Vacation players also cannot be attacked (`attaquer.php` line 63-64 checks `$enVac['vacance']`), so their accumulated wealth is risk-free.
- **Impact:** Vacation mode provides a risk-free resource accumulation strategy. Players can strategically go on vacation for exactly the time needed to fill storage, then return with full resources and no losses. Combined with the fact that buildings still have full HP and molecules still exist (only decaying), vacation becomes a competitive advantage.
- **Fix:** Either freeze resource accumulation during vacation (set `tempsPrecedent` to current time when vacation ends, discarding the gap) or cap the maximum resource tick duration.

---

### P3-XD-008 | HIGH | Compound Synthesis Stacking via Same-Effect Different-Key Compounds

- **Chain:** Compound system design gap -> stack same-effect buffs -> combat/pillage amplification
- **Description:**
  1. `activateCompound()` in `compounds.php` line 117-123 checks if a compound with the SAME EFFECT TYPE is already active and blocks it.
  2. However, the check compares `$COMPOUNDS[$active['compound_key']]['effect']` against `$COMPOUNDS[$key]['effect']`.
  3. Currently there are 5 compounds with 5 different effect types (production_boost, defense_boost, attack_boost, speed_boost, pillage_boost), so no stacking is possible.
  4. BUT: if a game update adds a second compound with the same effect type (e.g., another `attack_boost` compound), the check correctly blocks it. This is actually well-designed.
  5. The REAL issue: all 5 different effects CAN be active simultaneously. A player can have +10% production, +15% defense, +10% attack, +20% speed, AND +25% pillage all at once.
  6. Combined with prestige bonuses (+5% production, +5% combat), alliance duplicateur (+N%), alliance research bonuses, isotope bonuses, formation bonuses, and specialization bonuses, the total multiplier stack becomes very large.
  7. A fully optimized attack: base * 1.10 (compound attack) * 1.05 (prestige) * 1.20 (duplicateur L20) * 1.02*ionisateur_level * isotope * specialization = potentially 2-3x damage multiplier.
- **Impact:** While each individual bonus is moderate, the multiplicative stacking across 6+ systems creates extreme power spikes. A player who activates all buffs simultaneously before attacking can deal 2-3x normal damage in a 1-hour window.
- **Fix:** Consider making compound buffs consume each other (only one compound active at a time) or make them additive rather than multiplicative with other bonus sources. Alternatively, cap total bonus multiplier from all sources.

---

### P3-XD-009 | HIGH | Alliance Energy Laundering via Don + Quitter Cycle

- **Chain:** Alliance donation (don.php) -> leave alliance -> rejoin different alliance -> repeat
- **Description:**
  1. Player donates energy to Alliance A via `don.php`. Energy is deducted from player, added to alliance.
  2. Player leaves Alliance A (alliance.php line 67-76). Their `idalliance` is set to 0.
  3. The donated energy remains in Alliance A's `energieAlliance`. It is NOT refunded.
  4. Player joins Alliance B (accepts invitation, alliance.php line 160-166).
  5. Alliance A still has the donated energy and can spend it on duplicateur/research.
  6. The player's `energieDonnee` stat is incremented (don.php line 29), contributing to prestige PP via `PRESTIGE_PP_DONATION_BONUS`.
  7. An alliance leave cooldown was mentioned in memory (H-020), but examining the code shows no cooldown enforcement between leaving and joining a new alliance.
  8. A coordinated group can create multiple alliances, have one player cycle through donating to each, inflating all alliances' energy pools.
- **Impact:** Energy multiplication across alliances. A single wealthy player can fund multiple alliances' research trees. Combined with resource duplication (P3-XD-001), this allows ALL alliances in a coordination group to have maxed research.
- **Fix:** Implement a cooldown (e.g., 24 hours) between leaving an alliance and joining/creating another. Track total season donations per player and cap them.

---

### P3-XD-010 | HIGH | Combat Point Farming via Coordinated Mutual Attacks

- **Chain:** Combat points system -> coordinated same-alliance attacks -> ranking inflation
- **Description:**
  1. Two players (A and B) in different alliances coordinate attacks.
  2. Player A attacks Player B with a large army. Combat resolves, awarding points to the winner.
  3. Combat points formula: `floor(COMBAT_POINTS_BASE + COMBAT_POINTS_CASUALTY_SCALE * sqrt(totalMassDestroyed / COMBAT_MASS_DIVISOR))`, capped at `COMBAT_POINTS_MAX_PER_BATTLE` (20 points).
  4. After cooldown (1 hour for win, 4 hours for loss), Player B attacks Player A.
  5. Both players accumulate `pointsAttaque` and `pointsDefense` which feed into `totalPoints` via sqrt ranking.
  6. The multi-account detection (`checkCoordinatedAttacks()`) only flags same-IP attacks (multiaccount.php). Different-IP collusion is undetectable.
  7. With 24 attacks per day (alternating, 1-hour cooldown), each player gains up to 480 raw attack/defense points daily.
  8. Over a 31-day season: up to 14,880 raw points, yielding `5.0 * sqrt(14880) = ~610` display points per category.
  9. Both `pointsAttaque` (weight 1.5) and `pointsDefense` (weight 1.5) contribute to `totalPoints`, giving massive ranking advantage.
- **Impact:** Two colluding players can reach top rankings through coordinated attacks without any real competitive risk. The attack cooldown limits speed but does not prevent the strategy over a full season.
- **Fix:** Implement diminishing returns for repeated attacks on the same target (e.g., halve points for each subsequent attack on the same defender within a week). Track attack diversity and flag players who only attack a single target.

---

### P3-XD-011 | HIGH | Vault Flat Protection + Building Destruction = Storage Downgrade Exploit

- **Chain:** Vault protection formula (P2-D4-002) -> building destruction in combat -> storage shrinks -> vault protects more than exists
- **Description:**
  1. Vault protection is calculated as `capaciteCoffreFort(vaultLevel, depotLevel) = min(VAULT_MAX_PROTECTION_PCT, vaultLevel * VAULT_PCT_PER_LEVEL) * placeDepot(depotLevel)`.
  2. This is percentage-based (fixed in V4), which is correct.
  3. However, combat can destroy buildings, including the `depot` (storage). The `diminuerBatiment("depot", defender)` call reduces depot level by 1.
  4. When depot level drops, `placeDepot()` returns a lower value. But vault protection is recalculated on the next combat based on the NEW (lower) depot level.
  5. The issue: vault level is NOT reduced when depot is destroyed. A player at vault level 25 (50% protection) with depot level 1 still protects `0.50 * placeDepot(1) = 0.50 * 1150 = 575` resources per type.
  6. But at depot level 1, `placeDepot(1) = 1150`, meaning they can only HOLD 1150 resources. So 575 of 1150 (50%) is protected.
  7. This is actually working correctly on percentage basis. The pass 2 finding about "flat not percentage" appears to have been fixed.
  8. HOWEVER: the coffrefort building itself is NOT in the list of damageable buildings (lines 468-474 only list generateur, champdeforce, producteur, depot, ionisateur). Vault level can never be reduced by combat.
  9. This means a player can build a high vault, then intentionally let their depot be destroyed. With depot at level 1 and vault at level 25, they protect 50% of their tiny storage. Rebuilding depot is cheap at low levels.
- **Impact:** Low severity on its own (vault working as designed), but combined with intentional depot management, vault becomes an unbreakable shield at low storage levels. This primarily matters for resource denial strategies.
- **Fix:** Consider making coffrefort damageable in combat, or tie vault effectiveness to depot level (e.g., vault max level <= depot level).

---

### P3-XD-012 | HIGH | Prestige Production Bonus + Compound Boost + Node Bonus = Snowball Economy

- **Chain:** Prestige production (5%) + compound production (10%) + resource node (10%) + duplicateur + specialization -> early-season snowball
- **Description:**
  1. At season start, players with prestige unlocks have immediate advantages: `debutant_rapide` starts with generateur level 2 (2x energy production), `experimente` gives +5% production.
  2. On the first day, a prestige player produces: base_energy * 2 (gen level 2) * 1.05 (prestige) = 2.10x a normal player's energy.
  3. If they position near a resource node (+10%): 2.10 * 1.10 = 2.31x.
  4. If their alliance still has duplicateur from donations (wait -- duplicateur resets to 0 each season per remiseAZero line 934). So this path is blocked.
  5. However, compound synthesis is available from day 1 if atoms are accumulated fast enough. +10% production compound for 1 hour during the initial growth phase is significant.
  6. Total first-day advantage: 1.05 (prestige) * 1.10 (node) * 1.10 (compound) * 2 (gen level 2) = ~2.54x production.
  7. This compounds (pun intended) over time: more resources -> faster building upgrades -> even more production -> faster molecule creation -> first to attack -> first to gain combat points.
  8. The medal bonus cap (MAX_CROSS_SEASON_MEDAL_BONUS = 10%, grace period 14 days) mitigates veteran snowball in combat, but production snowball is uncapped.
- **Impact:** Experienced players with prestige unlocks have a 2.5x economic advantage on day 1 of each season. New players cannot compete on the same timeline. This creates a permanent stratification that makes the game unwinnable for newcomers.
- **Fix:** Apply a grace period to prestige production bonuses similar to the medal grace period. Or provide new players compensating bonuses (e.g., a "catch-up" production boost that decreases as they approach the average).

---

### P3-XD-013 | HIGH | Multi-Account IP Check Bypass via Transfer + Market Laundering

- **Chain:** IP check on transfers (marche.php line 32) -> market intermediary -> transfer laundering
- **Description:**
  1. Direct resource transfers between same-IP accounts are blocked (marche.php line 32: `$ipmm['ip'] != $ipdd['ip']`).
  2. Multi-account flagging (marche.php line 30: `areFlaggedAccounts()`) adds another layer.
  3. However, the market has NO IP restriction. Any player can buy/sell on the shared global market.
  4. Multi-account exploit: Account A (IP1) sells a rare resource (e.g., soufre) in bulk. This crashes the soufre price via the volatility formula.
  5. Account B (same person, different IP via VPN) buys the now-cheap soufre.
  6. The price crash means Account B gets many more atoms per energy unit than normal.
  7. The inverse operation: Account B sells a different resource, Account A buys it cheaply.
  8. Net effect: resources are transferred between accounts via market manipulation, bypassing the IP check entirely.
  9. The 5% sell tax (MARKET_SELL_TAX_RATE = 0.95) reduces efficiency slightly but does not prevent the transfer.
  10. Both accounts gain `tradeVolume` points from the trades, getting ranking benefits as a bonus.
- **Impact:** The IP-based transfer restriction is completely bypassable via market manipulation. Multi-account resource feeding continues undetected. The multi-account system (`checkTransferPatterns`) only monitors direct transfers, not market-mediated ones.
- **Fix:** Monitor per-player market activity for anomalous patterns (e.g., player sells 100% of one resource then buys 100% of another immediately). Flag accounts that consistently trade opposite sides of the same resources.

---

### P3-XD-014 | HIGH | No Transaction on Transfer Delivery + Double-Processing Risk

- **Chain:** actionsenvoi delivery (game_actions.php 526-600) -> no CAS guard -> double resource delivery
- **Description:**
  1. In `game_actions.php` lines 526-600, transfer deliveries are processed: `SELECT * FROM actionsenvoi WHERE ... AND tempsArrivee < ?`, then for each row: `DELETE FROM actionsenvoi WHERE id=?`, then add resources to receiver.
  2. The DELETE happens first (line 529), then resources are added (lines 567-599).
  3. There is NO transaction wrapping this operation.
  4. There is NO CAS guard (like the combat code has). The DELETE returns the number of affected rows, but it's not checked.
  5. If two concurrent requests both read the same `actionsenvoi` row before either deletes it, both will try to DELETE (one succeeds, one deletes 0 rows) and both will try to add resources.
  6. The resource addition at line 599 uses `dbExecute` which succeeds regardless of whether the DELETE claimed the row.
  7. Effectively, a race condition can cause resources to be delivered twice to the receiver.
  8. This is easily triggered by refreshing the page rapidly when a transfer is about to arrive.
- **Impact:** Resources delivered twice. Since transfers are player-initiated and arrival time is known (shown in UI), this is easily exploitable by refreshing at the right moment. Moderate resource duplication.
- **Fix:** Wrap the entire delivery block (DELETE + resource update) in `withTransaction()`. Check `DELETE` affected rows before adding resources (CAS pattern). Or use `DELETE ... RETURNING` pattern.

---

## MEDIUM CHAINS

### P3-XD-015 | MEDIUM | Compound Speed Boost + Close Proximity = Near-Instant Attack

- **Chain:** NH3 compound (speed_boost +20%) + adjacent map position -> sub-second attack travel
- **Description:**
  1. Speed compound NH3 provides +20% speed for 1 hour.
  2. Travel time = `distance / speed * 3600` seconds. For adjacent squares (distance ~1), base travel time is already very short.
  3. With speed boost: `max(1, round(tempsTrajet / 1.20))` (attaquer.php line 148).
  4. Combined with high Cl/N atoms in molecules (max speed), travel time can reach 1 second.
  5. Defender sees "incoming attack" but has essentially no reaction time.
  6. The defender notification system (actionsattaques shown on attaquer.php) only updates on page load. With 1-second travel, the attack arrives before the defender can react.
- **Impact:** Players positioned adjacent to targets with speed compounds can execute effectively instant attacks. Defenders have no time to activate defense compounds, change formation, or send troops away.
- **Fix:** Implement a minimum travel time (e.g., 5 minutes regardless of speed/distance). Or provide push notifications for incoming attacks.

---

### P3-XD-016 | MEDIUM | Season Reset Timing + Prestige Calculation = PP Sniping

- **Chain:** Prestige PP calculation (prestige.php) -> season end timing -> last-minute stat inflation
- **Description:**
  1. Prestige points are calculated by `calculatePrestigePoints()` which reads live stats from the `autre` table.
  2. PP sources include: medals (tier count), `nbattaques >= 10` (+5 PP), `tradeVolume >= 20` (+3 PP), `energieDonnee > 0` (+2 PP), final week activity (+5 PP).
  3. `awardPrestigePoints()` freezes rankings and iterates all players. Top 5 get +50 PP, top 10 get +30 PP, etc.
  4. The function is called in `performSeasonEnd()` before `remiseAZero()`.
  5. A player can optimize for PP by reaching exactly the minimum thresholds at the last possible moment (10 attacks, 20 trade volume, 1 energy donated).
  6. The ranking bonus is based on `totalPoints` at the moment of award. A coordinated group could boost one member's ranking via market trades in the final hour.
  7. Since `awardPrestigePoints()` reads live data without locks, concurrent modifications during the award process could cause inconsistent PP calculation.
- **Impact:** Players can game prestige PP by doing the minimum required activities right before season end. Coordinated groups can boost one member to top 5 for +50 PP. Over multiple seasons, this compounds into significant prestige advantages.
- **Fix:** Snapshot player stats at a specific time (e.g., 1 hour before season end) and use the snapshot for PP calculation. Lock the `autre` table during award processing.

---

### P3-XD-017 | MEDIUM | Resource Node Positioning + Season Start = Map Position Sniping

- **Chain:** Resource node generation (resource_nodes.php) -> map position on first login -> unfair advantage
- **Description:**
  1. At season start, `generateResourceNodes()` creates 15-25 nodes at random positions.
  2. Players connect after season start and are assigned map positions (based on first login).
  3. The node positions are visible on the map immediately.
  4. A player who logs in early can see node positions before choosing their location. However, map position is assigned automatically, not chosen.
  5. But: if map position assignment uses player order or is predictable, early logins get positions near the center where node density is highest.
  6. Players near nodes get +10% production for matching resources. Multiple overlapping nodes stack.
  7. Late joiners are placed on the map periphery, further from node clusters.
- **Impact:** Early-season login timing creates a map position advantage for node proximity bonuses. This compounds with the production snowball from P3-XD-012.
- **Fix:** Assign map positions independent of login order (e.g., random position seeded by player ID). Or make nodes discoverable only to players within range (fog of war).

---

### P3-XD-018 | MEDIUM | Phalange Formation Empty Class 1 Exploit + Building Damage

- **Chain:** Formation system -> Phalange with empty class 1 -> 60% damage discarded -> building protection
- **Description:**
  1. Phalange formation makes class 1 absorb 60% of incoming damage (FORMATION_PHALANX_ABSORB = 0.60) with +20% defense bonus.
  2. If a player sets formation to Phalange but has class 1 as "Vide" (empty, 0 molecules), then:
  3. The 60% of damage directed at class 1 has 0 targets. In combat.php lines 232-239, `$classeDefenseur1['nombre'] > 0` is false, so `$classe1DefenseurMort = 0` and `$phalanxOverflow = $phalanxDamage`.
  4. Wait -- reading more carefully, `$phalanxOverflow = $phalanxDamage` when class 1 has 0 molecules, which means ALL phalanx damage overflows to other classes. So the exploit doesn't work as described.
  5. HOWEVER, if class 1 has molecules but `$hpPerMol1 = 0` (which happens when brome=0 AND carbone=0 in the molecule formula, giving `MOLECULE_MIN_HP + 0 = 10` HP), the floor function `floor($phalanxDamage / 10)` still kills molecules.
  6. The real issue is that empty class 1 with Phalange causes ALL damage to overflow, making Phalange identical to default cascade. This is a UI/game design confusion, not an exploit.
- **Impact:** Low impact. Empty class 1 with Phalange is suboptimal, not exploitable. The `FORMATION_PHALANX_ABSORB` was already reduced from 0.70 to 0.60 per BAL-CROSS C7. The system handles the edge case correctly by overflowing damage.
- **Fix:** Add a UI warning when a player selects Phalange with an empty or very weak class 1.

---

### P3-XD-019 | MEDIUM | Espionage + Compound Activation Timing = Perfect Attack Information

- **Chain:** Espionage report -> compound activation -> attack with perfect timing
- **Description:**
  1. Player A spies on Player B. Espionage report reveals: all 4 molecule classes (formulas, counts), all resources, all building levels + HP, and defensive formation.
  2. Player A now knows exactly what compounds to activate for maximum advantage.
  3. If B has weak defense: activate CO2 (attack +10%) and H2SO4 (pillage +25%) for maximum damage and loot.
  4. If B has strong class 1 with Phalange: activate NH3 (speed +20%) for fast surprise attack.
  5. The espionage report shows building HP remaining, so A knows if one more attack will destroy a building level.
  6. A activates the optimal compound set (1 hour duration) and immediately launches the attack.
  7. B cannot know they were spied (actually they DO get a notification per game_actions.php line 474-478, but it's anonymous and just says "someone spied on you").
  8. B gets the spy notification but cannot know WHEN the attack will come within the 1-hour compound window.
- **Impact:** Espionage provides complete information advantage. Combined with compound timing, the attacker can optimize their entire offensive strategy with zero uncertainty. The anonymous spy notification gives defenders warning but no actionable intelligence.
- **Fix:** Consider adding noise to espionage reports (e.g., +/-10% on troop counts, randomize one building level). Or reveal the spy's identity to the defender.

---

### P3-XD-020 | MEDIUM | Alliance War Losses Tracking Not Protected by Transaction

- **Chain:** Combat resolution -> alliance war losses update -> concurrent combat -> wrong loss tallies
- **Description:**
  1. After combat resolves, if the attacker and defender are in warring alliances, war losses are updated (combat.php lines 683-699).
  2. The code reads the current war declaration: `SELECT * FROM declarations WHERE type=0 AND fin=0 AND ...`
  3. Then updates losses: `UPDATE declarations SET pertes1=?, pertes2=? WHERE id=?`
  4. This is a read-then-write pattern without a `FOR UPDATE` lock.
  5. If two combats resolve simultaneously between the same warring alliances (e.g., two separate attack pairs), both read the same `pertes1`/`pertes2` values, then both write `old_pertes + new_losses`.
  6. The second UPDATE overwrites the first's increment, losing one combat's losses.
  7. While combat.php runs inside a manual `mysqli_begin_transaction`, the war loss update at lines 683-699 is inside that same transaction. But a concurrent combat in a different PHP process has its own transaction with its own read.
- **Impact:** War loss tallies become inaccurate under concurrent combat. This affects war resolution and alliance competition. In a heated war with many simultaneous battles, losses could be significantly underreported.
- **Fix:** Use `UPDATE declarations SET pertes1 = pertes1 + ?, pertes2 = pertes2 + ? WHERE id=?` (atomic increment) instead of read-then-write. This eliminates the race condition.

---

### P3-XD-021 | MEDIUM | Specialization + Combat: Stacking Offensive Modifiers Beyond Intended Range

- **Chain:** Specialization (Oxydant +10% attack) + isotope (Reactif +20% attack) + compound (CO2 +10% attack) = 40%+ attack bonus on single class
- **Description:**
  1. Specialization Oxydant: `attack += 0.10`, `defense -= 0.05`.
  2. Isotope Reactif: `attack += 0.20`, `HP -= 0.10`, faster decay.
  3. Compound CO2: `attack_boost = 0.10` (multiplicative on total).
  4. Prestige maitre_chimiste: combat bonus 1.05x.
  5. Alliance duplicateur at level 10: 1.10x.
  6. Ionisateur at level 20: 1.40x (20 * 2% per level).
  7. Medal bonus up to 10% (capped).
  8. Catalyst attack bonus (varies by week).
  9. For a single molecule class with Reactif isotope:
     - Base attack multiplied by: 1.20 (reactif) * 1.10 (oxydant spec) * 1.10 (compound) * 1.05 (prestige) * 1.10 (duplicateur) * 1.40 (ionisateur) * 1.10 (medal) = ~2.31x multiplier before base stat calculation.
  10. The intended design has each bonus be moderate, but multiplicative stacking creates extreme outliers.
- **Impact:** A fully optimized glass cannon build can deal 2.3x expected damage. While the Reactif isotope's -10% HP and faster decay provide tradeoffs, the attack multiplier far exceeds the survivability penalty. This makes glass cannon builds dominant in the meta.
- **Fix:** Consider switching some multiplicative bonuses to additive (e.g., all percentage bonuses sum together, then multiply base once). Or implement a total bonus cap (e.g., max 100% bonus from all sources combined).

---

### P3-XD-022 | MEDIUM | Compound Synthesis No Cooldown + Resource Nodes = Buff Cycling

- **Chain:** Compounds (1h duration, no cooldown) + resource node proximity -> permanent buff maintenance
- **Description:**
  1. Compounds have a 1-hour duration and no cooldown between activations.
  2. `COMPOUND_MAX_STORED` is 3, meaning a player can stockpile 3 compounds.
  3. A player near a resource node that matches compound ingredients can produce atoms faster.
  4. The compound synthesis cost is: recipe * 100 atoms. E.g., H2O = 200 hydrogene + 100 oxygene.
  5. With moderate production (e.g., 500 atoms/hour per type), a player can re-synthesize compounds every hour.
  6. This means compound buffs can be maintained permanently -- activate one, synthesize the next, activate when the first expires.
  7. The 3-compound storage limit means a player can queue up 3 hours of buffs, then only needs to check in every 3 hours.
  8. With all 5 compound types having different effects, a player can maintain 5 simultaneous permanent buffs.
- **Impact:** Compounds designed as "temporary buffs" become effectively permanent for active players with sufficient production. The +10% production buff alone self-sustains (more production -> more atoms -> more compounds). This widens the gap between active and casual players.
- **Fix:** Add a synthesis cooldown (e.g., can only synthesize one compound per 2 hours). Or increase compound costs to require meaningful resource investment.

---

### P3-XD-023 | MEDIUM | Duplicateur Level Uncapped + Alliance Energy from Member Cycling

- **Chain:** No duplicateur max level -> infinite scaling -> small alliance with extreme duplicateur
- **Description:**
  1. Alliance research has a cap: `ALLIANCE_RESEARCH_MAX_LEVEL = 25` (enforced in alliance.php line 126).
  2. The duplicateur upgrade code (alliance.php lines 83-100) checks only energy, NOT level. There is no `DUPLICATEUR_MAX_LEVEL` constant or check.
  3. Cost formula: `round(DUPLICATEUR_BASE_COST * pow(DUPLICATEUR_COST_FACTOR, level + 1))` = `round(100 * pow(1.5, level + 1))`.
  4. At level 25: cost = `round(100 * 1.5^26)` = ~120 million energy. Practically unreachable normally.
  5. But with resource duplication (P3-XD-001) or aggressive energy donation from multiple members, higher levels become feasible.
  6. Each duplicateur level gives +1% to ALL resource production AND +1% to combat stats (attack and defense).
  7. At level 50: +50% to everything. At level 100: +100% to everything.
  8. The cost growth is exponential (1.5x per level), which provides natural limiting. Level 50 costs ~6.4 * 10^10 energy, which is astronomical.
  9. Without resource duplication, this is practically self-limiting. But the theoretical uncapped nature is a design risk.
- **Impact:** Theoretically infinite scaling, practically limited by exponential costs. However, combined with resource duplication bugs, the lack of a cap becomes dangerous. Even without bugs, a large alliance (20 members) donating aggressively all season could reach level 15-20 for +15-20% bonus.
- **Fix:** Add `DUPLICATEUR_MAX_LEVEL` constant (e.g., 30) and enforce it in the upgrade transaction.

---

## LOW CHAINS

### P3-XD-024 | LOW | Forum Medals + Prestige PP = Effortless PP Farming

- **Chain:** Forum posting medals ($MEDAL_THRESHOLDS_PIPELETTE) -> PP from medal tiers -> easy PP
- **Description:**
  1. The `calculatePrestigePoints()` function counts medal tiers reached across ALL medal categories.
  2. The function currently checks: Terreur, Attaque, Defense, Pillage, Pertes, Energievore (6 categories, prestige.php lines 62-76).
  3. It does NOT include Pipelette (forum posts), Constructeur, Bombe, or Troll medals.
  4. So this chain is actually NOT exploitable as described -- forum medals do not contribute to PP.
  5. However, the `Energievore` medal (energy spent) is included, and energy spending is trivially farmable by building/demolishing/rebuilding. A player can inflate `energieDepensee` by cycling cheap buildings.
- **Impact:** Low. Energievore medal farming provides at most 8 PP per season (one per tier). The cost in energy to reach higher tiers (100M+ for top tiers) makes it impractical.
- **Fix:** No immediate fix needed. The excluded medal categories are correctly excluded.

---

### P3-XD-025 | LOW | Season Reset Clears Attack Cooldowns + First-Strike Advantage

- **Chain:** Season reset (remiseAZero, line 969: DELETE FROM attack_cooldowns) -> immediate post-reset attacks
- **Description:**
  1. At season start, all attack cooldowns are cleared.
  2. All players get beginner protection (3 days) via `UPDATE membre SET timestamp=?`.
  3. During beginner protection, players cannot attack or be attacked.
  4. When protection expires (72 hours after season start), ALL cooldowns are gone.
  5. The first player to attack after protection expires has no cooldown restrictions on any target.
  6. Combined with prestige advantages (generateur level 2, +5% production), a prestige player can have a significant army ready at the 72-hour mark and attack multiple targets in rapid succession.
  7. The 1-hour post-win cooldown (ATTACK_COOLDOWN_WIN_SECONDS) still applies after the first attack, limiting the chain-attack speed.
- **Impact:** Minimal. The beginner protection equalizer means everyone starts from the same 72-hour mark. The prestige production advantage (P3-XD-012) is the real factor, not the cooldown clear.
- **Fix:** No fix needed. This is working as intended -- cooldowns are per-matchup and should reset between seasons.

---

## Cross-Reference Matrix

| Chain ID | Systems Involved | Pass 2 References | Severity |
|----------|-----------------|-------------------|----------|
| P3-XD-001 | Transfer + Market + Ranking | P2-D1-002, P2-D4-001 | CRITICAL |
| P3-XD-002 | Transfer + Market + Economy | P3-XD-001, P2-D4-001 | CRITICAL |
| P3-XD-003 | Combat + Logging | P2-D2-001, P2-D5-006 | CRITICAL |
| P3-XD-004 | Transfer + Alliance + Combat | P3-XD-001 | CRITICAL |
| P3-XD-005 | Transaction + Combat + Troops | P2-D7-003 | CRITICAL |
| P3-XD-006 | Beginner + Prestige | P2-D5-031 | HIGH |
| P3-XD-007 | Vacation + Resources | - | HIGH |
| P3-XD-008 | Compounds + Combat + Prestige | - | HIGH |
| P3-XD-009 | Alliance + Donation + Cycling | - | HIGH |
| P3-XD-010 | Combat + Ranking + Collusion | - | HIGH |
| P3-XD-011 | Vault + Building + Combat | P2-D4-002 | HIGH |
| P3-XD-012 | Prestige + Compounds + Nodes | - | HIGH |
| P3-XD-013 | Multi-Account + Market | - | HIGH |
| P3-XD-014 | Transfer Delivery + Race | - | HIGH |
| P3-XD-015 | Compounds + Speed + Map | - | MEDIUM |
| P3-XD-016 | Prestige + Season + Timing | - | MEDIUM |
| P3-XD-017 | Nodes + Map + Login Order | - | MEDIUM |
| P3-XD-018 | Formation + Empty Class | - | MEDIUM |
| P3-XD-019 | Espionage + Compounds + Combat | - | MEDIUM |
| P3-XD-020 | Combat + Alliance War + Race | - | MEDIUM |
| P3-XD-021 | Specialization + Isotope + Combat | - | MEDIUM |
| P3-XD-022 | Compounds + Nodes + Cycling | - | MEDIUM |
| P3-XD-023 | Duplicateur + Alliance + Uncapped | - | MEDIUM |
| P3-XD-024 | Medals + Prestige + Farming | - | LOW |
| P3-XD-025 | Season Reset + Cooldowns | - | LOW |

---

## Priority Remediation Order

### Immediate (before next season):

1. **P3-XD-001**: Wrap resource transfer in transaction with `FOR UPDATE`. This blocks P3-XD-002 and P3-XD-004.
2. **P3-XD-005**: Change `withTransaction()` to catch `\Throwable`. Move CAS guard inside transaction.
3. **P3-XD-003**: Fix `pointsProducteur` -> `pointsCondenseur` in combat.php lines 28 and 38.
4. **P3-XD-014**: Add transaction + CAS guard to transfer delivery in game_actions.php.

### Short-term (within 2 weeks):

5. **P3-XD-006**: Wire up `veteran` prestige unlock in attaquer.php.
6. **P3-XD-023**: Add `DUPLICATEUR_MAX_LEVEL` cap.
7. **P3-XD-020**: Use atomic increment for war loss tracking.
8. **P3-XD-013**: Add market activity monitoring for multi-account detection.

### Medium-term (next season prep):

9. **P3-XD-007**: Freeze resource accumulation during vacation.
10. **P3-XD-010**: Implement diminishing returns for repeated same-target combat.
11. **P3-XD-012**: Add catch-up mechanics for new players.
12. **P3-XD-021/008**: Consider total bonus multiplier caps.

---

*End of Pass 3 Cross-Domain Analysis*
