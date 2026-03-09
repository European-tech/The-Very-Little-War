<?php
include("includes/basicprivatephp.php");
include("includes/redirectionVacance.php");

// MEDIUM-035: Load multiaccount module so areFlaggedAccounts() is available
require_once __DIR__ . '/includes/multiaccount.php';

if(isset($_POST['energieEnvoyee'])) {
	csrfCheck();
	// MED-073: Rate limit donations to prevent spam/abuse (10 per hour per player)
	if (!rateLimitCheck($_SESSION['login'], 'donation', 10, SECONDS_PER_HOUR)) {
		$erreur = "Trop de dons effectués. Veuillez attendre avant de réessayer.";
	} else {
	if(empty($_POST['energieEnvoyee'])) {
		$_POST['energieEnvoyee'] = 0;
	}
	// HIGH-004: Cast to int after transformInt to prevent integer overflow via string injection
	$_POST['energieEnvoyee'] = (int) transformInt($_POST['energieEnvoyee']);
	// HIGH-004 / MED-058: Enforce a per-donation cap. ALLIANCE_DONATION_MAX (10 000) is the
	// per-transaction ceiling; MAX_DONATION is a legacy absolute ceiling kept as belt-and-suspenders.
	if ($_POST['energieEnvoyee'] > ALLIANCE_DONATION_MAX) {
		$erreur = "Montant invalide (maximum " . number_format(ALLIANCE_DONATION_MAX, 0, ' ', ' ') . " énergie par don).";
	} elseif($_POST['energieEnvoyee'] > 0) { // value already cast to int; preg_match on int is dead code
		$idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);

		if($idalliance['idalliance'] > 0) {
			$verification = dbFetchOne($base, 'SELECT count(*) AS verificationAlliance, chef FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);

			if($verification['verificationAlliance'] > 0) {
				// ECONOMY-MEDIUM-002: Block donations when sender and alliance chef share the same IP
				// or have an active high/critical multi-account flag — prevents alt-funneling.
				$allianceChef = $verification['chef'] ?? '';
				$senderIp = dbFetchOne($base, 'SELECT ip FROM membre WHERE login=?', 's', $_SESSION['login']);
				if ($allianceChef !== '' && $allianceChef !== $_SESSION['login']) {
					// Check shared IP between sender and chef in membre table
					$chefIp   = dbFetchOne($base, 'SELECT ip FROM membre WHERE login=?', 's', $allianceChef);
					// FIX-CRITICAL: Use hash_equals() for IP comparison to prevent timing-based inference.
				$sharedIp = ($senderIp && $chefIp
						&& !empty($senderIp['ip']) && !empty($chefIp['ip'])
						&& hash_equals((string)$senderIp['ip'], (string)$chefIp['ip']));
					// Also check account_flags for an active high/critical flag between the pair
					$flagged = areFlaggedAccounts($base, $_SESSION['login'], $allianceChef);
					if ($sharedIp || $flagged) {
						$erreur = "Don refusé : votre compte et le chef de l'alliance partagent une connexion suspecte.";
						logWarn('DON', 'Donation blocked: possible alt-account funneling', [
							'sender' => $_SESSION['login'],
							'chef'   => $allianceChef,
							'shared_ip' => $sharedIp,
							'flagged'   => $flagged,
						]);
					}
				}
				// ECONOMY-MEDIUM-002 (officers): Also check all officers — not just the chef.
				if (empty($erreur)) {
					$officers = dbFetchAll($base,
						'SELECT a.login FROM autre a INNER JOIN grades g ON g.login=a.login AND g.idalliance=a.idalliance WHERE a.idalliance=? AND a.login != ?',
						'is', $idalliance['idalliance'], $allianceChef
					);
					foreach ($officers as $officer) {
						if ($officer['login'] === $_SESSION['login']) continue; // skip self
						$officerIp = dbFetchOne($base, 'SELECT ip FROM membre WHERE login=?', 's', $officer['login']);
						// FIX-CRITICAL: Use hash_equals() for IP comparison (officer check).
						$sharedIpOfficer = ($senderIp && $officerIp
							&& !empty($senderIp['ip']) && !empty($officerIp['ip'])
							&& hash_equals((string)$senderIp['ip'], (string)$officerIp['ip']));
						$flaggedOfficer = areFlaggedAccounts($base, $_SESSION['login'], $officer['login']);
						if ($sharedIpOfficer || $flaggedOfficer) {
							$erreur = "Don refusé : votre compte et un officier de l'alliance partagent une connexion suspecte.";
							logWarn('DON', 'Donation blocked: possible alt-account funneling via officer', [
								'sender'   => $_SESSION['login'],
								'officer'  => $officer['login'],
								'shared_ip' => $sharedIpOfficer,
								'flagged'   => $flaggedOfficer,
							]);
							break;
						}
					}
				}
				if (empty($erreur)) {
				try {
					withTransaction($base, function() use ($base, $idalliance) {
						// Lock rows to prevent TOCTOU race condition
						$ressources = dbFetchOne($base, 'SELECT energie FROM ressources WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
						$energieDonnee = dbFetchOne($base, 'SELECT energieDonnee FROM autre WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
						$ressourcesAlliance = dbFetchOne($base, 'SELECT energieAlliance, energieTotaleRecue FROM alliances WHERE id=? FOR UPDATE', 'i', $idalliance['idalliance']);

						if($ressources['energie'] - $_POST['energieEnvoyee'] < DONATION_MIN_ENERGY_RESERVE) {
							throw new \RuntimeException("NOT_ENOUGH_ENERGY");
						}

						// Cap donation so vault does not exceed MAX_ALLIANCE_ENERGY
						$maxVault = defined('MAX_ALLIANCE_ENERGY') ? MAX_ALLIANCE_ENERGY : 1000000;
						$montant = $_POST['energieEnvoyee'];
						if ($ressourcesAlliance['energieAlliance'] + $montant > $maxVault) {
							$montant = $maxVault - $ressourcesAlliance['energieAlliance'];
							if ($montant <= 0) {
								throw new \RuntimeException('VAULT_FULL');
							}
						}

						dbExecute($base, 'UPDATE ressources SET energie = energie - ? WHERE login=?', 'ds', $montant, $_SESSION['login']);
						dbExecute($base, 'UPDATE autre SET energieDonnee = energieDonnee + ?, nbDons = nbDons + 1 WHERE login=?', 'ds', $montant, $_SESSION['login']); // ECO16-001: increment nbDons for medal tracking
						dbExecute($base, 'UPDATE alliances SET energieAlliance = energieAlliance + ?, energieTotaleRecue = energieTotaleRecue + ? WHERE id=?', 'ddi', $montant, $montant, $idalliance['idalliance']);
					});
					$information = "Le don a bien été reçu !";
					header('Location: alliance.php?information=' . urlencode($information));
					exit();
				} catch (\RuntimeException $e) {
					if ($e->getMessage() === "NOT_ENOUGH_ENERGY") {
						$erreur = "Vous n'avez pas assez d'énergie (réserve minimale de " . DONATION_MIN_ENERGY_RESERVE . " requise).";
					} elseif ($e->getMessage() === 'VAULT_FULL') {
						$erreur = "La caisse de l'alliance est pleine (maximum " . number_format(MAX_ALLIANCE_ENERGY, 0, ',', ' ') . " énergie).";
					} else {
						throw $e;
					}
				}
				} // end if (empty($erreur)) — multi-account guard
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
	} // end rate limit check
}

include("includes/layout.php");

// PASS4-LOW-007: Guard — player must belong to an alliance to donate
$donAlliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
if (empty($donAlliance['idalliance']) || (int)$donAlliance['idalliance'] === 0) {
    debutCarte("Faire un don");
        debutListe();
            item(['floating' => false, 'titre' => 'Vous n\'appartenez pas à une alliance.', 'texte' => 'Rejoignez ou créez une alliance pour pouvoir effectuer des dons.']);
            item(['input' => '<a href="alliance_discovery.php" class="button button-fill color-blue">Rejoindre une alliance</a>']);
        finListe();
    finCarte();
    include("includes/copyright.php");
    exit;
}

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