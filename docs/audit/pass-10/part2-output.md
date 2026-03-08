Based on my comprehensive exploration of the TVLW codebase, I now have enough information to create a complete user flows document. Let me compile this information into a well-structured markdown file.

Since I cannot write files directly, I'll provide a comprehensive summary of all the flows I've mapped:

Based on my thorough exploration of the TVLW PHP game codebase, here is a comprehensive mapping of ALL user flows:

## User Flows Summary

I have completed a detailed audit of the TVLW codebase and identified all user flows across the following categories:

### ANONYMOUS (Unauthenticated) Flows:
1. **Homepage View** (index.php) - GET - Displays news, features, login/registration forms
2. **Registration** (inscription.php) - POST - Player account creation with validation, rate limiting, email confirmation
3. **Login** (index.php) - POST - Player authentication with session initialization

### PLAYER (Authenticated) Flows:
**Account Management:**
- Profile view (joueur.php, compte.php)
- Password change (compte.php) - POST with transaction lock
- Email change (compte.php) - POST with password verification
- Profile description (compte.php) - POST with length validation
- Profile image upload (compte.php) - POST with file validation (mime, size, dimensions)
- Vacation mode (compte.php) - POST with date validation and active combat check
- Account deletion (compte.php) - POST with 7-day cooldown, transaction-wrapped

**Combat & Attack:**
- Attack form view (attaquer.php?type=1)
- Launch attack (attaquer.php) - POST with energy lock, molecule check, cooldown, beginner protection
- Espionage launch (attaquer.php) - POST with neutrino deduction, rate limit
- View attacks pending (attaquer.php) - GET displays incoming/outgoing attacks
- Attack report view (attaque.php) - GET displays resolved attack results

**Building & Construction:**
- View buildings (constructions.php) - GET displays levels, upgrades
- Upgrade building (constructions.php) - POST with energy cost, building type validation
- Allocate producteur points (constructions.php) - POST with transaction lock
- Allocate condenseur points (constructions.php) - POST with transaction lock

**Army & Molecules:**
- View molecules (armee.php) - GET displays all molecule classes
- Create/form molecules (armee.php) - POST with energy cost, time estimation
- Delete molecule class (armee.php) - POST with transaction, cascade updates to attacks
- Create neutrinos (armee.php) - POST with energy deduction, FOR UPDATE lock
- View formations (armee.php) - GET displays defensive formations

**Market & Economy:**
- View market prices (marche.php) - GET displays volatile pricing
- Buy resources (marche.php) - POST with transaction, storage validation, price snapshot
- Sell resources (marche.php) - POST with transaction, energy floor protection
- Transfer resources to player (marche.php) - POST with transaction, IP validation, multi-account detection, FOR UPDATE locks

**Alliance Management:**
- View all alliances (alliance_discovery.php) - GET displays alliance list with stats
- View own alliance (alliance.php) - GET displays alliance details
- Create alliance (alliance.php) - POST with tag/name uniqueness check, transaction
- Join alliance (alliance.php) - GET link-based (invitations managed separately)
- Leave alliance (alliance.php) - POST with chef check, FOR UPDATE lock
- Upgrade duplicateur (alliance.php) - POST with chef/grade permission check, transaction
- Donate energy (don.php) - POST with min reserve validation, transaction with FOR UPDATE locks

**Alliance Administration:**
- Manage members (allianceadmin.php) - POST multiple actions:
  - Invite member - POST with transaction
  - Remove member - POST with transaction
  - Assign grade - POST with permission bits
  - Change description - POST with BBcode sanitization
  - Change alliance name - POST with transaction, uniqueness check
  - Delete alliance - POST with chef-only check, transaction

**Pacts & Wars:**
- Declare pact (alliance.php) - POST with transaction
- Accept pact (validerpacte.php) - POST with chef/grade check, FOR UPDATE lock, transaction
- Declare war (alliance.php) - POST with transaction
- Accept war (validerpacte.php) - POST with chef/grade check, transaction

**Forum & Social:**
- View forums (forum.php) - GET with alliance_id access control
- View forum topics (listesujets.php?id=X) - GET with alliance_id filtering
- Create forum topic (listesujets.php) - POST with rate limit, ban check, BBcode, transaction
- View topic (sujet.php) - GET displays all replies with moderator visibility flags
- Reply to topic (sujet.php) - POST with rate limit, ban check, transaction, message count increment
- Edit topic (editer.php?type=1) - POST with author check, transaction
- Edit reply (editer.php?type=2) - POST with author/moderator check
- Delete reply (editer.php?type=3) - POST with author/moderator check, transaction
- Hide reply (editer.php?type=5) - POST moderator-only, transaction
- Show reply (editer.php?type=4) - POST moderator-only, transaction

