# Mega Audit #3 & Remediation Plan — The Very Little War

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Execute a comprehensive 3-round multi-domain audit of the entire TVLW codebase (96 PHP files, 16,500+ LOC, 31 DB tables, JS/CSS/images) using 51 specialized agents across 17 domains, then produce and execute a prioritized remediation plan from all findings.

**Architecture:** 3 sequential rounds of parallel agent dispatches. Each round covers ALL 17 domains with different agent specializations to maximize coverage. Round 1 = primary scan. Round 2 = deep dive on findings. Round 3 = cross-domain correlation. A final synthesis produces the remediation tasks.

**Tech Stack:** PHP 8.2, MariaDB 10.11, Apache 2, Framework7 v1 Material CSS, jQuery 3.1.1, vanilla JS

---

## Codebase Inventory (for agent context)

### Page Files (35 pages)
**Public pages (13):** index.php, classement.php, connectes.php, credits.php, guerre.php, historique.php, joueur.php, listesujets.php, regles.php, sinstruire.php, sujet.php, version.php, forum.php
**Auth-required pages (19):** alliance.php, allianceadmin.php, annonce.php, armee.php, atomes.php, attaque.php, attaquer.php, compte.php, comptetest.php, constructions.php, don.php, ecriremessage.php, editer.php, marche.php, medailles.php, messageCommun.php, messages.php, messagesenvoyes.php, molecule.php, rapports.php, tutoriel.php, vacance.php, validerpacte.php, voter.php, inscription.php, video.php, maintenance.php
**Special:** api.php, deconnexion.php, moderationForum.php
**Admin (9):** admin/index.php, admin/ip.php, admin/listenews.php, admin/listesujets.php, admin/redigernews.php, admin/redirectionmotdepasse.php, admin/supprimercompte.php, admin/supprimerreponse.php, admin/tableau.php
**Moderation (3):** moderation/index.php, moderation/ip.php, moderation/mdp.php

### Include Files (32 includes)
**Core:** basicprivatephp.php, basicpublicphp.php, connexion.php, fonctions.php, session_init.php, constantesBase.php, constantes.php, config.php, env.php
**Security:** csrf.php, validation.php, logger.php, rate_limiter.php, database.php
**Game Logic:** combat.php, formulas.php, game_actions.php, game_resources.php, player.php, prestige.php, catalyst.php, update.php, atomes.php, ressources.php
**UI/Template:** tout.php, basicprivatehtml.php, basicpublichtml.php, cardsprivate.php, cardspublic.php, ui_components.php, display.php, style.php, menus.php, meta.php, copyright.php, bbcode.php, mots.php, statistiques.php, redirectionVacance.php, partenariat.php

### Frontend Assets
**CSS:** css/my-app.css (57 lines), css/framework7.material.css (vendor), lightbox.css
**JS:** js/notification.js (76 lines), js/loader.js (152 lines), js/lightbox.js (351 lines), js/googleCharts.js (empty), jQuery 3.1.1, Framework7.js
**Legacy JS:** js/sha.js, js/sha1.js, js/aes.js, js/aes-json-format.js, js/jquery.jcryption.3.1.0.js, js/PushNotification.js, js/jquery-1.7.2.min.js

### Database (31 tables)
membre, autre, ressources, constructions, molecules, alliances, declarations, grades, invitations, actionsattaques, actionsconstruction, actionsenvoi, actionsformation, attack_cooldowns, connectes, cours, forums, sujets, reponses, messages, rapports, news, parties, prestige, sanctions, statistiques, statutforum, tutoriel, vacances, moderation, migrations

---

## Audit Domain Definitions (17 domains)

| # | Domain | Code | Scope | Agent Type |
|---|--------|------|-------|------------|
| 1 | Security & Auth | SEC | Session, CSRF, auth flows, admin/mod access, XSS, SQLi | security-auditor |
| 2 | Database & SQL | DB | Queries, transactions, races, indexes, schema | database-reviewer |
| 3 | Input Validation | INP | All POST/GET inputs, type coercion, boundary checks | code-reviewer |
| 4 | Game Logic & Rules | GAME | Combat, resources, buildings, formulas, exploits | code-reviewer |
| 5 | Game Balance | BAL | Math models, economy, pacing, fairness, pay-to-win risks | code-reviewer |
| 6 | Performance & Scale | PERF | N+1 queries, caching, page load, DB bottlenecks | performance-engineer |
| 7 | Error Handling | ERR | Null derefs, missing guards, error paths, edge cases | code-reviewer |
| 8 | Code Quality | CODE | Dead code, duplication, naming, structure, PHP best practices | code-reviewer |
| 9 | UI/UX Design | UX | Layout, navigation, mobile responsiveness, accessibility | code-reviewer |
| 10 | Frontend Code | FE | JS quality, CSS issues, asset loading, inline scripts | code-reviewer |
| 11 | Internationalization | I18N | Hardcoded strings, encoding, charset, date formats | code-reviewer |
| 12 | Infrastructure & Config | INFRA | Apache, PHP settings, .htaccess, CSP, headers, deployment | security-auditor |
| 13 | Data Integrity | DATA | Orphan records, cascading deletes, FK consistency, migrations | database-reviewer |
| 14 | Admin & Moderation | ADMIN | Admin panel security, moderation tools, privilege escalation | security-auditor |
| 15 | Alliance & Social | SOC | Alliance mechanics, messaging, pacts, wars, invitations | code-reviewer |
| 16 | Quality of Life | QOL | Missing features, user convenience, onboarding, tooltips | code-reviewer |
| 17 | Innovation & Ideas | IDEA | New feature proposals, modernization opportunities, engagement | code-reviewer |

