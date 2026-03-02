<?php
/**
 * Game Formulas Module
 * Pure math/game formula functions. Most are pure functions (no DB),
 * but some require DB access for medal bonuses.
 */

function pointsVictoireJoueur($classement)
{
    if ($classement == 1) {
        return 100;
    }
    if ($classement == 2) {
        return 80;
    }
    if ($classement == 3) {
        return 70;
    }
    if ($classement <= 10) {
        return 70 - ($classement - 3) * 5;
    }
    if ($classement <= 20) {
        return 35 - ($classement - 10) * 2;
    }
    if ($classement <= 50) {
        return max(1, floor(15 - ($classement - 20) * 0.4));
    }
    if ($classement <= 100) {
        return max(1, floor(3 - ($classement - 50) * 0.04));
    }
    return 0;
}

function pointsVictoireAlliance($classement)
{
    if ($classement == 1) {
        return 15;
    }
    if ($classement == 2) {
        return 10;
    }
    if ($classement == 3) {
        return 7;
    }
    if ($classement < 10) {
        return 10 - $classement;
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
    return $niveau / 100;
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

    return round((1 + (0.1 * $oxygene) * (0.1 * $oxygene) + $oxygene) * (1 + $niveau / 50) * (1 + $bonus / 100));
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

    return round((1 + (0.1 * $carbone) * (0.1 * $carbone) + $carbone) * (1 + $niveau / 50) * (1 + $bonus / 100));
}

function pointsDeVieMolecule($brome, $niveau)
{
    return round((1 + (0.1 * $brome) * (0.1 * $brome) + $brome) * (1 + $niveau / 50));
}

function potentielDestruction($hydrogene, $niveau)
{
    return round(((0.075 * $hydrogene) * (0.075 * $hydrogene) + $hydrogene) * (1 + $niveau / 50));
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
    return round(((0.1 * $soufre) * (0.1 * $soufre) + $soufre / 3) * (1 + $niveau / 50) * (1 + $bonus / 100) * $catalystPillageBonus);
}

function productionEnergieMolecule($iode, $niveau)
{
    return round(IODE_ENERGY_COEFFICIENT * $iode * (1 + $niveau / 50));
}

function vitesse($chlore, $niveau)
{
    return floor((1 + 0.5 * $chlore) * (1 + $niveau / 50) * 100) / 100;
}

function bonusLieur($niveau)
{
    return floor(100 * pow(1.07, $niveau)) / 100;
}

function tempsFormation($azote, $niveau, $ntotal, $joueur)
{
    global $base;
    $constructions = dbFetchOne($base, 'SELECT lieur FROM constructions WHERE login=?', 's', $joueur);
    $catalystSpeedBonus = 1 + catalystEffect('formation_speed');
    return ceil($ntotal / (1 + pow(0.09 * $azote, 1.09)) / (1 + $niveau / 20) / bonusLieur($constructions['lieur']) / $catalystSpeedBonus * 100) / 100;
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
    return round((log(0.5, 0.99) / log(coefDisparition($joueur, $classeOuNbTotal, $type), 0.99)));
}


function pointsDeVie($niveau)
{
    global $base;
    return round(20 * (pow(1.2, $niveau) + pow($niveau, 1.2)));
}

function vieChampDeForce($niveau)
{
    return round(50 * (pow(1.2, $niveau) + pow($niveau, 1.2)));
}

function coutClasse($numero)
{
    global $base;
    return (pow($numero + 1, 4));
}

function placeDepot($niveau)
{
    return 500 * $niveau;
}
