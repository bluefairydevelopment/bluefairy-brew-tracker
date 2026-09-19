<?php
/**
 * The Brew Tracker screen: list, add, edit, view, trash — and every write.
 *
 * Every write is a form POST (or a nonced link) to an admin_post_bfbt_*
 * handler. Each handler guards its own capability from the table in the
 * plan — edit_posts to record, delete_others_posts to destroy,
 * manage_options to configure — verifies the per-object nonce before it
 * reads anything, unslashes and sanitises before validation, confirms the
 * post type of every id it is handed, and reports what actually happened
 * through a one-shot transient notice.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class BFBT_Batch_Admin {

	public const PAGE_SLUG = 'bfbt-batches';

	/** Who may see the screen and record to it. */
	public const CAP_RECORD = 'edit_posts';

	/** Who may trash, restore and delete. A Brewer or Contributor can log, never destroy. */
	public const CAP_DESTROY = 'delete_others_posts';

	/** Who may change settings and export. */
	public const CAP_MANAGE = 'manage_options';

	/** Hook suffixes of this plugin's screens; assets load on these only. */
	private static array $screens = [];

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_notices', [ __CLASS__, 'show_notice' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );

		add_action( 'admin_post_bfbt_save_batch', [ __CLASS__, 'save_batch' ] );
		add_action( 'admin_post_bfbt_trash_batch', [ __CLASS__, 'trash_batch' ] );
		add_action( 'admin_post_bfbt_restore_batch', [ __CLASS__, 'restore_batch' ] );
		add_action( 'admin_post_bfbt_delete_batch', [ __CLASS__, 'delete_batch' ] );
		add_action( 'admin_post_bfbt_add_log', [ __CLASS__, 'add_log' ] );
		add_action( 'admin_post_bfbt_trash_log', [ __CLASS__, 'trash_log' ] );
		add_action( 'admin_post_bfbt_save_task', [ __CLASS__, 'save_task' ] );
		add_action( 'admin_post_bfbt_complete_task', [ __CLASS__, 'complete_task' ] );
		add_action( 'admin_post_bfbt_uncomplete_task', [ __CLASS__, 'uncomplete_task' ] );
		add_action( 'admin_post_bfbt_pin_og', [ __CLASS__, 'pin_og' ] );
	}

	public static function menu(): void {
		$hook = add_menu_page(
			__( 'Brew Tracker', 'bluefairy-brew-tracker' ),
			__( 'Brew Tracker', 'bluefairy-brew-tracker' ),
			self::CAP_RECORD,
			self::PAGE_SLUG,
			[ __CLASS__, 'render' ],
			'dashicons-beer',
			26
		);

		self::register_screen( (string) $hook );

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Batches', 'bluefairy-brew-tracker' ),
			__( 'Batches', 'bluefairy-brew-tracker' ),
			self::CAP_RECORD,
			self::PAGE_SLUG,
			[ __CLASS__, 'render' ]
		);

		/**
		 * The top-level menu is registered; add-ons add their submenus here.
		 *
		 * @param string $parent_slug
		 */
		do_action( 'bfbt_admin_menu', self::PAGE_SLUG );
	}

	/**
	 * Record a screen the plugin's assets belong on.
	 */
	public static function register_screen( string $hook_suffix ): void {
		if ( '' !== $hook_suffix ) {
			self::$screens[] = $hook_suffix;
		}
	}

	public static function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, self::$screens, true ) ) {
			return;
		}

		wp_enqueue_style( 'bfbt-admin', BFBT_URL . 'assets/css/bfbt-admin.css', [], BFBT_VERSION );
		wp_enqueue_script( 'bfbt-admin', BFBT_URL . 'assets/js/bfbt-admin.js', [], BFBT_VERSION, true );
	}

	/**
	 * @param array<string, string|int> $args
	 */
	public static function page_url( array $args = [] ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
	}

	public static function view_url( int $batch_id ): string {
		return self::page_url( [ 'view' => 'view', 'batch_id' => $batch_id ] );
	}

	/**
	 * Stop unless the current user holds the capability.
	 */
	public static function guard( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'bluefairy-brew-tracker' ), '', [ 'response' => 403 ] );
		}
	}

	/**
	 * A posted text field, unslashed and sanitised; '' when absent.
	 */
	public static function post_text( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller has verified its nonce before reading.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
	}

	public static function post_int( string $key ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see post_text().
		return isset( $_POST[ $key ] ) ? absint( wp_unslash( (string) $_POST[ $key ] ) ) : 0;
	}

	/**
	 * An id that may arrive by POST (a form) or GET (a nonced row-action link).
	 */
	private static function request_int( string $key ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- every caller has verified its nonce before reading.
		return isset( $_REQUEST[ $key ] ) ? absint( wp_unslash( (string) $_REQUEST[ $key ] ) ) : 0;
	}

	/**
	 * @param array{0: string, 1: string} $notice 'updated' or 'error', then the message.
	 * @param array<string, string|int>   $args   Query args for the screen to land on.
	 */
	public static function redirect_with_notice( array $notice, array $args = [] ): void {
		set_transient( 'bfbt_notice_' . get_current_user_id(), $notice, 30 );
		wp_safe_redirect( self::page_url( $args ) );
		exit;
	}

	public static function show_notice(): void {
		$notice = get_transient( 'bfbt_notice_' . get_current_user_id() );

		if ( ! is_array( $notice ) || 2 !== count( $notice ) ) {
			return;
		}

		delete_transient( 'bfbt_notice_' . get_current_user_id() );

		// The bfbt-notice class marks this as the plugin's own notice, so a
		// test (or a style) can tell it from another plugin's on the same page.
		printf(
			'<div class="notice notice-%s is-dismissible bfbt-notice"><p>%s</p></div>',
			esc_attr( 'error' === $notice[0] ? 'error' : 'success' ),
			esc_html( (string) $notice[1] )
		);
	}

	/*
	 * ----------------------------------------------------------------------
	 * A refused save keeps what was typed
	 * ----------------------------------------------------------------------
	 *
	 * A handler that refuses calls remember_input() before it redirects; the
	 * form it lands on reads every field through old(), so the page comes
	 * back filled — the refused value included, so the maker can see what
	 * was wrong. The copy lives in a per-user transient beside the notice
	 * and is deleted the first time a form reads it: a reload after that
	 * is a fresh form. Add-ons that print their own fields (or their own
	 * forms) use the same two calls.
	 */

	/** The remembered fields of this request, once read; null until then. */
	private static ?array $remembered = null;

	/**
	 * Keep a sanitised copy of a refused form's fields for the next render.
	 *
	 * @param string                 $form   A name the form reads back with, e.g. 'log'.
	 * @param array<string, string>  $fields Field name → value as posted (already unslashed).
	 */
	public static function remember_input( string $form, array $fields ): void {
		$clean = [];

		foreach ( $fields as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || ! is_scalar( $value ) ) {
				continue;
			}

			$clean[ $key ] = in_array( $key, [ 'note', 'notes' ], true )
				? wp_kses_post( (string) $value )
				: sanitize_text_field( (string) $value );
		}

		set_transient( 'bfbt_form_' . get_current_user_id(), [ 'form' => $form, 'fields' => $clean ], 30 );
	}

	/**
	 * A remembered field of a refused form, or the default when nothing was
	 * remembered for this form. The stored copy is deleted on first read.
	 */
	public static function old( string $form, string $key, string $default = '' ): string {
		if ( null === self::$remembered ) {
			$name   = 'bfbt_form_' . get_current_user_id();
			$stored = get_transient( $name );

			delete_transient( $name );

			self::$remembered = is_array( $stored ) && isset( $stored['form'], $stored['fields'] ) && is_array( $stored['fields'] )
				? [ 'form' => (string) $stored['form'], 'fields' => $stored['fields'] ]
				: [ 'form' => '', 'fields' => [] ];
		}

		if ( self::$remembered['form'] !== $form || ! array_key_exists( $key, self::$remembered['fields'] ) ) {
			return $default;
		}

		return (string) self::$remembered['fields'][ $key ];
	}

	/**
	 * Drop the in-memory copy once the screen has rendered, so the next
	 * request reads the store again.
	 */
	public static function forget_input(): void {
		self::$remembered = null;
	}

	/*
	 * ----------------------------------------------------------------------
	 * Screens
	 * ----------------------------------------------------------------------
	 */

	public static function render(): void {
		self::guard( self::CAP_RECORD );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- choosing which read-only view to draw.
		$view     = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : 'list';
		$batch_id = isset( $_GET['batch_id'] ) ? absint( wp_unslash( (string) $_GET['batch_id'] ) ) : 0;
		// phpcs:enable

		echo '<div class="wrap bfbt-wrap">';

		if ( 'add' === $view ) {
			self::render_form( 0 );
		} elseif ( 'edit' === $view && BFBT_Batch::is_batch( $batch_id ) ) {
			self::render_form( $batch_id );
		} elseif ( 'view' === $view && BFBT_Batch::is_batch( $batch_id ) ) {
			BFBT_Batch_View::render( $batch_id );
		} else {
			self::render_list( 'trash' === $view ? 'trash' : 'publish' );
		}

		echo '</div>';

		self::forget_input();
	}

	private static function render_list( string $status ): void {
		$table = new BFBT_Batch_List_Table( $status );
		$table->prepare_items();

		printf(
			'<h1 class="wp-heading-inline">%s</h1> <a href="%s" class="page-title-action">%s</a><hr class="wp-header-end">',
			esc_html__( 'Brew Tracker', 'bluefairy-brew-tracker' ),
			esc_url( self::page_url( [ 'view' => 'add' ] ) ),
			esc_html__( 'Add batch', 'bluefairy-brew-tracker' )
		);

		$table->views();

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::PAGE_SLUG ) );

		if ( 'trash' === $status ) {
			echo '<input type="hidden" name="view" value="trash">';
		}

		$table->display();
		echo '</form>';

		/**
		 * After the batch list. Add-ons render their own panels here.
		 */
		do_action( 'bfbt_after_batch_list' );

		BFBT_Pro_Upsell::render_panel();
	}

	private static function render_form( int $batch_id ): void {
		$editing = $batch_id > 0;
		$form    = self::batch_form_name( $batch_id );
		$old     = static fn( string $key, string $stored = '' ): string => self::old( $form, $key, $stored );

		$name      = $old( 'name', $editing ? get_the_title( $batch_id ) : '' );
		$notes     = $old( 'notes', $editing ? (string) get_post_field( 'post_content', $batch_id ) : '' );
		$style_id  = (int) $old( 'style', (string) ( $editing ? BFBT_Batch::style_term_id( $batch_id ) : 0 ) );
		$vessel    = $old( 'vessel', $editing ? BFBT_Batch::vessel_name( $batch_id ) : '' );
		$started   = $old( 'started', $editing ? (string) BFBT_Batch::get( $batch_id, 'started' ) : '' );
		$target_og = $old( 'target_og', $editing ? (string) BFBT_Batch::get( $batch_id, 'target_og' ) : '' );
		$target_fg = $old( 'target_fg', $editing ? (string) BFBT_Batch::get( $batch_id, 'target_fg' ) : '' );

		$entered = $editing ? (string) BFBT_Batch::get( $batch_id, 'volume_entered' ) : '';
		[ $volume, $volume_unit ] = 1 === preg_match( '/^(\S+)\s+(\S+)$/', $entered, $m ) ? [ $m[1], $m[2] ] : [ '', BFBT_Units::volume_unit() ];
		$volume      = $old( 'volume', $volume );
		$volume_unit = $old( 'volume_unit', $volume_unit );

		$vessel_new      = $old( 'vessel_new' );
		$vessel_type     = $old( 'vessel_type' );
		$vessel_capacity = $old( 'vessel_capacity' );
		$capacity_unit   = $old( 'vessel_capacity_unit', BFBT_Units::volume_unit() );

		printf(
			'<h1>%s</h1>',
			esc_html( $editing ? __( 'Edit batch', 'bluefairy-brew-tracker' ) : __( 'Add batch', 'bluefairy-brew-tracker' ) )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( $editing ? 'bfbt_edit_batch_' . $batch_id : 'bfbt_add_batch' );
		echo '<input type="hidden" name="action" value="bfbt_save_batch">';
		printf( '<input type="hidden" name="batch_id" value="%d">', absint( $batch_id ) );

		echo '<table class="form-table" role="presentation"><tbody>';

		self::field( 'name', __( 'Name', 'bluefairy-brew-tracker' ), $name, 'text', __( 'Required. "Traditional Mead #3", say.', 'bluefairy-brew-tracker' ) );

		// Style: top-level terms as groups, their children as options.
		echo '<tr><th scope="row"><label for="bfbt-style">' . esc_html__( 'Style', 'bluefairy-brew-tracker' ) . '</label></th><td>';
		echo '<select id="bfbt-style" name="style"><option value="">' . esc_html__( '— none —', 'bluefairy-brew-tracker' ) . '</option>';

		foreach ( self::style_groups() as $group ) {
			printf( '<optgroup label="%s">', esc_attr( $group['name'] ) );
			printf( '<option value="%d"%s>%s</option>', absint( $group['id'] ), selected( $style_id, $group['id'], false ), esc_html( $group['name'] ) );

			foreach ( $group['children'] as $child ) {
				printf( '<option value="%d"%s>%s</option>', absint( $child['id'] ), selected( $style_id, $child['id'], false ), esc_html( '— ' . $child['name'] ) );
			}

			echo '</optgroup>';
		}

		echo '</select></td></tr>';

		// Vessel: an existing one, or a new one with its type and capacity.
		echo '<tr><th scope="row"><label for="bfbt-vessel">' . esc_html__( 'Vessel', 'bluefairy-brew-tracker' ) . '</label></th><td>';
		echo '<select id="bfbt-vessel" name="vessel"><option value="">' . esc_html__( '— none —', 'bluefairy-brew-tracker' ) . '</option>';

		foreach ( self::vessel_names() as $known ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $known ), selected( $vessel, $known, false ), esc_html( $known ) );
		}

		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Or add a new vessel:', 'bluefairy-brew-tracker' ) . '</p>';
		printf(
			'<input type="text" name="vessel_new" value="%s" class="regular-text" placeholder="%s"> ',
			esc_attr( $vessel_new ),
			esc_attr__( 'Name, e.g. Carboy B', 'bluefairy-brew-tracker' )
		);
		echo '<select name="vessel_type"><option value="">' . esc_html__( 'type', 'bluefairy-brew-tracker' ) . '</option>';

		foreach ( BFBT_Batch::VESSEL_TYPES as $type ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $type ), selected( $vessel_type, $type, false ), esc_html( $type ) );
		}

		echo '</select> ';
		printf( '<input type="text" name="vessel_capacity" value="%s" class="small-text" placeholder="%s"> ', esc_attr( $vessel_capacity ), esc_attr__( 'capacity', 'bluefairy-brew-tracker' ) );
		self::unit_select( 'vessel_capacity_unit', $capacity_unit, BFBT_Units::volume_units() );
		echo '</td></tr>';

		self::field( 'started', __( 'Started', 'bluefairy-brew-tracker' ), $started, 'date', __( 'The day the yeast went in. Leave blank for a batch you are planning.', 'bluefairy-brew-tracker' ) );

		echo '<tr><th scope="row"><label for="bfbt-volume">' . esc_html__( 'Volume', 'bluefairy-brew-tracker' ) . '</label></th><td>';
		printf( '<input type="text" id="bfbt-volume" name="volume" value="%s" class="small-text"> ', esc_attr( $volume ) );
		self::unit_select( 'volume_unit', $volume_unit, BFBT_Units::volume_units() );
		echo '<p class="description">' . esc_html__( 'Stored in litres; shown as you entered it.', 'bluefairy-brew-tracker' ) . '</p></td></tr>';

		self::field( 'target_og', __( 'Target OG', 'bluefairy-brew-tracker' ), $target_og, 'text', __( 'Optional, as 1.090.', 'bluefairy-brew-tracker' ) );
		self::field( 'target_fg', __( 'Target FG', 'bluefairy-brew-tracker' ), $target_fg, 'text', __( 'Optional, as 1.000.', 'bluefairy-brew-tracker' ) );

		printf(
			'<tr><th scope="row"><label for="bfbt-notes">%s</label></th><td><textarea id="bfbt-notes" name="notes" rows="5" class="large-text">%s</textarea></td></tr>',
			esc_html__( 'Notes', 'bluefairy-brew-tracker' ),
			esc_textarea( $notes )
		);

		echo '</tbody></table>';

		/**
		 * Extra batch form fields. Add-ons print their own rows here.
		 *
		 * @param int $batch_id 0 when adding.
		 */
		do_action( 'bfbt_batch_form_fields', $batch_id );

		submit_button( $editing ? __( 'Update batch', 'bluefairy-brew-tracker' ) : __( 'Add batch', 'bluefairy-brew-tracker' ) );

		echo '</form>';
	}

	/**
	 * One labelled text-like input row.
	 */
	public static function field( string $name, string $label, string $value, string $type, string $help ): void {
		printf(
			'<tr><th scope="row"><label for="bfbt-%1$s">%2$s</label></th><td>'
			. '<input type="%3$s" id="bfbt-%1$s" name="%1$s" value="%4$s" class="regular-text">'
			. '<p class="description">%5$s</p></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $value ),
			esc_html( $help )
		);
	}

	/**
	 * @param string[] $units
	 */
	public static function unit_select( string $name, string $selected, array $units ): void {
		printf( '<select name="%s">', esc_attr( $name ) );

		foreach ( $units as $unit ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $unit ), selected( $selected, $unit, false ), esc_html( $unit ) );
		}

		echo '</select>';
	}

	/**
	 * @return array<int, array{id: int, name: string, children: array<int, array{id: int, name: string}>}>
	 */
	private static function style_groups(): array {
		$terms = get_terms( [ 'taxonomy' => BFBT_Batch::STYLE_TAX, 'hide_empty' => false, 'orderby' => 'name' ] );

		if ( ! is_array( $terms ) ) {
			return [];
		}

		$groups = [];

		foreach ( $terms as $term ) {
			if ( 0 === (int) $term->parent ) {
				$groups[ (int) $term->term_id ] = [ 'id' => (int) $term->term_id, 'name' => (string) $term->name, 'children' => [] ];
			}
		}

		foreach ( $terms as $term ) {
			if ( isset( $groups[ (int) $term->parent ] ) ) {
				$groups[ (int) $term->parent ]['children'][] = [ 'id' => (int) $term->term_id, 'name' => (string) $term->name ];
			}
		}

		return array_values( $groups );
	}

	/**
	 * @return string[]
	 */
	private static function vessel_names(): array {
		$terms = get_terms( [ 'taxonomy' => BFBT_Batch::VESSEL_TAX, 'hide_empty' => false, 'orderby' => 'name' ] );

		return is_array( $terms ) ? array_map( static fn( object $t ): string => (string) $t->name, $terms ) : [];
	}

	/*
	 * ----------------------------------------------------------------------
	 * Handlers — batch
	 * ----------------------------------------------------------------------
	 */

	public static function save_batch(): void {
		self::guard( self::CAP_RECORD );

		$batch_id = self::post_int( 'batch_id' );

		check_admin_referer( $batch_id > 0 ? 'bfbt_edit_batch_' . $batch_id : 'bfbt_add_batch' );

		if ( $batch_id > 0 && ! BFBT_Batch::is_batch( $batch_id ) ) {
			self::redirect_with_notice( [ 'error', __( 'That batch no longer exists.', 'bluefairy-brew-tracker' ) ] );
		}

		if ( 'milestones' === self::post_text( 'section' ) ) {
			$fields = [
				'secondary_date' => self::post_text( 'secondary_date' ),
				'packaged_date'  => self::post_text( 'packaged_date' ),
				'completed_date' => self::post_text( 'completed_date' ),
				'dumped'         => '' !== self::post_text( 'dumped' ) ? '1' : '',
			];

			$result = BFBT_Batch::update( $batch_id, $fields );

			if ( is_wp_error( $result ) ) {
				self::remember_input( 'milestones', $fields );
				self::redirect_with_notice( [ 'error', $result->get_error_message() ], [ 'view' => 'view', 'batch_id' => $batch_id ] );
			}

			self::redirect_with_notice( [ 'updated', __( 'Milestones saved.', 'bluefairy-brew-tracker' ) ], [ 'view' => 'view', 'batch_id' => $batch_id ] );
		}

		// Every field as posted, kept whole for the form to refill on a refusal.
		$typed = [
			'name'                 => self::post_text( 'name' ),
			'style'                => self::post_text( 'style' ),
			'vessel'               => self::post_text( 'vessel' ),
			'vessel_new'           => self::post_text( 'vessel_new' ),
			'vessel_type'          => self::post_text( 'vessel_type' ),
			'vessel_capacity'      => self::post_text( 'vessel_capacity' ),
			'vessel_capacity_unit' => self::post_text( 'vessel_capacity_unit' ),
			'started'              => self::post_text( 'started' ),
			'volume'               => self::post_text( 'volume' ),
			'volume_unit'          => self::post_text( 'volume_unit' ),
			'target_og'            => self::post_text( 'target_og' ),
			'target_fg'            => self::post_text( 'target_fg' ),
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
			'notes'                => isset( $_POST['notes'] ) ? wp_kses_post( wp_unslash( (string) $_POST['notes'] ) ) : '',
		];

		$refuse = static function ( string $message ) use ( $typed, $batch_id ): void {
			self::remember_input( self::batch_form_name( $batch_id ), $typed );
			self::redirect_with_notice( [ 'error', $message ], self::form_args( $batch_id ) );
		};

		$fields = [
			'name'        => $typed['name'],
			'style'       => $typed['style'],
			'started'     => $typed['started'],
			'volume'      => $typed['volume'],
			'volume_unit' => $typed['volume_unit'],
			'target_og'   => $typed['target_og'],
			'target_fg'   => $typed['target_fg'],
			'notes'       => $typed['notes'],
		];

		if ( '' !== $typed['vessel_new'] ) {
			$fields['vessel']      = $typed['vessel_new'];
			$fields['vessel_type'] = $typed['vessel_type'];

			if ( '' !== $typed['vessel_capacity'] ) {
				$litres = BFBT_Validate::volume( $typed['vessel_capacity'], $typed['vessel_capacity_unit'] );

				if ( is_wp_error( $litres ) ) {
					$refuse( $litres->get_error_message() );
				}

				$fields['vessel_capacity_l'] = number_format( $litres, 3, '.', '' );
			}
		} else {
			$fields['vessel'] = $typed['vessel'];
		}

		/**
		 * Filter the fields about to be saved from the batch form, so an
		 * add-on can read the inputs it printed through bfbt_batch_form_fields.
		 *
		 * @param array<string, mixed> $fields
		 * @param int                  $batch_id
		 */
		$fields = (array) apply_filters( 'bfbt_batch_form_submit', $fields, $batch_id );

		$result = $batch_id > 0 ? BFBT_Batch::update( $batch_id, $fields ) : BFBT_Batch::create( $fields );

		if ( is_wp_error( $result ) ) {
			$refuse( $result->get_error_message() );
		}

		$landed = $batch_id > 0 ? $batch_id : (int) $result;

		self::redirect_with_notice(
			[ 'updated', $batch_id > 0 ? __( 'Batch updated.', 'bluefairy-brew-tracker' ) : __( 'Batch added.', 'bluefairy-brew-tracker' ) ],
			[ 'view' => 'view', 'batch_id' => $landed ]
		);
	}

	/**
	 * @return array<string, string|int>
	 */
	private static function form_args( int $batch_id ): array {
		return $batch_id > 0 ? [ 'view' => 'edit', 'batch_id' => $batch_id ] : [ 'view' => 'add' ];
	}

	/**
	 * The name the add / edit form remembers its input under: one per batch,
	 * so a refused edit of one batch never fills another's form.
	 */
	public static function batch_form_name( int $batch_id ): string {
		return 'batch:' . $batch_id;
	}

	public static function trash_batch(): void {
		self::guard( self::CAP_DESTROY );

		$batch_id = self::request_int( 'batch_id' );

		check_admin_referer( 'bfbt_trash_batch_' . $batch_id );

		$ok = BFBT_Batch::is_batch( $batch_id ) && 'trash' !== get_post_status( $batch_id ) && wp_trash_post( $batch_id );

		self::redirect_with_notice( $ok
			? [ 'updated', __( 'Batch moved to the trash, with its entries and tasks.', 'bluefairy-brew-tracker' ) ]
			: [ 'error', __( 'That batch could not be moved to the trash.', 'bluefairy-brew-tracker' ) ] );
	}

	public static function restore_batch(): void {
		self::guard( self::CAP_DESTROY );

		$batch_id = self::request_int( 'batch_id' );

		check_admin_referer( 'bfbt_restore_batch_' . $batch_id );

		$ok = BFBT_Batch::is_batch( $batch_id ) && 'trash' === get_post_status( $batch_id ) && wp_untrash_post( $batch_id );

		self::redirect_with_notice(
			$ok
				? [ 'updated', __( 'Batch restored, with its entries and tasks.', 'bluefairy-brew-tracker' ) ]
				: [ 'error', __( 'That batch could not be restored.', 'bluefairy-brew-tracker' ) ],
			$ok ? [] : [ 'view' => 'trash' ]
		);
	}

	public static function delete_batch(): void {
		self::guard( self::CAP_DESTROY );

		$batch_id = self::request_int( 'batch_id' );

		check_admin_referer( 'bfbt_delete_batch_' . $batch_id );

		$ok = BFBT_Batch::is_batch( $batch_id ) && 'trash' === get_post_status( $batch_id ) && wp_delete_post( $batch_id, true );

		self::redirect_with_notice(
			$ok
				? [ 'updated', __( 'Batch deleted permanently, with its entries and tasks.', 'bluefairy-brew-tracker' ) ]
				: [ 'error', __( 'Only a batch in the trash can be deleted permanently.', 'bluefairy-brew-tracker' ) ],
			[ 'view' => 'trash' ]
		);
	}

	/*
	 * ----------------------------------------------------------------------
	 * Handlers — log
	 * ----------------------------------------------------------------------
	 */

	public static function add_log(): void {
		self::guard( self::CAP_RECORD );

		$batch_id = self::post_int( 'batch_id' );

		check_admin_referer( 'bfbt_add_log_' . $batch_id );

		if ( ! BFBT_Batch::is_batch( $batch_id ) ) {
			self::redirect_with_notice( [ 'error', __( 'That batch no longer exists.', 'bluefairy-brew-tracker' ) ] );
		}

		// Entries are immutable. A posted entry id is not an edit; it is refused.
		if ( self::post_int( 'log_id' ) > 0 ) {
			self::redirect_with_notice( [ 'error', __( 'Entries cannot be edited. Trash the entry and add it again.', 'bluefairy-brew-tracker' ) ], [ 'view' => 'view', 'batch_id' => $batch_id ] );
		}

		$typed = [
			'log_type'        => self::post_text( 'log_type' ),
			'time'            => self::post_text( 'time' ),
			'ingredient'      => self::post_text( 'ingredient' ),
			'qty'             => self::post_text( 'qty' ),
			'unit'            => self::post_text( 'unit' ),
			'sg'              => self::post_text( 'sg' ),
			'temp'            => self::post_text( 'temp' ),
			'temp_unit'       => self::post_text( 'temp_unit' ),
			'ph'              => self::post_text( 'ph' ),
			'volume_out'      => self::post_text( 'volume_out' ),
			'volume_out_unit' => self::post_text( 'volume_out_unit' ),
			'label'           => self::post_text( 'label' ),
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
			'note'            => isset( $_POST['note'] ) ? wp_kses_post( wp_unslash( (string) $_POST['note'] ) ) : '',
		];

		$data         = $typed;
		$data['type'] = $typed['log_type'];
		// A form that predates the unit select still posts temp_c, in °C.
		$data['temp_c'] = self::post_text( 'temp_c' );
		unset( $data['log_type'] );

		// Photos are counted before anything is written, so an over-long
		// set refuses the whole save; they are stored after, so a photo that
		// fails does not lose the reading. A browser never re-fills a file
		// input, so a refused form comes back with every field but these.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$photos   = BFBT_Photos::split( $_FILES[ BFBT_Photos::FIELD ] ?? [] );
		$too_many = BFBT_Photos::too_many( $photos );

		if ( $too_many ) {
			self::remember_input( 'log', $typed );
			self::redirect_with_notice( [ 'error', $too_many->get_error_message() ], [ 'view' => 'view', 'batch_id' => $batch_id ] );
		}

		$result = BFBT_Log::create( $batch_id, $data );

		if ( is_wp_error( $result ) ) {
			self::remember_input( 'log', $typed );
			self::redirect_with_notice( [ 'error', $result->get_error_message() ], [ 'view' => 'view', 'batch_id' => $batch_id ] );
		}

		self::redirect_with_notice( self::entry_added_notice( BFBT_Photos::attach( $result, $photos ) ), [ 'view' => 'view', 'batch_id' => $batch_id ] );
	}

	/**
	 * "Entry added." — with its photo count, and with every photo that was
	 * not saved named, because the entry exists either way.
	 *
	 * @param  array{ids: int[], errors: string[]} $outcome From BFBT_Photos::attach().
	 * @return array{0: string, 1: string} [ 'updated' | 'error', message ]
	 */
	public static function entry_added_notice( array $outcome ): array {
		$saved  = count( $outcome['ids'] );
		$errors = $outcome['errors'];

		if ( ! $errors ) {
			return [
				'updated',
				$saved > 0
					/* translators: %d: how many photos were saved with the entry */
					? sprintf( _n( 'Entry added with %d photo.', 'Entry added with %d photos.', $saved, 'bluefairy-brew-tracker' ), $saved )
					: __( 'Entry added.', 'bluefairy-brew-tracker' ),
			];
		}

		return [
			'error',
			sprintf(
				/* translators: 1: how many photos were not saved, 2: each failure as “file”: reason */
				_n(
					'Entry added, but %1$d photo was not saved. %2$s',
					'Entry added, but %1$d photos were not saved. %2$s',
					count( $errors ),
					'bluefairy-brew-tracker'
				),
				count( $errors ),
				implode( ' ', $errors )
			),
		];
	}

	public static function trash_log(): void {
		self::guard( self::CAP_DESTROY );

		$log_id = self::post_int( 'log_id' );

		check_admin_referer( 'bfbt_trash_log_' . $log_id );

		$post     = BFBT_Log::is_log( $log_id ) ? get_post( $log_id ) : null;
		$batch_id = $post ? (int) $post->post_parent : 0;
		$result   = BFBT_Log::trash( $log_id );

		self::redirect_with_notice(
			is_wp_error( $result )
				? [ 'error', $result->get_error_message() ]
				: [ 'updated', __( 'Entry moved to the trash.', 'bluefairy-brew-tracker' ) ],
			$batch_id > 0 ? [ 'view' => 'view', 'batch_id' => $batch_id ] : []
		);
	}

	public static function pin_og(): void {
		self::guard( self::CAP_RECORD );

		$batch_id = self::post_int( 'batch_id' );

		check_admin_referer( 'bfbt_pin_og_' . $batch_id );

		if ( ! BFBT_Batch::is_batch( $batch_id ) ) {
			self::redirect_with_notice( [ 'error', __( 'That batch no longer exists.', 'bluefairy-brew-tracker' ) ] );
		}

		$result = BFBT_Log::pin_as_og( $batch_id, self::post_int( 'log_id' ) );

		self::redirect_with_notice(
			is_wp_error( $result )
				? [ 'error', $result->get_error_message() ]
				: [ 'updated', __( 'That reading is now the original gravity.', 'bluefairy-brew-tracker' ) ],
			[ 'view' => 'view', 'batch_id' => $batch_id ]
		);
	}

	/*
	 * ----------------------------------------------------------------------
	 * Handlers — task
	 * ----------------------------------------------------------------------
	 */

	public static function save_task(): void {
		self::guard( self::CAP_RECORD );

		$batch_id = self::post_int( 'batch_id' );

		check_admin_referer( 'bfbt_add_task_' . $batch_id );

		if ( ! BFBT_Batch::is_batch( $batch_id ) ) {
			self::redirect_with_notice( [ 'error', __( 'That batch no longer exists.', 'bluefairy-brew-tracker' ) ] );
		}

		$typed = [
			'task_type'  => self::post_text( 'task_type' ),
			'title'      => self::post_text( 'title' ),
			'due'        => self::post_text( 'due' ),
			'ingredient' => self::post_text( 'ingredient' ),
			'qty'        => self::post_text( 'qty' ),
			'unit'       => self::post_text( 'unit' ),
		];

		$result = BFBT_Task::create( $batch_id, [ 'type' => $typed['task_type'] ] + $typed );

		if ( is_wp_error( $result ) ) {
			self::remember_input( 'task', $typed );
			self::redirect_with_notice( [ 'error', $result->get_error_message() ], [ 'view' => 'view', 'batch_id' => $batch_id ] );
		}

		self::redirect_with_notice( [ 'updated', __( 'Task added.', 'bluefairy-brew-tracker' ) ], [ 'view' => 'view', 'batch_id' => $batch_id ] );
	}

	public static function complete_task(): void {
		self::guard( self::CAP_RECORD );

		$task_id = self::post_int( 'task_id' );

		check_admin_referer( 'bfbt_complete_task_' . $task_id );

		$batch_id = self::task_batch( $task_id );
		$result   = BFBT_Task::complete( $task_id );

		self::redirect_with_notice(
			is_wp_error( $result )
				? [ 'error', $result->get_error_message() ]
				: [ 'updated', __( 'Done — logged to the timeline.', 'bluefairy-brew-tracker' ) ],
			$batch_id > 0 ? [ 'view' => 'view', 'batch_id' => $batch_id ] : []
		);
	}

	public static function uncomplete_task(): void {
		self::guard( self::CAP_RECORD );

		$task_id = self::post_int( 'task_id' );

		check_admin_referer( 'bfbt_complete_task_' . $task_id );

		$batch_id = self::task_batch( $task_id );
		$result   = BFBT_Task::uncomplete( $task_id );

		self::redirect_with_notice(
			is_wp_error( $result )
				? [ 'error', $result->get_error_message() ]
				: [ 'updated', __( 'Task reopened. The entry it logged is still on the timeline; trash it if it was a mistake.', 'bluefairy-brew-tracker' ) ],
			$batch_id > 0 ? [ 'view' => 'view', 'batch_id' => $batch_id ] : []
		);
	}

	private static function task_batch( int $task_id ): int {
		$post = BFBT_Task::is_task( $task_id ) ? get_post( $task_id ) : null;

		return $post ? (int) $post->post_parent : 0;
	}
}
