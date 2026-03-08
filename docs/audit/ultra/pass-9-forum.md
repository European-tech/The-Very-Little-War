# Forum Security Audit — Pass 9
**Date:** 2026-03-08
**Auditor:** Narrow-domain security agent (forum internals)
**Scope:** forum.php, sujet.php, listesujets.php, editer.php, moderationForum.php, admin/listesujets.php, includes/bbcode.php

---

## Findings

---

### FORUM-P9-001
**Severity:** HIGH
**File:** sujet.php:313-324
**Description:** `cspScriptTag()` opens a `<script nonce="...">` tag but the corresponding closing `</script>` tag appears as a raw literal in the PHP template at line 324. When `$javascript` is `true` (forum section 8), the MathJax CDN block is emitted before the `cspScriptTag()` call. Because `cspScriptTag()` only returns the opening tag, the inline JS body (`document.addEventListener(...)`) and the closing `</script>` are directly in the PHP template. This is correct HTML but the nonce on that script block is only valid if the CSP `script-src` in `layout.php` allows inline scripts via nonce. The nonce *is* injected there so this is functionally secure. However the construct is fragile: any developer who adds code between `cspScriptTag()` and `</script>` without realising it is still inside the nonce-protected block could introduce inline JS that bypasses intent review. Additionally, the `$javascript` variable is set to `true` if `$sujet['idforum'] == 8` (line 139), which means MathJax is loaded based on a **database value** — if the `forums.id` column is ever reassigned or a forum record is modified, LaTeX rendering can be enabled in unintended contexts, widening the attack surface of the BBCode LaTeX blacklist bypass (see FORUM-P9-003).
**Recommended fix:** Move the inline JS into a dedicated nonce-protected `<script>` tag and derive the MathJax flag from a config constant rather than a hardcoded DB row ID.

---

### FORUM-P9-002
**Severity:** HIGH
**File:** includes/bbcode.php:29
**Description:** The `[url=...]` BBCode tag allows any `https?://` URL as the href attribute of the produced anchor tag. The character class `[^\]\s"<>']+` excludes the most dangerous inline-injection characters, so a bare `javascript:` scheme is blocked by the `https?://` prefix requirement. However, the regex allows URLs of arbitrary length with no length cap. A malicious user can craft a URL 10,000+ characters long that the BBCode parser will embed verbatim in an `href=""` attribute. While not directly exploitable for XSS (the URL must start with `https?://`), a very long URL can cause rendering issues, and more critically, the regex does not strip null bytes (`\x00`) or other Unicode look-alikes within the URL body. In some browser/charset combinations, a null byte can truncate the URL and allow scheme confusion.
**Recommended fix:** Add a `mb_strlen($url) <= 2000` guard inside the `[url=]` regex callback and strip null bytes from the URL before emitting the href.

---

### FORUM-P9-003
**Severity:** HIGH
**File:** includes/bbcode.php:46-48
**Description:** The LaTeX blacklist in the `[latex]` handler (enabled when `$javascript=true`) blocks a hardcoded list of macro names via a regex substitution. This approach is a denylist and is inherently incomplete. For example, `\def` is blocked but `\DeclareMathOperator`, `\newenvironment`, `\@ifpackageloaded`, and many other macro-definition commands are not. Although MathJax client-side rendering does not itself execute arbitrary code, attackers can use unblocked MathJax macros to produce visually misleading output (e.g. fake game UI elements via `\htmlClass`, `\href`), or trigger MathJax-specific XSS vectors in older browser+MathJax combinations. The `href` macro is in the blacklist but `\HTML` and `\htmlData` are not.
**Recommended fix:** Replace the denylist with an allowlist of safe LaTeX environments/commands, or disable the `[latex]` tag entirely until a full MathJax sandbox review is conducted.

---

