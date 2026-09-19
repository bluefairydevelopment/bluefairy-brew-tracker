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

	public function test_a_fahrenheit_reading_stores_celsius_and_the_text_as_typed(): void {
		$id = BFBT_Log::create( $this->batch, [ 'type' => 'temperature', 'temp' => '74', 'temp_unit' => 'f' ] );

		$this->assertIsInt( $id );
		$this->assertSame( '23.3', $GLOBALS['bfbt_meta'][ $id ]['_bfbt_temp_c'] );
		$this->assertSame( '74 °F', $GLOBALS['bfbt_meta'][ $id ]['_bfbt_temp_entered'] );
		$this->assertSame( '74 °F', $GLOBALS['bfbt_posts'][ $id ]['post_title'] );

		$gravity = BFBT_Log::create( $this->batch, [ 'type' => 'gravity', 'sg' => '1.042', 'temp' => '68', 'temp_unit' => 'F' ] );
		$this->assertSame( '20.0', $GLOBALS['bfbt_meta'][ $gravity ]['_bfbt_temp_c'] );
		$this->assertSame( '68 °F', $GLOBALS['bfbt_meta'][ $gravity ]['_bfbt_temp_entered'] );
		$this->assertSame( 'SG 1.042 @ 68 °F', $GLOBALS['bfbt_posts'][ $gravity ]['post_title'] );
	}

	public function test_a_celsius_reading_keeps_its_unit_and_temp_c_still_means_celsius(): void {
		$c = BFBT_Log::create( $this->batch, [ 'type' => 'temperature', 'temp' => '20.0', 'temp_unit' => 'c' ] );
		$this->assertSame( '20.0', $GLOBALS['bfbt_meta'][ $c ]['_bfbt_temp_c'] );
		$this->assertSame( '20 °C', $GLOBALS['bfbt_meta'][ $c ]['_bfbt_temp_entered'] );
		$this->assertSame( '20 °C', $GLOBALS['bfbt_posts'][ $c ]['post_title'] );

		// The key a caller that already has °C uses — the Pro front-end form.
		$GLOBALS['bfbt_options']['bfbt_units'] = 'us';
		$legacy = BFBT_Log::create( $this->batch, [ 'type' => 'temperature', 'temp_c' => '20' ] );
		$this->assertSame( '20.0', $GLOBALS['bfbt_meta'][ $legacy ]['_bfbt_temp_c'] );
		$this->assertSame( '20 °C', $GLOBALS['bfbt_meta'][ $legacy ]['_bfbt_temp_entered'] );
	}

	public function test_a_temperature_without_a_unit_takes_the_settings_unit(): void {
		$GLOBALS['bfbt_options']['bfbt_units'] = 'us';
		$us = BFBT_Log::create( $this->batch, [ 'type' => 'temperature', 'temp' => '74' ] );
		$this->assertSame( '23.3', $GLOBALS['bfbt_meta'][ $us ]['_bfbt_temp_c'] );
		$this->assertSame( '74 °F', $GLOBALS['bfbt_meta'][ $us ]['_bfbt_temp_entered'] );

		$GLOBALS['bfbt_options']['bfbt_units'] = 'metric';
		$metric = BFBT_Log::create( $this->batch, [ 'type' => 'temperature', 'temp' => '20' ] );
		$this->assertSame( '20.0', $GLOBALS['bfbt_meta'][ $metric ]['_bfbt_temp_c'] );
		$this->assertSame( '20 °C', $GLOBALS['bfbt_meta'][ $metric ]['_bfbt_temp_entered'] );
	}

	public function test_a_fahrenheit_reading_out_of_range_is_refused_in_fahrenheit_and_nothing_is_written(): void {
		$result = BFBT_Log::create( $this->batch, [ 'type' => 'temperature', 'temp' => '200', 'temp_unit' => 'f' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( '“200” is outside the temperature range this plugin accepts (14 to 140 °F).', $result->get_error_message() );
		$this->assertCount( 1, $GLOBALS['bfbt_posts'] );
	}

	public function test_temperature_text_and_the_entered_unit(): void {
		$this->assertSame( '74 °F', BFBT_Log::temperature_text( [ 'temp_entered' => '74 °F', 'temp_c' => '23.3' ] ) );
		$this->assertSame( '20 °C', BFBT_Log::temperature_text( [ 'temp_entered' => '', 'temp_c' => '20.0' ] ) );
		$this->assertSame( 'f', BFBT_Log::entered_temperature_unit( '74 °F' ) );
		$this->assertSame( 'c', BFBT_Log::entered_temperature_unit( '20 °C' ) );
		$this->assertSame( 'c', BFBT_Log::entered_temperature_unit( '' ) );
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

	/* ---- update(): the seam an add-on's Edit screen uses ---------------- */

	private function updated_actions(): array {
		return array_values( array_filter( $GLOBALS['bfbt_actions'], static fn( array $a ): bool => 'bfbt_log_updated' === $a[0] ) );
	}

	public function test_update_rewrites_the_fields_title_note_and_time_and_marks_the_entry_edited(): void {
		$id = BFBT_Log::create( $this->batch, [ 'type' => 'gravity', 'sg' => '1.042', 'temp' => '68', 'temp_unit' => 'f', 'note' => 'first', 'time' => '2026-09-10 10:00' ] );
		$this->assertSame( 'SG 1.042 @ 68 °F', $GLOBALS['bfbt_posts'][ $id ]['post_title'] );
		$GLOBALS['bfbt_user_id'] = 7;

		$this->assertTrue( BFBT_Log::update( $id, [ 'sg' => '1.040', 'temp' => '20', 'temp_unit' => 'c', 'note' => 'corrected', 'time' => '2026-09-10 11:30' ] ) );

		$post = $GLOBALS['bfbt_posts'][ $id ];
		$this->assertSame( 'SG 1.040 @ 20 °C', $post['post_title'] );
		$this->assertSame( 'corrected', $post['post_content'] );
		$this->assertSame( '2026-09-10 11:30:00', $post['post_date'] );
		$this->assertSame( '1.040', BFBT_Log::field( $id, 'sg' ) );
		$this->assertSame( '20.0', BFBT_Log::field( $id, 'temp_c' ) );
		$this->assertSame( '20 °C', BFBT_Log::field( $id, 'temp_entered' ) );
		$this->assertSame( 'gravity', BFBT_Log::field( $id, 'type' ) );
		$this->assertSame( '2026-09-18 12:00:00', BFBT_Log::field( $id, 'edited' ) );
		$this->assertSame( '7', BFBT_Log::field( $id, 'edited_by' ) );

		$fired = $this->updated_actions();
		$this->assertCount( 1, $fired );
		$this->assertSame( [ $id, $this->batch ], [ $fired[0][1][0], $fired[0][1][1] ] );
		$this->assertSame( '1.040', $fired[0][1][2]['sg'] );
	}

	public function test_update_clears_a_field_left_empty(): void {
		$id = BFBT_Log::create( $this->batch, [ 'type' => 'gravity', 'sg' => '1.042', 'temp' => '20', 'temp_unit' => 'c' ] );
		$this->assertSame( '20.0', BFBT_Log::field( $id, 'temp_c' ) );

		$this->assertTrue( BFBT_Log::update( $id, [ 'sg' => '1.042', 'temp' => '' ] ) );

		$this->assertSame( '', BFBT_Log::field( $id, 'temp_c' ) );
		$this->assertSame( '', BFBT_Log::field( $id, 'temp_entered' ) );
		$this->assertArrayNotHasKey( '_bfbt_temp_c', $GLOBALS['bfbt_meta'][ $id ], 'deleted, not blanked' );
		$this->assertSame( 'SG 1.042', $GLOBALS['bfbt_posts'][ $id ]['post_title'] );
	}

	public function test_update_keeps_the_type_and_the_task_whatever_is_posted(): void {
		$task = BFBT_Task::create( $this->batch, [ 'type' => 'gravity' ] );
		$id   = BFBT_Log::create( $this->batch, [ 'type' => 'gravity', 'sg' => '1.050', 'task_id' => $task ] );

		$this->assertTrue( BFBT_Log::update( $id, [ 'type' => 'note', 'task_id' => '0', 'sg' => '1.048', 'note' => 'still a reading' ] ) );

		$this->assertSame( 'gravity', BFBT_Log::field( $id, 'type' ) );
		$this->assertSame( (string) $task, BFBT_Log::field( $id, 'task_id' ) );
		$this->assertSame( 'SG 1.048', $GLOBALS['bfbt_posts'][ $id ]['post_title'] );
	}

	public function test_a_refused_update_changes_nothing_and_fires_nothing(): void {
		$id     = BFBT_Log::create( $this->batch, [ 'type' => 'gravity', 'sg' => '1.042', 'note' => 'kept' ] );
		$before = [ $GLOBALS['bfbt_posts'][ $id ], $GLOBALS['bfbt_meta'][ $id ] ];

		$result = BFBT_Log::update( $id, [ 'sg' => '1090', 'note' => 'changed' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( '“1090” is not a specific gravity', $result->get_error_message() );
		$this->assertSame( $before, [ $GLOBALS['bfbt_posts'][ $id ], $GLOBALS['bfbt_meta'][ $id ] ] );
		$this->assertArrayNotHasKey( '_bfbt_edited', $GLOBALS['bfbt_meta'][ $id ] );
		$this->assertSame( [], $this->updated_actions() );
	}

	public function test_update_refuses_a_batch_a_missing_id_and_a_trashed_entry(): void {
		$id = BFBT_Log::create( $this->batch, [ 'type' => 'note', 'note' => 'x' ] );
		wp_trash_post( $id );

		foreach ( [ $this->batch, 999, $id ] as $bad ) {
			$result = BFBT_Log::update( $bad, [ 'note' => 'y' ] );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'bfbt_no_log', $result->get_error_code() );
		}

		$this->assertSame( 'x', $GLOBALS['bfbt_posts'][ $id ]['post_content'] );
	}

	public function test_update_leaves_the_photos_where_they_are(): void {
		$id     = BFBT_Log::create( $this->batch, [ 'type' => 'note', 'note' => 'x' ] );
		$photos = BFBT_Photos::attach( $id, [ [ 'name' => 'a.png', 'type' => 'image/png', 'tmp_name' => '/tmp/a', 'error' => UPLOAD_ERR_OK, 'size' => 1 ] ] )['ids'];

		$this->assertTrue( BFBT_Log::update( $id, [ 'note' => 'y' ] ) );

		$this->assertSame( $photos, BFBT_Photos::for_log( $id ) );
	}

	public function test_an_updated_entry_dated_ahead_stays_published(): void {
		$id = BFBT_Log::create( $this->batch, [ 'type' => 'note', 'note' => 'x' ] );

		$this->assertTrue( BFBT_Log::update( $id, [ 'note' => 'x', 'time' => '2026-09-19 12:00' ] ) );

		$this->assertSame( 'publish', get_post_status( $id ) );
	}
}