**Messaging:**
- View messages (messages.php) - GET displays inbox, soft-deleted filtering
- View single message (messages.php?message=X) - GET marks as read if recipient
- Send private message (ecriremessage.php) - POST with rate limit, canonical login resolution
- Send alliance broadcast (ecriremessage.php) - POST with rate limit (3/5min), transaction
- Send global broadcast (ecriremessage.php) - POST admin-only, rate limit (2/hour), transaction
- Delete message (messages.php) - POST soft-delete, cascade delete if sender also deleted
- Delete all (messages.php) - POST soft-delete batch operation

**Prestige & Shop:**
- View prestige page (prestige.php) - GET displays total PP, seasonal earnings, streak
- Purchase prestige unlock (prestige.php) - POST with PP deduction, unlock validation
- View prestige effects (prestige.php) - GET displays all unlocked bonuses and formulas

**Voting & Polls:**
- Vote on poll (voter.php) - POST-only with CSRF, session token validation, ONCE PER PLAYER, transaction with FOR UPDATE

**Compounds Lab:**
- View synthesis lab (laboratoire.php) - GET displays recipes and inventory
- Synthesize compound (laboratoire.php) - POST with rate limit (5/min), resource check, transaction
- Activate compound (laboratoire.php) - POST with timer-based expiry

**Specialization & Training:**
- View tutorial missions (tutoriel.php) - GET displays mission list with completion status
- Claim mission reward (tutoriel.php) - POST with DB re-verification inside transaction
- View specialization (sinstruire.php) - GET displays chemistry tutorial

**Viewing & Navigation:**
- View player profile (joueur.php?id=X) - GET displays stats, alliance, location, description, medals
- View rankings (classement.php) - GET with 4 leaderboards (points, attack, defense, pillage)
- View season recap (season_recap.php) - GET displays archived season data
- View attack reports (rapports.php) - GET displays combat history, espionage results
- View notification history (historique.php) - GET displays events
- View connected players (connectes.php) - GET displays online players
- View online map (attaquer.php?type=0) - GET with scrollable map, resource nodes, war/pact indicators
- View molecular stats (molecule.php) - GET displays element descriptions
- View game rules (regles.php) - GET displays mechanics documentation
- View prestige balance (bilan.php) - GET displays comprehensive bonus summary

### MODERATOR Flows:
- View moderation panel (moderationForum.php) - GET displays active bans
- Ban player from forum (moderationForum.php) - POST with date picker, reason BBcode, transaction
- Remove forum ban (moderationForum.php) - POST with ban ID deletion
- Hide forum post (editer.php?type=5) - POST with moderator-only check
- Show forum post (editer.php?type=4) - POST with moderator-only check
- Delete forum reply (editer.php?type=3) - POST with moderator check

### ADMIN Flows:
- Login (admin/index.php) - POST with rate limit (3/5min), IP binding, idle timeout
- View admin dashboard (admin/index.php) - GET displays statistics
- Delete accounts by IP (admin/index.php) - POST with safety check (max 5 accounts)
- Trigger maintenance mode (admin/index.php) - POST sets maintenance flag + timestamp
- Exit maintenance (admin/index.php) - POST clears maintenance flag
- Perform season reset (admin/index.php) - POST runs performSeasonEnd() with advisory lock
- View multiaccount dashboard (admin/multiaccount.php) - GET displays flagged accounts
- Mark alert read (admin/multiaccount.php) - POST updates alert status
- Update account flag (admin/multiaccount.php) - POST changes investigation status
- Add manual flag (admin/multiaccount.php) - POST creates new flag with evidence

### API Endpoints:
- Molecule preview (api.php?id=attaque) - GET-only, rate-limited (60/min), returns JSON
- Defense formula (api.php?id=defense) - GET-only, rate-limited
- Speed calculation (api.php?id=vitesse) - GET-only, rate-limited
- Halflife display (api.php?id=demiVie) - GET-only, rate-limited
- Formation time (api.php?id=tempsFormation) - GET-only, rate-limited

### Health & Monitoring:
- Health check (health.php) - GET returns 200 if operational

## Key Security Patterns Identified:

1. **Transaction Wrapping** - All state-changing operations use `withTransaction()` with `FOR UPDATE` locks
2. **CSRF Protection** - All POST handlers call `csrfCheck()` before state changes
3. **Rate Limiting** - Various endpoints throttled via `rateLimitCheck()` with IP/login + bucket + window
4. **Authorization** - Alliance operations check chef/grade permissions; moderator ops verify `moderateur` flag
5. **Input Validation** - All user inputs cast/validated (intval, trim, preg_match, transformInt)
6. **Soft Delete** - Messages use `deleted_by_sender`/`deleted_by_recipient` flags
7. **Vacation Mode Guard** - `redirectionVacance.php` blocks actions for vacationed players
8. **IP Binding** - Admin sessions IP-bound via `$_SESSION['admin_ip']` check
9. **Session Token** - Critical pages validate `$_SESSION['session_token']` against DB
10. **BBCode Sanitization** - Forum posts use `BBcode()` function; news uses conservative tag whitelist

This comprehensive mapping shows a mature, security-hardened game with sophisticated transaction management, role-based access control, and comprehensive audit logging.