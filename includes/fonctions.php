<?php
/**
 * fonctions.php - Backward-compatible shim
 *
 * This file previously contained ~85 functions in ~2585 lines.
 * It has been modularized into focused module files.
 * Existing code that includes this file continues to work unchanged.
 */

require_once __DIR__ . '/formulas.php';        // Game formulas (pure math + medal bonuses)
require_once __DIR__ . '/game_resources.php';  // Resource production & updateRessources
require_once __DIR__ . '/game_actions.php';    // Action processing (updateActions)
require_once __DIR__ . '/player.php';          // Player management (init, buildings, alliances)
require_once __DIR__ . '/ui_components.php';   // UI rendering helpers (cards, lists, forms)
require_once __DIR__ . '/display.php';         // Display/formatting helpers (images, numbers, text)
require_once __DIR__ . '/db_helpers.php';      // DB helper wrappers (query, ajouter, alliance)
require_once __DIR__ . '/prestige.php';       // Cross-season prestige system
require_once __DIR__ . '/catalyst.php';       // Weekly catalyst system
