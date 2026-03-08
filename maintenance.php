<?php
require_once("includes/session_init.php");
include("includes/connexion.php");
include("includes/fonctions.php");
require_once("includes/csp.php");
require_once("includes/constantesBase.php");

// INFO-009: Idle timeout check — reject expired sessions before any DB read
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_IDLE_TIMEOUT)) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// LOW-024: Restrict maintenance page to admin only.
// Non-admin visitors are shown a 403 to avoid information disclosure.
// Also validate session token against DB to prevent session fixation bypass.
if (!isset($_SESSION['login']) || $_SESSION['login'] !== ADMIN_LOGIN) {
    http_response_code(403);
    exit('Accès refusé.');
}
$tokenCheck = dbFetchOne($base, 'SELECT session_token FROM membre WHERE login=?', 's', $_SESSION['login']);
if (!$tokenCheck || !hash_equals($tokenCheck['session_token'] ?? '', $_SESSION['session_token'] ?? 'x')) {
    http_response_code(403);
    exit('Accès refusé.');
}

include("includes/layout.php");
debutCarte("Maintenance");
$donnees = dbFetchOne($base, 'SELECT * FROM news ORDER BY id DESC LIMIT 0, 1');
if (!$donnees) {
    echo '<p>Aucune annonce disponible.</p>';
    finCarte();
    include("includes/copyright.php");
    exit;
}
// MEDIUM-013: Use htmlspecialchars + nl2br instead of fragile regex-based HTML tag stripping.
// Admin-authored news content is treated as plain text with line breaks preserved.
$contenu = nl2br(htmlspecialchars($donnees['contenu'], ENT_QUOTES, 'UTF-8'));
echo important(htmlspecialchars($donnees['titre'], ENT_QUOTES, 'UTF-8') . '<em> le ' . date('d/m/Y à H\hi', $donnees['timestamp']) . '</em>');
echo '
<p>
<br/>
' . $contenu . '
</p>
';

finCarte();
include("includes/copyright.php");
