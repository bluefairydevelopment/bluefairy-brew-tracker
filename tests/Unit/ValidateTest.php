<?php
/**
 * Validation refuses, never guesses — and says what it refused.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidateTest extends TestCase {

	/**
	 * Every refusal quotes the value back, so the notice can say exactly
	 * what was not accepted.
	 */
	private function assertRefusedQuoting( mixed $result, string $typed ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( '“' . $typed . '”', $result->get_error_message() );
	}

	// ---- date ----

	public function test_a_real_date_is_accepted(): void {
		$this->assertSame( '2026-03-14', BFBT_Validate::date( '2026-03-14' ) );
	}

	public function test_a_leap_day_that_exists_is_accepted(): void {
		$this->assertSame( '2028-02-29', BFBT_Validate::date( '2028-02-29' ) );
	}

	public function test_surrounding_whitespace_is_trimmed(): void {
		$this->assertSame( '2026-03-14', BFBT_Validate::date( ' 2026-03-14 ' ) );
	}

	/**
	 * A regex alone passes 2026-02-30: right shape, not a day.
	 */
	#[DataProvider( 'bad_dates' )]
	public function test_a_date_that_is_not_a_day_is_refused_with_the_value_quoted( string $typed ): void {
		$this->assertRefusedQuoting( BFBT_Validate::date( $typed ), $typed );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function bad_dates(): array {
		return [
			'day that does not exist'      => [ '2026-02-30' ],
			'month that does not exist'    => [ '2026-13-01' ],
			'leap day in a common year'    => [ '2026-02-29' ],
			'american ordering'            => [ '03/14/2026' ],
			'date with a time'             => [ '2026-03-14 09:00:00' ],
			'two-digit year'               => [ '26-03-14' ],
			'words'                        => [ 'yesterday' ],
		];
	}

	public function test_an_empty_date_is_refused_unless_allowed(): void {
		$this->assertInstanceOf( WP_Error::class, BFBT_Validate::date( '' ) );
		$this->assertSame( '', BFBT_Validate::date( '', true ) );
		$this->assertSame( '', BFBT_Validate::date( '   ', true ) );
	}

	// ---- datetime ----

	public function test_a_date_and_time_is_accepted(): void {
		$this->assertSame( '2026-03-14 09:30', BFBT_Validate::datetime( '2026-03-14 09:30' ) );
		$this->assertSame( '2026-03-14 23:59', BFBT_Validate::datetime( '2026-03-14 23:59' ) );
		$this->assertSame( '2026-03-14 00:00', BFBT_Validate::datetime( '2026-03-14 00:00' ) );
	}

	#[DataProvider( 'bad_datetimes' )]
	public function test_a_bad_date_and_time_is_refused_with_the_value_quoted( string $typed ): void {
		$this->assertRefusedQuoting( BFBT_Validate::datetime( $typed ), $typed );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function bad_datetimes(): array {
		return [
			'date only'         => [ '2026-03-14' ],
			'non-day'           => [ '2026-02-30 09:30' ],
			'hour 24'           => [ '2026-03-14 24:00' ],
			'minute 60'         => [ '2026-03-14 09:60' ],
			'with seconds'      => [ '2026-03-14 09:30:00' ],
			'american ordering' => [ '03/14/2026 09:30' ],
		];
	}

	public function test_an_empty_datetime_is_refused_unless_allowed(): void {
		$this->assertInstanceOf( WP_Error::class, BFBT_Validate::datetime( '' ) );
		$this->assertSame( '', BFBT_Validate::datetime( '', true ) );
	}

	// ---- gravity ----

	public function test_a_gravity_is_normalised_to_three_places(): void {
		$this->assertSame( '1.090', BFBT_Validate::gravity( '1.090' ) );
		$this->assertSame( '1.090', BFBT_Validate::gravity( '1.09' ) );
		$this->assertSame( '1.000', BFBT_Validate::gravity( '1.0' ) );
		$this->assertSame( '0.998', BFBT_Validate::gravity( '0.998' ) );
	}

	public function test_the_lowest_gravity_in_range_is_accepted(): void {
		$this->assertSame( '0.980', BFBT_Validate::gravity( '0.980' ) );
	}

	public function test_the_highest_gravity_in_range_is_accepted(): void {
		$this->assertSame( '1.200', BFBT_Validate::gravity( '1.200' ) );
	}

	public function test_one_thousandth_below_the_range_is_refused(): void {
		$this->assertRefusedQuoting( BFBT_Validate::gravity( '0.979' ), '0.979' );
	}

	public function test_one_thousandth_above_the_range_is_refused(): void {
		$this->assertRefusedQuoting( BFBT_Validate::gravity( '1.201' ), '1.201' );
	}

	/**
	 * "1090" is what someone reading points off a hydrometer types, and
	 * "90" is what they say. Neither is guessed into 1.090.
	 */
	#[DataProvider( 'gravities_that_need_the_hint' )]
	public function test_a_gravity_without_the_decimal_is_refused_with_the_hint( string $typed ): void {
		$result = BFBT_Validate::gravity( $typed );

		$this->assertRefusedQuoting( $result, $typed );
		$this->assertStringContainsString( 'enter as 1.090', $result->get_error_message() );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function gravities_that_need_the_hint(): array {
		return [
			'points'          => [ '1090' ],
			'short points'    => [ '90' ],
			'comma decimal'   => [ '1,090' ],
			'brix-ish'        => [ '21.5' ],
			'words'           => [ 'dry' ],
			'four places'     => [ '1.0900' ],
			'leading plus'    => [ '+1.090' ],
		];
	}

	public function test_an_empty_gravity_is_refused_unless_allowed(): void {
		$this->assertInstanceOf( WP_Error::class, BFBT_Validate::gravity( '' ) );
		$this->assertSame( '', BFBT_Validate::gravity( '', true ) );
	}

	// ---- quantity ----

	public function test_a_quantity_is_normalised_to_three_places(): void {
		$this->assertSame( '1.200', BFBT_Validate::quantity( '1.2' ) );
		$this->assertSame( '0.000', BFBT_Validate::quantity( '0' ) );
		$this->assertSame( '0.500', BFBT_Validate::quantity( '.5' ) );
		$this->assertSame( '3.000', BFBT_Validate::quantity( '3' ) );
		$this->assertSame( '0.001', BFBT_Validate::quantity( '0.0014' ) );
	}

	#[DataProvider( 'bad_quantities' )]
	public function test_a_bad_quantity_is_refused_with_the_value_quoted( string $typed ): void {
		$this->assertRefusedQuoting( BFBT_Validate::quantity( $typed ), $typed );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function bad_quantities(): array {
		return [
			'negative'      => [ '-1' ],
			'comma decimal' => [ '1,5' ],
			'with unit'     => [ '3 lb' ],
			'words'         => [ 'some' ],
		];
	}

	public function test_an_empty_quantity_is_refused_unless_allowed(): void {
		$this->assertInstanceOf( WP_Error::class, BFBT_Validate::quantity( '' ) );
		$this->assertSame( '', BFBT_Validate::quantity( '', true ) );
	}

	// ---- unit ----

	public function test_every_listed_unit_is_accepted_and_lower_cased(): void {
		foreach ( [ 'g', 'kg', 'oz', 'lb', 'ml', 'l', 'gal', 'tsp', 'tbsp', 'pkt', 'tablet', 'each' ] as $unit ) {
			$this->assertSame( $unit, BFBT_Validate::unit( strtoupper( $unit ) ) );
		}
	}

	#[DataProvider( 'bad_units' )]
	public function test_a_unit_off_the_list_is_refused_with_the_value_quoted( string $typed ): void {
		$this->assertRefusedQuoting( BFBT_Validate::unit( $typed ), $typed );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function bad_units(): array {
		return [
			'cup'    => [ 'cup' ],
			'grams'  => [ 'grams' ],
			'empty'  => [ '' ],
		];
	}

	// ---- temperature ----

	public function test_a_temperature_is_normalised_to_one_place(): void {
		$this->assertSame( '20.5', BFBT_Validate::temperature_c( '20.5' ) );
		$this->assertSame( '20.0', BFBT_Validate::temperature_c( '20' ) );
		$this->assertSame( '-10.0', BFBT_Validate::temperature_c( '-10' ) );
		$this->assertSame( '60.0', BFBT_Validate::temperature_c( '60' ) );
	}

	#[DataProvider( 'bad_temperatures' )]
	public function test_a_bad_temperature_is_refused_with_the_value_quoted( string $typed ): void {
		$this->assertRefusedQuoting( BFBT_Validate::temperature_c( $typed ), $typed );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function bad_temperatures(): array {
		return [
			'below range' => [ '-10.1' ],
			'above range' => [ '60.1' ],
			'fahrenheit'  => [ '68F' ],
			'words'       => [ 'warm' ],
		];
	}

	public function test_an_empty_temperature_is_refused_unless_allowed(): void {
		$this->assertInstanceOf( WP_Error::class, BFBT_Validate::temperature_c( '' ) );
		$this->assertSame( '', BFBT_Validate::temperature_c( '', true ) );
		$this->assertInstanceOf( WP_Error::class, BFBT_Validate::temperature( '', 'f' ) );
		$this->assertSame( '', BFBT_Validate::temperature( '', 'f', true ) );
	}

	// ---- temperature in the unit it was typed in ----

	public function test_a_fahrenheit_reading_is_returned_in_celsius(): void {
		$this->assertSame( '23.3', BFBT_Validate::temperature( '74', 'f' ) );
		$this->assertSame( '23.3', BFBT_Validate::temperature( '74', 'F' ) );
		$this->assertSame( '20.0', BFBT_Validate::temperature( '68', 'f' ) );
		$this->assertSame( '20.0', BFBT_Validate::temperature( '20', 'c' ) );
	}

	public function test_the_fahrenheit_boundaries_are_accepted(): void {
		$this->assertSame( '-10.0', BFBT_Validate::temperature( '14', 'f' ) );
		$this->assertSame( '60.0', BFBT_Validate::temperature( '140', 'f' ) );
	}

	public function test_one_degree_outside_the_fahrenheit_range_is_refused_with_the_fahrenheit_wording(): void {
		foreach ( [ '13', '141' ] as $typed ) {
			$result = BFBT_Validate::temperature( $typed, 'f' );

			$this->assertRefusedQuoting( $result, $typed );
			$this->assertStringContainsString( 'is outside the temperature range this plugin accepts (14 to 140 °F).', $result->get_error_message() );
			$this->assertStringNotContainsString( '°C', $result->get_error_message() );
			$this->assertStringNotContainsString( 'If you meant Fahrenheit', $result->get_error_message() );
		}
	}

	public function test_a_celsius_refusal_names_the_celsius_range(): void {
		$result = BFBT_Validate::temperature( '-10.1', 'c' );

		$this->assertRefusedQuoting( $result, '-10.1' );
		$this->assertStringContainsString( '(-10 to 60 °C).', $result->get_error_message() );
		$this->assertStringNotContainsString( 'If you meant Fahrenheit', $result->get_error_message() );
	}

	/**
	 * 74 typed as °C is almost certainly 74 °F. The value is refused — never
	 * converted — and the notice points at the unit select and the setting.
	 */
	public function test_a_celsius_value_that_looks_like_fahrenheit_gets_the_hint(): void {
		$result = BFBT_Validate::temperature( '74', 'c' );

		$this->assertRefusedQuoting( $result, '74' );
		$this->assertSame(
			'“74” is outside the temperature range this plugin accepts (-10 to 60 °C). If you meant Fahrenheit, pick °F next to the field or set your units to US in Settings.',
			$result->get_error_message()
		);
	}

	public function test_the_fahrenheit_hint_is_not_given_where_it_does_not_apply(): void {
		// In range: accepted, no message at all.
		$this->assertSame( '20.0', BFBT_Validate::temperature( '20', 'c' ) );

		// Above 140: not a Fahrenheit reading either.
		$hot = BFBT_Validate::temperature( '500', 'c' );
		$this->assertInstanceOf( WP_Error::class, $hot );
		$this->assertStringNotContainsString( 'If you meant Fahrenheit', $hot->get_error_message() );

		// The same number typed as °F is out of range on its own terms.
		$f = BFBT_Validate::temperature( '500', 'f' );
		$this->assertStringContainsString( '(14 to 140 °F)', $f->get_error_message() );
	}

	public function test_a_temperature_format_refusal_names_the_unit(): void {
		$result = BFBT_Validate::temperature( 'warm', 'f' );

		$this->assertRefusedQuoting( $result, 'warm' );
		$this->assertStringContainsString( '20.5 °F', $result->get_error_message() );
	}

	public function test_a_temperature_unit_off_the_list_is_refused(): void {
		$result = BFBT_Validate::temperature( '20', 'k' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'bfbt_temperature_unit', $result->get_error_code() );
		$this->assertStringContainsString( '“k”', $result->get_error_message() );
	}

	public function test_temperature_c_is_the_celsius_case_of_temperature(): void {
		$this->assertSame( BFBT_Validate::temperature( '20.5', 'c' ), BFBT_Validate::temperature_c( '20.5' ) );
		$this->assertSame(
			BFBT_Validate::temperature( '74', 'c' )->get_error_message(),
			BFBT_Validate::temperature_c( '74' )->get_error_message()
		);
	}

	// ---- pH ----

	public function test_a_ph_is_normalised_to_two_places(): void {
		$this->assertSame( '3.40', BFBT_Validate::ph( '3.4' ) );
		$this->assertSame( '0.00', BFBT_Validate::ph( '0' ) );
		$this->assertSame( '14.00', BFBT_Validate::ph( '14' ) );
	}

	#[DataProvider( 'bad_phs' )]
	public function test_a_bad_ph_is_refused_with_the_value_quoted( string $typed ): void {
		$this->assertRefusedQuoting( BFBT_Validate::ph( $typed ), $typed );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function bad_phs(): array {
		return [
			'above range' => [ '14.01' ],
			'negative'    => [ '-1' ],
			'words'       => [ 'acidic' ],
		];
	}

	public function test_an_empty_ph_is_refused_unless_allowed(): void {
		$this->assertInstanceOf( WP_Error::class, BFBT_Validate::ph( '' ) );
		$this->assertSame( '', BFBT_Validate::ph( '', true ) );
	}

	// ---- volume ----

	public function test_a_volume_is_returned_in_litres(): void {
		$this->assertSame( 3.785, BFBT_Validate::volume( '1', 'gal' ) );
		$this->assertSame( 1.0, BFBT_Validate::volume( '1000', 'ml' ) );
		$this->assertSame( 5.0, BFBT_Validate::volume( '5', 'L' ) );
	}

	public function test_a_volume_in_a_mass_unit_is_refused_with_the_unit_quoted(): void {
		$this->assertRefusedQuoting( BFBT_Validate::volume( '3', 'lb' ), 'lb' );
	}

	public function test_a_volume_of_zero_is_refused_with_the_value_quoted(): void {
		$this->assertRefusedQuoting( BFBT_Validate::volume( '0', 'l' ), '0 l' );
	}

	public function test_a_volume_that_is_not_a_number_is_refused_with_the_value_quoted(): void {
		$this->assertRefusedQuoting( BFBT_Validate::volume( 'one', 'gal' ), 'one' );
	}
}
