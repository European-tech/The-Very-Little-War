<?php
/**
 * Simple database migration runner for TVLW.
 * Usage: php migrate.php
 *
 * Migrations are SQL files in this directory named:
 *   NNNN_description.sql (e.g., 0001_add_indexes.sql)
 *
 * Applied migrations are tracked in the `migrations` table.
 */

// INFRA-P9 (MEDIUM): block web execution — CLI only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../includes/connexion.php';

// Create migrations table if not exists
$createResult = mysqli_query($base, "
    CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL UNIQUE,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");
if ($createResult === false) {
    echo "MIGRATION FAILED: could not create migrations table: " . mysqli_error($base) . "\n";
    exit(1);
}

// Get applied migrations
$applied = [];
$appliedResult = mysqli_query($base, "SELECT filename FROM migrations ORDER BY id");
if ($appliedResult === false) {
    echo "MIGRATION FAILED: could not query migrations table: " . mysqli_error($base) . "\n";
    exit(1);
}
while ($row = mysqli_fetch_assoc($appliedResult)) {
    $applied[] = $row['filename'];
}

// Get pending migration files
$files = glob(__DIR__ . '/*.sql');
sort($files);

$pending = 0;
foreach ($files as $file) {
    $filename = basename($file);
    if (in_array($filename, $applied)) continue;

    echo "Applying: $filename... ";
    $sql = file_get_contents($file);

    // INFRA-DB MEDIUM-002: Wrap migration in a transaction so a mid-file failure
    // does not leave the database in a partially-applied state.
    // Note: DDL statements (ALTER TABLE, CREATE TABLE, DROP TABLE) cause an implicit
    // commit in MySQL/MariaDB and cannot be rolled back — those migrations are
    // "best-effort" transactional, but DML failures will still roll back cleanly.
    mysqli_begin_transaction($base);
    $migrationError = null;

    // Execute each statement; detect errors per-statement inside the drain loop
    // so that a failure mid-file is caught immediately rather than silently skipped.
    // INFRA-DATABASE MEDIUM: wrap in try/catch to handle mysqli_sql_exception thrown by MYSQLI_REPORT_STRICT.
    try {
        if (mysqli_multi_query($base, $sql)) {
            do {
                if ($result = mysqli_store_result($base)) {
                    mysqli_free_result($result);
                }
                // Check for an error produced by the statement just consumed
                if (mysqli_errno($base)) {
                    $migrationError = mysqli_error($base);
                    break;
                }
            } while (mysqli_next_result($base));
        }

        // Check for any error that prevented mysqli_multi_query from starting
        if ($migrationError === null && mysqli_errno($base)) {
            $migrationError = mysqli_error($base);
        }
    } catch (\mysqli_sql_exception $e) {
        mysqli_rollback($base);
        echo "ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }

    if ($migrationError !== null) {
        // INFRA-DATABASE-P20-006: Drain any remaining buffered results before rollback to prevent
        // "Commands out of sync" errors when the drain loop exited early via break.
        while (@mysqli_next_result($base)) {
            if ($r = @mysqli_store_result($base)) {
                mysqli_free_result($r);
            }
        }
        mysqli_rollback($base);
        echo "ERROR in migration $filename: $migrationError\n";
        exit(1);
    }

    // Record migration inside the same transaction so it's atomic with the SQL changes
    $stmt = mysqli_prepare($base, "INSERT INTO migrations (filename) VALUES (?)");
    mysqli_stmt_bind_param($stmt, "s", $filename);
    if (!mysqli_stmt_execute($stmt)) {
        $recordError = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        mysqli_rollback($base);
        echo "ERROR recording migration: $recordError\n";
        exit(1);
    }
    mysqli_stmt_close($stmt);

    mysqli_commit($base);

    echo "OK\n";
    $pending++;
}

if ($pending == 0) {
    echo "No pending migrations.\n";
} else {
    echo "\nApplied $pending migration(s).\n";
}
