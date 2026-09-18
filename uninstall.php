<?php
/**
 * Uninstall.
 *
 * Batches, log entries, tasks, styles and vessels are NOT deleted. They are
 * the maker's records and they are shared with the Pro add-on; a season of
 * readings must not vanish because someone tidied up the plugin list.
 *
 * Every option is deleted by its literal name. There is no
 * `option_name LIKE 'bfbt_%'` sweep: `bfbt_` is a prefix of the Pro add-on's
 * `bfbtp_`, so that sweep would erase Pro's settings, and `_` is itself a LIKE
 * wildcard. The only pattern query is the one-shot notice transient, whose
 * literal prefix is passed through esc_like so the underscores match
 * themselves.
 *
 * The set deleted here must equal the set the plugin creates; the uninstall
 * E2E test asserts that, and plants a `bfbtp_digest_hour` to prove Pro's
 * options survive.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'bfbt_features' );
delete_option( 'bfbt_units' );
delete_option( 'bfbt_seeded' );

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_bfbt_notice_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_bfbt_notice_' ) . '%'
	)
);
