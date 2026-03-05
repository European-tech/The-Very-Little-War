# Ultra Audit Pass 1 — Domain 4: Game Balance & Strategy

**Date:** 2026-03-05
**Pass:** 1 (Broad Scan)
**Subagents:** 5 (Economy, Combat, Win Conditions, Formula Edge Cases, Specializations)

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 5 |
| MEDIUM | 24 |
| LOW | 17 |
| **Total** | **46** |

---

## Area 1: Economy & Progression Curves (10 findings)

#### P1-D4-001: Exponential growth (1.15/1.20/1.25) creates runaway leader problem
- **Severity:** MEDIUM — **Location:** config.php:ECO_GROWTH_BASE/ADV/ULT
- **Description:** Day-1 early starter has 10x resource advantage by day 20. Stabilisateur at 1.25 compounds fastest.
- **Fix:** Reduce to 1.12/1.18/1.22 or implement catch-up bonus for lower-ranked players — **Effort:** L

#### P1-D4-002: Energy balance razor-thin at mid-game
- **Severity:** MEDIUM — **Location:** formulas.php:drainageProducteur(), game_resources.php:updateRessources()
- **Description:** Producteur level 20 drains 1,060 energy/hr (8 buildings) requiring generateur level 14+. No UI warning.
- **Fix:** Add energy deficit warning in dashboard — **Effort:** S

#### P1-D4-003: Storage (depot) is never a bottleneck — well balanced
- **Severity:** LOW — **Location:** formulas.php:placeDepot()
- **Description:** Storage grows at same 1.15 rate as production. Good design, no action needed.
- **Fix:** None required — **Effort:** N/A

#### P1-D4-004: Prestige production bonus (1.05x) is negligible
- **Severity:** LOW — **Location:** config.php:PRESTIGE_PRODUCTION_BONUS
- **Description:** 5% bonus invisible next to medal bonuses (1-50%). Veterans don't notice it.
- **Fix:** Increase to 1.10 (10%) or give prestige unique benefits — **Effort:** S

#### P1-D4-005: Alliance duplicateur stacking potential
- **Severity:** MEDIUM — **Location:** config.php:DUPLICATEUR_BONUS_PER_LEVEL, DUPLICATEUR_COST_FACTOR
- **Description:** No hard cap on duplicateur level. Level 100+ yields 2x+ bonus. Cost factor 1.5 makes high levels expensive but not impossible for funded alliances.
- **Fix:** Add DUPLICATEUR_MAX_LEVEL = 50 — **Effort:** S (cross-ref P1-D4-079)

#### P1-D4-006: Compound cost-effectiveness varies widely
- **Severity:** LOW — **Location:** config.php:$COMPOUNDS
- **Description:** H2O (+15% defense, 1hr) costs 300 atoms; CO2 (+10% attack, 1hr) costs 500 atoms. Cost/benefit ratio inconsistent across 5 compounds.
- **Fix:** Normalize atom costs relative to effect magnitude — **Effort:** S

#### P1-D4-007: Resource node RNG creates unfair positional advantage
- **Severity:** MEDIUM — **Location:** includes/resource_nodes.php
- **Description:** +10% production bonus from proximity. Random node placement gives some players 74k atom advantage over 31-day season.
- **Fix:** Guarantee minimum node proximity for all players, or reduce bonus to 5% — **Effort:** M

#### P1-D4-008: Break-even analysis shows strategic buildings pay off too late
- **Severity:** LOW — **Location:** formulas.php, config.php building costs
- **Description:** Coffrefort break-even requires being attacked ~5 times. Ionisateur ROI only realized in late game (day 20+).
- **Fix:** Document intended progression in player guide; consider cost reduction for utility buildings — **Effort:** S