---

## Task 1: Round 1 — Primary Audit Scan (17 agents in parallel)

**Goal:** First pass across all 17 domains. Each agent reads ALL files in its scope and produces findings.

**Step 1: Launch all 17 Round-1 agents in parallel**

Each agent receives: (a) the full file list, (b) its domain scope, (c) explicit instructions to read every file and produce structured findings.

Launch these 17 agents simultaneously using the Agent tool:

**Agent 1.1 — SEC (Security & Auth) — Round 1**
- Type: `voltagent-qa-sec:security-auditor`
- Prompt:
```
You are auditing a PHP 8.2 browser game "The Very Little War" for security vulnerabilities.

READ EVERY FILE listed below and produce findings in this format:
[SEC-R1-NNN] [CRITICAL|HIGH|MEDIUM|LOW] file.php:line — Description

Focus on: session management, CSRF protection, authentication flows, authorization checks, XSS, SQL injection, path traversal, insecure direct object references, privilege escalation, password handling, cookie security.

Files to audit (read ALL):
- includes/session_init.php, includes/basicprivatephp.php, includes/basicpublicphp.php
- includes/csrf.php, includes/validation.php, includes/rate_limiter.php, includes/database.php
- includes/connexion.php, includes/constantesBase.php, includes/env.php
- index.php, comptetest.php, inscription.php, deconnexion.php, api.php
- compte.php, voter.php, moderationForum.php
- admin/index.php, admin/ip.php, admin/tableau.php, admin/supprimercompte.php
- admin/redigernews.php, admin/redirectionmotdepasse.php, admin/supprimerreponse.php, admin/listenews.php, admin/listesujets.php
- moderation/index.php, moderation/ip.php, moderation/mdp.php
- .htaccess

Check: Are all POST actions CSRF-protected? Are all admin pages behind proper auth? Are all user inputs sanitized before DB or display? Is session fixation prevented? Are timing attacks possible?
```

**Agent 1.2 — DB (Database & SQL) — Round 1**
- Type: `voltagent-qa-sec:code-reviewer`
- Prompt:
```
You are auditing a PHP 8.2 + MariaDB 10.11 game for database issues.

READ EVERY FILE and produce findings as: [DB-R1-NNN] [CRITICAL|HIGH|MEDIUM|LOW] file.php:line — Description

Focus on: SQL injection (even with prepared statements, check dynamic column/table names), missing transactions for multi-step operations, race conditions (TOCTOU), missing SELECT FOR UPDATE where needed, N+1 query patterns, missing indexes, schema issues, orphaned data risks.

Files to audit (read ALL):
- includes/database.php, includes/connexion.php
- includes/player.php, includes/game_actions.php, includes/game_resources.php
- includes/combat.php, includes/update.php, includes/formulas.php
- includes/prestige.php, includes/catalyst.php
- marche.php, don.php, armee.php, constructions.php, attaquer.php, attaque.php
- allianceadmin.php, alliance.php, validerpacte.php, guerre.php
- comptetest.php, compte.php, voter.php
- ecriremessage.php, messages.php, messagesenvoyes.php, messageCommun.php
- classement.php, joueur.php, historique.php
- moderationForum.php, forum.php, sujet.php, editer.php, listesujets.php
- admin/tableau.php, admin/supprimercompte.php

Also check all 14 migration files in migrations/ for correctness.
DB tables: membre, autre, ressources, constructions, molecules, alliances, declarations, grades, invitations, actionsattaques, actionsconstruction, actionsenvoi, actionsformation, attack_cooldowns, connectes, cours, forums, sujets, reponses, messages, rapports, news, parties, prestige, sanctions, statistiques, statutforum, tutoriel, vacances, moderation, migrations
```

**Agent 1.3 — INP (Input Validation) — Round 1**
- Type: `voltagent-qa-sec:code-reviewer`
- Prompt:
```
You are auditing a PHP 8.2 game for input validation vulnerabilities.

READ EVERY FILE and produce findings as: [INP-R1-NNN] [CRITICAL|HIGH|MEDIUM|LOW] file.php:line — Description

Focus on: Missing intval/trim on GET/POST params, insufficient regex validation, type juggling issues, boundary checks (negative numbers, zero, overflow), missing empty() checks, missing isset() checks, URL parameter tampering, file upload issues, email validation, BBcode injection.

Files to audit (read ALL — every page that handles user input):
- index.php (login), comptetest.php (registration), inscription.php, compte.php (account settings)
- marche.php (buy/sell), don.php (donations), armee.php (army formation)
- constructions.php (building), attaquer.php (attacks), attaque.php
- ecriremessage.php, forum.php, sujet.php, editer.php (forum posts)
- allianceadmin.php (alliance management), alliance.php, validerpacte.php
- voter.php, moderationForum.php, messageCommun.php
- vacance.php, molecule.php, sinstruire.php, tutoriel.php
- classement.php (search), joueur.php, messages.php, rapports.php
- admin/*.php (all 9 admin files)
- includes/validation.php, includes/bbcode.php
```

