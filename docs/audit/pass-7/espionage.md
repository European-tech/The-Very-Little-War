# Pass 7 Audit — ESPIONAGE Domain
**Date:** 2026-03-08
**Agent:** Pass7-B2-ESPIONAGE

## Summary
| Severity | Count |
|----------|-------|
| CRITICAL | 1 |
| HIGH | 0 |
| MEDIUM | 0 |
| LOW | 1 |
| INFO | 8 |
| **Total** | **10** |

---

## CRITICAL Findings

### CRITICAL-001 — Defender notification sent on FAILED espionage (inverted logic)
**File:** `includes/game_actions.php:493`
**Description:** The defender notification condition is inverted. The same threshold comparison used to determine espionage success (line 375) is reused for notification at line 493:
```php
if ($espionageThreshold < $espActions['nombreneutrinos']) {
    // Notify defender they were spied on
    dbExecute($base, 'INSERT INTO rapports ... ');
}
```
This means:
- **Successful espionage:** Defender gets alerted (they see the spy report)
- **Failed espionage:** Defender sees nothing (no alert that an attempt was made)

This is backwards from the expected game mechanic where a successful spy is silent and a failed spy triggers a detection alert.

**Fix:** Invert the condition at line 493:
```php
// BEFORE (wrong):
if ($espionageThreshold < $espActions['nombreneutrinos']) {
// AFTER (correct):
if ($espionageThreshold >= $espActions['nombreneutrinos']) {
```

---

## LOW Findings

### LOW-001 — couleurFormule() output not escaped in display.php
**File:** `includes/display.php:64` (couleurFormule function)
**Description:** The `couleurFormule()` function applies regex replacements and returns HTML but does not escape its output. The call chain in game_actions.php does apply `htmlspecialchars()` before calling `couleurFormule()` (line 400), which is currently safe because formula strings only contain atom letters and subscripts. However, the function itself returns unescaped HTML, creating a latent XSS risk if called without the pre-escaping step.
**Fix:** For defense-in-depth, ensure all callers of `couleurFormule()` pre-escape input with `htmlspecialchars()`. Document this contract in a comment on the function.
**Status:** Currently mitigated by call-site escaping. No exploitable path exists today.

---

## Verified Clean

- CSRF protection: `csrfCheck()` called at attaquer.php:21 — clean.
- Session validation + self-spy prevention: `$_POST['joueurAEspionner'] != $_SESSION['login']` — clean.
- SQL injection: all queries use prepared statements — clean.
- CAS guard: `UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0` prevents double-processing — clean.
- Atomic cost deduction: neutrino deduction inside `withTransaction()` with FOR UPDATE — clean.
- Rate limiting: `rateLimitCheck('espionage_' . $_SESSION['login'], ...)` — clean.
- Beginner/vacation/shield protections: applied identically to combat — clean.
- Target validation: non-existent player checked before proceeding — clean.
