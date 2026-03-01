<?php
/**
 * Display Module
 * Display/formatting helpers for images, numbers, text, costs, and misc utilities.
 */

function image($num)
{
    global $nomsRes;
    global $nomsAccents;
    return '<img style="vertical-align:middle;width:37px;height:37px;" alt="Energie" src="images/' . $nomsRes[$num] . '.png" alt="' . $nomsRes[$num] . '" title="' . ucfirst($nomsAccents[$num]) . '" />';
}

function imageEnergie($imageAide = false)
{
    if ($imageAide) {
        $class = 'class="imageAide"';
    } else {
        $class = 'style="vertical-align:middle;width:25px;height:25px;"';
    }
    return '<img src="images/energie.png" ' . $class . '  alt="Energie" title="Energie" />';
}

function imagePoints()
{
    return '<img src="images/points.png" style="vertical-align:middle" alt="Points" title="Points" />';
}

function imageLabel($image, $label, $lien = false)
{
    if (!$lien) {
        $lien = "";
        $typeLabel = 'labelClassement';
    } else {
        $lien = '<a href="' . $lien . '" class="lienSousMenu">';
        $typeLabel = 'labelSousMenu';
    }
    return $lien . $image . '<br/><span class="' . $typeLabel . '"  style="color:black">' . $label . '</span></a>';
}

function separerZeros($nombre)
{
    return number_format($nombre, 0, ' ', ' ');
}

function couleur($chiffre)
{ // si négatif alors rouge, si positif alors vert
    if ($chiffre < 0) {
        return '<span style="color:red">' . $chiffre . '</span>';
    } elseif ($chiffre > 0) {
        return '<span style="color:green">+' . $chiffre . '</span>';
    } else {
        return $chiffre;
    }
}

function couleurFormule($formule)
{
    global $nomsRes;
    global $lettre;
    global $couleurs;

    foreach ($nomsRes as $num => $ressource) {
        $formule = preg_replace('#(' . $lettre[$num] . ')(<sub>[0-9]*</sub>)#', '<span style="color:' . $couleurs[$num] . ';font-weight:bold;">$1$2</span>', $formule);
    }

    return $formule;
}

function chiffrePetit($chiffre, $type = 1)
{
    $nombreDepart = floor($chiffre);
    $nombreFinal = floor($nombreDepart);
    $derriere = "";
    $negatif = "";
    if ($chiffre < 0) {
        $negatif = "-";
        $nombreFinal = -$nombreFinal;
    }

    while ($nombreFinal >= 1000) {
        if ($nombreFinal >= 1000000000000000000000000) {
            $nombreFinal = $nombreFinal / 1000000000000000000000000;
            $derriere = "Y" . $derriere . "";
        } elseif ($nombreFinal >= 1000000000000000000000) {
            $nombreFinal = $nombreFinal / 1000000000000000000000;
            $derriere = "Z" . $derriere . "";
        } elseif ($nombreFinal >= 1000000000000000000) {
            $nombreFinal = $nombreFinal / 1000000000000000000;
            $derriere = "E" . $derriere . "";
        } elseif ($nombreFinal >= 1000000000000000) {
            $nombreFinal = $nombreFinal / 1000000000000000;
            $derriere = "P" . $derriere . "";
        } elseif ($nombreFinal >= 1000000000000) {
            $nombreFinal = $nombreFinal / 1000000000000;
            $derriere = "T" . $derriere . "";
        } elseif ($nombreFinal >= 1000000000) {
            $nombreFinal = $nombreFinal / 1000000000;
            $derriere = "G" . $derriere . "";
        } elseif ($nombreFinal >= 1000000) {
            $nombreFinal = $nombreFinal / 1000000;
            $derriere = "M" . $derriere . "";
        } elseif ($nombreFinal >= 1000) {
            $nombreFinal = $nombreFinal / 1000;
            $derriere = "K" . $derriere . "";
        }
    }
    if ($nombreFinal <= 10) {
        $nombreFinal = floor($nombreFinal * 100) / 100;
    } elseif ($nombreFinal <= 100) {
        $nombreFinal = floor($nombreFinal * 10) / 10;
    } else {
        $nombreFinal = floor($nombreFinal);
    }

    $nombreFinal = $negatif . $nombreFinal;
    if ($type == 1) {
        return '<span title="' . number_format($nombreDepart, 0, ' ', ' ') . '">' . $nombreFinal . '' . $derriere . '</span>';
    } else {
        return $nombreFinal . '' . $derriere . '';
    }
}

