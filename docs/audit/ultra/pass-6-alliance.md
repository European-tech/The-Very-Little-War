# Pass 6 Alliance/Social Audit

## Findings

---

### SOC-P6-001 [HIGH] Pact accept/refuse form stripped by strip_tags in rapports.php — pacts can never be accepted

**File:** `allianceadmin.php:279-285` (form generation) / `rapports.php:29-35` (display)

**Description:**
When a pact proposal is sent, the notification report stored in `rapports.contenu` contains a raw `<form>` element with `<input>` and `<button>` tags (the Accept/Refuse buttons). When the recipient opens the report in `rapports.php`, the content is passed through `strip_tags($rapports['contenu'], $allowedTags)` where `$allowedTags` does not include `<form>`, `<input>`, or `<button>`. The entire accept/refuse widget is silently stripped. The recipient sees only the text "L'alliance X vous propose un pacte." with no buttons. Pacts can therefore never be accepted through the report system — the only acceptance path is broken.

**Code (form generation, allianceadmin.php:279-285):**
```php
$rapportContenu = 'L\'alliance <a href="alliance.php?id=' . urlencode($chef['tag']) . '">' . $safeTag . '</a> vous propose un pacte.
<form action="validerpacte.php" method="post">
' . csrfField() . '
<input type="submit" value="Accepter" name="accepter"/>
<input type="submit" value="Refuser" name="refuser"/>
<input type="hidden" value="' . $idDeclaration['id'] . '" name="idDeclaration"/>
</form>';
```

**Code (display sanitizer, rapports.php:29-30):**
```php
$allowedTags = '<a><br><br/><strong><b><i><em><p><div><span><img><table><tr><td><th><ul><ol><li><hr>';
$content = strip_tags($rapports['contenu'], $allowedTags);
```

**Fix:** Replace the embedded form approach with a link-based action. Store only the declaration ID in the report, and render the Accept/Refuse buttons from a separate endpoint like `validerpacte.php?idDeclaration=X`, or add `<form>`, `<input>`, `<button>` to `$allowedTags` and handle the CSRF token correctly (see SOC-P6-002 below).

---

### SOC-P6-002 [HIGH] CSRF token embedded in pact report belongs to sender, not recipient — always fails validation

**File:** `allianceadmin.php:281` (embed) / `validerpacte.php:5` (check)

**Description:**
The pact notification report embeds `csrfField()` at the time the *sending* officer creates the pact proposal. This stores the **sender's** CSRF session token in the `rapports` table. When the *recipient* submits the form, `csrfCheck()` in `validerpacte.php` compares the submitted token against `$_SESSION['csrf_token']` of the recipient's session, which is a completely different value. Even if the `strip_tags` issue (SOC-P6-001) were fixed, every pact acceptance attempt would return HTTP 403.

This is a compound bug: the pact system is doubly broken — form stripped by sanitizer AND CSRF mismatch even if form were rendered.

**Code:**
```php
// In allianceadmin.php — sender's request context:
$rapportContenu = '...<form action="validerpacte.php" method="post">
' . csrfField() . '   // ← sender's $_SESSION['csrf_token']
...';
dbExecute($base, 'INSERT INTO rapports VALUES(...)', ...);

// In validerpacte.php — recipient's request context:
csrfCheck();  // compares against recipient's $_SESSION['csrf_token'] — always fails
```

**Fix:** Remove the CSRF token from the stored report. Instead, render the Accept/Refuse buttons dynamically when displaying the report, using the current viewer's CSRF token. The authorization check in `validerpacte.php` (alliance membership + pact ownership) already ensures only the target alliance chef/officer can act — the CSRF token just needs to be generated at render time, not at write time.

A minimal fix: in `rapports.php`, after `strip_tags`, detect report types containing a pact declaration ID and inject fresh action buttons:
```php
// Replace stored form content with dynamically generated form at display time
if (preg_match('/idDeclaration[^0-9]+(\d+)/', $rapports['contenu'], $m)) {
    // Append fresh form; the DB-stored form is already stripped
    echo '<form action="validerpacte.php" method="post">' . csrfField()
       . '<input type="hidden" name="idDeclaration" value="' . (int)$m[1] . '"/>'
       . '<input type="submit" name="accepter" value="Accepter"/>'
       . '<input type="submit" name="refuser" value="Refuser"/></form>';
}
```
Or better: restructure so pact proposals link to a dedicated `/pacte-action.php?id=X` page that renders its own CSRF-protected form.

---

### SOC-P6-003 [MEDIUM] Leadership transfer (changerchef) does not remove the old chef's grade entry if they had one

**File:** `allianceadmin.php:170-190`

**Description:**
When a chef transfers leadership to another member via `changerchef`, the code updates `alliances.chef` to the new login but does not remove any existing `grades` row for the old chef. If the outgoing chef was previously a regular member who was granted a grade before becoming chef, their grade row remains in the `grades` table. After transfer, the old chef is just a regular member, but their stale grade entry still gives them officer permissions in `allianceadmin.php` (since `$existeGrade` is derived from the `grades` table). The new chef cannot demote the old chef from officer status without explicitly using the "Supprimer un grade" UI. Meanwhile the old chef retains `$bannir`, `$pacte`, `$guerre`, `$inviter`, `$description` permissions they should no longer hold once they lose the chef role.

