<?php
require_once("includes/session_init.php");
$_SESSION['start'] = "start";
if (isset($_SESSION['login']))
{
	include("includes/basicprivatephp.php");
}
else
{
	include("includes/basicpublicphp.php");
}

include("includes/tout.php");

if(isset($_GET['id'])) {
	$_GET['id'] = (int)$_GET['id'];
	$ex = dbQuery($base, 'SELECT * FROM declarations WHERE id=? AND type=0', 'i', $_GET['id']);
	$guerre = mysqli_fetch_array($ex);
	$nbGuerres = mysqli_num_rows($ex);

	if($nbGuerres > 0 && $guerre) {
	$alliance1 = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', 'i', $guerre['alliance1']);
	$alliance2 = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', 'i', $guerre['alliance2']);

	if (!$alliance1 || !$alliance2) {
		echo "<p>Données d'alliance introuvables pour cette guerre.</p>";
		include("includes/copyright.php");
		exit();
	}

	$tag1 = htmlspecialchars($alliance1['tag'], ENT_QUOTES, 'UTF-8');
	$tag2 = htmlspecialchars($alliance2['tag'], ENT_QUOTES, 'UTF-8');
	$utag1 = urlencode($alliance1['tag']);
	$utag2 = urlencode($alliance2['tag']);
        debutCarte('<a href="alliance.php?id='.$utag1.'" style="color:white"><span class="lienTitre">'.$tag1.'</span></a> VS <a href="alliance.php?id='.$utag2.'"><span class="lienTitre" style="color:white">'.$tag2.'</span></a>');
		echo '
		<p>
		<span class="subimportant">Nombre de pertes totales : </span>'.number_format(($guerre['pertes1'] + $guerre['pertes2']), 0 , ' ', ' ').' molécules dont<br/>';
		if($guerre['pertes1'] + $guerre['pertes2'] > 0) {
			echo '
			'.number_format($guerre['pertes1'], 0 , ' ', ' ').' molécules pour <a href="alliance.php?id='.$utag1.'">'.$tag1.'</a> ('.round($guerre['pertes1']/($guerre['pertes1'] + $guerre['pertes2'])*100).'%)<br/>
			'.number_format($guerre['pertes2'], 0 , ' ', ' ').' molécules pour <a href="alliance.php?id='.$utag2.'">'.$tag2.'</a> ('.round($guerre['pertes2']/($guerre['pertes1'] + $guerre['pertes2'])*100).'%)<br/>
			';
		}
		else {
			echo '
			0 molécules pour <a href="alliance.php?id='.$utag1.'">'.$tag1.'</a> (0%)<br/>
			0 molécules pour <a href="alliance.php?id='.$utag2.'">'.$tag2.'</a> (0%)<br/>
			';
		}
		echo '<br/><span class="subimportant">Date de début de la guerre : </span>'.date('d/m/Y à H\hi', $guerre['timestamp']).'<br/>';

		if($guerre['fin'] > $guerre['timestamp']) {
			echo '<span class="subimportant">Date de fin de la guerre : </span>'.date('d/m/Y à H\hi', $guerre['fin']).'<br/>
			Cette guerre a donc duré '.round(($guerre['fin'] - $guerre['timestamp'])/86400).' jours.';
		}
		else {
			echo '<span class="subimportant">Date de fin de la guerre : </span>Non finie<br/>';
		}
		echo '</p>';
        finCarte();
	}
	else {
		echo "<p>Cette guerre n'a jamais existé.</p>";
	}
}
else {
	echo "<p>Stop ça petit troll !</p>";
}

include("includes/copyright.php");