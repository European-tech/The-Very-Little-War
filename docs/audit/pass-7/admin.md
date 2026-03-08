## Domain ADMIN — Pass 7 Findings

| Severity | Count |
|----------|-------|
| CRITICAL | 1 |
| HIGH | 0 |
| MEDIUM | 4 |
| LOW | 4 |
| INFO | 0 |

### CRITICAL-001: moderation/ip.php auth gate broken — includes non-existent file, page runs unauthenticated
- **File:** moderation/ip.php:3
- **Code:** `include("redirectionmotdepasse.php");` — file does not exist in moderation/, correct file is mdp.php
- **Impact:** Any unauthenticated user can enumerate player accounts by IP address
- **Suggested fix:** Change to `require_once(__DIR__ . '/mdp.php');`

### MEDIUM-002: admin/index.php IP-batch deletion missing consolidated audit log entry
- **File:** admin/index.php:59-66
- **Suggested fix:** Add logInfo('ADMIN', 'Batch IP deletion', ...) after transaction

### MEDIUM-003: moderation/index.php forum thread deletion not wrapped in transaction (3 separate DELETEs)
- **File:** moderation/index.php:82-87
- **Suggested fix:** Wrap in withTransaction()

### MEDIUM-004: maintenance.php uses regex-based HTML sanitization (bypass risk) instead of htmlspecialchars
- **File:** maintenance.php:37-48
- **Suggested fix:** Use htmlspecialchars() + nl2br() or HTMLPurifier

### MEDIUM-005: admin/index.php nested withTransaction() in IP-batch deletion may commit prematurely
- **File:** admin/index.php:59-63
- **Impact:** If withTransaction() lacks savepoint support, partial batch deletion may not roll back
- **Suggested fix:** Verify savepoint support or refactor supprimerJoueur() to accept in-transaction flag

### LOW-006: admin/listenews.php missing CSP headers
- **File:** admin/listenews.php:1-5
- **Suggested fix:** Add csp.php include and CSP header

### LOW-007: admin/redigernews.php missing CSP headers
- **File:** admin/redigernews.php:1-4

### LOW-008: moderation/index.php echoes $erreur variable containing raw HTML (fragile)
- **File:** moderation/index.php:218-219

### LOW-009: admin/supprimercompte.php TOCTOU between existence check and deletion
- **File:** admin/supprimercompte.php:26-28
