Perfect! Now I have a comprehensive understanding of the project structure. Let me create the detailed file inventory. Since I cannot write to files, I'll compile all the information into a single response with the complete markdown table:

Based on my thorough exploration of the TVLW project, here is the comprehensive file inventory for Pass 10:

## TVLW File Inventory - Pass 10 Part 1

### Root-Level PHP Pages (47 files)

| File | Type | Purpose | HTTP | Auth |
|------|------|---------|------|------|
| index.php | PHP page | Homepage with login/registration for public; dashboard for logged-in players | yes | public |
| inscription.php | PHP page | Player registration with rate limiting and validation | yes | public |
| deconnexion.php | PHP page | Logout handler with session cleanup | yes | player |
| api.php | PHP page | JSON API for molecule stat previews with rate limiting | yes | player |
| health.php | PHP page | Health check endpoint for uptime monitoring | yes | none |
| joueur.php | PHP page | Public player profile viewer (viewable by anyone) | yes | none |
| attaquer.php | PHP page | Espionage attack targeting and execution | yes | player |
| attaque.php | PHP page | Attack preview/confirmation page | yes | player |
| bilan.php | PHP page | Comprehensive bonus summary (all bonuses with formula breakdowns) | yes | player |
| classement.php | PHP page | Global ranking/leaderboard with category filters | yes | none |
| compte.php | PHP page | Player account settings and profile customization | yes | player |
| comptetest.php | PHP page | Testing account page (debug/dev tool) | yes | player |
| connectes.php | PHP page | Online players list | yes | none |
| constructions.php | PHP page | Building management and construction queue | yes | player |
| credits.php | PHP page | Game credits and attribution | yes | none |
| don.php | PHP page | Donation/alliance treasury interface | yes | player |
| ecriremessage.php | PHP page | Compose new private message | yes | player |
| editer.php | PHP page | Edit existing message or forum post | yes | player |
| forum.php | PHP page | Forum landing page with topic list | yes | public |
| guerre.php | PHP page | War declaration details and history | yes | public |
| historique.php | PHP page | Action history/combat logs viewer | yes | player |
| laboratoire.php | PHP page | Compound synthesis laboratory | yes | player |
| listesujets.php | PHP page | Forum topic list with pagination | yes | public |
| maintenance.php | PHP page | Admin-only maintenance page (season reset, etc.) | yes | admin |
| marche.php | PHP page | Market trading interface with price charts | yes | player |
| medailles.php | PHP page | Medal display and milestone tracking | yes | player |
| messageCommun.php | PHP page | Alliance shared/common messages | yes | player |
| messages.php | PHP page | Private message inbox | yes | player |
| messagesenvoyes.php | PHP page | Sent messages folder | yes | player |
| moderationForum.php | PHP page | Forum moderation (posts/bans) - moderator only | yes | moderator |
| molecule.php | PHP page | Molecule/unit details and stats | yes | public |
| prestige.php | PHP page | Prestige points system and progression | yes | player |
| rapports.php | PHP page | Combat/attack reports viewer | yes | player |
| regles.php | PHP page | Game rules and mechanics documentation | yes | none |
| season_recap.php | PHP page | Past season history and archived stats | yes | player |
| sinstruire.php | PHP page | Technology/knowledge research system | yes | player |
| sujet.php | PHP page | Forum topic thread viewer | yes | public |
| tutoriel.php | PHP page | Tutorial missions with rewards | yes | player |
| vacance.php | PHP page | Vacation mode status page | yes | player |
| validerpacte.php | PHP page | Pact/alliance agreement validation | yes | player |
| version.php | PHP page | Game version and update information | yes | none |
| voter.php | PHP page | Poll voting system | yes | none |
| alliance.php | PHP page | Alliance management and diplomacy | yes | player |
| alliance_discovery.php | PHP page | Browse all alliances with stats | yes | public |
| allianceadmin.php | PHP page | Alliance leader administration | yes | player |
| armee.php | PHP page | Army composition and unit management | yes | player |

### Admin Directory (10 files)

