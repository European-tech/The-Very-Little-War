# TVLW Pass 10 — Master Traceability Matrix

> Generated: 2026-03-08 | Sources: 4 parallel exploration agents + 4 review agents
> Coverage: 243 files · 37 DB tables · 120+ user actions · 8 domains · 6 security gaps

---

## TABLE 1 — FILE INVENTORY WITH DOMAIN & AUTH

### Root PHP Pages (47 files)

| File | Domain | Purpose | Auth | CSRF | Notes |
|------|--------|---------|------|------|-------|
| index.php | INFRA | Homepage (public landing + login form) | none | — | Public |
| inscription.php | AUTH | Player registration | public | ✓ | Rate limit 3/hour/IP |
| deconnexion.php | AUTH | Logout + session cleanup | player | ✓ | Token wipe |
| compte.php | AUTH | Account settings (pw, email, avatar, vacation, delete) | player | ✓ | 7-day delete cooldown |
| api.php | INFRA | JSON API: molecule stat previews | player | — (GET-only) | Rate limit 60/60s/IP |
| health.php | INFRA | Health-check endpoint | none | — | 200 if DB OK |
| joueur.php | SOCIAL | Public player profile | none | — | Alliance-filtered |
| attaquer.php | COMBAT | Attack launcher + map + espionage | player | ✓ ×2 | Energy lock, beginner guard |
| attaque.php | COMBAT | Attack report detail viewer | player | — | Read-only |
| bilan.php | GAME_CORE | Comprehensive bonus summary (all formulas) | player | — | Read-only |
| classement.php | SOCIAL | Rankings (points/attack/defense/pillage) | none | — | Read-only |
| comptetest.php | AUTH | Test account debug tool | player | — | Dev only |
| connectes.php | SOCIAL | Online players list | none | — | Read-only |
| constructions.php | ECONOMY | Building management + upgrades | player | ✓ ×3 | FOR UPDATE production pts |
| credits.php | INFRA | Game credits | none | — | Static |
| don.php | ECONOMY | Alliance treasury donation | player | ✓ | Rate limit 10/hour |
| ecriremessage.php | SOCIAL | Compose private/alliance/broadcast message | player | ✓ | Rate limit 3 alliance/5min |
| editer.php | FORUM | Edit/delete/hide forum post | player | ✓ | Author+moderator gate |
| forum.php | FORUM | Forum category listing | public | — | Alliance-private filtering |
| guerre.php | GAME_CORE | War declaration details | public | ✓ (POST) | Alliance chef only |
| historique.php | GAME_CORE | Action/event history viewer | player | — | Read-only |
| laboratoire.php | ECONOMY | Compound synthesis lab | player | ✓ | Rate limit 5/min |
| listesujets.php | FORUM | Forum topic list + new topic form | public | ✓ (POST) | Rate limit 10/300s |
| maintenance.php | ADMIN | Admin maintenance mode toggle | admin | ✓ | Separate auth flow |
| marche.php | ECONOMY | Market trading interface | player | ✓ | Rate limit 10/60s, multi-acc block |
| medailles.php | GAME_CORE | Medal display and milestones | player | — | Read-only |
| messageCommun.php | SOCIAL | Alliance shared message board | player | — | Read-only |
| messages.php | SOCIAL | Private message inbox + delete | player | ✓ (POST) | Soft-delete |
| messagesenvoyes.php | SOCIAL | Sent messages folder | player | — | Read-only |
| moderationForum.php | FORUM | Forum moderation: bans + sanction list | moderator | ✓ | Moderator gate first |
| molecule.php | GAME_CORE | Molecule/unit stat viewer | none | — | Public reference |
| prestige.php | GAME_CORE | Prestige points + shop | player | ✓ | Double-season guard |
| rapports.php | COMBAT | Combat/espionage reports viewer | player | — | Read-only |
| regles.php | INFRA | Game rules documentation | none | — | Static |
| season_recap.php | GAME_CORE | Past season archived stats | player | — | Read-only |
| sinstruire.php | GAME_CORE | Chemistry specialization tutorial | player | — | Read-only |
| sujet.php | FORUM | Forum topic thread + reply form | public | ✓ (POST) | Rate limit 10/300s |
| tutoriel.php | GAME_CORE | Tutorial missions + reward claims | player | ✓ | DB re-verify inside tx |
| vacance.php | GAME_CORE | Vacation mode status | player | — | Read-only |
| validerpacte.php | GAME_CORE | Pact/war acceptance | player | ✓ | Grade bits, FOR UPDATE |
| version.php | INFRA | Game version changelog | none | — | Static |
| voter.php | SOCIAL | Poll voting | player | ✓ | Session token + CSRF, INSERT IGNORE |
| alliance.php | SOCIAL | Alliance home + diplomacy | player | ✓ | Mixed public/private |
| alliance_discovery.php | SOCIAL | Browse all alliances | public | — | Read-only |
| allianceadmin.php | SOCIAL | Alliance leader admin | player | ✓ | Chef/grade permission bits |
| armee.php | COMBAT | Army composition + molecule management | player | ✓ ×2 | FOR UPDATE neutrino |

### Admin Pages (10 files)

| File | Domain | Purpose | Auth | CSRF |
|------|--------|---------|------|------|
| admin/index.php | ADMIN | Admin dashboard + season reset | admin | ✓ |
| admin/ip.php | ADMIN | IP lookup (hashed) | admin | — |
| admin/listenews.php | ADMIN | News management | admin | ✓ |
| admin/listesujets.php | ADMIN | Forum topic moderation | admin | ✓ |
| admin/multiaccount.php | ADMIN | Multi-account detection dashboard | admin | ✓ |
| admin/redigernews.php | ADMIN | Create/edit news posts | admin | ✓ |
| admin/redirectionmotdepasse.php | ADMIN | Auth gate (password-based, WEAK) | admin | — |
| admin/supprimercompte.php | ADMIN | Account deletion tool | admin | ✓ ⚠ weak gate |
| admin/supprimerreponse.php | ADMIN | Delete poll response | admin | ✓ |
| admin/tableau.php | ADMIN | Resource grants + moderation tools | admin | ✓ |

