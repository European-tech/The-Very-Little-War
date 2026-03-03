# TVLW Market Economy -- Complete Cross-Domain Analysis

**Audit Round 3 | 2026-03-03**
**Scope:** marche.php, config.php, formulas.php, game_resources.php, game_actions.php, catalyst.php, player.php, constantesBase.php

---

## A. MARKET MECHANICS MAP

### A.1 BUY FLOW -- Step by Step

```
Player submits POST: typeRessourceAAcheter, nombreRessourceAAcheter
  |
  v
1. CSRF check (csrfCheck())
2. Input sanitization: intval(transformInt(POST))
3. Regex validation: /^[0-9]*$/
4. Resource name validation: loop nomsRes to find matching index ($numRes)
5. ADVISORY pre-check (stale data from page load):
   a. costAchat = round(tabCours[numRes] * quantity)
   b. Check: ressources['energie'] >= coutAchat
   c. Check: newResVal <= placeDepot
6. BEGIN TRANSACTION (withTransaction)
   a. SELECT energie, {resource} FROM ressources WHERE login=? FOR UPDATE
      -- Row-level lock acquired
   b. AUTHORITATIVE re-check: locked['energie'] >= coutAchat
   c. AUTHORITATIVE re-check: locked['res'] + quantity <= placeDepot
   d. UPDATE ressources SET energie=?, {resource}=? WHERE login=?
   e. PRICE UPDATE: Calculate new price for bought resource
      - newPrice = oldPrice + (volatility * quantity / placeDepot)
      - Apply mean reversion: price = price * (1 - MR) + 1.0 * MR
      - Clamp to [MARKET_PRICE_FLOOR, MARKET_PRICE_CEILING]
   f. INSERT INTO cours VALUES(default, newPriceString, time())
   g. TRADE POINTS: Calculate delta from sqrt-scaled energy spent
      - Read tradeVolume, totalPoints from autre
      - newVolume = oldVolume + round(coutAchat * reseauBonus)
      - pointsDelta = floor(SCALE * sqrt(newVolume)) - floor(SCALE * sqrt(oldVolume))
      - UPDATE autre SET tradeVolume, totalPoints
7. COMMIT
8. Log market buy event
9. Re-read latest cours for display refresh
```

**DB Operations inside transaction:** 1 SELECT FOR UPDATE + 1 UPDATE ressources + 1 INSERT cours + 1 SELECT autre + 1 UPDATE autre = **5 queries**

### A.2 SELL FLOW -- Step by Step

```
Player submits POST: typeRessourceAVendre, nombreRessourceAVendre
  |
  v
1. CSRF check
2. Input sanitization & regex validation (identical to buy)
3. Resource name validation against nomsRes
4. ADVISORY pre-check: ressources[resource] >= quantity
5. Pre-compute sellTaxRate = 0.95 (5% fee)
6. BEGIN TRANSACTION
   a. SELECT energie, {resource} FROM ressources WHERE login=? FOR UPDATE
   b. AUTHORITATIVE re-check: locked['res'] >= quantity
   c. energyGained = round(price * quantity * 0.95)
   d. newEnergie = min(locked['energie'] + energyGained, placeDepot)
   e. UPDATE ressources SET energie=?, {resource}=? WHERE login=?
   f. PRICE UPDATE: Calculate new price (inverse formula for selling)
      - newPrice = 1 / (1/oldPrice + volatility * quantity / placeDepot)
      - Apply mean reversion: price = price * (1 - MR) + 1.0 * MR
      - Clamp to [MARKET_PRICE_FLOOR, MARKET_PRICE_CEILING]
   g. INSERT INTO cours VALUES(default, newPriceString, time())
   h. TRADE POINTS: Same sqrt-scaled formula, based on energyGained
7. COMMIT
8. Log market sell event
9. Re-read latest cours for display refresh
```

**DB Operations inside transaction:** Same 5 as buy.

### A.3 SEND FLOW -- Step by Step

```
Player submits POST: energieEnvoyee, {resource}Envoyee (x8), destinataire
  |
  v
1. CSRF check
2. Input sanitization per resource + energy (transformInt, intval)
3. Regex validation per resource + energy
4. VALIDATION CHECKS (all pre-transaction, NO locks):
   a. IP anti-multiaccounting: sender IP != recipient IP
   b. Recipient exists: SELECT count FROM membre WHERE login=?
   c. Sender has enough of each resource
   d. Sender has enough energy
5. CALCULATE DELIVERY RATIO per resource:
   a. For each atom type: ratio = min(1.0, recipientRevenu / senderRevenu)
   b. For energy: ratio = min(1.0, recipientRevenuEnergie / senderRevenuEnergie)
   c. resourcesReceived = ratio * resourcesSent
6. CALCULATE TRAVEL TIME:
   a. distance = sqrt((x1-x2)^2 + (y1-y2)^2)
   b. arrivalTime = time() + round(3600 * distance / vitesseMarchands)
      -- vitesseMarchands = 20 (tiles per hour)
7. INSERT INTO actionsenvoi VALUES(id, sender, recipient, sentCSV, receivedCSV, arrivalTime)
8. UPDATE ressources: Deduct sent resources from sender (NO transaction!)
9. Display confirmation message

--- DELIVERY (in game_actions.php updateActions) ---

10. When tempsArrivee < time():
    a. DELETE FROM actionsenvoi WHERE id=?
    b. Parse sentCSV and receivedCSV
    c. Create delivery report for recipient
    d. SELECT recipient's current resources
    e. Cap each received amount at recipient's storage limit
    f. UPDATE ressources: Add received resources to recipient
```

