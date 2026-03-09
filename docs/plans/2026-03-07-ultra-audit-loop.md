# Ultra Audit Loop — Zero-Bug Convergence Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Achieve zero known bugs through iterative full-codebase audits, where each pass dispatches fresh specialized agents across all domains and user flows, remediates all findings, verifies fixes, then repeats until a clean pass produces zero new issues.

**Architecture:** Each audit pass is structured in 5 phases: (1) Parallel domain audits by fresh agents, (2) Consolidated remediation plan, (3) Plan review by independent reviewers, (4) Fix implementation by specialist agents, (5) Verification by spec/code reviewers. The loop terminates when a full pass finds zero issues.

**Domain taxonomy (35 domains as of Pass 9):**
BATCH A (Security & Infra): AUTH · INFRA-SECURITY · INFRA-DATABASE · ANTI_CHEAT · ADMIN · SEASON_RESET · FORUM
BATCH B (Combat & Economy): COMBAT · ESPIONAGE · ECONOMY · MARKET · BUILDINGS · COMPOUNDS · MAPS
BATCH C (Social & Cross-cutting): SOCIAL · ALLIANCE_MANAGEMENT · GAME_CORE · PRESTIGE · RANKINGS · NOTIFICATIONS · INFRA-TEMPLATES
BATCH D (Data Flow — cross-domain): TAINT-USER-INPUT · TAINT-DB-OUTPUT · TAINT-CROSS-MODULE · SCHEMA-USAGE · TAINT-EMAIL · TAINT-SESSION · TAINT-API
BATCH E (User Flows — cross-domain): FLOW-REGISTRATION · FLOW-COMBAT · FLOW-MARKET · FLOW-ALLIANCE · FLOW-SEASON · FLOW-PRESTIGE · FLOW-SOCIAL

**Tech Stack:** PHP 8.2, MariaDB 10.11, Apache 2, Framework7 CSS, jQuery 3.7.1, PHPUnit

---

## Codebase Inventory

### Page Files (46 files)
```
alliance.php, alliance_discovery.php, allianceadmin.php, api.php, armee.php,
attaque.php, attaquer.php, bilan.php, classement.php, compte.php,
comptetest.php, connectes.php, constructions.php, credits.php, deconnexion.php,
don.php, ecriremessage.php, editer.php, forum.php, guerre.php, health.php,
historique.php, index.php, inscription.php, joueur.php, laboratoire.php,
listesujets.php, maintenance.php, marche.php, medailles.php, messageCommun.php,
messages.php, messagesenvoyes.php, moderationForum.php, molecule.php,
prestige.php, rapports.php, regles.php, season_recap.php, sinstruire.php,
sujet.php, tutoriel.php, vacance.php, validerpacte.php, version.php, voter.php
```

### Include Files (38 files)
```
includes/atomes.php, basicprivatehtml.php, basicprivatephp.php,
basicpublichtml.php, basicpublicphp.php, bbcode.php, cardsprivate.php,
catalyst.php, combat.php, compounds.php, config.php, connexion.php,
constantesBase.php, copyright.php, csp.php, csrf.php, database.php,
db_helpers.php, display.php, env.php, fonctions.php, formulas.php,
game_actions.php, game_resources.php, layout.php, logger.php, meta.php,
multiaccount.php, player.php, prestige.php, rate_limiter.php,
redirectionVacance.php, resource_nodes.php, ressources.php, session_init.php,
statistiques.php, style.php, ui_components.php, validation.php
```

### Admin Files (10 files)
```
admin/index.php, ip.php, listenews.php, listesujets.php, multiaccount.php,
redigernews.php, redirectionmotdepasse.php, supprimercompte.php,
supprimerreponse.php, tableau.php
```

### JS Files (3 non-vendor)
```
js/countdown.js, js/loader.js, js/framework7.min.js
```

### Moderation Files (3 files)
```
moderation/index.php, moderation/ip.php, moderation/mdp.php
```

### Scripts (1 file)
```
scripts/cleanup_old_data.php
```

### Migrations (54 files), Tests (34 files)

---

## The Audit Loop

```
┌─────────────────────────────────────────────────┐
│                 AUDIT PASS N                     │
│                                                  │
│  Phase 1A: BATCH A — 7 agents in parallel        │
│     ↓  (wait for all 7 to finish)                │
│  Phase 1B: BATCH B — 7 agents in parallel        │
│     ↓  (wait for all 7 to finish)                │
│  Phase 1C: BATCH C — 7 agents in parallel        │
│     ↓  (wait for all 7 to finish)                │
│  Phase 1D: BATCH D — 7 DATA FLOW agents          │
│     (cross-domain: taint analysis, schema usage) │
│     ↓  (wait for all 7 to finish)                │
│  Phase 1E: BATCH E — 7 USER FLOW agents          │
│     (cross-domain: end-to-end journey testing)   │
│     ↓  (wait for all 7 to finish)                │
│  Phase 2: CONSOLIDATE (remediation plan)         │
│     ↓                                            │
│  Phase 3: REVIEW PLAN (2 reviewer agents)        │
│     ↓                                            │
│  Phase 4: FIX (specialist fix agents)            │
│     ↓                                            │
│  Phase 5: VERIFY (2 verification agents)         │
│     ↓                                            │
│  Run full test suite (PHPUnit)                   │
│     ↓                                            │
│  Commit + deploy to VPS                          │
│     ↓                                            │
│  ┌─ Findings == 0? ─── YES → DONE ✓             │
│  └─ NO → AUDIT PASS N+1 (fresh agents)          │
└─────────────────────────────────────────────────┘
```

---

## Phase 1: Domain Audits (35 Agents in 5 Batches)

To avoid CPU/resource pressure, the 35 domain agents are dispatched in **5 sequential batches of 7**. All agents within a batch run in parallel. Wait for each batch to complete before launching the next.

**Domain taxonomy (35 domains as of Pass 9):**
```
BATCH A — Security & Infrastructure (7 agents)
 1. AUTH              — Login, registration, session lifecycle
 2. INFRA-SECURITY    — CSRF, rate limiter, CSP, validation, logger
 3. INFRA-DATABASE    — DB helpers, migrations, connexion, column whitelists
 4. ANTI_CHEAT        — Multi-account detection, IP flagging, admin alerts
 5. ADMIN             — Admin dashboard, news, deletion, maintenance mode
 6. SEASON_RESET      — Season end orchestration, archive, reset cascade
 7. FORUM             — Topics, replies, moderation, bans

BATCH B — Combat & Economy (7 agents)
 8. COMBAT            — Direct attacks, army, formations, damage
 9. ESPIONAGE         — Spy missions, neutrino cost, spy reports
10. ECONOMY           — Resource production, storage, energy regeneration
11. MARKET            — Trading, price volatility, transfer security
12. BUILDINGS         — Construction queue, upgrade costs, building damage
13. COMPOUNDS         — Synthesis lab, timed compound buffs
14. MAPS              — Resource nodes, coordinates, proximity bonus

BATCH C — Social & Cross-Cutting (7 agents)
15. SOCIAL            — Player profiles, private messaging, online list
16. ALLIANCE_MGMT     — Governance, grades, research, war/pact diplomacy
17. GAME_CORE         — Tutorial, medals, voter, vacation, bilan
18. PRESTIGE          — PP earning/spending, login streak, comeback bonus
19. RANKINGS          — Leaderboard, sqrt ranking, daily/seasonal toggle
20. NOTIFICATIONS     — Email queue, combat reports display, historique
21. INFRA-TEMPLATES   — Layout, display, UI components, meta, countdown

BATCH D — End-to-End Data Flows (7 agents, cross-domain)
22. TAINT-USER-INPUT  — All $_POST/$_GET/$_COOKIE → DB write paths: validation coverage, bypass vectors
23. TAINT-DB-OUTPUT   — All DB reads → HTML/JSON/email output: escaping coverage per column
24. TAINT-CROSS-MODULE— Data passed between includes/modules (e.g. game_resources→combat, player→display)
25. SCHEMA-USAGE      — DB schema ↔ PHP usage correlation: orphaned columns, trusted-but-dirty columns
26. TAINT-EMAIL       — Data flowing into mail() headers, subjects, bodies: injection, encoding
27. TAINT-SESSION     — $_SESSION keys: what's stored, how trusted, re-validation coverage
28. TAINT-API         — api.php + json_encode paths: fields exposed, escaping, auth on each endpoint

BATCH E — End-to-End User Flows (7 agents, cross-domain)
29. FLOW-REGISTRATION — Register → verify → login → tutorial → first action: full new-player journey
30. FLOW-COMBAT       — Compose army → launch attack → flight → resolve → reports → notification: full cycle
31. FLOW-MARKET       — Browse prices → buy → storage update → price volatile → sell: full trade cycle
32. FLOW-ALLIANCE     — Create → invite → join → research → war declare → war end → leave → dissolve
33. FLOW-SEASON       — Season wind-down → maintenance → reset cascade → VP/prestige awards → new season
34. FLOW-PRESTIGE     — Earn PP (combat/streak/donation) → purchase unlock → bonus applied → season carryover
35. FLOW-SOCIAL       — Send message → read → delete → forum post → BBCode render → moderate → appeal
```

**Adaptive agent dispatch rules (apply each pass):**
- **Skip** domains that produced only INFO findings in the prior pass (no real bugs found)
- **Double agents** for domains with HIGH/CRITICAL findings in the prior pass (two independent agents, findings merged) — counts as 2 agents in the batch, so that batch becomes 8
- Domains always doubled in practice: Domain 8 (Combat), Domain 15 (Season Reset), Domain 3 (INFRA-DATABASE) — these always run 2 agents
- If a domain was CLEAN in the prior pass, still run it (fresh eyes catch regressions)
- **Collect all 3 batch outputs before proceeding to Phase 2**

Each agent produces a structured finding list in this format:

```markdown
### [SEVERITY]-[NNN]: Short title
- **File:** path/to/file.php:LINE
- **Domain:** AUTH | FORUM | COMBAT | ...
- **Description:** What is wrong (quote the actual code)
- **Impact:** What happens if not fixed
- **Suggested fix:** Concrete code change (before/after)
```

Severity levels: `CRITICAL` > `HIGH` > `MEDIUM` > `LOW`

---

### Domain 1: AUTH — Authentication & Session Lifecycle
**Scope:** Login flow, registration, logout, account settings, session validation, password management.
**Agent type:** `comprehensive-review:security-auditor`

**Files to audit (ALL of these):**
```
includes/basicpublicphp.php   — login form handler, bcrypt verify, session init
includes/basicprivatephp.php  — session guard for all private pages (idle timeout, token DB check)
includes/session_init.php     — secure session config (httponly, samesite, name)
inscription.php               — player registration (input validation, rate limit, uniqueness)
deconnexion.php               — logout (session destroy, token wipe, cookie erasure)
compte.php                    — account settings (pw change, email change, avatar, vacation, delete)
comptetest.php                — debug tool (should be gated or removed in production)
```

**Checklist:**
- [ ] Session fixation prevented (session_regenerate_id after login)
- [ ] Session idle timeout enforced (SESSION_IDLE_TIMEOUT from config)
- [ ] Session token validated against DB on every private page load
- [ ] CSRF on all POST forms in this domain (inscription, compte, deconnexion)
- [ ] Password hashing is bcrypt (no MD5/SHA1 for new passwords, MD5 auto-upgrade on login)
- [ ] Registration rate limiting enforced (3 accounts/hour/IP)
- [ ] Login rate limiting enforced (10 attempts/5min/IP)
- [ ] validateLogin() / validateEmail() used on all registration inputs
- [ ] Account deletion has 7-day cooldown
- [ ] Cookie flags: httponly=true, samesite=Strict, secure when HTTPS
- [ ] comptetest.php: is this behind auth? Is it accessible from web? Should it be deleted?
- [ ] Vacation mode cannot be enabled during active combat
- [ ] Password change requires current password verification
- [ ] Email change requires current password verification

---