| File | Type | Purpose | HTTP | Auth |
|------|------|---------|------|------|
| admin/index.php | PHP page | Admin login and dashboard | yes | admin |
| admin/ip.php | PHP page | IP lookup tool (show accounts on same IP) | yes | admin |
| admin/listenews.php | PHP page | News/announcement management | yes | admin |
| admin/listesujets.php | PHP page | Forum topic moderation | yes | admin |
| admin/multiaccount.php | PHP page | Multi-account detection and flagging dashboard | yes | admin |
| admin/redigernews.php | PHP page | Create/edit news posts | yes | admin |
| admin/redirectionmotdepasse.php | PHP include | Auth check redirect for admin pages | no | admin |
| admin/supprimercompte.php | PHP page | Account deletion tool | yes | admin |
| admin/supprimerreponse.php | PHP page | Delete poll/survey response | yes | admin |
| admin/tableau.php | PHP page | Admin resource grants and moderation tools | yes | admin |
| admin/.htaccess | Config | Apache security rules for admin | no | none |

### Moderation Directory (3 files)

| File | Type | Purpose | HTTP | Auth |
|------|------|---------|------|------|
| moderation/index.php | PHP page | Forum moderation interface | yes | moderator |
| moderation/ip.php | PHP page | IP lookup for moderation | yes | moderator |
| moderation/mdp.php | PHP page | Password reset tool (moderator access) | yes | moderator |

### Includes Directory (41 files)

| File | Type | Purpose | HTTP | Auth |
|------|------|---------|------|------|
| includes/session_init.php | PHP include | Session initialization and security setup | no | none |
| includes/connexion.php | PHP include | Database connection (mysqli) | no | none |
| includes/database.php | PHP include | Prepared statement helper functions (dbQuery, dbFetchOne, etc.) | no | none |
| includes/constantesBase.php | PHP include | Base constants loading (includes config.php) | no | none |
| includes/config.php | PHP include | Centralized game configuration and balance constants | no | none |
| includes/env.php | PHP include | Environment variable loader (.env support) | no | none |
| includes/logger.php | PHP include | Error and event logging system | no | none |
| includes/csrf.php | PHP include | CSRF token generation and verification | no | none |
| includes/rate_limiter.php | PHP include | Rate limiting for login/registration/API | no | none |
| includes/validation.php | PHP include | Input validation helpers (login, email, int, range) | no | none |
| includes/csp.php | PHP include | CSP nonce generation for inline script protection | no | none |
| includes/fonctions.php | PHP include | Backward-compatible shim loading 8 modules | no | none |
| includes/formulas.php | PHP include | Game formulas (combat, production, market math) | no | none |
| includes/game_resources.php | PHP include | Resource production and updateRessources function | no | none |
| includes/game_actions.php | PHP include | Action processing and queue handling | no | none |
| includes/player.php | PHP include | Player initialization and management functions | no | none |
| includes/ui_components.php | PHP include | UI rendering helpers (cards, forms, buttons) | no | none |
| includes/display.php | PHP include | Display formatting (images, numbers, text) | no | none |
| includes/db_helpers.php | PHP include | Database wrapper functions (query, ajouter, alliance) | no | none |
| includes/prestige.php | PHP include | Cross-season prestige points system | no | none |
| includes/catalyst.php | PHP include | Weekly catalyst earning system | no | none |
| includes/multiaccount.php | PHP include | Multi-account detection and fingerprinting | no | none |
| includes/resource_nodes.php | PHP include | Resource node proximity bonus system | no | none |
| includes/compounds.php | PHP include | Compound synthesis module (5 compounds, timed buffs) | no | none |
| includes/combat.php | PHP include | Combat resolution engine | no | none |
| includes/bbcode.php | PHP include | BBCode parser for forum posts | no | none |
| includes/layout.php | PHP include | Page layout template (formerly tout.php) | no | none |
| includes/meta.php | PHP include | SEO meta tags and Open Graph | no | none |
| includes/copyright.php | PHP include | Footer copyright notice | no | none |
| includes/style.php | PHP include | Dynamic CSS generation | no | none |
| includes/atomes.php | PHP include | Atom/molecule type definitions | no | none |
| includes/ressources.php | PHP include | Resource type definitions | no | none |
| includes/statistiques.php | PHP include | Statistics calculation helpers | no | none |
| includes/redirectionVacance.php | PHP include | Redirect vacation-mode players | no | none |
| includes/basicpublicphp.php | PHP include | Public page authentication guard | no | none |
| includes/basicprivatehtml.php | PHP include | Private page HTML structure | no | none |
| includes/basicprivatephp.php | PHP include | Private page authentication guard | no | none |
| includes/basicpublichtml.php | PHP include | Public page HTML structure | no | none |
| includes/cardsprivate.php | PHP include | Private player dashboard cards | no | none |

