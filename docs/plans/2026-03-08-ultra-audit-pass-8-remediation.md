# Ultra Audit Pass 8 — Remediation Plan
**Date:** 2026-03-08
**Findings:** 1 CRITICAL, 9 HIGH, 12 MEDIUM, 4 LOW (26 total)
<!-- NOTIF-HIGH-001 (event handler regex in rapports.php) reclassified to LOW: image field is 100% server-controlled, no practical exploitability -->
**Next migration:** `0091`

---

## Summary of Findings and Batches

| Batch | Severity | Findings | Theme |
|-------|----------|----------|-------|
| A | CRITICAL + HIGH | MARKET-CRIT-001, AUTH-HIGH-001, INFRA-SEC-HIGH-001, INFRA-DB-HIGH-001, ANTI-CHEAT-HIGH-001 | Market iode bug, account deletion, session init, DB exceptions, multiaccount |
| B | HIGH | ADMIN-HIGH-001, ADMIN-HIGH-002, SEASON-HIGH-001, ESPIONAGE-HIGH-001, COMBAT-HIGH-001 | Admin CSP, prestige idempotency, espionage notify, combat defense |
| C | MEDIUM | FORUM-MED-001, FORUM-MED-002, BUILDINGS-MED-001, COMPOUNDS-MED-001, MAPS-MED-001 | Forum rate limit, wrong table, building snapshot, compound check, MAP_SIZE |
| D | MEDIUM | MAPS-MED-002, ECONOMY-MED-001, ALLIANCE-MED-001, ALLIANCE-MED-002, ALLIANCE-MED-003 | (0,0) coords, energy floor, alliance cooldown, grade TOCTOU, war winner column |
| E | MEDIUM + LOW | NOTIF-MED-001, NOTIF-MED-002, SOCIAL-LOW-001, NOTIF-LOW-001, INFRA-TPL-LOW-001 | Report ID cast, email queue default, flash messages, alt text, CSP data: |

---

## Batch A — CRITICAL + HIGH: Market, Auth, Infra

### Task A-1: MARKET-CRIT-001 — Iode cost is always 1 energy
**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php`
**Also:** `/home/guortates/TVLW/The-Very-Little-War/includes/constantesBase.php`

**Root cause:** `$nbRes = count($nomsRes) - 1 = 7`. The 8-element `$RESOURCE_NAMES` array has indices 0-7, but iode is at index 7. `array_slice($txTabCours, 0, 7)` drops index 7, so `$txTabCours[7]` is `null` inside the transaction. Cost = `max(1, null * qty) = 1` energy for any amount of iode.

**Fix steps:**

1. In `constantesBase.php`, change:
   ```php
   $nbRes = count($nomsRes)-1;
   ```
   to:
   ```php
   $nbRes = count($nomsRes); // 8 atoms: C,N,H,O,Cl,S,Br,I (indices 0-7)
   ```

2. In `marche.php`, in the transaction closure, change:
   ```php
   $txTabCours = array_slice($txTabCours, 0, $nbRes);
   ```
   to:
   ```php
   $txTabCours = array_slice($txTabCours, 0, count($nomsRes));
   ```

3. Update the integrity assertion to match `count($nomsRes)` not `$nbRes`.

4. Fix fallback initialization if it uses `$nbRes + 1` — change to `count($nomsRes)`.

5. Audit the sell path for the same `array_slice` call.

6. **Verification:** Buy 1 iode; confirm energy cost equals `$tabCours[7] * 1` (not always 1).

---

### Task A-2: AUTH-HIGH-001 — Account deletion bypasses 7-day cooldown on POST
**File:** `/home/guortates/TVLW/The-Very-Little-War/compte.php`
**Lines:** 8-10

**Fix:** Re-check the cooldown inside the POST handler before calling `supprimerJoueur()`:

```php
if (isset($_POST['verification']) and isset($_POST['oui'])) {
    // AUTH-HIGH-001: Re-check 7-day cooldown on POST path.
    $memberTimestamp = dbFetchOne($base, 'SELECT timestamp FROM membre WHERE login = ?', 's', $_SESSION['login']);
    if (!$memberTimestamp || (time() - (int)$memberTimestamp['timestamp']) <= SECONDS_PER_WEEK) {
        $erreur = "Le compte ne peut être supprimé qu'au bout d'une semaine.";
    } else {
        supprimerJoueur($_SESSION['login']);
        header("Location: deconnexion.php"); exit;
    }
}
```

---

### Task A-3: INFRA-SEC-HIGH-001 — session_name() overwrites mod/admin session names
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/session_init.php`
**Line:** 16

**Fix:** Only set if not already customized:

```php
// INFRA-SEC-HIGH-001: Only set session name if not already customized by the calling page.
if (session_name() === 'PHPSESSID') {
    session_name('TVLW_SESSION');
}
```

