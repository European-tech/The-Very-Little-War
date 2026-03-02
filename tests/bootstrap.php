<?php
/**
 * Test bootstrap - sets up environment for testing game functions
 * without needing a real database connection.
 */

// Mock database connection for testing
$base = null;

// Load game constants (config.php is loaded by constantesBase.php)
require_once __DIR__ . '/../includes/constantesBase.php';

// Load validation helpers
require_once __DIR__ . '/../includes/validation.php';

// Load CSRF protection (no DB dependency, uses $_SESSION)
require_once __DIR__ . '/../includes/csrf.php';

// Load rate limiter (file-based, no DB dependency)
// RATE_LIMIT_DIR is guarded with if (!defined()) so tests can override it
require_once __DIR__ . '/../includes/rate_limiter.php';

// Mock session for testing
$_SESSION = [];

// Load the game functions that don't depend on database
// (pure computation functions can be tested directly)