**CRITICAL: The send flow has NO transaction wrapping the deduction + insertion.**

---

## B. PRICE FORMULA ANALYSIS

### B.1 Price Movement Formulas

**On Buy (demand increases price):**
```
newPrice = oldPrice + (volatility * quantity / placeDepot)
```

**On Sell (supply decreases price):**
```
newPrice = 1 / (1/oldPrice + volatility * quantity / placeDepot)
```

**Volatility:**
```
volatility = 0.3 / max(1, activePlayerCount)
```

Note: config.php defines MARKET_VOLATILITY_FACTOR = 0.5, but marche.php hardcodes 0.3.
This is a **config mismatch** -- the constant is defined but never used.

### B.2 Mean Reversion

After the raw price impact is calculated:
```
meanReversion = MARKET_MEAN_REVERSION * (1 + catalystEffect('market_convergence'))
newPrice = newPrice * (1 - meanReversion) + 1.0 * meanReversion
```

- Base mean reversion: 1% per trade (MARKET_MEAN_REVERSION = 0.01)
- With "Equilibre" catalyst active: 1% * (1 + 0.50) = 1.5% per trade
- Target equilibrium price: 1.0 energy per atom

### B.3 Price Bounds

```
MARKET_PRICE_FLOOR   = 0.1   (minimum: atoms cost 10% of baseline)
MARKET_PRICE_CEILING = 10.0  (maximum: atoms cost 10x baseline)
```

### B.4 Asymmetry Analysis

The buy and sell formulas are **fundamentally asymmetric**:

**Buy impact (additive):**
```
delta = volatility * quantity / placeDepot
newPrice = oldPrice + delta
```

**Sell impact (harmonic/reciprocal):**
```
newPrice = 1 / (1/oldPrice + delta)
         = oldPrice / (1 + oldPrice * delta)
```

For small delta relative to 1/oldPrice, these are approximately equivalent.
But at extreme prices, the behavior diverges:

| Starting Price | Buy 100 (depot=500) | Sell 100 (depot=500) |
|---------------|---------------------|----------------------|
| 1.0           | 1.0 + 0.06 = 1.06   | 1/(1+0.06) = 0.943  |
| 5.0           | 5.0 + 0.06 = 5.06   | 1/(0.2+0.06) = 3.85 |
| 0.2           | 0.2 + 0.06 = 0.26   | 1/(5+0.06) = 0.198  |

(Using volatility=0.3 for 1 active player, placeDepot=500)

**Finding B4-ASYM: The sell formula causes MUCH larger price drops at high prices than the buy formula causes increases.** At price=5.0, selling 100 atoms drops price to 3.85 (23% drop), while buying 100 atoms at price=1.0 only raises it by 6%. This creates a natural downward bias at high prices, which combines with mean reversion to make sustained high prices very difficult.

### B.5 Equilibrium Analysis

The system has a stable equilibrium at price = 1.0 due to:
1. Mean reversion explicitly pulls toward 1.0 every trade
2. The reciprocal sell formula resists price increases above 1.0
3. The additive buy formula pushes linearly away from any price

**With no trading activity:** Prices freeze (no time-based decay/reversion).
**With balanced buy/sell volume:** Prices oscillate near 1.0.
**With one-sided demand:** Price moves toward floor/ceiling but mean reversion dampens.

### B.6 Volatility Scaling with Player Count

| Active Players | Volatility | Price Impact of 100 atoms (depot=500) |
|---------------|-----------|---------------------------------------|
| 1             | 0.300     | +0.060 per trade                      |
| 5             | 0.060     | +0.012 per trade                      |
| 10            | 0.030     | +0.006 per trade                      |
| 50            | 0.006     | +0.0012 per trade                     |
| 100           | 0.003     | +0.0006 per trade                     |

With many active players, individual trades have negligible impact. The market becomes very stable but also very hard to move, reducing strategic value.

### B.7 `cours` Table Growth

Every single buy or sell inserts a new row into the `cours` table. The table is:
- **Never truncated on season reset** (remiseAZero does not touch it)
- Queried with `ORDER BY timestamp DESC LIMIT 1000` for chart display
- Indexed on timestamp (migration 0013)

