<?php
/**
 * UI Components Module
 * Framework7-style UI rendering helpers (cards, lists, accordions, forms, etc.)
 */

/**
 * Renders the opening markup for a Framework7 card component.
 *
 * @param string|false $titre  Card header text or HTML. Pass pre-escaped HTML or plain text.
 *                             Callers are responsible for escaping untrusted user content
 *                             (e.g. player names, alliance tags) before passing to $titre.
 * @param string       $style  Additional CSS for the card header (e.g. background-color).
 * @param string|false $image  URL for a background image on the card header.
 * @param string|false $overflow  HTML id for the inner content div; enables scroll overflow when set.
 *                               Value is HTML-escaped internally. Pass a plain identifier string.
 */
function debutCarte($titre = false, $style = "", $image = false, $overflow = false)
{
    if ($image) {
        $classe = "demo-card-header-pic";
        // Validate image path to a safe allowlist pattern before placing in CSS url() context.
        // Prevents CSS injection via a crafted $image value containing quotes or escape sequences.
        if (!preg_match('#^images/[a-zA-Z0-9_./-]+$#', $image)) {
            $image = 'images/defaut.jpg';
        }
        $escapedImage = htmlspecialchars($image, ENT_QUOTES, 'UTF-8');
        $style = $style . "background-image:url('" . $escapedImage . "');";
    } else {
        $classe = "";
    }
    if ($titre) {
        // Note: $titre may contain trusted HTML (aide() icons, alliance links).
        // Callers must escape user-supplied parts themselves.
        $titre = '
        <div class="card-header" style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '">
            ' . $titre . '
        </div>';
    } else {
        $titre = "";
    }

    if ($overflow) {
        $overflow = 'id="' . htmlspecialchars($overflow, ENT_QUOTES, 'UTF-8') . '" style="overflow-x:scroll;overflow-y:scroll;"';
    } else {
        $overflow = "";
    }

    echo '
    <div class="card ' . $classe . '" >
        <div class="card-content" >
            ' . $titre . '
            <div class="card-content-inner" ' . $overflow . ' >
            <p>';
}

function finCarte($footer = false)
{
    if ($footer) {
        $footer = '<div class="card-footer no-border">
        ' . $footer . '
        </div>';
    } else {
        $footer = "";
    }
    echo '   </p>
    	   </div>
           ' . $footer . '
	   </div>
    </div>
    ';
}

function debutListe($retour = false)
{
    $contenu = '
    <div class="list-block media-list">
        <ul>';
    if ($retour) {
        return $contenu;
    } else {
        echo $contenu;
    }
}

function finListe($retour = false)
{
    $contenu = '
        </ul>
    </div>';
    if ($retour) {
        return $contenu;
    } else {
        echo $contenu;
    }
}

function debutContent($inner = false, $return = false)
{
    if ($inner) {
        $inner = '<div class="content-block-inner">';
    } else {
        $inner = "";
    }

    if ($return) {
        return '
        <div class="content-block">' . $inner;
    } else {
        echo '
        <div class="content-block">' . $inner;
    }
}

function finContent($inner = false, $return = false)
{
    if ($inner) {
        $inner = '</div>';
    } else {
        $inner = "";
    }

    if ($return) {
        return $inner . '
        </div>
        ';
    } else {
        echo $inner . '
        </div>
        ';
    }
}

function debutAccordion()
{
    echo '
    <div class="list-block accordion-list">
        <ul>';
}

function finAccordion()
{
    echo '
        </ul>
    </div>';
}

