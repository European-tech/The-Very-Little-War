# Pass 9 — Private Messaging System Security Audit

**Date:** 2026-03-08
**Scope:** `ecriremessage.php`, `messages.php`, `messagesenvoyes.php`, `messageCommun.php`, `includes/bbcode.php` (messaging path)
**Auditor:** Narrow-domain security agent (Pass 9)

---

## Findings

---

### MSG-P9-001
**Severity:** HIGH
**File:** `messages.php:41`
**Title:** BBCode renders message body without `$javascript` flag — MathJax `[latex]` is stripped, but `[url]` link text is injected back into the DOM post-`htmlspecialchars`

**Description:**
`BBCode()` is called with the default `$javascript=false`, so `[latex]` is correctly stripped. However, after the initial `htmlspecialchars()` call in `BBCode()`, the `[url=...]...[/url]` regex injects the capture group `$2` (link text) back into an `<a>` tag verbatim:

```php
$text = preg_replace('!\[url=(https?://[^\]\s"<>\']+)\](.+?)\[/url\]!isU',
    '<a href="$1" rel="nofollow noopener noreferrer" target="_blank">$2</a>', $text);
```

Because `$text` has already been passed through `htmlspecialchars()` at the top of `BBCode()`, the link text `$2` is HTML-entity-encoded at that point — angle brackets and quotes become `&lt;`, `&gt;`, `&quot;`. This means injecting `<script>` inside `[url=...]` link text does **not** produce live HTML. The protection is intact; however, the pattern still allows arbitrarily long link text (no length cap on `$2`), which can be used for phishing by displaying URLs that mislead the reader about the real destination. This is a content-trust concern rather than a code-execution XSS.

**Risk refinement:** Downgraded from CRITICAL to HIGH because actual JS execution is blocked by the prior `htmlspecialchars()` pass, but the phishing/spoofing vector is genuine.

**Recommended fix:** Cap the visible link text to a maximum of 100 characters in the `[url]` regex replacement (e.g., `substr($2, 0, 100)`) and/or enforce a warning prefix when link text differs from the href domain.

---

### MSG-P9-002
**Severity:** MEDIUM
**File:** `ecriremessage.php:130`
**Title:** Reply pre-fill passes raw DB content to `creerBBcode()` without encoding — potential stored textarea injection

**Description:**
When loading a reply, the original message body is passed directly from the database into `creerBBcode()`:

```php
creerBBcode("contenu", $message['contenu'], 1);
$options = $message['contenu'];
```

The raw `$message['contenu']` is then output into the `<textarea>` on line 140:

```php
'<textarea name="contenu" id="contenu" rows="10" cols="50">'
    . htmlspecialchars($options, ENT_QUOTES, 'UTF-8') . '</textarea>'
```

The textarea itself is properly escaped. However, `creerBBcode()` is called with the raw DB string as its second argument. The current implementation of `creerBBcode()` (`includes/display.php:328`) ignores all arguments and only prints a static help string, so there is no live risk today. **But** if `creerBBcode()` is ever restored to its original functionality (it formerly echoed the second argument into JavaScript), this path would become a stored XSS sink. The raw value is passed where it should never reach output.

**Recommended fix:** Do not pass `$message['contenu']` to `creerBBcode()`; since the function currently ignores parameters, remove the second and third arguments to prevent a latent risk from future refactoring.

---

### MSG-P9-003
**Severity:** MEDIUM
**File:** `ecriremessage.php` (no line — missing check)
**Title:** No self-messaging guard — player can send messages to themselves

**Description:**
There is no check that `$_POST['destinataire']` (after canonical resolution) differs from `$_SESSION['login']`. A player can message themselves. While this is not directly exploitable for privilege escalation, it:
1. Wastes DB storage on a known-pointless action.
2. Creates a confusing UX where the inbox shows a message from yourself.
3. In certain edge-case flows (the reply guard at line 100 checks `$message['destinataire'] != $_SESSION['login']`) a self-sent message would slip through the reply check because both fields match — the player can reply to a message they sent to themselves, generating an infinite self-conversation loop that spams the messages table under the rate limit.

**Recommended fix:** After canonical login resolution, add `if ($canonicalLogin === $_SESSION['login']) { $erreur = "Vous ne pouvez pas vous envoyer un message à vous-même."; }` before the INSERT.