### JavaScript Directory (2 files)

| File | Type | Purpose | HTTP | Auth |
|------|------|---------|------|------|
| js/countdown.js | JS | Season countdown timer (live updating) | yes | none |
| js/framework7.min.js | JS | Framework7 mobile UI library (minified) | yes | none |

### CSS Directory (15 files)

| File | Type | Purpose | HTTP | Auth |
|------|------|---------|------|------|
| css/my-app.css | CSS | Custom application styles | yes | none |
| css/framework7.material.css | CSS | Framework7 Material Design theme | yes | none |
| css/framework7.material.min.css | CSS | Framework7 Material theme (minified) | yes | none |
| css/framework7.material.colors.css | CSS | Material Design color variants | yes | none |
| css/framework7.material.colors.min.css | CSS | Material Design colors (minified) | yes | none |
| css/framework7.material.rtl.css | CSS | Material Design RTL support | yes | none |
| css/framework7.material.rtl.min.css | CSS | Material RTL (minified) | yes | none |
| css/framework7.ios.css | CSS | Framework7 iOS theme | yes | none |
| css/framework7.ios.min.css | CSS | Framework7 iOS theme (minified) | yes | none |
| css/framework7.ios.colors.css | CSS | iOS color variants | yes | none |
| css/framework7.ios.colors.min.css | CSS | iOS colors (minified) | yes | none |
| css/framework7.ios.rtl.css | CSS | iOS RTL support | yes | none |
| css/framework7.ios.rtl.min.css | CSS | iOS RTL (minified) | yes | none |
| css/framework7-icons.css | CSS | Framework7 icon font definitions | yes | none |
| css/fonts/ | CSS | Font files directory | yes | none |

### Database Migrations (80 files)

