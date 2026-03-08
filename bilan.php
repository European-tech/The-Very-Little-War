<?php
include("includes/basicprivatephp.php");
$pageTitle = 'Bilan des Bonus — The Very Little War';
include("includes/layout.php");

// =============================================================================
// DATA LOADING
// =============================================================================
$login = $_SESSION['login'];

// Buildings
$constructions = dbFetchOne($base, 'SELECT * FROM constructions WHERE login = ?', 's', $login);

// Player stats
$autre = dbFetchOne($base, 'SELECT * FROM autre WHERE login = ?', 's', $login);

// Resources
$ressources = dbFetchOne($base, 'SELECT * FROM ressources WHERE login = ?', 's', $login);

// Molecules (MAX_MOLECULE_CLASSES classes)
$molecules = [];
for ($i = 1; $i <= MAX_MOLECULE_CLASSES; $i++) {
    $mol = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire = ? AND numeroclasse = ?', 'si', $login, $i);
    $molecules[$i] = $mol;
}

// Alliance info
$allianceId = (int)$autre['idalliance'];
$allianceName = '';
$duplicateurLevel = 0;
$allianceData = null;
if ($allianceId > 0) {
    $allianceData = dbFetchOne($base, 'SELECT * FROM alliances WHERE id = ?', 'i', $allianceId);
    $allianceName = $allianceData['nom'] ?? '';
    $duplicateurLevel = (int)($allianceData['duplicateur'] ?? 0);
}

// Medal globals
global $paliersEnergievore, $paliersAttaque, $paliersDefense, $paliersPillage, $paliersPertes, $paliersTerreur;
global $bonusMedailles, $paliersMedailles, $imagesMedailles;
global $nomsRes, $nomsAccents, $lettre, $couleurs;
global $ALLIANCE_RESEARCH, $SPECIALIZATIONS, $FORMATIONS, $CATALYSTS, $PRESTIGE_UNLOCKS;

// Condenseur and Producteur point allocations
$niveauxCondenseur = explode(';', $constructions['pointsCondenseur']);
$niveauxProducteur = explode(';', $constructions['pointsProducteur']);

// =============================================================================
// POST HANDLER: Specialization choice (irreversible)
// =============================================================================
if (isset($_POST['choose_specialization']) && isset($_POST['spec_type']) && isset($_POST['spec_choice'])) {
    csrfCheck();
    $specType = $_POST['spec_type'];
    $specChoice = (int)$_POST['spec_choice'];

    if (isset($SPECIALIZATIONS[$specType])) {
        $spec = $SPECIALIZATIONS[$specType];
        $col = $spec['column'];
        $allowedColumns = ['spec_combat', 'spec_economy', 'spec_research'];
        // MEDIUM-006: Wrap in transaction with FOR UPDATE to prevent TOCTOU race
        if (in_array($col, $allowedColumns, true)) {
            withTransaction($base, function() use ($base, $login, $col, $spec, $specChoice, $allowedColumns, &$constructions) {
                // Re-read with FOR UPDATE to get a fresh, locked view of the row
                $freshConstructions = dbFetchOne($base, 'SELECT specialisation, spec_combat, spec_economy, spec_research FROM constructions WHERE login=? FOR UPDATE', 's', $login);
                if (!$freshConstructions) return;
                $currentChoice = (int)($freshConstructions[$col] ?? 0);
                // Also re-read the unlock building level (not in allowed cols, safe to re-query)
                $buildingRow = dbFetchOne($base, 'SELECT ' . $spec['unlock_building'] . ' FROM constructions WHERE login=?', 's', $login);
                $buildingLevel = $buildingRow ? (int)($buildingRow[$spec['unlock_building']] ?? 0) : 0;
                // Only proceed if still unset and unlock is met (prevents double-claim race)
                if ($currentChoice === 0 && $buildingLevel >= $spec['unlock_level'] && isset($spec['options'][$specChoice])) {
                    dbExecute($base, "UPDATE constructions SET $col = ? WHERE login = ?", 'is', $specChoice, $login);
                    $constructions[$col] = $specChoice;
                }
            });
        }
    }
}

// =============================================================================
// HELPER: Format number with space separator
// =============================================================================
function fmtNum($n) {
    return number_format(round($n), 0, ',', ' ');
}

// =============================================================================
// HELPER: Find current medal tier index (-1 = none) and bonus
// =============================================================================
function getMedalTier($value, $paliers) {
    global $bonusMedailles;
    $tier = -1;
    foreach ($paliers as $idx => $threshold) {
        if ($value >= $threshold) {
            $tier = $idx;
        }
    }
    return $tier;
}

function getMedalBonus($value, $paliers) {
    global $bonusMedailles;
    $tier = getMedalTier($value, $paliers);
    return $tier >= 0 ? $bonusMedailles[$tier] : 0;
}

// =============================================================================
// PAGE HEADER
// =============================================================================
debutCarte("Bilan des bonus", "", "images/menu/classement.png");
    debutContent();
        echo '<p>Cette page recense l\'ensemble de vos bonus actifs et leur provenance. Chaque section montre le calcul detaille etape par etape.</p>';
    finContent();
finCarte();

