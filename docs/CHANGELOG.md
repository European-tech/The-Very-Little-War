# Changelog

## [3.0.0] - 2026-03-03 - V4 Gameplay Balance Overhaul

Complete rewrite of the game balance engine. Every formula, cost, and economy
mechanic has been redesigned for depth and strategic variety.

### Covalent Synergy System (New)

Every molecule stat now depends on TWO atom types (primary + secondary):
- `stat = (pow(primary, 1.2) + primary) * (1 + secondary / 100) * modCond(nivCond)`
- Attack: O + H | Defense: C + Br | HP: Br + C | Destruction: H + O
- Pillage: S + Cl | Speed: Cl + N | Formation: N + I
- Unified condenseur modifier: `modCond = 1 + (nivCond / 50)`
- Minimum HP of 10 prevents 0-brome instant wipe

### Exponential Economy

- **Building costs**: `costBase * pow(growth_base, level)` with three tiers:
  - Standard (1.15): Generateur, Producteur, Depot, Coffrefort
  - Advanced (1.20): Champdeforce, Ionisateur, Condenseur, Lieur
  - Ultimate (1.25): Stabilisateur
- **Build times**: `timeBase * pow(1.10, level + offset)` (universal growth)
- **Storage**: `round(1000 * pow(1.15, level))` (exponential, was linear 500*level)
- **Producteur drain**: `round(8 * pow(1.15, level))` (exponential, was linear 8*level)

### Building Mechanics

- **Polynomial HP**: `50 * pow(level, 2.5)` replaces exponential growth
  - Force field: `125 * pow(level, 2.5)` (2.5x standard)
  - Alliance Fortification research: `+1% HP per level`
- **Linear Lieur**: `1 + level * 0.15` replaces exponential `pow(1.07, level)`
- **Asymptotic Stabilisateur**: `pow(0.98, level)` replaces linear `1 - level * 0.01`
  - Never reaches 100% reduction, diminishing returns
  - Bonus per level buffed from 1% to 1.5%

### Iode Catalyst System (New)

- Iode is now a multiplicative generator catalyst, not flat energy
- `iodeCatalystBonus = 1.0 + min(1.0, totalIodeAtoms / 50000)` (up to +100%)
- Per-molecule energy formula: quadratic `round((0.003*I^2 + 0.04*I) * (1 + niv/50))`
- I=100 level 0 → 34 energy/mol (meaningful energy strategy)

### Molecule Decay V4

- `DECAY_ATOM_DIVISOR`: 100 → 150 (large molecules more viable)
- `DECAY_MASS_EXPONENT`: 2 → 1.5 (reduced size penalty)
- Asymptotic stabilisateur: `pow(0.98, level)` (never eliminates decay)
- Neutrinos now decay as mass-1 molecules

### Combat Changes

- Overkill cascade: surplus damage carries to next molecule class
- Combat points include mass-based component (atoms destroyed / 100)
- Chemical reactions REMOVED (no more inter-class reaction bonuses)
- Defensive rewards: +20% energy bonus on successful defense
- Combat cooldowns: 4h on loss/draw, 1h on win (same target)
- Defense points multiplier: 1.5x combat points for defensive victories

### Market & Economy

- Global economy divisor (10000) replaces per-player depot for price impact
- Sell overflow protection: excess atoms not consumed when storage full
- Both buying and selling now award trade points (5% sell tax prevents cycling)
- Market points boosted: scale 0.05→0.08, cap 40→80
- Attack/defense point multipliers: 3.0→5.0 (combat more relevant to ranking)
- Pillage points: divisor 100k→50k, multiplier 50→80

### Anti-Exploit & Balance

- Transfer ratio inverted: penalty on sending TO bigger players (prevents alt-feeding)
- Vault protection: percentage-based `min(50%, coffre*2%) * placeDepot(depot)`
- Cross-season medal bonus capped at 10% (Emeraude tier)
- 14-day grace period: medal bonuses capped at Gold (6%) in new seasons
- Duplicateur cost rebalanced: base 10→100, factor 2.5→1.5 (levels 10-12 achievable)
- Beginner protection: 5→3 days

### Review-Found Bug Fixes

