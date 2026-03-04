<?php
require_once("includes/session_init.php");
if (isset($_SESSION['login'])) {
    include("includes/basicprivatephp.php");
} else {
    include("includes/basicpublicphp.php");
}

include("includes/layout.php");

debutCarte('Historique des connexions'); ?>
<div class="panel-responsive">
    <table class="table table-striped table-bordered">
        <thead>
            <tr>
                <th><img src="images/classement/joueur.png" alt="joueur" title="Joueur" class="w32" /></th>
                <th><img src="images/classement/calendrier.png" alt="date" title="Date de connexion" class="w32" /></th>
            </tr>
        </thead>
        <tbody>
            <?php
            $ex = dbQuery($base, 'SELECT login, derniereConnexion FROM membre ORDER BY derniereConnexion DESC');
            while ($donnees = mysqli_fetch_array($ex)) {
                if ($donnees['login'] != "Guortates") {
                    echo '<tr>
                <td class="nowrapColumn"><a href="joueur.php?id=' . htmlspecialchars($donnees['login'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($donnees['login'], ENT_QUOTES, 'UTF-8') . '</a></td>
                <td class="nowrapColumn">' . date('d/m/Y à H\hi', $donnees['derniereConnexion']) . '</td>
                </tr>';
                }
            }
            ?>
        </tbody>
    </table>
</div>
<?php
finCarte();
include("includes/copyright.php"); ?>