**Finding B7-GROWTH: Over multiple seasons, the cours table grows without bound.** With even moderate activity (50 trades/day over a 30-day season), that is 1,500 rows per season. After 20 seasons: 30,000 rows. This is not catastrophic for MariaDB but is unnecessary bloat since historical prices across seasons are meaningless after reset.

---

## C. EXPLOIT ANALYSIS

### C.1 Buy-Sell Arbitrage for Trade Points

**Attack vector:** Buy atoms, then immediately sell them, earning trade points on both transactions.

**Current mitigation:** 5% sell tax means selling 100 atoms bought at price P yields:
- Buy cost: 100 * P energy
- Sell revenue: 100 * P' * 0.95 (where P' > P because buying raised the price)

**Analysis with numbers (1 active player, depot level 1 = 500 storage):**
```
Starting price: 1.0
Buy 100 atoms: cost = 100 energy
  New price = 1.0 + 0.3 * 100/500 = 1.06
  (after MR) = 1.06 * 0.99 + 1.0 * 0.01 = 1.0594

Sell 100 atoms: revenue = 100 * 1.0594 * 0.95 = 100.6 energy
  Net loss: 100 - 100.6 = +0.6 energy PROFIT

Trade points earned: floor(0.08 * sqrt(200.6)) - floor(0.08 * sqrt(0)) = 1 point
```

**Finding C1-ARBITRAGE: At low player counts with price near 1.0, buy-sell cycles can yield tiny energy PROFITS because the buy raises the price, and selling at the higher price with only 5% tax can exceed the buy cost.** The trade point farming is small (sqrt scaling caps returns), but the fact that it is net-positive in energy is a design flaw.

**Severity:** LOW -- the profit margin is tiny and diminishes as trade volume grows (sqrt scaling). The economic impact is negligible.

### C.2 Price Manipulation / Market Cornering

**Attack vector:** A player with large energy reserves buys massive quantities of one atom type, spiking the price, then sells at the inflated price.

**Analysis:**
```
Player has 10,000 energy, depot level 20 = 10,000 storage.
Starting price: 1.0
volatility = 0.3/10 = 0.03 (10 active players)

Buy 5,000 atoms: cost = 5,000 energy
  Price impact = 0.03 * 5000/10000 = +0.015
  New price (before MR) = 1.015
  After MR: 1.015 * 0.99 + 0.01 = 1.0149

Sell 5,000 atoms: revenue = 5000 * 1.0149 * 0.95 = 4,820.8
  Net loss: 5000 - 4820.8 = 179 energy lost
```

**Finding C2-CORNER: Market cornering is unprofitable.** The 5% sell tax, mean reversion, and the asymmetric sell formula (which drops price much more aggressively than buying raises it) all work against manipulators. The larger the attempted manipulation, the larger the percentage loss.

### C.3 Atom Laundering via Send System

**Attack vector:** Use the send mechanism to transfer resources between accounts (multi-accounting) while evading the IP check.

**Current mitigations:**
1. IP check: sender IP != recipient IP (line 28)
2. Revenue ratio: resources received = ratio * resources sent, where ratio = min(1, recipient_revenue / sender_revenue)

**Finding C3-LAUNDER-1: The IP check is trivially bypassed.** Any VPN, mobile network, or separate device defeats this. The check compares the stored IP from the `membre` table, which is the IP at registration/last login, not the current request IP. The comparison is:
```php
$ipdd = dbFetchOne('SELECT ip FROM membre WHERE login=?', recipient);
$ipmm = dbFetchOne('SELECT ip FROM membre WHERE login=?', sender);
if ($ipmm['ip'] != $ipdd['ip']) { // allow send }
```
This checks the DB-stored IPs, not `$_SERVER['REMOTE_ADDR']`. Two accounts created from different IPs but controlled by the same person can freely send resources.

**Finding C3-LAUNDER-2: The revenue ratio penalty is the real defense, but it has a flaw.** If the recipient has HIGHER production than the sender (e.g., a main account feeding a farm account), the ratio is capped at 1.0 and 100% of resources are received. The penalty only applies when a large account sends to a small account. A player could:
1. Create farm accounts
2. Build up resources on farm accounts (slow but free)
3. Send from farm accounts (high ratio) to main account
4. Receive 100% of resources because main account has higher production

**Finding C3-LAUNDER-3: No send quantity limits exist.** There is no daily cap on sends, no limit on number of sends per day, and no limit on total value sent. A player can drain an entire account in one send.

**Finding C3-LAUNDER-4: No audit trail for sends beyond the in-transit `actionsenvoi` record.** Once the shipment arrives and is processed, the `actionsenvoi` row is deleted. The only record is a report in the recipient's inbox, which can be deleted by the player. There is no persistent send log for admin review.

### C.4 Energy Laundering via Alliance Donations

**Attack vector:** Use `don.php` to donate energy from farm accounts to the alliance treasury, then use it for research that benefits the main account.

