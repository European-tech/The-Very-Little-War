<?php
include("includes/basicprivatephp.php");
include("includes/layout.php");

// Handle purchase POST
if (isset($_POST['achat'])) {
    csrfCheck();
    $result = purchasePrestigeUnlock($_SESSION['login'], $_POST['achat']);
    if ($result === true) {
        $information = "Amélioration achetée avec succès !";
    } else {
        $erreur = $result;
    }
}

// Load prestige data
$prestigeData = getPrestige($_SESSION['login']);
$totalPP = (int)$prestigeData['total_pp'];
$currentUnlocks = array_filter(explode(',', $prestigeData['unlocks']));
$seasonPP = calculatePrestigePoints($_SESSION['login']);

// Active bonuses
$prodBonus = prestigeProductionBonus($_SESSION['login']);
$combatBonus = prestigeCombatBonus($_SESSION['login']);

// ------- Section 1: PP Balance -------
debutCarte("Points de Prestige");
    debutContent();
        echo '<div style="text-align:center;">';
        echo '<span style="font-size:28px;font-weight:bold;color:#FFD700;">' . htmlspecialchars((string)$totalPP, ENT_QUOTES, 'UTF-8') . ' PP</span>';
        echo '<br/><span style="font-size:14px;color:grey;">Solde total</span>';
        echo '</div>';
        echo '<br/>';
        echo '<div style="text-align:center;padding:8px;background-color:#f5f5f5;border-radius:5px;">';
        echo '<span style="font-size:16px;">Cette saison : <strong style="color:green;">+' . htmlspecialchars((string)$seasonPP, ENT_QUOTES, 'UTF-8') . ' PP</strong></span>';
        echo '<br/><span style="font-size:11px;color:grey;">Points attribués à la fin de la saison</span>';
        echo '</div>';
    finContent();
finCarte();

// ------- Section 1b: Login Streak -------
$streakRow = dbFetchOne($base, 'SELECT streak_days, streak_last_date FROM autre WHERE login = ?', 's', $_SESSION['login']);
$currentStreak = $streakRow ? (int)$streakRow['streak_days'] : 0;
$nextMilestone = null;
$nextReward = 0;
foreach ($STREAK_MILESTONES as $day => $reward) {
    if ($day > $currentStreak) {
        $nextMilestone = $day;
        $nextReward = $reward;
        break;
    }
}
?>
<div class="card">
    <div class="card-header">Connexion quotidienne</div>
    <div class="card-content card-content-padding">
        <p><strong>Serie actuelle :</strong> <?= $currentStreak ?> jour<?= $currentStreak > 1 ? 's' : '' ?></p>
        <?php if ($nextMilestone): ?>
        <p>Prochain palier : <strong><?= $nextMilestone ?> jours</strong> (+<?= $nextReward ?> PP)</p>
        <div class="progressbar" data-progress="<?= min(100, round($currentStreak / $nextMilestone * 100)) ?>">
            <span></span>
        </div>
        <?php else: ?>
        <p>Tous les paliers atteints !</p>
        <?php endif; ?>
        <p class="text-color-gray" style="margin-top:8px;font-size:12px;">
            Connectez-vous chaque jour pour accumuler des PP bonus.
            Paliers : 1j (+<?= STREAK_REWARD_DAY_1 ?>PP), 3j (+<?= STREAK_REWARD_DAY_3 ?>PP), 7j (+<?= STREAK_REWARD_DAY_7 ?>PP),
            14j (+<?= STREAK_REWARD_DAY_14 ?>PP), 21j (+<?= STREAK_REWARD_DAY_21 ?>PP),
            28j (+<?= STREAK_REWARD_DAY_28 ?>PP)
        </p>
    </div>
</div>
<?php
// ------- Section 2: Active Bonuses -------
debutCarte("Bonus actifs");
    debutListe();
    if ($prodBonus > 1.0) {
        item([
            'media' => '<img src="images/batiments/producteur.png" alt="production" style="width:32px;height:32px;">',
            'titre' => 'Production +' . htmlspecialchars((string)round(($prodBonus - 1) * 100), ENT_QUOTES, 'UTF-8') . '%',
            'soustitre' => 'Bonus de production de ressources'
        ]);
    }
    if ($combatBonus > 1.0) {
        item([
            'media' => '<img src="images/molecule/sword.png" alt="combat" style="width:32px;height:32px;">',
            'titre' => 'Combat +' . htmlspecialchars((string)round(($combatBonus - 1) * 100), ENT_QUOTES, 'UTF-8') . '%',
            'soustitre' => 'Bonus aux stats de combat'
        ]);
    }
    if (hasPrestigeUnlock($_SESSION['login'], 'debutant_rapide')) {
        item([
            'media' => '<img src="images/batiments/generateur.png" alt="generateur" style="width:32px;height:32px;">',
            'titre' => 'Débutant Rapide',
            'soustitre' => 'Commence avec le Générateur niveau 2'
        ]);
    }
    if (hasPrestigeUnlock($_SESSION['login'], 'veteran')) {
        item([
            'media' => '<img src="images/molecule/shield.png" alt="protection" style="width:32px;height:32px;">',
            'titre' => 'Vétéran',
            'soustitre' => '+1 jour de protection débutant'
        ]);
    }
    if (isPrestigeLegend($_SESSION['login'])) {
        item([
            'media' => '<img src="images/classement/diamant.png" alt="legende" style="width:32px;height:32px;">',
            'titre' => 'Légende',
            'soustitre' => 'Badge unique et nom coloré'
        ]);
    }
    if (empty($currentUnlocks)) {
        item([
            'media' => '<img src="images/question.png" alt="aucun" style="width:32px;height:32px;">',
            'titre' => 'Aucun bonus actif',
            'soustitre' => 'Achetez des améliorations ci-dessous'
        ]);
    }
    finListe();
