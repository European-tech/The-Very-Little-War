<?php
require_once("includes/session_init.php");
if (isset($_SESSION['login'])) {
    include("includes/basicprivatephp.php");
} else {
    include("includes/basicpublicphp.php");
}


if (isset($_GET['inscrit'])) {
    $_GET['inscrit'] = intval($_GET['inscrit']);
    if ($_GET['inscrit'] == 1) {
        $information = "Vous avez bien été inscrit";
    } else {
        $erreur = "Ca t'amuse de changer la barre URL ?";
    }
}

if (isset($_GET['att'])) {
    $_GET['att'] = intval($_GET['att']);
    if ($_GET['att'] == 1) {
        $erreur = "Un visiteur s'est inscrit il y a moins d'une minute, veuillez attendre s'il vous plait puis réessayez (anti-bot)";
    } else {
        $erreur = "Ca t'amuse de changer la barre URL ?";
    }
}

include("includes/layout.php");

if (!isset($_SESSION['login'])) {
    // --- Hero tagline card ---
    debutCarte();
    debutContent(); ?>
    <div style="text-align:center; padding:10px 0;">
        <img src="images/icone.png" alt="atome" style="width:64px; height:64px;" /><br/>
        <span class="magma" style="font-size:22px; color:#8A0000;">The Very Little War</span><br/>
        <span style="font-size:15px; color:#555; font-style:italic;">Jeu de strat&eacute;gie chimique multijoueur gratuit</span>
    </div>
    <div style="text-align:center; padding:5px 10px;">
        Construisez votre base, composez des mol&eacute;cules, forgez des alliances et dominez le classement dans ce jeu de strat&eacute;gie unique au th&egrave;me chimique.
    </div>
    <div style="text-align:center; padding:10px 0;">
        <?php echo submit(['link' => 'inscription.php', 'titre' => 'S\'inscrire', 'style' => 'font-size:16px;']); ?>
    </div>
    <?php
    finContent();
    finCarte();

    // --- Login card (returning players) ---
    debutCarte("Se connecter", "background-color:#8A0000");
    debutListe();
    echo '<form action="index.php?noAutoConnexion=1" method="post" name="connexion">';
    echo csrfField();
    item(['floating' => true, 'titre' => 'Login', 'media' => '<img src="images/accueil/player.png" alt="user" class="w32"/>', 'input' => '<input type="text" name="loginConnexion" id="loginConnexion">']);
    item(['floating' => true, 'titre' => 'Mot de passe', 'media' => '<img src="images/accueil/door-key.png" alt="lock" class="w32"/>', 'input' => '<input type="password" name="passConnexion" id="passConnexion">']);
    finListe();
    echo '<br/><p class="buttons-row">' . submit(['form' => 'connexion', 'titre' => 'Connexion', 'id' => 'boutonConnexion']) . submit(['link' => 'comptetest.php?inscription=1', 'titre' => 'Tester']);
    echo '</p>';
    echo '</form>';
    finCarte();

    // --- Key features overview card ---
    debutCarte("Pourquoi jouer ?", "background-color:#333");
    debutContent(); ?>
    <div style="padding:5px 0;">
        <div style="margin-bottom:12px;">
            <img src="images/accueil/molecules.png" alt="molecules" class="w32" style="vertical-align:middle; margin-right:8px;" />
            <strong>8 types d'atomes</strong><br/>
            <span style="color:#555; font-size:13px; margin-left:40px; display:inline-block;">Carbone, oxyg&egrave;ne, azote, hydrog&egrave;ne, soufre, chlore, brome, iode : chacun avec un r&ocirc;le unique.</span>
        </div>
        <hr/>
        <div style="margin-bottom:12px;">
            <img src="images/accueil/cellule.png" alt="combat" class="w32" style="vertical-align:middle; margin-right:8px;" />
            <strong>Mol&eacute;cules et combat</strong><br/>
            <span style="color:#555; font-size:13px; margin-left:40px; display:inline-block;">Composez des mol&eacute;cules sur mesure et envoyez-les attaquer vos rivaux ou d&eacute;fendre votre territoire.</span>
        </div>
        <hr/>
        <div style="margin-bottom:12px;">
            <img src="images/accueil/deal.png" alt="alliances" class="w32" style="vertical-align:middle; margin-right:8px;" />
            <strong>Alliances</strong><br/>
            <span style="color:#555; font-size:13px; margin-left:40px; display:inline-block;">Rejoignez ou fondez une &eacute;quipe, d&eacute;clarez des guerres et partagez des bonus avec vos alli&eacute;s.</span>
        </div>
        <hr/>
        <div style="margin-bottom:12px;">
            <img src="images/accueil/cubes.png" alt="marche" class="w32" style="vertical-align:middle; margin-right:8px;" />
            <strong>March&eacute; des ressources</strong><br/>
            <span style="color:#555; font-size:13px; margin-left:40px; display:inline-block;">Achetez, vendez et &eacute;changez des atomes sur un march&eacute; dynamique avec des cours fluctuants.</span>
        </div>
        <hr/>
        <div style="margin-bottom:12px;">
            <img src="images/accueil/crown.png" alt="prestige" class="w32" style="vertical-align:middle; margin-right:8px;" />
            <strong>Syst&egrave;me de prestige</strong><br/>
            <span style="color:#555; font-size:13px; margin-left:40px; display:inline-block;">Gagnez des points de prestige, d&eacute;bloquez des bonus permanents et grimpez dans les classements.</span>
        </div>
        <hr/>
        <div style="margin-bottom:12px;">
            <img src="images/accueil/dimension.png" alt="formations" class="w32" style="vertical-align:middle; margin-right:8px;" />
            <strong>Formations d&eacute;fensives</strong><br/>
            <span style="color:#555; font-size:13px; margin-left:40px; display:inline-block;">Placez vos mol&eacute;cules en formation pour maximiser vos chances de survie lors des attaques ennemies.</span>
        </div>
    </div>
    <div style="text-align:center; padding:8px 0;">
        <span style="font-size:13px; color:#888;">Parties mensuelles &mdash; tout le monde repart &agrave; z&eacute;ro le 1er du mois !</span>
    </div>
    <?php
    finContent();
    finCarte();
}

