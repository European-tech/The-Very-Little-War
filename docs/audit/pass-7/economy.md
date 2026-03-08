# Pass 7 Audit — ECONOMY Domain
**Date:** 2026-03-08
**Agent:** Pass7-B3-ECONOMY

## Summary
| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 0 |
| MEDIUM | 0 |
| LOW | 1 |
| INFO | 3 |
| **Total** | **4** |

**Overall Assessment:** CLEAN. All resource mutation paths properly enforce storage caps. No exploitable overflow conditions found.

---

## LOW Findings

### LOW-001 — Resource initialization uses SQL DEFAULT instead of config constants
**File:** `includes/player.php:106`
**Description:** New player resources use `INSERT INTO ressources (login) VALUES (?)` relying on SQL DEFAULT values instead of explicitly inserting `STARTING_ENERGY` (64) and `STARTING_ATOMS` (64) from config.php. If schema DEFAULT values are changed without updating PHP constants, initialization will silently diverge from documented values.
**Fix:**
```php
// Use explicit values from config.php constants
dbExecute($base, 'INSERT INTO ressources (login, energie, carbone, azote, hydrogene, oxygene, chlore, soufre, brome, iode) VALUES (?,?,?,?,?,?,?,?,?,?)',
    'siiiiiiii', $login, STARTING_ENERGY, STARTING_ATOMS, STARTING_ATOMS, STARTING_ATOMS, STARTING_ATOMS, STARTING_ATOMS, STARTING_ATOMS, STARTING_ATOMS, STARTING_ATOMS);
```
**Risk:** Documentation inconsistency only. Not exploitable.

---

## Verified Clean (INFO)

### INFO-001 — Energy & atom production correctly implements all V4 multipliers
All multipliers (duplicateur, iode catalyst, medal bonus, prestige, resource nodes, compounds, specialization) reference config.php constants. No magic numbers. Formula verified against docs/game/09-BALANCE.md. CLEAN.

### INFO-002 — Resource overflow prevented on ALL write paths
Verified overflow prevention on 9 distinct write paths:
1. **updateRessources:** `LEAST(GREATEST(0, $resource + $delta), $placeMax)` — atomic guard
2. **Market buy:** FOR UPDATE + re-read storage cap inside tx, exception on overflow
3. **Market sell:** energy capped explicitly before update
4. **Transfers (send):** `GREATEST(0, $resource - $amount)` guard
5. **Transfers (receive):** `min($maxStorageRecv, $current + $received)`
6. **Donations:** atomic increment on alliance energy
7. **Combat loot:** `min($maxStorageAtt, $current + $loot)`
8. **Combat reward:** `min($maxEnergy, $current + $reward)`
9. **Compound synthesis:** `GREATEST($resource - $cost, 0)`
All paths CLEAN.

### INFO-003 — Transaction safety & race conditions properly guarded
- **CAS guard:** `updateRessources()` uses `tempsPrecedent` atomic check — prevents double-resource grants
- **FOR UPDATE locks** with consistent lock ordering (prevents deadlocks):
  - Market buy: ressources → constructions → cours
  - Market sell: ressources → constructions → cours
  - Transfer send: ressources
  - Transfer receive: actionsenvoi → ressources → constructions
  - Donation: ressources → autre → alliances
- **Deadlock retry:** 3 attempts on market buy/sell with backoff
All paths CLEAN.
