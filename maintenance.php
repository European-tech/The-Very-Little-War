<?php include("includes/connexion.php");
include("includes/fonctions.php");

include("includes/tout.php");
debutCarte("Maintenance");
$retour = mysqli_query($base, 'SELECT * FROM news ORDER BY id DESC LIMIT 0, 1');
$donnees = mysqli_fetch_array($retour);
$contenu = nl2br(stripslashes($donnees['contenu']));
echo important($donnees['titre'] . '<em> le ' . date('d/m/Y Ã H\hi', $donnees['timestamp']) . '</em>');
echo ' 
<p>
<br/>
' . $contenu . '
</p>
</div>
';

finCarte();
include("includes/copyright.php");
