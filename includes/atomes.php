<br/>  
<div style="text-align:center"><?php
           echo nombrePoints($autre['totalPoints']);
            $nbRow = dbFetchOne($base, 'SELECT COALESCE(SUM(nombre), 0) AS total FROM molecules WHERE proprietaire=?', 's', $_SESSION['login']);
            $nb_molecules = ceil($nbRow['total']);

            echo nombreMolecules($nb_molecules);
            ?>
</div>