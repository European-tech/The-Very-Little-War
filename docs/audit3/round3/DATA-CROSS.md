# DATA-CROSS: Complete Map of Non-Atomic DB Operations

**Audit Round 3 — The Very Little War**
**Date: 2026-03-03**
**Scope:** game_actions.php, player.php, combat.php, marche.php, attaquer.php, constructions.php, armee.php, alliance.php, allianceadmin.php, don.php, validerpacte.php, game_resources.php

---

## Legend

- **In Transaction?** — YES (withTransaction or manual BEGIN/COMMIT), PARTIAL (some statements wrapped, others not), NO (bare sequential writes)
- **FOR UPDATE?** — whether a SELECT ... FOR UPDATE lock is acquired before writes
- **Race Window** — nature of TOCTOU or lost-update window that exists when not fully protected
- **Priority** — CRITICAL / HIGH / MEDIUM / LOW based on financial impact and exploitability

---

## Master Table

| # | Operation | File:Line(s) | Statements | In Transaction? | FOR UPDATE? | Race Window | Priority |
|---|-----------|-------------|-----------|----------------|------------|-------------|----------|
| 1 | **Player Registration (inscrire)** | player.php:60-70 | INSERT membre, INSERT autre, INSERT ressources, UPDATE statistiques, INSERT molecules (multi-row), INSERT constructions — 6 statements | YES (withTransaction) | NO | Low: all rows are brand-new; concurrent duplicate registration possible but UNIQUE constraint on login prevents committed duplicates | LOW |
| 2 | **Season Reset (remiseAZero)** | player.php:799-838 | UPDATE autre (all), UPDATE constructions (all), UPDATE alliances (all), UPDATE molecules (all), UPDATE membre, UPDATE ressources (all), DELETE declarations, DELETE invitations, DELETE messages, DELETE rapports, DELETE actionsconstruction, DELETE actionsformation, DELETE actionsenvoi, DELETE actionsattaques, UPDATE statistiques, UPDATE membre, prestige loop UPDATEs, DELETE attack_cooldowns — 18+ statements | NO | NO | CRITICAL: entire game state rewrite with no transaction. Any mid-operation failure leaves partial reset. Mid-reset requests from players will read inconsistent data. | CRITICAL |
| 3 | **Resource Update (updateRessources)** | game_resources.php:105-201 | CAS UPDATE autre (tempsPrecedent), UPDATE ressources (energie), UPDATE ressources (all atoms), per-molecule UPDATE molecules + UPDATE autre (moleculesPerdues) loop, conditional INSERT rapports — 4+ statements | NO | NO | HIGH: CAS guard on tempsPrecedent prevents double energy grant. However per-molecule UPDATE+SELECT+UPDATE pairs inside the loop have a TOCTOU gap: another concurrent request could read moleculesPerdues between the SELECT and UPDATE, losing molecule-loss stats. | HIGH |
| 4 | **Formation Completion (updateActions — construction branch)** | game_actions.php:26-31 | augmenterBatiment() call (2-4 internal UPDATEs + ajouterPoints SELECT+UPDATE), DELETE FROM actionsconstruction | NO | NO | HIGH: delete of action row and building level-up are not atomic. A crash after building upgrade but before DELETE leaves action eligible for double-processing on next tick. | HIGH |
| 5 | **Formation Processing — partial completion (updateActions)** | game_actions.php:40-60 | UPDATE molecules (or autre neutrinos) SET nombre, UPDATE actionsformation SET nombreRestant — 2 statements per tick | NO | NO | HIGH: two writes not wrapped in transaction. A concurrent request for the same player could read stale nombre before the UPDATE commits, crediting molecules twice (double-formation). | HIGH |
| 6 | **Formation Processing — completion (updateActions)** | game_actions.php:52-59 | DELETE FROM actionsformation, UPDATE molecules (or neutrinos) nombre — 2 statements | NO | NO | HIGH: delete then credit is not atomic. A crash between them loses the formation batch. Duplicate completion if two requests race (no CAS guard here unlike combat). | HIGH |
| 7 | **Combat Resolution (updateActions — combat branch)** | game_actions.php:71-323 / combat.php:338-633 | CAS UPDATE actionsattaques (attaqueFaite), updateRessources+updateActions for both players, then inside manual BEGIN/COMMIT: UPDATE molecules (decay loop, per class), INSERT attack_cooldowns, UPDATE actionsattaques (troupes), 4x UPDATE molecules (defender losses), SELECT ressources attacker+defender, SELECT coffrefort, UPDATE ressources (attacker pillage), UPDATE ressources (defender losses), UPDATE autre (moleculesPerdues attacker), UPDATE autre (moleculesPerdues defender), SELECT autre (nbattaques), UPDATE autre (nbattaques), SELECT declarations (war), UPDATE declarations (war losses), ajouterPoints (3 SELECT+UPDATE pairs), conditional DELETE actionsattaques, 2x INSERT rapports — 25+ statements | PARTIAL | NO | CRITICAL: CAS claim on attaqueFaite prevents duplicate combat. However: (a) molecule decay loop (lines 94-103) runs OUTSIDE the transaction, before mysqli_begin_transaction at line 107. Decay writes can be committed and then the transaction rolls back, leaving permanent molecule loss with no combat report. (b) ajouterPoints calls are SELECT then UPDATE with no lock, subject to lost-update from concurrent point changes. (c) building damage via diminuerBatiment is also outside the BEGIN/COMMIT block. | CRITICAL |
| 8 | **Troop Return (updateActions — return branch)** | game_actions.php:446-468 | Per-molecule: UPDATE molecules (return), SELECT+UPDATE autre (moleculesPerdues) — loop + DELETE actionsattaques | NO | NO | HIGH: no transaction. Molecule return and action deletion are not atomic. If crash occurs after molecule credit but before DELETE, troops are credited again on next request. moleculesPerdues SELECT+UPDATE inside loop has lost-update risk. | HIGH |
| 9 | **Resource Envoi Arrival (updateActions — envoi branch)** | game_actions.php:471-539 | DELETE FROM actionsenvoi, INSERT rapports, SELECT ressources (receveur), SELECT depot, UPDATE ressources (receveur all columns) — 5 statements | NO | NO | HIGH: DELETE then resource credit not atomic. If two requests for receveur process the same envoi concurrently (before DELETE commits), resources credited twice. No FOR UPDATE on the recipient's ressources row. | HIGH |
| 10 | **Espionage Submission (updateActions — espionage branch)** | game_actions.php:325-443 | INSERT rapports (attacker), conditional INSERT rapports (defender), DELETE actionsattaques — 3 statements | NO | NO | LOW: no financial state change; only rapport inserts and action cleanup. Duplicate rapport possible if race, cosmetic only. | LOW |
| 11 | **Attack Launch (attaquer.php)** | attaquer.php:146-158 | Per-molecule UPDATE molecules (deduct troops) loop, INSERT actionsattaques, ajouter energie (SELECT+UPDATE), ajouter energieDepensee (SELECT+UPDATE) — 4+ statements | NO | NO | CRITICAL: no transaction and no FOR UPDATE. A player with two concurrent attack submissions can: (a) read the same molecule count twice before either UPDATE commits, sending more troops than they own; (b) deduct energy twice from a stale read via ajouter(). | CRITICAL |
| 12 | **Espionage Launch (attaquer.php)** | attaquer.php:36-39 | INSERT actionsattaques, UPDATE autre (neutrinos) — 2 statements | NO | NO | HIGH: no transaction. Concurrent espionage submissions could both read $autre['neutrinos'] before either UPDATE commits, spending the same neutrinos twice. | HIGH |
| 13 | **Build Queue — resource deduction + queue INSERT (constructions.php traitementConstructions)** | constructions.php:251-290 | UPDATE ressources (energie + atoms), INSERT actionsconstruction, UPDATE autre (energieDepensee) — 3 statements | NO | NO | CRITICAL: no transaction and no FOR UPDATE. Two concurrent build submissions will both read the same ressources values, deduct the same cost twice, and insert two queue entries. Double-spend of resources is the direct result. | CRITICAL |
| 14 | **Molecule Class Creation (armee.php emplacementmoleculecreer1)** | armee.php:191-220 | UPDATE ressources (niveauclasse++), UPDATE molecules (set formula), UPDATE ressources (energie deduct) — 3 statements | NO | NO | HIGH: niveauclasse increment and energy deduct are separate UPDATEs. Two concurrent form submissions can both read the same niveauclasse/energie values and both succeed, creating two molecule classes for the cost of one. | HIGH |
| 15 | **Molecule Deletion (armee.php emplacementmoleculesupprimer)** | armee.php:12-53 | UPDATE ressources (niveauclasse--), UPDATE molecules (reset formula+atoms), DELETE actionsformation, loop UPDATE actionsformation (reschedule), loop UPDATE actionsattaques (zero troop slot) — 5+ statements | NO | NO | MEDIUM: multiple writes not atomic. Partial completion leaves molecule data inconsistent (e.g. niveauclasse decremented but molecule row not reset). Attaques zeroing loop could be missed if crash occurs mid-loop. | MEDIUM |
| 16 | **Neutrino Formation (armee.php nombreneutrinos)** | armee.php:70-76 | UPDATE autre (neutrinos+), UPDATE ressources (energie-), UPDATE autre (energieDepensee+) — 3 statements | NO | NO | HIGH: three sequential writes without a transaction or FOR UPDATE. Concurrent submissions read stale energie/neutrinos, double-spend energy and double-add neutrinos. | HIGH |
| 17 | **Molecule Formation Queue (armee.php emplacementmoleculeformer)** | armee.php:118-139 | INSERT actionsformation, UPDATE ressources (atoms deducted) — 2 statements | NO | NO | HIGH: no transaction. Concurrent submissions both see the same atom balances, deduct atoms once each while inserting two formation queue entries. Atom double-spend. | HIGH |
| 18 | **Market Buy (marche.php)** | marche.php:168-226 | SELECT ressources FOR UPDATE, UPDATE ressources (energie-, atom+), INSERT cours, SELECT+conditional UPDATE autre (tradeVolume, totalPoints) — 4 statements | YES (withTransaction) | YES (ressources row) | MEDIUM: FOR UPDATE locks ressources correctly. However autre (tradeVolume/totalPoints) is read without FOR UPDATE inside the transaction; a concurrent market trade for the same player could produce a lost-update on totalPoints. | MEDIUM |
| 19 | **Market Sell (marche.php)** | marche.php:280-339 | SELECT ressources FOR UPDATE, UPDATE ressources (energie+, atom-), INSERT cours, SELECT+conditional UPDATE autre (tradeVolume, totalPoints) — 4 statements | YES (withTransaction) | YES (ressources row) | MEDIUM: same partial lock issue as market buy — autre not locked. | MEDIUM |
| 20 | **Resource Send — deduct sender (marche.php envoi)** | marche.php:95-115 | INSERT actionsenvoi, UPDATE ressources (sender atoms-, energie-) — 2 statements | NO | NO | HIGH: no transaction. Two concurrent send submissions read the same sender ressources, both pass the balance check, both deduct — overdraft possible. No FOR UPDATE on sender's ressources. | HIGH |
| 21 | **Alliance Donation (don.php)** | don.php:18-31 | SELECT ressources FOR UPDATE, SELECT energieDonnee FOR UPDATE, SELECT alliances FOR UPDATE, UPDATE ressources (energie-), UPDATE autre (energieDonnee+), UPDATE alliances (energieAlliance+, energieTotaleRecue+) — 6 statements | YES (withTransaction) | YES (all 3 rows) | LOW: fully locked and transactional. No race window. | LOW |
| 22 | **Alliance Creation (alliance.php nomalliance/tagalliance)** | alliance.php:44-49 | INSERT alliances, SELECT id (last insert), UPDATE autre (idalliance) — 3 statements | NO | NO | MEDIUM: INSERT then SELECT id (not using LAST_INSERT_ID()) then UPDATE autre is not atomic. Two concurrent creation requests with the same tag will fail at INSERT due to UNIQUE constraint, but between check and INSERT there is a race if no unique index exists on tag. The SELECT id after INSERT is a separate query — though tag is unique this is fragile. | MEDIUM |
| 23 | **Alliance Join via Invitation (alliance.php actioninvitation=Accepter)** | alliance.php:126-130 | UPDATE autre (idalliance), DELETE invitations — 2 statements | NO | NO | MEDIUM: no transaction. Two players accepting the same invitation simultaneously will both get UPDATE autre to set idalliance (both join), then both issue DELETE. Alliance may exceed player cap before the second delete fires. | MEDIUM |
| 24 | **Duplicateur Upgrade (alliance.php augmenterDuplicateur)** | alliance.php:83-86 | UPDATE alliances (duplicateur+1, energieAlliance-) — 1 statement (single UPDATE, atomic) | YES (atomic single UPDATE) | NO | LOW: single UPDATE sets both columns atomically. TOCTOU between the pre-check SELECT and the UPDATE: another member could drain energieAlliance between the check and the UPDATE. No FOR UPDATE on pre-check read. | LOW |
| 25 | **Alliance Research Upgrade (alliance.php upgradeResearch)** | alliance.php:106-108 | SELECT alliances (techName, energieAlliance), UPDATE alliances (techName+1, energieAlliance-) — SELECT then UPDATE | NO | NO | HIGH: SELECT then UPDATE with no transaction or FOR UPDATE. Two alliance members clicking upgrade simultaneously will both read the same level and energy, both pass the check, both issue UPDATE — double upgrade at single cost. | HIGH |
| 26 | **Pact Request (allianceadmin.php pacte)** | allianceadmin.php:209-220 | INSERT declarations, SELECT id (for rapport content), INSERT rapports — 3 statements | NO | NO | LOW: pact creation logic has a check-then-insert without a lock, but pact duplicates are cosmetically undesirable rather than financially exploitable. | LOW |
| 27 | **Pact Break (allianceadmin.php allie)** | allianceadmin.php:237-241 | DELETE declarations, INSERT rapports — 2 statements | NO | NO | LOW: no financial state. Two simultaneous break requests leave at most one extra rapport. | LOW |
| 28 | **War Declaration (allianceadmin.php guerre)** | allianceadmin.php:263-269 | DELETE declarations (pending pacts), DELETE declarations (reverse), INSERT declarations (war), INSERT rapports — 4 statements | NO | NO | MEDIUM: no transaction. Delete-pending-pact then insert-war is not atomic. If two alliances simultaneously declare war on each other, both DELETE + INSERT sequences can interleave, creating duplicate war rows. | MEDIUM |
| 29 | **War End (allianceadmin.php adversaire)** | allianceadmin.php:288-291 | UPDATE declarations (fin=now), INSERT rapports — 2 statements | NO | NO | LOW: concurrent end-war by two admins updates the same row idempotently (both set fin=now). Cosmetic duplicate rapports possible. | LOW |
| 30 | **Player Ban (allianceadmin.php bannirpersonne)** | allianceadmin.php:183-184 | UPDATE autre (idalliance=0), DELETE grades — 2 statements | NO | NO | LOW: no financial impact; worst case grade row is not deleted if crash between the two writes. | LOW |
| 31 | **supprimerAlliance** | player.php:739-750 | UPDATE autre (energieDonnee=0), DELETE alliances, UPDATE autre (idalliance=0), DELETE invitations, DELETE declarations, DELETE grades — 6 statements | YES (withTransaction) | NO | LOW: fully wrapped in transaction. No race window. | LOW |
| 32 | **supprimerJoueur** | player.php:752-778 | 13 DELETEs + 1 SELECT + 1 UPDATE statistiques — 15 statements | YES (withTransaction) | NO | LOW: fully wrapped in transaction. No race window. | LOW |
| 33 | **augmenterBatiment** | player.php:508-540 | Optional UPDATE constructions (pointsProducteurRestants or pointsCondenseurRestants), UPDATE constructions (level++, vie), ajouterPoints (SELECT+UPDATE) — 3 statements | NO | NO | HIGH: called from updateActions which is itself unprotected. augmenterBatiment reads constructions then writes level. The CAS guard on actionsattaques (combat) does not extend to this call. Two concurrent updateActions calls could both call augmenterBatiment for the same action — but the CAS on attaqueFaite prevents that specific vector. The construction branch (game_actions.php:28-30) has no CAS, so double-level-up on construction completion is possible if two requests both see the same action as unprocessed. ajouterPoints itself is SELECT+UPDATE with no lock. | HIGH |
| 34 | **diminuerBatiment** | player.php:542-621 | SELECT constructions, conditional UPDATE constructions (pointsProducteurRestants/Condenseur), UPDATE constructions (level--, vie), ajouterPoints (SELECT+UPDATE) — 3-4 statements | NO | NO | MEDIUM: called within already-open transaction context of combat (lines 476-524 of combat.php). The surrounding transaction covers the building damage call, but ajouterPoints inside diminuerBatiment is a SELECT+UPDATE pair not explicitly guarded. | MEDIUM |
| 35 | **ajouterPoints** | player.php:73-106 | SELECT autre (points), UPDATE autre (points+, totalPoints) — 2 statements per call | NO | NO | HIGH: called from combat (3 times), augmenterBatiment, diminuerBatiment. Each call is a SELECT then UPDATE with no lock. Concurrent calls for the same player lose updates. Points are financial score that determine victory conditions. | HIGH |
| 36 | **coordonneesAleatoires** | player.php:623-682 | SELECT statistiques, SELECT membre (all positions), UPDATE statistiques (tailleCarte, nbDerniere) — 3 statements | NO | NO | MEDIUM: two simultaneous registrations both compute free coordinates from the same snapshot of the map; both write the same nbDerniere+1. Players could be assigned identical map coordinates. | MEDIUM |
| 37 | **Producteur/Condenseur Point Allocation (constructions.php)** | constructions.php:34 / constructions.php:72 | UPDATE constructions (pointsProducteurRestants, pointsProducteur) — 1 statement (atomic single UPDATE) | YES (atomic) | NO | LOW: single UPDATE is atomic. Pre-check uses stale in-memory $constructions; a concurrent write could allow spending more points than available if the pre-check passes on both concurrent requests for the same points pool. | LOW |
| 38 | **Pact Accept/Reject (validerpacte.php)** | validerpacte.php:20-24 | SELECT declarations (existe check), SELECT declarations (alliance2), SELECT alliances (chef), UPDATE declarations (valide=1) OR DELETE declarations — 4 queries, final action is 1 statement | NO | NO | MEDIUM: check-then-act without a transaction. Two concurrent acceptance submissions for the same declaration both pass the existe==1 check, then both issue UPDATE valide=1 (idempotent) or one UPDATE + one no-op. Unlikely to cause damage but check-then-act is unsound. | MEDIUM |

