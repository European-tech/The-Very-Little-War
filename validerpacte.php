<?php
include("includes/basicprivatephp.php");

if(isset($_POST['idDeclaration'])) {
	csrfCheck();
	$donnees = dbFetchOne($base, 'SELECT count(*) AS existe FROM declarations WHERE id=? AND valide=0', 'i', $_POST['idDeclaration']);

	if($donnees['existe'] == 1) {
		// Verify the current player is authorized (must be chef of target alliance)
		$declaration = dbFetchOne($base, 'SELECT alliance2 FROM declarations WHERE id=? AND valide=0', 'i', $_POST['idDeclaration']);
		if ($declaration) {
			$targetAlliance = dbFetchOne($base, 'SELECT chef FROM alliances WHERE id=?', 'i', $declaration['alliance2']);
			if (!$targetAlliance || $targetAlliance['chef'] !== $_SESSION['login']) {
				header('Location: rapports.php');
				exit();
			}
		}

		if(isset($_POST['accepter'])) {
			dbExecute($base, 'UPDATE declarations SET valide=1 WHERE id=?', 'i', $_POST['idDeclaration']);
		}
		else {
			dbExecute($base, 'DELETE FROM declarations WHERE id=?', 'i', $_POST['idDeclaration']);
		}
	}
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include("includes/meta.php"); ?>
<title>The Very Little War - Valider</title>
<link rel="stylesheet" type="text/css" href="style.css" >
</head>
<body>
<script LANGUAGE="JavaScript">
window.location= "rapports.php";
</script>
</body>
</html>