<?php
/**
 * The checks that need a real WordPress.
 *
 *     wp eval-file wp-content/plugins/bluefairy-brew-tracker/tests/integration-checks.php
 *
 * Every check guards a claim in FEATURES.md or a finding in the plan's design
 * review, so a change that breaks one is recognisable rather than merely red.
 * Everything created here is deleted before the script ends; nothing of the
 * maker's is touched.
 *
 * No declare(strict_types=1): wp eval-file evaluates this rather than including
 * it, and a declare must be a script's first statement.
 *
 * @package BlueFairy\BrewTracker
 */

$bfbt_failures = 0;
$bfbt_checks   = 0;

$check = static function ( string $label, bool $passed, string $detail = '' ) use ( &$bfbt_failures, &$bfbt_checks ): void {
	++$bfbt_checks;

	if ( ! $passed ) {
		++$bfbt_failures;
	}

	printf( "  [%s] %s%s\n", $passed ? 'ok  ' : 'FAIL', $label, '' !== $detail ? "  ({$detail})" : '' );
};

$bfbt_cleanup = [];

echo "\n== the base plugin owns the record ==\n";
$check( 'the marker function answers the version', function_exists( 'bfbt_plugin' ) && bfbt_plugin() === BFBT_VERSION, function_exists( 'bfbt_plugin' ) ? bfbt_plugin() : 'missing' );
$check( 'the batch post type is registered', post_type_exists( BFBT_Batch::POST_TYPE ) );
$check( 'the log post type is registered', post_type_exists( BFBT_Batch::LOG_TYPE ) );
$check( 'the task post type is registered', post_type_exists( BFBT_Batch::TASK_TYPE ) );
$check( 'the style taxonomy is registered and hierarchical', taxonomy_exists( BFBT_Batch::STYLE_TAX ) && is_taxonomy_hierarchical( BFBT_Batch::STYLE_TAX ) );
$check( 'the vessel taxonomy is registered', taxonomy_exists( BFBT_Batch::VESSEL_TAX ) );

foreach ( [ BFBT_Batch::POST_TYPE, BFBT_Batch::LOG_TYPE, BFBT_Batch::TASK_TYPE ] as $bfbt_type ) {
	$bfbt_object = get_post_type_object( $bfbt_type );
	$check( "{$bfbt_type} is not public, not in the UI, not in REST", $bfbt_object && ! $bfbt_object->public && ! $bfbt_object->show_ui && ! $bfbt_object->show_in_rest );
}

echo "\n== the style tree is seeded once, twenty-four terms ==\n";
$bfbt_styles = get_terms( [ 'taxonomy' => BFBT_Batch::STYLE_TAX, 'hide_empty' => false, 'fields' => 'ids' ] );
$bfbt_count  = is_array( $bfbt_styles ) ? count( $bfbt_styles ) : -1;
$check( 'twenty-four style terms exist', 24 === $bfbt_count, $bfbt_count . ' found' );
$check( 'the seeded marker is set', '1' === (string) get_option( BFBT_Seeder::OPTION ) );
$bfbt_created = BFBT_Seeder::seed_styles();
$check( 'seeding again creates nothing', 0 === $bfbt_created, $bfbt_created . ' created' );
$bfbt_mead = get_term_by( 'slug', 'mead', BFBT_Batch::STYLE_TAX );
$check( 'the top-level term carries its category meta', $bfbt_mead && 'mead' === get_term_meta( $bfbt_mead->term_id, BFBT_Batch::STYLE_CATEGORY_META, true ) );
$bfbt_trad = get_term_by( 'slug', 'traditional', BFBT_Batch::STYLE_TAX );
$check( 'a substyle sits under its category', $bfbt_mead && $bfbt_trad && (int) $bfbt_trad->parent === (int) $bfbt_mead->term_id );

echo "\n== a value the plugin cannot read is refused, and quoted back ==\n";
foreach ( [
	[ 'started', '2026-02-30' ],
	[ 'started', '03/14/2026' ],
	[ 'target_og', '1090' ],
	[ 'target_og', '1.250' ],
	[ 'volume', 'abc' ],
	[ 'volume', '0 gal' ],
	[ 'style', 'no-such-style' ],
] as [ $bfbt_field, $bfbt_bad ] ) {
	$bfbt_result = BFBT_Batch::create( [ 'name' => 'Integration Check Refusal', $bfbt_field => $bfbt_bad ] );
	$bfbt_quoted = is_wp_error( $bfbt_result ) && str_contains( $bfbt_result->get_error_message(), $bfbt_bad );
	$check( "{$bfbt_field} \"{$bfbt_bad}\" is refused with the value quoted", is_wp_error( $bfbt_result ) && $bfbt_quoted, is_wp_error( $bfbt_result ) ? $bfbt_result->get_error_message() : 'created ' . $bfbt_result );

	if ( ! is_wp_error( $bfbt_result ) ) {
		$bfbt_cleanup[] = (int) $bfbt_result;
	}
}