### Moderation Pages (3 files)

| File | Domain | Purpose | Auth |
|------|--------|---------|------|
| moderation/index.php | FORUM | Forum moderation interface | moderator |
| moderation/ip.php | FORUM | IP lookup for moderation | moderator |
| moderation/mdp.php | AUTH | Password reset tool | moderator |

### Includes / Library Modules (41 files)

| File | Domain | Purpose |
|------|--------|---------|
| includes/session_init.php | INFRA | Session init + security |
| includes/connexion.php | INFRA | DB connection (mysqli) |
| includes/database.php | INFRA | Prepared statement helpers |
| includes/constantesBase.php | INFRA | Constant loader → config.php |
| includes/config.php | INFRA | All game constants + balance |
| includes/env.php | INFRA | .env loader |
| includes/logger.php | INFRA | ERROR/WARN/INFO logging |
| includes/csrf.php | INFRA | CSRF token gen + verify |
| includes/rate_limiter.php | INFRA | Rate limiting (file-based) |
| includes/validation.php | INFRA | Input validation helpers |
| includes/csp.php | INFRA | CSP nonce generation |
| includes/fonctions.php | INFRA | Compat shim → 8 modules |
| includes/formulas.php | GAME_CORE | Combat/production/market formulas |
| includes/game_resources.php | ECONOMY | Resource production + updateRessources |
| includes/game_actions.php | GAME_CORE | Action queue processing engine |
| includes/player.php | AUTH | Player init + inscrire() |
| includes/ui_components.php | INFRA | UI render helpers |
| includes/display.php | INFRA | Display formatting |
| includes/db_helpers.php | INFRA | DB wrapper + column whitelists |
| includes/prestige.php | GAME_CORE | Prestige points system |
| includes/catalyst.php | GAME_CORE | Weekly catalyst earning |
| includes/multiaccount.php | ADMIN | Multi-account detection |
| includes/resource_nodes.php | ECONOMY | Resource node proximity bonus |
| includes/compounds.php | ECONOMY | Compound synthesis (5 compounds) |
| includes/combat.php | COMBAT | Combat resolution engine |
| includes/bbcode.php | FORUM | BBCode parser |
| includes/layout.php | INFRA | Page layout template |
| includes/meta.php | INFRA | SEO meta tags + Open Graph |
| includes/copyright.php | INFRA | Footer copyright |
| includes/style.php | INFRA | Dynamic CSS generation |
| includes/atomes.php | GAME_CORE | Atom/molecule type definitions |
| includes/ressources.php | ECONOMY | Resource type definitions |
| includes/statistiques.php | GAME_CORE | Statistics calculation helpers |
| includes/redirectionVacance.php | GAME_CORE | Vacation mode redirect |
| includes/basicpublicphp.php | AUTH | Public page auth (login flow) |
| includes/basicprivatehtml.php | AUTH | Private page HTML structure |
| includes/basicprivatephp.php | AUTH | Private page session guard |
| includes/basicpublichtml.php | AUTH | Public page HTML structure |
| includes/cardsprivate.php | GAME_CORE | Player dashboard cards |

### JS / CSS / Config (22 files)

| File | Domain | Purpose |
|------|--------|---------|
| js/countdown.js | GAME_CORE | Season countdown timer |
| js/framework7.min.js | INFRA | Framework7 UI library |
| css/my-app.css | INFRA | Custom app styles |
| css/framework7.* (13 files) | INFRA | Framework7 theme files |
| .htaccess | INFRA | Apache security rules |
| .env.example | INFRA | Environment template |
| composer.json / .lock | INFRA | PHP dependencies |

### Database Migrations (80 files: 0001–0080)

All in `migrations/` directory. See part1-output.md for full list.

### Tests (38 files)

19 unit + 8 integration + 3 functional + 6 balance + 2 bootstrap
See part1-output.md for complete list.

---

## TABLE 2 — USER FLOW PATHS

### ANONYMOUS (unauthenticated) — 3 flows

| # | Flow | Entry Point | Method | DB Tables | Output |
|---|------|-------------|--------|-----------|--------|
| A1 | Homepage view | index.php | GET | statistiques | HTML |
| A2 | Player registration | inscription.php | POST | membre, autre, ressources, constructions | Redirect→index |
| A3 | Login | index.php (basicpublicphp) | POST | membre, session_token | Redirect→index |

### PLAYER — 65 flows

#### Account (7)
| # | Flow | Entry Point | Method | DB Tables |
|---|------|-------------|--------|-----------|
| P1 | View profile | compte.php | GET | membre, autre |
| P2 | Change password | compte.php | POST | membre |
| P3 | Change email | compte.php | POST | membre |
| P4 | Edit description | compte.php | POST | membre |
| P5 | Upload avatar | compte.php | POST | membre |
| P6 | Enable vacation mode | compte.php | POST | vacances, actionsattaques |
| P7 | Delete account | compte.php | POST | membre, autre, ressources, constructions, molecules + 5 more |