- Fixed productionEnergieMolecule ignoring $niveau parameter (V4 constants were dead code)
- Fixed null dereference in iode catalyst loop for players without all 4 molecule classes
- Fixed time_level1 off-by-one (triggered for level 2 upgrade, not initial build from 0)
- Fixed time_level_offset not applied to 6 strategic building time formulas
- Fixed combat.php hardcoded `4` → `$nbClasses` in damage loop
- Fixed 3 stale comments (drainageProducteur formula, phalange values, combat bonuses)

### Tests

- 370 tests / 2325 assertions covering all V4 formulas and balance relationships
- GameBalanceTest: constants sanity, formula symmetry, exponential cost ordering
- CombatFormulasTest: covalent synergies, iode energy, overkill cascade
- Full test suite passes with V4 values

### Documentation

- All game docs updated for V4 formulas and economy (02 through 09)
- Complete balance reference (09-BALANCE.md) with every formula and constant

---

## [2.0.0] - 2026-03-02 - Complete Security & Quality Refactor

This release represents a comprehensive security hardening, bug fix, and code quality
overhaul of The Very Little War. Every PHP file has been reviewed and most have been modified.

### Security (30 commits)

**Critical Fixes:**
- Replaced deprecated `mysql_connect()` with `mysqli_connect()` + `mysqli_set_charset()`
- Converted ALL SQL queries to prepared statements using `dbQuery/dbFetchOne/dbFetchAll/dbExecute`
- Added CSRF protection (`csrfField()`/`csrfCheck()`) to every form in the application
- Migrated password hashing from MD5 to bcrypt with transparent auto-upgrade on login
- Rewrote `voter.php` from scratch (had hardcoded DB credentials, SQL injection, no auth)
- Removed localStorage password auto-login mechanism (XSS risk)
- Converted all admin GET-based destructive actions to POST with CSRF
- Added session security: httponly cookies, strict mode, session_regenerate_id()
- Hardened file uploads: MIME validation, extension whitelist, random filenames, size limits
- Fixed antihtml() charset from ISO-8859-1 to UTF-8

**New Security Infrastructure:**
- `includes/csrf.php` - CSRF token generation and verification
- `includes/validation.php` - Input validation helpers
- `includes/logger.php` - Event logging with daily rotation
- `includes/rate_limiter.php` - File-based rate limiting (login: 10/5min, registration: 3/hr)
- `.htaccess` - Security headers, directory protection, upload security

**XSS Prevention:**
- Applied `htmlspecialchars()` to all user-generated output
- Hardened BBCode parser: requires https:// for URLs, prevents attribute injection in [img]
- Updated external JS libraries from HTTP to HTTPS

### Bug Fixes (11 bugs)

- BUG-1: Combat duplicateur used wrong login for defender's alliance lookup
- BUG-2: Inconsistent depot/storage formulas
- BUG-3: Inconsistent molecule decay calculations
- BUG-4: supprimerJoueur() overwrote $modif8 causing orphaned reports
- BUG-5: Missing break in combat.php switch case 3
- BUG-6: connexion.php used deprecated mysql_connect()
- BUG-7: Mixed mysql_error()/mysqli_error() calls
- BUG-8: armee.php molecule deletion used = instead of .=
- BUG-9: Absurdly large max molecule number
- BUG-10: classement.php triggered UPDATE on view
- BUG-11: War ending only worked for declaring alliance

### Game Balance

- Extended beginner protection from 2 to 5 days
- Fixed duplicateur combat coefficient (was 10x too weak)
- Buffed iode energy production 5x
- Reduced class cost exponent (6 -> 4)
- Added market price mean-reversion, floor (0.1), ceiling (10.0)
- Reduced military building time exponents

### Code Quality

- Modularized fonctions.php (2585 lines) into 7 focused modules
- Merged duplicate update.php/update1.php
- Extracted all magic numbers into centralized config.php
- Removed dead code and unused files
- Fixed missing global declarations, stale arguments, broken includes

### Testing

- PHPUnit infrastructure with combat, resource, market, and config tests

### Database

- Migration system with 25 new indexes and column type fixes

### Tutorial

- Rewrote with 7 structured missions, progress bar, energy rewards
