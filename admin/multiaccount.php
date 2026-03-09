<?php
include("redirectionmotdepasse.php");
include("../includes/connexion.php");
require_once("../includes/csrf.php");
require_once("../includes/database.php");
require_once("../includes/multiaccount.php");
require_once("../includes/logger.php");
require_once(__DIR__ . '/../includes/csp.php');
$nonce = cspNonce();
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';");

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();

    // Mark alert as read
    if (isset($_POST['mark_read'])) {
        $alertId = (int)$_POST['mark_read'];
        dbExecute($base, 'UPDATE admin_alerts SET is_read = 1 WHERE id = ?', 'i', $alertId);
    }

    // Mark all alerts as read
    if (isset($_POST['mark_all_read'])) {
        dbExecute($base, 'UPDATE admin_alerts SET is_read = 1 WHERE is_read = 0');
    }

    // Update flag status
    if (isset($_POST['flag_action']) && isset($_POST['flag_id'])) {
        $flagId = (int)$_POST['flag_id'];
        $action = $_POST['flag_action'];
        $validStatuses = ['investigating', 'confirmed', 'dismissed'];
        if (in_array($action, $validStatuses, true)) {
            // ANTICHEAT-P26-001: verify flag exists before updating
            $flagExists = dbCount($base, 'SELECT COUNT(*) FROM account_flags WHERE id = ?', 'i', $flagId);
            if (!$flagExists) {
                logWarn('ADMIN', "Flag update rejected: flag #$flagId not found");
                // P27-006: early exit — do NOT log success for non-existent flag
            } elseif ($action === 'confirmed' || $action === 'dismissed') {
                // P9-MED-024: Use session-scoped identifier instead of hardcoded 'admin'
                $resolvedBy = 'admin_' . substr(session_id(), 0, 8);
                dbExecute($base,
                    'UPDATE account_flags SET status = ?, resolved_at = ?, resolved_by = ? WHERE id = ?',
                    'sisi', $action, time(), $resolvedBy, $flagId
                );
                logInfo('ADMIN', "Flag #$flagId status changed to $action");
            } else {
                dbExecute($base,
                    'UPDATE account_flags SET status = ?, resolved_at = NULL, resolved_by = NULL WHERE id = ?',
                    'si', $action, $flagId
                );
                logInfo('ADMIN', "Flag #$flagId status changed to $action");
            }
            } // end flagExists check
        }
    }

    // Add manual flag
    if (isset($_POST['manual_login']) && isset($_POST['manual_related']) && !empty($_POST['manual_login'])) {
        $manualLogin = trim($_POST['manual_login']);
        $manualRelated = trim($_POST['manual_related']);
        $manualNote = trim($_POST['manual_note'] ?? '');
        if (!empty($manualLogin) && !empty($manualRelated)) {
            // ANTI-CHEAT-HIGH-001 / ANTICHEAT-P26-002: Validate both players exist and are not banned.
            $loginExists   = dbCount($base, 'SELECT COUNT(*) FROM membre WHERE login = ? AND estExclu = 0', 's', $manualLogin);
            $relatedExists = dbCount($base, 'SELECT COUNT(*) FROM membre WHERE login = ? AND estExclu = 0', 's', $manualRelated);
            if (!$loginExists || !$relatedExists) {
                if (function_exists('logWarn')) {
                    logWarn('ADMIN', "Manual flag rejected: player not found", ['login' => $manualLogin, 'related' => $manualRelated]);
                }
                // fall through without INSERT; $information stays empty
            } else {
                $evidence = json_encode([
                    'note' => $manualNote,
                    'added_by' => 'admin',
                    'added_at' => time()
                ]);
                dbExecute($base,
                    'INSERT INTO account_flags (login, flag_type, related_login, evidence, severity, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                    'sssssi', $manualLogin, 'manual', $manualRelated, $evidence, 'high', time()
                );
                logInfo('ADMIN', "Manual flag added: $manualLogin <-> $manualRelated");
            }
        }
    }
}

// View mode — ADMIN12-006: whitelist allowed views to prevent future injection bugs
$view = in_array($_GET['view'] ?? '', ['alerts', 'flags', 'stats', 'manual', 'md5accounts'], true)
    ? $_GET['view']
    : 'alerts';
