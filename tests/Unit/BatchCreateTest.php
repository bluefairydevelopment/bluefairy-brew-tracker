<?php
/**
 * create() and update(): validated through BFBT_Validate, refused with the
 * value quoted, and never reported as saved when the insert failed.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BatchCreateTest extends TestCase {

	private int $traditional = 0;

	protected function setUp(): void {
		bfbt_reset_stubs();

		$extra = new ReflectionProperty( BFBT_Batch::class, 'extra_fields' );
		$extra->setValue( null, [] );

		$mead              = wp_insert_term( 'Mead', 'bfbt_style', [ 'slug' => 'mead' ] );
		$this->traditional = wp_insert_term( 'Traditional', 'bfbt_style', [ 'slug' => 'traditional', 'parent' => $mead['term_id'] ] )['term_id'];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function owners_example(): array {
		return [
			'name'    => 'Traditional Mead #1',
			'started' => '2026-09-18',
			'volume'  => '1 gal',
			'style'   => 'traditional',
			'vessel'  => 'Carboy A',
		];
	}

	/**
	 * @return string[] The tags of every action fired so far.
	 */
	private function fired(): array {
		return array_column( $GLOBALS['bfbt_actions'], 0 );
	}

	public function test_the_owners_example_is_created_with_canonical_meta(): void {
		$id = BFBT_Batch::create( $this->owners_example() );

		$this->assertIsInt( $id );
		$this->assertSame( 'bfbt_batch', get_post_type( $id ) );
		$this->assertSame( 'Traditional Mead #1', $GLOBALS['bfbt_posts'][ $id ]['post_title'] );
		$this->assertSame( '2026-09-18', $GLOBALS['bfbt_meta'][ $id ]['_bfbt_started'] );
		$this->assertSame( '3.785', $GLOBALS['bfbt_meta'][ $id ]['_bfbt_volume_l'] );
		$this->assertSame( '1 gal', $GLOBALS['bfbt_meta'][ $id ]['_bfbt_volume_entered'] );
		$this->assertSame( $this->traditional, BFBT_Batch::style_term_id( $id ) );
		$this->assertSame( 'Traditional', BFBT_Batch::style_name( $id ) );
		$this->assertSame( 'Carboy A', BFBT_Batch::vessel_name( $id ) );
		$this->assertSame( [ 'bfbt_batch_created' ], $this->fired() );
	}

	public function test_a_volume_can_arrive_as_number_and_unit(): void {
		$id = BFBT_Batch::create( [ 'name' => 'B', 'volume' => '5', 'volume_unit' => 'L' ] );

		$this->assertSame( '5.000', $GLOBALS['bfbt_meta'][ $id ]['_bfbt_volume_l'] );
		$this->assertSame( '5 l', $GLOBALS['bfbt_meta'][ $id ]['_bfbt_volume_entered'] );
	}

	public function test_a_planned_batch_needs_only_a_name(): void {
		$id = BFBT_Batch::create( [ 'name' => 'Someday cyser' ] );

		$this->assertIsInt( $id );
		$this->assertSame( [], $GLOBALS['bfbt_meta'][ $id ] ?? [] );
	}

	public function test_a_batch_without_a_name_is_refused_and_nothing_is_written(): void {
		$this->assertInstanceOf( WP_Error::class, BFBT_Batch::create( [ 'started' => '2026-09-18' ] ) );
		$this->assertSame( [], $GLOBALS['bfbt_posts'] );
		$this->assertSame( [], $this->fired() );
	}

	public function test_a_non_day_start_date_is_refused_with_the_value_quoted(): void {
		$result = BFBT_Batch::create( [ 'name' => 'B', 'started' => '2026-02-30' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( '“2026-02-30”', $result->get_error_message() );
		$this->assertSame( [], $GLOBALS['bfbt_posts'] );
	}

	public function test_a_target_gravity_in_points_is_refused_with_the_hint(): void {
		$result = BFBT_Batch::create( [ 'name' => 'B', 'target_og' => '1090' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( '“1090”', $result->get_error_message() );
		$this->assertStringContainsString( 'enter as 1.090', $result->get_error_message() );
	}

	public function test_an_unknown_style_is_refused_with_the_value_quoted(): void {
		$result = BFBT_Batch::create( [ 'name' => 'B', 'style' => 'kombucha' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( '“kombucha”', $result->get_error_message() );
	}

	public function test_a_volume_without_a_unit_is_refused_with_the_value_quoted(): void {
		$result = BFBT_Batch::create( [ 'name' => 'B', 'volume' => '1' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( '“1”', $result->get_error_message() );
	}

	public function test_a_volume_in_a_mass_unit_is_refused_with_the_unit_quoted(): void {
		$result = BFBT_Batch::create( [ 'name' => 'B', 'volume' => '3 lb' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( '“lb”', $result->get_error_message() );
	}

	public function test_an_unknown_vessel_type_is_refused_with_the_value_quoted(): void {
		$result = BFBT_Batch::create( [ 'name' => 'B', 'vessel' => 'Carboy A', 'vessel_type' => 'bathtub' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( '“bathtub”', $result->get_error_message() );
	}

	public function test_vessel_type_and_capacity_land_on_the_vessel_term(): void {
		$id     = BFBT_Batch::create( [ 'name' => 'B', 'vessel' => 'Carboy A', 'vessel_type' => 'Carboy', 'vessel_capacity_l' => '3.785' ] );
		$vessel = BFBT_Batch::vessel_term_id( $id );

		$this->assertGreaterThan( 0, $vessel );
		$this->assertSame( 'carboy', get_term_meta( $vessel, 'bfbt_vessel_type', true ) );
		$this->assertSame( '3.785', get_term_meta( $vessel, 'bfbt_capacity_l', true ) );
	}

	/**
	 * The precedent's H1: a failed insert reported as a save. The stub
	 * answers WP_Error the way wp_insert_post( $arr, true ) does.
	 */
	public function test_a_failed_insert_is_returned_as_the_error_and_no_hook_fires(): void {
		$GLOBALS['bfbt_insert_fails'] = true;

		$result = BFBT_Batch::create( $this->owners_example() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'db_insert_error', $result->get_error_code() );
		$this->assertSame( [], $this->fired() );
	}

	public function test_update_changes_only_the_keys_given(): void {
		$id = BFBT_Batch::create( $this->owners_example() );
		$GLOBALS['bfbt_actions'] = [];

		$this->assertTrue( BFBT_Batch::update( $id, [ 'secondary_date' => '2026-10-02', 'target_og' => '1.11' ] ) );
		$this->assertSame( '2026-10-02', $GLOBALS['bfbt_meta'][ $id ]['_bfbt_secondary_date'] );
		$this->assertSame( '1.110', $GLOBALS['bfbt_meta'][ $id ]['_bfbt_target_og'] );
		$this->assertSame( '2026-09-18', $GLOBALS['bfbt_meta'][ $id ]['_bfbt_started'] );
		$this->assertSame( 'Traditional Mead #1', $GLOBALS['bfbt_posts'][ $id ]['post_title'] );
		$this->assertSame( [ 'bfbt_batch_updated' ], $this->fired() );
	}

	public function test_update_can_rename_and_clear_a_date(): void {
		$id = BFBT_Batch::create( $this->owners_example() );

		$this->assertTrue( BFBT_Batch::update( $id, [ 'name' => 'Traditional Mead #1 (dry)', 'started' => '' ] ) );
		$this->assertSame( 'Traditional Mead #1 (dry)', $GLOBALS['bfbt_posts'][ $id ]['post_title'] );
		$this->assertArrayNotHasKey( '_bfbt_started', $GLOBALS['bfbt_meta'][ $id ] );
	}

	/**
	 * Dumped is `1` or absent, never `0` or `''`, so the stage derivation
	 * reads one truthy check.
	 */
	public function test_dumped_is_one_or_absent(): void {
		$id = BFBT_Batch::create( $this->owners_example() );

		BFBT_Batch::update( $id, [ 'dumped' => '1' ] );
		$this->assertSame( '1', $GLOBALS['bfbt_meta'][ $id ]['_bfbt_dumped'] );

		BFBT_Batch::update( $id, [ 'dumped' => '' ] );
		$this->assertArrayNotHasKey( '_bfbt_dumped', $GLOBALS['bfbt_meta'][ $id ] );

		BFBT_Batch::update( $id, [ 'dumped' => '0' ] );
		$this->assertArrayNotHasKey( '_bfbt_dumped', $GLOBALS['bfbt_meta'][ $id ] );
	}

	public function test_update_refuses_a_bad_value_and_leaves_the_batch_untouched(): void {
		$id = BFBT_Batch::create( $this->owners_example() );
		$GLOBALS['bfbt_actions'] = [];

		$result = BFBT_Batch::update( $id, [ 'packaged_date' => '03/14/2026' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( '“03/14/2026”', $result->get_error_message() );
		$this->assertArrayNotHasKey( '_bfbt_packaged_date', $GLOBALS['bfbt_meta'][ $id ] );
		$this->assertSame( [], $this->fired() );
	}

	public function test_update_refuses_a_post_that_is_not_a_batch(): void {
		$GLOBALS['bfbt_types'][9] = 'bfbt_log';

		$this->assertInstanceOf( WP_Error::class, BFBT_Batch::update( 9, [ 'name' => 'x' ] ) );
		$this->assertInstanceOf( WP_Error::class, BFBT_Batch::update( 0, [ 'name' => 'x' ] ) );
	}

	public function test_og_log_id_must_be_a_log_entry(): void {
		$id = BFBT_Batch::create( $this->owners_example() );
		$GLOBALS['bfbt_types'][50] = 'bfbt_log';
		$GLOBALS['bfbt_types'][51] = 'bfbt_task';

		$this->assertTrue( BFBT_Batch::update( $id, [ 'og_log_id' => '50' ] ) );
		$this->assertSame( '50', $GLOBALS['bfbt_meta'][ $id ]['_bfbt_og_log_id'] );

		$result = BFBT_Batch::update( $id, [ 'og_log_id' => '51' ] );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( '“51”', $result->get_error_message() );
	}

	public function test_an_add_on_field_is_stored_under_its_registered_key(): void {
		BFBT_Batch::register_field( 'recipe_id', '_bfbt_recipe_id' );

		$id = BFBT_Batch::create( $this->owners_example() + [ 'recipe_id' => '12' ] );

		$this->assertSame( '12', $GLOBALS['bfbt_meta'][ $id ]['_bfbt_recipe_id'] );
	}
}