function item($options)
{
    if (!array_key_exists("noList", $options) || !$options["noList"]) {
        $d = '<li>';
        $e = '</li>';
    } else {
        $d = '';
        $e = '';
    }

    if (array_key_exists("floating", $options) && $options["floating"]) {
        $floating = "floating-label";
    } else {
        $floating = "";
    }

    if (array_key_exists("disabled", $options) && $options["disabled"]) {
        $disabled = " disabled";
    } else {
        $disabled = "";
    }

    if (array_key_exists("media", $options) && $options["media"]) {
        $media =
            '<div class="item-media">
            ' . $options["media"] . '
        </div>';
    } else {
        $media = "";
    }

    if (array_key_exists("input", $options) && $options["input"]) {
        $input = '
        <div class="item-input">
            ' . $options["input"] . '
        </div>';
    } else {
        $input = "";
    }

    if (array_key_exists("style", $options) && $options["style"]) {
        $style = $options["style"];
    } else {
        $style = "";
    }

    if (array_key_exists("after", $options) && $options["after"]) {
        $after = '
        <div class="item-after" style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '">
            ' . $options["after"] . '
        </div>';
    } else {
        $after = "";
    }

    if (array_key_exists("titre", $options) && $options["titre"]) {
        $titre = '
        <div class="item-title ' . $floating . '">
            ' . $options["titre"] . '
        </div>';
    } else {
        $titre = "";
    }

    if (array_key_exists("soustitre", $options) && $options["soustitre"]) {
        $soustitre = '
        <div class="item-subtitle">
            ' . $options["soustitre"] . '
        </div>';

        $titre = '<div class="item-title-row">' . $titre . $after . '</div>';
        $after = '';
    } else {
        $soustitre = "";
    }

    if (array_key_exists("accordion", $options) && $options["accordion"]) {
        $options['link'] = '#';
        $d = '<li class="accordion-item">';
        $e = '</li>';
        $accordion = '
        <div class="accordion-item-content">
            <div class="content-block">
                <p>' . $options["accordion"] . '</p>
            </div>
        </div>';
    } else {
        $accordion = '';
    }

    if (array_key_exists("autocomplete", $options) && $options["autocomplete"] && !array_key_exists("link", $options)) {
        $options['link'] = '#';
        $options['ajax'] = true;
    }

    if (array_key_exists("link", $options) && $options["link"]) {
        if (array_key_exists("ajax", $options) && $options["ajax"]) {
            $ajax = ' ajax';
        } else {
            $ajax = '';
        }

        if (array_key_exists("autocomplete", $options) && $options["autocomplete"]) {
            $autocomplete = ' autocomplete-opener';
            // Escape the id attribute value to prevent XSS via crafted autocomplete identifiers
            $autocompleteId = 'id="' . htmlspecialchars($options["autocomplete"], ENT_QUOTES, 'UTF-8') . '"';
        } else {
            $autocomplete = '';
            $autocompleteId = '';
        }

        // Escape href attribute to prevent XSS via crafted link values
        $link = '<a class="item-link' . $ajax . $autocomplete . ' close-panel-link" data-view=".view-main" href="' . htmlspecialchars($options["link"], ENT_QUOTES, 'UTF-8') . '" ' . $autocompleteId . '>';
        $finLink = '</a>';
    } else {
        $link = "";
        $finLink = '';
    }

    if (array_key_exists("form", $options) && $options["form"]) {
        if (array_key_exists("sup", $options["form"])) {
            $sup = $options["form"]["sup"];
        } else {
            $sup = "";
        }

        // Escape action URL and form name to prevent XSS via crafted form option values
        $form = '<form method="post" action="' . htmlspecialchars($options["form"][0], ENT_QUOTES, 'UTF-8') . '" name="' . htmlspecialchars($options["form"][1], ENT_QUOTES, 'UTF-8') . '" ' . $sup . '>';
        $finForm = '</form>';
    } else {
        $form = "";
        $finForm = '';
    }

    if (array_key_exists("select", $options) && $options["select"]) {
        $js = "";
        if (array_key_exists("javascript", $options["select"]) && $options["select"]["javascript"]) {
            $js = $options["select"]["javascript"];
        }

        if (array_key_exists("hauteur", $options["select"]) && $options["select"]["hauteur"]) {
            $options["select"]["hauteur"] = 'data-picker-height="' . $options["select"]["hauteur"] . 'px"';
        } else {
            $options["select"]["hauteur"] = '';
        }
        $select = '<a href="#" class="item-link smart-select" data-picker-close-text="Fermer" ' . $options["select"]['hauteur'] . '>
                    <select name="' . $options["select"][0] . '" id="' . $options["select"][0] . '" class="form-control" ' . $js . '>
                        ' . $options["select"][1] . '
                    </select>';
        $finSelect = '</a>';
    } else {
        $select = "";
        $finSelect = '';
    }


    if (array_key_exists("retour", $options) && $options["retour"]) { // si l'on veut pas afficher mais l'inclure dans une variable
        return  '
            ' . $d . '
                ' . $form . '
                ' . $link . '
                ' . $select . '
                <div class="item-content' . $disabled . '">
                    ' . $media . '
                    <div class="item-inner">
                        ' . $titre . '
                        ' . $soustitre . '
                        ' . $input . '
                    </div>
                    ' . $after . '
                </div>
                ' . $finSelect . '
                ' . $finLink . '
                ' . $finForm . '
            ' . $e;
    } else { // si on veut éviter de mettre echo à chaque fois
        echo '
        ' . $d . '
            ' . $form . '
            ' . $link . '
            ' . $select . '
            <div class="item-content' . $disabled . '">
                ' . $media . '
                <div class="item-inner">
                    ' . $titre . '
                    ' . $soustitre . '
                    ' . $input . '
                </div>
                ' . $after . '
            </div>
            ' . $finSelect . '
            ' . $finLink . '
            ' . $accordion . '
            ' . $finForm . '
        ' . $e;
    }
}

