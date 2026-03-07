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

require_once __DIR__ . '/../includes/connexion.php';

// Create migrations table if not exists
mysqli_query($base, "
    CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL UNIQUE,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

// Get applied migrations
$applied = [];
$result = mysqli_query($base, "SELECT filename FROM migrations ORDER BY id");
while ($row = mysqli_fetch_assoc($result)) {
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

    // Execute each statement; detect errors per-statement inside the drain loop
    // so that a failure mid-file is caught immediately rather than silently skipped.
    if (mysqli_multi_query($base, $sql)) {
        do {
            if ($result = mysqli_store_result($base)) {
                mysqli_free_result($result);
            }
            // Check for an error produced by the statement just consumed
            if (mysqli_errno($base)) {
                echo "ERROR in migration $filename: " . mysqli_error($base) . "\n";
                exit(1);
            }
        } while (mysqli_next_result($base));
    }

    // Check for any error that prevented mysqli_multi_query from starting
    if (mysqli_errno($base)) {
        echo "ERROR in migration $filename: " . mysqli_error($base) . "\n";
        exit(1);
    }

    // Record migration
    $stmt = mysqli_prepare($base, "INSERT INTO migrations (filename) VALUES (?)");
    mysqli_stmt_bind_param($stmt, "s", $filename);
    if (!mysqli_stmt_execute($stmt)) {
        echo "ERROR recording migration: " . mysqli_stmt_error($stmt) . "\n";
        mysqli_stmt_close($stmt);
        exit(1);
    }
    mysqli_stmt_close($stmt);

    echo "OK\n";
    $pending++;
}

if ($pending == 0) {
    echo "No pending migrations.\n";
} else {
    echo "\nApplied $pending migration(s).\n";
}
