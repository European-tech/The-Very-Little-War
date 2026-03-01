<?php 
include("includes/basicprivatephp.php");

if(isset($_POST['valider'])) {
	csrfCheck();
	$niveaututo = dbFetchOne($base, 'SELECT niveaututo FROM autre WHERE login=?', 's', $_SESSION['login']);
	if($niveaututo['niveaututo'] <= 8) {
		$tuto = dbFetchOne($base, 'SELECT * FROM tutoriel WHERE niveau=?', 'i', $niveaututo['niveaututo']);
		$valide = "pasok";
		if($niveaututo['niveaututo'] == 1) {
			$donnees = dbFetchOne($base, 'SELECT count(*) AS nb_classes FROM molecules WHERE proprietaire=? AND formule != ?', 'ss', $_SESSION['login'], 'Vide ');
			if($donnees['nb_classes'] > 0) {
				$valide = "ok";
			}
		}
		elseif($niveaututo['niveaututo'] == 2) {
			$donnees = dbFetchOne($base, 'SELECT count(*) AS nb_classes FROM molecules WHERE proprietaire=? AND nombre != 0', 's', $_SESSION['login']);
			if($donnees['nb_classes'] > 0) {
				$valide = "ok";
			}
		}
		elseif($niveaututo['niveaututo'] == 3) {
			$chaine = "";
			foreach($nomsRes as $num => $ressource) {
				$plus = "";
				if($num < $nbRes) { $plus = ","; }
					$chaine = $chaine.'generateur'.$ressource.''.$plus;
			}
			// $chaine columns come from server-side $nomsRes whitelist
			$donnees = dbFetchOne($base, 'SELECT generateurenergie, '.$chaine.' FROM constructions WHERE login=?', 's', $_SESSION['login']);
			
			$bool = 1;
			foreach($nomsRes as $num => $ressource) {
				if($donnees['generateur'.$ressource] <= 1) { $bool = 0; }
			}
			if($donnees['generateurenergie'] > 1 AND $bool == 1) {
				$valide = "ok";
			}
		}
		elseif($niveaututo['niveaututo'] == 4) {
			$donnees = dbFetchOne($base, 'SELECT muraille FROM constructions WHERE login=?', 's', $_SESSION['login']);
			if($donnees['muraille'] > 0) {
				$valide = "ok";
			}
		}
		elseif($niveaututo['niveaututo'] == 5) {
			$donnees = dbFetchOne($base, 'SELECT description FROM autre WHERE login=?', 's', $_SESSION['login']);
			if($donnees['description'] != "") {
				$valide = "ok";
			}
		}
		elseif($niveaututo['niveaututo'] == 6) {
			$donnees = dbFetchOne($base, 'SELECT nbattaques FROM autre WHERE login=?', 's', $_SESSION['login']);
			if($donnees['nbattaques'] > 0) {
				$valide = "ok";
			}
		}
		elseif($niveaututo['niveaututo'] == 7) {
			$donnees = dbFetchOne($base, 'SELECT terrain FROM ressources WHERE login=?', 's', $_SESSION['login']);
			if($donnees['terrain'] > 100) {
				$valide = "ok";
			}
			
		}
		elseif($niveaututo['niveaututo'] == 8) {
			$donnees = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
			if($donnees['idalliance'] != 0) {
				$valide = "ok";
			}
		}
		
		if($valide == "ok") {
			$ressources1 = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $_SESSION['login']);
			// Build SET clause with server-side $nomsRes column names
			$chaine = "";
			foreach($nomsRes as $num => $ressource) {
				$plus = "";
				if($num < $nbRes) { $plus = ","; }
					$chaine = $chaine.''.$ressource.'='.intval($ressources1[$ressource] + $tuto['bonus'.$ressource]).''.$plus;
			}
			$newEnergie = intval($ressources1['energie'] + $tuto['bonusenergie']);
			// Column names from $nomsRes whitelist, values are server-computed integers
			$stmt = mysqli_prepare($base, 'UPDATE ressources SET energie=?,'.$chaine.' WHERE login=?');
			mysqli_stmt_bind_param($stmt, 'is', $newEnergie, $_SESSION['login']);
			mysqli_stmt_execute($stmt);
			mysqli_stmt_close($stmt);

			$newNiveau = intval($niveaututo['niveaututo'] + 1);
			dbExecute($base, 'UPDATE autre SET niveaututo=? WHERE login=?', 'is', $newNiveau, $_SESSION['login']);
			?>
			<script LANGUAGE="JavaScript">
			window.location= "constructions.php";
			</script>
			<?php
			
		}
		else {
			$erreur = "Toutes les conditons ne sont pas remplies.";
		}
	}
	else {
		$erreur = "T'as cru que t'allais re-avoir des ressources ?.";
	}
}


include("includes/tout.php");