**Code:**
```php
withTransaction($base, function() use ($base, $currentAlliance, $newChef) {
    $member = dbFetchOne($base, 'SELECT login FROM autre WHERE idalliance=? AND login=? FOR UPDATE', ...);
    if (!$member) throw new \RuntimeException('NOT_IN_ALLIANCE');
    dbExecute($base, 'UPDATE alliances SET chef=? WHERE id=?', 'si', $newChef, ...);
    // Missing: DELETE FROM grades WHERE login=<old_chef> AND idalliance=<id>
});
```

**Fix:** Inside the `changerchef` transaction, add:
```php
$oldChef = $_SESSION['login'];
dbExecute($base, 'DELETE FROM grades WHERE login=? AND idalliance=?', 'si', $oldChef, $currentAlliance['idalliance']);
```

---

### SOC-P6-004 [MEDIUM] Grade string explosion without length/format validation — malformed grade string causes PHP notice or incorrect permission booleans

**File:** `allianceadmin.php:30`

**Description:**
The grade string (format `inviter.guerre.pacte.bannir.description`) is extracted from the DB and exploded via `list($inviter, $guerre, $pacte, $bannir, $description) = explode('.', $grade['grade'])`. If the stored grade string is malformed (e.g., fewer than 5 segments due to a DB edit or a bug in insertion), PHP will emit undefined-variable notices and the `=== '1'` comparisons will evaluate to `false`. While the security outcome (no permissions granted) is safe-fail, a malformed string with *more* segments than expected does not cause issues; a string with fewer segments silently gives reduced permissions with no error surfaced to the admin. There is no validation of the grade string format before parsing.

**Code:**
```php
list($inviter, $guerre, $pacte, $bannir, $description) = explode('.', $grade['grade']);
$inviter     = ($inviter === '1');
$guerre      = ($guerre === '1');
// etc.
```

**Fix:** Validate segment count before trusting the string:
```php
$bits = explode('.', $grade['grade']);
if (count($bits) !== 5) {
    // Treat as no permissions; optionally log a warning
    [$inviter, $guerre, $pacte, $bannir, $description] = [false, false, false, false, false];
} else {
    [$inviter, $guerre, $pacte, $bannir, $description] = array_map(fn($b) => $b === '1', $bits);
}
```

---

### SOC-P6-005 [MEDIUM] No limit on the number of grades per alliance — DoS via grade table bloat

**File:** `allianceadmin.php:84-125`

**Description:**
The grade creation block has no upper bound on how many grades a single alliance can hold. A chef or an officer with `inviter` rights cannot create grades, but the chef can. However, there is no cap. A malicious or careless chef could insert hundreds of grade rows per alliance. The `grades` table is read without pagination or limit in several display loops (e.g., `alliance.php:305`, `allianceadmin.php:519`), so a very large number of grades will cause slow page loads. The grades SELECT at `allianceadmin.php:519` fetches all grades for an alliance at once.

**Code:**
```php
// allianceadmin.php — no count check before INSERT
dbExecute($base, 'INSERT INTO grades VALUES(?,?,?,?)', 'ssss',
    $_POST['personnegrade'], $gradeStr, $chef['id'], $_POST['nomgrade']);
```

**Fix:** Add a maximum grade count check before INSERT. Since each grade is per-member, a practical cap equals MAX_ALLIANCE_MEMBERS (20):
```php
$gradeCount = dbCount($base, 'SELECT COUNT(*) FROM grades WHERE idalliance=?', 'i', $chef['id']);
if ($gradeCount >= MAX_ALLIANCE_MEMBERS) {
    $erreur = "Nombre maximum de grades atteint.";
} else {
    dbExecute($base, 'INSERT INTO grades VALUES(?,?,?,?)', ...);
}
```

---

### SOC-P6-006 [MEDIUM] `listesujets.php` topic count excludes locked topics (statut≠0) but the topic list query includes them — pagination is off-by-N

**File:** `listesujets.php:80` (forum.php count), `listesujets.php:113,123`

**Description:**
In `forum.php` (line 80), the per-forum topic count uses `WHERE statut = 0` (open topics only) for the "Sujets" column display. In `listesujets.php`, the pagination total count (line 113) uses `SELECT count(*) FROM sujets WHERE idforum = ?` (all topics including locked, `statut=1`). The listing query (line 123) also selects all topics. This means:
1. The forum index shows a lower topic count than actually exists (only open ones), which could be intentional.
2. The pagination calculation in `listesujets.php` counts *all* topics but the display sort puts locked first (`ORDER BY statut, timestamp DESC`) — so pagination is consistent within `listesujets.php` itself.

However, the `$nb_resultats` used to drive pagination does not match what a user might expect from the forum index count (which excluded locked topics). This is a minor UX inconsistency rather than a critical bug, but worth noting.

