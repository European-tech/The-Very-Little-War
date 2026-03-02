# 07 - Alliances, Player Lifecycle, and Map System

This document covers the alliance (team) system, player lifecycle from
registration through season resets, and the 2D map coordinate system.

---

## 1. Alliance System

Alliances (called "equipes" / teams in the UI) allow players to group together,
share a collective energy pool, wage wars, form pacts, and upgrade a shared
building called the Duplicateur.

### 1.1 Alliance Creation

**Source:** `alliance.php:30-65`

Any player who is not already in an alliance can create one by providing a name
and a tag. Validation rules:

- **Tag format:** 3-16 alphanumeric characters plus underscore, validated by the
  regex `^[a-zA-Z0-9_]{3,16}$` (`alliance.php:38`).
- **Tag length constants:** `ALLIANCE_TAG_MIN_LENGTH` (3) and
  `ALLIANCE_TAG_MAX_LENGTH` (16) defined in `includes/config.php:287-288`.
- **Uniqueness:** Both the tag and the name must be unique across all existing
  alliances. The check queries `alliances WHERE tag=? OR nom=?`
  (`alliance.php:40-41`).
- **Creator becomes chef (leader):** The creating player's login is stored in
  the `alliances.chef` column (`alliance.php:44-45`).
- **Player link:** The player's `autre.idalliance` is set to the new alliance's
  ID (`alliance.php:49`).

```sql
INSERT INTO alliances VALUES (default, ?, ?, ?, default, ?, default, default,
  default, default, default, default, default, default)
-- params: nom, tag, description(''), chef(login)
```

### 1.2 Membership and Capacity

**Max members:** 20, defined as `MAX_ALLIANCE_MEMBERS` in
`includes/config.php:25` and as `$joueursEquipe = 20` in
`includes/constantesBase.php:41`.

The member count is checked before accepting invitations (`alliance.php:100-101`)
and before sending new invitations (`allianceadmin.php:303`):

```php
if ($nombreJoueurs < $joueursEquipe) { ... }
```

**Joining:** Players join by accepting an invitation. Invitations are stored in
the `invitations` table with columns `(id, idalliance, tag, invite)`. The
invitation is created by the alliance admin (`allianceadmin.php:310`) and
accepted/refused in `alliance.php:94-111`.

**Leaving:** A member can leave voluntarily via a "Quitter" button that sets
`autre.idalliance = 0` (`alliance.php:69-72`).

**Kicking (banning):** A player with the `bannir` permission can kick members.
The kicked player's `autre.idalliance` is set to 0 and their grade entry is
deleted (`allianceadmin.php:176-193`).

### 1.3 Grades (Ranks / Permissions)

**Source:** `allianceadmin.php:10-41, 74-119`

Grades are stored in the `grades` table with columns:
`(login, grade, idalliance, nom)`.

The `grade` column stores a dot-separated permission string with 5 flags:

```
inviter.guerre.pacte.bannir.description
```

Each flag is `0` or `1`. Example: `1.1.0.1.0` means the player can invite,
declare war, and ban, but cannot propose pacts or edit the description.

**Parsing** (`allianceadmin.php:27`):
```php
list($inviter, $guerre, $pacte, $bannir, $description) = explode('.', $grade['grade']);
```

The chef (leader) automatically has all 5 permissions set to `true`
(`allianceadmin.php:35-41`).

**Grade creation:** Only the chef can create grades (`allianceadmin.php:74-106`).
A player cannot be graded if they are the chef or already have a grade.

**Grade deletion:** The chef can remove grades via the admin panel
(`allianceadmin.php:108-119`).

### 1.4 Chef (Leader) Powers

Only the chef can perform these actions (guarded by `$gradeChef` flag):

| Action | Source |
|--------|--------|
| Delete the alliance | `allianceadmin.php:44-54` |
| Change alliance name | `allianceadmin.php:56-72` |
| Change alliance tag | `allianceadmin.php:121-137` |
| Transfer leadership | `allianceadmin.php:139-158` |
| Create/delete grades | `allianceadmin.php:74-119` |

