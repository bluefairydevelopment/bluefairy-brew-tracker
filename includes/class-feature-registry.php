<?php
/**
 * The feature switches, and the seam the Pro add-on adds its own to.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class BFBT_Feature_Registry {

	public const OPTION = 'bfbt_features';

	/**
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return (array) apply_filters( 'bfbt_feature_labels', [] );
	}

	/**
	 * @return array<string, string>
	 */
	public static function descriptions(): array {
		return (array) apply_filters( 'bfbt_feature_descriptions', [] );
	}

	/**
	 * An unknown key counts as on.
	 *
	 * A feature the add-on introduces after this option was last written has no
	 * stored value, and defaulting it to off would silently disable something a
	 * customer had just paid for.
	 */
	public static function is_enabled( string $key ): bool {
		$stored = (array) get_option( self::OPTION, [] );

		return array_key_exists( $key, $stored ) ? (bool) $stored[ $key ] : true;
	}

	/**
	 * Rebuild from the label list, which is what the form renders from.
	 *
	 * @param  mixed $input
	 * @return array<string, bool>
	 */
	public static function sanitize( mixed $input ): array {
		$input  = is_array( $input ) ? $input : [];
		$result = [];

		foreach ( array_keys( self::labels() ) as $key ) {
			$result[ $key ] = ! empty( $input[ $key ] );
		}

		return $result;
	}
}
