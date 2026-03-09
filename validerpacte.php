<?php
include("includes/basicprivatephp.php");
// FLOW-P30-C001: Vacation players must not mutate alliance state (pact approval)
include("includes/redirectionVacance.php");

if(isset($_POST['idDeclaration'])) {
	csrfCheck();

	$warConflictErreur = null;
	try {
	withTransaction($base, function() use ($base) {
		// Atomic: lock row + verify authorization in one query
		// Include alliance1 here so we never need a second unlocked fetch below
		$declaration = dbFetchOne($base, 'SELECT d.id, d.alliance1, d.alliance2 FROM declarations d WHERE d.id=? AND d.valide=0 FOR UPDATE', 'i', $_POST['idDeclaration']);
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
			// Check no active war between these alliances (FLOW-ALL-P10-001)
			// Reuse the already-locked $declaration row — no second fetch needed
			$warCheck = dbFetchOne($base,
				'SELECT COUNT(*) as cnt FROM declarations
				 WHERE ((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?))
				 AND type=0 AND fin=0',
				'iiii', $declaration['alliance1'], $declaration['alliance2'],
				         $declaration['alliance1'], $declaration['alliance2']);
			if ($warCheck && (int)$warCheck['cnt'] > 0) {
				// ALLIANCE_MGMT MEDIUM FIX: throw instead of header()+exit() inside closure
				// to ensure proper transaction rollback before redirecting.
				throw new \RuntimeException('WAR_CONFLICT');
			}
			dbExecute($base, 'UPDATE declarations SET valide=1 WHERE id=?', 'i', $_POST['idDeclaration']);
		} else {
			dbExecute($base, 'DELETE FROM declarations WHERE id=?', 'i', $_POST['idDeclaration']);
		}
	});
	} catch (\RuntimeException $e) {
		if ($e->getMessage() === 'WAR_CONFLICT') {
			header('Location: rapports.php?erreur=' . urlencode('Impossible d\'accepter ce pacte : vos alliances sont en guerre.'));
			exit();
		}
		throw $e;
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
<script nonce="<?php echo htmlspecialchars(cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
window.location= "rapports.php";
</script>
</body>
</html>