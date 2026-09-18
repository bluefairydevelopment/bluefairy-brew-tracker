<?php
/**
 * Settings: display units, and the CSV export.
 *
 * Both are manage_options. A Contributor can log a batch; only an
 * administrator changes how volumes are shown or takes the whole log away
 * in a file.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class BFBT_Settings {

	public const PAGE_SLUG = 'bfbt-settings';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 20 );
		add_action( 'admin_post_bfbt_save_settings', [ __CLASS__, 'save' ] );
		add_action( 'admin_post_bfbt_export_csv', [ __CLASS__, 'export' ] );
	}

	public static function menu(): void {
		$hook = add_submenu_page(
			BFBT_Batch_Admin::PAGE_SLUG,
			__( 'Brew Tracker Settings', 'bluefairy-brew-tracker' ),
			__( 'Settings', 'bluefairy-brew-tracker' ),
			BFBT_Batch_Admin::CAP_MANAGE,
			self::PAGE_SLUG,
			[ __CLASS__, 'render' ]
		);

		BFBT_Batch_Admin::register_screen( (string) $hook );
	}

	public static function render(): void {
		BFBT_Batch_Admin::guard( BFBT_Batch_Admin::CAP_MANAGE );

		$units = BFBT_Units::setting();

		echo '<div class="wrap bfbt-wrap">';
		echo '<h1>' . esc_html__( 'Brew Tracker Settings', 'bluefairy-brew-tracker' ) . '</h1>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bfbt_settings' );
		echo '<input type="hidden" name="action" value="bfbt_save_settings">';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Display units', 'bluefairy-brew-tracker' ) . '</th><td>';
		printf( '<label><input type="radio" name="units" value="us"%s> %s</label><br>', checked( $units, BFBT_Units::US, false ), esc_html__( 'US (gallons, pounds)', 'bluefairy-brew-tracker' ) );
		printf( '<label><input type="radio" name="units" value="metric"%s> %s</label>', checked( $units, BFBT_Units::METRIC, false ), esc_html__( 'Metric (litres, grams)', 'bluefairy-brew-tracker' ) );
		echo '<p class="description">' . esc_html__( 'Governs derived volumes only — a batch size, say. A quantity you logged is always shown in the unit you typed.', 'bluefairy-brew-tracker' ) . '</p>';
		echo '</td></tr></tbody></table>';
		submit_button( __( 'Save settings', 'bluefairy-brew-tracker' ) );
		echo '</form>';

		echo '<hr><h2>' . esc_html__( 'Export', 'bluefairy-brew-tracker' ) . '</h2>';
		echo '<p>' . esc_html__( 'Every batch and every log entry as one CSV file, batches first, then entries keyed by batch id.', 'bluefairy-brew-tracker' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bfbt_export' );
		echo '<input type="hidden" name="action" value="bfbt_export_csv">';
		submit_button( __( 'Export CSV', 'bluefairy-brew-tracker' ), 'secondary' );
		echo '</form>';

		/**
		 * After the free settings. Add-ons print their own sections here.
		 */
		do_action( 'bfbt_settings_after' );

		echo '</div>';
	}

	public static function save(): void {
		BFBT_Batch_Admin::guard( BFBT_Batch_Admin::CAP_MANAGE );
		check_admin_referer( 'bfbt_settings' );

		$units = BFBT_Batch_Admin::post_text( 'units' );

		update_option( BFBT_Units::OPTION, BFBT_Units::METRIC === $units ? BFBT_Units::METRIC : BFBT_Units::US );

		set_transient( 'bfbt_notice_' . get_current_user_id(), [ 'updated', __( 'Settings saved.', 'bluefairy-brew-tracker' ) ], 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	public static function export(): void {
		BFBT_Batch_Admin::guard( BFBT_Batch_Admin::CAP_MANAGE );
		check_admin_referer( 'bfbt_export' );

		BFBT_CSV::stream();
	}
}
