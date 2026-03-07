<?php
include("includes/basicprivatephp.php");

// ============================================================
// Tutorial Mission Definitions
// ============================================================
// Each mission: titre, description, objectif, instructions (array of steps),
//   lien (page link), icone, recompense_energie, condition (bool),
//   mission_num (1-indexed for display)
// ============================================================

$tutorielMissions = [];

// --- Mission 1: Build your first generator (upgrade to level 2) ---
$tutorielMissions[] = [
    'titre'       => 'Construire son premier generateur',
    'description' => 'Le generateur produit de l\'energie, la ressource indispensable pour construire, attaquer et progresser dans le jeu. Sans energie, rien n\'est possible !',
    'objectif'    => 'Ameliorer le Generateur au niveau 2',
    'instructions' => [
        'Ouvrez le menu en haut a gauche',
        'Cliquez sur <strong>Constructions</strong>',
        'Trouvez le <strong>Generateur</strong> dans la liste',
        'Cliquez dessus pour l\'ameliorer au niveau 2',
    ],
    'lien'        => 'constructions.php',
    'lien_texte'  => 'Constructions',
    'icone'       => 'images/batiments/generateur.png',
    'recompense_energie' => TUTORIAL_REWARDS[0],
    'condition'   => ($constructions['generateur'] >= 2),
];

// --- Mission 2: Produce atoms (upgrade producteur to level 2) ---
$tutorielMissions[] = [
    'titre'       => 'Produire des atomes',
    'description' => 'Les atomes sont les briques de base pour creer vos molecules (soldats). Le producteur genere des atomes en continu, mais consomme de l\'energie.',
    'objectif'    => 'Ameliorer le Producteur au niveau 2',
    'instructions' => [
        'Rendez-vous dans <strong>Constructions</strong>',
        'Trouvez le <strong>Producteur</strong>',
        'Ameliorez-le au niveau 2',
        'Placez ensuite vos <strong>points de production</strong> sur les atomes souhaites',
    ],
    'lien'        => 'constructions.php',
    'lien_texte'  => 'Constructions',
    'icone'       => 'images/batiments/producteur.png',
    'recompense_energie' => TUTORIAL_REWARDS[1],
    'condition'   => ($constructions['producteur'] >= 2),
];

// --- Mission 3: Build storage (upgrade depot to level 2) ---
$tutorielMissions[] = [
    'titre'       => 'Construire un entrepot',
    'description' => 'L\'entrepot augmente la quantite maximale de ressources que vous pouvez stocker. Sans entrepot suffisant, votre production sera gaspillee !',
    'objectif'    => 'Ameliorer l\'Entrepot au niveau 2',
    'instructions' => [
        'Rendez-vous dans <strong>Constructions</strong>',
        'Trouvez l\'<strong>Entrepot</strong> (Depot)',
        'Ameliorez-le au niveau 2',
        'Votre capacite de stockage augmentera immediatement',
    ],
    'lien'        => 'constructions.php',
    'lien_texte'  => 'Constructions',
    'icone'       => 'images/batiments/depot.png',
    'recompense_energie' => TUTORIAL_REWARDS[2],
    'condition'   => ($constructions['depot'] >= 2),
];

// --- Mission 4: Create your first molecule ---
$exMolecules = dbFetchOne($base, 'SELECT count(*) AS nb FROM molecules WHERE proprietaire=? AND formule != ?', 'ss', $_SESSION['login'], 'Vide ');
$nbMoleculesCreees = $exMolecules ? intval($exMolecules['nb']) : 0;

$tutorielMissions[] = [
    'titre'       => 'Creer sa premiere molecule',
    'description' => 'Les molecules sont vos soldats ! Chaque molecule est composee d\'atomes qui determinent ses caracteristiques au combat : attaque, defense, vitesse, etc. Creez votre premiere classe de molecule pour commencer a former votre armee.',
    'objectif'    => 'Creer une classe de molecule',
    'instructions' => [
        'Cliquez sur <strong>Armee</strong> dans le menu',
        'Appuyez sur un des boutons <strong>+</strong> pour creer une nouvelle classe',
        'Repartissez les atomes selon les caracteristiques souhaitees',
        'Validez la creation de votre molecule',
        'Formez ensuite des molecules de cette classe',
    ],
    'lien'        => 'armee.php',
    'lien_texte'  => 'Armee',
    'icone'       => 'images/menu/armee.png',
    'recompense_energie' => TUTORIAL_REWARDS[3],
    'condition'   => ($nbMoleculesCreees > 0),
];

