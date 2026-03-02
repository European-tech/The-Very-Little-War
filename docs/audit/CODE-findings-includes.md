# CODE QUALITY AUDIT: includes/ directory

## The Very Little War -- includes/ (41 PHP files)

### SUMMARY

| Severity | Count | Key Themes |
|----------|-------|------------|
| HIGH | 10 | SQL injection via raw query(), antiXSS double-encoding, combat.php include architecture, 30+ globals, variable-variables, session password hash, hardcoded credentials |
| MEDIUM | 15 | Duplicate definitions, magic numbers, loose comparisons, N+1 queries, duplicate JS, missing include_once, HTTP mixed content, encoding artifacts |
| LOW | 13 | Missing type hints, unused globals, stale BUG comments, naming inconsistency, empty files, JS redirect |
| **Total** | **38** | |

---

### HIGH SEVERITY

**FINDING-CODE-001:** SQL Injection via unparameterized column/table names in ajouter()
- File: includes/db_helpers.php:18-24
- `ajouter($champ, $bdd, ...)` interpolates $champ and $bdd directly into SQL
- Remediation: Add whitelist of allowed column/table names

**FINDING-CODE-002:** SQL Injection via unparameterized column name in allianceResearchLevel()
- File: includes/db_helpers.php:39
- `$techName` interpolated directly into SELECT
- Remediation: Validate against $ALLIANCE_RESEARCH keys

**FINDING-CODE-003:** Legacy query() function bypasses prepared statements entirely
- File: includes/db_helpers.php:7-16
- Still called in atomes.php:5, copyright.php:52, menus.php:21
- Remediation: Replace all call sites with dbQuery(), remove query()

**FINDING-CODE-004:** antiXSS() applies triple-escaping: addslashes + htmlspecialchars + mysqli_real_escape_string
- File: includes/display.php:327-335
- With prepared statements, mysqli_real_escape_string is unnecessary; addslashes corrupts data
- Remediation: Replace with just sanitizeOutput() for output context

**FINDING-CODE-005:** combat.php included via include() inside function, pollutes caller scope
- File: includes/game_actions.php:102
- 602 lines creating dozens of loose variables in caller scope
- Remediation: Refactor to function resolveCombat() returning structured result

**FINDING-CODE-006:** updateActions() is 528-line function with extreme cyclomatic complexity
- File: includes/game_actions.php:7-528
- Handles constructions, formations, attacks, espionage, trade, HTML reports
- Remediation: Extract into separate focused functions

**FINDING-CODE-007:** initPlayer() creates 30+ global variables via variable-variables
- File: includes/player.php:104-449
- 345-line function with no return value, all state via globals, generates JS inline
- Remediation: Return associative array/object instead of globals

**FINDING-CODE-008:** Variable-variable abuse throughout combat.php
- File: includes/combat.php:9-601
- ${'classeAttaquant1'} through ${'classeAttaquant4'} etc.
- Remediation: Use indexed arrays: $classeAttaquant[1..4]

**FINDING-CODE-009:** Session password hash stored in $_SESSION['mdp']
- File: includes/basicpublicphp.php:61, includes/basicprivatephp.php:19
- Full bcrypt hash in session increases exposure
- Remediation: Use session token/version counter instead

**FINDING-CODE-010:** Hardcoded DB credentials in connexion.php
- File: includes/connexion.php:2-5
- root/empty password in repo (VPS has correct values)
- Remediation: Move to .env or config.local.php outside webroot

---

### MEDIUM SEVERITY

**FINDING-CODE-011:** Duplicate data definitions between config.php and constantesBase.php
- $nomsRes, $nomsAccents, $couleurs etc. in both files

**FINDING-CODE-012:** Magic numbers remain despite config.php constants (0.1, 500, 2678400)
- formulas.php uses hardcoded values, config.php constants unused

**FINDING-CODE-013:** Loose comparisons (== instead of ===) - 40+ instances

**FINDING-CODE-014:** formulas.php functions depend on global $base for DB access
- "Pure functions" comment but 7/17 query DB

**FINDING-CODE-015:** catalystEffect() hits DB on every invocation, no caching
- Called 10+ times per combat resolution

**FINDING-CODE-016:** N+1 query pattern in updateRessources() - 40+ queries per call

**FINDING-CODE-017:** Variable-variables in updateRessources() for resource looping

**FINDING-CODE-018:** basicprivatephp.php applies unnecessary sanitization to session login

**FINDING-CODE-019:** constantes.php calls initPlayer() at include time (side effect)
- Included twice per request = initPlayer() runs twice

**FINDING-CODE-020:** partenariat.php loads jQuery over HTTP (not HTTPS)

**FINDING-CODE-021:** menus.php date uses "A " encoding artifact

**FINDING-CODE-022:** include() without _once in basicpublicphp.php and basicprivatephp.php

**FINDING-CODE-023:** bbcode.php defines storeCaret() function twice

**FINDING-CODE-024:** bbcode.php references undefined 'as_mef' variable (typo for 'as_mf')

**FINDING-CODE-025:** mots.php and copyright.php contain 100% duplicated name generator JS (104 lines)

---

### LOW SEVERITY

**FINDING-CODE-026:** Unused $base parameter in coutClasse()
**FINDING-CODE-027:** placeDepot() uses hardcoded 500 instead of BASE_STORAGE_PER_LEVEL
**FINDING-CODE-028:** Copyright.php loads scripts after </html> closing tag
**FINDING-CODE-029:** pointsVictoireJoueur() uses hardcoded values instead of config constants
**FINDING-CODE-030:** Legacy global arrays used instead of config.php equivalents
**FINDING-CODE-031:** No type hints on any function parameters across all files
**FINDING-CODE-032:** session_start() called before session_status() check
**FINDING-CODE-033:** redirectionVacance.php uses JS redirect instead of HTTP 302
**FINDING-CODE-034:** basicprivatehtml.php duplicateur bonus formula inconsistent (0.1% vs 1%)
**FINDING-CODE-035:** cardspublic.php is an empty file
**FINDING-CODE-036:** update.php uses stale revenue fields from ressources table
**FINDING-CODE-037:** BUG comment markers left in production code
**FINDING-CODE-038:** Naming inconsistency: mixed French/English and camelCase/snake_case

---

### Top 3 Refactoring Targets
1. Eliminate query() function and all raw SQL (CODE-003)
2. Refactor combat.php from include-file into function (CODE-005)
3. Replace global state in initPlayer() with returned data structure (CODE-007)
