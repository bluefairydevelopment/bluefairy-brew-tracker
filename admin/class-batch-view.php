<?php
/**
 * One batch: header, chart, due panel, entry and task forms, timeline,
 * milestones.
 *
 * Read-only apart from the forms it prints; every form posts to a handler
 * in BFBT_Batch_Admin. ABV and attenuation are derived here at read time
 * from the pinned OG entry and the latest reading, and rendered with their
 * provenance so a trashed or re-dated entry changes a visible number.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class BFBT_Batch_View {

	public const TIMELINE_PER_PAGE = 50;

	public static function render( int $batch_id ): void {
		$today = wp_date( 'Y-m-d' );

		self::header( $batch_id, $today );

		echo '<div class="bfbt-columns">';
		echo '<div class="bfbt-main">';
		self::chart( $batch_id );
		self::entry_form( $batch_id );
		self::timeline( $batch_id );
		echo '</div>';

		echo '<div class="bfbt-side">';
		self::due_panel( $batch_id, $today );
		self::task_form( $batch_id );
		self::milestones( $batch_id );
		echo '</div>';
		echo '</div>';
	}

	private static function header( int $batch_id, string $today ): void {
		$stage = BFBT_Stage::get( $batch_id );
		$since = BFBT_Stage::since( $batch_id );

		printf(
			'<h1 class="wp-heading-inline">%s</h1> %s <a href="%s" class="page-title-action">%s</a> <a href="%s" class="page-title-action">%s</a><hr class="wp-header-end">',
			esc_html( get_the_title( $batch_id ) ),
			BFBT_Stage::badge( $stage ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside badge().
			esc_url( BFBT_Batch_Admin::page_url( [ 'view' => 'edit', 'batch_id' => $batch_id ] ) ),
			esc_html__( 'Edit', 'bluefairy-brew-tracker' ),
			esc_url( BFBT_Batch_Admin::page_url() ),
			esc_html__( 'All batches', 'bluefairy-brew-tracker' )
		);

		$facts = [];

		$style = BFBT_Batch::style_name( $batch_id );
		if ( '' !== $style ) {
			$facts[] = $style;
		}

		$vessel = BFBT_Batch::vessel_name( $batch_id );
		if ( '' !== $vessel ) {
			$facts[] = $vessel;
		}

		$volume = (string) BFBT_Batch::get( $batch_id, 'volume_entered' );
		$litres = (string) BFBT_Batch::get( $batch_id, 'volume_l' );
		if ( '' !== $litres ) {
			$facts[] = $volume . ' (' . BFBT_Units::display_volume( (float) $litres ) . ')';
		}

		$started = (string) BFBT_Batch::get( $batch_id, 'started' );
		if ( '' !== $started ) {
			/* translators: %s: date */
			$facts[] = sprintf( __( 'started %s', 'bluefairy-brew-tracker' ), $started );
		}

		if ( '' !== $since ) {
			$days = (int) floor( ( strtotime( $today ) - strtotime( $since ) ) / DAY_IN_SECONDS );
			/* translators: 1: number of days, 2: stage label */
			$facts[] = sprintf( _n( '%1$d day in %2$s', '%1$d days in %2$s', $days, 'bluefairy-brew-tracker' ), $days, BFBT_Stage::label( $stage ) );
		}

		if ( $facts ) {
			echo '<p class="bfbt-facts">' . esc_html( implode( ' · ', $facts ) ) . '</p>';
		}

		self::abv_block( $batch_id );

		$notes = (string) get_post_field( 'post_content', $batch_id );

		if ( '' !== trim( $notes ) ) {
			echo '<div class="bfbt-notes">' . wp_kses_post( wpautop( $notes ) ) . '</div>';
		}

		echo '<div class="bfbt-actions">';
		/**
		 * Action controls for one batch. Add-ons print their buttons here.
		 *
		 * @param int $batch_id
		 */
		do_action( 'bfbt_batch_actions', $batch_id );
		echo '</div>';
	}

	private static function abv_block( int $batch_id ): void {
		$og_id  = absint( BFBT_Batch::get( $batch_id, 'og_log_id' ) );
		$og_sg  = $og_id > 0 && BFBT_Log::is_log( $og_id ) && 'trash' !== get_post_status( $og_id ) ? BFBT_Log::field( $og_id, 'sg' ) : '';
		$latest = BFBT_Log::latest_gravity( $batch_id );

		echo '<div class="bfbt-abv">';

		// The latest reading being the pinned OG itself is one reading, not
		// two: "ABV 0.0 %" from OG − OG would be a number that measured nothing.
		if ( '' === $og_sg || null === $latest || (int) $latest['id'] === $og_id ) {
			echo '<p class="description">' . esc_html__( 'ABV appears once there is an original gravity reading and a later one.', 'bluefairy-brew-tracker' ) . '</p></div>';

			return;
		}

		$og = (float) $og_sg;
		$fg = (float) $latest['sg'];

		$og_post = get_post( $og_id );
		$og_date = $og_post ? substr( (string) $og_post->post_date, 0, 10 ) : '';

		printf(
			'<p class="bfbt-abv-figures"><strong>%s</strong> · %s</p>',
			esc_html( sprintf(
				/* translators: %s: per cent */
				__( 'ABV %s %%', 'bluefairy-brew-tracker' ),
				number_format( BFBT_ABV::from_gravities( $og, $fg ), 1 )
			) ),
			esc_html( sprintf(
				/* translators: %s: per cent */
				__( 'apparent attenuation %s %%', 'bluefairy-brew-tracker' ),
				number_format( BFBT_ABV::attenuation( $og, $fg ), 0 )
			) )
		);

		printf(
			'<p class="description">%s<br>%s</p>',
			esc_html( sprintf(
				/* translators: 1: OG, 2: date of the OG reading, 3: FG, 4: date of the latest reading */
				__( 'OG %1$s, reading of %2$s · FG %3$s, reading of %4$s', 'bluefairy-brew-tracker' ),
				$og_sg,
				$og_date,
				$latest['sg'],
				$latest['date']
			) ),
			esc_html( BFBT_ABV::formula_name( $og ) )
		);

		echo '</div>';
	}

	private static function chart( int $batch_id ): void {
		echo '<h2>' . esc_html__( 'Gravity', 'bluefairy-brew-tracker' ) . '</h2>';
		echo '<div class="bfbt-chart-wrap">' . BFBT_Chart::svg( BFBT_Log::gravity_series( $batch_id ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every number cast and every label escaped inside svg().
	}

	private static function due_panel( int $batch_id, string $today ): void {
		echo '<div class="bfbt-panel bfbt-due-panel"><h2>' . esc_html__( 'Due', 'bluefairy-brew-tracker' ) . '</h2>';

		$open = BFBT_Task::open_for_batch( $batch_id );

		if ( ! $open ) {
			echo '<p class="description">' . esc_html__( 'Nothing scheduled. Add a task below.', 'bluefairy-brew-tracker' ) . '</p>';
		} else {
			echo '<ul class="bfbt-tasks">';

			foreach ( $open as $task ) {
				$id  = (int) $task->ID;
				$due = BFBT_Task::field( $id, 'due' );

				if ( BFBT_Task::NO_DATE === $due ) {
					$when  = __( 'no date', 'bluefairy-brew-tracker' );
					$class = 'bfbt-undated';
				} elseif ( strcmp( $due, $today ) < 0 ) {
					$when  = $due;
					$class = 'bfbt-overdue';
				} elseif ( $due === $today ) {
					$when  = __( 'today', 'bluefairy-brew-tracker' );
					$class = 'bfbt-today';
				} else {
					$when  = $due;
					$class = '';
				}

				printf(
					'<li class="%s"><span class="bfbt-due">%s</span> <span class="bfbt-task-title">%s</span> %s</li>',
					esc_attr( trim( 'bfbt-task ' . $class ) ),
					esc_html( $when ),
					esc_html( get_the_title( $id ) ),
					self::task_button( 'bfbt_complete_task', $id, __( 'Done', 'bluefairy-brew-tracker' ), 'button button-small button-primary' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside task_button().
				);
			}

			echo '</ul>';
		}

		$done = BFBT_Task::recently_done_for_batch( $batch_id, 7 );

		if ( $done ) {
			echo '<h3>' . esc_html__( 'Done in the last 7 days', 'bluefairy-brew-tracker' ) . '</h3><ul class="bfbt-tasks bfbt-done">';

			foreach ( $done as $task ) {
				$id = (int) $task->ID;

				printf(
					'<li class="bfbt-task"><span class="bfbt-due">%s</span> <span class="bfbt-task-title">%s</span> %s</li>',
					esc_html( substr( BFBT_Task::field( $id, 'done_at' ), 0, 10 ) ),
					esc_html( get_the_title( $id ) ),
					self::task_button( 'bfbt_uncomplete_task', $id, __( 'Undo', 'bluefairy-brew-tracker' ), 'button button-small' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside task_button().
				);
			}

			echo '</ul>';
		}

		echo '</div>';
	}

	/**
	 * A one-button form to a task handler. Both handlers share the
	 * bfbt_complete_task_{id} nonce: they are the two directions of one act.
	 */
	private static function task_button( string $action, int $task_id, string $label, string $class ): string {
		return sprintf(
			'<form method="post" action="%s" class="bfbt-inline-form">%s<input type="hidden" name="action" value="%s"><input type="hidden" name="task_id" value="%d"><button type="submit" class="%s">%s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'bfbt_complete_task_' . $task_id, '_wpnonce', true, false ),
			esc_attr( $action ),
			absint( $task_id ),
			esc_attr( $class ),
			esc_html( $label )
		);
	}

	private static function entry_form( int $batch_id ): void {
		$old      = static fn( string $key, string $default = '' ): string => BFBT_Batch_Admin::old( 'log', $key, $default );
		$selected = $old( 'log_type', 'addition' );

		// A fieldset the chosen type does not use is hidden and its inputs
		// disabled, so two fieldsets can share a field name (temp on gravity
		// and on temperature) and only the visible one posts. The script
		// keeps this in step when the type changes.
		$fieldset = static fn( string $type ): string => sprintf(
			'<fieldset class="bfbt-type-fields%s" data-for="log" data-type="%s">',
			$type === $selected ? ' bfbt-active' : '',
			esc_attr( $type )
		);
		$disabled = static fn( string $type ): string => $type === $selected ? '' : ' disabled';

		echo '<div class="bfbt-panel"><h2>' . esc_html__( 'Add entry', 'bluefairy-brew-tracker' ) . '</h2>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bfbt-entry-form">';
		wp_nonce_field( 'bfbt_add_log_' . $batch_id );
		echo '<input type="hidden" name="action" value="bfbt_add_log">';
		printf( '<input type="hidden" name="batch_id" value="%d">', absint( $batch_id ) );

		echo '<p><label for="bfbt-log-type">' . esc_html__( 'Type', 'bluefairy-brew-tracker' ) . '</label> ';
		echo '<select id="bfbt-log-type" name="log_type" class="bfbt-type-switch" data-target="log">';

		$labels = BFBT_Log::type_labels();

		foreach ( BFBT_Log::types() as $type ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $type ), selected( $selected, $type, false ), esc_html( $labels[ $type ] ?? ucfirst( str_replace( '_', ' ', $type ) ) ) );
		}

		echo '</select> ';
		printf(
			'<label for="bfbt-log-time">%s</label> <input type="text" id="bfbt-log-time" name="time" value="%s" class="regular-text" placeholder="YYYY-MM-DD HH:MM"></p>',
			esc_html__( 'When', 'bluefairy-brew-tracker' ),
			esc_attr( $old( 'time', wp_date( 'Y-m-d H:i' ) ) )
		);

		// Addition.
		echo $fieldset( 'addition' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the closure.
		printf( '<p><label>%s <input type="text" name="ingredient" value="%s" class="regular-text" placeholder="%s"></label></p>', esc_html__( 'Ingredient', 'bluefairy-brew-tracker' ), esc_attr( $old( 'ingredient' ) ), esc_attr__( 'Fermaid-O', 'bluefairy-brew-tracker' ) );
		printf( '<p><label>%s <input type="text" name="qty" value="%s" class="small-text"></label> ', esc_html__( 'Quantity', 'bluefairy-brew-tracker' ), esc_attr( $old( 'qty' ) ) );
		BFBT_Batch_Admin::unit_select( 'unit', $old( 'unit', BFBT_Units::mass_unit() ), BFBT_Validate::UNITS );
		echo '</p></fieldset>';

		$temp      = $old( 'temp' );
		$temp_unit = $old( 'temp_unit', BFBT_Units::temperature_unit() );

		// Gravity.
		echo $fieldset( 'gravity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf( '<p><label>%s <input type="text" name="sg" value="%s" class="small-text" placeholder="1.090"%s></label> ', esc_html__( 'SG', 'bluefairy-brew-tracker' ), esc_attr( $old( 'sg' ) ), $disabled( 'gravity' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a literal attribute.
		printf( '<label>%s <input type="text" name="temp" value="%s" class="small-text"%s></label> ', esc_html__( 'Temp (optional)', 'bluefairy-brew-tracker' ), esc_attr( $temp ), $disabled( 'gravity' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::temperature_unit_select( $temp_unit, $disabled( 'gravity' ) );
		echo '</p></fieldset>';

		// Temperature.
		echo $fieldset( 'temperature' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf( '<p><label>%s <input type="text" name="temp" value="%s" class="small-text"%s></label> ', esc_html__( 'Temp', 'bluefairy-brew-tracker' ), esc_attr( $temp ), $disabled( 'temperature' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::temperature_unit_select( $temp_unit, $disabled( 'temperature' ) );
		echo '</p></fieldset>';

		// pH.
		echo $fieldset( 'ph' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf( '<p><label>%s <input type="text" name="ph" value="%s" class="small-text" placeholder="3.4"></label></p>', esc_html__( 'pH', 'bluefairy-brew-tracker' ), esc_attr( $old( 'ph' ) ) );
		echo '</fieldset>';

		// Racking.
		echo $fieldset( 'racking' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf( '<p><label>%s <input type="text" name="volume_out" value="%s" class="small-text"></label> ', esc_html__( 'Volume across (optional)', 'bluefairy-brew-tracker' ), esc_attr( $old( 'volume_out' ) ) );
		BFBT_Batch_Admin::unit_select( 'volume_out_unit', $old( 'volume_out_unit', BFBT_Units::volume_unit() ), BFBT_Units::volume_units() );
		echo '</p></fieldset>';

		// Stage.
		echo $fieldset( 'stage' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf( '<p><label>%s <input type="text" name="label" value="%s" class="regular-text"></label></p>', esc_html__( 'Label', 'bluefairy-brew-tracker' ), esc_attr( $old( 'label' ) ) );
		echo '</fieldset>';

		printf( '<p><label for="bfbt-log-note">%s</label><br><textarea id="bfbt-log-note" name="note" rows="3" class="large-text">%s</textarea></p>', esc_html__( 'Note', 'bluefairy-brew-tracker' ), esc_textarea( $old( 'note' ) ) );

		// A file input is never re-filled by the browser, so it is the one
		// field a refused save cannot give back; the description says so.
		printf(
			'<p><label for="bfbt-log-photos">%s</label><br><input type="file" id="bfbt-log-photos" name="%s[]" accept="image/*" multiple> <span class="description">%s</span></p>',
			esc_html(
				/* translators: %d: the most photos an entry takes */
				sprintf( __( 'Photos (optional, up to %d)', 'bluefairy-brew-tracker' ), BFBT_Photos::MAX )
			),
			esc_attr( BFBT_Photos::FIELD ),
			esc_html__( 'Images only. If the save is refused, attach them again.', 'bluefairy-brew-tracker' )
		);

		submit_button( __( 'Add entry', 'bluefairy-brew-tracker' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * The °C / °F select beside a temperature field. It opens on the unit
	 * the display setting implies; the unit picked here is the one stored.
	 */
	private static function temperature_unit_select( string $selected, string $attributes = '' ): void {
		printf( '<select name="temp_unit"%s>', $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a literal attribute.

		foreach ( BFBT_Units::temperature_units() as $unit ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $unit ), selected( $selected, $unit, false ), esc_html( BFBT_Units::temperature_label( $unit ) ) );
		}

		echo '</select>';
	}

	private static function task_form( int $batch_id ): void {
		$old      = static fn( string $key, string $default = '' ): string => BFBT_Batch_Admin::old( 'task', $key, $default );
		$selected = $old( 'task_type', 'addition' );

		echo '<div class="bfbt-panel"><h2>' . esc_html__( 'Add task', 'bluefairy-brew-tracker' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bfbt-task-form">';
		wp_nonce_field( 'bfbt_add_task_' . $batch_id );
		echo '<input type="hidden" name="action" value="bfbt_save_task">';
		printf( '<input type="hidden" name="batch_id" value="%d">', absint( $batch_id ) );

		echo '<p><label for="bfbt-task-type">' . esc_html__( 'Type', 'bluefairy-brew-tracker' ) . '</label> ';
		echo '<select id="bfbt-task-type" name="task_type" class="bfbt-type-switch" data-target="task">';

		$labels = BFBT_Task::type_labels();

		foreach ( BFBT_Task::types() as $type ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $type ), selected( $selected, $type, false ), esc_html( $labels[ $type ] ?? ucfirst( str_replace( '_', ' ', $type ) ) ) );
		}

		echo '</select></p>';

		printf( '<fieldset class="bfbt-type-fields%s" data-for="task" data-type="addition">', 'addition' === $selected ? ' bfbt-active' : '' );
		printf( '<p><label>%s <input type="text" name="ingredient" value="%s" class="regular-text" placeholder="%s"></label></p>', esc_html__( 'Ingredient', 'bluefairy-brew-tracker' ), esc_attr( $old( 'ingredient' ) ), esc_attr__( 'Fermaid-O', 'bluefairy-brew-tracker' ) );
		printf( '<p><label>%s <input type="text" name="qty" value="%s" class="small-text"></label> ', esc_html__( 'Quantity', 'bluefairy-brew-tracker' ), esc_attr( $old( 'qty' ) ) );
		BFBT_Batch_Admin::unit_select( 'unit', $old( 'unit', BFBT_Units::mass_unit() ), BFBT_Validate::UNITS );
		echo '</p><p class="description">' . esc_html__( 'Leave the quantity blank and put the instruction in the title — "water to 1 gal".', 'bluefairy-brew-tracker' ) . '</p></fieldset>';

		printf( '<p><label>%s <input type="text" name="title" value="%s" class="regular-text"></label></p>', esc_html__( 'Title (optional)', 'bluefairy-brew-tracker' ), esc_attr( $old( 'title' ) ) );
		printf( '<p><label>%s <input type="date" name="due" value="%s"></label> <span class="description">%s</span></p>', esc_html__( 'Due', 'bluefairy-brew-tracker' ), esc_attr( $old( 'due' ) ), esc_html__( 'Blank for a task with no date.', 'bluefairy-brew-tracker' ) );

		submit_button( __( 'Add task', 'bluefairy-brew-tracker' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	private static function timeline( int $batch_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a page number for a read-only list.
		$page = isset( $_GET['lpage'] ) ? max( 1, absint( wp_unslash( (string) $_GET['lpage'] ) ) ) : 1;

		$result  = BFBT_Log::for_batch( $batch_id, $page, self::TIMELINE_PER_PAGE );
		$og_id   = absint( BFBT_Batch::get( $batch_id, 'og_log_id' ) );
		$destroy = current_user_can( BFBT_Batch_Admin::CAP_DESTROY );
		$labels  = BFBT_Log::type_labels();

		echo '<h2>' . esc_html__( 'Timeline', 'bluefairy-brew-tracker' ) . '</h2>';

		if ( ! $result['items'] ) {
			echo '<p class="description">' . esc_html__( 'Nothing logged yet.', 'bluefairy-brew-tracker' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped bfbt-timeline"><thead><tr>';
		printf( '<th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th></th>', esc_html__( 'When', 'bluefairy-brew-tracker' ), esc_html__( 'Type', 'bluefairy-brew-tracker' ), esc_html__( 'Entry', 'bluefairy-brew-tracker' ), esc_html__( 'Note', 'bluefairy-brew-tracker' ), esc_html__( 'Photos', 'bluefairy-brew-tracker' ) );
		echo '</tr></thead><tbody>';

		foreach ( $result['items'] as $entry ) {
			$id      = (int) $entry->ID;
			$type    = BFBT_Log::field( $id, 'type' );
			$title   = (string) $entry->post_title;
			$is_og   = $og_id === $id;
			$content = trim( wp_strip_all_tags( (string) $entry->post_content ) );

			if ( 'addition' === $type ) {
				$ingredient = BFBT_Log::field( $id, 'ingredient' );
				$title      = BFBT_Log::ingredient_label( $ingredient ) . substr( $title, strlen( $ingredient ) );
			}

			$title .= self::converted_temperature( $id );

			$controls = '';

			if ( 'gravity' === $type ) {
				$controls .= $is_og
					? '<span class="bfbt-og-pin">' . esc_html__( 'OG', 'bluefairy-brew-tracker' ) . '</span> '
					: sprintf(
						'<form method="post" action="%s" class="bfbt-inline-form">%s<input type="hidden" name="action" value="bfbt_pin_og"><input type="hidden" name="batch_id" value="%d"><input type="hidden" name="log_id" value="%d"><button type="submit" class="button-link">%s</button></form> ',
						esc_url( admin_url( 'admin-post.php' ) ),
						wp_nonce_field( 'bfbt_pin_og_' . $batch_id, '_wpnonce', true, false ),
						absint( $batch_id ),
						absint( $id ),
						esc_html__( 'Use as OG', 'bluefairy-brew-tracker' )
					);
			}

			if ( $destroy ) {
				$controls .= sprintf(
					'<form method="post" action="%s" class="bfbt-inline-form" onsubmit="return confirm(\'%s\')">%s<input type="hidden" name="action" value="bfbt_trash_log"><input type="hidden" name="log_id" value="%d"><button type="submit" class="button-link submitdelete">%s</button></form>',
					esc_url( admin_url( 'admin-post.php' ) ),
					esc_js( __( 'Move this entry to the trash?', 'bluefairy-brew-tracker' ) ),
					wp_nonce_field( 'bfbt_trash_log_' . $id, '_wpnonce', true, false ),
					absint( $id ),
					esc_html__( 'Trash', 'bluefairy-brew-tracker' )
				);
			}

			printf(
				'<tr class="bfbt-entry bfbt-entry-%s"><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class="bfbt-photos">%s</td><td class="bfbt-entry-controls">%s</td></tr>',
				esc_attr( sanitize_key( $type ) ),
				esc_html( substr( (string) $entry->post_date, 0, 16 ) ),
				esc_html( $labels[ $type ] ?? ucfirst( str_replace( '_', ' ', $type ) ) ),
				esc_html( $title ),
				esc_html( mb_strlen( $content ) > 120 ? mb_substr( $content, 0, 117 ) . '…' : $content ),
				self::photo_thumbnails( $id ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				$controls // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
			);
		}

		echo '</tbody></table>';

		if ( $result['pages'] > 1 ) {
			echo '<p class="bfbt-timeline-pages">';

			for ( $n = 1; $n <= $result['pages']; $n++ ) {
				if ( $n === $page ) {
					printf( '<span class="current">%d</span> ', absint( $n ) );
				} else {
					printf( '<a href="%s">%d</a> ', esc_url( BFBT_Batch_Admin::page_url( [ 'view' => 'view', 'batch_id' => $batch_id, 'lpage' => $n ] ) ), absint( $n ) );
				}
			}

			echo '</p>';
		}
	}

	/**
	 * Each photo of an entry as a thumbnail linking to the full image, in
	 * the order they were attached; '' for an entry without any.
	 */
	private static function photo_thumbnails( int $log_id ): string {
		$html = '';

		foreach ( BFBT_Photos::for_log( $log_id ) as $attachment_id ) {
			$full  = wp_get_attachment_url( $attachment_id );
			$thumb = wp_get_attachment_image( $attachment_id, [ 48, 48 ], false, [ 'class' => 'bfbt-photo-thumb', 'loading' => 'lazy' ] );

			if ( ! is_string( $full ) || '' === $full || '' === $thumb ) {
				continue;
			}

			$html .= sprintf( '<a href="%s" class="bfbt-photo" target="_blank" rel="noopener">%s</a>', esc_url( $full ), $thumb );
		}

		return $html;
	}

	/**
	 * " (23.3 °C)" for an entry whose temperature was typed in a unit other
	 * than the one the display setting implies; '' otherwise. The title
	 * already carries the value as typed — that never changes — and this is
	 * the same reading in the unit the maker now works in.
	 */
	private static function converted_temperature( int $log_id ): string {
		$celsius = BFBT_Log::field( $log_id, 'temp_c' );

		if ( '' === $celsius ) {
			return '';
		}

		$entered_unit = BFBT_Log::entered_temperature_unit( BFBT_Log::field( $log_id, 'temp_entered' ) );

		return $entered_unit === BFBT_Units::temperature_unit()
			? ''
			: ' (' . BFBT_Units::display_temperature( (float) $celsius ) . ')';
	}

	private static function milestones( int $batch_id ): void {
		$old = static fn( string $key, string $default = '' ): string => BFBT_Batch_Admin::old( 'milestones', $key, $default );

		echo '<div class="bfbt-panel"><h2>' . esc_html__( 'Milestones', 'bluefairy-brew-tracker' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bfbt_edit_batch_' . $batch_id );
		echo '<input type="hidden" name="action" value="bfbt_save_batch"><input type="hidden" name="section" value="milestones">';
		printf( '<input type="hidden" name="batch_id" value="%d">', absint( $batch_id ) );

		foreach ( [
			'secondary_date' => __( 'Racked to secondary', 'bluefairy-brew-tracker' ),
			'packaged_date'  => __( 'Packaged', 'bluefairy-brew-tracker' ),
			'completed_date' => __( 'Completed', 'bluefairy-brew-tracker' ),
		] as $field => $label ) {
			printf(
				'<p><label for="bfbt-%1$s">%2$s</label><br><input type="date" id="bfbt-%1$s" name="%1$s" value="%3$s"></p>',
				esc_attr( $field ),
				esc_html( $label ),
				esc_attr( $old( $field, (string) BFBT_Batch::get( $batch_id, $field ) ) )
			);
		}

		printf(
			'<p><label><input type="checkbox" name="dumped" value="1"%s> %s</label></p>',
			checked( '' !== $old( 'dumped', (string) BFBT_Batch::get( $batch_id, 'dumped' ) ), true, false ),
			esc_html__( 'Dumped', 'bluefairy-brew-tracker' )
		);

		submit_button( __( 'Save milestones', 'bluefairy-brew-tracker' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}
}