// --- Mission 5: Explore the market (change your profile description to prove exploration) ---
$tutorielMissions[] = [
    'titre'       => 'Personnaliser son profil',
    'description' => 'Changez la description publique de votre profil pour que les autres joueurs puissent vous connaitre. C\'est aussi l\'occasion de decouvrir la page <strong>Mon compte</strong> et le <strong>Marche</strong> dans le menu.',
    'objectif'    => 'Modifier votre description dans Mon compte',
    'instructions' => [
        'Cliquez sur <strong>Mon compte</strong> dans le menu',
        'Modifiez votre description publique',
        'Validez le changement',
        'Profitez-en pour explorer le <strong>Marche</strong> qui permet d\'echanger vos ressources',
    ],
    'lien'        => 'compte.php',
    'lien_texte'  => 'Mon compte',
    'icone'       => 'images/menu/compte.png',
    'recompense_energie' => TUTORIAL_REWARDS[4],
    'condition'   => ($autre['description'] != "" && $autre['description'] != "Pas de description"),
];

// --- Mission 6: Scout a nearby player (use espionage) ---
$exNeutrinos = dbFetchOne($base, 'SELECT neutrinos FROM autre WHERE login=?', 's', $_SESSION['login']);
$nbNeutrinos = $exNeutrinos ? intval($exNeutrinos['neutrinos']) : 0;

// Check if player has ever spied — exclude victim notification ("Tentative d'espionnage détectée")
$exEspionnage = dbFetchOne($base, 'SELECT count(*) AS nb FROM rapports WHERE destinataire=? AND titre LIKE ? AND titre NOT LIKE ?', 'sss', $_SESSION['login'], '%spionnage%', 'Tentative%');
$aEspionne = ($exEspionnage && intval($exEspionnage['nb']) > 0);

$tutorielMissions[] = [
    'titre'       => 'Espionner un joueur',
    'description' => 'Avant d\'attaquer, il est sage d\'espionner ! Les neutrinos sont vos espions : ils vous revelent les constructions, l\'armee et les ressources d\'un adversaire. Achetez des neutrinos et lancez un espionnage depuis le profil d\'un joueur.',
    'objectif'    => 'Espionner un autre joueur',
    'instructions' => [
        'Achetez des <strong>neutrinos</strong> dans <strong>Constructions</strong> (section neutrinos)',
        'Rendez-vous sur la <strong>Carte</strong>',
        'Cliquez sur un joueur pour voir son profil',
        'Cliquez sur l\'icone d\'espionnage pour l\'espionner',
        'Consultez le rapport dans <strong>Rapports</strong>',
    ],
    'lien'        => 'attaquer.php',
    'lien_texte'  => 'Carte',
    'icone'       => 'images/rapports/binoculars.png',
    'recompense_energie' => TUTORIAL_REWARDS[5],
    'condition'   => $aEspionne,
];

// --- Mission 7: Join or create an alliance ---
$tutorielMissions[] = [
    'titre'       => 'Rejoindre ou creer une equipe',
    'description' => 'Les equipes (alliances) vous permettent de cooperer avec d\'autres joueurs. Ensemble, vous pouvez partager des ressources, coordonner vos attaques et ameliorer le duplicateur qui augmente la production de toute l\'equipe.',
    'objectif'    => 'Rejoindre ou creer une equipe',
    'instructions' => [
        'Cliquez sur <strong>Equipe</strong> dans le menu',
        'Creez une nouvelle equipe en choisissant un nom et un tag',
        'Ou demandez a un chef d\'equipe de vous inviter',
        'Les equipes sont limitees a ' . $joueursEquipe . ' membres',
    ],
    'lien'        => 'alliance.php',
    'lien_texte'  => 'Equipe',
    'icone'       => 'images/menu/alliance.png',
    'recompense_energie' => TUTORIAL_REWARDS[6],
    'condition'   => ($autre['idalliance'] != 0),
];

// ============================================================
// Tutorial Reward Processing
// ============================================================
// We use the 'missions' field in 'autre' table to track completion.
// The missions field is a semicolon-separated string of 0/1 values.
// We map tutorial mission indices starting after the existing missions
// defined in cardsprivate.php ($listeMissions has 19 entries, indices 0-18).
//
// Instead, we use a dedicated prefix for tutorial missions in the
// niveaututo field. niveaututo values 10+ mean the main tutorial is done.
// We'll use a POST-based claim system: player clicks "Claim" when
// condition is met.
// ============================================================

