<?php
/**
 * The batch record. One definition, owned by the free plugin.
 *
 * The Pro add-on reads and writes batches through this class and registers
 * its own fields with register_field() on bfbt_loaded. It does not register
 * the post types, the taxonomies, or any meta key of its own accord: two
 * plugins that each know the storage layout drift apart at the first schema
 * change, and a batch logged before Pro was bought has to be the same batch
 * afterwards.
 *
 * The log entry and task types are registered here too so that every post
 * type the free plugin owns is declared in one place, but their behaviour
 * lives in BFBT_Log and BFBT_Task.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class BFBT_Batch {

	public const POST_TYPE = 'bfbt_batch';
	public const LOG_TYPE  = 'bfbt_log';
	public const TASK_TYPE = 'bfbt_task';

	public const STYLE_TAX  = 'bfbt_style';
	public const VESSEL_TAX = 'bfbt_vessel';

	/** Term meta on the four top-level style terms only. */
	public const STYLE_CATEGORY_META = 'bfbt_category';

	/** Term meta on a vessel. */
	public const VESSEL_TYPE_META     = 'bfbt_vessel_type';
	public const VESSEL_CAPACITY_META = 'bfbt_capacity_l';

	public const VESSEL_TYPES = [ 'carboy', 'bucket', 'demijohn', 'conical', 'keg', 'barrel', 'other' ];

	/**
	 * Fields the free plugin owns.
	 *
	 * Stage is never one of them: it is derived from the milestone dates by
	 * BFBT_Stage, so no stored value can disagree with what happened.
	 */
	public const CORE_FIELDS = [
		'started'        => '_bfbt_started',
		'volume_l'       => '_bfbt_volume_l',
		'volume_entered' => '_bfbt_volume_entered',
		'target_og'      => '_bfbt_target_og',
		'target_fg'      => '_bfbt_target_fg',
		'secondary_date' => '_bfbt_secondary_date',
		'packaged_date'  => '_bfbt_packaged_date',
		'completed_date' => '_bfbt_completed_date',
		'dumped'         => '_bfbt_dumped',
		'og_log_id'      => '_bfbt_og_log_id',
	];

	/** @var array<string, string> Fields contributed by add-ons. */
	private static array $extra_fields = [];

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ] );
	}

	public static function register(): void {
		$common = [
			'public'          => false,
			'show_ui'         => false,
			'show_in_menu'    => false,
			'show_in_rest'    => false,
			'rewrite'         => false,
			'query_var'       => false,
			'capability_type' => 'post',
			'map_meta_cap'    => true,
		];

		register_post_type( self::POST_TYPE, $common + [
			'labels'   => [
				'name'          => __( 'Batches', 'bluefairy-brew-tracker' ),
				'singular_name' => __( 'Batch', 'bluefairy-brew-tracker' ),
				'add_new_item'  => __( 'New Batch', 'bluefairy-brew-tracker' ),
				'edit_item'     => __( 'Edit Batch', 'bluefairy-brew-tracker' ),
				'not_found'     => __( 'No batches yet', 'bluefairy-brew-tracker' ),
			],
			'supports' => [ 'title', 'editor' ],
		] );

		register_post_type( self::LOG_TYPE, $common + [
			'labels'   => [
				'name'          => __( 'Log Entries', 'bluefairy-brew-tracker' ),
				'singular_name' => __( 'Log Entry', 'bluefairy-brew-tracker' ),
			],
			'supports' => [ 'title' ],
		] );

		register_post_type( self::TASK_TYPE, $common + [
			'labels'   => [
				'name'          => __( 'Tasks', 'bluefairy-brew-tracker' ),
				'singular_name' => __( 'Task', 'bluefairy-brew-tracker' ),
			],
			'supports' => [ 'title' ],
		] );

		register_taxonomy( self::STYLE_TAX, [ self::POST_TYPE ], [
			'labels'            => [
				'name'          => __( 'Styles', 'bluefairy-brew-tracker' ),
				'singular_name' => __( 'Style', 'bluefairy-brew-tracker' ),
				'add_new_item'  => __( 'Add Style', 'bluefairy-brew-tracker' ),
				'edit_item'     => __( 'Edit Style', 'bluefairy-brew-tracker' ),
			],
			'hierarchical'      => true,
			'public'            => false,
			'show_ui'           => false,
			'show_in_menu'      => false,
			'show_in_rest'      => false,
			'show_admin_column' => false,
			'rewrite'           => false,
			'query_var'         => false,
		] );

		register_taxonomy( self::VESSEL_TAX, [ self::POST_TYPE ], [
			'labels'            => [
				'name'          => __( 'Vessels', 'bluefairy-brew-tracker' ),
				'singular_name' => __( 'Vessel', 'bluefairy-brew-tracker' ),
				'add_new_item'  => __( 'Add Vessel', 'bluefairy-brew-tracker' ),
				'edit_item'     => __( 'Edit Vessel', 'bluefairy-brew-tracker' ),
			],
			'hierarchical'      => false,
			'public'            => false,
			'show_ui'           => false,
			'show_in_menu'      => false,
			'show_in_rest'      => false,
			'show_admin_column' => false,
			'rewrite'           => false,
			'query_var'         => false,
		] );
	}

	/**
	 * Let an add-on store a field on a batch without knowing the meta layout.
	 *
	 * Pro registers its milestone dates and recipe link here, so there is
	 * exactly one place that maps a field name to a meta key.
	 */
	public static function register_field( string $name, string $meta_key ): void {
		self::$extra_fields[ $name ] = $meta_key;
	}

	/**
	 * @return array<string, string>
	 */
	public static function fields(): array {
		return array_merge( self::CORE_FIELDS, self::$extra_fields );
	}

	public static function get( int $batch_id, string $field, mixed $fallback = '' ): mixed {
		$key = self::fields()[ $field ] ?? null;

		if ( null === $key || ! self::is_batch( $batch_id ) ) {
			return $fallback;
		}

		$value = get_post_meta( $batch_id, $key, true );

		return '' === $value ? $fallback : $value;
	}

	/**
	 * Write one field as given. Callers that take user input go through
	 * create() / update() so the value is validated first.
	 */
	public static function set( int $batch_id, string $field, mixed $value ): bool {
		$key = self::fields()[ $field ] ?? null;

		if ( null === $key || ! self::is_batch( $batch_id ) ) {
			return false;
		}

		if ( '' === $value || null === $value || false === $value ) {
			delete_post_meta( $batch_id, $key );

			return true;
		}

		return false !== update_post_meta( $batch_id, $key, $value );
	}

	public static function is_batch( int $batch_id ): bool {
		return $batch_id > 0 && self::POST_TYPE === get_post_type( $batch_id );
	}

	public static function style_term_id( int $batch_id ): int {
		return self::first_term_id( $batch_id, self::STYLE_TAX );
	}

	public static function style_name( int $batch_id ): string {
		return self::first_term_name( $batch_id, self::STYLE_TAX );
	}

	public static function vessel_term_id( int $batch_id ): int {
		return self::first_term_id( $batch_id, self::VESSEL_TAX );
	}

	public static function vessel_name( int $batch_id ): string {
		return self::first_term_name( $batch_id, self::VESSEL_TAX );
	}

	/**
	 * Create a batch.
	 *
	 * Accepted keys: name (required), notes, style (term id or slug), vessel
	 * (name), vessel_type, vessel_capacity_l, volume ("1 gal", or a number
	 * with volume_unit beside it), and every field in fields(). Anything that
	 * fails validation is refused with the value quoted; nothing is written.
	 *
	 * @param  array<string, mixed> $data
	 * @return int|WP_Error
	 */
	public static function create( array $data ): int|WP_Error {
		$clean = self::validate( $data, true );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$batch_id = wp_insert_post( [
			'post_type'    => self::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => $clean['name'],
			'post_content' => $clean['notes'] ?? '',
		], true );

		if ( is_wp_error( $batch_id ) ) {
			return $batch_id;
		}

		// wp_insert_post() can also answer 0 without a WP_Error. That is not a
		// batch and must not be reported as one.
		if ( (int) $batch_id <= 0 ) {
			return new WP_Error( 'bfbt_insert_failed', __( 'The batch could not be saved.', 'bluefairy-brew-tracker' ) );
		}

		$applied = self::apply( (int) $batch_id, $clean );

		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		do_action( 'bfbt_batch_created', (int) $batch_id, $clean );

		return (int) $batch_id;
	}

	/**
	 * @param  array<string, mixed> $data Only the keys present are changed.
	 * @return true|WP_Error
	 */
	public static function update( int $batch_id, array $data ): true|WP_Error {
		if ( ! self::is_batch( $batch_id ) ) {
			return new WP_Error( 'bfbt_no_batch', __( 'That batch no longer exists.', 'bluefairy-brew-tracker' ) );
		}

		$clean = self::validate( $data, false );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$post = [];

		if ( array_key_exists( 'name', $clean ) ) {
			$post['post_title'] = $clean['name'];
		}

		if ( array_key_exists( 'notes', $clean ) ) {
			$post['post_content'] = $clean['notes'];
		}

		if ( $post ) {
			$result = wp_update_post( $post + [ 'ID' => $batch_id ], true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( (int) $result <= 0 ) {
				return new WP_Error( 'bfbt_update_failed', __( 'The batch could not be saved.', 'bluefairy-brew-tracker' ) );
			}
		}

		$applied = self::apply( $batch_id, $clean );

		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		do_action( 'bfbt_batch_updated', $batch_id, $clean );

		return true;
	}

	/**
	 * Validate every supplied key and return the normalised set.
	 *
	 * @param  array<string, mixed> $data
	 * @return array<string, mixed>|WP_Error
	 */
	private static function validate( array $data, bool $creating ): array|WP_Error {
		$clean = [];

		if ( $creating || array_key_exists( 'name', $data ) ) {
			$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );

			if ( '' === $name ) {
				return new WP_Error( 'bfbt_name', __( 'A batch name is required.', 'bluefairy-brew-tracker' ) );
			}

			$clean['name'] = $name;
		}

		if ( array_key_exists( 'notes', $data ) ) {
			$clean['notes'] = wp_kses_post( (string) $data['notes'] );
		}

		foreach ( [ 'started', 'secondary_date', 'packaged_date', 'completed_date' ] as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$value = BFBT_Validate::date( (string) $data[ $field ], true );

				if ( is_wp_error( $value ) ) {
					return $value;
				}

				$clean[ $field ] = $value;
			}
		}

		foreach ( [ 'target_og', 'target_fg' ] as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$value = BFBT_Validate::gravity( (string) $data[ $field ], true );

				if ( is_wp_error( $value ) ) {
					return $value;
				}

				$clean[ $field ] = $value;
			}
		}

		if ( array_key_exists( 'volume', $data ) ) {
			$volume = self::parse_volume( $data );

			if ( is_wp_error( $volume ) ) {
				return $volume;
			}

			[ $clean['volume_l'], $clean['volume_entered'] ] = $volume;
		}

		if ( array_key_exists( 'dumped', $data ) ) {
			$clean['dumped'] = ! empty( $data['dumped'] ) && 'false' !== $data['dumped'] ? '1' : '';
		}

		if ( array_key_exists( 'og_log_id', $data ) ) {
			$log_id = absint( $data['og_log_id'] );

			if ( $log_id > 0 && self::LOG_TYPE !== get_post_type( $log_id ) ) {
				return new WP_Error(
					'bfbt_og_log',
					sprintf(
						/* translators: %s: the entry id given */
						__( '“%s” is not a log entry, so it cannot be pinned as the original gravity.', 'bluefairy-brew-tracker' ),
						(string) $data['og_log_id']
					)
				);
			}

			$clean['og_log_id'] = $log_id > 0 ? (string) $log_id : '';
		}

		if ( array_key_exists( 'style', $data ) ) {
			$style = self::resolve_style( $data['style'] );

			if ( is_wp_error( $style ) ) {
				return $style;
			}

			$clean['style'] = $style;
		}

		if ( array_key_exists( 'vessel', $data ) ) {
			$clean['vessel'] = sanitize_text_field( (string) $data['vessel'] );
		}

		if ( array_key_exists( 'vessel_type', $data ) ) {
			$type = strtolower( sanitize_text_field( (string) $data['vessel_type'] ) );

			if ( '' !== $type && ! in_array( $type, self::VESSEL_TYPES, true ) ) {
				return new WP_Error(
					'bfbt_vessel_type',
					sprintf(
						/* translators: 1: the type given, 2: the accepted list */
						__( '“%1$s” is not a vessel type this plugin knows. Use one of: %2$s.', 'bluefairy-brew-tracker' ),
						(string) $data['vessel_type'],
						implode( ', ', self::VESSEL_TYPES )
					)
				);
			}

			$clean['vessel_type'] = $type;
		}

		if ( array_key_exists( 'vessel_capacity_l', $data ) ) {
			$capacity = BFBT_Validate::quantity( (string) $data['vessel_capacity_l'], true );

			if ( is_wp_error( $capacity ) ) {
				return $capacity;
			}

			$clean['vessel_capacity_l'] = $capacity;
		}

		// Add-on fields are stored as text; the add-on validates its own.
		foreach ( self::$extra_fields as $field => $key ) {
			if ( array_key_exists( $field, $data ) ) {
				$clean[ $field ] = sanitize_text_field( (string) $data[ $field ] );
			}
		}

		return $clean;
	}

	/**
	 * "1 gal", or volume + volume_unit, to [litres, "1 gal"].
	 *
	 * @param  array<string, mixed> $data
	 * @return array{0: string, 1: string}|WP_Error
	 */
	private static function parse_volume( array $data ): array|WP_Error {
		$typed = trim( (string) $data['volume'] );

		if ( '' === $typed ) {
			return [ '', '' ];
		}

		if ( array_key_exists( 'volume_unit', $data ) ) {
			$quantity = $typed;
			$unit     = trim( (string) $data['volume_unit'] );
		} elseif ( 1 === preg_match( '/^(\S+)\s+(\S+)$/', $typed, $m ) ) {
			$quantity = $m[1];
			$unit     = $m[2];
		} else {
			return new WP_Error(
				'bfbt_volume_format',
				sprintf(
					/* translators: %s: the volume the user typed */
					__( '“%s” is not a volume this plugin can read. Enter a number and a unit, for example 1 gal.', 'bluefairy-brew-tracker' ),
					$typed
				)
			);
		}

		$litres = BFBT_Validate::volume( $quantity, $unit );

		if ( is_wp_error( $litres ) ) {
			return $litres;
		}

		return [ number_format( $litres, 3, '.', '' ), $quantity . ' ' . strtolower( $unit ) ];
	}

	/**
	 * A style term id or slug to a term id. '' clears the style.
	 *
	 * @return int|''|WP_Error
	 */
	private static function resolve_style( mixed $style ): int|string|WP_Error {
		$style = trim( (string) $style );

		if ( '' === $style ) {
			return '';
		}

		$term = ctype_digit( $style )
			? get_term_by( 'id', (int) $style, self::STYLE_TAX )
			: get_term_by( 'slug', $style, self::STYLE_TAX );

		if ( ! $term ) {
			return new WP_Error(
				'bfbt_style',
				sprintf(
					/* translators: %s: the style given */
					__( '“%s” is not a style this plugin knows.', 'bluefairy-brew-tracker' ),
					$style
				)
			);
		}

		return (int) $term->term_id;
	}

	/**
	 * Write the validated set. Empty values delete the key, so "absent" and
	 * "empty" are one state and dumped is `1` or nothing.
	 *
	 * @param  array<string, mixed> $clean
	 * @return true|WP_Error
	 */
	private static function apply( int $batch_id, array $clean ): true|WP_Error {
		foreach ( self::fields() as $field => $key ) {
			if ( ! array_key_exists( $field, $clean ) ) {
				continue;
			}

			if ( '' === $clean[ $field ] ) {
				delete_post_meta( $batch_id, $key );
			} else {
				update_post_meta( $batch_id, $key, $clean[ $field ] );
			}
		}

		if ( array_key_exists( 'style', $clean ) ) {
			$result = wp_set_object_terms( $batch_id, '' === $clean['style'] ? [] : [ $clean['style'] ], self::STYLE_TAX, false );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( array_key_exists( 'vessel', $clean ) ) {
			$result = wp_set_object_terms( $batch_id, '' === $clean['vessel'] ? [] : [ $clean['vessel'] ], self::VESSEL_TAX, false );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$vessel_id = self::vessel_term_id( $batch_id );

		if ( $vessel_id > 0 && array_key_exists( 'vessel_type', $clean ) ) {
			self::set_term_meta( $vessel_id, self::VESSEL_TYPE_META, $clean['vessel_type'] );
		}

		if ( $vessel_id > 0 && array_key_exists( 'vessel_capacity_l', $clean ) ) {
			self::set_term_meta( $vessel_id, self::VESSEL_CAPACITY_META, $clean['vessel_capacity_l'] );
		}

		return true;
	}

	private static function set_term_meta( int $term_id, string $key, string $value ): void {
		if ( '' === $value ) {
			delete_term_meta( $term_id, $key );
		} else {
			update_term_meta( $term_id, $key, $value );
		}
	}

	private static function first_term_id( int $batch_id, string $taxonomy ): int {
		if ( ! self::is_batch( $batch_id ) ) {
			return 0;
		}

		$terms = get_the_terms( $batch_id, $taxonomy );

		return ( is_array( $terms ) && $terms ) ? (int) $terms[0]->term_id : 0;
	}

	private static function first_term_name( int $batch_id, string $taxonomy ): string {
		if ( ! self::is_batch( $batch_id ) ) {
			return '';
		}

		$terms = get_the_terms( $batch_id, $taxonomy );

		return ( is_array( $terms ) && $terms ) ? (string) $terms[0]->name : '';
	}
}
