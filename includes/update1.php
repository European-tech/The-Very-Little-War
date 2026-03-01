<?php
//////////////////////////////////////////////////////////// Gestion des ressources
$adversaire = dbFetchOne($base, 'SELECT tempsPrecedent FROM autre WHERE login=?', 's', $_POST['joueurAEspionner1']);
$nbsecondesAdverse = time() - $adversaire['tempsPrecedent'];// On calcule la différence de secondes
$depotAdverse = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=?', 's', $_POST['joueurAEspionner1']);

dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=?', 'is', time(), $_POST['joueurAEspionner1']);
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////ENERGIE

$donnees = dbFetchOne($base, 'SELECT energie, revenuenergie FROM ressources WHERE login=?', 's', $_POST['joueurAEspionner1']);

$energie = $donnees['energie'] + round($donnees['revenuenergie']*$nbsecondesAdverse/3600);// On calcule l'energie que l'on doit avoir
if($energie>=(4*pow(4, $depotAdverse['depot']+2)))
{
$energie= (4*pow(4, $depotAdverse['depot']+2)); // on limite l'energie pouvant être reçu (depots de ressources)
}
dbExecute($base, 'UPDATE ressources SET energie=? WHERE login=?', 'ds', $energie, $_POST['joueurAEspionner1']);

//////////////////////////////////////////////////////////////////////////////////////////////////////////////////RESSOURCES

foreach($nomsRes as $num => $ressource) {
	$donnees = dbFetchOne($base, "SELECT $ressource, revenu$ressource FROM ressources WHERE login=?", 's', $_POST['joueurAEspionner1']);

	$$ressource = $donnees[$ressource] + round($donnees['revenu'.$ressource]*$nbsecondesAdverse/3600);
	if($$ressource>=(4*pow(4, $depotAdverse['depot']+2)))
	{
	$$ressource = (4*pow(4, $depotAdverse['depot']+2));
	}
	dbExecute($base, "UPDATE ressources SET $ressource=? WHERE login=?", 'ds', $$ressource, $_POST['joueurAEspionner1']);
}

///////////////////////////////////////////////////////////////////////////////////////////////////////////////Gestion des molécules disparaissant

$exResult = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=? AND nombre > 0', 's', $_POST['joueurAEspionner1']);

while($molecules = mysqli_fetch_array($exResult)) {
	$nbAtomes = 0;
	foreach($nomsRes as $num => $ressource) {
		$nbAtomes = $nbAtomes+$molecules[$ressource];
	}
	$nbheures = round($nbsecondesAdverse/3600);
	$moleculesAEnlever = 0;
	$moleculesRestantes = $molecules['nombre'];
	while($nbheures > 0) {
		$moleculesAEnlever = ($nbAtomes / 1000) * $moleculesRestantes;
		$moleculesRestantes = $moleculesRestantes - $moleculesAEnlever;
		$nbheures = $nbheures - 1;
	}

	dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', $moleculesRestantes, $molecules['id']);
}

?>

