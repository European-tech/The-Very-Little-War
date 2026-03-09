<?php
/**
 * Safe database query helpers using prepared statements.
 * Prevents SQL injection throughout the application.
 */

/**
 * Execute a prepared SELECT query and return the mysqli_result.
 * Usage: dbQuery($base, "SELECT * FROM membre WHERE login = ?", "s", $login)
 */
if (!function_exists('dbQuery')):
function dbQuery($base, $sql, $types = "", ...$params) {
    // NOTE (INFRA-DB-HIGH-001): Under MYSQLI_REPORT_ERROR|STRICT, any mysqli failure throws mysqli_sql_exception
    // before if(!$stmt) branches are reached. These guards are dead code but kept as documentation.
    // The global exception handler in connexion.php catches all uncaught mysqli exceptions.
    $stmt = mysqli_prepare($base, $sql);
    if (!$stmt) {
        $truncatedQuery = mb_substr($sql, 0, 100);
        error_log("SQL Prepare Error [" . mysqli_errno($base) . "]: " . mysqli_error($base) . " | Query: " . $truncatedQuery);
        return false;
    }
    if ($types !== "" && count($params) > 0) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        $truncatedQuery = mb_substr($sql, 0, 100);
        error_log("SQL Execute Error [" . mysqli_stmt_errno($stmt) . "]: " . mysqli_stmt_error($stmt) . " | Query: " . $truncatedQuery);
        mysqli_stmt_close($stmt);
        return false;
    }
    $result = mysqli_stmt_get_result($stmt);
    mysqli_stmt_close($stmt);
    return $result;
}
endif;

if (!function_exists('dbFetchOne')):
/**
 * Execute a prepared query and return one row as associative array.
 */
function dbFetchOne($base, $sql, $types = "", ...$params) {
    $result = dbQuery($base, $sql, $types, ...$params);
    if (!$result) return null;
    $row = mysqli_fetch_assoc($result);
    mysqli_free_result($result);
    return $row;
}
endif;

if (!function_exists('dbFetchAll')):
/**
 * Execute a prepared query and return all rows as array of associative arrays.
 */
function dbFetchAll($base, $sql, $types = "", ...$params) {
    $result = dbQuery($base, $sql, $types, ...$params);
    if (!$result) return [];
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_free_result($result);
    return $rows;
}
endif;

if (!function_exists('dbExecute')):
/**
 * Execute a prepared INSERT/UPDATE/DELETE and return affected rows count.
 */
function dbExecute($base, $sql, $types = "", ...$params) {
    $stmt = mysqli_prepare($base, $sql);
    if (!$stmt) {
        $truncatedQuery = mb_substr($sql, 0, 100);
        error_log("SQL Prepare Error [" . mysqli_errno($base) . "]: " . mysqli_error($base) . " | Query: " . $truncatedQuery);
        return false;
    }
    if ($types !== "" && count($params) > 0) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        $truncatedQuery = mb_substr($sql, 0, 100);
        error_log("SQL Execute Error [" . mysqli_stmt_errno($stmt) . "]: " . mysqli_stmt_error($stmt) . " | Query: " . $truncatedQuery);
        mysqli_stmt_close($stmt);
        return false;
    }
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $affected;
}
endif;

if (!function_exists('dbLastId')):
/**
 * Get the last inserted auto-increment ID.
 */
function dbLastId($base) {
    return mysqli_insert_id($base);
}
endif;

if (!function_exists('dbEscapeLike')):
/**
 * Escape string for LIKE patterns (not a substitute for prepared statements).
 */
function dbEscapeLike($str) {
    return str_replace(['%', '_'], ['\\%', '\\_'], $str);
}
endif;

if (!function_exists('dbCount')):
/**
 * Count rows from a query result.
 * Usage: $count = dbCount($base, "SELECT COUNT(*) AS nb FROM membre WHERE login = ?", "s", $login);
 */
function dbCount($base, $sql, $types = "", ...$params) {
    $row = dbFetchOne($base, $sql, $types, ...$params);
    if (!$row) return 0;
    return (int) reset($row);
}
endif;

if (!function_exists('withTransaction')):
/**
 * Execute a callable inside a database transaction, with savepoint support for nesting.
 *
 * When called at the top level (depth 0) it issues a real BEGIN/COMMIT/ROLLBACK.
 * When called inside an already-active transaction (depth > 0) — for example when
 * game_actions.php has already called mysqli_begin_transaction() manually — it uses
 * a named SAVEPOINT instead, which avoids the implicit commit that MariaDB would
 * issue on a nested BEGIN.
 *
 * Usage:
 *   $result = withTransaction($base, function() use ($base, ...) {
 *       dbExecute($base, ...);
 *       return $someValue;
 *   });
 */
function withTransaction($base, callable $fn) {
    static $depth = 0;
    $useSavepoint = $depth > 0;
    $sp = 'sp_' . $depth;
    // MEDIUM-001: only increment $depth after the START TRANSACTION / SAVEPOINT
    // succeeds, so a connection failure cannot leave $depth permanently incremented.
    if ($useSavepoint) {
        if (!mysqli_query($base, "SAVEPOINT $sp")) {
            throw new \RuntimeException('savepoint_failed');
        }
    } else {
        if (!mysqli_begin_transaction($base)) {
            throw new \RuntimeException('transaction_begin_failed');
        }
    }
    // Increment only after the DB operation confirmed success.
    $depth++;
    $committed = false;
    try {
        $result = $fn();
        if ($useSavepoint) {
            mysqli_query($base, "RELEASE SAVEPOINT $sp");
        } else {
            mysqli_commit($base);
        }
        $committed = true;
        return $result;
    } catch (\Throwable $e) {
        if (!$committed) {
            if ($useSavepoint) {
                // LOW-010: log the savepoint name so failures are traceable in error logs.
                if (function_exists('logError')) {
                    logError('DB', 'withTransaction: rolling back to savepoint ' . $sp . ' — ' . $e->getMessage());
                } else {
                    error_log('withTransaction: rolling back to savepoint ' . $sp . ' — ' . $e->getMessage());
                }
                mysqli_query($base, "ROLLBACK TO SAVEPOINT $sp");
            } else {
                mysqli_rollback($base);
            }
        }
        throw $e;
    } finally {
        // INFRA-DB-H-001: Use finally so $depth is decremented exactly once regardless
        // of how the function exits (normal return, exception, or COMMIT failure).
        $depth--;
    }
}
endif;
