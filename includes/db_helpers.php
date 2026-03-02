<?php
/**
 * DB Helpers Module
 * Legacy database helper wrappers and lookup functions.
 */

function ajouter($champ, $bdd, $nombre, $joueur)
{
    global $base;
    // Whitelist allowed column/table names to prevent SQL injection
    static $allowedColumns = null;
    static $allowedTables = ['autre', 'ressources'];
    if ($allowedColumns === null) {
        global $nomsRes;
        $allowedColumns = array_merge(
            ['victoires', 'energieDonnee', 'neutrinos', 'moleculesPerdues', 'totalPoints',
             'pointsAttaque', 'pointsDefense', 'pointsPillage', 'pointsEspionnage',
             'pointsMolecule', 'pointsBatiment', 'pointsNbMolecule', 'tradeVolume',
             'nbattaques', 'nbdefenses', 'nbespionnages'],
            is_array($nomsRes) ? $nomsRes : []
        );
    }
    if (!in_array($champ, $allowedColumns) || !in_array($bdd, $allowedTables)) {
        error_log("ajouter() blocked: invalid column '$champ' or table '$bdd'");
        return;
    }

    // Use atomic increment instead of read-then-write
    dbExecute($base, "UPDATE $bdd SET $champ = $champ + ? WHERE login=?", 'ds', $nombre, $joueur);
}

function alliance($alliance)
{
    $safe = htmlspecialchars($alliance, ENT_QUOTES, 'UTF-8');
    return '<a href="alliance.php?id=' . $safe . '" class="lienVisible">' . $safe . '</a>';
}

/**
 * Get the level of an alliance research tech for a given player.
 * Returns 0 if the player has no alliance or the tech doesn't exist.
 */
function allianceResearchLevel($joueur, $techName) {
    global $base, $ALLIANCE_RESEARCH;
    // Whitelist tech names against config
    if (!isset($ALLIANCE_RESEARCH[$techName])) return 0;
    $autre = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $joueur);
    if (!$autre || $autre['idalliance'] <= 0) return 0;
    $alliance = dbFetchOne($base, 'SELECT ' . $techName . ' FROM alliances WHERE id=?', 'i', $autre['idalliance']);
    return $alliance ? intval($alliance[$techName]) : 0;
}

/**
 * Get the alliance research bonus multiplier for a given player and effect type.
 * Returns the bonus as a fraction (e.g., 0.06 for 6%).
 */
function allianceResearchBonus($joueur, $effectType) {
    global $base, $ALLIANCE_RESEARCH;
    $autre = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $joueur);
    if (!$autre || $autre['idalliance'] <= 0) return 0;

    foreach ($ALLIANCE_RESEARCH as $techName => $tech) {
        if ($tech['effect_type'] === $effectType) {
            $alliance = dbFetchOne($base, 'SELECT ' . $techName . ' FROM alliances WHERE id=?', 'i', $autre['idalliance']);
            $level = $alliance ? intval($alliance[$techName]) : 0;
            return $level * $tech['effect_per_level'];
        }
    }
    return 0;
}
