<?php
/**
 * The CSV export: every batch, then every log entry.
 *
 * Streamed straight to the output in pages of 500 posts, so a big log never
 * has to fit in memory. One file, two sections: the batch rows under their
 * header, a blank line, then the log rows under theirs — a spreadsheet
 * opens it as one sheet and the batch id column ties the two together.
 *
 * Every field is quoted and any value that begins with = + - @, a tab or a
 * carriage return is prefixed with a single quote, so a batch named
 * "=HYPERLINK(...)" opens in a spreadsheet as text, not as a formula.
 * PHP's fputcsv() cannot be told to quote every field, so the row writer
 * is this class's own.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class BFBT_CSV {

	public const FILENAME = 'brew-tracker-export.csv';

	public const PAGE_SIZE = 500;

	public const BATCH_COLUMNS = [ 'batch_id', 'name', 'style', 'vessel', 'stage', 'started', 'volume_l', 'volume_entered', 'target_og', 'target_fg', 'secondary_date', 'packaged_date', 'completed_date', 'dumped', 'notes' ];

	public const LOG_COLUMNS = [ 'log_id', 'batch_id', 'batch', 'time', 'type', 'title', 'ingredient', 'qty', 'unit', 'sg', 'temp_c', 'temp_entered', 'ph', 'volume_out_l', 'note' ];

	/**
	 * Send the headers and the file, then stop.
	 */
	public static function stream(): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . self::FILENAME . '"' );

		$out = fopen( 'php://output', 'w' );

		if ( false === $out ) {
			wp_die( esc_html__( 'The export could not be started.', 'bluefairy-brew-tracker' ) );
		}

		self::write( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Write the whole export to an open stream.
	 *
	 * @param resource $out
	 */
	public static function write( $out ): void {
		self::row( $out, self::BATCH_COLUMNS );

		self::each_page( BFBT_Batch::POST_TYPE, static function ( object $post ) use ( $out ): void {
			$id = (int) $post->ID;

			self::row( $out, [
				(string) $id,
				(string) $post->post_title,
				BFBT_Batch::style_name( $id ),
				BFBT_Batch::vessel_name( $id ),
				BFBT_Stage::get( $id ),
				(string) BFBT_Batch::get( $id, 'started' ),
				(string) BFBT_Batch::get( $id, 'volume_l' ),
				(string) BFBT_Batch::get( $id, 'volume_entered' ),
				(string) BFBT_Batch::get( $id, 'target_og' ),
				(string) BFBT_Batch::get( $id, 'target_fg' ),
				(string) BFBT_Batch::get( $id, 'secondary_date' ),
				(string) BFBT_Batch::get( $id, 'packaged_date' ),
				(string) BFBT_Batch::get( $id, 'completed_date' ),
				'' !== (string) BFBT_Batch::get( $id, 'dumped' ) ? '1' : '',
				wp_strip_all_tags( (string) $post->post_content ),
			] );
		} );

		fwrite( $out, "\r\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		self::row( $out, self::LOG_COLUMNS );

		self::each_page( BFBT_Batch::LOG_TYPE, static function ( object $post ) use ( $out ): void {
			$id       = (int) $post->ID;
			$batch_id = (int) $post->post_parent;

			self::row( $out, [
				(string) $id,
				(string) $batch_id,
				(string) get_the_title( $batch_id ),
				(string) $post->post_date,
				BFBT_Log::field( $id, 'type' ),
				(string) $post->post_title,
				BFBT_Log::field( $id, 'ingredient' ),
				BFBT_Log::field( $id, 'qty' ),
				BFBT_Log::field( $id, 'unit' ),
				BFBT_Log::field( $id, 'sg' ),
				BFBT_Log::field( $id, 'temp_c' ),
				BFBT_Log::field( $id, 'temp_entered' ),
				BFBT_Log::field( $id, 'ph' ),
				BFBT_Log::field( $id, 'volume_out_l' ),
				wp_strip_all_tags( (string) $post->post_content ),
			] );
		} );
	}

	/**
	 * Walk every published post of a type, PAGE_SIZE at a time, oldest first.
	 *
	 * @param callable(object): void $callback
	 */
	private static function each_page( string $post_type, callable $callback ): void {
		$page = 1;

		do {
			$query = new WP_Query( [
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => self::PAGE_SIZE,
				'paged'                  => $page,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'update_post_term_cache' => BFBT_Batch::POST_TYPE === $post_type,
				'update_post_meta_cache' => true,
				'no_found_rows'          => true,
			] );

			foreach ( $query->posts as $post ) {
				$callback( $post );
			}

			++$page;
		} while ( count( $query->posts ) === self::PAGE_SIZE );
	}

	/**
	 * One row: every field defused, quoted and joined; CRLF line end.
	 *
	 * @param resource $out
	 * @param string[] $fields
	 */
	public static function row( $out, array $fields ): void {
		$quoted = array_map(
			static fn( string $field ): string => '"' . str_replace( '"', '""', self::defuse( $field ) ) . '"',
			array_map( 'strval', $fields )
		);

		fwrite( $out, implode( ',', $quoted ) . "\r\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}

	/**
	 * A value a spreadsheet would run as a formula gets a leading quote.
	 */
	public static function defuse( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		return in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ? "'" . $value : $value;
	}
}
