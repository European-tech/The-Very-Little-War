# Pass 5 — Verification & Gap-Fill: Consolidated Findings

**Date:** 2026-03-05
**Focus Areas:** 2 (Critical Finding Verification, Coverage Gap Analysis)

## Verification Results

Of 15 critical findings verified against actual source code:

| Status | Count | Finding IDs |
|--------|-------|-------------|
| CONFIRMED | 6 | P2-D7-003, P4-ADV-001, P4-ADV-004, P4-EC-001, P2-D7-002, P4-ADV-003 |
| FALSE_POSITIVE | 6 | P2-D1-002*, P2-D1-003, P3-XD-003, P2-D2-001, P2-D4-001, P4-ADV-010 |
| PARTIALLY_FIXED | 2 | P3-EB-001, P2-D1-001 |
| CONFIRMED (edge) | 1 | P4-EC-003 |

**False positive rate: 40%** — typical for multi-pass audits that layer findings.

*P2-D1-002 severity overstated but resource transfer still lacks proper transaction (see P5-GAP-002)

### Key False Positives Explained
- **P3-XD-003** "Combat wrong stat column" — pointsProducteur is INTENTIONAL (not a bug)
- **P2-D2-001** "logError wrong args" — code is unreachable in current flow
- **P2-D4-001** "Trade arbitrage" — sell tax already prevents profitable cycling
- **P4-ADV-010** "BBCode javascript:" — parser already requires https:// prefix

## Gap Analysis

**New Findings from Pass 5:** 38 (3 CRITICAL, 12 HIGH, 15 MEDIUM, 8 LOW)
**Verification Gaps Found:** 4

### Critical Gaps (re-confirmed as unfixed)
1. **P5-GAP-001** `withTransaction()` catches Exception not Throwable — affects ALL 15-20 transactions
2. **P5-GAP-002** Resource transfer (marche.php sub=1) has NO transaction wrapper
3. **P5-GAP-003** Compound synthesis TOCTOU race in compounds.php

### Notable NEW Findings
4. **P5-GAP-005** (HIGH) admin/listenews.php calls csrfCheck() on GET — breaks page
5. **P5-GAP-008** (HIGH) messageCommun.php admin check uses wrong session namespace
6. **P5-GAP-014** (HIGH) editer.php hide/show has NO authorization check — any player can hide forum posts
7. **P5-GAP-011** (HIGH) Producteur/condenseur allocation in constructions.php not transactional
8. **GAP-004** (MED) Concurrent attacks on same defender corrupt molecule counts (no FOR UPDATE)

### Coverage Blind Spots
7 files never reviewed in any pass:
- comptetest.php, moderationForum.php, includes/cardsprivate.php
- includes/statistiques.php, includes/atomes.php, admin/tableau.php, tools/balance_simulator.php

### Lowest Confidence Domain
**Transaction integrity** — ~50-60% coverage. The Throwable fix alone cascades everywhere.

## Combined Pass Statistics

| Pass | Total | CRIT | HIGH | MED | LOW | False Positives |
|------|-------|------|------|-----|-----|-----------------|
| Pass 1 | 581 | 7 | 122 | 278 | 174 | — |
| Pass 2 | ~417 | 16 | 91 | 173 | 132 | — |
| Pass 3 | 81 | 10 | 29 | 30 | 12 | — |
| Pass 4 | 65 | 8 | 24 | 19 | 14 | — |
| Pass 5 | 42 | 3 | 12 | 15 | 8 | 6 removed |
| **Total** | **~1,186** | **44** | **278** | **515** | **340** | **6** |

## Top 6 Confirmed Critical Fixes (Priority Order)
1. `withTransaction()` → catch `\Throwable` (1 line, fixes rollback bypass everywhere)
2. Resource transfer → wrap in transaction with FOR UPDATE (marche.php sub=1)
3. Compound synthesis → move resource check inside transaction with FOR UPDATE
4. Add MAX_BUILDING_LEVEL constant and enforcement
5. Division by zero guards in player.php storage display
6. tempsFormation division by zero guard in formulas.php

## Domain Report Files
- [Verification Report](pass-5-verification.md)
- [Gap Analysis](pass-5-gaps.md)

## Pass 5 Status: COMPLETE
All 5 Passes Complete — Ready for Consolidation & Remediation