#### P1-D4-009: Iode catalyst multiplicative bonus can double production
- **Severity:** MEDIUM — **Location:** config.php:IODE_CATALYST_MAX_BONUS
- **Description:** At 50k iode, catalyst provides 100% production bonus (2x). This creates a dominant meta around iode maximization.
- **Fix:** Reduce IODE_CATALYST_MAX_BONUS from 1.0 to 0.5 (max +50%) — **Effort:** XS

#### P1-D4-010: Beginner protection only 3 days — insufficient for new players
- **Severity:** MEDIUM — **Location:** config.php:BEGINNER_PROTECTION_DAYS
- **Description:** 3 days allows generateur level 5-10. Insufficient to build meaningful defenses before being attacked.
- **Fix:** Extend to 5-7 days — **Effort:** XS

---

## Area 2: Combat Balance & Strategy Diversity (12 findings)

#### P1-D4-020: Dominant army composition — glass cannon + meat shield solves the game
- **Severity:** HIGH — **Location:** combat.php:166-298, formulas.php:attaque/defense
- **Description:** Sequential damage model + quadratic atom efficiency creates one optimal composition: Class 1 = pure tank (Br+C), Classes 2-4 = pure damage (O+H). No rock-paper-scissors mechanic exists.
- **Fix:** Add formation-based counter bonuses or diverse army composition reward — **Effort:** L

#### P1-D4-021: Phalange formation overpowered with high-defense class 1
- **Severity:** MEDIUM — **Location:** combat.php:222-256, config.php:FORMATION_PHALANX_ABSORB
- **Description:** 60% absorb + 20% defense bonus to class 1. Synergizes with high-C class 1 tank for known win condition.
- **Fix:** Reduce FORMATION_PHALANX_ABSORB from 0.60 to 0.50 — **Effort:** XS

#### P1-D4-022: Embuscade formation underwhelming (+25% attack only, condition-dependent)
- **Severity:** MEDIUM — **Location:** config.php:FORMATION_EMBUSCADE_*
- **Description:** Embuscade requires speed advantage for +25% attack. Win rate ~22-28% in simulations. Niche use insufficient to compete.
- **Fix:** Increase attack bonus to +35% or reduce speed requirement — **Effort:** XS

#### P1-D4-023: Réactif isotope often superior — decay irrelevant in 30-day season
- **Severity:** MEDIUM — **Location:** config.php:ISOTOPE_REACTIF_*
- **Description:** +20% attack / -10% HP / +faster decay. But stabilisateur makes decay negligible, so Réactif has no real downside.
- **Fix:** Increase Réactif decay penalty or make it apply before stabilisateur — **Effort:** S

#### P1-D4-024: Attack is 2.6x more profitable than defense (asymmetric EV)
- **Severity:** MEDIUM — **Location:** combat.php (pillage logic)
- **Description:** Attacker pillages resources + gets ranking points. Defender only gets 20% of pillage capacity as reward. Attack EV massively exceeds defense.
- **Fix:** Increase defense reward from 20% to 30-40% — **Effort:** XS

#### P1-D4-025: Cooldown permissive — 1h win / 4h loss allows repeated targeting
- **Severity:** LOW — **Location:** config.php:COOLDOWN_WIN/COOLDOWN_LOSS
- **Description:** 1-hour win cooldown allows 24 attacks/day on different targets. Enables systematic farming.
- **Fix:** Consider per-target cooldown of 8h — **Effort:** S

#### P1-D4-026: Building damage random targeting — too RNG-dependent
- **Severity:** LOW — **Location:** combat.php (building damage section)
- **Description:** Weighted random targeting means critical buildings (generateur, producteur) can be hit disproportionately. Players have no control over defense allocation.
- **Fix:** Allow players to set building defense priority — **Effort:** M

#### P1-D4-027: Vault protection (2%/level, max 50%) makes coffrefort mandatory
- **Severity:** LOW — **Location:** config.php:VAULT_PROTECTION_PER_LEVEL
- **Description:** Without coffrefort, all resources exposed to pillage. With level 25, 50% protected. Creates binary "must build" dynamic.
- **Fix:** Give baseline 10% protection without coffrefort — **Effort:** XS

