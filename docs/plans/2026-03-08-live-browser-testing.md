# Live Browser Testing Plan — Production Readiness Verification

> **For Claude:** This is the FINAL phase of the Ultra Audit loop. Execute this plan autonomously. Use the Chrome DevTools MCP to interact with the browser. Fix any bugs found inline and continue testing.

**Goal:** Prove the game is production-ready by playing through a complete game lifecycle on http://212.227.38.111, covering every major feature end-to-end.

**Server:** http://212.227.38.111
**DB Access:** `mysql -u tvlw -pmLLIoTy2ByGTBNb9RQpTGnqPhXQfUR tvlw`
**VPS SSH:** `ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111`

**Test Players to create:**
- Player 1 (main): login=`TestAudit1`, password=`AuditPass2026!`
- Player 2 (opponent): login=`TestAudit2`, password=`AuditPass2026!`
- Store both in DB notes if needed

**Rules:**
- If a bug is found: fix it locally, push, deploy to VPS, continue testing
- DB manipulation is allowed to accelerate (add resources, fast-forward timers)
- Document every bug found and its fix

---

## Phase A — CI Verification

### Task A1: Check CI pipeline status
**Step 1:** Run tests locally
```bash
cd /home/guortates/TVLW/The-Very-Little-War
php vendor/bin/phpunit --no-coverage 2>&1 | tail -5
```
Expected: 583+ tests, 0 failures (38 integration errors = expected)

**Step 2:** Verify git is clean and pushed
```bash
git status && git log --oneline -3
```

**Step 3:** Check GitHub Actions (if CI configured)
```bash
ls .github/workflows/
```

---

## Phase B — Public Pages (Unauthenticated)

### Task B1: Homepage
- Navigate to http://212.227.38.111/index.php
- Verify: page loads, no PHP errors, hero section visible, season countdown works
- Check: news section renders, CTA button present

### Task B2: Registration page
- Navigate to http://212.227.38.111/inscription.php
- Verify: form renders, autocomplete attributes on password fields
- Check: normalized login preview shown when typing

### Task B3: Rules page
- Navigate to http://212.227.38.111/regles.php
- Verify: renders without errors, V4 mechanics sections present

### Task B4: Alliance discovery (public)
- Navigate to http://212.227.38.111/alliance_discovery.php
- Verify: page loads (may redirect to login or show public view)

---

## Phase C — Registration Flow

### Task C1: Register Player 1
- Navigate to http://212.227.38.111/inscription.php
- Fill: login=`TestAudit1`, email=`testaudit1@test.com`, password=`AuditPass2026!`
- Submit and verify: success redirect, no duplicate key error
- **Expected:** Account created, redirected to login

### Task C2: Register Player 2
- Same flow for login=`TestAudit2`, email=`testaudit2@test.com`, password=`AuditPass2026!`

### Task C3: Verify in DB
```sql
SELECT login, email, x, y FROM membre WHERE login LIKE 'TestAudit%';
SELECT login, energie, timeMolecule FROM autre WHERE login LIKE 'TestAudit%';
```

---

## Phase D — Login and Dashboard

### Task D1: Login as TestAudit1
- Navigate to http://212.227.38.111/
- Fill login + password, submit
- Verify: redirect to game dashboard (basicprivatephp.php)
- Check: no PHP errors in page source, session established

### Task D2: Check tutorial triggers
- Verify tutorial missions appear
- Check tutorial UI renders correctly

### Task D3: Check navbar
- Season countdown visible in navbar
- Links to: carte, marche, prestige, classement, etc.

---

## Phase E — Tutorial Completion

### Task E1: Complete Mission 1 (move on map)
- Navigate to carte.php or equivalent
- Move player to a new position
- Return to tutoriel.php, verify mission 1 marked complete

### Task E2: Complete Mission 2 (upgrade building)
- Give TestAudit1 enough resources via DB:
```sql
UPDATE ressources SET carbone=10000, azote=5000, hydrogene=5000, oxygene=5000 WHERE login='TestAudit1';
UPDATE autre SET energie=50000 WHERE login='TestAudit1';
```
- Navigate to building upgrade page
- Upgrade producteur to level 2
- Verify tutorial mission 2 complete