### 1.5 Alliance Deletion

**Function:** `supprimerAlliance($alliance)`
**Source:** `includes/player.php:654-663`

When an alliance is deleted (or its chef no longer exists), the following
cleanup occurs:

```php
UPDATE autre SET energieDonnee=0 WHERE idalliance=?
DELETE FROM alliances WHERE id=?
UPDATE autre SET idalliance=0 WHERE idalliance=?
DELETE FROM invitations WHERE idalliance=?
DELETE FROM declarations WHERE (alliance1=? OR alliance2=?)
DELETE FROM grades WHERE idalliance=?
```

The alliance page also auto-detects orphaned alliances: if the chef no longer
exists or is no longer in the alliance, `supprimerAlliance()` is called
(`alliance.php:116-134`).

### 1.6 Duplicateur (Alliance Technology)

**Source:** `alliance.php:74-91`, `includes/player.php:197-201`,
`includes/formulas.php:70-73`, `includes/config.php:279-284`

The Duplicateur is a collective building funded by the alliance's shared energy
pool. It provides a percentage bonus to all member production and combat stats.

**Cost formula:**
```
cost = round(DUPLICATEUR_BASE_COST * pow(DUPLICATEUR_COST_FACTOR, level + 1))
     = round(10 * pow(2.5, level + 1))
```

**Source:** `alliance.php:76`

**Cost table (levels 1-10):**

| Target Level | Cost (Energy)   |
|:------------:|----------------:|
| 1            | 63              |
| 2            | 156             |
| 3            | 391             |
| 4            | 977             |
| 5            | 2,441           |
| 6            | 6,104           |
| 7            | 15,259          |
| 8            | 38,147          |
| 9            | 95,367          |
| 10           | 238,419         |

Formula verification: `round(10 * pow(2.5, 1))` = 25... Wait, the cost uses
`duplicateur + 1` where `duplicateur` is the current level. So to go from
level 0 to level 1, cost = `round(10 * pow(2.5, 0 + 1))` = `round(10 * 2.5)` = 25.

Corrected cost table:

| From Level | To Level | Cost (Energy)   |
|:----------:|:--------:|----------------:|
| 0          | 1        | 25              |
| 1          | 2        | 63              |
| 2          | 3        | 156             |
| 3          | 4        | 391             |
| 4          | 5        | 977             |
| 5          | 6        | 2,441           |
| 6          | 7        | 6,104           |
| 7          | 8        | 15,259          |
| 8          | 9        | 38,147          |
| 9          | 10       | 95,367          |

**Effect formula:**
```php
function bonusDuplicateur($niveau) {
    return $niveau / 100;   // 1% per level
}
```
**Source:** `includes/formulas.php:70-73`

At level 5, for example, the bonus is 5/100 = 0.05 = +5%.

**Where the bonus applies:**

1. **Resource production:** In `initPlayer()`, the duplicateur bonus is fetched
   and applied as a multiplier `1 + (duplicateur / 100)`
   (`includes/player.php:197-201`).

2. **Combat stats:** In `includes/combat.php`, the duplicateur bonus is applied
   to both attack and defense calculations using
   `DUPLICATEUR_COMBAT_COEFFICIENT` (1.0), meaning `level / 100` per level
   (`includes/config.php:234`).

3. **Building display:** The champ de force and ionisateur tooltips show the
   duplicateur-boosted percentages:
   `floor($bonusDuplicateur * level * 2)` (`includes/player.php:352-370`).

**Upgrade process** (`alliance.php:78-90`):
- Checks `energieAlliance >= cout`
- Increments `alliances.duplicateur`
- Deducts cost from `alliances.energieAlliance`

### 1.7 War System

**Source:** `allianceadmin.php:249-297`, `guerre.php:1-62`,
`includes/combat.php:383-398`