**Current mitigation:** The donation system (`don.php`) uses `withTransaction` and `FOR UPDATE` locks, so the race condition is handled. But there is:
- No limit on donation amount
- No limit on donation frequency
- No IP restriction on donations (unlike sends)

**Finding C4-DONATION: Alliance donations are a cleaner laundering channel than direct sends** because they bypass the IP check and revenue ratio penalty entirely. Farm accounts just donate energy to the alliance, which is used for research benefiting all members including the main account.

### C.5 Client-Side Price Injection

**Analysis:** The client-side JavaScript uses `tabCours` values from the server to calculate costs:
```javascript
var echange = <?php echo json_encode($tabCours); ?>
coutEnergie = Math.round(nombreRessourceAAcheter * echange[numAchat]);
```

But the actual cost calculation on the server re-reads prices from DB at transaction time:
```php
$coutAchat = round($tabCours[$numRes] * $_POST['nombreRessourceAAcheter']);
```

**Wait -- this uses $tabCours from line 11, read BEFORE the transaction.** Inside the transaction, the price used for the actual cost calculation is the PRE-TRANSACTION $tabCours, not a fresh read. The transaction only re-checks energy/storage balances, not the current price.

**Finding C5-STALE-PRICE: The buy/sell price is calculated using $tabCours read at page load time (line 10-11), NOT inside the transaction.** If another player's trade changes the price between page load and form submission, the buyer/seller uses the stale price. This is actually advantageous for the player who submits later if the price moved unfavorably (they pay the old price), and disadvantageous if it moved favorably. This is a minor fairness issue rather than an exploit.

---

## D. RACE CONDITION MAP

### D.1 Buy Operation -- PROTECTED

```
CONCURRENT ACCESS POINT: Two players buying the same resource simultaneously

Protection: withTransaction + SELECT FOR UPDATE on ressources row
- Each player locks their OWN ressources row
- No inter-player lock contention (different rows)
- Price update: Both insert new cours rows -- POSSIBLE ISSUE

RACE CONDITION D1-PRICE: Two concurrent buys both read the same $tabCours
before entering the transaction. Both calculate the SAME new price and
insert it. The second insert overwrites the first's price effect.

Scenario:
  T0: Player A reads cours: price = 1.0
  T1: Player B reads cours: price = 1.0
  T2: Player A's transaction: calculates new price 1.06, inserts cours
  T3: Player B's transaction: calculates new price 1.06, inserts cours
  Result: Price is 1.06 instead of the correct ~1.12 (two buys compounded)

Impact: Price movements are UNDER-COUNTED when trades happen concurrently.
This benefits traders (they pay less than they should) and destabilizes
the price mechanism's ability to reflect true demand.
```

### D.2 Sell Operation -- PROTECTED (same pattern as buy)

Same race condition as D1 applies to sells. Two concurrent sells both read the same price and both calculate the sell price independently, losing one price movement.

### D.3 Send Operation -- UNPROTECTED

```
RACE CONDITION D3-SEND: The send deduction is NOT inside a transaction.

Code path (marche.php lines 99-115):
  1. Read resources (line 51, pre-check)
  2. Validate sender has enough resources
  3. INSERT into actionsenvoi (line 95-96)
  4. UPDATE ressources: deduct from sender (lines 99-115)

No transaction, no FOR UPDATE lock.

Scenario:
  T0: Player reads resources: 1000 carbone
  T1: Player opens market page in two tabs
  T2: Tab 1 submits: send 800 carbone to Player B
  T3: Tab 2 submits: send 800 carbone to Player C
  T4: Both pass the pre-check (1000 >= 800)
  T5: Tab 1 deducts: 1000 - 800 = 200 carbone
  T6: Tab 2 deducts: 200 - 800 = -600 carbone (NEGATIVE RESOURCES!)

Wait -- actually checking line 106 more carefully:
  $chaine = $ressource . '=' . ($ressources[$ressource] - $_POST[$ressource . 'Envoyee'])

The UPDATE uses a COMPUTED value (server-side subtraction using the stale
$ressources read), not an atomic SQL decrement like `carbone = carbone - ?`.
So Tab 2 would set carbone = 1000 - 800 = 200 (using the stale value),
overwriting Tab 1's deduction.

ACTUAL RESULT: Player sends 800 to Player B AND 800 to Player C but only
loses 800 total (second write overwrites first). NET DUPLICATION of 800 atoms.

Severity: CRITICAL -- Resource duplication exploit.
```

### D.4 Send Delivery -- UNPROTECTED

