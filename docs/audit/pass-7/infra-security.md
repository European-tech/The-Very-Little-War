## Domain INFRA-SECURITY — Pass 7 Findings

| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 0 |
| MEDIUM | 2 |
| LOW | 3 |
| INFO | 1 |

### MEDIUM-001: csrfCheck() does not validate same-origin Referer/Origin header
- **File:** includes/csrf.php:30-59
- **Suggested fix:** Add Origin/Referer same-origin check before token validation

### MEDIUM-002: validatePassword() missing from validation.php — logic duplicated in inscription.php and compte.php
- **File:** includes/validation.php
- **Suggested fix:** Extract to `validatePassword()` function, call from both sites

### LOW-001: validateEmail() has no EMAIL_MAX_LENGTH check
- **File:** includes/validation.php:6-8
- **Suggested fix:** Add `mb_strlen($email) <= EMAIL_MAX_LENGTH` inside validateEmail()

### LOW-002: Rate limiter file_put_contents failure fails open (not logged, returns true)
- **File:** includes/rate_limiter.php:53
- **Suggested fix:** Check return value, fail closed and log on failure

### LOW-003: Rate limiter TOCTOU race on concurrent requests (read-check-write not atomic)
- **File:** includes/rate_limiter.php:38-53
- **Suggested fix:** Use flock() around read-check-write cycle, or accept minor over-admission

### INFO-001: CSP style-src still includes 'unsafe-inline' (tracked, Framework7 dependency)
- **File:** includes/layout.php:15
