<?php
/**
 * Field validation. Refuses, never guesses.
 *
 * Every method takes the string the user typed and returns either the
 * normalised value or a WP_Error whose message quotes the refused value back,
 * so a notice can say exactly what was not accepted. A handler that receives
 * a WP_Error stops; nothing here is ever "cleaned up" into something the user
 * did not type.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class BFBT_Validate {

	public const GRAVITY_MIN = 0.980;
	public const GRAVITY_MAX = 1.200;

	public const TEMP_MIN = -10.0;
	public const TEMP_MAX = 60.0;

	public const PH_MIN = 0.0;
	public const PH_MAX = 14.0;

	/** Units a logged quantity may carry. The entered unit is stored verbatim. */
	public const UNITS = [ 'g', 'kg', 'oz', 'lb', 'ml', 'l', 'gal', 'tsp', 'tbsp', 'pkt', 'tablet', 'each' ];

	/**
	 * A real calendar date in year-month-day, not merely something shaped
	 * like one. 2026-02-30 has the right shape and is not a day.
	 *
	 * @return string|WP_Error The date as typed, or '' when empty is allowed.
	 */
	public static function date( string $value, bool $allow_empty = false ): string|WP_Error {
		$value = trim( $value );

		if ( '' === $value ) {
			return $allow_empty ? '' : new WP_Error( 'bfbt_date_required', __( 'A date is required.', 'bluefairy-brew-tracker' ) );
		}

		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return new WP_Error(
				'bfbt_date_format',
				sprintf(
					/* translators: %s: the date the user typed */
					__( '“%s” is not a date this plugin can read. Use year-month-day, for example 2026-03-14.', 'bluefairy-brew-tracker' ),
					$value
				)
			);
		}

		return $value;
	}

	/**
	 * A calendar-checked date and a 24-hour time, "Y-m-d H:i".
	 *
	 * @return string|WP_Error
	 */
	public static function datetime( string $value, bool $allow_empty = false ): string|WP_Error {
		$value = trim( $value );

		if ( '' === $value ) {
			return $allow_empty ? '' : new WP_Error( 'bfbt_datetime_required', __( 'A date and time are required.', 'bluefairy-brew-tracker' ) );
		}

		$ok = 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})$/', $value, $m )
			&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] )
			&& (int) $m[4] < 24
			&& (int) $m[5] < 60;

		if ( ! $ok ) {
			return new WP_Error(
				'bfbt_datetime_format',
				sprintf(
					/* translators: %s: the value the user typed */
					__( '“%s” is not a date and time this plugin can read. Use year-month-day hour:minute, for example 2026-03-14 09:30.', 'bluefairy-brew-tracker' ),
					$value
				)
			);
		}

		return $value;
	}

	/**
	 * Specific gravity as 1.xxx, in 0.980–1.200.
	 *
	 * Only a dot decimal is accepted. "1090", "90" and "1,090" are refused with
	 * a hint rather than reinterpreted: a value that looks like Brix or points
	 * is not silently turned into an SG the maker did not measure.
	 *
	 * @return string|WP_Error Three decimal places, e.g. "1.090".
	 */
	public static function gravity( string $value, bool $allow_empty = false ): string|WP_Error {
		$value = trim( $value );

		if ( '' === $value ) {
			return $allow_empty ? '' : new WP_Error( 'bfbt_gravity_required', __( 'A gravity reading is required.', 'bluefairy-brew-tracker' ) );
		}

		if ( 1 !== preg_match( '/^\d\.\d{1,3}$/', $value ) ) {
			return new WP_Error(
				'bfbt_gravity_format',
				sprintf(
					/* translators: %s: the value the user typed */
					__( '“%s” is not a specific gravity this plugin can read — enter as 1.090.', 'bluefairy-brew-tracker' ),
					$value
				)
			);
		}

		$thousandths = (int) round( (float) $value * 1000 );

		if ( $thousandths < (int) round( self::GRAVITY_MIN * 1000 ) || $thousandths > (int) round( self::GRAVITY_MAX * 1000 ) ) {
			return new WP_Error(
				'bfbt_gravity_range',
				sprintf(
					/* translators: 1: the value the user typed, 2: lowest accepted, 3: highest accepted */
					__( '“%1$s” is outside the specific gravity range this plugin accepts (%2$s to %3$s).', 'bluefairy-brew-tracker' ),
					$value,
					number_format( self::GRAVITY_MIN, 3, '.', '' ),
					number_format( self::GRAVITY_MAX, 3, '.', '' )
				)
			);
		}

		return number_format( $thousandths / 1000, 3, '.', '' );
	}

	/**
	 * A non-negative decimal, kept to three places.
	 *
	 * @return string|WP_Error e.g. "1.200".
	 */
	public static function quantity( string $value, bool $allow_empty = false ): string|WP_Error {
		$value = trim( $value );

		if ( '' === $value ) {
			return $allow_empty ? '' : new WP_Error( 'bfbt_quantity_required', __( 'A quantity is required.', 'bluefairy-brew-tracker' ) );
		}

		if ( 1 !== preg_match( '/^\d+(\.\d+)?$|^\.\d+$/', $value ) ) {
			return new WP_Error(
				'bfbt_quantity_format',
				sprintf(
					/* translators: %s: the value the user typed */
					__( '“%s” is not a quantity this plugin can read. Use a number of zero or more, with a dot for decimals, for example 1.2.', 'bluefairy-brew-tracker' ),
					$value
				)
			);
		}

		return number_format( (float) $value, 3, '.', '' );
	}

	/**
	 * One of the fixed unit list, lower-cased.
	 *
	 * @return string|WP_Error
	 */
	public static function unit( string $value ): string|WP_Error {
		$unit = strtolower( trim( $value ) );

		if ( ! in_array( $unit, self::UNITS, true ) ) {
			return new WP_Error(
				'bfbt_unit',
				sprintf(
					/* translators: 1: the unit the user typed, 2: the accepted list */
					__( '“%1$s” is not a unit this plugin accepts. Use one of: %2$s.', 'bluefairy-brew-tracker' ),
					trim( $value ),
					implode( ', ', self::UNITS )
				)
			);
		}

		return $unit;
	}

	/**
	 * A temperature in °C, −10…60.
	 *
	 * @return string|WP_Error One decimal place.
	 */
	public static function temperature_c( string $value, bool $allow_empty = false ): string|WP_Error {
		$value = trim( $value );

		if ( '' === $value ) {
			return $allow_empty ? '' : new WP_Error( 'bfbt_temperature_required', __( 'A temperature is required.', 'bluefairy-brew-tracker' ) );
		}

		if ( 1 !== preg_match( '/^-?\d+(\.\d+)?$/', $value ) ) {
			return new WP_Error(
				'bfbt_temperature_format',
				sprintf(
					/* translators: %s: the value the user typed */
					__( '“%s” is not a temperature this plugin can read. Enter degrees Celsius as a number, for example 20.5.', 'bluefairy-brew-tracker' ),
					$value
				)
			);
		}

		$number = (float) $value;

		if ( $number < self::TEMP_MIN || $number > self::TEMP_MAX ) {
			return new WP_Error(
				'bfbt_temperature_range',
				sprintf(
					/* translators: 1: the value the user typed, 2: lowest accepted, 3: highest accepted */
					__( '“%1$s” is outside the temperature range this plugin accepts (%2$s to %3$s °C).', 'bluefairy-brew-tracker' ),
					$value,
					number_format( self::TEMP_MIN, 0, '.', '' ),
					number_format( self::TEMP_MAX, 0, '.', '' )
				)
			);
		}

		return number_format( $number, 1, '.', '' );
	}

	/**
	 * A pH, 0…14.
	 *
	 * @return string|WP_Error Two decimal places.
	 */
	public static function ph( string $value, bool $allow_empty = false ): string|WP_Error {
		$value = trim( $value );

		if ( '' === $value ) {
			return $allow_empty ? '' : new WP_Error( 'bfbt_ph_required', __( 'A pH is required.', 'bluefairy-brew-tracker' ) );
		}

		if ( 1 !== preg_match( '/^\d+(\.\d+)?$/', $value ) ) {
			return new WP_Error(
				'bfbt_ph_format',
				sprintf(
					/* translators: %s: the value the user typed */
					__( '“%s” is not a pH this plugin can read. Enter a number, for example 3.4.', 'bluefairy-brew-tracker' ),
					$value
				)
			);
		}

		$number = (float) $value;

		if ( $number < self::PH_MIN || $number > self::PH_MAX ) {
			return new WP_Error(
				'bfbt_ph_range',
				sprintf(
					/* translators: 1: the value the user typed, 2: lowest accepted, 3: highest accepted */
					__( '“%1$s” is outside the pH range this plugin accepts (%2$s to %3$s).', 'bluefairy-brew-tracker' ),
					$value,
					number_format( self::PH_MIN, 0, '.', '' ),
					number_format( self::PH_MAX, 0, '.', '' )
				)
			);
		}

		return number_format( $number, 2, '.', '' );
	}

	/**
	 * A batch volume: a positive quantity in a volume unit, returned in litres.
	 *
	 * @return float|WP_Error Litres, to three decimal places.
	 */
	public static function volume( string $value, string $unit ): float|WP_Error {
		$quantity = self::quantity( $value );

		if ( is_wp_error( $quantity ) ) {
			return $quantity;
		}

		$unit = strtolower( trim( $unit ) );

		if ( ! BFBT_Units::is_volume_unit( $unit ) ) {
			return new WP_Error(
				'bfbt_volume_unit',
				sprintf(
					/* translators: 1: the unit the user typed, 2: the accepted list */
					__( '“%1$s” is not a volume unit this plugin accepts. Use one of: %2$s.', 'bluefairy-brew-tracker' ),
					trim( $unit ),
					implode( ', ', BFBT_Units::volume_units() )
				)
			);
		}

		$litres = round( (float) BFBT_Units::to_litres( (float) $quantity, $unit ), 3 );

		if ( $litres <= 0 ) {
			return new WP_Error(
				'bfbt_volume_zero',
				sprintf(
					/* translators: %s: the volume the user typed */
					__( '“%s” is not a batch volume — it has to be more than zero.', 'bluefairy-brew-tracker' ),
					trim( $value ) . ' ' . $unit
				)
			);
		}

		return $litres;
	}
}
