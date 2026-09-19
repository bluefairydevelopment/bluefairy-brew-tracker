<?php
/**
 * Just enough WordPress for the logic under test.
 *
 * Posts, meta, terms, term meta and options are plain arrays in $GLOBALS so a
 * test can plant state and read back what the code wrote. Every fake starts
 * with bfbt_ so a test's setUp() can reset exactly these.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );

function bfbt_reset_stubs(): void {
	$GLOBALS['bfbt_meta']         = [];
	$GLOBALS['bfbt_types']        = [];
	$GLOBALS['bfbt_posts']        = [];
	$GLOBALS['bfbt_filters']      = [];
	$GLOBALS['bfbt_options']      = [];
	$GLOBALS['bfbt_actions']      = [];
	$GLOBALS['bfbt_terms']        = [];
	$GLOBALS['bfbt_object_terms'] = [];
	$GLOBALS['bfbt_term_meta']    = [];
	$GLOBALS['bfbt_next_id']      = 100;
	$GLOBALS['bfbt_insert_fails'] = false;
	$GLOBALS['bfbt_transients']   = [];
	$GLOBALS['bfbt_user_id']      = 1;
}

bfbt_reset_stubs();

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @var array<string, string[]> */
		private array $errors = [];

		public function __construct( string $code = '', string $message = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;
			}
		}

		public function get_error_code(): string {
			return (string) ( array_key_first( $this->errors ) ?? '' );
		}

		public function get_error_message(): string {
			$code = $this->get_error_code();

			return '' === $code ? '' : $this->errors[ $code ][0];
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) { return $text; }
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $text ) { return (string) $text; }
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) { return abs( (int) $value ); }
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $cb, $priority = 10, $args = 1 ) { return true; }
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag, ...$args ) { $GLOBALS['bfbt_actions'][] = [ $tag, $args ]; }
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		$cb = $GLOBALS['bfbt_filters'][ $tag ] ?? null;

		return $cb ? $cb( $value, ...$args ) : $value;
	}
}

if ( ! function_exists( 'register_post_type' ) ) {
	function register_post_type( $type, $args = [] ) { return null; }
}

if ( ! function_exists( 'register_taxonomy' ) ) {
	function register_taxonomy( $taxonomy, $object_type, $args = [] ) { return null; }
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $id ) { return $GLOBALS['bfbt_types'][ $id ] ?? false; }
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['bfbt_meta'][ $id ][ $key ] ?? ''; }
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) {
		$GLOBALS['bfbt_meta'][ $id ][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $id, $key ) {
		unset( $GLOBALS['bfbt_meta'][ $id ][ $key ] );

		return true;
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $postarr, $wp_error = false ) {
		if ( $GLOBALS['bfbt_insert_fails'] ) {
			return $wp_error ? new WP_Error( 'db_insert_error', 'Could not insert post into the database.' ) : 0;
		}

		$id = $GLOBALS['bfbt_next_id']++;

		// Core turns a published post dated a minute or more ahead into "future".
		if ( 'publish' === ( $postarr['post_status'] ?? 'publish' ) && isset( $postarr['post_date'] )
			&& strtotime( $postarr['post_date'] ) - strtotime( $GLOBALS['bfbt_now'] ?? 'now' ) >= 60 ) {
			$postarr['post_status'] = 'future';
		}

		$GLOBALS['bfbt_posts'][ $id ] = $postarr;
		$GLOBALS['bfbt_types'][ $id ] = $postarr['post_type'] ?? 'post';

		return $id;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $postarr, $wp_error = false ) {
		$id = (int) ( $postarr['ID'] ?? 0 );

		if ( ! isset( $GLOBALS['bfbt_posts'][ $id ] ) ) {
			return $wp_error ? new WP_Error( 'invalid_post', 'Invalid post ID.' ) : 0;
		}

		$GLOBALS['bfbt_posts'][ $id ] = $postarr + $GLOBALS['bfbt_posts'][ $id ];

		return $id;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) { return $GLOBALS['bfbt_options'][ $name ] ?? $default; }
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value ) {
		$GLOBALS['bfbt_options'][ $name ] = $value;

		return true;
	}
}

/**
 * Terms live in $GLOBALS['bfbt_terms'][ $taxonomy ][ $term_id ] as objects
 * with term_id, name, slug, parent.
 */
