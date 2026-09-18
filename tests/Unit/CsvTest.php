<?php
/**
 * CSV defusing and quoting.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CsvTest extends TestCase {

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function dangerous(): array {
		return [
			'equals' => [ '=HYPERLINK("x")', "'=HYPERLINK(\"x\")" ],
			'plus'   => [ '+1', "'+1" ],
			'minus'  => [ '-1', "'-1" ],
			'at'     => [ '@SUM(A1)', "'@SUM(A1)" ],
			'tab'    => [ "\tx", "'\tx" ],
			'cr'     => [ "\rx", "'\rx" ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'dangerous' )]
	public function test_defuse_prefixes_a_quote( string $in, string $out ): void {
		$this->assertSame( $out, BFBT_CSV::defuse( $in ) );
	}

	public function test_defuse_leaves_ordinary_values_alone(): void {
		$this->assertSame( 'Traditional Mead #1', BFBT_CSV::defuse( 'Traditional Mead #1' ) );
		$this->assertSame( '1.042', BFBT_CSV::defuse( '1.042' ) );
		$this->assertSame( '', BFBT_CSV::defuse( '' ) );
	}

	public function test_every_field_is_quoted_and_quotes_doubled(): void {
		$out = fopen( 'php://memory', 'w+' );
		BFBT_CSV::row( $out, [ 'a', '', 'say "hi"', '=1+1', '12' ] );
		rewind( $out );
		$line = stream_get_contents( $out );

		$this->assertSame( "\"a\",\"\",\"say \"\"hi\"\"\",\"'=1+1\",\"12\"\r\n", $line );
	}

	public function test_a_positive_control_is_written_unquoted_by_fputcsv_and_would_fail_here(): void {
		// fputcsv() leaves a bare number unquoted; our writer must not.
		$out = fopen( 'php://memory', 'w+' );
		fputcsv( $out, [ '12' ], ',', '"', '' );
		rewind( $out );

		$this->assertSame( "12\n", stream_get_contents( $out ) );
	}

	public function test_write_streams_both_sections_with_headers(): void {
		bfbt_reset_stubs();
		$batch = wp_insert_post( [ 'post_type' => 'bfbt_batch', 'post_title' => '=Evil Mead' ] );
		$GLOBALS['bfbt_meta'][ $batch ]['_bfbt_started'] = '2026-09-18';
		BFBT_Log::create( $batch, [ 'type' => 'gravity', 'sg' => '1.100', 'time' => '2026-09-18 10:00' ] );

		$out = fopen( 'php://memory', 'w+' );
		BFBT_CSV::write( $out );
		rewind( $out );
		$lines = explode( "\r\n", rtrim( stream_get_contents( $out ), "\r\n" ) );

		$this->assertStringStartsWith( '"batch_id","name","style","vessel","stage","started"', $lines[0] );
		$this->assertStringContainsString( "\"'=Evil Mead\"", $lines[1] );
		$this->assertStringContainsString( '"primary"', $lines[1] );
		$this->assertSame( '', $lines[2], 'a blank line separates the sections' );
		$this->assertStringStartsWith( '"log_id","batch_id","batch","time","type"', $lines[3] );
		$this->assertStringContainsString( '"gravity","SG 1.100"', $lines[4] );
		$this->assertStringContainsString( '"1.100"', $lines[4] );
		$this->assertCount( 5, $lines );
	}
}
