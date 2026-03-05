# Pass 4 — Edge Cases & Adversarial Math Audit
**The Very Little War** | PHP 8.2 | 2026-03-05

Auditor approach: Every formula tested at zero, one, maximum int, negative, float boundaries,
divisor-approaches-zero, log/sqrt of non-positive, soft cap interactions, and integer overflow.

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 4     |
| HIGH     | 12    |
| MEDIUM   | 10    |
| LOW      | 7     |
| **Total**| **33**|

---

## CRITICAL

---

### P4-EC-001 | CRITICAL | Division by Zero in "Storage Full" Time Display

- **Formula:** `player.php:324`
  ```php
  date('d/m/Y', time() + SECONDS_PER_HOUR * ($placeDepot - $ressources['energie']) / $revenu['energie'])
  ```
- **Edge case:** `$revenu['energie']` equals 0 (generateur level 0 with heavy producteur drain,
  or producteur drain exactly cancels generator output, or generator level 0).
  `revenuEnergie()` returns `max(0, round($prodProducteur))` — it can legally return 0 when
  `drainageProducteur` equals or exceeds gross production.
- **Result:** PHP fatal `DivisionByZeroError` / `Warning: Division by zero`. The `effetSup` string
  is built unconditionally in `initPlayer()` without checking `$revenuEnergie > 0` first.
- **Impact:** Every page load that calls `initPlayer()` crashes with a PHP warning (or fatal on
  strict PHP 8 setups) for any player whose net energy income is exactly 0. Affects the main
  game dashboard.
- **Fix:**
  ```php
  'effetSup' => $revenuEnergie > 0
      ? '<br/><br/><strong>Stockage plein : </strong>' . date('d/m/Y',
          time() + SECONDS_PER_HOUR * ($placeDepot - $ressources['energie']) / $revenuEnergie)
        . ' à ' . date('H\hi',
          time() + SECONDS_PER_HOUR * ($placeDepot - $ressources['energie']) / $revenuEnergie)
      : '',
  ```

---

### P4-EC-002 | CRITICAL | coefDisparition() Produces coefficient > 1.0 with High Medal + Réactif Isotope

- **Formula:** `formulas.php:247-265`
  ```php
  $rawDecay    = pow(0.99, pow(1 + $nbAtomes/150, 1.5) / 25000);
  $modStab     = pow(0.98, $stabilisateur_level);
  $modMedal    = 1 - ($bonus / 100);   // bonus can be 50 at Diamant Rouge tier
  $baseDecay   = pow($rawDecay, $modStab * $modMedal);
  // Then for ISOTOPE_STABLE: pow($baseDecay, 1.0 + (-0.30)) = pow($baseDecay, 0.70)
  ```
- **Edge case:** Diamond Rouge medal tier gives `bonus = 50`, so `$modMedal = 1 - 0.50 = 0.50`.
  With a high-level stabilisateur (e.g., level 30): `modStab = pow(0.98, 30) ≈ 0.5455`.
  Combined exponent = `0.5455 * 0.50 = 0.2727`.
  `rawDecay` for a small molecule (say 10 atoms) ≈ `0.99^(pow(1.067, 1.5)/25000)` ≈ `0.99999972`.
  `baseDecay = pow(0.99999972, 0.2727)` ≈ `0.9999992` (still < 1.0 — fine here).
  BUT with Réactif isotope applied: `pow(baseDecay, 1.0 + 0.20) = pow(0.9999992, 1.20)` — still OK.
  The real trigger is the **inverse**: ISOTOPE_STABLE with exponent 0.70 applied to an already
  very-close-to-1.0 rawDecay. With `nbAtomes = 0` (empty molecule):
  `rawDecay = pow(0.99, pow(1, 1.5)/25000) = pow(0.99, 0.00004) ≈ 0.9999996`.
  `modStab * modMedal` could be < 0 if `$bonus > 100`. Config caps medal bonuses at 50% via
  `MAX_CROSS_SEASON_MEDAL_BONUS = 10` in `computeMedalBonus()`, BUT `coefDisparition()` reads
  `$bonus` directly from `$bonusMedailles[$num]` via `$paliersPertes` — it calls the raw
  medal array, NOT `computeMedalBonus()`. The `$bonusMedailles` array holds values up to 50.
  If `$bonus = 50`, `$modMedal = 0.50`. With stab=50: `modStab ≈ 0.364`. Combined = `0.182`.
  `baseDecay = pow(0.9999996, 0.182)` ≈ `0.9999999`. Then Réactif: `pow(0.9999999, 1.20)`
  still < 1. However with `$bonus` somehow forced to 100+ (impossible via config, but worth
  noting): `$modMedal` goes negative, `pow(rawDecay, negative)` = value > 1.0, meaning
  molecules **grow** instead of decay. The actual bug is subtler: `$modMedal` is used as an
  exponent modifier — with `bonus = 50` it halves the decay, effectively making decay
  coefficient approach 1.0 very closely. This interacts badly with `demiVie()` which checks
  `$coef >= 1.0` to return `PHP_INT_MAX`, but `coefDisparition()` itself has no such guard.
  **Confirmed real bug:** `$modMedal = 1 - (bonus/100)`. If `$bonus` array value exceeds 100
  (an admin typo in `$bonusMedailles`), `$modMedal` goes negative. Then
  `pow(rawDecay, negative)` where `rawDecay < 1.0` gives result > 1.0. Molecules will grow
  without bound in `updateRessources()` — `pow(coef > 1, nbsecondes)` explodes exponentially.
- **Impact:** With a misconfigured medal value > 100%, molecules multiply instead of decaying.
  A player could accumulate astronomically large armies by staying offline. Also, the code
  that computes `$bonus` bypasses the `MAX_CROSS_SEASON_MEDAL_BONUS` cap used in combat.
- **Fix:**
  ```php
  // In coefDisparition(), after computing $baseDecay:
  $baseDecay = min(0.9999999, max(0.0, $baseDecay)); // clamp: never >= 1.0, never negative
  ```
  Also enforce cap: replace the raw medal bonus lookup in `coefDisparition()` with a call to
  `computeMedalBonus()` to honour `MAX_CROSS_SEASON_MEDAL_BONUS`.

---

### P4-EC-003 | CRITICAL | tempsFormation() Division by Zero When vitesse_form Rounds to Zero

- **Formula:** `formulas.php:200-209`
  ```php
  $vitesse_form = (1 + pow($azote, 1.1) * (1 + $iode / 200)) * modCond($nivCondN) * $bonus_lieur;
  // ...
  return ceil(($ntotal / $vitesse_form) * 100) / 100;
  ```
- **Edge case:** `$azote = 0`, `$iode = 0`, `$nivCondN = 0`, `$nivLieur = 0`:
  `$vitesse_form = (1 + 0 * 1) * 1.0 * 1.0 = 1.0`. Fine at these values.
  BUT: `$bonus_lieur = bonusLieur($nivLieur)` = `1 + 0 * 0.15 = 1.0`. Also fine.
  The spec modifier `(1 + $specFormationMod)` is applied when `$joueur !== null`. The
  'Théorique' specialization gives `formation_speed = -0.20` (a 20% slowdown), so
  `(1 + (-0.20)) = 0.80`. The 'Appliqué' spec applies `+0.20`. Neither makes it zero.
  However, `$catalystSpeedBonus = 1 + catalystEffect('formation_speed')`. If a catalyst
  returned `-1.0` for `formation_speed` (theoretically impossible from config, but if
  `catalystEffect()` returns a float from the DB with an admin-set negative value), then
  `$catalystSpeedBonus = 0.0` and `$vitesse_form = 0.0`, causing **division by zero** in
  the `ceil()` call.
  More practically: if `$ntotal = 0` (zero molecules to form), `ceil(0 / 1.0 * 100) / 100 = 0`.
  This is fine numerically but returns 0 seconds — the UI shows formation as instant.
  A player can create 0-molecule formations which complete immediately and add 0 molecules —
  wasting a formation slot with no validation penalty.
- **Impact:** With malformed catalyst data, division by zero crashes formation time calc.
  With `ntotal = 0`, formations can be spammed to occupy both formation slots without
  producing anything, blocking legitimate formations.
- **Fix:** Add `$vitesse_form = max(0.001, $vitesse_form)` before the division. Add server-side
  validation rejecting `ntotal <= 0` formation requests.

---

### P4-EC-004 | CRITICAL | Integer Overflow Risk in massDestroyedAttacker Combat Points

- **Formula:** `combat.php:593-597`
  ```php
  $massDestroyedAttacker += ${'classe' . $i . 'AttaquantMort'} * $attAtoms;
  $massDestroyedDefender += ${'classe' . $i . 'DefenseurMort'} * $defAtoms;
  // ...
  $battlePoints = min(COMBAT_POINTS_MAX_PER_BATTLE,
      floor(COMBAT_POINTS_BASE + COMBAT_POINTS_CASUALTY_SCALE * sqrt($totalMassDestroyed / COMBAT_MASS_DIVISOR)));
  ```
