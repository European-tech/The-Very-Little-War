<?php
// Ajout de Yojim
	// On vérifie si le joueur connecté est en vacances
	$joueurEnVac = dbFetchOne($base, 'SELECT vacance FROM membre WHERE login=?', 's', $_SESSION['login']);
	if ($joueurEnVac['vacance']) { ?>
		<script type="text/javascript">
		window.location = "vacance.php"
		</script>
		<?php
	}
?>