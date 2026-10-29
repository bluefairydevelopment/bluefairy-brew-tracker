<?php
/**
 * PHPStan bootstrap (developer tooling, not shipped — see .distignore).
 *
 * The main plugin file defines the BFBT_* constants after its ABSPATH guard, so
 * PHPStan never executes those define() calls and reports every use as
 * "Constant not found". These mirror the main file's definitions with the
 * same types (all strings).
 *
 * @package BlueFairy
 */

define( 'BFBT_VERSION', '0.0.0' );
define( 'BFBT_FILE', __DIR__ . '/bluefairy-brew-tracker.php' );
define( 'BFBT_PATH', __DIR__ . '/' );
define( 'BFBT_URL', 'https://example.invalid/' );
define( 'BFBT_BASENAME', 'bluefairy-brew-tracker/bluefairy-brew-tracker.php' );