**Agent 1.4 — GAME (Game Logic & Rules) — Round 1**
- Type: `voltagent-qa-sec:code-reviewer`
- Prompt:
```
You are auditing a chemistry-themed strategy game for game logic bugs and exploits.

READ EVERY FILE and produce findings as: [GAME-R1-NNN] [CRITICAL|HIGH|MEDIUM|LOW] file.php:line — Description

Focus on: Combat formula correctness, resource production/consumption bugs, building upgrade edge cases, army formation exploits, molecule composition exploits, prestige system abuses, market manipulation, alliance research stacking, catalyst effects, vacation mode bypasses, season reset correctness, beginner protection bypasses, decay formula errors, trade point exploits, isotope/formation/reaction bugs.

Files to audit (read ALL):
- includes/combat.php (full combat resolution)
- includes/formulas.php (all game formulas: attack, defense, pillage, HP, revenue)
- includes/config.php (all game constants)
- includes/game_actions.php (updateActions, updateRessources, constructions, attacks)
- includes/game_resources.php (resource calculations, production)
- includes/player.php (player creation, deletion, reset, alliance management)
- includes/prestige.php (prestige points, unlocks, combat bonuses)
- includes/catalyst.php (catalyst effects)
- includes/update.php (target player resource update with decay)
- includes/constantes.php, includes/constantesBase.php
- armee.php, attaquer.php, attaque.php, constructions.php
- marche.php, don.php, molecule.php, medailles.php
- allianceadmin.php, alliance.php, validerpacte.php, guerre.php
- vacance.php, sinstruire.php, tutoriel.php
- includes/basicprivatephp.php (season reset logic at bottom)
```

**Agent 1.5 — BAL (Game Balance) — Round 1**
- Type: `voltagent-qa-sec:code-reviewer`
- Prompt:
```
You are a game balance analyst auditing a chemistry-themed strategy game.

READ EVERY FORMULA AND CONSTANT and produce findings as: [BAL-R1-NNN] [CRITICAL|HIGH|MEDIUM|LOW] file.php:line — Description

The game has: 8 atom types (C,N,H,O,Cl,S,Br,I), 4 molecule classes, buildings (generateur, champdeforce, producteur, depot, coffrefort, ionisateur), alliances with research, prestige system, isotopes (Stable/Reactif/Catalytique), formations (Dispersee/Phalange/Embuscade), chemical reactions, market economy, medals.

Focus on: Dominant strategies, useless features, broken economy, snowball effects, catch-up mechanics (or lack thereof), atom balance (are some atoms worthless?), formation viability, isotope balance, reaction conditions achievability, prestige power curve, market price stability, defense vs offense balance, alliance research impact, medal accessibility, building ROI, beginner experience.

Files to audit (read ALL):
- includes/config.php (ALL constants — this is critical)
- includes/formulas.php (attack, defense, pillage, HP, destruction, revenue formulas)
- includes/combat.php (damage calc, proportional distribution, formations, reactions)
- includes/prestige.php (prestige point awards, unlock costs, bonuses)
- includes/catalyst.php (catalyst buffs)
- includes/game_resources.php (resource production, energy generation)
- includes/game_actions.php (construction times, formation times, attack speeds)
- marche.php (market pricing, volatility, mean reversion, sell tax)
- armee.php (molecule formation costs/times)
- constructions.php (building costs/times)
- sinstruire.php (training courses)
- medailles.php (medal system)
```

**Agent 1.6 — PERF (Performance & Scale) — Round 1**
- Type: `voltagent-qa-sec:code-reviewer`
- Prompt:
```
You are a performance engineer auditing a PHP 8.2 + MariaDB game.

READ EVERY FILE and produce findings as: [PERF-R1-NNN] [CRITICAL|HIGH|MEDIUM|LOW] file.php:line — Description

Focus on: N+1 query patterns (queries inside loops), full table scans, missing indexes, expensive operations on every page load, unbounded queries (no LIMIT), excessive includes/requires, heavy computation in request path, missing caching opportunities, large result sets, redundant queries (same data fetched multiple times), unnecessary DB writes, slow ORDER BY without index.

Files to audit (read ALL):
- includes/basicprivatephp.php (runs on EVERY authenticated page)
- includes/game_actions.php, includes/game_resources.php
- includes/update.php, includes/constantes.php
- includes/formulas.php, includes/combat.php
- includes/player.php (inscrire, supprimerJoueur, supprimerAlliance)
- classement.php (rankings with pagination)
- attaquer.php (map + espionage)
- marche.php (price chart with 1000 rows)
- forum.php, sujet.php, listesujets.php
- messages.php, rapports.php, messagesenvoyes.php
- historique.php, guerre.php
- alliance.php, allianceadmin.php
- admin/tableau.php
- All 14 migration files in migrations/
```

