<?php
/**
 * The timeline entry: what went in, what was measured, when.
 *
 * Entries are immutable. This class exposes create() and trash() and nothing
 * that edits — a typo is trashed and re-entered — so a number on the batch
 * view can always be traced to the entry that produced it. The entry's time
 * lives in post_date / post_date_gmt (site-local, on the core
 * type_status_date index), so "latest gravity" and the chart are ordinary
 * date queries with no meta join.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class BFBT_Log {

	public const TYPES = [ 'addition', 'gravity', 'temperature', 'ph', 'note', 'racking', 'stage' ];

	/** The most points the chart will draw. */
	public const SERIES_LIMIT = 500;

	public const META = [
		'type'         => '_bfbt_log_type',
		'ingredient'   => '_bfbt_ingredient',
		'qty'          => '_bfbt_qty',
		'unit'         => '_bfbt_unit',
		'sg'           => '_bfbt_sg',
		'temp_c'       => '_bfbt_temp_c',
		'ph'           => '_bfbt_ph',
		'volume_out_l' => '_bfbt_volume_out_l',
		'task_id'      => '_bfbt_task_id',
	];

	/**
	 * @return string[]
	 */
	public static function types(): array {
		/**
		 * Filter the entry types. Pro adds its own.
		 *
		 * @param string[] $types
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'bfbt_log_types', self::TYPES ) ) ) );
	}

	/**
	 * @return array<string, string>
	 */
	public static function type_labels(): array {
		return [
			'addition'    => __( 'Addition', 'bluefairy-brew-tracker' ),
			'gravity'     => __( 'Gravity', 'bluefairy-brew-tracker' ),
			'temperature' => __( 'Temperature', 'bluefairy-brew-tracker' ),
			'ph'          => __( 'pH', 'bluefairy-brew-tracker' ),
			'note'        => __( 'Note', 'bluefairy-brew-tracker' ),
			'racking'     => __( 'Racking', 'bluefairy-brew-tracker' ),
			'stage'       => __( 'Stage', 'bluefairy-brew-tracker' ),
		];
	}

	public static function is_log( int $log_id ): bool {
		return $log_id > 0 && BFBT_Batch::LOG_TYPE === get_post_type( $log_id );
	}

	public static function field( int $log_id, string $field ): string {
		$key = self::META[ $field ] ?? null;

		return null === $key ? '' : (string) get_post_meta( $log_id, $key, true );
	}

	/**
	 * The ingredient as the maker typed it, or as an add-on resolves it.
	 *
	 * Pro resolves free text to a bfbt_ingredient term here, on read, so an
	 * entry logged before Pro was bought groups correctly and nothing is
	 * back-filled.
	 */
	public static function ingredient_label( string $ingredient ): string {
		/**
		 * Filter the display label for an ingredient logged as text.
		 *
		 * @param string $label
		 * @param string $ingredient The text as entered.
		 */
		return (string) apply_filters( 'bfbt_ingredient_label', $ingredient, $ingredient );
	}

	/**
	 * Create an entry.
	 *
	 * Accepted keys: type (required), time ("Y-m-d H:i", default now), note,
	 * and per type — addition: ingredient (required), qty, unit; gravity: sg
	 * (required), temp_c; temperature: temp_c (required); ph: ph (required);
	 * racking: volume_out (litres) or volume_out + volume_out_unit; stage:
	 * label; task_id on any. Anything refused is quoted back; nothing is
	 * written.
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

		$post_date = $clean['time'] . ':00';

		$log_id = wp_insert_post( [
			'post_type'     => BFBT_Batch::LOG_TYPE,
			'post_status'   => 'publish',
			'post_parent'   => $batch_id,
			'post_title'    => self::summary( $clean ),
			'post_content'  => $clean['note'],
			'post_date'     => $post_date,
			'post_date_gmt' => get_gmt_from_date( $post_date ),
		], true );

		if ( is_wp_error( $log_id ) ) {
			return $log_id;
		}

		if ( (int) $log_id <= 0 ) {
			return new WP_Error( 'bfbt_insert_failed', __( 'The entry could not be saved.', 'bluefairy-brew-tracker' ) );
		}

		$log_id = (int) $log_id;

		self::keep_published( $log_id );

		foreach ( self::META as $field => $key ) {
			if ( isset( $clean[ $field ] ) && '' !== $clean[ $field ] ) {
				update_post_meta( $log_id, $key, $clean[ $field ] );
			}
		}

		// The first gravity reading is the original gravity unless the maker
		// has already pinned one. A later trash of that entry clears the pin.
		if ( 'gravity' === $clean['type'] && '' === (string) BFBT_Batch::get( $batch_id, 'og_log_id' ) ) {
			BFBT_Batch::set( $batch_id, 'og_log_id', (string) $log_id );
		}

		do_action( 'bfbt_log_created', $log_id, $batch_id, $clean );

		return $log_id;
	}

	/**
	 * An entry's time is data, not a schedule.
	 *
	 * wp_insert_post() turns a published post whose date is a minute or more
	 * ahead of now into status "future" and schedules it — so a reading the
	 * maker dated tomorrow (or a site clock behind the maker's) would vanish
	 * from every publish-only query: latest gravity, the chart, the cascade.
	 * Put the status back and drop the scheduled publish. wp_untrash_post()
	 * runs the same rule, so the cascade calls this after a restore too.
	 */
	public static function keep_published( int $log_id ): void {
		global $wpdb;

		if ( 'future' !== get_post_status( $log_id ) ) {
			return;
		}

		$wpdb->update( $wpdb->posts, [ 'post_status' => 'publish' ], [ 'ID' => $log_id ] );
		clean_post_cache( $log_id );
		wp_clear_scheduled_hook( 'publish_future_post', [ $log_id ] );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function trash( int $log_id ): true|WP_Error {
		if ( ! self::is_log( $log_id ) ) {
			return new WP_Error( 'bfbt_no_log', __( 'That entry no longer exists.', 'bluefairy-brew-tracker' ) );
		}

		$post     = get_post( $log_id );
		$batch_id = $post ? (int) $post->post_parent : 0;

		if ( ! wp_trash_post( $log_id ) ) {
			return new WP_Error( 'bfbt_trash_failed', __( 'The entry could not be moved to the trash.', 'bluefairy-brew-tracker' ) );
		}

		if ( $batch_id > 0 && (string) $log_id === (string) BFBT_Batch::get( $batch_id, 'og_log_id' ) ) {
			BFBT_Batch::set( $batch_id, 'og_log_id', '' );
		}

		return true;
	}

	/**
	 * Pin a gravity entry of this batch as its original gravity.
	 *
	 * @return true|WP_Error
	 */
	public static function pin_as_og( int $batch_id, int $log_id ): true|WP_Error {
		if ( ! self::is_log( $log_id ) ) {
			return new WP_Error( 'bfbt_no_log', __( 'That entry no longer exists.', 'bluefairy-brew-tracker' ) );
		}

		$post = get_post( $log_id );

		if ( ! $post || (int) $post->post_parent !== $batch_id || 'gravity' !== self::field( $log_id, 'type' ) ) {
			return new WP_Error( 'bfbt_og_log', __( 'Only a gravity reading of this batch can be pinned as its original gravity.', 'bluefairy-brew-tracker' ) );
		}

		return BFBT_Batch::update( $batch_id, [ 'og_log_id' => (string) $log_id ] );
	}

	/**
	 * One page of a batch's entries, newest first.
	 *
	 * @return array{items: WP_Post[], total: int, pages: int}
	 */
	public static function for_batch( int $batch_id, int $page = 1, int $per_page = 50 ): array {
		$query = new WP_Query( [
			'post_type'              => BFBT_Batch::LOG_TYPE,
			'post_status'            => 'publish',
			'post_parent'            => $batch_id,
			'posts_per_page'         => max( 1, min( 200, $per_page ) ),
			'paged'                  => max( 1, $page ),
			'orderby'                => [ 'date' => 'DESC', 'ID' => 'DESC' ],
			'update_post_term_cache' => false,
			'update_post_meta_cache' => true,
		] );

		return [
			'items' => $query->posts,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		];
	}

	/**
	 * The most recent gravity reading, or null when none has been logged.
	 *
	 * @return array{id: int, sg: string, date: string, time: string, temp_c: string}|null
	 */
	public static function latest_gravity( int $batch_id ): ?array {
		$query = new WP_Query( [
			'post_type'              => BFBT_Batch::LOG_TYPE,
			'post_status'            => 'publish',
			'post_parent'            => $batch_id,
			'posts_per_page'         => 1,
			'orderby'                => [ 'date' => 'DESC', 'ID' => 'DESC' ],
			'meta_key'               => self::META['type'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the reading type is the selector.
			'meta_value'             => 'gravity', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'update_post_term_cache' => false,
			'update_post_meta_cache' => true,
		] );

		if ( ! $query->posts ) {
			return null;
		}

		return self::reading( $query->posts[0] );
	}

	/**
	 * The most recent gravity reading for each of a set of batches, in one
	 * query — the list table's "Latest SG" column for a page of rows.
	 *
	 * The inner query finds each batch's latest gravity post_date; the outer
	 * one fetches the rows at that date. Two readings at the same minute tie,
	 * and pick_latest() keeps the higher ID.
	 *
	 * @param  int[] $batch_ids
	 * @return array<int, array{id: int, sg: string, date: string, time: string}>
	 */
	public static function latest_gravity_for( array $batch_ids ): array {
		global $wpdb;

		$batch_ids = array_values( array_filter( array_map( 'absint', $batch_ids ) ) );

		if ( ! $batch_ids ) {
			return [];
		}

		$in = implode( ',', array_fill( 0, count( $batch_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of %d placeholders; every value goes through prepare().
		$sql = "SELECT p.ID, p.post_parent, p.post_date, sg.meta_value AS sg
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} sg ON sg.post_id = p.ID AND sg.meta_key = '_bfbt_sg'
			INNER JOIN (
				SELECT p2.post_parent, MAX(p2.post_date) AS latest
				FROM {$wpdb->posts} p2
				INNER JOIN {$wpdb->postmeta} t2 ON t2.post_id = p2.ID AND t2.meta_key = '_bfbt_log_type' AND t2.meta_value = 'gravity'
				WHERE p2.post_type = %s AND p2.post_status = 'publish' AND p2.post_parent IN ({$in})
				GROUP BY p2.post_parent
			) latest ON latest.post_parent = p.post_parent AND latest.latest = p.post_date
			WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_parent IN ({$in})";

		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, array_merge( [ BFBT_Batch::LOG_TYPE ], $batch_ids, [ BFBT_Batch::LOG_TYPE ], $batch_ids ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		// phpcs:enable

		return self::pick_latest( is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Reduce candidate rows (ID, post_parent, post_date, sg) to one per
	 * batch: the latest date, then the highest ID.
	 *
	 * @param  array<int, array<string, mixed>> $rows
	 * @return array<int, array{id: int, sg: string, date: string, time: string}>
	 */
	public static function pick_latest( array $rows ): array {
		$latest = [];

		foreach ( $rows as $row ) {
			$batch_id = (int) ( $row['post_parent'] ?? 0 );
			$id       = (int) ( $row['ID'] ?? 0 );
			$time     = (string) ( $row['post_date'] ?? '' );

			if ( $batch_id <= 0 || $id <= 0 ) {
				continue;
			}

			$current = $latest[ $batch_id ] ?? null;

			if ( $current && ( strcmp( $current['time'], $time ) > 0 || ( $current['time'] === $time && $current['id'] > $id ) ) ) {
				continue;
			}

			$latest[ $batch_id ] = [
				'id'   => $id,
				'sg'   => (string) ( $row['sg'] ?? '' ),
				'date' => substr( $time, 0, 10 ),
				'time' => $time,
			];
		}

		return $latest;
	}

	/**
	 * A batch's gravity readings, oldest first, for the chart.
	 *
	 * @return array<int, array{id: int, date: string, time: string, sg: float, label: string}>
	 */
	public static function gravity_series( int $batch_id ): array {
		$query = new WP_Query( [
			'post_type'              => BFBT_Batch::LOG_TYPE,
			'post_status'            => 'publish',
			'post_parent'            => $batch_id,
			'posts_per_page'         => self::SERIES_LIMIT,
			'orderby'                => [ 'date' => 'ASC', 'ID' => 'ASC' ],
			'meta_key'               => self::META['type'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'             => 'gravity', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'update_post_term_cache' => false,
			'update_post_meta_cache' => true,
		] );

		$series = [];

		foreach ( $query->posts as $post ) {
			$reading  = self::reading( $post );
			$series[] = [
				'id'    => $reading['id'],
				'date'  => $reading['date'],
				'time'  => $reading['time'],
				'sg'    => (float) $reading['sg'],
				'label' => (string) $post->post_title,
			];
		}

		return $series;
	}

	/**
	 * @param  WP_Post|object $post
	 * @return array{id: int, sg: string, date: string, time: string, temp_c: string}
	 */
	private static function reading( object $post ): array {
		$id = (int) $post->ID;

		return [
			'id'     => $id,
			'sg'     => self::field( $id, 'sg' ),
			'date'   => substr( (string) $post->post_date, 0, 10 ),
			'time'   => (string) $post->post_date,
			'temp_c' => self::field( $id, 'temp_c' ),
		];
	}

	/**
	 * The generated title: "Fermaid-O 1.2 g", "SG 1.042 @ 20 °C".
	 *
	 * @param array<string, mixed> $clean
	 */
	public static function summary( array $clean ): string {
		switch ( $clean['type'] ) {
			case 'addition':
				$parts = [ $clean['ingredient'] ];

				if ( '' !== $clean['qty'] ) {
					$parts[] = self::trim_number( $clean['qty'] ) . ' ' . $clean['unit'];
				}

				return implode( ' ', $parts );

			case 'gravity':
				$title = 'SG ' . $clean['sg'];

				if ( '' !== $clean['temp_c'] ) {
					$title .= ' @ ' . self::trim_number( $clean['temp_c'] ) . ' °C';
				}

				return $title;

			case 'temperature':
				return self::trim_number( $clean['temp_c'] ) . ' °C';

			case 'ph':
				return 'pH ' . $clean['ph'];

			case 'racking':
				$title = __( 'Racked', 'bluefairy-brew-tracker' );

				if ( '' !== $clean['volume_out_l'] ) {
					/* translators: %s: litres racked across */
					$title .= ' — ' . sprintf( __( '%s l across', 'bluefairy-brew-tracker' ), self::trim_number( $clean['volume_out_l'] ) );
				}

				return $title;

			case 'stage':
				return '' !== $clean['label']
					? $clean['label']
					: __( 'Stage', 'bluefairy-brew-tracker' );

			case 'note':
			default:
				if ( '' !== $clean['label'] ) {
					return $clean['label'];
				}

				$text = trim( wp_strip_all_tags( (string) $clean['note'] ) );

				if ( '' === $text ) {
					return (string) ( self::type_labels()[ $clean['type'] ] ?? ucfirst( (string) $clean['type'] ) );
				}

				return mb_strlen( $text ) > 60 ? mb_substr( $text, 0, 57 ) . '…' : $text;
		}
	}

	/**
	 * "1.200" → "1.2", "20.0" → "20".
	 */
	public static function trim_number( string $number ): string {
		return str_contains( $number, '.' ) ? rtrim( rtrim( $number, '0' ), '.' ) : $number;
	}

	/**
	 * @param  array<string, mixed> $data
	 * @return array<string, mixed>|WP_Error
	 */
	private static function validate( array $data ): array|WP_Error {
		$type = strtolower( sanitize_text_field( (string) ( $data['type'] ?? '' ) ) );

		if ( ! in_array( $type, self::types(), true ) ) {
			return new WP_Error(
				'bfbt_log_type',
				sprintf(
					/* translators: 1: the type given, 2: the accepted list */
					__( '“%1$s” is not an entry type this plugin knows. Use one of: %2$s.', 'bluefairy-brew-tracker' ),
					(string) ( $data['type'] ?? '' ),
					implode( ', ', self::types() )
				)
			);
		}

		$time = BFBT_Validate::datetime( (string) ( $data['time'] ?? '' ), true );

		if ( is_wp_error( $time ) ) {
			return $time;
		}

		$clean = [
			'type'         => $type,
			'time'         => '' === $time ? current_time( 'Y-m-d H:i' ) : $time,
			'note'         => wp_kses_post( (string) ( $data['note'] ?? '' ) ),
			'label'        => sanitize_text_field( (string) ( $data['label'] ?? '' ) ),
			'ingredient'   => '',
			'qty'          => '',
			'unit'         => '',
			'sg'           => '',
			'temp_c'       => '',
			'ph'           => '',
			'volume_out_l' => '',
			'task_id'      => '',
		];

		$task_id = absint( $data['task_id'] ?? 0 );

		if ( $task_id > 0 ) {
			if ( BFBT_Batch::TASK_TYPE !== get_post_type( $task_id ) ) {
				return new WP_Error( 'bfbt_task_id', __( 'The task this entry completes no longer exists.', 'bluefairy-brew-tracker' ) );
			}

			$clean['task_id'] = (string) $task_id;
		}

		if ( 'addition' === $type ) {
			$clean['ingredient'] = sanitize_text_field( (string) ( $data['ingredient'] ?? '' ) );

			if ( '' === $clean['ingredient'] ) {
				return new WP_Error( 'bfbt_ingredient_required', __( 'An addition needs an ingredient.', 'bluefairy-brew-tracker' ) );
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

		if ( 'gravity' === $type ) {
			$sg = BFBT_Validate::gravity( (string) ( $data['sg'] ?? '' ) );

			if ( is_wp_error( $sg ) ) {
				return $sg;
			}

			$clean['sg'] = $sg;
		}

		if ( 'gravity' === $type || 'temperature' === $type ) {
			$temp = BFBT_Validate::temperature_c( (string) ( $data['temp_c'] ?? '' ), 'gravity' === $type );

			if ( is_wp_error( $temp ) ) {
				return $temp;
			}

			$clean['temp_c'] = $temp;
		}

		if ( 'ph' === $type ) {
			$ph = BFBT_Validate::ph( (string) ( $data['ph'] ?? '' ) );

			if ( is_wp_error( $ph ) ) {
				return $ph;
			}

			$clean['ph'] = $ph;
		}

		if ( 'racking' === $type ) {
			$volume = trim( (string) ( $data['volume_out'] ?? '' ) );

			if ( '' !== $volume ) {
				$unit   = (string) ( $data['volume_out_unit'] ?? 'l' );
				$litres = BFBT_Validate::volume( $volume, '' === $unit ? 'l' : $unit );

				if ( is_wp_error( $litres ) ) {
					return $litres;
				}

				$clean['volume_out_l'] = number_format( $litres, 3, '.', '' );
			}
		}

		return $clean;
	}
}