Wars are stored in the `declarations` table with `type=0`.

**Declaration columns:**
```
id, type, alliance1, alliance2, timestamp, pertes1, pertes2, pertesTotales, fin, valide
```

**Declaring war** (`allianceadmin.php:250-277`):
- Requires the `guerre` permission.
- Target alliance must exist and not be the same as the declaring alliance.
- Cannot declare if already at war (`type=0, fin=0`) or allied (`type=1,
  valide!=0`) with the target.
- Cleans up any pending (unvalidated) pact proposals between the two alliances.
- Sends a report to the target alliance's chef.

```php
INSERT INTO declarations VALUES(default, 0, ?, ?, ?, default, default, default, default, default)
-- type=0 (war), alliance1=declaring, alliance2=target, timestamp=now
```

**Ending war** (`allianceadmin.php:279-296`):
- Only the declaring alliance's admin can end the war.
- Sets `declarations.fin` to current timestamp.
- Sends a report to the target alliance's chef.

**War losses tracking** (`includes/combat.php:383-398`):
- After each combat between players whose alliances are at war, molecule losses
  are recorded.
- `pertes1` tracks losses for `alliance1`, `pertes2` for `alliance2`.
- The combat code checks which alliance the attacker belongs to and assigns
  losses accordingly.

**War detail page** (`guerre.php:15-57`):
- Displays total molecule losses per side.
- Shows start date, end date (if ended), and duration in days.
- Loss percentages computed as `pertes / (pertes1 + pertes2) * 100`.

### 1.8 Pacts

**Source:** `allianceadmin.php:195-247`, `validerpacte.php:1-41`

Pacts are stored in the `declarations` table with `type=1`.

**Proposing a pact** (`allianceadmin.php:196-228`):
- Requires the `pacte` permission.
- Cannot propose if already at war or allied with the target.
- Creates a declaration with `valide=0` (pending).
- Sends a report to the target alliance's chef containing accept/refuse buttons.

```php
INSERT INTO declarations VALUES(default, 1, ?, ?, ?, default, default, default, default, default)
-- type=1 (pact), valide=0 (pending)
```

**Validating a pact** (`validerpacte.php:4-26`):
- The target alliance's chef receives a report with a form.
- Authorization check: the current player must be the chef of `alliance2`
  (`validerpacte.php:10-17`).
- **Accept:** Sets `declarations.valide = 1` (`validerpacte.php:20`).
- **Refuse:** Deletes the declaration (`validerpacte.php:23`).
- Redirects to `rapports.php` after processing.

**Breaking a pact** (`allianceadmin.php:230-246`):
- Deletes the declaration row entirely.
- Sends a report to the other alliance's chef.

**Display:** Active pacts (where `valide != 0`) are shown on the alliance page
(`alliance.php:194-207`) and in the admin panel (`allianceadmin.php:444-490`).

### 1.9 Energy Donations

**Source:** `don.php:1-62`

Players can donate energy from their personal `ressources.energie` to their
alliance's shared pool `alliances.energieAlliance`.

**Process** (`don.php:4-49`):
1. Input validation: must be a positive integer matching `^[0-9]*$`.
2. Verify player has an alliance (`autre.idalliance > 0`).
3. Verify alliance exists.
4. Check `ressources.energie >= energieEnvoyee`.
5. Deduct from `ressources.energie`.
6. Add to `autre.energieDonnee` (personal donation tracking).
7. Add to `alliances.energieAlliance` (spendable pool).
8. Add to `alliances.energieTotaleRecue` (lifetime total, used for percentage
   display).

The donation percentage shown on the alliance member list is calculated as:
```
joueur.energieDonnee / alliance.energieTotaleRecue * 100
```
**Source:** `alliance.php:321-325`

---

## 2. Player Lifecycle

### 2.1 Registration

**Source:** `inscription.php:1-79`, `includes/player.php:27-69`