#### Combat (5)
| # | Flow | Entry Point | Method | DB Tables |
|---|------|-------------|--------|-----------|
| P8 | View map | attaquer.php?type=0 | GET | membre, resource_nodes |
| P9 | Launch attack | attaquer.php | POST | actionsattaques, ressources, molecules, autre |
| P10 | Launch espionage | attaquer.php | POST | actionsattaques, ressources |
| P11 | View attack reports | rapports.php | GET | rapports |
| P12 | View attack detail | attaque.php | GET | rapports |

#### Buildings (4)
| # | Flow | Entry Point | Method | DB Tables |
|---|------|-------------|--------|-----------|
| P13 | View buildings | constructions.php | GET | constructions, actionsconstruction |
| P14 | Upgrade building | constructions.php | POST | constructions, ressources, actionsconstruction |
| P15 | Allocate producteur pts | constructions.php | POST | constructions, ressources |
| P16 | Allocate condenseur pts | constructions.php | POST | constructions, ressources |

#### Army (5)
| # | Flow | Entry Point | Method | DB Tables |
|---|------|-------------|--------|-----------|
| P17 | View army | armee.php | GET | molecules, actionsformation |
| P18 | Form molecules | armee.php | POST | molecules, ressources, actionsformation |
| P19 | Delete molecule class | armee.php | POST | molecules, actionsattaques |
| P20 | Buy neutrinos | armee.php | POST | ressources (FOR UPDATE) |
| P21 | Change formation | armee.php | POST | constructions |

#### Market (4)
| # | Flow | Entry Point | Method | DB Tables |
|---|------|-------------|--------|-----------|
| P22 | View market | marche.php | GET | cours, ressources |
| P23 | Buy resources | marche.php | POST | ressources, cours |
| P24 | Sell resources | marche.php | POST | ressources, cours |
| P25 | Transfer to player | marche.php | POST | ressources, actionsenvoi, account_flags |

#### Alliance (7)
| # | Flow | Entry Point | Method | DB Tables |
|---|------|-------------|--------|-----------|
| P26 | Browse alliances | alliance_discovery.php | GET | alliances, grades |
| P27 | View own alliance | alliance.php | GET | alliances, grades, declarations |
| P28 | Create alliance | alliance.php | POST | alliances, grades, autre |
| P29 | Leave alliance | alliance.php | POST | grades, autre, alliances |
| P30 | Upgrade duplicateur | alliance.php | POST | alliances, ressources |
| P31 | Donate energy | don.php | POST | ressources, alliances, autre |
| P32 | Declare pact/war | alliance.php / guerre.php | POST | declarations, alliances |

#### Alliance Admin (5)
| # | Flow | Entry Point | Method | DB Tables |
|---|------|-------------|--------|-----------|
| P33 | Invite member | allianceadmin.php | POST | invitations, grades |
| P34 | Remove member | allianceadmin.php | POST | grades, autre, alliances |
| P35 | Assign grade | allianceadmin.php | POST | grades |
| P36 | Edit alliance name/desc | allianceadmin.php | POST | alliances |
| P37 | Delete alliance | allianceadmin.php | POST | alliances, grades, autre |

#### Pacts & Wars (2)
| # | Flow | Entry Point | Method | DB Tables |
|---|------|-------------|--------|-----------|
| P38 | Accept pact | validerpacte.php | POST | declarations, grades (FOR UPDATE) |
| P39 | Accept war | validerpacte.php | POST | declarations, grades (FOR UPDATE) |

#### Forum (9)
| # | Flow | Entry Point | Method | DB Tables |
|---|------|-------------|--------|-----------|
| P40 | View forums | forum.php | GET | forums |
| P41 | View topic list | listesujets.php | GET | sujets, statutforum |
| P42 | Create topic | listesujets.php | POST | sujets, statutforum, sanctions |
| P43 | View topic | sujet.php | GET | reponses, sujets, statutforum |
| P44 | Reply to topic | sujet.php | POST | reponses, autre, sanctions |
| P45 | Edit topic | editer.php?type=1 | POST | sujets, statutforum |
| P46 | Edit reply | editer.php?type=2 | POST | reponses, statutforum |
| P47 | Delete reply | editer.php?type=3 | POST | reponses, autre |
| P48 | Vote on poll | sujet.php (inline) | POST | sondages, reponses_sondage |

#### Messaging (5)
| # | Flow | Entry Point | Method | DB Tables |
|---|------|-------------|--------|-----------|
| P49 | View inbox | messages.php | GET | messages |
| P50 | Read message | messages.php?message=X | GET | messages |
| P51 | Send private msg | ecriremessage.php | POST | messages |
| P52 | Send alliance broadcast | ecriremessage.php | POST | messages, grades |
| P53 | Delete message | messages.php | POST | messages (soft-delete) |

#### Prestige & Shop (3)
| # | Flow | Entry Point | Method | DB Tables |
|---|------|-------------|--------|-----------|
| P54 | View prestige page | prestige.php | GET | prestige, autre |
| P55 | Buy prestige unlock | prestige.php | POST | prestige |
| P56 | View bonus summary | bilan.php | GET | prestige, autre, constructions |

#### Compounds Lab (3)
| # | Flow | Entry Point | Method | DB Tables |
|---|------|-------------|--------|-----------|
| P57 | View lab | laboratoire.php | GET | player_compounds |
| P58 | Synthesize compound | laboratoire.php | POST | player_compounds, ressources |
| P59 | Activate compound | laboratoire.php | POST | player_compounds |

#### Tutorial & Progression (2)
| # | Flow | Entry Point | Method | DB Tables |
|---|------|-------------|--------|-----------|
| P60 | View tutorial | tutoriel.php | GET | autre |
| P61 | Claim mission reward | tutoriel.php | POST | autre, ressources |

#### Voting (1)
| # | Flow | Entry Point | Method | DB Tables |
|---|------|-------------|--------|-----------|
| P62 | Vote on poll | voter.php | POST | sondages, reponses_sondage |

