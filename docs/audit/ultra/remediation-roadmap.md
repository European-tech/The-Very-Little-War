# Ultra Audit — Remediation Roadmap

**Date:** 2026-03-05
**Audit Passes Completed:** 5/5
**Total Findings:** ~1,186 (across all passes, pre-dedup)
**False Positives Removed:** 6 (from Pass 5 verification)
**Confirmed Actionable Fixes:** 24 (prioritized below)

## Sprint ALPHA — Critical Fixes (3 items, ~65 min)

| # | Finding | File | Fix | Est |
|---|---------|------|-----|-----|
| 1 | P5-GAP-001 | database.php:117 | catch Exception → catch \Throwable | 5m |
| 2 | P5-GAP-002 | marche.php:84-127 | Wrap resource transfer in withTransaction + FOR UPDATE | 30m |
| 3 | P5-GAP-003 | compounds.php:49-91 | Move resource check inside transaction + FOR UPDATE | 20m |

## Sprint BETA — High Priority (12 items, ~120 min)

| # | Finding | File | Fix | Est |
|---|---------|------|-----|-----|
| 4 | P2-D7-002 | game_actions.php:87-360 | Move CAS inside combat transaction | 15m |
| 5 | GAP-001 | game_actions.php:357 | catch Exception → catch \Throwable (combat) | 5m |
| 6 | GAP-004 | combat.php | Add FOR UPDATE on defender molecule reads | 15m |
| 7 | P4-EC-001 | player.php:324 | Guard division by zero in storage display | 10m |
| 8 | P4-ADV-004 | game_actions.php + config.php | Add MAX_BUILDING_LEVEL constant + enforcement | 15m |
| 9 | P5-GAP-005 | admin/listenews.php | Wrap csrfCheck() in POST check | 5m |
| 10 | P5-GAP-009 | voter.php | Remove GET vote support | 5m |
| 11 | P5-GAP-014 | editer.php | Add moderator check for type 4/5 operations | 10m |
| 12 | P5-GAP-004 | attaquer.php | Wrap espionage in transaction | 10m |
| 13 | P5-GAP-008 | messageCommun.php | Fix admin session namespace check | 10m |
| 14 | P5-GAP-011 | constructions.php | Add transaction + FOR UPDATE to point allocation | 15m |
| 15 | P5-GAP-010 | rapports.php | Fix XSS via report content sanitization | 10m |

## Sprint GAMMA — Medium Priority (9 items, ~70 min)

| # | Finding | File | Fix | Est |
|---|---------|------|-----|-----|
| 16 | P4-EC-003 | formulas.php | Guard tempsFormation division by zero | 5m |
| 17 | P4-ADV-003 | marche.php | Add self-transfer check | 5m |
| 18 | P2-D1-001 | csrf.php | Validate redirect is same-origin | 10m |
| 19 | P5-GAP-021 | compounds.php | Wrap activateCompound in transaction | 10m |
| 20 | P5-GAP-023 | sujet.php | Check topic lock before reply | 5m |
| 21 | P5-GAP-006 | admin/supprimercompte.php | Add input validation + confirmation | 10m |
| 22 | P5-GAP-007 | moderation/index.php | Cap resource grant + wrap in transaction | 15m |
| 23 | P5-GAP-012 | maintenance.php | Sanitize news content event handlers | 5m |
| 24 | P5-GAP-035 | .htaccess | Block backup file extensions | 5m |

## Total Estimated Time: ~255 minutes (~4.25 hours)

## Execution Order
1. Sprint ALPHA first (blocks most exploit chains)
2. Sprint BETA (high-impact security + data integrity)
3. Sprint GAMMA (defense-in-depth + edge cases)
4. Run full test suite after each sprint
5. Final CI validation