**Agent 1.7 — ERR (Error Handling) — Round 1**
- Type: `voltagent-qa-sec:code-reviewer`
- Prompt:
```
You are auditing a PHP 8.2 game for error handling and edge case issues.

READ EVERY FILE and produce findings as: [ERR-R1-NNN] [CRITICAL|HIGH|MEDIUM|LOW] file.php:line — Description

Focus on: Null dereferences (accessing array keys on null/false results), division by zero, undefined variable access, missing return value checks (especially from DB queries), uncaught exceptions, missing error messages for users, error_log vs silent failures, PHP warnings/notices that could leak info, empty catch blocks, missing default cases in switches, off-by-one errors, array index out of bounds.

Files to audit (read ALL — especially the large complex files):
- includes/basicprivatephp.php (season reset: 300+ lines of complex logic)
- includes/combat.php (633 lines of combat math)
- includes/player.php (838 lines of player management)
- includes/game_actions.php (543 lines of action processing)
- admin/tableau.php (792 lines of admin dashboard)
- classement.php (636 lines of ranking)
- marche.php (614 lines of market)
- allianceadmin.php (539 lines of alliance admin)
- attaquer.php (530 lines of attack page)
- armee.php (445 lines of army)
- includes/ui_components.php (629 lines of UI)
- constructions.php (361 lines)
- includes/display.php (352 lines)
- includes/bbcode.php (365 lines)
- All remaining .php files
```

**Agent 1.8 — CODE (Code Quality) — Round 1**
- Type: `voltagent-qa-sec:code-reviewer`
- Prompt:
```
You are auditing a PHP 8.2 codebase for code quality and maintainability.

READ EVERY FILE and produce findings as: [CODE-R1-NNN] [CRITICAL|HIGH|MEDIUM|LOW] file.php:line — Description

Focus on: Dead code (unreachable paths, unused variables/functions), code duplication (copy-paste blocks), variable variables ($$var), global state abuse, magic numbers not in config.php, inconsistent naming (French/English mix), overly complex functions (>50 lines), tight coupling, missing type hints, PHP 8.2 deprecations, legacy patterns (mysql_ functions remnants), unnecessary includes, circular dependencies, code that should be in a function.

Files to audit (read ALL):
- Every file in includes/ (32 files)
- Every page file (35 pages)
- Every admin/ file (9 files)
- Every moderation/ file (3 files)
Focus especially on the 10 largest files (player.php, tableau.php, classement.php, combat.php, ui_components.php, config.php, marche.php, game_actions.php, allianceadmin.php, attaquer.php).
```

**Agent 1.9 — UX (UI/UX Design) — Round 1**
- Type: `voltagent-qa-sec:code-reviewer`
- Prompt:
```
You are a UX auditor analyzing a mobile-first PHP game built with Framework7 v1 Material design.

READ EVERY TEMPLATE/UI FILE and produce findings as: [UX-R1-NNN] [CRITICAL|HIGH|MEDIUM|LOW] file.php:line — Description

Focus on: Navigation flow (how many clicks to key actions?), information hierarchy, visual feedback for actions, loading states, error message clarity, form usability, table readability on mobile, tooltip/help adequacy, onboarding experience, color accessibility, button sizes (touch targets), empty states, pagination UX, confirmation dialogs, dead-end pages, inconsistent layouts.

Files to audit (read ALL UI-related):
- includes/basicprivatehtml.php (main layout template for auth pages)
- includes/basicpublichtml.php (public page layout)
- includes/cardsprivate.php, includes/cardspublic.php (navigation cards)
- includes/menus.php (navigation menus)
- includes/ui_components.php (form elements, buttons, lists)
- includes/display.php (number formatting, time display)
- includes/style.php (custom CSS generation)
- includes/copyright.php (footer)
- includes/meta.php (page head/meta)
- includes/tout.php (bridge include)
- css/my-app.css (custom styles)
- Every page file to check its UX (all 35 page files)
```

**Agent 1.10 — FE (Frontend Code) — Round 1**
- Type: `voltagent-qa-sec:code-reviewer`
- Prompt:
```
You are auditing the frontend code (JavaScript, CSS, HTML) of a PHP game.

READ EVERY FILE and produce findings as: [FE-R1-NNN] [CRITICAL|HIGH|MEDIUM|LOW] file:line — Description

Focus on: Inline script security (eval, innerHTML), outdated jQuery patterns, unused JS files, dead CSS, Framework7 API misuse, accessibility issues (alt text, aria labels, semantic HTML), broken links, duplicate library loads, mixed content (HTTP in HTTPS context), console.log left in production, JavaScript errors, responsive design breakpoints, font loading, image optimization, CSP violations from inline scripts.

Files to audit (read ALL):
- js/notification.js, js/loader.js, js/lightbox.js, js/googleCharts.js
- js/sha.js, js/sha1.js, js/aes.js, js/aes-json-format.js, js/jquery.jcryption.3.1.0.js
- js/PushNotification.js
- css/my-app.css, lightbox.css
- .htaccess (CSP header)
- includes/basicprivatehtml.php, includes/basicpublichtml.php (script/CSS includes)
- includes/style.php (inline CSS)
- Every page that has inline <script> blocks (marche.php, attaquer.php, armee.php, classement.php, sujet.php, etc.)
- convertisseur.html, "mise en forme.html"
```

