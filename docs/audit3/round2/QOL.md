# QoL Deep-Dive — Round 2

**Auditor:** UX Research Agent
**Date:** 2026-03-03
**Scope:** Deep player journey analysis across 10 core pages
**Round 1 cross-reference:** All R1 items reviewed; no duplicates in this report.

## Summary
Total findings: 46 (2 CRITICAL, 19 HIGH, 17 MEDIUM, 8 LOW)

## Player Journey Analysis

Five journeys mapped:
- J1: New player first 10 minutes
- J2: Daily active player routine
- J3: Alliance leader tasks
- J4: Market trader workflow
- J5: Competitive player optimization loop

---

## Journey 1: New Player — First 10 Minutes

### QOL-R2-001 [HIGH] No post-login redirect to tutorial for first-time players
**File:** index.php:4-8 + basicprivatephp.php
**Issue:** New player logs in and sees the same marketing page as everyone else. No auto-redirect to tutoriel.php when niveaututo==1.
**Fix:** Add redirect in basicprivatephp.php: `if ($autre['niveaututo'] == 1) header('Location: tutoriel.php?nouveau=1');`

### QOL-R2-002 [HIGH] Login form renders below marketing cards on mobile
**File:** index.php:56-121
**Issue:** After registration, the "inscrit" success message is at top but login form is buried below news accordion.
**Fix:** Auto-focus loginConnexion input on ?inscrit=1.

### QOL-R2-003 [HIGH] Three competing tutorial systems with no clear primary path
**Files:** tutoriel.php (7 missions), basicprivatehtml.php (19 missions), cardsprivate.php (10 niveaututo steps)
**Issue:** New player sees competing instructions from header panel, sidebar, and tutorial page simultaneously.
**Fix:** Serialize: niveaututo < 10 shows ONLY sequential tutorial; $listeMissions gated to niveaututo >= 10.

### QOL-R2-004 [MEDIUM] Mission 5 conflates profile editing and market discovery
**File:** tutoriel.php:91-106
**Fix:** Split into two separate missions.

### QOL-R2-005 [MEDIUM] Mission 6 espionage LIKE pattern is fragile
**File:** tutoriel.php:113-114
**Fix:** Check actionsattaques table directly instead of report title pattern.

### QOL-R2-006 [MEDIUM] All seven tutorial rewards are identical (500 energy)
**File:** tutoriel.php:15-150
**Fix:** Escalating rewards: 200, 300, 300, 500, 400, 700, 1000 (total 3400 vs current 3500).

### QOL-R2-007 [LOW] "Comprendre le jeu" section duplicates niveaututo content
**File:** tutoriel.php:394-490

---

## Journey 2: Daily Active Player Routine

### QOL-R2-008 [HIGH] No bottleneck identification in building cost display
**File:** constructions.php:162-166
**Issue:** Shows "enough resources on date" but doesn't say which atom is the bottleneck.
**Fix:** Track which resource has longest wait time and display it.

### QOL-R2-009 [HIGH] Timer completion auto-reloads destroy player context
**Files:** constructions.php:317-328, armee.php:282-300
**Issue:** setInterval fires document.location.href on timer end, resetting scroll, closing accordions, erasing form input.
**Fix:** Use AJAX poll or batch reload after idle timeout.

### QOL-R2-010 [HIGH] Army overview tab requires ?sub=1 URL with no visible button
**File:** armee.php:340-445
**Fix:** Add tab switcher button at top of page.

### QOL-R2-011 [MEDIUM] Molecule count uses ceil() but formation uses floor() — mismatch
**File:** armee.php:354-360
**Fix:** Use floor() consistently for display.

### QOL-R2-012 [MEDIUM] Attack cost calculator doesn't update when field is cleared
**File:** attaquer.php:435-490
**Fix:** Always call actualiseTemps() on input regardless of value.

