<?php
/**
 * Safe database query helpers using prepared statements.
 * Prevents SQL injection throughout the application.
 */

/**
 * Execute a prepared SELECT query and return the mysqli_result.
 * Usage: dbQuery($base, "SELECT * FROM membre WHERE login = ?", "s", $login)
 */
function dbQuery($base, $sql, $types = "", ...$params) {
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

/**
 * Get the last inserted auto-increment ID.
 */
function dbLastId($base) {
    return mysqli_insert_id($base);
}

/**
 * Escape string for LIKE patterns (not a substitute for prepared statements).
 */
function dbEscapeLike($str) {
    return str_replace(['%', '_'], ['\\%', '\\_'], $str);
}

/**
 * Count rows from a query result.
 * Usage: $count = dbCount($base, "SELECT COUNT(*) AS nb FROM membre WHERE login = ?", "s", $login);
 */
function dbCount($base, $sql, $types = "", ...$params) {
    $row = dbFetchOne($base, $sql, $types, ...$params);
    if (!$row) return 0;
    return (int) reset($row);
}

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
    if ($useSavepoint) {
        mysqli_query($base, "SAVEPOINT $sp");
    } else {
        mysqli_begin_transaction($base);
    }
    $depth++;
    try {
        $result = $fn();
        $depth--;
        if ($useSavepoint) {
            mysqli_query($base, "RELEASE SAVEPOINT $sp");
        } else {
            mysqli_commit($base);
        }
        return $result;
    } catch (\Throwable $e) {
        $depth--;
        if ($useSavepoint) {
            mysqli_query($base, "ROLLBACK TO SAVEPOINT $sp");
        } else {
            mysqli_rollback($base);
        }
        throw $e;
    }
}
