# Remediation Plan — Ultra Audit Pass 3
**Date:** 2026-03-08
**Raw findings:** 95 across 12 domains
**After deduplication:** 91 unique findings
**Severity breakdown:** 3 CRITICAL, 20 HIGH, 47 MEDIUM, 21 LOW
**Batches:** 10 batches

---

## Deduplication Notes

| Merged | Original IDs |
|---|---|
| Session-MEDIUM-002 (countdown UTC+1) | = UI-LOW-015 — same issue |
| Forum-LOW-001 (ecriremessage [all] not in tx) | merged into Forum batch |
| Alliance-LOW-004 (quitter non-atomic) | kept as LOW |
| DB-MEDIUM-001 (two 0039 files) | + DB-MEDIUM-002 (season_recap FK deferred) = same batch |

---

## Summary Table

| ID | Sev | Title | File(s) | Batch |
|---|---|---|---|---|
| CRIT-001 | CRITICAL | Combat reports reference undefined variable-variables | includes/game_actions.php | A |
| CRIT-002 | CRITICAL | attack_cooldowns created utf8mb4 before charset fix | migrations/0004 | E |
| CRIT-003 | CRITICAL | DB 0022 unconditional UPDATE with EXP() overflow on large pillage | migrations/0022 | E |
| HIGH-001 | HIGH | Dual advisory lock names cause silent mismatch | includes/basicprivatephp.php, includes/player.php | B |
| HIGH-002 | HIGH | performSeasonEnd returns null on lock fail → false season emails | includes/player.php, includes/basicprivatephp.php | B |
| HIGH-003 | HIGH | Email queue runs AFTER advisory lock released (on failure too) | includes/basicprivatephp.php | B |
| HIGH-004 | HIGH | Resources SELECT lacks FOR UPDATE in combat transaction | includes/combat.php | B |
| HIGH-005 | HIGH | messageCommun.php broadcast loop not in transaction | messageCommun.php | C |
| HIGH-006 | HIGH | ecriremessage.php content limit 10000 vs config MESSAGE_MAX_LENGTH=5000 | ecriremessage.php | C |
| HIGH-007 | HIGH | Forum post content uses hardcoded 10000, no config constant | listesujets.php, sujet.php | C |
| HIGH-008 | HIGH | submit() image/nom/style/classe/link not htmlspecialchars'd | includes/ui_components.php | D |
| HIGH-009 | HIGH | checkbox() name not escaped in attributes | includes/ui_components.php | D |
| HIGH-010 | HIGH | basicprivatehtml.php innerHTML for resource counters | includes/basicprivatehtml.php | D |
| HIGH-011 | HIGH | player.php innerHTML for production points display | includes/player.php | D |
| HIGH-012 | HIGH | chipInfo()/popover() unescaped image/nom params | includes/ui_components.php | D |
| HIGH-013 | HIGH | login_history created utf8mb4, FK added before charset fix | migrations/0020 | E |
| HIGH-014 | HIGH | account_flags/admin_alerts created utf8mb4 | migrations/0021 | E |
| HIGH-015 | HIGH | attack_cooldowns.defender FK missing until 0035 | migrations/0018 | E |
| HIGH-016 | HIGH | grades PK missing until 0035, PREPARE/EXECUTE fragile | migrations/0035 | E |
| HIGH-017 | HIGH | 0018 orphan cleanup commented out, DELETEs only in 0041 | migrations/0018 | E |
| HIGH-018 | HIGH | VP display uses DENSE_RANK but award uses sequential counter | classement.php, includes/player.php | F |
| HIGH-019 | HIGH | awardPrestigePoints uses sequential counter for rank bonuses | includes/prestige.php | F |
| HIGH-020 | HIGH | Forum widget on index.php leaks alliance-private topics | index.php | C |
| MED-001 | MED | Compound pillage bonus not snapshotted at launch time | attaquer.php, includes/combat.php | B |
| MED-002 | MED | Report template hardcodes 4 classes instead of $nbClasses | includes/game_actions.php | A |
| MED-003 | MED | GET requests during maintenance phase 1 still mutate game state | includes/basicprivatephp.php | B |
| MED-004 | MED | Transfer recipient resources read without FOR UPDATE | marche.php | G |
| MED-005 | MED | Transfer can set sender resources negative (no GREATEST guard) | marche.php | G |
| MED-006 | MED | tradeVolume LEAST cap uses 'is' type vs 'd' increment | marche.php | G |
| MED-007 | MED | Alliance idalliance strict comparison with integer (=== 0 vs string "0") | allianceadmin.php | G |
| MED-008 | MED | Invitation tag stale after alliance tag rename | allianceadmin.php | G |
| MED-009 | MED | Leader transfer TOCTOU (not in transaction) | allianceadmin.php | G |
| MED-010 | MED | coordonneesAleatoires crashes on sentinel coordinates x=-1000 | includes/player.php | H |
| MED-011 | MED | Legacy mission rewards not transaction-safe (double-grant race) | includes/basicprivatehtml.php | H |
| MED-012 | MED | Embuscade formation description omits activation condition | regles.php | H |
| MED-013 | MED | Raw IP addresses logged in log context arrays | includes/basicpublicphp.php, inscription.php, etc. | H |
| MED-014 | MED | Raw IP stored in DB without purge policy | includes/multiaccount.php | H |
| MED-015 | MED | editer.php no content length check on edit | editer.php | C |
| MED-016 | MED | editer.php no title length check on topic edit | editer.php | C |
| MED-017 | MED | ecriremessage.php [all] broadcast not in transaction | ecriremessage.php | C |
| MED-018 | MED | messageCommun.php sender uses $_SESSION not ADMIN_LOGIN constant | messageCommun.php | C |
| MED-019 | MED | vieIonisateur and vieXxx columns lack CHECK >= 0 | migrations | I |
| MED-020 | MED | autre.tradeVolume lacks CHECK >= 0 | migrations | I |
| MED-021 | MED | 0014 ADD INDEX without IF NOT EXISTS | migrations/0014 | I |
| MED-022 | MED | compound bonus columns DECIMAL(5,4) may overflow | migrations/0039 | I |
| MED-023 | MED | vieDepot DEFAULT 30 inconsistent with others DEFAULT 0 | migrations/0002 | I |
| MED-024 | MED | migrate.php error detection has end-of-file edge case | migrations/migrate.php | I |
| MED-025 | MED | 0003-0012 ADD COLUMN without IF NOT EXISTS | migrations/0003-0012 | I |
| MED-026 | MED | 0002 ADD INDEX without IF NOT EXISTS | migrations/0002 | I |
| MED-027 | MED | messages/sanctions InnoDB but no FKs added | migrations/0040 | I |
| MED-028 | MED | Two files share prefix 0039 | migrations | I |
| MED-029 | MED | season_recap FK not at table creation | migrations/0029 | I |
| MED-030 | MED | player_compounds.activated_at/expires_at NULL ambiguity | migrations/0024 | I |
| MED-031 | MED | Daily leaderboard ignores sort column | classement.php | F |
| MED-032 | MED | Alliance ranking no DENSE_RANK for ties | classement.php | F |
| MED-033 | MED | bilan.php atom production omits node/compound/spec bonuses | bilan.php | F |
| MED-034 | MED | laboratoire.php GC probability hardcoded 1/20 | laboratoire.php | J |
| MED-035 | MED | select option hauteur not escaped | includes/ui_components.php | D |
| MED-036 | MED | form sup param injected raw | includes/ui_components.php | D |
| MED-037 | MED | chipInfo() image unescaped | includes/ui_components.php | D |
| MED-038 | MED | popover() nom/image unescaped | includes/ui_components.php | D |
| MED-039 | MED | imageLabel() lien in href unescaped | includes/display.php | D |
| MED-040 | MED | version.php exposes git hash to unauthenticated users | version.php | J |
| MED-041 | MED | Sell gives 0 energy for atoms at price floor (asymmetry) | marche.php | G |
| MED-042 | MED | Admin session not DB-backed (no server-side revocation) | admin/index.php | H |
| MED-043 | MED | Specialization display on molecule.php ignores spec modifiers | molecule.php | J |
| MED-044 | MED | revenuEnergie() queries constructions redundantly | includes/game_resources.php | J |
| LOW-001 | LOW | SECRET_SALT hardcoded in config.php | includes/config.php | J |
| LOW-002 | LOW | Rate limiter file read/write has narrow race (LOCK_EX on write only) | includes/rate_limiter.php | J |
| LOW-003 | LOW | Sensitive login identifiers in failed login logs | includes/basicpublicphp.php | J |
| LOW-004 | LOW | Market sell no rate limit on transfers | marche.php | G |
| LOW-005 | LOW | Alliance quitter leave not atomic | alliance.php | G |
| LOW-006 | LOW | Grade encoding fragile (implicit string conversion) | allianceadmin.php | G |
| LOW-007 | LOW | season_recap lacks composite indexes | migrations/0029 | I |
| LOW-008 | LOW | account_flags lacks (status, created_at) composite index | migrations/0021 | I |
| LOW-009 | LOW | season_recap.molecules_perdues is DOUBLE not BIGINT | migrations/0029 | I |
| LOW-010 | LOW | prestige.unlocks DEFAULT '' vs NULL design smell | migrations/0007 | J |
| LOW-011 | LOW | 0002 does not set ENGINE=InnoDB on tables it modifies | migrations/0002 | I |
| LOW-012 | LOW | Countdown UTC+1 label hardcoded (DST issue) | js/countdown.js | J |
| LOW-013 | LOW | countdown.js nonce redundant on external src | includes/copyright.php | J |
| LOW-014 | LOW | js/loader.js dead file in repository | js/loader.js | J |
| LOW-015 | LOW | Market volatility counts 31-day players not active traders | marche.php | J |
| LOW-016 | LOW | Prestige VP display pre-class label stale at +30% | checklist only | J |
| LOW-017 | LOW | center tag deprecated HTML | includes/ui_components.php | J |
| LOW-018 | LOW | classement.php player search rank uses COUNT not DENSE_RANK | classement.php | F |
| LOW-019 | LOW | Login streak day 1 code structure confusing | includes/player.php | J |
| LOW-020 | LOW | DEPLOYMENT.md says 5 days beginner protection, code says 3 | docs/DEPLOYMENT.md | J |
| LOW-021 | LOW | Market tutorial trivially satisfied by page visit | includes/basicprivatehtml.php | J |

