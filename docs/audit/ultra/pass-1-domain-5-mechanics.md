# Ultra Audit Pass 1 — Domain 5: Game Mechanics & Consistency

**Date:** 2026-03-05
**Pass:** 1 (Broad Scan)
**Subagents:** 5 (Action Queue, Combat Logic, Code vs Docs, Edge Cases, Test Coverage)

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 1 |
| HIGH | 18 |
| MEDIUM | 30 |
| LOW | 10 |
| **Total** | **59** |

---

## Area 1: Action Queue & Timing Bugs (19 findings)

#### P1-D5-001: Construction queue limit bypass via race condition
- **Severity:** HIGH — **Location:** constructions.php:236-239
- **Description:** MAX_CONCURRENT_CONSTRUCTIONS check is non-atomic read-check-insert. Two concurrent POSTs can both pass the limit.
- **Fix:** Atomic INSERT with inline count guard or transactional lock — **Effort:** S

#### P1-D5-002: Espionage action has no CAS guard — double processing possible
- **Severity:** HIGH — **Location:** game_actions.php:361-481
- **Description:** Combat uses attaqueFaite CAS but espionage branch has no equivalent guard. Two concurrent requests can generate duplicate spy reports.
- **Fix:** Add `UPDATE SET attaqueFaite=1 WHERE attaqueFaite=0` before espionage branch — **Effort:** XS

#### P1-D5-003: Formation query fetches all in-progress formations, not just completed
- **Severity:** HIGH — **Location:** game_actions.php:45
- **Description:** Query uses `debut < time()` instead of `fin < time()`. Creates unnecessary lock contention.
- **Fix:** Add `nombreRestant > 0` guard; separate completed from in-progress queries — **Effort:** S

#### P1-D5-004: Return-trip processing not gated behind attaqueFaite=1
- **Severity:** HIGH — **Location:** game_actions.php:484-523
- **Description:** Return block can execute before combat resolves if server was offline. Molecules returned at pre-combat quantities.
- **Fix:** Add `&& $actions['attaqueFaite'] == 1` guard — **Effort:** S

#### P1-D5-005: Combat transaction uses raw mysqli_begin_transaction, not withTransaction
- **Severity:** MEDIUM — **Location:** game_actions.php:109-360
- **Description:** Manual transaction mixed with withTransaction elsewhere. Nested calls can trigger implicit commits.
- **Fix:** Refactor to use withTransaction — **Effort:** M

#### P1-D5-006: Resource update race — tempsPrecedent CAS doesn't protect resource read/write gap
- **Severity:** MEDIUM — **Location:** game_resources.php:161-211
- **Description:** Resource READ and WRITE are outside transaction. Concurrent pillage can be overwritten by resource tick.
- **Fix:** Use relative updates or wrap in transaction with FOR UPDATE — **Effort:** M

#### P1-D5-007: Molecule decay uses absolute SET without FOR UPDATE — concurrent formation corrupts count
- **Severity:** MEDIUM — **Location:** game_resources.php:226-236, game_actions.php:66
- **Description:** Decay writes absolute value; formation completion writes absolute value. Race window erases new molecules.
- **Fix:** Use relative updates: `nombre = nombre * ?` for decay — **Effort:** S

#### P1-D5-008: coefDisparition static cache not invalidated after stabilisateur upgrade
- **Severity:** MEDIUM — **Location:** formulas.php:215-270
- **Fix:** Add cache invalidation function called from augmenterBatiment — **Effort:** XS

#### P1-D5-009: updateActions recursion guard allows attacker-defender circular calls to skip actions
- **Severity:** MEDIUM — **Location:** game_actions.php:7-13, 98-103
- **Fix:** Remove defender updateActions/updateRessources calls from combat preprocessing — **Effort:** S

#### P1-D5-010: Resource transfer (actionsenvoi) has no CAS guard — double delivery or resource loss possible
- **Severity:** MEDIUM — **Location:** game_actions.php:526-599
- **Description:** DELETE before UPDATE means server crash loses resources permanently.
- **Fix:** Wrap in withTransaction, move DELETE after UPDATE — **Effort:** S

#### P1-D5-011: revenuEnergie/revenuAtome static cache ignores building upgrades within same request
- **Severity:** MEDIUM — **Location:** game_resources.php:9-11, 96-98
- **Fix:** Add cache clear function called from augmenterBatiment — **Effort:** XS

#### P1-D5-012: Formation partial credit has ceil/floor mismatch with tempsFormation
- **Severity:** MEDIUM — **Location:** game_actions.php:62-70
- **Fix:** Standardize rounding direction — **Effort:** XS

