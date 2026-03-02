# 05 - Economy

This document covers the full economic system in TVLW: the atom/energy market,
player-to-player resource transfers, alliance energy donations, and the
duplicateur alliance technology.

---

## 1. Market Trading

The market (`marche.php`) allows players to exchange energy for atoms (buying)
and atoms for energy (selling). All 8 atom types are traded independently, each
with its own price tracked in the `cours` table. Prices start around 1.0 energy
per atom and fluctuate based on player activity.

### 1.1 Price State

Prices are stored as a comma-separated string in `cours.tableauCours`, one
value per atom type. The most recent row is the current price set.

```
-- marche.php:10-11
$val = dbFetchOne($base, 'SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1');
$tabCours = explode(",", $val['tableauCours']);
```

### 1.2 Volatility

Volatility is inversely proportional to the number of active players (those
who logged in within the last 31 days). More players means smaller price swings.

```
-- marche.php:6-7
$actifs = dbFetchOne($base, 'SELECT count(*) AS nbActifs FROM membre
           WHERE derniereConnexion >= ?', 'i', (time() - 2678400));
$volatilite = 0.3 / $actifs['nbActifs'];
```

Note: The config constant `MARKET_VOLATILITY_FACTOR` is defined as `0.5`
(`includes/config.php:265`), but `marche.php:7` hardcodes `0.3` in the
divisor. This is a known inconsistency; the live value is `0.3 / nbActifs`.

| Active players | Volatility |
|----------------|------------|
| 1              | 0.300      |
| 10             | 0.030      |
| 50             | 0.006      |
| 100            | 0.003      |

### 1.3 Buying Atoms

A player spends energy to receive atoms. The purchased atoms must fit within
storage (`placeDepot`).

**File:** `marche.php:145-225`

**Steps:**

1. **Cost calculation** -- The energy cost is `round(currentPrice * quantity)`.
   ```
   -- marche.php:159
   $coutAchat = round($tabCours[$numRes] * $_POST['nombreRessourceAAcheter']);
   ```

2. **Validation** -- The player must have enough energy (`energie >= coutAchat`)
   and enough storage space for the new atoms (`currentAtoms + quantity <= placeDepot`).
   ```
   -- marche.php:161-164
   $diffEnergieAchat = $ressources['energie'] - $coutAchat;
   if ($diffEnergieAchat >= 0) {
       $newResVal = $ressources[$nomsRes[$numRes]] + $_POST['nombreRessourceAAcheter'];
       if ($newResVal > $placeDepot) { ... error ... }
   ```

3. **Price impact** -- Buying pushes the price up linearly:
   ```
   newPrice = oldPrice + (volatility * quantity / placeDepot)
   ```
   ```
   -- marche.php:183
   $ajout = $tabCours[$num] + $volatilite * $_POST['nombreRessourceAAcheter'] / $placeDepot;
   ```

4. **Mean reversion** -- After the raw price change, the price is pulled 1%
   toward the baseline of 1.0:
   ```
   newPrice = newPrice * (1 - MARKET_MEAN_REVERSION) + 1.0 * MARKET_MEAN_REVERSION
   ```
   ```
   -- marche.php:185
   $ajout = $ajout * (1 - MARKET_MEAN_REVERSION) + 1.0 * MARKET_MEAN_REVERSION;
   ```
   With `MARKET_MEAN_REVERSION = 0.01` (`includes/config.php:268`), this is:
   ```
   newPrice = newPrice * 0.99 + 0.01
   ```

5. **Clamping** -- The price is clamped to `[MARKET_PRICE_FLOOR, MARKET_PRICE_CEILING]`:
   ```
   -- marche.php:187
   $ajout = max(MARKET_PRICE_FLOOR, min(MARKET_PRICE_CEILING, $ajout));
   ```
   - Floor: `0.1` (`includes/config.php:266`)
   - Ceiling: `10.0` (`includes/config.php:267`)

6. **Record new prices** -- The updated price array is inserted as a new row
   in the `cours` table with the current timestamp.
   ```
   -- marche.php:194-195
   $now = time();
   dbExecute($base, 'INSERT INTO cours VALUES (default,?,?)', 'si', $chaine, $now);
   ```

7. **Trade points** -- Only buying awards trade points (see Section 1.5).

### 1.4 Selling Atoms

A player sells atoms to receive energy. Energy gained is capped at storage.

**File:** `marche.php:227-292`

**Steps:**

1. **Gain calculation** -- The energy gained is `round(currentPrice * quantity)`.
   ```
   -- marche.php:242
   $newEnergie = $ressources['energie'] + round($tabCours[$numRes] * $_POST['nombreRessourceAVendre']);
   ```