| File | Type | Purpose | HTTP | Auth |
|------|------|---------|------|------|
| migrations/migrate.php | PHP script | Migration runner and tracker | no | admin |
| migrations/0001_add_indexes.sql | Migration | Add performance indexes | no | none |
| migrations/0002_fix_column_types.sql | Migration | Fix numeric/text column types | no | none |
| migrations/0003_add_trade_volume.sql | Migration | Market trade volume tracking | no | none |
| migrations/0004_add_attack_cooldowns.sql | Migration | Attack rate limiting per player | no | none |
| migrations/0005_add_vault_building.sql | Migration | Vault building addition | no | none |
| migrations/0006_add_formation_column.sql | Migration | Army formation storage | no | none |
| migrations/0007_add_prestige_table.sql | Migration | Cross-season prestige system | no | none |
| migrations/0008_add_isotope_column.sql | Migration | Isotope specialization | no | none |
| migrations/0009_add_catalyst_columns.sql | Migration | Weekly catalyst tracking | no | none |
| migrations/0010_add_alliance_research.sql | Migration | Alliance tech research system | no | none |
| migrations/0011_add_specializations.sql | Migration | Building specialization column | no | none |
| migrations/0012_add_session_token.sql | Migration | Session token for auth | no | none |
| migrations/0013_myisam_to_innodb_and_charset.sql | Migration | Engine and charset fixes | no | none |
| migrations/0014_add_actionsattaques_combat_index.sql | Migration | Combat action indexes | no | none |
| migrations/0015_fix_schema_issues.sql | Migration | Schema constraint fixes | no | none |
| migrations/0016_connectes_primary_key.sql | Migration | Online players table PK | no | none |
| migrations/0017_add_check_constraints.sql | Migration | Data validation constraints | no | none |
| migrations/0018_add_foreign_keys.sql | Migration | Referential integrity FKs | no | none |
| migrations/0019_fix_idclasse_type.sql | Migration | Class ID type correction | no | none |
| migrations/0020_create_login_history.sql | Migration | Login audit trail | no | none |
| migrations/0021_create_account_flags.sql | Migration | Account warning/flag system | no | none |
| migrations/0022_recalculate_sqrt_ranking.sql | Migration | Square-root ranking recalc | no | none |
| migrations/0023_create_resource_nodes.sql | Migration | Resource node system | no | none |
| migrations/0024_create_compounds.sql | Migration | Compound synthesis table | no | none |
| migrations/0025_add_ionisateur_hp.sql | Migration | Ionizer building HP column | no | none |
| migrations/0026_add_totalpoints_index.sql | Migration | Total points performance index | no | none |
| migrations/0027_add_login_streak.sql | Migration | Daily login streak tracking | no | none |
| migrations/0028_add_comeback_tracking.sql | Migration | Comeback bonus tracking | no | none |
| migrations/0029_create_season_recap.sql | Migration | Season archive table | no | none |
| migrations/0030_alliance_unique_constraints.sql | Migration | Alliance table constraints | no | none |
| migrations/0031_create_sondages_table.sql | Migration | Polls/survey table | no | none |
| migrations/0032_create_reponses_sondage.sql | Migration | Poll responses table | no | none |
| migrations/0033_fix_utf8mb4_tables.sql | Migration | UTF-8 charset fixes | no | none |
| migrations/0034_add_alliance_left_at.sql | Migration | Alliance leave timestamp | no | none |
| migrations/0035_add_missing_pks_and_fks.sql | Migration | Primary key fixes | no | none |
| migrations/0036_fix_not_null_constraints.sql | Migration | NOT NULL constraint fixes | no | none |
| migrations/0037_fix_auteur_charset.sql | Migration | Author field charset | no | none |
| migrations/0038_create_email_queue.sql | Migration | Email queue for async sending | no | none |
| migrations/0039_add_compound_snapshot_to_actionsattaques.sql | Migration | Combat compound state storage | no | none |
| migrations/0040_convert_myisam_to_innodb.sql | Migration | Engine conversion | no | none |
| migrations/0041_cleanup_orphans_and_fks.sql | Migration | Orphaned record cleanup | no | none |
| migrations/0042_unique_email_login.sql | Migration | Email/login uniqueness | no | none |
| migrations/0043_create_moderation_log.sql | Migration | Moderation action audit | no | none |
| migrations/0044_add_message_soft_delete.sql | Migration | Soft delete for messages | no | none |
| migrations/0045_fix_vacances_charset.sql | Migration | Vacation table charset | no | none |
| migrations/0046_0035_procedure_note.sql | Migration | Migration note/comment | no | none |
| migrations/0047_add_rapport_type.sql | Migration | Report type classification | no | none |
| migrations/0048_fix_grades_pk_safe.sql | Migration | Alliance grades PK fix | no | none |
| migrations/0049_check_constraints_nonneg.sql | Migration | Non-negative value checks | no | none |
| migrations/0050_idempotent_add_columns.sql | Migration | Safe column additions | no | none |
| migrations/0051_composite_indexes.sql | Migration | Multi-column indexes | no | none |
| migrations/0052_fix_column_types.sql | Migration | Column type corrections | no | none |
| migrations/0053_add_compound_pillage_snapshot.sql | Migration | Pillage compound state | no | none |
| migrations/0054_add_season_recap_fk.sql | Migration | Season recap foreign keys | no | none |
| migrations/0055_declarations_fk.sql | Migration | War/pact declaration FKs | no | none |
| migrations/0056_fix_resource_nodes_charset.sql | Migration | Resource nodes charset | no | none |
| migrations/0057_season_recap_constraints.sql | Migration | Season recap constraints | no | none |
| migrations/0058_fix_compound_fk.sql | Migration | Compound table FK fix | no | none |
| migrations/0059_fix_login_history_and_connectes.sql | Migration | Login history cleanup | no | none |
| migrations/0060_moderation_log_fk.sql | Migration | Moderation log FKs | no | none |
| migrations/0061_spec_check_constraints.sql | Migration | Specialization constraints | no | none |
| migrations/0062_vacances_fk_and_molecules_type.sql | Migration | Vacation and molecule FKs | no | none |
| migrations/0063_idempotent_check_constraints.sql | Migration | Safe constraint additions | no | none |
| migrations/0064_convert_remaining_utf8_tables.sql | Migration | UTF-8 full conversion | no | none |
| migrations/0065_invitations_indexes.sql | Migration | Alliance invite indexes | no | none |
| migrations/0066_messages_rapports_composite_indexes.sql | Migration | Message/report indexes | no | none |
| migrations/0067_player_compounds_unique.sql | Migration | Player compound uniqueness | no | none |
| migrations/0068_declarations_not_null.sql | Migration | Declaration NOT NULL fixes | no | none |
| migrations/0069_membre_coordinates_unique.sql | Migration | Player coordinate uniqueness | no | none |
| migrations/0070_alliance_desc_maxlength.sql | Migration | Alliance description length | no | none |
| migrations/0071_declarations_pertesTotales_generated.sql | Migration | War losses calculation | no | none |
| migrations/0072_login_history_composite_idx.sql | Migration | Login audit indexes | no | none |
| migrations/0073_rapports_sujets_indexes.sql | Migration | Report and topic indexes | no | none |
| migrations/0074_declarations_winner.sql | Migration | War winner tracking | no | none |
| migrations/0075_prestige_total_pp_unsigned.sql | Migration | Prestige points type fix | no | none |
| migrations/0076_declarations_type_fin_index.sql | Migration | War end state indexes | no | none |
| migrations/0077_email_queue_utf8.sql | Migration | Email queue charset | no | none |
| migrations/0078_leaderboard_indexes.sql | Migration | Leaderboard performance | no | none |
| migrations/0079_prestige_awarded_season.sql | Migration | Prestige season tracking | no | none |
| migrations/0080_hash_ip_columns.sql | Migration | IP hashing for privacy | no | none |

