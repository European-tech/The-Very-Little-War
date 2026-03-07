<?php
/**
 * Game Formulas Module
 * Pure math/game formula functions. Most are pure functions (no DB),
 * but some require DB access for medal bonuses.
 */

/**
 * Get a specific specialization modifier value for a player.
 *
 * @param string $joueur  Player login
 * @param string $modKey  Modifier key: 'attack', 'defense', 'atom_production',
 *                        'energy_production', 'condenseur_points', 'formation_speed'
 * @return float  Modifier value (e.g. 0.10 for +10%, -0.05 for -5%)
 */
function getSpecModifier($joueur, $modKey)
{
    global $base, $SPECIALIZATIONS;
    static $cache = [];
    if (isset($cache[$joueur][$modKey])) return $cache[$joueur][$modKey];

    if (!isset($cache[$joueur])) {
        $constructions = dbFetchOne($base, 'SELECT spec_combat, spec_economy, spec_research FROM constructions WHERE login=?', 's', $joueur);
        $total = [];
        foreach ($SPECIALIZATIONS as $spec) {
            $choice = (int)($constructions[$spec['column']] ?? 0);
            if ($choice > 0 && isset($spec['options'][$choice])) {
                foreach ($spec['options'][$choice]['modifiers'] as $key => $val) {
                    $total[$key] = ($total[$key] ?? 0.0) + $val;
                }
            }
        }
        $cache[$joueur] = $total;
    }

    return $cache[$joueur][$modKey] ?? 0.0;
}

function pointsVictoireJoueur($classement)
{
    if ($classement == 1) {
        return VP_PLAYER_RANK1;
    }
    if ($classement == 2) {
        return VP_PLAYER_RANK2;
    }
    if ($classement == 3) {
        return VP_PLAYER_RANK3;
    }
    if ($classement <= 10) {
        return VP_PLAYER_RANK4_10_BASE - ($classement - 3) * VP_PLAYER_RANK4_10_STEP;
    }
    if ($classement <= 20) {
        return VP_PLAYER_RANK11_20_BASE - ($classement - 10) * VP_PLAYER_RANK11_20_STEP;
    }
    if ($classement <= 50) {
        return max(1, floor(VP_PLAYER_RANK21_50_BASE - ($classement - 20) * VP_PLAYER_RANK21_50_STEP));
    }
    if ($classement <= 100) {
        return max(1, floor(VP_PLAYER_RANK51_100_BASE - ($classement - 50) * VP_PLAYER_RANK51_100_STEP));
    }
    return 0;
}

function pointsVictoireAlliance($classement)
{
    if ($classement == 1) {
        return VP_ALLIANCE_RANK1;
    }
    if ($classement == 2) {
        return VP_ALLIANCE_RANK2;
    }
    if ($classement == 3) {
        return VP_ALLIANCE_RANK3;
    }
    if ($classement < 10) {
        return VP_ALLIANCE_RANK2 - $classement;
    }

    return 0;
}

function pointsAttaque($pts)
{
    if ($pts <= 0) return 0;
    return round(ATTACK_POINTS_MULTIPLIER * sqrt(abs($pts)));
}

function pointsDefense($pts)
{
    if ($pts <= 0) return 0;
    return round(DEFENSE_POINTS_MULTIPLIER * sqrt(abs($pts)));
}

function pointsPillage($nbRessources)
{
    return round(tanh($nbRessources / PILLAGE_POINTS_DIVISOR) * PILLAGE_POINTS_MULTIPLIER);
}

/**
 * Unified sqrt ranking: prevents any single activity from dominating.
 * Total = sum of (weight * pow(category_points, 0.5)) for each category.
 * Category points are the already-transformed values (pointsAttaque, pointsDefense, etc.)
 */
function calculerTotalPoints($construction, $attaque, $defense, $commerce, $pillage)
{
    return round(
        RANKING_CONSTRUCTION_WEIGHT * pow(max(0, $construction), RANKING_SQRT_EXPONENT)
        + RANKING_ATTACK_WEIGHT * pow(max(0, $attaque), RANKING_SQRT_EXPONENT)
        + RANKING_DEFENSE_WEIGHT * pow(max(0, $defense), RANKING_SQRT_EXPONENT)
        + RANKING_TRADE_WEIGHT * pow(max(0, $commerce), RANKING_SQRT_EXPONENT)
        + RANKING_PILLAGE_WEIGHT * pow(max(0, $pillage), RANKING_SQRT_EXPONENT)
    );
}

/**
 * Recompute totalPoints for a player from their raw category columns.
 * Call after any point category changes.
 */
