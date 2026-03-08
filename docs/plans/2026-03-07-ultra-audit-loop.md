# Ultra Audit Loop — Zero-Bug Convergence Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Achieve zero known bugs through iterative full-codebase audits, where each pass dispatches fresh specialized agents across all domains and user flows, remediates all findings, verifies fixes, then repeats until a clean pass produces zero new issues.

**Architecture:** Each audit pass is structured in 5 phases: (1) Parallel domain audits by fresh agents, (2) Consolidated remediation plan, (3) Plan review by independent reviewers, (4) Fix implementation by specialist agents, (5) Verification by spec/code reviewers. The loop terminates when a full pass finds zero issues.

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
│  Phase 1: AUDIT (12 fresh domain agents)         │
│     ↓                                            │
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

## Phase 1: Domain Audits (12 Parallel Agents)

Each audit pass dispatches **13+ fresh agents**, one per domain (with doubled agents for high-finding domains). Every agent reads the FULL file list relevant to its domain. No agent reuses context from prior passes — they are always fresh.

**Adaptive agent dispatch rules (apply each pass):**
- **Skip** domains that produced only INFO findings in the prior pass (no real bugs found)
- **Double agents** for domains with HIGH/CRITICAL findings in the prior pass (two independent agents, findings merged)
- Domains always doubled in practice: Domain 2 (Combat), Domain 10 (Database), Domain 12 (Molecules)
- If a domain was CLEAN in the prior pass, still run it (fresh eyes catch regressions)

Each agent produces a structured finding list in this format:

```markdown
### [SEVERITY]-[NNN]: Short title
- **File:** path/to/file.php:LINE
- **Domain:** Security | Logic | Data | ...
- **Description:** What is wrong
- **Impact:** What happens if not fixed
- **Suggested fix:** Concrete code change
```

Severity levels: `CRITICAL` > `HIGH` > `MEDIUM` > `LOW`

---

### Domain 1: Security & Authentication
**Agent type:** `comprehensive-review:security-auditor`

**Files to audit (ALL of these):**
```
includes/basicpublicphp.php, includes/basicprivatephp.php,
includes/session_init.php, includes/csrf.php, includes/validation.php,
includes/rate_limiter.php, includes/csp.php, includes/env.php,
includes/connexion.php, includes/database.php, includes/logger.php,
includes/multiaccount.php,
inscription.php, deconnexion.php, compte.php, api.php,
admin/index.php, admin/supprimercompte.php, admin/redirectionmotdepasse.php,
admin/multiaccount.php, admin/ip.php
```

**Checklist:**
- [ ] Session fixation / regeneration on login
- [ ] CSRF token on every POST form and AJAX call
- [ ] Input validation on every user-supplied parameter (GET, POST, COOKIE)
- [ ] SQL injection — all queries use prepared statements, no string concat
- [ ] XSS — all output uses htmlspecialchars() or equivalent
- [ ] Authentication bypass — every private page includes basicprivatephp.php
- [ ] Authorization — admin pages check admin status
- [ ] Rate limiting on login, registration, password reset
- [ ] CSP headers present and correct (no unsafe-inline without nonce)
- [ ] Sensitive data in logs (passwords, tokens, emails)
- [ ] Cookie flags (httponly, secure when HTTPS, samesite)
- [ ] Password hashing (bcrypt, no MD5/SHA1)
- [ ] File upload restrictions (if any upload exists)
- [ ] Directory traversal in any file path parameter
- [ ] HTTP header injection in any redirect

---