**Rate limiting:** 3 registrations per hour per IP address
(`inscription.php:9`).

**Validation** (`inscription.php:17-53`):
- Login: 3-20 alphanumeric characters, validated by `validateLogin()`
  (`inscription.php:27`).
- Login is ucfirst + mb_strtolower normalized (`inscription.php:19`).
- Email must pass `validateEmail()` and be unique (`inscription.php:29-34`).
- Password and confirmation must match.
- CSRF token check.

**The `inscrire()` function** (`includes/player.php:27-69`):

This function creates all database rows for a new player.

**Step 1 -- Random starting element** (`includes/player.php:33-50`):

A random number 1-200 determines the player's element (stored in
`membre.element`):

| Range   | Element | Probability |
|---------|:-------:|:-----------:|
| 1-100   | 0       | 50.0%       |
| 101-150 | 1       | 25.0%       |
| 151-175 | 2       | 12.5%       |
| 176-187 | 3       | 6.0%        |
| 188-193 | 4       | 3.0%        |
| 194-197 | 5       | 2.0%        |
| 198-199 | 6       | 1.0%        |
| 200     | 7       | 0.5%        |

**Source constants:** `REGISTRATION_RANDOM_MAX` (200),
`$REGISTRATION_ELEMENT_THRESHOLDS` in `includes/config.php:400-401`.

**Step 2 -- Database rows created** (`includes/player.php:52-68`):

| Table          | Notes                                                          |
|----------------|----------------------------------------------------------------|
| `membre`       | Login, hashed password, timestamps, IP, element, email, x=-1000, y=-1000 |
| `autre`        | Player metadata, idalliance=0, description="Pas de description", timestamps |
| `ressources`   | All resources at default values                                |
| `molecules`    | 4 rows (classes 1-4), all empty/default                        |
| `constructions`| All buildings at default (0) except generateur, producteur, depot at 1; HP values initialized |
| `statistiques` | Increments `inscrits` count                                    |

**Starting position:** `x = -1000, y = -1000` (off-map sentinel value). The
player is placed on the actual map on first login (see section 2.3).

**Starting buildings:** `generateur=1, producteur=1, depot=1` (from DB defaults).
All other buildings start at 0. Building HP values are initialized:
`vieGenerateur = pointsDeVie(1)`, `vieChampdeforce = vieChampDeForce(0)`,
`vieProducteur = pointsDeVie(1)`, `vieDepot = pointsDeVie(1)`
(`includes/player.php:68`).

### 2.2 New Player Protections

**Source:** `includes/config.php:26-28`

| Protection             | Duration | Constant                        |
|------------------------|----------|---------------------------------|
| Beginner protection    | 5 days   | `BEGINNER_PROTECTION_SECONDS` (432000) |
| Production boost (2x)  | 3 days   | `NEW_PLAYER_BOOST_DURATION` (259200)   |
| Boost multiplier       | 2x       | `NEW_PLAYER_BOOST_MULTIPLIER` (2)      |

Beginner protection prevents attacks against the new player for 5 days after
registration.

The production boost doubles all resource output for the first 3 days, giving
new players a head start.

### 2.3 First Login and Map Placement

**Source:** `includes/basicprivatephp.php:38-42`

On every authenticated page load, the system checks if the player's position is
the off-map sentinel `(-1000, -1000)`. If so, it calls
`coordonneesAleatoires()` and places the player:

```php
$posAct = dbFetchOne($base, 'SELECT x, y FROM membre WHERE login = ?', 's', $_SESSION['login']);
if ($posAct && $posAct['x'] == -1000) {
    $position = coordonneesAleatoires();
    dbExecute($base, 'UPDATE membre SET x = ?, y = ? WHERE login = ?', 'iis',
        $position['x'], $position['y'], $_SESSION['login']);
}
```

### 2.4 Game Loop (Every Page Load)