#### Read-Only Views (8)
| # | Flow | Entry Point | DB Tables |
|---|------|-------------|-----------|
| P63 | View player profile | joueur.php | membre, autre, alliances |
| P64 | View rankings | classement.php | autre, alliances |
| P65 | View season recap | season_recap.php | season_recap |
| P66 | View online players | connectes.php | connectes |
| P67 | View history | historique.php | actionsattaques, actionsformation |
| P68 | View medals | medailles.php | autre |
| P69 | View molecule stats | molecule.php | — (static) |
| P70 | View rules | regles.php | — (static) |

### MODERATOR — 6 flows

| # | Flow | Entry Point | Method | DB Tables |
|---|------|-------------|--------|-----------|
| M1 | View moderation panel | moderationForum.php | GET | sanctions |
| M2 | Ban player | moderationForum.php | POST | sanctions |
| M3 | Remove ban | moderationForum.php | POST | sanctions |
| M4 | Hide forum reply | editer.php?type=5 | POST | reponses |
| M5 | Show forum reply | editer.php?type=4 | POST | reponses |
| M6 | Delete forum reply | editer.php?type=3 | POST | reponses, autre |

### ADMIN — 10 flows

| # | Flow | Entry Point | Method | DB Tables |
|---|------|-------------|--------|-----------|
| Ad1 | Admin login | admin/index.php | POST | membre |
| Ad2 | View dashboard | admin/index.php | GET | statistiques, membre |
| Ad3 | Enable maintenance | admin/index.php | POST | statistiques |
| Ad4 | Season reset | admin/index.php | POST | ALL tables (performSeasonEnd) |
| Ad5 | Delete accounts by IP | admin/index.php | POST | membre + cascades |
| Ad6 | View multiaccount dashboard | admin/multiaccount.php | GET | account_flags, login_history |
| Ad7 | Mark alert read | admin/multiaccount.php | POST | account_flags |
| Ad8 | Update flag status | admin/multiaccount.php | POST | account_flags |
| Ad9 | IP lookup | admin/ip.php | GET | login_history |
| Ad10 | Delete account | admin/supprimercompte.php | POST | membre + cascades |

### API Endpoints — 5 flows

| # | Endpoint | Auth | Rate Limit |
|---|----------|------|-----------|
| Ap1 | api.php?id=attaque | player | 60/60s/IP |
| Ap2 | api.php?id=defense | player | 60/60s/IP |
| Ap3 | api.php?id=vitesse | player | 60/60s/IP |
| Ap4 | api.php?id=demiVie | player | 60/60s/IP |
| Ap5 | api.php?id=tempsFormation | player | 60/60s/IP |

---

## TABLE 3 — DATABASE TABLES & DATA FLOWS

### 37 Tables by Domain

| Table | Domain | Sensitivity | Key Writers | Key Readers |
|-------|--------|-------------|-------------|-------------|
| membre | AUTH | HIGH | player.php (inscrire), basicpublicphp | basicprivatephp, all private pages |
| autre | GAME_CORE | HIGH | game_actions.php, game_resources.php, alliance.php | classement.php, bilan.php |
| ressources | ECONOMY | HIGH | game_resources.php (updateRessources), marche.php, don.php | constructions.php, armee.php |
| constructions | ECONOMY | HIGH | game_actions.php (augmenterBatiment), combat.php | combat.php, bilan.php |
| molecules | COMBAT | HIGH | game_actions.php (formation), attaquer.php, combat.php | armee.php, attaquer.php |
| prestige | GAME_CORE | MEDIUM | game_actions.php, includes/prestige.php | prestige.php, bilan.php |
| actionsattaques | COMBAT | HIGH | attaquer.php, game_actions.php (combat resolution) | rapports.php, historique.php |
| actionsconstruction | ECONOMY | MEDIUM | constructions.php, game_actions.php | constructions.php |
| actionsformation | COMBAT | MEDIUM | armee.php, game_actions.php | armee.php |
| actionsenvoi | ECONOMY | MEDIUM | marche.php | game_actions.php |
| rapports | COMBAT | MEDIUM | game_actions.php (doCombat → 2 reports) | rapports.php |
| alliances | SOCIAL | MEDIUM | allianceadmin.php, don.php, alliance.php | alliance.php, classement.php |
| grades | SOCIAL | MEDIUM | allianceadmin.php, validerpacte.php | allianceadmin.php, validerpacte.php |
| declarations | GAME_CORE | MEDIUM | allianceadmin.php, validerpacte.php | guerre.php, declarations.php |
| sujets | FORUM | LOW | listesujets.php, editer.php | sujet.php, listesujets.php |
| reponses | FORUM | LOW | sujet.php, editer.php | sujet.php, editer.php |
| messages | SOCIAL | MEDIUM | ecriremessage.php | messages.php, messagesenvoyes.php |
| moderation | FORUM | MEDIUM | moderationForum.php, editer.php | moderationForum.php |
| cours | ECONOMY | LOW | marche.php | marche.php |
| statistiques | INFRA | LOW | player.php (inscrire), admin/index.php, catalyst.php | marche.php, index.php |
| connectes | INFRA | LOW | DEPRECATED | connectes.php |
| vacances | GAME_CORE | LOW | compte.php | basicprivatephp.php, redirectionVacance.php |
| sanctions | FORUM | MEDIUM | moderationForum.php | sujet.php, editer.php |
| forums | FORUM | LOW | — (seeded) | forum.php, listesujets.php |
| statutforum | FORUM | LOW | sujet.php, listesujets.php, editer.php | listesujets.php |
| sondages | SOCIAL | LOW | admin (seeded) | voter.php, sujet.php |
| reponses_sondage | SOCIAL | LOW | voter.php | voter.php, admin |
| login_history | ADMIN | HIGH | basicpublicphp.php → multiaccount.php | admin/ip.php, admin/multiaccount.php |
| account_flags | ADMIN | HIGH | multiaccount.php | admin/multiaccount.php |
| resource_nodes | ECONOMY | MEDIUM | resource_nodes.php | attaquer.php, game_resources.php |
| player_compounds | ECONOMY | MEDIUM | laboratoire.php, includes/compounds.php | laboratoire.php, attaquer.php |
| email_queue | INFRA | MEDIUM | player.php (inscrire, season reset) | cron/email sender |
| moderation_log | FORUM | MEDIUM | editer.php (moderator edits) | admin/moderationlog.php |
| invitations | SOCIAL | LOW | allianceadmin.php | allianceadmin.php |
| season_recap | GAME_CORE | LOW | player.php (archiveSeasonData) | season_recap.php |
| prestige_awards | GAME_CORE | MEDIUM | includes/prestige.php | prestige.php |
| alliancemembre | SOCIAL | LOW | alliance.php | alliance.php |