$bfbt_result = BFBT_Batch::create( [ 'name' => '' ] );
$check( 'a missing name is refused', is_wp_error( $bfbt_result ) );
$bfbt_none = get_posts( [ 'post_type' => BFBT_Batch::POST_TYPE, 'post_status' => 'any', 'title' => 'Integration Check Refusal', 'posts_per_page' => 5, 'fields' => 'ids' ] );
$check( 'a refused batch wrote nothing', [] === $bfbt_none, count( $bfbt_none ) . ' written' );

echo "\n== a good batch round-trips ==\n";
$bfbt_id = BFBT_Batch::create( [
	'name'              => 'Integration Check Mead',
	'style'             => 'traditional',
	'vessel'            => 'Integration Check Carboy',
	'vessel_type'       => 'carboy',
	'vessel_capacity_l' => '4',
	'started'           => '2026-03-01',
	'volume'            => '1 gal',
	'target_og'         => '1.110',
	'notes'             => 'Three pounds of honey.',
] );
$check( 'a valid batch is created', ! is_wp_error( $bfbt_id ), is_wp_error( $bfbt_id ) ? $bfbt_id->get_error_message() : (string) $bfbt_id );

if ( ! is_wp_error( $bfbt_id ) ) {
	$bfbt_cleanup[] = $bfbt_id;

	$check( 'the style reads back from the taxonomy', 'Traditional' === BFBT_Batch::style_name( $bfbt_id ), BFBT_Batch::style_name( $bfbt_id ) );
	$check( 'the vessel reads back from the taxonomy', 'Integration Check Carboy' === BFBT_Batch::vessel_name( $bfbt_id ) );
	$bfbt_vessel_id = BFBT_Batch::vessel_term_id( $bfbt_id );
	$check( 'the vessel carries its type and capacity', 'carboy' === get_term_meta( $bfbt_vessel_id, BFBT_Batch::VESSEL_TYPE_META, true ) && '4.000' === get_term_meta( $bfbt_vessel_id, BFBT_Batch::VESSEL_CAPACITY_META, true ) );
	$check( '"1 gal" is stored in litres and kept as typed', '3.785' === (string) BFBT_Batch::get( $bfbt_id, 'volume_l' ) && '1 gal' === (string) BFBT_Batch::get( $bfbt_id, 'volume_entered' ), (string) BFBT_Batch::get( $bfbt_id, 'volume_l' ) );
	$check( 'the target OG is normalised to three places', '1.110' === (string) BFBT_Batch::get( $bfbt_id, 'target_og' ) );

	echo "\n== stage is derived from the milestones, most final first ==\n";
	$check( 'a started batch is primary', 'primary' === BFBT_Stage::get( $bfbt_id ), BFBT_Stage::get( $bfbt_id ) );
	BFBT_Batch::update( $bfbt_id, [ 'secondary_date' => '2026-03-20' ] );
	$check( 'a secondary date makes it secondary', 'secondary' === BFBT_Stage::get( $bfbt_id ) );
	BFBT_Batch::update( $bfbt_id, [ 'packaged_date' => '2026-05-01' ] );
	$check( 'a packaged date beats secondary', 'packaged' === BFBT_Stage::get( $bfbt_id ) );
	BFBT_Batch::update( $bfbt_id, [ 'completed_date' => '2026-08-01' ] );
	$check( 'a completed date beats packaged', 'complete' === BFBT_Stage::get( $bfbt_id ) );
	BFBT_Batch::update( $bfbt_id, [ 'dumped' => '1' ] );
	$check( 'dumped beats everything', 'dumped' === BFBT_Stage::get( $bfbt_id ) );
	$bfbt_bad_date = BFBT_Batch::update( $bfbt_id, [ 'completed_date' => '2026-02-30' ] );
	$check( 'an impossible milestone date is refused', is_wp_error( $bfbt_bad_date ) );
	BFBT_Batch::update( $bfbt_id, [ 'dumped' => '', 'completed_date' => '', 'packaged_date' => '', 'secondary_date' => '' ] );
	$check( 'clearing the milestones takes it back to primary', 'primary' === BFBT_Stage::get( $bfbt_id ) );
	$check( 'an empty start date is planned', 'planned' === BFBT_Stage::get( (int) BFBT_Batch::create( [ 'name' => 'Integration Check Planned' ] ) ) );
	$bfbt_cleanup[] = (int) get_posts( [ 'post_type' => BFBT_Batch::POST_TYPE, 'title' => 'Integration Check Planned', 'posts_per_page' => 1, 'fields' => 'ids' ] )[0];
	// Pro inserts its states between these through bfbt_stage_order, so the
	// invariant is the relative order of the six, not the exact list.
	$bfbt_order    = BFBT_Stage::order();
	$bfbt_free_six = array_values( array_intersect( $bfbt_order, [ 'planned', 'primary', 'secondary', 'packaged', 'complete', 'dumped' ] ) );
	$check( 'the six free stages sit in cycle order', [ 'planned', 'primary', 'secondary', 'packaged', 'complete', 'dumped' ] === $bfbt_free_six, implode( ',', $bfbt_order ) );

	echo "\n== the first gravity reading is pinned as OG ==\n";
	$bfbt_bad_log = BFBT_Log::create( $bfbt_id, [ 'type' => 'gravity', 'sg' => '1090' ] );
	$check( 'a gravity of "1090" is refused with the hint', is_wp_error( $bfbt_bad_log ) && str_contains( $bfbt_bad_log->get_error_message(), 'enter as 1.090' ) );
	$bfbt_no_type = BFBT_Log::create( $bfbt_id, [ 'type' => 'poem' ] );
	$check( 'an unknown entry type is refused', is_wp_error( $bfbt_no_type ) );
	$bfbt_log1 = BFBT_Log::create( $bfbt_id, [ 'type' => 'gravity', 'sg' => '1.110', 'temp_c' => '20', 'time' => '2026-03-01 10:00' ] );
	$check( 'a gravity entry is created', ! is_wp_error( $bfbt_log1 ), is_wp_error( $bfbt_log1 ) ? $bfbt_log1->get_error_message() : (string) $bfbt_log1 );
	$check( 'its title is generated', 'SG 1.110 @ 20 °C' === get_the_title( (int) $bfbt_log1 ), get_the_title( (int) $bfbt_log1 ) );
	$check( 'its time is the post date', '2026-03-01 10:00:00' === get_post( (int) $bfbt_log1 )->post_date );
	$check( 'the first reading is pinned as OG', (string) $bfbt_log1 === (string) BFBT_Batch::get( $bfbt_id, 'og_log_id' ) );
	$bfbt_log2 = BFBT_Log::create( $bfbt_id, [ 'type' => 'gravity', 'sg' => '1.000', 'time' => '2026-03-21 10:00' ] );
	$check( 'a second reading does not move the pin', (string) $bfbt_log1 === (string) BFBT_Batch::get( $bfbt_id, 'og_log_id' ) );
	$check( 'the latest gravity is the second reading', '1.000' === ( BFBT_Log::latest_gravity( $bfbt_id )['sg'] ?? '' ) );
	$bfbt_abv = BFBT_ABV::from_gravities( 1.110, 1.000 );
	$check( 'OG 1.110 uses the high-gravity formula', 'alternate' === BFBT_ABV::formula_key( 1.110 ) && abs( $bfbt_abv - 15.85 ) < 0.01, number_format( $bfbt_abv, 2 ) );
	$check( 'OG 1.050 uses the standard formula', 'standard' === BFBT_ABV::formula_key( 1.050 ) && abs( BFBT_ABV::from_gravities( 1.050, 1.010 ) - 5.25 ) < 0.001 );
	$bfbt_wrong = BFBT_Log::pin_as_og( $bfbt_id, (int) BFBT_Log::create( $bfbt_id, [ 'type' => 'note', 'note' => 'not a reading' ] ) );
	$check( 'a note cannot be pinned as OG', is_wp_error( $bfbt_wrong ) );
	$check( 'a different reading can be pinned', true === BFBT_Log::pin_as_og( $bfbt_id, (int) $bfbt_log2 ) && (string) $bfbt_log2 === (string) BFBT_Batch::get( $bfbt_id, 'og_log_id' ) );
	BFBT_Log::trash( (int) $bfbt_log2 );
	$check( 'trashing the pinned reading clears the pin', '' === (string) BFBT_Batch::get( $bfbt_id, 'og_log_id' ) );
	$bfbt_future = BFBT_Log::create( $bfbt_id, [ 'type' => 'note', 'note' => 'dated ahead', 'time' => '2099-01-01 10:00' ] );
	$check( 'an entry dated ahead stays published', 'publish' === get_post_status( (int) $bfbt_future ), (string) get_post_status( (int) $bfbt_future ) );
	$check( 'no edit method exists on the log class', ! method_exists( 'BFBT_Log', 'update' ) && ! method_exists( 'BFBT_Log', 'edit' ) );

	echo "\n== completing a task is idempotent ==\n";
	$bfbt_bad_task = BFBT_Task::create( $bfbt_id, [ 'type' => 'addition' ] );
	$check( 'an addition task with no ingredient and no title is refused', is_wp_error( $bfbt_bad_task ) );
	$bfbt_task = BFBT_Task::create( $bfbt_id, [ 'type' => 'addition', 'ingredient' => 'Fermaid-O', 'qty' => '1.2', 'unit' => 'g', 'due' => '2026-03-02' ] );
	$check( 'an addition task is created with a generated title', ! is_wp_error( $bfbt_task ) && 'Fermaid-O 1.2 g' === get_the_title( (int) $bfbt_task ), is_wp_error( $bfbt_task ) ? $bfbt_task->get_error_message() : get_the_title( (int) $bfbt_task ) );
	$bfbt_undated = BFBT_Task::create( $bfbt_id, [ 'type' => 'rack', 'title' => 'Rack when stable' ] );
	$check( 'a task with no date stores 9999-12-31', BFBT_Task::NO_DATE === BFBT_Task::field( (int) $bfbt_undated, 'due' ) );
	$bfbt_open = array_map( static fn( $p ) => (int) $p->ID, BFBT_Task::open_for_batch( $bfbt_id ) );
	$check( 'both tasks are open, dated first', [ (int) $bfbt_task, (int) $bfbt_undated ] === $bfbt_open, implode( ',', $bfbt_open ) );
	$bfbt_fired = 0;
	add_action( 'bfbt_task_completed', static function () use ( &$bfbt_fired ): void { ++$bfbt_fired; } );
	$bfbt_log_from_task = BFBT_Task::complete( (int) $bfbt_task );
	$check( 'completing writes an addition entry', ! is_wp_error( $bfbt_log_from_task ) && 'Fermaid-O 1.2 g' === get_the_title( (int) $bfbt_log_from_task ) && (string) $bfbt_task === BFBT_Log::field( (int) $bfbt_log_from_task, 'task_id' ) );
	$check( 'the task is done', BFBT_Task::is_done( (int) $bfbt_task ) );
	$bfbt_again = BFBT_Task::complete( (int) $bfbt_task );
	$check( 'completing again is refused', is_wp_error( $bfbt_again ) && 'bfbt_task_done' === $bfbt_again->get_error_code() );
	$check( 'the hook fired exactly once', 1 === $bfbt_fired, $bfbt_fired . ' times' );
	$bfbt_entries = BFBT_Log::for_batch( $bfbt_id, 1, 50 );
	$bfbt_from_task = array_filter( $bfbt_entries['items'], static fn( $p ) => (string) $bfbt_task === BFBT_Log::field( (int) $p->ID, 'task_id' ) );
	$check( 'only one entry carries the task id', 1 === count( $bfbt_from_task ), count( $bfbt_from_task ) . ' entries' );
	BFBT_Task::uncomplete( (int) $bfbt_task );
	$check( 'uncomplete deletes the key rather than writing an empty string', ! metadata_exists( 'post', (int) $bfbt_task, BFBT_Task::META['done_at'] ) );
	$check( 'the task is open again', in_array( (int) $bfbt_task, array_map( static fn( $p ) => (int) $p->ID, BFBT_Task::open_for_batch( $bfbt_id ) ), true ) );

	echo "\n== next due is one grouped query over a page of batches ==\n";
	$bfbt_other = (int) BFBT_Batch::create( [ 'name' => 'Integration Check Cider', 'started' => '2026-03-05' ] );
	$bfbt_cleanup[] = $bfbt_other;
	BFBT_Task::create( $bfbt_other, [ 'type' => 'gravity', 'due' => '2026-04-01' ] );
	BFBT_Task::create( $bfbt_other, [ 'type' => 'gravity', 'due' => '2026-03-10' ] );
	$bfbt_done_task = (int) BFBT_Task::create( $bfbt_other, [ 'type' => 'gravity', 'due' => '2026-03-01' ] );
	BFBT_Task::complete( $bfbt_done_task );
	$bfbt_undated_only = (int) BFBT_Batch::create( [ 'name' => 'Integration Check Undated' ] );
	$bfbt_cleanup[] = $bfbt_undated_only;
	BFBT_Task::create( $bfbt_undated_only, [ 'type' => 'custom', 'title' => 'Someday' ] );
	$bfbt_next = BFBT_Task::next_due_for( [ $bfbt_id, $bfbt_other, $bfbt_undated_only ] );
	$check( 'the mead\'s next due is its dated task', '2026-03-02' === ( $bfbt_next[ $bfbt_id ]['due'] ?? '' ) && 'Fermaid-O 1.2 g' === ( $bfbt_next[ $bfbt_id ]['title'] ?? '' ), wp_json_encode( $bfbt_next[ $bfbt_id ] ?? null ) );
	$check( 'the earliest OPEN task wins, not the done one', '2026-03-10' === ( $bfbt_next[ $bfbt_other ]['due'] ?? '' ), wp_json_encode( $bfbt_next[ $bfbt_other ] ?? null ) );
	$check( 'a batch whose only task is undated has no next due', ! isset( $bfbt_next[ $bfbt_undated_only ] ) );
	$bfbt_latest = BFBT_Log::latest_gravity_for( [ $bfbt_id, $bfbt_other ] );
	$check( 'latest gravity per batch comes back for the batch that has one', '1.110' === ( $bfbt_latest[ $bfbt_id ]['sg'] ?? '' ) && ! isset( $bfbt_latest[ $bfbt_other ] ), wp_json_encode( $bfbt_latest ) );

	echo "\n== trashing a batch cascades to its entries and tasks, and back ==\n";
	$bfbt_on_purpose = (int) BFBT_Log::create( $bfbt_id, [ 'type' => 'note', 'note' => 'trashed on purpose' ] );
	BFBT_Log::trash( $bfbt_on_purpose );
	$bfbt_children = static function ( int $batch, string $status ): int {
		return count( get_posts( [ 'post_type' => [ BFBT_Batch::LOG_TYPE, BFBT_Batch::TASK_TYPE ], 'post_status' => $status, 'post_parent' => $batch, 'posts_per_page' => 100, 'fields' => 'ids' ] ) );
	};
	$bfbt_before = $bfbt_children( $bfbt_id, 'publish' );
	$check( 'the batch has published children to cascade', $bfbt_before > 0, $bfbt_before . ' children' );
	wp_trash_post( $bfbt_id );
	$check( 'trash, not a permanent delete', 'trash' === get_post_status( $bfbt_id ) );
	$check( 'every published child went to the trash with it', 0 === $bfbt_children( $bfbt_id, 'publish' ) && $bfbt_children( $bfbt_id, 'trash' ) === $bfbt_before + 1 + 1, $bfbt_children( $bfbt_id, 'trash' ) . ' in trash' );
	wp_untrash_post( $bfbt_id );
	$check( 'restoring puts the batch back to publish, not draft', 'publish' === get_post_status( $bfbt_id ), (string) get_post_status( $bfbt_id ) );
	$check( 'the children that went with it came back', $bfbt_before === $bfbt_children( $bfbt_id, 'publish' ), $bfbt_children( $bfbt_id, 'publish' ) . ' published' );
	$check( 'the entry trashed on purpose beforehand stayed in the trash', 'trash' === get_post_status( $bfbt_on_purpose ) && 'trash' === get_post_status( (int) $bfbt_log2 ) );
	$check( 'the entry dated ahead came back published, not future', 'publish' === get_post_status( (int) $bfbt_future ), (string) get_post_status( (int) $bfbt_future ) );
	$bfbt_child_ids = get_posts( [ 'post_type' => [ BFBT_Batch::LOG_TYPE, BFBT_Batch::TASK_TYPE ], 'post_status' => 'any', 'post_parent' => $bfbt_id, 'posts_per_page' => 100, 'fields' => 'ids' ] );
	wp_trash_post( $bfbt_id );
	wp_delete_post( $bfbt_id, true );
	$bfbt_left = array_filter( $bfbt_child_ids, static fn( $cid ) => null !== get_post( (int) $cid ) );
	$check( 'deleting the batch permanently deletes every child', null === get_post( $bfbt_id ) && [] === $bfbt_left, count( $bfbt_left ) . ' left' );
	$bfbt_cleanup = array_diff( $bfbt_cleanup, [ $bfbt_id ] );
}