```
RACE CONDITION D4-DELIVERY: Resource delivery in game_actions.php is also
not transactional.

Code path (game_actions.php lines 471-539):
  1. SELECT actionsenvoi WHERE tempsArrivee < time()
  2. DELETE the actionsenvoi row
  3. SELECT recipient's current resources
  4. UPDATE recipient's resources (add received)

If two requests process the same delivery simultaneously:
  - Both could add resources to the recipient (double delivery)
  - The DELETE is not guarded by an atomic CAS check

However, updateActions uses a static $updating guard per player,
and the DELETE happens before the ADD, so the second request would
find no row to process. This is a WEAK guard (not bulletproof
against true concurrency) but makes exploitation harder in practice.

Severity: MEDIUM -- Theoretically possible but requires precise timing.
```

### D.5 Price + Trade Points -- PARTIAL PROTECTION

```
RACE CONDITION D5-TRADEPOINTS: Inside the transaction, trade points are
calculated by reading tradeVolume and totalPoints, then computing the new
values. But this read is NOT protected by FOR UPDATE.

Code (buy, line 215):
  $autreData = dbFetchOne($base, 'SELECT tradeVolume, totalPoints FROM autre WHERE login=?', 's', $_SESSION['login']);

This is inside the withTransaction block, so it runs within the same
InnoDB transaction, but without FOR UPDATE. If two transactions for the
same player (unlikely but possible with concurrent tabs) both read the
same tradeVolume, one update overwrites the other.

Severity: LOW -- Trade points are cosmetic ranking points, and concurrent
trades by the same player are rare.
```

### D.6 Volatility Calculation -- UNPROTECTED

```
The volatility is calculated ONCE at page load (line 7):
  $volatilite = 0.3 / max(1, $actifs['nbActifs']);

This reads active player count from the membre table. If a player registers
or goes inactive during the page session, the volatility used for subsequent
trades is stale. This is not exploitable but causes minor inaccuracies.

Severity: INFORMATIONAL
```

### Summary of Race Conditions

| ID | Component | Protection | Severity |
|----|-----------|-----------|----------|
| D1-PRICE | Concurrent buy price update | NONE -- lost price updates | MEDIUM |
| D2-PRICE | Concurrent sell price update | NONE -- lost price updates | MEDIUM |
| D3-SEND | Send resource deduction | NONE -- resource duplication | **CRITICAL** |
| D4-DELIVERY | Send delivery processing | WEAK (static guard) | MEDIUM |
| D5-TRADEPOINTS | Trade points accumulation | PARTIAL (in tx, no FOR UPDATE) | LOW |
| D6-VOLATILITY | Volatility staleness | N/A | INFORMATIONAL |

---

## E. ECONOMIC BALANCE ANALYSIS

### E.1 Resource Generation Rates

**Energy production:**
```
revenuEnergie = BASE_ENERGY_PER_LEVEL * generateur_level  (= 75 * level)
  + iode molecule production
  + medal bonus
  + duplicateur bonus
  + prestige bonus
  - producteur drain (PRODUCTEUR_DRAIN_PER_LEVEL * producteur_level = 8 * level)
```

**Atom production (per type):**
```
revenuAtome = duplicateur_bonus * BASE_ATOMS_PER_POINT * producteur_points_for_atom * prestige_bonus
  = (1 + dup_level/100) * 60 * points * prestige
```

**Storage capacity:**
```
placeDepot = BASE_STORAGE_PER_LEVEL * depot_level = 500 * level
```

### E.2 Market Price Impact vs Production Rates

At equilibrium (price = 1.0), buying 1 atom costs 1 energy.

**Energy-to-atom conversion efficiency via market:**
- Buy: 1 energy -> 1 atom (at equilibrium)
- Sell: 1 atom -> 0.95 energy (5% tax)
- Round-trip loss: 5%

**Direct production comparison:**
- 1 generateur level produces 75 energy/hour
- 1 producteur point produces 60 atoms/hour (of chosen type)
- But producteur drains 8 energy per producteur level

At generateur level 10, producteur level 5:
- Energy: 750/hour - 40 drain = 710/hour net
- Atoms: 60 * points_per_type / hour (depends on point allocation)
- Via market: 710 energy/hour * 1.0 price = 710 atoms/hour buyable

**Finding E2-ECON: The market is most useful when:**
1. A player over-produces one atom type and needs another
2. Energy is abundant but specific atoms are scarce
3. The price of the desired atom is below 1.0 (discount)

### E.3 Can the Market Be Cornered?

**Cornering = accumulating enough of one resource to control its price.**

**Defense mechanisms:**
1. The 5% sell tax makes round-trip trading unprofitable
2. Mean reversion pulls prices back to 1.0
3. The reciprocal sell formula creates diminishing returns on price pumping
4. Price ceiling of 10.0 caps maximum price
5. Production is unlimited (resources regenerate every hour), so artificial scarcity cannot be maintained

**Scenario: Player tries to corner Carbon (carbone)**
```
Player buys ALL available carbon from market repeatedly.

Problem: There is no "available supply" on the market. The market is not
an order book. It is an INFINITE LIQUIDITY virtual market maker.

Any player can buy ANY quantity at the current price (if they have energy).
Any player can sell ANY quantity at the current price (if they have atoms).

The market has INFINITE supply and INFINITE demand. You cannot corner it
because there is nothing to corner. Price is the only variable, and it
is bounded by floor/ceiling and pulled by mean reversion.
```

