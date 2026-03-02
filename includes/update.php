<?php
/**
 * Update target player's resources and molecule decay.
 *
 * This function brings a player's resources and molecules up to date
 * based on elapsed time since their last update. It is used before
 * resolving attacks or espionage so that resource values are current.
 *
 * @param string $targetPlayer  The login of the player to update.
 */
function updateTargetResources($targetPlayer)
{
    global $base;
    global $nomsRes;

    //////////////////////////////////////////////////////////// Gestion des ressources
    $adversaire = dbFetchOne($base, 'SELECT tempsPrecedent FROM autre WHERE login=?', 's', $targetPlayer);
    $nbsecondesAdverse = time() - $adversaire['tempsPrecedent']; // On calcule la différence de secondes
    $depotAdverse = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=?', 's', $targetPlayer);

    dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=?', 'is', time(), $targetPlayer);

    //////////////////////////////////////////////////////////////////////////////////////////////////////////////////ENERGIE

    $donnees = dbFetchOne($base, 'SELECT energie, revenuenergie FROM ressources WHERE login=?', 's', $targetPlayer);

    $energie = $donnees['energie'] + round($donnees['revenuenergie'] * $nbsecondesAdverse / 3600); // On calcule l'energie que l'on doit avoir
    if ($energie >= placeDepot($depotAdverse['depot'])) {
        $energie = placeDepot($depotAdverse['depot']); // on limite l'energie pouvant être reçu (depots de ressources)
    }
    dbExecute($base, 'UPDATE ressources SET energie=? WHERE login=?', 'ds', $energie, $targetPlayer);

    //////////////////////////////////////////////////////////////////////////////////////////////////////////////////RESSOURCES

    foreach ($nomsRes as $num => $ressource) {
        $donnees = dbFetchOne($base, "SELECT $ressource, revenu$ressource FROM ressources WHERE login=?", 's', $targetPlayer);

        $$ressource = $donnees[$ressource] + round($donnees['revenu' . $ressource] * $nbsecondesAdverse / 3600);
        if ($$ressource >= placeDepot($depotAdverse['depot'])) {
            $$ressource = placeDepot($depotAdverse['depot']);
        }
        dbExecute($base, "UPDATE ressources SET $ressource=? WHERE login=?", 'ds', $$ressource, $targetPlayer);
    }

    ///////////////////////////////////////////////////////////////////////////////////////////////////////////////Gestion des molécules disparaissant

    $exResult = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=? AND nombre > 0', 's', $targetPlayer);

    $compteurClasse = 0;
    while ($molecules = mysqli_fetch_array($exResult)) {
        $compteurClasse++;
        $moleculesRestantes = pow(coefDisparition($targetPlayer, $compteurClasse), $nbsecondesAdverse) * $molecules['nombre'];

        dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', $moleculesRestantes, $molecules['id']);
    }
}