#### P1-D5-013: actionsenvoi resource cap check uses stale non-locked recipient data
- **Severity:** MEDIUM — **Location:** game_actions.php:567-599
- **Fix:** Use SELECT FOR UPDATE or relative update with cap — **Effort:** S

#### P1-D5-014: Negative countdown display when construction completes during page render
- **Severity:** LOW — **Location:** constructions.php:354-356
- **Fix:** Clamp JS countdown to min 0 — **Effort:** XS

#### P1-D5-015: Attack decay period naming consistent (informational)
- **Severity:** LOW — **Fix:** Add clarifying comments — **Effort:** XS

#### P1-D5-016: coefDisparition uses current molecule composition, not launch-time composition
- **Severity:** LOW — **Location:** game_actions.php:113-127
- **Fix:** Store decay coefficient at launch time or document as intentional — **Effort:** M

#### P1-D5-017: Absence report uninitialized variables for players with fewer than 4 molecule classes
- **Severity:** LOW — **Location:** game_resources.php:253-265
- **Fix:** Initialize $nombre1-4 = 0 before loop — **Effort:** XS

#### P1-D5-018: Formation molecule update uses absolute SET while neutrino uses relative +=
- **Severity:** LOW — **Location:** game_actions.php:66-69
- **Fix:** Change to relative: `nombre = nombre + ?` — **Effort:** XS

#### P1-D5-019: Molecule decay stores floats while neutrino decay uses floor()
- **Severity:** LOW — **Location:** game_resources.php:228, 246
- **Fix:** Apply round() consistently; use integer type binding — **Effort:** XS

---

## Area 2: Combat Logic Bugs (14 findings)

#### P1-D5-020: No server-side guard preventing alliance member attacks
- **Severity:** MEDIUM — **Location:** combat.php (entire file), attaquer.php:59
- **Description:** UI hides attack button for alliance members but no POST validation exists. Crafted request bypasses protection.
- **Fix:** Add alliance membership check in attaquer.php POST handler — **Effort:** S

#### P1-D5-021: No server-side guard preventing attacks on pact members
- **Severity:** MEDIUM — **Location:** attaquer.php POST handler
- **Fix:** Add pact lookup check before queueing attack — **Effort:** S

#### P1-D5-022: Report shows ambiguous pillage figure in defender section
- **Severity:** MEDIUM — **Location:** game_actions.php:228-261
- **Fix:** Label pillage clearly as "Ressources volées par l'attaquant" — **Effort:** XS

#### P1-D5-023: Building damage uses stale unlocked $constructions — concurrent attack race
- **Severity:** HIGH — **Location:** combat.php:452-565
- **Description:** Two attacks against same defender read same HP, write conflicting values.
- **Fix:** Add SELECT FOR UPDATE on constructions row before damage — **Effort:** S

#### P1-D5-024: Level-1 ionisateur at lethal damage: HP not updated in DB
- **Severity:** MEDIUM — **Location:** combat.php:553-565
- **Fix:** Reset HP to max for level 1 when damage exceeds HP — **Effort:** XS

#### P1-D5-025: Building HP never regenerates between attacks
- **Severity:** HIGH — **Location:** combat.php (no regen system exists)
- **Description:** Sustained sub-lethal attacks drain HP to near-0 with no recovery mechanism.
- **Fix:** Add HP regen (e.g., 10% per hour) or add regen building — **Effort:** M

#### P1-D5-026: Zero-arrival empty army yields 1 defense point — farmable
- **Severity:** LOW — **Location:** combat.php:600
- **Fix:** Skip point award when total attacker molecules = 0 — **Effort:** XS

#### P1-D5-027: Simultaneous attacks on same defender — resource double-pillage race
- **Severity:** HIGH — **Location:** combat.php:362-433
- **Description:** Defender resources read without FOR UPDATE. Two attackers can each pillage from same stale snapshot.
- **Fix:** Add SELECT FOR UPDATE on defender's ressources row — **Effort:** S

#### P1-D5-028: Defense energy reward causes double energie= SET clause
- **Severity:** MEDIUM — **Location:** combat.php:655-678
- **Fix:** Exclude energie from loop when defense reward applies — **Effort:** XS

#### P1-D5-030: War casualty writes lack fin=0 guard — can write to ended wars
- **Severity:** MEDIUM — **Location:** combat.php:695
- **Fix:** Add `AND fin=0` to WHERE clause — **Effort:** XS

#### P1-D5-033: $activeReactionsAtt/$activeReactionsDef undefined — dead scaffolded feature
- **Severity:** CRITICAL — **Location:** game_actions.php:311-321
- **Description:** Variables referenced but never defined. PHP 8.2 deprecation notice silently swallowed. Dead code from unbuilt feature.
- **Fix:** Remove the reactions block from report generation — **Effort:** XS

