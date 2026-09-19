<?php
/**
 * Photos on a log entry: the $_FILES reshaping, the five-photo limit, the
 * image-only mime map, storing, listing, the notice, the CSV column, and
 * the delete that takes an entry's photos with it.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PhotosTest extends TestCase {

	private int $batch = 0;
	private int $log   = 0;

	protected function setUp(): void {
		bfbt_reset_stubs();
		$_FILES      = [];
		$this->batch = wp_insert_post( [ 'post_type' => 'bfbt_batch', 'post_title' => 'Mine' ] );
		$this->log   = BFBT_Log::create( $this->batch, [ 'type' => 'note', 'note' => 'x' ] );
	}

	/**
	 * PHP's layout for <input type="file" name="photos[]" multiple>.
	 *
	 * @param string[] $names
	 * @param int[]    $errors
	 * @return array<string, array<int, mixed>>
	 */
	private static function multi( array $names, array $errors = [] ): array {
		$n = count( $names );

		return [
			'name'     => $names,
			'type'     => array_fill( 0, $n, 'image/png' ),
			'tmp_name' => array_map( static fn( string $name ): string => '/tmp/php' . md5( $name ), $names ),
			'error'    => $errors ?: array_fill( 0, $n, UPLOAD_ERR_OK ),
			'size'     => array_fill( 0, $n, 1234 ),
		];
	}

	/* ---- split() ------------------------------------------------------- */

	public function test_split_turns_the_per_key_layout_into_one_array_per_file(): void {
		$list = BFBT_Photos::split( self::multi( [ 'a.png', 'b.png' ] ) );

		$this->assertCount( 2, $list );
		$this->assertSame( 'a.png', $list[0]['name'] );
		$this->assertSame( 'b.png', $list[1]['name'] );
		$this->assertSame( '/tmp/php' . md5( 'b.png' ), $list[1]['tmp_name'] );
		$this->assertSame( UPLOAD_ERR_OK, $list[1]['error'] );
		$this->assertSame( 1234, $list[1]['size'] );
		$this->assertSame( 'image/png', $list[0]['type'] );
	}

	public function test_split_drops_the_empty_slot_a_browser_sends_for_no_file(): void {
		// One <input multiple> with nothing chosen posts a single NO_FILE slot.
		$this->assertSame( [], BFBT_Photos::split( self::multi( [ '' ], [ UPLOAD_ERR_NO_FILE ] ) ) );

		$list = BFBT_Photos::split( self::multi( [ 'a.png', '', 'c.png' ], [ UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK ] ) );
		$this->assertSame( [ 'a.png', 'c.png' ], array_column( $list, 'name' ) );
	}

	public function test_split_keeps_a_failed_upload_so_it_can_be_reported(): void {
		$list = BFBT_Photos::split( self::multi( [ 'huge.png' ], [ UPLOAD_ERR_INI_SIZE ] ) );

		$this->assertCount( 1, $list );
		$this->assertSame( UPLOAD_ERR_INI_SIZE, $list[0]['error'] );
	}

	public function test_split_accepts_a_single_file_layout_and_rejects_non_uploads(): void {
		$single = [ 'name' => 'one.jpg', 'type' => 'image/jpeg', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK, 'size' => 9 ];
		$this->assertSame( [ $single ], BFBT_Photos::split( $single ) );

		$this->assertSame( [], BFBT_Photos::split( [] ) );
		$this->assertSame( [], BFBT_Photos::split( null ) );
		$this->assertSame( [], BFBT_Photos::split( 'photos' ) );
		$this->assertSame( [], BFBT_Photos::split( [ 'tmp_name' => [ '/tmp/x' ] ] ) );
	}

	/* ---- too_many() ---------------------------------------------------- */

	public function test_five_photos_are_allowed_and_six_are_refused_with_the_count(): void {
		$five = BFBT_Photos::split( self::multi( [ '1.png', '2.png', '3.png', '4.png', '5.png' ] ) );
		$six  = BFBT_Photos::split( self::multi( [ '1.png', '2.png', '3.png', '4.png', '5.png', '6.png' ] ) );

		$this->assertNull( BFBT_Photos::too_many( $five ) );
		$this->assertNull( BFBT_Photos::too_many( [] ) );

		$refusal = BFBT_Photos::too_many( $six );
		$this->assertInstanceOf( WP_Error::class, $refusal );
		$this->assertSame( 'An entry takes up to 5 photos; 6 were attached. Nothing was saved.', $refusal->get_error_message() );
	}

	public function test_an_empty_slot_does_not_count_towards_the_limit(): void {
		$names  = [ '1.png', '2.png', '3.png', '4.png', '5.png', '' ];
		$errors = [ UPLOAD_ERR_OK, UPLOAD_ERR_OK, UPLOAD_ERR_OK, UPLOAD_ERR_OK, UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE ];

		$this->assertNull( BFBT_Photos::too_many( BFBT_Photos::split( self::multi( $names, $errors ) ) ) );
	}

	/* ---- mimes() ------------------------------------------------------- */

	public function test_the_accepted_types_are_exactly_the_image_entries_of_the_site_map(): void {
		$mimes = BFBT_Photos::mimes();

		$this->assertSame( [ 'jpg|jpeg|jpe', 'png', 'gif', 'webp' ], array_keys( $mimes ) );
		$this->assertSame( 'image/webp', $mimes['webp'] );
		$this->assertArrayNotHasKey( 'pdf', $mimes );
		$this->assertArrayNotHasKey( 'mp4|m4v', $mimes );
		$this->assertArrayNotHasKey( 'txt|asc|c|cc|h|srt', $mimes );
	}

	/* ---- attach() ------------------------------------------------------ */

	public function test_attach_stores_each_file_as_an_attachment_of_the_entry_with_the_image_map(): void {
		$outcome = BFBT_Photos::attach( $this->log, BFBT_Photos::split( self::multi( [ 'a.png', 'b.png' ] ) ) );

		$this->assertSame( [], $outcome['errors'] );
		$this->assertCount( 2, $outcome['ids'] );

		foreach ( $outcome['ids'] as $id ) {
			$this->assertSame( 'attachment', get_post_type( $id ) );
			$this->assertSame( $this->log, (int) get_post( $id )->post_parent );
			$this->assertSame( 'inherit', get_post_status( $id ) );
		}

		$this->assertCount( 2, $GLOBALS['bfbt_uploads'] );
		$this->assertSame( 'bfbt_photo', $GLOBALS['bfbt_uploads'][0]['key'] );
		$this->assertSame( 'a.png', $GLOBALS['bfbt_uploads'][0]['file']['name'] );
		$this->assertSame( 'b.png', $GLOBALS['bfbt_uploads'][1]['file']['name'] );
		$this->assertSame( $this->log, $GLOBALS['bfbt_uploads'][1]['post_id'] );
		$this->assertFalse( $GLOBALS['bfbt_uploads'][0]['overrides']['test_form'] );
		$this->assertSame( BFBT_Photos::mimes(), $GLOBALS['bfbt_uploads'][0]['overrides']['mimes'] );
		$this->assertArrayNotHasKey( 'bfbt_photo', $_FILES, 'the scratch key is cleared afterwards' );
	}

	public function test_attach_with_nothing_to_attach_touches_nothing(): void {
		$this->assertSame( [ 'ids' => [], 'errors' => [] ], BFBT_Photos::attach( $this->log, [] ) );
		$this->assertSame( [], $GLOBALS['bfbt_uploads'] );
	}

	public function test_a_refused_file_is_named_with_its_reason_and_the_others_are_still_stored(): void {
		$GLOBALS['bfbt_upload_fails']['evil.png'] = 'Sorry, you are not allowed to upload this file type.';

		$outcome = BFBT_Photos::attach( $this->log, BFBT_Photos::split( self::multi( [ 'ok.png', 'evil.png', 'fine.png' ] ) ) );

		$this->assertCount( 2, $outcome['ids'] );
		$this->assertSame( [ '“evil.png”: Sorry, you are not allowed to upload this file type.' ], $outcome['errors'] );
		$this->assertSame( [ 'ok.png', 'fine.png' ], array_map( static fn( int $id ): string => get_post( $id )->post_title, $outcome['ids'] ) );
	}

	public function test_a_file_php_already_failed_is_not_sent_to_the_uploader_and_says_why(): void {
		$list = BFBT_Photos::split( self::multi( [ 'big.png', 'part.png', 'ok.png' ], [ UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_OK ] ) );

		$outcome = BFBT_Photos::attach( $this->log, $list );

		$this->assertCount( 1, $outcome['ids'] );
		$this->assertCount( 1, $GLOBALS['bfbt_uploads'], 'only the good file reached media_handle_upload' );
		$this->assertSame( 'ok.png', $GLOBALS['bfbt_uploads'][0]['file']['name'] );
		$this->assertSame( [
			'“big.png”: the file is larger than this site allows (8 MB).',
			'“part.png”: only part of the file arrived; try again.',
		], $outcome['errors'] );
	}

	public function test_a_file_name_is_sanitised_before_it_is_quoted_back(): void {
		$GLOBALS['bfbt_upload_fails']['<b>x</b>.png'] = 'no';

		$outcome = BFBT_Photos::attach( $this->log, BFBT_Photos::split( self::multi( [ '<b>x</b>.png' ] ) ) );

		$this->assertSame( [ '“-b-x-b-.png”: no' ], $outcome['errors'] );
	}

	/* ---- for_log() / urls() ------------------------------------------- */

	public function test_for_log_lists_only_this_entrys_photos_oldest_first(): void {
		$other = BFBT_Log::create( $this->batch, [ 'type' => 'note', 'note' => 'y' ] );

		$mine   = BFBT_Photos::attach( $this->log, BFBT_Photos::split( self::multi( [ 'first.png', 'second.png' ] ) ) )['ids'];
		$theirs = BFBT_Photos::attach( $other, BFBT_Photos::split( self::multi( [ 'other.png' ] ) ) )['ids'];

		$this->assertSame( $mine, BFBT_Photos::for_log( $this->log ) );
		$this->assertSame( $theirs, BFBT_Photos::for_log( $other ) );
		$this->assertSame( [], BFBT_Photos::for_log( 0 ) );
		$this->assertSame( [], BFBT_Photos::for_log( $this->batch ), 'a batch has entries, not photos' );

		$this->assertSame(
			[ 'https://example.test/uploads/first.png', 'https://example.test/uploads/second.png' ],
			BFBT_Photos::urls( $this->log )
		);
	}

	/* ---- the notice ---------------------------------------------------- */

	public function test_the_notice_counts_saved_photos_and_names_every_failure(): void {
		$this->assertSame( [ 'updated', 'Entry added.' ], BFBT_Batch_Admin::entry_added_notice( [ 'ids' => [], 'errors' => [] ] ) );
		$this->assertSame( [ 'updated', 'Entry added with 1 photo.' ], BFBT_Batch_Admin::entry_added_notice( [ 'ids' => [ 7 ], 'errors' => [] ] ) );
		$this->assertSame( [ 'updated', 'Entry added with 3 photos.' ], BFBT_Batch_Admin::entry_added_notice( [ 'ids' => [ 7, 8, 9 ], 'errors' => [] ] ) );

		$this->assertSame(
			[ 'error', 'Entry added, but 1 photo was not saved. “a.png”: too big.' ],
			BFBT_Batch_Admin::entry_added_notice( [ 'ids' => [ 7 ], 'errors' => [ '“a.png”: too big.' ] ] )
		);
		$this->assertSame(
			[ 'error', 'Entry added, but 2 photos were not saved. “a.png”: too big. “b.txt”: not an image.' ],
			BFBT_Batch_Admin::entry_added_notice( [ 'ids' => [], 'errors' => [ '“a.png”: too big.', '“b.txt”: not an image.' ] ] )
		);
	}

	/* ---- the CSV ------------------------------------------------------- */

	public function test_the_csv_names_each_photo_of_an_entry_in_its_last_column(): void {
		$columns = BFBT_CSV::LOG_COLUMNS;
		$this->assertSame( [ 'note', 'photos' ], array_slice( $columns, -2 ) );

		BFBT_Photos::attach( $this->log, BFBT_Photos::split( self::multi( [ 'a.png', 'b.png' ] ) ) );
		$bare = BFBT_Log::create( $this->batch, [ 'type' => 'note', 'note' => 'no photo' ] );

		$this->assertSame( 'https://example.test/uploads/a.png https://example.test/uploads/b.png', implode( ' ', BFBT_Photos::urls( $this->log ) ) );
		$this->assertSame( '', implode( ' ', BFBT_Photos::urls( $bare ) ) );
	}

	/* ---- deleting ------------------------------------------------------ */

	public function test_deleting_an_entry_permanently_deletes_its_photos_and_nobody_elses(): void {
		$other  = BFBT_Log::create( $this->batch, [ 'type' => 'note', 'note' => 'y' ] );
		$mine   = BFBT_Photos::attach( $this->log, BFBT_Photos::split( self::multi( [ 'a.png', 'b.png' ] ) ) )['ids'];
		$theirs = BFBT_Photos::attach( $other, BFBT_Photos::split( self::multi( [ 'c.png' ] ) ) )['ids'];

		BFBT_Photos::on_before_delete( $this->log );

		$this->assertSame( $mine, $GLOBALS['bfbt_deleted_attachments'] );
		$this->assertFalse( get_post_status( $mine[0] ) );
		$this->assertFalse( get_post_status( $mine[1] ) );
		$this->assertSame( 'inherit', get_post_status( $theirs[0] ) );
		$this->assertSame( [], BFBT_Photos::for_log( $this->log ) );
	}

	public function test_deleting_a_batch_or_a_task_does_not_reach_for_photos(): void {
		$task = BFBT_Task::create( $this->batch, [ 'type' => 'rack' ] );
		$mine = BFBT_Photos::attach( $this->log, BFBT_Photos::split( self::multi( [ 'a.png' ] ) ) )['ids'];

		// The batch's own delete reaches the entry through the cascade, which
		// calls wp_delete_post on it; that entry then fires this hook itself.
		BFBT_Photos::on_before_delete( $this->batch );
		BFBT_Photos::on_before_delete( $task );

		$this->assertSame( [], $GLOBALS['bfbt_deleted_attachments'] );
		$this->assertSame( 'inherit', get_post_status( $mine[0] ) );
	}

	public function test_the_hook_is_registered_on_before_delete_post(): void {
		BFBT_Photos::init();

		$this->assertContains( [ 'BFBT_Photos', 'on_before_delete' ], $GLOBALS['bfbt_hooks']['before_delete_post'] ?? [] );
		$this->assertNotContains( [ 'BFBT_Photos', 'on_before_delete' ], $GLOBALS['bfbt_hooks']['trashed_post'] ?? [], 'trashing keeps the photos' );
	}

	public function test_upload_error_text_covers_every_php_code(): void {
		foreach ( [ UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION, 99 ] as $code ) {
			$text = BFBT_Photos::upload_error_text( $code );
			$this->assertNotSame( '', $text );
			$this->assertStringEndsWith( '.', $text );
		}

		$this->assertSame( 'the file is larger than this site allows (8 MB).', BFBT_Photos::upload_error_text( UPLOAD_ERR_INI_SIZE ) );
		$this->assertSame( 'the upload failed.', BFBT_Photos::upload_error_text( 99 ) );
	}
}
