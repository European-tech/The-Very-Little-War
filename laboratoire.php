<?php
include("includes/basicprivatephp.php");
include("includes/redirectionVacance.php");
require_once("includes/compounds.php");

// Handle synthesis
if (isset($_POST['synthesize'])) {
    csrfCheck();
    // P9-MED-013: Rate-limit synthesis to prevent abuse (5 per minute per player)
    require_once('includes/rate_limiter.php');
    if (!rateLimitCheck($_SESSION['login'], 'lab_synthesis', 5, 60)) {
        $erreur = "Trop de synthèses. Attendez une minute.";
    } else {
        $compoundKey = trim($_POST['compound_key'] ?? '');
        $result = synthesizeCompound($base, $_SESSION['login'], $compoundKey);
        if ($result === true) {
            $information = "Composé synthétisé avec succès !";
        } else {
            $erreur = $result;
        }
    }
}

// Handle activation
if (isset($_POST['activate'])) {
    csrfCheck();
    $compoundId = intval($_POST['compound_id'] ?? 0);
    // LAB11-001: Validate compound_id > 0 before passing to activateCompound()
    if ($compoundId <= 0) {
        $erreur = "Composé invalide.";
    } else {
        $result = activateCompound($base, $_SESSION['login'], $compoundId);
        if ($result === true) {
            $information = "Composé activé !";
        } else {
            $erreur = $result;
        }
    }
}

// GC runs ~5% of requests — expired compounds are filtered by expiry timestamp at read time
if (mt_rand(1, 1000) <= (int)(COMPOUND_GC_PROBABILITY * 1000)) {
    cleanupExpiredCompounds($base);
}

include("includes/layout.php");

global $COMPOUNDS, $nomsRes, $nomsAccents, $couleurs;
$ressources = dbFetchOne($base, 'SELECT * FROM ressources WHERE login = ?', 's', $_SESSION['login']);
$stored = getStoredCompounds($base, $_SESSION['login']);
$active = getActiveCompounds($base, $_SESSION['login']);
$storedCount = count($stored);

// =============================================================================
// Active Compounds
// =============================================================================
if (!empty($active)) {
    debutCarte("Composés Actifs");
    debutContent();

    echo '<div class="data-table"><table>';
    echo '<thead><tr><th>Composé</th><th>Effet</th><th>Expire dans</th></tr></thead>';
    echo '<tbody>';

    foreach ($active as $comp) {
        $key = $comp['compound_key'];
        if (!isset($COMPOUNDS[$key])) continue;
        $def = $COMPOUNDS[$key];
        $remaining = max(0, $comp['expires_at'] - time());

        echo '<tr>';
        echo '<td><strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</strong> — ' . htmlspecialchars($def['name'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td style="color:green">' . htmlspecialchars($def['description'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . affichageTemps($remaining) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    finContent();
    finCarte();
}

// =============================================================================
// Stored Compounds
// =============================================================================
if (!empty($stored)) {
    debutCarte("Stock (" . $storedCount . "/" . COMPOUND_MAX_STORED . ")");
    debutContent();

    echo '<div class="data-table"><table>';
    echo '<thead><tr><th>Composé</th><th>Effet</th><th>Action</th></tr></thead>';
    echo '<tbody>';

    foreach ($stored as $comp) {
        $key = $comp['compound_key'];
        if (!isset($COMPOUNDS[$key])) continue;
        $def = $COMPOUNDS[$key];

        echo '<tr>';
        echo '<td><strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</strong> — ' . htmlspecialchars($def['name'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($def['description'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>';
        echo '<form method="post" action="laboratoire.php" style="display:inline">';
        echo csrfField();
        echo '<input type="hidden" name="compound_id" value="' . (int)$comp['id'] . '"/>';
        echo '<button type="submit" name="activate" class="button button-fill color-green" style="padding:4px 12px;font-size:12px;">Activer</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    finContent();
    finCarte();
}

// =============================================================================
// Synthesis Lab
// =============================================================================
debutCarte("Laboratoire de Synthèse");
debutContent();

if ($storedCount >= COMPOUND_MAX_STORED) {
    echo '<p style="color:orange;font-weight:bold;">Stock plein ! Activez un composé avant d\'en synthétiser de nouveaux.</p>';
}

echo '<div class="data-table"><table>';
echo '<thead><tr><th>Composé</th><th>Recette</th><th>Effet</th><th>Durée</th><th>Action</th></tr></thead>';
echo '<tbody>';

foreach ($COMPOUNDS as $key => $compound) {
    $canAfford = true;
    $recipeParts = [];

    foreach ($compound['recipe'] as $resource => $qty) {
        $needed = $qty * COMPOUND_ATOM_MULTIPLIER;
        $available = floor($ressources[$resource] ?? 0);
        $color = ($available >= $needed) ? 'green' : 'red';
        if ($available < $needed) $canAfford = false;

        // Find resource display name
        $resIdx = array_search($resource, $nomsRes);
        $displayName = ($resIdx !== false) ? ucfirst($nomsAccents[$resIdx]) : ucfirst($resource);
        $resColor = ($resIdx !== false) ? $couleurs[$resIdx] : '#333';

        $recipeParts[] = '<span style="color:' . $resColor . '">' . $needed . ' ' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . '</span> <span style="color:' . $color . ';font-size:11px;">(' . chiffrePetit($available) . ')</span>';
    }

    echo '<tr>';
    echo '<td><strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</strong><br/><span style="font-size:11px;color:#666;">' . htmlspecialchars($compound['name'], ENT_QUOTES, 'UTF-8') . '</span></td>';
    echo '<td>' . implode('<br/>', $recipeParts) . '</td>';
    echo '<td style="color:green">' . htmlspecialchars($compound['description'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . affichageTemps($compound['duration']) . '</td>';
    echo '<td>';

    if ($canAfford && $storedCount < COMPOUND_MAX_STORED) {
        echo '<form method="post" action="laboratoire.php" style="display:inline">';
        echo csrfField();
        echo '<input type="hidden" name="compound_key" value="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '"/>';
        echo '<button type="submit" name="synthesize" class="button button-fill color-blue" style="padding:4px 12px;font-size:12px;">Synthétiser</button>';
        echo '</form>';
    } elseif ($storedCount >= COMPOUND_MAX_STORED) {
        echo '<span style="color:orange;font-size:11px;">Stock plein</span>';
    } else {
        echo '<span style="color:red;font-size:11px;">Ressources insuffisantes</span>';
    }

    echo '</td>';
    echo '</tr>';
}

echo '</tbody></table></div>';

echo '<p style="margin-top:8px;font-size:13px;color:#666;">';
echo 'Chaque unité de recette coûte ' . COMPOUND_ATOM_MULTIPLIER . ' atomes. ';
echo 'Maximum ' . COMPOUND_MAX_STORED . ' composés en stock. ';
echo 'Un seul composé par type d\'effet peut être actif à la fois.';
echo '</p>';

finContent();
finCarte();

include("includes/copyright.php");
?>