### QOL-R2-013 [MEDIUM] Incoming attack notification has no time estimate or auto-refresh
**File:** attaquer.php:247-249
**Fix:** Show actual tempsRetour or add AJAX poll.

### QOL-R2-014 [MEDIUM] Report list has broken HTML (missing `<tr>` tags)
**File:** rapports.php:63-73

### QOL-R2-015 [LOW] Delete-all reports button mixed with pagination
**File:** rapports.php:75, 110

---

## Journey 3: Alliance Leader Tasks

### QOL-R2-016 [HIGH] No mechanism to communicate donation goals to members
**File:** alliance.php:264-320
**Fix:** Add alliance_message TEXT column to alliances table, editable by chef/grades.

### QOL-R2-017 [HIGH] Alliance research effects hidden inside accordion
**File:** alliance.php:291-318
**Fix:** Show active bonus percentage in the collapsed subtitle.

### QOL-R2-018 [HIGH] Duplicateur description has copy-paste error ("de de")
**File:** alliance.php:271-278
**Fix:** Clarify production vs combat bonuses separately.

### QOL-R2-019 [MEDIUM] Alliance rank uses O(n) PHP loop instead of SQL COUNT
**File:** alliance.php:170-178
**Fix:** `SELECT COUNT(*) FROM alliances WHERE pointstotaux > ?`

### QOL-R2-020 [MEDIUM] No war/pact declaration button on alliance view page
**File:** alliance.php
**Fix:** Add quick-action links for chef/grades.

### QOL-R2-021 [LOW] Donation percentage column has no unit label
**File:** alliance.php:379-383

---

## Journey 4: Market Trader Workflow

### QOL-R2-022 [HIGH] Google Charts loaded without SRI hash
**File:** marche.php:560
**Fix:** Self-host loader.js or add integrity attribute.

### QOL-R2-023 [HIGH] Market chart X-axis uses empty strings — no timestamps
**File:** marche.php:577-592
**Fix:** Add date() formatting to labels, show every 50th point.

### QOL-R2-024 [HIGH] No post-sale price preview for sellers
**File:** marche.php:255-363
**Fix:** JS preview computing post-sale price using same formula as server.

### QOL-R2-025 [MEDIUM] In-transit shipments show no resource breakdown
**File:** marche.php:372-409
**Fix:** Parse and display ressourcesEnvoyees column.

### QOL-R2-026 [MEDIUM] Buy form reverse energy field has no label explaining its function
**File:** marche.php:460-465
**Fix:** Add placeholder text.

### QOL-R2-027 [LOW] Sell success message hides fee amount
**File:** marche.php:341-342
**Fix:** Show "500 de frais, 5%" instead of just "5% de frais".

---

## Journey 5: Competitive Player Optimization Loop

### QOL-R2-028 [HIGH] Medal progress shows raw numbers with no visual bar
**File:** medailles.php:49-50
**Issue:** progressBar() function exists and is used in constructions.php but never called here.
**Fix:** Call existing progressBar() function.

### QOL-R2-029 [HIGH] Explosif/Aléatoire medals have no explanation of how to earn them
**File:** medailles.php:42-43
**Fix:** Add description strings to medal list.

### QOL-R2-030 [HIGH] PP column in classement unexplained, no link to prestige system
**File:** classement.php:163-165
**Fix:** Link PP header to prestige.php, use distinct icon.

### QOL-R2-031 [HIGH] Prestige unlocks exist in code but no purchase UI
**File:** includes/prestige.php:10-41
**Issue:** 5 unlocks implemented, purchasePrestigeUnlock() works, but no page calls it.
**Fix:** Create prestige.php page.

### QOL-R2-032 [MEDIUM] Default Points sort column has no clickable link
**File:** classement.php:157-164
**Fix:** Add `<a href>` on Points column header.

### QOL-R2-033 [MEDIUM] Season end countdown absent from all pages
**Fix:** Add days/hours countdown in header.