2. **Energy cap** -- If the resulting energy exceeds storage, it is capped:
   ```
   -- marche.php:243-244
   if ($newEnergie > $placeDepot) {
       $newEnergie = $placeDepot;
   }
   ```

3. **Price impact** -- Selling pushes the price down using a harmonic formula:
   ```
   newPrice = 1 / (1/oldPrice + volatility * quantity / placeDepot)
   ```
   ```
   -- marche.php:264
   $ajout = 1 / (1 / $tabCours[$num] + $volatilite * $_POST['nombreRessourceAVendre'] / $placeDepot);
   ```
   This harmonic form ensures the price can never go negative regardless of
   sell volume, which is an important asymmetry with the linear buy formula.

4. **Mean reversion** -- Same 1% pull toward 1.0 as buying:
   ```
   -- marche.php:266
   $ajout = $ajout * (1 - MARKET_MEAN_REVERSION) + 1.0 * MARKET_MEAN_REVERSION;
   ```

5. **Clamping** -- Same floor/ceiling as buying:
   ```
   -- marche.php:268
   $ajout = max(MARKET_PRICE_FLOOR, min(MARKET_PRICE_CEILING, $ajout));
   ```

6. **No trade points** -- Selling does NOT award trade points. This is an
   intentional anti-exploit measure to prevent players from cycling buy/sell
   to farm points.

### 1.5 Trade Points

Trade points reward active market participation and contribute to a player's
`totalPoints` ranking score.

**File:** `marche.php:198-210`

**Tracking:** Each buy accumulates the energy spent (`$coutAchat`) into
`autre.tradeVolume` for the player.

```
-- marche.php:199-202
$tradeVolume = $coutAchat;
$autreData = dbFetchOne($base, 'SELECT tradeVolume, totalPoints FROM autre WHERE login=?', ...);
$oldVolume = $autreData['tradeVolume'] ?? 0;
$newVolume = $oldVolume + $tradeVolume;
```

**Points formula:**
```
tradePoints = min(MARKET_POINTS_MAX, floor(MARKET_POINTS_SCALE * sqrt(tradeVolume)))
```
```
-- marche.php:203-204
$oldTradePoints = min(MARKET_POINTS_MAX, floor(MARKET_POINTS_SCALE * sqrt($oldVolume)));
$newTradePoints = min(MARKET_POINTS_MAX, floor(MARKET_POINTS_SCALE * sqrt($newVolume)));
```

**Constants** (`includes/config.php:273-274`):
- `MARKET_POINTS_SCALE = 0.05` -- sqrt scaling factor
- `MARKET_POINTS_MAX = 40` -- hard cap on trade points

The delta (`newTradePoints - oldTradePoints`) is added to `autre.totalPoints`.

**Example progression:**

| Cumulative energy spent | Trade points |
|-------------------------|--------------|
| 100                     | 0            |
| 1,000                   | 1            |
| 10,000                  | 5            |
| 100,000                 | 15           |
| 250,000                 | 25           |
| 640,000                 | 40 (cap)     |

---

## 2. Price Dynamics Summary

| Property        | Value / Formula                                         |
|-----------------|---------------------------------------------------------|
| Baseline price  | ~1.0 energy per atom                                    |
| Price floor     | 0.1 (`MARKET_PRICE_FLOOR`)                              |
| Price ceiling   | 10.0 (`MARKET_PRICE_CEILING`)                           |
| Mean reversion  | 1% pull toward 1.0 per trade (`MARKET_MEAN_REVERSION`)  |
| Volatility      | `0.3 / nbActivePlayers` (hardcoded in `marche.php:7`)   |
| Buy impact      | `+volatility * quantity / placeDepot` (linear, additive) |
| Sell impact     | `1 / (1/price + volatility * qty / depot)` (harmonic)    |

### Price Chart

The market page (`marche.php:372-374, 486-537`) displays a Google Charts line
graph of the last 1000 price entries from the `cours` table, one line per atom
type, color-coded by element.

```
-- marche.php:504
$ex = dbQuery($base, "SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1000");
```

---

## 3. Resource Transfers

Players can send resources (all 8 atom types plus energy) to another player.

**File:** `marche.php:20-143` (sub=1 form at `marche.php:338-370`)

### 3.1 Anti-Alt Protection

Transfers between players sharing the same IP address are blocked.