**Agent 1.11 — I18N (Internationalization) — Round 1**
- Type: `voltagent-qa-sec:code-reviewer`
- Prompt:
```
You are auditing a French-language PHP game for internationalization and encoding issues.

READ EVERY FILE and produce findings as: [I18N-R1-NNN] [CRITICAL|HIGH|MEDIUM|LOW] file.php:line — Description

Focus on: Character encoding (UTF-8 consistency), hardcoded French strings that should be constants, date formatting inconsistencies (d/m/Y vs Y-m-d), number formatting (French uses spaces as thousands sep), email encoding issues, HTML charset declarations, database charset/collation, mb_string vs string functions for UTF-8, special characters in error messages, accented characters in variable names, mixed encoding in includes.

Files to audit (read ALL):
- Every PHP file (check charset headers, string handling)
- includes/meta.php, includes/basicprivatehtml.php, includes/basicpublichtml.php (charset declarations)
- includes/display.php (number_format usage)
- includes/mots.php (word lists/filters)
- includes/bbcode.php (text processing)
- deconnexion.php (was fixed for charset conflict)
- includes/basicprivatephp.php (email sending section with encoding)
- comptetest.php (registration with French text)
```

**Agent 1.12 — INFRA (Infrastructure & Config) — Round 1**
- Type: `voltagent-qa-sec:security-auditor`
- Prompt:
```
You are auditing the infrastructure and server configuration of a PHP game hosted on Debian 12 + Apache 2 + PHP 8.2 + MariaDB 10.11.

READ EVERY CONFIG FILE and produce findings as: [INFRA-R1-NNN] [CRITICAL|HIGH|MEDIUM|LOW] file:line — Description

Focus on: .htaccess security rules, CSP header completeness, missing security headers (HSTS, Permissions-Policy), PHP configuration (display_errors, expose_php, session settings), database connection security, file permissions, sensitive file exposure, error log configuration, SSL/TLS readiness, backup strategy, PHP version compatibility, Apache module requirements, rate limiting at server level, resource limits.

Files to audit (read ALL):
- .htaccess
- includes/session_init.php
- includes/connexion.php, includes/env.php
- includes/constantesBase.php
- includes/config.php
- includes/logger.php
- includes/rate_limiter.php
- All migration files in migrations/
```

**Agent 1.13 — DATA (Data Integrity) — Round 1**
- Type: `voltagent-qa-sec:code-reviewer`
- Prompt:
```
You are auditing database data integrity for a PHP game with 31 tables.

READ EVERY FILE that modifies data and produce findings as: [DATA-R1-NNN] [CRITICAL|HIGH|MEDIUM|LOW] file.php:line — Description

Focus on: Missing foreign key constraints, orphaned records when players/alliances are deleted, cascading delete completeness, data consistency across related tables (membre↔autre↔ressources↔constructions↔molecules), migration safety (can they be re-run?), default values, NOT NULL constraints, unique constraints, season reset data preservation, timestamp consistency.

Tables to trace relationships for: membre, autre, ressources, constructions, molecules (these 5 must stay in sync per player). Also: alliances↔grades↔declarations↔invitations.

Files to audit (read ALL data-modifying files):
- includes/player.php (inscrire, supprimerJoueur, supprimerAlliance, remiseAZero)
- includes/basicprivatephp.php (season reset logic)
- comptetest.php (visitor account creation + rename)
- admin/supprimercompte.php
- allianceadmin.php (alliance create/delete/kick)
- All migration files in migrations/
```

**Agent 1.14 — ADMIN (Admin & Moderation) — Round 1**
- Type: `voltagent-qa-sec:security-auditor`
- Prompt:
```
You are auditing the admin and moderation panels of a PHP game for security and functionality.

READ EVERY ADMIN/MOD FILE and produce findings as: [ADMIN-R1-NNN] [CRITICAL|HIGH|MEDIUM|LOW] file.php:line — Description

Focus on: Authentication bypass, privilege escalation, CSRF on destructive actions, authorization checks (is moderator != admin properly enforced?), SQL injection in admin queries, XSS in admin display, missing audit logging, dangerous operations without confirmation, bulk operations safety, admin-only features accessible to moderators or vice versa.

Files to audit (read ALL):
- admin/index.php (admin login)
- admin/tableau.php (admin dashboard — 792 lines, largest admin file)
- admin/ip.php (IP management)
- admin/listenews.php, admin/redigernews.php (news management)
- admin/listesujets.php, admin/supprimerreponse.php (forum moderation)
- admin/supprimercompte.php (account deletion — dangerous!)
- admin/redirectionmotdepasse.php (password reset — critical!)
- moderation/index.php (moderator login)
- moderation/ip.php (mod IP check)
- moderation/mdp.php (mod password)
- moderationForum.php (forum ban management)
```

**Agent 1.15 — SOC (Alliance & Social) — Round 1**
- Type: `voltagent-qa-sec:code-reviewer`
- Prompt:
```
You are auditing the social/alliance features of a multiplayer strategy game.

READ EVERY FILE and produce findings as: [SOC-R1-NNN] [CRITICAL|HIGH|MEDIUM|LOW] file.php:line — Description

Focus on: Alliance creation/deletion edge cases, invitation system exploits, war declaration validation, pact system integrity, messaging security (XSS, spam), resource donation exploits, alliance research stacking bugs, grade/permission enforcement, alliance announcements, member kick authorization, cross-alliance information leakage.

Files to audit (read ALL):
- alliance.php (alliance view)
- allianceadmin.php (alliance management — 539 lines)
- validerpacte.php (pact acceptance)
- guerre.php (war details)
- don.php (alliance energy donation)
- ecriremessage.php (private messages)
- messages.php, messagesenvoyes.php (message inbox/outbox)
- messageCommun.php (alliance-wide messages)
- annonce.php (alliance announcements)
- includes/player.php (alliance join/leave/kick functions)
- forum.php, sujet.php, editer.php, listesujets.php (forum system)
```

