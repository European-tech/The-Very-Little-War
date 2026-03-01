<?php
	$inscrits = dbFetchOne($base, 'SELECT count(*) AS c FROM membre');

    $connectes = dbFetchOne($base, 'SELECT COUNT(*) AS c FROM connectes');
?>


<div style="text-align:center">
    <br/>
    <?php
    echo chip($inscrits['c'],'<img src="images/accueil/man-user.png" alt="user" style="width:20px;height:20px">',"black");
    echo chip(compterActifs(),'<img src="images/accueil/man-user.png" alt="user" style="width:20px;height:20px">',"red");
    echo chip('<a href="connectes.php" class="lienVisible">'.$connectes['c'].'</a>','<img src="images/accueil/man-user.png" alt="user" style="width:20px;height:20px">',"green");
    ?>
</div>