$niveaututo = dbFetchOne($base, 'SELECT niveaututo FROM autre WHERE login=?', 's', $_SESSION['login']);
if(!(isset($_GET['tuto']))){
if($niveaututo['niveaututo'] > 8) {

}
else {
	/*?>
	<div class="panel panel-default margin-10 pattern-bg">
	<div class="panel-heading">
	<h2>Mission n°<?php echo $niveaututo['niveaututo']; ?></h2></div>
	<div class="panel-body">
	<p>
	<?php 
	$sql1 = 'SELECT * FROM tutoriel WHERE niveau=\''.$niveaututo['niveaututo'].'\'';
	$ex1 = mysqli_query($base,$sql1) or die ('Erreur SQL !<br />'.$sql1.'<br />'.mysql_error());
	$tutoriel = mysqli_fetch_array($ex1);

	echo $tutoriel['description'];
	?>
	<br/>
	<table class="table table-bordered">
	<tr>
	<th style="width: 50px"><img src="images/cadeau.png" alt="cadeau" title="Bonus"/></th>
	<th style="width: 100px"><?php echo $tutoriel['bonusenergie']; ?> <img src="images/energie.png" alt="energie" title="Energie"/></th>
	<?php foreach($nomsRes as $num => $ressource) {
		echo'<th style="width: 100px">'.$tutoriel['bonus'.$ressource].' <img src="images/'.$ressource.'.png" alt="'.$ressource.'" title="'.$ressource.'" /></th>';
	}
	?>
	<th style="width: 100px"><form method="post" action="tutoriel.php"><input value="Valider" src="images/yes.png" alt="yes" type="image" name="valider"/></form></th>
	</tr>
	</table><br/>
	</p>
	</div>
	</div>
	
	<?php */
} 

debutCarte("Comprendre le jeu");
    ?>
        <?php echo important('Accueil'); ?>
        La page sur laquelle vous êtes tombés en arrivant sur le site. Se trouvent dessus les news et une bréve présentation du jeu.<br/>
        <br/>
        <?php echo important('S\'instruire'); ?>
        Sur cette page se trouvent des cours concernant les atomes , si vous êtes curieux de savoir comment la mécanique des atomes marche vraiment.<br/>
        <br/>
        <?php echo important('Règles'); ?>
        Vous trouverez sur cette page les réglementations concernant TVLW et la charte de condidentialité.<br/>
        <br/>
        <?php echo important('Armée'); ?>
        C'est ici que vous pouvez créer vos molécules (pour se battre) il y a différents critères :<br/>
        par exemple l'hydrogène (blanc) est la capacité à détruire les bâtiments ennemis ou plus précisément à leur infliger des dégâts qui finiront par les détruire.<br/>
        Le Carbone (noir) est la capacité de défense<br/>
        L'oxygène (rouge) est la capacité d'attaque etc...<br/>
        C'est à vous de créer vos molécules, on appelle cela une classe. Vous pouvez construire au maximum 4 classes, chacune d'entre elle nécessite un coût de plus en plus important d'énergie. A vous de trouver la bonne combinaison de molécules et votre style de molécule que ce soit en défense ou en attaque!<br/>
        <br/>
        <?php echo important('Constructions'); ?>
        Comme son nom l'indique, c'est ici que l'on construit nos bâtiments. Ceux-ci permettent différentes améliorations, on peut augmenter la production de ressources (énergie & atomes), augmenter la capacité d'entrepôt (nombre de ressources maximale de stockage) et d'autres bâtiments avec des fonction différentes (ex : augmenter le pourcentage de défense ou d'attaque!)<br/>
        Chaque bâtiment a un coût plus ou moins important en énergie (naturellement, plus le niveau du bâtiment est élevé, plus cela coûte cher à l'augmenter!)<br/>
        <br/>
        <?php echo important('Marché'); ?>
        Ici vous pourrez envoyer des atomes et de l'energie à vos alliés. Vous pourrez aussi échanger une quantité d'un type de ressource contre une certaine quantité d'une autre.<br/>
        <br/>
        <?php echo important('Carte'); ?>
        A partir de cette carte, vous pourrez trouver les joueurs adverses : cliquez sur un joueur sur la carte pour tomber sur son profil et pouvoir l'attaquer<br/>
        <br/>
        <?php echo important('Equipe'); ?>
        Dans « Equipe », eh bien c'est ici que vous pouvez faire partie d'une alliance en recevant une invitation de la part du chef de l'alliance dont vous voulez entrer (ce dernier peut apporté des conditions!). Une fois entré dans une alliance, vous pourrez voir les informations sur votre alliance, la description de votre alliance et les membres de votre alliance. Et puis si elle ne vous plait pas, vous pourrez en sortir bien sûr!<br/>
        <br/>
        <?php echo important('Classement'); ?>
        C'est le Classement général où vous pouvez voir ou vous vous situez, votre nombre de points (il se calcule en fonction du niveau des bâtiments et attaques reçues et effectuées), et votre nombre de molécules. Vous pouvez aussi voir toutes ces informations pour les autres joueurs et les alliances du jeu. Le pseudo et le nom de l'alliance y est aussi affiché.<br/>
        <br/>
        <?php echo important('Messages'); ?>
        C'est ici que vous recevrez vos messages et que vous en enverrez.<br/>
        <br/>
        <?php echo important('Rapports'); ?>
        Vous aurez ici vos rapports de combats, et vos rapports de pertes (en fonction du nombre d'heure passées sans s'être connecté).<br/>
        <br/>
        <?php echo important('Mon compte'); ?>
        Vous pouvez ici apporter des nouvelles modifications à votre compte, avec le changement de mot de passe et modifier votre description (les autres joueurs pourront la voir évidemment!), c'est ici aussi que vous pouvez suprimer votre compte.<br/>
        <br/>
        <?php echo important('Forum'); ?>
        Ici vous parlez de tout et de rien avec les autres joueurs de ce jeu, les suggestions et les améliorations possibles sont le bienvenue!<br/>
        <br/>
        <?php echo important('Aide'); ?>
        Eh bien vous y êtes!! C'est ici que vous allez (et normalement c'est fait ^^) apprendre à jouer!<br/>
        <br/>
        <?php echo important('Médailles'); ?>
        Vous trouverez ici des médailles de bronze, argent, or et platine en fonction du nombre d'attaques lançées, du nombre de messages postés etc... Cela vous donne droit à des bonus de plus en plus importants.<br/>
        <br/>
        </p>

<?php 
    finCarte();
}
include("includes/copyright.php"); ?>
