<?php
// Ajout de Yojim
	// On vérifie si le joueur connecté est en vacances
	$joueurEnVac = dbFetchOne($base, 'SELECT vacance FROM membre WHERE login=?', 's', $_SESSION['login']);
	if ($joueurEnVac['vacance']) { ?>
		<script nonce="<?php echo htmlspecialchars(cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
		window.location = "vacance.php"
		</script>
		<?php
	}
?>