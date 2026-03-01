<?php
include("includes/basicprivatephp.php");

if(isset($_POST['energieEnvoyee'])) {
	csrfCheck();
	if(empty($_POST['energieEnvoyee'])) {
		$_POST['energieEnvoyee'] = 0;
	}
	$_POST['energieEnvoyee'] = transformInt($_POST['energieEnvoyee']);
	if(preg_match("#^[0-9]*$#", $_POST['energieEnvoyee'])) {
		$idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);

		if($idalliance['idalliance'] > 0) {
			$verification = dbFetchOne($base, 'SELECT count(*) AS verificationAlliance FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);

			if($verification['verificationAlliance'] > 0) {
					$ressources = dbFetchOne($base, 'SELECT energie FROM ressources WHERE login=?', 's', $_SESSION['login']);

					if($ressources['energie'] >= $_POST['energieEnvoyee']) {

					$energieDonnee = dbFetchOne($base, 'SELECT energieDonnee FROM autre WHERE login=?', 's', $_SESSION['login']);

					$ressourcesAlliance = dbFetchOne($base, 'SELECT energieAlliance, energieTotaleRecue FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);

					$newEnergie = $ressources['energie'] - $_POST['energieEnvoyee'];
					dbExecute($base, 'UPDATE ressources SET energie=? WHERE login=?', 'ds', $newEnergie, $_SESSION['login']);
					$newEnergieDonnee = $energieDonnee['energieDonnee'] + $_POST['energieEnvoyee'];
					dbExecute($base, 'UPDATE autre SET energieDonnee=? WHERE login=?', 'ds', $newEnergieDonnee, $_SESSION['login']);
					$newEnergieAlliance = $ressourcesAlliance['energieAlliance'] + $_POST['energieEnvoyee'];
					$newEnergieTotale = $ressourcesAlliance['energieTotaleRecue'] + $_POST['energieEnvoyee'];
					dbExecute($base, 'UPDATE alliances SET energieAlliance=?, energieTotaleRecue=? WHERE id=?', 'ddi', $newEnergieAlliance, $newEnergieTotale, $idalliance['idalliance']);
					$information = "Le don a bien été reçu !";
                    echo '<script>document.location.href=\'alliance.php?information='.$information.'\';</script>';
				}
				else {
					$erreur = "Vous n'avez pas assez d'energie.";
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

include("includes/tout.php");

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