---

## Summary by Priority

### CRITICAL (must fix before any real traffic)

| # | Operation | Core Issue |
|---|-----------|-----------|
| 2 | remiseAZero (season reset) | 18+ statement reset with no transaction; partial failure leaves database in mixed-season state |
| 7 | Combat Resolution | Pre-transaction molecule decay writes (lines 94-103 game_actions.php) committed before BEGIN; rollback leaves permanent molecule loss with no combat report; ajouterPoints not locked |
| 11 | Attack Launch | No transaction, no FOR UPDATE on molecules or energy; concurrent submissions overdraft troops and energy |
| 13 | Build Queue — constructions.php | No transaction, no FOR UPDATE on ressources; double build-cost deduction with two concurrent requests |

### HIGH (fix in next sprint)

| # | Operation | Core Issue |
|---|-----------|-----------|
| 3 | updateRessources — molecule decay loop | Per-molecule SELECT+UPDATE moleculesPerdues inside loop; lost-update from concurrency |
| 4 | updateActions — construction completion | augmenterBatiment + DELETE not atomic; double-level-up possible |
| 5 | updateActions — partial formation | Two writes not in transaction; duplicate molecule credit |
| 6 | updateActions — formation completion | DELETE then credit not atomic; duplicate or lost molecules |
| 8 | Troop Return | Molecule return + DELETE not atomic; double troop return |
| 9 | Resource Envoi Arrival | DELETE then credit not atomic + no FOR UPDATE on recipient; double credit |
| 12 | Espionage Launch | No transaction; concurrent submissions double-spend neutrinos |
| 14 | Molecule Class Creation | Three sequential UPDATEs without transaction; double class creation at single cost |
| 16 | Neutrino Formation | Three writes without transaction or FOR UPDATE; energy double-spend |
| 17 | Molecule Formation Queue | No transaction; atom double-spend with duplicate formation inserts |
| 20 | Resource Send (marche.php envoi) | No transaction, no FOR UPDATE on sender; overdraft of sender resources |
| 25 | Alliance Research Upgrade | SELECT then UPDATE without transaction; double upgrade at single cost |
| 35 | ajouterPoints | SELECT+UPDATE without lock; called 5+ times from combat and buildings; score lost-updates |

