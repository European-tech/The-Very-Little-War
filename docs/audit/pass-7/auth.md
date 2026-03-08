## Domain AUTH — Pass 7 Findings

| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 0 |
| MEDIUM | 4 |
| LOW | 4 |
| INFO | 1 |

### MEDIUM-001: Session cookie SameSite set to Lax instead of Strict
- **File:** includes/session_init.php:12
- **Suggested fix:** Change `'Lax'` to `'Strict'`

### MEDIUM-002: Email change does not verify legacy MD5 passwords
- **File:** compte.php:98
- **Suggested fix:** Add MD5 fallback same as password change handler

### MEDIUM-003: Session name uses PHP default (PHPSESSID), leaking server technology
- **File:** includes/session_init.php
- **Suggested fix:** Add `session_name('TVLW_SESSION');` before `session_start()`

### MEDIUM-004: comptetest.php is publicly accessible with no auth guard for visitor creation
- **File:** comptetest.php:10
- **Suggested fix:** Convert visitor creation from GET to POST with CSRF token

### LOW-001: Visitor account passwords are predictable (password equals username)
- **File:** comptetest.php:23-28
- **Suggested fix:** Use `bin2hex(random_bytes(8))` for visitor password

### LOW-002: Plaintext IP address stored in connectes table
- **File:** includes/basicprivatephp.php:79
- **Suggested fix:** Use `hashIpAddress($_SERVER['REMOTE_ADDR'])` in connectes INSERT

### LOW-003: Visitor account creation via GET request (no CSRF)
- **File:** comptetest.php:10
- **Suggested fix:** Convert to POST + CSRF

### LOW-004: Account deletion 7-day cooldown bypassable via deconnexion.php direct POST
- **File:** deconnexion.php:20-23
- **Suggested fix:** Add 7-day check in deconnexion.php or remove deletion logic from there