function recalculerTotalPointsJoueur($base, $joueur)
{
    $data = dbFetchOne($base, 'SELECT points, pointsAttaque, pointsDefense, ressourcesPillees, tradeVolume FROM autre WHERE login = ?', 's', $joueur);
    if (!$data) return;

    $total = calculerTotalPoints(
        $data['points'],
        pointsAttaque($data['pointsAttaque']),
        pointsDefense($data['pointsDefense']),
        $data['tradeVolume'],
        pointsPillage($data['ressourcesPillees'])
    );
    dbExecute($base, 'UPDATE autre SET totalPoints = ? WHERE login = ?', 'ds', $total, $joueur);
}

function bonusDuplicateur($niveau)
{
    return $niveau * DUPLICATEUR_BONUS_PER_LEVEL;
}

function modCond($niveauCondenseur)
{
    return 1 + ($niveauCondenseur / COVALENT_CONDENSEUR_DIVISOR);
}

function drainageProducteur($niveau)
{
    return round(PRODUCTEUR_DRAIN_PER_LEVEL * pow(ECO_GROWTH_BASE, $niveau));
}

function attaque($O, $H, $nivCondO, $bonusMedaille = 0)
{
    $base = (pow($O, COVALENT_BASE_EXPONENT) + $O) * (1 + $H / COVALENT_SYNERGY_DIVISOR);
    return round($base * modCond($nivCondO) * (1 + $bonusMedaille / 100));
}

function defense($C, $Br, $nivCondC, $bonusMedaille = 0)
{
    $base = (pow($C, COVALENT_BASE_EXPONENT) + $C) * (1 + $Br / COVALENT_SYNERGY_DIVISOR);
    return round($base * modCond($nivCondC) * (1 + $bonusMedaille / 100));
}

function pointsDeVieMolecule($Br, $C, $nivCondBr)
{
    $base = MOLECULE_MIN_HP + (pow($Br, COVALENT_BASE_EXPONENT) + $Br) * (1 + $C / COVALENT_SYNERGY_DIVISOR);
    return round($base * modCond($nivCondBr));
}

function potentielDestruction($H, $O, $nivCondH)
{
    $base = (pow($H, COVALENT_BASE_EXPONENT) + $H) * (1 + $O / COVALENT_SYNERGY_DIVISOR);
    return round($base * modCond($nivCondH));
}

function pillage($S, $Cl, $nivCondS, $bonusMedaille = 0)
{
    $base = (pow($S, COVALENT_BASE_EXPONENT) + $S) * (1 + $Cl / COVALENT_SYNERGY_DIVISOR);
    return round($base * modCond($nivCondS) * (1 + $bonusMedaille / 100));
}

function productionEnergieMolecule($iode, $niveau)
{
    return round((IODE_QUADRATIC_COEFFICIENT * pow($iode, 2) + IODE_ENERGY_COEFFICIENT * $iode) * (1 + $niveau / IODE_LEVEL_DIVISOR));
}

function vitesse($Cl, $N, $nivCondCl)
{
    $clContrib = min(SPEED_SOFT_CAP, $Cl * SPEED_ATOM_COEFFICIENT);
    $base = 1 + $clContrib + (($Cl * $N) / SPEED_SYNERGY_DIVISOR);
    return max(1.0, floor($base * modCond($nivCondCl) * 100) / 100);
}

function bonusLieur($niveau)
{
    return 1 + $niveau * LIEUR_LINEAR_BONUS_PER_LEVEL;
}

function tempsFormation($ntotal, $azote, $iode, $nivCondN, $nivLieur, $joueur = null)
{
    $bonus_lieur = bonusLieur($nivLieur);
    $vitesse_form = (1 + pow($azote, 1.1) * (1 + $iode / 200)) * modCond($nivCondN) * $bonus_lieur;

    if ($joueur !== null) {
        $catalystSpeedBonus = 1 + catalystEffect('formation_speed');
        $allianceCatalyseurBonus = 1 + allianceResearchBonus($joueur, 'formation_speed');
        $specFormationMod = getSpecModifier($joueur, 'formation_speed');
        $vitesse_form *= $catalystSpeedBonus * $allianceCatalyseurBonus * (1 + $specFormationMod);
    }

    return ceil(($ntotal / max(0.01, $vitesse_form)) * 100) / 100;
}