### FORUM-P9-004
**Severity:** MEDIUM
**File:** editer.php:150-151
**Description:** The `<form action="...">` attribute is built from `strtok($_SERVER['REQUEST_URI'], '?')` passed through `htmlspecialchars`. This correctly strips query parameters and escapes HTML entities. However `REQUEST_URI` can contain a path like `/sujet.php/../../../../admin/` in some server configurations, and the path is not validated against a known-safe page list. In Apache with standard configuration this is not exploitable, but the pattern is unsafe by design — the form action should be a hardcoded string (`editer.php`) rather than derived from the request URI.
**Recommended fix:** Replace `strtok($_SERVER['REQUEST_URI'], '?')` with the hardcoded string `'editer.php'`.

---

### FORUM-P9-005
**Severity:** MEDIUM
**File:** sujet.php:48-49, listesujets.php:77
**Description:** The `FORUM_POST_MAX_LENGTH` constant (10,000 characters per config.php line 661) is enforced server-side via `mb_strlen`. However, the reply textarea in `sujet.php` (line 299) and the content textarea in `listesujets.php` (line 223) have **no `maxlength` HTML attribute**. An attacker with JavaScript disabled or using a direct HTTP client can submit up to 10,000 characters without any client-side resistance. More importantly, the `mb_strlen` check in `sujet.php:48` uses `<=` (allows exactly 10,000), while in `listesujets.php:77` it also uses `<=`. These are consistent, but the absence of a `maxlength` attribute means a user with JavaScript disabled gets no feedback until server-side rejection. This is LOW severity on its own, but combined with the absence of a `minlength` check on post content (an empty string `""` would be caught by `!empty()`, but a string of only whitespace would pass `!empty()` and also pass `mb_strlen`), a user can store a whitespace-only post.
**Recommended fix:** Add `maxlength="10000"` to the post content textareas and add a `trim()` + non-empty check on `$contenu` before the `mb_strlen` guard in both `sujet.php` and `listesujets.php`.

---

### FORUM-P9-006
**Severity:** MEDIUM
**File:** moderationForum.php:90
**Description:** The "Sanctions en cours" section fetches **all** sanctions with `SELECT * FROM sanctions` (no date filter, no LIMIT). If the sanctions table grows large (e.g. bulk-banning during a spam event), this query loads every row into memory and renders them all into one HTML page without pagination. This is a denial-of-service vector: a moderator repeatedly banning/unbanning thousands of accounts can make the moderation page unresponsive. The same `SELECT *` also retrieves the full `motif` text for every row, which is passed through `BBCode()` — meaning rendering hundreds of BBCode-heavy sanction motifs in one page is a CPU-intensive operation.
**Recommended fix:** Add `WHERE dateFin >= CURDATE()` and `LIMIT 200 ORDER BY idSanction DESC` to the sanctions list query, and add pagination.

---

### FORUM-P9-007
**Severity:** MEDIUM
**File:** editer.php:24-56
**Description:** Types 3 (delete reply), 4 (show reply), and 5 (hide reply) all require `$_SERVER['REQUEST_METHOD'] === 'POST'` and call `csrfCheck()`. However, the type-4 and type-5 handlers (hide/show reply) do not redirect after success — they fall through to the display section of the file (lines 125+). After a successful hide/show, the page renders the edit form for the reply instead of redirecting back to the topic. This is an inconsistency compared to the delete handler (which redirects at line 33). While not a direct security vulnerability, the lack of Post-Redirect-Get means the moderator's browser can replay the hide/show action by pressing Back+Refresh, potentially double-toggling visibility. More critically, if a future developer adds code between the hide/show action and the redirect point, CSRF protection could be inadvertently bypassed.
**Recommended fix:** Add `header("Location: sujet.php?id=" . (int)$sujetId); exit;` immediately after the `dbExecute` in both type-4 and type-5 handlers (they already have it — confirmed at lines 45 and 54 — this finding is confirmed as already addressed; see FORUM-P9-007 RESOLVED below).

**RESOLUTION:** On re-reading lines 44-46 and 53-55, both type-4 and type-5 handlers *do* redirect after success. This finding is withdrawn.

