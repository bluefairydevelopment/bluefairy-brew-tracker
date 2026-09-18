<?php
/**
 * Base plugin bootstrap.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class BFBT_Plugin {

	/**
	 * Boot.
	 *
	 * bfbt_loaded fires BEFORE any of this plugin's own subsystems register.
	 * The Pro add-on hooks it to declare its batch fields, its stage model,
	 * its log and task types and its feature switches, and all of those have
	 * to be in place before the registry, the settings screen or the list
	 * table read them.
	 *
	 * There is deliberately no load_plugin_textdomain() call: WordPress loads
	 * a wordpress.org plugin's translations itself, and calling it here, on
	 * plugins_loaded, is a _doing_it_wrong on WordPress 6.7+.
	 */
	public static function boot(): void {
		BFBT_Batch::init();

		do_action( 'bfbt_loaded' );

		// The admin screens land in a later phase. The guard lets this file
		// boot a tree that does not carry them yet.
		if ( is_admin() && file_exists( BFBT_PATH . 'admin/class-batch-admin.php' ) ) {
			require_once BFBT_PATH . 'admin/class-batch-admin.php';
			BFBT_Batch_Admin::init();
		}
	}

	/**
	 * Activation: register the types so the seeder has a taxonomy to write
	 * to, then seed the style tree.
	 *
	 * Seeding is idempotent by slug, so activating twice seeds once and never
	 * overwrites a term the maker has renamed.
	 */
	public static function activate(): void {
		BFBT_Batch::register();
		BFBT_Seeder::seed_styles();
	}
}
