<?php
/**
 * The field registry: the seam Pro adds its milestone fields through.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BatchFieldsTest extends TestCase {

	protected function setUp(): void {
		bfbt_reset_stubs();

		$extra = new ReflectionProperty( BFBT_Batch::class, 'extra_fields' );
		$extra->setValue( null, [] );
	}

	public function test_the_core_fields_are_exactly_the_plan_table(): void {
		$this->assertSame( [
			'started'        => '_bfbt_started',
			'volume_l'       => '_bfbt_volume_l',
			'volume_entered' => '_bfbt_volume_entered',
			'target_og'      => '_bfbt_target_og',
			'target_fg'      => '_bfbt_target_fg',
			'secondary_date' => '_bfbt_secondary_date',
			'packaged_date'  => '_bfbt_packaged_date',
			'completed_date' => '_bfbt_completed_date',
			'dumped'         => '_bfbt_dumped',
			'og_log_id'      => '_bfbt_og_log_id',
		], BFBT_Batch::CORE_FIELDS );
	}

	public function test_fields_are_the_core_fields_before_any_add_on_registers(): void {
		$this->assertSame( BFBT_Batch::CORE_FIELDS, BFBT_Batch::fields() );
	}

	public function test_a_registered_field_is_merged_after_the_core_fields(): void {
		BFBT_Batch::register_field( 'recipe_id', '_bfbt_recipe_id' );
		BFBT_Batch::register_field( 'cold_crash_date', '_bfbt_cold_crash_date' );

		$fields = BFBT_Batch::fields();

		$this->assertSame( '_bfbt_recipe_id', $fields['recipe_id'] );
		$this->assertSame( '_bfbt_cold_crash_date', $fields['cold_crash_date'] );
		$this->assertCount( count( BFBT_Batch::CORE_FIELDS ) + 2, $fields );
		$this->assertSame( array_keys( BFBT_Batch::CORE_FIELDS ), array_slice( array_keys( $fields ), 0, 10 ) );
	}

	public function test_registering_a_field_twice_keeps_one_entry(): void {
		BFBT_Batch::register_field( 'recipe_id', '_bfbt_recipe_id' );
		BFBT_Batch::register_field( 'recipe_id', '_bfbt_recipe_id' );

		$this->assertCount( count( BFBT_Batch::CORE_FIELDS ) + 1, BFBT_Batch::fields() );
	}

	public function test_a_registered_field_is_readable_and_writable_through_get_and_set(): void {
		BFBT_Batch::register_field( 'recipe_id', '_bfbt_recipe_id' );
		$GLOBALS['bfbt_types'][7] = 'bfbt_batch';

		$this->assertTrue( BFBT_Batch::set( 7, 'recipe_id', '42' ) );
		$this->assertSame( '42', BFBT_Batch::get( 7, 'recipe_id' ) );
		$this->assertSame( '42', $GLOBALS['bfbt_meta'][7]['_bfbt_recipe_id'] );
	}

	public function test_an_unregistered_field_is_neither_read_nor_written(): void {
		$GLOBALS['bfbt_types'][7] = 'bfbt_batch';

		$this->assertFalse( BFBT_Batch::set( 7, 'stage', 'primary' ) );
		$this->assertSame( 'fallback', BFBT_Batch::get( 7, 'stage', 'fallback' ) );
		$this->assertSame( [], $GLOBALS['bfbt_meta'] );
	}

	public function test_a_post_that_is_not_a_batch_is_refused_by_get_and_set(): void {
		$GLOBALS['bfbt_types'][7] = 'bfbt_log';

		$this->assertFalse( BFBT_Batch::set( 7, 'started', '2026-09-18' ) );
		$this->assertSame( '', BFBT_Batch::get( 7, 'started' ) );
	}

	public function test_setting_an_empty_value_deletes_the_key(): void {
		$GLOBALS['bfbt_types'][7] = 'bfbt_batch';
		BFBT_Batch::set( 7, 'dumped', '1' );
		BFBT_Batch::set( 7, 'dumped', '' );

		$this->assertArrayNotHasKey( '_bfbt_dumped', $GLOBALS['bfbt_meta'][7] );
	}
}
