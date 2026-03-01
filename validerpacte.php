<?php
include("includes/basicprivatephp.php");

if(isset($_POST['idDeclaration'])) {
	csrfCheck();
	$donnees = dbFetchOne($base, 'SELECT count(*) AS existe FROM declarations WHERE id=? AND valide=0', 'i', $_POST['idDeclaration']);

	if($donnees['existe'] == 1) {
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
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="fr" >
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