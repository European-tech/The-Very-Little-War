# Pass 7 XSS Audit

**Date:** 2026-03-08
**Scope:** All PHP files in `/home/guortates/TVLW/The-Very-Little-War/`
**Methodology:**
1. `grep -rn "echo.*\$_GET|echo.*\$_POST|echo.*\$_REQUEST|echo.*\$_COOKIE"` — superglobal echoes
2. `grep -rn 'echo \$[a-zA-Z]'` — variable echoes without known-safe wrappers
3. `grep -rn "echo.*\$donnees\['"` — DB row field echoes
4. `grep -rn "echo.*\$[a-zA-Z]"` filtered against all safe wrappers — residual scan
5. Manual review of all flagged lines for source tracing
6. JS injection audit: variables echoed inside `<script>` tags

---

## Findings

CLEAN — no new findings.

---

## Verification Notes

Every flagged echo was traced to its source. Findings grouped by pattern:

### Superglobals echoed to HTML
- `listesujets.php:218` — `echo (int)$_GET['id']` — integer cast. **Safe.**

### BBcode-rendered user content
All calls to `BBcode($text)` are safe: the function starts with `htmlspecialchars($text, ENT_QUOTES, 'UTF-8')` at line 14 of `includes/bbcode.php` before any tag substitution. Files verified: `messages.php`, `sujet.php`, `forum.php`, `moderationForum.php`, `joueur.php`, `alliance.php`, `editer.php`.

### Molecule formula display (`couleurFormule()`)
`couleurFormule()` in `includes/display.php` does not pre-escape its input, but every call site passes a formula value that is either:
- Constructed in `armee.php` from `$lettre[$num]` (hardcoded chemical symbols: C, N, H, O, Cl, S, Br, I) concatenated with `intval($_POST[$ressource])`, producing strings like `C<sub>5</sub>H`. No user-controlled text.
- Retrieved from `molecules.formule` which was stored via the above construction path only.

The neutrino edge case (`armee.php:308` echoes `$actionsformation['formule']` directly) is equally constrained: neutrino records store the same formula format from the same construction path. **Safe.**

### Combat report content (`rapports.php`)
`rapports.php:59` echoes `$content` after:
1. `strip_tags($rapports['contenu'], '<a><br>...')` — allowlist strips `<script>`, `<svg>`, `<iframe>`, etc.
2. `preg_replace('/\s+on\w+\s*=.../i', ...)` — removes all event handlers.
3. `preg_replace('/\s+style\s*=.../i', ...)` — removes style attributes.
4. `preg_replace('/href\s*=\s*["\']?\s*javascript\s*:/i', ...)` — neutralises JS hrefs.

Report content is generated server-side by `game_actions.php` and `allianceadmin.php`, where all player login and alliance tag values are wrapped in `htmlspecialchars()` before storage. **Safe.**

### News content (`index.php`)
News is authored by admins only (behind `redirectionmotdepasse.php`). Output uses `strip_tags()` with an allowlist plus three regex passes stripping event handlers, bare `<a>` tags, and `javascript:`/`data:` URIs. **Acceptable for admin-only content.**

### Flash messages (`$information` / `$erreur`)
Both variables are output via `json_encode()` inside a `<script>` tag in `includes/copyright.php`:
```php
echo "myApp.addNotification({ message: ".json_encode($information).", ...});";
```
When sourced from `$_GET`, `antiXSS()` (which calls `htmlspecialchars()`) is applied first in `includes/basicprivatephp.php`. When set programmatically, values are hardcoded strings or numeric data. **Safe.**

### Admin pages (`admin/multiaccount.php`)
- `$view` from `$_GET['view']` is never echoed directly. It only appears as the comparison operand in ternary expressions that output `'active'` or `''`. **Safe.**
- `$statusFilter` from `$_GET['status']` is validated with `in_array($statusFilter, ['open','investigating','confirmed','dismissed','all'], true)` before any echo. **Safe.**
- All DB-sourced fields (login, email, severity, flag_type, evidence, etc.) use `htmlspecialchars()`. **Safe.**

### Numeric DB fields echoed without escaping
Verified as integers/floats at source:
- `classement.php` — `$nbjoueurs`, `$nbSujetsCount`, `$donnees['id']` (int PK), `$donnees1['nbMessages']` (COUNT), prestige cache values cast to `(int)`.
- `alliance.php` — `totalPoints`, `points`, `ressourcesPillees`, `victoires` — all numeric game stats.
- `tutoriel.php` — `$completedMissions`, `$totalMissions`, `$progressPercent`, `$idx` — all integers derived from array operations.
- `forum.php` — `$nbSujets['nbSujets']`, `$nbMessages['cnt']` — COUNT results.
- `constructions.php:367` — `$actionsconstruction['niveau']` (int), `$actionsconstruction['affichage']` maps to `$liste['titre']` which is a hardcoded building name string from `includes/player.php` (`$listeConstructions` array). **Safe.**
- `marche.php:746` — `$tot` built from `date()` and `$cours['tableauCours']` (numeric price floats stored by market arithmetic, not user input). **Safe.**

### Style attributes with PHP values
- `tutoriel.php` — `$cardBorder`, `$opacity`, `$statusColor`, `$isClaimed`/`$isComplete` — all derived from boolean checks, hardcoded color strings, or `number_format($progressPercent)`. No user text.
- `attaquer.php` — `$mapPx`, `$tailleTile`, `$x`, `$y` — all integers (MAP constant × coordinate via `intval()`).
- `classement.php` — `$enGuerre` — always one of three hardcoded RGB strings (`"160,160,160"`, `"254,130,130"`, `"156,255,136"`).

### JS context echoes
All PHP values echoed inside `<script>` blocks are either:
- Integers/arithmetic results (coordinates, timestamps, tile sizes)
- Wrapped in `json_encode()` (admin tableau DB values, flash messages)
- Array indices (`$molecules['numeroclasse']` — integer slot number)

### Hardcoded data structures
`$PRESTIGE_UNLOCKS`, `$FORMATIONS`, `$CATALYSTS`, `$BUILDING_CONFIG`, `$nomsAccents`, `$lettre`, `$couleurs`, `$RESOURCE_NAMES_ACCENTED` — all defined in `includes/config.php` or `includes/player.php` as PHP literals. None sourced from DB or user input.

---

## Summary

**CLEAN — no new XSS findings in Pass 7.**

All DB-sourced string fields output to HTML use `htmlspecialchars()`, `BBcode()` (which starts with `htmlspecialchars()`), `joueur()`/`alliance()` wrappers, or `json_encode()` for JS contexts. Numeric fields are uniformly cast to `int`/`float` or processed through `number_format()`. The one area of intentional HTML passthrough (combat reports and news) applies appropriate `strip_tags()` allowlisting with event-handler regex stripping, and the content originates from server-side game logic rather than direct user input.

**Coverage:** 46 root PHP files + all files in `includes/`, `admin/`, `moderation/`, `tools/` examined.