#### P1-D5-034: Building damage report shows wrong HP% when building is downgraded
- **Severity:** HIGH — **Location:** combat.php:502-565
- **Description:** Report shows "N% → 0%" but building actually downgraded to lower level with full HP.
- **Fix:** Recompute percentage after diminuerBatiment using new level's max HP — **Effort:** S

#### P1-D5-036: nbattaques incremented on defeats/draws — terror medal farmable
- **Severity:** MEDIUM — **Location:** combat.php:681
- **Fix:** Only increment on $gagnant == 2 (attacker wins) — **Effort:** XS

#### P1-D5-039: Compound/spec bonuses read without FOR UPDATE in combat transaction
- **Severity:** MEDIUM — **Location:** combat.php:184-195
- **Fix:** Add FOR UPDATE on compound/specialization reads — **Effort:** S

---

## Area 3: Code vs Documentation (11 findings)

#### P1-D5-040: Player Guide: Stable isotope values wrong (-10%atk/+20%HP vs actual -5%/+30%)
- **Severity:** MEDIUM — **Location:** docs/game/10-PLAYER-GUIDE.md:269
- **Fix:** Update to match config.php values — **Effort:** XS

#### P1-D5-041: Balance doc: Stable isotope values stale (same issue)
- **Severity:** MEDIUM — **Location:** docs/game/09-BALANCE.md:662
- **Fix:** Update — **Effort:** XS

#### P1-D5-042: Player Guide: Storage formula says linear 500×level but code is exponential
- **Severity:** MEDIUM — **Location:** docs/game/10-PLAYER-GUIDE.md:114
- **Fix:** Update to exponential formula — **Effort:** XS

#### P1-D5-043: Player Guide: Vault says 100/level flat but code is percentage-based
- **Severity:** MEDIUM — **Location:** docs/game/10-PLAYER-GUIDE.md:137
- **Fix:** Update — **Effort:** XS

#### P1-D5-044: Player Guide: ALL molecule stat formulas are pre-V4 — missing covalent synergies
- **Severity:** MEDIUM — **Location:** docs/game/10-PLAYER-GUIDE.md:223-229
- **Fix:** Rewrite Section 4 and Annexe B with V4 formulas — **Effort:** M

#### P1-D5-047: Player Guide: Building costs shown as polynomial but V4 uses exponential
- **Severity:** MEDIUM — **Location:** docs/game/10-PLAYER-GUIDE.md:150-161
- **Fix:** Update cost table — **Effort:** S

#### P1-D5-049: Player Guide + Balance doc: Embuscade says +15% but code is +25%
- **Severity:** MEDIUM — **Location:** docs/game/10-PLAYER-GUIDE.md:364, 09-BALANCE.md:646
- **Fix:** Update both docs — **Effort:** XS

#### P1-D5-051: Player Guide: Beginner protection says 5 days but code is 3 days
- **Severity:** LOW — **Location:** docs/game/10-PLAYER-GUIDE.md:55
- **Fix:** Update — **Effort:** XS

#### P1-D5-054: Player Guide + Balance doc: Medal thresholds for Attaque/Defense are old values
- **Severity:** LOW — **Location:** docs/game/10-PLAYER-GUIDE.md:631
- **Fix:** Update threshold tables — **Effort:** XS

#### P1-D5-056: Player Guide: Chemical Reactions section describes removed V4 system
- **Severity:** LOW — **Location:** docs/game/10-PLAYER-GUIDE.md:377-400
- **Fix:** Remove or replace with V4 covalent synergy description — **Effort:** S

#### P1-D5-059: Building damage: docs say 4 targets but code now targets 5 (includes ionisateur)
- **Severity:** LOW — **Location:** docs + config.php:NUM_DAMAGEABLE_BUILDINGS
- **Fix:** Update constant and all docs — **Effort:** XS

---

## Area 4: Edge Cases & Boundary Conditions (10 findings)

#### P1-D5-061: Season reset omits player_compounds table
- **Severity:** HIGH — **Location:** player.php:remiseAZero()
- **Description:** Compound buffs survive season boundary. Active +25% pillage buff persists into new season.
- **Fix:** Add `DELETE FROM player_compounds` to remiseAZero — **Effort:** XS

#### P1-D5-062: Season reset omits login_events and account_flags tables
- **Severity:** HIGH — **Location:** player.php:remiseAZero()
- **Fix:** Add DELETE statements for both tables — **Effort:** XS

#### P1-D5-063: Player deletion omits login_events and account_flags tables
- **Severity:** HIGH — **Location:** player.php:supprimerJoueur()
- **Fix:** Add DELETE statements — **Effort:** XS