**Source:** `includes/basicprivatephp.php:1-280`

Every authenticated page that includes `basicprivatephp.php` executes the
following sequence:

```
1. Session security hardening (cookie flags, strict mode)     [line 2-8]
2. Auth check: session hash vs DB hash                        [line 16-34]
   - Mismatch -> session_destroy() -> redirect to index.php
   - Session ID regeneration on first load                    [line 25-29]
3. Map placement if x == -1000 (first login)                  [line 38-42]
4. Load constants (constantes.php)                            [line 43]
5. Track online users (connectes table, 5-min timeout)        [line 57-72]
6. Vacation check                                             [line 76-107]
   - If on vacation: check if vacation ended, auto-disable
   - If not on vacation: proceed to step 7
7. updateRessources(login) -- accumulate since last visit     [line 79]
8. updateActions(login) -- process completed queued actions    [line 86]
9. Season reset check (monthly)                               [line 111-279]
10. Page-specific code runs
11. User action -> form submit -> new action queued
```

The game is entirely driven by page loads. There is no background worker or
cron job. Resource accumulation, action completion, and season resets all happen
when any player loads any page.

### 2.5 Vacation Mode

**Source:** `compte.php:13-28`, `includes/basicprivatephp.php:76-107`,
`includes/redirectionVacance.php:1-10`, `vacance.php:1-10`

**Activating vacation** (`compte.php:13-28`):
- Player sets a `dateFin` (end date) via the account page.
- Minimum duration: 3 days (`$dateT->getTimestamp() >= time() + (3600 * 24 * 3)`).
- Deletes all pending molecule formation actions (`actionsformation`).
- Creates a row in the `vacances` table with `(idJoueur, dateDebut=CURRENT_DATE,
  dateFin)`.
- Sets `membre.vacance = 1`.

**During vacation** (`includes/basicprivatephp.php:91-107`):
- No resource updates (`updateRessources` is called but production is
  effectively paused since `tempsPrecedent` is not updated).
- No action processing (`updateActions` is skipped).
- `derniereConnexion` is still updated (so the player counts as active).
- On each page load, the system checks if `dateFin` has passed:
  - If vacation ended: sets `membre.vacance = 0`, deletes the `vacances` row,
    resets `autre.tempsPrecedent` to now.

**Vacation redirect** (`includes/redirectionVacance.php`):
Most game pages include this file, which redirects vacation players to
`vacance.php` -- a simple page showing "You are on vacation."

### 2.6 Season Reset

**Source:** `includes/basicprivatephp.php:111-279`,
`includes/player.php:704-737`

The game runs in monthly seasons. At the start of each new month, a two-phase
reset is triggered:

**Phase 1 -- Maintenance announcement** (`basicprivatephp.php:270-275`):
- Detects new month: `date('n', time()) != date('n', $debut["debut"])`.
- Sets `statistiques.maintenance = 1`.
- Updates `statistiques.debut` to current time (starts the 24h countdown).
- Displays: "A new game will restart in 24 hours."

**Phase 2 -- Full reset** (`basicprivatephp.php:117-268`):
Triggered when `maintenance == 1` and 24 hours have passed since Phase 1.

**Archival (before reset):**
- Top 20 players archived into `parties` table (login, totalPoints, alliance,
  points breakdown, victories) (`basicprivatephp.php:122-142`).
- Top 20 alliances archived (tag, members, total points, breakdowns,
  pointsVictoire) (`basicprivatephp.php:145-153`).
- Top 20 wars archived (participants, total losses, duration)
  (`basicprivatephp.php:156-166`).
- Victory points awarded to players and alliances based on rankings
  (`basicprivatephp.php:169-186`).

**The `remiseAZero()` function** (`includes/player.php:704-737`):

Resets the following:

