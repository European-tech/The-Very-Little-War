# Pass 7 SQL Injection Audit

**Date:** 2026-03-08
**Scope:** All PHP files in `/home/guortates/TVLW/The-Very-Little-War/` (excluding `vendor/` and `tests/`)
**Method:** Four search strategies
1. Raw `mysqli_query` / `mysql_query` / `mysqli_real_escape_string` occurrences
2. `$base->query()` calls with string arguments
3. `$sql = ...` assignments with variable interpolation
4. SQL strings containing `ORDER BY`, `WHERE`, `SET` with variable concatenation not through prepared helpers

---

## Findings

CLEAN — no new findings.

All detected instances of raw `mysqli_query` / `->query()` are either:

| Location | Pattern | Safe? | Reason |
|---|---|---|---|
| `migrations/migrate.php:15,25` | `mysqli_query($base, "CREATE TABLE …")` / `"SELECT filename FROM migrations …"` | **Yes** | CLI-only migration runner. Both queries are fully hard-coded literals with zero user input. The `INSERT INTO migrations` that records each applied filename uses `mysqli_prepare` / `mysqli_stmt_bind_param`. |
| `includes/database.php:125,138,146` | `mysqli_query($base, "SAVEPOINT $sp")` | **Yes** | `$sp` is derived from `'sp_' . $depth` where `$depth` is a static integer counter incremented/decremented internally. It is never populated from any user-supplied value. No injection surface. |
| `health.php:25` | `mysqli_query($conn, 'SELECT 1')` | **Yes** | Hard-coded literal string. Used only to test DB connectivity. |
| `scripts/cleanup_old_data.php:88` | `$base->query("SHOW TABLES LIKE 'player_compounds'")` | **Yes** | Hard-coded literal string. CLI-only script, no user input reaches this call. |

### Dynamic SQL Column Interpolation (whitelisted)

The following files build SQL with dynamic column names. All are safe because column names are derived from a hard-coded whitelist before interpolation:

| File | Pattern | Whitelist |
|---|---|---|
| `classement.php` | `ORDER BY ' . $order . '` | `$order` is assigned only inside a closed `if/elseif/else` chain from a hard-coded set of 8 column names; the source `$clas` is cast to `(int)` before entering the chain. No user string can reach `$order`. |
| `includes/db_helpers.php` | `SELECT ' . $techName . '` / `UPDATE $bdd SET $champ` | `$techName` validated against `ALLIANCE_RESEARCH_COLUMNS` constant (5 values). `$champ` / `$bdd` validated against `$allowedColumns` / `$allowedTables` static arrays at runtime before use. |
| `includes/combat.php` | `SET ' . implode(',', $setClauses)` | `$setClauses` built by iterating `$nomsRes`, whose values come from `$RESOURCE_NAMES` — a hard-coded PHP array of 8 atom names defined in `includes/config.php`. The `in_array($ressource, $nomsRes, true)` guard provides defence-in-depth. |
| `marche.php` | `SET ' . implode(', ', $setClauses)` | Same: columns come from `$nomsRes` (hard-coded config). Values are parameterised with `?`. |
| `includes/player.php:1277` | `UPDATE ressources SET … ' . $chaine` | `$chaine` is built by iterating `$nomsRes` and appending `$ressource . '=default'`; `$ressource` values come from the same hard-coded config array. Only safe column names and the literal `=default` are interpolated; no user data involved. |

---

## Summary

**Total actionable findings: 0**

The codebase is CLEAN with respect to SQL injection. Every raw `mysqli_query` call is isolated to infrastructure code (migrations, savepoints, health check) with no user-controlled input. Every instance of dynamic SQL column construction uses verified hard-coded whitelists derived from `$RESOURCE_NAMES` in `includes/config.php` or explicitly declared constant arrays, and all user-supplied values are bound as parameters via the `dbQuery` / `dbFetchOne` / `dbFetchAll` / `dbExecute` / `dbCount` prepared-statement helpers.
