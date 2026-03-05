# Pass 4 — Edge Cases & Adversarial: Consolidated Findings

**Date:** 2026-03-05
**Total New Findings:** 65
**Focus Areas:** 2 (Adversarial Exploits, Edge Case Math)

## Summary

| Area | Findings | CRIT | HIGH | MED | LOW |
|------|----------|------|------|-----|-----|
| Adversarial Exploits (ADV) | 32 | 4 | 12 | 9 | 7 |
| Edge Case Math (EC) | 33 | 4 | 12 | 10 | 7 |
| **Total** | **65** | **8** | **24** | **19** | **14** |

## Top Critical Findings

### Adversarial Exploits
1. **P4-ADV-001** Compound synthesis TOCTOU: Double-spend race condition (two tabs → 2 compounds for price of 1)
2. **P4-ADV-002** Resource transfer still lacks transaction wrapper → unlimited duplication
3. **P4-ADV-003** No self-transfer validation → self-trade points farming
4. **P4-ADV-004** No MAX_BUILDING_LEVEL cap → buildings upgradeable infinitely

### Edge Case Math
5. **P4-EC-001** Division by zero in storage-full time display when net energy = 0
6. **P4-EC-002** coefDisparition medal cap bypass: sqrt(-negative) → NaN propagation
7. **P4-EC-003** tempsFormation division by zero when no condenseur
8. **P4-EC-004** Integer overflow in combat mass calculation at extreme army sizes

## Key HIGH Findings

### Adversarial
- **P4-ADV-005** Alliance join/leave cycling exploits cooldown gaps
- **P4-ADV-006** Market buy with 0 quantity accepted (free trade points)
- **P4-ADV-007** Resource node proximity bonus stacks without diminishing returns
- **P4-ADV-008** Compound buff timers persist across season resets
- **P4-ADV-009** Multi-account detection trivially bypassed (clear localStorage)
- **P4-ADV-010** Forum BBCode [url] allows javascript: protocol
- **P4-ADV-011** Rate limiter key collision on shared IPs
- **P4-ADV-012** Vacation mode grants free production accumulation
- **P4-ADV-013** Army recall during combat → ghost army exploit
- **P4-ADV-014** Concurrent alliance operations race condition
- **P4-ADV-015** Tutorial skip exploit via direct URL navigation
- **P4-ADV-016** Map coordinate prediction for strategic placement

### Edge Cases
- **P4-EC-005** log(0) in sqrt ranking when all players have 0 points
- **P4-EC-006** Negative time display when buildings finish before page refresh
- **P4-EC-007** Float precision loss in market price calculations
- **P4-EC-008** Molecule class formula edge case at exactly 0 atoms
- **P4-EC-009** Vault protection underflow at minimum deposit
- **P4-EC-010** Catalyst bonus multiplied by 0 when no active catalyst
- **P4-EC-011** Alliance bonus formula NaN when 1 member
- **P4-EC-012** Combat damage rounding allows 0-damage attacks indefinitely
- **P4-EC-013** Production formula overflow at building level > 100
- **P4-EC-014** Season timer display wraps negative after season end
- **P4-EC-015** Medal threshold formula edge case at exactly boundary values
- **P4-EC-016** Trade points calculation truncation loses fractional points

## Three Root Causes Identified
1. **Missing input boundary checks** — formulas assume positive non-zero values, never validated
2. **TOCTOU in all resource mutations** — read-check-modify pattern without FOR UPDATE
3. **No server-side caps on progression** — building levels, bonuses, army sizes uncapped

## Remediation Priority (from Pass 4)
1. Guard all division operations with denominator > 0 checks (blocks 2 CRITICAL, 1 hour)
2. Add FOR UPDATE + transaction to compound synthesis (blocks P4-ADV-001)
3. Add self-transfer check in transfert.php (blocks P4-ADV-003, 10 min)
4. Add MAX_BUILDING_LEVEL constant + enforcement (blocks P4-ADV-004, 30 min)
5. Validate quantity > 0 in all market operations (blocks P4-ADV-006)
6. Strip javascript: from BBCode [url] handler (blocks P4-ADV-010)

## Domain Report Files
- [Adversarial Exploits](pass-4-adversarial.md)
- [Edge Case Math](pass-4-edge-cases.md)

## Pass 4 Status: COMPLETE
Ready for Pass 5 — Verification & Gap-Fill