- **Edge case:** `$attAtoms` is the sum of all 8 atom types in a molecule. Max atoms per element
  is 200 (config), so `$attAtoms` could reach `200 * 8 = 1600`. Number of molecules killed
  is unbounded in theory (a player could have millions of molecules after offline accumulation
  if decay is broken per EC-002). With 10 million molecules killed each with 1600 atoms:
  `$massDestroyedAttacker = 10,000,000 * 1600 = 16,000,000,000`. This exceeds PHP's 32-bit
  int range (2,147,483,647) on a 32-bit system. On 64-bit PHP (standard), this is fine up to
  ~9.2 × 10^18. However `sqrt(16e9 / 100) = sqrt(160,000,000) ≈ 12,649` — still within
  `COMBAT_POINTS_MAX_PER_BATTLE = 20`, so the cap protects the points value. The real risk is
  that `$massDestroyedAttacker` itself stored as a float accumulator in PHP can lose integer
  precision at values above 2^53 (~9 quadrillion). With truly astronomical armies (possible
  if EC-002 bug occurs), the mass variable becomes imprecise.
  The `UPDATE autre SET moleculesPerdues = moleculesPerdues + ?` with type `'ds'` stores a
  double — but the database column is likely an integer, causing silent truncation.
- **Impact:** If EC-002 (molecule growth) is triggered, combat mass calculations produce
  imprecise results. More immediately: the `moleculesPerdues` DB column receives a float
  where an integer is expected.
- **Fix:** Use integer accumulation with explicit `intval()` casts. Add a `min()` cap on
  `${'classe' . $i . 'AttaquantMort'}` to prevent impossible kill counts.

---

## HIGH

---

### P4-EC-005 | HIGH | coefDisparition() with nbAtomes = 0 Bypasses Decay Entirely

- **Formula:** `formulas.php:247`
  ```php
  $rawDecay = pow(DECAY_BASE, pow(1 + $nbAtomes / DECAY_ATOM_DIVISOR, DECAY_MASS_EXPONENT) / DECAY_POWER_DIVISOR);
  ```
- **Edge case:** `$nbAtomes = 0`:
  `pow(1 + 0/150, 1.5) = pow(1.0, 1.5) = 1.0`
  `rawDecay = pow(0.99, 1.0 / 25000) = pow(0.99, 0.00004) ≈ 0.99999960`
  This is extremely close to 1.0 — meaning a zero-atom molecule decays at roughly 0.000040%
  per second, or about 0.144% per hour. A molecule with zero atoms would survive for
  approximately 28,000 hours (>3 years of game time). Since `MOLECULE_MIN_HP = 10` protects
  against 0-brome insta-kill in combat, zero-atom molecules can exist (e.g., after the DB is
  reset or a formula is set to "Vide"). These near-immortal zero-atom molecules clog slots
  without being removable.
- **Impact:** "Vide" (empty) molecule slots decay at ~3 years half-life — effectively immortal.
  Players cannot naturally clear empty classes, which may block the slot for new molecules.
- **Fix:** Add an early-return in `coefDisparition()`: `if ($nbAtomes <= 0) return 0.0;` so
  empty molecules decay immediately. Or set minimum `$nbAtomes = 1` in the formula.

---

### P4-EC-006 | HIGH | Building HP Formula Returns 50 at Level 0 (Registration Bug)

- **Formula:** `formulas.php:284-285`
  ```php
  function pointsDeVie($niveau, $joueur = null) {
      $base_hp = round(BUILDING_HP_BASE * pow(max(1, $niveau), BUILDING_HP_POLY_EXP));
  ```
  `player.php:48`:
  ```php
  $vieCDF = vieChampDeForce(0); // called with level 0 at registration
  ```
- **Edge case:** `vieChampDeForce(0)` is called during player registration. The formula uses
  `max(1, $niveau)`, so level 0 is silently promoted to level 1:
  `round(125 * pow(1, 2.5)) = round(125 * 1) = 125`. This is the HP stored for a level-0
  champdeforce in the database.
  Then in combat, when `champdeforce` level is 0 (it starts at 0), the damage display
  calculates: `round($constructions['vieChampdeforce'] / vieChampDeForce(0) * 100)` which
  returns `round(125 / 125 * 100) = 100%`. This looks correct but is misleading: a level-0
  building should have 0 HP / not exist. The `diminuerBatiment` check (`if level > 1`) means
  level-0 buildings are immune to damage in the level-down branch, but the HP system still
  tracks them as 125 HP.
- **Impact:** Level-0 buildings have phantom HP (125 for champdeforce) stored in the DB.
  This HP gets subtracted during combat building damage, but since the building can't be
  reduced below level 0, the only effect is that `vieChampdeforce` goes negative in the DB
  and the percentage display shows negative values in combat reports.
- **Fix:** Registration should store `vieCDF = 0` for a level-0 champdeforce. Building HP
  functions should return 0 for level 0 without the `max(1, $niveau)` promotion.
  ```php
  if ($niveau <= 0) return 0;
  $base_hp = round(BUILDING_HP_BASE * pow($niveau, BUILDING_HP_POLY_EXP));
  ```

---

### P4-EC-007 | HIGH | Dispersed Formation Division by Zero When All Defender Classes Are Empty

- **Formula:** `combat.php:260-263`
  ```php
  $activeDefClasses = 0;
  for ($i = 1; $i <= $nbClasses; $i++) {
      if (${'classeDefenseur' . $i}['nombre'] > 0) $activeDefClasses++;
  }
  $sharePerClass = ($activeDefClasses > 0) ? $degatsAttaquant / $activeDefClasses : 0;
  ```
- **Edge case:** Protected by the ternary. However the `$overflow` accumulation loop below it
  only processes classes with `nombre > 0`. If all 4 defender classes have 0 molecules,
  `$sharePerClass = 0`, `$overflow = 0`, and `$defenseursRestants = 0`. The attacker wins
  automatically (all defenders dead) — `$gagnant = 2`. This is correct gameplay behaviour.
  However the **pillage phase** then runs with `$ressourcesTotalesDefenseur` potentially
  being non-zero (the player has resources even with no army). The pillage capacity is
  calculated from surviving attacker molecules — all alive since no defender damage occurred.
  An attacker can send a minimal army (1 molecule) against a completely undefended player
  and pillage their entire resource pool above vault protection. This is correct by design
  but the magnitude is extreme: with even 1 surviving S=200 molecule,
  `pillage(200, 0, ...) = round((pow(200,1.2) + 200) * 1) = round(794 + 200) = 994`.
  This means 994 atoms can be pillaged per molecule — a single molecule army can steal
  up to 994 resources from a defenceless player.
- **Impact:** Single-molecule armies can strip defenceless players. Not a code bug per se,
  but an extreme balance edge case where the formula produces disproportionate pillage.
- **Fix:** Implement a minimum army size requirement (e.g., at least 10 molecules must be
  sent to attack) or cap pillage per battle at some fraction of the attacker's army size.

---

### P4-EC-008 | HIGH | bonusLieur() Grows Without Upper Bound — Negative Formation Time Possible

- **Formula:** `formulas.php:193-195`
  ```php
  function bonusLieur($niveau) {
      return 1 + $niveau * LIEUR_LINEAR_BONUS_PER_LEVEL; // 1 + niveau * 0.15
  }
  ```
  `formulas.php:200`:
  ```php
  $vitesse_form = (1 + pow($azote, 1.1) * (1 + $iode / 200)) * modCond($nivCondN) * $bonus_lieur;
  ```
- **Edge case:** `LIEUR_LINEAR_BONUS_PER_LEVEL = 0.15`. There is no cap on building level in
  the codebase (buildings can theoretically be levelled indefinitely). At level 100:
  `bonusLieur(100) = 1 + 100 * 0.15 = 16.0`. Formation speed multiplier = `1 * 1 * 16 = 16`.
  For 1000 molecules: `tempsFormation(1000, 0, 0, 0, 100) = ceil(1000/16) = 63 seconds`.
  This is fast but valid. However the 'Appliqué' specialization adds `+0.20` to formation
  speed (as a modifier applied to `$vitesse_form`). The 'Théorique' spec adds `-0.20`.
  With `$specFormationMod = -0.20`: `$vitesse_form *= (1 + (-0.20)) = 0.80`. Still positive.
  The actual edge is alliance catalyst research at `ALLIANCE_RESEARCH_MAX_LEVEL = 25`:
  `allianceCatalyseurBonus = 1 + (25 * 0.02) = 1.50`. Combined with Théorique spec (−0.20):
  `vitesse_form *= 1.50 * (1 + (-0.20)) = 1.50 * 0.80 = 1.20`. Still positive. No true
  zero/negative path exists from config — HOWEVER: if someone adds a new catalyst effect
  returning a large negative `formation_speed`, `$catalystSpeedBonus` could go negative,
  making `$vitesse_form` negative. `ceil(ntotal / negative)` returns a negative float, and
  formation time is stored as a negative timestamp — placing the finish time in 1970, causing
  instant completions.
