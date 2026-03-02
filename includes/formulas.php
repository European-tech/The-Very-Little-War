<?php
/**
 * Game Formulas Module
 * Pure math/game formula functions. Most are pure functions (no DB),
 * but some require DB access for medal bonuses.
 */

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

function bonusDuplicateur($niveau)
{
    return $niveau * DUPLICATEUR_BONUS_PER_LEVEL;
}

function drainageProducteur($niveau)
{
    return round(PRODUCTEUR_DRAIN_PER_LEVEL * $niveau);
}

function attaque($oxygene, $niveau, $joueur)
{
    global $paliersAttaque;
    global $bonusMedailles;

    global $base;
    $donneesMedaille = dbFetchOne($base, 'SELECT pointsAttaque FROM autre WHERE login=?', 's', $joueur);
    $bonus = 0;

    foreach ($paliersAttaque as $num => $palier) {
        if ($donneesMedaille['pointsAttaque'] >= $palier) {
            $bonus = $bonusMedailles[$num];
        }
    }

    return round((1 + (ATTACK_ATOM_COEFFICIENT * $oxygene) * (ATTACK_ATOM_COEFFICIENT * $oxygene) + $oxygene) * (1 + $niveau / ATTACK_LEVEL_DIVISOR) * (1 + $bonus / 100));
}

function defense($carbone, $niveau, $joueur)
{
    global $paliersDefense;
    global $bonusMedailles;

    global $base;
    $donneesMedaille = dbFetchOne($base, 'SELECT pointsDefense FROM autre WHERE login=?', 's', $joueur);
    $bonus = 0;

    foreach ($paliersDefense as $num => $palier) {
        if ($donneesMedaille['pointsDefense'] >= $palier) {
            $bonus = $bonusMedailles[$num];
        }
    }

    return round((1 + (DEFENSE_ATOM_COEFFICIENT * $carbone) * (DEFENSE_ATOM_COEFFICIENT * $carbone) + $carbone) * (1 + $niveau / DEFENSE_LEVEL_DIVISOR) * (1 + $bonus / 100));
}

function pointsDeVieMolecule($brome, $niveau)
{
    return round((1 + (HP_ATOM_COEFFICIENT * $brome) * (HP_ATOM_COEFFICIENT * $brome) + $brome) * (1 + $niveau / HP_LEVEL_DIVISOR));
}

function potentielDestruction($hydrogene, $niveau)
{
    return round(((DESTRUCTION_ATOM_COEFFICIENT * $hydrogene) * (DESTRUCTION_ATOM_COEFFICIENT * $hydrogene) + $hydrogene) * (1 + $niveau / DESTRUCTION_LEVEL_DIVISOR));
}

function pillage($soufre, $niveau, $joueur)
{
    global $paliersPillage;
    global $bonusMedailles;

    global $base;
    $donneesMedaille = dbFetchOne($base, 'SELECT ressourcesPillees FROM autre WHERE login=?', 's', $joueur);
    $bonus = 0;

    foreach ($paliersPillage as $num => $palier) {
        if ($donneesMedaille['ressourcesPillees'] >= $palier) {
            $bonus = $bonusMedailles[$num];
        }
    }

    $catalystPillageBonus = 1 + catalystEffect('pillage_bonus');
    return round(((PILLAGE_ATOM_COEFFICIENT * $soufre) * (PILLAGE_ATOM_COEFFICIENT * $soufre) + $soufre / PILLAGE_SOUFRE_DIVISOR) * (1 + $niveau / PILLAGE_LEVEL_DIVISOR) * (1 + $bonus / 100) * $catalystPillageBonus);
}

function productionEnergieMolecule($iode, $niveau)
{
    return round(IODE_ENERGY_COEFFICIENT * $iode * (1 + $niveau / IODE_LEVEL_DIVISOR));
}

function vitesse($chlore, $niveau)
{
    return floor((1 + SPEED_ATOM_COEFFICIENT * $chlore) * (1 + $niveau / SPEED_LEVEL_DIVISOR) * 100) / 100;
}

function bonusLieur($niveau)
{
    return floor(100 * pow(LIEUR_GROWTH_BASE, $niveau)) / 100;
}

function tempsFormation($azote, $niveau, $ntotal, $joueur)
{
    global $base;
    $constructions = dbFetchOne($base, 'SELECT lieur FROM constructions WHERE login=?', 's', $joueur);
    $catalystSpeedBonus = 1 + catalystEffect('formation_speed');
    $allianceCatalyseurBonus = 1 + allianceResearchBonus($joueur, 'formation_speed');
    return ceil($ntotal / (1 + pow(FORMATION_AZOTE_COEFFICIENT * $azote, FORMATION_AZOTE_EXPONENT)) / (1 + $niveau / FORMATION_LEVEL_DIVISOR) / bonusLieur($constructions['lieur']) / $catalystSpeedBonus / $allianceCatalyseurBonus * 100) / 100;
}


function coefDisparition($joueur, $classeOuNbTotal, $type = 0)
{
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
    $baseDecay = pow(pow(DECAY_BASE, pow(1 + $nbAtomes / DECAY_ATOM_DIVISOR, 2) / DECAY_POWER_DIVISOR), (1 - ($bonus / 100)) * (1 - ($stabilisateur['stabilisateur'] * STABILISATEUR_BONUS_PER_LEVEL)));

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
            $baseDecay = pow($baseDecay, 1.0 + ISOTOPE_REACTIF_DECAY_MOD); // +50% = 1.5 exponent = faster
        }
    }

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
    $base_hp = round(BUILDING_HP_BASE * (pow(BUILDING_HP_GROWTH_BASE, $niveau) + pow($niveau, BUILDING_HP_LEVEL_EXP)));
    if ($joueur !== null) {
        $fortBonus = 1 + allianceResearchBonus($joueur, 'building_hp');
        return round($base_hp * $fortBonus);
    }
    return $base_hp;
}

function vieChampDeForce($niveau, $joueur = null)
{
    $base_hp = round(FORCEFIELD_HP_BASE * (pow(FORCEFIELD_HP_GROWTH_BASE, $niveau) + pow($niveau, FORCEFIELD_HP_LEVEL_EXP)));
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
    return BASE_STORAGE_PER_LEVEL * $niveau;
}