if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy ) {
		foreach ( $GLOBALS['bfbt_terms'][ $taxonomy ] ?? [] as $term ) {
			if ( 'id' === $field && (int) $term->term_id === (int) $value ) {
				return $term;
			}

			if ( in_array( $field, [ 'slug', 'name' ], true ) && $term->{$field} === (string) $value ) {
				return $term;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'wp_insert_term' ) ) {
	function wp_insert_term( $name, $taxonomy, $args = [] ) {
		$slug = $args['slug'] ?? strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $name ) );

		if ( get_term_by( 'slug', $slug, $taxonomy ) ) {
			return new WP_Error( 'term_exists', 'A term with the name provided already exists.' );
		}

		$id = $GLOBALS['bfbt_next_id']++;

		$GLOBALS['bfbt_terms'][ $taxonomy ][ $id ] = (object) [
			'term_id' => $id,
			'name'    => $name,
			'slug'    => $slug,
			'parent'  => (int) ( $args['parent'] ?? 0 ),
		];

		return [ 'term_id' => $id, 'term_taxonomy_id' => $id ];
	}
}

/**
 * Non-hierarchical taxonomies accept names and create them; the style
 * taxonomy is set by id.
 */
if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
		$ids = [];

		foreach ( (array) $terms as $term ) {
			if ( is_int( $term ) ) {
				$found = get_term_by( 'id', $term, $taxonomy );
			} else {
				$found = get_term_by( 'name', $term, $taxonomy );

				if ( ! $found ) {
					$inserted = wp_insert_term( $term, $taxonomy );
					$found    = get_term_by( 'id', $inserted['term_id'], $taxonomy );
				}
			}

			if ( ! $found ) {
				return new WP_Error( 'invalid_term', 'Empty Term.' );
			}

			$ids[] = (int) $found->term_id;
		}

		$GLOBALS['bfbt_object_terms'][ $object_id ][ $taxonomy ] = $ids;

		return $ids;
	}
}

if ( ! function_exists( 'get_the_terms' ) ) {
	function get_the_terms( $id, $taxonomy ) {
		$ids = $GLOBALS['bfbt_object_terms'][ $id ][ $taxonomy ] ?? [];

		if ( ! $ids ) {
			return false;
		}

		return array_values( array_filter( array_map(
			static fn( int $term_id ) => get_term_by( 'id', $term_id, $taxonomy ) ?: null,
			$ids
		) ) );
	}
}

if ( ! function_exists( 'add_term_meta' ) ) {
	function add_term_meta( $term_id, $key, $value, $unique = false ) {
		if ( $unique && isset( $GLOBALS['bfbt_term_meta'][ $term_id ][ $key ] ) ) {
			return false;
		}

		$GLOBALS['bfbt_term_meta'][ $term_id ][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'update_term_meta' ) ) {
	function update_term_meta( $term_id, $key, $value ) {
		$GLOBALS['bfbt_term_meta'][ $term_id ][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_term_meta' ) ) {
	function delete_term_meta( $term_id, $key ) {
		unset( $GLOBALS['bfbt_term_meta'][ $term_id ][ $key ] );

		return true;
	}
}

if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $term_id, $key, $single = false ) { return $GLOBALS['bfbt_term_meta'][ $term_id ][ $key ] ?? ''; }
}

/*
 * ---------------------------------------------------------------------------
 * Phase 3: posts as records, a WP_Query fake, trash / untrash / delete, time.
 * ---------------------------------------------------------------------------
 */

defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

/** A fixed clock for the suite: 2026-09-18 12:00 site-local. */
$GLOBALS['bfbt_now'] = '2026-09-18 12:00:00';

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		$ts = strtotime( $GLOBALS['bfbt_now'] );

		return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s', $ts ) : ( 'timestamp' === $type ? $ts : gmdate( $type, $ts ) );
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null ) {
		return gmdate( $format, $timestamp ?? strtotime( $GLOBALS['bfbt_now'] ) );
	}
}

if ( ! function_exists( 'get_gmt_from_date' ) ) {
	function get_gmt_from_date( $date, $format = 'Y-m-d H:i:s' ) { return $date; }
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $cb, $priority = 10, $args = 1 ) { return true; }
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = null ) { return esc_html( $text ); }
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = null ) { return esc_attr( $text ); }
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) { return trim( strip_tags( (string) $text ) ); }
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $id ) { return (string) ( $GLOBALS['bfbt_posts'][ (int) $id ]['post_title'] ?? '' ); }
}

/**
 * A post record as an object, the shape WP_Query and get_post() hand out.
 */