if(isset($_POST['claimMission'])) {
    csrfCheck();
    $missionIndex = intval($_POST['claimMission']);

    if($missionIndex >= 0 && $missionIndex < count($tutorielMissions)) {
        $mission = $tutorielMissions[$missionIndex];

        // Fix PASS1-MEDIUM-037: sequential enforcement — all prior missions must be claimed first
        $sequenceOk = true;
        if($missionIndex > 0) {
            $prevMissionsData = $autre['missions'];
            $prevMissionsArr = ($prevMissionsData != "") ? explode(";", $prevMissionsData) : [];
            $prevTutoOffset = 19;
            while(count($prevMissionsArr) < $prevTutoOffset + $missionIndex) {
                $prevMissionsArr[] = "0";
            }
            for($i = 0; $i < $missionIndex; $i++) {
                $prevClaimIdx = $prevTutoOffset + $i;
                if(!isset($prevMissionsArr[$prevClaimIdx]) || $prevMissionsArr[$prevClaimIdx] != "1") {
                    $erreur = "Vous devez d'abord completer les missions precedentes.";
                    $sequenceOk = false;
                    break;
                }
            }
        }

        // Verify the condition is actually met
        if($sequenceOk && $mission['condition']) {
            // Wrap in transaction to prevent double-claim race condition
            $tutoResult = null;
            try {
                withTransaction($base, function() use ($base, $missionIndex, $mission, $tutorielMissions, &$tutoResult) {
                    $autreRow = dbFetchOne($base, 'SELECT missions FROM autre WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
                    $missionsData = $autreRow['missions'];
                    if($missionsData == "") {
                        $missionsData = str_repeat("0;", 19 + count($tutorielMissions));
                    }

                    $missionsArr = explode(";", $missionsData);
                    $tutoOffset = 19;
                    while(count($missionsArr) < $tutoOffset + count($tutorielMissions) + 1) {
                        $missionsArr[] = "0";
                    }

                    $claimIdx = $tutoOffset + $missionIndex;

                    if(isset($missionsArr[$claimIdx]) && $missionsArr[$claimIdx] == "0") {
                        $missionsArr[$claimIdx] = "1";
                        $chaineM = implode(";", $missionsArr);
                        dbExecute($base, 'UPDATE autre SET missions=? WHERE login=?', 'ss', $chaineM, $_SESSION['login']);

                        // Fix PASS1-MEDIUM-038: cap energy at depot storage limit to prevent overflow
                        $constructRow = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=?', 's', $_SESSION['login']);
                        $energieCap = placeDepot($constructRow ? intval($constructRow['depot']) : 0);
                        dbExecute($base, 'UPDATE ressources SET energie = LEAST(energie + ?, ?) WHERE login=?', 'ids', $mission['recompense_energie'], $energieCap, $_SESSION['login']);

                        $tutoResult = 'ok';
                    } else {
                        $tutoResult = 'already';
                    }
                });
            } catch (\Exception $e) {
                $tutoResult = 'error';
            }

            if ($tutoResult === 'ok') {
                $autre = dbFetchOne($base, 'SELECT * FROM autre WHERE login=?', 's', $_SESSION['login']);
                $ressources = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $_SESSION['login']);
                $information = "Mission terminee ! +" . $mission['recompense_energie'] . " energie";
            } elseif ($tutoResult === 'already') {
                $erreur = "Cette mission a deja ete validee.";
            } else {
                $erreur = "Erreur lors de la validation.";
            }
        } elseif($sequenceOk) {
            // Sequence is fine but the game condition is not yet satisfied
            $erreur = "Les conditions de cette mission ne sont pas encore remplies.";
        }
        // else: $sequenceOk is false — $erreur already set by sequential enforcement check above
    } else {
        $erreur = "Mission invalide.";
    }
}

// ============================================================
// Determine which missions are claimed
// ============================================================
$missionsData = $autre['missions'];
$missionsArr = ($missionsData != "") ? explode(";", $missionsData) : [];
$tutoOffset = 19;

// Ensure array is big enough
while(count($missionsArr) < $tutoOffset + count($tutorielMissions) + 1) {
    $missionsArr[] = "0";
}

// Build claimed status for each tutorial mission
foreach($tutorielMissions as $idx => &$mission) {
    $claimIdx = $tutoOffset + $idx;
    $mission['claimed'] = (isset($missionsArr[$claimIdx]) && $missionsArr[$claimIdx] == "1");
}
unset($mission); // break reference

include("includes/layout.php");

