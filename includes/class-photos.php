<?php
/**
 * Photos on a log entry.
 *
 * A maker can attach up to MAX images when adding an entry. Each one becomes
 * an ordinary media-library attachment whose parent is the entry, so the
 * timeline can show it, the CSV can name it, and the media library can
 * manage it. Entries are immutable, so photos are too: there is no way to
 * add one to, or remove one from, an entry that exists — trash the entry and
 * add it again, as with any other field.
 *
 * Deleting an entry permanently deletes its photos with it (core would
 * merely orphan them). Trashing an entry leaves them where they are, so a
 * restore brings them back. Uninstall keeps every photo, as it keeps every
 * entry: they are the maker's records.
 *
 * Nothing here is admin-only — the Pro front-end tap-to-log form uses the
 * same split() / attach() pair — so the wp-admin upload helpers are loaded
 * where they are needed rather than assumed.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class BFBT_Photos {

	/** Photos accepted on one entry. */
	public const MAX = 5;

	/** The file-input name (posted as photos[]). */
	public const FIELD = 'photos';

	public static function init(): void {
		add_action( 'before_delete_post', [ __CLASS__, 'on_before_delete' ] );
	}

	/**
	 * Turn PHP's per-key layout of a multiple file input —
	 * ['name' => [a, b], 'tmp_name' => [x, y], …] — into one array per file,
	 * dropping the slots the browser sent empty (UPLOAD_ERR_NO_FILE). A
	 * single-file layout is returned as a one-item list. Anything that is
	 * not an upload array is an empty list.
	 *
	 * @param  mixed $files Usually $_FILES[ self::FIELD ].
	 * @return array<int, array{name: string, type: string, tmp_name: string, error: int, size: int}>
	 */
	public static function split( mixed $files ): array {
		if ( ! is_array( $files ) || ! isset( $files['name'] ) ) {
			return [];
		}

		$names = is_array( $files['name'] ) ? $files['name'] : [ $files['name'] ];
		$list  = [];

		foreach ( array_keys( $names ) as $i ) {
			$pick = static function ( string $key, mixed $default ) use ( $files, $i ): mixed {
				$value = $files[ $key ] ?? $default;

				return is_array( $value ) ? ( $value[ $i ] ?? $default ) : $value;
			};

			$error = (int) $pick( 'error', UPLOAD_ERR_NO_FILE );

			if ( UPLOAD_ERR_NO_FILE === $error ) {
				continue;
			}

			$list[] = [
				'name'     => (string) $pick( 'name', '' ),
				'type'     => (string) $pick( 'type', '' ),
				'tmp_name' => (string) $pick( 'tmp_name', '' ),
				'error'    => $error,
				'size'     => (int) $pick( 'size', 0 ),
			];
		}

		return $list;
	}

	/**
	 * A WP_Error when more than MAX files were sent; null otherwise. Checked
	 * before the entry is written, so an over-long set refuses the whole
	 * save and nothing is stored.
	 *
	 * @param array<int, array<string, mixed>> $list From split().
	 */
	public static function too_many( array $list ): ?WP_Error {
		$count = count( $list );

		if ( $count <= self::MAX ) {
			return null;
		}

		return new WP_Error(
			'bfbt_too_many_photos',
			sprintf(
				/* translators: 1: the most photos an entry takes, 2: how many were attached */
				__( 'An entry takes up to %1$d photos; %2$d were attached. Nothing was saved.', 'bluefairy-brew-tracker' ),
				self::MAX,
				$count
			)
		);
	}

	/**
	 * The image types an entry accepts: core's image mime map, nothing else.
	 * Passed to the upload as an override, so a renamed executable is
	 * refused by extension AND by sniffed content.
	 *
	 * @return array<string, string> extension pattern → mime type
	 */
	public static function mimes(): array {
		$images = [];

		foreach ( wp_get_mime_types() as $pattern => $mime ) {
			if ( str_starts_with( (string) $mime, 'image/' ) ) {
				$images[ $pattern ] = $mime;
			}
		}

		return $images;
	}

	/**
	 * Store each uploaded file as an attachment of the entry.
	 *
	 * Runs after the entry is written: a photo that fails leaves the entry
	 * and the other photos in place and is reported by name, because a
	 * reading typed in is worth more than a picture of it. Files are sent
	 * to media_handle_upload() one at a time under a scratch key, since it
	 * reads a single-file layout from $_FILES.
	 *
	 * @param  array<int, array<string, mixed>> $list From split().
	 * @return array{ids: int[], errors: string[]}
	 */
	public static function attach( int $log_id, array $list ): array {
		$ids    = [];
		$errors = [];

		if ( ! $list ) {
			return [ 'ids' => $ids, 'errors' => $errors ];
		}

		if ( ! function_exists( 'media_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		foreach ( $list as $file ) {
			$name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );

			if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
				$errors[] = self::describe( $name, self::upload_error_text( (int) $file['error'] ) );

				continue;
			}

			$_FILES['bfbt_photo'] = $file; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller verified the nonce.

			$result = media_handle_upload( 'bfbt_photo', $log_id, [], [ 'test_form' => false, 'mimes' => self::mimes() ] );

			unset( $_FILES['bfbt_photo'] );

			if ( is_wp_error( $result ) ) {
				$errors[] = self::describe( $name, $result->get_error_message() );

				continue;
			}

			$ids[] = (int) $result;
		}

		return [ 'ids' => $ids, 'errors' => $errors ];
	}

	/**
	 * Attachment ids of an entry's photos, oldest first.
	 *
	 * @return int[]
	 */
	public static function for_log( int $log_id ): array {
		if ( $log_id <= 0 ) {
			return [];
		}

		$query = new WP_Query( [
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_parent'            => $log_id,
			'posts_per_page'         => self::MAX * 4,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		] );

		return array_map( 'intval', $query->posts );
	}

	/**
	 * The full-size URLs of an entry's photos, oldest first.
	 *
	 * @return string[]
	 */
	public static function urls( int $log_id ): array {
		$urls = [];

		foreach ( self::for_log( $log_id ) as $id ) {
			$url = wp_get_attachment_url( $id );

			if ( is_string( $url ) && '' !== $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Delete an entry's photos when the entry itself is deleted permanently.
	 * Core reparents a deleted post's attachments to its parent; a photo
	 * of a reading that no longer exists is not worth keeping. Only entries:
	 * a batch's own delete reaches its entries through the cascade, and
	 * each entry arrives here in turn.
	 */
	public static function on_before_delete( int $post_id ): void {
		if ( BFBT_Batch::LOG_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		foreach ( self::for_log( $post_id ) as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * "“x.jpg”: reason" for a notice.
	 */
	private static function describe( string $name, string $reason ): string {
		return sprintf(
			/* translators: 1: file name, 2: why it was not saved */
			__( '“%1$s”: %2$s', 'bluefairy-brew-tracker' ),
			'' === $name ? __( '(unnamed file)', 'bluefairy-brew-tracker' ) : $name,
			$reason
		);
	}

	/**
	 * PHP's upload error codes in plain words. The two size codes are
	 * merged: to the maker, both mean "too big".
	 */
	public static function upload_error_text( int $code ): string {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return sprintf(
					/* translators: %s: the server's upload limit, e.g. 8 MB */
					__( 'the file is larger than this site allows (%s).', 'bluefairy-brew-tracker' ),
					size_format( wp_max_upload_size() )
				);
			case UPLOAD_ERR_PARTIAL:
				return __( 'only part of the file arrived; try again.', 'bluefairy-brew-tracker' );
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
			case UPLOAD_ERR_EXTENSION:
				return __( 'the server could not store the upload.', 'bluefairy-brew-tracker' );
			default:
				return __( 'the upload failed.', 'bluefairy-brew-tracker' );
		}
	}
}