// =============================================================================
// SECTION A: Production d'Energie
// =============================================================================
debutCarte("Production d'Energie");
    debutContent();

    $genLevel = (int)$constructions['generateur'];
    $prodLevel = (int)$constructions['producteur'];

    // Step 1: Base
    $energyBase = BASE_ENERGY_PER_LEVEL * $genLevel;

    // Step 2: Iode catalyst
    $totalIodeAtoms = 0;
    for ($i = 1; $i <= MAX_MOLECULE_CLASSES; $i++) {
        if ($molecules[$i]) {
            $totalIodeAtoms += $molecules[$i]['iode'] * $molecules[$i]['nombre'];
        }
    }
    $iodeCatalystBonus = 1.0 + min(IODE_CATALYST_MAX_BONUS, $totalIodeAtoms / IODE_CATALYST_DIVISOR);
    $afterIode = $energyBase * $iodeCatalystBonus;

    // Step 3: Medal Energievore
    $medalEnergyBonus = getMedalBonus($autre['energieDepensee'], $paliersEnergievore);
    $afterMedal = $afterIode * (1 + $medalEnergyBonus / 100);

    // Step 4: Duplicateur
    $bonusDupVal = 1 + bonusDuplicateur($duplicateurLevel);
    $afterDup = $afterMedal * $bonusDupVal;

    // Step 5: Prestige
    $prestigeProdMult = prestigeProductionBonus($login);
    $afterPrestige = $afterDup * $prestigeProdMult;

    // Step 6: Resource node proximity bonus for energy
    require_once('includes/resource_nodes.php');
    $energyNodeBonus = 0.0;
    $playerPosEnergy = dbFetchOne($base, 'SELECT x, y FROM membre WHERE login = ?', 's', $login);
    if ($playerPosEnergy && $playerPosEnergy['x'] >= 0 && $playerPosEnergy['y'] >= 0) {
        $energyNodeBonus = getResourceNodeBonus($base, $playerPosEnergy['x'], $playerPosEnergy['y'], 'energie');
    }
    $afterNodes = $afterPrestige * (1 + $energyNodeBonus);

    // Step 7: Compound production boost
    require_once('includes/compounds.php');
    $compoundEnergyBonus = getCompoundBonus($base, $login, 'production_boost');
    $afterCompound = $afterNodes * (1 + $compoundEnergyBonus);

    // Step 8: Specialization energy_production modifier
    $specEnergyMod = getSpecModifier($login, 'energy_production');
    $afterSpec = $afterCompound * (1 + $specEnergyMod);

    // Step 9: Producteur drain
    $drainage = drainageProducteur($prodLevel);
    $netEnergy = max(0, round($afterSpec) - $drainage);

    // Verify against revenuEnergie
    $verifiedEnergy = revenuEnergie($genLevel, $login, 0);

    echo '<div class="data-table"><table>';
    echo '<thead><tr><th>Source</th><th>Bonus</th><th>Valeur</th></tr></thead>';
    echo '<tbody>';
    echo '<tr><td>Generateur niveau ' . $genLevel . '</td><td>' . BASE_ENERGY_PER_LEVEL . ' x ' . $genLevel . '</td><td>' . fmtNum($energyBase) . ' E/h</td></tr>';

    if ($totalIodeAtoms > 0) {
        echo '<tr><td>Catalyseur Iode (' . fmtNum($totalIodeAtoms) . ' atomes)</td><td style="color:green">x' . round($iodeCatalystBonus, 4) . '</td><td>' . fmtNum(round($afterIode)) . ' E/h</td></tr>';
    }

    if ($medalEnergyBonus > 0) {
        $tierIdx = getMedalTier($autre['energieDepensee'], $paliersEnergievore);
        echo '<tr><td>Medaille Energivore (' . htmlspecialchars($paliersMedailles[$tierIdx], ENT_QUOTES, 'UTF-8') . ')</td><td style="color:green">+' . $medalEnergyBonus . '%</td><td>' . fmtNum(round($afterMedal)) . ' E/h</td></tr>';
    }

    if ($duplicateurLevel > 0) {
        echo '<tr><td>Duplicateur niveau ' . $duplicateurLevel . '</td><td style="color:green">+' . round(bonusDuplicateur($duplicateurLevel) * 100) . '%</td><td>' . fmtNum(round($afterDup)) . ' E/h</td></tr>';
    }

    if ($prestigeProdMult > 1.0) {
        echo '<tr><td>Prestige (Experimente)</td><td style="color:green">+' . round(($prestigeProdMult - 1) * 100) . '%</td><td>' . fmtNum(round($afterPrestige)) . ' E/h</td></tr>';
    }

    // MED-002: Resource node bonus row
    if ($energyNodeBonus > 0.0) {
        echo '<tr><td>Noeud de ressource (proximite)</td><td style="color:green">+' . round($energyNodeBonus * 100, 1) . '%</td><td>' . fmtNum(round($afterNodes)) . ' E/h</td></tr>';
    }

    // MED-002: Compound production boost row
    if ($compoundEnergyBonus > 0.0) {
        echo '<tr><td>Compose actif (boost production)</td><td style="color:green">+' . round($compoundEnergyBonus * 100, 1) . '%</td><td>' . fmtNum(round($afterCompound)) . ' E/h</td></tr>';
    }

    // MED-002: Specialization energy modifier row
    if ($specEnergyMod != 0.0) {
        $specColor = $specEnergyMod > 0 ? 'green' : 'red';
        $specSign = $specEnergyMod > 0 ? '+' : '';
        echo '<tr><td>Specialisation (energie)</td><td style="color:' . $specColor . '">' . $specSign . round($specEnergyMod * 100, 1) . '%</td><td>' . fmtNum(round($afterSpec)) . ' E/h</td></tr>';
    }

    echo '<tr><td>Producteur niveau ' . $prodLevel . '</td><td style="color:red">-' . fmtNum($drainage) . ' E/h</td><td>' . fmtNum($netEnergy) . ' E/h</td></tr>';
    echo '<tr style="font-weight:bold;background:#f5f5f5;"><td>Production nette</td><td></td><td style="color:green">' . fmtNum($verifiedEnergy) . ' E/h</td></tr>';
    echo '</tbody></table></div>';

    finContent();
finCarte();