Additionally, in `forum.php`'s `statutforum` read/unread comparison (lines 85-89), `$nbSujets['nbSujets']` only counts open topics (statut=0) but `statutforum` may have entries for locked topics too, slightly skewing the "unread" indicator.

**Fix:** Make both counts consistent — either both include all topics or both exclude locked topics. The simpler fix is to remove `AND statut = 0` from the `forum.php` nbSujets query so it matches what `listesujets.php` actually shows.

---

### SOC-P6-007 [LOW] Pact break in `allianceadmin.php` only shows pacts where own alliance is `alliance1` — pacts where own alliance is `alliance2` can be broken by the ally but not by us via UI

**File:** `allianceadmin.php:559-591`

**Description:**
The pact listing renders two tables: `pacteRows1` (pacts where own alliance is `alliance1`) and `pacteRows2` (pacts where own alliance is `alliance2`). The "break pact" form in `pacteRows2` correctly passes `alliance1` as the `allie` hidden field (line 588). The `allie` POST handler in the break-pact logic (lines 298-328) uses a symmetric query to find the pact:
```php
'((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?)) AND type=1 AND valide!=0'
```
This is correctly symmetric — both sides can break a pact. So there is no bug in who can break. However, there is a display issue: the break button for pacts listed in `pacteRows2` passes `$pacte['alliance1']` as the value for `allie`, which is the *other* alliance's ID, not the own alliance. The break handler's query uses: `$chef['id']` (own alliance) and `$allieId` (posted value). For row in `pacteRows2`, the posted `allie` value is `alliance1` = the *other* alliance. That combination `($chef['id'], $allianceAdverse['id'])` correctly represents the pact. So the break *works* for both directions. **No functional bug** — the logic is symmetric. This is an INFO-level observation only.

**Severity downgrade:** INFO — not a bug.

---

### SOC-P6-008 [LOW] `allianceadmin.php` invitation check for alliance fullness is not inside a transaction — race condition allows over-inviting

**File:** `allianceadmin.php:402-428`

**Description:**
When sending an invitation, the check `$nombreJoueurs < $joueursEquipe` (line 402) uses `$nombreJoueurs` fetched at page load time (line 23, before any POST processing). By the time the invitation INSERT executes, additional players could have joined, pushing membership to the cap. The invitation itself doesn't add a member, but it means players can receive invitations to a full alliance and then accept them (the accept-invitation path *does* check member count inside a transaction, so the join is correctly blocked). The invitation just becomes useless spam, but it is misleading. The real-time check on invite is slightly stale.

This is LOW severity because the critical path (join) is protected. Stale invitation checks only cause UX confusion.

**Fix:** Re-fetch the live member count inside the invitation logic rather than relying on the page-load value:
```php
$liveCount = dbCount($base, 'SELECT COUNT(*) FROM autre WHERE idalliance=?', 'i', $currentAlliance['idalliance']);
if ($liveCount < $joueursEquipe) { // proceed }
```

---

### SOC-P6-009 [LOW] `sujet.php` inline script incorrectly placed after `cspScriptTag()` closes but `</script>` appears on line 324 without matching open tag in PHP output

**File:** `sujet.php:319-324`

**Description:**
`cspScriptTag()` returns an opening `<script nonce="...">` tag (without closing tag). Lines 320-323 then contain raw JavaScript that are *not inside* a `<?php ... ?>` block — they are literal PHP file content output directly. This works correctly. However, the orphaned `</script>` on line 324 closes the tag opened by `cspScriptTag()`. This pattern is intentional and correct. **No bug** — INFO observation only.

---

## Summary

| ID | Severity | Description |
|----|----------|-------------|
| SOC-P6-001 | HIGH | Pact accept/refuse form stripped by `strip_tags` — pacts unacceptable |
| SOC-P6-002 | HIGH | CSRF token embedded in pact report belongs to sender — always 403 |
| SOC-P6-003 | MEDIUM | Leadership transfer does not remove old chef's grade — stale officer privileges |
| SOC-P6-004 | MEDIUM | Grade string not validated before explode — malformed strings silently fail |
| SOC-P6-005 | MEDIUM | No cap on grades per alliance — DoS via grade table bloat |
| SOC-P6-006 | MEDIUM | Topic count inconsistency between forum.php and listesujets.php pagination |
| SOC-P6-007 | INFO | Pact break symmetric — no bug (observation only) |
| SOC-P6-008 | LOW | Invitation fullness check uses stale page-load count, not live DB query |
| SOC-P6-009 | INFO | sujet.php script tag structure — correct, no bug |

**Total actionable findings: 7** (2 HIGH / 3 MEDIUM / 1 LOW / 1 INFO)

**Critical path:** SOC-P6-001 and SOC-P6-002 together mean the pact system is **completely non-functional** for acceptance via the report notification. Pacts can be proposed but never accepted through the intended UI flow. The only workaround would be if the target alliance chef already knows the declaration ID and submits directly to `validerpacte.php` with a valid CSRF token — which is not exposed anywhere in the UI.