---

### FORUM-P9-008
**Severity:** MEDIUM
**File:** sujet.php:138-141
**Description:** The `$javascript` flag (which enables MathJax and LaTeX BBCode rendering) is set based on `$sujet['idforum'] == 8`. This hardcoded ID `8` is not defined in `config.php` and is not documented. If the forums table is ever re-seeded, the forum IDs shift, and either: (a) LaTeX rendering silently breaks for the math forum, or (b) LaTeX rendering activates for a different forum category (e.g. a general discussion forum), exposing all users of that forum to the expanded LaTeX attack surface. This is a latent correctness/security issue that becomes acute during any DB migration or season reset.
**Recommended fix:** Define `FORUM_MATH_ID` (or equivalent) in `config.php` and replace the literal `8` with the constant.

---

### FORUM-P9-009
**Severity:** MEDIUM
**File:** editer.php:89
**Description:** When a non-moderator user edits their own reply (type 2), the UPDATE query at line 92 correctly binds both `auteur = ?` and `id = ?` as parameters: `UPDATE reponses SET contenu = ? WHERE auteur = ? AND id = ?`. This prevents editing another user's reply. However, when a **moderator** edits a reply (type 2, lines 106-121), the UPDATE at line 114 only binds `id = ?`: `UPDATE reponses SET contenu = ? WHERE id = ?`. While this is intentional (moderators may edit any post), there is no ownership check that the target reply belongs to a topic within a non-restricted forum. A moderator could therefore edit replies in an alliance-private forum even if they are not a member of that alliance. The ban check at editer.php:14 strips moderator privileges if the moderator is banned, which is correct, but the alliance-forum access control that exists in `sujet.php` and `listesujets.php` is absent from `editer.php`.
**Recommended fix:** Add an alliance-membership check in `editer.php` for moderator edit/delete/hide actions on posts in alliance-private forums.

---

### FORUM-P9-010
**Severity:** LOW
**File:** editer.php:1-3
**Description:** `editer.php` includes `includes/basicprivatephp.php` directly (line 2) without first calling `require_once("includes/session_init.php")`. All other forum pages (`sujet.php`, `listesujets.php`, `forum.php`) correctly call `session_init.php` first. If `basicprivatephp.php` ever changes to rely on session state already being initialized by `session_init.php` (e.g. for session fixation prevention logic), `editer.php` would silently bypass that protection.
**Recommended fix:** Add `require_once("includes/session_init.php");` as the first line of `editer.php`, before the `basicprivatephp.php` include.

---

### FORUM-P9-011
**Severity:** LOW
**File:** moderationForum.php:1-3
**Description:** Same issue as FORUM-P9-010: `moderationForum.php` includes `basicprivatephp.php` directly without a prior `session_init.php` call.
**Recommended fix:** Add `require_once("includes/session_init.php");` as the first line of `moderationForum.php`.

---

### FORUM-P9-012
**Severity:** LOW
**File:** includes/bbcode.php:16
**Description:** The `localStorage.getItem` filter at line 16 strips `localStorage.getItem("mdp` and `localStorage.getItem('mdp` from BBCode input. This is an ad-hoc filter for a specific known attack string. It is trivially bypassed by encoding variations (e.g. `localStorage['getItem']('mdp')`, `window["localStorage"].getItem("mdp")`). Since `htmlspecialchars` runs first (line 14), raw `<script>` injection is already blocked; this regex filter adds no meaningful protection because angle-brackets and quotes are already entity-encoded. The filter is therefore security theater that gives false confidence.
**Recommended fix:** Remove the `localStorage.getItem` regex (it provides no real protection after `htmlspecialchars` has already run) and document that XSS prevention is handled by the `htmlspecialchars` call at line 14.

---