function bfbt_post_object( int $id ): ?object {
	$row = $GLOBALS['bfbt_posts'][ $id ] ?? null;

	if ( null === $row ) {
		return null;
	}

	return (object) ( [ 'ID' => $id ] + $row + [
		'post_status'  => 'publish',
		'post_parent'  => 0,
		'post_title'   => '',
		'post_content' => '',
		'post_date'    => $GLOBALS['bfbt_now'],
	] );
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) { return bfbt_post_object( (int) $id ); }
}

if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $id ) { return $GLOBALS['bfbt_posts'][ (int) $id ]['post_status'] ?? false; }
	function get_post_stati() { return [ 'publish' => 'publish', 'future' => 'future', 'draft' => 'draft', 'pending' => 'pending', 'private' => 'private', 'trash' => 'trash', 'auto-draft' => 'auto-draft', 'inherit' => 'inherit' ]; }
}

if ( ! function_exists( 'wp_trash_post' ) ) {
	function wp_trash_post( $id ) {
		$id = (int) $id;

		if ( ! isset( $GLOBALS['bfbt_posts'][ $id ] ) || 'trash' === ( $GLOBALS['bfbt_posts'][ $id ]['post_status'] ?? 'publish' ) ) {
			return false;
		}

		$GLOBALS['bfbt_posts'][ $id ]['post_status'] = 'trash';

		return bfbt_post_object( $id );
	}
}

if ( ! function_exists( 'wp_untrash_post' ) ) {
	function wp_untrash_post( $id ) {
		$id = (int) $id;

		if ( 'trash' !== ( $GLOBALS['bfbt_posts'][ $id ]['post_status'] ?? '' ) ) {
			return false;
		}

		// Core restores through wp_update_post(), which future-dates again.
		$date = $GLOBALS['bfbt_posts'][ $id ]['post_date'] ?? $GLOBALS['bfbt_now'];

		$GLOBALS['bfbt_posts'][ $id ]['post_status'] = strtotime( $date ) - strtotime( $GLOBALS['bfbt_now'] ) >= 60 ? 'future' : 'publish';

		return bfbt_post_object( $id );
	}
}