**Agent 1.16 — QOL (Quality of Life) — Round 1**
- Type: `voltagent-qa-sec:code-reviewer`
- Prompt:
```
You are a game designer analyzing a 15-year-old chemistry strategy game for quality-of-life improvements.

READ EVERY PAGE and produce suggestions as: [QOL-R1-NNN] [HIGH|MEDIUM|LOW] file.php — Description

Think like a new player. Focus on: Missing feedback/confirmations, confusing interfaces, information that should be more visible, missing search/filter features, missing bulk operations, tedious multi-step processes that could be simplified, missing keyboard shortcuts, missing auto-refresh for timers, notifications system gaps, missing sorting options, missing quick-links, missing resource calculators, mobile-specific pain points, tutorial gaps.

Files to audit (read ALL pages — focus on user journeys):
Journey 1 - New player: index.php → comptetest.php → tutoriel.php → constructions.php → armee.php
Journey 2 - Combat: attaquer.php → attaque.php → rapports.php → joueur.php
Journey 3 - Economy: marche.php → constructions.php → ressources display
Journey 4 - Social: alliance.php → allianceadmin.php → ecriremessage.php → forum.php
Journey 5 - Progression: medailles.php → sinstruire.php → classement.php → prestige
Journey 6 - Account: compte.php → vacance.php → deconnexion.php
```

**Agent 1.17 — IDEA (Innovation & Ideas) — Round 1**
- Type: `voltagent-qa-sec:code-reviewer`
- Prompt:
```
You are a game design consultant proposing innovative features for a 15-year-old chemistry strategy game that's being modernized.

READ THE KEY FILES to understand the game, then produce proposals as: [IDEA-R1-NNN] [HIGH|MEDIUM|LOW] — Title: Description

The game: Chemistry-themed (8 atoms, 4 molecule classes), alliances, market trading, combat, prestige system, isotopes, formations, chemical reactions, tutorial missions, monthly seasons.

Focus on: Engagement features (daily login rewards, achievements, leaderboard seasons), social features (chat, alliance events), modernization (WebSocket real-time updates, push notifications, PWA), new game mechanics (diplomacy, events, tournaments, resource trading between alliances), visual improvements (combat animations, resource graphs, interactive map), retention (comeback rewards, progressive complexity), monetization ideas (cosmetic only — NO pay-to-win).

Files to read for context:
- includes/config.php (all game constants — understand the full scope)
- includes/combat.php (combat system)
- includes/prestige.php (progression system)
- includes/catalyst.php (buff system)
- tutoriel.php (onboarding)
- marche.php (economy)
- sinstruire.php (research/training)
- medailles.php (achievement system)
```

**Step 2: Collect all Round 1 results**
Wait for all 17 agents to complete. Save each agent's output to `docs/audit3/round1/[DOMAIN].md`.

**Step 3: Commit Round 1 results**
```bash
mkdir -p docs/audit3/round1
# Save each agent output to its file
git add docs/audit3/round1/
git commit -m "audit: round 1 — 17-domain primary scan results"
```

---

## Task 2: Round 2 — Deep Dive on Findings (17 agents in parallel)

**Goal:** Second pass with different agent specializations. Each agent receives Round 1 findings for cross-reference and goes deeper into the highest-risk areas.

**Step 1: Launch all 17 Round-2 agents in parallel**

Each Round-2 agent receives: (a) the Round 1 findings from its domain AND adjacent domains, (b) instructions to validate findings, find missed issues, and go deeper.

**Agent 2.1 — SEC Round 2** (`comprehensive-review:security-auditor`)
- Receives: SEC-R1 + INP-R1 + ADMIN-R1 findings
- Deep dive: Verify each R1 finding, test edge cases, check for chained exploits

**Agent 2.2 — DB Round 2** (`everything-claude-code:database-reviewer`)
- Receives: DB-R1 + DATA-R1 + PERF-R1 findings
- Deep dive: Trace every query path, check all transaction boundaries, verify all indexes

**Agent 2.3 — INP Round 2** (`comprehensive-review:code-reviewer`)
- Receives: INP-R1 + SEC-R1 findings
- Deep dive: Test every input with edge cases (empty, null, negative, huge, unicode, HTML)

**Agent 2.4 — GAME Round 2** (`comprehensive-review:code-reviewer`)
- Receives: GAME-R1 + BAL-R1 findings
- Deep dive: Trace every combat path, every resource flow, every exploit vector

**Agent 2.5 — BAL Round 2** (`comprehensive-review:code-reviewer`)
- Receives: BAL-R1 + GAME-R1 findings
- Deep dive: Mathematical analysis of formulas, economy simulation, dominant strategy analysis

**Agent 2.6 — PERF Round 2** (`everything-claude-code:database-reviewer`)
- Receives: PERF-R1 + DB-R1 findings
- Deep dive: Count exact query count per page load, identify the worst offenders

**Agent 2.7 — ERR Round 2** (`comprehensive-review:code-reviewer`)
- Receives: ERR-R1 + CODE-R1 findings
- Deep dive: Trace every null path, every error branch, every edge case

