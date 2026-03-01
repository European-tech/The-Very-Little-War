<?php
/**
 * Test bootstrap - sets up environment for testing game functions
 * without needing a real database connection.
 */

// Mock database connection for testing
$base = null;

// Load game constants
require_once __DIR__ . '/../includes/constantesBase.php';

// Load validation helpers
require_once __DIR__ . '/../includes/validation.php';

// Mock session for testing
$_SESSION = [];

// Load the game functions that don't depend on database
// (pure computation functions can be tested directly)
