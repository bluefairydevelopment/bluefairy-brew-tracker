<?php
/**
 * A scheduled step: what to do, when, and when it was done.
 *
 * Due is always present and never empty — a task with no calendar date
 * stores 9999-12-31 — because an empty string sorts before every date and
 * would read as overdue. Done means "the _bfbt_done_at key is absent";
 * uncomplete() deletes the key and never writes '', and every open-task
 * query accepts NOT EXISTS OR = '' so a stray '' cannot hide a task forever.
 *
 * complete() is idempotent: a task already done is refused with WP_Error
 * bfbt_task_done and bfbt_task_completed fires only on the transition, so a
 * double-click or a replayed POST cannot write two log entries.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class BFBT_Task {

	public const TYPES = [ 'addition', 'gravity', 'rack', 'package', 'custom' ];

	/** The due date of a task that has no calendar date. */
	public const NO_DATE = '9999-12-31';

	/** The most open tasks one batch screen will list. */
	public const OPEN_LIMIT = 200;

	public const META = [
		'type'       => '_bfbt_task_type',
		'due'        => '_bfbt_due',
		'done_at'    => '_bfbt_done_at',
		'ingredient' => '_bfbt_ingredient',
		'qty'        => '_bfbt_qty',
		'unit'       => '_bfbt_unit',
	];

	/**
	 * @return string[]
	 */
	public static function types(): array {
		/**
		 * Filter the task types. Pro adds stabilise, backsweeten, cold_crash.
		 *
		 * @param string[] $types
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'bfbt_task_types', self::TYPES ) ) ) );
	}

	/**
	 * @return array<string, string>
	 */
	public static function type_labels(): array {
		return [
			'addition' => __( 'Addition', 'bluefairy-brew-tracker' ),
			'gravity'  => __( 'Gravity check', 'bluefairy-brew-tracker' ),
			'rack'     => __( 'Rack', 'bluefairy-brew-tracker' ),
			'package'  => __( 'Package', 'bluefairy-brew-tracker' ),
			'custom'   => __( 'Other', 'bluefairy-brew-tracker' ),
		];
	}

	public static function is_task( int $task_id ): bool {
		return $task_id > 0 && BFBT_Batch::TASK_TYPE === get_post_type( $task_id );
	}

	public static function field( int $task_id, string $field ): string {
		$key = self::META[ $field ] ?? null;

		return null === $key ? '' : (string) get_post_meta( $task_id, $key, true );
	}

	public static function is_done( int $task_id ): bool {
		return '' !== self::field( $task_id, 'done_at' );
	}

	public static function has_date( int $task_id ): bool {
		$due = self::field( $task_id, 'due' );

		return '' !== $due && self::NO_DATE !== $due;
	}

	/**
	 * Create a task.
	 *
	 * Accepted keys: type (required), title, due ("Y-m-d", optional), and
	 * for additions ingredient (required), qty, unit. An addition with no
	 * quantity keeps its instruction in the title ("water to 1 gal").
	 *
	 * @param  array<string, mixed> $data
	 * @return int|WP_Error
	 */
	public static function create( int $batch_id, array $data ): int|WP_Error {
		if ( ! BFBT_Batch::is_batch( $batch_id ) ) {
			return new WP_Error( 'bfbt_no_batch', __( 'That batch no longer exists.', 'bluefairy-brew-tracker' ) );
		}

		$clean = self::validate( $data );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$task_id = wp_insert_post( [
			'post_type'   => BFBT_Batch::TASK_TYPE,
			'post_status' => 'publish',
			'post_parent' => $batch_id,
			'post_title'  => $clean['title'],
		], true );

		if ( is_wp_error( $task_id ) ) {
			return $task_id;
		}

		if ( (int) $task_id <= 0 ) {
			return new WP_Error( 'bfbt_insert_failed', __( 'The task could not be saved.', 'bluefairy-brew-tracker' ) );
		}

		$task_id = (int) $task_id;

		update_post_meta( $task_id, self::META['type'], $clean['type'] );
		update_post_meta( $task_id, self::META['due'], $clean['due'] );

		foreach ( [ 'ingredient', 'qty', 'unit' ] as $field ) {
			if ( '' !== $clean[ $field ] ) {
				update_post_meta( $task_id, self::META[ $field ], $clean[ $field ] );
			}
		}

		return $task_id;
	}

	/**
	 * Mark a task done and write the log entry it produces.
	 *
	 * An addition task logs the addition with its ingredient, quantity and
	 * unit; every other type logs a note "Done: <title>". The entry is
	 * written before done-at is stamped, so a refused entry leaves the task
	 * open and reported as such.
	 *
	 * @return int|WP_Error The log entry id.
	 */
	public static function complete( int $task_id ): int|WP_Error {
		if ( ! self::is_task( $task_id ) ) {
			return new WP_Error( 'bfbt_no_task', __( 'That task no longer exists.', 'bluefairy-brew-tracker' ) );
		}

		if ( self::is_done( $task_id ) ) {
			return new WP_Error( 'bfbt_task_done', __( 'That task is already done.', 'bluefairy-brew-tracker' ) );
		}

		$post     = get_post( $task_id );
		$batch_id = $post ? (int) $post->post_parent : 0;
		$title    = $post ? (string) $post->post_title : '';

		if ( 'addition' === self::field( $task_id, 'type' ) ) {
			$entry = [
				'type'       => 'addition',
				'ingredient' => self::field( $task_id, 'ingredient' ),
				'qty'        => self::field( $task_id, 'qty' ),
				'unit'       => self::field( $task_id, 'unit' ),
				'task_id'    => $task_id,
			];

			// An addition whose instruction lives in the title ("water to 1 gal").
			if ( '' === $entry['ingredient'] ) {
				$entry['ingredient'] = $title;
			}
		} else {
			$entry = [
				'type'    => 'note',
				/* translators: %s: the task title */
				'label'   => sprintf( __( 'Done: %s', 'bluefairy-brew-tracker' ), $title ),
				'task_id' => $task_id,
			];
		}

		$log_id = BFBT_Log::create( $batch_id, $entry );

		if ( is_wp_error( $log_id ) ) {
			return $log_id;
		}

		update_post_meta( $task_id, self::META['done_at'], current_time( 'mysql' ) );

		/**
		 * A task has just been completed. Fires once per transition.
		 *
		 * @param int $task_id
		 * @param int $batch_id
		 * @param int $log_id   The entry the completion wrote.
		 */
		do_action( 'bfbt_task_completed', $task_id, $batch_id, $log_id );

		return $log_id;
	}

	/**
	 * Reopen a task. Deletes the key; never writes ''.
	 *
	 * @return true|WP_Error
	 */
	public static function uncomplete( int $task_id ): true|WP_Error {
		if ( ! self::is_task( $task_id ) ) {
			return new WP_Error( 'bfbt_no_task', __( 'That task no longer exists.', 'bluefairy-brew-tracker' ) );
		}

		delete_post_meta( $task_id, self::META['done_at'] );

		return true;
	}

	/**
	 * A batch's open tasks, soonest first, bounded.
	 *
	 * @return WP_Post[]
	 */
	public static function open_for_batch( int $batch_id ): array {
		$query = new WP_Query( [
			'post_type'              => BFBT_Batch::TASK_TYPE,
			'post_status'            => 'publish',
			'post_parent'            => $batch_id,
			'posts_per_page'         => self::OPEN_LIMIT,
			'orderby'                => [ 'due' => 'ASC', 'ID' => 'ASC' ],
			'update_post_term_cache' => false,
			'update_post_meta_cache' => true,
			'meta_query'             => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- due and done-at are the selectors.
				'relation' => 'AND',
				'due'      => [
					'key'     => self::META['due'],
					'value'   => '',
					'compare' => '!=',
				],
				[
					'relation' => 'OR',
					[
						'key'     => self::META['done_at'],
						'compare' => 'NOT EXISTS',
					],
					[
						'key'     => self::META['done_at'],
						'value'   => '',
						'compare' => '=',
					],
				],
			],
		] );

		return $query->posts;
	}

	/**
	 * Tasks of a batch completed in the last N days, for the Undo control.
	 *
	 * @return WP_Post[]
	 */
	public static function recently_done_for_batch( int $batch_id, int $days = 7 ): array {
		$since = wp_date( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );

		$query = new WP_Query( [
			'post_type'              => BFBT_Batch::TASK_TYPE,
			'post_status'            => 'publish',
			'post_parent'            => $batch_id,
			'posts_per_page'         => 50,
			'orderby'                => [ 'done' => 'DESC' ],
			'update_post_term_cache' => false,
			'update_post_meta_cache' => true,
			'meta_query'             => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'done' => [
					'key'     => self::META['done_at'],
					'value'   => $since,
					'compare' => '>=',
				],
			],
		] );

		return $query->posts;
	}

	/**
	 * The next due task per batch, for a page of the list table.
	 *
	 * One prepared grouped query gives each batch's earliest open due date;
	 * a second fetches the titles of the winning rows. Two queries for any
	 * number of batches, and no per-batch cap that could push one batch's
	 * next-due out of a window.
	 *
	 * @param  int[] $batch_ids
	 * @return array<int, array{due: string, title: string, id: int}>
	 */
	public static function next_due_for( array $batch_ids ): array {
		global $wpdb;

		$batch_ids = array_values( array_filter( array_map( 'absint', $batch_ids ) ) );

		if ( ! $batch_ids ) {
			return [];
		}

		$in = implode( ',', array_fill( 0, count( $batch_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of %d placeholders; every value goes through prepare().
		$open = "FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} due ON due.post_id = p.ID AND due.meta_key = '_bfbt_due'
			LEFT JOIN {$wpdb->postmeta} done ON done.post_id = p.ID AND done.meta_key = '_bfbt_done_at'
			WHERE p.post_type = %s AND p.post_status = 'publish'
				AND p.post_parent IN ({$in})
				AND due.meta_value != ''
				AND (done.meta_id IS NULL OR done.meta_value = '')";

		$mins = $wpdb->get_results(
			$wpdb->prepare( "SELECT p.post_parent, MIN(due.meta_value) AS due {$open} GROUP BY p.post_parent", array_merge( [ BFBT_Batch::TASK_TYPE ], $batch_ids ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$mins = is_array( $mins ) ? $mins : [];

		if ( ! $mins ) {
			return [];
		}

		$dues = array_values( array_unique( array_map( static fn( array $row ): string => (string) $row['due'], $mins ) ) );
		$dues_in = implode( ',', array_fill( 0, count( $dues ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT p.ID, p.post_parent, p.post_title, due.meta_value AS due {$open} AND due.meta_value IN ({$dues_in})", array_merge( [ BFBT_Batch::TASK_TYPE ], $batch_ids, $dues ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		// phpcs:enable

		return self::pick_next_due( $mins, is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Match each batch's minimum due date to the title of the task that
	 * carries it (lowest ID on a tie). A batch whose only open tasks are
	 * undated (9999-12-31) has no next due.
	 *
	 * @param  array<int, array<string, mixed>> $mins Rows of post_parent, due.
	 * @param  array<int, array<string, mixed>> $rows Rows of ID, post_parent, post_title, due.
	 * @return array<int, array{due: string, title: string, id: int}>
	 */
	public static function pick_next_due( array $mins, array $rows ): array {
		$next = [];

		foreach ( $mins as $min ) {
			$batch_id = (int) ( $min['post_parent'] ?? 0 );
			$due      = (string) ( $min['due'] ?? '' );

			if ( $batch_id <= 0 || '' === $due || self::NO_DATE === $due ) {
				continue;
			}

			$next[ $batch_id ] = [ 'due' => $due, 'title' => '', 'id' => 0 ];
		}

		foreach ( $rows as $row ) {
			$batch_id = (int) ( $row['post_parent'] ?? 0 );
			$id       = (int) ( $row['ID'] ?? 0 );

			if ( ! isset( $next[ $batch_id ] ) || (string) ( $row['due'] ?? '' ) !== $next[ $batch_id ]['due'] ) {
				continue;
			}

			if ( 0 === $next[ $batch_id ]['id'] || $id < $next[ $batch_id ]['id'] ) {
				$next[ $batch_id ]['id']    = $id;
				$next[ $batch_id ]['title'] = (string) ( $row['post_title'] ?? '' );
			}
		}

		return $next;
	}

	/**
	 * "Fermaid-O 1.1 g" for an addition with a quantity.
	 *
	 * @param array<string, mixed> $clean
	 */
	private static function generated_title( array $clean ): string {
		if ( 'addition' === $clean['type'] && '' !== $clean['ingredient'] ) {
			return '' === $clean['qty']
				? $clean['ingredient']
				: $clean['ingredient'] . ' ' . BFBT_Log::trim_number( $clean['qty'] ) . ' ' . $clean['unit'];
		}

		return (string) ( self::type_labels()[ $clean['type'] ] ?? ucfirst( str_replace( '_', ' ', $clean['type'] ) ) );
	}

	/**
	 * @param  array<string, mixed> $data
	 * @return array<string, mixed>|WP_Error
	 */
	private static function validate( array $data ): array|WP_Error {
		$type = strtolower( sanitize_text_field( (string) ( $data['type'] ?? '' ) ) );

		if ( ! in_array( $type, self::types(), true ) ) {
			return new WP_Error(
				'bfbt_task_type',
				sprintf(
					/* translators: 1: the type given, 2: the accepted list */
					__( '“%1$s” is not a task type this plugin knows. Use one of: %2$s.', 'bluefairy-brew-tracker' ),
					(string) ( $data['type'] ?? '' ),
					implode( ', ', self::types() )
				)
			);
		}

		$due = BFBT_Validate::date( (string) ( $data['due'] ?? '' ), true );

		if ( is_wp_error( $due ) ) {
			return $due;
		}

		$clean = [
			'type'       => $type,
			'due'        => '' === $due ? self::NO_DATE : $due,
			'title'      => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'ingredient' => '',
			'qty'        => '',
			'unit'       => '',
		];

		if ( 'addition' === $type ) {
			$clean['ingredient'] = sanitize_text_field( (string) ( $data['ingredient'] ?? '' ) );

			if ( '' === $clean['ingredient'] && '' === $clean['title'] ) {
				return new WP_Error( 'bfbt_ingredient_required', __( 'An addition needs an ingredient, or a title that says what to add.', 'bluefairy-brew-tracker' ) );
			}

			$qty = BFBT_Validate::quantity( (string) ( $data['qty'] ?? '' ), true );

			if ( is_wp_error( $qty ) ) {
				return $qty;
			}

			$clean['qty'] = $qty;

			if ( '' !== $qty ) {
				$unit = BFBT_Validate::unit( (string) ( $data['unit'] ?? '' ) );

				if ( is_wp_error( $unit ) ) {
					return $unit;
				}

				$clean['unit'] = $unit;
			}
		}

		if ( '' === $clean['title'] ) {
			$clean['title'] = self::generated_title( $clean );
		}

		return $clean;
	}
}