**Finding E3-CORNER: The market CANNOT be cornered** because it is a virtual market maker with infinite liquidity. This is by design but has the side effect that the market is less strategically interesting than an order-book system.

### E.4 Production-Consumption Balance

**Major resource sinks:**
1. Building construction (energy + atoms)
2. Molecule formation (atoms consumed to create molecules)
3. Market buy (energy -> atoms, energy consumed)
4. Alliance donations (energy only)
5. Building destruction via combat (indirect)
6. 5% market sell tax (energy destroyed)

**Major resource sources:**
1. Generateur (energy production, continuous)
2. Producteur (atom production, continuous, drains energy)
3. Market sell (atoms -> energy)
4. Pillaging (steal resources from other players -- zero-sum)
5. Resource sends (transfer between players -- reduced by revenue ratio)
6. Iode molecules (energy production from military units)

**Key insight:** The only true resource SINKS (where resources leave the economy entirely) are:
1. The 5% market sell tax
2. Building construction costs (energy/atoms consumed)
3. The revenue ratio on sends (resources lost in transit)

**Key insight:** Resources are generated continuously and infinitely. There is no resource scarcity mechanic beyond storage limits. The game economy is inherently **inflationary** -- total resources in the economy grow without bound.

**Finding E4-INFLATION: The economy has no meaningful deflationary pressure.** The 5% market tax is the only resource destruction mechanism that scales with activity. Building costs are one-time. This means late-game players accumulate vast resources with nowhere to spend them, and the market becomes less relevant because everyone is producing enough of everything.

### E.5 Market Points Balance

```
Points = min(80, floor(0.08 * sqrt(totalTradeVolume)))
```

**To reach maximum 80 trade points:**
```
80 = 0.08 * sqrt(volume)
sqrt(volume) = 1000
volume = 1,000,000 energy
```

One million energy of total trade volume to max trade points.

**At generateur level 10 (750 energy/hour gross):**
- ~1,333 hours of pure energy production = ~55 days of continuous play
- With market cycling, faster, but the 5% tax burns energy

**Finding E5-TRADEPOINTS: Trade points are well-balanced for a monthly season.** Reaching the cap of 80 requires sustained trading activity throughout the season. The sqrt scaling prevents early-game spamming from reaching the cap.

### E.6 Send System Economic Impact

**Revenue ratio formula:**
```
For each resource type:
  ratio = min(1.0, recipient_revenue[type] / sender_revenue[type])

Resources received = resources_sent * ratio
```

This means:
- Equal-sized players: 100% transfer efficiency
- Large -> Small: Reduced (proportional to size difference)
- Small -> Large: 100% efficiency

**Finding E6-SEND: The revenue ratio creates a perverse incentive.** It is more efficient to have a larger account RECEIVE from a smaller account than the reverse. This is backwards from the intended anti-abuse purpose. Farm accounts should be penalized for SENDING to mains, not for receiving. The current system makes it optimal to:
1. Build up farm accounts independently
2. Send from farms to the main (high ratio because main is bigger)
3. Never send from main to farms (low ratio, wastes resources)

### E.7 Cross-Season Price Persistence

**Finding E7-PERSISTENCE: The `cours` table is never cleared on season reset** (function `remiseAZero` does not TRUNCATE or DELETE from `cours`). This means:

1. The chart on the market page shows prices from PREVIOUS seasons
2. The most recent price entry carries over, so the starting price of a new season is NOT 1.0 -- it is whatever the last trade of the previous season set it to
3. This creates an unfair advantage for players who know the starting prices and can buy underpriced atoms immediately

This needs one of two fixes:
- Option A: Add `DELETE FROM cours` to remiseAZero and insert a baseline row with all prices = 1.0
- Option B: Filter chart query by season start timestamp

---

## F. ADDITIONAL FINDINGS

### F.1 Config Constant Mismatch

```php
// config.php line 360:
define('MARKET_VOLATILITY_FACTOR', 0.5);

// marche.php line 7:
$volatilite = 0.3 / max(1, $actifs['nbActifs']);
```

The config constant `MARKET_VOLATILITY_FACTOR` is defined as 0.5 but the actual code hardcodes 0.3. The constant is never referenced. This was noted in previous audit rounds but remains unfixed.

**Fix:** `$volatilite = MARKET_VOLATILITY_FACTOR / max(1, $actifs['nbActifs']);` and set the constant to 0.3 if the current behavior is desired, or 0.5 if the config value is intended.

### F.2 No Post-Transaction Price Preview

The client-side JavaScript calculates costs based on current prices, but does not show the player what the price WILL BE after their trade. A player buying 1,000 atoms sees the cost at the current price, but the actual price impact could be significant.