#### P1-D5-065: Market buy: stale $placeDepot in transaction closure
- **Severity:** HIGH — **Location:** marche.php:188
- **Description:** Depot upgrade between page load and transaction allows wrong storage limit.
- **Fix:** Re-read placeDepot inside transaction — **Effort:** XS

#### P1-D5-066: Market sell: division by zero when cours table is empty (post-reset)
- **Severity:** HIGH — **Location:** marche.php:299-301
- **Description:** First visit to market after season reset crashes on empty price table.
- **Fix:** Seed initial prices in remiseAZero or add empty check — **Effort:** S

#### P1-D5-069: Vacation mode activates during active attack — attack proceeds through immunity
- **Severity:** HIGH — **Location:** compte.php:13-28
- **Description:** No check for pending attacks when enabling vacation mode.
- **Fix:** Block vacation activation when player has in-flight attacks or is being attacked — **Effort:** S

#### P1-D5-070: Alliance with 0 members after chef deletion — wars persist
- **Severity:** HIGH — **Location:** player.php:supprimerJoueur()
- **Description:** When alliance chef is deleted, wars and pacts remain until some page visit triggers cleanup.
- **Fix:** Call supprimerAlliance when chef is last member — **Effort:** S

#### P1-D5-073: Division by zero when revenuEnergie = 0 in initPlayer
- **Severity:** MEDIUM — **Location:** player.php:initPlayer():324
- **Fix:** Guard division with max(1, $revenuEnergie) — **Effort:** XS

#### P1-D5-074: Concurrent coordinate placement assigns same tile to two players
- **Severity:** MEDIUM — **Location:** player.php:coordonneesAleatoires()
- **Fix:** Add UNIQUE constraint on (x, y) columns or use INSERT IGNORE — **Effort:** S

#### P1-D5-078: ucfirst not multibyte-safe; login normalization differs from message recipient lookup
- **Severity:** HIGH — **Location:** inscription.php:20, ecriremessage.php:10
- **Fix:** Use mb_ucfirst() (PHP 8.4) or custom polyfill — **Effort:** XS

---

## Area 5: Test Coverage Gaps (5 findings)

#### P1-D5-080: Integration test suite has no working database connection
- **Severity:** CRITICAL → HIGH — **Location:** phpunit.xml, tests/integration/
- **Description:** Integration tests use $base = null from bootstrap. All 7 integration test files are non-functional.
- **Fix:** Wire bootstrap_integration.php into phpunit.xml or add DB setup to CI — **Effort:** M

#### P1-D5-081: Zero test coverage for combat.php, player.php, game_actions.php, game_resources.php
- **Severity:** HIGH — **Location:** tests/
- **Description:** Core game logic (combat resolution, building upgrades, action processing, resource ticks) has 0% test coverage. 371 tests only cover formula math and config validation.
- **Fix:** Write integration tests for critical paths — **Effort:** XL

#### P1-D5-093: Missing negative tests for rejected actions
- **Severity:** MEDIUM — **Location:** tests/
- **Description:** No tests verify that invalid actions are properly rejected (attack under protection, build over queue limit, buy over storage, etc.)
- **Fix:** Add negative test suite — **Effort:** L

#### P1-D5-094: Missing boundary tests at exact max/min values
- **Severity:** MEDIUM — **Location:** tests/
- **Description:** No tests exercise exact boundaries (200 atoms, 2 concurrent constructions, 3-day protection, etc.)
- **Fix:** Add boundary test suite — **Effort:** M

#### P1-D5-096: Several tests assert trivially true things or have reversed operands
- **Severity:** MEDIUM — **Location:** MultiaccountTest.php, BuildingConstructionTest.php
- **Description:** Placeholder tests that always pass; cost formula test uses wrong argument order.
- **Fix:** Rewrite affected tests — **Effort:** S

---

## Cross-Domain References

| Finding | Related |
|---------|---------|
| P1-D5-001 | P1-D2-005 (dual auth guard pattern) |
| P1-D5-005 | P1-D2-035, P1-D2-063 (combat transaction) |
| P1-D5-006 | P1-D1-047 (resource transfer no transaction) |
| P1-D5-020 | P1-D1-030 (authorization gaps) |
| P1-D5-027 | P1-D1-038 (race conditions) |
| P1-D5-033 | P1-D2-088 (alliance research stubs) |
| P1-D5-061 | P1-D4-060 (stabilisateur decay) |
| P1-D5-044 | P1-D4-020 (dominant army composition) |
| P1-D5-080 | P1-D3-041 (CI missing tests) |
