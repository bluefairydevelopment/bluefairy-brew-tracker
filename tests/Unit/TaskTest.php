<?php
/**
 * Tasks: due always present, done means key absent, complete() once.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TaskTest extends TestCase {

	private int $batch = 0;

	protected function setUp(): void {
		bfbt_reset_stubs();
		$this->batch = wp_insert_post( [ 'post_type' => 'bfbt_batch', 'post_title' => 'Mead' ] );
	}

	/**
	 * @return string[]
	 */
	private function fired( string $tag ): array {
		return array_values( array_filter( $GLOBALS['bfbt_actions'], static fn( array $a ): bool => $a[0] === $tag ) );
	}

	public function test_due_defaults_to_9999_12_31_and_empty_due_is_never_written(): void {
		$id = BFBT_Task::create( $this->batch, [ 'type' => 'rack' ] );

		$this->assertIsInt( $id );
		$this->assertSame( '9999-12-31', $GLOBALS['bfbt_meta'][ $id ]['_bfbt_due'] );
		$this->assertArrayNotHasKey( '_bfbt_done_at', $GLOBALS['bfbt_meta'][ $id ] );
		$this->assertSame( 'Rack', $GLOBALS['bfbt_posts'][ $id ]['post_title'] );
		$this->assertFalse( BFBT_Task::has_date( $id ) );

		$dated = BFBT_Task::create( $this->batch, [ 'type' => 'gravity', 'due' => '' ] );
		$this->assertSame( '9999-12-31', $GLOBALS['bfbt_meta'][ $dated ]['_bfbt_due'] );
	}

	public function test_a_bad_due_date_is_refused_with_the_value_quoted(): void {
		$result = BFBT_Task::create( $this->batch, [ 'type' => 'rack', 'due' => '2026-02-30' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( '2026-02-30', $result->get_error_message() );
	}

	public function test_an_unknown_type_is_refused(): void {
		$result = BFBT_Task::create( $this->batch, [ 'type' => 'stabilise' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'bfbt_task_type', $result->get_error_code() );

		$GLOBALS['bfbt_filters']['bfbt_task_types'] = static fn( array $types ): array => array_merge( $types, [ 'stabilise' ] );

		$this->assertIsInt( BFBT_Task::create( $this->batch, [ 'type' => 'stabilise' ] ) );
	}

	public function test_addition_title_is_generated_and_empty_qty_keeps_the_label(): void {
		$dosed = BFBT_Task::create( $this->batch, [ 'type' => 'addition', 'ingredient' => 'Fermaid-O', 'qty' => '1.1', 'unit' => 'g', 'due' => '2026-09-19' ] );
		$this->assertSame( 'Fermaid-O 1.1 g', $GLOBALS['bfbt_posts'][ $dosed ]['post_title'] );
		$this->assertSame( '1.100', $GLOBALS['bfbt_meta'][ $dosed ]['_bfbt_qty'] );

		$water = BFBT_Task::create( $this->batch, [ 'type' => 'addition', 'title' => 'water to 1 gal', 'ingredient' => 'Water', 'qty' => '' ] );
		$this->assertSame( 'water to 1 gal', $GLOBALS['bfbt_posts'][ $water ]['post_title'] );
		$this->assertArrayNotHasKey( '_bfbt_qty', $GLOBALS['bfbt_meta'][ $water ] );
		$this->assertArrayNotHasKey( '_bfbt_unit', $GLOBALS['bfbt_meta'][ $water ] );
	}

	public function test_complete_writes_the_addition_entry_once_and_fires_once(): void {
		$task = BFBT_Task::create( $this->batch, [ 'type' => 'addition', 'ingredient' => 'Fermaid-O', 'qty' => '1.1', 'unit' => 'g', 'due' => '2026-09-18' ] );

		$log = BFBT_Task::complete( $task );

		$this->assertIsInt( $log );
		$this->assertSame( 'bfbt_log', get_post_type( $log ) );
		$this->assertSame( 'addition', $GLOBALS['bfbt_meta'][ $log ]['_bfbt_log_type'] );
		$this->assertSame( 'Fermaid-O', $GLOBALS['bfbt_meta'][ $log ]['_bfbt_ingredient'] );
		$this->assertSame( '1.100', $GLOBALS['bfbt_meta'][ $log ]['_bfbt_qty'] );
		$this->assertSame( 'g', $GLOBALS['bfbt_meta'][ $log ]['_bfbt_unit'] );
		$this->assertSame( (string) $task, $GLOBALS['bfbt_meta'][ $log ]['_bfbt_task_id'] );
		$this->assertArrayNotHasKey( '_bfbt_sg', $GLOBALS['bfbt_meta'][ $log ] );
		$this->assertSame( '2026-09-18 12:00:00', $GLOBALS['bfbt_meta'][ $task ]['_bfbt_done_at'] );
		$this->assertTrue( BFBT_Task::is_done( $task ) );

		$again = BFBT_Task::complete( $task );

		$this->assertInstanceOf( WP_Error::class, $again );
		$this->assertSame( 'bfbt_task_done', $again->get_error_code() );

		$fired = $this->fired( 'bfbt_task_completed' );
		$this->assertCount( 1, $fired );
		$this->assertSame( [ $task, $this->batch, $log ], $fired[0][1] );
		$this->assertCount( 1, array_filter( $GLOBALS['bfbt_types'], static fn( string $t ): bool => 'bfbt_log' === $t ) );
	}

	public function test_completing_a_non_addition_logs_a_done_note(): void {
		$task = BFBT_Task::create( $this->batch, [ 'type' => 'rack', 'title' => 'Rack to secondary' ] );

		$log = BFBT_Task::complete( $task );

		$this->assertSame( 'note', $GLOBALS['bfbt_meta'][ $log ]['_bfbt_log_type'] );
		$this->assertSame( 'Done: Rack to secondary', $GLOBALS['bfbt_posts'][ $log ]['post_title'] );
	}

	public function test_uncomplete_deletes_the_key(): void {
		$task = BFBT_Task::create( $this->batch, [ 'type' => 'gravity', 'due' => '2026-09-20' ] );
		BFBT_Task::complete( $task );
		$this->assertArrayHasKey( '_bfbt_done_at', $GLOBALS['bfbt_meta'][ $task ] );

		$this->assertTrue( BFBT_Task::uncomplete( $task ) );

		$this->assertArrayNotHasKey( '_bfbt_done_at', $GLOBALS['bfbt_meta'][ $task ] );
		$this->assertFalse( BFBT_Task::is_done( $task ) );
		$this->assertIsInt( BFBT_Task::complete( $task ) );
	}

	public function test_an_empty_done_at_still_counts_as_open(): void {
		$open  = BFBT_Task::create( $this->batch, [ 'type' => 'gravity', 'due' => '2026-09-25' ] );
		$blank = BFBT_Task::create( $this->batch, [ 'type' => 'gravity', 'due' => '2026-09-20' ] );
		$done  = BFBT_Task::create( $this->batch, [ 'type' => 'gravity', 'due' => '2026-09-19' ] );
		$undated = BFBT_Task::create( $this->batch, [ 'type' => 'rack' ] );

		$GLOBALS['bfbt_meta'][ $blank ]['_bfbt_done_at'] = '';
		BFBT_Task::complete( $done );

		$ids = array_map( static fn( object $p ): int => (int) $p->ID, BFBT_Task::open_for_batch( $this->batch ) );

		$this->assertSame( [ $blank, $open, $undated ], $ids, 'soonest first, undated last, done excluded, blank done-at included' );
		$this->assertFalse( BFBT_Task::is_done( $blank ) );
		$this->assertIsInt( BFBT_Task::complete( $blank ) );
	}

	public function test_next_due_bucketing_skips_undated_and_takes_the_lowest_id_on_a_tie(): void {
		$mins = [
			[ 'post_parent' => '10', 'due' => '2026-09-19' ],
			[ 'post_parent' => '11', 'due' => '9999-12-31' ],
			[ 'post_parent' => '12', 'due' => '2026-09-18' ],
		];
		$rows = [
			[ 'ID' => '203', 'post_parent' => '10', 'post_title' => 'Later twin', 'due' => '2026-09-19' ],
			[ 'ID' => '201', 'post_parent' => '10', 'post_title' => 'Fermaid-O 1.1 g', 'due' => '2026-09-19' ],
			[ 'ID' => '202', 'post_parent' => '10', 'post_title' => 'Wrong date', 'due' => '2026-09-25' ],
			[ 'ID' => '210', 'post_parent' => '11', 'post_title' => 'Rack', 'due' => '9999-12-31' ],
			[ 'ID' => '220', 'post_parent' => '12', 'post_title' => 'Gravity', 'due' => '2026-09-18' ],
		];

		$next = BFBT_Task::pick_next_due( $mins, $rows );

		$this->assertSame( [ 'due' => '2026-09-19', 'title' => 'Fermaid-O 1.1 g', 'id' => 201 ], $next[10] );
		$this->assertArrayNotHasKey( 11, $next );
		$this->assertSame( 'Gravity', $next[12]['title'] );
	}
}