### Domain 2: FORUM — Topics, Replies, Moderation & Bans
**Scope:** Forum listing, topic creation, replies, editing, hiding, moderator sanctions.
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
forum.php                     — forum category listing (alliance-private filtering)
listesujets.php               — topic list + new topic form (rate limit 10/300s)
sujet.php                     — topic thread + reply form (rate limit 10/300s, ban check)
editer.php                    — edit/delete/hide/show forum post (author + moderator gate)
moderationForum.php           — moderator panel: ban list, create/remove sanctions
includes/bbcode.php           — BBCode parser ([img] restriction, [url] sanitization)
admin/listesujets.php         — admin topic management
admin/supprimerreponse.php    — admin reply deletion
```

**Checklist:**
- [ ] BBCode [img] only allows relative paths or whitelisted domains (no arbitrary URLs)
- [ ] BBCode output is XSS-safe (htmlspecialchars applied before/after BBCode parsing)
- [ ] Forum access by alliance_id correctly filters private forums
- [ ] Reply rate limit enforced (10/300s per player, via rateLimitCheck)
- [ ] Topic creation rate limit enforced
- [ ] CSRF on all POST actions (reply, edit, delete, hide, ban)
- [ ] editer.php: ban check on moderator happens BEFORE POST['contenu'] handler (not after)
- [ ] editer.php: author check (can't edit someone else's post unless moderator)
- [ ] moderationForum.php: rate limit on sanction creation (rateLimitCheck sanction_create 20/3600)
- [ ] moderationForum.php: moderator gate before any action
- [ ] sujet.php: expired ban check (GC probabilistic + nightly cron)
- [ ] Pagination LIMIT/OFFSET values are integer-cast (no SQL injection)
- [ ] admin/supprimerreponse.php requires admin authentication

---

### Domain 3: COMBAT — Direct Attacks, Army & Formations
**Scope:** Attack launch (type=1), army composition, molecule creation/deletion, formation change, attack resolution, combat reports.
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
attaquer.php                  — attack launcher (type=1 branch only; espionage in Domain 4)
attaque.php                   — single attack report detail viewer
armee.php                     — army composition, molecule creation, neutrino purchase, formation
rapports.php                  — combat reports list (attacker/defender entries)
includes/combat.php           — combat resolution engine (damage, formation, pillage, reports)
includes/formulas.php         — combat formulas (attaque(), defense(), vitesse(), demiVie())
includes/config.php           — combat constants (VAULT_PERCENT, DEFENSE_REWARD, BEGINNER_DAYS, etc.)
includes/game_actions.php     — updateActions(): resolves pending attacks from actionsattaques
includes/player.php           — army HP functions, supprimerJoueur cascade
```

**Checklist:**
- [ ] Damage formula: correct variable assignment (attacker damage applied to defender, not self)
- [ ] Formation modifiers: Phalange (+50% HP), Dispersée (equal split), Embuscade (+40% initiative)
- [ ] Isotope HP bonus: Stable class gets +30% HP; radioactive decay applied correctly
- [ ] Beginner protection window: BEGINNER_DAYS from config, comparison uses correct timestamp
- [ ] Pillage only on attacker victory (not on draw or defender win)
- [ ] Vault percentage (VAULT_PERCENT from config) protects correct portion of resources
- [ ] Overkill cascade: no negative HP, kill count computed correctly
- [ ] Building damage: weighted targeting (ionisateur, champdeforce), level floor ≥ 1 for core buildings
- [ ] Return trip guard: army returns to sender even if target account deleted mid-flight
- [ ] Combat cooldown enforced between attacks on same target
- [ ] Covalent synergy bonus: correct element pair detection and bonus application
- [ ] Defense reward (DEFENSE_REWARD from config) applied when defender wins
- [ ] Combat report variables: winner/loser names are DB-fetched names, not $_SESSION
- [ ] withTransaction() wraps full combat resolution (no partial commits)
- [ ] Army count: can't send more molecules than you own (validated pre-flight)
- [ ] Molecule creation: energy cost deducted, FOR UPDATE on ressources
- [ ] Molecule deletion: transaction + cascade to pending attacks removed or returned
- [ ] Neutrino purchase: FOR UPDATE on ressources, cost deducted atomically
- [ ] CSRF ×2 on armee.php (molecule creation + neutrino purchase are separate POST actions)

---

### Domain 4: ESPIONAGE — Spy Missions & Intelligence Reports
**Scope:** Espionage flow (attaquer.php type=2), neutrino deduction, spy report generation and display.
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
attaquer.php                  — espionage branch (type=2): neutrino check, rate limit, target validation
rapports.php                  — spy report display (espionage entries filtered by type)
includes/config.php           — espionage constants (ESPIONAGE_NEUTRINO_COST, rate limits per formula)
includes/formulas.php         — espionage formulas (spy success probability, info revealed)
```

**Checklist:**
- [ ] Neutrino cost deducted via FOR UPDATE on ressources (no race condition)
- [ ] Espionage rate limit enforced per formula (from config, not hardcoded)
- [ ] CSRF on espionage POST
- [ ] Spy can't target self
- [ ] Beginner protection: espionage blocked during beginner window
- [ ] Vacation mode: can't spy on vacationing players (or vice versa — check both)
- [ ] Spy report contents: only reveals what the formula permits (no excessive data leak)
- [ ] Tutorial espionage step: requires valid target (not a deleted or non-existent account)
- [ ] Spy report display: XSS-safe (htmlspecialchars on all player-controlled fields in report)
- [ ] Spy failure: neutrinos still consumed even on failure (by design — verify)
- [ ] rapports.php pagination: LIMIT/OFFSET integer-cast

---

### Domain 5: ECONOMY — Resource Production, Storage & Energy
**Scope:** Energy regeneration, atom production, storage caps, resource update loop, compound/spec/node bonuses.
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
includes/game_resources.php   — updateRessources(), revenuEnergie(), all production formulas
includes/formulas.php         — production bonus formulas (duplicateur, research, catalyst)
includes/ressources.php       — resource display helpers
includes/config.php           — economy constants (ENERGY_BASE, ATOM_RATE_*, STORAGE_*, etc.)
don.php                       — alliance donation (min reserve check, FOR UPDATE)
sinstruire.php                — specialization tutorial (condenseur mechanics explanation)
```

**Checklist:**
- [ ] Energy regeneration formula matches config constants (ENERGY_BASE + building bonuses)
- [ ] Atom gathering rate per type uses config constants (ATOM_RATE_C/N/H/O/Cl/S/Br/I)
- [ ] Storage cap enforced: can't exceed placeDepot() for atoms, energy cap for energy
- [ ] LEAST/GREATEST guards prevent negative resources in updateRessources()
- [ ] updateRessources() performs a single atomic UPDATE (no read-then-write race)
- [ ] Resource node proximity bonus: getResourceNodeBonus() called with correct coordinates
- [ ] Compound bonus: getCompoundBonus() applied to production correctly
- [ ] Specialization modifier: getSpecModifier() applied without double-counting
- [ ] Donation minimum reserve: player must keep ≥ MIN_DONATION_RESERVE energy
- [ ] Donation transaction: withTransaction() + FOR UPDATE on ressources, autre, alliances
- [ ] donation.nbDons counter incremented (used for medal tracking)
- [ ] Weekend catchup multiplier: only applies weeks 2-3 of season, correct date check
- [ ] Comeback bonus resource grant: correct amounts from config, 7-day cooldown respected

---

### Domain 6: MARKET — Trading, Price Volatility & Transfer Security
**Scope:** Resource buy/sell, player-to-player transfers, price volatility, multi-account block.
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
marche.php                    — market UI: buy, sell, player transfer forms + price chart
includes/formulas.php         — market pricing (spread, slippage, volatility formulas)
includes/config.php           — market constants (MARKET_SPREAD, MARKET_SLIPPAGE_*, MARKET_VOLATILITY_*)
includes/game_resources.php   — getTradeVolume(), updateTradeVolume()
```

**Checklist:**
- [ ] Buy price always > sell price (spread always positive, no arbitrage)
- [ ] Global slippage calculation uses trade volume FOR UPDATE (TOCTOU prevention)
- [ ] Volatility dampening coefficient from config (not hardcoded)
- [ ] Price bounds: no negative prices, no infinite prices (clamp applied)
- [ ] Market purchases respect storage limits: recipient can't receive more than available space
- [ ] Player transfer: areFlaggedAccounts() blocks transfers between flagged multi-account pairs
- [ ] Player transfer: rate limit (10/60s per player, rateLimitCheck)
- [ ] Player transfer: can't transfer to self
- [ ] Buy/sell transactions: withTransaction() + FOR UPDATE on ressources
- [ ] Market chart timestamps: correct timezone formatting (no Ã→à encoding bug)
- [ ] Minimum trade amount enforced (can't buy/sell 0 or negative)
- [ ] CSRF on all buy/sell/transfer POSTs
- [ ] Market tutorial hint: shown for new players (< N days old, from config)

---

### Domain 7: COMPOUNDS — Synthesis Lab & Timed Buffs
**Scope:** Compound synthesis, atom cost deduction, active buff lifecycle, cache, cleanup.
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
laboratoire.php               — synthesis lab UI: recipes, inventory, activate/synthesize
includes/compounds.php        — synthesizeCompound(), getStoredCompounds(), getActiveCompounds(),
                                getCompoundBonus(), cleanupExpiredCompounds()
includes/config.php           — compound constants (COMPOUND_DURATION_*, COMPOUND_COST_*, 5 compounds)
includes/catalyst.php         — catalyst weekly rotation (can modify compound costs)
```

**Checklist:**
- [ ] Synthesis requirements: all 5 compounds have correct atom costs from config
- [ ] Atom cost deduction: withTransaction() + FOR UPDATE on ressources
- [ ] Synthesis rate limit: rateLimitCheck('compound_synth', 5, 60)
- [ ] Buff duration: each compound uses correct COMPOUND_DURATION_* constant
- [ ] Buff activation: only one active buff of same type at a time (or stackable — verify)
- [ ] Compound cache: getCompoundBonus() uses global (not static) cache to ensure cross-request invalidation
- [ ] cleanupExpiredCompounds(): called on lab page load, removes expired player_compounds rows
- [ ] CSRF on synthesis POST and activate POST
- [ ] Compound display: correct htmlspecialchars on all user-visible compound fields
- [ ] Atom cost display in lab UI matches actual synthesis cost (no drift from config)
- [ ] Catalyst discount on compound cost applied correctly (catalystEffect())

---

### Domain 8: BUILDINGS — Construction Queue, Upgrades & Combat Damage
**Scope:** Building level management, upgrade cost/time formulas, construction queue processing, building HP damage in combat.
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
constructions.php             — building UI: current levels, upgrade button, production point allocation,
                                formation change (all 3 POST actions: upgrade, producteur pts, condenseur pts)
includes/game_actions.php     — updateActions(): processes actionsconstruction queue (completes upgrades)
                                augmenterBatiment(), diminuerBatiment()
includes/combat.php           — building combat damage (diminuerBatiment called after combat)
includes/config.php           — BUILDING_CONFIG array (all building costs, times, bonuses)
includes/formulas.php         — building production formulas, storage formulas
```

**Checklist:**
- [ ] All building upgrade costs come from BUILDING_CONFIG (no hardcoded values)
- [ ] All building upgrade times come from BUILDING_CONFIG (no hardcoded values)
- [ ] Construction queue uniqueness: can't queue the same building twice (check before INSERT into actionsconstruction)
- [ ] Construction FOR UPDATE: ressources locked during cost deduction
- [ ] Production point allocation: total producteur + condenseur ≤ MAX_PRODUCTION_POINTS, FOR UPDATE
- [ ] Building level floor: champdeforce and producteur can't go below level 1 via combat
- [ ] diminuerBatiment(): level floor ≥ 1 enforced for core buildings
- [ ] augmenterBatiment(): level cap enforced (MAX_BUILDING_LEVEL from config)
- [ ] updateActions() in withTransaction() (building completion is atomic)
- [ ] Building completion: molecule counts updated correctly after construction finishes
- [ ] Formation change: valid formation values (Phalange/Dispersée/Embuscade only)
- [ ] Building damage in combat: weighted targeting respects building weights from config
- [ ] Ionisateur: damageable flag checked, HP tracking correct, not counted as combat building
- [ ] CSRF ×3 on constructions.php (upgrade, producteur points, condenseur points are separate)
- [ ] Building bonus display in UI matches BUILDING_CONFIG values

---

### Domain 9: SOCIAL — Profiles, Messaging & Online List
**Scope:** Player profile viewing, private/alliance/broadcast messaging, sent messages, online list.
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
joueur.php                    — public player profile (stats, alliance, location, description, medals)
ecriremessage.php             — compose private/alliance/broadcast message
messages.php                  — private message inbox + delete + read marking
messagesenvoyes.php           — sent messages folder
messageCommun.php             — alliance shared message board (read-only display)
connectes.php                 — online players list (last seen tracking)
```