---

### MSG-P9-004
**Severity:** MEDIUM
**File:** `ecriremessage.php:15–18`
**Title:** Subject (titre) length cap is 200 chars but DB column is `varchar(255)` — no HTML `maxlength` attribute on input

**Description:**
The server-side cap for `titre` is 200 characters (line 15), which is correctly enforced. The content cap uses `MESSAGE_MAX_LENGTH` (5000, from `config.php`). However, the `<input>` for the subject field (line 118) has no `maxlength` HTML attribute:

```php
'<input type="text" class="form-control" name="titre" id="titre"
    value="' . htmlspecialchars($valueTitre, ENT_QUOTES, 'UTF-8') . '" />'
```

No `<textarea>` has a `maxlength` attribute either. The server-side check is authoritative and correct, but missing HTML-level feedback means the player can type past the limit and only discover the rejection after a round-trip. This is a UX/defense-in-depth gap, not a bypass.

**Recommended fix:** Add `maxlength="200"` to the titre input and `maxlength="<?= MESSAGE_MAX_LENGTH ?>"` to the contenu textarea to provide immediate client-side feedback.

---

### MSG-P9-005
**Severity:** MEDIUM
**File:** `messageCommun.php:59`
**Title:** Admin broadcast redirect passes raw interpolated count in URL — `information` GET param is not a constant

**Description:**
After sending a broadcast, the admin is redirected:

```php
header('Location: messages.php?information=' . urlencode("Message envoyé à $count joueurs."));
```

The `$count` variable is `count($membres)` — an integer from `count()`, which is safe. However, `basicprivatephp.php:68` picks up `$_GET['information']` and passes it through `antiXSS()` (which calls `htmlspecialchars()`), and then `json_encode()` in `copyright.php:84` encodes it for JavaScript insertion. The chain is safe. This is INFO level in practice, but the pattern of passing human-readable strings through GET redirects is fragile — if the string were ever derived from user input, it would become an open redirect/XSS vector.

**Severity revised to:** LOW (see MSG-P9-009)

---

### MSG-P9-006
**Severity:** LOW
**File:** `messages.php:30`
**Title:** Single-message view allows sender to view own sent message through the inbox URL — authorization is correct but nuanced

**Description:**
The authorization query for viewing a single message is:

```php
$messages = dbFetchOne($base, 'SELECT * FROM messages WHERE
    ( (destinataire = ? AND deleted_by_recipient=0) OR
      (expeditaire = ? AND deleted_by_sender=0) )
    AND id = ?', 'ssi', $_SESSION['login'], $_SESSION['login'], $messageId);
```

This correctly restricts access to the message owner (sender or recipient). A third-party player cannot access another pair's messages — the prepared statement binds `$_SESSION['login']` for both parties. Authorization is sound.

However, when the sender views a message they sent (`expeditaire = $_SESSION['login']`), the code at line 37 conditionally marks the message read only when `$_SESSION['login'] == $messages['destinataire']`. The sender viewing their own sent message via `messages.php?message=N` does **not** accidentally mark it read — this is correct. The issue is that `messagesenvoyes.php` links sent messages to `messages.php?message=N` (line 28), which means senders read their sent messages through the inbox URL, and the soft-delete "Supprimer" button on that view deletes from the **recipient** side (`deleted_by_recipient=1`) rather than the sender side:

```php
// messages.php:15-16 — delete always sets deleted_by_recipient
dbExecute($base, 'UPDATE messages SET deleted_by_recipient=1 WHERE id = ? AND destinataire = ?', ...);
```

If the sender is viewing the message and clicks "Supprimer," the WHERE clause `destinataire = ?` won't match (sender ≠ recipient), so the delete silently does nothing — the message remains in both inboxes. Senders cannot delete messages they sent from the sent-message-detail view.

**Recommended fix:** In `messages.php`, detect whether the viewing player is the sender or recipient and apply the appropriate soft-delete column (`deleted_by_sender` vs `deleted_by_recipient`).

---

### MSG-P9-007
**Severity:** LOW
**File:** `ecriremessage.php:61`
**Title:** Rate limit (10 messages / 5 minutes) applies per-username, not per-IP — can be bypassed by creating multiple accounts

