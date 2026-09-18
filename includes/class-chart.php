<?php
/**
 * An inline SVG line chart of specific gravity over time.
 *
 * No JavaScript library, no external asset. Every number is cast to float
 * before it reaches the markup and every piece of text goes through
 * esc_html on the raw string — never on kses output — so an entry title
 * cannot inject markup into the SVG.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class BFBT_Chart {

	private const WIDTH  = 640;
	private const HEIGHT = 240;
	private const PAD_L  = 56;
	private const PAD_R  = 16;
	private const PAD_T  = 16;
	private const PAD_B  = 36;

	/**
	 * @param array<int, array{date?: string, time?: string, sg: float|string, label?: string}> $series Oldest first.
	 */
	public static function svg( array $series ): string {
		$points = [];

		foreach ( $series as $point ) {
			if ( ! is_array( $point ) || ! isset( $point['sg'] ) ) {
				continue;
			}

			$time = (string) ( $point['time'] ?? $point['date'] ?? '' );
			$ts   = '' === $time ? false : strtotime( $time );

			if ( false === $ts ) {
				continue;
			}

			$points[] = [
				'ts'    => (float) $ts,
				'sg'    => (float) $point['sg'],
				'date'  => substr( $time, 0, 10 ),
				'label' => (string) ( $point['label'] ?? '' ),
			];
		}

		if ( ! $points ) {
			return '<p class="bfbt-chart-empty">' . esc_html__( 'No readings yet.', 'bluefairy-brew-tracker' ) . '</p>';
		}

		$ts_values = array_column( $points, 'ts' );
		$sg_values = array_column( $points, 'sg' );

		$ts_min = (float) min( $ts_values );
		$ts_max = (float) max( $ts_values );
		$sg_min = (float) min( $sg_values );
		$sg_max = (float) max( $sg_values );

		// Give a flat or single-point series some height and width to sit in.
		if ( $sg_max - $sg_min < 0.004 ) {
			$sg_min -= 0.002;
			$sg_max += 0.002;
		}

		if ( $ts_max - $ts_min < 1.0 ) {
			$ts_max = $ts_min + DAY_IN_SECONDS;
		}

		$plot_w = self::WIDTH - self::PAD_L - self::PAD_R;
		$plot_h = self::HEIGHT - self::PAD_T - self::PAD_B;

		$x = static fn( float $ts ): float => self::PAD_L + ( $ts - $ts_min ) / ( $ts_max - $ts_min ) * $plot_w;
		$y = static fn( float $sg ): float => self::PAD_T + ( $sg_max - $sg ) / ( $sg_max - $sg_min ) * $plot_h;

		$out = sprintf(
			'<svg class="bfbt-chart" viewBox="0 0 %d %d" role="img" aria-label="%s" xmlns="http://www.w3.org/2000/svg">',
			self::WIDTH,
			self::HEIGHT,
			esc_attr__( 'Specific gravity over time', 'bluefairy-brew-tracker' )
		);

		// Axes.
		$out .= sprintf(
			'<line class="bfbt-chart-axis" x1="%s" y1="%s" x2="%s" y2="%s"/><line class="bfbt-chart-axis" x1="%s" y1="%s" x2="%s" y2="%s"/>',
			self::f( (float) self::PAD_L ),
			self::f( (float) self::PAD_T ),
			self::f( (float) self::PAD_L ),
			self::f( (float) ( self::PAD_T + $plot_h ) ),
			self::f( (float) self::PAD_L ),
			self::f( (float) ( self::PAD_T + $plot_h ) ),
			self::f( (float) ( self::PAD_L + $plot_w ) ),
			self::f( (float) ( self::PAD_T + $plot_h ) )
		);

		// Y labels: top and bottom of the range.
		$out .= sprintf(
			'<text class="bfbt-chart-label" x="%s" y="%s" text-anchor="end">%s</text><text class="bfbt-chart-label" x="%s" y="%s" text-anchor="end">%s</text>',
			self::f( (float) ( self::PAD_L - 6 ) ),
			self::f( (float) ( self::PAD_T + 4 ) ),
			esc_html( number_format( $sg_max, 3, '.', '' ) ),
			self::f( (float) ( self::PAD_L - 6 ) ),
			self::f( (float) ( self::PAD_T + $plot_h + 4 ) ),
			esc_html( number_format( $sg_min, 3, '.', '' ) )
		);

		// X labels: first and last reading dates.
		$first = $points[0];
		$last  = $points[ count( $points ) - 1 ];

		$out .= sprintf(
			'<text class="bfbt-chart-label" x="%s" y="%s" text-anchor="start">%s</text>',
			self::f( (float) self::PAD_L ),
			self::f( (float) ( self::HEIGHT - 12 ) ),
			esc_html( $first['date'] )
		);

		if ( $last['date'] !== $first['date'] ) {
			$out .= sprintf(
				'<text class="bfbt-chart-label" x="%s" y="%s" text-anchor="end">%s</text>',
				self::f( (float) ( self::PAD_L + $plot_w ) ),
				self::f( (float) ( self::HEIGHT - 12 ) ),
				esc_html( $last['date'] )
			);
		}

		// The line.
		$coords = [];

		foreach ( $points as $point ) {
			$coords[] = self::f( $x( $point['ts'] ) ) . ',' . self::f( $y( $point['sg'] ) );
		}

		if ( count( $coords ) > 1 ) {
			$out .= '<polyline class="bfbt-chart-line" fill="none" points="' . esc_attr( implode( ' ', $coords ) ) . '"/>';
		}

		// The points, each with a hover title.
		foreach ( $points as $point ) {
			$out .= sprintf(
				'<circle class="bfbt-chart-point" cx="%s" cy="%s" r="4"><title>%s</title></circle>',
				self::f( $x( $point['ts'] ) ),
				self::f( $y( $point['sg'] ) ),
				esc_html( trim( number_format( $point['sg'], 3, '.', '' ) . ' — ' . $point['date'] . ( '' !== $point['label'] ? ' — ' . $point['label'] : '' ) ) )
			);
		}

		return $out . '</svg>';
	}

	private static function f( float $number ): string {
		return number_format( $number, 1, '.', '' );
	}
}
