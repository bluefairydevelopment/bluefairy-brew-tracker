<?php
/**
 * Stage derivation, precedence and the filterable order.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StageTest extends TestCase {

	private int $batch = 0;

	protected function setUp(): void {
		bfbt_reset_stubs();
		$this->batch = wp_insert_post( [ 'post_type' => 'bfbt_batch', 'post_title' => 'Mead' ] );
	}

	private function set( string $key, string $value ): void {
		$GLOBALS['bfbt_meta'][ $this->batch ][ $key ] = $value;
	}

	public function test_no_milestones_is_planned(): void {
		$this->assertSame( 'planned', BFBT_Stage::get( $this->batch ) );
	}

	public function test_started_is_primary(): void {
		$this->set( '_bfbt_started', '2026-09-18' );
		$this->assertSame( 'primary', BFBT_Stage::get( $this->batch ) );
	}

	public function test_each_later_milestone_beats_the_earlier(): void {
		$this->set( '_bfbt_started', '2026-09-18' );
		$this->set( '_bfbt_secondary_date', '2026-10-01' );
		$this->assertSame( 'secondary', BFBT_Stage::get( $this->batch ) );

		$this->set( '_bfbt_packaged_date', '2026-11-01' );
		$this->assertSame( 'packaged', BFBT_Stage::get( $this->batch ) );

		$this->set( '_bfbt_completed_date', '2026-12-01' );
		$this->assertSame( 'complete', BFBT_Stage::get( $this->batch ) );
	}

	public function test_dumped_beats_complete(): void {
		$this->set( '_bfbt_started', '2026-09-18' );
		$this->set( '_bfbt_completed_date', '2026-12-01' );
		$this->set( '_bfbt_dumped', '1' );

		$this->assertSame( 'dumped', BFBT_Stage::get( $this->batch ) );
	}

	public function test_the_stage_filter_replaces_the_answer(): void {
		$this->set( '_bfbt_started', '2026-09-18' );
		$GLOBALS['bfbt_filters']['bfbt_batch_stage'] = static fn( string $stage, int $id ): string => 'cold_crash';

		$this->assertSame( 'cold_crash', BFBT_Stage::get( $this->batch ) );
		$this->assertSame( 'Cold crash', BFBT_Stage::label( 'cold_crash' ) );
	}

	public function test_since_is_the_deciding_milestone_date(): void {
		$this->assertSame( '', BFBT_Stage::since( $this->batch ) );

		$this->set( '_bfbt_started', '2026-09-18' );
		$this->assertSame( '2026-09-18', BFBT_Stage::since( $this->batch ) );

		$this->set( '_bfbt_secondary_date', '2026-10-01' );
		$this->assertSame( '2026-10-01', BFBT_Stage::since( $this->batch ) );

		$this->set( '_bfbt_dumped', '1' );
		$this->assertSame( '', BFBT_Stage::since( $this->batch ) );
	}

	public function test_default_order_and_index(): void {
		$this->assertSame( [ 'planned', 'primary', 'secondary', 'packaged', 'complete', 'dumped' ], BFBT_Stage::order() );
		$this->assertSame( 0, BFBT_Stage::index_of( 'planned' ) );
		$this->assertSame( 3, BFBT_Stage::index_of( 'packaged' ) );
		$this->assertSame( -1, BFBT_Stage::index_of( 'nope' ) );
	}

	public function test_order_with_pros_five_states_inserted(): void {
		$GLOBALS['bfbt_filters']['bfbt_stage_order'] = static function ( array $order ): array {
			$out = [];

			foreach ( $order as $stage ) {
				if ( 'packaged' === $stage ) {
					array_push( $out, 'cold_crash', 'stabilised', 'back_sweetened' );
				}

				$out[] = $stage;

				if ( 'packaged' === $stage ) {
					array_push( $out, 'conditioning', 'carbonating' );
				}
			}

			return $out;
		};

		$this->assertSame(
			[ 'planned', 'primary', 'secondary', 'cold_crash', 'stabilised', 'back_sweetened', 'packaged', 'conditioning', 'carbonating', 'complete', 'dumped' ],
			BFBT_Stage::order()
		);
		$this->assertGreaterThan( BFBT_Stage::index_of( 'secondary' ), BFBT_Stage::index_of( 'stabilised' ) );
		$this->assertLessThan( BFBT_Stage::index_of( 'packaged' ), BFBT_Stage::index_of( 'stabilised' ) );
		$this->assertGreaterThan( BFBT_Stage::index_of( 'packaged' ), BFBT_Stage::index_of( 'carbonating' ) );
		$this->assertLessThan( BFBT_Stage::index_of( 'complete' ), BFBT_Stage::index_of( 'carbonating' ) );
	}

	public function test_terminal_stages(): void {
		$this->assertTrue( BFBT_Stage::is_terminal( 'complete' ) );
		$this->assertTrue( BFBT_Stage::is_terminal( 'dumped' ) );
		$this->assertFalse( BFBT_Stage::is_terminal( 'packaged' ) );
	}

	public function test_badge_escapes_the_label(): void {
		$GLOBALS['bfbt_filters']['bfbt_stage_labels'] = static fn( array $labels ): array => $labels + [ 'evil' => '<b>x</b>' ];

		$badge = BFBT_Stage::badge( 'evil' );

		$this->assertStringNotContainsString( '<b>', $badge );
		$this->assertStringContainsString( '&lt;b&gt;x&lt;/b&gt;', $badge );
	}
}