function affichageTemps($secondes, $petitTemps = false)
{
    if ($petitTemps && $secondes <= 60) {
        return $secondes . 's';
    }

    if ($secondes >= 24 * 2 * 3600) {
        return (floor($secondes / 3600 / 24 * 100) / 100) . ' jours';
    }

    $heures = intval($secondes / 3600) . ':';
    $minutes = intval(($secondes % 3600) / 60) . ':';
    if ($minutes < 10) {
        $minutes = '0' . $minutes;
    }
    $secondes = intval((($secondes % 3600) % 60));
    if ($secondes < 10) {
        $secondes = '0' . $secondes;
    }
    return $heures . $minutes . $secondes;
}

function scriptAffichageTemps()
{
    echo '
    <script>
        function affichageTemps(secondes){
            var heures=String(Math.floor(secondes / 3600))+":";
            var minutes=Math.floor((secondes % 3600) / 60);
            if(minutes < 10){
                minutes = "0"+String(minutes)+":";
            }
            else {
                minutes = String(minutes)+":";
            }
            secondes=Math.floor(((secondes % 3600) % 60));
            if(secondes < 10){
                secondes = "0"+String(secondes);
            }
            return heures+minutes+secondes;
        }
    </script>';
}

function nombreMolecules($nombre)
{
    return chip($nombre, '<img src="images/molecule.png" alt="molecule" title="Population" style="width:20px;height;20px;border-radius:0px"/>', "white", "", true);
}

function nombrePoints($nombre)
{
    return chip($nombre, '<img src="images/points.png" alt="points" style="width:23px;height:23px;border-radius:0px"/>', "white", "", true);
}

function nombreAtome($num, $nombre)
{
    return chip($nombre, image($num), "white");
}

function nombreNeutrino($nombre)
{
    return chip($nombre, '<img style="vertical-align:middle;width:37px;height:37px;" alt="Neutrino" src="images/neutrino.png" title="Neutrino" />', "white");
}

function nombreEnergie($nombre, $id = false)
{
    return chip($nombre, imageEnergie(), "white", "", true, $id);
}

function nombreTemps($nombre)
{
    return chip($nombre, '<img alt="sablier" style="width:23px;height:23px;border-radius:1px;" src="images/sand-clock.png"/>', "white", "", true);
}

function nombreTout($nombre)
{
    return '
        <div class="chip bg-">
            <div class="chip-media bg-white" style="width:143px;border-radius:20px"><img src="images/tout.png" style="border-radius:0px;margin-right:0px;" alt="toutes" title="Toutes les ressources" /></div>
            <div class="chip-label">' . $nombre . '</div>
        </div>';
}

function coutEnergie($cout)
{
    global $ressources;

    if ($ressources['energie'] >= $cout) {
        return chip(chiffrePetit($cout), imageEnergie(), "white", "green", true);
    } else {
        return chip(chiffrePetit($cout), imageEnergie(), "white", "red", true);
    }
}

function coutAtome($num, $cout)
{
    global $nomsRes;
    global $ressources;

    if ($ressources[$nomsRes[$num]] >= $cout) { // BUG ICI
        return chip(chiffrePetit($cout), image($num), "white", "green");
    } else {
        return chip(chiffrePetit($cout), image($num), "white", "red");
    }
}

function coutTout($cout)
{
    global $nomsRes;
    global $ressources;

    $ok = true;
    foreach ($nomsRes as $num => $ressource) {
        if ($ressources[$ressource] < $cout) {
            $ok = false;
        }
    }

    if ($ok) {
        $couleur = 'green';
    } else {
        $couleur = 'red';
    }

    return '
    <div class="chip bg-' . $couleur . '">
        <div class="chip-media bg-white" style="width:143px;border-radius:20px"><img src="images/tout.png" style="border-radius:0px;margin-right:0px;" alt="toutes" title="Toutes les ressources" /></div>
        <div class="chip-label">' . $cout . '</div>
    </div>';
}

