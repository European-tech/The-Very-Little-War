# TVLW Dead Code, Unused Features, and Unreachable Code — Round 3 Cross-Domain Inventory

**Audit Date:** 2026-03-03
**Scope:** includes/copyright.php, includes/bbcode.php, includes/config.php, includes/prestige.php, includes/fonctions.php, includes/game_resources.php, includes/display.php, js/ directory
**Previous rounds found:** Dead Cordova/AES scripts, 83-line dead bbcode, chemical reactions defined but unused, specializations defined but no UI, prestige system backend-only, $\_SESSION['start'] dead code.

---

## A) DEAD CODE TABLE

| File:Line | Code | Size (lines) | Reason Dead | Safe to Remove? |
|-----------|------|-------------|-------------|-----------------|
| `includes/copyright.php:21-23` | `<script src="cordova.js">`, `notification.js`, `PushNotification.js` | 3 | Cordova/mobile-wrapper never shipped. `cordova.js` does not exist on the web server. `notification.js` (77 lines) initialises Cordova GCM push notifications that will never fire in a browser context. `PushNotification.js` is a Cordova plugin stub. All three are loaded on every single page after `</html>`, meaning the browser downloads but silently ignores them. | YES — delete script tags and the two JS files |
| `includes/copyright.php:28-29` | `<script src="js/aes.js">` and `<script src="js/aes-json-format.js">` | 2 | AES encryption was intended for client-side credential transport. That scheme was never implemented; the login form now posts plaintext over HTTPS (or HTTP until DNS switches). Nothing in any PHP or JS file calls `AES.encrypt()` or any function from these two libraries. The files together are ~60 KB of dead weight loaded on every page. | YES — delete both script tags; optionally delete js/aes.js and js/aes-json-format.js from disk |
| `includes/copyright.php:27` | `<script src="js/loader.js">` | 1 | `js/loader.js` is actually the Google Charts Loader (~70 KB minified, contains locale data for every country). It was renamed and bundled under the misleading name `loader.js`. No page references `google.charts` or `google.load()` except `marche.php`, which loads the real Google Charts loader directly from `https://www.gstatic.com/charts/loader.js` at line 560. This copy is never called and wastes 70 KB on every page. | YES — delete the `<script>` tag; delete `js/loader.js` from disk |
| `includes/bbcode.php:1-87` | First `storeCaret(selec)` function definition (single-argument variant, IE-era) | 87 | PHP immediately defines a second `storeCaret(ao_txtfield, as_mf)` function starting at line 89 that completely shadows the first one. In JavaScript, the second `function storeCaret` declaration overwrites the first in the same scope. The first definition (lines 1-87) targets `document.forms['news'].elements['newst']` — a form that no longer exists — and uses `document.selection` (IE4-era API, removed from all modern browsers). Dead since at least IE11 end-of-life. | YES — remove lines 1-87; keep the second definition |
| `includes/copyright.php:159-261` | Name-generator globals and functions: `consonnes[]`, `voyelles[]`, `lettres[]`, `generate()`, `genererLettre()`, `genererConsonne()` | ~103 | These functions are only needed on `inscription.php` (the registration page) where the "Générer" button calls `generate()`. They are emitted by `copyright.php` (which is included on every authenticated and public page) and also duplicated verbatim in `includes/mots.php`. On every page except `inscription.php` they are loaded and parsed for no reason. The duplicate in `mots.php` means the code exists twice on disk. | MOVE (not delete) — load only on inscription.php; delete the duplicate in mots.php |
| `includes/copyright.php:38-46` | `var calVacs = myApp.calendar(...)` — calendar widget initialisation | 9 | The calendar input is `#calVacs` but no page contains an element with `id="calVacs"` in the current codebase. The Framework7 `.calendar()` call silently fails because the selector matches nothing. Loaded on every authenticated page. | YES — remove the calendar init block |
| `js/notification.js` (entire file) | GCM push notification handler — 77 lines | 77 | Cordova GCM push is the mobile-app notification system for the cancelled Android wrapper. Contains a hardcoded call to `http://www.theverylittlewar.com/tests/inscrireCle.php` (plaintext HTTP, non-existent path). `app.initialize()` fires on load and calls `document.addEventListener('deviceready', ...)` which never fires in a browser. Completely inert in a web context. | YES — delete file and its script tag in copyright.php |
| `js/PushNotification.js` (entire file) | Cordova PushPlugin stub | unknown | Cordova plugin stub, browser-incompatible, loaded via copyright.php. The `window.plugins.pushNotification` object it tries to register will never exist outside a Cordova app container. | YES — delete file and its script tag |
| `js/aes.js` | AES-128/256 implementation | large | Never called by any PHP or JS in the codebase. Loaded on every page. See copyright.php:28 row above. | YES — delete from disk |
| `js/aes-json-format.js` | AES JSON format wrapper | small | Companion to aes.js, same situation. | YES — delete from disk |
| `js/loader.js` | Google Charts Loader (renamed, ~70 KB) | large | Duplicate of what marche.php loads from CDN. The local copy is never called. | YES — delete from disk |
| `js/jquery-1.7.2.min.js` | jQuery 1.7.2 (2012) | large | Never loaded by any page. Contains known XSS CVEs (CVE-2011-4969, etc.). Only the CDN jQuery 3.7.1 in copyright.php:26 is active. Dead file with known vulnerabilities on the web root. | YES — delete from disk |
| `js/jquery-3.1.1.min.js` | jQuery 3.1.1 (2016) | large | Never loaded by any page after migration to CDN jQuery 3.7.1. Contains known vulnerabilities (CVE-2020-11022, CVE-2020-11023). Dead file. | YES — delete from disk |
| `js/jquery-ui-1.8.18.custom.min.js` | jQuery UI 1.8.18 (2011) | large | moderationForum.php loads jQuery UI from CDN (cdnjs, v1.13.3). This local copy is never referenced. CVE-2010-5312 and others apply to this ancient version. | YES — delete from disk |
| `js/jquery.jcryption.3.1.0.js` | jCryption client-side RSA encryption | medium | Was paired with the now-defunct PHP RSA key exchange system (also deleted). Nothing calls `$.jCryption`. | YES — delete from disk |
| `js/jquery.smooth-scroll.min.js` | Smooth-scroll jQuery plugin | small | No page loads or calls this file. Zero references in PHP or HTML templates. | YES — delete from disk |
| `js/sha.js` | jsSHA full library (SHA-1 through SHA-3/SHAKE) | medium | Never referenced in any PHP file. Was part of the AES/crypto scheme that was abandoned. Loaded by nothing. | YES — delete from disk |
| `js/sha1.js` | jsSHA SHA-1 only (overwrites sha.js `jsSHA` global) | small | Same as sha.js. Both libraries declare `window.jsSHA`; the second loaded silently overwrites the first. Neither is called. | YES — delete from disk |
| `js/googleCharts.js` | Empty placeholder file (0 bytes) | 0 | Empty file. Never loaded by any page. marche.php uses the CDN loader directly. | YES — delete from disk |
| `js/lightbox.js` | Lightbox 2.51 image overlay library | 351 | Not loaded by any page template. No PHP file emits a `<script src="js/lightbox.js">` tag. Has known jQuery `.html()` DOM-XSS vector (FE-R1-008 from Round 1). | YES — delete from disk |
| `includes/mots.php` | Duplicate name-generator (consonnes, voyelles, generate, etc.) | ~100 | Exact duplicate of the generator code in copyright.php. Both define the same JS functions in the same global scope. `mots.php` appears to be the original extracted file; copyright.php re-inlined it. Whichever is kept, the other must go. | YES — delete (move logic to inscription.php only) |
| `includes/display.php: rangForum()` checks `$donnees2['codeur']` column | `elseif ($donnees2['codeur'] == 1)` — "Codeur" forum rank | 4 | The `codeur` column in `membre` table exists but is never set to 1 by any game action, admin panel, or registration flow in the current codebase. Only the creator "Guortates" is hardcoded as a special case above it. The Codeur rank displays with colour `#740152` but no user can ever hold it through normal operations. | CAUTION — low risk to keep, but the branch is unreachable in practice |