#### P1-D4-028: Soufre (S) pillage bonus may be too profitable
- **Severity:** LOW — **Location:** formulas.php:capacitePillage()
- **Description:** S atoms directly increase pillage capacity. Combined with attack superiority, creates wealth concentration spiral.
- **Fix:** Add diminishing returns to pillage formula at high S counts — **Effort:** S

#### P1-D4-029: Chlore (Cl) speed bonus rarely matters
- **Severity:** LOW — **Location:** formulas.php:vitesse()
- **Description:** Speed mainly affects travel time and Embuscade formation. Neither is decisive in current meta.
- **Fix:** Give speed additional combat benefits (initiative, retreat chance) — **Effort:** M

#### P1-D4-030: Catalytique isotope self-sacrifice unclear in benefit
- **Severity:** LOW — **Location:** config.php:ISOTOPE_CATALYTIQUE_*
- **Description:** -10% self stats to give +15% to allies. Net gain positive only with 2+ other classes present.
- **Fix:** Improve tooltip to show net team benefit calculation — **Effort:** XS

#### P1-D4-031: Single attack can pillage 20 months of production
- **Severity:** MEDIUM — **Location:** combat.php (pillage calculation)
- **Description:** No per-attack pillage cap means one devastating attack can strip a player's entire stockpile.
- **Fix:** Add per-attack pillage cap (e.g., max 50% of target's resources) — **Effort:** S

---

## Area 3: Win Conditions & Ranking (10 findings)

#### P1-D4-040: SQRT ranking formula is well-designed
- **Severity:** LOW — **Location:** formulas.php:calculerScore()
- **Description:** SQRT prevents single-category dominance. All 5 archetypes viable in tests. Balanced player beats specialist.
- **Fix:** None — well balanced — **Effort:** N/A

#### P1-D4-041: Attack+Defense combined weight (3.0) favors combat-first strategies
- **Severity:** MEDIUM — **Location:** config.php:RANKING_WEIGHT_ATTACK/DEFENSE
- **Description:** Combat total weight 3.0 vs construction 1.0, trade 1.0, pillage 1.2. Intentional design choice per commit history but limits non-combat viability.
- **Fix:** Consider reducing to 1.3/1.3 if playtesting shows combat dominance — **Effort:** XS

#### P1-D4-042: Pure trader can rank #1 but requires extreme dedication
- **Severity:** LOW — **Location:** formulas.php:calculerScore()
- **Description:** Trader needs ~76k trade volume vs balanced player's ~2k per category. Achievable but impractical.
- **Fix:** Increase trade weight to 1.2 to improve trader viability — **Effort:** XS

#### P1-D4-043: Pillage weight (1.2) is well-calibrated
- **Severity:** LOW — **Location:** config.php:RANKING_WEIGHT_PILLAGE
- **Description:** Between construction (1.0) and combat (1.5). Tanh cap at 80 points prevents runaway pillage ranking.
- **Fix:** None — balanced — **Effort:** N/A

#### P1-D4-044: Victory points distribution too top-heavy
- **Severity:** MEDIUM — **Location:** formulas.php:pointsVictoireJoueur()
- **Description:** Rank 1 gets 100 VP, rank 50 gets ~6 VP. Creates winner-take-all dynamic that discourages mid-tier players.
- **Fix:** Flatten curve: rank 1 = 80 VP, increase rank 11-50 awards — **Effort:** S

#### P1-D4-045: Medal tier bonuses — Diamant Rouge (50%) too powerful
- **Severity:** MEDIUM — **Location:** config.php:MEDAL_TIER_BONUSES
- **Description:** 50% production/combat bonus for top veterans creates insurmountable advantage. Cross-season cap (10%) mitigates but 50% within season is extreme.
- **Fix:** Reduce Diamant Rouge cap to 30% — **Effort:** XS

#### P1-D4-046: Prestige PP concentration — top 5% earns 50+ PP/season
- **Severity:** LOW — **Location:** prestige.php
- **Description:** Bottom 95% earns 0-10 PP. Slow unlock progression for casual players. Acceptable as long-term retention hook.
- **Fix:** Add PP floor of 5 PP for active participation — **Effort:** S

#### P1-D4-047: Grace period (14 days, Gold 6% cap) is fair
- **Severity:** LOW — **Location:** config.php:MEDAL_GRACE_PERIOD_*
- **Description:** New players get 14 days before full medal bonuses kick in. Gold (6%) cap during grace prevents veteran snowball.
- **Fix:** None — well designed — **Effort:** N/A

#### P1-D4-048: Multiple strategies viable — confirmed by test suite
- **Severity:** LOW — **Location:** tests/balance/StrategyViabilityTest.php
- **Description:** Raider, Turtle, Pillager, Speedster, Balanced all pass viability tests. No single dominant strategy in ranking.
- **Fix:** None — design working as intended — **Effort:** N/A

#### P1-D4-049: Market points cap too low (80 points via tanh)
- **Severity:** MEDIUM — **Location:** formulas.php:pointsMarche()
- **Description:** Market/trade ranking capped at 80 points via tanh curve. Limits economic players' ranking ceiling.
- **Fix:** Increase cap to 120 or add secondary trade scoring — **Effort:** S

---

## Area 4: Formula Edge Cases (14 findings)

#### P1-D4-060: Stabilisateur effectively eliminates decay at level 25+
- **Severity:** MEDIUM — **Location:** formulas.php:coefDisparition(), config.php:STABILISATEUR_ASYMPTOTE
- **Description:** Level 30 stabilisateur gives 10-day half-life for 200-atom molecule. Decay becomes irrelevant, removing strategic tension.
- **Fix:** Add DECAY_COEFFICIENT_FLOOR = 0.999 (minimum 19hr half-life) — **Effort:** S

#### P1-D4-061: Speed formula uncapped — Cl=200, N=200 gives 462x speed
- **Severity:** HIGH — **Location:** formulas.php:vitesse(), config.php:SPEED_SYNERGY_DIVISOR
- **Description:** Cl*N synergy term has no cap. Produces map-breaking speeds at high investment. Soft cap only applies to linear Cl term.
- **Fix:** Add SPEED_TOTAL_CAP = 50 on base speed before condenseur multiplier — **Effort:** S

#### P1-D4-062: Combat attack modifier stack reaches 8x — no global cap
- **Severity:** HIGH — **Location:** combat.php:170-195
- **Description:** All modifiers multiplicative: isotope(1.2) × ionisateur(2.0) × duplicateur(1.2) × catalyst(2.0) × prestige(1.05) × compound(1.1) × spec(1.1) × medal(1.1) = 8.05x.
- **Fix:** Add COMBAT_MODIFIER_CAP = 4.0 applied after all multipliers — **Effort:** M

#### P1-D4-063: Condenseur level has no hard cap — unbounded modCond() multiplier
- **Severity:** MEDIUM — **Location:** formulas.php:modCond()
- **Description:** modCond = 1 + n/50. Level 100 = 3x all stats. Most powerful single building lever.
- **Fix:** Add MODCOND_MAX = 2.0 or CONDENSEUR_LEVEL_HARD_CAP = 50 — **Effort:** S

#### P1-D4-064: Decay formula trivially long half-life for 0-atom molecules
- **Severity:** LOW — **Location:** formulas.php:coefDisparition()
- **Description:** 0-atom molecule has 20-day half-life instead of instant decay.
- **Fix:** Guard: if ($nbAtomes <= 0) return 1.0 — **Effort:** XS

#### P1-D4-065: Float precision boundary at stabilisateur level ~500
- **Severity:** LOW — **Location:** formulas.php:coefDisparition()
- **Description:** Existing >= 1.0 guard in demiVie() handles edge case correctly. No functional bug.
- **Fix:** Add comment documenting boundary — **Effort:** XS

#### P1-D4-066: VP curve has cliffs at rank 20→21 and 10→11 boundaries
- **Severity:** MEDIUM — **Location:** formulas.php:pointsVictoireJoueur()
- **Description:** Rank 20 = 15 VP, rank 21 = 11 VP (4-point cliff). Creates boundary camping incentive.
- **Fix:** Set VP_PLAYER_RANK21_50_BASE = 13 to smooth transition — **Effort:** XS

#### P1-D4-067: Market manipulation — 9 trades to reach price ceiling
- **Severity:** HIGH — **Location:** config.php:MARKET_VOLATILITY_FACTOR, MARKET_MEAN_REVERSION
- **Description:** With 1 active player, volatility = 0.30 per trade. Mean reversion only 0.01 per tick. Single player can pump/dump prices.
- **Fix:** Floor minimum volatility divisor: max(5, $nbActifs) — **Effort:** XS

#### P1-D4-068: Lieur linear bonus unbounded — level 25 gives 4.75x formation speedup
- **Severity:** MEDIUM — **Location:** formulas.php:bonusLieur()
- **Description:** Combined with catalyseur (+50%) and spec (+20%): 8.55x speedup. Removes formation time as strategic constraint.
- **Fix:** Cap bonusLieur at LIEUR_MAX_BONUS = 2.5 — **Effort:** S

#### P1-D4-069: Neutrino cost halved by max radar research
- **Severity:** LOW — **Location:** config.php:NEUTRINO_COST, alliance research radar
- **Description:** Level 25 radar = 50% cost reduction. Enables 30 neutrinos/hour continuous espionage.
- **Fix:** Add NEUTRINO_COST_MIN = 30 floor — **Effort:** XS

#### P1-D4-070: Alliance VP ranks 4-9 formula uses unclear constant reference
- **Severity:** MEDIUM — **Location:** formulas.php:pointsVictoireAlliance()
- **Description:** Uses VP_ALLIANCE_RANK2 base for ranks 4-9. Rank 9 = 1 VP, rank 10 = 0 VP cliff.
- **Fix:** Rename constant for clarity; give rank 10 token 1 VP — **Effort:** XS

#### P1-D4-071: Iode energy production quadratic — practically bounded
- **Severity:** LOW — **Location:** formulas.php:productionEnergieMolecule()
- **Description:** At iode=200, producteur=30: 205 energy/molecule. 1000 molecules = 205k energy. Outscales buildings by design.
- **Fix:** Document as intentional design choice — **Effort:** XS

#### P1-D4-072: NH3 compound +20% speed on already-extreme speed values
- **Severity:** MEDIUM — **Location:** config.php:$COMPOUNDS['NH3'], compounds.php
- **Description:** Applied to 462x base speed = 554x. Effectively teleportation.
- **Fix:** Add SPEED_ABSOLUTE_CAP = 100 in movement code — **Effort:** S (cross-ref P1-D4-061)

#### P1-D4-073: Catalytique + Stable isotope combined modifier can stack to 1.45x
- **Severity:** MEDIUM — **Location:** combat.php:172-178
- **Description:** Stable HP mod (+0.30) + Catalytique ally bonus (+0.15) = 1.45x HP on same class.
- **Fix:** Cap combined isotope modifier per class at 1.40 — **Effort:** S

---

## Area 5: Specializations & Late-Game Depth (10 findings)

#### P1-D4-080: Combat specialization (Oxydant vs Réducteur) is balanced
- **Severity:** LOW — **Location:** config.php:$SPECIALIZATIONS['combat']
- **Description:** +10%/-5% trade-off. Both situationally optimal. Genuine strategic choice.
- **Fix:** None — well designed — **Effort:** N/A

#### P1-D4-081: Economy specialization — Industriel likely dominates Énergétique
- **Severity:** MEDIUM — **Location:** config.php:$SPECIALIZATIONS['economy']
- **Description:** Atoms are universal currency; energy is more abundant. +20% atoms likely always better than +20% energy.
- **Fix:** If >90% choose Industriel, rebalance to +15% atoms / +25% energy — **Effort:** XS

#### P1-D4-082: Research specialization — balanced but trade-off clarity needed
- **Severity:** LOW — **Location:** config.php:$SPECIALIZATIONS['research']
- **Description:** Théorique (+2 cond pts, -20% formation) vs Appliqué (+20% formation, -1 cond pt). Both viable.
- **Fix:** Improve UI to clarify stat implications — **Effort:** XS

#### P1-D4-083: condenseur_points modifier may not be fully applied
- **Severity:** MEDIUM — **Location:** player.php, formulas.php:modCond()
- **Description:** Théorique adds +2 cond pts/level but modCond() may not differentiate specialty. Implementation needs verification.
- **Fix:** Verify condenseur_points modifier is wired into modCond() — **Effort:** S (cross-ref previous audit P1-D2-089)

#### P1-D4-084: Alliance research — Duplicateur+Catalyseur always researched first
- **Severity:** MEDIUM — **Location:** config.php:$ALLIANCE_RESEARCH
- **Description:** Radar, Réseau, Bouclier have worse cost/benefit ratios (2.0-2.5 cost growth vs 1.5 for Duplicateur). Creates locked meta.
- **Fix:** Reduce cost growth for underused techs: 2.0→1.8, 2.5→2.0 — **Effort:** S

#### P1-D4-085: Compound adoption unknown — may be underutilized
- **Severity:** LOW — **Location:** laboratoire.php, compounds.php
- **Description:** 5 compounds, 1-hour duration, max 3 stored. No analytics to measure adoption.
- **Fix:** Add usage tracking; add UI prompts if adoption < 20% — **Effort:** S

#### P1-D4-086: Late-game activity vacuum after buildings maxed
- **Severity:** MEDIUM — **Location:** (design-level)
- **Description:** Once buildings hit effective ceiling (~level 25-30), endgame is incremental. Alliance wars and market are only content.
- **Fix:** Add endgame objectives: achievements, seasonal challenges, territory control — **Effort:** L

#### P1-D4-087: Season reset feels punishing without prestige payoff
- **Severity:** LOW — **Location:** config.php, basicprivatephp.php (season reset)
- **Description:** Losing everything is harsh. Prestige rewards (5% bonus) feel insufficient.
- **Fix:** Increase prestige rewards or add cosmetic season rewards — **Effort:** M

#### P1-D4-088: Day-20 joiner cannot compete within same season
- **Severity:** MEDIUM — **Location:** (design-level)
- **Description:** Exponential growth means day-20 joiner has ~1/100th the resources of day-1 starter. Beginner protection and nodes don't compensate.
- **Fix:** Add escalating catch-up bonus for late joiners (e.g., +50% production for first 7 days) — **Effort:** M

#### P1-D4-089: Stale comment in combat.php says 70% when constant is 60%
- **Severity:** LOW — **Location:** combat.php:223
- **Description:** Comment references old FORMATION_PHALANX_ABSORB value (0.70) but constant is now 0.60.
- **Fix:** Update comment — **Effort:** XS

---

## Cross-Domain References

| Finding | Related |
|---------|---------|
| P1-D4-005 | P1-D4-079 (duplicateur cap) |
| P1-D4-061 | P1-D4-072 (speed cap) |
| P1-D4-062 | P1-D2-030 (withTransaction Throwable) |
| P1-D4-067 | P1-D1-xxx (market manipulation exploit) |
| P1-D4-083 | P1-D2-089 (condenseur_points modifier) |
