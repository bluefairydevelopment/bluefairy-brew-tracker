<?php
/**
 * A batch's stage, derived rather than stored.
 *
 * The free plugin knows six stages and decides them from the milestone
 * dates on the batch. Pro supplies the states between through the
 * bfbt_batch_stage filter and inserts them into the order through
 * bfbt_stage_order — the same contract as bfmt_tray_status in the Microgreen
 * pair. There is no stage column to fall out of step with the milestone that
 * actually happened.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class BFBT_Stage {

	public const PLANNED   = 'planned';
	public const PRIMARY   = 'primary';
	public const SECONDARY = 'secondary';
	public const PACKAGED  = 'packaged';
	public const COMPLETE  = 'complete';
	public const DUMPED    = 'dumped';

	/** The free plugin's order, before Pro inserts its states. */
	private const ORDER = [ self::PLANNED, self::PRIMARY, self::SECONDARY, self::PACKAGED, self::COMPLETE, self::DUMPED ];

	/**
	 * Derive the stage from the milestones, most final first.
	 *
	 * dumped beats everything: a batch that was completed and then poured
	 * away is dumped, and a completed date left behind must not hide that.
	 */
	public static function get( int $batch_id ): string {
		if ( '' !== (string) BFBT_Batch::get( $batch_id, 'dumped' ) ) {
			$stage = self::DUMPED;
		} elseif ( '' !== (string) BFBT_Batch::get( $batch_id, 'completed_date' ) ) {
			$stage = self::COMPLETE;
		} elseif ( '' !== (string) BFBT_Batch::get( $batch_id, 'packaged_date' ) ) {
			$stage = self::PACKAGED;
		} elseif ( '' !== (string) BFBT_Batch::get( $batch_id, 'secondary_date' ) ) {
			$stage = self::SECONDARY;
		} elseif ( '' !== (string) BFBT_Batch::get( $batch_id, 'started' ) ) {
			$stage = self::PRIMARY;
		} else {
			$stage = self::PLANNED;
		}

		/**
		 * Filter a batch's stage.
		 *
		 * Pro returns one of its extended states here. Anything returned is
		 * treated as the stage; label() falls back to the raw key for a state
		 * this plugin does not know about.
		 *
		 * @param string $stage
		 * @param int    $batch_id
		 */
		return (string) apply_filters( 'bfbt_batch_stage', $stage, $batch_id );
	}

	/**
	 * The date of the milestone that decided the free stage, or '' when the
	 * stage carries no date (planned, dumped).
	 */
	public static function since( int $batch_id ): string {
		$field = [
			self::COMPLETE  => 'completed_date',
			self::PACKAGED  => 'packaged_date',
			self::SECONDARY => 'secondary_date',
			self::PRIMARY   => 'started',
		][ self::get( $batch_id ) ] ?? '';

		return '' === $field ? '' : (string) BFBT_Batch::get( $batch_id, $field );
	}

	/**
	 * Every stage key, in cycle order.
	 *
	 * @return string[]
	 */
	public static function order(): array {
		/**
		 * Filter the ordered list of stage keys.
		 *
		 * Pro inserts its states here; "until stage" and "days per stage"
		 * compare by index in this list.
		 *
		 * @param string[] $order
		 */
		$order = (array) apply_filters( 'bfbt_stage_order', self::ORDER );

		return array_values( array_unique( array_map( 'strval', $order ) ) );
	}

	/**
	 * Position of a stage in the cycle; -1 for a key nobody registered.
	 */
	public static function index_of( string $stage ): int {
		$index = array_search( $stage, self::order(), true );

		return false === $index ? -1 : (int) $index;
	}

	/**
	 * @return string[]
	 */
	public static function terminal(): array {
		/**
		 * Filter the stages after which nothing more is scheduled.
		 *
		 * @param string[] $stages
		 */
		return (array) apply_filters( 'bfbt_terminal_stages', [ self::COMPLETE, self::DUMPED ] );
	}

	public static function is_terminal( string $stage ): bool {
		return in_array( $stage, self::terminal(), true );
	}

	/**
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return (array) apply_filters( 'bfbt_stage_labels', [
			self::PLANNED   => __( 'Planned', 'bluefairy-brew-tracker' ),
			self::PRIMARY   => __( 'Primary', 'bluefairy-brew-tracker' ),
			self::SECONDARY => __( 'Secondary', 'bluefairy-brew-tracker' ),
			self::PACKAGED  => __( 'Packaged', 'bluefairy-brew-tracker' ),
			self::COMPLETE  => __( 'Complete', 'bluefairy-brew-tracker' ),
			self::DUMPED    => __( 'Dumped', 'bluefairy-brew-tracker' ),
		] );
	}

	public static function label( string $stage ): string {
		return (string) ( self::labels()[ $stage ] ?? ucfirst( str_replace( '_', ' ', $stage ) ) );
	}

	/**
	 * @return array<string, string> stage => "colour;background:colour"
	 */
	public static function colours(): array {
		return (array) apply_filters( 'bfbt_stage_colours', [
			self::PLANNED   => '#374151;background:#f3f4f6',
			self::PRIMARY   => '#92400e;background:#fef3c7',
			self::SECONDARY => '#1e40af;background:#dbeafe',
			self::PACKAGED  => '#5b21b6;background:#ede9fe',
			self::COMPLETE  => '#166534;background:#dcfce7',
			self::DUMPED    => '#991b1b;background:#fee2e2',
		] );
	}

	public static function badge( string $stage ): string {
		$colour = (string) ( self::colours()[ $stage ] ?? '#374151;background:#f3f4f6' );

		return sprintf(
			'<span class="bfbt-stage bfbt-stage-%s" style="%s">%s</span>',
			esc_attr( sanitize_key( $stage ) ),
			esc_attr( 'color:' . $colour . ';' ),
			esc_html( self::label( $stage ) )
		);
	}
}
