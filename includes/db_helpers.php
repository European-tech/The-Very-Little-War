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