| What                  | Reset to                                | Source line |
|-----------------------|-----------------------------------------|-------------|
| `autre` fields        | points=0, niveaututo=1, nbattaques=0, energieDonnee=0, totalPoints=0, pointsAttaque=0, pointsDefense=0, ressourcesPillees=0, tradeVolume=0, batMax=1 | 710 |
| `constructions`       | All buildings to defaults; generateur/producteur at default, depot=1, condenseur=0; HP values reinitialized | 711 |
| `alliances`           | energieAlliance=0, duplicateur=0        | 712 |
| `molecules`           | All set to formule="Vide", nombre=0     | 713 |
| `membre.timestamp`    | Current time                            | 714 |
| `ressources`          | All atom types and energy to defaults   | 716-725 |
| `declarations`        | All deleted                             | 726 |
| `invitations`         | All deleted                             | 727 |
| `messages`            | All deleted                             | 728 |
| `rapports`            | All deleted                             | 729 |
| `actionsconstruction` | All deleted                             | 730 |
| `actionsformation`    | All deleted                             | 731 |
| `actionsenvoi`        | All deleted                             | 732 |
| `actionsattaques`     | All deleted                             | 733 |
| `statistiques`        | nbDerniere=0, tailleCarte=1             | 735 |
| `membre` positions    | x=-1000, y=-1000 (off-map, re-placed on next login) | 736 |

**Post-reset:**
- `statistiques.debut` updated to current time (`basicprivatephp.php:200`).
- A news post announces the winner (`basicprivatephp.php:202-207`).
- Email sent to all players about the new season (`basicprivatephp.php:210-264`).
- `statistiques.maintenance` set back to 0 (`basicprivatephp.php:268`).

**Key note:** Victory points (`autre.victoires`, `alliances.pointsVictoire`)
are NOT reset. They persist across seasons, providing a long-term progression
metric.

### 2.7 Account Deletion

**Function:** `supprimerJoueur($joueur)`
**Source:** `includes/player.php:665-689`

Triggered from `compte.php:8-10` when the player confirms deletion.

Removes the player from all tables:

```
vacances, autre, membre, ressources, molecules, constructions,
invitations, messages, rapports, grades, actionsattaques,
actionsformation, actionsenvoi, statutforum
```

Also decrements `statistiques.inscrits`.

**Note:** The function does NOT update the player's alliance membership
(`autre.idalliance`). However, since the `autre` row is deleted entirely, the
alliance member count query (`SELECT login FROM autre WHERE idalliance=?`) will
naturally exclude the deleted player. If the deleted player was the chef, the
orphan detection in `alliance.php:116-134` will trigger
`supprimerAlliance()` on the next page load by any member.

---

## 3. Map System

### 3.1 Coordinate Grid

**Source:** `includes/player.php:539-598`, `includes/basicprivatephp.php:38-42`

The game world is a 2D integer coordinate grid. Player positions are stored as
`(x, y)` in the `membre` table. The grid starts at `(0, 0)` and expands
dynamically as new players are placed.

**Off-map sentinel:** `(-1000, -1000)` indicates a player who has not yet been
placed (new registration or post-season-reset). They are placed on their first
login.

### 3.2 Random Placement Algorithm

**Function:** `coordonneesAleatoires()`
**Source:** `includes/player.php:539-598`

The algorithm places new players along the edges of the expanding map:

1. Read `tailleCarte` (current map size) and `nbDerniere` (placement counter)
   from `statistiques` (`player.php:542`).
2. If `nbDerniere > tailleCarte - 2`, reset counter and expand the map by 1
   (`player.php:544-547`).
3. Build a 2D occupancy grid from all player positions (`player.php:549-561`).
4. Randomly choose horizontal or vertical edge placement (`player.php:563`):
   - **Horizontal:** y = tailleCarte - 1 (bottom edge), random x.
   - **Vertical:** x = tailleCarte - 1 (right edge), random y.
5. If the chosen cell is occupied, re-roll (up to `tailleCarte * 2` attempts)
   (`player.php:569-572, 582-586`).