// ============================================================
// Calculate overall progress
// ============================================================
$totalMissions = count($tutorielMissions);
$completedMissions = 0;
foreach($tutorielMissions as $m) {
    if($m['claimed']) $completedMissions++;
}
$progressPercent = ($totalMissions > 0) ? round(($completedMissions / $totalMissions) * 100) : 0;

// ============================================================
// Display Tutorial Page
// ============================================================

debutCarte("Guide du debutant", "background-color:#1a237e");
?>
<div style="text-align:center;margin-bottom:15px;">
    <img src="images/icone.png" alt="TVLW" style="width:60px;height:60px;margin-bottom:5px;"/><br/>
    <span style="font-size:16px;font-weight:bold;">Bienvenue sur The Very Little War !</span><br/>
    <span style="font-size:13px;color:#666;">Completez ces missions pour apprendre les bases du jeu et gagner des recompenses.</span>
</div>

<div style="margin:10px 0 20px 0;">
    <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
        <strong>Progression : <?php echo $completedMissions; ?>/<?php echo $totalMissions; ?> missions</strong>
        <span><?php echo $progressPercent; ?>%</span>
    </div>
    <div style="background:#e0e0e0;border-radius:10px;height:12px;overflow:hidden;">
        <div style="background:linear-gradient(90deg, #4caf50, #81c784);height:100%;width:<?php echo $progressPercent; ?>%;border-radius:10px;transition:width 0.5s;"></div>
    </div>
    <?php if($completedMissions == $totalMissions && $totalMissions > 0) { ?>
        <div style="text-align:center;margin-top:10px;padding:8px;background:#e8f5e9;border-radius:8px;border:1px solid #4caf50;">
            <strong style="color:#2e7d32;">Toutes les missions sont terminees ! Bravo !</strong><br/>
            <span style="font-size:12px;">Explorez le reste du jeu : medailles, classement, forum...</span>
        </div>
    <?php } ?>
</div>
<?php
finCarte();