### Domain 2: Combat System
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
includes/combat.php, includes/formulas.php, includes/config.php,
attaquer.php, attaque.php, rapports.php, armee.php, historique.php,
includes/game_actions.php (attack-related functions),
includes/player.php (army/HP functions)
```

**Checklist:**
- [ ] Damage formula correctness (attacker vs defender variables not swapped)
- [ ] Formation modifiers applied correctly (phalanx, dispersed, embuscade)
- [ ] Isotope combat modifiers (stable HP bonus, radioactive decay)
- [ ] Beginner protection window respected
- [ ] Pillage only on attacker victory (not draw)
- [ ] Vault percentage applied correctly
- [ ] Overkill cascade logic (no negative HP, proper kill counting)
- [ ] Building damage (weighted targeting, level >= 1 floor)
- [ ] Ionisateur HP system (damageable flag, HP tracking)
- [ ] Return trip guard (army returns even if target disappears)
- [ ] Combat cooldown enforcement
- [ ] Covalent synergy bonus applied correctly
- [ ] Defense reward calculation
- [ ] Combat report variables (winner/loser names, correct stats)
- [ ] Transaction safety (withTransaction wrapping combat resolution)
- [ ] Army count validation (can't attack with more than you have)
- [ ] Espionage interaction with combat (spy before attack)

---

### Domain 3: Economy & Resources
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
includes/game_resources.php, includes/formulas.php, includes/config.php,
includes/resource_nodes.php, includes/player.php (resource functions),
includes/ressources.php,
constructions.php, sinstruire.php, don.php, marche.php
```

