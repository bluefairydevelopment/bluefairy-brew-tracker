<?php
/**
 * Canonical storage units and the display setting.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UnitsTest extends TestCase {

	protected function setUp(): void {
		bfbt_reset_stubs();
	}

	public function test_one_us_gallon_is_3_785_litres(): void {
		$this->assertEqualsWithDelta( 3.785411784, BFBT_Units::to_litres( 1.0, 'gal' ), 1e-9 );
	}

	public function test_gallons_round_trip_through_litres(): void {
		foreach ( [ 1.0, 3.0, 5.0, 0.25 ] as $gallons ) {
			$litres = BFBT_Units::to_litres( $gallons, 'gal' );

			$this->assertEqualsWithDelta( $gallons, BFBT_Units::from_litres( $litres, 'gal' ), 1e-9 );
		}
	}

	public function test_litres_round_trip_through_gallons(): void {
		$gallons = BFBT_Units::from_litres( 18.927, 'gal' );

		$this->assertEqualsWithDelta( 18.927, BFBT_Units::to_litres( $gallons, 'gal' ), 1e-9 );
	}

	public function test_millilitres_are_a_thousandth(): void {
		$this->assertEqualsWithDelta( 0.5, BFBT_Units::to_litres( 500.0, 'ml' ), 1e-9 );
		$this->assertEqualsWithDelta( 500.0, BFBT_Units::from_litres( 0.5, 'ml' ), 1e-9 );
	}

	public function test_one_pound_is_453_592_grams(): void {
		$this->assertEqualsWithDelta( 453.59237, BFBT_Units::to_grams( 1.0, 'lb' ), 1e-9 );
	}

	public function test_pounds_round_trip_through_grams(): void {
		$grams = BFBT_Units::to_grams( 3.0, 'lb' );

		$this->assertEqualsWithDelta( 1360.77711, $grams, 1e-5 );
		$this->assertEqualsWithDelta( 3.0, BFBT_Units::from_grams( $grams, 'lb' ), 1e-9 );
	}

	public function test_ounces_and_kilograms_round_trip(): void {
		$this->assertEqualsWithDelta( 1.2, BFBT_Units::from_grams( BFBT_Units::to_grams( 1.2, 'oz' ), 'oz' ), 1e-9 );
		$this->assertEqualsWithDelta( 2.5, BFBT_Units::from_grams( BFBT_Units::to_grams( 2.5, 'kg' ), 'kg' ), 1e-9 );
	}

	/**
	 * A unit this class does not know answers null, not 0 and not the
	 * quantity unchanged — both would look like a conversion that happened.
	 */
	public function test_an_unknown_unit_answers_null(): void {
		$this->assertNull( BFBT_Units::to_litres( 1.0, 'lb' ) );
		$this->assertNull( BFBT_Units::from_litres( 1.0, 'cup' ) );
		$this->assertNull( BFBT_Units::to_grams( 1.0, 'gal' ) );
		$this->assertNull( BFBT_Units::from_grams( 1.0, 'each' ) );
	}

	public function test_the_display_setting_defaults_to_us(): void {
		$this->assertSame( 'us', BFBT_Units::setting() );
		$this->assertSame( '1.00 gal', BFBT_Units::display_volume( 3.785411784 ) );
		$this->assertSame( '3.00 lb', BFBT_Units::display_mass( 1360.77711 ) );
	}

	public function test_the_metric_setting_displays_litres_and_grams(): void {
		$GLOBALS['bfbt_options']['bfbt_units'] = 'metric';

		$this->assertSame( 'metric', BFBT_Units::setting() );
		$this->assertSame( '3.79 l', BFBT_Units::display_volume( 3.785411784 ) );
		$this->assertSame( '1360.78 g', BFBT_Units::display_mass( 1360.77711 ) );
	}

	public function test_a_stored_value_that_is_neither_reads_as_us(): void {
		$GLOBALS['bfbt_options']['bfbt_units'] = 'imperial';

		$this->assertSame( 'us', BFBT_Units::setting() );
	}

	public function test_an_add_on_can_override_the_setting(): void {
		$GLOBALS['bfbt_filters']['bfbt_units'] = static fn( string $s ): string => 'metric';

		$this->assertSame( 'metric', BFBT_Units::setting() );
	}
}
