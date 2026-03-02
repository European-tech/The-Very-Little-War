        <div style="text-align:center">
           <?php
            echo '<a href="#" data-popover=".popover-ressources" class="open-popover">'.chipInfo('Atomes','images/atom.png').'</a>';
            echo '<a href="#" data-popover=".popover-detailsEnergie" class="open-popover">'.nombreEnergie('<span id="affichageenergie">'.chiffrePetit($ressources['energie']).'/'.$ressourcesMax.'</span> <span style="color:green;margin-left:10px"> +'.chiffrePetit(revenuEnergie($constructions['generateur'],$_SESSION['login'])).'/h').'</a>';
            // Weekly catalyst indicator
            $activeCatalyst = getActiveCatalyst();
            echo '<div style="font-size:10px;color:#888;margin-top:2px;">⚗ <strong>' . htmlspecialchars($activeCatalyst['name']) . '</strong> — ' . htmlspecialchars($activeCatalyst['desc']) . '</div>';
            ?>
        </div>