```
-- marche.php:25-28
$ipdd = dbFetchOne($base, 'SELECT ip FROM membre WHERE login=?', 's', $_POST['destinataire']);
$ipmm = dbFetchOne($base, 'SELECT ip FROM membre WHERE login=?', 's', $_SESSION['login']);
if ($ipmm['ip'] != $ipdd['ip']) { ... proceed ... }
else { $erreur = "Impossible d'envoyer des ressources a ce joueur. Meme adresse IP."; }
```

### 3.2 Receiving Rate (Production Ratio)

The amount actually received is reduced based on the ratio of the receiver's
production to the sender's production. If the receiver produces less than the
sender, they receive proportionally less. If the receiver produces the same or
more, they receive 100%.

**For energy:**
```
-- marche.php:62-66
if ($revenuEnergie >= revenuEnergie($constructionsJoueur['generateur'], $_POST['destinataire'])) {
    $rapportEnergie = revenuEnergie($constructionsJoueur['generateur'], $_POST['destinataire']) / $revenuEnergie;
} else {
    $rapportEnergie = 1;
}
```

**For each atom type:**
```
-- marche.php:69-75
if ($revenu[$ressource] >= revenuAtome($revenusJoueur[$num], $_POST['destinataire'])) {
    ${'rapport' . $ressource} = revenuAtome($revenusJoueur[$num], $_POST['destinataire']) / $revenu[$ressource];
} else {
    ${'rapport' . $ressource} = 1;
}
```

**General formula:**
```
ratio = min(1.0, receiver_production / sender_production)
received = sent * ratio
```

This means a weaker player sending to a stronger player transfers at full
rate, but a stronger player sending to a weaker player loses some resources
in transit. The mechanic prevents powerful players from directly boosting
weaker alt accounts.

### 3.3 Travel Time

Transfers are not instant. They travel at `MERCHANT_SPEED` (20 tiles/hour)
across the Euclidean distance between sender and receiver positions.

```
-- marche.php:89
$distance = pow(pow($membre['x'] - $joueur['x'], 2) + pow($membre['y'] - $joueur['y'], 2), 0.5);
```
```
-- marche.php:94
$tempsArrivee = time() + round(3600 * $distance / $vitesseMarchands);
```

**Formula:**
```
travelTime (seconds) = round(3600 * sqrt((x1-x2)^2 + (y1-y2)^2) / MERCHANT_SPEED)
```

Where `MERCHANT_SPEED = 20` (`includes/config.php:269`, `includes/constantesBase.php:45`).

| Distance (tiles) | Travel time    |
|-------------------|----------------|
| 1                 | 3 min          |
| 10                | 30 min         |
| 20                | 1 hr           |
| 50                | 2 hr 30 min    |
| 100               | 5 hr           |

### 3.4 Transfer Contents

A single transfer can include all 8 atom types and energy simultaneously. The
sent and received amounts are stored as semicolon-separated strings in
`actionsenvoi`.

```
-- marche.php:78-92
$ressourcesEnvoyees = '';  // "carbone;azote;...;iode;energy"
$ressourcesRecues = '';    // same format, with ratios applied
```

Pending transfers are displayed on the market page with a live countdown
timer (`marche.php:300-336`).

---

## 4. Alliance Energy Donations

Players can donate personal energy to their alliance's shared pool.

**File:** `don.php`

### 4.1 Donation Flow

1. Player submits an energy amount via the donation form (`don.php:56-60`).
2. The system verifies the player belongs to an alliance (`don.php:11-13`).
3. The system verifies the alliance exists (`don.php:14-16`).
4. The system verifies the player has enough energy (`don.php:19`).
5. Energy is deducted from `ressources.energie`:
   ```
   -- don.php:25-26
   $newEnergie = $ressources['energie'] - $_POST['energieEnvoyee'];
   dbExecute($base, 'UPDATE ressources SET energie=? WHERE login=?', ...);
   ```
6. The donated amount is tracked in `autre.energieDonnee`:
   ```
   -- don.php:27-28
   $newEnergieDonnee = $energieDonnee['energieDonnee'] + $_POST['energieEnvoyee'];
   dbExecute($base, 'UPDATE autre SET energieDonnee=? WHERE login=?', ...);
   ```
7. The alliance pool is updated in both `alliances.energieAlliance` (current
   balance, spendable) and `alliances.energieTotaleRecue` (lifetime total):
   ```
   -- don.php:29-31
   $newEnergieAlliance = $ressourcesAlliance['energieAlliance'] + $_POST['energieEnvoyee'];
   $newEnergieTotale = $ressourcesAlliance['energieTotaleRecue'] + $_POST['energieEnvoyee'];
   dbExecute($base, 'UPDATE alliances SET energieAlliance=?, energieTotaleRecue=? WHERE id=?', ...);
   ```

