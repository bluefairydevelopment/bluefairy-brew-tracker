<?php
/**
 * The SVG chart: empty message, escaping, numbers as numbers.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ChartTest extends TestCase {

	public function test_an_empty_series_is_a_short_paragraph(): void {
		$out = BFBT_Chart::svg( [] );

		$this->assertStringContainsString( 'No readings yet', $out );
		$this->assertStringNotContainsString( '<svg', $out );
	}

	public function test_a_label_carrying_script_is_escaped(): void {
		$out = BFBT_Chart::svg( [
			[ 'time' => '2026-09-18 10:00:00', 'sg' => '1.100', 'label' => '<script>alert(1)</script>' ],
			[ 'time' => '2026-09-21 10:00:00', 'sg' => 1.040, 'label' => 'SG 1.040' ],
		] );

		$this->assertStringStartsWith( '<svg', $out );
		$this->assertStringNotContainsString( '<script>', $out );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $out );
		$this->assertStringContainsString( 'viewBox="0 0 640 240"', $out );
		$this->assertStringContainsString( '<polyline', $out );
		$this->assertSame( 2, substr_count( $out, '<circle' ) );
		$this->assertStringContainsString( '>1.100<', $out );
		$this->assertStringContainsString( '>1.040<', $out );
	}

	public function test_a_non_numeric_sg_is_cast_not_echoed(): void {
		$out = BFBT_Chart::svg( [ [ 'time' => '2026-09-18 10:00:00', 'sg' => '1.050" onload="x' ] ] );

		$this->assertStringNotContainsString( 'onload', $out );
		$this->assertStringContainsString( '1.050', $out );
	}
}