### MEDIUM (fix when time permits)

| # | Operation | Core Issue |
|---|-----------|-----------|
| 15 | Molecule Deletion | 5+ writes without transaction; partial failure leaves inconsistent molecule state |
| 18 | Market Buy — autre tradeVolume | autre row not locked inside transaction; tradeVolume/totalPoints lost-update |
| 19 | Market Sell — autre tradeVolume | Same as market buy |
| 22 | Alliance Creation | No LAST_INSERT_ID(); SELECT id after INSERT is racy |
| 23 | Alliance Join via Invitation | Two players accept same invite; both join; cap exceeded |
| 28 | War Declaration | Delete+insert not atomic; interleaving creates duplicate war rows |
| 34 | diminuerBatiment — ajouterPoints | ajouterPoints SELECT+UPDATE not explicitly locked within surrounding transaction |
| 36 | coordonneesAleatoires | Two registrations get same map coordinates |
| 38 | Pact Accept/Reject | Check-then-act without transaction |

### LOW (cosmetic / negligible financial impact)

| # | Operation | Core Issue |
|---|-----------|-----------|
| 1 | inscrire | Already in withTransaction; UNIQUE constraint on login prevents committed duplicates |
| 10 | Espionage completion | Only rapport inserts; duplicate rapport cosmetic |
| 21 | Alliance Donation (don.php) | Fully locked with FOR UPDATE on 3 rows inside withTransaction |
| 24 | Duplicateur Upgrade | Single-UPDATE atomic; pre-check TOCTOU is minor risk |
| 26 | Pact Request | No financial impact |
| 27 | Pact Break | No financial impact |
| 29 | War End | Idempotent UPDATE; duplicate rapport only |
| 30 | Player Ban | No financial impact |
| 31 | supprimerAlliance | In withTransaction |
| 32 | supprimerJoueur | In withTransaction |
| 37 | Point Allocation | Single atomic UPDATE; pre-check uses cached value |

