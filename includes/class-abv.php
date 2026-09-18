<?php
/**
 * Alcohol by volume and apparent attenuation, from two gravities.
 *
 * Two formulas, both named in the UI. The standard (OG − FG) × 131.25 is
 * accurate to about 1.070; above that it under-reports by around 1.4 % ABV,
 * which is most of the difference between a session mead and a sack mead,
 * so high-gravity batches use the alternate formula.
 *
 * Pure functions; unit-tested in both bands.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class BFBT_ABV {

	/** The OG at and below which the standard formula is used. */
	public const STANDARD_MAX_OG = 1.070;

	public const STANDARD  = 'standard';
	public const ALTERNATE = 'alternate';

	/**
	 * Alcohol by volume, per cent.
	 */
	public static function from_gravities( float $og, float $fg ): float {
		if ( self::ALTERNATE === self::formula_key( $og ) ) {
			return 76.08 * ( $og - $fg ) / ( 1.775 - $og ) * ( $fg / 0.794 );
		}

		return ( $og - $fg ) * 131.25;
	}

	/**
	 * Apparent attenuation, per cent of the fermentable gravity consumed.
	 * Undefined at OG 1.000 (nothing to ferment); answers 0 rather than divide.
	 */
	public static function attenuation( float $og, float $fg ): float {
		$points = $og - 1.0;

		if ( $points <= 0.0 ) {
			return 0.0;
		}

		return ( $og - $fg ) / $points * 100;
	}

	public static function formula_key( float $og ): string {
		// Compare in thousandths so 1.070 typed as "1.070" is not nudged over
		// the line by float representation.
		return (int) round( $og * 1000 ) <= (int) round( self::STANDARD_MAX_OG * 1000 ) ? self::STANDARD : self::ALTERNATE;
	}

	/**
	 * The formula, as the UI states it.
	 */
	public static function formula_name( float $og ): string {
		if ( self::ALTERNATE === self::formula_key( $og ) ) {
			return __( '76.08 × (OG − FG) / (1.775 − OG) × (FG / 0.794), the high-gravity formula for OG above 1.070', 'bluefairy-brew-tracker' );
		}

		return __( '(OG − FG) × 131.25, the standard formula for OG up to 1.070', 'bluefairy-brew-tracker' );
	}
}
