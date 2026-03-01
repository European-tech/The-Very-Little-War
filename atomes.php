<br/>  
<div style="text-align:center"><?php
           echo nombrePoints($autre['points']);
            $nb_molecules = 0;
            $ex = dbQuery($base, 'SELECT nombre FROM molecules WHERE proprietaire=?', 's', $_SESSION['login']);
            while($nb = mysqli_fetch_array($ex)){
                
                $nb_molecules += ceil($nb['nombre']);
            }

            echo nombreMolecules($nb_molecules);
            ?>
</div>