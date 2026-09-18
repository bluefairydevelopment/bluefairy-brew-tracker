<?php
/**
 * The batch list.
 *
 * One paged WP_Query for the rows, then one grouped query each for the
 * "Latest SG" and "Next due" columns over the page's ids, and one meta-cache
 * prime for the pinned OG entries — never a query per row.
 *
 * Sortable in the database by started and name. Stage is derived from four
 * milestone keys and next-due from an aggregate over another post type, so
 * neither is a sortable column: sorting them would need either a page-local
 * sort (a lie on page 2) or a join the plan does not budget for.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BFBT_Batch_List_Table extends WP_List_Table {

	public const PER_PAGE = 25;

	private string $status;

	/** @var array<int, array{id: int, sg: string, date: string, time: string}> */
	private array $latest = [];

	/** @var array<int, array{due: string, title: string, id: int}> */
	private array $next = [];

	private string $today;

	public function __construct( string $status = 'publish' ) {
		parent::__construct( [ 'singular' => 'batch', 'plural' => 'batches', 'ajax' => false ] );

		$this->status = 'trash' === $status ? 'trash' : 'publish';
		$this->today  = wp_date( 'Y-m-d' );
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return [
			'title'     => __( 'Batch', 'bluefairy-brew-tracker' ),
			'style'     => __( 'Style', 'bluefairy-brew-tracker' ),
			'vessel'    => __( 'Vessel', 'bluefairy-brew-tracker' ),
			'started'   => __( 'Started', 'bluefairy-brew-tracker' ),
			'stage'     => __( 'Stage', 'bluefairy-brew-tracker' ),
			'latest_sg' => __( 'Latest SG', 'bluefairy-brew-tracker' ),
			'abv'       => __( 'ABV', 'bluefairy-brew-tracker' ),
			'next_due'  => __( 'Next due', 'bluefairy-brew-tracker' ),
		];
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public function get_sortable_columns(): array {
		return [
			'title'   => [ 'title', false ],
			'started' => [ 'started', true ],
		];
	}

	/**
	 * All | Trash (N).
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$counts = wp_count_posts( BFBT_Batch::POST_TYPE );
		$all    = (int) ( $counts->publish ?? 0 );
		$trash  = (int) ( $counts->trash ?? 0 );

		$views = [
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( BFBT_Batch_Admin::page_url() ),
				'publish' === $this->status ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'bluefairy-brew-tracker' ),
				$all
			),
		];

		if ( $trash > 0 || 'trash' === $this->status ) {
			$views['trash'] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( BFBT_Batch_Admin::page_url( [ 'view' => 'trash' ] ) ),
				'trash' === $this->status ? ' class="current" aria-current="page"' : '',
				esc_html__( 'Trash', 'bluefairy-brew-tracker' ),
				$trash
			);
		}

		return $views;
	}

	/**
	 * Stage and style filters.
	 *
	 * @param string $which
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which || 'trash' === $this->status ) {
			return;
		}

		$stage = $this->filter( 'stage' );
		$style = absint( $this->filter( 'style' ) );

		echo '<div class="alignleft actions">';

		echo '<label for="bfbt-filter-stage" class="screen-reader-text">' . esc_html__( 'Filter by stage', 'bluefairy-brew-tracker' ) . '</label>';
		echo '<select id="bfbt-filter-stage" name="stage"><option value="">' . esc_html__( 'All stages', 'bluefairy-brew-tracker' ) . '</option>';

		foreach ( [ 'planned', 'primary', 'secondary', 'packaged', 'complete', 'dumped' ] as $key ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $stage, $key, false ), esc_html( BFBT_Stage::label( $key ) ) );
		}

		echo '</select> ';

		$terms = get_terms( [ 'taxonomy' => BFBT_Batch::STYLE_TAX, 'hide_empty' => false, 'orderby' => 'name' ] );

		echo '<label for="bfbt-filter-style" class="screen-reader-text">' . esc_html__( 'Filter by style', 'bluefairy-brew-tracker' ) . '</label>';
		echo '<select id="bfbt-filter-style" name="style"><option value="">' . esc_html__( 'All styles', 'bluefairy-brew-tracker' ) . '</option>';

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				printf(
					'<option value="%d"%s>%s</option>',
					absint( $term->term_id ),
					selected( $style, (int) $term->term_id, false ),
					esc_html( ( 0 === (int) $term->parent ? '' : '— ' ) . $term->name )
				);
			}
		}

		echo '</select> ';
		submit_button( __( 'Filter', 'bluefairy-brew-tracker' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * A read-only filter value from the query string.
	 */
	private function filter( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a filter to build a read-only query.
		return isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	/**
	 * @param WP_Post $item
	 */
	public function column_title( $item ): string {
		$id   = (int) $item->ID;
		$name = get_the_title( $id );
		$name = '' !== $name ? $name : __( '(no name)', 'bluefairy-brew-tracker' );

		if ( 'trash' === $this->status ) {
			$title   = '<strong>' . esc_html( $name ) . '</strong>';
			$actions = [];

			if ( current_user_can( BFBT_Batch_Admin::CAP_DESTROY ) ) {
				$actions['restore'] = sprintf( '<a href="%s">%s</a>', esc_url( $this->action_url( 'bfbt_restore_batch', $id, 'bfbt_restore_batch_' . $id ) ), esc_html__( 'Restore', 'bluefairy-brew-tracker' ) );
				$actions['delete']  = sprintf(
					'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\')">%s</a>',
					esc_url( $this->action_url( 'bfbt_delete_batch', $id, 'bfbt_delete_batch_' . $id ) ),
					esc_js( __( 'Delete this batch, its entries and its tasks permanently?', 'bluefairy-brew-tracker' ) ),
					esc_html__( 'Delete permanently', 'bluefairy-brew-tracker' )
				);
			}

			return $title . $this->row_actions( $actions );
		}

		$title = sprintf( '<strong><a href="%s">%s</a></strong>', esc_url( BFBT_Batch_Admin::view_url( $id ) ), esc_html( $name ) );

		$actions = [
			'view' => sprintf( '<a href="%s">%s</a>', esc_url( BFBT_Batch_Admin::view_url( $id ) ), esc_html__( 'View', 'bluefairy-brew-tracker' ) ),
			'edit' => sprintf( '<a href="%s">%s</a>', esc_url( BFBT_Batch_Admin::page_url( [ 'view' => 'edit', 'batch_id' => $id ] ) ), esc_html__( 'Edit', 'bluefairy-brew-tracker' ) ),
		];

		if ( current_user_can( BFBT_Batch_Admin::CAP_DESTROY ) ) {
			$actions['trash'] = sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\')">%s</a>',
				esc_url( $this->action_url( 'bfbt_trash_batch', $id, 'bfbt_trash_batch_' . $id ) ),
				esc_js( __( 'Move this batch, its entries and its tasks to the trash?', 'bluefairy-brew-tracker' ) ),
				esc_html__( 'Trash', 'bluefairy-brew-tracker' )
			);
		}

		return $title . $this->row_actions( $actions );
	}

	private function action_url( string $action, int $batch_id, string $nonce ): string {
		return wp_nonce_url( add_query_arg( [ 'action' => $action, 'batch_id' => $batch_id ], admin_url( 'admin-post.php' ) ), $nonce );
	}

	/**
	 * @param WP_Post $item
	 * @param string  $column_name
	 */
	public function column_default( $item, $column_name ): string {
		$id = (int) $item->ID;

		switch ( $column_name ) {
			case 'style':
				return $this->text_or_dash( BFBT_Batch::style_name( $id ) );

			case 'vessel':
				return $this->text_or_dash( BFBT_Batch::vessel_name( $id ) );

			case 'started':
				return $this->text_or_dash( (string) BFBT_Batch::get( $id, 'started' ) );

			case 'stage':
				return BFBT_Stage::badge( BFBT_Stage::get( $id ) );

			case 'latest_sg':
				return isset( $this->latest[ $id ] ) ? esc_html( $this->latest[ $id ]['sg'] ) : '&mdash;';

			case 'abv':
				$og = $this->pinned_og( $id );

				// One reading, which is the OG itself, is not an ABV.
				if ( '' === $og || ! isset( $this->latest[ $id ] ) || $this->latest[ $id ]['id'] === absint( BFBT_Batch::get( $id, 'og_log_id' ) ) ) {
					return '&mdash;';
				}

				return esc_html( number_format( BFBT_ABV::from_gravities( (float) $og, (float) $this->latest[ $id ]['sg'] ), 1 ) . ' %' );

			case 'next_due':
				if ( ! isset( $this->next[ $id ] ) ) {
					return '&mdash;';
				}

				$due     = $this->next[ $id ]['due'];
				$overdue = strcmp( $due, $this->today ) < 0;

				return sprintf(
					'<span class="bfbt-due%s">%s</span><br><span class="description">%s</span>',
					$overdue ? ' bfbt-overdue' : '',
					esc_html( $due ),
					esc_html( $this->next[ $id ]['title'] )
				);

			default:
				return '';
		}
	}

	private function text_or_dash( string $text ): string {
		return '' !== $text ? esc_html( $text ) : '&mdash;';
	}

	/**
	 * The pinned OG's SG, from the meta cache primed in prepare_items().
	 */
	private function pinned_og( int $batch_id ): string {
		$og_log_id = absint( BFBT_Batch::get( $batch_id, 'og_log_id' ) );

		return $og_log_id > 0 ? BFBT_Log::field( $og_log_id, 'sg' ) : '';
	}

	/**
	 * Query one page, then the page's derived columns in bulk.
	 */
	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading a sort order to build a read-only query.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( (string) $_GET['orderby'] ) ) : 'started';
		$order   = ( isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( (string) $_GET['order'] ) ) ) ) ? 'ASC' : 'DESC';
		// phpcs:enable

		$args = [
			'post_type'              => BFBT_Batch::POST_TYPE,
			'post_status'            => $this->status,
			'posts_per_page'         => self::PER_PAGE,
			'paged'                  => $this->get_pagenum(),
			'update_post_term_cache' => true,
			'update_post_meta_cache' => true,
		];

		if ( 'title' === $orderby ) {
			$args['orderby'] = [ 'title' => $order, 'ID' => $order ];
		} else {
			$args['orderby'] = [ 'ID' => $order ];
			add_filter( 'posts_clauses', [ $this, 'order_by_started' ], 10, 2 );
			$this->started_order = $order;
		}

		$meta_query = $this->stage_meta_query( $this->filter( 'stage' ) );

		if ( $meta_query ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the stage filter is a set of milestone keys.
		}

		$style = absint( $this->filter( 'style' ) );

		if ( $style > 0 ) {
			$args['tax_query'] = [ [ 'taxonomy' => BFBT_Batch::STYLE_TAX, 'field' => 'term_id', 'terms' => $style ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$query = new WP_Query( $args );

		remove_filter( 'posts_clauses', [ $this, 'order_by_started' ], 10 );

		$this->items = $query->posts;

		$ids = array_map( static fn( object $p ): int => (int) $p->ID, $this->items );

		if ( $ids ) {
			$this->latest = BFBT_Log::latest_gravity_for( $ids );
			$this->next   = BFBT_Task::next_due_for( $ids );

			// The ABV column reads each pinned OG entry's SG; prime those in one go.
			$og_ids = array_values( array_filter( array_map( static fn( int $id ): int => absint( BFBT_Batch::get( $id, 'og_log_id' ) ), $ids ) ) );

			if ( $og_ids ) {
				update_meta_cache( 'post', $og_ids );
			}
		}

		$this->set_pagination_args( [
			'total_items' => (int) $query->found_posts,
			'per_page'    => self::PER_PAGE,
			'total_pages' => (int) $query->max_num_pages,
		] );

		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
	}

	private string $started_order = 'DESC';

	/**
	 * Sort by the start date with a LEFT JOIN, so a planned batch (no start
	 * date) stays on the list. A meta_key sort would drop it.
	 *
	 * @param  array<string, string> $clauses
	 * @return array<string, string>
	 */
	public function order_by_started( array $clauses, WP_Query $query ): array {
		global $wpdb;

		if ( BFBT_Batch::POST_TYPE !== $query->get( 'post_type' ) ) {
			return $clauses;
		}

		$clauses['join']   .= $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} bfbt_started ON ( bfbt_started.post_id = {$wpdb->posts}.ID AND bfbt_started.meta_key = %s )", '_bfbt_started' );
		$clauses['orderby'] = 'bfbt_started.meta_value ' . $this->started_order . ', ' . $clauses['orderby'];

		return $clauses;
	}

	/**
	 * The stage filter as the milestone keys that derive it.
	 *
	 * Free's six stages only: a Pro stage is supplied through a filter at
	 * read time and has no milestone key of its own here.
	 *
	 * @return array<mixed>
	 */
	private function stage_meta_query( string $stage ): array {
		$exists = static fn( string $key ): array => [ 'key' => $key, 'compare' => 'EXISTS' ];
		$absent = static fn( string $key ): array => [ 'key' => $key, 'compare' => 'NOT EXISTS' ];

		$dumped    = '_bfbt_dumped';
		$completed = '_bfbt_completed_date';
		$packaged  = '_bfbt_packaged_date';
		$secondary = '_bfbt_secondary_date';
		$started   = '_bfbt_started';

		switch ( $stage ) {
			case 'dumped':
				return [ $exists( $dumped ) ];
			case 'complete':
				return [ $exists( $completed ), $absent( $dumped ) ];
			case 'packaged':
				return [ $exists( $packaged ), $absent( $completed ), $absent( $dumped ) ];
			case 'secondary':
				return [ $exists( $secondary ), $absent( $packaged ), $absent( $completed ), $absent( $dumped ) ];
			case 'primary':
				return [ $exists( $started ), $absent( $secondary ), $absent( $packaged ), $absent( $completed ), $absent( $dumped ) ];
			case 'planned':
				return [ $absent( $started ), $absent( $secondary ), $absent( $packaged ), $absent( $completed ), $absent( $dumped ) ];
			default:
				return [];
		}
	}

	public function no_items(): void {
		if ( 'trash' === $this->status ) {
			esc_html_e( 'The trash is empty.', 'bluefairy-brew-tracker' );
		} else {
			esc_html_e( 'No batches yet. Add your first one to start the log.', 'bluefairy-brew-tracker' );
		}
	}
}
