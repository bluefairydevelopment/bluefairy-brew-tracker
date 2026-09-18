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