- **Impact:** A future misconfigured catalyst with a very large negative formation_speed value
  could grant instant (or already-past) formation completion to all players.
- **Fix:** Add `$vitesse_form = max(0.01, $vitesse_form)` guard before the division.

---

### P4-EC-009 | HIGH | pointsVictoireAlliance() Off-by-One at Rank 10

- **Formula:** `formulas.php:76-80`
  ```php
  if ($classement < 10) {
      return VP_ALLIANCE_RANK2 - $classement; // VP_ALLIANCE_RANK2 = 10
  }
  return 0;
  ```
- **Edge case:** `$classement = 4`: returns `10 - 4 = 6`. `$classement = 9`: returns `10 - 9 = 1`.
  `$classement = 10`: condition `< 10` is false, falls through to return 0.
  But `$classement = 3` returns `VP_ALLIANCE_RANK3 = 7` from the earlier branch.
  `$classement = 4` returns 6 from the `< 10` branch. The sequence is: 15, 10, 7, 6, 5, 4, 3,
  2, 1, 0. The issue is that rank 10 gets 0 points but rank 9 gets 1 — this is intentional
  but the comment in config.php says "Ranks 4-9: 10 - rank", which covers ranks 4 through 9
  correctly with `< 10`. However `$classement = 3` also satisfies `$classement < 10` but is
  caught by the earlier `== 3` check, so no double-counting. The real issue: rank 10 should
  logically receive some points per the formula pattern (`10 - 10 = 0`), so rank 10 always
  gets 0. This means there's an abrupt cliff at rank 10 vs rank 9.
  Additional issue: for rank 1, `VP_ALLIANCE_RANK2 - 1 = 10 - 1 = 9`, but rank 1 is caught
  by the `== 1` check first. For rank 2: `10 - 2 = 8`, but `VP_ALLIANCE_RANK2 = 10` should
  return 10. Rank 2 is caught by `== 2` first. BUT rank 4 falls through to `< 10` and
  returns `10 - 4 = 6`, not a proper documented value. The formula is inconsistent with
  the comment "Ranks 4-9: 10 - rank" because rank 4 using `10 - 4 = 6` makes rank 4 give
  fewer points than rank 3 (7). This step is actually intentional (7 > 6) but undocumented.
  **True bug:** if `$classement = 0` (a bug in ranking code), `10 - 0 = 10` — same as rank 2,
  which would give an alliance at rank 0 the same VP as rank 2.
- **Impact:** A rank-0 edge case (defensive coding gap) gives incorrect VP. Low probability
  but not guarded.
- **Fix:** Add guard: `if ($classement <= 0) return 0;` at the top of `pointsVictoireAlliance()`.

---

### P4-EC-010 | HIGH | modCond() Grows Unboundedly with No Upper Cap

- **Formula:** `formulas.php:140-143`
  ```php
  function modCond($niveauCondenseur) {
      return 1 + ($niveauCondenseur / COVALENT_CONDENSEUR_DIVISOR); // divisor = 50
  }
  ```
  Used in: `attaque()`, `defense()`, `pointsDeVieMolecule()`, `vitesse()`, `tempsFormation()`
- **Edge case:** There is no upper cap on condenseur level. At condenseur level 200:
  `modCond(200) = 1 + 200/50 = 5.0`. Applied to `attaque(O=200, H=200, condLevel=200)`:
  `base = (pow(200, 1.2) + 200) * (1 + 200/100)`
  `= (pow(200, 1.2) + 200) * 3.0`
  `pow(200, 1.2) ≈ 672`. So `base ≈ (672 + 200) * 3.0 = 2616`.
  `attaque = round(2616 * 5.0 * 1.0) = 13080` per molecule.
  With 1 million molecules: `degatsAttaquant = 13.08 billion`. This is a PHP float, no
  overflow on 64-bit. But `$hpPerMol` at defender side with Br=200, C=200, condLevel=200:
  `MOLECULE_MIN_HP + (pow(200, 1.2) + 200) * (1 + 200/100) * 5.0`
  `= 10 + 872 * 3.0 * 5.0 = 10 + 13080 = 13090`.
  Kill count = `floor(13.08e9 / 13090) ≈ 999,236`. Near-symmetric combat. The arms race
  is self-similar — no exploit. However at extreme condenseur levels (500+), `modCond`
  returns 11.0, making ALL stats scale linearly with condenseur level. This removes the
  intended sqrt/polynomial growth curve and makes condenseur the dominant stat.
  At condenseur = 1000: `modCond = 21.0`. Every formula multiplier becomes 21x.
  The formula's intended sqrt-like curve is destroyed by linear condenseur scaling.
- **Impact:** Very high condenseur levels make the building a dominant linear multiplier,
  breaking game balance. No soft cap means a whale player investing entirely in condenseur
  can multiply all stats by 21x over a normal player.
- **Fix:** Apply a soft cap: `return min(MAX_COND_MOD, 1 + ($niveauCondenseur / COVALENT_CONDENSEUR_DIVISOR));`
  or switch to logarithmic: `return 1 + log(1 + $niveauCondenseur) / COVALENT_CONDENSEUR_DIVISOR;`

---

### P4-EC-011 | HIGH | drainageProducteur() Energy Drain Grows Exponentially — Can Exceed Total Production

- **Formula:** `formulas.php:145-148`
  ```php
  function drainageProducteur($niveau) {
      return round(PRODUCTEUR_DRAIN_PER_LEVEL * pow(ECO_GROWTH_BASE, $niveau)); // 8 * 1.15^level
  }
  ```
  `game_resources.php:77`: `$prodProducteur = $prodSpec - drainageProducteur($producteur['producteur']);`
  Then: `$result = max(0, round($prodProducteur));`
- **Edge case:** The drain grows at 1.15^level while production grows linearly with generator
  level (BASE_ENERGY_PER_LEVEL * generateur_level = 75 * level). At producteur level 60:
  `drainageProducteur(60) = round(8 * pow(1.15, 60)) = round(8 * 267.86) = 2143`.
  At generator level 30: gross production = `75 * 30 = 2250`. Net = `2250 - 2143 = 107/h`.
  At producteur level 70: drain = `round(8 * 1.15^70) = round(8 * 1083) = 8664`. The drain
  completely overwhelms any realistic generator level. Players who upgrade producteur much
  faster than generator will hit a hard wall where they produce 0 energy. This is caught
  by `max(0, round(...))` — no crash, but effectively negative energy income silently zeroed.
  The issue: players don't see the drain will exceed income until after they've spent
  resources on construction. The "effetSup" display shows "Stockage plein" only when energy
  revenue > 0, but when it's 0, the display is blank (per EC-001 fix above). The UI does
  show the `drainageProducteur` value, but no warning that it exceeds generator output.
- **Impact:** A player who upgrades producteur aggressively will silently receive 0 energy.
  This may feel like a game bug. Energy starvation prevents further construction and molecule
  formation, soft-locking the player's economy.
- **Fix:** Add a UI warning when `drainageProducteur(current_level + 1) > grossEnergyProduction`.
  Consider capping drain at 90% of gross production via a gameplay rule.

---

### P4-EC-012 | HIGH | Vault Protection Applied Per-Resource but Subtracted Once — Over-Protection

- **Formula:** `combat.php:374-392`
  ```php
  $vaultProtection = capaciteCoffreFort($vaultLevel, $depotDefLevel);
  // ...
  foreach ($nomsRes as $num => $ressource) {
      $pillageable = max(0, $ressourcesDefenseur[$ressource] - $vaultProtection);
      $ressourcesTotalesDefenseur += $pillageable;
  }
  ```
- **Edge case:** `capaciteCoffreFort()` returns a single number (e.g., 500 atoms).
  This protection value is subtracted from EACH of the 8 resource types independently.
  A defender with 100 of each resource (800 total) and vault protection = 500:
  Each type: `max(0, 100 - 500) = 0`. Total pillageable = 0.
  But the vault should only protect 500 total atoms, not 500 of each type.
  The vault effectively protects up to `8 * vaultProtection` atoms in practice (if spread
  evenly across types), providing 8x the documented protection.
  At max vault (50% of depot at depot level 10): `placeDepot(10) = round(1000 * 1.15^10) ≈ 4046`.
  `vaultProtection = round(4046 * 0.50) = 2023` per resource type.
  If a defender has 2000 of each type (16,000 total), each type has `max(0, 2000 - 2023) = 0`
  pillaged. Effectively all 16,000 atoms are protected despite the vault promising only 50%
  (8,000 atoms) protection.