**Fix:** Add JavaScript to calculate and display the post-trade price alongside the cost.

### F.3 Chart Has No Timestamps

```javascript
// Line 587:
$tot = '["",' . $cours['tableauCours'] . ']' . $fin . $tot;
```

The X-axis label is hardcoded to empty string `""`. The `timestamp` column exists in the database and is selected but never displayed. Players cannot tell WHEN price changes occurred.

**Fix:** Use `date('H:i', $cours['timestamp'])` or similar for X-axis labels.

### F.4 In-Transit Shipments Show No Detail

```php
// Lines 385-388: Only show direction icon, player name, and countdown
echo '<tr><td><img src="images/rapports/envoi.png".../>
      </td><td><a href="joueur.php?id=' . $safeReceveur . '">' . $safeReceveur . '</a>
      </td><td id="affichage' . $actionsenvoi['id'] . '">' ...
```

The `actionsenvoi` table stores `ressourcesEnvoyees` and `ressourcesRecues` as semicolon-delimited strings, but the in-transit display never shows WHAT is being sent. Players cannot see the contents of their pending shipments.

**Fix:** Parse and display `$actionsenvoi['ressourcesEnvoyees']` alongside the transit entry.

### F.5 No Rate Limiting on Market Operations

Unlike login/registration which have rate limiting (includes/rate_limiter.php), market operations have no rate limiting. A player could submit thousands of buy/sell requests programmatically.

**Impact:** Combined with the stale-price race condition (D1/D2), rapid automated trading could exploit price staleness to accumulate tiny profits at scale.

### F.6 Trade Points Awarded on Both Buy AND Sell

Trade points are awarded on both buy transactions (line 213-225) and sell transactions (line 326-338). Since the 5% tax makes round-tripping barely unprofitable, a player can farm trade points by cycling buy/sell with minimal energy loss. The sqrt scaling mitigates this (diminishing returns), but there is no cooldown or anti-cycling mechanism.

### F.7 Negative Resource Possible via Send Bug

At line 106-107:
```php
$chaine = $ressource . '=' . ($ressources[$ressource] - $_POST[$ressource . 'Envoyee']);
```

If the race condition in D3 fires, `$ressources[$ressource]` is the stale value from the initial read, not the current DB value. The computed value could go negative because there is no `max(0, ...)` guard.

---

## G. SEVERITY SUMMARY

### CRITICAL

| ID | Finding | Description |
|----|---------|-------------|
| D3-SEND | Send race condition | Resource duplication via concurrent sends (no transaction, no lock, stale read-then-write) |

### HIGH

| ID | Finding | Description |
|----|---------|-------------|
| E7-PERSISTENCE | Cross-season price carry | cours table never reset, creates unfair starting conditions |
| C3-LAUNDER-1 | IP check bypass | Stored IP comparison trivially defeated by VPN/separate devices |
| C3-LAUNDER-3 | No send limits | Unlimited sends per day, no value cap, enables unrestricted transfers |
| C3-LAUNDER-4 | No send audit trail | Delivery records deleted after processing, no persistent log |

### MEDIUM

| ID | Finding | Description |
|----|---------|-------------|
| D1/D2-PRICE | Concurrent trade price loss | Simultaneous trades lose price impact (both read same stale price) |
| D4-DELIVERY | Delivery race condition | Weak guard against double-delivery |
| F1 | Config mismatch | MARKET_VOLATILITY_FACTOR=0.5 unused, code hardcodes 0.3 |
| C3-LAUNDER-2 | Revenue ratio backwards | Penalizes sending TO small accounts, not FROM small accounts |
| E6-SEND | Perverse incentive | Farm-to-main transfers more efficient than main-to-farm |

### LOW

| ID | Finding | Description |
|----|---------|-------------|
| C1-ARBITRAGE | Tiny buy-sell profit | Net-positive energy on buy-sell cycle at low player counts |
| B4-ASYM | Price formula asymmetry | Sell formula causes larger moves than buy at high prices |
| B7-GROWTH | cours table bloat | Never truncated across seasons, grows indefinitely |
| F2 | No price preview | Players cannot see post-trade price impact |
| F3 | No chart timestamps | X-axis shows empty strings instead of timestamps |
| F4 | No send detail | In-transit shipments show no cargo contents |
| F5 | No market rate limit | Automated rapid trading possible |
| F6 | Dual trade point award | Points earned on both buy and sell enables cycling |

### INFORMATIONAL

| ID | Finding | Description |
|----|---------|-------------|
| D6 | Volatility staleness | Player count read once at page load |
| E4-INFLATION | Inflationary economy | No meaningful resource sinks beyond 5% tax |
| C5-STALE-PRICE | Stale price for cost | Buy/sell cost uses page-load price, not transactional price |

---

## H. RECOMMENDED FIXES (Priority Order)

### 1. [CRITICAL] Fix Send Race Condition (D3-SEND)