**Description:**
`rateLimitCheck($_SESSION['login'], 'private_msg', 10, 300)` limits by session login (username). A player who registers multiple accounts (or uses the anti-multiaccounting system imperfectly) could send 10 messages per account per 5-minute window. At 10 messages/300s per account, even 3 accounts produce 30 messages in 5 minutes — enough to spam a victim's inbox before the soft-delete and pagination hide them.

The rate limiter file is keyed on `hash('sha256', json_encode([$identifier, $action]))`, which is username-based only. There is no IP-based secondary limiter for the message action specifically.

**Recommended fix:** Add an IP-based secondary rate limit (e.g., 20 messages per IP per 5 minutes) as a defense-in-depth layer against multi-account spam.

---

### MSG-P9-008
**Severity:** LOW
**File:** `ecriremessage.php:121–127`
**Title:** `$_GET['destinataire']` pre-fills the recipient field without validation — open pre-fill injection

**Description:**
The "write message" form pre-fills the recipient field from `$_GET['destinataire']` without any validation of the value:

```php
if (isset($_GET['destinataire'])) {
    $valueDestinataire = trim($_GET['destinataire']);
}
```

The value is HTML-escaped before output (line 127: `htmlspecialchars($valueDestinataire, ENT_QUOTES, 'UTF-8')`), so there is no XSS risk. However, an attacker can craft a link such as:

```
ecriremessage.php?destinataire=admin&titre=Urgent+security+alert
```

to pre-populate the form with a recipient and subject when a victim clicks it. This is a social engineering / phishing vector — a crafted link can make a player appear to be composing a message to the admin. Combined with a spoofed `?id=` parameter that loads another player's message (which is blocked by the auth check), this is low severity.

**Recommended fix:** Validate that `$_GET['destinataire']` exists in the `autre` table before using it as a pre-fill value, providing a cleaner user experience and preventing misleading pre-fill attacks.

---

### MSG-P9-009
**Severity:** LOW
**File:** `messageCommun.php:59` / `basicprivatephp.php:67–68`
**Title:** `information` and `erreur` GET parameters accepted on any private page — reflected information insertion

**Description:**
`basicprivatephp.php` unconditionally reads `$_GET['information']` and `$_GET['erreur']` on every private page load, sanitizes with `antiXSS()` (htmlspecialchars), and stores in `$information`/`$erreur`. These are then injected into JavaScript via `json_encode()` in `copyright.php`. The JavaScript context encoding (`json_encode`) correctly escapes the value for JS string context.

However, any external link (e.g., in a forum post or private message) can point to `messages.php?information=Votre+compte+a+été+piraté` and display an alarming fake notification to the victim. The content cannot execute JavaScript (XSS is blocked), but it can display arbitrary text in the notification UI, enabling phishing/social engineering.

**Recommended fix:** Use session-based flash messages (already implemented via `$_SESSION['flash_message']` in `messages.php`) rather than GET-parameter-based notifications, or whitelist allowable notification strings.

---

### MSG-P9-010
**Severity:** INFO
**File:** `ecriremessage.php:7–9` / `messages.php:6–11`
**Title:** CSRF protection is correctly applied on all state-changing POST operations

**Description:**
All POST actions that modify state call `csrfCheck()`:
- `ecriremessage.php:8` — message send (all variants: single, alliance broadcast, global admin broadcast)
- `messages.php:7` and `messages.php:12` — individual and bulk delete

`csrfField()` is emitted in all forms. No CSRF vulnerability found.

---

### MSG-P9-011
**Severity:** INFO
**File:** `ecriremessage.php:65` / `messages.php:30`, `messagesenvoyes.php:11,17`
**Title:** SQL injection — all queries use prepared statements; no injection vectors found

**Description:**
Every database query in the messaging system uses `dbExecute`, `dbFetchOne`, `dbFetchAll`, and `dbCount` with parameterized placeholders. Reviewed queries:
- Message INSERT (single, alliance broadcast, admin broadcast)
- Message SELECT with authorization checks
- Message soft-delete UPDATE and physical DELETE
- Recipient existence lookup

No raw string interpolation into SQL found.

---

### MSG-P9-012
**Severity:** INFO
**File:** `ecriremessage.php:65–67`
**Title:** Recipient validation correctly queries DB for existence before sending