---

### Task A-4: INFRA-DB-HIGH-001 — Uncaught mysqli exceptions can leak schema info
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/connexion.php`

**Fix:** Add a global exception handler after the `mysqli_report()` call:

```php
set_exception_handler(function(\Throwable $e) {
    if ($e instanceof \mysqli_sql_exception) {
        error_log('DB Exception [' . $e->getCode() . ']: ' . $e->getMessage());
        http_response_code(500);
        die('<h1>Erreur interne</h1><p>Une erreur de base de données s\'est produite. Veuillez réessayer.</p>');
    }
    throw $e;
});
```

Add comment in `database.php` noting the `if (!$stmt)` branches are dead code under STRICT mode but kept as documentation.

---

### Task A-5: ANTI-CHEAT-HIGH-001 — Manual flag accepts non-existent login
**File:** `/home/guortates/TVLW/The-Very-Little-War/admin/multiaccount.php`
**Lines:** 55-66

**Fix:** Add DB existence check before flagging:

```php
if (!empty($manualLogin) && !empty($manualRelated)) {
    $loginExists   = dbCount($base, 'SELECT COUNT(*) FROM membre WHERE login = ?', 's', $manualLogin);
    $relatedExists = dbCount($base, 'SELECT COUNT(*) FROM membre WHERE login = ?', 's', $manualRelated);
    if (!$loginExists || !$relatedExists) {
        logWarn('ADMIN', "Manual flag rejected: player not found", ['login' => $manualLogin, 'related' => $manualRelated]);
    } else {
        // ... existing INSERT logic
    }
}
```

---

## Batch B — HIGH: Admin CSP, Prestige Idempotency, Espionage, Combat

### Task B-1: ADMIN-HIGH-001 — Missing CSP headers in admin pages
**Files:**
- `/home/guortates/TVLW/The-Very-Little-War/admin/listesujets.php`
- `/home/guortates/TVLW/The-Very-Little-War/admin/supprimerreponse.php`

**Fix:** After the last `require_once` at the top of each file, add:

```php
require_once(__DIR__ . '/../includes/csp.php');
$nonce = cspNonce();
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self'; frame-ancestors 'none'; form-action 'self';");
```

Add `nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>"` to any inline `<style>` blocks.

---

### Task B-2: ADMIN-HIGH-002 — Incomplete CSP on moderation pages
**Files:**
- `/home/guortates/TVLW/The-Very-Little-War/moderation/index.php` line 12
- `/home/guortates/TVLW/The-Very-Little-War/moderation/ip.php`

**Fix for moderation/index.php:** Replace existing CSP header with complete one including `frame-ancestors 'none'; form-action 'self';` and remove `data:` from img-src.

**Fix for moderation/ip.php:** Add `require_once('../includes/csp.php');`, generate nonce, and emit full CSP header.

---

### Task B-3: SEASON-HIGH-001 — Prestige idempotency flag outside transaction
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/prestige.php`
**Line:** 176

**Fix:** Move the `UPDATE statistiques SET prestige_awarded_season = ?` call INSIDE the `withTransaction()` callback:

```php
if (!empty($awards)) {
    withTransaction($base, function() use ($base, $awards, $currentSeason) {
        foreach ($awards as $award) {
            dbExecute($base,
                'INSERT INTO prestige (login, total_pp) VALUES (?, ?) ON DUPLICATE KEY UPDATE total_pp = total_pp + ?',
                'sii', $award['login'], $award['pp'], $award['pp']
            );
        }
        // SEASON-HIGH-001: Idempotency flag inside transaction — atomic with award grants.
        dbExecute($base, 'UPDATE statistiques SET prestige_awarded_season = ?', 'i', $currentSeason);
    });
} else {
    // No players to award but still mark the season.
    dbExecute($base, 'UPDATE statistiques SET prestige_awarded_season = ?', 'i', $currentSeason);
}
```

Remove the standalone line 176 that previously ran this UPDATE outside the transaction.

---

### Task B-4: ESPIONAGE-HIGH-001 — Defenders never notified of successful espionage
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php`
**Lines:** 492-497

