<?php
include("redirectionmotdepasse.php");
require_once(__DIR__ . '/../includes/csrf.php');
// LOW-016: CSP header for admin page.
require_once(__DIR__ . '/../includes/csp.php');
$nonce = cspNonce();
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';");
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<title>The Very Little War - Rédiger une news</title>
<meta http-equiv="content-type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1">
<style type="text/css">
h3, form
{
text-align:center;
}
</style>
</head>
<body>
<h3><a href="listenews.php">Retour à la liste des news</a></h3>
<?php
include("../includes/connexion.php");
if (isset($_GET['modifier_news'])) // Si on demande de modifier une news.
{
    $modifierNewsId = (int)$_GET['modifier_news'];
    // On récupère les informations de la news correspondante.
    $donnees = dbFetchOne($base, 'SELECT * FROM news WHERE id = ?', 'i', $modifierNewsId);
    if ($donnees === null) {
        header('Location: listenews.php');
        exit();
    }

    // On place le titre et le contenu dans des variables simples.
    $titre = $donnees['titre'];
    $contenu = $donnees['contenu'];
    $id_news = $donnees['id']; // Cette variable va servir pour se souvenir que c'est une modification.
}
else // C'est qu'on rédige une nouvelle news.
{
    // Les variables $titre et $contenu sont vides, puisque c'est une nouvelle news.
    $titre = '';
    $contenu = '';
    $id_news = 0; // La variable vaut 0, donc on se souviendra que ce n'est pas une modification.
}
?>
<form action="listenews.php" method="post">
<?php echo csrfField(); ?>
<p>Titre : <input type="text" size="30" name="titre" value="<?php echo htmlspecialchars($titre, ENT_QUOTES, 'UTF-8'); ?>" />
</p>
<p>
Contenu :<br />
<textarea name="contenu" cols="50" rows="10">
<?php echo htmlspecialchars($contenu, ENT_QUOTES, 'UTF-8'); ?>
</textarea><br />
<input type="hidden" name="id_news" value="<?php echo (int)$id_news; ?>" />
<input type="submit" value="Envoyer" />
</p>
</form>
</body>
</html>