---

## Batch Definitions

### Batch A — CRITICAL: Combat Reports (game_actions.php)
Fix CRIT-001 and MED-002 together (same file, same issue).
- `$classeAttaquant1` → `$classeAttaquant[1]` throughout game_actions.php report template
- `$classeDefenseur1` → `$classeDefenseur[1]` throughout
- Replace variable-variable access `${'classeAttaquant' . $i}` → `$classeAttaquant[$i]`
- Refactor report loops to use `$nbClasses` dynamically

### Batch B — HIGH: Season/Session + Combat FOR UPDATE
- Unify advisory lock names (tvlw_season_reset everywhere)
- performSeasonEnd throws exception on lock fail (not silent return)
- Email queue guarded by $seasonResetOk
- Resources SELECT → FOR UPDATE in combat.php
- Compound pillage snapshot (add column + attaquer.php + combat.php)
- GET requests return 503 during maintenance phase 1 transition

### Batch C — HIGH: Forum Content Safety
- messageCommun.php: wrap broadcast in withTransaction
- ecriremessage.php: use MESSAGE_MAX_LENGTH constant; [all] broadcast in tx; maxlength on title
- listesujets.php + sujet.php: define FORUM_POST_MAX_LENGTH constant, use it
- editer.php: add content and title length checks
- messageCommun.php: use ADMIN_LOGIN constant as sender
- index.php: forum widget filter alliance-private topics