---

## TABLE 4 — DOMAIN SUMMARY

### 8 Domains with File Counts and Gaps

| Domain | Files | Tables | Flows | Critical Gaps |
|--------|-------|--------|-------|---------------|
| **AUTH** | 8 | 1 (membre) | 7 player + 3 anon | Weak gate on admin/supprimercompte.php |
| **FORUM** | 8 | 5 | 9 player + 6 mod | No rate limit on sanctions; moderator ban check timing |
| **COMBAT** | 6 | 4 | 5 player | None found |
| **ECONOMY** | 9 | 6 | 15 player | None found |
| **SOCIAL** | 9 | 7 | 14 player | None found |
| **GAME_CORE** | 10 | 6 | 12 player + 4 admin | None found |
| **ADMIN** | 8 | 3 | 10 admin | Weak auth gate on supprimercompte.php |
| **INFRA-SECURITY** | 8 | — | — | — |
| **INFRA-DATABASE** | 6 + 80 migrations | 2 | — | — |
| **INFRA-TEMPLATES** | 5 | — | 5 API (api.php) | Cookie secure pending HTTPS |

---

## TABLE 5 — GAP ANALYSIS (Security Findings)

| ID | Severity | Domain | File | Gap | Fix |
|----|----------|--------|------|-----|-----|
| P10-HIGH-001 | HIGH | ADMIN | admin/supprimercompte.php | Uses `redirectionmotdepasse.php` weak gate instead of full session validation | Replace with basicprivatephp.php + ADMIN_LOGIN check |
| P10-MED-001 | MEDIUM | FORUM | moderationForum.php | No rate limit on sanction creation (DOS vector) | Add rateLimitCheck('sanction_create', 20, 3600) |
| P10-MED-002 | MEDIUM | ADMIN | admin/supprimercompte.php | csrfCheck() ordering could be improved | Move csrfCheck() to first line inside POST block |
| P10-LOW-001 | LOW | FORUM | editer.php | Banned moderator ban check not preemptive enough | Move ban check before POST['contenu'] handler |
| P10-LOW-002 | LOW | FORUM | sujet.php | Expired ban GC probabilistic (1%/request) | Add nightly cron `DELETE FROM sanctions WHERE dateFin < CURDATE()` |
| P10-LOW-003 | LOW | INFRA | api.php | nbTotalAtomes param unused — dead code | Remove or document param |
| P10-INFO-001 | INFO | INFRA | — | Cookie secure flag pending HTTPS DNS propagation | Point theverylittlewar.com → 212.227.38.111, run certbot |
| P10-INFO-002 | INFO | FORUM | — | No queryable audit log for admin actions (sanctions, deletions, resets) | Consider audit_log table for admin actions |
| P10-INFO-003 | INFO | AUTH | — | Moderator IP binding breaks on VPN switches | Consider session re-auth instead of IP binding |
| P10-INFO-004 | INFO | INFRA | — | No centralized permission matrix | Currently inline per-file; consider dispatcher pattern |

---

## TABLE 5-B — ADDITIONAL GAPS FROM REVIEW AGENTS

| ID | Severity | Domain | File | Gap | Fix |
|----|----------|--------|------|-----|-----|
| P10-HIGH-001 | HIGH | ADMIN | admin/supprimercompte.php | Weak auth gate (redirectionmotdepasse instead of basicprivatephp) | Replace with basicprivatephp.php + ADMIN_LOGIN check |
| P10-MED-001 | MEDIUM | FORUM | moderationForum.php | No rate limit on sanction creation | Add rateLimitCheck('sanction_create', 20, 3600) |
| P10-MED-002 | MEDIUM | ADMIN | admin/supprimercompte.php | csrfCheck() ordering inside POST block | Move csrfCheck() to first line inside POST block |
| P10-MED-003 | MEDIUM | SOCIAL | joueur.php | No rate limit on GET — player enumeration possible | Add rateLimitCheck($ip, 'profile_view', 60, 60) |
| P10-MED-004 | MEDIUM | ADMIN | admin/supprimercompte.php | supprimerJoueur() has no audit log | Add logInfo('ADMIN', 'Player deleted', [...]) inside deletion |
| P10-MED-005 | MEDIUM | ADMIN | admin/tableau.php | References non-existent table `signalement` (BROKEN QUERY) | Remove dead code or create migration for the table |
| P10-MED-006 | MEDIUM | ADMIN | admin/tableau.php | References non-existent table `lieux` (BROKEN QUERY) | Remove dead code or create migration for the table |
| P10-LOW-001 | LOW | FORUM | editer.php | Banned moderator ban check not preemptive | Move ban check before POST['contenu'] handler |
| P10-LOW-002 | LOW | FORUM | sujet.php | Expired ban GC only 1%/request | Add nightly cron `DELETE FROM sanctions WHERE dateFin < CURDATE()` |
| P10-LOW-003 | LOW | INFRA | api.php | nbTotalAtomes param unused — dead code | Remove or document |
| P10-LOW-004 | LOW | ECONOMY | constructions.php | Building queue allows 2x same building (no UNIQUE on actionsconstruction(login,batiment)) | Add validation check in constructions.php before INSERT |
| P10-INFO-001 | INFO | INFRA | — | Cookie secure flag pending HTTPS DNS | Point DNS → 212.227.38.111, run certbot |
| P10-INFO-002 | INFO | ADMIN | — | No queryable audit log table for admin actions | Consider audit_log table |
| P10-INFO-003 | INFO | AUTH | — | Moderator IP binding breaks on VPN | Consider session re-auth |
| P10-INFO-004 | INFO | INFRA | — | No centralized permission matrix | Consider dispatcher pattern |

