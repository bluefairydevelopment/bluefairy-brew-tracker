<?php
/**
 * Trash, restore and delete a batch's entries and tasks with the batch.
 *
 * WordPress does not cascade custom-type children on its own; without this
 * an orphaned task would keep appearing in the digest and calendar after
 * its batch was trashed. Children trashed here are marked, so restoring the
 * batch restores exactly the children that went with it and not an entry
 * the maker had trashed on purpose beforehand.
 *
 * Also owns the wp_untrash_post_status filter for all three types: core
 * restores a post to draft unless told otherwise, and a draft batch is
 * invisible to a list that shows published ones.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class BFBT_Batch_Cascade {

	/** Set on a child trashed by the cascade; cleared when it is restored. */
	public const WITH_BATCH_META = '_bfbt_trashed_with_batch';

	/** Children handled per query; the loop re-queries until none are left. */
	public const PAGE_SIZE = 500;

	/** A safety stop for the re-query loop; 100 × 500 is far past any hobby log. */
	private const MAX_PAGES = 100;

	public static function init(): void {
		add_action( 'trashed_post', [ __CLASS__, 'on_trashed' ] );
		add_action( 'untrashed_post', [ __CLASS__, 'on_untrashed' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'on_before_delete' ] );
		add_filter( 'wp_untrash_post_status', [ __CLASS__, 'untrash_status' ], 10, 3 );
	}

	/**
	 * Restore our types to the status they had, not to draft.
	 */
	public static function untrash_status( string $new_status, int $post_id, string $previous_status ): string {
		if ( in_array( get_post_type( $post_id ), self::types(), true ) ) {
			return 'trash' === $previous_status || '' === $previous_status ? 'publish' : $previous_status;
		}

		return $new_status;
	}

	public static function on_trashed( int $post_id ): void {
		if ( ! self::is_batch( $post_id ) ) {
			return;
		}

		self::each_child( $post_id, 'publish', static function ( int $child_id ): void {
			update_post_meta( $child_id, self::WITH_BATCH_META, '1' );
			wp_trash_post( $child_id );
		} );
	}

	public static function on_untrashed( int $post_id ): void {
		if ( ! self::is_batch( $post_id ) ) {
			return;
		}

		self::each_child( $post_id, 'trash', static function ( int $child_id ): void {
			if ( '' === (string) get_post_meta( $child_id, self::WITH_BATCH_META, true ) ) {
				return;
			}

			delete_post_meta( $child_id, self::WITH_BATCH_META );
			wp_untrash_post( $child_id );

			// Restoring re-runs core's future-dating rule; an entry dated
			// ahead would come back as "future" and vanish again.
			if ( BFBT_Batch::LOG_TYPE === get_post_type( $child_id ) ) {
				BFBT_Log::keep_published( $child_id );
			}
		} );
	}

	public static function on_before_delete( int $post_id ): void {
		if ( ! self::is_batch( $post_id ) ) {
			return;
		}

		self::each_child( $post_id, 'any', static function ( int $child_id ): void {
			wp_delete_post( $child_id, true );
		} );
	}

	/**
	 * @return string[]
	 */
	private static function types(): array {
		return [ BFBT_Batch::POST_TYPE, BFBT_Batch::LOG_TYPE, BFBT_Batch::TASK_TYPE ];
	}

	private static function is_batch( int $post_id ): bool {
		return BFBT_Batch::POST_TYPE === get_post_type( $post_id );
	}

	/**
	 * Apply a callback to every child of a status, PAGE_SIZE at a time.
	 *
	 * The callback changes the child's status (or removes it), so each pass
	 * re-runs page one; a callback that leaves a child untouched would loop,
	 * which MAX_PAGES stops. The untrash pass skips children it does not
	 * own, so it pages forward instead.
	 *
	 * @param callable(int): void $callback
	 */
	private static function each_child( int $batch_id, string $status, callable $callback ): void {
		$paged = 1;

		for ( $pass = 0; $pass < self::MAX_PAGES; $pass++ ) {
			$query = new WP_Query( [
				'post_type'              => [ BFBT_Batch::LOG_TYPE, BFBT_Batch::TASK_TYPE ],
				'post_status'            => $status,
				'post_parent'            => $batch_id,
				'posts_per_page'         => self::PAGE_SIZE,
				'paged'                  => $paged,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => 'trash' === $status,
			] );

			$ids = array_map( 'intval', $query->posts );

			if ( ! $ids ) {
				return;
			}

			foreach ( $ids as $child_id ) {
				$callback( $child_id );
			}

			if ( count( $ids ) < self::PAGE_SIZE ) {
				return;
			}

			// Restoring leaves the children it skipped in the trash, so the
			// next page is really the next page; the other passes drain the
			// status they query and start over.
			if ( 'trash' === $status ) {
				++$paged;
			}
		}
	}
}