- **Impact:** Vault protection is 8x stronger than described. Coffrefort is dramatically
  overpowered. Defenders with resources spread across all 8 types are nearly immune to
  pillage once they build a level 25 coffrefort (50% protection).
- **Fix:** Implement vault as total atom protection, not per-resource:
  ```php
  // Calculate proportion of each resource above vault floor
  $totalRes = array_sum(array_map(fn($r) => $ressourcesDefenseur[$r], $nomsRes));
  $totalPillageable = max(0, $totalRes - $vaultProtection);
  ```

---

### P4-EC-013 | HIGH | Alliance Research Bonus Cap Not Applied to Espionage Threshold

- **Formula:** `game_actions.php:364-365`
  ```php
  $radarDiscount = 1 - allianceResearchBonus($actions['attaquant'], 'espionage_cost');
  $espionageThreshold = ($nDef['neutrinos'] * ESPIONAGE_SUCCESS_RATIO) * $radarDiscount;
  ```
  `allianceResearchBonus()` returns `$level * 0.02` for radar tech (2% per level, max level 25).
  Max radar bonus = `25 * 0.02 = 0.50` (50% discount). So `$radarDiscount = 0.50`.
- **Edge case:** `ALLIANCE_RESEARCH_MAX_LEVEL = 25` is a constant but is only enforced during
  alliance research upgrade, not in `allianceResearchBonus()` itself. If a DB row has
  `radar > 25` (via admin edit or SQL injection), `allianceResearchBonus()` returns > 0.50.
  At radar level 50: bonus = `50 * 0.02 = 1.0`. `$radarDiscount = 1 - 1.0 = 0.0`.
  `$espionageThreshold = neutrinos * 0.5 * 0.0 = 0.0`. Since `0.0 < $actions['nombreneutrinos']`
  for any positive neutrino count, espionage **always succeeds** regardless of defender's
  neutrinos. At radar > 50: bonus > 1.0, `$radarDiscount < 0`, `$espionageThreshold < 0`.
  Any positive neutrino count satisfies `threshold < sent_neutrinos` — permanent free espionage.
- **Impact:** An alliance with inflated radar level in the DB gains automatic espionage success.
- **Fix:** Cap the return of `allianceResearchBonus()` at the theoretical maximum:
  ```php
  return min($tech['effect_per_level'] * ALLIANCE_RESEARCH_MAX_LEVEL, $level * $tech['effect_per_level']);
  ```

---

### P4-EC-014 | HIGH | placeDepot() Grows Exponentially — Astronomically Large at High Levels

- **Formula:** `formulas.php:319-321`
  ```php
  function placeDepot($niveau) {
      return round(BASE_STORAGE_INITIAL * pow(ECO_GROWTH_BASE, $niveau)); // 1000 * 1.15^level
  }
  ```
- **Edge case:** At level 200: `1000 * pow(1.15, 200) = 1000 * 6.7e22 = 6.7e25`. This exceeds
  PHP float precision (PHP floats have ~15 significant digits). `round(6.7e25)` returns the
  floating-point approximation. The actual stored value in the DB (likely a BIGINT or FLOAT
  column) may lose precision. Also, this value is used as a storage cap:
  `if ($energie >= placeDepot($depot['depot'])) $energie = placeDepot(...)`. With depot=200,
  the cap is 6.7e25 — effectively infinite, removing the resource cap mechanic entirely.
  More immediately: at level 150, `1000 * 1.15^150 ≈ 1.17e10` (11.7 billion) — this already
  exceeds a 32-bit signed int. The building cost formula at level 150:
  `round(100 * pow(1.15, 150)) = round(1.17e9)` — valid as a 64-bit int but potentially
  displayed as scientific notation in the UI.
- **Impact:** Storage becomes effectively infinite above depot level ~150. Resource cap
  mechanic is eliminated. Building costs exceed reasonable display values.
- **Fix:** Add a hard cap on depot level (e.g., 100) enforced in `augmenterBatiment()`, or
  switch to a logarithmic storage formula for higher levels.

---

### P4-EC-015 | HIGH | generateResourceNodes() Infinite Skip Loop on Very Small Maps

- **Formula:** `resource_nodes.php:33-65`
  ```php
  for ($i = 0; $i < $count; $i++) {
      $maxAttempts = 50;
      // ...
      while ($attempts < $maxAttempts) {
          // try to place node with RESOURCE_NODE_MIN_DISTANCE = 3 separation
      }
      if (!$valid) continue; // skip placement
  }
  ```
- **Edge case:** `$count = mt_rand(15, 25)` = up to 25 nodes. `RESOURCE_NODE_MIN_DISTANCE = 3`.
  Map size check: `$margin = 1`, so valid placement range is `[1, $mapSize - 2]`.
  If `$mapSize = 5`: valid range = `[1, 3]` → 3×3 = 9 possible positions. With min distance 3,
  maximum non-overlapping nodes in a 3×3 grid = 1 (since any two nodes are within distance 3).
  After placing 1 node, all subsequent placements fail all 50 attempts and are skipped.
  Result: only 1 node placed out of 25 attempted. No crash, but the function silently produces
  far fewer nodes than intended with no error logged. The map is left with 1 resource node
  instead of 15-25. Production bonuses are nearly non-existent.
  Also: `mt_rand($margin, $mapSize - 1 - $margin)` where `$mapSize = 3` and `$margin = 1`:
  `mt_rand(1, 3 - 1 - 1) = mt_rand(1, 1)` — always returns 1. All nodes would attempt to
  place at (1, 1) and only 1 node gets placed. For `$mapSize = 2`:
  `mt_rand(1, 2 - 1 - 1) = mt_rand(1, 0)` — invalid range, PHP behaviour is undefined
  (may throw a ValueError in PHP 8.x).
- **Impact:** Very small maps crash or produce broken resource node layouts. Even medium maps
  (size 10) with 25 nodes and min-distance 3 may fail to place all nodes.
- **Fix:**
  ```php
  // Validate mapSize before node generation
  if ($mapSize < 2 * RESOURCE_NODE_MIN_DISTANCE + 2) {
      logError("Map too small for resource nodes: $mapSize");
      return;
  }
  ```
  Also log a warning when fewer than `RESOURCE_NODE_MIN_COUNT` nodes are successfully placed.

---

### P4-EC-016 | HIGH | coefDisparition() Static Cache Survives Across Requests in CLI/Test Contexts

- **Formula:** `formulas.php:213-218`
  ```php
  function coefDisparition($joueur, $classeOuNbTotal, $type = 0) {
      static $cache = [];
      $cacheKey = $joueur . '-' . $classeOuNbTotal . '-' . $type;
      if (isset($cache[$cacheKey])) return $cache[$cacheKey];
  ```
- **Edge case:** The static cache is per-process (PHP web server worker). If a player's
  stabilisateur level changes during a request (via `augmenterBatiment()`), subsequent calls
  to `coefDisparition()` in the same request (e.g., in the attack decay loop in
  `game_actions.php`) return stale cached values reflecting the old stabilisateur level.
  More critically: the cache key does NOT include the current stabilisateur level or medal
  bonus. If two players share the same login string AND the same class number (impossible
  in practice due to login uniqueness), the cache would return wrong data.
  Practical impact: within a single `updateRessources()` call, if `coefDisparition()` is
  called before a building upgrade and then called again after (both in the same PHP request
  via `initPlayer()` → `augmenterBatiment()` → `initPlayer()` chain), the second call returns
  the pre-upgrade cached decay coefficient.
- **Impact:** After a stabilisateur upgrade in the same request, molecule decay uses the
  old (worse) coefficient for the remainder of that request. Minor but produces incorrect
  decay calculation that slightly disadvantages the player.
- **Fix:** Clear the static cache after building upgrades, or include the stabilisateur level
  in the cache key:
  ```php
  $cacheKey = $joueur . '-' . $classeOuNbTotal . '-' . $type . '-' . $stabilisateur['stabilisateur'];
  ```

---

## MEDIUM

---

### P4-EC-017 | MEDIUM | pointsPillage() tanh Returns Values Below 1 at Low Resource Counts

- **Formula:** `formulas.php:96-98`
  ```php
  function pointsPillage($nbRessources) {
      return round(tanh($nbRessources / PILLAGE_POINTS_DIVISOR) * PILLAGE_POINTS_MULTIPLIER);
  }
  ```
  `PILLAGE_POINTS_DIVISOR = 50000`, `PILLAGE_POINTS_MULTIPLIER = 80`.