## TABLE 5-C — HIGH-RISK CROSS-DOMAIN SEAMS (from cross-domain mapping agents)

> These are architectural risks found during TABLE 7 mapping — not bugs, but dangerous coupling points worth tracking.

| ID | Severity | Domain Seam | File | Risk | Recommendation |
|----|----------|------------|------|------|----------------|
| P10-SEAM-001 | MEDIUM | ADMIN → AUTH | admin/index.php:60 | `supprimerJoueur()` called without `withTransaction()` — if mid-deletion fails (e.g., FK on molecules), partial records remain orphaned | Wrap in `withTransaction()` |
| P10-SEAM-002 | MEDIUM | INFRA-SEC → ALL | includes/rate_limiter.php | File-based rate limit GC is probabilistic (1/200 requests) and fails silently on permission error — no DB fallback, potential open-rate after disk fill | Add db-backed fallback or log GC failures |
| P10-SEAM-003 | LOW | GAME_CORE → AUTH | includes/player.php:1174 | Season reset clears ALL session_tokens atomically — player mid-attack POST gets logged out, CSRF token becomes invalid, state may be inconsistent | Document as known; add server maintenance banner pre-reset |
| P10-SEAM-004 | LOW | INFRA-SEC → ALL | includes/basicprivatephp.php | Acts as implicit state mutation hub (updateRessources + updateActions + streak + comeback + season trigger) — all called on every private page load, no circuit breaker | Add error boundary around each hook; log individually |

## TABLE 6 — DB TABLE CORRECTIONS (from Review Agent 3)

| Original Name | Actual Name | Domain | Notes |
|---------------|-------------|--------|-------|
| compounds | **player_compounds** | ECONOMY | Migration 0024 creates player_compounds |
| multiaccount_flags | **account_flags** | ADMIN | Migration 0021 creates account_flags |
| partie | **parties** (plural) | GAME_CORE | Base schema table |
| prestige_unlocks | **unlocks column** in prestige | GAME_CORE | VARCHAR column, not a separate table |
| medals | **non-existent** | GAME_CORE | Medal tracking via autre columns |
| ip_bans | **non-existent** | FORUM | Ban tracking via sanctions table |

### Tables Missing From Original Catalogue

| Table | Migration | Domain | Purpose |
|-------|-----------|--------|---------|
| attack_cooldowns | 0004 | COMBAT | Cooldown tracking after failed attacks |
| moderation_log | 0043 | FORUM | Audit trail for moderator forum edits |
| reponses_sondage | 0032 | SOCIAL | Poll response votes |
| sondages | 0031 | SOCIAL | Poll questions and options |
| resource_nodes | 0023 | ECONOMY | Map resource node locations |
| login_history | 0020 | ADMIN | Login/register event log (fraud detection) |

**Corrected total: 39 tables** (was 37)

### Orphaned Code (Broken Queries)

| File | Line | Table Referenced | Status |
|------|------|-----------------|--------|
| admin/tableau.php | ~640 | `signalement` | **TABLE DOES NOT EXIST** — broken admin query |
| admin/tableau.php | ~353 | `lieux` | **TABLE DOES NOT EXIST** — broken admin query |

---

## SUMMARY

- **Total files catalogued:** 243
- **Total DB tables:** 39 (corrected from 37)
- **Total user flows:** 75+ distinct POST handlers (3 anon + 65+ player + 6 mod + 10 admin + 5 API)
- **Domains:** 8 (INFRA optionally split into 3 sub-domains for deeper audit)
- **Security posture:** STRONG — 0 CRITICAL, 1 HIGH, 6 MEDIUM, 4 LOW, 4 INFO
- **CSRF coverage:** 28/46 pages (rest are read-only, exempt)
- **Auth coverage:** 45/46 pages (1 weak gate = HIGH finding)
- **Prepared statements:** 100%
- **FOR UPDATE locks:** All critical mutations covered
- **Rate limiting:** 9 endpoints covered (gaps: sanction creation, joueur.php enumeration)
- **Broken admin queries:** 2 (admin/tableau.php referencing non-existent `signalement` and `lieux` tables)

### Pass 10 Remediation Priority

| Priority | Count | Items |
|----------|-------|-------|
| **Fix immediately** | 1 | P10-HIGH-001 (admin auth gate) |
| **Fix this pass** | 6 | P10-MED-001 through P10-MED-006 |
| **Fix if time** | 4 | P10-LOW-001 through P10-LOW-004 |
| **Track/defer** | 4 | P10-INFO-001 through P10-INFO-004 |

