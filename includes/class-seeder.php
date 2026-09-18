<?php
/**
 * The style tree, seeded on activation.
 *
 * Idempotent by slug: a term that exists under its slug is left exactly as
 * it is — name, parent and all — so a maker who renamed "Melomel" to "Fruit
 * mead" keeps that name across a reactivation. The category meta on the four
 * parents is added only where it is absent, never replaced.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class BFBT_Seeder {

	public const OPTION = 'bfbt_seeded';

	/**
	 * slug => [ name, category, children slug => name ]
	 */
	public const STYLES = [
		'mead'  => [
			'name'     => 'Mead',
			'children' => [
				'traditional' => 'Traditional',
				'melomel'     => 'Melomel',
				'cyser'       => 'Cyser',
				'metheglin'   => 'Metheglin',
				'braggot'     => 'Braggot',
				'pyment'      => 'Pyment',
			],
		],
		'wine'  => [
			'name'     => 'Wine',
			'children' => [
				'red'       => 'Red',
				'white'     => 'White',
				'rose'      => 'Rosé',
				'fruit'     => 'Fruit',
				'sparkling' => 'Sparkling',
			],
		],
		'cider' => [
			'name'     => 'Cider',
			'children' => [
				'dry'   => 'Dry',
				'sweet' => 'Sweet',
				'perry' => 'Perry',
			],
		],
		'beer'  => [
			'name'     => 'Beer',
			'children' => [
				'ale'   => 'Ale',
				'lager' => 'Lager',
				'stout' => 'Stout',
				'ipa'   => 'IPA',
				'wheat' => 'Wheat',
				'sour'  => 'Sour',
			],
		],
	];

	/**
	 * Seed every style that is missing.
	 *
	 * @return int How many terms were created this run.
	 */
	public static function seed_styles(): int {
		$created = 0;

		foreach ( self::STYLES as $category => $group ) {
			$parent_id = self::ensure_term( $category, $group['name'], 0, $created );

			if ( null === $parent_id ) {
				continue;
			}

			// add_term_meta() with $unique adds only where nothing is stored.
			add_term_meta( $parent_id, BFBT_Batch::STYLE_CATEGORY_META, $category, true );

			foreach ( $group['children'] as $slug => $name ) {
				self::ensure_term( $slug, $name, $parent_id, $created );
			}
		}

		update_option( self::OPTION, '1' );

		return $created;
	}

	/**
	 * @return int|null The term id, or null when it neither exists nor could be created.
	 */
	private static function ensure_term( string $slug, string $name, int $parent_id, int &$created ): ?int {
		$existing = get_term_by( 'slug', $slug, BFBT_Batch::STYLE_TAX );

		if ( $existing ) {
			return (int) $existing->term_id;
		}

		$inserted = wp_insert_term( $name, BFBT_Batch::STYLE_TAX, [
			'slug'   => $slug,
			'parent' => $parent_id,
		] );

		if ( is_wp_error( $inserted ) ) {
			return null;
		}

		++$created;

		return (int) $inserted['term_id'];
	}
}