**Description:**
For single-recipient messages, the code performs a case-insensitive lookup:

```php
$canonicalRow = dbFetchOne($base, 'SELECT login FROM autre WHERE LOWER(login)=LOWER(?)', 's', $_POST['destinataire']);
```

Only if the player exists does the INSERT proceed. Non-existent recipients produce a sanitized error message (line 75 uses `htmlspecialchars`). No message is sent to ghost/non-existent users.

---

### MSG-P9-013
**Severity:** INFO
**File:** `ecriremessage.php:17–18`
**Title:** Content length limits enforced server-side using `MESSAGE_MAX_LENGTH` (5000 chars)

**Description:**
Both subject (200 chars) and content (`MESSAGE_MAX_LENGTH` = 5000) are validated server-side with `mb_strlen`. The admin broadcast in `messageCommun.php:43` also validates against `MESSAGE_MAX_LENGTH`. No storage abuse bypass found.

---

### MSG-P9-014
**Severity:** INFO
**File:** `includes/bbcode.php:14` / `messages.php:41`
**Title:** XSS in message display — content is safe via BBCode's leading `htmlspecialchars()`

**Description:**
`BBCode()` applies `htmlspecialchars($text, ENT_QUOTES, 'UTF-8')` as its very first operation before any regex substitution. All subsequent tag replacements operate on already-encoded content. The HTML tags injected by BBCode patterns are hardcoded strings (not user-controlled). The `[img]` tag is restricted to self-hosted images only (whitelist of `images/`, `/path.ext`, and `theverylittlewar.com` URLs). The `[color]` tag is restricted to a named-color whitelist. The `[url]` link text is HTML-entity-safe. No XSS execution path found.

---

### MSG-P9-015
**Severity:** INFO
**File:** `messages.php:33–38`
**Title:** Authorization on single-message view is correct — third parties cannot read messages

**Description:**
The query gate `(destinataire = ? OR expeditaire = ?) AND id = ?` binds only `$_SESSION['login']`. Unauthorized access redirects to `messages.php`. The `?message=` parameter is cast to `(int)`. No IDOR vulnerability found.

---

## Summary Table

| ID | Severity | File | Issue |
|----|----------|------|-------|
| MSG-P9-001 | HIGH | `messages.php:41` / `bbcode.php:29` | `[url]` link text phishing via mismatched anchor text |
| MSG-P9-002 | MEDIUM | `ecriremessage.php:130` | Raw DB content passed to `creerBBcode()` — latent XSS sink |
| MSG-P9-003 | MEDIUM | `ecriremessage.php` (missing) | No self-messaging guard; enables self-loop spam |
| MSG-P9-004 | MEDIUM | `ecriremessage.php:118,140` | No HTML `maxlength` attributes on titre/contenu inputs |
| MSG-P9-006 | LOW | `messages.php:15-16` | Sender cannot delete their own sent messages via detail view |
| MSG-P9-007 | LOW | `ecriremessage.php:61` | Rate limit is username-only; multi-account bypass possible |
| MSG-P9-008 | LOW | `ecriremessage.php:121-127` | `?destinataire=` pre-fill enables social engineering links |
| MSG-P9-009 | LOW | `basicprivatephp.php:67-68` | GET `?information=` / `?erreur=` enables fake notification injection |
| MSG-P9-010 | INFO | `ecriremessage.php:8`, `messages.php:7,12` | CSRF: correctly protected on all POST actions |
| MSG-P9-011 | INFO | All files | SQL injection: all queries parameterized; no SQLI found |
| MSG-P9-012 | INFO | `ecriremessage.php:65-67` | Recipient validation: DB lookup before send; no ghost-send |
| MSG-P9-013 | INFO | `ecriremessage.php:15-18` | Length limits: server-side cap enforced correctly |
| MSG-P9-014 | INFO | `bbcode.php:14`, `messages.php:41` | XSS in display: BBCode leading `htmlspecialchars()` blocks all XSS |
| MSG-P9-015 | INFO | `messages.php:33-38` | Auth: IDOR impossible; all queries bound to `$_SESSION['login']` |

---

FINDINGS: 0 critical, 1 high, 3 medium, 4 low