**Checklist:**
- [ ] joueur.php: htmlspecialchars on all user-controlled fields (description, login, alliance tag)
- [ ] joueur.php: rate limit on GET (60/min/IP via rateLimitCheck) — player enumeration prevention
- [ ] joueur.php: no information disclosure (hidden stats, email not shown, IP not shown)
- [ ] ecriremessage.php: private message rate limit (10/300s per player)
- [ ] ecriremessage.php: alliance broadcast rate limit (3/300s per player)
- [ ] ecriremessage.php: global broadcast is admin-only (check gate)
- [ ] ecriremessage.php: canonical login resolution (can't spoof login casing)
- [ ] ecriremessage.php: can't message self
- [ ] ecriremessage.php: CSRF on send POST
- [ ] messages.php: soft-delete (deleted_by_sender / deleted_by_recipient flags)
- [ ] messages.php: cascade delete when both sides deleted (no orphan message rows)
- [ ] messages.php: authorization — can only delete own messages
- [ ] messages.php: CSRF on delete POST
- [ ] messages.php: read marking only sets flag if current user is recipient
- [ ] connectes.php: no XSS on online player list (login names escaped)
- [ ] Last seen indicator on joueur.php: sourced from connectes/derniere_connexion (not manipulable)

---

### Domain 10: ALLIANCE_MANAGEMENT — Governance, Grades, Research & Diplomacy
**Scope:** Alliance create/join/leave, grade permissions, research upgrades, duplicateur, war/pact declaration and acceptance.
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
alliance.php                  — alliance home: create, join, leave, duplicateur upgrade, war/pact initiation
allianceadmin.php             — admin panel: invite/remove members, assign grades, name/description change,
                                delete alliance
alliance_discovery.php        — public alliance browser (read-only, alliance stats)
validerpacte.php              — pact/war acceptance (grade-based auth, FOR UPDATE declaration lock)
guerre.php                    — war declaration detail + accept UI
don.php                       — alliance donation (also in Domain 5 — check the donation security here)
includes/game_actions.php     — alliance-related action functions
```

**Checklist:**
- [ ] Grade "0" truthy bug: all grade comparisons use strict === not loose == or truthy check
- [ ] Pact duplicate check: can't create same pact twice (pre-INSERT SELECT check)
- [ ] War declaration: withTransaction() wrapping
- [ ] Alliance tag: uniqueness enforced, preg_match validates tag format
- [ ] Grade permission bits: create/delete/promote/demote use correct bit masks
- [ ] Cooldown on rejoin after leaving alliance (ALLIANCE_REJOIN_COOLDOWN from config)
- [ ] Duplicateur bonus: bonusDuplicateur() formula uses correct duplicateur level
- [ ] Research tree: point spending deducted from alliance energy, each branch capped
- [ ] Victory points calculation correct at season end
- [ ] Alliance member count: enforced via max_membres check before invite
- [ ] Alliance dissolution: cleanup cascades (grades, invitations, declarations, messages)
- [ ] Stale grade access: player kicked from alliance loses grade immediately (no stale session)
- [ ] validerpacte.php: grade-based auth checked (not just any logged-in player)
- [ ] validerpacte.php: FOR UPDATE on declarations (prevents double-acceptance race)
- [ ] CSRF on all alliance POST actions (create, leave, upgrade, invite, remove, assign grade)
- [ ] alliance_discovery.php: read-only, no mutations

---

### Domain 11: GAME_CORE — Tutorial, Medals, Voter, Vacation & Bilan
**Scope:** Tutorial missions, medal display, sondage/voter, vacation mode, specialization UI, molecule reference, bonus summary.
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
tutoriel.php                  — tutorial missions (progression, claim reward, espionage step)
medailles.php                 — medal display and progress bars (reads autre counters)
voter.php                     — poll voting (INSERT IGNORE, FOR UPDATE, CSRF, session token)
vacance.php                   — vacation mode activation/deactivation
includes/redirectionVacance.php — vacation mode redirect guard (included by action pages)
bilan.php                     — comprehensive bonus summary page (all formulas and modifiers)
molecule.php                  — molecule/unit stat viewer (public reference, read-only)
sinstruire.php                — chemistry specialization tutorial (read-only)
```

**Checklist:**
- [ ] Tutorial mission progression: can't claim step N without completing step N-1
- [ ] Tutorial reward escalation: reward values match config escalation table
- [ ] Tutorial espionage step: requires a valid (existing, non-self) target
- [ ] Tutorial reward claim: DB re-verified inside transaction before awarding
- [ ] Medal thresholds: all thresholds sourced from config (not hardcoded in medailles.php)
- [ ] Medal progress bar: correct percentage calculation (no divide-by-zero)
- [ ] voter.php: INSERT IGNORE prevents duplicate votes
- [ ] voter.php: FOR UPDATE on sondages row prevents TOCTOU on vote count
- [ ] voter.php: session token validated (not just session)
- [ ] voter.php: CSRF on vote POST
- [ ] Vacation mode: cannot activate during active combat (pending attacks out/in)
- [ ] Vacation mode: blocks all resource-producing actions while active
- [ ] bilan.php: every displayed formula matches the actual formula in game_resources.php / combat.php
- [ ] bilan.php: specialization choice is irreversible (locked after first pick)
- [ ] molecule.php: read-only, no auth required, no mutations

---

### Domain 12: PRESTIGE — PP Earning, Spending, Login Streak & Comeback Bonus
**Scope:** Prestige point lifecycle (earn via medals/combat/activity, spend on unlocks), login streak rewards, comeback bonus.
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
prestige.php                  — prestige UI: PP balance, unlock shop, earning guide, bonus display
includes/prestige.php         — getPrestige(), calculatePrestigePoints(), purchasePrestigeUnlock(),
                                hasPrestigeUnlock(), awardPrestigePoints(), isPrestigeLegend()
includes/player.php           — updateLoginStreak(), checkComebackBonus()
includes/config.php           — prestige constants (PRESTIGE_UNLOCK_*, STREAK_MILESTONES, COMEBACK_*)
```

**Checklist:**
- [ ] PP earning formulas: each source (medals, combat wins, economy, streak) uses config constants
- [ ] PP spending: purchasePrestigeUnlock() deducts PP atomically (FOR UPDATE on prestige row)
- [ ] Double-season guard: awardPrestigePoints() can't run twice in same season
- [ ] Prestige unlocks: each unlock's bonus is applied in the correct domain (production, combat, etc.)
- [ ] Prestige bonus stacking: bonuses don't double-count when multiple unlocks active
- [ ] Veteran unlock: conditions correct (N seasons played, from config)
- [ ] Login streak: updateLoginStreak() uses DATE() comparison (timezone-safe)
- [ ] Streak milestones: PP awards at 1/3/7/14/21/28 days match STREAK_MILESTONES config
- [ ] Streak reset: streak_days reset to 1 (not 0) when gap > 1 day
- [ ] Comeback bonus: 3-day absence threshold from config (COMEBACK_ABSENCE_DAYS)
- [ ] Comeback bonus: 7-day cooldown enforced (COMEBACK_COOLDOWN_DAYS from config)
- [ ] Comeback bonus: energy + atom grant amounts from config (COMEBACK_ENERGY, COMEBACK_ATOMS)
- [ ] Comeback shield: 24h shield duration from config (COMEBACK_SHIELD_HOURS)
- [ ] CSRF on prestige unlock purchase POST

---

### Domain 13: RANKINGS — Leaderboard, Sqrt Formula & Seasonal Toggle
**Scope:** Player rankings across 4 categories (points, attack, defense, pillage), daily/seasonal display, frozen rankings during season end.
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
classement.php                — ranking page (4 leaderboards, daily/seasonal toggle)
includes/statistiques.php     — player stats aggregation helpers
includes/formulas.php         — ranking score formula (sqrt weighting)
includes/config.php           — ranking constants (category weights, season freeze window)
```

**Checklist:**
- [ ] Sqrt ranking formula applied correctly (weights from config, not hardcoded)
- [ ] Category weights match documented game rules
- [ ] Daily toggle: daily scores sourced correctly (separate column or daily snapshot)
- [ ] Seasonal toggle: full-season cumulative score sourced correctly
- [ ] Rankings frozen during season transition window (no misleading partial-reset data)
- [ ] ORDER BY column: uses whitelist (no SQL injection via user-supplied column name)
- [ ] LIMIT/OFFSET pagination: integer-cast (no SQL injection)
- [ ] No auth required (classement.php is public) — confirmed read-only, no mutations
- [ ] XSS: login names, alliance tags escaped with htmlspecialchars

---

### Domain 14: ADMIN — Dashboard, News, Account Deletion & Maintenance
**Scope:** Admin authentication, admin dashboard stats, news management, manual account deletion, maintenance mode toggle.
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
admin/index.php               — admin dashboard (auth gate, stats, deletion by IP, maintenance toggle)
admin/supprimercompte.php     — single account deletion (auth gate, CSRF, audit log)
admin/redirectionmotdepasse.php — admin password gate (legacy — should not be the sole auth for actions)
admin/tableau.php             — admin data tables (stats display, broken query cleanup)
admin/listenews.php           — news listing (requires admin auth)
admin/redigernews.php         — news creation/editing (requires admin auth, CSRF)
maintenance.php               — maintenance mode toggle page
moderation/index.php          — moderator panel
moderation/ip.php             — IP lookup tool
moderation/mdp.php            — moderator password tool
scripts/cleanup_old_data.php  — nightly cleanup script (expired sanctions, rate limit files)
```

**Checklist:**
- [ ] admin/supprimercompte.php: auth gate is basicprivatephp.php + ADMIN_LOGIN check (NOT redirectionmotdepasse only)
- [ ] admin/supprimercompte.php: csrfCheck() called FIRST inside POST block (before supprimerJoueur)
- [ ] admin/supprimercompte.php: logInfo('ADMIN', 'Player deleted', ...) called after deletion
- [ ] admin/supprimercompte.php: supprimerJoueur() wrapped in withTransaction()
- [ ] admin/tableau.php: no queries referencing non-existent tables (signalement, lieux — must be removed)
- [ ] admin/index.php: supprimerJoueur() for IP-batch deletion also wrapped in withTransaction()
- [ ] admin/index.php: manual season reset calls performSeasonEnd() correctly (with advisory lock)
- [ ] admin/listenews.php + redigernews.php: require admin authentication, CSRF on POST
- [ ] moderation/* files: require appropriate auth level (moderator or admin)
- [ ] maintenance.php: CSRF on toggle POST, admin auth required
- [ ] scripts/cleanup_old_data.php: not callable via HTTP (CLI-only guard, e.g., php_sapi_name() check)
- [ ] cleanup_old_data.php: includes `DELETE FROM sanctions WHERE dateFin < CURDATE()` (nightly ban GC)

---

### Domain 15: SEASON_RESET — Season End Orchestration, Archive & Cascade Reset
**Scope:** End-of-season detection, winner determination, archiving, 15-table wipe, VP awards, session invalidation, post-reset setup.
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
includes/player.php           — performSeasonEnd(), remiseAZero(), archiveSeasonData(),
                                awardPrestigePoints() (season-end batch), processEmailQueue()
includes/basicprivatephp.php  — season end trigger logic (maintenance check + performSeasonEnd call)
season_recap.php              — past season history display (reads season_recap table)
includes/config.php           — season constants (SEASON_DURATION_DAYS, MAINTENANCE_DURATION_HOURS, etc.)
```

**Checklist:**
- [ ] Winner determination: uses DB-fetched winner login (NOT $_SESSION['login'])
- [ ] Advisory lock: performSeasonEnd() acquires GET_LOCK before proceeding (prevents double-reset)
- [ ] Archive phase: archiveSeasonData() runs BEFORE remiseAZero() (data preserved before wipe)
- [ ] 15-table reset completeness: all tables wiped in remiseAZero() (check against full table list)
- [ ] VP awards: awardPrestigePoints() called with correct winner list before reset
- [ ] Session invalidation: all session_tokens set to NULL post-reset (forces re-login)
- [ ] Resource node generation: generateResourceNodes() called post-reset for new season map
- [ ] Winner news post: inserted into news table post-reset with correct winner name
- [ ] Email queue: queued notifications sent to top players post-reset
- [ ] Two-phase maintenance: phase 1 (maintenance flag set) → 24h pause → phase 2 (reset runs)
- [ ] season_recap table: populated correctly with archived stats
- [ ] season_recap.php: read-only, requires auth, XSS-safe output
- [ ] processEmailQueue(): probabilistic drain (not blocking, no infinite loop)
- [ ] No nested transaction risk: each reset step's failure is logged but doesn't rollback prior steps (by design — verify this is acceptable)

---

### Domain 16: ANTI_CHEAT — Multi-Account Detection & Admin Alerts
**Scope:** Login event logging, same-IP/fingerprint detection, coordinated attack patterns, admin alert dashboard.
**Agent type:** `comprehensive-review:security-auditor`

**Files to audit (ALL of these):**
```
includes/multiaccount.php     — logLoginEvent(), checkSameIpAccounts(), checkSameFingerprintAccounts(),
                                checkTimingCorrelation(), checkCoordinatedAttacks(), checkTransferPatterns(),
                                areFlaggedAccounts(), createAdminAlert()
includes/basicpublicphp.php   — calls logLoginEvent() on successful login (line ~74)
admin/multiaccount.php        — admin flag dashboard: view alerts, mark read, update flag status, add manual flag
admin/ip.php                  — IP lookup tool
```

**Checklist:**
- [ ] logLoginEvent(): inserts login_history row on every successful login (not just first)
- [ ] IP hashing: GDPR-compliant — raw IP is NOT stored, only hash (verify in logLoginEvent)
- [ ] Fingerprint: UA + accept-language hash used (not full UA string stored raw)
- [ ] Same-IP detection: 30-day window check, correct flag creation in account_flags
- [ ] Fingerprint collision: creates flag only when both accounts have logged in (not hypothetical)
- [ ] Timing correlation: accounts never online simultaneously → flag (check the query logic)
- [ ] Coordinated attacks: reads actionsattaques — cross-referencing same-IP attack patterns
- [ ] Transfer patterns: one-sided resource flows flagged (reads actionsenvoi or marche history)
- [ ] areFlaggedAccounts(): used by marche.php before transfer — verify it's actually called
- [ ] createAdminAlert(): deduplicates alerts (no flood of same-type alerts)
- [ ] admin/multiaccount.php: requires admin authentication
- [ ] admin/multiaccount.php: CSRF on status update + manual flag creation POSTs
- [ ] Flag resolution: flags can be marked resolved/false-positive (not just accumulating)
- [ ] Detection functions called in background (non-blocking) or in login flow (check impact on login latency)

---

### Domain 17: MAPS — Resource Nodes, Coordinates & Proximity Bonus
**Scope:** Map display, player coordinate placement, resource node generation and proximity bonus calculation.
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
attaquer.php                  — map display (type=0 branch): scrollable map, resource node markers,
                                war/pact indicators, player coordinates
includes/resource_nodes.php   — generateResourceNodes(), getResourceNodeBonus(), node type definitions
includes/player.php           — coordonneesAleatoires() (bounded random placement)
includes/game_resources.php   — getResourceNodeBonus() call in updateRessources()
includes/config.php           — map constants (MAP_WIDTH, MAP_HEIGHT, RESOURCE_NODE_COUNT, NODE_PROXIMITY_RADIUS)
```

**Checklist:**
- [ ] coordonneesAleatoires(): bounded loop with MAX_ATTEMPTS (no infinite loop on full map)
- [ ] coordonneesAleatoires(): coordinates within MAP_WIDTH × MAP_HEIGHT bounds
- [ ] Resource node generation: NODE_COUNT from config (not hardcoded)
- [ ] Resource node amounts: per-node amounts from config ranges (not hardcoded)
- [ ] Proximity bonus formula: correct distance calculation (Euclidean or Manhattan — verify consistency)
- [ ] NODE_PROXIMITY_RADIUS from config (not hardcoded radius)
- [ ] Map display: player coordinates are integer-cast before use in HTML (no XSS via coordinates)
- [ ] Map display: resource node markers correctly filtered (only active nodes shown)
- [ ] War/pact indicators on map: sourced from declarations table with correct status filter
- [ ] Travel time: distance formula matches the speed formula in formulas.php (consistent)
- [ ] Post-season reset: generateResourceNodes() called to place fresh nodes (verify in season_reset flow)
- [ ] No auth required for basic map view within attaquer.php type=0 (player must still be logged in)

---

### Domain 18: NOTIFICATIONS — Email Queue, Combat Reports & Event History
**Scope:** Email notification queue, combat report display, historique event log.
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
rapports.php                  — combat and espionage reports list (player's own reports only)
historique.php                — event history log (attacks, trades, donations, etc.)
includes/player.php           — processEmailQueue() (probabilistic drain, 1/50 requests)
includes/config.php           — notification constants (EMAIL_FROM, EMAIL_QUEUE_DRAIN_PROB)
```

**Checklist:**
- [ ] rapports.php: auth required, player can only see their own reports (WHERE login = ?)
- [ ] rapports.php: XSS-safe display of all report fields (opponent name, resource amounts, etc.)
- [ ] rapports.php: pagination LIMIT/OFFSET integer-cast
- [ ] rapports.php: espionage vs combat reports correctly filtered by type
- [ ] historique.php: auth required, player can only see their own history
- [ ] historique.php: pagination LIMIT/OFFSET integer-cast
- [ ] historique.php: XSS-safe output on all event descriptions
- [ ] historique.php: alliance-specific events only shown to alliance members
- [ ] processEmailQueue(): no infinite loop (LIMIT clause on batch drain)
- [ ] processEmailQueue(): email headers properly encoded (no header injection)
- [ ] Email addresses from DB escaped before use in mail() headers
- [ ] Email date/subject encoding: no Ã→à garbling (use mb_encode_mimeheader or iconv)
- [ ] Email from address uses EMAIL_FROM constant (not hardcoded)
- [ ] Drain probability from config (EMAIL_QUEUE_DRAIN_PROB, not hardcoded 1/50)

---

### Domain 19: INFRA-SECURITY — CSRF, Rate Limiter, CSP, Validation & Logger
**Scope:** All shared security infrastructure: token generation/verification, request throttling, Content Security Policy, input validators, event logging.
**Agent type:** `comprehensive-review:security-auditor`

**Files to audit (ALL of these):**
```
includes/csrf.php             — csrfToken(), csrfCheck(), csrfField() — token gen/verify
includes/rate_limiter.php     — rateLimitCheck() — file-based token bucket per key+window
includes/csp.php              — cspNonce(), cspScriptTag() — per-request CSP nonce
includes/validation.php       — validateLogin(), validateEmail(), validatePassword(), transformInt()
includes/logger.php           — logError(), logWarn(), logInfo() — file-based event log
includes/env.php              — environment variable loading (no secrets in code)
```

**Checklist:**
- [ ] csrfCheck(): uses hash_equals() (timing-safe comparison, not == or ===)
- [ ] csrfCheck(): also validates same-origin referer (defense in depth)
- [ ] CSRF token: generated with random_bytes() or equivalent (not mt_rand)
- [ ] Rate limiter: GC (1/200 requests) failure handled gracefully (log error, don't crash)
- [ ] Rate limiter: if data/rates directory unwritable, fails closed (blocks request) not open
- [ ] Rate limiter: key includes both identifier AND bucket name (no cross-action collisions)
- [ ] CSP nonce: generated fresh per request with random_bytes() (not session-based)
- [ ] CSP header: script-src uses 'nonce-{X}' only (no unsafe-inline, no unsafe-eval)
- [ ] validateLogin(): correct length bounds (LOGIN_MIN_LENGTH, LOGIN_MAX_LENGTH from config)
- [ ] validateEmail(): FILTER_VALIDATE_EMAIL + length check
- [ ] validatePassword(): PASSWORD_MIN_LENGTH, PASSWORD_BCRYPT_MAX_LENGTH enforced
- [ ] transformInt(): iterative suffix parsing (K/M/B), no infinite loop
- [ ] logger.php: does NOT log passwords, session tokens, or raw IPs
- [ ] logger.php: log files not web-accessible (check .htaccess or Apache config)
- [ ] env.php: no credentials hardcoded; all secrets from environment or .env file not in web root

---

### Domain 20: INFRA-DATABASE — DB Helpers, Migrations, Connexion & Integrity
**Scope:** Prepared statement helpers, all database migrations (schema + indexes + constraints), DB connection config, column whitelists.
**Agent type:** `voltagent-lang:sql-pro`

**Files to audit (ALL of these):**
```
includes/database.php         — withTransaction(), dbQuery(), dbFetchOne(), dbFetchAll(),
                                dbExecute(), dbCount() — prepared statement helpers
includes/db_helpers.php       — column whitelists for ORDER BY, higher-level query helpers
includes/connexion.php        — mysqli connection setup, charset, error mode
ALL migrations/*.sql          — all 29+ migration files (schema, indexes, FK, CHECK constraints)
```

**Checklist:**
- [ ] All migrations use latin1 charset (required for FK compatibility with membre table)
- [ ] Foreign keys reference valid columns with matching types (varchar(30) ↔ varchar(30))
- [ ] CHECK constraints on all resource columns (no negative values in ressources, autre)
- [ ] Indexes exist for all frequent WHERE, JOIN, ORDER BY columns (check EXPLAIN on top queries)
- [ ] Migration ordering: no migration references a table/column not yet created
- [ ] Column type mismatches: PHP code TINYINT(1) used as bool, INT(11) for IDs — verify consistency
- [ ] NOT NULL where application requires it (login, login_cible, etc.)
- [ ] Default values match application expectations (e.g., 0 for counters, '' for strings)
- [ ] Primary keys on ALL tables (no table without PK)
- [ ] Unique constraints where business logic demands (membre.login, alliances.tag, etc.)
- [ ] No orphan data possible: cascade deletes defined for critical FK relationships
- [ ] db_helpers.php: ORDER BY column whitelist covers all columns used dynamically in app
- [ ] withTransaction(): uses proper BEGIN/COMMIT/ROLLBACK (not savepoints for nested calls)
- [ ] connexion.php: mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT) set for exception mode
- [ ] scripts/cleanup_old_data.php migration: DELETE FROM sanctions WHERE dateFin < CURDATE() present

---

### Domain 21: INFRA-TEMPLATES — Layout, Display, UI Components & Client-Side
**Scope:** Page template (navbar, toolbar), display helpers, UI component rendering, SEO meta tags, CSP header injection, JavaScript (countdown).
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
includes/layout.php           — global page wrapper (navbar, toolbar, season countdown, CSP header)
includes/display.php          — number formatting, htmlspecialchars wrappers, image display
includes/ui_components.php    — card rendering, list rendering, form helpers
includes/cardsprivate.php     — player stats card (private page sidebar)
includes/meta.php             — <meta> tags, Open Graph, SEO, canonical URL
includes/style.php            — CSS helper (inline style generation)
includes/copyright.php        — footer copyright
includes/bbcode.php           — BBCode renderer (also in Domain 2 — here check template usage)
includes/csp.php              — cspNonce() usage in layout + CSP header output
js/countdown.js               — season countdown timer (client-side)
index.php                     — public landing page (hero, features, login form, forum widget)
credits.php                   — credits page (static)
health.php                    — health check endpoint (returns JSON with DB status)
version.php                   — version/build info page
```

**Checklist:**
- [ ] CSP nonce: cspNonce() called once per request, same nonce used in header and all script tags
- [ ] CSP header: set via header() (not meta tag), includes script-src nonce, no unsafe-inline
- [ ] No inline event handlers in layout.php or any template (no onclick=, onload=, etc.)
- [ ] jQuery loaded with SRI hash attribute (integrity= sha256/sha384)
- [ ] Framework7 CSS/JS loaded correctly (version pinned, SRI if CDN)
- [ ] Countdown timer: handles season boundary correctly (doesn't show negative time)
- [ ] Countdown timer: correctly reads season end date from PHP-injected variable
- [ ] SEO: meta description, Open Graph og:title / og:description / og:image present on index.php
- [ ] Canonical URL: correct domain in og:url and canonical link
- [ ] HTML maxlength attributes: match PHP validation constants (LOGIN_MAX_LENGTH, ALLIANCE_TAG_MAX_LENGTH)
- [ ] Formula tooltips: rendered client-side with nonce-tagged script (no unsafe-inline)
- [ ] Empty-state messages: present on all list pages when no data (rapports, messages, classement)
- [ ] health.php: returns valid JSON {"status":"ok","db":"connected"}, no auth required, no data leak
- [ ] version.php: does not expose server details (PHP version, MariaDB version, paths)
- [ ] display.php: all number formatting helpers escape output correctly
- [ ] index.php forum widget: latest posts displayed with htmlspecialchars

---

## BATCH D — End-to-End Data Flow Agents (7 Agents, Cross-Domain)

These agents perform **taint analysis** across the entire codebase — tracing data from origin to destination regardless of domain boundaries. Each agent reads ALL relevant files to follow the complete data pipeline.

---

### Domain 22: TAINT-USER-INPUT — All User-Controlled Input → DB Write Paths
**Scope:** Every `$_POST`, `$_GET`, `$_COOKIE`, `$_SERVER` value that eventually reaches a DB write. Verify each has appropriate validation, sanitization, and type-casting before insertion.
**Agent type:** `comprehensive-review:security-auditor`

**What to trace:**
- `$_POST['login']`, `$_POST['motdepasse']` → `inscription.php` → `membre` INSERT
- `$_POST['contenu']` (forum) → `sujet.php`/`editer.php` → `messages` INSERT
- `$_POST['description']` (alliance) → `allianceadmin.php` → `alliances` UPDATE
- `$_POST['nbclasse*']` (army) → `armee.php` → `actionsattaques` INSERT
- `$_GET['page']`, `$_GET['clas']` → classement/rapports → SQL LIMIT/OFFSET
- `$_SERVER['HTTP_X_FORWARDED_FOR']` → ip logging → potential injection
- ALL other user-controlled parameters in the codebase

**Checklist:**
- [ ] Every `$_POST`/`$_GET` value goes through validation before DB use
- [ ] Integer parameters use `(int)` cast or `intval()` — no string passed as int
- [ ] String parameters use prepared statements — never concatenated into SQL
- [ ] Login/username inputs validated via `validateLogin()` before INSERT
- [ ] Email inputs validated via `validateEmail()` before INSERT
- [ ] Text content inputs have max-length enforced (mb_strlen check before INSERT)
- [ ] File uploads (if any) — mime type verified, not just extension
- [ ] `$_SERVER['HTTP_*']` headers treated as user-controlled (never trusted without validation)
- [ ] No `extract($_POST)` or `$$varname` variable variable patterns

---

### Domain 23: TAINT-DB-OUTPUT — All DB Read → HTML/JSON/Email Output Paths
**Scope:** Every value read from the database that is eventually rendered to the user (HTML, JSON response, email body). Verify that each value is escaped at the output layer appropriate to its context.
**Agent type:** `comprehensive-review:security-auditor`

**What to trace (sample — agent must trace ALL):**
- `membre.login` → rendered in player lists, profiles, leaderboards → must use `htmlspecialchars`
- `alliances.description` → rendered via BBCode → must sanitize BBCode output
- `messages.contenu` → rendered in message inbox → BBCode or htmlspecialchars
- `rapports.contenu` → rendered in combat reports → strip_tags + event handler strip
- `autre.specialisation` → rendered in bilan.php → must escape
- `actualites.contenu` → rendered in news/index → BBCode
- `statistiques.vainqueur` → rendered in season history → htmlspecialchars
- DB values inserted into JavaScript via `json_encode` → verify no raw injection

**Checklist:**
- [ ] Every DB string value rendered in HTML goes through `htmlspecialchars(ENT_QUOTES, 'UTF-8')` OR BBCode pipeline that starts with htmlspecialchars
- [ ] Integer/float values cast to `(int)` or `(float)` before HTML output (no type confusion)
- [ ] BBCode output: htmlspecialchars applied at START of bbcode() before tag parsing (not after)
- [ ] Values embedded in JS via `json_encode()` — confirm json_encode flags include `JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP`
- [ ] Email body: user-controlled values escaped with htmlspecialchars before HTML email body embed
- [ ] Email subject: user-controlled values stripped of CRLF and MIME-encoded
- [ ] No `echo $row['column']` without escaping anywhere in the codebase

---

### Domain 24: TAINT-CROSS-MODULE — Data Flow Between PHP Includes and Functions
**Scope:** Data passed between modules (includes) as function parameters, global variables, or return values. Find cases where a module receives data it cannot verify is clean.
**Agent type:** `voltagent-qa-sec:code-reviewer`

**What to trace:**
- `game_actions.php::updateActions()` → reads from `actionsattaques` → passes to `combat.php::resoudreCombat()` — are values trusted without re-validation?
- `player.php::updateLoginStreak()` return value → used in `basicprivatephp.php` — is PP grant always atomic?
- `compounds.php::getCompoundBonus()` → called in `game_resources.php` AND `combat.php` — same instance or different?
- `formulas.php` functions → receive `$joueur` array — is the array always fresh or could be stale?
- `resource_nodes.php::getResourceNodeBonus()` → called from `game_resources.php` → parameters validated?
- `multiaccount.php::areFlaggedAccounts()` → called from `marche.php` — what if it throws?

**Checklist:**
- [ ] No function receives user-controlled string and embeds it in SQL without prepared statement
- [ ] No function returns mixed type (string|false|null) where caller assumes specific type
- [ ] Global variables (`$base`, `$joueur`, `$inscrits`) — verify they are always initialized before use in every include path
- [ ] Functions that modify state (DB writes) are not called multiple times due to include-order bugs
- [ ] Return values from external functions always checked before use (no unchecked `false`)
- [ ] No circular include dependencies (A includes B which includes A)
- [ ] Constants defined in config.php are always available in every include (no "undefined constant" risk)

---

### Domain 25: SCHEMA-USAGE — DB Schema ↔ PHP Code Correlation
**Scope:** Compare the actual database schema (tables, columns, types) against how PHP code reads and writes those columns. Find mismatches, orphaned columns, and columns used in unexpected ways.
**Agent type:** `voltagent-data-ai:data-analyst`

**What to check:**
- Every table column in the schema → is it read anywhere in PHP? Written anywhere? (Orphaned columns)
- Every column written from PHP → is the data type match correct? (Writing string to INT column)
- Every column displayed to the user → was it originally stored with `htmlspecialchars` (double-escape risk) or raw (XSS risk)?
- Columns assumed to be clean (coming from DB) but storing raw user input: e.g., `membre.description`, `alliances.description`, `rapports.contenu`
- Foreign key relationships defined in schema vs PHP-enforced orphan cleanup in `supprimerJoueur()`

**Checklist:**
- [ ] Map every table → list of columns → PHP files that write to each column
- [ ] Map every table → list of columns → PHP files that read each column for display
- [ ] Identify columns where PHP stores raw user input (no htmlspecialchars on write) — these MUST be escaped on read
- [ ] Identify columns where PHP stores htmlspecialchars-encoded values — these must NOT be double-escaped on read
- [ ] Orphaned columns: columns in schema not referenced in any PHP file (candidates for removal)
- [ ] Columns used with wrong type: INT columns written with string concatenation, TINYINT used as bitmask without masking
- [ ] VARCHAR length in schema matches PHP validation max-length (no truncation on INSERT)
- [ ] NULL vs NOT NULL: PHP code that reads a NOT NULL column should not guard for null; PHP that reads a nullable column must guard

---

### Domain 26: TAINT-EMAIL — Data Flowing Into Email Headers, Subjects & Bodies
**Scope:** Trace every value that ends up in a `mail()` call — recipient address, subject, headers, body. Ensure no injection is possible and all encoding is correct.
**Agent type:** `comprehensive-review:security-auditor`

**Files to trace:**
```
includes/player.php        — processEmailQueue(): mail() call, all email construction
includes/basicprivatephp.php — email queue population (queueEmail() calls)
includes/config.php        — EMAIL_FROM, EMAIL_REPLY_TO, EMAIL_FROM_NAME constants
```

**Checklist:**
- [ ] Recipient email address: sourced from DB (membre.email) — stored with validation on registration
- [ ] Recipient email: CRLF-stripped before use in mail() `$to` parameter
- [ ] Subject: CRLF-stripped AND MIME-encoded with `mb_encode_mimeheader()`
- [ ] From header: RFC 5322 compliant format `"Name" <addr>` with space before `<`
- [ ] Reply-To header: same CRLF + MIME encoding as From
- [ ] Email body: any user-controlled values (login, winner name) escaped with `htmlspecialchars` before embedding in HTML body
- [ ] MIME boundary: generated with `random_bytes()` (not predictable)
- [ ] No user-controlled data flows into header lines (only into body after encoding)
- [ ] Email queue populated only with sanitized content (trace queueEmail() call sites)
- [ ] No raw PHP `mail()` calls outside `processEmailQueue()` (centralized mail sending)

---

### Domain 27: TAINT-SESSION — Session Data: What's Stored, How Trusted
**Scope:** Every `$_SESSION` key — what data is stored, where it's set, where it's read, and whether reads correctly re-validate or blindly trust session values.
**Agent type:** `comprehensive-review:security-auditor`

**What to trace (map all $_SESSION keys):**
- `$_SESSION['login']` → used as DB primary key in most queries — if this changes?
- `$_SESSION['session_token']` → validated against DB on every private page load?
- `$_SESSION['idalliance']` → used for alliance auth checks — refreshed after alliance change?
- `$_SESSION['moderateur']` → used for moderation gate — validated each request or just set at login?
- `$_SESSION['flash_message']` → read once and unset? Or can persist and be re-shown?
- `$_SESSION['csrf_token']` → matches entre requests, not leaked?
- `$_SESSION['streak_pp_today']` → used to prevent double PP award — can be cleared by other means?

**Checklist:**
- [ ] `$_SESSION['login']` — is it re-verified against DB on every page load, or trusted from login?
- [ ] `$_SESSION['session_token']` — DB validation runs on EVERY private page (basicprivatephp.php check)
- [ ] `$_SESSION['idalliance']` — refreshed from DB when alliance membership changes (not stale after kick)
- [ ] `$_SESSION['moderateur']` — validated against DB each request (not just at login, stale after removal)
- [ ] Flash messages: read-once (unset after display), never reflected directly as HTML without escaping
- [ ] No sensitive data in session (passwords, raw IPs, full email addresses)
- [ ] Session data used in DB queries uses prepared statements (even though session values are "internal")
- [ ] No session data embedded in HTML without `htmlspecialchars`
- [ ] Session fixation prevented: `session_regenerate_id(true)` called after login
- [ ] No session data stored in client-controlled cookies (only session ID in cookie)

---

### Domain 28: TAINT-API — api.php Endpoints and json_encode Output
**Scope:** The `api.php` dispatch table — every endpoint, what data it reads from DB, and what it exposes in the JSON response. Also any other `json_encode()` output sites in the codebase.
**Agent type:** `comprehensive-review:security-auditor`

**Files to trace:**
```
api.php                    — dispatch table, all API endpoints
includes/game_resources.php — may produce data consumed by API
Any file with json_encode()
```

**Checklist:**
- [ ] Every API endpoint requires authentication (no unauthenticated endpoints except health/public data)
- [ ] API rate-limited (rateLimitCheck per endpoint per player)
- [ ] CSRF token or session-only validation on state-changing API calls
- [ ] Sensitive fields NOT exposed in API responses (email, IP hash, session token, password hash)
- [ ] All string values in JSON responses are HTML-safe (json_encode with JSON_HEX_TAG etc. OR rendered in non-HTML context)
- [ ] API endpoints validate all input parameters (type, range, ownership)
- [ ] Error responses never reveal stack traces, SQL queries, or table names
- [ ] API dispatch table: no dynamic method dispatch using user-controlled action name (whitelist-only)
- [ ] Integer values in JSON: cast to (int)/(float) not raw string
- [ ] No JSONP or cross-origin data exposure

---

## BATCH E — End-to-End User Flow Agents (7 Agents, Cross-Domain)

These agents trace **complete user journeys** through the game, checking for logical correctness, broken flows, state inconsistencies, and race conditions that only appear when multiple systems interact. Each agent reads ALL files involved in the flow.

---

### Domain 29: FLOW-REGISTRATION — New Player Registration to First Action
**Scope:** The complete new player journey: landing page → registration form → account creation → first login → tutorial start → first resource production → first molecule creation.
**Agent type:** `voltagent-qa-sec:qa-expert`

**Flow to trace:**
```
index.php (landing) → inscription.php (registration) → basicpublicphp.php (login)
→ basicprivatephp.php (session init) → updateLoginStreak() → checkComebackBonus()
→ tutoriel.php (mission 1) → constructions.php (first build) → armee.php (first molecule)
→ updateRessources() (first production tick)
```

**Checklist:**
- [ ] Registration → DB: all fields validated, duplicate login/email rejected, bcrypt applied
- [ ] First login: session_regenerate_id() called, session_token written to DB
- [ ] `updateLoginStreak()` called on first private page load: streak_days set to 1, PP earned
- [ ] Tutorial mission 1 available immediately after login (no prerequisite)
- [ ] First resource production: updateRessources() returns positive values (no negative on new account)
- [ ] First molecule creation: atom costs deducted correctly, molecule row inserted
- [ ] New player registration: `coordonneesAleatoires()` places player at valid (≥1,≥1) coordinates
- [ ] STARTING_ENERGY and STARTING_ATOMS from config.php correctly applied on registration
- [ ] Rate limiter prevents mass registration (3 accounts/hour/IP)
- [ ] Duplicate email rejected even with different casing (LOWER() comparison)
- [ ] New player cannot attack others until beginner protection expires
- [ ] Registration generates `inscription` type audit log entry

---

### Domain 30: FLOW-COMBAT — Full Attack Cycle End-to-End
**Scope:** The complete combat cycle: army composition → attack launch → flight period → combat resolution → report generation → defender notification → resource transfer.
**Agent type:** `voltagent-qa-sec:qa-expert`

**Flow to trace:**
```
armee.php (compose army) → attaquer.php (launch, type=1) → actionsattaques INSERT
→ [time passes] → basicprivatephp.php triggers updateActions()
→ game_actions.php::updateActions() → combat.php::resoudreCombat()
→ rapports INSERT (attacker + defender) → game_actions.php::notifyDefender()
→ ressources UPDATE (pillage) → molecules DELETE (casualties)
→ rapports.php (display) → attaque.php (detail view)
```

**Checklist:**
- [ ] Army composition validated: can't send more molecules than owned (pre-flight check)
- [ ] Attack energy cost deducted atomically (FOR UPDATE on ressources)
- [ ] Attack inserted into `actionsattaques` with correct arrival time
- [ ] `updateActions()` processes attack only once (CAS guard: `WHERE attaqueFaite=0`)
- [ ] Combat resolution: damage formula produces non-negative casualties
- [ ] Defender casualties: correct molecule types destroyed (not attacker's molecules)
- [ ] Pillage: only on attacker victory, vault-protected amount excluded, storage cap respected
- [ ] Both attacker AND defender reports created in single transaction
- [ ] Defender notification: defender receives report in rapports (ESPIONAGE-HIGH-001 fix verified)
- [ ] Return trip: attacker's surviving molecules returned even if target account deleted
- [ ] Building damage: random building selected, level never below 1 for core buildings
- [ ] Combat stats: `pointsAttaque`/`pointsDefense` incremented for correct player
- [ ] Beginner protection: neither attacker nor defender in beginner window
- [ ] Comeback shield: attack blocked if defender has active comeback shield

---

### Domain 31: FLOW-MARKET — Full Trade Cycle End-to-End
**Scope:** The complete market cycle: price display → buy transaction → storage validation → price volatility update → sell transaction → player-to-player transfer.
**Agent type:** `voltagent-qa-sec:qa-expert`

**Flow to trace:**
```
marche.php GET (price display) → cours table SELECT
→ marche.php POST buy → ressources FOR UPDATE → cours FOR UPDATE
→ ressources UPDATE (deduct energy, add atoms) → cours UPDATE (volatility)
→ marche.php POST sell → reverse direction
→ marche.php POST transfer → areFlaggedAccounts() → actionsenvoi INSERT
→ [time passes] → updateActions() → actionsenvoi delivery
```

**Checklist:**
- [ ] Buy: energy deducted AND atoms added in single transaction (no partial state)
- [ ] Buy: storage cap enforced (can't receive more atoms than available space)
- [ ] Buy: iode (index 7) correctly priced — array_slice fix verified (MARKET-CRIT-001)
- [ ] Sell: atoms deducted AND energy added in single transaction
- [ ] Sell: energy cap enforced (can't receive more energy than max)
- [ ] Price volatility: updated INSIDE transaction with FOR UPDATE on cours (no TOCTOU)
- [ ] Transfer: flagged multi-account pairs blocked by `areFlaggedAccounts()`
- [ ] Transfer: can't transfer to self
- [ ] Transfer: rate limited (10/60s per player)
- [ ] Transfer delivery: recipient storage cap enforced on delivery (not just on send)
- [ ] Price chart: timestamps correctly encoded (no Ã→à mojibake)
- [ ] Market tutorial hint: shown correctly for new players

---

### Domain 32: FLOW-ALLIANCE — Full Alliance Lifecycle End-to-End
**Scope:** The complete alliance lifecycle: creation → member invitation → member joins → research upgrade → war declaration → war resolution → member leaves → dissolution.
**Agent type:** `voltagent-qa-sec:qa-expert`

**Flow to trace:**
```
alliance.php (create) → alliances INSERT → autre UPDATE (idalliance)
→ allianceadmin.php (invite) → invitations INSERT
→ alliance.php (accept invite) → autre UPDATE (join) → alliance_left_at = NULL
→ alliance.php (research upgrade) → alliances UPDATE → FOR UPDATE
→ guerre.php (war declare) → declarations INSERT
→ [combat resolves war] → declarations UPDATE (fin, winner)
→ allianceadmin.php (member leave/kick) → autre UPDATE + alliance_left_at = UNIX_TIMESTAMP()
→ allianceadmin.php (dissolve) → supprimerAlliance() → cascade cleanup
```

**Checklist:**
- [ ] Alliance creation: tag uniqueness enforced with FOR UPDATE
- [ ] Alliance join: cooldown enforced (alliance_left_at checked correctly)
- [ ] Alliance join: member count cap enforced before INSERT
- [ ] Research upgrade: energy deducted from alliance, not from player (correct table)
- [ ] War declaration: can't declare war on own alliance
- [ ] War declaration: can't declare war on ally (active pact check)
- [ ] Pact acceptance: grade permission verified before accepting
- [ ] Pact: duplicate pact prevention (can't have two active pacts with same alliance)
- [ ] War resolution: winner determined by losses (pertes1 vs pertes2)
- [ ] Member kick: alliance_left_at set to UNIX_TIMESTAMP() (ALLIANCE-MED-001 fix verified)
- [ ] Alliance dissolution: cascade cleanup of grades, invitations, declarations, research, messages
- [ ] Alliance dissolution: all members' idalliance reset to 0, alliance_left_at set (ALLIANCE-MED-001)
- [ ] Grade permissions: chef can always act; graded members limited to their permission bits

---

### Domain 33: FLOW-SEASON — Full Season End-to-End Transition
**Scope:** The complete season transition: detection → maintenance phase → 24h pause → reset cascade → VP awards → prestige awards → email notifications → new season start.
**Agent type:** `voltagent-qa-sec:qa-expert`

**Flow to trace:**
```
basicprivatephp.php (any page load) → isSeasonEnd() check
→ phase 1: maintenance flag set, timestamp recorded
→ [24h pause] → phase 2: GET_LOCK acquired
→ archiveSeasonData() → season_recap INSERT
→ awardPrestigePoints() → prestige UPDATE (inside transaction) → idempotency flag SET
→ processEmailQueue() → mail() to top players
→ remiseAZero() → 15-table wipe → resource node generation → session invalidation
→ first page load after reset → new season state
```

**Checklist:**
- [ ] Phase 1: maintenance flag set before any reset operations
- [ ] Phase 2: GET_LOCK prevents concurrent reset (double-reset protection)
- [ ] archiveSeasonData() runs BEFORE remiseAZero() (data preserved before wipe)
- [ ] Winner determined from DB (not $_SESSION) — correct player name in emails
- [ ] awardPrestigePoints(): idempotency flag inside transaction (SEASON-HIGH-001 fix verified)
- [ ] remiseAZero(): all 15 tables wiped completely (verify completeness)
- [ ] Session tokens set to NULL after reset (forces all players to re-login)
- [ ] Resource nodes regenerated after reset (new season starts with fresh nodes)
- [ ] New season: STARTING_ENERGY and STARTING_ATOMS applied to all players
- [ ] Email notifications: winner + top players notified correctly
- [ ] Season recap page: readable after reset with correct archived data
- [ ] No page serves stale pre-reset data after the transition (session invalidation effective)

---

### Domain 34: FLOW-PRESTIGE — Full Prestige Point Lifecycle
**Scope:** The complete prestige journey: earning PP through multiple sources → purchasing an unlock → bonus applied in game → surviving season reset → carryover to next season.
**Agent type:** `voltagent-qa-sec:qa-expert`

**Flow to trace:**
```
[combat win] → ajouterPoints() → prestige INSERT ON DUPLICATE KEY UPDATE
[login streak] → updateLoginStreak() → prestige UPDATE (atomic)
[season end] → awardPrestigePoints() → prestige UPDATE (inside tx, idempotent)
prestige.php (shop) → purchasePrestigeUnlock() → FOR UPDATE → unlocks UPDATE
[next page load] → hasPrestigeUnlock() → bonus applied in production/combat
[season reset] → remiseAZero() → prestige table NOT wiped (carries over)
```

**Checklist:**
- [ ] PP earned via combat: `ajouterPoints()` correctly writes to prestige table
- [ ] PP earned via login streak: milestone awards match STREAK_MILESTONES config
- [ ] PP earned at season end: rank-based awards match PRESTIGE_RANK_BONUSES config
- [ ] Double-award prevention: idempotency check covers all PP sources, not just season-end
- [ ] Unlock purchase: FOR UPDATE prevents double-spend race condition
- [ ] Unlock purchase: sufficient PP verified inside transaction (not pre-checked then spent)
- [ ] Unlock effects: `prestigeProductionBonus()` and `prestigeCombatBonus()` return correct multipliers
- [ ] Unlocks applied in correct domain: production bonus in game_resources.php, combat bonus in combat.php
- [ ] Season reset: prestige table intact (not in remiseAZero() wipe list)
- [ ] Unlock carryover: purchases from previous season still active in new season
- [ ] PP balance display: reads from prestige.total_pp minus prestige.spent_pp (or verify schema)

---

### Domain 35: FLOW-SOCIAL — Full Social Interaction Lifecycle
**Scope:** The complete social flow: send message → inbox → read → delete → forum post → BBCode render → moderator review → sanction → appeal.
**Agent type:** `voltagent-qa-sec:qa-expert`

**Flow to trace:**
```
ecriremessage.php (compose) → messages INSERT + rate limit check
→ messages.php (inbox) → SELECT with ownership filter → mark read → soft delete
→ listesujets.php (new topic) → messages INSERT (type=forum)
→ sujet.php (reply) → messages INSERT + nbMessages UPDATE (autre, not membre)
→ editer.php (edit post) → rate limit check → messages UPDATE
→ moderationForum.php (sanction) → sanctions INSERT → ban check
→ [player posts again] → ban check blocks (dateFin check)
→ [ban expires] → probabilistic GC removes old sanctions
→ admin/supprimerreponse.php (admin delete) → messages DELETE + nbMessages UPDATE (autre)
```

**Checklist:**
- [ ] Message send: rate limit correctly enforced (identifier=login, action=key — FORUM-MED-001 fix verified)
- [ ] Message inbox: ownership filter `WHERE destinataire = $_SESSION['login']`
- [ ] Message read: marks read only if current user is recipient
- [ ] Soft delete: both `deleted_by_sender` and `deleted_by_recipient` flags used correctly
- [ ] Cascade delete: message hard-deleted when BOTH soft-delete flags set
- [ ] Forum reply: `nbMessages` incremented on `autre` table (not `membre`) — FORUM-MED-002 fix verified
- [ ] Admin delete: `nbMessages` decremented on `autre` table (not `membre`) — FORUM-MED-002 fix verified
- [ ] BBCode: htmlspecialchars applied at start of bbcode() (before tag parsing, not after)
- [ ] BBCode [img]: restricted to relative paths or whitelisted domains
- [ ] Forum ban: `dateFin >= CURDATE()` check runs before any POST action allowed
- [ ] Moderation sanction: rate-limited (20/3600s per moderator)
- [ ] Alliance-private forum: access check correctly compares viewer's idalliance with forum's alliance_id
- [ ] editer.php: edit only allowed for post author OR moderator (not any logged-in player)

---

## BATCH F — Static Analysis: Concurrency, Config Drift, Coverage & Dead Code (4 Agents, Cross-Domain)

These agents perform **static correctness analysis** — they don't audit a feature domain but instead examine systemic properties of the codebase that no domain-specific agent can catch.

---

### Domain 36: CONCURRENCY — Race Conditions Across Code Paths
**Scope:** Find race conditions that arise from the *intersection* of two simultaneous user actions, not just missing locks within a single action. Focus on shared state (DB rows, files) modified by paths that each look correct in isolation.
**Agent type:** `comprehensive-review:architect-review`

**What to analyze:**
- Two players simultaneously joining the same alliance (member cap enforcement)
- Two players simultaneously buying the last unit of a resource on the market
- Player A attacks Player B while Player B is deleting their account
- Two requests triggering `updateRessources()` for the same player at the same millisecond
- Admin triggering season reset while a player is mid-combat resolution
- Two moderators simultaneously banning the same player
- Player activating vacation mode while an incoming attack is in-flight

**Method:** For each shared resource (DB row/table), enumerate all code paths that write to it. For each pair of write paths, reason: "if both execute concurrently, can the result be wrong?" Specifically look for:
- Read-then-write without FOR UPDATE (TOCTOU between two different endpoints)
- Transaction A commits, then Transaction B reads stale cached data
- Two paths that each check a condition and pass, but together violate an invariant
- `session_start()` + DB session token check: gap between session read and DB validation

**Checklist:**
- [ ] Alliance join: member cap check + INSERT in same transaction with FOR UPDATE on alliances row
- [ ] Market buy: cours FOR UPDATE covers both price read and stock deduction
- [ ] Account deletion mid-combat: `supprimerJoueur()` cascade deletes actionsattaques — does `updateActions()` handle missing defender gracefully?
- [ ] `updateRessources()`: tempsPrecedent CAS pattern — is it truly atomic against concurrent calls?
- [ ] Season reset + active combat: GET_LOCK prevents reset, but does combat resolution run after lock release?
- [ ] Vacation mode + incoming attack: both checked at attack-launch time, but what if vacation activated after launch?
- [ ] Two simultaneous `purchasePrestigeUnlock()` calls: FOR UPDATE on prestige row covers both reads
- [ ] `rateLimitCheck()` file-based: file locking used? Two concurrent requests could both pass
- [ ] `coordonneesAleatoires()`: two new players registering simultaneously — can they get same coordinates?

---

### Domain 37: CONFIG-DRIFT — config.php Constants vs Actual Usage
**Scope:** Every constant defined in `includes/config.php` — verify it matches exactly what the PHP code uses in formulas, queries, and display. Find constants that are defined but unused, used with wrong name, or where the code has drifted to a different hardcoded value.
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Method:**
1. Extract every `define(...)` from `config.php` — list of constant name + value
2. For each constant, grep the entire codebase for its usage
3. For each usage, verify the formula matches the intent documented in config.php comments
4. Also grep for numeric literals that match config values (e.g., `0.05`, `86400`, `30`) — check if these should be referencing a constant instead

**Checklist:**
- [ ] Every constant in config.php is referenced at least once in PHP code (no orphaned constants)
- [ ] Every formula using a config constant uses the CORRECT constant (e.g., VAULT_PERCENT not DEFENSE_REWARD)
- [ ] No numeric literals in game logic that duplicate a config constant value (hardcoded drift)
- [ ] BUILDING_CONFIG array: every building's cost/time/bonus used correctly in constructions.php + game_actions.php
- [ ] PRESTIGE_RANK_BONUSES array: values match what's displayed in prestige.php UI
- [ ] COMPOUND_* constants: synthesis costs in UI match config, activation durations match config
- [ ] MARKET_* constants: spread, slippage, volatility values consistent between formula and display
- [ ] SEASON_DURATION_DAYS: used consistently in all season-end detection logic
- [ ] STREAK_MILESTONES: displayed milestones in prestige.php match config array exactly
- [ ] LOGIN_MAX_LENGTH / ALLIANCE_TAG_MAX_LENGTH: HTML maxlength attributes match config values
- [ ] EMAIL_QUEUE_DRAIN_PROB_DENOM: the `1 in N` probability matches config
- [ ] Any `//TODO` or `//FIXME` comments referencing a different value than what's in config

---

### Domain 38: TEST-COVERAGE — PHPUnit Suite Quality Audit
**Scope:** The test suite itself — not running it, but reading it. Find: untested critical paths, assertions that don't actually verify correctness, tests that could never fail, and missing edge case coverage.
**Agent type:** `voltagent-qa-sec:qa-expert`

**Files to read:**
```
tests/unit/*.php       — all unit test files
tests/integration/*.php — all integration test files
```

**Also read the critical source files to understand what SHOULD be tested:**
```
includes/combat.php, includes/game_actions.php, includes/prestige.php,
marche.php, includes/player.php, includes/compounds.php
```

**Checklist:**
- [ ] Combat damage formula: test asserts specific numeric output, not just "is numeric"
- [ ] Market buy/sell: test covering iode (index 7) specifically — MARKET-CRIT-001 regression guard
- [ ] Espionage success: test verifies defender report IS created — ESPIONAGE-HIGH-001 regression guard
- [ ] CSRF verification: test that invalid token returns 403, not just that valid token passes
- [ ] Rate limiter: test that Nth+1 request is actually blocked (not just that first N pass)
- [ ] Account deletion cooldown: test that POST within 7 days is rejected
- [ ] Prestige idempotency: test that calling awardPrestigePoints() twice gives same result as once
- [ ] Defense boost: test that attacker takes 15% more casualties vs defender with active defense compound
- [ ] `ajouter()` floor: test that negative delta cannot bring column below 0
- [ ] Alliance cooldown: test that supprimerAlliance() sets alliance_left_at (not NULL)
- [ ] Tests with no assertions: find and flag `testXxx()` methods that assert nothing
- [ ] Tests that always pass: assertions like `assertTrue(true)` or `assertNotNull($x)` on a literal
- [ ] Missing error path tests: functions that return false on error — is the false case tested?
- [ ] Integration test coverage: which pages have no HTTP-level test at all

---

### Domain 39: DEAD-CODE — Unreachable Paths, Unused Functions & Stale Branches
**Scope:** Code that can never execute, functions that are never called, conditions that are always true/false, and variables that are written but never read. Dead code is a hiding place for bugs (they matter if the code ever becomes reachable) and a sign of past fixes that weren't cleaned up properly.
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Method:** For each file, read the code and reason about reachability:
- Conditions that are structurally impossible (like the ESPIONAGE-HIGH-001 inverted condition we fixed)
- Functions defined in includes/ that are never called anywhere
- Variables assigned inside an `if` branch that is always true/false
- `return` statements followed by more code
- `switch` cases that can never be reached given the input domain
- Class/function definitions in files that are included but the class is never instantiated
- Config constants that reference removed features

**Files to focus on (highest risk of dead code):**
```
includes/game_actions.php   — large, complex, historic accumulation
includes/player.php         — 1500+ lines, many old functions
includes/combat.php         — multiple formation/isotope branches
includes/formulas.php       — some formula variants may be unused
includes/prestige.php       — seasonal logic with many guard clauses
includes/multiaccount.php   — detection functions may not all be called
```

**Checklist:**
- [ ] Every function defined in includes/ files is called from at least one PHP page or other include
- [ ] No `if (false)` or `if (0)` or always-false compound conditions
- [ ] No `if (true)` or always-true conditions masking dead `else` branches
- [ ] No code after unconditional `return`, `exit`, or `die`
- [ ] Switch statements: every `case` is reachable given the actual values passed to the switch
- [ ] No assigned variables that are never subsequently read (write-only variables)
- [ ] `supprimerJoueur()` cascade: every DELETE references a table that still exists in schema
- [ ] `remiseAZero()` table list: every table in the wipe list still exists in current schema
- [ ] Old TODO/FIXME comments referencing issues that have already been fixed (stale noise)
- [ ] `comptetest.php`: is this file still needed? Should it be deleted or web-restricted?

---

## Phase 2: Consolidation (1 Agent)

### Task: Build Remediation Plan

**Agent type:** `voltagent-meta:knowledge-synthesizer`

**Input:** All 35 domain audit outputs from Phase 1 (Batches A–E).

**Prompt template:**
```
You are given 21 domain audit reports for the TVLW PHP game.
Your job is to:

1. Deduplicate findings (same bug reported by multiple domains).
2. Assign a unique ID to each finding: PASS{N}-{SEV}-{NNN}
   (e.g., PASS1-CRITICAL-001, PASS1-HIGH-012)
3. Sort by severity (CRITICAL > HIGH > MEDIUM > LOW).
4. For each finding, write a concrete remediation step:
   - Exact file(s) to modify
   - What to change (code diff or description)
   - Which test to add/update
5. Group findings into fix batches (max 5 findings per batch).
6. Estimate batch dependencies (which batches must run before others).

Output format:
# Remediation Plan — Pass N
## Summary: X critical, Y high, Z medium, W low
## Batch 1: [batch name]
### PASS{N}-{SEV}-{NNN}: Title
- File: path:line
- Fix: description
- Test: what to verify
## Batch 2: ...
```

**Output:** Save to `docs/plans/2026-03-07-ultra-audit-pass-{N}-remediation.md`

---

## Phase 3: Plan Review (2 Parallel Agents)

Two independent reviewers verify the remediation plan is complete and correct.

### Reviewer A: Completeness Reviewer
**Agent type:** `comprehensive-review:architect-review`

**Prompt template:**
```
You are reviewing a remediation plan for the TVLW game codebase.
You have access to the original 21 domain audit reports (3 batches) and the remediation plan.

Verify:
1. Every finding from every domain audit appears in the plan (no dropped issues).
2. Every fix has a concrete file path and code change.
3. Batch ordering respects dependencies.
4. No fix introduces a new problem (review the suggested changes).

Output: List of gaps, missing items, or incorrect fixes.
If the plan is complete, output: "PLAN APPROVED — all N findings addressed."
```

### Reviewer B: Technical Correctness Reviewer
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Prompt template:**
```
You are reviewing remediation fixes for the TVLW PHP game.
For each fix in the remediation plan:

1. Read the actual source file at the specified location.
2. Verify the suggested fix is technically correct.
3. Check for side effects (does the fix break other code paths?).
4. Verify the test plan would actually catch a regression.

Output: List of technically incorrect fixes with corrections.
If all fixes are correct, output: "FIXES VERIFIED — all technically sound."
```

**If either reviewer finds issues:** Update the remediation plan, then re-run Phase 3.

---

## Phase 4: Fix Implementation (Batched Agents)

Each batch from the remediation plan is implemented by a dedicated agent.

### Fix Agent Template
**Agent type:** `voltagent-lang:php-pro`

**Prompt template per batch:**
```
You are fixing bugs in the TVLW PHP game (The Very Little War).
Project root: /home/guortates/TVLW/The-Very-Little-War/

## Context
- PHP 8.2, MariaDB 10.11, Framework7 CSS
- Prepared statements via includes/database.php (dbQuery, dbFetchOne, dbFetchAll, dbExecute, dbCount)
- CSRF via includes/csrf.php
- Config constants in includes/config.php
- All output must use htmlspecialchars()
- All POST forms need CSRF tokens
- All DB queries use prepared statements
- latin1 charset for all tables

## Your Batch
[INSERT BATCH FINDINGS HERE]

## Instructions
1. For each finding, read the file first.
2. Make the minimal fix — do not refactor surrounding code.
3. Add/update PHPUnit tests for each fix.
4. Run: cd /home/guortates/TVLW/The-Very-Little-War && vendor/bin/phpunit
5. All tests must pass before you're done.
6. Do NOT modify files outside your batch unless strictly necessary.
```

**Concurrency:** Batches with no dependencies run in parallel. Dependent batches run sequentially.

---

## Phase 5: Verification (2 Parallel Agents)

After all fix batches complete, two verification agents confirm correctness.

### Verifier A: Spec Reviewer
**Agent type:** `superpowers:code-reviewer`

**Prompt template:**
```
Review all changes made during fix Phase 4 of Ultra Audit Pass N.

For each fix:
1. Read the remediation plan entry (the spec).
2. Read the actual code change (git diff or file).
3. Verify the change matches the spec exactly.
4. Verify no unrelated changes were made.
5. Verify a test exists that would catch regression.

Output:
- PASS/FAIL per finding
- Any findings that were not properly addressed
- Any side effects introduced
```

### Verifier B: Integration Verifier
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Prompt template:**
```
Run full verification of the TVLW codebase after Ultra Audit Pass N fixes.

1. Run: cd /home/guortates/TVLW/The-Very-Little-War && vendor/bin/phpunit --testdox
2. Verify all tests pass (0 failures, 0 errors).
3. Run: php -l on every modified .php file (syntax check).
4. Grep for common anti-patterns in modified files:
   - mysql_query, mysql_fetch (old API)
   - $_GET/$_POST used without validation
   - echo without htmlspecialchars on user data
   - SQL without prepared statements
5. Check that no debug code was left (var_dump, print_r, error_log with sensitive data).

Output:
- Test results summary
- Syntax check results
- Anti-pattern scan results
- VERDICT: CLEAN or list of remaining issues
```

---

## Phase 6: Deploy & Loop Decision

### Step 1: Run full test suite
```bash
cd /home/guortates/TVLW/The-Very-Little-War && vendor/bin/phpunit --testdox
```
Expected: 0 failures, 0 errors

### Step 2: Commit
```bash
git add -A
git commit -m "Ultra Audit Pass N: X findings fixed

- N critical, N high, N medium, N low issues resolved
- All tests pass (NNN tests / NNN assertions)

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

### Step 3: Deploy to VPS
```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'cd /var/www/html && git pull origin main'
```

### Step 4: Smoke test VPS
```bash
# Check all pages return HTTP 200
for page in index.php inscription.php classement.php regles.php marche.php \
  constructions.php armee.php alliance.php tutoriel.php prestige.php \
  medailles.php bilan.php laboratoire.php season_recap.php health.php; do
  curl -s -o /dev/null -w "%{http_code} $page\n" "http://theverylittlewar.com/$page"
done
```

### Step 5: Loop Decision

**IF Phase 1 of this pass found 0 issues across all 21 domains:**
→ **DONE. Zero-bug convergence achieved.**

**ELSE:**
→ **Start Audit Pass N+1** with completely fresh agents (no resumed context).
→ Same 12 domains, same checklists, same file lists.
→ Fresh eyes catch what prior passes missed.

---

## Execution Protocol

### Mandatory Skills
- **REQUIRED:** Use `superpowers:executing-plans` skill at the start of every pass.
- **REQUIRED:** At the end of EACH phase (after each batch, after consolidation, after review, after fixes, after verification), re-read this ultra audit plan file before proceeding to give full fresh context. Do not rely on memory of earlier sections.
- **REQUIRED:** At the start of each pass, initialize a full TaskCreate list covering ALL tasks for the entire pass (including "Prepare next pass task list" as the final task) BEFORE dispatching any agents.

### How to Run Pass N

```
Step 0:  Invoke superpowers:executing-plans skill.
Step 0b: Re-read /home/guortates/TVLW/The-Very-Little-War/docs/plans/2026-03-07-ultra-audit-loop.md in full.
Step 0c: Create TaskCreate entries for ALL pass tasks (Phase 1A through Phase 6 + "Prepare next pass task list").
Step 1:  Announce "Starting Ultra Audit Pass N"
Step 2:  Determine which domains to skip/double:
         - Always run all 39 domains (Batches A-F)
         - Domains with HIGH/CRITICAL findings in Pass N-1: run 2 agents (counts as 2 in batch)
         - Domains with only INFO findings in Pass N-1: still run (fresh eyes catch regressions)
         - Domains always doubled: Domain 3 (Combat), Domain 6 (Season Reset), Domain 20 (INFRA-DATABASE)

Step 3:  Launch BATCH A in parallel — 7 agents (Auth, Forum, Combat, Espionage, Admin, Season-Reset,
         Infra-Security). Wait for ALL to complete before Step 4.
         → RE-READ this plan file before proceeding to Step 4.

Step 4:  Launch BATCH B in parallel — 7 agents (Combat, Espionage, Economy, Market, Buildings,
         Compounds, Maps). Wait for ALL to complete before Step 5.
         → RE-READ this plan file before proceeding to Step 5.

Step 5:  Launch BATCH C in parallel — 7 agents (Social, Alliance-Mgmt, Game-Core, Prestige,
         Rankings, Notifications, Infra-Templates). Wait for ALL to complete before Step 6.
         → RE-READ this plan file before proceeding to Step 6.

Step 6:  Launch BATCH D in parallel — 7 DATA FLOW agents (Taint-User-Input, Taint-DB-Output,
         Taint-Cross-Module, Schema-Usage, Taint-Email, Taint-Session, Taint-API).
         Wait for ALL to complete before Step 7.
         → RE-READ this plan file before proceeding to Step 7.

Step 7:  Launch BATCH E in parallel — 7 USER FLOW agents (Flow-Registration, Flow-Combat,
         Flow-Market, Flow-Alliance, Flow-Season, Flow-Prestige, Flow-Social).
         Wait for ALL to complete before Step 8.
         → RE-READ this plan file before proceeding to Step 8.

Step 7b: Launch BATCH F in parallel — 4 STATIC ANALYSIS agents (Concurrency, Config-Drift,
         Test-Coverage, Dead-Code). Wait for ALL to complete before Step 8.
         → RE-READ this plan file before proceeding to Step 8.

Step 8:  Collect all 39 reports. Launch consolidation agent (Phase 2).
         → RE-READ this plan file before proceeding to Step 9.
Step 9:  Launch 2 plan reviewers IN PARALLEL (Phase 3).
         → RE-READ this plan file before proceeding to Step 10.
Step 10: If reviewers find gaps → fix plan → re-review.
Step 11: Launch fix agents per batch (Phase 4) — parallel where possible.
         → RE-READ this plan file before proceeding to Step 12.
Step 12: Launch 2 verifiers IN PARALLEL (Phase 5).
         → RE-READ this plan file before proceeding to Step 13.
Step 13: If verifiers find issues → fix and re-verify.
Step 14: Run PHPUnit, commit, deploy (Phase 6).
Step 15: Prepare next pass task list (create TaskCreate for Pass N+1 with all steps above).
Step 16: If Pass N found issues → go to Step 1 for Pass N+1.
Step 17: If Pass N found 0 issues → DONE.
```

### Agent Count Per Pass
| Phase | Agents | Type | Parallel? |
|-------|--------|------|-----------|
| 1A — Batch A | 7-9 | Domain specialists (Security & Infra) | Yes — all 7 at once |
| 1B — Batch B | 7-9 | Domain specialists (Combat & Economy) | Yes — all 7 at once, after 1A |
| 1C — Batch C | 7-9 | Domain specialists (Social & Cross-cutting) | Yes — all 7 at once, after 1B |
| 1D — Batch D | 7 | Data flow / taint analysis agents (cross-domain) | Yes — all 7 at once, after 1C |
| 1E — Batch E | 7 | User flow / journey agents (cross-domain) | Yes — all 7 at once, after 1D |
| 2 — Consolidate | 1 | Knowledge synthesizer | Sequential, after 1E |
| 3 — Review Plan | 2 | Architect + Code reviewer | Yes, both |
| 4 — Fix | 1-8 | PHP specialist (per batch) | Parallel where independent |
| 5 — Verify | 2 | Spec + Integration reviewer | Yes, both |
| **Total per pass** | **~41-55** | | |

### Convergence Expectation
- Pass 11+: Expect 10-30 findings (first pass with full 21-domain taxonomy — fresh eyes on 8 new domains)
- Pass 12: Expect 5-15 findings (regressions from fixes + deep issues in new domain splits)
- Pass 13: Expect 0-5 findings (edge cases)
- Pass 14: Expect 0 (convergence) — or continue if a domain reveals a systemic issue
- Pass 15+: Convergence confirmed

Typical convergence: 3-5 additional passes from current state (now with 21 domains and doubled critical agents).

---

## Pass Execution History

| Pass | Date | CRITICAL | HIGH | MEDIUM | LOW | Outcome |
|------|------|----------|------|--------|-----|---------|
| 1 | 2026-03-07 | ? | ? | ? | ? | Fixed → see pass-1-remediation.md |
| 2 | 2026-03-07 | ? | ? | ? | ? | Fixed → see pass-2-remediation.md |
| 3 | 2026-03-08 | ? | ? | ? | ? | Fixed → see pass-3-remediation.md |
| 4 | 2026-03-08 | ? | ? | ? | ? | Fixed → see pass-4-remediation.md |
| 5 | 2026-03-08 | ? | ? | ? | ? | Fixed → see pass-5-remediation.md |
| 6 | 2026-03-08 | 0 | 0 | 2 | 2 | Clean (MEDIUM/LOW deferred) → pass-6-clean.md |
| 7 | 2026-03-08 | 🔄 | 🔄 | 🔄 | 🔄 | **IN PROGRESS** |

---

## Pass 7 — Live Execution Log

**Date:** 2026-03-08
**Max agents in parallel:** 7

### Batch A — Security & Infrastructure (7 agents)
| Domain | Agent | Status |
|--------|-------|--------|
| 1. AUTH | `comprehensive-review:security-auditor` | ✅ DONE — 0C/0H/4M/4L |
| 2. INFRA-SECURITY | `comprehensive-review:security-auditor` | ✅ DONE — 0C/0H/2M/3L |
| 3. INFRA-DATABASE | `voltagent-lang:sql-pro` | ✅ DONE — 0C/3H/10M/4L |
| 4. ANTI_CHEAT | `comprehensive-review:security-auditor` | ✅ DONE — 0C/0H/5M/3L |
| 5. ADMIN | `voltagent-qa-sec:code-reviewer` | ✅ DONE — 1C/0H/4M/4L ⚠️ CRITICAL |
| 6. SEASON_RESET | `voltagent-qa-sec:code-reviewer` | ✅ DONE — 0C/0H/2M/3L |
| 7. FORUM | `voltagent-qa-sec:code-reviewer` | ✅ DONE — 0C/0H/3M/5L |

### Batch B — Combat & Economy (7 agents)
| Domain | Agent | Status |
|--------|-------|--------|
| 8. COMBAT | `Explore` | ✅ DONE — 0C/4H/2M/2L |
| 9. ESPIONAGE | `Explore` | ✅ DONE — 1C/0H/0M/1L ⚠️ CRITICAL |
| 10. ECONOMY | `Explore` | ✅ DONE — 0C/0H/0M/1L |
| 11. MARKET | `Explore` | ✅ DONE — 0C/0H/1M/1L |
| 12. BUILDINGS | `Explore` | ✅ DONE — 1C/2H/1M/0L ⚠️ CRITICAL |
| 13. COMPOUNDS | `Explore` | ✅ DONE — 1C/1H/2M/2L ⚠️ CRITICAL |
| 14. MAPS | `Explore` | ✅ DONE — 0C/0H/2M/1L |

### Batch C — Social & Cross-Cutting (7 agents)
| Domain | Agent | Status |
|--------|-------|--------|
| 15. SOCIAL | `Explore` | ✅ DONE — 0C/0H/0M/0L (CLEAN) |
| 16. ALLIANCE_MGMT | `Explore` | ✅ DONE — 0C/0H/0M/1L |
| 17. GAME_CORE | `Explore` | ✅ DONE — 0C/0H/2M/3L |
| 18. PRESTIGE | `Explore` | ✅ DONE — 1C/0H/0M/0L ⚠️ UX bug |
| 19. RANKINGS | `Explore` | ✅ DONE — 0C/0H/0M/1L |
| 20. NOTIFICATIONS | `Explore` | ✅ DONE — 0C/1H/3M/1L |
| 21. INFRA-TEMPLATES | `Explore` | ✅ DONE — 0C/0H/1M/3L |

### Triage & Remediation
| Phase | Status | Output |
|-------|--------|--------|
| Consolidate findings | ✅ DONE | `docs/plans/2026-03-08-ultra-audit-pass-7-remediation.md` — 5C/9H/42M/35L |
| Plan review (2 agents) | 🔄 RUNNING | — |
| Fix execution | ⏳ PENDING | — |
| Verification (2 agents) | ⏳ PENDING | — |
| PHPUnit | ⏳ PENDING | — |
| Commit + Deploy | ⏳ PENDING | — |

---

## Domain Agent Prompt Template

Use this template for ALL 21 domain agents in Phase 1:

```
You are a fresh auditor for the TVLW game (The Very Little War).
Project root: /home/guortates/TVLW/The-Very-Little-War/

This is Audit Pass {N}. You have NO context from prior passes.
Your domain: {DOMAIN_NAME}

## Files You MUST Read and Audit
{FILE_LIST}

## Your Checklist
{CHECKLIST}

## Output Format
For each issue found:
### [{SEVERITY}]-[{NNN}]: {Short title}
- **File:** path/to/file.php:LINE
- **Domain:** {DOMAIN_NAME}
- **Description:** What is wrong (be specific, quote the code)
- **Impact:** What happens if not fixed
- **Suggested fix:** Concrete code change (show before/after)

If no issues found, output:
"DOMAIN CLEAN — {DOMAIN_NAME}: 0 issues found in Pass {N}"

## Rules
- Read EVERY file in your list. Do not skip any.
- Check EVERY item on your checklist. Do not skip any.
- Be specific: quote line numbers and code snippets.
- Only report real bugs, not style preferences.
- If unsure, report it with severity LOW and note your uncertainty.
```

---

## Adaptive Domain Skipping Rule

**A domain may be SKIPPED in Pass N if the TWO MOST RECENT consecutive passes (N-2 and N-1) both found zero MEDIUM or higher issues in that domain.**

- LOW findings alone do NOT trigger skipping.
- If a domain is skipped and a later pass finds a MEDIUM+ issue in a neighboring domain that could affect it, re-run it.
- Domains that are NEVER skipped (always doubled): INFRA-DATABASE (Domain 20), COMBAT (Domain 3), SEASON_RESET (Domain 6).

## Termination Criteria

The ultra audit loop terminates when ALL of these are true:

1. The latest pass found **zero MEDIUM or higher issues** across all active domains (LOW findings may remain as accepted risk).
2. PHPUnit test suite passes with 0 failures and 0 errors.
3. All VPS pages return HTTP 200.
4. No anti-pattern grep hits in the codebase.

At termination, create a final summary:
```
docs/plans/2026-03-07-ultra-audit-FINAL-REPORT.md
```

Containing:
- Number of passes completed
- Total findings across all passes (by severity)
- Total fixes applied
- Final test count (tests / assertions)
- Timestamp of convergence
