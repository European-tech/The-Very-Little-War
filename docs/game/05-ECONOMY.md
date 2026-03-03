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

The config constant `MARKET_VOLATILITY_FACTOR` is `0.3` (`includes/config.php:350`),
and `marche.php:7` uses this constant:
```
$volatilite = MARKET_VOLATILITY_FACTOR / max(1, $actifs['nbActifs']);
```

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

3. **Price impact** -- Buying pushes the price up linearly, scaled by a
   global economy divisor (not the player's storage):
   ```
   newPrice = oldPrice + volatility * quantity / MARKET_GLOBAL_ECONOMY_DIVISOR
   ```
   ```
   -- marche.php:202
   $ajout = $tabCours[$num] + $volatilite * $_POST['nombreRessourceAAcheter'] / MARKET_GLOBAL_ECONOMY_DIVISOR;
   ```
   With `MARKET_GLOBAL_ECONOMY_DIVISOR = 10000` (`includes/config.php:140`),
   the price impact is independent of any individual player's storage capacity.
   This ensures uniform market behavior regardless of depot level.

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

7. **Trade points** -- Both buying and selling award trade points (see Section 1.5).

### 1.4 Selling Atoms

A player sells atoms to receive energy. The quantity actually sold is limited
to what fits in the player's remaining energy storage, so excess atoms are not
consumed.

**File:** `marche.php:260-383`

**Steps:**

1. **Overflow protection** -- Before executing the trade, the system calculates
   the maximum number of atoms that can be sold without exceeding storage:
   ```
   -- marche.php:294-303
   $energySpace = $placeDepot - $locked['energie'];
   $pricePerAtom = $tabCours[$numRes] * $sellTaxRate;
   $maxSellable = floor($energySpace / $pricePerAtom);
   $actualSold = min($_POST['nombreRessourceAVendre'], $maxSellable, $locked['res']);
   ```
   If storage is already full (`$energySpace <= 0`), the sell is rejected. Only
   `$actualSold` atoms are consumed -- the remainder stays in the player's
   inventory.

2. **Gain calculation** -- The energy gained is based on the actual quantity sold:
   ```
   -- marche.php:306
   $energyGained = round($tabCours[$numRes] * $actualSold * $sellTaxRate);
   ```
   The sell tax rate is `MARKET_SELL_TAX_RATE = 0.95` (5% fee).

3. **Price impact** -- Selling pushes the price down using a harmonic formula,
   scaled by the same global economy divisor as buying:
   ```
   newPrice = 1 / (1/oldPrice + volatility * actualSold / MARKET_GLOBAL_ECONOMY_DIVISOR)
   ```
   ```
   -- marche.php:329
   $ajout = 1 / (1 / $tabCours[$num] + $volatilite * $actualSold / MARKET_GLOBAL_ECONOMY_DIVISOR);
   ```
   This harmonic form ensures the price can never go negative regardless of
   sell volume, which is an important asymmetry with the linear buy formula.
   Note: the quantity used is `$actualSold` (the amount actually sold after
   overflow protection), not the raw requested quantity.

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

6. **Trade points** -- Selling now awards trade points, mirroring the buy
   logic (see Section 1.5). The 5% sell tax is sufficient to prevent
   buy-sell cycling for point farming.

### 1.5 Trade Points

Trade points reward active market participation and contribute to a player's
`totalPoints` ranking score. Both buying and selling award trade points.

**File:** `marche.php:217-230` (buy), `marche.php:344-357` (sell)

**Tracking:** Each trade accumulates energy volume into `autre.tradeVolume`.
For buys, volume = energy spent (`$coutAchat`). For sells, volume = energy
gained (`$energyGained`). Alliance Reseau research boosts volume by
`+5% * reseauLevel`.

```
-- marche.php:218-222
$reseauBonus = 1 + allianceResearchBonus($_SESSION['login'], 'trade_points');
$tradeVolume = round($coutAchat * $reseauBonus);
$autreData = dbFetchOne($base, 'SELECT tradeVolume, totalPoints FROM autre WHERE login=?', ...);
$oldVolume = $autreData['tradeVolume'] ?? 0;
$newVolume = $oldVolume + $tradeVolume;
```

**Points formula:**
```
tradePoints = min(MARKET_POINTS_MAX, floor(MARKET_POINTS_SCALE * sqrt(tradeVolume)))
```
```
-- marche.php:223-224
$oldTradePoints = min(MARKET_POINTS_MAX, floor(MARKET_POINTS_SCALE * sqrt($oldVolume)));
$newTradePoints = min(MARKET_POINTS_MAX, floor(MARKET_POINTS_SCALE * sqrt($newVolume)));
```

**Constants** (`includes/config.php:360-361`):
- `MARKET_POINTS_SCALE = 0.08` -- sqrt scaling factor
- `MARKET_POINTS_MAX = 80` -- hard cap on trade points

The delta (`newTradePoints - oldTradePoints`) is added to `autre.totalPoints`.

**Example progression:**

| Cumulative energy traded | Trade points |
|--------------------------|--------------|
| 100                      | 0            |
| 1,000                    | 2            |
| 10,000                   | 8            |
| 100,000                  | 25           |
| 500,000                  | 56           |
| 1,000,000                | 80 (cap)     |

---

## 2. Price Dynamics Summary

| Property        | Value / Formula                                                          |
|-----------------|--------------------------------------------------------------------------|
| Baseline price  | ~1.0 energy per atom                                                     |
| Price floor     | 0.1 (`MARKET_PRICE_FLOOR`)                                              |
| Price ceiling   | 10.0 (`MARKET_PRICE_CEILING`)                                           |
| Mean reversion  | 1% pull toward 1.0 per trade (`MARKET_MEAN_REVERSION`)                  |
| Volatility      | `MARKET_VOLATILITY_FACTOR / nbActivePlayers` = `0.3 / nbActivePlayers`  |
| Buy impact      | `+volatility * quantity / MARKET_GLOBAL_ECONOMY_DIVISOR` (linear)        |
| Sell impact     | `1 / (1/price + volatility * qty / MARKET_GLOBAL_ECONOMY_DIVISOR)` (harmonic) |
| Economy divisor | `MARKET_GLOBAL_ECONOMY_DIVISOR = 10000` (global scale, not per-player)   |
| Sell tax        | 5% (`MARKET_SELL_TAX_RATE = 0.95`)                                       |
| Sell overflow   | Quantity auto-limited to fit remaining energy storage; excess atoms kept  |

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

### 3.2 Receiving Rate (Production Ratio) -- V4 Inverted

The transfer ratio has been **inverted** in V4 to prevent alt-account feeding
(small alt sending resources to a larger main account). The new rule:

- Sending to a **smaller** player (lower production): **full rate** (charity is OK)
- Sending to a **bigger** player (higher production): **penalized** (prevents alt feeding)

If the receiver produces MORE than the sender, the ratio is reduced:

**For energy:**
```
-- marche.php:63-68
$receiverEnergyRev = revenuEnergie($constructionsJoueur['generateur'], $_POST['destinataire']);
if ($receiverEnergyRev > $revenuEnergie) {
    $rapportEnergie = min(1.0, $revenuEnergie / max(1, $receiverEnergyRev));
} else {
    $rapportEnergie = 1;
}
```

**For each atom type:**
```
-- marche.php:70-77
$receiverAtomRev = revenuAtome($num, $_POST['destinataire']);
if ($receiverAtomRev > $revenu[$ressource]) {
    ${'rapport' . $ressource} = min(1.0, $revenu[$ressource] / max(1, $receiverAtomRev));
} else {
    ${'rapport' . $ressource} = 1;
}
```

**General formula:**
```
if receiver_production > sender_production:
    ratio = min(1.0, sender_production / receiver_production)
else:
    ratio = 1.0 (full transfer)
received = sent * ratio
```

This means a weaker alt-account sending to a stronger main account has its
transfer heavily penalized, while a stronger player helping a weaker player
transfers at full rate. The mechanic specifically targets the alt-feeding
exploit where players create disposable accounts to funnel resources to
their main.

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
(bonus formula), `includes/config.php:367-371` (constants)

### 5.1 Upgrade Cost

The cost is paid from the alliance energy pool (`alliances.energieAlliance`).

**Formula:**
```
cost = round(DUPLICATEUR_BASE_COST * pow(DUPLICATEUR_COST_FACTOR, level + 1))
```
```
-- alliance.php:76
$cout = round(DUPLICATEUR_BASE_COST * pow(DUPLICATEUR_COST_FACTOR, ($duplicateur['duplicateur'] + 1)));
```

**Constants** (`includes/config.php:367-368`):
- `DUPLICATEUR_BASE_COST = 100`
- `DUPLICATEUR_COST_FACTOR = 1.5`

**Cost table:**

| Level | Cost (alliance energy)                               |
|-------|------------------------------------------------------|
| 0->1  | `round(100 * 1.5^1)` = 150                          |
| 1->2  | `round(100 * 1.5^2)` = 225                           |
| 2->3  | `round(100 * 1.5^3)` = 338                           |
| 3->4  | `round(100 * 1.5^4)` = 506                           |
| 4->5  | `round(100 * 1.5^5)` = 759                           |
| 5->6  | `round(100 * 1.5^6)` = 1,139                         |
| 6->7  | `round(100 * 1.5^7)` = 1,709                         |
| 7->8  | `round(100 * 1.5^8)` = 2,563                         |
| 8->9  | `round(100 * 1.5^9)` = 3,844                         |
| 9->10 | `round(100 * 1.5^10)` = 5,767                        |

The reduced cost factor (1.5 instead of 2.5) makes levels 10-12 achievable
within a 31-day season.

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

| Constant                         | Value  | Description                                   |
|----------------------------------|--------|-----------------------------------------------|
| `MARKET_VOLATILITY_FACTOR`       | 0.3    | Divided by active players for volatility       |
| `MARKET_PRICE_FLOOR`             | 0.1    | Minimum atom price                             |
| `MARKET_PRICE_CEILING`           | 10.0   | Maximum atom price                             |
| `MARKET_MEAN_REVERSION`          | 0.01   | 1% pull toward baseline per trade              |
| `MARKET_GLOBAL_ECONOMY_DIVISOR`  | 10000  | Global scale for price impact (V4)             |
| `MARKET_SELL_TAX_RATE`           | 0.95   | 5% fee on sell revenue                         |
| `MERCHANT_SPEED`                 | 20     | Transfer speed (tiles/hour)                    |
| `MARKET_POINTS_SCALE`            | 0.08   | Trade points sqrt scale factor                 |
| `MARKET_POINTS_MAX`              | 80     | Trade points hard cap                          |
| `DUPLICATEUR_BASE_COST`          | 100    | Base cost for duplicateur formula              |
| `DUPLICATEUR_COST_FACTOR`        | 1.5    | Exponential growth factor                      |
| `DUPLICATEUR_BONUS_PER_LEVEL`    | 0.01   | 1% per level (resource production)             |
| `DUPLICATEUR_COMBAT_COEFFICIENT` | 1.0    | Combat bonus coefficient                       |

---

## 8. Known Issues and Notes

1. **Buy/sell asymmetry** -- The buy formula is linear (additive) while the
   sell formula is harmonic. This means buying has proportionally more price
   impact than selling for the same quantity, and a buy-then-sell cycle does
   not return the price to its starting point.

2. **V4 sell overflow fix** -- Selling now properly caps the quantity sold to
   what fits in remaining energy storage. Excess atoms are NOT consumed. This
   replaces the old behavior where atoms were consumed even when energy was
   silently capped at storage.

3. **Both buy and sell award trade points** -- The 5% sell tax prevents
   buy-sell cycling exploits while allowing active traders to earn points
   through either operation.

4. **Transfer ratio per-resource** -- Each of the 8 atom types and energy has
   its own production ratio calculated independently. A player could have a
   favorable ratio for one atom type and unfavorable for another.

5. **V4 transfer ratio inversion** -- The direction of the penalty was
   reversed. Old: stronger-to-weaker penalized (prevent alt boosting). New:
   weaker-to-stronger penalized (prevent alt feeding to main). Big players
   helping small players now transfer at full rate.

6. **Duplicateur has no level cap** -- The exponential cost curve is the only
   practical limit. With `DUPLICATEUR_BASE_COST = 100` and
   `DUPLICATEUR_COST_FACTOR = 1.5`, level 10 costs ~5,767 alliance energy.