echo "\n== the list pages in the database ==\n";
require_once BFBT_PATH . 'admin/class-batch-list-table.php';
$check( 'the list asks for twenty-five rows a page', 25 === BFBT_Batch_List_Table::PER_PAGE );
$check( 'no query in the plugin asks for every row', 0 === (int) shell_exec( 'grep -rl "posts_per_page. *=> *-1" ' . escapeshellarg( BFBT_PATH . 'includes' ) . ' ' . escapeshellarg( BFBT_PATH . 'admin' ) . ' | wc -l' ) );

echo "\n== the uninstall deletes exactly the options the plugin creates ==\n";
// Comments stripped: the docblock names the very sweep this check forbids.
$bfbt_uninstall = (string) php_strip_whitespace( BFBT_PATH . 'uninstall.php' );
preg_match_all( "/delete_option\(\s*'([^']+)'\s*\)/", $bfbt_uninstall, $bfbt_m );
$bfbt_deleted = $bfbt_m[1];
sort( $bfbt_deleted );
// Plus bfbt_style_children, which core writes for the hierarchical taxonomy.
$bfbt_created_set = [ BFBT_Feature_Registry::OPTION, BFBT_Units::OPTION, BFBT_Seeder::OPTION, BFBT_Batch::STYLE_TAX . '_children' ];
sort( $bfbt_created_set );
$check( 'uninstall.php deletes bfbt_features, bfbt_units, bfbt_seeded and the core style hierarchy cache by literal name', $bfbt_created_set === $bfbt_deleted, implode( ', ', $bfbt_deleted ) );
$check( 'uninstall.php has no LIKE sweep over bfbt_ options', 1 !== preg_match( "/LIKE\s+'bfbt_/i", $bfbt_uninstall ) && 1 === preg_match( "/esc_like\(\s*'_transient_bfbt_notice_'\s*\)/", $bfbt_uninstall ) );
$check( 'uninstall.php deletes no posts or terms', ! str_contains( $bfbt_uninstall, 'wp_delete_post' ) && ! str_contains( $bfbt_uninstall, 'wp_delete_term' ) && ! str_contains( $bfbt_uninstall, '$wpdb->posts' ) );
$bfbt_writers = (string) shell_exec( 'grep -rhoE "update_option\( *[A-Za-z_:]+" ' . escapeshellarg( BFBT_PATH . 'includes' ) . ' ' . escapeshellarg( BFBT_PATH . 'admin' ) . ' | sort -u' );
$check( 'the only options written are the ones uninstall deletes', 2 === substr_count( $bfbt_writers, 'update_option' ) && str_contains( $bfbt_writers, 'BFBT_Units::OPTION' ) && str_contains( $bfbt_writers, 'self::OPTION' ), trim( str_replace( "\n", '; ', $bfbt_writers ) ) );

echo "\n== cleanup ==\n";
foreach ( array_unique( $bfbt_cleanup ) as $bfbt_temp ) {
	if ( $bfbt_temp > 0 && get_post( $bfbt_temp ) ) {
		wp_delete_post( $bfbt_temp, true );
	}
}
$bfbt_vessel = get_term_by( 'name', 'Integration Check Carboy', BFBT_Batch::VESSEL_TAX );
if ( $bfbt_vessel ) {
	wp_delete_term( (int) $bfbt_vessel->term_id, BFBT_Batch::VESSEL_TAX );
}
$bfbt_leftover = get_posts( [ 'post_type' => [ BFBT_Batch::POST_TYPE, BFBT_Batch::LOG_TYPE, BFBT_Batch::TASK_TYPE ], 'post_status' => 'any', 's' => 'Integration Check', 'posts_per_page' => 50, 'fields' => 'ids' ] );
$check( 'nothing this script created is left behind', [] === $bfbt_leftover, count( $bfbt_leftover ) . ' left' );

printf( "\n%s\n\n", 0 === $bfbt_failures ? "All {$bfbt_checks} checks passed." : "{$bfbt_failures} of {$bfbt_checks} check(s) failed." );