**Agent 2.8 — CODE Round 2** (`comprehensive-review:code-reviewer`)
- Receives: CODE-R1 + ERR-R1 findings
- Deep dive: Dead code analysis, duplication measurement, refactoring opportunities

**Agent 2.9 — UX Round 2** (`comprehensive-review:architect-review`)
- Receives: UX-R1 + FE-R1 + QOL-R1 findings
- Deep dive: User journey mapping, accessibility audit, mobile usability

**Agent 2.10 — FE Round 2** (`comprehensive-review:code-reviewer`)
- Receives: FE-R1 + UX-R1 findings
- Deep dive: JS code review, CSS audit, asset optimization, library audit

**Agent 2.11 — I18N Round 2** (`comprehensive-review:code-reviewer`)
- Receives: I18N-R1 findings
- Deep dive: Every string literal, every date format, every encoding path

**Agent 2.12 — INFRA Round 2** (`comprehensive-review:security-auditor`)
- Receives: INFRA-R1 + SEC-R1 findings
- Deep dive: Apache hardening, PHP config audit, deployment pipeline review

**Agent 2.13 — DATA Round 2** (`everything-claude-code:database-reviewer`)
- Receives: DATA-R1 + DB-R1 findings
- Deep dive: Full entity relationship audit, cascading delete trace, migration safety

**Agent 2.14 — ADMIN Round 2** (`comprehensive-review:security-auditor`)
- Receives: ADMIN-R1 + SEC-R1 findings
- Deep dive: Every admin action tested for auth, CSRF, authorization

**Agent 2.15 — SOC Round 2** (`comprehensive-review:code-reviewer`)
- Receives: SOC-R1 + GAME-R1 findings
- Deep dive: Alliance exploit chains, social engineering vectors, messaging abuse

**Agent 2.16 — QOL Round 2** (`comprehensive-review:architect-review`)
- Receives: QOL-R1 + UX-R1 + IDEA-R1 findings
- Deep dive: Prioritized QOL improvements with implementation estimates

**Agent 2.17 — IDEA Round 2** (`comprehensive-review:architect-review`)
- Receives: IDEA-R1 + QOL-R1 + BAL-R1 findings
- Deep dive: Feasibility analysis, implementation complexity, impact vs effort matrix

**Step 2: Collect all Round 2 results**
Save to `docs/audit3/round2/[DOMAIN].md`.

**Step 3: Commit Round 2 results**
```bash
mkdir -p docs/audit3/round2
git add docs/audit3/round2/
git commit -m "audit: round 2 — 17-domain deep dive results"
```

---

## Task 3: Round 3 — Cross-Domain Correlation (17 agents in parallel)

**Goal:** Third pass focused on cross-domain patterns. Each agent now has R1+R2 findings from ALL domains and looks for patterns that only emerge when combining insights.

**Step 1: Launch all 17 Round-3 agents in parallel**

**Agent 3.1 — SEC Round 3** (`voltagent-qa-sec:penetration-tester`)
- Receives: ALL R1+R2 findings across all domains
- Task: Construct exploit chains combining findings from multiple domains

**Agent 3.2 — DB Round 3** (`voltagent-data-ai:database-optimizer`)
- Receives: ALL DB+PERF+DATA findings from R1+R2
- Task: Produce a comprehensive schema optimization and index strategy

**Agent 3.3 — INP Round 3** (`voltagent-qa-sec:code-reviewer`)
- Receives: ALL INP+SEC+GAME findings
- Task: Map every user input to its final use (display, DB, calculation) — full taint analysis

**Agent 3.4 — GAME Round 3** (`voltagent-qa-sec:code-reviewer`)
- Receives: ALL GAME+BAL+SOC findings
- Task: Produce a complete game mechanic integrity report

**Agent 3.5 — BAL Round 3** (`voltagent-qa-sec:code-reviewer`)
- Receives: ALL BAL+GAME findings
- Task: Produce a complete balance tuning recommendation with specific config.php changes

**Agent 3.6 — PERF Round 3** (`voltagent-qa-sec:performance-engineer`)
- Receives: ALL PERF+DB findings
- Task: Produce a complete performance optimization plan with benchmarks

**Agent 3.7 — ERR Round 3** (`voltagent-qa-sec:code-reviewer`)
- Receives: ALL ERR+CODE findings
- Task: Classify every error path and produce an error handling standardization plan

**Agent 3.8 — CODE Round 3** (`voltagent-qa-sec:architect-reviewer`)
- Receives: ALL CODE+ERR findings
- Task: Produce a refactoring roadmap with dependency graph

**Agent 3.9 — UX Round 3** (`comprehensive-review:architect-review`)
- Receives: ALL UX+FE+QOL+I18N findings
- Task: Produce a complete UX redesign proposal

**Agent 3.10 — FE Round 3** (`comprehensive-review:code-reviewer`)
- Receives: ALL FE+UX findings
- Task: Produce a frontend modernization plan

**Agent 3.11 — I18N Round 3** (`comprehensive-review:code-reviewer`)
- Receives: ALL I18N findings
- Task: Produce an i18n readiness report and extraction plan

**Agent 3.12 — INFRA Round 3** (`comprehensive-review:security-auditor`)
- Receives: ALL INFRA+SEC findings
- Task: Produce a production hardening checklist

