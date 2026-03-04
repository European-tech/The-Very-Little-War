<br/>  
<div style="text-align:center"><?php
           echo nombrePoints($autre['totalPoints']);
            $nb_molecules = 0;
            $nbRows = dbFetchAll($base, 'SELECT nombre FROM molecules WHERE proprietaire=?', 's', $_SESSION['login']);
            foreach($nbRows as $nb){

                $nb_molecules += ceil($nb['nombre']);
            }

            echo nombreMolecules($nb_molecules);
            ?>
</div>