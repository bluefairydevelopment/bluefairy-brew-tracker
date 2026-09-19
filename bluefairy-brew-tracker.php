<?php
/**
 * Plugin Name:       BlueFairy Brew Tracker
 * Plugin URI:        https://wordpress.bluefairydevelopment.com/brew-tracker/
 * Description:       A batch log for mead, wine, cider and beer: style, vessel, start date, gravity readings, additions, stage and what is due next.
 * Version:           1.0.0

 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Blue Fairy Development
 * Author URI:        https://wordpress.bluefairydevelopment.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bluefairy-brew-tracker
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'BFBT_VERSION',  '1.0.0' );
define( 'BFBT_FILE',     __FILE__ );
define( 'BFBT_PATH',     plugin_dir_path( __FILE__ ) );
define( 'BFBT_URL',      plugin_dir_url( __FILE__ ) );
define( 'BFBT_BASENAME', plugin_basename( __FILE__ ) );

require_once BFBT_PATH . 'includes/class-validate.php';
require_once BFBT_PATH . 'includes/class-units.php';
require_once BFBT_PATH . 'includes/class-batch.php';
require_once BFBT_PATH . 'includes/class-log.php';
require_once BFBT_PATH . 'includes/class-task.php';
require_once BFBT_PATH . 'includes/class-stage.php';
require_once BFBT_PATH . 'includes/class-abv.php';
require_once BFBT_PATH . 'includes/class-chart.php';
require_once BFBT_PATH . 'includes/class-csv.php';
require_once BFBT_PATH . 'includes/class-batch-cascade.php';
require_once BFBT_PATH . 'includes/class-photos.php';
require_once BFBT_PATH . 'includes/class-seeder.php';
require_once BFBT_PATH . 'includes/class-feature-registry.php';
require_once BFBT_PATH . 'includes/class-plugin.php';

/**
 * Marker the Pro add-on uses to confirm the base plugin is present, and to
 * compare against its MIN_BASE.
 */
function bfbt_plugin(): string {
	return BFBT_VERSION;
}

register_activation_hook( __FILE__, [ 'BFBT_Plugin', 'activate' ] );

add_action( 'plugins_loaded', [ 'BFBT_Plugin', 'boot' ] );