function itemAccordion($titre = false, $media = false, $contenu = false, $id = false)
{
    if ($media) {
        $media =
            '<div class="item-media">
            ' . $media . '
        </div>';
    } else {
        $media = "";
    }

    if ($titre) {
        $titre = '
        <div class="item-title-row">' . $titre . '<br/></div>';
    } else {
        $titre = "";
    }

    if ($id) {
        // Escape the id attribute value to prevent XSS via crafted id strings
        $id = 'id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"';
    } else {
        $id = "";
    }

    echo '
    <li class="accordion-item" ' . $id . '><a href="#" class="item-content item-link">
        ' . $media . '
        <div class="item-inner">
            ' . $titre . '
        </div></a>
        <div class="accordion-item-content">
            <div class="content-block">
                <p>' . $contenu . '</p>
            </div>
        </div>
    </li>';
}

function accordion($options)
{
    if (array_key_exists("media", $options) && $options["media"]) {
        $media =
            '<div class="item-media">
            ' . $options["media"] . '
        </div>';
    } else {
        $media = "";
    }

    if (array_key_exists("titre", $options) && $options["titre"]) {
        $titre =
            '<div class="item-media">
            ' . $options["titre"] . '
        </div>';
    } else {
        $titre = "";
    }

    if (array_key_exists("contenu", $options) && $options["contenu"]) {
        $contenu =
            '<div class="item-media">
            ' . $options["contenu"] . '
        </div>';
    } else {
        $contenu = "";
    }

    echo '<div class="accordion-item"><a href="#" class="item-content item-link">
        ' . $media . '
        <div class="item-inner">
            ' . $titre . '
        </div></a>
        <div class="accordion-item-content">
            <div class="content-block">
                <p>' . $contenu . '</p>
            </div>
        </div>
        </div>';
}

function checkbox($liste)
{
    $options = '';

    foreach ($liste as $key => $value) {

        if (array_key_exists("after", $value) && $value["after"]) {
            $after = '<div class="item-after">
                ' . $value['after'] . '
                </div>';
        } else {
            $after = "";
        }

        if (!array_key_exists("noList", $value) || !$value["noList"]) {
            $d = '<li>';
            $e = '</li>';
        } else {
            $d = '';
            $e = '';
        }
        $options = $options . '
            <li>
            <label class="label-checkbox item-content">
                <input type="checkbox" name="' . $value['name'] . '" id="' . $value['name'] . '">
                <div class="item-media">
                    <i class="icon icon-form-checkbox"></i>
                </div>
                <div class="item-inner">
                    <div class="item-title">' . $value['titre'] . '</div>
                </div>
            ' . $after . '
            </label>
            </li>';
    }

    return '
    ' . $d . '
    <div class="list-block">
        <ul>
        ' . $options . '
        </ul>
    </div>
    ' . $e;
}

function chip($label, $image, $couleurImage = "black", $couleur = "", $circle = false, $id = false)
{
    if ($circle) {
        $style = "border:1px solid black";
    } else {
        $style = "";
    }

    if ($id) {
        $id = 'id="' . $id . '"';
    } else {
        $id = "";
    }
    return '<div class="chip bg-' . htmlspecialchars($couleur, ENT_QUOTES, 'UTF-8') . '" style="margin-right:3px;margin-left:3px;">
                <div class="chip-media bg-' . htmlspecialchars($couleurImage, ENT_QUOTES, 'UTF-8') . '" style="' . $style . '">' . $image . '</div>
                <div class="chip-label" ' . $id . '>' . $label . '</div>
            </div>';
}

function chipInfo($label, $image, $id = false)
{
    return chip($label, '<img alt="tag" src="' . $image . '" style="width:25px;height:25px;border-radius:0px;"/>', "white", "", true, $id);
}

function progressBar($vie, $vieMax, $couleur)
{
    $pct = $vieMax > 0 ? min(100, round(($vie / $vieMax) * 100)) : 0;
    return '
        <br/><br/><br/>
        <div class="item-content" style="margin:0;padding:0;">
            <div class="item-inner" style="width: 80px;padding-right:0px;">
              <div data-progress="' . $pct . '" class="progressbar color-' . $couleur . '" style="height:6px;border:2px solid black;"></div>
              <center><strong style="font-size:13px">' . $vie . '/' . $vieMax . '</strong></center>
        </div>
        </div>';
}

function slider($options)
{
    $min = 0;
    if (array_key_exists("min", $options) && $options["min"]) {
        $min = $options['min'];
    }

    $max = 100;
    if (array_key_exists("max", $options) && $options["max"]) {
        $max = $options['max'];
    }

    $value = 50;
    if (array_key_exists("value", $options) && $options["value"]) {
        $value = $options['value'];
    }

    $step = 1;
    if (array_key_exists("step", $options) && $options["step"]) {
        $step = $options['step'];
    }

    $color = '';
    if (array_key_exists("color", $options) && $options["color"]) {
        $color = 'class="color-' . $options['color'] . '"';
    }


    return '
    <div class="range-slider" ' . $color . '>
        <input type="range" min="' . $min . '" max="' . $max . '" value="' . $value . '" step="' . $step . '">
    </div>';
}

