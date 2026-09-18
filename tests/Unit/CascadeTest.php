<?php
/**
 * Trashing, restoring and deleting a batch carries its children with it.
 *
 * The hooks are wired by init(); here the handlers are called the way
 * WordPress would call them, with the stubbed post store standing in.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CascadeTest extends TestCase {

	private int $batch = 0;
	private int $other = 0;
	private int $log   = 0;
	private int $task  = 0;
	private int $theirs = 0;

	protected function setUp(): void {
		bfbt_reset_stubs();
		$this->batch  = wp_insert_post( [ 'post_type' => 'bfbt_batch', 'post_title' => 'Mine' ] );
		$this->other  = wp_insert_post( [ 'post_type' => 'bfbt_batch', 'post_title' => 'Other' ] );
		$this->log    = BFBT_Log::create( $this->batch, [ 'type' => 'note', 'note' => 'x' ] );
		$this->task   = BFBT_Task::create( $this->batch, [ 'type' => 'rack' ] );
		$this->theirs = BFBT_Task::create( $this->other, [ 'type' => 'rack' ] );
	}

	private function post_status_of( int $id ): string {
		return (string) ( $GLOBALS['bfbt_posts'][ $id ]['post_status'] ?? 'gone' );
	}

	public function test_trash_cascades_to_this_batchs_children_only(): void {
		wp_trash_post( $this->batch );
		BFBT_Batch_Cascade::on_trashed( $this->batch );

		$this->assertSame( 'trash', $this->post_status_of( $this->log ) );
		$this->assertSame( 'trash', $this->post_status_of( $this->task ) );
		$this->assertSame( 'publish', $this->post_status_of( $this->theirs ) );
		$this->assertSame( '1', $GLOBALS['bfbt_meta'][ $this->task ]['_bfbt_trashed_with_batch'] );
	}

	public function test_restore_brings_back_only_the_children_that_went_with_it(): void {
		$earlier = BFBT_Log::create( $this->batch, [ 'type' => 'note', 'note' => 'trashed on purpose' ] );
		wp_trash_post( $earlier );

		wp_trash_post( $this->batch );
		BFBT_Batch_Cascade::on_trashed( $this->batch );

		wp_untrash_post( $this->batch );
		BFBT_Batch_Cascade::on_untrashed( $this->batch );

		$this->assertSame( 'publish', $this->post_status_of( $this->log ) );
		$this->assertSame( 'publish', $this->post_status_of( $this->task ) );
		$this->assertSame( 'trash', $this->post_status_of( $earlier ) );
		$this->assertArrayNotHasKey( '_bfbt_trashed_with_batch', $GLOBALS['bfbt_meta'][ $this->task ] );
	}

	/**
	 * Found by the live harness: the restored 09-21 reading came back "future".
	 */
	public function test_restore_republishes_a_future_dated_entry(): void {
		$ahead = BFBT_Log::create( $this->batch, [ 'type' => 'gravity', 'sg' => '1.040', 'time' => '2026-09-21 09:00' ] );
		$this->assertSame( 'publish', $this->post_status_of( $ahead ) );

		wp_trash_post( $this->batch );
		BFBT_Batch_Cascade::on_trashed( $this->batch );
		$this->assertSame( 'trash', $this->post_status_of( $ahead ) );

		wp_untrash_post( $this->batch );
		BFBT_Batch_Cascade::on_untrashed( $this->batch );

		$this->assertSame( 'publish', $this->post_status_of( $ahead ) );
	}

	public function test_the_stub_untrash_itself_future_dates(): void {
		// Positive control for the test above.
		$ahead = wp_insert_post( [ 'post_type' => 'bfbt_log', 'post_date' => '2026-09-21 09:00:00', 'post_status' => 'trash' ] );
		wp_untrash_post( $ahead );

		$this->assertSame( 'future', $this->post_status_of( $ahead ) );
	}

	public function test_delete_removes_the_children(): void {
		BFBT_Batch_Cascade::on_before_delete( $this->batch );

		$this->assertSame( 'gone', $this->post_status_of( $this->log ) );
		$this->assertSame( 'gone', $this->post_status_of( $this->task ) );
		$this->assertSame( 'publish', $this->post_status_of( $this->theirs ) );
	}

	public function test_a_log_id_does_not_cascade(): void {
		BFBT_Batch_Cascade::on_trashed( $this->log );

		$this->assertSame( 'publish', $this->post_status_of( $this->task ) );
	}

	public function test_our_types_untrash_to_publish_not_draft(): void {
		$this->assertSame( 'publish', BFBT_Batch_Cascade::untrash_status( 'draft', $this->batch, 'publish' ) );
		$this->assertSame( 'publish', BFBT_Batch_Cascade::untrash_status( 'draft', $this->task, 'publish' ) );

		$page = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Not ours' ] );
		$this->assertSame( 'draft', BFBT_Batch_Cascade::untrash_status( 'draft', $page, 'publish' ) );
	}
}