- **Edge case:** At `$nbRessources = 100`: `tanh(100/50000) * 80 = tanh(0.002) * 80 ≈ 0.002 * 80 = 0.16`.
  `round(0.16) = 0` — a player who pillages 100 atoms gets 0 pillage points.
  Threshold for 1 point: `tanh(x/50000) * 80 >= 0.5` → `x >= 314`. So pillaging fewer than
  314 atoms awards 0 ranking points. This creates a dead zone for new players with small
  armies (e.g., early game pillage of 50-200 atoms per battle = always 0 points).
  At `$nbRessources = 0`: `tanh(0) = 0`, returns 0. No crash — correct.
  At `$nbRessources = PHP_INT_MAX` (9.2e18): `tanh(9.2e18/50000)` = `tanh(1.84e14)` = 1.0.
  `round(1.0 * 80) = 80`. Correctly capped by tanh asymptote. The multiplier cap of 80 is
  reached, so no overflow possible via this formula.
- **Impact:** Early-game players accumulating small pillage amounts receive 0 ranking points
  for pillaging, creating a false sense that pillaging doesn't contribute to rank until a
  certain threshold. This discourages early aggressive play.
- **Fix:** Add a minimum 1 point for any non-zero pillage: `return max(0 === $nbRessources ? 0 : 1, round(...))`.

---

### P4-EC-018 | MEDIUM | calculerTotalPoints() Double sqrt Application — sqrt(sqrt(x)) Intended?

- **Formula:** `formulas.php:105-114`
  ```php
  function calculerTotalPoints($construction, $attaque, $defense, $commerce, $pillage) {
      return round(
          RANKING_CONSTRUCTION_WEIGHT * pow(max(0, $construction), RANKING_SQRT_EXPONENT)
          + RANKING_ATTACK_WEIGHT * pow(max(0, $attaque), RANKING_SQRT_EXPONENT)
          // ...
      );
  }
  ```
  `recalculerTotalPointsJoueur()` calls `calculerTotalPoints()` with already-transformed
  values:
  ```php
  calculerTotalPoints(
      $data['points'],            // raw construction points
      pointsAttaque($data['pointsAttaque']),   // already sqrt-transformed
      pointsDefense($data['pointsDefense']),   // already sqrt-transformed
      $data['tradeVolume'],       // raw trade volume
      pointsPillage($data['ressourcesPillees']) // already tanh-transformed
  );
  ```
  `pointsAttaque($pts) = round(5.0 * sqrt(abs($pts)))` — already applies sqrt.
  Then `calculerTotalPoints()` applies `pow(..., 0.5)` = another sqrt.
  So attack contribution = `1.5 * sqrt(sqrt(raw_pointsAttaque))` = `1.5 * (pointsAttaque)^0.5`.
- **Edge case:** This double-transformation is either intentional (sqrt of sqrt to further
  compress large values) or a design error. At `pointsAttaque = 10000`:
  Single sqrt path: `5.0 * sqrt(10000) = 500`. Then sqrt again: `1.5 * sqrt(500) ≈ 33.5`.
  If raw `pointsAttaque` were passed directly: `1.5 * sqrt(10000) = 150`. The double-sqrt
  path gives 33.5 vs 150 — a 4.5x difference. The inequality compresses the score heavily
  at high values. A player with 10,000 attack points gets 33.5 total contribution vs
  a player with 100 who gets `1.5 * sqrt(5.0 * sqrt(100)) = 1.5 * sqrt(50) ≈ 10.6`.
  The ratio is 33.5/10.6 = 3.1x for 100x more attack points. This is extreme compression.
- **Impact:** High-attack players are very heavily penalized in ranking relative to balanced
  players. Whether intentional or not, the double-sqrt makes attack/defense points contribute
  almost negligibly at high values. This may be a design oversight.
- **Fix:** Clarify intent in docs. If single-sqrt is desired, pass raw values to
  `calculerTotalPoints()` and remove the pre-transformation in `pointsAttaque()`/`pointsDefense()`.
  Or rename `recalculerTotalPointsJoueur()` to make the double-transform explicit.

---

### P4-EC-019 | MEDIUM | Building Cost Formula Returns 0 When Medal Bonus = 100%

- **Formula:** `player.php:326`
  ```php
  'coutEnergie' => round((1 - ($bonus / 100)) * $BUILDING_CONFIG['generateur']['cost_energy_base']
                  * pow($BUILDING_CONFIG['generateur']['cost_growth_base'], $niveauActuel['niveau'])),
  ```
- **Edge case:** If `$bonus = 100` (impossible from `$MEDAL_BONUSES` max of 50, but if an
  admin sets a custom tier), `1 - (100/100) = 0`, `coutEnergie = 0`. Buildings become free.
  More practically: at `$bonus = 50` (Diamant Rouge max), `1 - 0.50 = 0.50`. Costs are halved.
  This is the intended mechanic. BUT: the `$bonus` value here is read from `computeMedalBonus()`
  which caps at `MAX_CROSS_SEASON_MEDAL_BONUS = 10` (Emeraude = 10%). Actually reading the
  code more carefully in `initPlayer()`: building cost bonus uses the player's actual medal tier
  without the grace-period cap. So during the first 14 days of a season, a Diamant Rouge player
  still gets 50% off construction costs (the cap only applies to `computeMedalBonus()` used in
  combat). This is an inconsistency: combat stats are capped at 10% grace period, but
  construction costs are not capped.
  **Additional edge:** `round(0) = 0` — if the base cost is 0 (like `depot` which has
  `cost_atoms_base = 0`), the formula correctly returns 0. No crash.
- **Impact:** During first 14 days, veteran Diamant Rouge players get 50% off buildings but
  only 10% combat advantage — the economic advantage is uncapped during grace period.
- **Fix:** Apply the grace period cap to construction costs using `computeMedalBonus()`.

---

### P4-EC-020 | MEDIUM | getResourceNodeBonus() Accumulates Unboundedly for Overlapping Nodes

- **Formula:** `resource_nodes.php:97-108`
  ```php
  $totalBonus = 0.0;
  foreach ($nodesCache as $node) {
      if ($node['resource_type'] !== $resourceName) continue;
      $dist = sqrt(pow($px - $node['x'], 2) + pow($py - $node['y'], 2));
      if ($dist <= $node['radius']) {
          $totalBonus += $node['bonus_pct'] / 100.0; // 0.10 per node
      }
  }
  return $totalBonus;
  ```
- **Edge case:** `RESOURCE_NODE_DEFAULT_BONUS_PCT = 10.0` (10% per node), `RESOURCE_NODE_DEFAULT_RADIUS = 5`.
  If 10 carbone nodes overlap at the same location (possible if `RESOURCE_NODE_MIN_DISTANCE`
  enforcement fails on small maps — EC-015), a player standing at that location gets
  `10 * 0.10 = 1.0` = 100% production bonus. With 25 overlapping nodes: 250% bonus.
  This bonus is then multiplied into `revenuAtome()`:
  `result = round(... * (1 + $nodeBonus))` — at 250% bonus, `(1 + 2.5) = 3.5x` production.
  The DB constraint `RESOURCE_NODE_MIN_DISTANCE = 3` combined with radius 5 means nodes
  intentionally CAN overlap (two nodes at distance 4 both have radius 5, so they overlap).
  The minimum distance constraint only prevents nodes from being placed too close together,
  but radius 5 > min_distance 3 means all adjacent nodes overlap. Up to 25 nodes of the same
  type can cover the same cell, for a potential 250% production boost.
  The function has no cap on total bonus returned.
- **Impact:** Players who position themselves at a resource hotspot (multiple overlapping nodes)
  can get unbounded production bonuses. The maximum theoretically achievable is 25 * 10% = 250%
  bonus multiplier to one resource type, making production 3.5x base in that location.
- **Fix:** Cap the total node bonus:
  ```php
  return min(RESOURCE_NODE_MAX_TOTAL_BONUS, $totalBonus); // e.g., max 50% total
  ```
  or reduce radius to equal min_distance: `RESOURCE_NODE_DEFAULT_RADIUS = 3`.

---

### P4-EC-021 | MEDIUM | Specialization Modifier Stack Allows Negative Stats at Extreme Combinations