function coefDisparition($joueur, $classeOuNbTotal, $type = 0)
{
    static $cache = [];
    $cacheKey = $joueur . '-' . $classeOuNbTotal . '-' . $type;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    global $base;
    global $nomsRes;
    global $paliersPertes;
    global $bonusMedailles;

    if ($type == 0) {
        $donnees = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $joueur, $classeOuNbTotal);
    }

    $stabilisateur = dbFetchOne($base, 'SELECT stabilisateur FROM constructions WHERE login=?', 's', $joueur);

    $donneesMedaille = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $joueur);
    $bonus = 0;

    foreach ($paliersPertes as $num => $palier) {
        if ($donneesMedaille['moleculesPerdues'] >= $palier) {
            $bonus = $bonusMedailles[$num];
        }
    }

    if ($type == 0) {
        $nbAtomes = 0;
        foreach ($nomsRes as $num => $ressource) {
            $nbAtomes = $nbAtomes + $donnees[$ressource];
        }
    } else {
        $nbAtomes = $classeOuNbTotal;
    }
    $rawDecay = pow(DECAY_BASE, pow(1 + $nbAtomes / DECAY_ATOM_DIVISOR, DECAY_MASS_EXPONENT) / DECAY_POWER_DIVISOR);
    $modStab = pow(STABILISATEUR_ASYMPTOTE, $stabilisateur['stabilisateur']);
    $modMedal = 1 - ($bonus / 100);
    $baseDecay = pow($rawDecay, $modStab * $modMedal);

    // Catalyst decay increase (Volatilité: +30% faster decay)
    $catalystDecayIncrease = catalystEffect('decay_increase');
    if ($catalystDecayIncrease > 0) {
        $baseDecay = pow($baseDecay, 1.0 + $catalystDecayIncrease);
    }

    // Isotope decay modifier: Stable = slower decay, Réactif = faster decay
    if ($type == 0 && isset($donnees['isotope'])) {
        $isotope = intval($donnees['isotope']);
        if ($isotope == ISOTOPE_STABLE) {
            // Reduce decay rate (move coefficient closer to 1.0)
            $baseDecay = pow($baseDecay, 1.0 + ISOTOPE_STABLE_DECAY_MOD); // -30% = 0.7 exponent = slower
        } elseif ($isotope == ISOTOPE_REACTIF) {
            // Increase decay rate (move coefficient further from 1.0)
            // ISOTOPE_REACTIF_DECAY_MOD = 0.20, so exponent = 1.20 = +20% faster decay
            $baseDecay = pow($baseDecay, 1.0 + ISOTOPE_REACTIF_DECAY_MOD);
        }
    }

    $cache[$cacheKey] = $baseDecay;
    return $baseDecay;
}

function demiVie($joueur, $classeOuNbTotal, $type = 0)
{
    // FIX FINDING-GAME-020: Prevent division by zero when decay coefficient >= 1.0 (no decay)
    $coef = coefDisparition($joueur, $classeOuNbTotal, $type);
    if ($coef >= 1.0) return PHP_INT_MAX; // No decay = infinite half-life
    return round((log(0.5, DECAY_BASE) / log($coef, DECAY_BASE)));
}


function pointsDeVie($niveau, $joueur = null)
{
    $base_hp = round(BUILDING_HP_BASE * pow(max(1, $niveau), BUILDING_HP_POLY_EXP));
    if ($joueur !== null) {
        $fortBonus = 1 + allianceResearchBonus($joueur, 'building_hp');
        return round($base_hp * $fortBonus);
    }
    return $base_hp;
}

function vieChampDeForce($niveau, $joueur = null)
{
    $base_hp = round(FORCEFIELD_HP_BASE * pow(max(1, $niveau), BUILDING_HP_POLY_EXP));
    if ($joueur !== null) {
        $fortBonus = 1 + allianceResearchBonus($joueur, 'building_hp');
        return round($base_hp * $fortBonus);
    }
    return $base_hp;
}

function vieIonisateur($niveau, $joueur = null)
{
    $base_hp = round(IONISATEUR_HP_BASE * pow(max(1, $niveau), BUILDING_HP_POLY_EXP));
    if ($joueur !== null) {
        $fortBonus = 1 + allianceResearchBonus($joueur, 'building_hp');
        return round($base_hp * $fortBonus);
    }
    return $base_hp;
}

function coutClasse($numero)
{
    return (pow($numero + CLASS_COST_OFFSET, CLASS_COST_EXPONENT));
}

function placeDepot($niveau)
{
    return round(BASE_STORAGE_INITIAL * pow(ECO_GROWTH_BASE, $niveau));
}

function capaciteCoffreFort($nivCoffre, $nivDepot)
{
    $pct = min(VAULT_MAX_PROTECTION_PCT, $nivCoffre * VAULT_PCT_PER_LEVEL);
    return round(placeDepot($nivDepot) * $pct);
}

function computeMedalBonus($points, $paliers, $bonusMedailles) {
    $bonus = 0;
    foreach ($paliers as $num => $palier) {
        if ($points >= $palier) $bonus = $bonusMedailles[$num];
    }
    return min($bonus, MAX_CROSS_SEASON_MEDAL_BONUS);
}