---

## TABLE 7 — CROSS-DOMAIN INTERACTION MAP

> Source: 3 parallel cross-domain mapping agents (aad6637d = AUTH/FORUM/COMBAT, ae94d786 = ECONOMY/SOCIAL/GAME_CORE, a5366944 = ADMIN/INFRA)

### 7A — Write Dependencies (Domain X writes to Domain Y's tables)

| Source Domain | Target Domain | Function/Table Written | Caller File | Notes |
|---------------|--------------|----------------------|-------------|-------|
| **AUTH** | GAME_CORE | `actionsformation` INSERT | compte.php (vacation) | Vacation mode inserts into GAME_CORE action table |
| **AUTH** | GAME_CORE | `vacances` INSERT | compte.php (vacation) | Vacation block written cross-domain |
| **AUTH** | ADMIN | `login_history` INSERT | basicpublicphp.php | Every login writes to fraud-detection table |
| **AUTH** | ADMIN | `account_flags` INSERT | multiaccount.php | IP/fingerprint collision → ADMIN's anti-cheat table |
| **FORUM** | SOCIAL | `autre.nbMessages` UPDATE | sujet.php (reply) | Reply count stored in SOCIAL's `autre` stats table |
| **COMBAT** | ECONOMY | `ressources` UPDATE | attaquer.php → updateRessources() | Combat outcome modifies ECONOMY resource rows |
| **COMBAT** | GAME_CORE | `actionsconstruction` via updateActions() | attaquer.php | Combat tick advances building queues (GAME_CORE) |
| **COMBAT** | ECONOMY | `molecules` DELETE | armee.php → molecule deletion tx | Army changes cascade to ECONOMY's molecule tracking |
| **ECONOMY** | COMBAT | `ressources` FOR UPDATE | constructions.php | Building upgrade locks combat-critical resource row |
| **ECONOMY** | GAME_CORE | `autre.nbDons` counter | don.php | Donation increments GAME_CORE's medal counter |
| **ECONOMY** | ADMIN | `trade_volume` tracking | marche.php | Market transfers feed ADMIN anti-cheat analysis |
| **SOCIAL** | AUTH | `membre.alliances` UPDATE | alliance.php (join/leave) | Alliance membership written to AUTH's membre table |
| **SOCIAL** | GAME_CORE | `declarations` | alliance.php → validerpacte.php | Pact/war stored in GAME_CORE's declarations table |
| **GAME_CORE** | ALL | 15-table wipe | performSeasonEnd() | Season reset touches every domain's primary tables |
| **GAME_CORE** | ECONOMY | `ressources` + `constructions` zero-fill | performSeasonEnd() → remiseAZero() | Economy fully reset cross-domain |
| **GAME_CORE** | COMBAT | `molecules` + `attaques` DELETE | performSeasonEnd() | Combat state wiped |
| **GAME_CORE** | SOCIAL | `messages` + `alliances` purge | performSeasonEnd() | Social data wiped on season end |
| **ADMIN** | AUTH | `membre` DELETE (8-table cascade) | supprimerJoueur() | Account deletion cascades across every domain |
| **INFRA-SECURITY** | ALL | called on every private page | basicprivatephp.php | Auth hub that also triggers: updateRessources (ECONOMY), updateActions (GAME_CORE), updateLoginStreak (GAME_CORE), checkComebackBonus (GAME_CORE), performSeasonEnd (GAME_CORE), processEmailQueue |

### 7B — Read Dependencies (Domain X reads Domain Y's tables)

| Source Domain | Reads From | Table | Purpose |
|---------------|-----------|-------|---------|
| COMBAT | ECONOMY | `ressources`, `constructions` | Energy, atoms, building levels for attack calculation |
| COMBAT | ECONOMY | `player_compounds` | Active compound buffs modify attack/defense |
| COMBAT | GAME_CORE | `declarations` | War/pact status determines valid targets |
| FORUM | AUTH | `membre` | Moderator flag check, ban target lookup |
| SOCIAL | AUTH | `membre` | Profile lookup, alliance membership |
| ECONOMY | COMBAT | `actionsformation` | Formation completion affects production |
| GAME_CORE | ECONOMY | `constructions` + `ressources` | Bilan.php reads all economy data for bonus calc |
| GAME_CORE | COMBAT | `molecules`, `attaques` | Medal counters read combat history |
| ADMIN | ALL | ALL | Admin dashboard aggregates cross-domain stats |
| INFRA-SECURITY | AUTH | `membre.session_token` | Session token validated against DB on every request |

### 7C — Hub Functions (Cross-cutting, called from many domains)

| Function | Defined In | Called By Domains | Tables Touched |
|---------|-----------|-------------------|----------------|
| `updateRessources()` | includes/game_resources.php | INFRA-SEC (every page), COMBAT, ECONOMY | `ressources`, `constructions` |
| `updateActions()` | includes/game_actions.php | INFRA-SEC (every page), COMBAT | `actionsformation`, `actionsrecherche`, `actionsconstruction`, `attaques` |
| `initPlayer()` | includes/player.php | AUTH, INFRA-SEC | `membre`, `ressources`, `autre` |
| `performSeasonEnd()` | includes/player.php | ADMIN, GAME_CORE | ALL 15 tables |
| `supprimerJoueur()` | includes/player.php | ADMIN, AUTH | 8 tables cascade |
| `csrfCheck()` | includes/csrf.php | ALL domains | `membre.csrf_token` |
| `rateLimitCheck()` | includes/rate_limiter.php | AUTH, FORUM, ECONOMY, SOCIAL, INFRA | `data/rates/` files |
| `logInfo/logError()` | includes/logger.php | ALL domains | `logs/` files |
| `getCompoundBonus()` | includes/compounds.php | COMBAT, ECONOMY | `player_compounds` |
| `getSpecModifier()` | includes/game_resources.php | COMBAT, ECONOMY | `membre.specialisation` |