// =============================================================================
// SECTION B: Production d'Atomes
// =============================================================================
debutCarte("Production d'Atomes");
    debutContent();

    // MED-033: Load shared atom bonuses (node/compound/spec) for the full bonus chain
    $atomPlayerPos = dbFetchOne($base, 'SELECT x, y FROM membre WHERE login = ?', 's', $login);
    require_once('includes/compounds.php');
    $compoundAtomBonus = getCompoundBonus($base, $login, 'production_boost');
    $specAtomMod = getSpecModifier($login, 'atom_production');

    echo '<div class="data-table"><table>';
    echo '<thead><tr><th>Atome</th><th>Points alloues</th><th>Base</th>';
    if ($duplicateurLevel > 0) echo '<th>Duplicateur</th>';
    if ($prestigeProdMult > 1.0) echo '<th>Prestige</th>';
    echo '<th>Noeud</th>';
    if ($compoundAtomBonus > 0.0) echo '<th>Compose</th>';
    if ($specAtomMod != 0.0) echo '<th>Spec</th>';
    echo '<th>Total/h</th></tr></thead>';
    echo '<tbody>';

    $nomsDisplay = ['Carbone', 'Azote', 'Hydrogene', 'Oxygene', 'Chlore', 'Soufre', 'Brome', 'Iode'];

    require_once('includes/resource_nodes.php');
    foreach ($nomsRes as $num => $ressource) {
        $points = (int)$niveauxProducteur[$num];
        $atomBase = BASE_ATOMS_PER_POINT * $points;
        $atomDup = $atomBase * $bonusDupVal;
        $atomPrestige = $atomDup * $prestigeProdMult;

        // Per-atom node bonus (different resource type per atom)
        $atomNodeBonus = 0.0;
        if ($atomPlayerPos && $atomPlayerPos['x'] >= 0 && $atomPlayerPos['y'] >= 0) {
            $atomNodeBonus = getResourceNodeBonus($base, $atomPlayerPos['x'], $atomPlayerPos['y'], $nomsRes[$num]);
        }
        $atomAfterNode = $atomPrestige * (1 + $atomNodeBonus);
        $atomAfterCompound = $atomAfterNode * (1 + $compoundAtomBonus);
        $atomAfterSpec = $atomAfterCompound * (1 + $specAtomMod);
        $verified = revenuAtome($num, $login);

        echo '<tr>';
        echo '<td style="color:' . htmlspecialchars($couleurs[$num], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($nomsDisplay[$num], ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($lettre[$num], ENT_QUOTES, 'UTF-8') . ')</td>';
        echo '<td>' . $points . '</td>';
        echo '<td>' . fmtNum($atomBase) . '</td>';
        if ($duplicateurLevel > 0) echo '<td>' . fmtNum(round($atomDup)) . '</td>';
        if ($prestigeProdMult > 1.0) echo '<td>' . fmtNum(round($atomPrestige)) . '</td>';
        echo '<td' . ($atomNodeBonus > 0 ? ' style="color:green"' : '') . '>'
            . ($atomNodeBonus > 0 ? fmtNum(round($atomAfterNode)) : '—') . '</td>';
        if ($compoundAtomBonus > 0.0) echo '<td style="color:green">' . fmtNum(round($atomAfterCompound)) . '</td>';
        if ($specAtomMod != 0.0) {
            $specColor = $specAtomMod > 0 ? 'green' : 'red';
            echo '<td style="color:' . $specColor . '">' . fmtNum(round($atomAfterSpec)) . '</td>';
        }
        echo '<td style="color:green;font-weight:bold">' . fmtNum($verified) . '/h</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    // Show shared bonus summary
    echo '<p style="margin-top:8px;font-size:13px;color:#666;">';
    echo 'Base : ' . BASE_ATOMS_PER_POINT . ' atomes/h par point alloue';
    if ($duplicateurLevel > 0) echo ' | Duplicateur : +' . round(bonusDuplicateur($duplicateurLevel) * 100) . '%';
    if ($prestigeProdMult > 1.0) echo ' | Prestige : +' . round(($prestigeProdMult - 1) * 100) . '%';
    if ($compoundAtomBonus > 0.0) echo ' | Compose actif : +' . round($compoundAtomBonus * 100, 1) . '%';
    if ($specAtomMod != 0.0) {
        $specSign = $specAtomMod > 0 ? '+' : '';
        echo ' | Specialisation : ' . $specSign . round($specAtomMod * 100, 1) . '%';
    }
    echo '</p>';

    finContent();
finCarte();

// =============================================================================
// SECTION B2: Resource Nodes (Nearby Map Bonuses)
// =============================================================================
require_once('includes/resource_nodes.php');
$playerPos = dbFetchOne($base, 'SELECT x, y FROM membre WHERE login = ?', 's', $login);
$nearbyNodes = [];
if ($playerPos && $playerPos['x'] >= 0 && $playerPos['y'] >= 0) {
    $allNodes = getActiveResourceNodes($base);
    foreach ($allNodes as $node) {
        $dist = sqrt(pow($playerPos['x'] - $node['x'], 2) + pow($playerPos['y'] - $node['y'], 2));
        // MISC12-003: Add epsilon tolerance so floating-point rounding (e.g. 19.9999999 vs 20)
        // does not cause border-case nodes to be incorrectly excluded from the bonus display.
        if ($dist <= (float)$node['radius'] + 0.001) {
            $node['distance'] = round($dist, 1);
            $nearbyNodes[] = $node;
        }
    }
}

if (!empty($nearbyNodes)) {
    debutCarte("Noeuds de Ressources (proximite)");
        debutContent();

        echo '<div class="data-table"><table>';
        echo '<thead><tr><th>Type</th><th>Position</th><th>Distance</th><th>Bonus</th></tr></thead>';
        echo '<tbody>';

        foreach ($nearbyNodes as $node) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars(ucfirst($node['resource_type']), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . (int)$node['x'] . ',' . (int)$node['y'] . '</td>';
            echo '<td>' . $node['distance'] . ' cases</td>';
            echo '<td style="color:green">+' . (int)$node['bonus_pct'] . '%</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        echo '<p style="margin-top:8px;font-size:13px;color:#666;">Les noeuds de ressources sont regeneres chaque saison. Installez-vous pres d\'un noeud pour beneficier du bonus de production.</p>';

        finContent();
    finCarte();
}

// =============================================================================
// SECTION C: Combat — Attaque
// =============================================================================
debutCarte("Combat — Attaque");
    debutContent();

    $ionisateurLevel = (int)$constructions['ionisateur'];
    $ionisateurBonus = $ionisateurLevel * IONISATEUR_COMBAT_BONUS_PER_LEVEL;

    $medalAttackBonus = getMedalBonus($autre['pointsAttaque'], $paliersAttaque);
    $medalAttackTier = getMedalTier($autre['pointsAttaque'], $paliersAttaque);

    $prestigeCombatMult = prestigeCombatBonus($login);

    $catalystAttackBonus = catalystEffect('attack_bonus');

    $specCombat = (int)($constructions['spec_combat'] ?? 0);
    // LOW-016: use $SPECIALIZATIONS config values, not hardcoded percentages
    $specAttackMod = 0;
    $specAttackLabel = '';
    if ($specCombat === 1) {
        $specAttackMod = $SPECIALIZATIONS['combat']['options'][1]['modifiers']['attack'];
        $atkPct = round($specAttackMod * 100);
        $specAttackLabel = 'Oxydant (' . ($atkPct >= 0 ? '+' : '') . $atkPct . '%)';
    } elseif ($specCombat === 2) {
        $specAttackMod = $SPECIALIZATIONS['combat']['options'][2]['modifiers']['attack'];
        $atkPct = round($specAttackMod * 100);
        $specAttackLabel = 'Reducteur (' . ($atkPct >= 0 ? '+' : '') . $atkPct . '%)';
    }

    echo '<div class="data-table"><table>';
    echo '<thead><tr><th>Source</th><th>Bonus</th></tr></thead>';
    echo '<tbody>';

    echo '<tr><td>Ionisateur niveau ' . $ionisateurLevel . '</td><td style="color:green">+' . $ionisateurBonus . '% d\'attaque</td></tr>';

    if ($medalAttackTier >= 0) {
        echo '<tr><td>Medaille Attaquant (' . htmlspecialchars($paliersMedailles[$medalAttackTier], ENT_QUOTES, 'UTF-8') . ')</td><td style="color:green">+' . $medalAttackBonus . '%</td></tr>';
    } else {
        echo '<tr><td>Medaille Attaquant</td><td style="color:#999">Aucune</td></tr>';
    }

    if ($prestigeCombatMult > 1.0) {
        echo '<tr><td>Prestige (Maitre Chimiste)</td><td style="color:green">+' . round(($prestigeCombatMult - 1) * 100) . '%</td></tr>';
    }

    if ($catalystAttackBonus > 0) {
        echo '<tr><td>Catalyseur de la semaine (Combustion)</td><td style="color:green">+' . round($catalystAttackBonus * 100) . '%</td></tr>';
    }

    if ($specAttackMod != 0) {
        $color = $specAttackMod > 0 ? 'green' : 'red';
        echo '<tr><td>Specialisation ' . htmlspecialchars($specAttackLabel, ENT_QUOTES, 'UTF-8') . '</td><td style="color:' . $color . '">' . ($specAttackMod > 0 ? '+' : '') . round($specAttackMod * 100) . '%</td></tr>';
    } elseif ($specCombat === 0) {
        echo '<tr><td>Specialisation Combat</td><td style="color:#999">Non choisie</td></tr>';
    }

    // Formation
    $currentFormation = (int)($constructions['formation'] ?? 0);
    $formationInfo = $FORMATIONS[$currentFormation] ?? $FORMATIONS[FORMATION_DISPERSEE];
    $formationDesc = isset($formationInfo['desc']) ? '<br/><small style="color:#666;">' . htmlspecialchars($formationInfo['desc'], ENT_QUOTES, 'UTF-8') . '</small>' : '';
    echo '<tr><td>Formation defensive</td><td>' . htmlspecialchars($formationInfo['name'], ENT_QUOTES, 'UTF-8') . $formationDesc . '</td></tr>';

    echo '</tbody></table></div>';

    finContent();
finCarte();

// =============================================================================
// SECTION D: Combat — Defense
// =============================================================================
debutCarte("Combat — Defense");
    debutContent();

    $champdeforceLevel = (int)$constructions['champdeforce'];
    $champdeforceBonus = $champdeforceLevel * CHAMPDEFORCE_COMBAT_BONUS_PER_LEVEL;

    $medalDefenseBonus = getMedalBonus($autre['pointsDefense'], $paliersDefense);
    $medalDefenseTier = getMedalTier($autre['pointsDefense'], $paliersDefense);

    $specDefenseMod = 0;
    $specDefenseLabel = '';
    if ($specCombat === 1) {
        $specDefenseMod = $SPECIALIZATIONS['combat']['options'][1]['modifiers']['defense'];
        $defPct = round($specDefenseMod * 100);
        $specDefenseLabel = 'Oxydant (' . ($defPct >= 0 ? '+' : '') . $defPct . '%)';
    } elseif ($specCombat === 2) {
        $specDefenseMod = $SPECIALIZATIONS['combat']['options'][2]['modifiers']['defense'];
        $defPct = round($specDefenseMod * 100);
        $specDefenseLabel = 'Reducteur (' . ($defPct >= 0 ? '+' : '') . $defPct . '%)';
    }

    echo '<div class="data-table"><table>';
    echo '<thead><tr><th>Source</th><th>Bonus</th></tr></thead>';
    echo '<tbody>';

    echo '<tr><td>Champ de force niveau ' . $champdeforceLevel . '</td><td style="color:green">+' . $champdeforceBonus . '% de defense</td></tr>';

    if ($medalDefenseTier >= 0) {
        echo '<tr><td>Medaille Defenseur (' . htmlspecialchars($paliersMedailles[$medalDefenseTier], ENT_QUOTES, 'UTF-8') . ')</td><td style="color:green">+' . $medalDefenseBonus . '%</td></tr>';
    } else {
        echo '<tr><td>Medaille Defenseur</td><td style="color:#999">Aucune</td></tr>';
    }

    if ($prestigeCombatMult > 1.0) {
        echo '<tr><td>Prestige (Maitre Chimiste)</td><td style="color:green">+' . round(($prestigeCombatMult - 1) * 100) . '%</td></tr>';
    }

    if ($specDefenseMod != 0) {
        $color = $specDefenseMod > 0 ? 'green' : 'red';
        echo '<tr><td>Specialisation ' . htmlspecialchars($specDefenseLabel, ENT_QUOTES, 'UTF-8') . '</td><td style="color:' . $color . '">' . ($specDefenseMod > 0 ? '+' : '') . round($specDefenseMod * 100) . '%</td></tr>';
    } elseif ($specCombat === 0) {
        echo '<tr><td>Specialisation Combat</td><td style="color:#999">Non choisie</td></tr>';
    }

    // Formation details (reuse $formationInfo already validated above)
    echo '<tr><td>Formation defensive</td><td>' . htmlspecialchars($formationInfo['name'], ENT_QUOTES, 'UTF-8') . ' — ' . htmlspecialchars($formationInfo['desc'], ENT_QUOTES, 'UTF-8') . '</td></tr>';

    echo '</tbody></table></div>';

    finContent();
finCarte();

// =============================================================================
// SECTION E: Pillage
// =============================================================================
debutCarte("Pillage");
    debutContent();

    $medalPillageBonus = getMedalBonus($autre['ressourcesPillees'], $paliersPillage);
    $medalPillageTier = getMedalTier($autre['ressourcesPillees'], $paliersPillage);

    $catalystPillageBonus = catalystEffect('pillage_bonus');

    $coffrefortLevel = (int)$constructions['coffrefort'];
    $depotLevel = (int)$constructions['depot'];
    $vaultProtection = capaciteCoffreFort($coffrefortLevel, $depotLevel);
    $totalStorage = placeDepot($depotLevel);

    echo '<div class="data-table"><table>';
    echo '<thead><tr><th>Source</th><th>Valeur</th></tr></thead>';
    echo '<tbody>';

    if ($medalPillageTier >= 0) {
        echo '<tr><td>Medaille Pilleur (' . htmlspecialchars($paliersMedailles[$medalPillageTier], ENT_QUOTES, 'UTF-8') . ')</td><td style="color:green">+' . $medalPillageBonus . '% de capacite de pillage</td></tr>';
    } else {
        echo '<tr><td>Medaille Pilleur</td><td style="color:#999">Aucune</td></tr>';
    }

    if ($catalystPillageBonus > 0) {
        echo '<tr><td>Catalyseur (Volatilite)</td><td style="color:green">+' . round($catalystPillageBonus * 100) . '% de pillage</td></tr>';
    }

    // Vault protection — per-level rate and total, capped at VAULT_MAX_PROTECTION_PCT (M-016)
    $vaultPctPerLevel = round(VAULT_PCT_PER_LEVEL * 100, 1);
    $vaultTotalPct    = round(min($coffrefortLevel * VAULT_PCT_PER_LEVEL, VAULT_MAX_PROTECTION_PCT) * 100, 1);
    echo '<tr><td>Coffre-fort niveau ' . $coffrefortLevel
        . '<br/><small style="color:#757575;">' . $vaultPctPerLevel . '% par niveau &mdash; niveau '
        . $coffrefortLevel . ' = ' . $vaultTotalPct . '% du stockage</small>'
        . '</td><td style="color:green">' . fmtNum($vaultProtection) . ' ressources protegees par type</td></tr>';

    $pctProtected = $totalStorage > 0 ? round(($vaultProtection / $totalStorage) * 100, 1) : 0;
    echo '<tr><td>Protection relative</td><td>' . $pctProtected . '% du stockage protege</td></tr>';

    // Alliance research bouclier
    $bouclierBonus = allianceResearchBonus($login, 'pillage_defense');
    if ($bouclierBonus > 0) {
        echo '<tr><td>Recherche Bouclier (alliance)</td><td style="color:green">-' . round($bouclierBonus * 100) . '% de pertes au pillage</td></tr>';
    }

    echo '</tbody></table></div>';

    finContent();
finCarte();

// =============================================================================
// SECTION F: Stockage
// =============================================================================
debutCarte("Stockage");
    debutContent();

    echo '<div class="data-table"><table>';
    echo '<thead><tr><th>Element</th><th>Valeur</th></tr></thead>';
    echo '<tbody>';

    echo '<tr><td>Depot niveau ' . $depotLevel . '</td><td>' . fmtNum($totalStorage) . ' par ressource</td></tr>';
    echo '<tr><td>Coffre-fort niveau ' . $coffrefortLevel
        . '<br/><small style="color:#757575;">' . $vaultPctPerLevel . '% par niveau &mdash; niveau '
        . $coffrefortLevel . ' = ' . $vaultTotalPct . '% du stockage</small>'
        . '</td><td>' . fmtNum($vaultProtection) . ' proteges par type (' . $pctProtected . '%)</td></tr>';

    // Current fill status
    echo '<tr><td>Energie</td><td>' . fmtNum($ressources['energie']) . ' / ' . fmtNum($totalStorage) . '</td></tr>';
    foreach ($nomsRes as $num => $ressource) {
        echo '<tr><td style="color:' . htmlspecialchars($couleurs[$num], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($nomsDisplay[$num], ENT_QUOTES, 'UTF-8') . '</td><td>' . fmtNum($ressources[$ressource]) . ' / ' . fmtNum($totalStorage) . '</td></tr>';
    }

    echo '</tbody></table></div>';

    finContent();
finCarte();

// =============================================================================
// SECTION G: Vitesse de Formation
// =============================================================================
debutCarte("Vitesse de Formation");
    debutContent();

    $lieurLevel = (int)$constructions['lieur'];
    $lieurBonusVal = bonusLieur($lieurLevel);

    $allianceCatalyseurBonus = allianceResearchBonus($login, 'formation_speed');
    $catalystFormationBonus = catalystEffect('formation_speed');

    $specResearch = (int)($constructions['spec_research'] ?? 0);
    // LOW-016: use $SPECIALIZATIONS config values, not hardcoded percentages
    $specFormationMod = 0;
    $specFormationLabel = '';
    if ($specResearch === 1) {
        $specFormationMod = $SPECIALIZATIONS['research']['options'][1]['modifiers']['formation_speed'];
        $fmtPct = round($specFormationMod * 100);
        $specFormationLabel = 'Theorique (' . ($fmtPct >= 0 ? '+' : '') . $fmtPct . '% vitesse)';
    } elseif ($specResearch === 2) {
        $specFormationMod = $SPECIALIZATIONS['research']['options'][2]['modifiers']['formation_speed'];
        $fmtPct = round($specFormationMod * 100);
        $specFormationLabel = 'Applique (' . ($fmtPct >= 0 ? '+' : '') . $fmtPct . '% vitesse)';
    }

    echo '<div class="data-table"><table>';
    echo '<thead><tr><th>Source</th><th>Bonus</th></tr></thead>';
    echo '<tbody>';

    echo '<tr><td>Lieur niveau ' . $lieurLevel . '</td><td style="color:green">x' . round($lieurBonusVal, 2) . ' (base : 1 + ' . $lieurLevel . ' x ' . LIEUR_LINEAR_BONUS_PER_LEVEL . ')</td></tr>';

    if ($allianceCatalyseurBonus > 0) {
        echo '<tr><td>Recherche Catalyseur (alliance)</td><td style="color:green">+' . round($allianceCatalyseurBonus * 100) . '%</td></tr>';
    }

    if ($catalystFormationBonus > 0) {
        echo '<tr><td>Catalyseur de la semaine (Synthese)</td><td style="color:green">+' . round($catalystFormationBonus * 100) . '%</td></tr>';
    }

    if ($specFormationMod != 0) {
        $color = $specFormationMod > 0 ? 'green' : 'red';
        echo '<tr><td>Specialisation ' . htmlspecialchars($specFormationLabel, ENT_QUOTES, 'UTF-8') . '</td><td style="color:' . $color . '">' . ($specFormationMod > 0 ? '+' : '') . round($specFormationMod * 100) . '%</td></tr>';
    } elseif ($specResearch === 0) {
        echo '<tr><td>Specialisation Recherche</td><td style="color:#999">Non choisie</td></tr>';
    }

    echo '</tbody></table></div>';

    finContent();
finCarte();

// =============================================================================
// SECTION H: Declin des Molecules
// =============================================================================
debutCarte("Declin des Molecules");
    debutContent();

    $stabilisateurLevel = (int)$constructions['stabilisateur'];
    $stabReduction = (1 - pow(STABILISATEUR_ASYMPTOTE, $stabilisateurLevel)) * 100;

    $medalPertesBonus = getMedalBonus($autre['moleculesPerdues'], $paliersPertes);
    $medalPertesTier = getMedalTier($autre['moleculesPerdues'], $paliersPertes);

    $catalystDecayIncrease = catalystEffect('decay_increase');

    echo '<div class="data-table"><table>';
    echo '<thead><tr><th>Source</th><th>Effet</th></tr></thead>';
    echo '<tbody>';

    echo '<tr><td>Stabilisateur niveau ' . $stabilisateurLevel . '</td><td style="color:green">-' . round($stabReduction, 1) . '% de vitesse de disparition</td></tr>';

    if ($medalPertesTier >= 0) {
        echo '<tr><td>Medaille Pertes (' . htmlspecialchars($paliersMedailles[$medalPertesTier], ENT_QUOTES, 'UTF-8') . ')</td><td style="color:green">-' . $medalPertesBonus . '% de disparition</td></tr>';
    } else {
        echo '<tr><td>Medaille Pertes</td><td style="color:#999">Aucune</td></tr>';
    }

    if ($catalystDecayIncrease > 0) {
        echo '<tr><td>Catalyseur (Volatilite)</td><td style="color:red">+' . round($catalystDecayIncrease * 100) . '% de disparition</td></tr>';
    }

    echo '</tbody></table></div>';

    // Per-class half-lives
    $hasAnyMolecule = false;
    for ($i = 1; $i <= MAX_MOLECULE_CLASSES; $i++) {
        if ($molecules[$i] && $molecules[$i]['formule'] !== 'Vide') {
            $hasAnyMolecule = true;
            break;
        }
    }

    if ($hasAnyMolecule) {
        echo '<br/>';
        echo '<div class="data-table"><table>';
        echo '<thead><tr><th>Classe</th><th>Formule</th><th>Demi-vie</th><th>Isotope</th></tr></thead>';
        echo '<tbody>';

        global $ISOTOPES;
        for ($i = 1; $i <= MAX_MOLECULE_CLASSES; $i++) {
            if ($molecules[$i] && $molecules[$i]['formule'] !== 'Vide') {
                $halfLife = demiVie($login, $i);
                $halfLifeDisplay = ($halfLife >= PHP_INT_MAX) ? 'Infinie' : affichageTemps($halfLife);
                $isotope = (int)($molecules[$i]['isotope'] ?? 0);
                $isotopeName = $ISOTOPES[$isotope]['name'] ?? 'Normal';

                echo '<tr>';
                echo '<td>Classe ' . $i . '</td>';
                echo '<td>' . couleurFormule($molecules[$i]['formule']) . '</td>';
                echo '<td>' . htmlspecialchars($halfLifeDisplay, ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . htmlspecialchars($isotopeName, ENT_QUOTES, 'UTF-8') . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table></div>';
    }

    finContent();
finCarte();

// =============================================================================
// SECTION I: Recherche Alliance
// =============================================================================
if ($allianceId > 0 && $allianceData) {
    debutCarte("Recherche Alliance (" . htmlspecialchars($allianceName, ENT_QUOTES, 'UTF-8') . ")");
        debutContent();

        echo '<div class="data-table"><table>';
        echo '<thead><tr><th>Technologie</th><th>Niveau</th><th>Bonus actuel</th></tr></thead>';
        echo '<tbody>';

        // Duplicateur
        echo '<tr><td>Duplicateur</td><td>' . $duplicateurLevel . '</td><td style="color:green">+' . round(bonusDuplicateur($duplicateurLevel) * 100) . '% de production</td></tr>';

        // Other techs
        foreach ($ALLIANCE_RESEARCH as $techName => $tech) {
            $techLevel = (int)($allianceData[$techName] ?? 0);
            $techBonus = $techLevel * $tech['effect_per_level'];
            $techBonusPct = round($techBonus * 100, 1);

            echo '<tr>';
            echo '<td>' . htmlspecialchars($tech['name'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . $techLevel . '</td>';
            if ($techLevel > 0) {
                echo '<td style="color:green">+' . $techBonusPct . '% — ' . htmlspecialchars($tech['desc'], ENT_QUOTES, 'UTF-8') . '</td>';
            } else {
                echo '<td style="color:#999">Aucun</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        finContent();
    finCarte();
}

// =============================================================================
// SECTION J: Medailles
// =============================================================================
debutCarte("Medailles");
    debutContent();

    $medalCategories = [
        ['name' => 'Terreur', 'stat' => 'nbattaques', 'paliers' => $paliersTerreur, 'unit' => 'attaques', 'bonus_desc' => 'reduction du cout d\'attaque'],
        ['name' => 'Attaquant', 'stat' => 'pointsAttaque', 'paliers' => $paliersAttaque, 'unit' => 'pts attaque', 'bonus_desc' => 'attaque supplementaire'],
        ['name' => 'Defenseur', 'stat' => 'pointsDefense', 'paliers' => $paliersDefense, 'unit' => 'pts defense', 'bonus_desc' => 'defense supplementaire'],
        ['name' => 'Pilleur', 'stat' => 'ressourcesPillees', 'paliers' => $paliersPillage, 'unit' => 'ressources', 'bonus_desc' => 'pillage supplementaire'],
        ['name' => 'Energivore', 'stat' => 'energieDepensee', 'paliers' => $paliersEnergievore, 'unit' => 'energie', 'bonus_desc' => 'production d\'energie'],
        ['name' => 'Pertes', 'stat' => 'moleculesPerdues', 'paliers' => $paliersPertes, 'unit' => 'molecules', 'bonus_desc' => 'stabilisation des molecules'],
    ];

    echo '<div class="data-table"><table>';
    echo '<thead><tr><th>Medaille</th><th>Palier</th><th>Progression</th><th>Bonus</th></tr></thead>';
    echo '<tbody>';

    foreach ($medalCategories as $cat) {
        $currentValue = floor($autre[$cat['stat']]);
        $tier = getMedalTier($currentValue, $cat['paliers']);
        $bonus = $tier >= 0 ? $bonusMedailles[$tier] : 0;
        $tierName = $tier >= 0 ? $paliersMedailles[$tier] : '—';

        // Find next threshold
        $nextThreshold = '—';
        $nextTierName = '';
        if ($tier < count($cat['paliers']) - 1) {
            $nextIdx = $tier + 1;
            $nextThreshold = fmtNum($cat['paliers'][$nextIdx]);
            $nextTierName = ' (' . $paliersMedailles[$nextIdx] . ')';
        }

        echo '<tr>';
        echo '<td>' . htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($tierName, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . fmtNum($currentValue) . ' / ' . $nextThreshold . htmlspecialchars($nextTierName, ENT_QUOTES, 'UTF-8') . '</td>';
        if ($bonus > 0) {
            echo '<td style="color:green">+' . $bonus . '% ' . htmlspecialchars($cat['bonus_desc'], ENT_QUOTES, 'UTF-8') . '</td>';
        } else {
            echo '<td style="color:#999">Aucun</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table></div>';

    finContent();
finCarte();

// =============================================================================
// SECTION K: Prestige
// =============================================================================
debutCarte("Prestige");
    debutContent();

    $prestigeData = getPrestige($login);
    $totalPP = (int)$prestigeData['total_pp'];
    $currentUnlocks = array_filter(explode(',', $prestigeData['unlocks']));

    echo '<p style="font-size:18px;font-weight:bold;text-align:center;color:#FFD700;">' . fmtNum($totalPP) . ' PP disponibles</p>';

    echo '<div class="data-table"><table>';
    echo '<thead><tr><th>Amelioration</th><th>Cout</th><th>Effet</th><th>Statut</th></tr></thead>';
    echo '<tbody>';

    foreach ($PRESTIGE_UNLOCKS as $key => $unlock) {
        $owned = in_array($key, $currentUnlocks);
        echo '<tr>';
        echo '<td>' . htmlspecialchars($unlock['name'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string)$unlock['cost'], ENT_QUOTES, 'UTF-8') . ' PP</td>'; // L-001
        echo '<td>' . htmlspecialchars($unlock['desc'], ENT_QUOTES, 'UTF-8') . '</td>';
        if ($owned) {
            echo '<td style="color:green;font-weight:bold">Debloque</td>';
        } else {
            echo '<td style="color:#999">Verrouille</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table></div>';

    // Active prestige bonuses summary
    $activeBonuses = [];
    if (hasPrestigeUnlock($login, 'experimente')) {
        $activeBonuses[] = '+5% production de ressources';
    }
    if (hasPrestigeUnlock($login, 'maitre_chimiste')) {
        $activeBonuses[] = '+5% stats de combat';
    }
    if (hasPrestigeUnlock($login, 'veteran')) {
        $activeBonuses[] = '+1 jour de protection debutant';
    }
    if (hasPrestigeUnlock($login, 'debutant_rapide')) {
        $activeBonuses[] = 'Generateur commence au niveau 2';
    }
    if (hasPrestigeUnlock($login, 'legende')) {
        $activeBonuses[] = 'Badge Legende';
    }

    if (!empty($activeBonuses)) {
        echo '<p style="margin-top:8px;"><strong>Bonus actifs :</strong> ' . implode(' | ', array_map(function($b) { return htmlspecialchars($b, ENT_QUOTES, 'UTF-8'); }, $activeBonuses)) . '</p>';
    }

    finContent();
finCarte();

// =============================================================================
// SECTION L: Catalyseur de la Semaine
// =============================================================================
debutCarte("Catalyseur de la Semaine");
    debutContent();

    $activeCatalyst = getActiveCatalyst();

    echo '<div style="text-align:center;padding:8px;">';
    echo '<p style="font-size:18px;font-weight:bold;">' . htmlspecialchars($activeCatalyst['name'], ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p>' . htmlspecialchars($activeCatalyst['desc'], ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</div>';

    echo '<div class="data-table"><table>';
    echo '<thead><tr><th>Effet</th><th>Valeur</th></tr></thead>';
    echo '<tbody>';

    foreach ($activeCatalyst['effects'] as $effectName => $effectValue) {
        $effectLabels = [
            'attack_bonus' => 'Bonus d\'attaque',
            'formation_speed' => 'Vitesse de formation',
            'market_convergence' => 'Convergence du marche',
            'duplicateur_discount' => 'Reduction cout Duplicateur',
            'construction_speed' => 'Vitesse de construction',
            'decay_increase' => 'Augmentation de la disparition',
            'pillage_bonus' => 'Bonus de pillage',
        ];
        $label = $effectLabels[$effectName] ?? $effectName;
        $color = ($effectName === 'decay_increase') ? 'red' : 'green';
        $sign = ($effectName === 'decay_increase') ? '+' : '+';

        echo '<tr><td>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td><td style="color:' . $color . '">' . $sign . round($effectValue * 100) . '%</td></tr>';
    }

    echo '</tbody></table></div>';

    // Next rotation
    $nextMonday = strtotime('next monday');
    $timeUntilRotation = $nextMonday - time();
    if ($timeUntilRotation > 0) {
        echo '<p style="margin-top:8px;font-size:13px;color:#666;text-align:center;">Prochain catalyseur dans ' . htmlspecialchars(affichageTemps($timeUntilRotation), ENT_QUOTES, 'UTF-8') . '</p>';
    }

    finContent();
finCarte();

// =============================================================================
// SECTION M: Condenseur (Atom Effectiveness)
// =============================================================================
debutCarte("Niveaux du Condenseur");
    debutContent();

    $condenseurLevel = (int)$constructions['condenseur'];

    // M-016: use $SPECIALIZATIONS config instead of hardcoded condenseur modifier values
    $specCondMod = 0;
    if ($specResearch === 1) {
        $specCondMod = $SPECIALIZATIONS['research']['options'][1]['modifiers']['condenseur_points']; // +2 pts/level (Theorique)
    } elseif ($specResearch === 2) {
        $specCondMod = $SPECIALIZATIONS['research']['options'][2]['modifiers']['condenseur_points']; // -1 pt/level (Applique)
    }

    echo '<p>Condenseur niveau ' . $condenseurLevel . ' — Multiplicateur global : x' . round(modCond($condenseurLevel), 2) . '</p>';

    echo '<div class="data-table"><table>';
    echo '<thead><tr><th>Atome</th><th>Niveau condenseur</th><th>Effet</th></tr></thead>';
    echo '<tbody>';

    $condenseurEffects = [
        'Carbone' => 'Defense',
        'Azote' => 'Temps de formation',
        'Hydrogene' => 'Degats batiments',
        'Oxygene' => 'Attaque',
        'Chlore' => 'Vitesse',
        'Soufre' => 'Pillage',
        'Brome' => 'Points de vie',
        'Iode' => 'Production d\'energie',
    ];

    foreach ($nomsRes as $num => $ressource) {
        $nivCond = (int)$niveauxCondenseur[$num];
        $mult = modCond($nivCond);

        echo '<tr>';
        echo '<td style="color:' . htmlspecialchars($couleurs[$num], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($nomsDisplay[$num], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . $nivCond . '</td>';
        echo '<td>x' . round($mult, 2) . ' ' . htmlspecialchars($condenseurEffects[$nomsDisplay[$num]] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';

    if ($specCondMod != 0) {
        $condLabel = $specCondMod > 0 ? '+' . $specCondMod . ' pts/niv (Theorique)' : $specCondMod . ' pt/niv (Applique)';
        echo '<p style="margin-top:8px;font-size:13px;color:#666;">Specialisation Recherche : ' . htmlspecialchars($condLabel, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    finContent();
finCarte();

// =============================================================================
// SECTION N: Specialisations
// =============================================================================
debutCarte("Specialisations");
    debutContent();

    echo '<div class="data-table"><table>';
    echo '<thead><tr><th>Type</th><th>Deblocage</th><th>Choix</th><th>Effets</th></tr></thead>';
    echo '<tbody>';

    foreach ($SPECIALIZATIONS as $specType => $spec) {
        $col = $spec['column'];
        $currentChoice = (int)($constructions[$col] ?? 0);
        $unlockBuilding = $spec['unlock_building'];
        $unlockLevel = $spec['unlock_level'];
        $currentBuildingLevel = (int)($constructions[$unlockBuilding] ?? 0);
        $unlocked = $currentBuildingLevel >= $unlockLevel;

        echo '<tr>';
        echo '<td>' . ucfirst(htmlspecialchars($specType, ENT_QUOTES, 'UTF-8')) . '</td>';
        echo '<td>' . htmlspecialchars(ucfirst($unlockBuilding), ENT_QUOTES, 'UTF-8') . ' niv. ' . $unlockLevel;
        if (!$unlocked) {
            echo ' <span style="color:red">(niv. ' . $currentBuildingLevel . ')</span>';
        } else {
            echo ' <span style="color:green">(OK)</span>';
        }
        echo '</td>';

        if ($currentChoice > 0 && isset($spec['options'][$currentChoice])) {
            $opt = $spec['options'][$currentChoice];
            echo '<td style="font-weight:bold">' . htmlspecialchars($opt['name'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars($opt['desc'], ENT_QUOTES, 'UTF-8') . '</td>';
        } elseif ($unlocked) {
            echo '<td colspan="2">';
            foreach ($spec['options'] as $optId => $opt) {
                echo '<form action="bilan.php" method="post" style="display:inline-block;margin:2px 4px;">'
                    . csrfField()
                    . '<input type="hidden" name="spec_type" value="' . htmlspecialchars($specType, ENT_QUOTES, 'UTF-8') . '"/>'
                    . '<input type="hidden" name="spec_choice" value="' . $optId . '"/>'
                    . '<button type="submit" name="choose_specialization" value="1" class="button button-fill" '
                    . 'data-confirm="' . htmlspecialchars('Choisir ' . $opt['name'] . ' ? Ce choix est irréversible !', ENT_QUOTES, 'UTF-8') . '">'
                    . htmlspecialchars($opt['name'], ENT_QUOTES, 'UTF-8')
                    . '</button>'
                    . '<br/><small>' . htmlspecialchars($opt['desc'], ENT_QUOTES, 'UTF-8') . '</small>'
                    . '</form>';
            }
            echo '</td>';
        } else {
            echo '<td style="color:#999">Verrouillée</td>';
            echo '<td>—</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table></div>';

    finContent();
finCarte();

// =============================================================================
// SECTION O: Composés Actifs (Laboratoire)
// =============================================================================
require_once('includes/compounds.php');
$activeCompounds = getActiveCompounds($base, $login);
if (!empty($activeCompounds)) {
    global $COMPOUNDS;
    debutCarte("Composés Actifs");
        debutContent();

        echo '<div class="data-table"><table>';
        echo '<thead><tr><th>Composé</th><th>Effet</th><th>Expire dans</th></tr></thead>';
        echo '<tbody>';

        foreach ($activeCompounds as $comp) {
            $key = $comp['compound_key'];
            if (!isset($COMPOUNDS[$key])) continue;
            $def = $COMPOUNDS[$key];
            $remaining = max(0, $comp['expires_at'] - time());

            echo '<tr>';
            echo '<td><strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</strong> — ' . htmlspecialchars($def['name'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td style="color:green">' . htmlspecialchars($def['description'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars(affichageTemps($remaining), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        finContent();
    finCarte();
}

echo cspScriptTag();
?>
document.addEventListener('click', function(e) {
    var el = e.target.closest('[data-confirm]');
    if (el && !confirm(el.dataset.confirm)) e.preventDefault();
});
</script>
<?php
include("includes/copyright.php");
