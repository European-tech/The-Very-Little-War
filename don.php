<?php
include("includes/basicprivatephp.php");

if(isset($_POST['energieEnvoyee'])) {
	csrfCheck();
	if(empty($_POST['energieEnvoyee'])) {
		$_POST['energieEnvoyee'] = 0;
	}
	$_POST['energieEnvoyee'] = transformInt($_POST['energieEnvoyee']);
	if(preg_match("#^[0-9]+$#", $_POST['energieEnvoyee']) && $_POST['energieEnvoyee'] > 0) {
		$idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);

		if($idalliance['idalliance'] > 0) {
			$verification = dbFetchOne($base, 'SELECT count(*) AS verificationAlliance FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);

			if($verification['verificationAlliance'] > 0) {
				try {
					withTransaction($base, function() use ($base, $idalliance) {
						// Lock rows to prevent TOCTOU race condition
						$ressources = dbFetchOne($base, 'SELECT energie FROM ressources WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
						$energieDonnee = dbFetchOne($base, 'SELECT energieDonnee FROM autre WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
						$ressourcesAlliance = dbFetchOne($base, 'SELECT energieAlliance, energieTotaleRecue FROM alliances WHERE id=? FOR UPDATE', 'i', $idalliance['idalliance']);

						if($ressources['energie'] < $_POST['energieEnvoyee']) {
							throw new \RuntimeException("NOT_ENOUGH_ENERGY");
						}

						dbExecute($base, 'UPDATE ressources SET energie = energie - ? WHERE login=?', 'ds', $_POST['energieEnvoyee'], $_SESSION['login']);
						dbExecute($base, 'UPDATE autre SET energieDonnee = energieDonnee + ? WHERE login=?', 'ds', $_POST['energieEnvoyee'], $_SESSION['login']);
						dbExecute($base, 'UPDATE alliances SET energieAlliance = energieAlliance + ?, energieTotaleRecue = energieTotaleRecue + ? WHERE id=?', 'ddi', $_POST['energieEnvoyee'], $_POST['energieEnvoyee'], $idalliance['idalliance']);
					});
					$information = "Le don a bien été reçu !";
					header('Location: alliance.php?information=' . urlencode($information));
					exit();
				} catch (\RuntimeException $e) {
					if ($e->getMessage() === "NOT_ENOUGH_ENERGY") {
						$erreur = "Vous n'avez pas assez d'energie.";
					} else {
						throw $e;
					}
				}
			}
			else {
				$erreur = "Cette alliance n'existe pas.";
			}
		}
		else {
			$erreur = "Vous n'avez pas d'alliance.";
		}
	}
	else {
		$erreur = "Seul des nombres entiers et positifs doivent être entrés.";
	}
}

include("includes/layout.php");

debutCarte("Faire un don");
    debutListe();
        echo '<form name="faireUnDon" method="post" action="don.php" />';
        echo csrfField();
        item(['floating' => false, 'titre' => nombreEnergie('Energie'), 'input' => '<input type="text" name="energieEnvoyee" id="energieEnvoyee" class="form-control" placeholder="Energie à donner"/>' ]);
        item(['input' => submit(['form' => 'faireUnDon', 'titre' => 'Donner', 'image' => 'images/boutons/cadeau.png'])]);
        echo '</form>';
    finListe();
finCarte();
include("includes/copyright.php"); ?>