---

## TABLE 8 — PROPOSED NEW DOMAINS

> Synthesized from 3 cross-domain mapping agents. Each proposal includes rationale, files to migrate, cross-domain interactions, and priority score.

| # | Proposed Domain | Split From | Priority | Rationale | Files to Move |
|---|----------------|-----------|----------|-----------|---------------|
| 1 | **SEASON_RESET** | GAME_CORE + ADMIN | ★★★ HIGH | performSeasonEnd() touches 15 tables across all domains; it's a lifecycle event that deserves its own bounded context. Currently scattered between player.php and admin/index.php | performSeasonEnd(), archiveSeasonData(), remiseAZero() (all in player.php), season_recap.php, migrations/0029 |
| 2 | **ANTI_CHEAT** | ADMIN | ★★★ HIGH | Multi-account detection, IP logging, fingerprinting, and admin flagging are a distinct security subdomain. Currently mixed into ADMIN + AUTH login flow | includes/multiaccount.php, admin/multiaccount.php, login_history writes in basicpublicphp.php, account_flags table |
| 3 | **ALLIANCE_MANAGEMENT** | SOCIAL | ★★ MEDIUM | Alliance governance (chef, grades, members, cooldowns, duplicateur upgrades) is more complex than "social" implies. Currently shares domain with player profiles and messaging | allianceadmin.php, don.php, war/pact initiation in alliance.php, validerpacte.php |
| 4 | **ESPIONAGE** | COMBAT | ★★ MEDIUM | Espionage (attaquer.php type=2) has its own neutrino cost, rate limit, report type, and formula set. Logically distinct from direct combat (type=1) | Espionage branch in attaquer.php, espionage entries in rapports.php, neutrino deduction |
| 5 | **MARKET** | ECONOMY | ★★ MEDIUM | Market trading (buy/sell/transfer) has its own rate limits, price volatility, multi-account checks, and FOR UPDATE patterns. Currently diluted inside ECONOMY with buildings and labs | marche.php, market pricing functions in includes/game_resources.php |
| 6 | **COMPOUNDS** | ECONOMY | ★★ MEDIUM | Compound synthesis (laboratoire.php) and active buffs (player_compounds) are a cross-cutting game mechanic that affects COMBAT, ECONOMY, and GAME_CORE. Separating would clarify the buff lifecycle | laboratoire.php, includes/compounds.php, player_compounds table |
| 7 | **RANKINGS** | SOCIAL / GAME_CORE | ★ LOW | Leaderboard logic reads from multiple domains. Currently in SOCIAL (classement.php) but is more of a cross-domain aggregator | classement.php, sqrt ranking functions |
| 8 | **PRESTIGE** | GAME_CORE | ★ LOW | Prestige point tracking, unlocks, and season rewards are distinct from tutorial/war/medals. Would benefit from isolated testing | prestige.php, includes/prestige.php (if extracted), awardPrestigePoints() in player.php |
| 9 | **MAPS** | COMBAT / ECONOMY | ★ LOW | Resource nodes, map display, coordinate generation are a standalone subsystem | includes/resource_nodes.php, map view in attaquer.php type=0, coordonneesAleatoires() |
| 10 | **NOTIFICATIONS** | INFRA | ★ LOW | Email queue processing (processEmailQueue) and historique.php event log are notification concerns, not core game logic or infrastructure | historique.php, processEmailQueue() in player.php, email templates |

### Current 10-Domain Structure vs Proposed Extended Structure

| Current Domain | Status | Action |
|---------------|--------|--------|
| AUTH | Stable | Keep; absorb ANTI_CHEAT boundary |
| FORUM | Stable | Keep |
| COMBAT | Stable | Consider ESPIONAGE split later |
| ECONOMY | Stable | Consider MARKET + COMPOUNDS splits |
| SOCIAL | Stable | Consider ALLIANCE_MANAGEMENT split |
| GAME_CORE | Overloaded | Extract SEASON_RESET (★★★), PRESTIGE, RANKINGS |
| ADMIN | Overloaded | Extract ANTI_CHEAT (★★★), SEASON_RESET boundary |
| INFRA-SECURITY | Stable | Keep |
| INFRA-DATABASE | Stable | Keep |
| INFRA-TEMPLATES | Stable | Keep |
| **SEASON_RESET** (NEW) | **Proposed ★★★** | Extract immediately — reduces coupling dramatically |
| **ANTI_CHEAT** (NEW) | **Proposed ★★★** | Extract immediately — security domain deserves isolation |
| **ALLIANCE_MANAGEMENT** (NEW) | Proposed ★★ | Extract in next audit pass |
| **ESPIONAGE** (NEW) | Proposed ★★ | Low risk split |
| **MARKET** (NEW) | Proposed ★★ | Natural boundary already present |
| **COMPOUNDS** (NEW) | Proposed ★★ | Buff lifecycle is cross-cutting |
| **RANKINGS** (NEW) | Proposed ★ | Nice-to-have |
| **PRESTIGE** (NEW) | Proposed ★ | Nice-to-have |
| **MAPS** (NEW) | Proposed ★ | Low priority |
| **NOTIFICATIONS** (NEW) | Proposed ★ | Low priority |

**Recommended immediate action:** Adopt SEASON_RESET and ANTI_CHEAT as formal domains in Pass 11 matrix — both are already architecturally isolated, just not labeled. The remaining 8 proposals are valid long-term refactoring goals but not urgent.