---

## Recommended Fix Patterns

### Pattern A: Wrap in withTransaction (no read lock needed)
Use for operations where all data was already validated from the session-loaded snapshot and concurrent modification by others is not a concern (e.g. supprimerAlliance, inscrire).

```php
withTransaction($base, function() use ($base, ...) {
    dbExecute($base, 'UPDATE ...', ...);
    dbExecute($base, 'DELETE ...', ...);
});
```

### Pattern B: SELECT ... FOR UPDATE inside withTransaction (financial writes)
Use for all operations involving energy, atoms, molecules, or points where another request could modify the row concurrently.

```php
withTransaction($base, function() use ($base, $login) {
    $row = dbFetchOne($base, 'SELECT energie FROM ressources WHERE login=? FOR UPDATE', 's', $login);
    if ($row['energie'] < $cost) throw new Exception('NOT_ENOUGH_ENERGY');
    dbExecute($base, 'UPDATE ressources SET energie=? WHERE login=?', 'ds', $row['energie'] - $cost, $login);
});
```

### Pattern C: CAS UPDATE for idempotent action claiming
Already used for combat (attaqueFaite). Extend to construction action processing:

```php
$claimed = dbExecute($base, 'UPDATE actionsconstruction SET traitee=1 WHERE id=? AND traitee=0', 'i', $id);
if ($claimed === 0) continue; // already processed by concurrent request
// now do augmenterBatiment inside a transaction
```