### Task E3: Complete Mission 3 (produce atoms)
- Navigate to molecule production
- Produce atoms, verify mission 3 complete

### Task E4: Complete Mission 4 (set profile)
- Navigate to compte.php
- Set description to at least 10 chars
- Verify mission 4 (description mission) complete

### Task E5: Complete Mission 5 (espionage)
- Needs TestAudit2 on map
- Set both players near each other via DB:
```sql
UPDATE membre SET x=10, y=10 WHERE login='TestAudit1';
UPDATE membre SET x=11, y=10 WHERE login='TestAudit2';
```
- Launch espionage on TestAudit2
- Verify report created, mission 5 complete

---

## Phase F — Core Game Features

### Task F1: Building upgrades
- Test upgrading each of: producteur, depot, condenseur, duplicateur
- Verify costs deducted, levels incremented
- Verify no double-deduction (click twice fast)

### Task F2: Map and movement
- Navigate to carte.php
- Move player, verify coordinates updated in DB
- Verify resource node proximity bonus shown (if any)

### Task F3: Market (marche.php)
- Buy atoms from market
- Sell atoms to market
- Verify rate limit kicks in after many trades
- Verify tradeVolume cap is enforced

### Task F4: Molecule production (molecule.php)
- Convert atoms to a molecule class
- Verify cooldown applied (timeMolecule updated)
- Fast-forward timer via DB:
```sql
UPDATE autre SET timeMolecule=0 WHERE login='TestAudit1';
```
- Convert again, verify works

### Task F5: Isotope decay
- Navigate to isotope section
- Trigger decay on a molecule if possible
- Verify neutron loss logic

---

## Phase G — Combat System

### Task G1: Setup for combat
Give TestAudit1 a real army and resources:
```sql
UPDATE ressources SET carbone=50000, azote=50000, hydrogene=50000, oxygene=50000, chlore=50000, soufre=50000, brome=50000, iode=50000 WHERE login='TestAudit1';
UPDATE autre SET energie=200000 WHERE login='TestAudit1';
-- Give TestAudit2 some army too
UPDATE ressources SET carbone=10000, azote=10000 WHERE login='TestAudit2';
```

Place both players adjacent on map:
```sql
UPDATE membre SET x=15, y=15 WHERE login='TestAudit1';
UPDATE membre SET x=16, y=15 WHERE login='TestAudit2';
```

### Task G2: Launch attack
- Navigate to attaquer.php
- Select TestAudit2 as target
- Launch attack with some troops
- Verify attack queued in actionsattaques

### Task G3: Resolve combat (fast-forward)
```sql
-- Set travel time to past so combat resolves immediately
UPDATE actionsattaques SET tempsAller=UNIX_TIMESTAMP()-1, tempsAttaque=UNIX_TIMESTAMP()-1, tempsRetour=UNIX_TIMESTAMP()-1 WHERE attaquant='TestAudit1';
```
- Trigger combat resolution (visit any game page)
- Check rapports for combat report
- Verify attacker and defender both see report

### Task G4: Combat report
- Navigate to rapports.php
- Open the combat report
- Verify: no raw HTML entities, no PHP errors
- Verify: building HP not shown (only status)

### Task G5: Espionage
- Launch espionage on TestAudit2
- Verify rate limit (try 6 times in a minute)
- Check espionage report in rapports.php

---

## Phase H — Alliance System

### Task H1: Create alliance (as TestAudit1)
- Navigate to creeralliance.php or equivalent
- Create alliance named `AllianceTest`, tag `[AT]`
- Verify: alliance created, TestAudit1 is chief

### Task H2: Invite TestAudit2
- Login as TestAudit1
- Navigate to allianceadmin.php
- Invite TestAudit2
- Verify: invitation appears in invitations table

### Task H3: Accept invitation (as TestAudit2)
- Login as TestAudit2
- Navigate to alliance.php
- Accept invitation
- Verify: TestAudit2 now in AllianceTest

### Task H4: Alliance actions
- Post an alliance message
- Upgrade alliance building (give energy first)
- Test war declaration against another alliance (create one if needed)

---

## Phase I — Forum & Messaging

### Task I1: Create forum topic
- Navigate to listesujets.php
- Create new topic
- Post a reply with BBCode: [b]bold[/b], [joueur=TestAudit2]

