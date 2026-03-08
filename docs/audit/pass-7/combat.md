# Pass 7 Audit — COMBAT Domain
**Date:** 2026-03-08
**Agent:** Pass7-B1-COMBAT

## Summary
| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 4 |
| MEDIUM | 2 |
| LOW | 2 |
| **Total** | **8** |

---

## HIGH Findings

### HIGH-001 — Formation type not range-validated
**File:** `includes/combat.php:142`
**Description:** Formation value is cast to `int` but never validated to be within `[0, 2]`. If a corrupted DB row has `formation=999`, the subsequent `if/elseif` branches may skip silently, leaving no formation applied.
**Fix:**
```php
$defenderFormation = isset($constructionsDef['formation']) ? intval($constructionsDef['formation']) : FORMATION_DISPERSEE;
if ($defenderFormation < 0 || $defenderFormation > 2) {
    $defenderFormation = FORMATION_DISPERSEE;
    logError("Combat: invalid formation ID for " . $actions['defenseur']);
}
```

### HIGH-002 — getSpecModifier() return not type-validated
**File:** `includes/combat.php:187-194`
**Description:** Four calls to `getSpecModifier()` assume return is numeric. If the function returns `null` or a non-numeric string (e.g., due to a DB lookup returning no row), the multiplication `$degatsAttaquant *= (1 + $specAttackMod)` produces unexpected results silently.
**Fix:**
```php
$specAttackMod      = (float)($specAttackMod ?? 0.0);
$specDefenseMod     = (float)($specDefenseMod ?? 0.0);
// ... etc
```

### HIGH-003 — catalystEffect() return not type-validated
**File:** `includes/combat.php:163, 198, 453`
**Description:** Three calls to `catalystEffect()` assume return is numeric. Same risk as HIGH-002.
**Fix:**
```php
$catalystAttackBonus  = 1 + (float)(catalystEffect('attack_bonus') ?? 0.0);
$catalystPillageBonus = 1 + (float)(catalystEffect('pillage_bonus') ?? 0.0);
```

### HIGH-004 — Compound bonus columns accessed without null-safety on legacy rows
**File:** `includes/combat.php:181-184`
**Description:** `$actions['compound_atk_bonus']`, `$actions['compound_def_bonus']`, `$actions['compound_pillage_bonus']` are accessed directly. Pre-migration attack records may not have these columns. PHP 8.2 emits warnings on undefined array keys, which could appear in logs or be exposed if error display is on.
**Fix:**
```php
$compoundAttackBonus  = (float)($actions['compound_atk_bonus'] ?? 0.0);
$compoundDefenseBonus = (float)($actions['compound_def_bonus'] ?? 0.0);
$compoundPillageBonus = (float)($actions['compound_pillage_bonus'] ?? 0.0);
```

---

## MEDIUM Findings

### MEDIUM-001 — Vault capacity return not validated
**File:** `includes/combat.php:435-443`
**Description:** `capaciteCoffreFort()` result is used directly without checking for null/negative. If the function returns null (unexpected DB state), the `max(0, $ressourcesDefenseur[$ressource] - $vaultProtection)` calculation fails silently.
**Fix:**
```php
$vaultProtection = capaciteCoffreFort($vaultLevel, $depotDefLevel);
if (!is_numeric($vaultProtection) || $vaultProtection < 0) {
    $vaultProtection = 0;
    logError("Combat: invalid vault protection for " . $actions['defenseur']);
}
```

### MEDIUM-002 — Null coalesce inconsistency in casualty array accesses
**File:** `includes/combat.php:362`
**Description:** Line 362 does `$classeDefenseur[$i]['nombre'] - $defenseurMort[$i]` without null coalesce, while earlier accesses at lines 323, 333 use `($defenseurMort[$ci] ?? 0)`. If `$defenseurMort[$i]` was never set, PHP 8.2 emits a warning.
**Fix:**
```php
$defenseursRestants += $classeDefenseur[$i]['nombre'] - ($defenseurMort[$i] ?? 0);
```

---

## LOW Findings

### LOW-001 — Player fetch for report display outside transaction context
**File:** `attaque.php:19`
**Description:** Minor style issue — player fetch for display is done outside the transaction context. No correctness or security impact.

### LOW-002 — Report HTML filtering uses regex instead of DOMDocument
**File:** `rapports.php:32-37`
**Description:** `strip_tags()` + regex approach is functional but DOMDocument would be more robust. Not exploitable.

---

## Verified Clean

- **Overkill cascade** (combat.php:203-359): complex but correct.
- **Formation branching**: all 3 formations (Dispersée, Phalange, Embuscade) handled correctly.
- **Vault protection math** (line 443): safe with GREATEST guards.
- **Pillage tax** (line 471): correct.
- **Building damage distribution** (lines 530-564): weighted random targeting correct.
- **Combat point scaling** (line 678): sqrt formula correct.
- **Self-attack prevention** (attaquer.php:96): correct.
- **Zero-troops check** (attaquer.php:154-160): comprehensive.
- **Compound bonus snapshotting** (attaquer.php:204-207): prevents TOCTOU.
- **Molecule availability re-check in TX** (attaquer.php:224-230): FOR UPDATE guard present.
- **Combat CAS guard** (game_actions.php:119): prevents double-processing.
- **Access control on rapports.php** (line 20): `destinataire` validated in query.
- **Negative resources** (combat.php:762): `max(0, ...)` guard present.
- **Storage cap on pillage** (combat.php:745): `min($maxStorage, ...)` guard present.
