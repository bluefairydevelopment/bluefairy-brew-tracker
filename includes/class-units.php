<?php
/**
 * Unit conversion.
 *
 * Volumes are stored in litres, mass in grams, temperature in °C. The
 * bfbt_units option (us / metric) decides how DERIVED volumes — batch size,
 * bottle count, priming — are displayed. It never touches a logged quantity:
 * the unit a maker typed on an entry is authoritative and is displayed
 * verbatim, so a 1.2 g Fermaid-O dose is never re-rendered as 0.04 oz.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class BFBT_Units {

	public const OPTION = 'bfbt_units';

	public const US     = 'us';
	public const METRIC = 'metric';

	/** Litres per unit. `gal` is the US gallon. */
	private const VOLUME = [
		'ml'  => 0.001,
		'l'   => 1.0,
		'gal' => 3.785411784,
	];

	/** Grams per unit. */
	private const MASS = [
		'g'  => 1.0,
		'kg' => 1000.0,
		'oz' => 28.349523125,
		'lb' => 453.59237,
	];

	/**
	 * @return string[]
	 */
	public static function volume_units(): array {
		return array_keys( self::VOLUME );
	}

	/**
	 * @return string[]
	 */
	public static function mass_units(): array {
		return array_keys( self::MASS );
	}

	public static function is_volume_unit( string $unit ): bool {
		return isset( self::VOLUME[ $unit ] );
	}

	public static function is_mass_unit( string $unit ): bool {
		return isset( self::MASS[ $unit ] );
	}

	/**
	 * @return float|null Null when the unit is not a volume unit this plugin knows.
	 */
	public static function to_litres( float $quantity, string $unit ): ?float {
		return isset( self::VOLUME[ $unit ] ) ? $quantity * self::VOLUME[ $unit ] : null;
	}

	/**
	 * @return float|null Null when the unit is not a volume unit this plugin knows.
	 */
	public static function from_litres( float $litres, string $unit ): ?float {
		return isset( self::VOLUME[ $unit ] ) ? $litres / self::VOLUME[ $unit ] : null;
	}

	/**
	 * @return float|null Null when the unit is not a mass unit this plugin knows.
	 */
	public static function to_grams( float $quantity, string $unit ): ?float {
		return isset( self::MASS[ $unit ] ) ? $quantity * self::MASS[ $unit ] : null;
	}

	/**
	 * @return float|null Null when the unit is not a mass unit this plugin knows.
	 */
	public static function from_grams( float $grams, string $unit ): ?float {
		return isset( self::MASS[ $unit ] ) ? $grams / self::MASS[ $unit ] : null;
	}

	/**
	 * The display setting: 'us' or 'metric'. Anything else stored reads as 'us'.
	 */
	public static function setting(): string {
		$stored = (string) get_option( self::OPTION, self::US );

		/**
		 * Filter the display-unit setting.
		 *
		 * @param string $setting 'us' or 'metric'.
		 */
		$setting = (string) apply_filters( 'bfbt_units', $stored );

		return self::METRIC === $setting ? self::METRIC : self::US;
	}

	/**
	 * A derived volume in the display unit, e.g. "1.00 gal" or "3.79 l".
	 */
	public static function display_volume( float $litres, int $decimals = 2 ): string {
		if ( self::METRIC === self::setting() ) {
			return number_format( $litres, $decimals, '.', '' ) . ' l';
		}

		return number_format( (float) self::from_litres( $litres, 'gal' ), $decimals, '.', '' ) . ' gal';
	}

	/**
	 * A derived mass in the display unit, e.g. "3.00 lb" or "1360.78 g".
	 */
	public static function display_mass( float $grams, int $decimals = 2 ): string {
		if ( self::METRIC === self::setting() ) {
			return number_format( $grams, $decimals, '.', '' ) . ' g';
		}

		return number_format( (float) self::from_grams( $grams, 'lb' ), $decimals, '.', '' ) . ' lb';
	}
}
