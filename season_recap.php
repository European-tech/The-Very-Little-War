<?php
include("includes/basicprivatephp.php");
include("includes/layout.php");

$login = $_SESSION['login'];

$recaps = dbFetchAll($base,
    'SELECT * FROM season_recap WHERE login = ? ORDER BY season_number DESC LIMIT 10',
    's', $login);

debutCarte("Historique des saisons");

if (empty($recaps)) {
    debutContent();
    echo '<p style="text-align:center;color:#999;padding:20px;">Aucun historique disponible. Les donnees seront archivees a la fin de chaque saison.</p>';
    finContent();
} else {
    foreach ($recaps as $recap) {
        $seasonNum = (int)$recap['season_number'];
        $finalRank = (int)$recap['final_rank'];
        $totalPts = (int)$recap['total_points'];
        $atkPts = (int)$recap['points_attaque'];
        $defPts = (int)$recap['points_defense'];
        $tradeVol = (float)$recap['trade_volume'];
        $pillage = (int)$recap['ressources_pillees'];
        $nbAtk = (int)$recap['nb_attaques'];
        $victories = (int)$recap['victoires'];
        $molLost = (float)$recap['molecules_perdues'];
        $allianceName = $recap['alliance_name'] ?? '';
        $streakMax = (int)$recap['streak_max'];
        $createdAt = $recap['created_at'] ?? '';

        echo '<div class="card" style="margin:8px 0;">';
        echo '<div class="card-header" style="font-weight:bold;background-color:#f5f5f5;">';
        echo 'Saison ' . htmlspecialchars((string)$seasonNum, ENT_QUOTES, 'UTF-8');
        echo ' &mdash; Rang #' . htmlspecialchars((string)$finalRank, ENT_QUOTES, 'UTF-8');
        echo '</div>';
        echo '<div class="card-content card-content-padding">';

        echo '<table class="table table-bordered" style="width:100%;">';
        echo '<tbody>';

        echo '<tr><td><strong>Points totaux</strong></td><td>' . number_format($totalPts, 0, ' ', ' ') . '</td></tr>';
        echo '<tr><td><strong>Points d\'attaque</strong></td><td>' . number_format($atkPts, 0, ' ', ' ') . '</td></tr>';
        echo '<tr><td><strong>Points de defense</strong></td><td>' . number_format($defPts, 0, ' ', ' ') . '</td></tr>';
        echo '<tr><td><strong>Volume d\'echange</strong></td><td>' . number_format($tradeVol, 0, ' ', ' ') . '</td></tr>';
        echo '<tr><td><strong>Ressources pillees</strong></td><td>' . number_format($pillage, 0, ' ', ' ') . '</td></tr>';
        echo '<tr><td><strong>Combats menes</strong></td><td>' . number_format($nbAtk, 0, ' ', ' ') . '</td></tr>';
        echo '<tr><td><strong>Victoires</strong></td><td>' . number_format($victories, 0, ' ', ' ') . '</td></tr>';
        echo '<tr><td><strong>Molecules perdues</strong></td><td>' . number_format($molLost, 0, ' ', ' ') . '</td></tr>';

        if ($allianceName !== '') {
            echo '<tr><td><strong>Alliance</strong></td><td>' . htmlspecialchars($allianceName, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }

        echo '<tr><td><strong>Meilleure serie de connexion</strong></td><td>' . htmlspecialchars((string)$streakMax, ENT_QUOTES, 'UTF-8') . ' jours</td></tr>';

        echo '</tbody>';
        echo '</table>';

        if ($createdAt !== '') {
            echo '<p style="text-align:right;color:#999;font-size:11px;margin-top:5px;">Archive le ' . htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        echo '</div>';
        echo '</div>';
    }
}

finCarte();

include("includes/copyright.php");
?>