finCarte();

// ------- Section 3: Unlock Shop -------
global $PRESTIGE_UNLOCKS;
debutCarte("Améliorations disponibles");
    debutListe();
    foreach ($PRESTIGE_UNLOCKS as $key => $unlock) {
        $owned = in_array($key, $currentUnlocks);
        $canAfford = $totalPP >= $unlock['cost'];

        $costColor = $owned ? 'grey' : ($canAfford ? 'green' : 'red');
        $costText = htmlspecialchars((string)$unlock['cost'], ENT_QUOTES, 'UTF-8') . ' PP';

        if ($owned) {
            $actionHtml = '<span style="color:green;font-weight:bold;">Déjà acheté</span>';
        } else {
            $btnHtml = submit([
                    'titre' => 'Acheter (' . $costText . ')',
                    'form' => 'achat_' . $key,
                    'classe' => $canAfford ? 'button-raised button-fill' : 'button-raised button-disabled',
                    'style' => $canAfford ? '' : 'opacity:0.5;cursor:not-allowed;'
                ]);
            if (!$canAfford) {
                // Inject disabled attribute so the button is non-interactive
                $btnHtml = str_replace('<button ', '<button disabled ', $btnHtml);
            }
            $actionHtml = '<form method="post" action="prestige.php" name="achat_' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '" style="display:inline;">'
                . csrfField()
                . '<input type="hidden" name="achat" value="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '"/>'
                . $btnHtml
                . '</form>';
        }

        item([
            'titre' => '<strong>' . htmlspecialchars($unlock['name'], ENT_QUOTES, 'UTF-8') . '</strong> <span style="color:' . $costColor . ';">(' . $costText . ')</span>',
            'soustitre' => htmlspecialchars($unlock['desc'], ENT_QUOTES, 'UTF-8'),
            'accordion' => $actionHtml
        ]);
    }
    finListe();
finCarte();

// ------- Section 4: How to Earn PP -------
debutCarte("Comment gagner des PP");
    debutContent();
        echo important('Sources de points de prestige');
        echo '<ul style="list-style:none;padding-left:5px;">';
        echo '<li style="margin-bottom:8px;"><img src="images/menu/medailles.png" alt="medailles" style="width:20px;height:20px;vertical-align:middle;margin-right:5px;"> ';
        echo '<strong>Médailles</strong> : +1 PP par palier atteint dans chaque catégorie</li>';
        echo '<li style="margin-bottom:8px;"><img src="images/menu/attaquer.png" alt="attaques" style="width:20px;height:20px;vertical-align:middle;margin-right:5px;"> ';
        echo '<strong>Activité d\'attaque</strong> : +' . htmlspecialchars((string)PRESTIGE_PP_ATTACK_BONUS, ENT_QUOTES, 'UTF-8') . ' PP pour ' . htmlspecialchars((string)PRESTIGE_PP_ATTACK_THRESHOLD, ENT_QUOTES, 'UTF-8') . '+ attaques</li>';
        echo '<li style="margin-bottom:8px;"><img src="images/menu/marche.png" alt="echanges" style="width:20px;height:20px;vertical-align:middle;margin-right:5px;"> ';
        echo '<strong>Échanges</strong> : +' . htmlspecialchars((string)PRESTIGE_PP_TRADE_BONUS, ENT_QUOTES, 'UTF-8') . ' PP pour ' . htmlspecialchars((string)PRESTIGE_PP_TRADE_THRESHOLD, ENT_QUOTES, 'UTF-8') . '+ échanges</li>';
        echo '<li style="margin-bottom:8px;"><img src="images/menu/alliance.png" alt="dons" style="width:20px;height:20px;vertical-align:middle;margin-right:5px;"> ';
        echo '<strong>Dons d\'énergie</strong> : +' . htmlspecialchars((string)PRESTIGE_PP_DONATION_BONUS, ENT_QUOTES, 'UTF-8') . ' PP</li>';
        echo '<li style="margin-bottom:8px;"><img src="images/menu/compte.png" alt="connexion" style="width:20px;height:20px;vertical-align:middle;margin-right:5px;"> ';
        echo '<strong>Connexion dernière semaine</strong> : +' . htmlspecialchars((string)PRESTIGE_PP_ACTIVE_FINAL_WEEK, ENT_QUOTES, 'UTF-8') . ' PP</li>';
        echo '</ul>';
        echo '<br/>';
        echo important('Bonus de classement');
        echo '<table style="width:100%;text-align:center;">';
        echo '<tr style="font-weight:bold;border-bottom:1px solid #ddd;"><td>Rang</td><td>Bonus PP</td></tr>';

        global $PRESTIGE_RANK_BONUSES;
        foreach ($PRESTIGE_RANK_BONUSES as $cutoff => $bonus) {
            echo '<tr><td>Top ' . htmlspecialchars((string)$cutoff, ENT_QUOTES, 'UTF-8') . '</td><td style="color:green;">+' . htmlspecialchars((string)$bonus, ENT_QUOTES, 'UTF-8') . ' PP</td></tr>';
        }
        echo '</table>';
    finContent();
finCarte();

include("includes/copyright.php"); ?>
