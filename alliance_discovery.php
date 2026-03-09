<?php
require_once("includes/session_init.php");
if (isset($_SESSION['login'])) {
    include("includes/basicprivatephp.php");
} else {
    include("includes/basicpublicphp.php");
}

include("includes/layout.php");

// Query all alliances with member count and average points
$allianceRows = dbFetchAll($base,
    'SELECT a.id, a.nom, a.tag, a.duplicateur, a.chef,
            COUNT(au.login) AS membres,
            ROUND(AVG(au.totalPoints)) AS avg_points
     FROM alliances a
     LEFT JOIN autre au ON au.idalliance = a.id AND au.idalliance > 0
     GROUP BY a.id
     HAVING COUNT(au.login) > 0
     ORDER BY avg_points DESC', '', '');

// Check if the current player is in an alliance
$playerAllianceId = 0;
if (isset($_SESSION['login'])) {
    $playerAllianceRow = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
    $playerAllianceId = $playerAllianceRow ? (int)$playerAllianceRow['idalliance'] : 0;
}

debutCarte("Trouver une alliance");
debutContent();

if (isset($_SESSION['login']) && $playerAllianceId == 0) {
    echo '<div class="card" style="background-color:#e8f5e9; margin-bottom:15px; padding:10px;">';
    echo '<p style="margin:0;"><strong>Vous n\'avez pas encore d\'alliance !</strong></p>';
    echo '<p style="margin:5px 0 0 0; font-size:13px; color:#555;">Rejoindre une alliance vous donne acces au <strong>duplicateur</strong> (bonus de production d\'energie), ';
    echo 'au <strong>forum d\'equipe</strong>, et vous permet de participer aux <strong>guerres</strong> entre alliances. ';
    echo 'Contactez le chef d\'une alliance ci-dessous pour demander une invitation !</p>';
    echo '</div>';
}

if (!empty($allianceRows)) {
    // Compute ranks
    $rang = 1;
    ?>
    <div class="table-responsive">
    <table class="table table-striped table-bordered">
    <thead>
    <tr>
        <th><img src="images/classement/up.png" alt="rang" class="imageSousMenu"/><br/><span class="labelClassement">Rang</span></th>
        <th><img src="images/classement/post-it.png" alt="tag" class="imageSousMenu"/><br/><span class="labelClassement">Tag</span></th>
        <th><img src="images/classement/alliance.png" alt="nom" class="imageSousMenu"/><br/><span class="labelClassement">Nom</span></th>
        <th><img src="images/classement/alliance.png" alt="membres" class="imageSousMenu"/><br/><span class="labelClassement">Membres</span></th>
        <th><img src="images/classement/points.png" alt="duplicateur" class="imageSousMenu"/><br/><span class="labelClassement">Duplicateur</span></th>
        <th><img src="images/classement/sum-sign.png" alt="moyenne" class="imageSousMenu"/><br/><span class="labelClassement">Rang moyen</span></th>
        <th><img src="images/classement/joueur.png" alt="chef" class="imageSousMenu"/><br/><span class="labelClassement">Chef</span></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($allianceRows as $row):
        $isFull = (int)$row['membres'] >= MAX_ALLIANCE_MEMBERS;
        $rowStyle = '';
        if (isset($_SESSION['login']) && $playerAllianceId > 0 && (int)$row['id'] === $playerAllianceId) {
            $rowStyle = 'background-color: rgba(160,160,160,0.6);';
        }
    ?>
    <tr style="<?= htmlspecialchars($rowStyle, ENT_QUOTES, 'UTF-8') ?>">
        <td><?= imageClassement($rang) ?></td>
        <td><?= alliance($row['tag']) ?></td>
        <td><?= htmlspecialchars($row['nom'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= (int)$row['membres'] ?>/<?= MAX_ALLIANCE_MEMBERS ?><?php if ($isFull) echo ' <span style="color:red; font-size:11px;">Complet</span>'; ?></td>
        <td>Niv. <?= (int)$row['duplicateur'] ?></td>
        <td><?= number_format((int)$row['avg_points'], 0, ' ', ' ') ?> pts</td>
        <td><?= joueur($row['chef']) ?></td>
    </tr>
    <?php
        $rang++;
    endforeach; ?>
    </tbody>
    </table>
    </div>
    <?php
} else {
    echo '<p style="text-align:center; color:#999;">Aucune alliance active pour le moment.</p>';
}

finContent();
finCarte();

include("includes/copyright.php");
?>