- **Formula:** `formulas.php:16-37` (`getSpecModifier()`), used in `attaque()`, `defense()` via
  `combat.php:192-195`
  ```php
  $specAttackMod = getSpecModifier($actions['attaquant'], 'attack');
  $degatsAttaquant *= (1 + $specAttackMod);
  ```
  All three specs combined: combat=Réducteur (−0.05 atk), economy=Industriel (no atk effect),
  research=Théorique (no atk effect). Max negative attack modifier = −0.05 from Réducteur.
  `$degatsAttaquant *= (1 + (-0.05)) = 0.95x`. Positive.
  Defense: combat=Oxydant (−0.05 def), max negative = −0.05.
  These are safe. BUT formation speed: `$specFormationMod = getSpecModifier($joueur, 'formation_speed')`.
  Théorique gives −0.20. `(1 + (-0.20)) = 0.80`. Safe.
  Appliqué gives +0.20. These are capped by config values.
  The exploitable edge: `condenseur_points` modifier from Théorique (+2) and Appliqué (−1).
  With Théorique: condenseur gives +2 extra points per level. But there's no code in the
  codebase that actually READS `condenseur_points` from `getSpecModifier()` — it's defined
  in config but never consumed in production code. This is a dead/orphaned specialization effect.
- **Impact:** The `condenseur_points` specialization effect from 'Théorique' and 'Appliqué' is
  defined in config but never applied to actual condenseur point distribution. Players who
  choose 'Théorique' expecting +2 condenseur points/level receive no benefit from that
  modifier. This is misleading to players.
- **Fix:** Implement `condenseur_points` modifier in `augmenterBatiment()` for condenseur:
  apply `getSpecModifier($joueur, 'condenseur_points')` to the points awarded per level.

---

### P4-EC-022 | MEDIUM | coutClasse() Returns Non-Integer Floats Due to pow()

- **Formula:** `formulas.php:313-316`
  ```php
  function coutClasse($numero) {
      return (pow($numero + CLASS_COST_OFFSET, CLASS_COST_EXPONENT)); // pow(n+1, 4)
  }
  ```
- **Edge case:** `$numero` is presumably 1-4 (class number 0-indexed). `pow(2, 4) = 16`,
  `pow(3, 4) = 81`, `pow(4, 4) = 256`, `pow(5, 4) = 625`. These are exact integers.
  However PHP's `pow()` with integer arguments returns a float in PHP 8:
  `var_dump(pow(2, 4))` → `float(16)`. The return value is used as energy cost:
  compared to player's energy (an integer from DB). `$energie >= coutClasse(...)` works
  correctly because float 16.0 == int 16 in PHP comparisons.
  The real issue: if `$numero` comes from user input (GET/POST parameter) and is a negative
  value or very large: `coutClasse(-2) = pow(-1, 4) = 1`. Class -2 costs only 1 energy.
  `coutClasse(0) = pow(1, 4) = 1`. Class 0 costs 1 energy.
  `coutClasse(-1) = pow(0, 4) = 0`. Class -1 costs 0 energy — free class unlock.
- **Impact:** If `$numero` is user-supplied without validation (e.g., from a form parameter),
  passing 0 or −1 gives a free class unlock costing 0 or 1 energy. This bypasses the
  intended exponential cost curve.
- **Fix:** Add input validation before `coutClasse()`: `$numero = max(1, min(4, intval($numero)));`
  Verify all callers pass only validated class numbers.

---

### P4-EC-023 | MEDIUM | productionEnergieMolecule() with Iode = 0 and Level = 0 Returns 0 Correctly, but Level = -1 Returns Negative

- **Formula:** `formulas.php:180-183`
  ```php
  function productionEnergieMolecule($iode, $niveau) {
      return round((IODE_QUADRATIC_COEFFICIENT * pow($iode, 2) + IODE_ENERGY_COEFFICIENT * $iode)
             * (1 + $niveau / IODE_LEVEL_DIVISOR));
  }
  ```
- **Edge case:** `$iode = 0`: `round((0 + 0) * ...) = 0`. Safe.
  `$niveau = -1` (e.g., if a molecule level is somehow -1): `1 + (-1)/50 = 0.98`.
  `round(somePositive * 0.98)` — slightly reduced but still positive. No crash.
  `$niveau = -50`: `1 + (-50)/50 = 0`. Returns 0 — no energy production. Fine.
  `$niveau = -51`: `1 + (-51)/50 = -0.02`. Energy production goes negative.
  `round(positiveValue * (-0.02)) = round(-0.002 * iodeValue^2)` — a small negative number.
  The calling code: `$prodMedaille = (1 + ($bonus / 100)) * $prodIode` where `$prodIode`
  incorporates this. If `$prodIode < 0`, `$prodMedaille < 0`, `$prodDuplicateur < 0`, and
  ultimately `revenuEnergie()` returns `max(0, round(...))` — clamped to 0. No crash.
  `$iode = -1` (impossible from game flow, but if DB is manually edited): `pow(-1, 2) = 1`,
  `IODE_ENERGY_COEFFICIENT * (-1) = -0.04`. Net = `0.003 - 0.04 = -0.037`. Negative result.
  Multiplied by level modifier gives negative energy. Clamped by `max(0, ...)`.
- **Impact:** Negative iode or very negative molecule level values produce negative energy
  production, which is silently clamped. No exploit possible from legitimate gameplay.
  Low risk but worth hardening.
- **Fix:** Add input guards: `$iode = max(0, $iode); $niveau = max(0, $niveau);`

---

### P4-EC-024 | MEDIUM | updateRessources() Molecule Decay — pow(coef, nbsecondes) Precision Loss

- **Formula:** `game_resources.php:228`
  ```php
  $moleculesRestantes = (pow(coefDisparition($joueur, $compteur + 1), $nbsecondes) * $molecules['nombre']);
  ```
- **Edge case:** `$nbsecondes` is `time() - $donnees['tempsPrecedent']`. If a player is offline
  for 1 month (2,678,400 seconds), `pow(0.9999996, 2678400) ≈ pow(0.9999996, 2.68e6)`.
  `log(0.9999996) * 2.68e6 = -0.000000400... * 2.68e6 ≈ -1.072`.
  `e^(-1.072) ≈ 0.342`. So ~34% remain — reasonable.
  But at `coef = 0.999` (small molecule, no stabilisateur), `nbsecondes = 2.68e6`:
  `pow(0.999, 2680000) = e^(ln(0.999) * 2680000) = e^(-0.001001 * 2680000) = e^(-2681) ≈ 0`.
  PHP returns `0.0` for very small floats (underflow to 0). `$moleculesRestantes = 0`. Correct.
  The issue is `$moleculesRestantes` is stored via `'di'` type bind:
  `dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', $moleculesRestantes, ...)`.
  The type is `'d'` (double/float) for the molecule count. This is intentional (fractional
  molecules allowed). However `$molecules['nombre']` from the DB could be a float like
  `0.00000001` — the display would show "0.000" molecules but the record is not 0.
  A player could exploit this by having a tiny non-zero army that never triggers automatic
  cleanup (no `WHERE nombre <= 0` delete exists). The army shows as "0" in UI but technically
  exists.
- **Impact:** Ghost armies: molecules with near-zero counts (e.g., 1e-8) that display as 0
  but are not null. These won't participate in combat (their damage contribution is ~0) but
  occupy class slots and contribute to decay calculations.
- **Fix:** After computing `$moleculesRestantes`, add `if ($moleculesRestantes < 0.01) $moleculesRestantes = 0;`
  to snap near-zero values to exactly 0.

---

### P4-EC-025 | MEDIUM | Combat Building Damage Percentage Can Show > 100% or < 0%

- **Formula:** `combat.php:502`
  ```php
  $destructionGenEnergie = round($constructions['vieGenerateur'] / pointsDeVie($constructions['generateur']) * 100)
      . "% → "
      . max(round(($constructions['vieGenerateur'] - $degatsGenEnergie) / pointsDeVie($constructions['generateur']) * 100), 0)
      . "%";
  ```
- **Edge case:** `$constructions['vieGenerateur']` can be higher than `pointsDeVie($constructions['generateur'])`
  if the alliance fortification research bonus changes between when HP was last set and now.
  The `fortBonus` from `allianceResearchBonus()` is applied at `pointsDeVie()` call time, but
  the stored `vieGenerateur` in the DB was computed at the last HP-reset event without that
  bonus. If fortification level increases, `pointsDeVie()` returns a higher max HP, but the
  stored current HP is at the old (lower) max. This makes `vieGenerateur / pointsDeVie()` < 1.
  Conversely, if a player LEAVES an alliance with high fortification, `pointsDeVie()` drops,
  but stored HP remains at the higher value — making `vieGenerateur / pointsDeVie() > 1.0`,
  displaying > 100% HP in the combat report. The `max(..., 0)` on the post-damage HP prevents
  negative display, but the pre-damage HP has no `min(..., 100)` cap.
- **Impact:** Combat reports can show "127% HP → 45%" which is confusing to players and reveals
  an inconsistency in the HP tracking system.
- **Fix:** Apply `min(100, ...)` to the pre-damage HP percentage calculation:
  ```php
  round(min(100, $constructions['vieGenerateur'] / pointsDeVie($constructions['generateur']) * 100))
  ```