---

## B) DEAD FEATURES TABLE

| Feature | Files | Status | Action |
|---------|-------|--------|--------|
| **Cordova Mobile App Wrapper** | `copyright.php` (script tags), `js/notification.js`, `js/PushNotification.js` | Defined / script files on disk — completely non-functional in browser context. The `deviceready` event never fires. GCM registration calls a non-existent endpoint over plain HTTP. | Remove all script tags and delete the two JS files. Document in CHANGELOG that the mobile app was never released. |
| **Client-Side AES Encryption** | `copyright.php:28-29`, `js/aes.js`, `js/aes-json-format.js`, `js/jquery.jcryption.3.1.0.js`, `js/sha.js`, `js/sha1.js` | Defined — all crypto libraries loaded but zero call sites anywhere in the active codebase. The scheme was to encrypt credentials client-side before POSTing; HTTPS makes this redundant and the implementation was never completed. | Remove all 6 script tags and delete all 5 JS files. Already flagged in multiple prior audit rounds. |
| **Prestige System — Purchase UI** | `includes/prestige.php` (all 5 functions + $PRESTIGE_UNLOCKS array) | Backend fully implemented; no front-end page calls `purchasePrestigeUnlock()` or `isPrestigeLegend()`. `awardPrestigePoints()` IS called from `basicprivatephp.php` at season end, and `prestigeProductionBonus()` / `prestigeCombatBonus()` ARE called in game_resources.php and combat.php. The `prestige` DB table is written to but players have no interface to spend accumulated PP or view their unlocks. | Partially dead: award pipeline works, spend pipeline has no UI. Either build prestige.php page or document as future feature. The backend is safe to keep as-is. |
| **Atom Specializations** | `includes/config.php:571-629` (`$SPECIALIZATIONS` array: combat/economy/research) | Defined — three specialization trees with unlock conditions (ionisateur lv15, producteur lv20, condenseur lv15) and stat modifiers. No PHP page reads `$SPECIALIZATIONS`. No DB columns `spec_combat`, `spec_economy`, `spec_research` exist in the schema. No player action triggers specialization assignment. | Entirely dead. Array defined in config but never read by any game code. Either implement (requires DB migration for 3 columns) or remove from config.php to reduce confusion. |
| **Defensive Formations UI** | `includes/config.php:273-283`, `includes/combat.php`, `constructions.php:338-342` | Partially implemented — `$FORMATIONS` array is rendered in constructions.php UI; combat.php reads `formation` field from DB and applies FORMATION_PHALANGE / FORMATION_EMBUSCADE logic correctly. This feature IS live. | Active — no action needed. Included here for completeness since previous rounds flagged it as "defined but no UI". UI was found in constructions.php. |
| **Isotope Variants** | `includes/config.php:288-308`, `includes/combat.php:78-116`, `includes/formulas.php:216-221`, `molecule.php`, `armee.php` | Fully implemented — isotope selection UI is in armee.php, combat applies modifiers, decay formula uses ISOTOPE_STABLE/REACTIF_DECAY_MOD. `ISOTOPE_NORMAL` (value 0) is used as the default/sentinel but never explicitly tested in conditional logic (it is the else branch). | Active — no action needed. `ISOTOPE_NORMAL` is a legitimate sentinel constant. |
| **Chemical Reactions** | `includes/config.php:313-344` (`$CHEMICAL_REACTIONS`), `includes/combat.php:127-141` | Implemented and active in combat.php which iterates `$CHEMICAL_REACTIONS` to compute cross-class atom bonuses. Also referenced in catalyst.php for naming. | Active — no action needed. |
| **Alliance Research Tree** | `includes/config.php:391-437` (`$ALLIANCE_RESEARCH`), `alliance.php`, `includes/db_helpers.php`, `includes/combat.php`, `marche.php`, `includes/game_actions.php` | Fully implemented and active. All 5 technologies (catalyseur, fortification, reseau, radar, bouclier) are upgradeable in alliance.php and their effects are consumed in combat, market, and espionage code. | Active — no action needed. |
| **bbcode.php First `storeCaret` Definition** | `includes/bbcode.php:1-87` | Dead — overwritten by the second definition on line 89. Uses IE4/IE5-era `document.selection` API (removed from all browsers). Targets `document.forms['news']` which does not exist. | Remove lines 1-87. The second `storeCaret(ao_txtfield, as_mf)` definition at line 89 is the active one. |
| **Calendar Widget (#calVacs)** | `includes/copyright.php:38-46` | Dead — `Framework7.calendar()` initialisation bound to `#calVacs` but no element with that ID exists anywhere in the current UI templates. The API call silently fails. | Remove the 9-line calendar init block from copyright.php. |
| **Name Generator on Non-Registration Pages** | `includes/copyright.php:159-261`, `includes/mots.php` | Misplaced — `generate()` is only needed on `inscription.php`. Currently loaded (and duplicated) on every page. `mots.php` is a verbatim copy. | Move generator code to inscription.php inline or a dedicated JS file loaded only there. Delete mots.php. Remove from copyright.php. |
| **Google Charts Local Copy** | `js/loader.js` (renamed Google Charts Loader), `js/googleCharts.js` (empty) | Dead — `marche.php` loads the real Google Charts Loader from CDN. The local `js/loader.js` is never called. `js/googleCharts.js` is 0 bytes. | Delete both files. Remove `<script src="js/loader.js">` from copyright.php:27. |

---

## C) UNUSED CONSTANTS TABLE

| Constant Name | Defined In | Referenced In Game Code? | Action |
|--------------|-----------|--------------------------|--------|
| `ONLINE_TIMEOUT_SECONDS` | `config.php:28` | No — only in tests (ConfigConsistencyTest.php) and docs. No PHP page uses it to check online status. The online status logic that would use it (e.g. `time() - $member['timestamp'] < ONLINE_TIMEOUT_SECONDS`) was apparently never implemented. | Keep for documentation value OR implement online indicator; do not silently remove without noting the gap |
| `MAX_CONCURRENT_CONSTRUCTIONS` | `config.php:22` | No — `constructions.php` uses the hardcoded literal `2` (lines 151, 212) instead of this constant. The constant exists and tests verify its value, but the actual enforcement code does not reference it. | Replace the two `< 2` literals in constructions.php with `< MAX_CONCURRENT_CONSTRUCTIONS` |
| `NUM_DAMAGEABLE_BUILDINGS` | `config.php:250` | No — `combat.php` uses `rand(1, 4)` (the literal 4) for building damage targeting. The constant defines the intent but is not actually passed to rand(). Only referenced in tests and docs. | Replace `rand(1, 4)` in combat.php with `rand(1, NUM_DAMAGEABLE_BUILDINGS)` |
| `$VICTORY_POINTS_PLAYER` | `config.php:450-458` | Only in tests — the actual VP calculation in `includes/formulas.php` uses the individual VP_PLAYER_RANK* constants directly, not this array. The array mirrors the constants but is otherwise redundant. Tests check both. | Remove the redundant array (keep the individual constants); or use the array in formulas.php for a single source of truth |
| `$VICTORY_POINTS_ALLIANCE` | `config.php:475-481` | Only in tests — same pattern as VICTORY_POINTS_PLAYER. formulas.php uses VP_ALLIANCE_RANK1/2/3 directly. The array is redundant. | Same as above — consolidate to one representation |
| `ISOTOPE_NORMAL` | `config.php:288` | Only in the `$ISOTOPES` array key and in docs/ideas — never tested as a condition in game logic (it is the implicit else/default). It is used as an array key in `$ISOTOPES[ISOTOPE_NORMAL]` (config.php:304) and displayed in molecule.php/armee.php via `$ISOTOPES[$isoType]`. | Keep — it is a legitimate named constant used as an array key; the absence of an explicit `if ($x == ISOTOPE_NORMAL)` branch is correct |
| `ISOTOPE_STABLE_DECAY_MOD` | `config.php:295` | Yes — used in `formulas.php:218` | Active — no action |
| `ISOTOPE_REACTIF_DECAY_MOD` | `config.php:298` | Yes — used in `formulas.php:221` | Active — no action |
| `$RESOURCE_NAMES_ACCENTED` | `config.php:37` | Indirectly — assigned to `$nomsAccents` in `constantesBase.php:6` which is then used throughout the UI. | Active via alias — no action |
| `$RESOURCE_COLORS` | `config.php:38` | Indirectly — assigned to `$couleurs` in `constantesBase.php:7`, used in display.php `couleurFormule()`. | Active via alias — no action |
| `$RESOURCE_COLORS_SIMPLE` | `config.php:39` | Indirectly — assigned to `$couleursSimples` in `constantesBase.php:8`. | Active via alias — no action |
| `MARKET_POINTS_SCALE` | `config.php:369` | Yes — used in marche.php for trade volume point scoring. | Active — no action |
| `MARKET_POINTS_MAX` | `config.php:370` | Yes — used in marche.php cap. | Active — no action |
| `CLASS_COST_OFFSET` | `config.php:444` | Yes — used in formulas.php:259 `coutClasse()`. | Active — no action |
| `ACTIVE_PLAYER_THRESHOLD` | `config.php:565` | Only in tests and docs — no game PHP file reads this constant to filter active players. The market and classement queries that should use it likely use `SECONDS_PER_MONTH` or raw literals instead. | Audit marche.php / classement.php for the literal 2678400 or SECONDS_PER_MONTH and replace with ACTIVE_PLAYER_THRESHOLD |
| `$SPECIALIZATIONS` | `config.php:571-629` | No — zero references in any game PHP file. No DB columns for spec_combat, spec_economy, spec_research. | Entirely dead. Remove from config.php or implement (DB migration + UI required) |
| `DUPLICATEUR_BASE_COST` | `config.php:376` | Yes — referenced in player.php / alliance.php for duplicateur upgrade cost. | Active — no action |
| `DUPLICATEUR_COST_FACTOR` | `config.php:377` | Yes — same as above. | Active — no action |
| `ALLIANCE_TAG_MIN_LENGTH` | `config.php:386` | Yes — used in validation for alliance tag creation. | Active — no action |
| `ALLIANCE_TAG_MAX_LENGTH` | `config.php:387` | Yes — same. | Active — no action |
| `$BUILDING_CONFIG` | `config.php:124-231` | Yes — used extensively in constructions.php and player.php for cost/time calculations. | Active — no action |
| `VAULT_PROTECTION_PER_LEVEL` | `config.php:233` | Yes — used in combat.php for coffrefort pillage protection. | Active — no action |
| `ATTACK_ENERGY_COST_FACTOR` | `config.php:239` | Yes — used in game_actions.php for attack energy calculation. | Active — no action |
| `IONISATEUR_COMBAT_BONUS_PER_LEVEL` | `config.php:242` | Yes — used in combat.php. | Active — no action |
| `CHAMPDEFORCE_COMBAT_BONUS_PER_LEVEL` | `config.php:243` | Yes — used in combat.php. | Active — no action |
| `DUPLICATEUR_COMBAT_COEFFICIENT` | `config.php:247` | Yes — used in combat.php for duplicateur attack/defense bonus. | Active — no action |
| `REGISTRATION_RANDOM_MAX` | `config.php:552` | Yes — used in inscription.php for element distribution. | Active — no action |
| `$REGISTRATION_ELEMENT_THRESHOLDS` | `config.php:553` | Yes — used in inscription.php. | Active — no action |
| `LIEUR_GROWTH_BASE` | `config.php:559` | Yes — used in formulas.php `bonusLieur()`. | Active — no action |
| `DECAY_BASE` | `config.php:101` | Yes — used in formulas.php `coefDisparition()`. | Active — no action |
| `DECAY_ATOM_DIVISOR` | `config.php:102` | Yes — same. | Active — no action |
| `DECAY_POWER_DIVISOR` | `config.php:103` | Yes — same. | Active — no action |
| `STABILISATEUR_BONUS_PER_LEVEL` | `config.php:104` | Yes — used in formulas.php. | Active — no action |

---

## D) PRESTIGE SYSTEM — DETAILED DEAD/LIVE BREAKDOWN

The prestige system is split: the award pipeline is wired up; the spend/display pipeline has no UI.

| Function / Data | Called By | Status |
|----------------|-----------|--------|
| `awardPrestigePoints()` | `basicprivatephp.php:215` (season end) | LIVE — called at season rotation |
| `calculatePrestigePoints($login)` | `awardPrestigePoints()` | LIVE — internal helper |
| `getPrestige($login)` | `hasPrestigeUnlock()` | LIVE — internal helper |
| `hasPrestigeUnlock($login, $key)` | `prestigeProductionBonus()`, `prestigeCombatBonus()`, `isPrestigeLegend()` | LIVE — internal |
| `prestigeProductionBonus($login)` | `game_resources.php:51,82` | LIVE — applied to production |
| `prestigeCombatBonus($login)` | `combat.php:216,217` | LIVE — applied to combat damage |
| `purchasePrestigeUnlock($login, $key)` | Nobody — zero call sites in game PHP | DEAD — no page calls this |
| `isPrestigeLegend($login)` | Nobody — zero call sites in game PHP | DEAD — no display code calls this |
| `$PRESTIGE_UNLOCKS` array | Only by `purchasePrestigeUnlock()` | DEAD — the spend function itself is dead |
| Prestige DB table writes | `awardPrestigePoints()` | LIVE — PP are being accumulated in the DB |
| Prestige DB table reads | Only through `hasPrestigeUnlock()` → `getPrestige()` | LIVE — unlocks ARE checked for production/combat |

**Conclusion:** Players accumulate PP each season. If they already unlocked 'experimente' or 'maitre_chimiste' in the DB (by manual admin insert), those bonuses apply. But no player can ever call `purchasePrestigeUnlock()` through the UI — no page exposes it. The 'debutant_rapide', 'veteran', 'legende' unlocks' effects are also never applied anywhere (only 'experimente' and 'maitre_chimiste' have consumers). `isPrestigeLegend()` is defined but never called.

---

## E) SUMMARY: PRIORITY REMOVAL LIST

### Immediate (high impact, zero risk):
1. Delete `js/aes.js`, `js/aes-json-format.js`, `js/loader.js` (Google Charts copy), `js/googleCharts.js` (empty), `js/notification.js`, `js/PushNotification.js`, `js/jquery-1.7.2.min.js`, `js/jquery-3.1.1.min.js`, `js/jquery-ui-1.8.18.custom.min.js`, `js/jquery.jcryption.3.1.0.js`, `js/sha.js`, `js/sha1.js`, `js/lightbox.js`, `js/jquery.smooth-scroll.min.js` — 14 dead JS files
2. Remove corresponding `<script>` tags from `includes/copyright.php` (lines 21-23, 27, 28-29)
3. Remove `includes/bbcode.php:1-87` (dead first `storeCaret` definition)
4. Remove `includes/copyright.php:38-46` (dead calVacs calendar init)
5. Remove `$SPECIALIZATIONS` array from `config.php:571-629` — entirely unimplemented feature

### Medium priority (behaviour-neutral fixes):
6. Replace literal `< 2` with `< MAX_CONCURRENT_CONSTRUCTIONS` in `constructions.php:151,212`
7. Replace `rand(1, 4)` with `rand(1, NUM_DAMAGEABLE_BUILDINGS)` in combat.php
8. Audit marche.php / classement.php for `SECONDS_PER_MONTH` / `2678400` literals that should use `ACTIVE_PLAYER_THRESHOLD`
9. Consolidate `$VICTORY_POINTS_PLAYER` / `$VICTORY_POINTS_ALLIANCE` arrays — either drive formulas.php from them or remove the redundant arrays
10. Move name-generator code out of `copyright.php` into inscription.php only; delete `includes/mots.php`

### Low priority (design decisions):
11. Build a prestige spend UI page (`prestige.php`) to expose `purchasePrestigeUnlock()` and display PP / unlocks — the backend is solid and wired
12. Decide fate of 'debutant_rapide' / 'veteran' / 'legende' prestige unlocks — their effects are defined but never consumed by any game code beyond `purchasePrestigeUnlock()` itself
13. Either implement `$SPECIALIZATIONS` (DB migration + UI + combat integration) or formally document it as a planned future feature in game docs
14. Add a "Codeur" role assignment mechanism to admin panel if the forum rank is intended to be usable