### 4.2 Tracking

- `autre.energieDonnee` -- per-player lifetime donation total
- `alliances.energieAlliance` -- current spendable alliance pool
- `alliances.energieTotaleRecue` -- lifetime total received by the alliance

The alliance energy pool is spent on upgrading the duplicateur (see Section 5).

---

## 5. Duplicateur (Alliance Technology)

The duplicateur is a shared alliance upgrade that boosts all members'
resource production and combat stats.

**File:** `alliance.php:74-90` (upgrade logic), `includes/formulas.php:70-73`
(bonus formula), `includes/config.php:279-284` (constants)

### 5.1 Upgrade Cost

The cost is paid from the alliance energy pool (`alliances.energieAlliance`).

**Formula:**
```
cost = round(DUPLICATEUR_BASE_COST * pow(DUPLICATEUR_COST_FACTOR, level + 1))
```
```
-- alliance.php:76
$cout = round(10 * pow(2.5, ($duplicateur['duplicateur'] + 1)));
```

**Constants** (`includes/config.php:280-281`):
- `DUPLICATEUR_BASE_COST = 10`
- `DUPLICATEUR_COST_FACTOR = 2.5`

**Cost table:**

| Level | Cost (alliance energy)                               |
|-------|------------------------------------------------------|
| 0->1  | `round(10 * 2.5^1)` = 25                            |
| 1->2  | `round(10 * 2.5^2)` = 63                             |
| 2->3  | `round(10 * 2.5^3)` = 156                            |
| 3->4  | `round(10 * 2.5^4)` = 391                            |
| 4->5  | `round(10 * 2.5^5)` = 977                            |
| 5->6  | `round(10 * 2.5^6)` = 2,441                          |
| 6->7  | `round(10 * 2.5^7)` = 6,104                          |
| 7->8  | `round(10 * 2.5^8)` = 15,259                         |
| 8->9  | `round(10 * 2.5^9)` = 38,147                         |
| 9->10 | `round(10 * 2.5^10)` = 95,367                        |

### 5.2 Upgrade Process

```
-- alliance.php:78-89
if (isset($_POST['augmenterDuplicateur'])) {
    csrfCheck();
    $energieAlliance = dbFetchOne($base, 'SELECT energieAlliance FROM alliances WHERE id=?', ...);
    if ($energieAlliance['energieAlliance'] >= $cout) {
        $newDup = $duplicateur['duplicateur'] + 1;
        $newEnergie = $energieAlliance['energieAlliance'] - $cout;
        dbExecute($base, 'UPDATE alliances SET duplicateur=?, energieAlliance=? WHERE id=?', ...);
    }
}
```

Any alliance member can trigger the upgrade as long as the pool has enough
energy. There is no level cap defined in code.

### 5.3 Effect: Production Bonus

The duplicateur grants `+level%` to ALL member resource production.

**Formula** (`includes/formulas.php:70-73`):
```php
function bonusDuplicateur($niveau) {
    return $niveau / 100;
}
```

This returns a decimal (e.g., level 3 returns 0.03 for a 3% bonus).

**Applied to energy production** (`includes/game_resources.php:24-28`):
```php
$bonusDuplicateur = 1;
if ($idalliance['idalliance'] > 0) {
    $duplicateur = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', ...);
    $bonusDuplicateur = 1 + bonusDuplicateur($duplicateur['duplicateur']);
}
// ... later:
$prodDuplicateur = $bonusDuplicateur * $prodMedaille;  // line 50
```

**Applied to atom production** (`includes/game_resources.php:74-81`):
```php
$bonusDuplicateur = 1;
if ($idalliance['idalliance'] > 0) {
    $duplicateur = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', ...);
    $bonusDuplicateur = 1 + bonusDuplicateur($duplicateur['duplicateur']);
}
return round($bonusDuplicateur * BASE_ATOMS_PER_POINT * $niveau);
```

### 5.4 Effect: Combat Bonus

The duplicateur also grants `+level%` to attack and defense in combat.

```
-- includes/config.php:234
define('DUPLICATEUR_COMBAT_COEFFICIENT', 1.0);
```

The combat bonus uses `duplicateur_level / 100`, matching the resource bonus.
The config comment notes this was previously bugged at `(0.1 * level) / 100`,
giving only 0.1% per level instead of the intended 1%.

### 5.5 Effect Summary

