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
            // LOW-007: Track online status by login (session-based), not by IP address.
            // Only show players active in the last ONLINE_TIMEOUT_SECONDS (default 5 min).
            // LOW-029: show relative timestamps to non-admins, exact only to admin.
            $isAdmin = (isset($_SESSION['login']) && $_SESSION['login'] === ADMIN_LOGIN);
            $onlineThreshold = time() - ONLINE_TIMEOUT_SECONDS;
            $connectesRows = dbFetchAll($base, 'SELECT login, derniereConnexion FROM membre WHERE derniereConnexion > ? ORDER BY derniereConnexion DESC', 'i', $onlineThreshold);
            foreach ($connectesRows as $donnees) {
                if ($donnees['login'] != ADMIN_LOGIN) {
                    $lastSeen = (int)$donnees['derniereConnexion'];
                    if ($isAdmin) {
                        $displayTime = date('d/m/Y à H\hi', $lastSeen);
                    } else {
                        $diff = time() - $lastSeen;
                        if ($diff < 60) {
                            $displayTime = "à l'instant";
                        } elseif ($diff < 3600) {
                            $displayTime = 'il y a ' . floor($diff / 60) . ' min';
                        } else {
                            $displayTime = 'il y a ' . floor($diff / 3600) . 'h';
                        }
                    }
                    echo '<tr>
                <td class="nowrapColumn"><a href="joueur.php?id=' . htmlspecialchars($donnees['login'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($donnees['login'], ENT_QUOTES, 'UTF-8') . '</a></td>
                <td class="nowrapColumn">' . htmlspecialchars($displayTime, ENT_QUOTES, 'UTF-8') . '</td>
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
