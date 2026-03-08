<?php
include("includes/basicprivatephp.php");

if(isset($_POST['idDeclaration'])) {
	csrfCheck();

	withTransaction($base, function() use ($base) {
		// Atomic: lock row + verify authorization in one query
		$declaration = dbFetchOne($base, 'SELECT d.id, d.alliance2 FROM declarations d WHERE d.id=? AND d.valide=0 FOR UPDATE', 'i', $_POST['idDeclaration']);
		if (!$declaration) return;

		$targetAlliance = dbFetchOne($base, 'SELECT chef FROM alliances WHERE id=? FOR UPDATE', 'i', $declaration['alliance2']);
		if (!$targetAlliance) return;

		$isChef = ($targetAlliance['chef'] === $_SESSION['login']);

		// PASS1-MEDIUM-015: Also allow grade-holding officers with the pact permission bit
		// Grade string format: "inviter.guerre.pacte.bannir.description" (index 2 = pacte)
		// ALLY-001: Use FOR UPDATE on grades row to prevent TOCTOU race where grade is revoked mid-request
		$hasPactPerm = false;
		if (!$isChef) {
			$gradeRow = dbFetchOne($base, 'SELECT grade FROM grades WHERE login=? AND idalliance=? FOR UPDATE', 'si', $_SESSION['login'], $declaration['alliance2']);
			if ($gradeRow && !empty($gradeRow['grade'])) {
				$bits = explode('.', $gradeRow['grade']);
				$hasPactPerm = (isset($bits[2]) && $bits[2] === '1');
			}
		}

		if (!$isChef && !$hasPactPerm) {
			return; // Not authorized
		}

		if(isset($_POST['accepter'])) {
			dbExecute($base, 'UPDATE declarations SET valide=1 WHERE id=?', 'i', $_POST['idDeclaration']);
		} else {
			dbExecute($base, 'DELETE FROM declarations WHERE id=?', 'i', $_POST['idDeclaration']);
		}
	});
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
<script nonce="<?php echo htmlspecialchars(cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
window.location= "rapports.php";
</script>
</body>
</html>