Wrap the send deduction in a transaction with FOR UPDATE:

```php
withTransaction($base, function() use ($base, $nomsRes, ...) {
    $locked = dbFetchOne($base,
        'SELECT * FROM ressources WHERE login=? FOR UPDATE',
        's', $_SESSION['login']);

    // Re-validate all amounts against locked values
    foreach ($nomsRes as $num => $ressource) {
        if ($locked[$ressource] < $_POST[$ressource . 'Envoyee']) {
            throw new Exception('NOT_ENOUGH_RESOURCES');
        }
    }
    if ($locked['energie'] < $_POST['energieEnvoyee']) {
        throw new Exception('NOT_ENOUGH_ENERGY');
    }

    // Use atomic SQL decrement
    // Build UPDATE with parameterized decrements
    $sets = ['energie = energie - ?'];
    $types = 'd';
    $params = [$_POST['energieEnvoyee']];
    foreach ($nomsRes as $num => $ressource) {
        $sets[] = "$ressource = $ressource - ?";
        $types .= 'd';
        $params[] = $_POST[$ressource . 'Envoyee'];
    }
    $params[] = $_SESSION['login'];
    $types .= 's';
    dbExecute($base,
        'UPDATE ressources SET ' . implode(',', $sets) . ' WHERE login=?',
        $types, ...$params);

    // Insert actionsenvoi inside the same transaction
    dbExecute($base, 'INSERT INTO actionsenvoi ...', ...);
});
```

### 2. [HIGH] Reset cours table on season reset

Add to `remiseAZero()`:
```php
dbExecute($base, 'DELETE FROM cours');
$baseline = implode(',', array_fill(0, count($nomsRes), '1'));
dbExecute($base, 'INSERT INTO cours VALUES(default, ?, ?)', 'si', $baseline, time());
```

### 3. [HIGH] Add persistent send audit log

Create a `send_log` table that is NOT cleared on season reset:
```sql
CREATE TABLE send_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender VARCHAR(255) NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    resources_sent TEXT NOT NULL,
    resources_received TEXT NOT NULL,
    timestamp INT NOT NULL,
    INDEX idx_sender (sender),
    INDEX idx_recipient (recipient),
    INDEX idx_timestamp (timestamp)
);
```

Insert a log entry when the send is initiated AND when it is delivered.

### 4. [MEDIUM] Fix price staleness in concurrent trades (D1/D2)

Inside the buy/sell transaction, re-read the latest cours row:
```php
// Inside withTransaction, before price calculation:
$latestCours = dbFetchOne($base,
    'SELECT tableauCours FROM cours ORDER BY timestamp DESC LIMIT 1 FOR UPDATE');
$tabCours = explode(",", $latestCours['tableauCours']);
```

The `FOR UPDATE` on the cours table would serialize concurrent price updates. This adds latency but ensures correctness.

### 5. [MEDIUM] Use config constant for volatility

```php
$volatilite = MARKET_VOLATILITY_FACTOR / max(1, $actifs['nbActifs']);
```

And update the constant value to 0.3 if that is the desired behavior.

### 6. [LOW] Add send limits

- Daily send limit per player (configurable in config.php)
- Maximum total value per send
- Cooldown between sends to same recipient

---

## I. ARCHITECTURE NOTES

### Market Type: Virtual Market Maker

The TVLW market is a **virtual market maker** (VMM), not an order book. Key properties:
- Infinite liquidity: any quantity can be bought or sold at any time
- Deterministic pricing: price is a function of trade history, not of standing orders
- No counterparty: players trade against the system, not each other
- Single price: there is no bid-ask spread (except the 5% sell tax, which acts as spread)

This is the simplest possible market design and appropriate for a small-player-count game. An order book would be more strategically interesting but requires a larger player base to provide liquidity.

### Price State Machine

```
Initial state: All prices = 1.0 (baseline)
          |
          v
   +----------------+
   |  Buy event     |-----> Price += volatility * qty / depot
   +----------------+       + mean reversion toward 1.0
          |                  + clamp [0.1, 10.0]
          v
   +----------------+
   |  Sell event    |-----> Price = 1/(1/P + vol*qty/dep)
   +----------------+       + mean reversion toward 1.0
          |                  + clamp [0.1, 10.0]
          v
   +----------------+
   |  No activity   |-----> Price FROZEN (no time decay)
   +----------------+
          |
          v
   +----------------+
   |  Season reset  |-----> Price CARRIES OVER (bug: should reset)
   +----------------+
```

---

*End of Market Economy Cross-Domain Analysis*
*Files analyzed: marche.php, includes/config.php, includes/formulas.php, includes/game_resources.php, includes/game_actions.php, includes/catalyst.php, includes/player.php, includes/constantesBase.php, includes/constantes.php, includes/basicprivatephp.php, includes/prestige.php, includes/database.php, includes/db_helpers.php, don.php*
