<?php
/**
 * Log entries: whitelist, generated title, OG pinning, trash clears the pin.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LogTest extends TestCase {

	private int $batch = 0;

	protected function setUp(): void {
		bfbt_reset_stubs();
		$this->batch = wp_insert_post( [ 'post_type' => 'bfbt_batch', 'post_title' => 'Mead' ] );
	}

	public function test_type_whitelist_and_the_filter(): void {
		$result = BFBT_Log::create( $this->batch, [ 'type' => 'yeast' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'bfbt_log_type', $result->get_error_code() );
		$this->assertStringContainsString( 'yeast', $result->get_error_message() );

		$GLOBALS['bfbt_filters']['bfbt_log_types'] = static fn( array $types ): array => array_merge( $types, [ 'yeast' ] );

		$this->assertIsInt( BFBT_Log::create( $this->batch, [ 'type' => 'yeast', 'label' => 'Pitched EC-1118' ] ) );
	}

	public function test_a_missing_batch_is_refused(): void {
		$this->assertInstanceOf( WP_Error::class, BFBT_Log::create( 999, [ 'type' => 'note', 'note' => 'x' ] ) );
	}

	public function test_summary_titles(): void {
		$addition = BFBT_Log::create( $this->batch, [ 'type' => 'addition', 'ingredient' => 'Fermaid-O', 'qty' => '1.2', 'unit' => 'g' ] );
		$this->assertSame( 'Fermaid-O 1.2 g', $GLOBALS['bfbt_posts'][ $addition ]['post_title'] );

		$gravity = BFBT_Log::create( $this->batch, [ 'type' => 'gravity', 'sg' => '1.042', 'temp_c' => '20' ] );
		$this->assertSame( 'SG 1.042 @ 20 °C', $GLOBALS['bfbt_posts'][ $gravity ]['post_title'] );

		$bare = BFBT_Log::create( $this->batch, [ 'type' => 'gravity', 'sg' => '1.1' ] );
		$this->assertSame( 'SG 1.100', $GLOBALS['bfbt_posts'][ $bare ]['post_title'] );

		$racking = BFBT_Log::create( $this->batch, [ 'type' => 'racking', 'volume_out' => '1', 'volume_out_unit' => 'gal' ] );
		$this->assertSame( '3.785', $GLOBALS['bfbt_meta'][ $racking ]['_bfbt_volume_out_l'] );
		$this->assertStringStartsWith( 'Racked', $GLOBALS['bfbt_posts'][ $racking ]['post_title'] );

		$note = BFBT_Log::create( $this->batch, [ 'type' => 'note', 'note' => '<p>Smells of apples</p>' ] );
		$this->assertSame( 'Smells of apples', $GLOBALS['bfbt_posts'][ $note ]['post_title'] );
	}

	public function test_time_is_validated_and_goes_in_post_date(): void {
		$bad = BFBT_Log::create( $this->batch, [ 'type' => 'note', 'note' => 'x', 'time' => '18/09/2026 10:00' ] );
		$this->assertInstanceOf( WP_Error::class, $bad );
		$this->assertStringContainsString( '18/09/2026 10:00', $bad->get_error_message() );

		$id = BFBT_Log::create( $this->batch, [ 'type' => 'note', 'note' => 'x', 'time' => '2026-09-18 10:00' ] );
		$this->assertSame( '2026-09-18 10:00:00', $GLOBALS['bfbt_posts'][ $id ]['post_date'] );
		$this->assertArrayNotHasKey( '_bfbt_time', $GLOBALS['bfbt_meta'][ $id ] ?? [] );

		$now = BFBT_Log::create( $this->batch, [ 'type' => 'note', 'note' => 'x' ] );
		$this->assertSame( '2026-09-18 12:00:00', $GLOBALS['bfbt_posts'][ $now ]['post_date'] );
	}

	/**
	 * Found by the live harness: a reading dated three days ahead came back
	 * with status "future" and disappeared from latest_gravity().
	 */
	public function test_a_future_dated_entry_is_still_published(): void {
		$GLOBALS['bfbt_unscheduled'] = [];

		$id = BFBT_Log::create( $this->batch, [ 'type' => 'gravity', 'sg' => '1.040', 'time' => '2026-09-21 09:00' ] );

		$this->assertSame( 'publish', $GLOBALS['bfbt_posts'][ $id ]['post_status'] );
		$this->assertSame( '2026-09-21 09:00:00', $GLOBALS['bfbt_posts'][ $id ]['post_date'] );
		$this->assertSame( [ [ 'publish_future_post', [ $id ] ] ], $GLOBALS['bfbt_unscheduled'] );
		$this->assertSame( $id, BFBT_Log::latest_gravity( $this->batch )['id'] );
	}

	public function test_the_stub_itself_future_dates_a_plain_insert(): void {
		// The positive control for the test above: without keep_published()
		// the store really does hold "future".
		$id = wp_insert_post( [ 'post_type' => 'bfbt_log', 'post_status' => 'publish', 'post_date' => '2026-09-21 09:00:00' ] );

		$this->assertSame( 'future', $GLOBALS['bfbt_posts'][ $id ]['post_status'] );
	}

	public function test_a_gravity_reading_is_refused_when_malformed(): void {
		$result = BFBT_Log::create( $this->batch, [ 'type' => 'gravity', 'sg' => '1090' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( '1090', $result->get_error_message() );
		$this->assertArrayNotHasKey( '_bfbt_og_log_id', $GLOBALS['bfbt_meta'][ $this->batch ] ?? [] );
	}

	public function test_first_gravity_pins_og_and_a_second_does_not_re_pin(): void {
		$first = BFBT_Log::create( $this->batch, [ 'type' => 'gravity', 'sg' => '1.100', 'time' => '2026-09-18 10:00' ] );
		$this->assertSame( (string) $first, $GLOBALS['bfbt_meta'][ $this->batch ]['_bfbt_og_log_id'] );

		$second = BFBT_Log::create( $this->batch, [ 'type' => 'gravity', 'sg' => '1.040', 'time' => '2026-09-21 10:00' ] );
		$this->assertSame( (string) $first, $GLOBALS['bfbt_meta'][ $this->batch ]['_bfbt_og_log_id'] );

		$latest = BFBT_Log::latest_gravity( $this->batch );
		$this->assertSame( $second, $latest['id'] );
		$this->assertSame( '1.040', $latest['sg'] );

		$series = BFBT_Log::gravity_series( $this->batch );
		$this->assertSame( [ 1.1, 1.04 ], array_column( $series, 'sg' ) );

		$fired = array_column( $GLOBALS['bfbt_actions'], 0 );
		$this->assertSame( 2, count( array_keys( $fired, 'bfbt_log_created', true ) ) );
	}

	public function test_trashing_the_pinned_entry_clears_the_pin_and_only_that(): void {
		$first  = BFBT_Log::create( $this->batch, [ 'type' => 'gravity', 'sg' => '1.100' ] );
		$second = BFBT_Log::create( $this->batch, [ 'type' => 'gravity', 'sg' => '1.040' ] );

		$this->assertTrue( BFBT_Log::trash( $second ) );
		$this->assertSame( (string) $first, $GLOBALS['bfbt_meta'][ $this->batch ]['_bfbt_og_log_id'] );

		$this->assertTrue( BFBT_Log::trash( $first ) );
		$this->assertArrayNotHasKey( '_bfbt_og_log_id', $GLOBALS['bfbt_meta'][ $this->batch ] );
		$this->assertSame( 'trash', $GLOBALS['bfbt_posts'][ $first ]['post_status'] );
	}

	public function test_trash_refuses_a_batch_id(): void {
		$result = BFBT_Log::trash( $this->batch );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'trash', $GLOBALS['bfbt_posts'][ $this->batch ]['post_status'] ?? 'publish' );
	}

	public function test_pin_as_og_accepts_only_this_batchs_gravity_entries(): void {
		$other   = wp_insert_post( [ 'post_type' => 'bfbt_batch', 'post_title' => 'Other' ] );
		$theirs  = BFBT_Log::create( $other, [ 'type' => 'gravity', 'sg' => '1.050' ] );
		$note    = BFBT_Log::create( $this->batch, [ 'type' => 'note', 'note' => 'x' ] );
		$first   = BFBT_Log::create( $this->batch, [ 'type' => 'gravity', 'sg' => '1.100' ] );
		$second  = BFBT_Log::create( $this->batch, [ 'type' => 'gravity', 'sg' => '1.090' ] );

		$this->assertInstanceOf( WP_Error::class, BFBT_Log::pin_as_og( $this->batch, $theirs ) );
		$this->assertInstanceOf( WP_Error::class, BFBT_Log::pin_as_og( $this->batch, $note ) );
		$this->assertSame( (string) $first, $GLOBALS['bfbt_meta'][ $this->batch ]['_bfbt_og_log_id'] );

		$this->assertTrue( BFBT_Log::pin_as_og( $this->batch, $second ) );
		$this->assertSame( (string) $second, $GLOBALS['bfbt_meta'][ $this->batch ]['_bfbt_og_log_id'] );
	}

	public function test_for_batch_is_paged_newest_first(): void {
		for ( $day = 1; $day <= 7; $day++ ) {
			BFBT_Log::create( $this->batch, [ 'type' => 'note', 'note' => "day $day", 'time' => sprintf( '2026-09-%02d 09:00', $day ) ] );
		}

		$page = BFBT_Log::for_batch( $this->batch, 1, 3 );
		$this->assertSame( 7, $page['total'] );
		$this->assertSame( 3, $page['pages'] );
		$this->assertSame( [ 'day 7', 'day 6', 'day 5' ], array_map( static fn( object $p ): string => $p->post_title, $page['items'] ) );
	}

	public function test_pick_latest_keeps_the_latest_then_highest_id(): void {
		$rows = [
			[ 'ID' => '5', 'post_parent' => '1', 'post_date' => '2026-09-21 10:00:00', 'sg' => '1.040' ],
			[ 'ID' => '6', 'post_parent' => '1', 'post_date' => '2026-09-21 10:00:00', 'sg' => '1.041' ],
			[ 'ID' => '9', 'post_parent' => '1', 'post_date' => '2026-09-18 10:00:00', 'sg' => '1.100' ],
			[ 'ID' => '7', 'post_parent' => '2', 'post_date' => '2026-09-19 10:00:00', 'sg' => '1.050' ],
		];

		$latest = BFBT_Log::pick_latest( $rows );

		$this->assertSame( 6, $latest[1]['id'] );
		$this->assertSame( '1.041', $latest[1]['sg'] );
		$this->assertSame( '2026-09-19', $latest[2]['date'] );
	}

	public function test_ingredient_label_passes_through_the_filter(): void {
		$this->assertSame( 'Fermaid-O', BFBT_Log::ingredient_label( 'Fermaid-O' ) );

		$GLOBALS['bfbt_filters']['bfbt_ingredient_label'] = static fn( string $label, string $raw ): string => 'Fermaid O (Lallemand)';

		$this->assertSame( 'Fermaid O (Lallemand)', BFBT_Log::ingredient_label( 'Fermaid-O' ) );
	}
}