### Test Files (38 files)

#### Unit Tests (19 files)

| File | Type | Purpose | HTTP | Auth |
|------|------|---------|------|------|
| tests/unit/CombatFormulasTest.php | Test | Combat damage and defense formulas | no | none |
| tests/unit/CompoundsTest.php | Test | Compound synthesis system | no | none |
| tests/unit/ConfigConsistencyTest.php | Test | Config.php constant validation | no | none |
| tests/unit/CsrfTest.php | Test | CSRF token generation/verification | no | none |
| tests/unit/DatabaseConnectionTest.php | Test | Database connection and queries | no | none |
| tests/unit/DiminuerBatimentTest.php | Test | Building damage calculations | no | none |
| tests/unit/ExploitPreventionTest.php | Test | Security exploit prevention | no | none |
| tests/unit/GameBalanceTest.php | Test | Game balance and fairness | no | none |
| tests/unit/GameFormulasTest.php | Test | General game formulas | no | none |
| tests/unit/MarketFormulasTest.php | Test | Market pricing and trades | no | none |
| tests/unit/MultiaccountTest.php | Test | Multi-account detection | no | none |
| tests/unit/PactSystemTest.php | Test | Alliance pact system | no | none |
| tests/unit/PrestigeTest.php | Test | Prestige points system | no | none |
| tests/unit/RateLimiterTest.php | Test | Rate limiting functionality | no | none |
| tests/unit/ResourceFormulasTest.php | Test | Resource production formulas | no | none |
| tests/unit/ResourceNodesTest.php | Test | Resource node system | no | none |
| tests/unit/SecurityFunctionsTest.php | Test | Security helper functions | no | none |
| tests/unit/SqrtRankingTest.php | Test | Square-root ranking system | no | none |
| tests/unit/ValidationTest.php | Test | Input validation functions | no | none |

#### Integration Tests (8 files)

