## Domain SEASON_RESET — Pass 7 Findings

| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 0 |
| MEDIUM | 2 |
| LOW | 3 |
| INFO | 3 |

### MEDIUM-001: War archive query uses wrong ID column (declarations.id instead of alliance ID)
- **File:** includes/player.php:1096
- **Code:** `dbFetchAll($base, 'SELECT login FROM autre WHERE idalliance = ?', 'i', $data['id']);`
- **Bug:** $data comes from declarations table; $data['id'] is war declaration ID, not alliance ID
- **Impact:** War history archive silently empty every season — $nbjoueurs always 0
- **Suggested fix:** Use `$data['alliance1']` and `$data['alliance2']` instead

### MEDIUM-002: remiseAZero() single large transaction may block concurrent writes (acceptable under maintenance gate)
- **File:** includes/player.php:1297-1358
- **Impact:** Mitigated by maintenance gate; acceptable risk

### LOW-001: Archive strings in parties table lack closing ] bracket delimiter
- **File:** includes/player.php:1070,1086,1099
- **Suggested fix:** Append ']' to each record concatenation

### LOW-002: Stale revenu* columns (8 atom revenue) not reset (dead/unused columns)
- **File:** includes/player.php:1317

### LOW-003: awardPrestigePoints() season number offset by +1 vs season_recap (confusing but not broken)
- **File:** includes/prestige.php:118