### Task I2: Private messages
- Navigate to ecriremessage.php
- Send PM from TestAudit1 to TestAudit2
- Verify: flash message on success
- Login as TestAudit2, verify message received

### Task I3: Delete message
- Delete message (verify soft-delete works)
- Verify: message still visible to sender until they delete it

---

## Phase J — Prestige & Rankings

### Task J1: Check classement.php
- Navigate to classement.php
- Verify DENSE_RANK (tied players show same rank)
- Switch between tabs (combat, marche, etc.)

### Task J2: Prestige page
- Navigate to prestige.php
- Verify PP balance displayed
- Check streak PP shown (if logged in > 1 day, add via DB):
```sql
UPDATE autre SET streak_days=3 WHERE login='TestAudit1';
```

### Task J3: Bilan page
- Navigate to bilan.php
- Verify all bonus sections render
- Verify resource node, compound, specialization stages shown

---

## Phase K — Compound Lab

### Task K1: Open lab
- Navigate to laboratoire.php
- Verify compounds listed with costs

### Task K2: Synthesize compound
- Give enough resources
- Synthesize a compound
- Verify: active compound shown, timer running

### Task K3: Check compound effect in combat
- Launch attack while compound active
- Verify compound_atk_bonus captured in actionsattaques

---

## Phase L — Season End Simulation

### Task L1: Trigger season reset
Force the season to end:
```sql
-- Set maintenance check to trigger (set debut to 31+ days ago)
UPDATE statistiques SET debut=UNIX_TIMESTAMP()-2764800, maintenance=0;
```
- Login as admin (Guortates) or visit basicprivatephp.php
- Verify maintenance mode activates

### Task L2: Verify reset
After reset:
- Verify player resources zeroed (except medals/prestige)
- Verify season_recap has entry for past season
- Verify forum data persisted
- Verify sanctions cleared (temporary ones)
- Verify news cleared

### Task L3: Post-reset
- Login again as TestAudit1 (new season)
- Verify tutorial starts fresh
- Verify buildings reset to level 1

---

## Phase M — Error & Edge Cases

### Task M1: Invalid inputs
- Try registering with existing login → expect "login taken" error
- Try buying more atoms than energy allows → expect error
- Try attacking yourself → expect error
- Try donating negative energy → expect error

### Task M2: Rate limiting
- Try logging in with wrong password 10+ times
- Verify rate limit message appears

### Task M3: Maintenance mode
- Toggle maintenance on/off via admin
- Verify non-admin players see maintenance page
- Verify admin can still access

---

## Phase N — Final Checks

### Task N1: Check error logs on VPS
```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "tail -50 /var/www/html/logs/error.log 2>/dev/null || echo 'no log'"
```
Verify: no critical PHP errors logged during testing

### Task N2: Check Apache error log
```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "tail -20 /var/log/apache2/error.log"
```

### Task N3: Clean up test data
```sql
-- Remove test players (cascade deletes all related data)
DELETE FROM membre WHERE login IN ('TestAudit1', 'TestAudit2');
DELETE FROM alliances WHERE nom='AllianceTest';
```

### Task N4: Final HTTP smoke test
```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "for page in index.php inscription.php classement.php marche.php attaquer.php prestige.php bilan.php laboratoire.php maintenance.php; do code=\$(curl -s -o /dev/null -w '%{http_code}' http://localhost/\$page); echo \"\$code \$page\"; done"
```
All must return 200.

---

## Bug Tracking

During testing, record each bug here:

| # | Page | Description | Severity | Status |
|---|------|-------------|----------|--------|
| - | - | (filled during testing) | - | - |

---

## Completion Criteria

The game is **PRODUCTION READY** when:
- [ ] All Phase A-N tasks completed without blocking bugs
- [ ] All PHP errors from logs investigated and fixed
- [ ] All HTTP 200 on final smoke test
- [ ] Season end cycle completed successfully
- [ ] At least one full combat resolved correctly
- [ ] At least one PM sent/received correctly
- [ ] Test data cleaned up

**Execution:** This plan executes with the Chrome DevTools MCP for browser interaction and SSH/DB commands for acceleration. All bugs found must be fixed before continuing to next task.
