# FILE-INVENTORY: Complete PHP File Inventory and Dead Code Analysis

**Audit Date:** 2026-03-03
**Scope:** All PHP files in /home/guortates/TVLW/The-Very-Little-War/ (excluding vendor/ and tests/)
**Total project PHP files:** 85 (+ 11 test files + vendor)
**Total project LOC:** 16,658 lines

---

## Table of Contents

1. [Summary Statistics](#summary-statistics)
2. [Include Chain Architecture](#include-chain-architecture)
3. [Complete File Inventory](#complete-file-inventory)
4. [Orphaned Files](#orphaned-files)
5. [Duplicate Functionality](#duplicate-functionality)
6. [Missing References](#missing-references)
7. [Config Files with Secrets](#config-files-with-secrets)
8. [Recommended Merges and Deletions](#recommended-merges-and-deletions)
9. [Dead Code Estimates](#dead-code-estimates)
10. [Test Files](#test-files)

---

## Summary Statistics

| Category | Count | LOC |
|----------|-------|-----|
| Page files (root) | 41 | 8,255 |
| Include files | 38 | 7,045 |
| Admin files | 9 | 1,358 |
| Moderation files | 3 | 306 |
| Migration files | 1 | 69 |
| **Total** | **85** | **16,658** |

| Status | Files |
|--------|-------|
| Active (in use) | 74 |
| Dead/Orphaned | 5 |
| Partial (contains dead code) | 6 |

---

## Include Chain Architecture

### Private Page Flow
```
page.php
  -> includes/basicprivatephp.php
       -> includes/session_init.php (session hardening)
       -> includes/connexion.php
            -> includes/env.php (loads .env)
            -> includes/database.php (dbQuery, dbFetchOne, dbFetchAll, dbExecute, withTransaction)
       -> includes/fonctions.php (shim)
            -> includes/formulas.php (pure math: attaque, defense, pillage, etc.)
            -> includes/game_resources.php (updateRessources, revenuEnergie, revenuAtome)
            -> includes/game_actions.php (updateActions, combat resolution)
            -> includes/player.php (initPlayer, inscrire, supprimerJoueur, remiseAZero)
            -> includes/ui_components.php (debutCarte, item, chip, etc.)
            -> includes/display.php (image, couleurFormule, antiXSS, etc.)
            -> includes/db_helpers.php (ajouter, alliance, allianceResearchBonus)
            -> includes/prestige.php (cross-season progression)
            -> includes/catalyst.php (weekly catalyst system)
       -> includes/csrf.php (csrfToken, csrfCheck)
       -> includes/validation.php (validateLogin, validateEmail, etc.)
       -> includes/logger.php (gameLog, logInfo, logWarn, logError)
       [session auth, resource update, action processing, season reset]
       -> includes/constantes.php
            -> includes/constantesBase.php
                 -> includes/config.php (ALL game constants)
  -> includes/tout.php (HTML layout wrapper)
       -> includes/meta.php (meta tags)
       -> includes/style.php (CSS)
       -> includes/basicprivatehtml.php or includes/basicpublichtml.php
            -> includes/atomes.php (points/molecule display)
            -> includes/statistiques.php (stats panel)
            -> includes/ressources.php (resource bar)
            -> includes/cardsprivate.php (tutorial cards)
  -> includes/copyright.php (footer)
```

### Public Page Flow
```
page.php
  -> includes/basicpublicphp.php
       -> includes/connexion.php (DB)
       -> includes/fonctions.php (shim)
       -> includes/csrf.php
       -> includes/logger.php
       -> includes/rate_limiter.php
       -> includes/session_init.php
  -> includes/tout.php (layout)
  -> includes/copyright.php (footer)
```

---

## Complete File Inventory

### Page Files (Root Directory)

| File | Type | Auth | LOC | Tables Touched | Status | Dead % |
|------|------|------|-----|----------------|--------|--------|
| `index.php` | Page | Dual (pub/priv) | 121 | membre, news, connectes, statistiques (via includes) | **Active** | 0% |
| `inscription.php` | Page | Public | 79 | membre (via inscrire), validation | **Active** | 0% |
| `constructions.php` | Page | Player | 361 | constructions, actionsconstruction, ressources, autre, molecules | **Active** | 0% |
| `armee.php` | Page | Player | 445 | molecules, actionsformation, ressources, constructions, autre | **Active** | 0% |
| `atomes.php` | Page (display) | Player | 12 | molecules, autre | **Active** | 0% |
| `attaque.php` | Page | Player | 97 | membre, actionsattaques, autre, molecules | **Active** | 0% |
| `attaquer.php` | Page | Player | 530 | actionsattaques, membre, molecules, ressources, constructions, autre | **Active** | 0% |
| `classement.php` | Page | Dual | 636 | autre, alliances, parties, membre, molecules, declarations | **Active** | 0% |
| `compte.php` | Page | Player | 247 | membre, autre, prestige | **Active** | 0% |
| `comptetest.php` | Page | Public | 108 | statistiques, membre, autre, constructions, molecules, ressources, ... | **Active** | 0% |
| `connectes.php` | Page | Dual | 38 | connectes, membre | **Active** | 0% |
| `credits.php` | Page | Dual | 23 | none | **Active** | 0% |
| `deconnexion.php` | Page | Player | 39 | membre | **Active** | 0% |
| `don.php` | Page | Player | 66 | autre, alliances, ressources | **Active** | 0% |
| `ecriremessage.php` | Page | Player | 105 | messages, membre, moderation | **Active** | 0% |
| `editer.php` | Page | Player | 143 | sujets, reponses | **Active** | 0% |
| `forum.php` | Page | Dual | 106 | sujets, reponses, statutforum, membre | **Active** | 0% |
| `guerre.php` | Page | Dual | 70 | declarations, alliances | **Active** | 0% |
| `historique.php` | Page | Dual | 190 | parties | **Active** | 0% |
| `joueur.php` | Page | Dual | 94 | membre, autre, molecules, alliances | **Active** | 0% |
| `listesujets.php` | Page | Dual | 177 | sujets, reponses, statutforum | **Active** | 0% |
| `maintenance.php` | Page | Public | 19 | news | **Active** | 0% |
| `marche.php` | Page | Player | 614 | ressources, constructions, autre, actionsenvoi, alliances | **Active** | 0% |
| `medailles.php` | Page | Player | 89 | autre | **Active** | 0% |
| `messageCommun.php` | Page | Admin | 50 | messages, membre | **Active** | 0% |
| `messages.php` | Page | Player | 118 | messages | **Active** | 0% |
| `messagesenvoyes.php` | Page | Player | 35 | messages | **Active** | 0% |
| `moderationForum.php` | Page | Moderator | 139 | sujets, reponses, sanctions, moderation, membre | **Active** | 0% |
| `molecule.php` | Page | Player | 70 | molecules, constructions | **Active** | 0% |
| `rapports.php` | Page | Player | 113 | rapports | **Active** | 0% |
| `regles.php` | Page | Dual | 82 | none | **Active** | 0% |
| `sinstruire.php` | Page | Dual | 326 | none (static help content) | **Active** | 0% |
| `sujet.php` | Page | Dual | 223 | sujets, reponses, statutforum, membre | **Active** | 0% |
| `tutoriel.php` | Page | Player | 492 | autre, constructions, molecules, ressources | **Active** | 0% |
| `vacance.php` | Page | Player | 9 | vacances (via includes) | **Active** | 0% |
| `validerpacte.php` | Page | Player | 40 | declarations, alliances | **Active** | 0% |
| `version.php` | Page | Dual | 231 | none (static changelog) | **Active** | 0% |
| `alliance.php` | Page | Dual | 441 | alliances, autre, invitations, declarations, membre | **Active** | 0% |
| `allianceadmin.php` | Page | Player | 539 | alliances, autre, invitations, declarations, membre | **Active** | 0% |
| `api.php` | API | Player | 95 | membre, molecules, constructions, autre (via formula functions) | **Active** | 0% |
| `voter.php` | API | Player | 54 | sondages, reponses | **Active** | 0% |
| `annonce.php` | Page | Player | 25 | none (hardcoded 2015 announcement) | **DEAD** | 100% |
| `video.php` | Page | None | 39 | liens | **DEAD** | 100% |

### Include Files (includes/)

| File | Type | LOC | Included By | Tables Touched | Status | Dead % |
|------|------|-----|-------------|----------------|--------|--------|
| `session_init.php` | Infra | 13 | basicprivatephp, basicpublicphp, many pages | none | **Active** | 0% |
| `env.php` | Infra | 13 | connexion.php | none (reads .env file) | **Active** | 0% |
| `connexion.php` | Infra | 22 | basicprivatephp, basicpublicphp, admin, video | none (DB connection) | **Active** | 0% |
| `database.php` | Infra | 121 | connexion.php (auto), admin/listesujets | all (provides DB helpers) | **Active** | 0% |
| `config.php` | Config | 629 | constantesBase.php | none (pure constants) | **Active** | 0% |
| `constantesBase.php` | Config | 55 | constantes.php, admin pages, moderation | none (loads config + display arrays) | **Active** | 5% |
| `constantes.php` | Config | 16 | basicprivatephp.php | none (calls initPlayer) | **Active** | 0% |
| `fonctions.php` | Shim | 18 | basicprivatephp, basicpublicphp, admin, comptetest | all (loads 9 modules) | **Active** | 0% |
| `formulas.php` | Module | 265 | fonctions.php | autre, constructions (medal lookups) | **Active** | 0% |
| `game_resources.php` | Module | 201 | fonctions.php | ressources, constructions, autre, molecules, alliances | **Active** | 0% |
| `game_actions.php` | Module | 543 | fonctions.php | actionsattaques, actionsformation, actionsconstruction, molecules, rapports, actionsenvoi, ressources, autre, constructions | **Active** | 0% |
| `player.php` | Module | 838 | fonctions.php | membre, autre, constructions, molecules, ressources, alliances, invitations, messages, rapports, reponses, moderation, sanctions, statutforum, sujets, grade, vacances, actionsattaques, actionsconstruction, actionsformation, actionsenvoi, prestige, attack_cooldowns | **Active** | 5% |
| `ui_components.php` | Module | 629 | fonctions.php | none (pure UI rendering) | **Active** | 5% |
| `display.php` | Module | 352 | fonctions.php | reponses, membre (rangForum) | **Active** | 0% |
| `db_helpers.php` | Module | 88 | fonctions.php | autre, ressources, alliances | **Active** | 0% |
| `prestige.php` | Module | 210 | fonctions.php | prestige, autre, membre | **Active** | 0% |
| `catalyst.php` | Module | 88 | fonctions.php | statistiques | **Active** | 0% |
| `combat.php` | Engine | 633 | game_actions.php (included inline) | molecules, constructions, ressources, autre, alliances, actionsattaques, declarations, attack_cooldowns | **Active** | 0% |
| `update.php` | Engine | 59 | NOT INCLUDED ANYWHERE | autre, constructions, ressources, molecules | **DEAD** | 100% |
| `csrf.php` | Security | 30 | basicprivatephp, basicpublicphp, many pages | none (session only) | **Active** | 0% |
| `validation.php` | Security | 21 | basicprivatephp, inscription | none | **Active** | 0% |
| `logger.php` | Infra | 50 | basicprivatephp, basicpublicphp, admin, moderation | none (writes to logs/) | **Active** | 0% |
| `rate_limiter.php` | Security | 55 | basicpublicphp, comptetest, api, admin, moderation | none (writes to /tmp/) | **Active** | 0% |
| `basicprivatephp.php` | Auth Guard | 308 | 25+ private pages | membre, connectes, autre, constructions, statistiques, parties, alliances, news, vacances, prestige | **Active** | 5% |
| `basicpublicphp.php` | Auth Guard | 87 | index, inscription, many public pages | membre, connectes | **Active** | 0% |
| `basicprivatehtml.php` | Layout | 463 | tout.php (private mode) | messages, rapports, sujets, statutforum, autre, alliances, invitations, membre, molecules, constructions, ressources | **Active** | 0% |
| `basicpublichtml.php` | Layout | 26 | tout.php (public mode) | none (static menu) | **Active** | 0% |
| `tout.php` | Layout | 187 | 35+ pages | none (structural wrapper) | **Active** | 0% |
| `meta.php` | Layout | 16 | tout.php | none (HTML meta tags) | **Active** | 0% |
| `style.php` | Layout | 303 | tout.php | none (CSS) | **Active** | 0% |
| `menus.php` | Layout | 71 | NOT DIRECTLY INCLUDED (referenced via cardspublic.php path) | connectes, membre | **DEAD** | 100% |
| `copyright.php` | Layout | 261 | 30+ pages | none (footer HTML) | **Active** | 0% |
| `atomes.php` | Widget | 12 | basicprivatehtml.php | molecules, autre | **Active** | 0% |
| `ressources.php` | Widget | 11 | cardsprivate.php | ressources, constructions (via functions) | **Active** | 0% |
| `statistiques.php` | Widget | 14 | basicpublichtml.php | membre, connectes | **Active** | 0% |
| `cardsprivate.php` | Widget | 196 | basicprivatehtml.php | autre, molecules, constructions, messages, sujets, statutforum | **Active** | 0% |
| `cardspublic.php` | Widget | 0 | NOT INCLUDED (empty file) | none | **DEAD** | 100% |
| `bbcode.php` | Utility | 365 | forum, sujet, editer, ecriremessage, listesujets, etc. | none (JavaScript BBcode editor) | **Active** | 30% |
| `mots.php` | Utility | 104 | NOT INCLUDED ANYWHERE | none (JavaScript word generator) | **DEAD** | 100% |
| `partenariat.php` | Widget | 10 | NOT DIRECTLY INCLUDED (legacy external scripts) | none (external JS/CSS) | **Partial** | 80% |
| `redirectionVacance.php` | Guard | 10 | constructions, attaque, attaquer, armee, marche | membre (vacation check) | **Active** | 0% |

### Admin Files (admin/)

| File | Type | Auth | LOC | Tables Touched | Status | Dead % |
|------|------|------|-----|----------------|--------|--------|
| `index.php` | Admin Panel | Admin pass | 187 | membre, constructions, statistiques, alliances, autre, news | **Active** | 0% |
| `redirectionmotdepasse.php` | Auth Guard | Admin pass | 7 | none (session check) | **Active** | 0% |
| `tableau.php` | Admin Panel | Admin pass | 792 | membre, constructions, autre, molecules, alliances, ressources | **Active** | 0% |
| `supprimercompte.php` | Admin Action | Admin pass | 52 | all player tables (via supprimerJoueur) | **Active** | 0% |
| `supprimerreponse.php` | Admin Action | Admin pass | 63 | reponses, sujets | **Active** | 0% |
| `listenews.php` | Admin Panel | Admin pass | 98 | news | **Active** | 0% |
| `redigernews.php` | Admin Action | Admin pass | 55 | news | **Active** | 0% |
| `listesujets.php` | Admin Panel | Admin pass | 82 | sujets, reponses | **Active** | 0% |
| `ip.php` | Admin Panel | Admin pass | 24 | membre | **Active** | 0% |

### Moderation Files (moderation/)

| File | Type | Auth | LOC | Tables Touched | Status | Dead % |
|------|------|------|-----|----------------|--------|--------|
| `index.php` | Mod Panel | Admin pass | 270 | sujets, statutforum, autre, reponses, sanctions, membre | **Active** | 0% |
| `mdp.php` | Auth Guard | Admin pass | 7 | none (session check) | **Active** | 0% |
| `ip.php` | Mod Panel | Admin pass | 29 | membre | **Active** | 0% |

### Migration Files (migrations/)

| File | Type | LOC | Status |
|------|------|-----|--------|
| `migrate.php` | CLI Tool | 69 | **Active** (run via CLI) |

---

## Orphaned Files

These files exist but are never included, linked, or reachable from any navigation:

### 1. `annonce.php` (25 LOC) -- DEAD
- **Purpose:** Static announcement page from July 2015 about a game reset vote.
- **Evidence:** Not linked from any menu, navigation, or other PHP file. Contains hardcoded 2015 date text.
- **Recommendation:** DELETE. Historical artifact with no current function.

### 2. `video.php` (39 LOC) -- DEAD
- **Purpose:** Streaming/video player page that loads video links from a `liens` table.
- **Evidence:** Not linked from any menu or page. References `afterglow.min.js` which does not exist in the project. The `liens` table may not exist in the current schema.
- **Recommendation:** DELETE. Dead feature, missing dependencies.

### 3. `includes/update.php` (59 LOC) -- DEAD
- **Purpose:** `updateTargetResources()` function that brings a target player's resources up to date.
- **Evidence:** Not included by any PHP file via grep search. The same functionality exists in `game_resources.php::updateRessources()` and is called directly in `game_actions.php`.
- **Recommendation:** DELETE. Superseded by the main update flow.

### 4. `includes/cardspublic.php` (0 LOC) -- DEAD
- **Purpose:** Empty file, presumably intended to mirror `cardsprivate.php` for public pages.
- **Evidence:** Zero bytes. Not included anywhere.
- **Recommendation:** DELETE. Empty placeholder.

### 5. `includes/mots.php` (104 LOC) -- DEAD
- **Purpose:** JavaScript random word generator (consonant+vowel patterns). Possibly for random name generation.
- **Evidence:** Not included by any PHP file. Pure JavaScript that is never loaded.
- **Recommendation:** DELETE. Unused feature.

### 6. `includes/menus.php` (71 LOC) -- LIKELY DEAD
- **Purpose:** An alternative public-facing statistics/menu panel with ad space, news, stats, and screenshots sections.
- **Evidence:** Not directly included by any PHP file via `include` or `require`. The `basicpublichtml.php` and `basicprivatehtml.php` files handle navigation menus instead. Contains hardcoded Google AdSense code (commented out).
- **Recommendation:** DELETE or investigate if loaded by JavaScript. Almost certainly orphaned.

### 7. `includes/partenariat.php` (10 LOC) -- MOSTLY DEAD
- **Purpose:** Loads jQuery CDN, an external partner JS file, and an external CSS file from theverylittlewar.com.
- **Evidence:** Not directly included by any PHP file. References `http://` URLs (insecure). The jQuery CDN line is useful but already handled elsewhere. The partner bar functionality appears abandoned.
- **Recommendation:** DELETE. jQuery is loaded in `basicprivatehtml.php` already; partner bar is dead.

---

## Duplicate Functionality

### 1. `includes/update.php` vs `includes/game_resources.php::updateRessources()`
- **update.php:** `updateTargetResources()` - reads stored `revenuenergie`/`revenu<atom>` columns from `ressources` table.
- **game_resources.php:** `updateRessources()` - recalculates revenue from building levels each time.
- **Issue:** `updateTargetResources()` uses stale cached revenue values from the DB, while `updateRessources()` computes fresh values. The game_actions.php already calls `updateRessources()` for defenders. `update.php` is completely unused.
- **Action:** Confirm `update.php` is dead code and delete.

### 2. `includes/constantesBase.php` vs `includes/config.php`
- **config.php:** 629-line centralized game config (all constants, arrays, building configs).
- **constantesBase.php:** 55 lines, loads config.php then re-declares display arrays ($nomsRes, $utilite, $lettre, etc.) and medal data.
- **Issue:** constantesBase.php duplicates several arrays that already exist in config.php ($nomsRes, $couleurs, etc.) via aliases. The display arrays ($utilite, $lettre, $aidesAtomes, etc.) and medal palier arrays only exist in constantesBase.php.
- **Action:** Move all remaining unique arrays from `constantesBase.php` into `config.php` and eliminate `constantesBase.php`, updating all references to load `config.php` directly.

### 3. `admin/redirectionmotdepasse.php` vs `moderation/mdp.php`
- Both are 7-line admin password check guards that redirect to `index.php` if not authenticated.
- **Action:** Could be merged into a single shared admin auth guard, but low priority (7 LOC each).

### 4. `includes/bbcode.php` (365 LOC) -- Partial dead code
- Contains a large JavaScript BBcode editor with many functions.
- Many BBcode features (font size, spoiler, code blocks) appear unused by the current UI.
- **Dead estimate:** ~30% of the code is BBcode toolbar JavaScript that may not be rendered in the current Framework7 UI.

---

## Missing References

### Files Referenced But Not Found
1. **`afterglow.min.js`** -- Referenced in `video.php` line 18 but does not exist in the project. (Dead page anyway.)
2. **`images/partenariat/charger_barre.js`** and **`images/partenariat/news.json`** and **`images/partenariat/style_barre.css`** -- Referenced in `partenariat.php` via HTTP URLs. These may or may not exist on the live server, but the entire partenariat feature is dead.
3. **`style.css`** -- Referenced in `validerpacte.php` line 34 and `moderation/ip.php` line 8, but the project uses `css/my-app.css` and Framework7 CSS. This file may or may not exist.

### DB Tables Referenced But Possibly Missing
1. **`liens`** -- Referenced in `video.php` only. Likely does not exist in the current schema.
2. **`sondages`** -- Referenced in `voter.php`. May or may not exist (poll feature).
3. **`attack_cooldowns`** -- Referenced in `combat.php`. Created by migration 0004.
4. **`prestige`** -- Referenced in `prestige.php`. Created by migration 0007.
5. **`migrations`** -- Created by `migrate.php` at runtime.

---

## Config Files with Secrets

| File | Content | Risk |
|------|---------|------|
| `.env` | DB_HOST, DB_USER, DB_PASS, DB_NAME | **HIGH** -- Contains database credentials. Protected by `.htaccess`. |
| `.env.example` | Template with placeholder values | Safe (no real secrets) |
| `includes/constantesBase.php` | ADMIN_PASSWORD_HASH (bcrypt hash) | **MEDIUM** -- Bcrypt hash is safe if strong password, but should move to .env |
| `includes/config.php` | Game constants only | Safe (no secrets) |

### Protection Status
- `.htaccess` in root denies access to `.env`, `.git`, `logs/`, `includes/`
- `includes/.htaccess` denies all direct access
- `logs/.htaccess` denies all direct access
- `docs/.htaccess` denies all direct access

---

## Recommended Merges and Deletions

### Deletions (7 files, ~298 LOC savings)
| File | LOC | Reason |
|------|-----|--------|
| `annonce.php` | 25 | Dead page, 2015 hardcoded content, not linked anywhere |
| `video.php` | 39 | Dead page, missing dependencies, not linked anywhere |
| `includes/update.php` | 59 | Dead code, superseded by game_resources.php::updateRessources() |
| `includes/cardspublic.php` | 0 | Empty file, never included |
| `includes/mots.php` | 104 | Dead JavaScript word generator, never included |
| `includes/menus.php` | 71 | Dead alternative menu panel, never included |
| `includes/partenariat.php` | 10 | Dead partner bar loader, insecure HTTP refs |

### Merges (future refactoring)
| Source | Target | Reason |
|--------|--------|--------|
| `constantesBase.php` unique arrays | `config.php` | Eliminate intermediate config file; move $utilite, $lettre, $aidesAtomes, medal palier arrays, ADMIN_PASSWORD_HASH into config.php |
| `admin/redirectionmotdepasse.php` + `moderation/mdp.php` | shared `includes/admin_auth.php` | Both are identical 7-line admin session guards |
| `constantes.php` | Inline into `basicprivatephp.php` | Only 16 lines: loads constantesBase and calls initPlayer() |

### Code Reduction Opportunities
| File | Current LOC | Potential Savings | Description |
|------|-------------|-------------------|-------------|
| `includes/bbcode.php` | 365 | ~110 | Remove unused BBcode toolbar functions |
| `includes/basicprivatephp.php` | 308 | ~30 | Legacy MD5 session fallback code (lines 23-39) can be removed after migration window |
| `includes/copyright.php` | 261 | ~240 | The last ~240 lines are closing HTML/JS tags from the layout system -- could be cleaner |

---

## Dead Code Estimates

### By File Category

| Category | Total LOC | Estimated Dead LOC | Dead % |
|----------|-----------|-------------------|--------|
| Root pages | 8,255 | 64 (annonce + video) | 0.8% |
| Include modules | 7,045 | 234 (update + cardspublic + mots + menus + partenariat) | 3.3% |
| Admin | 1,358 | 0 | 0% |
| Moderation | 306 | 0 | 0% |
| Migrations | 69 | 0 | 0% |
| **Total** | **16,658** | **298** | **1.8%** |

### Within Active Files (Partial Dead Code)

| File | LOC | Dead Code LOC | Description |
|------|-----|---------------|-------------|
| `basicprivatephp.php` | 308 | ~17 | Legacy MD5 session migration block (lines 23-39) |
| `constantesBase.php` | 55 | ~10 | Duplicate arrays that exist in config.php ($paliersMedailles, $imagesMedailles, etc.) |
| `bbcode.php` | 365 | ~110 | Unused BBcode features (font size controls, spoiler, etc.) |
| `player.php` | 838 | ~40 | Commented-out code blocks, some legacy function signatures |
| `ui_components.php` | 629 | ~30 | `accordion()` function appears minimally used |
| `display.php` | 352 | ~10 | `transformInt()` function has overly deep SI prefix chain |
| **Subtotal** | | **~217** | |

### Grand Total Dead Code
- **Fully dead files:** 298 LOC (7 files)
- **Partial dead code in active files:** ~217 LOC
- **Total dead/unnecessary code:** ~515 LOC out of 16,658 = **3.1%**

---

## Test Files

| File | LOC | Purpose |
|------|-----|---------|
| `tests/bootstrap.php` | ~50 | PHPUnit bootstrap, loads config and mock functions |
| `tests/unit/CombatFormulasTest.php` | ~100 | Combat math verification |
| `tests/unit/ConfigConsistencyTest.php` | ~80 | Config array consistency checks |
| `tests/unit/CsrfTest.php` | ~60 | CSRF token generation/verification |
| `tests/unit/ExploitPreventionTest.php` | ~120 | Input validation exploit tests |
| `tests/unit/GameBalanceTest.php` | ~150 | Game balance formula checks |
| `tests/unit/GameFormulasTest.php` | ~100 | Core formula unit tests |
| `tests/unit/MarketFormulasTest.php` | ~80 | Market pricing formula tests |
| `tests/unit/RateLimiterTest.php` | ~60 | Rate limiter functionality |
| `tests/unit/ResourceFormulasTest.php` | ~80 | Resource production tests |
| `tests/unit/SecurityFunctionsTest.php` | ~90 | Security function tests |
| `tests/unit/ValidationTest.php` | ~60 | Input validation tests |

370 tests / 2325 assertions reported in project memory.

---

## Database Tables Referenced

Complete list of tables touched by the codebase:

| Table | Referenced In | Purpose |
|-------|---------------|---------|
| `membre` | 20+ files | Player accounts (login, password, email, IP, session_token) |
| `autre` | 20+ files | Player stats (points, medals, alliance, resources pillaged, etc.) |
| `constructions` | 15+ files | Building levels and HP |
| `molecules` | 15+ files | Molecule class compositions and counts |
| `ressources` | 12+ files | Player resource storage (energy + 8 atoms) |
| `alliances` | 10+ files | Alliance data (tag, members, research levels) |
| `actionsattaques` | 5 files | Pending attack/espionage actions |
| `actionsformation` | 3 files | Pending molecule formation queues |
| `actionsconstruction` | 3 files | Pending building construction queues |
| `actionsenvoi` | 2 files | Pending resource transfer actions |
| `messages` | 4 files | Player-to-player messages |
| `rapports` | 4 files | Combat/espionage/loss reports |
| `news` | 4 files | Admin news posts |
| `sujets` | 6 files | Forum topics |
| `reponses` | 6 files | Forum replies (also used for polls) |
| `declarations` | 5 files | War/pact declarations between alliances |
| `invitations` | 3 files | Alliance invitations |
| `connectes` | 3 files | Currently online IPs |
| `statistiques` | 4 files | Global game stats (season start, inscrits, catalyst) |
| `parties` | 2 files | Archived season results |
| `grade` | 1 file | Player grades/ranks |
| `sanctions` | 2 files | Moderation sanctions |
| `moderation` | 2 files | Moderation queue |
| `statutforum` | 3 files | Forum read status per player |
| `vacances` | 2 files | Vacation mode data |
| `prestige` | 2 files | Cross-season prestige points and unlocks |
| `attack_cooldowns` | 1 file | Attack cooldown tracking |
| `sondages` | 1 file | Polls (voter.php) |
| `migrations` | 1 file | Applied migration tracking |
| `liens` | 1 file (dead) | Video links (video.php -- dead page) |

**Total: 30 tables referenced** (29 live + 1 dead)

---

## Key Findings Summary

1. **The codebase is remarkably clean** for a 15-year-old PHP game. Only 1.8% of code is fully dead (7 files), and only 3.1% including partial dead code. The refactoring effort has been thorough.

2. **Seven files should be deleted** immediately: `annonce.php`, `video.php`, `includes/update.php`, `includes/cardspublic.php`, `includes/mots.php`, `includes/menus.php`, `includes/partenariat.php`. Combined savings: 298 LOC and reduced attack surface.

3. **The include chain is well-structured** but has one unnecessary layer: `constantesBase.php` could be merged into `config.php`, and `constantes.php` could be inlined into `basicprivatephp.php`, eliminating two files from the include chain.

4. **Security posture is strong**: .env file for credentials, .htaccess protection, CSRF on all forms, prepared statements everywhere, session token auth, rate limiting. The only secret in code is `ADMIN_PASSWORD_HASH` in constantesBase.php (a bcrypt hash, which is safe but could be moved to .env for consistency).

5. **No missing critical files**: All `include`/`require` references resolve to existing files. The only missing references are in dead files (video.php's afterglow.min.js) or legacy references (style.css).

6. **The `comptetest.php` file is a visitor account creation and conversion page**, not a "test" file. Despite its name, it is actively used for the "Visitor" quick-play feature. Do not delete.

7. **Legacy session migration code** in `basicprivatephp.php` (lines 23-39) handles MD5-to-bcrypt password migration. This can be removed after all active sessions have been migrated (safe to remove after 30+ days of uptime).