6. If all attempts fail, force-expand the map and place at a corner
   (`player.php:573-578, 587-593`).
7. Update `statistiques.tailleCarte` and `statistiques.nbDerniere`
   (`player.php:595`).

### 3.3 Distance Calculation

**Source:** `attaquer.php:38, 122, 366, 486`, `marche.php:89`

Distance between two points uses Euclidean distance:

```
distance = sqrt((x1 - x2)^2 + (y1 - y2)^2)
```

In PHP:
```php
$distance = pow(pow($membre['x'] - $joueur['x'], 2) + pow($membre['y'] - $joueur['y'], 2), 0.5);
```

### 3.4 Travel Time

All travel in the game follows the same base formula:

```
travel_time_seconds = distance / speed * 3600
```

Where `speed` is in cases (grid cells) per hour.

**Speed values by action type:**

| Action      | Speed                                              | Source                           |
|-------------|----------------------------------------------------|----------------------------------|
| Attack      | `vitesse(chlore, niveauChlore)` (varies per molecule) | `includes/formulas.php:149-152` |
| Espionage   | 20 cases/hr (`$vitesseEspionnage`, `ESPIONAGE_SPEED`) | `includes/constantesBase.php:48`, `includes/config.php:254` |
| Trade       | 20 cases/hr (`$vitesseMarchands`, `MERCHANT_SPEED`)   | `includes/constantesBase.php:45`, `includes/config.php:269` |

**Attack speed formula** (`includes/formulas.php:149-152`):
```php
function vitesse($chlore, $niveau) {
    return floor((1 + 0.5 * $chlore) * (1 + $niveau / 50) * 100) / 100;
}
```

Where `$chlore` is the number of chlorine atoms in the molecule and `$niveau`
is the condenseur level for chlorine. A molecule with 0 chlorine has a base
speed of 1 case/hr. Each chlorine atom adds 0.5 to the base speed, further
multiplied by the condenseur level bonus.

**Attack travel time uses the slowest molecule** (`attaquer.php:122-123`):
```php
$tempsTrajet = max($tempsTrajet, round($distance / vitesse($moleculesAttaque['chlore'], $niveauchlore) * 3600));
```

The attack fleet travels at the speed of its slowest molecule class.

**Espionage travel time** (`attaquer.php:38-39`):
```php
$distance = pow(pow($membre['x'] - $membreJoueur['x'], 2) + pow($membre['y'] - $membreJoueur['y'], 2), 0.5);
$tempsTrajet = round($distance / $vitesseEspionnage * 3600);
```

**Trade travel time** (`marche.php:89-94`):
```php
$distance = pow(pow($membre['x'] - $joueur['x'], 2) + pow($membre['y'] - $joueur['y'], 2), 0.5);
$tempsArrivee = time() + round(3600 * $distance / $vitesseMarchands);
```

---

## 4. Key Database Tables (Summary)

| Table          | Key Columns for This Document                                     |
|----------------|-------------------------------------------------------------------|
| `alliances`    | id, nom, tag, description, chef, duplicateur, energieAlliance, energieTotaleRecue, pointsVictoire, pointstotaux |
| `autre`        | login, idalliance, energieDonnee, totalPoints, victoires, tradeVolume, tempsPrecedent |
| `declarations` | id, type (0=war, 1=pact), alliance1, alliance2, timestamp, pertes1, pertes2, pertesTotales, fin, valide |
| `grades`       | login, grade (dot-separated permissions), idalliance, nom         |
| `invitations`  | id, idalliance, tag, invite (login of invited player)             |
| `membre`       | login, x, y, vacance, derniereConnexion, element, timestamp       |
| `vacances`     | id, idJoueur, dateDebut (CURRENT_DATE), dateFin                   |
| `statistiques` | inscrits, tailleCarte, nbDerniere, debut, maintenance             |
| `parties`      | id, timestamp, players_archive, alliances_archive, wars_archive   |