| File | Type | Purpose | HTTP | Auth |
|------|------|---------|------|------|
| tests/integration/BuildingConstructionTest.php | Test | Building construction system | no | none |
| tests/integration/CombatFlowTest.php | Test | Full combat flow | no | none |
| tests/integration/MarketSystemTest.php | Test | Market trading system | no | none |
| tests/integration/MultiaccountDetectionTest.php | Test | Multi-account detection integration | no | none |
| tests/integration/ResourceProductionTest.php | Test | Resource generation system | no | none |
| tests/integration/SeasonPrestigeTest.php | Test | Season and prestige integration | no | none |
| tests/integration/IntegrationTestCase.php | Test | Base test case class | no | none |
| tests/integration/bootstrap_integration.php | Test | Integration test setup | no | none |

#### Functional Tests (3 files)

| File | Type | Purpose | HTTP | Auth |
|------|------|---------|------|------|
| tests/functional/ConfigSanityTest.php | Test | Configuration sanity checks | no | none |
| tests/functional/FormulaConsistencyTest.php | Test | Formula consistency across modules | no | none |
| tests/functional/PageIncludesTest.php | Test | Page include structure validation | no | none |

#### Balance Tests (6 files)

| File | Type | Purpose | HTTP | Auth |
|------|------|---------|------|------|
| tests/balance/AllianceResearchTest.php | Test | Alliance research system balance | no | none |
| tests/balance/CombatFairnessTest.php | Test | Combat fairness and balance | no | none |
| tests/balance/CompoundBalanceTest.php | Test | Compound system balance | no | none |
| tests/balance/EconomyProgressionTest.php | Test | Economic progression curves | no | none |
| tests/balance/IsotopeSpecializationTest.php | Test | Isotope specialization balance | no | none |
| tests/balance/StrategyViabilityTest.php | Test | Strategy diversity viability | no | none |
| tests/balance/bootstrap_balance.php | Test | Balance test setup | no | none |

#### Test Bootstrap

| File | Type | Purpose | HTTP | Auth |
|------|------|---------|------|------|
| tests/bootstrap.php | Test | PHPUnit test bootstrap | no | none |

### Test Fixtures (2 files)

| File | Type | Purpose | HTTP | Auth |
|------|------|---------|------|------|
| tests/integration/fixtures/base_schema.sql | SQL | Base schema for test DB | no | none |
| tests/integration/fixtures/seed_players.sql | SQL | Test player data fixtures | no | none |

### Tools & Scripts (1 file)

| File | Type | Purpose | HTTP | Auth |
|------|------|---------|------|------|
| tools/balance_simulator.php | PHP script | Game balance testing simulator | no | admin |
| scripts/cleanup_old_data.php | PHP script | Data cleanup maintenance script | no | admin |

### Configuration Files

| File | Type | Purpose | HTTP | Auth |
|------|------|---------|------|------|
| .htaccess | Config | Apache security rules (SQL injection, XSS protection) | no | none |
| .env.example | Config | Environment variables template | no | none |
| composer.json | Config | PHP dependency manager | no | none |
| composer.lock | Config | Composer lock file | no | none |
| .gitignore | Config | Git ignore rules | no | none |

## Summary Statistics

- **Total Root PHP Pages:** 47
- **Admin Pages:** 10
- **Moderation Pages:** 3
- **Includes (non-HTTP):** 41
- **JavaScript Files:** 2
- **CSS Files:** 15
- **Database Migrations:** 80
- **Test Files:** 38
- **Configuration Files:** 5
- **Tools/Scripts:** 2

**Total Files Cataloged:** 243 files

**Key Observations:**
1. Comprehensive modularized architecture with 8 module includes under fonctions.php
2. 80 numbered database migrations with clear progression
3. 38 test files covering unit, integration, functional, and balance testing
4. Strong security focus: CSRF, rate limiting, session tokens, input validation
5. Three access levels: public (none), player (logged-in), admin/moderator
6. RESTful API endpoint (api.php) for frontend previews
7. Admin panels separated (admin/, moderation/)
8. Framework7 Material Design mobile-first UI
9. All pages follow prepared statement pattern for SQL injection prevention
10. Layout.php serves as main template (formerly tout.php)