<?php
/**
 * DB Helpers Module
 * Legacy database helper wrappers and lookup functions.
 */

function query($truc)
{
    global $base;
    $ex = mysqli_query($base, $truc);
    if (!$ex) {
        error_log('SQL Error: ' . mysqli_error($base) . ' | Query: ' . $truc);
        return false;
    }
    return $ex;
}

function ajouter($champ, $bdd, $nombre, $joueur)
{
    global $base;
    $d = dbFetchOne($base, "SELECT $champ FROM $bdd WHERE login=?", 's', $joueur);

    dbExecute($base, "UPDATE $bdd SET $champ=? WHERE login=?", 'ds', ($d[$champ] + $nombre), $joueur);
}

function alliance($alliance)
{
    return '<a href="alliance.php?id=' . $alliance . '" class="lienVisible">' . $alliance . '</a>';
}

/**
 * Get the level of an alliance research tech for a given player.
 * Returns 0 if the player has no alliance or the tech doesn't exist.
 */
function allianceResearchLevel($joueur, $techName) {
    global $base;
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