if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( $id, $force = false ) {
		$id   = (int) $id;
		$post = bfbt_post_object( $id );

		unset( $GLOBALS['bfbt_posts'][ $id ], $GLOBALS['bfbt_types'][ $id ], $GLOBALS['bfbt_meta'][ $id ] );

		return $post ?: false;
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Enough of WP_Query for post_type / post_status / post_parent /
	 * meta_key+meta_value / meta_query (=, !=, >=, <=, EXISTS, NOT EXISTS,
	 * nested relation) / orderby (date, ID, named meta clause) / paging /
	 * fields => ids.
	 */
	class WP_Query {
		/** @var array<int, object|int> */
		public array $posts = [];
		public int $found_posts = 0;
		public int $max_num_pages = 0;

		public function __construct( array $args = [] ) {
			$types  = (array) ( $args['post_type'] ?? 'post' );
			$status = $args['post_status'] ?? 'publish';
			$rows   = [];

			foreach ( array_keys( $GLOBALS['bfbt_posts'] ) as $id ) {
				$post = bfbt_post_object( (int) $id );

				if ( ! in_array( $post->post_type ?? 'post', $types, true ) ) {
					continue;
				}

				// Core's 'any' is "every status not excluded from search",
				// which leaves out trash and auto-draft. The stub used to
				// read it as "everything", and that hid a cascade that never
				// found the children of a batch deleted from the trash.
				$wanted = 'any' === $status ? [ 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ] : (array) $status;

				if ( ! in_array( $post->post_status, $wanted, true ) ) {
					continue;
				}

				if ( isset( $args['post_parent'] ) && (int) $post->post_parent !== (int) $args['post_parent'] ) {
					continue;
				}

				if ( isset( $args['post_parent__in'] ) && ! in_array( (int) $post->post_parent, array_map( 'intval', $args['post_parent__in'] ), true ) ) {
					continue;
				}

				if ( isset( $args['meta_key'] ) && ( $GLOBALS['bfbt_meta'][ $id ][ $args['meta_key'] ] ?? '' ) !== ( $args['meta_value'] ?? '' ) ) {
					continue;
				}

				if ( isset( $args['meta_query'] ) && ! self::meta_matches( (int) $id, $args['meta_query'] ) ) {
					continue;
				}

				$rows[] = $post;
			}

			$orderby = $args['orderby'] ?? [ 'date' => 'DESC' ];

			if ( is_string( $orderby ) ) {
				$orderby = [ $orderby => $args['order'] ?? 'DESC' ];
			}

			$clauses = $args['meta_query'] ?? [];

			usort( $rows, static function ( object $a, object $b ) use ( $orderby, $clauses ): int {
				foreach ( $orderby as $field => $dir ) {
					if ( 'date' === $field ) {
						$cmp = strcmp( $a->post_date, $b->post_date );
					} elseif ( 'ID' === $field ) {
						$cmp = $a->ID <=> $b->ID;
					} elseif ( isset( $clauses[ $field ]['key'] ) ) {
						$key = $clauses[ $field ]['key'];
						$cmp = strcmp( (string) ( $GLOBALS['bfbt_meta'][ $a->ID ][ $key ] ?? '' ), (string) ( $GLOBALS['bfbt_meta'][ $b->ID ][ $key ] ?? '' ) );
					} else {
						$cmp = 0;
					}

					if ( 0 !== $cmp ) {
						return 'ASC' === strtoupper( (string) $dir ) ? $cmp : -$cmp;
					}
				}

				return 0;
			} );

			$per_page = (int) ( $args['posts_per_page'] ?? 10 );
			$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );

			$this->found_posts   = count( $rows );
			$this->max_num_pages = $per_page > 0 ? (int) ceil( $this->found_posts / $per_page ) : 1;

			$page = $per_page > 0 ? array_slice( $rows, ( $paged - 1 ) * $per_page, $per_page ) : $rows;

			$this->posts = 'ids' === ( $args['fields'] ?? '' )
				? array_map( static fn( object $p ): int => (int) $p->ID, $page )
				: $page;
		}

		/**
		 * @param array<mixed> $query
		 */
		private static function meta_matches( int $id, array $query ): bool {
			$relation = strtoupper( (string) ( $query['relation'] ?? 'AND' ) );
			$results  = [];

			foreach ( $query as $key => $clause ) {
				if ( 'relation' === $key || ! is_array( $clause ) ) {
					continue;
				}

				if ( ! isset( $clause['key'] ) ) {
					$results[] = self::meta_matches( $id, $clause );
					continue;
				}

				$exists  = array_key_exists( $clause['key'], $GLOBALS['bfbt_meta'][ $id ] ?? [] );
				$value   = (string) ( $GLOBALS['bfbt_meta'][ $id ][ $clause['key'] ] ?? '' );
				$compare = strtoupper( (string) ( $clause['compare'] ?? '=' ) );
				$wanted  = (string) ( $clause['value'] ?? '' );

				$results[] = match ( $compare ) {
					'EXISTS'     => $exists,
					'NOT EXISTS' => ! $exists,
					'!='         => $exists && $value !== $wanted,
					'>='         => $exists && strcmp( $value, $wanted ) >= 0,
					'<='         => $exists && strcmp( $value, $wanted ) <= 0,
					default      => $exists && $value === $wanted,
				};
			}

			if ( ! $results ) {
				return true;
			}

			return 'OR' === $relation ? in_array( true, $results, true ) : ! in_array( false, $results, true );
		}
	}
}

if ( ! function_exists( 'clean_post_cache' ) ) {
	function clean_post_cache( $id ) {}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook, $args = [] ) { $GLOBALS['bfbt_unscheduled'][] = [ $hook, $args ]; return 0; }
}

/**
 * Just $wpdb->update() against the post store; the grouped SELECTs are
 * covered by their pure pick_*() halves and by the live harness.
 */
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new class {
		public string $posts    = 'wp_posts';
		public string $postmeta = 'wp_postmeta';

		public function update( string $table, array $data, array $where ): int {
			$id = (int) ( $where['ID'] ?? 0 );

			if ( 'wp_posts' !== $table || ! isset( $GLOBALS['bfbt_posts'][ $id ] ) ) {
				return 0;
			}

			$GLOBALS['bfbt_posts'][ $id ] = $data + $GLOBALS['bfbt_posts'][ $id ];

			return 1;
		}
	};
}

/*
 * ---------------------------------------------------------------------------
 * Transients and the current user, for the remembered-input store.
 * ---------------------------------------------------------------------------
 */

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['bfbt_user_id'] ?? 0 ); }
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $name, $value, $expiration = 0 ) {
		$GLOBALS['bfbt_transients'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $name ) { return $GLOBALS['bfbt_transients'][ $name ] ?? false; }
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $name ) {
		$existed = isset( $GLOBALS['bfbt_transients'][ $name ] );
		unset( $GLOBALS['bfbt_transients'][ $name ] );

		return $existed;
	}
}
