<?php
include("includes/connexion.php");
?>

<!DOCTYPE html>
<!--[if lt IE 7]>      <html class="no-js lt-ie9 lt-ie8 lt-ie7"> <![endif]-->
<!--[if IE 7]>         <html class="no-js lt-ie9 lt-ie8"> <![endif]-->
<!--[if IE 8]>         <html class="no-js lt-ie9"> <![endif]-->
<!--[if gt IE 8]><!--> <html class="no-js"> <!--<![endif]-->
	<head>
	    <meta charset="utf-8">
	    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
	    <title>Streaming</title>
	    <meta name="description" content="">
	    <meta name="viewport" content="width=device-width">

	    <link rel="icon" type="image/x-png" href="img/grenade.png" />
	    <script type="text/javascript" src="afterglow.min.js"></script>

	</head>
	<body>
		<?php
		$data = ['lien' => ''];
		if(isset($_GET['id'])) {
			$ex = dbQuery($base, 'SELECT lien FROM liens WHERE id=?', 's', $_GET['id']);
			if ($ex) {
				$data = mysqli_fetch_array($ex);
			}
		} ?>
		<video class="afterglow" id="myvideo" width="1280" height="720">
	    	<source type="video/mp4" src="<?php echo htmlspecialchars($data['lien'], ENT_QUOTES, 'UTF-8'); ?>" />
	    </video>
	    <iframe src="<?php echo htmlspecialchars($data['lien'], ENT_QUOTES, 'UTF-8'); ?>">
	    </iframe>
	</body>
</html>