// ============================================================
// Display each mission as a card
// ============================================================
foreach($tutorielMissions as $idx => $mission) {
    $missionNum = $idx + 1;
    $isComplete = $mission['condition'];
    $isClaimed = $mission['claimed'];

    // Determine status
    if($isClaimed) {
        $statusColor = '#4caf50';
        $statusText = 'Terminee';
        $statusIcon = '&#10003;'; // checkmark
        $cardBorder = 'border-left:4px solid #4caf50;';
        $opacity = 'opacity:0.85;';
    } elseif($isComplete) {
        $statusColor = '#ff9800';
        $statusText = 'Prete a valider !';
        $statusIcon = '&#9733;'; // star
        $cardBorder = 'border-left:4px solid #ff9800;';
        $opacity = '';
    } else {
        $statusColor = '#9e9e9e';
        $statusText = 'En cours';
        $statusIcon = '&#9679;'; // bullet
        $cardBorder = 'border-left:4px solid #e0e0e0;';
        $opacity = '';
    }

    ?>
    <div class="card" style="<?php echo $cardBorder . $opacity; ?>margin-bottom:10px;">
        <div class="card-content">
            <div class="card-content-inner" style="padding:12px;">
                <!-- Mission header -->
                <div style="display:flex;align-items:center;margin-bottom:10px;">
                    <div style="flex-shrink:0;width:45px;height:45px;border-radius:50%;background:<?php echo $isClaimed ? '#e8f5e9' : ($isComplete ? '#fff3e0' : '#f5f5f5'); ?>;display:flex;align-items:center;justify-content:center;margin-right:12px;">
                        <img src="<?php echo htmlspecialchars($mission['icone']); ?>" alt="mission" style="width:30px;height:30px;"/>
                    </div>
                    <div style="flex-grow:1;">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <strong style="font-size:15px;">Mission <?php echo $missionNum; ?> : <?php echo $mission['titre']; ?></strong>
                            <span style="font-size:12px;color:<?php echo $statusColor; ?>;font-weight:bold;white-space:nowrap;margin-left:8px;">
                                <?php echo $statusIcon; ?> <?php echo $statusText; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Description -->
                <p style="color:#555;font-size:13px;margin:0 0 10px 0;">
                    <?php echo $mission['description']; ?>
                </p>

                <!-- Objective -->
                <div style="background:#e3f2fd;border-radius:6px;padding:8px 12px;margin-bottom:10px;">
                    <strong style="color:#1565c0;font-size:13px;">Objectif :</strong>
                    <span style="font-size:13px;"><?php echo $mission['objectif']; ?></span>
                </div>

                <!-- Step by step instructions -->
                <?php if(!$isClaimed) { ?>
                <div style="margin-bottom:10px;">
                    <strong style="font-size:13px;color:#333;">Comment faire :</strong>
                    <ol style="margin:5px 0 0 0;padding-left:20px;font-size:13px;color:#555;">
                        <?php foreach($mission['instructions'] as $step) { ?>
                            <li style="margin-bottom:4px;"><?php echo $step; ?></li>
                        <?php } ?>
                    </ol>
                </div>
                <?php } ?>

                <!-- Reward -->
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <div style="display:flex;align-items:center;">
                        <span style="font-size:13px;color:#666;margin-right:5px;">Recompense :</span>
                        <?php echo nombreEnergie($mission['recompense_energie']); ?>
                    </div>

                    <?php if($isClaimed) { ?>
                        <span class="chip bg-green" style="margin:0;">
                            <div class="chip-label" style="color:white;">Recompense obtenue</div>
                        </span>
                    <?php } elseif($isComplete) { ?>
                        <form method="post" action="tutoriel.php" style="display:inline;margin:0;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="claimMission" value="<?php echo $idx; ?>"/>
                            <button type="submit" class="button button-fill button-raised color-orange" style="text-transform:none;font-size:14px;">
                                Valider la mission
                            </button>
                        </form>
                    <?php } else { ?>
                        <a href="<?php echo $mission['lien']; ?>" class="button button-fill button-raised color-blue" style="text-transform:none;font-size:13px;">
                            <?php echo $mission['lien_texte']; ?>
                        </a>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// ============================================================
// Prestige hint card (P1-D8-048)
// ============================================================
?>
<div class="card" style="background:#e8f5e9;border-left:4px solid #4caf50;">
    <div class="card-content card-content-padding">
        <p><strong>Astuce :</strong> Gagnez des medailles pour debloquer des bonus PERMANENTS pour la saison suivante !
        <a href="prestige.php">Voir le systeme de prestige</a></p>
    </div>
</div>
<?php

// ============================================================
// Help Section - "Comprendre le jeu"
// ============================================================
debutCarte("Comprendre le jeu");
?>
<p>
    <?php echo important('Vue d\'ensemble'); ?>
    The Very Little War est un jeu de strategie ou vous devez <strong>construire</strong> des batiments, <strong>produire</strong> des ressources, <strong>creer</strong> une armee de molecules et <strong>attaquer</strong> les autres joueurs pour obtenir le plus de points avant la fin du mois.<br/>
    <br/>

    <?php echo important('Constructions'); ?>
    <img src="images/menu/constructions.png" alt="constructions" style="width:20px;height:20px;vertical-align:middle;margin-right:5px;"/>
    C'est ici que l'on construit et ameliore les batiments. Les plus importants sont :<br/>
    <ul style="padding-left:20px;">
        <li><strong>Generateur</strong> : produit de l'energie (indispensable)</li>
        <li><strong>Producteur</strong> : produit des atomes (consomme de l'energie)</li>
        <li><strong>Entrepot</strong> : augmente la capacite de stockage des ressources</li>
        <li><strong>Champ de force</strong> : protege les batiments contre les attaques</li>
        <li><strong>Ionisateur</strong> : augmente les degats de vos molecules</li>
        <li><strong>Condenseur</strong> : ameliore l'efficacite de chaque atome</li>
        <li><strong>Lieur</strong> : reduit le temps de formation des molecules</li>
        <li><strong>Stabilisateur</strong> : reduit la deterioration naturelle des molecules</li>
    </ul><br/>

    <?php echo important('Armee'); ?>
    <img src="images/menu/armee.png" alt="armee" style="width:20px;height:20px;vertical-align:middle;margin-right:5px;"/>
    Creez vos molecules en choisissant leur composition en atomes. Chaque atome a un role different :<br/>
    <ul style="padding-left:20px;">
        <?php
        foreach($nomsRes as $num => $ressource) {
            echo '<li><span style="color:'.$couleurs[$num].';font-weight:bold;">'.ucfirst($nomsAccents[$num]).'</span> : '.$utilite[$num].'</li>';
        }
        ?>
    </ul>
    Vous pouvez creer jusqu'a <strong>4 classes</strong> de molecules differentes. La premiere classe est celle qui encaisse les attaques en priorite.<br/>
    <br/>
    <span style="color:red;"><strong>Attention :</strong> plus il y a d'atomes dans une molecule, plus elle est instable et disparait rapidement !</span><br/>
    <br/>

    <?php echo important('Carte & Combat'); ?>
    <img src="images/menu/attaquer.png" alt="carte" style="width:20px;height:20px;vertical-align:middle;margin-right:5px;"/>
    Sur la carte, trouvez les autres joueurs et attaquez-les pour gagner des points. Vous pouvez aussi les espionner pour connaitre leurs forces avant d'attaquer.<br/>
    <br/>
    <ul style="padding-left:20px;">
        <li><strong style="color:orange;">Orange</strong> : votre position</li>
        <li><strong style="color:blue;">Bleu</strong> : votre equipe</li>
        <li><strong style="color:green;">Vert</strong> : vos allies (pacte)</li>
        <li><strong style="color:red;">Rouge</strong> : vos ennemis (guerre)</li>
    </ul><br/>

    <?php echo important('Marche'); ?>
    <img src="images/menu/marche.png" alt="marche" style="width:20px;height:20px;vertical-align:middle;margin-right:5px;"/>
    Echangez vos ressources entre elles ou envoyez-en a d'autres joueurs. Les cours d'echange varient en fonction de l'offre et de la demande.<br/>
    <br/>

    <?php echo important('Equipe'); ?>
    <img src="images/menu/alliance.png" alt="equipe" style="width:20px;height:20px;vertical-align:middle;margin-right:5px;"/>
    Rejoignez ou creez une equipe pour cooperer avec d'autres joueurs. Une equipe peut ameliorer le <strong>Duplicateur</strong> qui augmente la production de tous les membres. Maximum <?php echo $joueursEquipe; ?> membres par equipe.<br/>
    <br/>

    <?php echo important('Classement'); ?>
    <img src="images/menu/classement.png" alt="classement" style="width:20px;height:20px;vertical-align:middle;margin-right:5px;"/>
    Consultez votre position par rapport aux autres joueurs. Les points se calculent grace aux constructions, attaques, defenses et pillages. En fin de mois, les meilleurs joueurs gagnent des <strong>points de victoire</strong>.<br/>
    <br/>

    <?php echo important('Medailles'); ?>
    <img src="images/menu/medailles.png" alt="medailles" style="width:20px;height:20px;vertical-align:middle;margin-right:5px;"/>
    Obtenez des medailles en franchissant des paliers (attaques, constructions, pillages...). Chaque medaille donne un <strong>bonus permanent</strong> de plus en plus puissant, du Bronze au Diamant Rouge.<br/>
    <br/>

    <?php echo important('Messages & Rapports'); ?>
    <img src="images/menu/message.png" alt="messages" style="width:20px;height:20px;vertical-align:middle;margin-right:5px;"/>
    Envoyez des messages aux autres joueurs et consultez vos rapports de combat et d'espionnage dans <strong>Rapports</strong>.<br/>
    <br/>

    <?php echo important('Forum'); ?>
    <img src="images/menu/forum.png" alt="forum" style="width:20px;height:20px;vertical-align:middle;margin-right:5px;"/>
    Discutez avec les autres joueurs, proposez des ameliorations, signalez des bugs ou presentez-vous.<br/>
    <br/>

    <?php echo important('Mon compte'); ?>
    <img src="images/menu/compte.png" alt="compte" style="width:20px;height:20px;vertical-align:middle;margin-right:5px;"/>
    Modifiez votre mot de passe, changez votre description publique ou supprimez votre compte.<br/>
    <br/>

    <?php echo important('Conseils pour bien debuter'); ?>
    <ol style="padding-left:20px;">
        <li><strong>Ameliorez le generateur en priorite</strong> : l'energie est la cle de tout</li>
        <li><strong>Montez le producteur</strong> pour obtenir des atomes plus rapidement</li>
        <li><strong>Creez une molecule defensive</strong> en premiere classe (beaucoup de carbone et brome)</li>
        <li><strong>Rejoignez une equipe</strong> le plus tot possible pour beneficier du duplicateur</li>
        <li><strong>Montez le stabilisateur</strong> pour eviter que vos molecules disparaissent trop vite</li>
        <li><strong>Espionnez avant d'attaquer</strong> pour eviter les mauvaises surprises</li>
        <li><strong>Consultez les medailles</strong> pour savoir quels bonus vous pouvez debloquer</li>
        <li><strong>Visitez le forum</strong> pour poser vos questions et decouvrir des strategies</li>
    </ol>
</p>
<?php
finCarte();

include("includes/copyright.php");
?>