**Checklist:**
- [ ] Resource production formulas match config.php constants
- [ ] Storage cap enforcement (can't exceed max)
- [ ] Building construction cost formulas
- [ ] Building upgrade time formulas
- [ ] Exponential economy scaling (config constants used)
- [ ] Resource node proximity bonus calculation
- [ ] Donation minimum reserve check
- [ ] Market buy/sell price calculations (spread, slippage)
- [ ] Market volume tracking (FOR UPDATE on trade volume)
- [ ] Energy regeneration formula
- [ ] Atom gathering rates per type
- [ ] No negative resources (LEAST/GREATEST guards)
- [ ] updateRessources() atomicity (single UPDATE, no race)
- [ ] Building level floor (never below 1 for core buildings, 0 allowed for optional)
- [ ] Comeback bonus resource grant (correct amounts)
- [ ] Weekend catchup multiplier logic

---

### Domain 4: Alliance System
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
alliance.php, allianceadmin.php, alliance_discovery.php,
guerre.php, validerpacte.php, voter.php, don.php (alliance donations),
includes/game_actions.php (alliance functions),
includes/db_helpers.php (alliance queries)
```

**Checklist:**
- [ ] Grade "0" truthy bug (grade checks use === not ==)
- [ ] Pact duplicate check (can't create same pact twice)
- [ ] War declaration transaction safety
- [ ] Alliance tag uniqueness enforcement
- [ ] Grade permission checks (create/delete/promote/demote)
- [ ] Alliance cooldown on rejoin
- [ ] Duplicateur bonus calculation per alliance
- [ ] Alliance research tree point spending
- [ ] Alliance victory points calculation
- [ ] Alliance member count limits
- [ ] Alliance message board XSS protection
- [ ] Stale grade access after kick
- [ ] Alliance dissolution cleanup (related tables)

---

### Domain 5: Market & Trading
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
marche.php, includes/formulas.php, includes/config.php,
includes/game_resources.php (market functions)
```

**Checklist:**
- [ ] Buy price > sell price (spread always positive)
- [ ] Global slippage calculation
- [ ] Trade volume FOR UPDATE (TOCTOU prevention)
- [ ] Market purchases respect storage limits
- [ ] Market chart timestamp formatting
- [ ] Minimum trade amounts
- [ ] Market tutorial hint for new players
- [ ] Volatility dampening coefficient
- [ ] No self-trading exploits
- [ ] Transaction wrapping for buy/sell operations
- [ ] Price bounds (no negative or infinite prices)

---

### Domain 6: Prestige, Medals & Ranking
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
prestige.php, medailles.php, classement.php, bilan.php,
joueur.php, includes/statistiques.php,
includes/prestige.php, includes/formulas.php, includes/config.php,
includes/player.php (ranking functions)
```

**Checklist:**
- [ ] PP earning formulas (combat, economy, medals)
- [ ] PP spending (unlock shop items, bonuses)
- [ ] Medal threshold values match config
- [ ] Medal progress bar calculation
- [ ] Sqrt ranking formula (category weights)
- [ ] Daily leaderboard toggle logic
- [ ] Season-frozen rankings during transition
- [ ] Prestige bonus stacking rules
- [ ] Veteran unlock conditions
- [ ] Login streak PP milestones (1/3/7/14/21/28)
- [ ] Season recap archiving (archiveSeasonData)
- [ ] Bilan.php bonus display formulas match actual game formulas

---

### Domain 7: Session, Season & Time
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
includes/session_init.php, includes/basicpublicphp.php,
includes/basicprivatephp.php, includes/config.php,
maintenance.php, season_recap.php, index.php,
vacance.php, includes/redirectionVacance.php,
includes/game_actions.php (season functions),
includes/player.php (login streak, comeback)
```

**Checklist:**
- [ ] Session token validation flow
- [ ] Session timeout enforcement
- [ ] Season end detection (performSeasonEnd)
- [ ] Two-phase maintenance window
- [ ] Season countdown timer accuracy (JS)
- [ ] Full column resets at season end
- [ ] Winner determination (correct variable, not $_SESSION)
- [ ] Comeback bonus cooldown (7-day check)
- [ ] Comeback shield duration
- [ ] Login streak date comparison (no timezone bugs)
- [ ] Vacation mode interaction with season end
- [ ] Monthly reset cycle timing

---

### Domain 8: Forum, Messages & Social
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
forum.php, listesujets.php, sujet.php, editer.php,
ecriremessage.php, messages.php, messagesenvoyes.php,
messageCommun.php, moderationForum.php,
includes/bbcode.php, admin/listesujets.php, admin/supprimerreponse.php
```

**Checklist:**
- [ ] BBCode [img] restricted (no arbitrary URLs)
- [ ] XSS in forum posts (BBCode output sanitized)
- [ ] XSS in private messages
- [ ] Forum ID race condition (auto-increment safety)
- [ ] Moderation charset handling
- [ ] CSRF on message send / forum post / edit
- [ ] Message deletion authorization (own messages only, or admin)
- [ ] Pagination SQL injection (LIMIT/OFFSET validated)
- [ ] Empty-state messages when no posts/messages
- [ ] Last seen indicator data source
- [ ] Unread attack badge accuracy
- [ ] Forum widget on index.php (latest posts)

---

### Domain 9: Tutorial & New Player
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
tutoriel.php, inscription.php, sinstruire.php,
includes/config.php (tutorial constants),
includes/game_actions.php (tutorial functions),
regles.php
```

**Checklist:**
- [ ] Tutorial mission progression (can't skip steps)
- [ ] Tutorial reward escalation values
- [ ] Espionage tutorial step (requires valid target)
- [ ] Tutorial completion tracking
- [ ] Registration input validation (length, charset, uniqueness)
- [ ] Registration rate limiting
- [ ] Initial resource grants match config
- [ ] Beginner protection timer start
- [ ] Market tutorial hint trigger
- [ ] Rules page accuracy vs actual mechanics
- [ ] New player map placement (coordonneesAleatoires bounded)

---

### Domain 10: Database Integrity
**Agent type:** `voltagent-lang:sql-pro`

**Files to audit (ALL of these):**
```
ALL 32 migration files (migrations/0001-0032),
includes/database.php, includes/db_helpers.php,
includes/connexion.php, includes/config.php
```

**Checklist:**
- [ ] All tables use latin1 charset (FK compatibility)
- [ ] Foreign keys reference valid columns with matching types
- [ ] CHECK constraints cover all non-negative resource columns
- [ ] Indexes exist for all frequently-queried columns (WHERE, JOIN, ORDER BY)
- [ ] Migration ordering (no forward references)
- [ ] Column type mismatches between PHP code and schema
- [ ] Missing NOT NULL where required
- [ ] Default values match application expectations
- [ ] Primary keys on all tables
- [ ] Unique constraints where business logic demands
- [ ] No orphan data possible (cascade or application cleanup)
- [ ] Transaction isolation level appropriate for concurrent access

---

### Domain 11: UI, Display & Client-Side
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
includes/layout.php, includes/style.php, includes/ui_components.php,
includes/cardsprivate.php, includes/display.php, includes/meta.php,
includes/copyright.php, includes/csp.php,
js/countdown.js,
index.php (public landing page), credits.php, version.php, health.php
```

**Checklist:**
- [ ] CSP nonce on all inline scripts
- [ ] No inline event handlers (onclick, onload, etc.)
- [ ] jQuery loaded with SRI hash
- [ ] Framework7 CSS/JS loaded correctly
- [ ] Responsive layout on mobile (Framework7 grid)
- [ ] SEO meta tags and Open Graph present
- [ ] Countdown timer handles season boundary correctly
- [ ] Formula tooltips render properly
- [ ] Empty-state messages on all list pages
- [ ] HTML maxlength matches PHP validation constants
- [ ] No hardcoded French strings that should be constants
- [ ] Health endpoint returns valid JSON
- [ ] Version page shows current build info
- [ ] CSP header blocks eval/unsafe-inline

---

### Domain 12: Molecules, Compounds & Specializations
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
molecule.php, laboratoire.php,
includes/compounds.php, includes/catalyst.php,
includes/atomes.php, includes/formulas.php,
includes/config.php (molecule/compound constants),
includes/player.php (specialization functions)
```

**Checklist:**
- [ ] 4 molecule classes (stats, creation requirements)
- [ ] 8 atom types (C, N, H, O, Cl, S, Br, I) — all handled
- [ ] Molecule stat formulas (HP, attack, defense, speed)
- [ ] Molecule decay formula (asymptotic, radioactive vs stable)
- [ ] Compound synthesis requirements and buff durations
- [ ] Compound cache invalidation (global vs static)
- [ ] Catalyst weekly rotation logic
- [ ] Catalyst buff stacking rules
- [ ] Atom specialization effects wired correctly
- [ ] Condenseur redistribution (no negatives)
- [ ] Iode catalyst interaction
- [ ] Neutrino decay formula
- [ ] Isotope stable buff values (-5% atk / +30% HP)
- [ ] transformInt iterative suffix (K, M, B)

---

### Domain 13: Admin, Player Utilities & Maintenance
**Agent type:** `voltagent-qa-sec:code-reviewer`

**Files to audit (ALL of these):**
```
joueur.php (player profile — if not covered by Domain 6),
vacance.php, includes/redirectionVacance.php,
voter.php (if not covered by Domain 4),
historique.php (if not covered by Domain 2),
comptetest.php,
includes/ressources.php, includes/statistiques.php,
admin/tableau.php, admin/listenews.php, admin/redigernews.php,
moderation/index.php, moderation/ip.php, moderation/mdp.php,
scripts/cleanup_old_data.php
```

**Checklist:**
- [ ] Admin dashboard (tableau.php) requires admin authentication
- [ ] News management (listenews/redigernews) requires admin auth + CSRF
- [ ] Moderation panel (moderation/) requires appropriate auth level
- [ ] vacation mode (vacance.php) cannot be activated during active combat
- [ ] Vacation mode exit correctly restores player state
- [ ] Player profile (joueur.php) — XSS on all rendered fields
- [ ] Player profile — no information disclosure (hidden stats, etc.)
- [ ] voter.php — authorization (only alliance members can vote)
- [ ] voter.php — TOCTOU prevention on vote counting
- [ ] historique.php — pagination security (no SQL injection via LIMIT/OFFSET)
- [ ] historique.php — authorization (can only see own history)
- [ ] comptetest.php — is this production code? Should it be gated or removed?
- [ ] statistics.php — no raw DB queries (uses prepared statements)
- [ ] cleanup_old_data.php — CLI-only execution guard (not callable via HTTP)
- [ ] Admin/moderation pages — all POST actions have CSRF protection

---

## Phase 2: Consolidation (1 Agent)

### Task: Build Remediation Plan

**Agent type:** `voltagent-meta:knowledge-synthesizer`

**Input:** All 12 domain audit outputs from Phase 1.

**Prompt template:**
```
You are given 12 domain audit reports for the TVLW PHP game.
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
You have access to the original 12 domain audit reports and the remediation plan.

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

**IF Phase 1 of this pass found 0 issues across all 12 domains:**
→ **DONE. Zero-bug convergence achieved.**

**ELSE:**
→ **Start Audit Pass N+1** with completely fresh agents (no resumed context).
→ Same 12 domains, same checklists, same file lists.
→ Fresh eyes catch what prior passes missed.

---

## Execution Protocol

### How to Run Pass N

```
Step 1:  Announce "Starting Ultra Audit Pass N"
Step 2:  Determine which domains to run:
         - Always run all 13 domains
         - Domains with HIGH/CRITICAL findings in Pass N-1: run 2 agents (in parallel)
         - Domains with only INFO findings in Pass N-1: still run (fresh eyes catch regressions)
         - Domains always doubled: Domain 2 (Combat), Domain 10 (Database), Domain 12 (Molecules)
Step 3:  Launch domain audit agents IN PARALLEL (Phase 1) — 13 to 16 agents
Step 4:  Collect all reports
Step 5:  Launch consolidation agent (Phase 2)
Step 6:  Launch 2 plan reviewers IN PARALLEL (Phase 3)
Step 7:  If reviewers find gaps → fix plan → re-review
Step 8:  Launch fix agents per batch (Phase 4) — parallel where possible
Step 9:  Launch 2 verifiers IN PARALLEL (Phase 5)
Step 10: If verifiers find issues → fix and re-verify
Step 11: Run PHPUnit, commit, deploy (Phase 6)
Step 12: If Pass N found issues → go to Step 1 for Pass N+1
Step 13: If Pass N found 0 issues → DONE
```

### Agent Count Per Pass
| Phase | Agents | Type | Parallel? |
|-------|--------|------|-----------|
| 1 — Audit | 13-16 | Domain specialists (doubled for DB/Combat/Molecules) | Yes, all |
| 2 — Consolidate | 1 | Knowledge synthesizer | Sequential |
| 3 — Review Plan | 2 | Architect + Code reviewer | Yes, both |
| 4 — Fix | 1-8 | PHP specialist (per batch) | Parallel where independent |
| 5 — Verify | 2 | Spec + Integration reviewer | Yes, both |
| **Total per pass** | **~20-28** | | |

### Convergence Expectation
- Pass 1: Expect 20-50 findings (initial sweep)
- Pass 2: Expect 5-15 findings (regressions from fixes + deep issues)
- Pass 3: Expect 0-5 findings (edge cases)
- Pass 4: Expect 0 (convergence) — or continue if new domains reveal issues
- Pass 5+: Convergence confirmed

Typical convergence: 3-5 passes (now with 13 domains and doubled critical agents).

---

## Domain Agent Prompt Template

Use this template for ALL 12 domain agents in Phase 1:

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

## Termination Criteria

The ultra audit loop terminates when ALL of these are true:

1. All 13 domain agents (and doubled agents) in the latest pass report "DOMAIN CLEAN"
2. PHPUnit test suite passes with 0 failures and 0 errors
3. All VPS pages return HTTP 200
4. No anti-pattern grep hits in the codebase

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