function submit($options)
{
    if (array_key_exists("style", $options) && $options["style"]) {
        $style = $options['style'];
    } else {
        $style = "";
    }

    if (array_key_exists("titre", $options) && $options["titre"]) {
        $titre = $options['titre'];
    } else {
        $titre = "";
    }

    $isFormSubmit = false;
    if (array_key_exists("form", $options) && $options["form"]) {
        $isFormSubmit = true;
        $form = '';
    } else {
        $form = "";
    }

    if (array_key_exists("link", $options) && $options["link"]) {
        $isFormSubmit = false;
        $form = $options["link"];
    }

    if (array_key_exists("id", $options) && $options["id"]) {
        $id = 'id="' . $options["id"] . '"';
    } else {
        $id = '';
    }

    if (array_key_exists("classe", $options) && $options["classe"]) {
        $classe = $options['classe'];
    } else {
        $classe = "button-raised button-fill";
    }

    if (array_key_exists("image", $options) && $options["image"]) {
        $image1 = '<img alt="imageCote" src="' . $options['image'] . '" style="float:left;vertical-align:middle;width:25px;height:25px;margin-top:5px;margin-left:-3px"/>';
        if (!array_key_exists("simple", $options) || !$options["simple"]) {
            $image2 = '<img alt="imageCote" src="' . $options['image'] . '" style="float:right;vertical-align:middle;width:25px;height:25px;margin-top:5px;margin-right:-3px"/>';
        } else {
            $image2 = "";
        }
    } else {
        $image1 = "";
        $image2 = "";
    }

    if (array_key_exists("nom", $options) && $options["nom"]) {
        $nom = '<input type="hidden" name="' . $options['nom'] . '"/>';
    } else {
        $nom = '';
    }

    $confirmAttr = '';
    if (array_key_exists("confirm", $options) && $options["confirm"]) {
        $confirmAttr = ' data-confirm="' . htmlspecialchars($options['confirm'], ENT_QUOTES, 'UTF-8') . '"';
    }

    if ($isFormSubmit) {
        return $nom . '<button type="submit" class="button ' . $classe . '" style="' . $style . '" ' . $id . $confirmAttr . '>' . $image1 . $titre . $image2 . '</button>';
    }
    return $nom . '<a class="button ' . $classe . '" style="' . $style . '" href="' . $form . '" ' . $id . $confirmAttr . '>' . $image1 . $titre . $image2 . '</a>';
}

function important($contenu)
{
    return '<span class="important">' . $contenu . '</span><hr/>';
}

function aide($page, $noir = false)
{ // renvoie l'icone d'aide et lorsque l'on clique dessus, cela affiche l'aide associée, la liste des aides étant inscrite dans basicprivatehtml.php
    if ($noir) {
        return popover('popover-' . $page, 'images/question.png');
    } else {
        return popover('popover-' . $page, 'images/aide.png');
    }
}

function popover($nom, $image)
{
    return '<a href="#" data-popover=".' . $nom . '" class="open-popover" style=""><img src="' . $image . '" alt="question" style="width:20px;height:20px;vertical-align:middle;"></a>';
}

function carteForum($avatar, $login, $date, $titre, $contenu, $grade, $sujet = false)
{
    if ($sujet) {
        $sujet = '<div class="card-footer no-border">
        ' . $sujet . '
        </div>';
    } else {
        $sujet = '';
    }
    echo '
    <div class="card facebook-card">
      <div class="card-header no-border">
        <div class="facebook-avatar">' . $avatar . '</div>
        <div class="facebook-grade">' . $login . '<br/><span style="color:' . $grade['couleur'] . '">' . $grade['nom'] . '</span></div>
        <div class="facebook-name">' . $titre . '</div><br/>
        <div class="facebook-date">' . $date . '</div>
      </div>
      <div class="card-content-inner">' . $contenu . '</div>
      ' . $sujet . '
    </div>';
}

function imageClassement($rang)
{
    if ($rang == 1) {
        return '<img src="images/classement/or.png" alt="or" title="1er" style="vertical-align:middle;height:28px;width:28px;"/>';
    } elseif ($rang == 2) {
        return '<img src="images/classement/argent.png" alt="argent" title="2e" style="vertical-align:middle;height:25px;width:25px;"/>';
    } elseif ($rang == 3) {
        return '<img src="images/classement/bronze.png" alt="bronze" title="3e" style="vertical-align:middle;height:21px;width:21px;"/>';
    } else {
        return $rang;
    }
}