| Duplicateur Level | Production bonus | Combat bonus |
|-------------------|------------------|--------------|
| 0                 | +0%              | +0%          |
| 1                 | +1%              | +1%          |
| 2                 | +2%              | +2%          |
| 3                 | +3%              | +3%          |
| 5                 | +5%              | +5%          |
| 10                | +10%             | +10%         |

The production bonus applies multiplicatively to the base production chain
after medal bonuses but before producteur drain (for energy). For atoms, it
multiplies the final output directly.

### 5.6 Alliance Display

The alliance page (`alliance.php:241-258`) shows the duplicateur card with:
- Current level and effect percentages
- Next level effect preview
- Upgrade button with cost display
- Description explaining it is a collective building

---

## 6. Economy Flow Diagram

```
Energy ------> Buy Atoms -------> Molecules -------> Combat -------> Pillage
  |                 |                                                    |
  |-- Buildings     |-- Sell Atoms --> Energy                         Points
  |-- Neutrinos     |-- Resource Transfers (player to player)
  |                 |
  |                 +-- (storage limit: placeDepot)
  |
  +-- Alliance Pool ----> Duplicateur ----> +% Production (all members)
       (don.php)          (alliance.php)    +% Combat (all members)
```

### Detailed flow:

1. **Energy generation** -- Generateur building produces base energy per hour.
   Duplicateur bonus, medal bonus, and iode molecule production are added.
   Producteur drain is subtracted.

2. **Atom production** -- Producteur building + allocated points produce atoms
   per hour, multiplied by the duplicateur bonus.

3. **Market exchange** -- Energy can be converted to atoms (buying) or atoms
   to energy (selling) at market prices. Buying awards trade points.

4. **Molecule creation** -- Atoms are consumed to form molecules, which are
   the combat units.

5. **Combat** -- Molecules fight. Winners gain pillage resources and combat
   points. Losers have molecules destroyed.

6. **Alliance donations** -- Players donate energy to the alliance pool.
   The pool funds duplicateur upgrades, which benefit all members.

7. **Resource transfers** -- Players send atoms and energy directly to other
   players, subject to production ratio penalties and travel time.

---

## 7. Key Configuration Constants

All constants are defined in `includes/config.php` unless otherwise noted.

| Constant                   | Value  | Line | Description                           |
|----------------------------|--------|------|---------------------------------------|
| `MARKET_VOLATILITY_FACTOR` | 0.5    | 265  | Config value (live uses 0.3)          |
| `MARKET_PRICE_FLOOR`       | 0.1    | 266  | Minimum atom price                    |
| `MARKET_PRICE_CEILING`     | 10.0   | 267  | Maximum atom price                    |
| `MARKET_MEAN_REVERSION`    | 0.01   | 268  | 1% pull toward baseline per trade     |
| `MERCHANT_SPEED`           | 20     | 269  | Transfer speed (tiles/hour)           |
| `MARKET_POINTS_SCALE`      | 0.05   | 273  | Trade points sqrt scale factor        |
| `MARKET_POINTS_MAX`        | 40     | 274  | Trade points hard cap                 |
| `DUPLICATEUR_BASE_COST`    | 10     | 280  | Base cost for duplicateur formula     |
| `DUPLICATEUR_COST_FACTOR`  | 2.5    | 281  | Exponential growth factor             |
| `DUPLICATEUR_BONUS_PER_LEVEL` | 0.01 | 284 | 1% per level (resource production)   |
| `DUPLICATEUR_COMBAT_COEFFICIENT` | 1.0 | 234 | Combat bonus coefficient           |

---

## 8. Known Issues and Notes

1. **Volatility mismatch** -- `marche.php:7` hardcodes `0.3 / nbActifs` while
   `MARKET_VOLATILITY_FACTOR` is defined as `0.5`. The config constant is not
   actually used in the market code.

2. **Buy/sell asymmetry** -- The buy formula is linear (additive) while the
   sell formula is harmonic. This means buying has proportionally more price
   impact than selling for the same quantity, and a buy-then-sell cycle does
   not return the price to its starting point.

3. **Sell energy overflow** -- When selling atoms, energy gained is silently
   capped at `placeDepot` (`marche.php:243-244`). The atoms are still consumed,
   meaning the player can lose value if their storage is nearly full.

4. **No trade points for selling** -- This is documented as intentional
   (`marche.php:198` comment) to prevent buy-sell cycling for point farming.

5. **Transfer ratio per-resource** -- Each of the 8 atom types and energy has
   its own production ratio calculated independently. A player could have a
   favorable ratio for one atom type and unfavorable for another.

6. **Duplicateur has no level cap** -- The exponential cost curve is the only
   practical limit. At level 10 the cost is ~95,000 alliance energy.