function pref($ressource)
{ // retourne le bon prefixe
    if (preg_match("#^[aeiouyh]#", $ressource)) {
        return "d'";
    } else {
        return "de ";
    }
}

function rangForum($joueur)
{
    global $base;
    global $paliersPipelette;

    $donnees = dbFetchOne($base, 'SELECT count(*) AS nbmessages FROM reponses WHERE auteur=?', 's', $joueur);

    $exLogin = dbQuery($base, 'SELECT login FROM membre WHERE login=?', 's', $joueur);
    $nb = mysqli_num_rows($exLogin);

    if ($nb == 0) {
        $couleur = "gray";
        $nom = "Supprimé";
    } else {

        $donnees2 = dbFetchOne($base, 'SELECT moderateur, login, codeur FROM membre WHERE login=?', 's', $joueur);
        if ($donnees2['login'] == "Guortates") {
            $couleur = "#FFCC99";
            $nom = "Créateur";
        } elseif ($donnees2['moderateur'] == 1) { //Si il est moderateur
            $couleur = "#a42800"; //Couleur speciale
            $nom = "Modérateur";
        } elseif ($donnees2['codeur'] == 1) {
            $couleur = "#740152";
            $nom = "Codeur";
        } elseif ($donnees['nbmessages'] >= $paliersPipelette[7]) {
            $couleur = 'red';
            $nom = "Diamant rouge";
        } elseif ($donnees['nbmessages'] >= $paliersPipelette[6]) {
            $couleur = '#40e0d0';
            $nom = "Diamant";
        } elseif ($donnees['nbmessages'] >= $paliersPipelette[5]) {
            $couleur = 'red';
            $nom = "Rubis";
        } elseif ($donnees['nbmessages'] >= $paliersPipelette[4]) {
            $couleur = 'blue';
            $nom = "Saphir";
        } elseif ($donnees['nbmessages'] >= $paliersPipelette[3]) {
            $couleur = 'green';
            $nom = "Emeraude";
        } elseif ($donnees['nbmessages'] >= $paliersPipelette[2]) {
            $couleur = '#d9a710';
            $nom = "Or";
        } elseif ($donnees['nbmessages'] >= $paliersPipelette[1]) {
            $couleur = '#cecece';
            $nom = "Argent";
        } elseif ($donnees['nbmessages'] >= $paliersPipelette[0]) {
            $couleur = '#614e1a';
            $nom = "Bronze";
        } else {
            $couleur = '#200001';
            $nom = "Apprenti";
        }
    }

    return ['couleur' => $couleur, 'nom' => $nom];
}

function antihtml($phrase)
{
    return htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8');
}

function antiXSS($phrase, $specialTexte = false)
{
    global $base;
    if ($specialTexte) {
        return mysqli_real_escape_string($base, antihtml($phrase));
    } else {
        return mysqli_real_escape_string($base, addslashes(antihtml(trim($phrase))));
    }
}

function creerBBcode($nomTextArea, $interieur = NULL, $reponse = 0)
{
    debutContent();
    echo 'BBcode <strong>activé</strong> ' . aide('bbcode', true);
    finContent();
}

function transformInt($nombre)
{
    $nombre = preg_replace('#K#i', '000', $nombre);
    $nombre = preg_replace('#M#i', '000000', $nombre);
    $nombre = preg_replace('#G#i', '000000000', $nombre);
    $nombre = preg_replace('#T#i', '000000000000', $nombre);
    $nombre = preg_replace('#P#i', '000000000000000', $nombre);
    $nombre = preg_replace('#E#i', '000000000000000000', $nombre);
    $nombre = preg_replace('#Z#i', '000000000000000000000', $nombre);
    $nombre = preg_replace('#Y#i', '000000000000000000000000', $nombre);
    return $nombre;
}
