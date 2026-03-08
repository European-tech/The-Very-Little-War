## Domain ANTI_CHEAT — Pass 7 Findings

| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 0 |
| MEDIUM | 5 |
| LOW | 3 |
| INFO | 1 |

### MEDIUM-001: Raw User-Agent stored in login_history despite IP hashing (GDPR data minimization)
- **File:** includes/multiaccount.php:45
- **Suggested fix:** Hash UA before storage or truncate to coarse browser family

### MEDIUM-002: createAdminAlert() has no deduplication — alert flood risk
- **File:** includes/multiaccount.php:301-306
- **Suggested fix:** Add 24h dedup check before INSERT in createAdminAlert()

### MEDIUM-003: checkSameFingerprintAccounts() asymmetric dedup allows duplicate flags
- **File:** includes/multiaccount.php:111-113
- **Suggested fix:** Add symmetric OR check like checkSameIpAccounts()

### MEDIUM-004: checkCoordinatedAttacks() asymmetric dedup allows duplicate flags + emails
- **File:** includes/multiaccount.php:154-157
- **Suggested fix:** Apply symmetric OR pattern

### MEDIUM-005: areFlaggedAccounts() not called on don.php (alliance donation bypass)
- **File:** don.php
- **Suggested fix:** Add areFlaggedAccounts() check in don.php before processing donation

### LOW-001: Detection functions synchronous on login path (latency)
- **File:** includes/multiaccount.php:48-50

### LOW-002: admin/ip.php leaks raw IP in GET URL/logs
- **File:** admin/ip.php:18-19
- **Suggested fix:** Accept IP via POST, add FILTER_VALIDATE_IP

### LOW-003: checkCoordinatedAttacks uses time() instead of $timestamp for flag created_at
- **File:** includes/multiaccount.php:169

### INFO-001: Fingerprint entropy too low (UA + Accept-Language only)
- **File:** includes/multiaccount.php:40
