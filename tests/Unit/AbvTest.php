<?php
/**
 * ABV in both formula bands, and attenuation.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AbvTest extends TestCase {

	public function test_standard_band_1050_to_1010_is_5_25(): void {
		$this->assertEqualsWithDelta( 5.25, BFBT_ABV::from_gravities( 1.050, 1.010 ), 0.0001 );
		$this->assertSame( 'standard', BFBT_ABV::formula_key( 1.050 ) );
	}

	public function test_1070_is_still_the_standard_formula(): void {
		$this->assertSame( 'standard', BFBT_ABV::formula_key( 1.070 ) );
		$this->assertEqualsWithDelta( ( 1.070 - 1.010 ) * 131.25, BFBT_ABV::from_gravities( 1.070, 1.010 ), 0.0001 );
	}

	public function test_high_gravity_1110_to_1000_uses_the_alternate_formula(): void {
		$this->assertSame( 'alternate', BFBT_ABV::formula_key( 1.110 ) );

		$abv = BFBT_ABV::from_gravities( 1.110, 1.000 );

		// The standard formula would say 14.4375; the alternate says more.
		$this->assertGreaterThan( 14.44, $abv );
		$this->assertEqualsWithDelta( 76.08 * 0.110 / 0.665 * ( 1.000 / 0.794 ), $abv, 0.0001 );
		$this->assertStringContainsString( '76.08', BFBT_ABV::formula_name( 1.110 ) );
		$this->assertStringContainsString( '131.25', BFBT_ABV::formula_name( 1.050 ) );
	}

	public function test_attenuation(): void {
		$this->assertEqualsWithDelta( 80.0, BFBT_ABV::attenuation( 1.050, 1.010 ), 0.0001 );
		$this->assertEqualsWithDelta( 100.0, BFBT_ABV::attenuation( 1.110, 1.000 ), 0.0001 );
		$this->assertSame( 0.0, BFBT_ABV::attenuation( 1.000, 1.000 ) );
	}
}
