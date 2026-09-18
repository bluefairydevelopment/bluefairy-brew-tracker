<?php
/**
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FeatureRegistryTest extends TestCase {

	protected function setUp(): void {
		bfbt_reset_stubs();
	}

	public function test_a_feature_with_no_stored_value_is_on(): void {
		$this->assertTrue( BFBT_Feature_Registry::is_enabled( 'reports' ) );
	}

	/**
	 * Pro's switches arrive after this option was last written; defaulting an
	 * unknown key to off would silently disable something just paid for.
	 */
	public function test_a_feature_the_stored_option_predates_is_on(): void {
		$GLOBALS['bfbt_options']['bfbt_features'] = [ 'calendar' => true ];

		$this->assertTrue( BFBT_Feature_Registry::is_enabled( 'reports' ) );
	}

	public function test_a_feature_stored_as_off_is_off(): void {
		$GLOBALS['bfbt_options']['bfbt_features'] = [ 'calendar' => false ];

		$this->assertFalse( BFBT_Feature_Registry::is_enabled( 'calendar' ) );
	}

	public function test_only_keys_with_a_label_are_stored(): void {
		$GLOBALS['bfbt_filters']['bfbt_feature_labels'] = static fn( array $l ): array => $l + [ 'calendar' => 'Calendar' ];

		$stored = BFBT_Feature_Registry::sanitize( [ 'calendar' => '1', 'smuggled' => '1' ] );

		$this->assertSame( [ 'calendar' ], array_keys( $stored ) );
	}
}
