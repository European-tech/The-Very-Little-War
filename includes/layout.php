<?php
$nonce = cspNonce();
// LOW-006: Compute current page name once, used for nav sub-menu highlighting
$currentPage = basename($_SERVER['PHP_SELF']);
// INFRA-SECURITY HIGH: Emit CSP header for all player pages (admin pages already emit their own CSP).
// NOTE (MED-059): 'unsafe-inline' is intentionally kept in style-src.
// Reason: Framework7 injects inline styles at runtime via JS (impossible to nonce/hash),
// and the codebase has ~50+ inline style="" attributes across layout, game pages, and
// PHP-generated HTML. Removing unsafe-inline would require either:
//   a) Moving all style="" attrs to CSS classes (large refactor), or
//   b) SHA-256 hashing every inline style value (brittle — breaks on any change).
// The <style nonce="$nonce"> block in includes/style.php IS nonce-protected (so the
// nonce in style-src is still meaningful for that block). Inline style="" attrs remain
// covered by unsafe-inline until the refactor is completed.
// TODO: progressively replace inline style="" attrs with CSS classes, then drop unsafe-inline.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce' https://cdnjs.cloudflare.com https://www.gstatic.com; style-src 'self' 'nonce-$nonce' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; img-src 'self' https://www.theverylittlewar.com; font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 0'); // Disable broken browser heuristic; CSP handles XSS
?>
<!DOCTYPE html>
<html lang="fr">
  <head>
    <?php include("includes/meta.php");
    include("includes/style.php"); ?>
  </head>
    <?php if (isset($_SESSION['login']))
    {
        include("includes/basicprivatehtml.php");
    }
    else
    {
        include("includes/basicpublichtml.php"); 
    }
    ?>
    
    <!-- commun au publique et au privé -->
    <div class="views tabs">
      <!-- Your main view, should have "view-main" class -->
      <div class="view tab view-main tab active " >
        <!-- Pages container, because we use fixed navbar and toolbar, it has additional appropriate classes-->
            <div class="navbar" style="box-shadow: -1px 2px 5px 2px rgba(0, 0, 0, 0.3); ">
              <div class="navbar-inner">
                <div class="left" id="leftMenu">
                  <a href="#" class="link icon-only open-panel">
                      <i class="icon icon-bars"></i>
                  </a>
                </div>
                <div class="center">
                <p>
                <img alt="banniere" src="images/banniere.png" id="titre" style="position:fixed;top:10px;left:15%;width:260px;height:27px;"/>
                </p>
                </div>
                <?php if (isset($_SESSION['login']) && isset($debut) && !empty($debut['debut'])): ?>
                <div class="right" style="position:absolute;right:8px;top:0;line-height:44px;">
                    <?php
                    // Season ends when calendar month changes from the month of $debut['debut']
                    $seasonStartMonth = (int)date('n', $debut['debut']);
                    $seasonStartYear = (int)date('Y', $debut['debut']);
                    // End = first second of next month
                    $endMonth = $seasonStartMonth + 1;
                    $endYear = $seasonStartYear;
                    if ($endMonth > 12) {
                        $endMonth = 1;
                        $endYear++;
                    }
                    $seasonEndTimestamp = mktime(0, 0, 0, $endMonth, 1, $endYear);
                    $secondsLeft = max(0, $seasonEndTimestamp - time());
                    $daysLeft = floor($secondsLeft / SECONDS_PER_DAY);
                    $hoursLeft = floor(($secondsLeft % SECONDS_PER_DAY) / SECONDS_PER_HOUR);
                    $minutesLeft = floor(($secondsLeft % SECONDS_PER_HOUR) / 60);
                    ?>
                    <span id="season-countdown-navbar" data-end="<?php echo htmlspecialchars((int)$seasonEndTimestamp, ENT_QUOTES, 'UTF-8'); ?>"
                          style="font-size:10px;color:#aaa;cursor:pointer;"
                          title="Fin de manche"><?php echo $daysLeft . 'j ' . $hoursLeft . 'h ' . $minutesLeft . 'm'; ?></span>
                </div>
                <?php endif; ?>
              </div>
            </div>
            
            <!-- AJOUT DES SOUS-MENUS -->
            <?php
            if($currentPage === "classement.php"){
            ?>
        
            <div class="toolbar toolbar-bottom toolbarcustom">
            <div class="toolbar-inner">
                <a class="tab-link lienSousMenu" href="classement.php?sub=0"><img src="images/sous-menus/joueur.png" class="imageSousMenu"/><br/><span class="labelSousMenu">Joueurs</span></a>
                <a class="tab-link lienSousMenu" href="classement.php?sub=1"><img src="images/sous-menus/alliance.png" class="imageSousMenu"/><br/><span class="labelSousMenu">Equipes</span></a>
                <a class="tab-link lienSousMenu" href="classement.php?sub=2"><img src="images/sous-menus/swords.png" class="imageSousMenu"/><br/><span class="labelSousMenu">Guerres</span></a>
                <a class="tab-link lienSousMenu" href="classement.php?sub=3"><img src="images/sous-menus/forum.png" class="imageSousMenu"/><br/><span class="labelSousMenu">Forum</span></a>
                <a class="tab-link lienSousMenu" href="historique.php?sub=0"><img src="images/sous-menus/parchemin.png" class="imageSousMenu"/><br/><span class="labelSousMenu">Archives</span></a>
            </div>
            </div>
            <?php } 
          
            if($currentPage === "marche.php"){
            ?>
        
            <div class="toolbar toolbar-bottom toolbarcustom">
            <div class="toolbar-inner">
                <a class="tab-link lienSousMenu" href="marche.php?sub=0"><img src="images/sous-menus/back-forth.png" class="imageSousMenu"/><br/><span class="labelSousMenu">Echanger</span></a>
                <a class="tab-link lienSousMenu" href="marche.php?sub=1"><img src="images/sous-menus/present.png" class="imageSousMenu"/><br/><span class="labelSousMenu">Envoyer</span></a>
            </div>
            </div>
            <?php } 
          
            if($currentPage === "armee.php" && !isset($_POST['emplacementmoleculecreer'])){
            ?>
        
            <div class="toolbar toolbar-bottom toolbarcustom" >
            <div class="toolbar-inner">
                <a class="tab-link lienSousMenu" href="armee.php?sub=0"><img src="images/sous-menus/sword-spin.png" class="imageSousMenu"/><br/><span class="labelSousMenu">Formation</span></a>
                <a class="tab-link lienSousMenu" href="armee.php?sub=1"><img src="images/sous-menus/rally-the-troops.png" class="imageSousMenu"/><br/><span class="labelSousMenu">Vue d'ensemble</span></a>
            </div>
            </div>
            <?php }
          
            if($currentPage === "armee.php" && isset($_POST['emplacementmoleculecreer'])){
            ?>
            <div class="toolbar toolbar-bottom toolbarcustom">
            <div class="toolbar-inner" style="background-color:lightgray;overflow-x:auto;" >
                <?php
                // V4: Pre-compute medal bonuses and lieur level for covalent formulas
                $medalDataTout = dbFetchOne($base, 'SELECT pointsAttaque, pointsDefense, ressourcesPillees FROM autre WHERE login=?', 's', $_SESSION['login']);
                $bonusAttaqueTout = computeMedalBonus($medalDataTout ? $medalDataTout['pointsAttaque'] : 0, $paliersAttaque, $bonusMedailles);
                $bonusDefenseTout = computeMedalBonus($medalDataTout ? $medalDataTout['pointsDefense'] : 0, $paliersDefense, $bonusMedailles);
                $bonusPillageTout = computeMedalBonus($medalDataTout ? $medalDataTout['ressourcesPillees'] : 0, $paliersPillage, $bonusMedailles);
                $lieurDataTout = dbFetchOne($base, 'SELECT lieur FROM constructions WHERE login=?', 's', $_SESSION['login']);
                $nivLieurTout = ($lieurDataTout && isset($lieurDataTout['lieur'])) ? $lieurDataTout['lieur'] : 0;

                echo chipInfo(attaque(0,0,$niveauoxygene,$bonusAttaqueTout),'images/molecule/sword.png','attaque');
                echo chipInfo(defense(0,0,$niveaucarbone,$bonusDefenseTout),'images/molecule/shield.png','defense');
                echo chipInfo(pointsDeVieMolecule(0,0,$niveaubrome),'images/molecule/sante.png','vie').'<br/>';
                echo chipInfo(vitesse(0,0,$niveauchlore).' cases/h','images/molecule/vitesse.png','vitesse');
                echo chipInfo(potentielDestruction(0,0,$niveauhydrogene),'images/molecule/fire.png','destruction');
                echo chipInfo(affichageTemps(tempsFormation(0,0,0,$niveauazote,$nivLieurTout,$_SESSION['login']),true),'images/molecule/temps.png','tempsFormation');
                echo chipInfo(pillage(0,0,$niveausoufre,$bonusPillageTout).' ressources','images/molecule/bag.png','pillage').'<br/>';
                echo nombreEnergie('<span style="color:green">+'.productionEnergieMolecule(0,$niveauiode).'/h</span>','productionIode');
                echo chipInfo(affichageTemps(demiVie($_SESSION['login'],0,1)),'images/molecule/demivie.png','demiVie');
                ?>
            </div>
            </div>
            <?php
            echo '
            ' . cspScriptTag() . '
                function actualiserStats(){
                    var totalAtomes = 0;
                    ';
                    foreach($nomsRes as $num => $res){
                        echo 'totalAtomes += Number(document.getElementById("'.$res.'").value);
                        ';
                    }
                    echo '
                    $.ajax({url: "api.php?id=attaque&niveau='.$niveauoxygene.'&nombre="+document.getElementById(\'oxygene\').value+"&nombre2="+document.getElementById(\'hydrogene\').value,
                    success: function(data){
                        var contenu = JSON.parse(data);
                        // Safe: contenu.valeur is a server-computed numeric string
                        document.getElementById(\'attaque\').textContent = contenu.valeur;
                    }});

                    $.ajax({url: "api.php?id=defense&niveau='.$niveaucarbone.'&nombre="+document.getElementById(\'carbone\').value+"&nombre2="+document.getElementById(\'brome\').value,
                    success: function(data){
                        var contenu = JSON.parse(data);
                        // Safe: contenu.valeur is a server-computed numeric string
                        document.getElementById(\'defense\').textContent = contenu.valeur;
                    }});

                    $.ajax({url: "api.php?id=pointsDeVieMolecule&niveau='.$niveaubrome.'&nombre="+document.getElementById(\'brome\').value+"&nombre2="+document.getElementById(\'carbone\').value,
                    success: function(data){
                        var contenu = JSON.parse(data);
                        // Safe: contenu.valeur is a server-computed numeric string
                        document.getElementById(\'vie\').textContent = contenu.valeur;
                    }});

                    $.ajax({url: "api.php?id=potentielDestruction&niveau='.$niveauhydrogene.'&nombre="+document.getElementById(\'hydrogene\').value+"&nombre2="+document.getElementById(\'oxygene\').value,
                    success: function(data){
                        var contenu = JSON.parse(data);
                        // Safe: contenu.valeur is a server-computed numeric string
                        document.getElementById(\'destruction\').textContent = contenu.valeur;
                    }});

                    $.ajax({url: "api.php?id=vitesse&niveau='.$niveauchlore.'&nombre="+document.getElementById(\'chlore\').value+"&nombre2="+document.getElementById(\'azote\').value,
                    success: function(data){
                        var contenu = JSON.parse(data);
                        // Safe: contenu.valeur is a server-computed numeric string
                        document.getElementById(\'vitesse\').textContent = contenu.valeur+" cases/h";
                    }});

                    $.ajax({url: "api.php?id=pillage&niveau='.$niveausoufre.'&nombre="+document.getElementById(\'soufre\').value+"&nombre2="+document.getElementById(\'chlore\').value,
                    success: function(data){
                        var contenu = JSON.parse(data);
                        // Safe: contenu.valeur is a server-computed numeric string
                        document.getElementById(\'pillage\').textContent = contenu.valeur;
                    }});

                    $.ajax({url: "api.php?id=productionEnergieMolecule&niveau='.$niveauiode.'&nombre="+document.getElementById(\'iode\').value,
                    success: function(data){
                        var contenu = JSON.parse(data);
                        // Build span via DOM API — avoids innerHTML with dynamic content
                        var span = document.createElement(\'span\');
                        span.style.color = \'green\';
                        // Safe: contenu.valeur is a server-computed numeric string
                        span.textContent = \'+\' + contenu.valeur + \'/h\';
                        var el = document.getElementById(\'productionIode\');
                        el.innerHTML = \'\';
                        el.appendChild(span);
                    }});

                    $.ajax({url: "api.php?id=tempsFormation&nbTotalAtomes="+totalAtomes+"&niveau='.$niveauazote.'&nombre="+document.getElementById(\'azote\').value+"&nombre2="+document.getElementById(\'iode\').value,
                    success: function(data){
                        var contenu = JSON.parse(data);
                        // Safe: contenu.valeur is a server-formatted time string (e.g. "2h30")
                        document.getElementById(\'tempsFormation\').textContent = contenu.valeur;
                    }});

                    $.ajax({url: "api.php?id=demiVie&nbTotalAtomes="+totalAtomes,
                    success: function(data){
                        var contenu = JSON.parse(data);
                        // Safe: contenu.valeur is a server-formatted time string (e.g. "2h30")
                        document.getElementById(\'demiVie\').textContent = contenu.valeur;
                    }});
                }
                // Bind oninput for all atom composition fields (replaces inline oninput handlers)
                ';
                foreach($nomsRes as $num => $res){
                    echo 'if(document.getElementById("'.$res.'")) { document.getElementById("'.$res.'").addEventListener("input", actualiserStats); }
                    ';
                }
                echo '
            </script>';
            }
            
            if($currentPage === "historique.php"){ ?>
            <div class="toolbar toolbar-bottom toolbarcustom">
            <div class="toolbar-inner">
                <a class="tab-link lienSousMenu" href="historique.php?sub=0"><img src="images/sous-menus/joueur.png" class="imageSousMenu"/><br/><span class="labelSousMenu">Joueurs</span></a>
                <a class="tab-link lienSousMenu" href="historique.php?sub=1"><img src="images/sous-menus/alliance.png" class="imageSousMenu"/><br/><span class="labelSousMenu">Equipes</span></a>
                <a class="tab-link lienSousMenu" href="historique.php?sub=2"><img src="images/sous-menus/swords.png" class="imageSousMenu"/><br/><span class="labelSousMenu">Guerres</span></a>
                <a class="tab-link lienSousMenu" href="classement.php?sub=0"><img src="images/sous-menus/podium.png" class="imageSousMenu"/><br/><span class="labelSousMenu">Partie en cours</span></a>
            </div>
            </div>
            <?php } ?>

        <div class="pages navbar-through">
          <!-- Page, "data-page" contains page name -->
          <div data-page="index" class="page">
 
            <!-- Top Navbar. In Material theme it should be inside of the page-->
            
            <!-- Scrollable page content -->
            <div class="page-content">
            <div style="height:63px"></div> <!-- pour éviter des problèmes avec la barre du menu -->
            <?php if (isset($_SESSION['login']))
            {
                include("includes/cardsprivate.php");
            }
            else
            {
                // cardspublic.php removed (was empty dead file)
            }
            ?>