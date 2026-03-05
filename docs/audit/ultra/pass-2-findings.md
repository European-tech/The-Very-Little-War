# Pass 2 — Deep Dive: Consolidated Findings

**Date:** 2026-03-05
**Total New Findings:** ~355
**Domains:** 9

## Summary by Domain

| Domain | Findings | CRIT | HIGH | MED | LOW | INFO |
|--------|----------|------|------|-----|-----|------|
| D1 Security | 34 | 3 | 8 | 12 | 6 | 5 |
| D2 Code Quality | 52 | 2 | 6 | 19 | 25 | 0 |
| D3 Technology | 75 | 2 | 16 | 33 | 24 | 0 |
| D4 Game Balance | 39 | 3 | 11 | 16 | 9 | 0 |
| D5 Game Mechanics | 40 | 0 | 10 | 18 | 12 | 0 |
| D6 UX & Performance | 34 | 0 | 6 | 15 | 13 | 0 |
| D7 Database | 36 | 4 | 8 | 14 | 10 | 0 |
| D8 Features | 60 | 2 | 18 | 24 | 16 | 0 |
| D9 UI Visual Design | 47 | 0 | 8 | 22 | 17 | 0 |
| **Total** | **~417** | **16** | **91** | **173** | **132** | **5** |

Note: D9 agent also applied ~40 immediate CSS/HTML fixes during the audit.

## Top Critical Findings (Pass 2)

### Security
1. **P2-D1-001** CSRF redirect via Referer injection — open redirect on CSRF failure
2. **P2-D1-002** Resource transfer race condition — no transaction/FOR UPDATE, allows duplication
3. **P2-D1-003** Action processing race condition — double-credit via concurrent requests

### Code Quality
4. **P2-D2-001** `logError()` called with wrong arg count (1 vs 2) across 11 sites in combat.php — silently breaks all error logging
5. **P2-D2-002** `$activeReactionsAtt`/`$activeReactionsDef` never defined — dead code with PHP warnings

### Game Balance
6. **P2-D4-001** Buy-sell trade points arbitrage loop — 6x more efficient than combat for ranking
7. **P2-D4-002** Vault protection flat per-resource not percentage — inverts intended mechanic
8. **P2-D4-003** Traveling troops use home-base stats for decay — exploitable molecule reshaping

### Database
9. **P2-D7-001** `withTransaction()` catches Exception not Throwable — PHP Error bypasses rollback everywhere
10. **P2-D7-002** CAS `attaqueFaite=1` committed before transaction — permanent army loss on any Error
11. **P2-D7-003** `withTransaction()` single-line fix cascades to every transaction in codebase
12. **P2-D7-004** `ajouterPoints()` FOR UPDATE with no surrounding transaction — no-op lock

### Features
13. **P2-D8-001** Tutorial non-branching — players stuck if they deviate
14. **P2-D8-002** Season reset emotionally destructive — no ceremony, feels punishing

## Key Insights from Deep Dive

### Most Dangerous Attack Chain
**P2-D1-002 + P2-D1-003**: Resource transfer send and receive both lack transactional integrity. Two concurrent tabs can duplicate unlimited resources.

### Single Highest-Leverage Fix
**P2-D7-003**: Changing `catch (Exception $e)` to `catch (\Throwable $e)` in `withTransaction()` fixes rollback bypass across EVERY transaction in the codebase.

### Performance Root Cause
**P2-D6-001+002+003**: `initPlayer()` runs 2-5 times per page load, each call executing 60-80 DB queries. A typical page load performs 160-220 queries. With building completion, this spikes to 380-430 queries.

## Domain Report Files
- [Domain 1: Security](pass-2-domain-1-security.md)
- [Domain 2: Code Quality](pass-2-domain-2-code-quality.md)
- [Domain 3: Technology](pass-2-domain-3-technology.md)
- [Domain 4: Game Balance](pass-2-domain-4-balance.md)
- [Domain 5: Game Mechanics](pass-2-domain-5-mechanics.md)
- [Domain 6: UX & Performance](pass-2-domain-6-ux-performance.md)
- [Domain 7: Database](pass-2-domain-7-database.md)
- [Domain 8: Features](pass-2-domain-8-features.md)
- [Domain 9: UI Visual Design](pass-2-domain-9-ui-visual.md)

## Pass 2 Status: COMPLETE
Ready for Pass 3 — Cross-Domain Analysis