### Batch D — HIGH: UI Component Escaping
- submit(): escape image, nom, style, classe, link
- checkbox(): escape name/id
- chipInfo(): escape image
- popover(): escape nom, image
- select hauteur: escape
- form sup: document/validate
- imageLabel(): escape lien
- basicprivatehtml.php: innerHTML → textContent for resource counters
- player.php: innerHTML → textContent for production points

### Batch E — CRITICAL+HIGH: Database/Migrations
- migration 0004: change to latin1 at creation
- migration 0020: change to latin1 at creation
- migration 0021: change to latin1 at creation
- migration 0022: add EXP overflow protection (CASE WHEN)
- migration 0018: add defender FK, uncomment orphan DELETEs
- new migration 0048: fix grades PK with safe DDL (no PREPARE/EXECUTE)
- new migration 0049: CHECK constraints for vieIonisateur, tradeVolume
- migration 0014: IF NOT EXISTS on ADD INDEX
- migrations 0003-0012: add IF NOT EXISTS guards (via new idempotent migration)
- migration 0002: IF NOT EXISTS on ADD INDEX
- rename 0039_season_recap_fk → 0048 (or create as new)

### Batch F — HIGH+MED: Prestige/Ranking Consistency
- performSeasonEnd: use DENSE_RANK for VP award
- awardPrestigePoints: use DENSE_RANK for rank bonuses
- classement.php daily mode: respect $order column
- classement.php alliance: use DENSE_RANK
- bilan.php: add node/compound/spec bonus columns for atoms

### Batch G — MED: Economy/Alliance
- marche.php transfer: FOR UPDATE on recipient, GREATEST guard on deduction, type fix for tradeVolume, sell floor asymmetry
- allianceadmin.php: int cast on idalliance, update invitations on tag change, withTransaction on leader transfer
- alliance.php: withTransaction on quitter leave

### Batch H — MED: Tutorial/Auth
- player.php coordonneesAleatoires: filter out x<0 y<0 sentinel rows
- basicprivatehtml.php: wrap legacy missions in withTransaction + FOR UPDATE
- regles.php: fix Embuscade condition description
- basicpublicphp.php/inscription.php: hash IPs in log context arrays
- basicprivatephp.php: 503 on GET during maintenance transition
- maintenance.php: include basicprivatephp.php for session token validation

### Batch I — MED: Database Integrity Migrations
- New migration 0049: CHECK constraints on vieXxx columns and tradeVolume
- New migration 0050: composite indexes on season_recap and account_flags
- New migration 0051: molecules_perdues BIGINT, DECIMAL(5,4)→(6,4), messages/sanctions FKs
- New migration 0052: ENGINE=InnoDB for remaining MyISAM tables
- Fix migration 0029: add FK inline (via migration patch)
- Fix migration 0024: document NULL ambiguity, or add DEFAULT 0 NOT NULL in new migration
- Fix migrate.php: add post-loop errno check

### Batch J — LOW+MED: Cleanup
- Delete js/loader.js
- countdown.js: remove hardcoded UTC+1 label
- copyright.php: remove redundant nonce on external script
- version.php: gate git hash behind auth
- laboratoire.php: use COMPOUND_GC_PROBABILITY constant
- molecule.php: add spec modifier tooltip note
- game_resources.php: accept optional $constructions parameter
- center tags: replace with CSS
- DEPLOYMENT.md: fix BEGINNER_PROTECTION_SECONDS
- docs/config.php checklist: update stable HP +40%