**Agent 3.13 — DATA Round 3** (`comprehensive-review:code-reviewer`)
- Receives: ALL DATA+DB findings
- Task: Produce a data integrity enforcement plan (constraints, triggers, FK)

**Agent 3.14 — ADMIN Round 3** (`comprehensive-review:security-auditor`)
- Receives: ALL ADMIN+SEC findings
- Task: Produce an admin panel security hardening plan

**Agent 3.15 — SOC Round 3** (`comprehensive-review:code-reviewer`)
- Receives: ALL SOC+GAME+SEC findings
- Task: Produce a social feature improvement plan

**Agent 3.16 — QOL Round 3** (`comprehensive-review:architect-review`)
- Receives: ALL QOL+UX+IDEA findings
- Task: Produce a prioritized QOL implementation roadmap

**Agent 3.17 — IDEA Round 3** (`comprehensive-review:architect-review`)
- Receives: ALL IDEA+QOL+BAL findings
- Task: Produce a feature proposal document with effort estimates

**Step 2: Collect all Round 3 results**
Save to `docs/audit3/round3/[DOMAIN].md`.

**Step 3: Commit Round 3 results**
```bash
mkdir -p docs/audit3/round3
git add docs/audit3/round3/
git commit -m "audit: round 3 — 17-domain cross-correlation results"
```

---

## Task 4: Synthesis — Mega Findings Tracker

**Goal:** Merge all 51 agent outputs into a single prioritized findings tracker.

**Step 1: Read all Round 1, 2, and 3 outputs**

Read every file in docs/audit3/round1/, round2/, round3/.

**Step 2: Deduplicate and merge findings**

Produce `docs/audit3/mega-findings-tracker.md` with:
- All findings deduplicated (same issue found by multiple agents = higher confidence)
- Severity classification: CRITICAL > HIGH > MEDIUM > LOW > SUGGESTION
- Each finding tagged with which rounds/agents found it
- Grouped by domain
- Implementation effort estimate (S/M/L/XL)

**Step 3: Produce statistics**
- Total findings per domain
- Total findings per severity
- Cross-domain patterns identified
- Top 10 highest-impact fixes

**Step 4: Commit synthesis**
```bash
git add docs/audit3/mega-findings-tracker.md
git commit -m "audit: mega findings tracker — synthesized from 51 agent reports"
```

---

## Task 5: Write the Mega Remediation Plan

**Goal:** Transform the mega findings tracker into an executable remediation plan.

**Step 1: Read the mega findings tracker**

**Step 2: Group findings into implementation tasks**

Organize by:
1. **Critical Security** — Must fix immediately (exploit chains, auth bypasses)
2. **High Bugs** — Game-breaking issues (combat bugs, economy exploits)
3. **Database** — Schema, indexes, integrity fixes
4. **Performance** — Query optimization, caching
5. **UX/Frontend** — Interface improvements, mobile fixes
6. **Game Balance** — Formula tuning, config changes
7. **Code Quality** — Refactoring, dead code, duplication
8. **Infrastructure** — Server hardening, deployment
9. **Quality of Life** — Player convenience features
10. **Innovation** — New feature proposals (future roadmap)

**Step 3: Write the remediation plan**

Save to `docs/plans/2026-03-02-mega-remediation.md` with full task breakdown following the writing-plans skill format (bite-sized steps, exact file paths, test commands, commit messages).

**Step 4: Commit the remediation plan**
```bash
git add docs/plans/2026-03-02-mega-remediation.md
git commit -m "plan: mega remediation plan from audit #3 findings"
```

---

## Execution Notes

- **Parallelism:** Within each round, all 17 agents launch simultaneously
- **Sequential rounds:** Round 2 starts only after Round 1 completes (needs R1 findings as input)
- **Agent budget:** 51 agents total (17 × 3 rounds)
- **Expected output:** ~200-500 findings across all domains
- **Remediation plan:** Will be a separate executable plan document
- **Files created:** 51 audit reports + 1 tracker + 1 remediation plan

## Agent Type Mapping

| Round | SEC | DB | INP | GAME | BAL | PERF | ERR | CODE | UX | FE | I18N | INFRA | DATA | ADMIN | SOC | QOL | IDEA |
|-------|-----|-----|-----|------|-----|------|-----|------|-----|-----|------|-------|------|-------|-----|-----|------|
| R1 | security-auditor | code-reviewer | code-reviewer | code-reviewer | code-reviewer | code-reviewer | code-reviewer | code-reviewer | code-reviewer | code-reviewer | code-reviewer | security-auditor | code-reviewer | security-auditor | code-reviewer | code-reviewer | code-reviewer |
| R2 | security-auditor | database-reviewer | code-reviewer | code-reviewer | code-reviewer | database-reviewer | code-reviewer | code-reviewer | architect-review | code-reviewer | code-reviewer | security-auditor | database-reviewer | security-auditor | code-reviewer | architect-review | architect-review |
| R3 | penetration-tester | database-optimizer | code-reviewer | code-reviewer | code-reviewer | performance-engineer | code-reviewer | architect-reviewer | architect-review | code-reviewer | code-reviewer | security-auditor | code-reviewer | security-auditor | code-reviewer | architect-review | architect-review |