$detailLogin = isset($_GET['login']) ? $_GET['login'] : '';
if (!empty($detailLogin) && (strlen($detailLogin) > 20 || !preg_match('/^[a-zA-Z0-9_-]+$/', $detailLogin))) {
    $detailLogin = '';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TVLW Admin - Multi-comptes</title>
    <style>
        body { font-family: sans-serif; margin: 20px; background: #f5f5f5; }
        h2, h3, h4 { margin-top: 20px; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; background: white; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 14px; }
        th { background: #333; color: white; }
        tr:nth-child(even) { background: #f9f9f9; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; color: white; }
        .badge-critical { background: #d32f2f; }
        .badge-warning { background: #f57c00; }
        .badge-info { background: #1976d2; }
        .badge-high { background: #e53935; }
        .badge-medium { background: #fb8c00; }
        .badge-low { background: #43a047; }
        .badge-open { background: #1565c0; }
        .badge-investigating { background: #f9a825; color: #333; }
        .badge-confirmed { background: #c62828; }
        .badge-dismissed { background: #757575; }
        .nav { margin-bottom: 20px; }
        .nav a { display: inline-block; padding: 8px 16px; margin-right: 4px; background: #333; color: white; text-decoration: none; border-radius: 4px; }
        .nav a.active { background: #1976d2; }
        .btn { display: inline-block; padding: 4px 12px; border: none; border-radius: 3px; cursor: pointer; font-size: 13px; color: white; }
        .btn-dismiss { background: #757575; }
        .btn-investigate { background: #f9a825; color: #333; }
        .btn-confirm { background: #c62828; }
        .btn-read { background: #43a047; }
        .btn-primary { background: #1976d2; }
        form.inline { display: inline; }
        .card { background: white; padding: 15px; border-radius: 8px; margin: 10px 0; box-shadow: 0 1px 3px rgba(0,0,0,0.12); }
        .stats { display: flex; gap: 15px; flex-wrap: wrap; }
        .stat { padding: 15px 25px; background: white; border-radius: 8px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.12); }
        .stat .number { font-size: 28px; font-weight: bold; }
        .stat .label { font-size: 12px; color: #666; }
        input[type="text"] { padding: 6px 10px; border: 1px solid #ccc; border-radius: 3px; }
        textarea { padding: 6px 10px; border: 1px solid #ccc; border-radius: 3px; width: 100%; }
    </style>
</head>
<body>
    <h2>Anti Multi-comptes</h2>
    <p><a href="index.php">&larr; Retour admin</a></p>

    <div class="nav">
        <a href="multiaccount.php?view=alerts" class="<?php echo $view === 'alerts' ? 'active' : ''; ?>">Alertes</a>
        <a href="multiaccount.php?view=flags" class="<?php echo $view === 'flags' ? 'active' : ''; ?>">Drapeaux</a>
        <a href="multiaccount.php?view=stats" class="<?php echo $view === 'stats' ? 'active' : ''; ?>">Statistiques</a>
        <a href="multiaccount.php?view=manual" class="<?php echo $view === 'manual' ? 'active' : ''; ?>">Ajouter</a>
        <a href="multiaccount.php?view=md5accounts" class="<?php echo $view === 'md5accounts' ? 'active' : ''; ?>">Comptes MD5</a>
    </div>

    <?php if ($view === 'alerts'): ?>
        <?php
        $unreadCount = dbCount($base, 'SELECT COUNT(*) AS nb FROM admin_alerts WHERE is_read = 0');
        $alerts = dbFetchAll($base,
            'SELECT * FROM admin_alerts ORDER BY is_read ASC, FIELD(severity, "critical", "warning", "info"), created_at DESC LIMIT 100'
        );
        ?>
        <h3>Alertes non lues: <?php echo $unreadCount; ?></h3>

        <?php if ($unreadCount > 0): ?>
        <form method="post" action="multiaccount.php?view=alerts" class="inline">
            <?php echo csrfField(); ?>
            <button type="submit" name="mark_all_read" value="1" class="btn btn-primary">Tout marquer comme lu</button>
        </form>
        <?php endif; ?>

        <table>
            <tr>
                <th>ID</th>
                <th>Type</th>
                <th>Sévérité</th>
                <th>Message</th>
                <th>Date</th>
                <th>Lu</th>
                <th>Action</th>
            </tr>
            <?php foreach ($alerts as $alert): ?>
            <tr<?php echo $alert['is_read'] ? '' : ' style="font-weight:bold"'; ?>>
                <td><?php echo (int)$alert['id']; ?></td>
                <td><?php echo htmlspecialchars($alert['alert_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><span class="badge badge-<?php echo htmlspecialchars($alert['severity'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($alert['severity'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                <td><?php echo htmlspecialchars($alert['message'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo date('d/m/Y H:i', $alert['created_at']); ?></td>
                <td><?php echo $alert['is_read'] ? 'Oui' : 'Non'; ?></td>
                <td>
                    <?php if (!$alert['is_read']): ?>
                    <form method="post" action="multiaccount.php?view=alerts" class="inline">
                        <?php echo csrfField(); ?>
                        <button type="submit" name="mark_read" value="<?php echo (int)$alert['id']; ?>" class="btn btn-read">Lu</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>

    <?php elseif ($view === 'flags'): ?>
        <?php
        $statusFilter = isset($_GET['status']) ? $_GET['status'] : 'open';
        $validFilters = ['open', 'investigating', 'confirmed', 'dismissed', 'all'];
        if (!in_array($statusFilter, $validFilters, true)) $statusFilter = 'open';

        if ($statusFilter === 'all') {
            $flags = dbFetchAll($base, 'SELECT * FROM account_flags ORDER BY FIELD(severity, "critical", "high", "medium", "low"), created_at DESC LIMIT 200');
        } else {
            $flags = dbFetchAll($base, 'SELECT * FROM account_flags WHERE status = ? ORDER BY FIELD(severity, "critical", "high", "medium", "low"), created_at DESC LIMIT 200', 's', $statusFilter);
        }
        ?>

        <h3>Drapeaux de suspicion</h3>
        <p>
            Filtre:
            <?php foreach (['open' => 'Ouverts', 'investigating' => 'En cours', 'confirmed' => 'Confirmés', 'dismissed' => 'Rejetés', 'all' => 'Tous'] as $k => $v): ?>
                <a href="multiaccount.php?view=flags&status=<?php echo $k; ?>" class="btn <?php echo $statusFilter === $k ? 'btn-primary' : 'btn-dismiss'; ?>"><?php echo $v; ?></a>
            <?php endforeach; ?>
        </p>

        <table>
            <tr>
                <th>ID</th>
                <th>Compte</th>
                <th>Lié à</th>
                <th>Type</th>
                <th>Sévérité</th>
                <th>Statut</th>
                <th>Date</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($flags as $flag): ?>
            <tr>
                <td><?php echo (int)$flag['id']; ?></td>
                <td><a href="multiaccount.php?view=flags&login=<?php echo urlencode($flag['login']); ?>"><?php echo htmlspecialchars($flag['login'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                <td><a href="multiaccount.php?view=flags&login=<?php echo urlencode($flag['related_login'] ?? ''); ?>"><?php echo htmlspecialchars($flag['related_login'] ?? '', ENT_QUOTES, 'UTF-8'); ?></a></td>
                <td><?php echo htmlspecialchars($flag['flag_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><span class="badge badge-<?php echo htmlspecialchars($flag['severity'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flag['severity'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                <td><span class="badge badge-<?php echo htmlspecialchars($flag['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flag['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                <td><?php echo date('d/m/Y H:i', $flag['created_at']); ?></td>
                <td>
                    <?php if ($flag['status'] === 'open'): ?>
                        <form method="post" action="multiaccount.php?view=flags&status=<?php echo $statusFilter; ?>" class="inline">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="flag_id" value="<?php echo (int)$flag['id']; ?>">
                            <button type="submit" name="flag_action" value="investigating" class="btn btn-investigate">Enquêter</button>
                            <button type="submit" name="flag_action" value="dismissed" class="btn btn-dismiss">Rejeter</button>
                        </form>
                    <?php elseif ($flag['status'] === 'investigating'): ?>
                        <form method="post" action="multiaccount.php?view=flags&status=<?php echo $statusFilter; ?>" class="inline">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="flag_id" value="<?php echo (int)$flag['id']; ?>">
                            <button type="submit" name="flag_action" value="confirmed" class="btn btn-confirm">Confirmer</button>
                            <button type="submit" name="flag_action" value="dismissed" class="btn btn-dismiss">Rejeter</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>

        <?php
        // Account detail view
        if (!empty($detailLogin)):
            $loginSafe = htmlspecialchars($detailLogin, ENT_QUOTES, 'UTF-8');
            $loginHistory = dbFetchAll($base,
                'SELECT * FROM login_history WHERE login = ? ORDER BY timestamp DESC LIMIT 50',
                's', $detailLogin
            );
            $accountFlags = dbFetchAll($base,
                'SELECT * FROM account_flags WHERE login = ? OR related_login = ? ORDER BY created_at DESC',
                'ss', $detailLogin, $detailLogin
            );
        ?>
            <div class="card">
                <h3>Détail: <?php echo $loginSafe; ?></h3>

                <h4>Historique de connexion (50 dernières)</h4>
                <table>
                    <tr><th>Date</th><th>IP</th><th>Empreinte</th><th>Type</th><th>User-Agent</th></tr>
                    <?php foreach ($loginHistory as $lh): ?>
                    <tr>
                        <td><?php echo date('d/m/Y H:i:s', $lh['timestamp']); ?></td>
                        <td title="<?php echo htmlspecialchars($lh['ip'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><?php $ipDisplay = substr($lh['ip'] ?? '', 0, 12) . '…'; echo htmlspecialchars($ipDisplay, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td title="<?php echo htmlspecialchars($lh['fingerprint'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(substr($lh['fingerprint'] ?? '', 0, 12), ENT_QUOTES, 'UTF-8'); ?>…</td>
                        <td><?php echo htmlspecialchars($lh['event_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td title="<?php echo htmlspecialchars($lh['user_agent'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(substr($lh['user_agent'] ?? '', 0, 60), ENT_QUOTES, 'UTF-8'); ?>...</td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <h4>Tous les drapeaux</h4>
                <table>
                    <tr><th>Type</th><th>Lié à</th><th>Sévérité</th><th>Statut</th><th>Date</th><th>Preuves</th></tr>
                    <?php foreach ($accountFlags as $af): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($af['flag_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($af['related_login'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><span class="badge badge-<?php echo htmlspecialchars($af['severity'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($af['severity'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td><span class="badge badge-<?php echo htmlspecialchars($af['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($af['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td><?php echo date('d/m/Y H:i', $af['created_at']); ?></td>
                        <td><pre style="max-width:400px;overflow:auto;font-size:11px"><?php echo htmlspecialchars(json_encode(json_decode($af['evidence'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></pre></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>

    <?php elseif ($view === 'stats'): ?>
        <?php
        $totalFlags = dbCount($base, 'SELECT COUNT(*) AS nb FROM account_flags');
        $openFlags = dbCount($base, 'SELECT COUNT(*) AS nb FROM account_flags WHERE status = ?', 's', 'open');
        $criticalFlags = dbCount($base, 'SELECT COUNT(*) AS nb FROM account_flags WHERE severity = ? AND status IN (?, ?)', 'sss', 'critical', 'open', 'investigating');
        $totalAlerts = dbCount($base, 'SELECT COUNT(*) AS nb FROM admin_alerts');
        $unreadAlerts = dbCount($base, 'SELECT COUNT(*) AS nb FROM admin_alerts WHERE is_read = 0');
        $totalLogins = dbCount($base, 'SELECT COUNT(*) AS nb FROM login_history');
        $uniqueIPs = dbCount($base, 'SELECT COUNT(DISTINCT ip) AS nb FROM login_history');
        $uniqueFingerprints = dbCount($base, 'SELECT COUNT(DISTINCT fingerprint) AS nb FROM login_history');

        $flagsByType = dbFetchAll($base, 'SELECT flag_type, COUNT(*) AS cnt FROM account_flags WHERE status IN (?, ?) GROUP BY flag_type ORDER BY cnt DESC', 'ss', 'open', 'investigating');
        ?>

        <h3>Statistiques</h3>
        <div class="stats">
            <div class="stat"><div class="number"><?php echo $totalFlags; ?></div><div class="label">Drapeaux total</div></div>
            <div class="stat"><div class="number" style="color:#c62828"><?php echo $openFlags; ?></div><div class="label">Ouverts</div></div>
            <div class="stat"><div class="number" style="color:#d32f2f"><?php echo $criticalFlags; ?></div><div class="label">Critiques actifs</div></div>
            <div class="stat"><div class="number"><?php echo $unreadAlerts; ?>/<?php echo $totalAlerts; ?></div><div class="label">Alertes non lues</div></div>
            <div class="stat"><div class="number"><?php echo $totalLogins; ?></div><div class="label">Connexions tracées</div></div>
            <div class="stat"><div class="number"><?php echo $uniqueIPs; ?></div><div class="label">IPs uniques</div></div>
            <div class="stat"><div class="number"><?php echo $uniqueFingerprints; ?></div><div class="label">Empreintes uniques</div></div>
        </div>

        <?php if (!empty($flagsByType)): ?>
        <div class="card">
            <h4>Drapeaux actifs par type</h4>
            <table>
                <tr><th>Type</th><th>Nombre</th></tr>
                <?php foreach ($flagsByType as $ft): ?>
                <tr>
                    <td><?php echo htmlspecialchars($ft['flag_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int)$ft['cnt']; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

    <?php elseif ($view === 'manual'): ?>
        <h3>Ajouter un drapeau manuel</h3>
        <div class="card">
            <form method="post" action="multiaccount.php?view=flags">
                <?php echo csrfField(); ?>
                <p><label>Compte 1: <input type="text" name="manual_login" required></label></p>
                <p><label>Compte 2: <input type="text" name="manual_related" required></label></p>
                <p><label>Note: <textarea name="manual_note" rows="3" placeholder="Raison du signalement..."></textarea></label></p>
                <p><button type="submit" class="btn btn-primary">Ajouter le drapeau</button></p>
            </form>
        </div>

    <?php elseif ($view === 'md5accounts'): ?>
        <!-- LOW-002: Identify accounts still using legacy MD5 hashes (not yet auto-upgraded) -->
        <h3>Comptes avec mot de passe MD5 (héritage)</h3>
        <p style="color:#c62828;"><strong>Information uniquement.</strong> Ces comptes utilisent encore un hash MD5. Ils seront mis à niveau automatiquement à leur prochaine connexion. Si un compte n'a pas été vu depuis longtemps, envisagez de contacter le joueur pour qu'il se reconnecte et change son mot de passe.</p>
        <?php
        $md5Accounts = dbFetchAll($base,
            "SELECT login, email, derniereConnexion FROM membre WHERE pass_md5 NOT LIKE '\$2y\$%' LIMIT 50"
        );
        if (empty($md5Accounts)):
        ?>
            <p style="color:#43a047;">Aucun compte MD5 trouvé — tous les mots de passe sont en bcrypt.</p>
        <?php else: ?>
            <p>Comptes trouvés : <?php echo count($md5Accounts); ?> (max 50 affichés)</p>
            <table>
                <tr><th>Login</th><th>Email</th><th>Dernière connexion</th></tr>
                <?php foreach ($md5Accounts as $acc): ?>
                <tr>
                    <td><?php echo htmlspecialchars($acc['login'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php // GDPR11-001: Mask email to comply with data minimization (admin doesn't need full address)
                        $parts = explode('@', $acc['email'] ?? '', 2);
                        $masked = (strlen($parts[0] ?? '') > 2 ? substr($parts[0], 0, 2) . '***' : '***') . (isset($parts[1]) ? '@' . $parts[1] : '');
                        echo htmlspecialchars($masked, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo $acc['derniereConnexion'] ? date('d/m/Y H:i', (int)$acc['derniereConnexion']) : 'Jamais'; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

    <?php endif; ?>

</body>
</html>
