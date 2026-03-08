## Domain FORUM — Pass 7 Findings

| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 0 |
| MEDIUM | 3 |
| LOW | 5 |
| INFO | 1 |

### MEDIUM-001: Rate limit check runs before login check in listesujets.php
- **File:** listesujets.php:45
- **Suggested fix:** Move rateLimitCheck inside isset($_SESSION['login']) block

### MEDIUM-002: Moderators see topic edit form but cannot save (type=1 has no moderator path)
- **File:** editer.php:71-83
- **Suggested fix:** Add moderator path for type=1 like the type=2 moderator path

### MEDIUM-003: editer.php delete/hide/show (type=3/4/5) missing alliance-private forum access check
- **File:** editer.php:24-37
- **Suggested fix:** Add alliance-private check to types 3, 4, 5 as done in type=2

### LOW-001: [url] label escaping confirmed safe (no fix needed)
### LOW-002: BBCode ban motif has no rendering-side length cap (2000-char input limit is sufficient)
### LOW-003: admin/supprimerreponse.php does not decrement nbMessages counter
- **File:** admin/supprimerreponse.php:10
- **Suggested fix:** Decrement nbMessages after delete like editer.php:32

### LOW-004: admin/listesujets.php topic deletion does not decrement author nbMessages
- **File:** admin/listesujets.php:38-41
- **Suggested fix:** Fetch all reply authors, decrement nbMessages within transaction

### LOW-005: No rate limit on editer.php POST actions (edit/delete/hide/show)
- **File:** editer.php:59
- **Suggested fix:** Add rateLimitCheck('forum_edit', 10, 300)

### INFO-001: Banned moderator gets confusing error instead of ban notification
- **File:** editer.php:14-18