$donnees = dbFetchOne($base, 'SELECT * FROM news ORDER BY id DESC LIMIT 0, 1');
if (!$donnees) {
    $donnees = ['titre' => 'Aucune news', 'contenu' => ''];
    $contenuNews = 'Aucune news pour l\'instant.';
} else {
    // News entries created by admins only (admin/redigernews.php behind redirectionmotdepasse.php).
    // Use a conservative tag allowlist. Strip javascript: and data: URIs from href attributes
    // to prevent XSS via anchor tags that survive strip_tags().
    $contenuNews = strip_tags($donnees['contenu'], '<b><i><u><br><p><a><strong><em>');
    $contenuNews = preg_replace('/href\s*=\s*["\']?\s*(javascript|data):[^"\'>\s]*/i', 'href="#"', $contenuNews);
    $contenuNews = nl2br($contenuNews);
}

debutCarte();
debutAccordion();
itemAccordion(htmlspecialchars($donnees['titre'], ENT_QUOTES, 'UTF-8'), '<img src="images/accueil/newspaper.png" width="44">', $contenuNews);
finAccordion();
finCarte();

if (isset($_SESSION['login'])) {
    // Season countdown: season ends at midnight on the 1st of next month
    $finSaison = mktime(0, 0, 0, (int)date('n') + 1, 1, (int)date('Y'));
    $secondsLeft = max(0, $finSaison - time());
    $jours = floor($secondsLeft / 86400);
    $heures = floor(($secondsLeft % 86400) / 3600);
    $minutes = floor(($secondsLeft % 3600) / 60);
    $countdownText = $jours . 'j ' . $heures . 'h ' . $minutes . 'm';
    echo '<div style="text-align:center; padding:10px 0;">';
    echo '<img src="images/accueil/agenda.png" alt="saison" class="w32" style="vertical-align:middle;" />';
    echo '<span style="font-size:16px; font-weight:bold; margin-left:5px;">Fin de saison : </span>';
    echo '<span id="season-countdown" data-end="' . (int)$finSaison . '" style="font-size:18px; font-weight:bold; color:#8A0000;">';
    echo htmlspecialchars($countdownText, ENT_QUOTES, 'UTF-8');
    echo '</span>';
    echo '</div>';

    // Forum latest threads widget
    $latestThreads = dbFetchAll($base, 'SELECT id, titre, auteur, timestamp FROM sujets WHERE statut = 0 ORDER BY timestamp DESC LIMIT 3', '', '');
    if (!empty($latestThreads)):
?>
<div class="card">
    <div class="card-header">Dernieres discussions</div>
    <div class="card-content">
        <div class="list">
            <ul>
                <?php foreach ($latestThreads as $thread): ?>
                <li>
                    <a href="sujet.php?id=<?= (int)$thread['id'] ?>" class="item-link item-content">
                        <div class="item-inner">
                            <div class="item-title"><?= htmlspecialchars($thread['titre'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="item-after"><?= htmlspecialchars($thread['auteur'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="card-footer"><a href="forum.php">Voir tout le forum</a></div>
</div>
<?php
    endif;
}

debutCarte("The Very Little War", "", 'images/accueil/wallpaper.jpg');
debutContent(); ?>
<center>Depuis la nuit des temps, les atomes se livrent une guerre sans fin...</center><br /><br />
<img alt="so" src="images/accueil/azote.png" class="imageAtome" /><img alt="so" src="images/accueil/carbone.png" style="float:right" class="imageAtome" />
<div style="text-align:left;"><span style="color:#0024A7" class="atome">azote</span></div>
<div style="text-align:right;"><span class="atome">carbone</span></div>

<div style="margin-left:15%"><span style="color:#AF0000;" class="atome">oxygene</span><img alt="so" src="images/accueil/oxygene.png" class="imageAtome" /></div><img alt="so" src="images/accueil/hydrogene.png" style="float:right" class="imageAtome" />
<div style="text-align:right"><span style="color:lightGray;" class="atome">Hydrogene</span></div><br /><br />
<div style="margin-right:15%"><span style="color:#F9B106;" class="atome">soufre</span><img alt="so" src="images/accueil/soufre.png" class="imageAtome" /></div>

<br />
<div style="margin-left:5%;text-align:middle"><span style="color:#087625;" class="atome">chlore</span><img alt="so" src="images/accueil/chlore.png" class="imageAtome" /></div>

<br />
<div style="margin-left:40%">
    <span style="color:#FF3C54;" class="atome">iode</span> <span style="color:#693C25;" class="atome">brome</span>
</div>
<div style="margin-left:40%">
    <img alt="so" src="images/accueil/iode.png" style="float:left;" class="imageAtome" />
    <img alt="so" src="images/accueil/brome.png" style="text-align:middle;" class="imageAtome" />
</div>
<br />
<br /><br /><br />
<center>Prenez part à ce combat éternel en contrôlant votre propre armée de molécules !<br /><br /><img src="images/icone.png" style="height:50px;width:50px" alt="atome" /><br /><br />
    Rejoignez une communauté investie autour d'un jeu complétement <strong>gratuit</strong>, seule votre stratégie pourra vous sauver ! <br /><br />
    <?php echo submit(['link' => 'inscription.php', 'titre' => 'S\'inscrire']); ?><br />
</center><?php
            finContent();
            finCarte();

            debutCarte();
            debutContent(); ?>
<center><img src="images/accueil/molecules.png" alt="2" class="w32" /></center><br />Créez vos propres molécules à partir des différents atomes : <strong>carbone</strong> pour la défense, <span style="color:red">oxygène</span> pour l'attaque, reste à découvrir les capacités du <span style="color:orange">soufre</span>, <span style="color:marroon">brome</span>, <span style="color:lightGray">hydrogène</span>, <span style="color:fuschia">iode</span> et <span style="color:blue">azote</span> !
<?php
finContent();
finCarte();

debutCarte();
debutContent(); ?>
<center><img src="images/accueil/deal.png" alt="alliance" class="w32" /></center><br /><strong>Alliez-vous</strong> avec d'autres joueurs afin d'obtenir des bonus grâce au duplicateur : l'union fait la force !
<?php
finContent();
finCarte();

debutCarte();
debutContent(); ?>
<center><img src="images/accueil/crown.png" alt="victoire" class="w32" /></center><br />Prenez la tête des 4 différents classements en détruisant vos ennemis et <strong>remportez la victoire</strong> au bout du mois ! Une nouvelle partie recommencera tous les premiers du mois pour permettre de repartir sur un pied d'égalité...<br /><br />
<?php
finContent();
finCarte();

debutCarte();
debutContent(); ?>
<center><img src="images/accueil/agenda.png" alt="1" class="w32" /></center><br />Découvrez le bon côté de la physique, <strong>aucune connaissance scientifique</strong> n'est requise pour ce jeu ! Vous pouvez quand même en apprendre plus grâce aux cours <strong><a href="sinstruire.php">S'instruire</a></strong>.
<?php
finContent();
finCarte();

include("includes/copyright.php");
?>