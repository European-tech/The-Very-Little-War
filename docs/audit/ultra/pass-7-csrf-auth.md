# Pass 7 CSRF + Auth Audit

**Date:** 2026-03-08
**Auditor:** Automated agent (Pass 7 sweep)
**Scope:** All root-level PHP files + admin/ + moderation/ for CSRF and auth guard coverage

---

## Methodology

1. Listed all PHP files that handle `$_POST` or `$_SERVER['REQUEST_METHOD'] === 'POST'`
2. Diffed that list against files that call `csrfCheck()` or `csrfVerify()` — both lists must match for POST-mutating files
3. Verified each private game page includes `basicprivatephp.php` (or has equivalent inline session-token auth for AJAX endpoints)
4. Verified admin/ pages include `redirectionmotdepasse.php`
5. Verified moderation/ pages include moderation `mdp.php` shim
6. Scanned all files for GET-triggered DB mutations (CSRF bypass via idempotent HTTP verbs)

---

## Findings

### AUTH-P7-001 [LOW] — GET-triggered read-status mutation in rapports.php

**File:** `rapports.php:23`

**Description:** Viewing a combat report via `GET ?rapport=N` triggers an `UPDATE rapports SET statut=1 WHERE id = ?`. This marks the report as read without a CSRF token. An attacker who can get a victim to click a crafted link (e.g. via forum or message) can mark specific combat reports as read, causing the navbar attack badge to disappear.

**Code:**
```php
if(isset($_GET['rapport'])) {
    $rapportId = (int)$_GET['rapport'];
    $rapports = dbFetchOne($base, 'SELECT * FROM rapports WHERE id = ? AND destinataire = ?', 'is', $rapportId, $_SESSION['login']);
    $nb_messages = $rapports ? 1 : 0;
    if($nb_messages > 0) {
        dbExecute($base, 'UPDATE rapports SET statut=1 WHERE id = ?', 'i', $rapportId);  // ← GET mutation
```

**Impact:** Very limited. The update is scoped to the victim's own reports (the SELECT on line 20 validates ownership via `destinataire = $_SESSION['login']`). The attacker cannot read report contents, only flip the read-status flag. No game-state damage. No resource loss.

**Severity Rationale:** Low — read-status mutation, player-scoped, no game-state impact. This pattern (mark-as-read on GET) is widespread in web apps and accepted practice. Requiring POST for read-status would break link-based navigation.

**Fix (optional):** Accept this as a known low-risk pattern. If hardening is desired, change the mark-as-read to a separate POST endpoint or use a redirect-after-GET pattern.

---

### AUTH-P7-002 [LOW] — GET-triggered read-status mutation in messages.php

**File:** `messages.php:34`

**Description:** Identical pattern to AUTH-P7-001. Viewing a private message via `GET ?message=N` triggers `UPDATE messages SET statut=1 WHERE id = ?`. Ownership is verified by the SELECT on line 30 (`destinataire = $_SESSION['login']`), so only the recipient's own messages are affected.

**Code:**
```php
if(isset($_GET['message'])) {
    $messageId = (int)$_GET['message'];
    $messages = dbFetchOne($base, 'SELECT * FROM messages WHERE ( (destinataire = ? AND deleted_by_recipient=0) OR (expeditaire = ? AND deleted_by_sender=0) ) AND id = ?', 'ssi', $_SESSION['login'], $_SESSION['login'], $messageId);
    $nb_messages = $messages ? 1 : 0;
    if($nb_messages > 0) {
        if($_SESSION['login'] == $messages['destinataire']) {
            dbExecute($base, 'UPDATE messages SET statut=1 WHERE id = ?', 'i', $messageId);  // ← GET mutation
```

**Impact:** Same as AUTH-P7-001 — an attacker can force-mark a specific message as read, suppressing unread indicators. No message content exposed, no resources lost.

**Severity Rationale:** Low — same pattern as AUTH-P7-001. Mark-as-read on GET is standard practice. Ownership is properly verified before mutation.

**Fix (optional):** Same as AUTH-P7-001 — accept, or move to POST if hardening is desired.

---

## Clean Sections

The following areas were fully audited and found **CLEAN** — no missing CSRF guards or auth failures:

### Root-level POST handlers — all have csrfCheck()
| File | CSRF call location | Notes |
|---|---|---|
| alliance.php | Before each action block | Multiple actions each guarded |
| allianceadmin.php | Before each action block | |
| armee.php | Before each action block | |
| attaquer.php | Before each action block | |
| bilan.php | Before specialization POST | |
| compte.php | Top of file (line 6) | Guards all forms on the page |
| comptetest.php | Inside POST check | |
| constructions.php | Before each action block | |
| deconnexion.php | Before account deletion | Session token also verified |
| don.php | Inside POST check | |
| ecriremessage.php | Inside POST check | |
| editer.php | Before each action block | |
| inscription.php | Inside POST check | |
| laboratoire.php | Before each compound action | |
| listesujets.php | Inside POST check | |
| marche.php | Inside POST check | Before any DB write |
| messageCommun.php | Top of file (line 32) | |
| messages.php | Before delete operations | |
| moderationForum.php | Before each action block | |
| prestige.php | Inside POST check | |
| rapports.php | Before delete operations | |
| sujet.php | Inside POST check | |
| tutoriel.php | Inside POST check | |
| validerpacte.php | Top of file (line 5) | |
| voter.php | Inside POST check | AJAX endpoint; inline session-token auth replaces basicprivatephp.php |

### GET-only pages with no state mutation (correctly have no CSRF)
- attaque.php, bilan.php (display only), classement.php (search is read-only), credits.php, forum.php, guerre.php, historique.php (archive view), joueur.php, medailles.php, messagesenvoyes.php, molecule.php, regles.php, season_recap.php, sinstruire.php, vacance.php, version.php, health.php, connectes.php, alliance_discovery.php

### Auth guards — private pages
All private game pages include `basicprivatephp.php`:
- Verified for all 32 private pages
- `voter.php` is the only exception: it is a JSON-only AJAX endpoint with equivalent inline session-token validation + CSRF (intentional, documented in code comment)

### Admin panel (admin/)
- All subpages include `redirectionmotdepasse.php` which enforces `$_SESSION['motdepasseadmin'] === true`, idle timeout, and IP binding
- CSRF guards in place for all POST actions in: index.php, listenews.php, listesujets.php, supprimercompte.php, supprimerreponse.php, multiaccount.php
- `redigernews.php` has no POST handler (form posts to listenews.php)
- `tableau.php` and `ip.php` are read-only

### Moderation panel (moderation/)
- `index.php`: password-protected via own session, CSRF on all POST actions, IP binding
- `ip.php`: includes `mdp.php` auth shim, read-only
- `mdp.php`: auth shim with session + IP check

---

## Summary

**Total findings:** 2 (both LOW)

Both findings are the same pattern — mark-as-read mutations on GET in `rapports.php` and `messages.php`. These are intentional UX patterns (view-triggers-read) with player-scoped ownership verification, carrying near-zero real-world impact. No attacker can exploit them to gain game advantage or access other players' data.

**No MISSING CSRF guards** on any state-mutating POST handler.
**No MISSING auth guards** on any private page.
**No admin/moderation action reachable** without password authentication.
**No GET-based CSRF bypasses** with meaningful game impact.

The codebase is essentially **CLEAN** on CSRF and auth for Pass 7. The two LOW findings may be accepted as-is given their negligible impact.
