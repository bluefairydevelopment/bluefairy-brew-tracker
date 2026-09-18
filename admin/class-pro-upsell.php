<?php
/**
 * The one link to the paid add-on.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class BFBT_Pro_Upsell {

	private const PRODUCT_URL = 'https://wordpress.bluefairydevelopment.com/product/bluefairy-brew-tracker-pro/';

	public static function init(): void {
		add_filter( 'plugin_action_links_' . BFBT_BASENAME, [ __CLASS__, 'action_links' ] );
	}

	/**
	 * @param  string[] $links
	 * @return string[]
	 */
	public static function action_links( array $links ): array {
		if ( self::pro_is_active() ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( self::PRODUCT_URL ),
			esc_html__( 'Get Brew Tracker Pro', 'bluefairy-brew-tracker' )
		);

		return $links;
	}

	public static function pro_is_active(): bool {
		return function_exists( 'bfbtp_plugin' );
	}

	/**
	 * The panel under the batch list.
	 *
	 * Every line is a Pro-only row of the plan's Free vs Pro table.
	 */
	public static function render_panel(): void {
		if ( self::pro_is_active() ) {
			return;
		}

		$features = [
			__( 'Recipes: an ingredient bill and a schedule template — offset days, recurrence, gravity conditions', 'bluefairy-brew-tracker' ),
			__( 'A seeded recipe library of common meads, wines, ciders and beers, each startable at 1, 3 or 5 US gallons', 'bluefairy-brew-tracker' ),
			__( 'Start a batch from a recipe: the bill scaled to your volume, the additions logged as planned, every task generated with its due date', 'bluefairy-brew-tracker' ),
			__( 'Bottle count and priming sugar from the batch volume and bottle size', 'bluefairy-brew-tracker' ),
			__( 'Staggered nutrient schedule preset (day 0 / 1 / 2 / 7) and gravity-check recurrence', 'bluefairy-brew-tracker' ),
			__( 'Gravity-conditioned tasks — "rack when SG ≤ 1.000" becomes due when a reading satisfies it', 'bluefairy-brew-tracker' ),
			__( 'A daily digest email: overdue, due today, due tomorrow, readings overdue, silent batches', 'bluefairy-brew-tracker' ),
			__( 'A "Due today" dashboard widget, an admin calendar and an .ics feed', 'bluefairy-brew-tracker' ),
			__( 'Extended stages: cold crash, stabilised, back-sweetened, conditioning, carbonating', 'bluefairy-brew-tracker' ),
			__( 'A vessel board: which vessel holds what, and which are free', 'bluefairy-brew-tracker' ),
			__( 'A Brewer role and front-end shortcodes: board, tap-to-log, calendar', 'bluefairy-brew-tracker' ),
			__( 'Tap-to-add: the recipe\'s ingredients as one-tap buttons on a phone-sized form', 'bluefairy-brew-tracker' ),
			__( 'Reports: batches per style, average ABV and attenuation, days per stage, vessel utilisation', 'bluefairy-brew-tracker' ),
			__( 'Clone or re-brew a batch', 'bluefairy-brew-tracker' ),
			__( 'An ingredient library with default units', 'bluefairy-brew-tracker' ),
		];

		echo '<div class="bfbt-upsell"><hr><h2>' . esc_html__( 'Brew Tracker Pro', 'bluefairy-brew-tracker' ) . '</h2>';
		echo '<p>' . esc_html__( 'The add-on installs alongside this plugin and keeps every batch you have already logged.', 'bluefairy-brew-tracker' ) . '</p>';
		echo '<ul>';

		foreach ( $features as $feature ) {
			echo '<li>' . esc_html( $feature ) . '</li>';
		}

		echo '</ul>';
		printf(
			'<p><a class="button button-primary" href="%s" target="_blank" rel="noopener noreferrer">%s</a></p></div>',
			esc_url( self::PRODUCT_URL ),
			esc_html__( 'Get Brew Tracker Pro', 'bluefairy-brew-tracker' )
		);
	}
}