### QOL-R2-034 [MEDIUM] Password change form doesn't show current email first
**File:** compte.php:151-161

### QOL-R2-035 [MEDIUM] Vacation mode doesn't explain attack protection benefit
**File:** compte.php:188-195
**Fix:** Add benefit list.

### QOL-R2-036 [LOW] Account deletion uses image-only buttons (no text)
**File:** compte.php:237-242

---

## Cross-Cutting Issues

### QOL-R2-037 [HIGH] $_SESSION['start'] assignments serve no purpose — dead code
**Files:** index.php:3, classement.php:2, joueur.php
**Fix:** Remove all occurrences.

### QOL-R2-038 [HIGH] No navigation badges for pending construction/formation completions
**Fix:** Add badge counts in menu using existing initPlayer() data.

### QOL-R2-039 [MEDIUM] Isotope system never displayed after molecule creation
**File:** armee.php:321-335
**Fix:** Show isotope label next to molecule formula.

### QOL-R2-040 [MEDIUM] Chemical reactions defined in config.php but never activate in combat
**File:** config.php:312-344
**Fix:** Phase 1: display on tutoriel.php; Phase 2: implement in combat.php.

### QOL-R2-041 [MEDIUM] Specialization system defined but has no UI
**File:** config.php:570-629
**Fix:** Add specialization section to constructions.php or compte.php.

### QOL-R2-042 [MEDIUM] Map renders all N×N tiles including empty ones
**File:** attaquer.php:329-365
**Fix:** Render only occupied tiles with CSS grid background.

### QOL-R2-043 [LOW] Viewing rival medals shows no comparison to own medals
**File:** medailles.php:5-87

### QOL-R2-044 [LOW] War history includes trivial 1-molecule wars
**File:** classement.php:420

### QOL-R2-045 [LOW] Active defensive formation has minimal visual distinction
**File:** constructions.php:336-350
**Fix:** Highlight active formation with colored border.

### QOL-R2-046 [CRITICAL] No prestige.php page exists — entire progression system unreachable
**File:** includes/prestige.php
**Issue:** 5 unlocks, purchase logic, PP awards all implemented. Zero UI. Players cannot interact with the system at all.
**Fix:** Create prestige.php page with PP display, unlock shop, and history.

---

## Top 15 Priority Fixes

| Rank | ID | Finding |
|---|---|---|
| 1 | QOL-R2-046 | Prestige system entirely unreachable |
| 2 | QOL-R2-031 | Prestige unlocks no purchase UI |
| 3 | QOL-R2-003 | Three competing tutorial systems |
| 4 | QOL-R2-038 | No navigation badges |
| 5 | QOL-R2-001 | No auto-redirect for new players |
| 6 | QOL-R2-030 | PP column unexplained |
| 7 | QOL-R2-040 | Chemical reactions never activate |
| 8 | QOL-R2-041 | Specialization system no UI |
| 9 | QOL-R2-009 | Timer reloads destroy context |
| 10 | QOL-R2-023 | Market chart no time axis |
| 11 | QOL-R2-016 | No alliance donation goals |
| 12 | QOL-R2-010 | Army overview hidden |
| 13 | QOL-R2-029 | Medals unexplained |
| 14 | QOL-R2-028 | No progress bars on medals |
| 15 | QOL-R2-022 | Google Charts no SRI |

## Quick Wins (< 1 hour each)

1. QOL-R2-001 — niveaututo redirect (5 lines)
2. QOL-R2-028 — Call existing progressBar() (10 lines)
3. QOL-R2-032 — Add sort link on Points column (2 lines)
4. QOL-R2-023 — Add date() to chart labels (3 lines)
5. QOL-R2-035 — Vacation mode benefit text (5 lines)
6. QOL-R2-017 — Research effect outside accordion (3 lines)
7. QOL-R2-045 — Highlight active formation (1 line)
8. QOL-R2-019 — SQL COUNT instead of PHP loop (3 lines)