### Pattern D: Fix ajouterPoints atomically
Replace the SELECT+UPDATE pattern with an atomic expression:

```php
// Instead of SELECT points then UPDATE points+$nb:
dbExecute($base, 'UPDATE autre SET points=GREATEST(0, points+?), totalPoints=totalPoints+? WHERE login=?', 'dds', $nb, $nb, $joueur);
```

### Pattern E: remiseAZero — wrap entire reset in transaction
```php
withTransaction($base, function() use ($base) {
    // All 18+ statements here
    dbExecute($base, 'UPDATE autre SET ...');
    dbExecute($base, 'UPDATE constructions SET ...');
    // ... etc
});
```

---

## Cross-File Dependency Map

```
attaquer.php (attack launch)
  -> UPDATE molecules [no lock]       CRITICAL #11
  -> INSERT actionsattaques
  -> ajouter('energie') = SELECT+UPDATE [no lock]

game_actions.php::updateActions()
  -> constructions branch:
       augmenterBatiment()            HIGH #4
         -> UPDATE constructions
         -> ajouterPoints()           HIGH #35
       DELETE actionsconstruction     [outside transaction]

  -> formation branch:
       UPDATE molecules / neutrinos   HIGH #5, #6
       UPDATE actionsformation

  -> combat branch:
       molecule decay loop            CRITICAL #7
       [mysqli_begin_transaction]
         include combat.php
           -> UPDATE molecules (defender)
           -> diminuerBatiment()      MEDIUM #34
               -> ajouterPoints()    HIGH #35
           -> UPDATE ressources (attacker, defender)
           -> UPDATE autre (moleculesPerdues x2)
           -> UPDATE autre (nbattaques)
           -> UPDATE declarations (war losses)
           -> ajouterPoints() x3     HIGH #35
           -> INSERT rapports x2
       [mysqli_commit]
       [molecule decay writes already committed — cannot rollback]

  -> return branch:
       UPDATE molecules / moleculesPerdues loop  HIGH #8
       DELETE actionsattaques

  -> envoi branch:
       DELETE actionsenvoi            HIGH #9
       INSERT rapports
       UPDATE ressources (receveur)

constructions.php::traitementConstructions()
  -> UPDATE ressources               CRITICAL #13
  -> INSERT actionsconstruction
  -> UPDATE autre (energieDepensee)

armee.php (various)
  -> molecule creation               HIGH #14
  -> neutrino formation              HIGH #16
  -> molecule formation queue        HIGH #17
  -> molecule deletion               MEDIUM #15

marche.php
  -> buy: withTransaction + FOR UPDATE on ressources  MEDIUM #18
  -> sell: withTransaction + FOR UPDATE on ressources MEDIUM #19
  -> send (envoi): no transaction                     HIGH #20

player.php::remiseAZero()
  -> 18+ global UPDATEs/DELETEs, no transaction       CRITICAL #2

player.php::ajouterPoints()
  -> SELECT autre + UPDATE autre, no lock             HIGH #35
  -> called from: combat (x3), augmenterBatiment, diminuerBatiment

alliance.php
  -> research upgrade: SELECT + UPDATE, no lock       HIGH #25
  -> duplicateur upgrade: single UPDATE               LOW #24
  -> invitation accept: UPDATE + DELETE, no txn       MEDIUM #23
  -> creation: INSERT + SELECT + UPDATE, no txn       MEDIUM #22
```

---

## Files With Zero Transaction Protection on Financial Operations

These files perform multi-statement financial writes with **no** `withTransaction()` and **no** `FOR UPDATE`:

1. `attaquer.php` — attack launch (troops + energy)
2. `constructions.php` — build queue (atoms + energy)
3. `armee.php` — molecule creation, neutrino formation, formation queue
4. `game_actions.php` — construction completion, formation completion, troop return, envoi arrival
5. `game_resources.php` — molecule decay loop
6. `player.php::remiseAZero` — season reset
7. `player.php::augmenterBatiment` — building level-up points
8. `player.php::ajouterPoints` — score updates
9. `alliance.php` — research upgrade, invitation accept, alliance creation
10. `marche.php (envoi section)` — resource send deduction

---

*End of DATA-CROSS audit document — Round 3*