---

### P4-EC-026 | MEDIUM | pointsVictoireJoueur() VP Curve Has a Floor of 1 at Ranks 51-100, Then Drops to 0 at 101

- **Formula:** `formulas.php:59-63`
  ```php
  if ($classement <= 100) {
      return max(1, floor(VP_PLAYER_RANK51_100_BASE - ($classement - 50) * VP_PLAYER_RANK51_100_STEP));
  }
  return 0;
  ```
  `VP_PLAYER_RANK51_100_BASE = 6`, `VP_PLAYER_RANK51_100_STEP = 0.08`.
  At rank 100: `max(1, floor(6 - 50 * 0.08)) = max(1, floor(6 - 4)) = max(1, 2) = 2`.
  Wait — recalculating: `floor(6 - (100-50)*0.08) = floor(6 - 4.0) = floor(2.0) = 2`.
  So rank 100 gets 2 VP, rank 101 gets 0 VP. The cliff from 2 to 0 may feel harsh.
  Also: rank 51 gets `floor(6 - (51-50)*0.08) = floor(6 - 0.08) = floor(5.92) = 5 VP`.
  But rank 50 gets `max(1, floor(12 - (50-20)*0.23)) = max(1, floor(12 - 6.9)) = max(1, 5) = 5 VP`.
  Ranks 50 and 51 give the same VP — no differentiation at the boundary.
  `VP_PLAYER_RANK21_50_BASE = 12`, step `0.23`. At rank 50: `12 - 30*0.23 = 12 - 6.9 = 5.1` → floor → 5.
  At rank 51: `6 - 1*0.08 = 5.92` → floor → 5. Same VP. Boundary discontinuity.
- **Impact:** Ranks 50 and 51 give identical VP (5). Players at rank 51 have no incentive to
  reach rank 50 if both award 5 VP. The boundary formula is misaligned.
- **Fix:** Ensure continuity at rank boundaries. Adjust `VP_PLAYER_RANK51_100_BASE` to 5 so
  rank 51 starts at `floor(5 - 0.08) = 4` VP, creating proper differentiation.

---

## LOW

---

### P4-EC-027 | LOW | capaciteCoffreFort() Uses Vault Level 0 with No Lower Bound on pct

- **Formula:** `formulas.php:323-327`
  ```php
  function capaciteCoffreFort($nivCoffre, $nivDepot) {
      $pct = min(VAULT_MAX_PROTECTION_PCT, $nivCoffre * VAULT_PCT_PER_LEVEL);
      return round(placeDepot($nivDepot) * $pct);
  }
  ```
- **Edge case:** `$nivCoffre = 0`: `$pct = min(0.50, 0 * 0.02) = 0`. `return round(... * 0) = 0`.
  Correct — no vault protection when coffrefort is level 0.
  `$nivCoffre = -1` (impossible from normal play, but defensive coding): `$pct = min(0.50, -0.02) = -0.02`.
  `return round(placeDepot($nivDepot) * (-0.02))` — a **negative** vault protection.
  In combat: `$pillageable = max(0, $ressourcesDefenseur[$ressource] - $vaultProtection)`.
  With negative `$vaultProtection`: `max(0, resource - (-200)) = max(0, resource + 200)`.
  This INCREASES the pillageable amount above the actual resource count. A defender could
  have more atoms stolen than they actually possess — normally prevented by the resource
  update `max(0, current - stolen)`.
- **Impact:** Negative coffrefort level (from DB manipulation) makes resources appear MORE
  pillageable than they are. No true exploit since the resource update clamps at 0, but
  the pillage stat tracking would count resources "stolen" that didn't exist.
- **Fix:** Add `$nivCoffre = max(0, $nivCoffre);` at function entry.

---

### P4-EC-028 | LOW | bonusDuplicateur() Returns 0 for Level 0 and Multiplier Becomes 1 — No Issue, but Alliance Level Silently Accepts Negatives

- **Formula:** `formulas.php:135-138`
  ```php
  function bonusDuplicateur($niveau) {
      return $niveau * DUPLICATEUR_BONUS_PER_LEVEL; // niveau * 0.01
  }
  ```
  Used as: `$bonusDuplicateur = 1 + bonusDuplicateur($duplicateur['duplicateur'])`.
- **Edge case:** `$niveau = 0`: `0 * 0.01 = 0`. `bonusDuplicateur = 1 + 0 = 1`. Correct.
  `$niveau = -5` (admin error): `-5 * 0.01 = -0.05`. `bonusDuplicateur = 0.95`.
  All production and combat using this multiplier is reduced by 5%. Alliance members would
  be penalized instead of helped. No crash but incorrect gameplay.
  `$niveau = 1000`: `1000 * 0.01 = 10`. `bonusDuplicateur = 11`. 11x production multiplier.
  Since `DUPLICATEUR_COST_FACTOR = 1.5` and cost = `round(100 * pow(1.5, level+1))`, at
  level 1000 the cost is astronomically large (`1.5^1001`), effectively unreachable.
  But DB manipulation could set level to 1000 for free.
- **Impact:** Admin or SQL injection could set a negative duplicateur level, penalizing
  entire alliances. Or set it astronomically high for unlimited production.
- **Fix:** Add `$niveau = max(0, min(ALLIANCE_RESEARCH_MAX_LEVEL, $niveau));` in `bonusDuplicateur()`.

---

### P4-EC-029 | LOW | pointsAttaque() and pointsDefense() Use abs() Masking Negative Combat Points

- **Formula:** `formulas.php:83-93`
  ```php
  function pointsAttaque($pts) {
      if ($pts <= 0) return 0;
      return round(ATTACK_POINTS_MULTIPLIER * sqrt(abs($pts)));
  }
  function pointsDefense($pts) {
      if ($pts <= 0) return 0;
      return round(DEFENSE_POINTS_MULTIPLIER * sqrt(abs($pts)));
  }
  ```
- **Edge case:** `$pts = -100`: Early guard returns 0. The `abs()` is redundant but harmless
  since `$pts <= 0` catches all negatives. The redundant `abs()` suggests confusion about
  whether negative values are expected — if the guard is ever removed, `sqrt(-100)` would
  return `NAN` in PHP.
  `$pts = 0.5`: `if (0.5 <= 0)` is false, so `sqrt(abs(0.5)) = 0.707`, times 5.0 = 3.535,
  `round(3.535) = 4`. Points awarded for a fraction of a combat point.
  `$pts = PHP_INT_MAX (9.2e18)`: `sqrt(9.2e18) ≈ 3.03e9`. Times 5.0 = 1.5e10. Well within
  float range but would produce an astronomically high ranking score if such a value reached
  `pointsAttaque()`. Capped only by `COMBAT_POINTS_MAX_PER_BATTLE = 20` in combat — but
  `pointsAttaque` in the DB can accumulate across many battles without a ceiling.
  At 100,000 battles won (extreme late-season player): `pointsAttaque = 100000 * 20 = 2,000,000`.
  `pointsAttaque(2000000) = round(5 * sqrt(2000000)) = round(5 * 1414) = 7071`. Then in
  `calculerTotalPoints`: `1.5 * sqrt(7071) = 1.5 * 84 = 126`. Manageable.
- **Impact:** The redundant `abs()` is harmless but confusing. No real exploit.
- **Fix:** Remove the redundant `abs()` for clarity: `return round(ATTACK_POINTS_MULTIPLIER * sqrt($pts));`
  (since `$pts > 0` is already guaranteed by the guard).

---

### P4-EC-030 | LOW | Compound Synthesis Stacking: Same Effect From Two Sources Not Blocked by Different Keys

- **Formula:** `compounds.php:116-123`
  ```php
  $activeCompounds = getActiveCompounds($base, $login);
  foreach ($activeCompounds as $active) {
      if (isset($COMPOUNDS[$active['compound_key']]) &&
          $COMPOUNDS[$active['compound_key']]['effect'] === $COMPOUNDS[$key]['effect']) {
          return "Un composé avec le même effet est déjà actif.";
      }
  }
  ```
- **Edge case:** This correctly prevents activating two compounds with the same `effect` type.
  However `getCompoundBonus()` sums ALL active compounds with matching effect:
  ```php
  $totalBonus += $COMPOUNDS[$key]['effect_value'];
  ```
  If a player has two stored compounds of type 'attack_boost' and activates the first (blocked
  re-activation), but then their cache is stale, the double-activation guard relies on the
  DB query being consistent. With the static `$cache` in `getCompoundBonus()`, if both
  compounds were somehow activated (e.g., race condition in two simultaneous requests), both
  would be summed: `0.10 + 0.10 = 0.20` attack boost instead of 0.10.
  The activation check reads `getActiveCompounds()` without a transaction lock, so a race
  condition between two simultaneous POST requests for compound activation could both pass
  the "no active same-type compound" check before either commits.
