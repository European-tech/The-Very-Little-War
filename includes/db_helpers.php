<?php
/**
 * DB Helpers Module
 * Legacy database helper wrappers and lookup functions.
 */

function ajouter($champ, $bdd, $nombre, $joueur)
{
    global $base;
    // Whitelist allowed column/table names to prevent SQL injection via interpolation.
    // Callers pass column/table names directly into SQL, so we must validate them.
    // Allowed columns: 'autre' table stats + 'ressources' table columns (resource names + energie).
    static $allowedColumns = null;
    static $allowedTables = ['autre', 'ressources'];
    if ($allowedColumns === null) {
        global $nomsRes;
        $allowedColumns = array_merge(
            // 'autre' table stat columns
            ['victoires', 'energieDonnee', 'energieDepensee', 'neutrinos', 'moleculesPerdues',
             'totalPoints', 'pointsAttaque', 'pointsDefense', 'pointsPillage', 'pointsEspionnage',
             'pointsMolecule', 'pointsBatiment', 'pointsNbMolecule', 'tradeVolume',
             'nbattaques', 'nbdefenses', 'nbespionnages'],
            // 'ressources' table columns: 8 resource names + energie
            ['energie'],
            is_array($nomsRes) ? $nomsRes : []
        );
    }
    if (!in_array($champ, $allowedColumns, true) || !in_array($bdd, $allowedTables, true)) {
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

// Hard-coded whitelist of alliance research column names (must match alliances table schema).
// This provides defense-in-depth: even if $ALLIANCE_RESEARCH config were tampered with,
// only these column names can ever be interpolated into SQL.
define('ALLIANCE_RESEARCH_COLUMNS', ['catalyseur', 'fortification', 'reseau', 'radar', 'bouclier']);

/**
 * Get the level of an alliance research tech for a given player.
 * Returns 0 if the player has no alliance or the tech doesn't exist.
 */
function allianceResearchLevel($joueur, $techName) {
    global $base, $ALLIANCE_RESEARCH;
    // Whitelist tech names against both config and hard-coded column list
    if (!isset($ALLIANCE_RESEARCH[$techName])) return 0;
    if (!in_array($techName, ALLIANCE_RESEARCH_COLUMNS, true)) {
        error_log("allianceResearchLevel() blocked: invalid tech name '$techName'");
        return 0;
    }
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
            // Validate column name against hard-coded whitelist before interpolating into SQL
            if (!in_array($techName, ALLIANCE_RESEARCH_COLUMNS, true)) {
                error_log("allianceResearchBonus() blocked: invalid tech name '$techName'");
                return 0;
            }
            $alliance = dbFetchOne($base, 'SELECT ' . $techName . ' FROM alliances WHERE id=?', 'i', $autre['idalliance']);
            $level = $alliance ? intval($alliance[$techName]) : 0;
            return $level * $tech['effect_per_level'];
        }
    }
    return 0;
}