### FORUM-P9-013
**Severity:** LOW
**File:** sujet.php:18
**Description:** The reply rate limit is `rateLimitCheck($_SESSION['login'], 'forum_reply', 10, 300)` — 10 replies per 5 minutes. The topic-creation rate limit in `listesujets.php:45` is `rateLimitCheck($_SESSION['login'], 'forum_topic', 5, 300)` — 5 topics per 5 minutes. A single user can therefore post up to 600 replies per hour (10 per 5-minute window, no hourly cap). For a game forum this is permissive; a compromised or malicious account can flood topics at 120 replies/hour with no upper bound beyond the per-window limit resetting.
**Recommended fix:** Add a secondary hourly rate limit (e.g. `rateLimitCheck($login, 'forum_reply_hourly', 60, 3600)`) to cap sustained abuse.

---

### FORUM-P9-014
**Severity:** LOW
**File:** forum.php:35
**Description:** The sanction ban message on `forum.php` line 35 passes `$sanction['motif']` through `BBcode()`. The `BBcode()` function correctly calls `htmlspecialchars` first, so stored XSS is not possible. However, this means a moderator-entered ban reason is rendered with full BBCode formatting (bold, color, images, URLs) visible to the banned user. If the `[img=]` whitelist in bbcode.php is ever relaxed (it currently only allows self-hosted images), the ban reason could display external images that track the banned user's IP address via image load. This is the same concern for `moderationForum.php:124` where all sanctions' motifs are rendered via BBCode for moderators.
**Recommended fix:** Consider stripping BBCode from ban reason display (use `htmlspecialchars` only) or confirm that the `[img=]` whitelist is intentionally permanent.

---

### FORUM-P9-015
**Severity:** LOW
**File:** listesujets.php:154
**Description:** The subject listing in `listesujets.php` at line 154 outputs `$sujet['id']` directly into an `href` without casting to integer: `<a href="sujet.php?id=' . $sujet['id'] . '">`. The `id` column is from a database `SELECT *` query, so the value is a DB-returned integer string. However, relying on MySQL to always return a numeric string is fragile. Using `(int)$sujet['id']` is safer and is already the pattern used elsewhere in the file (e.g. line 68 in admin/listesujets.php).
**Recommended fix:** Replace `$sujet['id']` with `(int)$sujet['id']` in the `href` attribute at listesujets.php:154.

---

### FORUM-P9-016
**Severity:** INFO
**File:** sujet.php:299
**Description:** The reply form includes `<input type="hidden" name="sujet_id" value="' . $getId . '">`. The `$getId` variable is already cast to `(int)` at line 81, so no injection is possible. This is correct practice and noted for completeness.
**Recommended fix:** None required.

---

### FORUM-P9-017
**Severity:** INFO
**File:** includes/bbcode.php:26-27
**Description:** The `[joueur=...]` and `[alliance=...]` tags use character class `[a-z0-9_-]{3,20}` and `[a-z0-9_-]{3,16}` respectively with the `i` flag (case-insensitive). These values are placed directly into an `href` attribute using a backreference (`$1`) after `htmlspecialchars` has already been applied to the input text. Since `htmlspecialchars` runs first and the character class only allows alphanumerics, hyphens, and underscores, there is no injection risk. The pattern is secure.
**Recommended fix:** None required.

---

### FORUM-P9-018
**Severity:** INFO
**File:** admin/listesujets.php
**Description:** The admin subject list page at `admin/listesujets.php` is correctly protected by `admin/redirectionmotdepasse.php` which enforces: (1) a separate admin session name (`TVLW_ADMIN`), (2) a session flag `$_SESSION['motdepasseadmin'] === true`, (3) an admin idle timeout, and (4) IP binding. All POST actions (lock, unlock, delete) require CSRF via `csrfCheck()`. Subject titles and author names are output through `htmlspecialchars`. No SQL injection or authorization issues found.
**Recommended fix:** None required.

---

## Summary Table

