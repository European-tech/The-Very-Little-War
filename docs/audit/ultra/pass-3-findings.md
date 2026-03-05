# Pass 3 — Cross-Domain Analysis: Consolidated Findings

**Date:** 2026-03-05
**Total New Findings:** 81
**Focus Areas:** 3 (Exploit Chains, Emergent Balance, Architecture)

## Summary

| Area | Findings | CRIT | HIGH | MED | LOW |
|------|----------|------|------|-----|-----|
| Cross-Domain Security (XD) | 25 | 5 | 9 | 8 | 3 |
| Emergent Balance (EB) | 28 | 2 | 9 | 9 | 8 |
| Architecture (AR) | 28 | 3 | 11 | 13 | 1 |
| **Total** | **81** | **10** | **29** | **30** | **12** |

## Top Critical Findings

### Exploit Chains (Security)
1. **P3-XD-001** Unlimited resource duplication via concurrent tab race
2. **P3-XD-002** Duped atoms → market cycling → infinite energy + ranking
3. **P3-XD-003** ALL combat uses wrong stat column + silenced errors = every fight broken
4. **P3-XD-004** Duped resources → alliance duplicateur → +100% stats for all members
5. **P3-XD-005** Throwable gap + CAS guard = permanent army loss on TypeError

### Emergent Balance
6. **P3-EB-001** 7 multiplicative combat modifiers stack to 4.21x (vs new player 1.0x)
7. **P3-EB-002** Production bonus chain = 5.81x base at full stack

### Architecture
8. **P3-AR-001+002** 50+ globals from initPlayer() pollute combat scope
9. **P3-AR-004** Throwable gap cascades through every transaction
10. **P3-AR-011** Session file locks serialize all same-user requests

## Three Root Causes Identified
1. **Multiplicative stacking** — bonuses across systems never analyzed together
2. **No progression limits** — no catch-up mechanics, advantage compounds
3. **Transaction boundary gaps** — critical state mutations outside transactions

## Remediation Priority (from Pass 3)
1. `withTransaction()` → catch `\Throwable` (blocks 3 CRITICAL chains, 1 hour)
2. Resource transfer → wrap in transaction with FOR UPDATE (blocks 3 CRITICAL chains)
3. Fix combat.php: `pointsCondenseur` not `pointsProducteur` (fixes ALL combat)
4. Add `session_write_close()` after session reads (blocks serialization)
5. Cap total combat modifier at 2.5x (fixes balance)
6. Convert production bonuses to additive (fixes snowball)

## Domain Report Files
- [Cross-Domain Security](pass-3-cross-domain.md)
- [Emergent Balance](pass-3-emergent-balance.md)
- [Architecture](pass-3-architecture.md)

## Pass 3 Status: COMPLETE
Ready for Pass 4 — Edge Cases & Adversarial