**Root cause:** Inside the SUCCESS branch, the condition `if ($espionageThreshold >= $espActions['nombreneutrinos'])` is ALWAYS FALSE (we're already in the branch where threshold < nombreneutrinos).

**Fix:** Remove the inverted condition; always send notification on success:

```php
// ESPIONAGE-HIGH-001: Notify defender unconditionally on espionage success.
// Previous condition was inverted, making this dead code.
$titreRapportEspionDef   = 'Tentative d\'espionnage détectée';
$contenuRapportEspionDef = '<p>Un agent inconnu a espionné votre base. Vos défenses, ressources et compositions moléculaires ont été observées.</p>';
dbExecute($base,
    'INSERT INTO rapports (timestamp, titre, contenu, destinataire, type) VALUES(?, ?, ?, ?, ?)',
    'issss', $espActions['tempsAttaque'], $titreRapportEspionDef, $contenuRapportEspionDef, $espActions['defenseur'], 'defense'
);
```

---

### Task B-5: COMBAT-HIGH-001 — Defense boost amplifies counter-damage instead of reducing incoming damage
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php`
**Lines:** 190-191

**Fix:** Remove the counter-damage multiplier. Instead, divide attacker damage by the defense boost factor BEFORE the defender casualty loop:

```php
// COMBAT-HIGH-001: Defense boost reduces attacker's effective damage, not boost defender counter-damage.
// Placed after all attacker damage calculations, before the defender casualty loop.
if ($compoundDefenseBonus > 0) {
    $degatsAttaquant /= (1.0 + $compoundDefenseBonus);
}
// Remove: if ($compoundDefenseBonus > 0) $degatsDefenseur *= (1 + $compoundDefenseBonus);
```

---

## Batch C — MEDIUM: Forum, Buildings, Compounds, Maps

### Task C-1: FORUM-MED-001 — Rate limiter args swapped and return value discarded in editer.php
**File:** `/home/guortates/TVLW/The-Very-Little-War/editer.php`
**Line:** 99

**Fix:**
```php
// FORUM-MED-001: Correct arg order (identifier first, action second) and check return value.
if (!rateLimitCheck($_SESSION['login'], 'forum_edit', 10, 300)) {
    $erreur = "Trop de modifications. Veuillez patienter avant de modifier à nouveau.";
}
```

---

### Task C-2: FORUM-MED-002 — UPDATE targets membre.nbMessages (column doesn't exist; should be autre)
**Files:**
- `/home/guortates/TVLW/The-Very-Little-War/admin/listesujets.php` line 48
- `/home/guortates/TVLW/The-Very-Little-War/admin/supprimerreponse.php` line 15

**Fix in both files:** Change `UPDATE membre SET nbMessages` to `UPDATE autre SET nbMessages`.

---

### Task C-3: BUILDINGS-MED-001 — $constructions snapshot stale during multi-building damage loop
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php`
**Line:** 533

**Fix:** Track which buildings have already been damaged in this combat resolution:

```php
$alreadyDamaged = [];
// Before each diminuerBatiment() call:
if (!isset($alreadyDamaged[$building])) {
    diminuerBatiment($building, $actions['defenseur']);
    $alreadyDamaged[$building] = true;
}
```

Also verify `diminuerBatiment()` uses `GREATEST(1, level - 1)` as a floor guard.

---

### Task C-4: COMPOUNDS-MED-001 — activateCompound() doesn't check UPDATE affected rows
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/compounds.php`
**Lines:** 204-207

**Fix:** Check affected rows and throw if 0:

```php
$affected = dbExecute($base,
    'UPDATE player_compounds SET activated_at = ?, expires_at = ? WHERE id = ?',
    'iii', $now, $now + $duration, $compoundId
);
// COMPOUNDS-MED-001: If 0 rows updated, compound was already activated or not found.
if ($affected === 0) {
    throw new \RuntimeException('COMPOUND_NOT_FOUND');
}
```

---

### Task C-5: MAPS-MED-001 — MAP_SIZE undefined; uses hardcoded fallback of 100
**Files:**
- `/home/guortates/TVLW/The-Very-Little-War/includes/config.php`
- `/home/guortates/TVLW/The-Very-Little-War/includes/resource_nodes.php` line 133

**Fix:** Add to `config.php`:
```php
define('MAP_SIZE', 200); // MAPS-MED-001: ceiling for resource node bounds-checking
```

In `resource_nodes.php` line 133:
```php
$mapBound = defined('MAP_SIZE') ? MAP_SIZE : 200;
```

---

## Batch D — MEDIUM: Maps (0,0), Economy floor, Alliance issues

### Task D-1: MAPS-MED-002 — Coordinate (0,0) allowed; player invisible on map
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`
**Lines:** 828-856

**Fix:** Add guard after coordinate is assigned but before return:

```php
// MAPS-MED-002: (0,0) is reserved as "empty" sentinel; force minimum to (1,1).
if ($x === 0 && $y === 0) { $x = 1; $y = 1; }
$x = max(1, $x);
$y = max(1, $y);
```

---

### Task D-2: ECONOMY-MED-001 — ajouter() energy can go negative
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/db_helpers.php`
**Line:** 49

**Fix:** Always use GREATEST(0, ...) for resource decrements:

```php
// ECONOMY-MED-001: Prevent resource columns going below zero.
dbExecute($base, "UPDATE $bdd SET $champ = GREATEST(0, $champ + ?) WHERE login=?", $bindType . 's', $nombre, $joueur);
```

---

### Task D-3: ALLIANCE-MED-001 — supprimerAlliance() sets alliance_left_at=NULL, bypassing cooldown
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`
**Line:** ~948

**Fix:** Change NULL to UNIX_TIMESTAMP():
```php
// ALLIANCE-MED-001: Use UNIX_TIMESTAMP() so 24h rejoin cooldown applies after dissolution.
dbExecute($base, 'UPDATE autre SET idalliance=0, alliance_left_at=UNIX_TIMESTAMP() WHERE idalliance=?', 'i', $alliance);
```

---

### Task D-4: ALLIANCE-MED-002 — Grade creation TOCTOU: count check and INSERT not in transaction
**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`
**Lines:** 94-125

**Fix:** Wrap check + INSERT in `withTransaction()` with FOR UPDATE lock. Also create migration:

**Migration `migrations/0091_grades_unique_name_per_alliance.sql`:**
```sql
-- ALLIANCE-MED-002: Ensure grade name is unique per alliance for DB-level TOCTOU guard.
ALTER TABLE grades ADD UNIQUE INDEX IF NOT EXISTS idx_grade_name_alliance (idalliance, nom);
```

---

### Task D-5: ALLIANCE-MED-003 — War winner column may not exist in declarations table
**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`
**Line:** ~407

**Fix:** Create migration to add the column:

**Migration `migrations/0092_declarations_add_winner_column.sql`:**
```sql
-- ALLIANCE-MED-003: Add winner column to declarations if not present.
ALTER TABLE declarations
    ADD COLUMN IF NOT EXISTS winner TINYINT NOT NULL DEFAULT 0
        COMMENT '0=draw,1=alliance1,2=alliance2';
```

---

## Batch E — MEDIUM + LOW: Notifications, Flash Messages, Cosmetic

### Task E-1: NOTIF-MED-001 — Report ID not cast to int before echo
**File:** `/home/guortates/TVLW/The-Very-Little-War/rapports.php`
**Line:** 125

**Fix:** Add `(int)` cast: `value="'.(int)$rapports['id'].'"`.

---

### Task E-2: NOTIF-MED-002 — Email queue created_at has no DEFAULT
**Migration `migrations/0093_email_queue_created_at_default.sql`:**
```sql
-- NOTIF-MED-002: Add DEFAULT 0 to created_at to prevent INSERT failures if omitted.
ALTER TABLE email_queue MODIFY COLUMN created_at INT NOT NULL DEFAULT 0;
```

---

### Task E-3: SOCIAL-LOW-001 — Broadcast success messages not stored in flash session
**File:** `/home/guortates/TVLW/The-Very-Little-War/ecriremessage.php`
**Lines:** 39, 58

**Fix:** Store in `$_SESSION['flash_message']` before redirect (PRG pattern) or ensure `$information` is displayed in the current HTML output path.

---

### Task E-4: NOTIF-LOW-001 — Alt text "supprimer" non-descriptive
**File:** `/home/guortates/TVLW/The-Very-Little-War/rapports.php`
**Line:** 125

**Fix:** Change `alt="supprimer"` to `alt="Supprimer ce rapport"`.

---

### Task E-5: INFRA-TPL-LOW-001 — data: URI in CSP img-src not needed
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/layout.php`
**Line:** 15

**Fix:** Remove `data:` from img-src directive. First verify no PHP file generates `src="data:image/..."`. Apply same removal to `moderation/index.php` (covered by Task B-2).

---

## Migration Files Required

| Migration | Task | Description |
|-----------|------|-------------|
| `0091_grades_unique_name_per_alliance.sql` | D-4 | UNIQUE index on grades(idalliance, nom) |
| `0092_declarations_add_winner_column.sql` | D-5 | winner TINYINT on declarations |
| `0093_email_queue_created_at_default.sql` | E-2 | DEFAULT 0 on email_queue.created_at |

---

## Deployment Sequence

1. **Batch A first** — MARKET-CRIT-001 (iode exploit) must be fixed immediately.
2. **Batch B** — All HIGH security fixes.
3. **Run migrations** 0091-0093 on VPS before Batch D.
4. **Batches C-D-E** — together after migrations.
5. **Full test suite:** `php phpunit.phar --testdox` — 663 tests / 3651 assertions must pass.
6. **Smoke tests after deploy:**
   - `marche.php` — buy iode, verify cost > 1 energy
   - `compte.php` — new account delete POST must fail cooldown check
   - `moderation/index.php` — verify full CSP header with frame-ancestors
   - `admin/listesujets.php` — verify CSP header present
   - `rapports.php` — verify alt text updated