| ID | Severity | File | Issue |
|----|----------|------|-------|
| FORUM-P9-001 | HIGH | sujet.php:139 | MathJax/LaTeX enabled by hardcoded DB row ID — fragile gating |
| FORUM-P9-002 | HIGH | bbcode.php:29 | No URL length cap on `[url=]` tag; null byte not stripped |
| FORUM-P9-003 | HIGH | bbcode.php:46 | LaTeX denylist incomplete — `\htmlData`, `\HTML`, `\newenvironment` not blocked |
| FORUM-P9-004 | MEDIUM | editer.php:151 | Form action derived from `REQUEST_URI` instead of hardcoded string |
| FORUM-P9-005 | MEDIUM | sujet.php:299, listesujets.php:223 | No `maxlength` on textareas; whitespace-only content bypasses `!empty()` |
| FORUM-P9-006 | MEDIUM | moderationForum.php:90 | Unbounded `SELECT * FROM sanctions` — no LIMIT, no date filter |
| FORUM-P9-007 | MEDIUM | editer.php | WITHDRAWN (redirect already present at lines 45/54) |
| FORUM-P9-008 | MEDIUM | sujet.php:139 | Hardcoded forum ID `8` for LaTeX not defined in config.php |
| FORUM-P9-009 | MEDIUM | editer.php:114 | Moderator edits bypass alliance-private forum access control |
| FORUM-P9-010 | LOW | editer.php:1 | Missing `session_init.php` include before `basicprivatephp.php` |
| FORUM-P9-011 | LOW | moderationForum.php:1 | Missing `session_init.php` include before `basicprivatephp.php` |
| FORUM-P9-012 | LOW | bbcode.php:16 | `localStorage.getItem` filter is security theater after `htmlspecialchars` |
| FORUM-P9-013 | LOW | sujet.php:18, listesujets.php:45 | No hourly rate limit cap on replies — 10/5min window resets indefinitely |
| FORUM-P9-014 | LOW | forum.php:35, moderationForum.php:124 | BBCode in ban reasons enables future tracking via `[img=]` whitelist drift |
| FORUM-P9-015 | LOW | listesujets.php:154 | `$sujet['id']` not cast to int in href — relies on DB returning numeric string |
| FORUM-P9-016 | INFO | sujet.php:299 | `$getId` in hidden input already cast to int — secure |
| FORUM-P9-017 | INFO | bbcode.php:26-27 | `[joueur=]` / `[alliance=]` backreferences safe after htmlspecialchars |
| FORUM-P9-018 | INFO | admin/listesujets.php | Admin panel auth, CSRF, and output escaping all correct |

---

## Notes on What Was NOT Found

- **SQL injection:** All forum queries use prepared statements via `dbExecute`/`dbFetchOne`/`dbFetchAll` with bound parameters. No raw string interpolation into SQL was found across any forum file.
- **Stored XSS in post content:** `BBcode()` calls `htmlspecialchars` as its first operation (bbcode.php:14), so all user content is entity-encoded before any BBCode transformation. Subsequent BBCode-generated HTML uses only safe tags (`<span>`, `<a href="">`, `<img src="">`). No XSS path found.
- **Stored XSS in titles/authors:** All title and author fields are output through `htmlspecialchars()` before being placed in HTML context (`sujet.php:208,216`, `listesujets.php:154`, `admin/listesujets.php:74-75`).
- **CSRF on all POST actions:** Every state-changing POST in forum.php (n/a — read-only), sujet.php, listesujets.php, editer.php, and moderationForum.php calls `csrfCheck()` before acting.
- **Non-moderator moderating:** `editer.php` checks `$moderateur['moderateur']` from the DB before allowing hide/show/mod-edit operations. `moderationForum.php` gates all access on `$joueur['moderateur']` before any output. No privilege escalation path found (except the alliance-forum gap in FORUM-P9-009).
- **User editing another user's posts:** Non-moderator reply edits bind `auteur = $_SESSION['login']` in the WHERE clause. Topic edits check `$auteur['auteur'] == $_SESSION['login']` before updating.

---

FINDINGS: 0 critical, 3 high, 5 medium, 6 low