- **Impact:** Race condition allows double-stacking the same compound effect type during
  simultaneous requests. The result is 2x the intended compound bonus in combat/production
  for 1 hour. Low probability but exploitable.
- **Fix:** Wrap compound activation in a transaction with a SELECT FOR UPDATE on
  `player_compounds WHERE login = ? AND ... effect = ? ... FOR UPDATE` to serialize
  concurrent activations.

---

### P4-EC-031 | LOW | vitesse() floor() Before Multiply Loses Precision

- **Formula:** `formulas.php:185-190`
  ```php
  function vitesse($Cl, $N, $nivCondCl) {
      $clContrib = min(SPEED_SOFT_CAP, $Cl * SPEED_ATOM_COEFFICIENT);
      $base = 1 + $clContrib + (($Cl * $N) / SPEED_SYNERGY_DIVISOR);
      return max(1.0, floor($base * modCond($nivCondCl) * 100) / 100);
  }
  ```
- **Edge case:** The `floor(... * 100) / 100` truncates to 2 decimal places. With `$Cl = 1`,
  `$N = 0`, `$nivCondCl = 0`: `$clContrib = min(30, 0.5) = 0.5`. `$base = 1.5`.
  `floor(1.5 * 1.0 * 100) / 100 = floor(150) / 100 = 1.5`. Fine.
  With `$Cl = 0`, `$N = 0`, `$nivCondCl = 0`: `$base = 1.0`. `max(1.0, 1.0) = 1.0`. Fine.
  The soft cap prevents extreme values. BUT: `$Cl * $N` is unbounded for large N without
  a Cl cap. With `$Cl = 60` (at soft cap), `$N = 200`:
  `$clContrib = min(30, 60 * 0.5) = min(30, 30) = 30`. Cap hit.
  `synergy = (60 * 200) / 200 = 60`. `$base = 1 + 30 + 60 = 91`. `modCond(0) = 1.0`.
  `speed = 91.0`. Formation takes 1/91 of base time — very fast but capped by the formulas.
  With high N and moderate Cl: `$Cl = 20`, `$N = 200`:
  `clContrib = min(30, 10) = 10`. `synergy = (20 * 200)/200 = 20`. `base = 31`. Speed = 31.
  The synergy divisor `SPEED_SYNERGY_DIVISOR = 200` provides a natural cap.
  `max(1.0, ...)` correctly prevents speed below 1.0 regardless of inputs.
- **Impact:** Floor truncation (not round) means vitesse consistently underestimates speed by
  up to 0.01. Over many formations, this creates a slight systematic bias toward slower
  formation times. Minor.
- **Fix:** Consider using `round()` instead of `floor()` for fairer calculation.

---

### P4-EC-032 | LOW | VP Rank Formula: Ranks 21-50 Can Return 0 Before max(1) Due to Float Step

- **Formula:** `formulas.php:57`
  ```php
  if ($classement <= 50) {
      return max(1, floor(VP_PLAYER_RANK21_50_BASE - ($classement - 20) * VP_PLAYER_RANK21_50_STEP));
  }
  ```
  `VP_PLAYER_RANK21_50_BASE = 12`, `VP_PLAYER_RANK21_50_STEP = 0.23`.
- **Edge case:** At rank 50: `12 - 30 * 0.23 = 12 - 6.9 = 5.1`. `floor(5.1) = 5`. `max(1, 5) = 5`.
  At rank 72 (not in this branch): handled by rank 51-100 formula.
  The `max(1, ...)` guard ensures a minimum of 1 VP, but floating point in `0.23 * 30 = 6.9`
  could theoretically accumulate error. `0.23` is not exactly representable in IEEE 754:
  `0.23` ≈ `0.2299999999...`. So `30 * 0.229999... = 6.899999...`. `12 - 6.9 = 5.1`.
  `floor(5.0999...) = 5`. Safe here. But at rank 72: `VP_PLAYER_RANK51_100_STEP = 0.08`.
  `(72-50) * 0.08 = 22 * 0.08 = 1.76`. Float `0.08` = `0.07999999...`. `22 * 0.07999... ≈ 1.759999`.
  `6 - 1.759999 = 4.240001`. `floor(4.240001) = 4`. Correct. These float imprecisions do not
  cross integer boundaries in practice for the range [21-100].
- **Impact:** Negligible. Float imprecision in VP calculation is sub-threshold. The `max(1, ...)`
  guard and `floor()` combination handles this correctly.
- **Fix:** None required. Documenting for completeness.

---

### P4-EC-033 | LOW | Alliance Research Bonus Returns Pure Float — Not Rounded — May Accumulate FP Error

- **Formula:** `db_helpers.php:84`
  ```php
  return $level * $tech['effect_per_level'];
  ```
  `effect_per_level` for catalyseur = `0.02`, for radar = `0.02`, etc.
- **Edge case:** At level 25: `25 * 0.02 = 0.5`. PHP float arithmetic: `0.02` in IEEE 754 is
  `0.020000000000000000416...`. `25 * 0.02 = 0.5000000000000001`. This is used as:
  `$allianceCatalyseurBonus = 1 + 0.5000000000000001 = 1.5000000000000002`.
  In `tempsFormation()`: `$vitesse_form *= 1.5000000000000002`. Formation time calculation
  involves `ceil()` which will round this up — the 0.0000000002 difference is below any
  meaningful threshold. No real impact. The espionage threshold: `$radarDiscount = 1 - 0.5 = 0.5`
  — float imprecision in the discount is `0.4999999...` vs `0.5` — could theoretically round
  wrong in boundary cases. But `$espionageThreshold = nDef * 0.5 * discount`. If both player
  neutrinos and the result are integers, the float comparison with `nombreneutrinos` (an int)
  is unaffected.
- **Impact:** Negligible float precision issue in alliance research bonuses. Well within
  meaningful game impact threshold.
- **Fix:** Could use `round($level * $tech['effect_per_level'], 4)` to bound float errors,
  but this is optional.

---

## Appendix: Formula Reference Table

| Formula Function | Zero Input Safe? | Negative Safe? | Overflow Risk? | Div/0 Risk? |
|---|---|---|---|---|
| `revenuEnergie()` | YES (returns 0 if gen=0) | N/A | LOW | YES (display div, EC-001) |
| `revenuAtome()` | YES | N/A | LOW | NO |
| `attaque(O=0, H=0)` | YES (returns 0) | NO (pow) | NO | NO |
| `defense(C=0, Br=0)` | YES (returns 0) | NO (pow) | NO | NO |
| `pointsDeVieMolecule(Br=0, C=0)` | YES (MOLECULE_MIN_HP=10) | NO | NO | NO |
| `vitesse(Cl=0, N=0)` | YES (max 1.0) | YES (max 1.0) | NO | NO |
| `productionEnergieMolecule(0, 0)` | YES | NO (negative niveau) | NO | NO |
| `tempsFormation(0, ...)` | PARTIAL (EC-003) | NO | NO | YES (EC-003) |
| `coefDisparition()` | PARTIAL (EC-005) | NO | NO | NO |
| `demiVie()` | YES (guard exists) | N/A | NO | YES if coef=1 (guarded) |
| `pointsDeVie(0)` | PARTIAL (EC-006) | N/A | HIGH (EC-014) | NO |
| `placeDepot(0)` | YES (=1000) | N/A | HIGH (EC-014) | NO |
| `capaciteCoffreFort(0, ...)` | YES (=0) | YES (EC-027) | NO | NO |
| `bonusLieur(0)` | YES (=1.0) | NO | NO | NO |
| `modCond(0)` | YES (=1.0) | YES | NO (EC-010) | NO |
| `drainageProducteur(0)` | YES (=8) | N/A | YES (EC-011) | NO |
| `coutClasse(-1)` | PARTIAL (=0, EC-022) | YES bug (EC-022) | NO | NO |
| `pointsAttaque(0)` | YES (returns 0) | YES (returns 0) | NO | NO |
| `pointsPillage(0)` | YES (returns 0) | N/A | NO | NO |
| `calculerTotalPoints(0,...)` | YES (all zeros) | YES (max 0) | NO | NO |
| `pointsVictoireJoueur(0)` | NO (EC-009) | NO | NO | NO |
| `getResourceNodeBonus()` | YES | N/A | MEDIUM (EC-020) | NO |
| `generateResourceNodes(2)` | N/A | N/A | NO | YES (EC-015) |
| `coefDisparition() nbAtomes=0` | PARTIAL (EC-005) | N/A | NO | NO |

---

*End of Pass 4 — Edge Cases & Adversarial Math Audit. 33 findings total.*
